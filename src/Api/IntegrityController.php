<?php

namespace ErnestDefoe\Shutter\Api;

use Carbon\Carbon;
use ErnestDefoe\Shutter\Integrity;
use ErnestDefoe\Shutter\Model\Vote;
use ErnestDefoe\Shutter\Results;
use Flarum\Http\RequestUtil;
use Laminas\Diactoros\Response\JsonResponse;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;

/**
 * GET /api/shutter/contests/{id}/integrity — flagged votes, for staff.
 * POST the same with {voteIds: [...], voided: true|false} to void or restore.
 */
class IntegrityController implements RequestHandlerInterface
{
    use Concerns;

    public function __construct(private Integrity $integrity, private Results $results)
    {
    }

    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        $actor = RequestUtil::getActor($request);
        $contest = $this->manageableContest($this->routeId($request), $actor);

        if ($request->getMethod() === 'POST') {
            $body = $this->body($request);
            $ids = array_slice(array_map('intval', array_filter((array) ($body['voteIds'] ?? []), 'is_numeric')), 0, 1000);

            if ($ids) {
                Vote::query()->where('contest_id', $contest->id)->whereIn('id', $ids)
                    ->update(['voided_at' => empty($body['voided']) ? null : Carbon::now()]);
                $this->results->rerank($contest);
            }
        }

        return new JsonResponse(['data' => $this->integrity->review($contest)]);
    }
}
