<?php

namespace ErnestDefoe\Shutter\Api;

use ErnestDefoe\Shutter\ContestSaver;
use ErnestDefoe\Shutter\Model\Contest;
use Flarum\Discussion\Discussion;
use Flarum\Settings\SettingsRepositoryInterface;
use Psr\Http\Message\ServerRequestInterface;
use WeakMap;

/**
 * The `shutterContest` attribute on discussions: `{id, phase}` or null.
 *
 * 🚨 Batched. Each discussion queues its id and returns a deferred value;
 * the first one resolved loads every queued contest in ONE query, wherever
 * the discussions came from (the list, a post's include, a notification).
 * And on a forum with no contests at all, not even that: the count lives in
 * a setting every request has already loaded.
 */
class DiscussionContest
{
    /** @var WeakMap<ServerRequestInterface, array{queued: array<int, true>, loaded: array<int, array{id: int, phase: string}|null>}> */
    private WeakMap $state;

    public function __construct(private SettingsRepositoryInterface $settings)
    {
        $this->state = new WeakMap();
    }

    public function get(Discussion $discussion, ServerRequestInterface $request): ?\Closure
    {
        if (! (int) $this->settings->get(ContestSaver::COUNT)) {
            return null;
        }

        $state = $this->state[$request] ?? ['queued' => [], 'loaded' => []];
        $state['queued'][(int) $discussion->id] = true;
        $this->state[$request] = $state;

        return fn () => $this->resolve($request, (int) $discussion->id);
    }

    /** @return array{id: int, phase: string}|null */
    private function resolve(ServerRequestInterface $request, int $id): ?array
    {
        $state = $this->state[$request];
        $missing = array_keys(array_diff_key($state['queued'], $state['loaded']));

        if ($missing) {
            $found = Contest::query()->whereIn('discussion_id', $missing)
                ->get(['id', 'discussion_id', 'opens_at', 'submissions_close_at', 'voting_close_at'])
                ->keyBy('discussion_id');

            foreach ($missing as $discussionId) {
                $contest = $found->get($discussionId);
                $state['loaded'][$discussionId] = $contest ? ['id' => (int) $contest->id, 'phase' => $contest->phase()] : null;
            }

            $this->state[$request] = $state;
        }

        return $state['loaded'][$id] ?? null;
    }
}
