<?php

namespace ErnestDefoe\Shutter\Api;

use ErnestDefoe\Shutter\Access;
use ErnestDefoe\Shutter\Model\Contest;
use ErnestDefoe\Shutter\Model\Entry;
use Flarum\Foundation\ValidationException;
use Flarum\User\Exception\PermissionDeniedException;
use Flarum\User\User;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Arr;
use Psr\Http\Message\ServerRequestInterface;

/** The lookups every controller starts with. */
trait Concerns
{
    /** The id in the route, or 0 (not found) for one too big for an id column. */
    protected function routeId(ServerRequestInterface $request, string $name = 'id'): int
    {
        $id = (string) Arr::get($request->getAttributes(), 'routeParameters.'.$name);

        return strlen($id) <= 10 && (int) $id <= 2147483647 ? (int) $id : 0;
    }

    /** A contest the actor may see, or a 404 — the same answer whether it is missing or hidden. */
    protected function visibleContest(int $id, User $actor): Contest
    {
        return Contest::query()->visibleTo($actor)->findOrFail($id);
    }

    protected function manageableContest(int $id, User $actor): Contest
    {
        $contest = $this->visibleContest($id, $actor);

        if (! Access::canManage($actor)) {
            throw new PermissionDeniedException();
        }

        return $contest;
    }

    /**
     * An entry the actor may see, with its contest. In the running, or their
     * own, or anything to staff; otherwise a 404.
     */
    protected function visibleEntry(int $id, User $actor): Entry
    {
        $entry = Entry::query()->with('contest')->find($id);

        if (! $entry || ! Contest::query()->visibleTo($actor)->whereKey($entry->contest_id)->exists()) {
            throw new ModelNotFoundException();
        }

        $running = $entry->approved_at !== null && $entry->disqualified_at === null;

        if (! $running && ! ($entry->user_id && $entry->user_id === $actor->id) && ! Access::canManage($actor)) {
            throw new ModelNotFoundException();
        }

        return $entry;
    }

    /** @param array<string, mixed> $replace */
    protected function refuse(string $field, string $key, array $replace = []): never
    {
        throw new ValidationException([$field => resolve('translator')->trans("ernestdefoe-shutter.api.$key", $replace)]);
    }

    /** @return array<string, mixed> */
    protected function body(ServerRequestInterface $request): array
    {
        $body = $request->getParsedBody();
        $body = is_array($body) ? $body : [];

        return is_array($body['data'] ?? null) ? $body['data'] : $body;
    }
}
