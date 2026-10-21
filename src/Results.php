<?php

namespace ErnestDefoe\Shutter;

use Carbon\Carbon;
use ErnestDefoe\Shutter\Event\ContestFinalized;
use ErnestDefoe\Shutter\Integration\Atrium\Publisher;
use ErnestDefoe\Shutter\Model\Contest;
use ErnestDefoe\Shutter\Model\Entry;
use ErnestDefoe\Shutter\Notification\PlacedBlueprint;
use ErnestDefoe\Shutter\Notification\ResultsBlueprint;
use Flarum\Discussion\Discussion;
use Flarum\Notification\NotificationSyncer;
use Flarum\User\User;
use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Database\ConnectionInterface;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Tallies, placings and the end of a contest.
 *
 * Counts are always read from the votes themselves — one grouped query —
 * never kept in a counter that a crash or a race could leave wrong.
 */
class Results
{
    public function __construct(
        private ConnectionInterface $db,
        private NotificationSyncer $notifications,
        private Dispatcher $events,
        private LoggerInterface $log,
        private Publisher $publisher,
    ) {
    }

    /**
     * Each entry's tally: votes, or judges' average and how many judged it.
     *
     * @param list<int>|null $entryIds limit to these entries (one page)
     * @return array<int, array{votes?: int, score?: float, scores?: int}>
     */
    public function tallies(Contest $contest, ?array $entryIds = null): array
    {
        $table = $contest->judgeScored() ? 'shutter_scores' : 'shutter_votes';
        $query = $this->db->table($table)->where('contest_id', $contest->id)->groupBy('entry_id')->select('entry_id');

        if ($entryIds !== null) {
            $query->whereIn('entry_id', $entryIds ?: [0]);
        }

        if (! $contest->judgeScored()) {
            $query->whereNull('voided_at');
        }

        if ($contest->judgeScored()) {
            $query->selectRaw('AVG(score) as average')->selectRaw('COUNT(*) as total');
        } else {
            $query->selectRaw('COUNT(*) as total');
        }

        $out = [];

        foreach ($query->get() as $row) {
            $out[(int) $row->entry_id] = $contest->judgeScored()
                ? ['score' => round((float) $row->average, 2), 'scores' => (int) $row->total]
                : ['votes' => (int) $row->total];
        }

        return $out;
    }

    /**
     * Placings by competition ranking: equal tallies share a place and the
     * next place is skipped (1, 2, 2, 4). An entry nobody voted for is not
     * placed at all, however few entries there are.
     *
     * @return array<int, int> entry id => placing, podium only
     */
    public function placings(Contest $contest): array
    {
        $running = Entry::query()->where('contest_id', $contest->id)->inRunning()->pluck('id')->map(fn ($id) => (int) $id)->all();
        $tallies = array_intersect_key($this->tallies($contest), array_flip($running));
        $key = $contest->judgeScored() ? 'score' : 'votes';
        $values = array_filter(array_map(fn ($t) => $t[$key] ?? 0, $tallies), fn ($v) => $v > 0);

        arsort($values);

        $placings = [];
        $position = 0;
        $previous = null;
        $place = 0;

        foreach ($values as $entryId => $value) {
            $position++;

            if ($value !== $previous) {
                $place = $position;
                $previous = $value;
            }

            if ($place > $contest->winners_count) {
                break;
            }

            $placings[$entryId] = $place;
        }

        return $placings;
    }

    /**
     * End a closed contest: write placings, tell entrants, award badges.
     *
     * 🚨 Claimed with a conditional UPDATE, so a page view, the scheduler and
     * a second page view arriving together finalize it exactly once.
     */
    public function finalize(Contest $contest): void
    {
        if ($contest->finalized_at !== null || $contest->phase() !== Contest::CLOSED) {
            return;
        }

        $now = Carbon::now();
        $claimed = Contest::query()->whereKey($contest->id)->whereNull('finalized_at')->update(['finalized_at' => $now]);

        if (! $claimed) {
            return;
        }

        $contest->finalized_at = $now;

        try {
            $this->place($contest, true);
        } catch (Throwable $e) {
            // Released, so the scheduler or the next visitor tries again. Alerts
            // already sent are not sent twice: the notification syncer keeps one
            // per member and photo.
            Contest::query()->whereKey($contest->id)->update(['finalized_at' => null]);
            $contest->finalized_at = null;
            $this->log->error('Shutter could not decide contest '.$contest->id.': '.$e->getMessage(), ['exception' => $e]);
        }
    }

    /**
     * Placings again, after staff disqualify, reinstate or delete an entry in
     * a finished contest. Only members whose placing is new are told.
     */
    public function rerank(Contest $contest): void
    {
        if ($contest->finalized_at !== null) {
            $this->place($contest, false);
        }
    }

    private function place(Contest $contest, bool $first): void
    {
        $placings = $this->placings($contest);
        $entries = Entry::query()->where('contest_id', $contest->id)->get(['id', 'user_id', 'placement', 'disqualified_at', 'approved_at']);
        $newlyPlaced = [];

        foreach ($entries as $entry) {
            $placing = $placings[$entry->id] ?? null;

            if ($placing !== null && $entry->user_id && ($entry->placement === null || $placing < $entry->placement)) {
                $newlyPlaced[$entry->id] = (int) $entry->user_id;
            }
        }

        // One UPDATE per distinct placing, not one per entry.
        $this->db->transaction(function () use ($contest, $placings) {
            Entry::query()->where('contest_id', $contest->id)->whereNotIn('id', array_keys($placings) ?: [0])->whereNotNull('placement')->update(['placement' => null]);

            foreach (array_unique($placings) as $placing) {
                Entry::query()->whereIn('id', array_keys($placings, $placing, true))->update(['placement' => $placing]);
            }
        });

        $discussion = $contest->discussion;

        if ($discussion) {
            // One alert per member, for their best new placing. The entry is
            // in the alert's data, so each one is its own notification and a
            // later re-rank never retracts an earlier one.
            $best = [];

            foreach ($newlyPlaced as $entryId => $userId) {
                if (! isset($best[$userId]) || $placings[$entryId] < $placings[$best[$userId]]) {
                    $best[$userId] = $entryId;
                }
            }

            $users = $best ? User::query()->whereIn('id', array_keys($best))->get()->keyBy('id') : collect();

            foreach ($best as $userId => $entryId) {
                // 🚨 The email names the discussion and is not filtered by
                // Flarum the way the alert list is: only to a member who can
                // still see it.
                $user = $users->get($userId);

                if ($user && Discussion::query()->whereVisibleTo($user)->whereKey($discussion->id)->exists()) {
                    $this->notifications->sync(new PlacedBlueprint($discussion, $placings[$entryId], $entryId), [$user]);
                }
            }

            if ($first) {
                $others = $entries->whereNotNull('approved_at')->whereNull('disqualified_at')->pluck('user_id')->filter()->unique()->diff(array_keys($best));

                if ($others->isNotEmpty()) {
                    $this->notifications->sync(new ResultsBlueprint($discussion), User::query()->whereIn('id', $others->all())->get()->all());
                }
            }
        }

        $entrants = $first ? $entries->pluck('user_id')->filter()->unique()->map(fn ($id) => (int) $id)->values()->all() : [];
        // Atrium (optional): the podium photos into the contest's album.
        $this->publisher->publish($contest, Entry::query()->where('contest_id', $contest->id)->whereNotNull('placement')->inRunning()->orderBy('placement')->orderBy('id')->get());

        $this->events->dispatch(new ContestFinalized($contest, array_values(array_unique($newlyPlaced)), $entrants));
    }
}
