<?php

namespace ErnestDefoe\Shutter\Api;

use ErnestDefoe\Shutter\Access;
use ErnestDefoe\Shutter\Model\Contest;
use ErnestDefoe\Shutter\Presenter;
use Flarum\Http\RequestUtil;
use Flarum\User\Exception\PermissionDeniedException;
use Flarum\User\User;
use Laminas\Diactoros\Response\JsonResponse;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;

/**
 * GET /api/shutter/entries/{id}/voters — who voted for an entry.
 *
 * Only in a contest with named votes, and only once voting has closed (not
 * merely when live counts are on):
 * names appearing during the vote would be a bandwagon. Staff always.
 * Fetched when a photo is opened, never per tile.
 */
class VotersController implements RequestHandlerInterface
{
    use Concerns;

    public function __construct(private Presenter $presenter)
    {
    }

    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        $actor = RequestUtil::getActor($request);
        $entry = $this->visibleEntry($this->routeId($request), $actor);
        $contest = $entry->contest;

        if (! Access::canManage($actor) && ! ($contest->named_votes && $contest->phase() === Contest::CLOSED && ! $contest->judgeScored())) {
            throw new PermissionDeniedException();
        }

        $users = User::query()
            ->join('shutter_votes', 'shutter_votes.user_id', '=', 'users.id')
            ->where('shutter_votes.entry_id', $entry->id)
            ->whereNull('shutter_votes.voided_at')
            ->orderBy('shutter_votes.id')
            ->limit(200)
            ->get(['users.*']);

        return new JsonResponse(['data' => $users->map(fn (User $u) => $this->presenter->user($u))->values()->all()]);
    }
}
