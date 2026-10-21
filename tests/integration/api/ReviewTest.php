<?php

namespace ErnestDefoe\Shutter\Tests\integration\api;

use Carbon\Carbon;
use ErnestDefoe\Shutter\Event\ContestFinalized;
use ErnestDefoe\Shutter\Tests\integration\ShutterTestCase;
use ErnestDefoe\Shutter\Tests\Photos;
use Flarum\Extend;
use PHPUnit\Framework\Attributes\Test;
use RuntimeException;

/** Each test here failed on the code before the adversarial review's fixes. */
class ReviewTest extends ShutterTestCase
{
    #[Test]
    public function an_animated_webp_is_refused_before_it_is_decoded(): void
    {
        $contest = $this->contest();
        // A VP8X header with the animation flag, then a frame of junk.
        $webp = 'RIFF'.pack('V', 30).'WEBPVP8X'.pack('V', 10)."\x02\0\0\0".substr(pack('V', 999), 0, 3).substr(pack('V', 999), 0, 3).'ANIM'.pack('V', 6).str_repeat("\0", 6);

        $response = $this->submit($contest, 4, $webp);

        $this->assertSame(422, $response->getStatusCode(), (string) $response->getBody());
        $this->assertStringContainsString('nimated', (string) $response->getBody());
    }

    #[Test]
    public function uploads_are_rate_limited(): void
    {
        $contest = $this->contest(1, ['entriesPerMember' => 20]);
        $codes = [];

        for ($i = 0; $i < 7; $i++) {
            $codes[] = $this->submit($contest, 4)->getStatusCode();
        }

        $this->assertSame([201, 201, 201, 201, 201, 201, 429], $codes);
    }

    #[Test]
    public function when_names_are_hidden_so_is_the_time_of_entry(): void
    {
        $contest = $this->contest();
        $this->entryId($this->submit($contest, 4));
        $this->toVoting();

        $entry = $this->show($contest, 5)['entries'][0];
        $this->assertNull($entry['user']);
        $this->assertNull($entry['createdAt']);
        $this->assertNotNull($this->show($contest, 4)['entries'][0]['createdAt'], 'the entrant still sees their own');
    }

    #[Test]
    public function a_vote_for_a_disqualified_photo_is_given_back(): void
    {
        $contest = $this->contest(1, ['votesPerMember' => 1]);
        $a = $this->entryId($this->submit($contest, 4));
        $b = $this->entryId($this->submit($contest, 6));
        $this->toVoting();
        $this->assertSame(200, $this->vote($a, 5)->getStatusCode());

        $this->send($this->request('PATCH', "/api/shutter/entries/$a", ['authenticatedAs' => 3, 'json' => ['disqualified' => true]]));

        $this->assertSame(0, $this->show($contest, 5)['viewer']['votesUsed']);
        $this->assertSame(200, $this->vote($b, 5)->getStatusCode());
        $this->assertSame([$b], $this->database()->table('shutter_votes')->where('user_id', 5)->pluck('entry_id')->map(fn ($id) => (int) $id)->all());
    }

    #[Test]
    public function live_counts_do_not_name_voters_before_the_end(): void
    {
        $contest = $this->contest(1, ['namedVotes' => true, 'liveCounts' => true]);
        $a = $this->entryId($this->submit($contest, 4));
        $this->toVoting();
        $this->vote($a, 5);

        $this->assertSame(403, $this->send($this->request('GET', "/api/shutter/entries/$a/voters", ['authenticatedAs' => 6]))->getStatusCode());
    }

    #[Test]
    public function a_disqualified_photo_cannot_be_withdrawn_and_entered_again(): void
    {
        $contest = $this->contest();
        $bytes = Photos::jpeg(400, 300, random_int(1, 2 ** 31 - 1));
        $a = $this->entryId($this->submit($contest, 4, $bytes));
        $this->send($this->request('PATCH', "/api/shutter/entries/$a", ['authenticatedAs' => 3, 'json' => ['disqualified' => true]]));

        $this->assertSame(403, $this->send($this->request('DELETE', "/api/shutter/entries/$a", ['authenticatedAs' => 4]))->getStatusCode());
        $this->assertSame(422, $this->submit($contest, 4, $bytes)->getStatusCode());
    }

    #[Test]
    public function in_a_moderated_contest_an_edit_goes_back_for_approval(): void
    {
        $contest = $this->contest(1, ['moderateEntries' => true]);
        $a = $this->entryId($this->submit($contest, 4));
        $this->send($this->request('PATCH', "/api/shutter/entries/$a", ['authenticatedAs' => 3, 'json' => ['approved' => true]]));

        $this->send($this->request('PATCH', "/api/shutter/entries/$a", ['authenticatedAs' => 4, 'json' => ['title' => 'Something else entirely']]));

        $this->assertNull($this->database()->table('shutter_entries')->where('id', $a)->value('approved_at'));
    }

    #[Test]
    public function no_placing_alert_or_email_to_a_winner_who_can_no_longer_see_the_contest(): void
    {
        $contest = $this->contest();
        $a = $this->entryId($this->submit($contest, 4));
        $this->toVoting();
        $this->vote($a, 5);
        $this->toClosed();
        $this->database()->table('discussions')->where('id', 1)->update(['hidden_at' => Carbon::now(), 'hidden_user_id' => 3]);

        $this->show($contest, 3);

        $this->assertSame(1, (int) $this->database()->table('shutter_entries')->where('id', $a)->value('placement'));
        $this->assertSame(0, $this->database()->table('notifications')->where('type', 'shutterPlaced')->count());
    }

    #[Test]
    public function a_moderator_judging_does_not_see_the_running_average(): void
    {
        $contest = $this->contest(1, ['scoring' => 'judges', 'judges' => 'mod,admin']);
        $a = $this->entryId($this->submit($contest, 4));
        $this->toVoting();
        $this->send($this->request('PUT', "/api/shutter/entries/$a/score", ['authenticatedAs' => 1, 'json' => ['score' => 9]]));

        $asJudge = $this->show($contest, 3, ['sort' => 'top']);
        $this->assertArrayNotHasKey('score', $asJudge['entries'][0]);
        $this->assertNotSame('top', $asJudge['sort']);
        $this->assertFalse($asJudge['seesTallies']);
    }

    #[Test]
    public function a_failed_decision_is_released_to_be_tried_again(): void
    {
        $this->extend((new Extend\Event())->listen(ContestFinalized::class, function () {
            throw new RuntimeException('mail server down');
        }));
        $contest = $this->contest();
        $this->entryId($this->submit($contest, 4));
        $this->toClosed();

        $this->show($contest, 2);

        $this->assertNull($this->database()->table('shutter_contests')->value('finalized_at'));
    }

    #[Test]
    public function deleting_a_photo_takes_its_votes(): void
    {
        $contest = $this->contest();
        $a = $this->entryId($this->submit($contest, 4));
        $this->toVoting();
        $this->vote($a, 5);

        $this->send($this->request('DELETE', "/api/shutter/entries/$a", ['authenticatedAs' => 3]));

        $this->assertSame(0, $this->database()->table('shutter_votes')->count());
    }
}
