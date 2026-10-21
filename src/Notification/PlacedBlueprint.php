<?php

namespace ErnestDefoe\Shutter\Notification;

use Flarum\Database\AbstractModel;
use Flarum\Discussion\Discussion;
use Flarum\Locale\TranslatorInterface;
use Flarum\Notification\AlertableInterface;
use Flarum\Notification\Blueprint\BlueprintInterface;
use Flarum\Notification\MailableInterface;
use Flarum\User\User;

/**
 * "Your photo placed 2nd in Autumn Colours." Alert and email.
 *
 * The subject is the contest's discussion, so Flarum's own visibility keeps
 * the alert from anyone who can no longer see it.
 */
class PlacedBlueprint implements AlertableInterface, BlueprintInterface, MailableInterface
{
    public function __construct(public Discussion $discussion, public int $placement, public int $entryId)
    {
    }

    public function getSubject(): ?AbstractModel
    {
        return $this->discussion;
    }

    public function getFromUser(): ?User
    {
        return null;
    }

    public function getData(): mixed
    {
        return ['placement' => $this->placement, 'entry' => $this->entryId];
    }

    /** 🚨 Both views, or Flarum throws while sending, in the queue. */
    public function getEmailViews(): array
    {
        return [
            'text' => 'ernestdefoe-shutter::emails.plain.placed',
            'html' => 'ernestdefoe-shutter::emails.html.placed',
        ];
    }

    public function getEmailSubject(TranslatorInterface $translator): string
    {
        return $translator->trans('ernestdefoe-shutter.email.placed.subject', [
            '{place}' => $translator->trans('ernestdefoe-shutter.lib.place.'.min($this->placement, 4), ['{n}' => $this->placement]),
            '{title}' => $this->discussion->title,
        ]);
    }

    public static function getType(): string
    {
        return 'shutterPlaced';
    }

    public static function getSubjectModel(): string
    {
        return Discussion::class;
    }
}
