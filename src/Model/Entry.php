<?php

namespace ErnestDefoe\Shutter\Model;

use Carbon\Carbon;
use Flarum\Database\AbstractModel;
use Flarum\User\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property int $id
 * @property int $contest_id
 * @property int|null $user_id
 * @property int|null $slot
 * @property string $title
 * @property string|null $caption
 * @property string $hash
 * @property string $ext
 * @property int $width
 * @property int $height
 * @property int $size
 * @property string|null $blur
 * @property array<string, string>|null $camera
 * @property string|null $ip_hash
 * @property Carbon|null $approved_at
 * @property Carbon|null $disqualified_at
 * @property int|null $placement
 * @property int|null $atrium_image_id
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property Contest $contest
 * @property User|null $user
 */
class Entry extends AbstractModel
{
    protected $table = 'shutter_entries';

    public $timestamps = true;

    protected $casts = [
        'camera' => 'array',
        'approved_at' => 'datetime',
        'disqualified_at' => 'datetime',
        'width' => 'integer',
        'height' => 'integer',
        'size' => 'integer',
        'placement' => 'integer',
    ];

    /**
     * Entries in the running: approved and not disqualified. What voters see,
     * what may be voted for, and what is ranked.
     *
     * @param Builder<self> $query
     */
    public function scopeInRunning(Builder $query): void
    {
        $query->whereNotNull('shutter_entries.approved_at')->whereNull('shutter_entries.disqualified_at');
    }

    /** @return BelongsTo<Contest, $this> */
    public function contest(): BelongsTo
    {
        return $this->belongsTo(Contest::class);
    }

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
