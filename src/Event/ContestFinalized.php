<?php

namespace ErnestDefoe\Shutter\Event;

use ErnestDefoe\Shutter\Model\Contest;

/**
 * Placings were written. `$placed` are the members whose placing is new;
 * `$entrants` everyone who entered, the first time a contest is decided.
 */
class ContestFinalized
{
    /**
     * @param list<int> $placed
     * @param list<int> $entrants
     */
    public function __construct(public Contest $contest, public array $placed, public array $entrants = [])
    {
    }
}
