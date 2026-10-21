<?php

namespace ErnestDefoe\Shutter\Tests;

use Laminas\Diactoros\StreamFactory;
use Laminas\Diactoros\UploadedFile;

/** Test photographs, made on the spot. */
class Photos
{
    /** A JPEG of the given size, in a colour derived from $seed (so each is a different file). */
    public static function jpeg(int $w = 800, int $h = 600, int $seed = 1): string
    {
        $img = imagecreatetruecolor($w, $h);
        imagefill($img, 0, 0, imagecolorallocate($img, ($seed * 53) % 256, ($seed * 97) % 256, ($seed * 29) % 256));
        // A white block in the top-left corner, to tell which way up it is.
        imagefilledrectangle($img, 0, 0, (int) ($w / 4), (int) ($h / 4), imagecolorallocate($img, 255, 255, 255));
        // The seed itself, drawn as a row of pixels: two seeds, two files.
        for ($bit = 0; $bit < 32; $bit++) {
            imagefilledrectangle($img, $w - 4 * ($bit + 1), $h - 8, $w - 4 * $bit - 1, $h - 1, ($seed >> $bit) & 1 ? 0xFFFFFF : 0);
        }
        ob_start();
        imagejpeg($img, null, 90);

        return (string) ob_get_clean();
    }

    /**
     * The same JPEG carrying an EXIF block with a camera, an orientation
     * (6 = rotate 90° clockwise) and a GPS position — built byte by byte, the
     * way a phone writes it.
     */
    public static function jpegWithGps(int $w = 800, int $h = 600, int $seed = 1): string
    {
        $jpeg = self::jpeg($w, $h, $seed);

        $le = fn (int $v, int $bytes) => substr(pack('V', $v), 0, $bytes);
        $entry = fn (int $tag, int $type, int $count, string $value) => $le($tag, 2).$le($type, 2).$le($count, 4).str_pad($value, 4, "\0");

        // Layout: header(8) | IFD0 (2 + 4*12 + 4 = 54) | GPS IFD (2 + 3*12 + 4 = 42) | data
        $ifd0At = 8;
        $gpsAt = $ifd0At + 54;
        $dataAt = $gpsAt + 42;
        $make = "ShutterCam\0";
        $model = "ShutterCam X100\0";
        $makeAt = $dataAt;
        $modelAt = $makeAt + strlen($make);
        $latAt = $modelAt + strlen($model);
        $lat = $le(51, 4).$le(1, 4).$le(30, 4).$le(1, 4).$le(1234, 4).$le(100, 4);
        $lngAt = $latAt + 24;
        $lng = $le(0, 4).$le(1, 4).$le(7, 4).$le(1, 4).$le(4321, 4).$le(100, 4);

        $tiff = 'II'.$le(42, 2).$le($ifd0At, 4)
            .$le(4, 2)
            .$entry(0x010F, 2, strlen($make), $le($makeAt, 4))
            .$entry(0x0110, 2, strlen($model), $le($modelAt, 4))
            .$entry(0x0112, 3, 1, $le(6, 2))
            .$entry(0x8825, 4, 1, $le($gpsAt, 4))
            .$le(0, 4)
            .$le(3, 2)
            .$entry(0x0001, 2, 2, "N\0")
            .$entry(0x0002, 5, 3, $le($latAt, 4))
            .$entry(0x0004, 5, 3, $le($lngAt, 4))
            .$le(0, 4)
            .$make.$model.$lat.$lng;

        $app1 = "Exif\0\0".$tiff;
        $segment = "\xFF\xE1".pack('n', strlen($app1) + 2).$app1;

        // After SOI.
        return substr($jpeg, 0, 2).$segment.substr($jpeg, 2);
    }

    public static function png(int $w = 300, int $h = 200): string
    {
        $img = imagecreatetruecolor($w, $h);
        imagefill($img, 0, 0, imagecolorallocate($img, 10, 120, 200));
        ob_start();
        imagepng($img);

        return (string) ob_get_clean();
    }

    /** A real PNG header claiming 20,000 × 20,000 pixels, and almost nothing after it. */
    public static function pixelBomb(): string
    {
        $ihdr = pack('NNCCCCC', 20000, 20000, 8, 2, 0, 0, 0);

        return "\x89PNG\r\n\x1a\n".pack('N', 13).'IHDR'.$ihdr.pack('N', crc32('IHDR'.$ihdr)).str_repeat("\0", 64);
    }

    public static function upload(string $bytes, string $name = 'photo.jpg', string $type = 'image/jpeg'): UploadedFile
    {
        return new UploadedFile((new StreamFactory())->createStream($bytes), strlen($bytes), UPLOAD_ERR_OK, $name, $type);
    }
}
