<?php

namespace ErnestDefoe\Shutter\Tests\integration\api;

use Carbon\Carbon;
use ErnestDefoe\Shutter\Tests\integration\ShutterTestCase;
use Illuminate\Database\QueryException;
use PHPUnit\Framework\Attributes\Test;

class VoteTest extends ShutterTestCase
{
    /** @return array{0: int, 1: int, 2: int} contest, alice's entry, bob's entry */
    private function running(array $settings = []): array
    {
        $contest = $this->contest(1, $settings);
        $a = $this->entryId($this->submit($contest, 4, null, 'Alice at dawn'));
        $b = $this->entryId($this->submit($contest, 5, null, 'Bob at dusk'));
        $this->toVoting();

        return [$contest, $a, $b];
    }

    #[Test]
    public function voting_is_only_while_voting_is_open(): void
    {
        $contest = $this->contest();
        $a = $this->entryId($this->submit($contest, 4));

        $this->assertSame(422, $this->vote($a, 5)->getStatusCode());
        $this->toVoting();
        $this->assertSame(200, $this->vote($a, 5)->getStatusCode());
        $this->toClosed();
        $this->assertSame(422, $this->vote($a, 6)->getStatusCode());
        $this->assertSame(422, $this->vote($a, 5, 'DELETE')->getStatusCode());
    }

    #[Test]
    public function nobody_votes_for_themselves_or_twice_or_past_the_limit(): void
    {
        [$contest, $a, $b] = $this->running(['votesPerMember' => 1]);

        $this->assertSame(422, $this->vote($a, 4)->getStatusCode(), 'own entry');
        $this->assertSame(200, $this->vote($b, 4)->getStatusCode());
        $this->assertSame(200, $this->vote($b, 4)->getStatusCode(), 'repeat is a no-op');
        $this->assertSame(1, $this->database()->table('shutter_votes')->where('user_id', 4)->count());

        $c = $this->database()->table('shutter_entries')->insertGetId((array) $this->database()->table('shutter_entries')->where('id', $b)->first() ? ['contest_id' => $contest, 'user_id' => 6, 'slot' => 1, 'title' => 'C', 'hash' => str_repeat('c', 64), 'ext' => 'webp', 'width' => 1, 'height' => 1, 'size' => 1, 'approved_at' => Carbon::now()] : []);
        $this->assertSame(422, $this->vote($c, 4)->getStatusCode(), 'over the limit of one');

        // Taking it back frees the vote.
        $this->assertSame(200, $this->vote($b, 4, 'DELETE')->getStatusCode());
        $this->assertSame(200, $this->vote($c, 4)->getStatusCode());
    }

    #[Test]
    public function the_indexes_refuse_what_a_race_would_slip_through(): void
    {
        [$contest, $a, $b] = $this->running(['votesPerMember' => 1]);
        $this->assertSame(200, $this->vote($b, 4)->getStatusCode());
        $row = (array) $this->database()->table('shutter_votes')->first();
        unset($row['id']);

        // Same entry twice.
        try {
            $this->database()->transaction(fn () => $this->database()->table('shutter_votes')->insert($row));
            $this->fail('a second vote for the same entry was stored');
        } catch (QueryException) {
        }

        // A second vote in the only slot.
        $this->expectException(QueryException::class);
        $this->database()->table('shutter_votes')->insert(['entry_id' => $a] + $row);
    }

    #[Test]
    public function voter_eligibility(): void
    {
        [$contest, $a] = $this->running(['voterMinDays' => 30, 'voterMinPosts' => 2, 'voteGroupIds' => [3]]);

        // normal (2) joined just now with no posts.
        $this->assertSame(422, $this->vote($a, 2)->getStatusCode());
        $this->database()->table('users')->where('id', 2)->update(['joined_at' => Carbon::now()->subYear()]);
        $this->assertSame(422, $this->vote($a, 2)->getStatusCode());
        $this->database()->table('users')->where('id', 2)->update(['comment_count' => 2]);
        $this->assertSame(200, $this->vote($a, 2)->getStatusCode());

        $guest = $this->send($this->request('POST', "/api/shutter/entries/$a/vote"));
        $this->assertContains($guest->getStatusCode(), [400, 401, 403]);
    }

    #[Test]
    public function a_judged_contest_takes_no_votes_and_only_judges_score(): void
    {
        [$contest, $a, $b] = $this->running(['scoring' => 'judges', 'judges' => 'carol']);

        $this->assertSame(422, $this->vote($a, 2)->getStatusCode());

        $score = fn (int $entry, int $user, mixed $value) => $this->send($this->request('PUT', "/api/shutter/entries/$entry/score", ['authenticatedAs' => $user, 'json' => ['score' => $value]]));
        $this->assertSame(403, $score($a, 2, 7)->getStatusCode());
        $this->assertSame(422, $score($a, 6, 11)->getStatusCode());
        $this->assertSame(422, $score($a, 6, 'ten')->getStatusCode());
        $this->assertSame(200, $score($a, 6, 7)->getStatusCode());
        $this->assertSame(200, $score($a, 6, 9)->getStatusCode());
        $this->assertSame(9, (int) $this->database()->table('shutter_scores')->where('entry_id', $a)->value('score'));
        $this->assertSame(1, $this->database()->table('shutter_scores')->count());

        // No running average for anybody but staff while judging goes on.
        $shown = $this->show($contest, 6)['entries'];
        $this->assertArrayNotHasKey('score', $shown[0]);
        $this->assertSame(9, collect($shown)->firstWhere('id', $a)['myScore']);
    }

    #[Test]
    public function names_and_counts_stay_hidden_while_people_vote(): void
    {
        [$contest, $a, $b] = $this->running(['namedVotes' => true]);
        $this->vote($a, 5);

        $response = $this->send($this->request('GET', "/api/shutter/contests/$contest", ['authenticatedAs' => 6]));
        $raw = (string) $response->getBody();
        $this->assertStringNotContainsString('alice', $raw);
        $this->assertStringNotContainsString('"bob"', $raw);
        $this->assertStringNotContainsString('"votes":', $raw);
        // "Top" would leak the running order; it is not on offer yet.
        $this->assertSame('shuffle', $this->show($contest, 6, ['sort' => 'top'])['sort']);
        $this->assertSame(403, $this->send($this->request('GET', "/api/shutter/entries/$a/voters", ['authenticatedAs' => 6]))->getStatusCode());

        // Entrants see their own; staff see all.
        $mine = collect($this->show($contest, 4)['entries'])->firstWhere('id', $a);
        $this->assertSame('alice', $mine['user']['username']);
        $this->assertSame(1, collect($this->show($contest, 3)['entries'])->firstWhere('id', $a)['votes']);

        $this->toClosed();
        $after = collect($this->show($contest, 6)['entries'])->firstWhere('id', $a);
        $this->assertSame('alice', $after['user']['username']);
        $this->assertSame(1, $after['votes']);
        $voters = $this->json($this->send($this->request('GET', "/api/shutter/entries/$a/voters", ['authenticatedAs' => 6])))['data'];
        $this->assertSame(['bob'], array_column($voters, 'username'));
    }

    #[Test]
    public function anonymous_votes_never_name_the_voter(): void
    {
        [$contest, $a] = $this->running();
        $this->vote($a, 5);
        $this->toClosed();

        $this->assertSame(403, $this->send($this->request('GET', "/api/shutter/entries/$a/voters", ['authenticatedAs' => 6]))->getStatusCode());
    }

    #[Test]
    public function live_counts_when_the_contest_says_so(): void
    {
        [$contest, $a] = $this->running(['liveCounts' => true]);
        $this->assertSame(1, $this->json($this->vote($a, 5))['data']['votes']);
        $this->assertSame(1, collect($this->show($contest, 6)['entries'])->firstWhere('id', $a)['votes']);
    }

    #[Test]
    public function a_disqualified_entry_takes_no_votes(): void
    {
        [$contest, $a] = $this->running();
        $this->send($this->request('PATCH', "/api/shutter/entries/$a", ['authenticatedAs' => 3, 'json' => ['disqualified' => true]]));

        $this->assertSame(404, $this->vote($a, 5)->getStatusCode());
        $this->assertNotContains($a, array_column($this->show($contest, 5)['entries'], 'id'));
    }
}
