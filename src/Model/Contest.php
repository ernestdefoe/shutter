<?php

namespace ErnestDefoe\Shutter\Model;

use Carbon\Carbon;
use Flarum\Database\AbstractModel;
use Flarum\Discussion\Discussion;
use Flarum\User\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * @property int $id
 * @property int $discussion_id
 * @property int|null $user_id
 * @property Carbon|null $opens_at
 * @property Carbon $submissions_close_at
 * @property Carbon $voting_close_at
 * @property string|null $rules
 * @property int $entries_per_member
 * @property int $votes_per_member
 * @property list<int>|null $enter_group_ids
 * @property list<int>|null $vote_group_ids
 * @property string $scoring
 * @property list<int>|null $judge_ids
 * @property bool $named_votes
 * @property bool $live_counts
 * @property bool $hide_entrants
 * @property bool $moderate_entries
 * @property int $winners_count
 * @property int $voter_min_days
 * @property int $voter_min_posts
 * @property Carbon|null $voting_notified_at
 * @property Carbon|null $finalized_at
 * @property int|null $atrium_album_id
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property Discussion|null $discussion
 */
class Contest extends AbstractModel
{
    public const UPCOMING = 'upcoming';
    public const SUBMISSIONS = 'submissions';
    public const VOTING = 'voting';
    public const CLOSED = 'closed';

    protected $table = 'shutter_contests';

    public $timestamps = true;

    protected $casts = [
        'opens_at' => 'datetime',
        'submissions_close_at' => 'datetime',
        'voting_close_at' => 'datetime',
        'voting_notified_at' => 'datetime',
        'finalized_at' => 'datetime',
        'enter_group_ids' => 'array',
        'vote_group_ids' => 'array',
        'judge_ids' => 'array',
        'named_votes' => 'boolean',
        'live_counts' => 'boolean',
        'hide_entrants' => 'boolean',
        'moderate_entries' => 'boolean',
        'entries_per_member' => 'integer',
        'votes_per_member' => 'integer',
        'winners_count' => 'integer',
        'voter_min_days' => 'integer',
        'voter_min_posts' => 'integer',
    ];

    /** Where the contest is in its life, read off the clock. */
    public function phase(?Carbon $now = null): string
    {
        $now ??= Carbon::now();

        return match (true) {
            $this->opens_at !== null && $now->lt($this->opens_at) => self::UPCOMING,
            $now->lt($this->submissions_close_at) => self::SUBMISSIONS,
            $now->lt($this->voting_close_at) => self::VOTING,
            default => self::CLOSED,
        };
    }

    public function judgeScored(): bool
    {
        return $this->scoring === 'judges';
    }

    /** @return list<int> */
    public function judges(): array
    {
        return array_values(array_map('intval', $this->judge_ids ?? []));
    }

    public function isJudge(User $actor): bool
    {
        return $this->judgeScored() && ! $actor->isGuest() && in_array((int) $actor->id, $this->judges(), true);
    }

    /**
     * Whether results — counts, scores, placings — may be shown. Before the
     * contest closes only when the contest says so, and never for judges'
     * scores: a running average invites the last judge to tip it.
     */
    public function resultsPublic(): bool
    {
        return $this->phase() === self::CLOSED || ($this->live_counts && ! $this->judgeScored() && $this->phase() === self::VOTING);
    }

    /** Entrants' names are hidden while people are voting, if the contest says so. */
    public function entrantsHidden(): bool
    {
        return $this->hide_entrants && $this->phase() !== self::CLOSED;
    }

    /**
     * Contests whose discussion the actor can see. Visibility is the
     * discussion's: tags, private discussions, hidden ones, all of it.
     *
     * @param Builder<self> $query
     */
    public function scopeVisibleTo(Builder $query, User $actor): void
    {
        $query->whereIn('shutter_contests.discussion_id', Discussion::query()->whereVisibleTo($actor)->select('discussions.id'));
    }

    /** @return BelongsTo<Discussion, $this> */
    public function discussion(): BelongsTo
    {
        return $this->belongsTo(Discussion::class);
    }

    /** @return HasMany<Entry, $this> */
    public function entries(): HasMany
    {
        return $this->hasMany(Entry::class);
    }
}
