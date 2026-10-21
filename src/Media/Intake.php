<?php

namespace ErnestDefoe\Shutter\Media;

use Flarum\Foundation\ValidationException;
use Flarum\Locale\TranslatorInterface;
use Flarum\Settings\SettingsRepositoryInterface;
use Intervention\Image\Decoders\BinaryImageDecoder;
use Intervention\Image\Encoders\JpegEncoder;
use Intervention\Image\Encoders\WebpEncoder;
use Intervention\Image\ImageManager;
use Intervention\Image\Interfaces\ImageInterface;
use Psr\Http\Message\UploadedFileInterface;
use Throwable;

/**
 * One uploaded photograph, from untrusted bytes to two re-encoded files.
 *
 * 🚨 The security surface of the extension. Nothing the upload claims is
 * believed: not the filename, not the declared type, not the extension. The
 * type is read from the bytes; the pixels are decoded and encoded again, so
 * what is served is an image we wrote — no EXIF, no GPS, no comment blocks,
 * no polyglot payload riding along after the image data. The upload itself is
 * never stored. SVG, HTML and everything else that is not a raster photograph
 * is refused before it is decoded.
 */
class Intake
{
    /** What a photograph may arrive as. No SVG, no GIF, nothing scriptable. */
    private const ACCEPT = [IMAGETYPE_JPEG, IMAGETYPE_PNG, IMAGETYPE_WEBP];

    /**
     * 🚨 A pixel ceiling as well as a byte ceiling: decoding costs about
     * width × height × 4 bytes, so a 2 MB file of 20,000² pixels wants 1.6 GB.
     */
    public const MAX_PIXELS = 50_000_000;

    public const THUMB = 640;

    public function __construct(
        private ImageManager $images,
        private SettingsRepositoryInterface $settings,
        private TranslatorInterface $translator,
    ) {
    }

    public function maxBytes(): int
    {
        return max(1, min(50, (int) ($this->settings->get('ernestdefoe-shutter.max_mb') ?: 10))) * 1024 * 1024;
    }

    public function maxEdge(): int
    {
        return max(1024, min(4096, (int) ($this->settings->get('ernestdefoe-shutter.max_edge') ?: 2560)));
    }

    /**
     * The cheap checks, before anything is decoded: size, type from the
     * bytes, pixels, animation. Returns what derive() needs and the hash, so
     * a duplicate is refused before it costs a decode.
     *
     * @return array{bytes: string, type: int, hash: string}
     */
    public function inspect(mixed $file): array
    {
        if (! $file instanceof UploadedFileInterface) {
            $this->fail('missing');
        }

        if ($file->getError() !== UPLOAD_ERR_OK) {
            $this->fail(in_array($file->getError(), [UPLOAD_ERR_INI_SIZE, UPLOAD_ERR_FORM_SIZE], true) ? 'too_big' : 'incomplete');
        }

        $size = (int) $file->getSize();

        if ($size <= 0) {
            $this->fail('incomplete');
        }

        if ($size > $this->maxBytes()) {
            $this->fail('too_big');
        }

        $bytes = (string) $file->getStream()->getContents();

        if (strlen($bytes) > $this->maxBytes()) {
            $this->fail('too_big');
        }

        $info = @getimagesizefromstring($bytes);

        if (! $info || ! in_array($info[2], self::ACCEPT, true)) {
            $this->fail('type');
        }

        if ($info[0] < 1 || $info[1] < 1 || $info[0] * $info[1] > $this->pixelBudget()) {
            $this->fail('pixels');
        }

        // 🚨 An animated WebP or PNG reports one frame's size here and then
        // decodes every frame (Imagick): a small file worth minutes of CPU.
        if ($this->animated($bytes, $info[2])) {
            $this->fail('animated');
        }

        return ['bytes' => $bytes, 'type' => $info[2], 'hash' => hash('sha256', $bytes)];
    }

    /**
     * Decode once and shrink in place, large then thumbnail then blur, so no
     * more than one full-size copy is ever held.
     *
     * @param array{bytes: string, type: int, hash: string} $upload
     * @return array{hash: string, ext: string, width: int, height: int, size: int, blur: string, camera: ?array<string, string>, files: array{large: string, thumb: string}}
     */
    public function derive(array $upload): array
    {
        try {
            // Turned the right way up from the EXIF orientation, which is then
            // gone with the rest of the metadata.
            $image = $this->images->read($upload['bytes'], BinaryImageDecoder::class);
        } catch (Throwable) {
            $this->fail('damaged');
        }

        [$encoder, $ext] = $this->format();

        $image->scaleDown($this->maxEdge(), $this->maxEdge());
        $width = $image->width();
        $height = $image->height();
        $large = $this->encode($image, $encoder, 85);
        $thumb = $this->encode($image->scaleDown(self::THUMB, self::THUMB), $encoder, 80);
        $blur = $this->encode($image->scaleDown(16, 16), $encoder, 40);

        return [
            'hash' => $upload['hash'],
            'ext' => $ext,
            'width' => $width,
            'height' => $height,
            'size' => strlen($large),
            'blur' => 'data:'.($ext === 'webp' ? 'image/webp' : 'image/jpeg').';base64,'.base64_encode($blur),
            'camera' => Camera::read($upload['bytes'], $upload['type']),
            'files' => ['large' => $large, 'thumb' => $thumb],
        ];
    }

    /**
     * The most pixels this PHP process can decode: 50 MP, or fewer when the
     * memory limit would not hold a full-size copy (4 bytes a pixel) and the
     * first resized one beside it.
     */
    private function pixelBudget(): int
    {
        $limit = $this->bytesOf((string) ini_get('memory_limit'));

        if ($limit <= 0) {
            return self::MAX_PIXELS;
        }

        $free = $limit - memory_get_usage(true) - 24 * 1024 * 1024;

        return (int) max(0, min(self::MAX_PIXELS, $free / 5));
    }

    private function bytesOf(string $value): int
    {
        $number = (int) $value;

        return match (strtolower(substr(trim($value), -1))) {
            'g' => $number * 1024 ** 3,
            'm' => $number * 1024 ** 2,
            'k' => $number * 1024,
            default => $number,
        };
    }

    private function animated(string $bytes, int $type): bool
    {
        return match ($type) {
            // VP8X header, animation flag.
            IMAGETYPE_WEBP => substr($bytes, 12, 4) === 'VP8X' && (ord($bytes[20] ?? "\0") & 0x02) === 0x02,
            // An APNG's animation control chunk comes before its image data.
            IMAGETYPE_PNG => ($actl = strpos($bytes, 'acTL')) !== false && $actl < (int) strpos($bytes, 'IDAT'),
            default => false,
        };
    }

    /**
     * WebP when the forum's image driver can write it (a third of the size of
     * a JPEG at the same quality), JPEG otherwise.
     *
     * @return array{0: class-string<JpegEncoder|WebpEncoder>, 1: string}
     */
    private function format(): array
    {
        return $this->images->driver()->supports('webp') ? [WebpEncoder::class, 'webp'] : [JpegEncoder::class, 'jpg'];
    }

    /**
     * 🚨 strip: true. Intervention keeps metadata on the Imagick driver unless
     * told otherwise (GD drops it anyway), and a forum chooses its driver in
     * config.php — so without this a forum on Imagick would serve the GPS
     * position of every entrant's home.
     *
     * @param class-string<JpegEncoder|WebpEncoder> $encoder
     */
    private function encode(ImageInterface $image, string $encoder, int $quality): string
    {
        return (string) $image->encode(new $encoder(quality: $quality, strip: true));
    }

    private function fail(string $reason): never
    {
        throw new ValidationException(['photo' => $this->translator->trans("ernestdefoe-shutter.api.upload.$reason", [
            'size' => $this->maxBytes() / 1024 / 1024,
        ])]);
    }
}
