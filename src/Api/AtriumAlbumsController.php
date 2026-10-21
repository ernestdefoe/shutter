<?php

namespace ErnestDefoe\Shutter\Api;

use ErnestDefoe\Shutter\Access;
use ErnestDefoe\Shutter\Integration\Atrium\Publisher;
use Flarum\Http\RequestUtil;
use Flarum\User\Exception\PermissionDeniedException;
use Laminas\Diactoros\Response\JsonResponse;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;

/** GET /api/shutter/atrium-albums — the Atrium albums staff may publish winners to. */
class AtriumAlbumsController implements RequestHandlerInterface
{
    public function __construct(private Publisher $publisher)
    {
    }

    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        $actor = RequestUtil::getActor($request);

        if (! Access::canManage($actor)) {
            throw new PermissionDeniedException();
        }

        return new JsonResponse(['data' => $this->publisher->albums($actor)]);
    }
}
