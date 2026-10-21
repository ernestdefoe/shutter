<?php

namespace ErnestDefoe\Shutter\Model;

use Carbon\Carbon;
use Flarum\Database\AbstractModel;

/**
 * @property int $id
 * @property int $contest_id
 * @property int $entry_id
 * @property int $user_id
 * @property int $slot
 * @property string|null $ip_hash
 * @property Carbon|null $voided_at
 * @property Carbon|null $created_at
 */
class Vote extends AbstractModel
{
    protected $table = 'shutter_votes';

    protected $casts = ['voided_at' => 'datetime', 'created_at' => 'datetime'];
}
