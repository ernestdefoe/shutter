<?php

namespace ErnestDefoe\Shutter\Console;

use Carbon\Carbon;
use ErnestDefoe\Shutter\Model\Contest;
use ErnestDefoe\Shutter\Notification\VotingOpenBlueprint;
use ErnestDefoe\Shutter\Results;
use Flarum\Console\AbstractCommand;
use Flarum\Notification\NotificationSyncer;
use Flarum\User\User;

/**
 * `php flarum shutter:tick` — run by the scheduler every five minutes.
 *
 * Tells entrants when voting opens and decides contests that have closed.
 * Phases themselves never wait for this: they are read off the clock. A
 * forum with no scheduler still decides a contest the first time somebody
 * looks at it; it only misses the "voting is open" alert.
 */
class TickCommand extends AbstractCommand
{
    public function __construct(private Results $results, private NotificationSyncer $notifications)
    {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->setName('shutter:tick')->setDescription('Announce photo contest voting and decide closed contests.');
    }

    protected function fire(): int
    {
        $now = Carbon::now();

        $opening = Contest::query()->with('discussion')->whereNull('voting_notified_at')
            ->where('submissions_close_at', '<=', $now)->where('voting_close_at', '>', $now)->get();

        foreach ($opening as $contest) {
            // Claimed first, so two overlapping runs alert once.
            if (! Contest::query()->whereKey($contest->id)->whereNull('voting_notified_at')->update(['voting_notified_at' => $now])) {
                continue;
            }

            $entrants = User::query()->whereIn('id', $contest->entries()->inRunning()->whereNotNull('user_id')->select('user_id'))->get();

            if ($contest->discussion && $entrants->isNotEmpty() && ! $contest->judgeScored()) {
                $this->notifications->sync(new VotingOpenBlueprint($contest->discussion), $entrants->all());
            }
        }

        $closed = Contest::query()->with('discussion')->whereNull('finalized_at')->where('voting_close_at', '<=', $now)->get();

        foreach ($closed as $contest) {
            $this->results->finalize($contest);
        }

        $this->info(sprintf('%d opened for voting, %d decided.', $opening->count(), $closed->count()));

        return 0;
    }
}
