<?php

namespace ErnestDefoe\Shutter\Media;

/**
 * What the camera recorded, as a photographer would write it — and nothing
 * that says where it was standing.
 *
 * 🚨 Only the tags below are read, and GPS is not one of them: coordinates are
 * never stored, so they cannot leak through an export, a dump or a bug. The
 * served files carry no metadata at all; they are re-encoded.
 */
class Camera
{
    private const KEEP = [
        'Make' => 'make',
        'Model' => 'model',
        'LensModel' => 'lens',
        'ExposureTime' => 'shutter',
        'FNumber' => 'aperture',
        'ISOSpeedRatings' => 'iso',
        'FocalLength' => 'focal',
    ];

    /** @return array<string, string>|null */
    public static function read(string $bytes, int $type): ?array
    {
        if ($type !== IMAGETYPE_JPEG || ! function_exists('exif_read_data')) {
            return null;
        }

        // A memory stream, not a data: URL, which allow_url_fopen=0 refuses.
        $stream = fopen('php://memory', 'r+b');

        if ($stream === false) {
            return null;
        }

        fwrite($stream, $bytes);
        rewind($stream);
        $raw = @exif_read_data($stream, 'EXIF,IFD0', true);
        fclose($stream);

        if (! is_array($raw)) {
            return null;
        }

        $flat = array_merge($raw['IFD0'] ?? [], $raw['EXIF'] ?? []);
        $out = [];

        foreach (self::KEEP as $tag => $key) {
            $value = $flat[$tag] ?? null;
            $value = is_array($value) ? reset($value) : $value;

            if (! is_scalar($value) || trim((string) $value) === '') {
                continue;
            }

            $out[$key] = mb_substr(self::pretty($key, trim((string) $value)), 0, 60);
        }

        // "Canon Canon EOS R5" reads better as "Canon EOS R5".
        if (isset($out['make'], $out['model']) && stripos($out['model'], $out['make']) === 0) {
            unset($out['make']);
        }

        return $out ?: null;
    }

    private static function pretty(string $key, string $value): string
    {
        $number = self::rational($value);

        return match (true) {
            $number === null => $value,
            $key === 'shutter' => $number >= 1 ? rtrim(rtrim(number_format($number, 1), '0'), '.').'s' : '1/'.max(1, (int) round(1 / max($number, 0.000001))).'s',
            $key === 'aperture' => 'f/'.rtrim(rtrim(number_format($number, 1), '0'), '.'),
            $key === 'focal' => round($number).' mm',
            $key === 'iso' => 'ISO '.(int) $number,
            default => $value,
        };
    }

    private static function rational(string $value): ?float
    {
        if (preg_match('~^(\d+(?:\.\d+)?)/(\d+(?:\.\d+)?)$~', $value, $m)) {
            return (float) $m[2] == 0.0 ? null : (float) $m[1] / (float) $m[2];
        }

        return is_numeric($value) ? (float) $value : null;
    }
}
