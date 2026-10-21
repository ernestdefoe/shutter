<?php

namespace ErnestDefoe\Shutter;

use ErnestDefoe\Shutter\Model\Contest;
use ErnestDefoe\Shutter\Model\Entry;
use ErnestDefoe\Shutter\Model\Score;
use ErnestDefoe\Shutter\Model\Vote;
use Flarum\User\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;

/** Which entries a viewer sees, in what order, a page at a time. */
class Entries
{
    public const PAGE = 24;

    /**
     * @param array<string, mixed> $params
     * @return array{entries: Collection<int, Entry>, next: ?int, sort: string}
     */
    public function page(Contest $contest, User $actor, array $params): array
    {
        $offset = max(0, min(100000, (int) ($params['offset'] ?? 0)));
        $limit = max(1, min(60, (int) ($params['limit'] ?? self::PAGE)));
        $mineOnly = ! empty($params['mine']) && ! $actor->isGuest();
        $manage = Access::canManage($actor);

        $query = Entry::query()->where('shutter_entries.contest_id', $contest->id)->with('user');

        if ($mineOnly) {
            $query->where('shutter_entries.user_id', $actor->id);
        } elseif (! empty($params['pending']) && $manage) {
            $query->where(fn (Builder $q) => $q->whereNull('shutter_entries.approved_at')->orWhereNotNull('shutter_entries.disqualified_at'));
        } else {
            // The gallery is the entries in the running. The viewer's own,
            // still awaiting approval, are shown to them under "Mine".
            $query->inRunning();
        }

        $sort = $this->sort($contest, $actor, (string) ($params['sort'] ?? ''));
        $this->order($query, $contest, $actor, $sort);

        $rows = $query->skip($offset)->take($limit + 1)->get();
        $next = $rows->count() > $limit ? $offset + $limit : null;

        return ['entries' => $rows->take($limit)->values(), 'next' => $next, 'sort' => $sort];
    }

    /** @return Collection<int, Entry> */
    public function podium(Contest $contest): Collection
    {
        return Entry::query()->where('contest_id', $contest->id)->whereNotNull('placement')->inRunning()
            ->with('user')->orderBy('placement')->orderBy('id')->get();
    }

    /**
     * newest | top | shuffle. Top is only on offer once tallies may be seen;
     * asked for earlier it would leak the running order.
     */
    private function sort(Contest $contest, User $actor, string $asked): string
    {
        $top = Access::seesTallies($actor, $contest);

        return match (true) {
            $asked === 'top' && $top, $asked === 'newest', $asked === 'shuffle' => $asked,
            $contest->phase() === Contest::CLOSED => 'top',
            // While people vote, each sees the gallery in their own fixed
            // shuffle: nobody's photo wins for being first in the grid.
            $contest->phase() === Contest::VOTING => 'shuffle',
            default => 'newest',
        };
    }

    /** @param Builder<Entry> $query */
    private function order(Builder $query, Contest $contest, User $actor, string $sort): void
    {
        if ($sort === 'top') {
            $tally = $contest->judgeScored()
                ? Score::query()->selectRaw('AVG(score)')->whereColumn('shutter_scores.entry_id', 'shutter_entries.id')
                : Vote::query()->selectRaw('COUNT(*)')->whereColumn('shutter_votes.entry_id', 'shutter_entries.id')->whereNull('shutter_votes.voided_at');

            // Placed entries first (nulls last on every database), then by tally.
            $query->orderByRaw('CASE WHEN placement IS NULL THEN 1 ELSE 0 END')->orderBy('shutter_entries.placement')
                ->orderByDesc($tally)->orderBy('shutter_entries.id');

            return;
        }

        if ($sort === 'shuffle') {
            // Stable for one viewer, different between viewers, the same SQL
            // on SQLite, MySQL and PostgreSQL. Bare column: no table prefix.
            $seed = (($actor->id ?: 0) * 7919 + $contest->id * 104729) % 10007;
            $query->orderByRaw('((id % 10007) * 7907 + ?) % 10007', [$seed])->orderBy('shutter_entries.id');

            return;
        }

        $query->orderByDesc('shutter_entries.created_at')->orderByDesc('shutter_entries.id');
    }
}
