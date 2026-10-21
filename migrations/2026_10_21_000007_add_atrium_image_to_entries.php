<?php

use Flarum\Database\Migration;

return Migration::addColumns('shutter_entries', [
    'atrium_image_id' => ['integer', 'unsigned' => true, 'nullable' => true],
]);
