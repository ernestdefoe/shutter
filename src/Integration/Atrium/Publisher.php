<?php

namespace ErnestDefoe\Shutter\Integration\Atrium;

use Carbon\Carbon;
use ErnestDefoe\Atrium\Api\Presenter as AtriumPresenter;
use ErnestDefoe\Atrium\Counters;
use ErnestDefoe\Atrium\Media\Intake;
use ErnestDefoe\Atrium\Model\Album;
use ErnestDefoe\Shutter\Media\Store;
use ErnestDefoe\Shutter\Model\Contest;
use ErnestDefoe\Shutter\Model\Entry;
use Flarum\Extension\ExtensionManager;
use Flarum\Locale\TranslatorInterface;
use Flarum\User\User;
use Illuminate\Contracts\Container\Container;
use Laminas\Diactoros\StreamFactory;
use Laminas\Diactoros\UploadedFile;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Publishing a decided contest's winners to an Atrium album.
 *
 * Optional in every direction: nothing here runs, and no Atrium class is
 * touched, unless ernestdefoe/atrium is enabled. Each winner goes through
 * Atrium's own upload path, credited to its photographer, once; a failure is
 * logged and never stops the contest being decided.
 */
class Publisher
{
    public function __construct(
        private ExtensionManager $extensions,
        private Container $container,
        private Store $store,
        private TranslatorInterface $translator,
        private LoggerInterface $log,
    ) {
    }

    public function available(): bool
    {
        return $this->extensions->isEnabled('ernestdefoe-atrium') && class_exists(Intake::class);
    }

    /**
     * Albums the actor may add pictures to, for the contest editor.
     *
     * @return list<array{id: int, title: string}>
     */
    public function albums(User $actor): array
    {
        if (! $this->available()) {
            return [];
        }

        $presenter = $this->container->make(AtriumPresenter::class);

        return Album::query()->orderBy('title')->limit(500)->get()
            ->filter(fn (Album $album) => $presenter->canUploadTo($album, $actor))
            ->map(fn (Album $album) => ['id' => (int) $album->id, 'title' => (string) $album->title])
            ->values()->all();
    }

    /** Whether the actor may choose this album for a contest. */
    public function canChoose(int $albumId, User $actor): bool
    {
        if (! $this->available()) {
            return false;
        }

        $album = Album::query()->find($albumId);

        return $album !== null && $this->container->make(AtriumPresenter::class)->canUploadTo($album, $actor);
    }

    /**
     * The placed photos not yet in the album.
     *
     * @param iterable<Entry> $entries
     */
    public function publish(Contest $contest, iterable $entries): void
    {
        if (! $contest->atrium_album_id || ! $this->available()) {
            return;
        }

        $album = Album::query()->find($contest->atrium_album_id);

        if (! $album) {
            return;
        }

        $intake = $this->container->make(Intake::class);
        $published = 0;

        foreach ($entries as $entry) {
            if ($entry->atrium_image_id || $entry->placement === null || ! $entry->user_id) {
                continue;
            }

            try {
                $user = User::query()->find($entry->user_id);
                $bytes = $user ? $this->store->disk()->get($this->store::paths($entry->hash, $entry->ext)['large']) : null;

                if (! $user || ! $bytes) {
                    continue;
                }

                $file = new UploadedFile((new StreamFactory())->createStream($bytes), strlen($bytes), UPLOAD_ERR_OK, "{$entry->hash}.{$entry->ext}", $entry->ext === 'webp' ? 'image/webp' : 'image/jpeg');
                $image = $intake->accept($file, $album, $user);
                $image->title = mb_substr($entry->title, 0, 255);
                $image->caption = $this->translator->trans('ernestdefoe-shutter.api.atrium_caption', [
                    '{place}' => $this->translator->trans('ernestdefoe-shutter.lib.place.'.min($entry->placement, 4), ['{n}' => $entry->placement]),
                    '{contest}' => $contest->discussion->title ?? '',
                ]).($entry->caption ? "\n\n".$entry->caption : '');
                // Staff chose to publish the winners: no second approval.
                $image->approved_at = Carbon::now();
                $image->position = (int) $album->images()->max('position') + 1;
                $image->save();

                Entry::query()->whereKey($entry->id)->update(['atrium_image_id' => $image->id]);
                $published++;
            } catch (Throwable $e) {
                $this->log->warning("Shutter could not publish entry {$entry->id} to Atrium album {$album->id}: ".$e->getMessage());
            }
        }

        if ($published) {
            $counters = $this->container->make(Counters::class);
            $counters->album((int) $album->id);
            $counters->categoryCounts();
        }
    }
}
