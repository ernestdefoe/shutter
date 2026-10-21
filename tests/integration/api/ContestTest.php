<?php

namespace ErnestDefoe\Shutter\Tests\integration\api;

use Carbon\Carbon;
use ErnestDefoe\Shutter\Tests\integration\ShutterTestCase;
use PHPUnit\Framework\Attributes\Test;

class ContestTest extends ShutterTestCase
{
    #[Test]
    public function only_staff_run_contests(): void
    {
        $body = ['json' => ['data' => [
            'discussionId' => 3,
            'submissionsCloseAt' => Carbon::now()->addDay()->toIso8601String(),
            'votingCloseAt' => Carbon::now()->addDays(2)->toIso8601String(),
        ]]];

        $this->assertSame(403, $this->send($this->request('POST', '/api/shutter/contests', $body + ['authenticatedAs' => 2]))->getStatusCode());
        $this->assertContains($this->send($this->request('POST', '/api/shutter/contests', $body))->getStatusCode(), [400, 401, 403]);
        $this->assertSame(201, $this->send($this->request('POST', '/api/shutter/contests', $body + ['authenticatedAs' => 3]))->getStatusCode());
        // One contest per discussion.
        $this->assertSame(422, $this->send($this->request('POST', '/api/shutter/contests', $body + ['authenticatedAs' => 3]))->getStatusCode());

        $contest = $this->database()->table('shutter_contests')->value('id');
        $this->assertSame(403, $this->send($this->request('PATCH', "/api/shutter/contests/$contest", ['authenticatedAs' => 2, 'json' => ['data' => ['rules' => 'x']]]))->getStatusCode());
        $this->assertSame(403, $this->send($this->request('DELETE', "/api/shutter/contests/$contest", ['authenticatedAs' => 2]))->getStatusCode());
    }

    #[Test]
    public function the_schedule_must_run_forwards(): void
    {
        $send = fn (array $data) => $this->send($this->request('POST', '/api/shutter/contests', ['authenticatedAs' => 3, 'json' => ['data' => $data + ['discussionId' => 1]]]));
        $now = Carbon::now();

        $this->assertSame(422, $send(['submissionsCloseAt' => $now->copy()->addDay()->toIso8601String(), 'votingCloseAt' => $now->copy()->toIso8601String()])->getStatusCode());
        $this->assertSame(422, $send(['opensAt' => $now->copy()->addDays(2)->toIso8601String(), 'submissionsCloseAt' => $now->copy()->addDay()->toIso8601String(), 'votingCloseAt' => $now->copy()->addDays(3)->toIso8601String()])->getStatusCode());
        $this->assertSame(422, $send(['submissionsCloseAt' => 'next tuesday-ish', 'votingCloseAt' => $now->copy()->addDays(3)->toIso8601String()])->getStatusCode());
        $this->assertSame(422, $send(['submissionsCloseAt' => $now->copy()->addDay()->toIso8601String(), 'votingCloseAt' => $now->copy()->addDays(3)->toIso8601String(), 'votesPerMember' => 0])->getStatusCode());
        $this->assertSame(422, $send(['submissionsCloseAt' => $now->copy()->addDay()->toIso8601String(), 'votingCloseAt' => $now->copy()->addDays(3)->toIso8601String(), 'scoring' => 'judges'])->getStatusCode());
        $this->assertSame(422, $send(['submissionsCloseAt' => $now->copy()->addDay()->toIso8601String(), 'votingCloseAt' => $now->copy()->addDays(3)->toIso8601String(), 'scoring' => 'judges', 'judges' => 'nobody_here'])->getStatusCode());
        $this->assertSame(422, $send(['submissionsCloseAt' => $now->copy()->addDay()->toIso8601String(), 'votingCloseAt' => $now->copy()->addDays(3)->toIso8601String(), 'enterGroupIds' => [999]])->getStatusCode());

        $ok = $send(['submissionsCloseAt' => $now->copy()->addDay()->toIso8601String(), 'votingCloseAt' => $now->copy()->addDays(3)->toIso8601String(), 'scoring' => 'judges', 'judges' => 'MOD, @alice']);
        $this->assertSame(201, $ok->getStatusCode(), (string) $ok->getBody());
        $this->assertSame([3, 4], json_decode($this->database()->table('shutter_contests')->value('judge_ids'), true));
    }

    #[Test]
    public function a_new_discussion_can_be_a_contest_from_the_composer_by_staff_only(): void
    {
        $contest = [
            'submissionsCloseAt' => Carbon::now()->addDay()->toIso8601String(),
            'votingCloseAt' => Carbon::now()->addDays(2)->toIso8601String(),
            'votesPerMember' => 5,
        ];
        $discussion = fn (int $user) => $this->send($this->request('POST', '/api/discussions', ['authenticatedAs' => $user, 'json' => ['data' => [
            'type' => 'discussions',
            'attributes' => ['title' => 'Golden hour', 'content' => 'Rules: shoot at golden hour.', 'shutterContest' => $contest],
        ]]]));

        $member = $discussion(2);
        $this->assertNotSame(201, $member->getStatusCode());
        $this->assertSame(0, $this->database()->table('shutter_contests')->count());

        $staff = $discussion(3);
        $this->assertSame(201, $staff->getStatusCode(), (string) $staff->getBody());
        $created = $this->json($staff)['data'];
        $this->assertSame('submissions', $created['attributes']['shutterContest']['phase']);
        $this->assertSame(5, (int) $this->database()->table('shutter_contests')->where('discussion_id', $created['id'])->value('votes_per_member'));
    }

    #[Test]
    public function a_bad_contest_in_the_composer_creates_no_discussion(): void
    {
        $before = $this->database()->table('discussions')->count();
        $response = $this->send($this->request('POST', '/api/discussions', ['authenticatedAs' => 3, 'json' => ['data' => [
            'type' => 'discussions',
            'attributes' => ['title' => 'Golden hour', 'content' => 'Rules.', 'shutterContest' => ['submissionsCloseAt' => 'nope']],
        ]]]));

        $this->assertSame(422, $response->getStatusCode(), (string) $response->getBody());
        $this->assertSame($before, $this->database()->table('discussions')->count());
    }

    #[Test]
    public function a_contest_is_as_visible_as_its_discussion(): void
    {
        $contest = $this->contest(1);
        $this->database()->table('discussions')->where('id', 1)->update(['hidden_at' => Carbon::now(), 'hidden_user_id' => 3]);

        $this->assertSame(404, $this->send($this->request('GET', "/api/shutter/contests/$contest", ['authenticatedAs' => 4]))->getStatusCode());
        $this->assertSame(404, $this->send($this->request('GET', "/api/shutter/contests/$contest"))->getStatusCode());
        $this->assertSame(404, $this->submit($contest, 4)->getStatusCode());
        $this->assertSame([], $this->json($this->send($this->request('GET', '/api/shutter/contests', ['authenticatedAs' => 4])))['data']);
        $this->assertSame(200, $this->send($this->request('GET', "/api/shutter/contests/$contest", ['authenticatedAs' => 3]))->getStatusCode());
    }

    #[Test]
    public function discussions_say_whether_they_are_contests(): void
    {
        $this->contest(1);

        $list = $this->json($this->send($this->request('GET', '/api/discussions', ['authenticatedAs' => 2])))['data'];
        $by = array_column(array_map(fn ($d) => ['id' => $d['id'], 'c' => $d['attributes']['shutterContest'] ?? 'missing'], $list), 'c', 'id');

        $this->assertSame('submissions', $by['1']['phase']);
        $this->assertContains($by['3'], [null, 'missing']);
    }

    #[Test]
    public function entering_follows_the_rules(): void
    {
        $contest = $this->contest(1, ['entriesPerMember' => 2, 'enterGroupIds' => [4]]);

        // Group 4 (Moderators) only.
        $this->assertSame(422, $this->submit($contest, 4)->getStatusCode());
        $this->database()->table('group_user')->insert(['user_id' => 4, 'group_id' => 4]);
        $this->entryId($this->submit($contest, 4));
        $this->entryId($this->submit($contest, 4));
        $this->assertSame(422, $this->submit($contest, 4)->getStatusCode(), 'third entry over a limit of two');
        $guest = $this->send($this->request('POST', "/api/shutter/contests/$contest/entries")->withParsedBody(['title' => 'x']));
        $this->assertContains($guest->getStatusCode(), [400, 401, 403]);

        // Picking Members means every member.
        $this->send($this->request('PATCH', "/api/shutter/contests/$contest", ['authenticatedAs' => 3, 'json' => ['data' => ['enterGroupIds' => [3]]]]));
        $this->entryId($this->submit($contest, 5));

        // Before the contest opens, and after submissions close: no.
        $this->toVoting();
        $this->assertSame(422, $this->submit($contest, 6)->getStatusCode());
    }

    #[Test]
    public function the_slot_index_refuses_a_racing_second_entry(): void
    {
        $contest = $this->contest();
        $this->entryId($this->submit($contest, 4));
        $row = (array) $this->database()->table('shutter_entries')->first();
        unset($row['id']);

        $this->expectException(\Illuminate\Database\QueryException::class);
        $this->database()->table('shutter_entries')->insert(['hash' => str_repeat('a', 64)] + $row);
    }

    #[Test]
    public function a_judge_cannot_enter(): void
    {
        $contest = $this->contest(1, ['scoring' => 'judges', 'judges' => 'alice']);

        $this->assertSame(422, $this->submit($contest, 4)->getStatusCode());
        $this->entryId($this->submit($contest, 5));
    }

    #[Test]
    public function moderated_entries_wait_out_of_sight(): void
    {
        $contest = $this->contest(1, ['moderateEntries' => true]);
        $entry = $this->entryId($this->submit($contest, 4));

        $this->assertSame([], $this->show($contest, 5)['entries']);
        $this->assertSame(404, $this->send($this->request('PATCH', "/api/shutter/entries/$entry", ['authenticatedAs' => 5, 'json' => ['title' => 'x']]))->getStatusCode());
        $this->assertSame('pending', $this->show($contest, 4, ['mine' => 1])['entries'][0]['status']);
        $this->assertCount(1, $this->show($contest, 3, ['pending' => 1])['entries']);

        $this->assertSame(200, $this->send($this->request('PATCH', "/api/shutter/entries/$entry", ['authenticatedAs' => 3, 'json' => ['approved' => true]]))->getStatusCode());
        $this->assertCount(1, $this->show($contest, 5)['entries']);
    }

    #[Test]
    public function entrants_may_change_or_withdraw_only_while_submissions_are_open(): void
    {
        $contest = $this->contest();
        $entry = $this->entryId($this->submit($contest, 4));

        $this->assertSame(403, $this->send($this->request('PATCH', "/api/shutter/entries/$entry", ['authenticatedAs' => 5, 'json' => ['title' => 'Mine now']]))->getStatusCode());
        $this->assertSame(200, $this->send($this->request('PATCH', "/api/shutter/entries/$entry", ['authenticatedAs' => 4, 'json' => ['title' => 'Better title', 'approved' => false]]))->getStatusCode());
        $this->assertNotNull($this->database()->table('shutter_entries')->value('approved_at'), 'an entrant cannot unapprove');

        $this->toVoting();
        $this->assertSame(403, $this->send($this->request('DELETE', "/api/shutter/entries/$entry", ['authenticatedAs' => 4]))->getStatusCode());
        $this->assertSame(204, $this->send($this->request('DELETE', "/api/shutter/entries/$entry", ['authenticatedAs' => 3]))->getStatusCode());
    }

    #[Test]
    public function deleting_the_discussion_takes_the_contest_and_its_files(): void
    {
        $contest = $this->contest();
        $entry = $this->json($this->submit($contest, 4))['data'];
        $this->assertNotSame('', $this->file($entry['large']));

        $this->assertSame(204, $this->send($this->request('DELETE', '/api/discussions/1', ['authenticatedAs' => 1]))->getStatusCode());

        $this->assertSame(0, $this->database()->table('shutter_contests')->count());
        $this->assertSame(0, $this->database()->table('shutter_entries')->count());
        $this->assertSame('', @$this->file($entry['large']));
    }

    #[Test]
    public function without_atrium_there_is_no_album_to_publish_to(): void
    {
        $contest = $this->contest();

        $named = $this->send($this->request('PATCH', "/api/shutter/contests/$contest", ['authenticatedAs' => 3, 'json' => ['data' => ['atriumAlbumId' => 5]]]));
        $this->assertSame(422, $named->getStatusCode());
        $none = $this->send($this->request('PATCH', "/api/shutter/contests/$contest", ['authenticatedAs' => 3, 'json' => ['data' => ['atriumAlbumId' => null]]]));
        $this->assertSame(200, $none->getStatusCode());

        $this->assertSame([], $this->json($this->send($this->request('GET', '/api/shutter/atrium-albums', ['authenticatedAs' => 3])))['data']);
        $this->assertSame(403, $this->send($this->request('GET', '/api/shutter/atrium-albums', ['authenticatedAs' => 4]))->getStatusCode());
        $this->assertFalse($this->json($this->send($this->request('GET', '/api', ['authenticatedAs' => 3])))['data']['attributes']['shutterAtrium']);
        $this->assertNull($this->json($this->submit($contest, 4))['data']['atriumUrl']);
    }
}
