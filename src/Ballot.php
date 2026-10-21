<?php

namespace ErnestDefoe\Shutter;

use ErnestDefoe\Shutter\Model\Entry;
use ErnestDefoe\Shutter\Model\Vote;
use Illuminate\Database\Eloquent\Builder;

/**
 * A member's votes in one contest, as they count against the limit.
 *
 * A vote for a photo that has since been disqualified (or sent back for
 * approval) does not count: the voter did nothing wrong. Its row is kept, so
 * reinstating the photo restores the vote, until the voter needs the slot for
 * another photo. A vote staff voided does count: that is the point of voiding.
 */
class Ballot
{
    /** @return Builder<Vote> */
    public static function counted(int $contestId, int $userId): Builder
    {
        return Vote::query()
            ->where('shutter_votes.contest_id', $contestId)
            ->where('shutter_votes.user_id', $userId)
            ->where(fn (Builder $q) => $q->whereNotNull('shutter_votes.voided_at')
                ->orWhereIn('shutter_votes.entry_id', Entry::query()->where('contest_id', $contestId)->inRunning()->select('id')));
    }

    /** Give back one slot held by a vote that no longer counts, if there is one. */
    public static function reclaim(int $contestId, int $userId): bool
    {
        $stale = Vote::query()
            ->where('contest_id', $contestId)
            ->where('user_id', $userId)
            ->whereNull('voided_at')
            ->whereNotIn('entry_id', Entry::query()->where('contest_id', $contestId)->inRunning()->select('id'))
            ->orderBy('id')
            ->first();

        return $stale !== null && (bool) $stale->delete();
    }
}
