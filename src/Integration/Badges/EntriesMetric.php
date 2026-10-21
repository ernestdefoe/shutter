<?php

namespace ErnestDefoe\Shutter\Integration\Badges;

use ErnestDefoe\Shutter\Event\EntrySubmitted;
use Flarum\User\User;
use FoF\Badges\Trigger\MetricInterface;
use Illuminate\Database\ConnectionInterface;

/** A FoF Badges trigger: "photos entered in contests ≥ N". */
class EntriesMetric implements MetricInterface
{
    public function __construct(private ConnectionInterface $db)
    {
    }

    public function getType(): string
    {
        return 'shutter_entries';
    }

    public function getTranslationKey(): string
    {
        return 'ernestdefoe-shutter.admin.badges.entries';
    }

    public function getEventTriggers(): array
    {
        return [EntrySubmitted::class => fn (EntrySubmitted $e) => $e->actor];
    }

    public function getValue(User $user, array $config = []): int
    {
        return $this->db->table('shutter_entries')->where('user_id', $user->id)->whereNull('disqualified_at')->count();
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
