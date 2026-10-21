<?php

namespace ErnestDefoe\Shutter\Model;

use Carbon\Carbon;
use Flarum\Database\AbstractModel;

/**
 * @property int $id
 * @property int $contest_id
 * @property int $entry_id
 * @property int $user_id
 * @property int $score
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
class Score extends AbstractModel
{
    protected $table = 'shutter_scores';

    public $timestamps = true;
}
