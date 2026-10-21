<?php

namespace ErnestDefoe\Shutter\Api;

use Carbon\Carbon;
use ErnestDefoe\Shutter\Media\Store;
use ErnestDefoe\Shutter\Model\Contest;
use ErnestDefoe\Shutter\Model\Entry;
use Flarum\Http\RequestUtil;
use Illuminate\Database\ConnectionInterface;
use Laminas\Diactoros\Response\JsonResponse;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;

/**
 * GET /api/shutter/contests?status=active|past&offset= — the contests page.
 *
 * Four queries however many contests: the contests with their discussions,
 * entry counts grouped, and one cover photo each (the winner once decided,
 * otherwise the newest entry), fetched by id.
 */
class ListContestsController implements RequestHandlerInterface
{
    private const PAGE = 20;

    public function __construct(private Store $store, private ConnectionInterface $db)
    {
    }

    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        $actor = RequestUtil::getActor($request);
        $params = $request->getQueryParams();
        $past = ($params['status'] ?? 'active') === 'past';
        $offset = max(0, min(100000, (int) ($params['offset'] ?? 0)));
        $now = Carbon::now();

        $query = Contest::query()->visibleTo($actor)->with('discussion');
        $past
            ? $query->where('voting_close_at', '<=', $now)->orderByDesc('voting_close_at')
            : $query->where('voting_close_at', '>', $now)->orderBy('voting_close_at');

        $contests = $query->orderByDesc('id')->skip($offset)->take(self::PAGE + 1)->get();
        $next = $contests->count() > self::PAGE ? $offset + self::PAGE : null;
        $contests = $contests->take(self::PAGE);
        $ids = $contests->pluck('id')->all();

        $counts = $ids ? $this->db->table('shutter_entries')->whereIn('contest_id', $ids)
            ->whereNotNull('approved_at')->whereNull('disqualified_at')
            ->groupBy('contest_id')->selectRaw('contest_id, COUNT(*) as total, MAX(id) as newest, MIN(CASE WHEN placement = 1 THEN id END) as winner')
            ->get()->keyBy('contest_id') : collect();

        $coverIds = $counts->map(fn ($row) => (int) ($row->winner ?: $row->newest))->filter()->values()->all();
        $covers = $coverIds ? Entry::query()->whereIn('id', $coverIds)->get(['id', 'contest_id', 'hash', 'ext', 'blur'])->keyBy('contest_id') : collect();

        $data = $contests->map(function (Contest $contest) use ($counts, $covers) {
            $cover = $covers->get($contest->id);

            return [
                'id' => $contest->id,
                'phase' => $contest->phase(),
                'opensAt' => $contest->opens_at?->toIso8601String(),
                'submissionsCloseAt' => $contest->submissions_close_at->toIso8601String(),
                'votingCloseAt' => $contest->voting_close_at->toIso8601String(),
                'entryCount' => (int) ($counts->get($contest->id)->total ?? 0),
                'discussion' => [
                    'id' => $contest->discussion_id,
                    'title' => $contest->discussion?->title,
                    'slug' => $contest->discussion?->slug,
                ],
                'cover' => $cover ? ['thumb' => $this->store->urls($cover->hash, $cover->ext)['thumb'], 'blur' => $cover->blur] : null,
            ];
        })->values()->all();

        return new JsonResponse(['data' => $data, 'nextOffset' => $next]);
    }
}
