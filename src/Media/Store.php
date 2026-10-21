<?php

namespace ErnestDefoe\Shutter\Media;

use Illuminate\Contracts\Filesystem\Cloud;
use Illuminate\Contracts\Filesystem\Factory;

/**
 * Where an entry's two files live: the large copy the lightbox shows and the
 * thumbnail the grid shows.
 *
 * 🚨 Named by the SHA-256 of the uploaded bytes and an extension we chose, so
 * no part of a path comes from the upload: nothing to escape, traverse or
 * overwrite. The disk is declared in extend.php, so a forum can point it at
 * S3 or R2 by overriding one driver.
 */
class Store
{
    public const DISK = 'ernestdefoe-shutter';

    public function __construct(private Factory $filesystem)
    {
    }

    public function disk(): Cloud
    {
        /** @var Cloud */
        return $this->filesystem->disk(self::DISK);
    }

    /** @return array{large: string, thumb: string} */
    public static function paths(string $hash, string $ext): array
    {
        $base = substr($hash, 0, 2).'/'.substr($hash, 2, 2).'/'.$hash;

        return ['large' => "$base.$ext", 'thumb' => "$base-t.$ext"];
    }

    /** @return array{large: string, thumb: string} */
    public function urls(string $hash, string $ext): array
    {
        return array_map(fn (string $path) => $this->disk()->url($path), self::paths($hash, $ext));
    }

    /** @param array{large: string, thumb: string} $files */
    public function put(string $hash, string $ext, array $files): void
    {
        foreach (self::paths($hash, $ext) as $key => $path) {
            // The same photograph entered in two contests shares its files.
            if (! $this->disk()->exists($path)) {
                $this->disk()->put($path, $files[$key]);
            }
        }
    }

    public function forget(string $hash, string $ext): void
    {
        $this->disk()->delete(array_values(self::paths($hash, $ext)));
    }
}
