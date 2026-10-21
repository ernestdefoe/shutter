<?php

namespace ErnestDefoe\Shutter\Api;

use Carbon\Carbon;
use ErnestDefoe\Shutter\Access;
use ErnestDefoe\Shutter\Model\Score;
use Flarum\Http\RequestUtil;
use Flarum\User\Exception\PermissionDeniedException;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Database\QueryException;
use Laminas\Diactoros\Response\JsonResponse;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;

/**
 * PUT /api/shutter/entries/{id}/score — a judge's 1–10 for an entry, or null
 * to take it back. Only while voting is open, only by the contest's judges,
 * never for their own entry (a judge cannot enter, but staff can make a
 * former entrant a judge).
 */
class ScoreController implements RequestHandlerInterface
{
    use Concerns;

    public function __construct(private ConnectionInterface $db)
    {
    }

    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        $actor = RequestUtil::getActor($request);
        $entry = $this->visibleEntry($this->routeId($request), $actor);
        $contest = $entry->contest;

        if ($block = Access::scoreBlock($actor, $contest)) {
            $block === 'not_judge' ? throw new PermissionDeniedException() : $this->refuse('score', "block.$block");
        }

        if ($entry->approved_at === null || $entry->disqualified_at !== null) {
            $this->refuse('score', 'vote.not_running');
        }

        if ($entry->user_id === $actor->id) {
            $this->refuse('score', 'vote.own');
        }

        $value = $this->body($request)['score'] ?? null;
        $mine = Score::query()->where('entry_id', $entry->id)->where('user_id', $actor->id);

        if ($value === null) {
            $mine->delete();

            return new JsonResponse(['data' => ['myScore' => null]]);
        }

        if (! is_numeric($value) || (int) $value != $value || $value < 1 || $value > 10) {
            $this->refuse('score', 'score.range');
        }

        $attributes = ['score' => (int) $value, 'updated_at' => Carbon::now()];

        if (! (clone $mine)->update($attributes)) {
            try {
                $this->db->transaction(fn () => (new Score())->forceFill($attributes + [
                    'contest_id' => $contest->id,
                    'entry_id' => $entry->id,
                    'user_id' => $actor->id,
                    'created_at' => Carbon::now(),
                ])->save());
            } catch (QueryException) {
                // A double click inserted it first; this value wins.
                (clone $mine)->update($attributes);
            }
        }

        return new JsonResponse(['data' => ['myScore' => (int) $value]]);
    }
}
