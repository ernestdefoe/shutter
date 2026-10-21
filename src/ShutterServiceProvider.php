<?php

namespace ErnestDefoe\Shutter;

use ErnestDefoe\Shutter\Api\DiscussionContest;
use ErnestDefoe\Shutter\Listener\ForgetFiles;
use Flarum\Foundation\AbstractServiceProvider;

class ShutterServiceProvider extends AbstractServiceProvider
{
    public function register(): void
    {
        // 🚨 Both hold state between two calls: the discussion batch between
        // queuing and resolving, the file listener between Deleting and
        // Deleted. Resolved fresh each time, both would forget it.
        $this->container->singleton(DiscussionContest::class);
        $this->container->singleton(ForgetFiles::class);
    }
}
