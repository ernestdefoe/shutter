<?php

namespace ErnestDefoe\Shutter\Tests\integration\api;

use Carbon\Carbon;
use ErnestDefoe\Shutter\Tests\integration\ShutterTestCase;
use PHPUnit\Framework\Attributes\Test;

/** Costs that must not grow with the number of photos, votes or contests. */
class QueryCountTest extends ShutterTestCase
{
    private function queries(string $path, ?int $as = 2, array $query = []): int
    {
        $request = $this->request('GET', $path, $as ? ['authenticatedAs' => $as] : [])->withQueryParams($query);
        $this->send($request); // warm
        $queries = 0;
        $this->database()->listen(function ($q) use (&$queries) {
            // Flarum's own access-token garbage collection, a 2% lottery.
            if (! str_contains($q->sql, 'access_tokens')) {
                $queries++;
            }
        });
        $response = $this->send($request);
        $this->assertSame(200, $response->getStatusCode(), (string) $response->getBody());
        $this->database()->getEventDispatcher()->forget(\Illuminate\Database\Events\QueryExecuted::class);

        return $queries;
    }

    private function fill(int $contest, int $entries): void
    {
        $now = Carbon::now();
        for ($i = 0; $i < $entries; $i++) {
            $id = $this->database()->table('shutter_entries')->insertGetId([
                'contest_id' => $contest, 'user_id' => [4, 5, 6][$i % 3], 'slot' => $i + 1, 'title' => "E$i",
                'hash' => hash('sha256', "$contest-$i"), 'ext' => 'webp', 'width' => 10, 'height' => 10, 'size' => 1,
                'approved_at' => $now, 'created_at' => $now, 'updated_at' => $now,
            ]);
            foreach ([2, 3] as $slot => $voter) {
                $this->database()->table('shutter_votes')->insert(['contest_id' => $contest, 'entry_id' => $id, 'user_id' => $voter, 'slot' => $i * 2 + $slot + 1, 'created_at' => $now]);
            }
        }
    }

    #[Test]
    public function the_discussion_list_asks_nothing_on_a_forum_without_contests(): void
    {
        $without = $this->queries('/api/discussions');
        $contest = $this->contest(1);
        $this->send($this->request('DELETE', "/api/shutter/contests/$contest", ['authenticatedAs' => 3]));

        $this->assertSame($without, $this->queries('/api/discussions'));
    }

    #[Test]
    public function the_discussion_list_asks_once_for_any_number_of_contests(): void
    {
        $this->contest(1);
        $one = $this->queries('/api/discussions');
        $this->contest(2);

        $this->assertSame($one, $this->queries('/api/discussions'));
    }

    #[Test]
    public function the_gallery_costs_the_same_for_two_photos_or_twenty(): void
    {
        $small = $this->contest(1, ['votesPerMember' => 50, 'entriesPerMember' => 20, 'namedVotes' => true]);
        $big = $this->contest(2, ['votesPerMember' => 50, 'entriesPerMember' => 20, 'namedVotes' => true]);
        $this->fill($small, 2);
        $this->fill($big, 20);

        foreach ([null, 2, 3] as $as) {
            $this->assertSame($this->queries("/api/shutter/contests/$small", $as), $this->queries("/api/shutter/contests/$big", $as), "contest page as user $as");
        }

        // Closed and decided: the podium and tallies are in too.
        Carbon::setTestNow(Carbon::now()->addDays(3));
        $this->send($this->request('GET', "/api/shutter/contests/$small", ['authenticatedAs' => 2]));
        $this->send($this->request('GET', "/api/shutter/contests/$big", ['authenticatedAs' => 2]));
        $this->assertSame($this->queries("/api/shutter/contests/$small"), $this->queries("/api/shutter/contests/$big"));
        $this->assertSame($this->queries("/api/shutter/contests/$small/integrity", 3), $this->queries("/api/shutter/contests/$big/integrity", 3));
    }

    #[Test]
    public function the_contests_page_costs_the_same_for_one_contest_or_three(): void
    {
        $this->fill($this->contest(1), 3);
        $one = $this->queries('/api/shutter/contests');
        $this->fill($this->contest(2), 3);
        $this->fill($this->contest(3), 3);

        $this->assertSame($one, $this->queries('/api/shutter/contests'));
        $this->assertCount(3, $this->json($this->send($this->request('GET', '/api/shutter/contests', ['authenticatedAs' => 2])))['data']);
    }
}
