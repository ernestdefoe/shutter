<?php

namespace ErnestDefoe\Shutter\Api;

use Carbon\Carbon;
use ErnestDefoe\Shutter\Access;
use ErnestDefoe\Shutter\Ballot;
use ErnestDefoe\Shutter\Media\Network;
use ErnestDefoe\Shutter\Model\Vote;
use ErnestDefoe\Shutter\Results;
use Flarum\Http\RequestUtil;
use Flarum\User\Exception\PermissionDeniedException;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Database\QueryException;
use Laminas\Diactoros\Response\JsonResponse;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;

/**
 * POST and DELETE /api/shutter/entries/{id}/vote.
 *
 * 🚨 Server-side and race-safe: one vote per entry per member and at most
 * votes_per_member per contest are both unique indexes (entry+user, and
 * contest+user+slot), so a burst of parallel requests cannot exceed either.
 * Voting for your own entry is refused here, not just hidden in the page.
 */
class VoteController implements RequestHandlerInterface
{
    use Concerns;

    public function __construct(private Network $network, private Results $results, private ConnectionInterface $db)
    {
    }

    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        $actor = RequestUtil::getActor($request);
        $entry = $this->visibleEntry($this->routeId($request), $actor);
        $contest = $entry->contest;

        if ($block = Access::voteBlock($actor, $contest)) {
            $actor->isGuest() ? throw new PermissionDeniedException() : $this->refuse('vote', "block.$block");
        }

        if ($entry->approved_at === null || $entry->disqualified_at !== null) {
            $this->refuse('vote', 'vote.not_running');
        }

        if ($entry->user_id === $actor->id) {
            $this->refuse('vote', 'vote.own');
        }

        $mine = Vote::query()->where('contest_id', $contest->id)->where('user_id', $actor->id);

        if ($request->getMethod() === 'DELETE') {
            // A vote staff voided stays: withdrawing it would hand the slot back.
            (clone $mine)->where('entry_id', $entry->id)->whereNull('voided_at')->delete();
        } elseif (! (clone $mine)->where('entry_id', $entry->id)->exists()) {
            $this->cast($contest->id, $entry->id, $actor->id, (clone $mine)->pluck('slot')->all(), $contest->votes_per_member, $request);
        }

        $tally = $contest->resultsPublic() ? ($this->results->tallies($contest, [$entry->id])[$entry->id]['votes'] ?? 0) : null;

        return new JsonResponse(['data' => [
            'voted' => (clone $mine)->where('entry_id', $entry->id)->exists(),
            'votesUsed' => Ballot::counted($contest->id, $actor->id)->count(),
            'votes' => $tally,
        ]]);
    }

    /** @param array<int, mixed> $used */
    private function cast(int $contestId, int $entryId, int $userId, array $used, int $limit, ServerRequestInterface $request): void
    {
        // A few tries: a parallel vote may take the slot this one picked, and
        // a reclaimed slot needs a second look.
        for ($try = 0; $try < 3; $try++) {
            $used = array_map('intval', $used);
            $slot = null;

            for ($s = 1; $s <= $limit; $s++) {
                if (! in_array($s, $used, true)) {
                    $slot = $s;
                    break;
                }
            }

            if ($slot === null) {
                // A slot held by a vote for a since-disqualified photo is the
                // voter's to spend again.
                if (Ballot::reclaim($contestId, $userId)) {
                    $used = Vote::query()->where('contest_id', $contestId)->where('user_id', $userId)->pluck('slot')->all();
                    continue;
                }

                $this->refuse('vote', 'vote.limit', ['{count}' => $limit]);
            }

            $vote = new Vote();
            $vote->forceFill([
                'contest_id' => $contestId,
                'entry_id' => $entryId,
                'user_id' => $userId,
                'slot' => $slot,
                'ip_hash' => $this->network->hash($request->getAttribute('ipAddress')),
                'created_at' => Carbon::now(),
            ]);

            try {
                // Its own transaction: on PostgreSQL a refused INSERT poisons
                // any transaction around it; this keeps that to a savepoint.
                $this->db->transaction(fn () => $vote->save());

                return;
            } catch (QueryException) {
                if (Vote::query()->where('entry_id', $entryId)->where('user_id', $userId)->exists()) {
                    return;
                }

                $used = Vote::query()->where('contest_id', $contestId)->where('user_id', $userId)->pluck('slot')->all();
            }
        }

        $this->refuse('vote', 'vote.limit', ['{count}' => $limit]);
    }
}
