<?php

namespace ErnestDefoe\Shutter\Tests\integration;

use Carbon\Carbon;
use ErnestDefoe\Shutter\Tests\Photos;
use Flarum\Discussion\Discussion;
use Flarum\Group\Group;
use Flarum\Post\Post;
use Flarum\Testing\integration\RetrievesAuthorizedUsers;
use Flarum\Testing\integration\TestCase;
use Flarum\User\User;
use Laminas\Diactoros\ServerRequest;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

/**
 * Users: 1 admin, 2 normal (a member), 3 mod (Moderators), 4 alice, 5 bob,
 * 6 carol — members, made a year ago with a post each. Discussions 1–3.
 */
abstract class ShutterTestCase extends TestCase
{
    use RetrievesAuthorizedUsers;

    protected function setUp(): void
    {
        parent::setUp();

        $this->extension('ernestdefoe-shutter');

        $old = Carbon::now()->subYear();
        $member = fn (int $id, string $name) => ['id' => $id, 'username' => $name, 'email' => "$name@example.com", 'password' => 'too-obscure', 'is_email_confirmed' => 1, 'joined_at' => $old, 'comment_count' => 3];

        $this->prepareDatabase([
            User::class => [
                $this->normalUser(),
                $member(3, 'mod'),
                $member(4, 'alice'),
                $member(5, 'bob'),
                $member(6, 'carol'),
            ],
            'group_user' => [['user_id' => 3, 'group_id' => Group::MODERATOR_ID]],
            Discussion::class => [
                ['id' => 1, 'title' => 'Autumn colours', 'user_id' => 3, 'created_at' => $old, 'first_post_id' => 1, 'comment_count' => 1, 'is_private' => 0],
                ['id' => 2, 'title' => 'Street life', 'user_id' => 3, 'created_at' => $old, 'first_post_id' => 2, 'comment_count' => 1, 'is_private' => 0],
                ['id' => 3, 'title' => 'Plain chat', 'user_id' => 2, 'created_at' => $old, 'first_post_id' => 3, 'comment_count' => 1, 'is_private' => 0],
            ],
            Post::class => [
                ['id' => 1, 'discussion_id' => 1, 'user_id' => 3, 'type' => 'comment', 'content' => '<t><p>Rules</p></t>', 'created_at' => $old, 'number' => 1],
                ['id' => 2, 'discussion_id' => 2, 'user_id' => 3, 'type' => 'comment', 'content' => '<t><p>Rules</p></t>', 'created_at' => $old, 'number' => 1],
                ['id' => 3, 'discussion_id' => 3, 'user_id' => 2, 'type' => 'comment', 'content' => '<t><p>Hi</p></t>', 'created_at' => $old, 'number' => 1],
            ],
        ]);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    /** @return array<string, mixed> */
    protected function json(ResponseInterface $response): array
    {
        return json_decode((string) $response->getBody(), true) ?? [];
    }

    /**
     * A contest on a discussion, made through the API by the moderator.
     * Submissions open now and close in a day; voting the day after.
     *
     * @param array<string, mixed> $settings
     */
    protected function contest(int $discussionId = 1, array $settings = []): int
    {
        $response = $this->send($this->request('POST', '/api/shutter/contests', ['authenticatedAs' => 3, 'json' => ['data' => $settings + [
            'discussionId' => $discussionId,
            'submissionsCloseAt' => Carbon::now()->addDay()->toIso8601String(),
            'votingCloseAt' => Carbon::now()->addDays(2)->toIso8601String(),
        ]]]));
        $this->assertSame(201, $response->getStatusCode(), (string) $response->getBody());

        return $this->json($response)['data']['id'];
    }

    protected function submit(int $contestId, int $userId, ?string $bytes = null, string $title = 'My photo', int $seed = 0): ResponseInterface
    {
        $bytes ??= Photos::jpeg(800, 600, $seed ?: random_int(1, 2 ** 31 - 1));

        return $this->send(
            $this->request('POST', "/api/shutter/contests/$contestId/entries", ['authenticatedAs' => $userId])
                ->withParsedBody(['title' => $title])
                ->withUploadedFiles(['photo' => Photos::upload($bytes)])
        );
    }

    protected function entryId(ResponseInterface $response): int
    {
        $this->assertSame(201, $response->getStatusCode(), (string) $response->getBody());

        return $this->json($response)['data']['id'];
    }

    /** Move the clock into voting (or closed) for a contest made by contest(). */
    protected function toVoting(): void
    {
        Carbon::setTestNow(Carbon::now()->addDay()->addHour());
    }

    protected function toClosed(): void
    {
        Carbon::setTestNow(Carbon::now()->addDays(3));
    }

    protected function vote(int $entryId, int $userId, string $method = 'POST'): ResponseInterface
    {
        return $this->send($this->request($method, "/api/shutter/entries/$entryId/vote", ['authenticatedAs' => $userId]));
    }

    protected function show(int $contestId, ?int $userId = null, array $query = []): array
    {
        $request = $this->request('GET', "/api/shutter/contests/$contestId", $userId ? ['authenticatedAs' => $userId] : [])->withQueryParams($query);
        $response = $this->send($request);
        $this->assertSame(200, $response->getStatusCode(), (string) $response->getBody());

        return $this->json($response)['data'];
    }

    /** The same request, arriving from another address. */
    protected function fromIp(string $ip, ServerRequestInterface $r): ServerRequestInterface
    {
        $copy = new ServerRequest(['REMOTE_ADDR' => $ip], $r->getUploadedFiles(), $r->getUri(), $r->getMethod(), $r->getBody(), $r->getHeaders(), $r->getCookieParams(), $r->getQueryParams(), $r->getParsedBody());

        foreach ($r->getAttributes() as $key => $value) {
            $copy = $copy->withAttribute($key, $value);
        }

        return $copy;
    }

    /** The public path of a stored file, from its URL. */
    protected function file(string $url): string
    {
        $path = substr($url, strpos($url, '/assets/shutter/') + strlen('/assets/shutter/'));
        $root = $this->app()->getContainer()->make(\Flarum\Foundation\Paths::class)->public.'/assets/shutter/';

        return (string) file_get_contents($root.$path);
    }
}
