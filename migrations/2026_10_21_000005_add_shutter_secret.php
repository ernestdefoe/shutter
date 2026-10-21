<?php

use Flarum\Database\Migration;

// The key for the network hashes on votes and entries: per forum, never
// serialised to the frontend, so a hash cannot be reversed by trying every
// address against a known key.
return Migration::addSettings([
    'ernestdefoe-shutter.secret' => bin2hex(random_bytes(16)),
]);
