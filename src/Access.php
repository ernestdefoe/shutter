<?php

namespace ErnestDefoe\Shutter;

use Carbon\Carbon;
use ErnestDefoe\Shutter\Model\Contest;
use Flarum\Group\Group;
use Flarum\User\User;

/**
 * Who may do what in a contest. Each check returns null when allowed, or the
 * translation key of the reason, so the page can say why rather than just
 * hiding a button.
 */
class Access
{
    public static function canManage(User $actor): bool
    {
        return $actor->hasPermission('shutter.manage');
    }

    /**
     * Whether the actor may see tallies (and the order they make) now: when
     * results are public, or as staff — except a staff member who is judging
     * an open judged contest, who must not see the running average.
     */
    public static function seesTallies(User $actor, Contest $contest): bool
    {
        if ($contest->resultsPublic()) {
            return true;
        }

        return self::canManage($actor) && ! ($contest->judgeScored() && $contest->isJudge($actor));
    }

    public static function enterBlock(User $actor, Contest $contest): ?string
    {
        return match (true) {
            $contest->phase() !== Contest::SUBMISSIONS => 'closed',
            $actor->isGuest() => 'guest',
            ! $actor->hasPermission('shutter.enter') => 'permission',
            ! self::inGroups($actor, $contest->enter_group_ids) => 'groups',
            // A judge scores the entries; entering would mean scoring their own.
            $contest->isJudge($actor) => 'judge',
            default => null,
        };
    }

    public static function voteBlock(User $actor, Contest $contest): ?string
    {
        return match (true) {
            $contest->judgeScored() => 'judges_only',
            $contest->phase() !== Contest::VOTING => 'not_voting',
            $actor->isGuest() => 'guest',
            ! $actor->hasPermission('shutter.vote') => 'permission',
            ! self::inGroups($actor, $contest->vote_group_ids) => 'groups',
            // Sock-puppet brakes: an account made for the vote, or one that has
            // never taken part, does not get a say.
            $contest->voter_min_days > 0 && ($actor->joined_at === null || $actor->joined_at->gt(Carbon::now()->subDays($contest->voter_min_days))) => 'too_new',
            $contest->voter_min_posts > 0 && (int) $actor->comment_count < $contest->voter_min_posts => 'too_few_posts',
            default => null,
        };
    }

    public static function scoreBlock(User $actor, Contest $contest): ?string
    {
        return match (true) {
            ! $contest->isJudge($actor) => 'not_judge',
            $contest->phase() !== Contest::VOTING => 'not_voting',
            default => null,
        };
    }

    /**
     * Whether the actor is in one of the contest's groups. No groups = anyone
     * with the forum-wide permission. Admins hold every permission and are let
     * through here too, so staff can always test a contest.
     *
     * @param list<int>|null $groups
     */
    private static function inGroups(User $actor, ?array $groups): bool
    {
        $groups = array_map('intval', $groups ?? []);

        // 🚨 Members (3) is implied, never stored in group_user: picking it
        // means every member, and a lookup would match nobody.
        if (! $groups || $actor->isAdmin() || in_array(Group::MEMBER_ID, $groups, true)) {
            return true;
        }

        return $actor->groups->pluck('id')->map(fn ($id) => (int) $id)->intersect($groups)->isNotEmpty();
    }
}
