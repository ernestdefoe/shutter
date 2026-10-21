<?php

namespace ErnestDefoe\Shutter\Api;

use ErnestDefoe\Shutter\Access;
use ErnestDefoe\Shutter\ContestSaver;
use ErnestDefoe\Shutter\Presenter;
use ErnestDefoe\Shutter\Results;
use Flarum\Discussion\Discussion;
use Flarum\Http\RequestUtil;
use Flarum\User\Exception\PermissionDeniedException;
use Laminas\Diactoros\Response\JsonResponse;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;

/**
 * POST /api/shutter/contests — turn an existing discussion into a contest.
 * PATCH /api/shutter/contests/{id} — change one.
 * (A new discussion becomes a contest from the composer, through the
 * discussion's own `shutterContest` attribute.).
 */
class SaveContestController implements RequestHandlerInterface
{
    use Concerns;

    public function __construct(private ContestSaver $saver, private Presenter $presenter, private Results $results)
    {
    }

    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        $actor = RequestUtil::getActor($request);
        $body = $this->body($request);

        if ($request->getMethod() === 'PATCH') {
            $contest = $this->manageableContest($this->routeId($request), $actor);
            $contest = $this->saver->update($contest, $this->saver->clean($body, $contest), $this->results);

            return new JsonResponse(['data' => $this->presenter->contest($contest, $actor)]);
        }

        if (! Access::canManage($actor)) {
            throw new PermissionDeniedException();
        }

        $discussion = Discussion::query()->whereVisibleTo($actor)->findOrFail((int) ($body['discussionId'] ?? 0));
        $contest = $this->saver->create($discussion, $actor, $this->saver->clean($body));

        return new JsonResponse(['data' => $this->presenter->contest($contest, $actor)], 201);
    }
}
