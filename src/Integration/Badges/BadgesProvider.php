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
            if ($event->placed) {
                foreach (User::query()->whereIn('id', $event->placed)->get() as $user) {
                    $this->evaluate($user, ContestFinalized::class);
                }
            }
        });

        $events->listen(EntrySubmitted::class, fn (EntrySubmitted $event) => $this->evaluate($event->actor, EntrySubmitted::class));
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
