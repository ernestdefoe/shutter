<?php

namespace ErnestDefoe\Shutter\Notification;

use Flarum\Database\AbstractModel;
use Flarum\Discussion\Discussion;
use Flarum\Notification\AlertableInterface;
use Flarum\Notification\Blueprint\BlueprintInterface;
use Flarum\User\User;

/**
 * "The results of Autumn Colours are in." To entrants who did not place.
 *
 * The subject is the contest's discussion, so Flarum's own visibility keeps
 * the alert from anyone who can no longer see it.
 */
class ResultsBlueprint implements AlertableInterface, BlueprintInterface
{
    public function __construct(public Discussion $discussion)
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
        return null;
    }

    public static function getType(): string
    {
        return 'shutterResults';
    }

    public static function getSubjectModel(): string
    {
        return Discussion::class;
    }
}
