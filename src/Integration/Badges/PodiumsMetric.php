<?php

namespace ErnestDefoe\Shutter\Integration\Badges;

use ErnestDefoe\Shutter\Event\ContestFinalized;
use Flarum\User\User;
use FoF\Badges\Trigger\MetricInterface;
use Illuminate\Database\ConnectionInterface;

/** A FoF Badges trigger: "podium finishes in photo contests ≥ N". */
class PodiumsMetric implements MetricInterface
{
    public function __construct(private ConnectionInterface $db)
    {
    }

    public function getType(): string
    {
        return 'shutter_podiums';
    }

    public function getTranslationKey(): string
    {
        return 'ernestdefoe-shutter.admin.badges.podiums';
    }

    public function getEventTriggers(): array
    {
        return [ContestFinalized::class => fn (ContestFinalized $e) => null];
    }

    public function getValue(User $user, array $config = []): int
    {
        return $this->db->table('shutter_entries')->where('user_id', $user->id)->whereNotNull('placement')->count();
    }

    public function isBoolean(): bool
    {
        return false;
    }

    public function getConfigFields(): array
    {
        return [];
    }

    public function getExtensionDependencies(): array
    {
        return ['ernestdefoe/shutter'];
    }
}
