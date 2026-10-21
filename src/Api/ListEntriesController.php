<?php

namespace ErnestDefoe\Shutter\Api;

use ErnestDefoe\Shutter\Entries;
use ErnestDefoe\Shutter\Presenter;
use Flarum\Http\RequestUtil;
use Laminas\Diactoros\Response\JsonResponse;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;

/** GET /api/shutter/contests/{id}/entries — the next page of the gallery. */
class ListEntriesController implements RequestHandlerInterface
{
    use Concerns;

    public function __construct(private Presenter $presenter, private Entries $entries)
    {
    }

    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        $actor = RequestUtil::getActor($request);
        $contest = $this->visibleContest($this->routeId($request), $actor);
        $page = $this->entries->page($contest, $actor, $request->getQueryParams());

        return new JsonResponse([
            'data' => $this->presenter->entries($page['entries'], $contest, $actor),
            'nextOffset' => $page['next'],
            'sort' => $page['sort'],
        ]);
    }
}
