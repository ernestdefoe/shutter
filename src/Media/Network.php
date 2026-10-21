<?php

namespace ErnestDefoe\Shutter\Media;

use Flarum\Settings\SettingsRepositoryInterface;

/**
 * A keyed hash of the network a request came from — the /24 of an IPv4
 * address, the /64 of an IPv6 one — so a vote from the entrant's own home can
 * be spotted without keeping anybody's address.
 */
class Network
{
    public function __construct(private SettingsRepositoryInterface $settings)
    {
    }

    public function hash(?string $ip): ?string
    {
        $packed = $ip ? @inet_pton($ip) : false;

        if ($packed === false) {
            return null;
        }

        // An IPv4 address written as IPv6 (::ffff:a.b.c.d) is the IPv4 address.
        if (strlen($packed) === 16 && str_starts_with($packed, str_repeat("\0", 10)."\xff\xff")) {
            $packed = substr($packed, 12);
        }

        $network = strlen($packed) === 4 ? substr($packed, 0, 3) : substr($packed, 0, 8);
        $key = (string) $this->settings->get('ernestdefoe-shutter.secret');

        return substr(hash_hmac('sha256', $network, $key !== '' ? $key : 'shutter'), 0, 16);
    }
}
