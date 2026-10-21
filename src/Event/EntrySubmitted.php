<?php

namespace ErnestDefoe\Shutter\Event;

use ErnestDefoe\Shutter\Model\Entry;
use Flarum\User\User;

class EntrySubmitted
{
    public function __construct(public Entry $entry, public User $actor)
    {
    }
}
