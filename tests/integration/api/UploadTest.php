<?php

namespace ErnestDefoe\Shutter\Tests\integration\api;

use ErnestDefoe\Shutter\Tests\integration\ShutterTestCase;
use ErnestDefoe\Shutter\Tests\Photos;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;

/**
 * 🚨 The upload path: what is served is a re-encoded image with no metadata,
 * whatever arrived, on either of Flarum's image drivers.
 */
class UploadTest extends ShutterTestCase
{
    /** @return array<string, array{string}> */
    public static function drivers(): array
    {
        return ['gd' => ['gd'], 'imagick' => ['imagick']];
    }

    #[Test]
    #[DataProvider('drivers')]
    public function gps_and_every_other_tag_is_gone_from_the_served_files(string $driver): void
    {
        if ($driver === 'imagick' && ! extension_loaded('imagick')) {
            $this->markTestSkipped('imagick not installed');
        }

        $this->config('intervention.driver', $driver);
        $contest = $this->contest();
        // Fresh bytes every run: files are named by their hash and never
        // rewritten, so a repeat would serve the other driver's copy.
        $original = Photos::jpegWithGps(800, 600, random_int(1, 2 ** 31 - 1));
        $this->assertArrayHasKey('GPS', exif_read_data('data://image/jpeg;base64,'.base64_encode($original), null, true));

        $response = $this->submit($contest, 4, $original);
        $entry = $this->json($response)['data'];
        $this->assertSame(201, $response->getStatusCode(), (string) $response->getBody());

        foreach (['large', 'thumb'] as $key) {
            $bytes = $this->file($entry[$key]);
            $this->assertNotSame('', $bytes);
            $this->assertStringNotContainsString('Exif', $bytes, "$key keeps an EXIF block");
            $this->assertStringNotContainsString('ShutterCam', $bytes, "$key keeps the camera tag");
            $this->assertNotFalse(@imagecreatefromstring($bytes));
        }

        // Orientation 6 was applied to the pixels: 800×600 became 600×800.
        $this->assertSame([600, 800], [$entry['width'], $entry['height']]);

        // The camera is kept for the caption; the position never reaches the database.
        $this->assertSame('ShutterCam X100', $entry['camera']['model']);
        $row = (array) $this->database()->table('shutter_entries')->first();
        $this->assertStringNotContainsStringIgnoringCase('gps', json_encode($row));
        $this->assertStringNotContainsString('51/1', json_encode($row));
    }

    #[Test]
    public function anything_appended_after_the_image_does_not_survive(): void
    {
        $contest = $this->contest();
        $polyglot = Photos::jpeg(400, 300, 7).'<html><script>alert(document.cookie)</script></html>';

        $entry = $this->json($this->submit($contest, 4, $polyglot))['data'];

        foreach (['large', 'thumb'] as $key) {
            $this->assertStringNotContainsString('<script', $this->file($entry[$key]));
        }
        $this->assertMatchesRegularExpression('~\.(webp|jpg)$~', $entry['large']);
    }

    /** @return array<string, array{string, string, string}> */
    public static function refused(): array
    {
        return [
            'svg' => ['<svg xmlns="http://www.w3.org/2000/svg" onload="alert(1)"><rect width="10" height="10"/></svg>', 'a.svg', 'image/svg+xml'],
            'html named .jpg' => ['<!doctype html><script>alert(1)</script>', 'photo.jpg', 'image/jpeg'],
            'gif' => [base64_decode('R0lGODlhAQABAIAAAP///wAAACH5BAEAAAAALAAAAAABAAEAAAICRAEAOw=='), 'a.gif', 'image/gif'],
            'truncated jpeg' => [substr(Photos::jpeg(), 0, 200), 'a.jpg', 'image/jpeg'],
            'empty' => ['', 'a.jpg', 'image/jpeg'],
        ];
    }

    #[Test]
    #[DataProvider('refused')]
    public function only_real_photographs_are_accepted(string $bytes, string $name, string $type): void
    {
        $contest = $this->contest();

        $response = $this->send(
            $this->request('POST', "/api/shutter/contests/$contest/entries", ['authenticatedAs' => 4])
                ->withParsedBody(['title' => 'x'])
                ->withUploadedFiles(['photo' => Photos::upload($bytes, $name, $type)])
        );

        $this->assertSame(422, $response->getStatusCode(), (string) $response->getBody());
        $this->assertSame(0, $this->database()->table('shutter_entries')->count());
    }

    #[Test]
    public function a_pixel_bomb_is_refused_before_it_is_decoded(): void
    {
        $contest = $this->contest();
        $before = memory_get_peak_usage();

        $response = $this->submit($contest, 4, Photos::pixelBomb());

        $this->assertSame(422, $response->getStatusCode());
        $this->assertStringContainsString('pixels', strtolower((string) $response->getBody()));
        $this->assertLessThan(50 * 1024 * 1024, memory_get_peak_usage() - $before);
    }

    #[Test]
    public function the_size_cap_holds(): void
    {
        $this->setting('ernestdefoe-shutter.max_mb', 1);
        $contest = $this->contest();
        $noise = imagecreatetruecolor(2000, 2000);
        for ($i = 0; $i < 40000; $i++) {
            imagesetpixel($noise, random_int(0, 1999), random_int(0, 1999), random_int(0, 0xFFFFFF));
        }
        for ($y = 0; $y < 2000; $y += 2) {
            for ($x = 0; $x < 2000; $x += 2) {
                imagesetpixel($noise, $x, $y, random_int(0, 0xFFFFFF));
            }
        }
        ob_start();
        imagejpeg($noise, null, 100);
        $big = (string) ob_get_clean();
        $this->assertGreaterThan(1024 * 1024, strlen($big));

        $this->assertSame(422, $this->submit($contest, 4, $big)->getStatusCode());
    }

    #[Test]
    public function the_large_copy_is_capped_and_the_thumbnail_is_small(): void
    {
        $this->setting('ernestdefoe-shutter.max_edge', 1024);
        $contest = $this->contest();

        $entry = $this->json($this->submit($contest, 4, Photos::jpeg(3000, 1500)))['data'];

        $this->assertSame([1024, 512], [$entry['width'], $entry['height']]);
        $thumb = getimagesizefromstring($this->file($entry['thumb']));
        $this->assertSame(640, max($thumb[0], $thumb[1]));
        $this->assertStringStartsWith('data:image/', $entry['blur']);
        $this->assertLessThan(600, strlen($entry['blur']));
    }

    #[Test]
    public function the_same_photo_twice_in_a_contest_is_refused_but_shared_across_contests(): void
    {
        $one = $this->contest(1);
        $two = $this->contest(2);
        $bytes = Photos::jpeg(500, 500, 42);

        $a = $this->entryId($this->submit($one, 4, $bytes));
        $this->assertSame(422, $this->submit($one, 5, $bytes)->getStatusCode());
        $b = $this->entryId($this->submit($two, 5, $bytes));

        $row = $this->database()->table('shutter_entries')->find($a);
        $large = $this->json($this->send($this->request('GET', "/api/shutter/contests/$one", ['authenticatedAs' => 4])))['data']['entries'][0]['large'];

        // Withdrawing one leaves the other's files in place; the last one takes them.
        $this->assertSame(204, $this->send($this->request('DELETE', "/api/shutter/entries/$a", ['authenticatedAs' => 4]))->getStatusCode());
        $this->assertNotSame('', $this->file($large));
        $this->assertSame(204, $this->send($this->request('DELETE', "/api/shutter/entries/$b", ['authenticatedAs' => 5]))->getStatusCode());
        $this->assertSame('', @$this->file($large));
        $this->assertNotNull($row);
    }
}
