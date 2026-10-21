<?php

namespace ErnestDefoe\Shutter\Api;

use Carbon\Carbon;
use ErnestDefoe\Shutter\Access;
use ErnestDefoe\Shutter\Files;
use ErnestDefoe\Shutter\Model\Contest;
use ErnestDefoe\Shutter\Model\Entry;
use ErnestDefoe\Shutter\Presenter;
use ErnestDefoe\Shutter\Results;
use Flarum\Http\RequestUtil;
use Flarum\User\Exception\PermissionDeniedException;
use Flarum\User\User;
use Illuminate\Database\ConnectionInterface;
use Laminas\Diactoros\Response\EmptyResponse;
use Laminas\Diactoros\Response\JsonResponse;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;

/**
 * GET, PATCH and DELETE /api/shutter/entries/{id}.
 *
 * The entrant may retitle or withdraw their photo while submissions are
 * open — not once voting starts, when a withdrawal would throw away other
 * people's votes. Staff may edit, approve, disqualify, reinstate or delete
 * at any time; in a decided contest the placings follow.
 */
class EntryController implements RequestHandlerInterface
{
    use Concerns;

    public function __construct(private Presenter $presenter, private Results $results, private Files $files, private ConnectionInterface $db)
    {
    }

    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        $actor = RequestUtil::getActor($request);
        $entry = $this->visibleEntry($this->routeId($request), $actor);
        $contest = $entry->contest;
        $manage = Access::canManage($actor);

        // GET: one photo, for a link straight to it (?photo= on the discussion).
        if ($request->getMethod() === 'GET') {
            return new JsonResponse(['data' => $this->presenter->entries(collect([$entry->load('user')]), $contest, $actor)[0]]);
        }

        if (! $manage && ! $this->ownerMayChange($entry, $contest, $actor)) {
            throw new PermissionDeniedException();
        }

        if ($request->getMethod() === 'DELETE') {
            // Children first: SQLite without foreign keys would keep them,
            // and a kept vote would hold its voter's slot.
            $this->db->table('shutter_votes')->where('entry_id', $entry->id)->delete();
            $this->db->table('shutter_scores')->where('entry_id', $entry->id)->delete();
            $entry->delete();
            $this->files->release([$entry]);
            $this->results->rerank($contest);

            return new EmptyResponse(204);
        }

        $body = $this->body($request);

        if (array_key_exists('title', $body)) {
            $title = trim((string) $body['title']);
            if ($title === '' || mb_strlen($title) > 100) {
                $this->refuse('title', 'entry.title', ['{max}' => 100]);
            }
            $entry->title = $title;
        }

        if (array_key_exists('caption', $body)) {
            $caption = trim((string) $body['caption']);
            if (mb_strlen($caption) > 500) {
                $this->refuse('caption', 'entry.caption', ['{max}' => 500]);
            }
            $entry->caption = $caption === '' ? null : $caption;
        }

        $standing = false;

        if ($manage && array_key_exists('approved', $body)) {
            $entry->approved_at = $body['approved'] ? ($entry->approved_at ?? Carbon::now()) : null;
            $standing = true;
        }

        if ($manage && array_key_exists('disqualified', $body)) {
            $entry->disqualified_at = $body['disqualified'] ? ($entry->disqualified_at ?? Carbon::now()) : null;
            $standing = true;
        }

        // In a moderated contest an approved photo whose words change goes back
        // to the moderators, or approval would only ever cover the first draft.
        if (! $manage && $contest->moderate_entries && $entry->isDirty(['title', 'caption'])) {
            $entry->approved_at = null;
        }

        $entry->save();

        if ($standing) {
            $this->results->rerank($contest);
            $entry->refresh();
        }

        return new JsonResponse(['data' => $this->presenter->entries(collect([$entry->load('user')]), $contest, $actor)[0]]);
    }

    private function ownerMayChange(Entry $entry, Contest $contest, User $actor): bool
    {
        // Never a disqualified photo: withdrawing it and entering it again
        // would undo the moderators' decision.
        return $entry->user_id !== null
            && $entry->user_id === $actor->id
            && $entry->disqualified_at === null
            && ($contest->phase() === Contest::SUBMISSIONS || ($entry->approved_at === null && $contest->phase() !== Contest::CLOSED));
    }
}
