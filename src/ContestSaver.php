<?php

namespace ErnestDefoe\Shutter;

use Carbon\Carbon;
use ErnestDefoe\Shutter\Integration\Atrium\Publisher;
use ErnestDefoe\Shutter\Model\Contest;
use ErnestDefoe\Shutter\Model\Entry;
use Flarum\Discussion\Discussion;
use Flarum\Foundation\ValidationException;
use Flarum\Group\Group;
use Flarum\Locale\TranslatorInterface;
use Flarum\Settings\SettingsRepositoryInterface;
use Flarum\User\User;
use Throwable;

/**
 * Creating and editing a contest, from the discussion composer or from an
 * existing discussion. Everything a contest stores passes through clean().
 */
class ContestSaver
{
    public const COUNT = 'ernestdefoe-shutter.contest_count';

    public function __construct(
        private TranslatorInterface $translator,
        private SettingsRepositoryInterface $settings,
        private Publisher $publisher,
    ) {
    }

    /**
     * Validated, normalised attributes, or a ValidationException naming the
     * field. On an edit, only the fields sent are checked and returned.
     *
     * @param array<string, mixed> $in
     * @return array<string, mixed>
     */
    public function clean(array $in, ?Contest $existing = null, ?User $actor = null): array
    {
        $out = [];
        $has = fn (string $key) => $existing === null || array_key_exists($key, $in);

        if ($has('opensAt')) {
            $out['opens_at'] = $this->time($in['opensAt'] ?? null, 'opensAt', false);
        }

        foreach (['submissionsCloseAt' => 'submissions_close_at', 'votingCloseAt' => 'voting_close_at'] as $key => $column) {
            if ($has($key)) {
                $out[$column] = $this->time($in[$key] ?? null, $key, true);
            }
        }

        $opens = array_key_exists('opens_at', $out) ? $out['opens_at'] : $existing?->opens_at;
        $subs = $out['submissions_close_at'] ?? $existing?->submissions_close_at;
        $votes = $out['voting_close_at'] ?? $existing?->voting_close_at;

        if ($opens && $subs && $subs->lte($opens)) {
            $this->fail('submissionsCloseAt', 'after_opens');
        }

        if ($subs && $votes && $votes->lte($subs)) {
            $this->fail('votingCloseAt', 'after_submissions');
        }

        if ($has('rules')) {
            $rules = trim((string) ($in['rules'] ?? ''));
            if (mb_strlen($rules) > 5000) {
                $this->fail('rules', 'too_long', ['{max}' => 5000]);
            }
            $out['rules'] = $rules === '' ? null : $rules;
        }

        foreach ([
            'entriesPerMember' => ['entries_per_member', 1, 20, 1],
            'votesPerMember' => ['votes_per_member', 1, 50, 3],
            'winnersCount' => ['winners_count', 1, 10, 3],
            'voterMinDays' => ['voter_min_days', 0, 3650, 0],
            'voterMinPosts' => ['voter_min_posts', 0, 10000, 0],
        ] as $key => [$column, $min, $max, $default]) {
            if ($has($key)) {
                $value = $in[$key] ?? $default;
                if (! is_numeric($value) || (int) $value != $value || $value < $min || $value > $max) {
                    $this->fail($key, 'between', ['{min}' => $min, '{max}' => $max]);
                }
                $out[$column] = (int) $value;
            }
        }

        foreach (['namedVotes' => ['named_votes', false], 'liveCounts' => ['live_counts', false], 'hideEntrants' => ['hide_entrants', true], 'moderateEntries' => ['moderate_entries', false]] as $key => [$column, $default]) {
            if ($has($key)) {
                $out[$column] = (bool) ($in[$key] ?? $default);
            }
        }

        foreach (['enterGroupIds' => 'enter_group_ids', 'voteGroupIds' => 'vote_group_ids'] as $key => $column) {
            if ($has($key)) {
                $out[$column] = $this->groups($in[$key] ?? [], $key);
            }
        }

        if ($has('scoring')) {
            $scoring = $in['scoring'] ?? 'votes';
            if (! in_array($scoring, ['votes', 'judges'], true)) {
                $this->fail('scoring', 'invalid');
            }
            // 🚨 Once voting has begun the rules of the count are fixed: votes
            // already cast must not become scores, or the other way round.
            if ($existing && $scoring !== $existing->scoring && $existing->phase() !== Contest::UPCOMING && $existing->phase() !== Contest::SUBMISSIONS) {
                $this->fail('scoring', 'scoring_locked');
            }
            $out['scoring'] = $scoring;
        }

        if ($has('judges')) {
            $out['judge_ids'] = $this->judges($in['judges'] ?? []);
        }

        // Atrium (optional): the album the winners are published to.
        if (array_key_exists('atriumAlbumId', $in)) {
            $album = $in['atriumAlbumId'];
            if ($album === null || $album === '' || $album === 0 || $album === '0') {
                $out['atrium_album_id'] = null;
            } elseif (! is_numeric($album) || ! $actor || ! $this->publisher->canChoose((int) $album, $actor)) {
                $this->fail('atriumAlbumId', 'invalid');
            } else {
                $out['atrium_album_id'] = (int) $album;
            }
        }

        $scoring = $out['scoring'] ?? $existing->scoring ?? 'votes';
        $judges = array_key_exists('judge_ids', $out) ? $out['judge_ids'] : $existing?->judge_ids;

        if ($scoring === 'judges' && ! $judges) {
            $this->fail('judges', 'judges_required');
        }

        return $out;
    }

    /** @param array<string, mixed> $attributes */
    public function create(Discussion $discussion, User $actor, array $attributes): Contest
    {
        if (Contest::query()->where('discussion_id', $discussion->id)->exists()) {
            $this->fail('discussionId', 'already_contest');
        }

        $contest = new Contest();
        $contest->forceFill($attributes);
        $contest->discussion_id = (int) $discussion->id;
        $contest->user_id = (int) $actor->id;
        $contest->save();

        $this->recount();

        return $contest;
    }

    /** @param array<string, mixed> $attributes */
    public function update(Contest $contest, array $attributes, Results $results): Contest
    {
        $contest->forceFill($attributes);

        // Voting moved back into the future: the contest is open again, and
        // its placings are not results any more.
        if ($contest->finalized_at !== null && $contest->phase() !== Contest::CLOSED) {
            $contest->finalized_at = null;
            Entry::query()->where('contest_id', $contest->id)->update(['placement' => null]);
        }

        // Submissions re-opened: a "voting is open" alert is owed again later.
        if ($contest->voting_notified_at !== null && in_array($contest->phase(), [Contest::UPCOMING, Contest::SUBMISSIONS], true)) {
            $contest->voting_notified_at = null;
        }

        $contest->save();
        $results->finalize($contest);

        return $contest;
    }

    /**
     * A contest and everything in it, child tables first. The foreign keys
     * cascade on MySQL and PostgreSQL; this makes it so wherever they do
     * not (SQLite without foreign_key_constraints).
     */
    public function purge(int $contestId): void
    {
        $db = Contest::query()->getConnection();

        foreach (['shutter_votes', 'shutter_scores', 'shutter_entries'] as $table) {
            $db->table($table)->where('contest_id', $contestId)->delete();
        }

        $db->table('shutter_contests')->where('id', $contestId)->delete();
        $this->recount();
    }

    /**
     * How many contests exist, kept in a setting, which every request loads
     * anyway: on a forum with none, the discussion list asks nothing.
     */
    public function recount(): void
    {
        $this->settings->set(self::COUNT, (string) Contest::query()->count());
    }

    private function time(mixed $value, string $field, bool $required): ?Carbon
    {
        if ($value === null || $value === '') {
            if ($required) {
                $this->fail($field, 'required');
            }

            return null;
        }

        if (! is_string($value) || strlen($value) > 40) {
            $this->fail($field, 'invalid');
        }

        try {
            $time = Carbon::parse($value)->utc();
        } catch (Throwable) {
            $this->fail($field, 'invalid');
        }

        if ($time->year < 2000 || $time->year > 2100) {
            $this->fail($field, 'invalid');
        }

        return $time;
    }

    /** @return list<int>|null */
    private function groups(mixed $ids, string $field): ?array
    {
        if (! is_array($ids) || count($ids) > 100) {
            $this->fail($field, 'invalid');
        }

        $ids = array_values(array_unique(array_map('intval', array_filter($ids, 'is_numeric'))));
        // Guests can neither enter nor vote; picking them means nothing.
        $ids = array_values(array_diff($ids, [Group::GUEST_ID]));

        if (! $ids) {
            return null;
        }

        $known = Group::query()->whereIn('id', $ids)->pluck('id')->map(fn ($id) => (int) $id)->all();

        if (count($known) !== count($ids)) {
            $this->fail($field, 'invalid');
        }

        sort($known);

        return $known;
    }

    /**
     * Judges by username (what staff type) or id.
     *
     * @return list<int>|null
     */
    private function judges(mixed $names): ?array
    {
        if (is_string($names)) {
            $names = preg_split('/[\s,]+/', $names, -1, PREG_SPLIT_NO_EMPTY) ?: [];
        }

        if (! is_array($names) || count($names) > 25) {
            $this->fail('judges', 'invalid');
        }

        $names = array_values(array_unique(array_map(fn ($n) => ltrim(trim((string) $n), '@'), $names)));

        if (! $names) {
            return null;
        }

        // Usernames compared without case, on every database.
        $lower = array_map('mb_strtolower', $names);
        $users = User::query()
            ->where(fn ($q) => $q->whereIn($q->raw('LOWER(username)'), $lower)->orWhereIn('id', array_map('intval', array_filter($names, 'ctype_digit'))))
            ->pluck('username', 'id');
        $found = array_merge(array_map('mb_strtolower', $users->values()->all()), array_map('strval', $users->keys()->all()));
        $missing = array_values(array_filter($names, fn ($n) => ! in_array(mb_strtolower($n), $found, true)));

        if ($missing) {
            $this->fail('judges', 'unknown_judges', ['{names}' => implode(', ', $missing)]);
        }

        $ids = array_map('intval', $users->keys()->all());
        sort($ids);

        return $ids;
    }

    /** @param array<string, mixed> $replace */
    private function fail(string $field, string $key, array $replace = []): never
    {
        throw new ValidationException([$field => $this->translator->trans("ernestdefoe-shutter.api.contest.$key", $replace)]);
    }
}
