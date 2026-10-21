<?php

/*
 * Shutter — photo contests for Flarum.
 *
 * The shape of the extension: a contest belongs to a discussion and shares
 * its visibility. Its phase is read off three times, never stored. Photos
 * are re-encoded on the way in, so what is served carries no metadata.
 * Votes and scores are rows guarded by unique indexes, counted when read.
 */

use ErnestDefoe\Shutter\Access;
use ErnestDefoe\Shutter\Api;
use ErnestDefoe\Shutter\Console\TickCommand;
use ErnestDefoe\Shutter\ContestSaver;
use ErnestDefoe\Shutter\Integration\Atrium\Publisher;
use ErnestDefoe\Shutter\Integration\Badges\BadgesProvider;
use ErnestDefoe\Shutter\Listener\ForgetFiles;
use ErnestDefoe\Shutter\Media\Store;
use ErnestDefoe\Shutter\Notification;
use ErnestDefoe\Shutter\ShutterServiceProvider;
use Flarum\Api\Context;
use Flarum\Api\Resource\DiscussionResource;
use Flarum\Api\Resource\ForumResource;
use Flarum\Api\Schema;
use Flarum\Discussion\Discussion;
use Flarum\Discussion\Event\Deleted;
use Flarum\Discussion\Event\Deleting;
use Flarum\Extend;
use Flarum\Foundation\Paths;
use Flarum\Foundation\ValidationException;
use Flarum\Http\UrlGenerator;
use Flarum\Settings\SettingsRepositoryInterface;

return [
    (new Extend\Frontend('forum'))
        ->js(__DIR__.'/js/dist/forum.js')
        // The gallery, lightbox, editor and contests page are their own chunks.
        ->jsDirectory(__DIR__.'/js/dist/forum')
        ->css(__DIR__.'/less/forum.less')
        ->route('/contests', 'shutter.contests'),

    (new Extend\Frontend('admin'))
        ->js(__DIR__.'/js/dist/admin.js'),

    new Extend\Locales(__DIR__.'/locale'),

    (new Extend\View())
        ->namespace('ernestdefoe-shutter', __DIR__.'/views'),

    (new Extend\Filesystem())
        ->disk(Store::DISK, fn (Paths $paths, UrlGenerator $url) => [
            'root' => "$paths->public/assets/shutter",
            'url' => $url->to('forum')->path('assets/shutter'),
            // Explicitly public: left to the umask, directories come out 0700
            // and every photo is a 403 where PHP and the web server differ.
            'visibility' => 'public',
            'directory_visibility' => 'public',
        ]),

    (new Extend\Routes('api'))
        ->get('/shutter/contests', 'shutter.contests.index', Api\ListContestsController::class)
        ->post('/shutter/contests', 'shutter.contests.create', Api\SaveContestController::class)
        ->get('/shutter/contests/{id:[0-9]+}', 'shutter.contests.show', Api\ShowContestController::class)
        ->patch('/shutter/contests/{id:[0-9]+}', 'shutter.contests.update', Api\SaveContestController::class)
        ->delete('/shutter/contests/{id:[0-9]+}', 'shutter.contests.delete', Api\DeleteContestController::class)
        ->get('/shutter/contests/{id:[0-9]+}/entries', 'shutter.entries.index', Api\ListEntriesController::class)
        ->post('/shutter/contests/{id:[0-9]+}/entries', 'shutter.entries.create', Api\SubmitEntryController::class)
        ->get('/shutter/contests/{id:[0-9]+}/integrity', 'shutter.integrity', Api\IntegrityController::class)
        ->post('/shutter/contests/{id:[0-9]+}/integrity', 'shutter.integrity.void', Api\IntegrityController::class)
        ->get('/shutter/entries/{id:[0-9]+}', 'shutter.entries.show', Api\EntryController::class)
        ->patch('/shutter/entries/{id:[0-9]+}', 'shutter.entries.update', Api\EntryController::class)
        ->delete('/shutter/entries/{id:[0-9]+}', 'shutter.entries.delete', Api\EntryController::class)
        ->post('/shutter/entries/{id:[0-9]+}/vote', 'shutter.vote', Api\VoteController::class)
        ->delete('/shutter/entries/{id:[0-9]+}/vote', 'shutter.unvote', Api\VoteController::class)
        ->put('/shutter/entries/{id:[0-9]+}/score', 'shutter.score', Api\ScoreController::class)
        ->get('/shutter/entries/{id:[0-9]+}/voters', 'shutter.voters', Api\VotersController::class)
        ->get('/shutter/atrium-albums', 'shutter.atrium.albums', Api\AtriumAlbumsController::class),

    (new Extend\ThrottleApi())
        ->set('shutterUploads', Api\UploadThrottle::class),

    (new Extend\ApiResource(DiscussionResource::class))
        ->fields(fn () => [
            Schema\Arr::make('shutterContest')
                ->nullable()
                ->get(fn (Discussion $discussion, Context $context) => resolve(Api\DiscussionContest::class)->get($discussion, $context->request))
                // A new discussion can be a contest from the start, by staff.
                ->writable(fn ($discussion, Context $context) => $context->creating() && Access::canManage($context->getActor()))
                // 🚨 A Flarum rule, not json-api-server's validate(): Flarum
                // never calls the latter, and a bad contest then surfaced only
                // after the discussion had been saved.
                ->rule(fn (Context $context) => function (string $attribute, mixed $value, Closure $fail) use ($context) {
                    try {
                        resolve(ContestSaver::class)->clean(is_array($value) ? $value : [], null, $context->getActor());
                    } catch (ValidationException $e) {
                        $fail((string) array_values($e->getAttributes())[0]);
                    }
                })
                ->set(fn () => null)
                ->save(function (Discussion $discussion, mixed $value, Context $context) {
                    if (is_array($value)) {
                        $saver = resolve(ContestSaver::class);
                        $saver->create($discussion, $context->getActor(), $saver->clean($value, null, $context->getActor()));
                    }
                }),
        ]),

    // Settings and a permission the actor's groups were already loaded for: no query.
    (new Extend\ApiResource(ForumResource::class))
        ->fields(fn () => [
            Schema\Boolean::make('canManagePhotoContests')
                ->get(fn ($forum, Context $context) => Access::canManage($context->getActor())),
            Schema\Boolean::make('shutterAtrium')
                ->get(fn ($forum, Context $context) => Access::canManage($context->getActor()) && resolve(Publisher::class)->available()),
            Schema\Boolean::make('shutterNav')
                ->get(fn () => (bool) resolve(SettingsRepositoryInterface::class)->get('ernestdefoe-shutter.show_nav')
                    && (int) resolve(SettingsRepositoryInterface::class)->get(ContestSaver::COUNT) > 0),
            Schema\Integer::make('shutterMaxMb')
                ->get(fn () => max(1, min(50, (int) (resolve(SettingsRepositoryInterface::class)->get('ernestdefoe-shutter.max_mb') ?: 10)))),
        ]),

    (new Extend\Settings())
        ->default('ernestdefoe-shutter.show_nav', true)
        ->default('ernestdefoe-shutter.max_mb', 10)
        ->default('ernestdefoe-shutter.max_edge', 2560),

    (new Extend\Notification())
        ->type(Notification\PlacedBlueprint::class, ['alert', 'email'])
        ->type(Notification\ResultsBlueprint::class, ['alert'])
        ->type(Notification\VotingOpenBlueprint::class, ['alert']),

    (new Extend\ServiceProvider())
        ->register(ShutterServiceProvider::class),

    (new Extend\Event())
        ->listen(Deleting::class, [ForgetFiles::class, 'deleting'])
        ->listen(Deleted::class, [ForgetFiles::class, 'deleted']),

    (new Extend\Console())
        ->command(TickCommand::class)
        ->schedule(TickCommand::class, function ($event): void {
            $event->everyFiveMinutes();
        }),

    (new Extend\Conditional())
        ->whenExtensionEnabled('fof-badges', fn () => [
            (new Extend\ServiceProvider())->register(BadgesProvider::class),
        ]),
];
