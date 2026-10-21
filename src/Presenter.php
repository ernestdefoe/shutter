<?php

namespace ErnestDefoe\Shutter;

use ErnestDefoe\Shutter\Integration\Atrium\Publisher;
use ErnestDefoe\Shutter\Media\Store;
use ErnestDefoe\Shutter\Model\Contest;
use ErnestDefoe\Shutter\Model\Entry;
use Flarum\Group\Group;
use Flarum\Http\UrlGenerator;
use Flarum\User\User;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Support\Collection;

/**
 * Contests and entries as JSON, shaped for whoever is asking.
 *
 * 🚨 Every rule about who may know what is here, in one place: whose photo it
 * is (hidden while voting, if the contest says so), how many votes it has
 * (hidden until the end unless live counts are on), an entry's moderation
 * state (its owner and staff only). The frontend only ever hides what it was
 * never sent.
 */
class Presenter
{
    public function __construct(
        private Store $store,
        private Results $results,
        private ConnectionInterface $db,
        private Publisher $publisher,
        private UrlGenerator $url,
    ) {
    }

    /** @return array<string, mixed> */
    public function contest(Contest $contest, User $actor): array
    {
        $manage = Access::canManage($actor);

        return [
            'id' => $contest->id,
            'discussionId' => $contest->discussion_id,
            'phase' => $contest->phase(),
            'opensAt' => $contest->opens_at?->toIso8601String(),
            'submissionsCloseAt' => $contest->submissions_close_at->toIso8601String(),
            'votingCloseAt' => $contest->voting_close_at->toIso8601String(),
            'rules' => $contest->rules,
            'entriesPerMember' => $contest->entries_per_member,
            'votesPerMember' => $contest->votes_per_member,
            'scoring' => $contest->scoring,
            'namedVotes' => $contest->named_votes,
            'liveCounts' => $contest->live_counts,
            'hideEntrants' => $contest->hide_entrants,
            'moderateEntries' => $contest->moderate_entries,
            'winnersCount' => $contest->winners_count,
            'voterMinDays' => $contest->voter_min_days,
            'voterMinPosts' => $contest->voter_min_posts,
            'enterGroups' => $this->groups($contest->enter_group_ids),
            'voteGroups' => $this->groups($contest->vote_group_ids),
            'resultsPublic' => $contest->resultsPublic(),
            'seesTallies' => Access::seesTallies($actor, $contest),
            'finalized' => $contest->finalized_at !== null,
            'canManage' => $manage,
            'atriumAlbumId' => $manage ? $contest->atrium_album_id : null,
        ];
    }

    /**
     * @param array<int, array{votes?: int, score?: float, scores?: int}> $tallies
     * @param array<int, true> $myVotes
     * @param array<int, int> $myScores
     * @return array<string, mixed>
     */
    public function entry(Entry $entry, Contest $contest, User $actor, array $tallies, array $myVotes, array $myScores): array
    {
        $mine = $entry->user_id !== null && $entry->user_id === $actor->id;
        $manage = Access::canManage($actor);
        $urls = $this->store->urls($entry->hash, $entry->ext);
        $showUser = $mine || $manage || ! $contest->entrantsHidden();
        $showTally = Access::seesTallies($actor, $contest);

        $data = [
            'id' => $entry->id,
            'contestId' => $entry->contest_id,
            'title' => $entry->title,
            'caption' => $entry->caption,
            'width' => $entry->width,
            'height' => $entry->height,
            'thumb' => $urls['thumb'],
            'large' => $urls['large'],
            'blur' => $entry->blur,
            'camera' => $entry->camera,
            // Hidden with the name: a submission time can be matched to the
            // entrant's other activity.
            'createdAt' => $showUser ? $entry->created_at?->toIso8601String() : null,
            'mine' => $mine,
            'user' => $showUser ? $this->user($entry->user) : null,
            'placement' => $contest->finalized_at !== null ? $entry->placement : null,
            'atriumUrl' => $entry->atrium_image_id && $this->publisher->available()
                ? $this->url->to('forum')->route('atrium.image', ['id' => $entry->atrium_image_id])
                : null,
        ];

        if ($mine || $manage) {
            $data['status'] = match (true) {
                $entry->disqualified_at !== null => 'disqualified',
                $entry->approved_at === null => 'pending',
                default => 'approved',
            };
        }

        if ($showTally) {
            $tally = $tallies[$entry->id] ?? [];
            $data += $contest->judgeScored()
                ? ['score' => $tally['score'] ?? null, 'scores' => $tally['scores'] ?? 0]
                : ['votes' => $tally['votes'] ?? 0];
        }

        if (! $contest->judgeScored() && ! $actor->isGuest()) {
            $data['voted'] = isset($myVotes[$entry->id]);
        }

        if ($contest->isJudge($actor)) {
            $data['myScore'] = $myScores[$entry->id] ?? null;
        }

        return $data;
    }

    /** @return array<string, mixed>|null */
    public function user(?User $user): ?array
    {
        return $user ? [
            'id' => $user->id,
            'username' => $user->username,
            'displayName' => $user->display_name,
            'avatarUrl' => $user->avatar_url,
        ] : null;
    }

    /**
     * @param list<int>|null $ids
     * @return list<array{id: int, name: string}>
     */
    private function groups(?array $ids): array
    {
        if (! $ids) {
            return [];
        }

        // Groups are few and cached for the request by Flarum's own lookups.
        return Group::query()->whereIn('id', $ids)->get(['id', 'name_plural'])
            ->map(fn (Group $g) => ['id' => (int) $g->id, 'name' => (string) $g->name_plural])->values()->all();
    }

    /**
     * @param Collection<int, Entry> $entries
     * @return list<array<string, mixed>>
     */
    public function entries(Collection $entries, Contest $contest, User $actor): array
    {
        $ids = $entries->pluck('id')->map(fn ($id) => (int) $id)->all();
        $tallies = $ids && Access::seesTallies($actor, $contest) ? $this->results->tallies($contest, $ids) : [];
        $myVotes = [];
        $myScores = [];

        if ($ids && ! $actor->isGuest()) {
            if ($contest->isJudge($actor)) {
                $myScores = $this->db->table('shutter_scores')->where('user_id', $actor->id)->whereIn('entry_id', $ids)->pluck('score', 'entry_id')->map(fn ($s) => (int) $s)->all();
            } elseif (! $contest->judgeScored()) {
                $myVotes = array_fill_keys($this->db->table('shutter_votes')->where('user_id', $actor->id)->whereIn('entry_id', $ids)->pluck('entry_id')->map(fn ($id) => (int) $id)->all(), true);
            }
        }

        return $entries->map(fn (Entry $e) => $this->entry($e, $contest, $actor, $tallies, $myVotes, $myScores))->values()->all();
    }
}
