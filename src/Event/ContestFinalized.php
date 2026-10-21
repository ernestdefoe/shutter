<?php

namespace ErnestDefoe\Shutter\Event;

use ErnestDefoe\Shutter\Model\Contest;

/** Placings were written. `$placed` are the members whose placing is new. */
class ContestFinalized
{
    /** @param list<int> $placed */
    public function __construct(public Contest $contest, public array $placed)
    {
    }
}
