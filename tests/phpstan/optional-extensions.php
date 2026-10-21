<?php

// The parts of ernestdefoe/atrium (private, optional) that Shutter uses, for PHPStan only.

namespace ErnestDefoe\Atrium\Model {
    use Flarum\Database\AbstractModel;
    use Illuminate\Database\Eloquent\Relations\HasMany;

    /**
     * @property int $id
     * @property string $title
     */
    class Album extends AbstractModel
    {
        /** @return HasMany<Image, $this> */
        public function images(): HasMany
        {
            return $this->hasMany(Image::class);
        }
    }

    /**
     * @property int $id
     * @property string|null $title
     * @property string|null $caption
     * @property \Carbon\Carbon|null $approved_at
     * @property int $position
     */
    class Image extends AbstractModel
    {
    }
}

namespace ErnestDefoe\Atrium\Media {
    use ErnestDefoe\Atrium\Model\Album;
    use ErnestDefoe\Atrium\Model\Image;
    use Flarum\User\User;
    use Psr\Http\Message\UploadedFileInterface;

    class Intake
    {
        public function accept(UploadedFileInterface $file, Album $album, User $actor): Image
        {
            return new Image();
        }
    }
}

namespace ErnestDefoe\Atrium\Api {
    use ErnestDefoe\Atrium\Model\Album;
    use Flarum\User\User;

    class Presenter
    {
        public function canUploadTo(Album $album, User $actor): bool
        {
            return false;
        }
    }
}

namespace ErnestDefoe\Atrium {
    class Counters
    {
        public function album(int $albumId): void
        {
        }

        public function categoryCounts(): void
        {
        }
    }
}
