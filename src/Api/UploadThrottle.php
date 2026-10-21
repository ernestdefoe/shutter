<?php

namespace ErnestDefoe\Shutter\Api;

use Flarum\Http\RequestUtil;
use Illuminate\Contracts\Cache\Repository as Cache;
use Psr\Http\Message\ServerRequestInterface;

/**
 * Six photo uploads a minute per member. Counted on every attempt, accepted
 * or not: a refused upload has still been read and perhaps decoded, which is
 * the cost being limited.
 */
class UploadThrottle
{
    public const PER_MINUTE = 6;

    public function __construct(private Cache $cache)
    {
    }

    public function __invoke(ServerRequestInterface $request): ?bool
    {
        if ($request->getAttribute('routeName') !== 'shutter.entries.create') {
            return null;
        }

        $actor = RequestUtil::getActor($request);

        if ($actor->isAdmin()) {
            return false;
        }

        // A minute from the member's first upload, not the clock's minute: a
        // clock window lets twice the limit through either side of :00.
        $key = 'shutter-uploads:'.$actor->id;
        $this->cache->add($key, 0, 60);

        return $this->cache->increment($key) > self::PER_MINUTE;
    }
}
