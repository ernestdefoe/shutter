<?php

namespace ErnestDefoe\Shutter;

use ErnestDefoe\Shutter\Media\Store;
use ErnestDefoe\Shutter\Model\Entry;

/**
 * Removing the files of entries that are gone.
 *
 * 🚨 Only when no other entry still uses the same picture: the same
 * photograph entered in two contests shares its files, and deleting on the
 * first delete would blank it in the other.
 */
class Files
{
    public function __construct(private Store $store)
    {
    }

    /** @param iterable<Entry|object{hash: string, ext: string}> $gone */
    public function release(iterable $gone): void
    {
        $byHash = [];

        foreach ($gone as $entry) {
            $byHash[$entry->hash] = $entry->ext;
        }

        if (! $byHash) {
            return;
        }

        $still = Entry::query()->whereIn('hash', array_keys($byHash))->pluck('hash')->all();

        foreach (array_diff_key($byHash, array_flip($still)) as $hash => $ext) {
            $this->store->forget((string) $hash, $ext);
        }
    }
}
