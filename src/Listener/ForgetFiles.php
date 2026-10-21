<?php

namespace ErnestDefoe\Shutter\Listener;

use ErnestDefoe\Shutter\ContestSaver;
use ErnestDefoe\Shutter\Files;
use ErnestDefoe\Shutter\Model\Contest;
use ErnestDefoe\Shutter\Model\Entry;
use Flarum\Discussion\Event\Deleted;
use Flarum\Discussion\Event\Deleting;

/**
 * A deleted discussion takes its contest with it. Its entries' files are
 * noted while the rows still exist, and removed once the discussion is gone.
 */
class ForgetFiles
{
    /** @var array<int, array{contest: int, entries: list<object>}> */
    private array $pending = [];

    public function __construct(private Files $files, private ContestSaver $saver)
    {
    }

    public function deleting(Deleting $event): void
    {
        $contest = Contest::query()->where('discussion_id', $event->discussion->id)->first(['id']);

        if ($contest) {
            $this->pending[(int) $event->discussion->id] = [
                'contest' => (int) $contest->id,
                'entries' => Entry::query()->where('contest_id', $contest->id)->get(['hash', 'ext'])->all(),
            ];
        }
    }

    public function deleted(Deleted $event): void
    {
        $id = (int) $event->discussion->id;

        if (array_key_exists($id, $this->pending)) {
            $this->saver->purge($this->pending[$id]['contest']);
            $this->files->release($this->pending[$id]['entries']);
            unset($this->pending[$id]);
        }
    }
}
