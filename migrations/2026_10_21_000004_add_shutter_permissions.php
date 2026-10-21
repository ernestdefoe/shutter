<?php

use Flarum\Database\Migration;
use Flarum\Group\Group;

// Members enter and vote; moderators run contests. A contest can narrow who
// enters and votes to particular groups, never widen it past these.
return Migration::addPermissions([
    'shutter.enter' => Group::MEMBER_ID,
    'shutter.vote' => Group::MEMBER_ID,
    'shutter.manage' => Group::MODERATOR_ID,
]);
