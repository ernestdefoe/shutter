<?php

namespace ErnestDefoe\Shutter\Integration\Badges;

use ErnestDefoe\Shutter\Event\ContestFinalized;
use ErnestDefoe\Shutter\Event\EntrySubmitted;
use Flarum\Foundation\AbstractServiceProvider;
use Flarum\User\User;
use FoF\Badges\BadgeAwarder;
use FoF\Badges\Trigger\MetricManager;
use FoF\Badges\Trigger\TriggerEvaluator;
use Illuminate\Contracts\Events\Dispatcher;

/**
 * Shutter's three FoF Badges triggers — contests won, podium finishes,
 * photos entered — and the listener that evaluates them.
 *
 * 🚨 FoF Badges' own listener only recognises the events it knows, so
 * Shutter asks the evaluator itself, for each member a result touched.
 *
 * Registered from extend.php only when fof-badges is enabled.
 */
class BadgesProvider extends AbstractServiceProvider
{
    public function register(): void
    {
        $this->container->extend(MetricManager::class, function (MetricManager $manager) {
            $manager->register($this->container->make(WinsMetric::class));
            $manager->register($this->container->make(PodiumsMetric::class));
            $manager->register($this->container->make(EntriesMetric::class));

            return $manager;
        });
    }

    public function boot(Dispatcher $events): void
    {
        $events->listen(ContestFinalized::class, function (ContestFinalized $event): void {
            foreach (User::query()->whereIn('id', $event->placed ?: [0])->get() as $user) {
                $this->evaluate($user, ContestFinalized::class);
            }

            // 🚨 In a contest that hides who entered, the "photos entered"
            // badge waits for the result: awarded at the moment of entry, its
            // timestamp on a profile would name the photographer.
            if ($event->contest->hide_entrants) {
                foreach (User::query()->whereIn('id', $event->entrants ?: [0])->get() as $user) {
                    $this->evaluate($user, EntrySubmitted::class);
                }
            }
        });

        $events->listen(EntrySubmitted::class, function (EntrySubmitted $event): void {
            if (! $event->entry->contest->hide_entrants) {
                $this->evaluate($event->actor, EntrySubmitted::class);
            }
        });
    }

    private function evaluate(User $user, string $eventClass): void
    {
        $evaluator = $this->container->make(TriggerEvaluator::class);
        $awarder = $this->container->make(BadgeAwarder::class);

        foreach ($evaluator->evaluateForEvent($user, $eventClass) as $badge) {
            $awarder->award($user, $badge);
        }
    }
}
