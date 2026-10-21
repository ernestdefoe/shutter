<?php

namespace ErnestDefoe\Shutter\Api;

use ErnestDefoe\Shutter\Access;
use ErnestDefoe\Shutter\Ballot;
use ErnestDefoe\Shutter\Entries;
use ErnestDefoe\Shutter\Model\Contest;
use ErnestDefoe\Shutter\Presenter;
use ErnestDefoe\Shutter\Results;
use Flarum\Http\RequestUtil;
use Flarum\User\User;
use Illuminate\Database\ConnectionInterface;
use Laminas\Diactoros\Response\JsonResponse;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;

/**
 * GET /api/shutter/contests/{id} — the contest, what the viewer may do in it,
 * the podium once it is decided, and the first page of entries: everything
 * the discussion page draws, in one request.
 */
class ShowContestController implements RequestHandlerInterface
{
    use Concerns;

    public function __construct(
        private Presenter $presenter,
        private Results $results,
        private Entries $entries,
        private ConnectionInterface $db,
    ) {
    }

    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        $actor = RequestUtil::getActor($request);
        $contest = $this->visibleContest($this->routeId($request), $actor);

        // A contest past its end is decided by whoever looks first, so a forum
        // without a scheduler still crowns its winners.
        $this->results->finalize($contest);

        $data = $this->presenter->contest($contest, $actor);

        $voteBlock = Access::voteBlock($actor, $contest);
        $mine = $actor->isGuest() ? 0 : $this->db->table('shutter_entries')->where('contest_id', $contest->id)->where('user_id', $actor->id)->count();
        $myVotes = $actor->isGuest() || $contest->judgeScored() ? 0 : Ballot::counted($contest->id, (int) $actor->id)->count();

        $data['viewer'] = [
            'enterBlock' => Access::enterBlock($actor, $contest),
            'voteBlock' => $voteBlock,
            'scoreBlock' => $contest->judgeScored() ? Access::scoreBlock($actor, $contest) : null,
            'isJudge' => $contest->isJudge($actor),
            'entries' => $mine,
            'votesUsed' => $myVotes,
        ];

        $data['entryCount'] = $this->db->table('shutter_entries')->where('contest_id', $contest->id)->whereNotNull('approved_at')->whereNull('disqualified_at')->count();

        if ($contest->judgeScored()) {
            $data['judges'] = User::query()->whereIn('id', $contest->judges() ?: [0])->get()->map(fn (User $u) => $this->presenter->user($u))->values()->all();
        }

        $data['podium'] = $contest->finalized_at !== null
            ? $this->presenter->entries($this->entries->podium($contest), $contest, $actor)
            : [];

        $page = $this->entries->page($contest, $actor, $request->getQueryParams());
        $data['entries'] = $this->presenter->entries($page['entries'], $contest, $actor);
        $data['nextOffset'] = $page['next'];
        $data['sort'] = $page['sort'];

        return new JsonResponse(['data' => $data]);
    }
}
