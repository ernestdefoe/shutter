<?php

namespace ErnestDefoe\Shutter\Api;

use ErnestDefoe\Shutter\ContestSaver;
use ErnestDefoe\Shutter\Files;
use ErnestDefoe\Shutter\Model\Entry;
use Flarum\Http\RequestUtil;
use Laminas\Diactoros\Response\EmptyResponse;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;

/**
 * DELETE /api/shutter/contests/{id} — the contest, its entries, votes and
 * files. The discussion stays: it is the forum's, not the contest's.
 */
class DeleteContestController implements RequestHandlerInterface
{
    use Concerns;

    public function __construct(private Files $files, private ContestSaver $saver)
    {
    }

    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        $actor = RequestUtil::getActor($request);
        $contest = $this->manageableContest($this->routeId($request), $actor);
        $entries = Entry::query()->where('contest_id', $contest->id)->get(['hash', 'ext']);

        $this->saver->purge($contest->id);
        $this->files->release($entries);

        return new EmptyResponse(204);
    }
}
