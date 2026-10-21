<?php

use Flarum\Database\Migration;

// Atrium (optional): the album a contest's winners are published to, and the
// picture each winner became there, so a photo is published once. No foreign
// keys: Atrium may not be installed, and a deleted album just stops publishing.
return Migration::addColumns('shutter_contests', [
    'atrium_album_id' => ['integer', 'unsigned' => true, 'nullable' => true],
]);
