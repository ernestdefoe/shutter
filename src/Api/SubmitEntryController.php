<?php

namespace ErnestDefoe\Shutter\Api;

use Carbon\Carbon;
use ErnestDefoe\Shutter\Access;
use ErnestDefoe\Shutter\Event\EntrySubmitted;
use ErnestDefoe\Shutter\Media\Intake;
use ErnestDefoe\Shutter\Media\Network;
use ErnestDefoe\Shutter\Media\Store;
use ErnestDefoe\Shutter\Model\Entry;
use ErnestDefoe\Shutter\Presenter;
use Flarum\Http\RequestUtil;
use Flarum\User\Exception\PermissionDeniedException;
use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Database\QueryException;
use Illuminate\Support\Arr;
use Laminas\Diactoros\Response\JsonResponse;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Throwable;

/**
 * POST /api/shutter/contests/{id}/entries — multipart: `photo`, `title`,
 * `caption`.
 *
 * 🚨 The entry limit holds under concurrent uploads without a lock: the row
 * takes the member's first free slot and the unique index refuses a second
 * row in the same one. Two uploads racing for the last slot: one is entered,
 * the other is told the limit is reached.
 */
class SubmitEntryController implements RequestHandlerInterface
{
    use Concerns;

    public function __construct(
        private Intake $intake,
        private Store $store,
        private Network $network,
        private Presenter $presenter,
        private Dispatcher $events,
        private ConnectionInterface $db,
    ) {
    }

    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        $actor = RequestUtil::getActor($request);
        $contest = $this->visibleContest($this->routeId($request), $actor);

        if ($block = Access::enterBlock($actor, $contest)) {
            $actor->isGuest() ? throw new PermissionDeniedException() : $this->refuse('photo', "block.$block");
        }

        $body = (array) $request->getParsedBody();
        $title = trim((string) Arr::get($body, 'title', ''));
        $caption = trim((string) Arr::get($body, 'caption', ''));

        if ($title === '' || mb_strlen($title) > 100) {
            $this->refuse('title', 'entry.title', ['{max}' => 100]);
        }

        if (mb_strlen($caption) > 500) {
            $this->refuse('caption', 'entry.caption', ['{max}' => 500]);
        }

        // Checked before the photo is decoded, which is the expensive part.
        $used = Entry::query()->where('contest_id', $contest->id)->where('user_id', $actor->id)->pluck('slot')->all();
        $slot = $this->freeSlot($used, $contest->entries_per_member);

        if ($slot === null) {
            $this->refuse('photo', 'entry.limit', ['{count}' => $contest->entries_per_member]);
        }

        $upload = $this->intake->inspect(Arr::get($request->getUploadedFiles(), 'photo'));

        // Refused before the decode, so sending the same file again costs nothing.
        if (Entry::query()->where('contest_id', $contest->id)->where('hash', $upload['hash'])->exists()) {
            $this->refuse('photo', 'entry.duplicate');
        }

        $photo = $this->intake->derive($upload);

        $entry = new Entry();
        $entry->forceFill([
            'contest_id' => $contest->id,
            'user_id' => $actor->id,
            'slot' => $slot,
            'title' => $title,
            'caption' => $caption === '' ? null : $caption,
            'hash' => $photo['hash'],
            'ext' => $photo['ext'],
            'width' => $photo['width'],
            'height' => $photo['height'],
            'size' => $photo['size'],
            'blur' => $photo['blur'],
            'camera' => $photo['camera'],
            'ip_hash' => $this->network->hash($request->getAttribute('ipAddress')),
            // Staff are never asked to approve their own entry.
            'approved_at' => $contest->moderate_entries && ! Access::canManage($actor) ? null : Carbon::now(),
        ]);

        try {
            // Its own transaction: on PostgreSQL a refused INSERT poisons any
            // transaction around it, and this keeps that to a savepoint.
            $this->db->transaction(fn () => $entry->save());
        } catch (QueryException) {
            // Lost a race: the same photo, or the last slot, taken a moment ago.
            Entry::query()->where('contest_id', $contest->id)->where('hash', $photo['hash'])->count() > 0
                ? $this->refuse('photo', 'entry.duplicate')
                : $this->refuse('photo', 'entry.limit', ['{count}' => $contest->entries_per_member]);
        }

        try {
            $this->store->put($photo['hash'], $photo['ext'], $photo['files']);
        } catch (Throwable $e) {
            $entry->delete();

            throw $e;
        }

        $entry->setRelation('user', $actor);
        $entry->setRelation('contest', $contest);
        $this->events->dispatch(new EntrySubmitted($entry, $actor));

        return new JsonResponse(['data' => $this->presenter->entry($entry, $contest, $actor, [], [], [])], 201);
    }

    /** @param array<int, mixed> $used */
    private function freeSlot(array $used, int $limit): ?int
    {
        $used = array_map('intval', $used);

        for ($slot = 1; $slot <= $limit; $slot++) {
            if (! in_array($slot, $used, true)) {
                return $slot;
            }
        }

        return null;
    }
}
