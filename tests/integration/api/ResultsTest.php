<?php

namespace ErnestDefoe\Shutter\Tests\integration\api;

use Carbon\Carbon;
use ErnestDefoe\Shutter\Console\TickCommand;
use ErnestDefoe\Shutter\Tests\integration\ShutterTestCase;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\BufferedOutput;

class ResultsTest extends ShutterTestCase
{
    /** @return array<int, int> entrant user id => entry id */
    private function field(int $contest): array
    {
        $entries = [];
        foreach ([4, 5, 6, 2] as $user) {
            $entries[$user] = $this->entryId($this->submit($contest, $user, null, "By $user"));
        }

        return $entries;
    }

    private function notifications(string $type): array
    {
        return $this->database()->table('notifications')->where('type', $type)->orderBy('user_id')->pluck('user_id')->map(fn ($id) => (int) $id)->all();
    }

    #[Test]
    public function the_first_look_after_closing_crowns_the_winners_once(): void
    {
        $contest = $this->contest(1, ['votesPerMember' => 3, 'winnersCount' => 3]);
        $e = $this->field($contest);
        $this->toVoting();

        // alice 2 votes, bob 2 (tie for first), carol 1, normal 0.
        $this->vote($e[4], 5);
        $this->vote($e[4], 6);
        $this->vote($e[5], 4);
        $this->vote($e[5], 6);
        $this->vote($e[6], 4);

        $this->toClosed();
        $first = $this->show($contest, 2);
        $this->show($contest, 3);

        $placing = $this->database()->table('shutter_entries')->pluck('placement', 'user_id')->map(fn ($p) => $p === null ? null : (int) $p)->all();
        $this->assertSame([4 => 1, 5 => 1, 6 => 3, 2 => null], [4 => $placing[4], 5 => $placing[5], 6 => $placing[6], 2 => $placing[2]]);
        $this->assertTrue($first['finalized']);
        $this->assertSame([1, 1, 3], array_column($first['podium'], 'placement'));
        $this->assertSame('top', $first['sort']);

        // Once each, however many times it is looked at.
        $this->assertSame([4, 5, 6], $this->notifications('shutterPlaced'));
        $this->assertSame([2], $this->notifications('shutterResults'));
    }

    #[Test]
    public function the_scheduler_announces_voting_and_decides_without_a_page_view(): void
    {
        $contest = $this->contest();
        $e = $this->field($contest);
        $tick = function () {
            $command = $this->app()->getContainer()->make(TickCommand::class);
            $command->run(new ArrayInput([]), new BufferedOutput());
        };

        $this->toVoting();
        $tick();
        $tick();
        $this->assertSame([2, 4, 5, 6], $this->notifications('shutterVotingOpen'));

        $this->vote($e[4], 5);
        $this->toClosed();
        $tick();
        $this->assertNotNull($this->database()->table('shutter_contests')->value('finalized_at'));
        $this->assertSame([4], $this->notifications('shutterPlaced'));
    }

    #[Test]
    public function disqualifying_a_winner_moves_the_podium_up(): void
    {
        $contest = $this->contest(1, ['winnersCount' => 1]);
        $e = $this->field($contest);
        $this->toVoting();
        $this->vote($e[4], 5);
        $this->vote($e[4], 6);
        $this->vote($e[5], 6);
        $this->toClosed();
        $this->show($contest, 2);
        $this->assertSame([4], $this->notifications('shutterPlaced'));

        $this->send($this->request('PATCH', "/api/shutter/entries/{$e[4]}", ['authenticatedAs' => 3, 'json' => ['disqualified' => true]]));

        $this->assertSame(1, (int) $this->database()->table('shutter_entries')->where('id', $e[5])->value('placement'));
        $this->assertNull($this->database()->table('shutter_entries')->where('id', $e[4])->value('placement'));
        $this->assertSame([4, 5], $this->notifications('shutterPlaced'));
    }

    #[Test]
    public function judges_average_decides_a_judged_contest(): void
    {
        $contest = $this->contest(1, ['scoring' => 'judges', 'judges' => 'mod,admin', 'winnersCount' => 2]);
        $e = [4 => $this->entryId($this->submit($contest, 4)), 5 => $this->entryId($this->submit($contest, 5)), 6 => $this->entryId($this->submit($contest, 6))];
        $this->toVoting();
        $score = fn (int $entry, int $user, int $value) => $this->send($this->request('PUT', "/api/shutter/entries/$entry/score", ['authenticatedAs' => $user, 'json' => ['score' => $value]]));
        $score($e[4], 3, 6);
        $score($e[4], 1, 10); // 8
        $score($e[5], 3, 9);
        $score($e[5], 1, 9);  // 9
        $score($e[6], 3, 5);  // 5

        $this->toClosed();
        $podium = $this->show($contest, 2)['podium'];

        $this->assertSame([$e[5], $e[4]], array_column($podium, 'id'));
        $this->assertEquals(9.0, $podium[0]['score']);
    }

    #[Test]
    public function extending_voting_reopens_a_decided_contest(): void
    {
        $contest = $this->contest();
        $e = $this->field($contest);
        $this->toVoting();
        $this->vote($e[4], 5);
        $this->toClosed();
        $this->show($contest, 2);

        $response = $this->send($this->request('PATCH', "/api/shutter/contests/$contest", ['authenticatedAs' => 3, 'json' => ['data' => ['votingCloseAt' => Carbon::now()->addDay()->toIso8601String()]]]));
        $this->assertSame(200, $response->getStatusCode(), (string) $response->getBody());

        $this->assertNull($this->database()->table('shutter_contests')->value('finalized_at'));
        $this->assertSame(0, $this->database()->table('shutter_entries')->whereNotNull('placement')->count());
        $this->assertSame(200, $this->vote($e[5], 4)->getStatusCode());
    }

    #[Test]
    public function the_scoring_method_is_fixed_once_voting_starts(): void
    {
        $contest = $this->contest();
        $this->toVoting();

        $response = $this->send($this->request('PATCH', "/api/shutter/contests/$contest", ['authenticatedAs' => 3, 'json' => ['data' => ['scoring' => 'judges', 'judges' => 'mod']]]));
        $this->assertSame(422, $response->getStatusCode());
    }

    #[Test]
    public function staff_see_votes_from_the_entrants_own_network_and_can_void_them(): void
    {
        $contest = $this->contest();
        $alice = $this->entryId($this->send($this->fromIp(
            '203.0.113.7',
            $this->request('POST', "/api/shutter/contests/$contest/entries", ['authenticatedAs' => 4])

                ->withParsedBody(['title' => 'x'])
                ->withUploadedFiles(['photo' => \ErnestDefoe\Shutter\Tests\Photos::upload(\ErnestDefoe\Shutter\Tests\Photos::jpeg(300, 300, random_int(1, 2 ** 31 - 1)))])
        )));
        $this->toVoting();
        // The sock puppet votes from the same /24; carol from elsewhere.
        $this->send($this->fromIp('203.0.113.99', $this->request('POST', "/api/shutter/entries/$alice/vote", ['authenticatedAs' => 5])));
        $this->send($this->fromIp('198.51.100.1', $this->request('POST', "/api/shutter/entries/$alice/vote", ['authenticatedAs' => 6])));

        $this->assertSame(403, $this->send($this->request('GET', "/api/shutter/contests/$contest/integrity", ['authenticatedAs' => 4]))->getStatusCode());
        $review = $this->json($this->send($this->request('GET', "/api/shutter/contests/$contest/integrity", ['authenticatedAs' => 3])))['data'];

        $flags = collect($review['entries'][0]['flagged'])->keyBy(fn ($f) => $f['voter']['username']);
        $this->assertContains('same_network', $flags['bob']['reasons']);
        $this->assertNotContains('same_network', $flags['carol']['reasons'] ?? []);
        $this->assertStringNotContainsString('203.0.113', json_encode($review));

        $this->send($this->request('POST', "/api/shutter/contests/$contest/integrity", ['authenticatedAs' => 3, 'json' => ['voteIds' => [$flags['bob']['voteId']], 'voided' => true]]));
        $this->assertSame(1, collect($this->show($contest, 3)['entries'])->firstWhere('id', $alice)['votes']);
        // A voided vote cannot be withdrawn and cast again.
        $this->vote($alice, 5, 'DELETE');
        $this->assertSame(2, $this->database()->table('shutter_votes')->count());
    }
}
