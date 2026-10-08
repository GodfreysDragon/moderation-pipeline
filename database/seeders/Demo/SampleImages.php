<?php

namespace Database\Seeders\Demo;

use GdImage;

/**
 * Procedurally drawn demo images, so the repository ships no stock photos.
 * Scenes are harmless landscapes; posters carry text a vision model can read
 * (and that the offline classifier matches through the filename).
 */
final class SampleImages
{
    /**
     * The upload samples offered on the Submit page (public/samples).
     *
     * @return array<string, string> filename => PNG bytes
     */
    public static function uploadSamples(): array
    {
        return [
            'sunset-harbour.png' => self::scene(11),
            'crypto-giveaway.png' => self::poster(['CRYPTO', 'GIVEAWAY', '500% RETURNS', 'LINK IN BIO'], [17, 17, 17], [250, 190, 40]),
            'parts-for-sale.png' => self::poster(['PARTS', 'FOR SALE', 'NO PAPERS', 'DM TO BUY'], [90, 20, 20], [255, 255, 255]),
            'known-bad.png' => self::knownBad(),
        ];
    }

    /**
     * A harmless landscape, varied by seed: sky gradient, sun, sea and hills.
     */
    public static function scene(int $seed, int $width = 800, int $height = 600): string
    {
        mt_srand($seed);
        $image = imagecreatetruecolor($width, $height);

        $top = [mt_rand(20, 90), mt_rand(40, 120), mt_rand(120, 200)];
        $bottom = [mt_rand(200, 255), mt_rand(120, 200), mt_rand(60, 140)];
        $horizon = (int) ($height * (0.55 + mt_rand(0, 15) / 100));

        for ($y = 0; $y < $horizon; $y++) {
            $t = $y / $horizon;
            $color = imagecolorallocate($image, ...array_map(fn ($a, $b) => (int) ($a + ($b - $a) * $t), $top, $bottom));
            imageline($image, 0, $y, $width, $y, $color);
        }

        $sun = imagecolorallocate($image, 255, mt_rand(200, 240), mt_rand(120, 180));
        $sunSize = mt_rand(70, 140);
        imagefilledellipse($image, mt_rand(100, $width - 100), $horizon - mt_rand(40, 160), $sunSize, $sunSize, $sun);

        $sea = [mt_rand(10, 40), mt_rand(60, 110), mt_rand(110, 160)];
        imagefilledrectangle($image, 0, $horizon, $width, $height, imagecolorallocate($image, ...$sea));

        for ($i = 0; $i < 3; $i++) {
            $shade = 30 + $i * 25;
            $hill = imagecolorallocate($image, $shade, $shade + mt_rand(10, 40), $shade);
            $points = [0, $height];
            $x = 0;

            while ($x <= $width) {
                $points[] = $x;
                $points[] = $horizon + mt_rand(-60, 40) + $i * 45;
                $x += mt_rand(60, 140);
            }

            array_push($points, $width, $horizon + $i * 45, $width, $height);
            imagefilledpolygon($image, $points, $hill);
        }

        // Sparkle on the water
        $glint = imagecolorallocatealpha($image, 255, 255, 255, 80);

        for ($i = 0; $i < 40; $i++) {
            $gx = mt_rand(0, $width);
            $gy = mt_rand($horizon + 5, $height - 5);
            imageline($image, $gx, $gy, $gx + mt_rand(6, 20), $gy, $glint);
        }

        mt_srand();

        return self::png($image);
    }

    /**
     * Big blocky text on a flat background.
     *
     * @param  list<string>  $lines
     * @param  array{int, int, int}  $background
     * @param  array{int, int, int}  $foreground
     */
    public static function poster(array $lines, array $background, array $foreground, int $width = 800, int $height = 600): string
    {
        $font = 5;
        $charW = imagefontwidth($font);
        $charH = imagefontheight($font);
        $longest = max(array_map('strlen', $lines));

        // Draw small, then scale up with nearest-neighbour for crisp pixel text.
        $small = imagecreatetruecolor($longest * $charW + 8, count($lines) * ($charH + 4) + 8);
        imagefill($small, 0, 0, imagecolorallocate($small, ...$background));
        $ink = imagecolorallocate($small, ...$foreground);

        foreach ($lines as $i => $line) {
            $x = (int) ((imagesx($small) - strlen($line) * $charW) / 2);
            imagestring($small, $font, $x, 4 + $i * ($charH + 4), $line, $ink);
        }

        $image = imagecreatetruecolor($width, $height);
        imagefill($image, 0, 0, imagecolorallocate($image, ...$background));
        $scale = min(($width - 80) / imagesx($small), ($height - 80) / imagesy($small));
        $w = (int) (imagesx($small) * $scale);
        $h = (int) (imagesy($small) * $scale);
        imagecopyresized($image, $small, (int) (($width - $w) / 2), (int) (($height - $h) / 2), 0, 0, $w, $h, imagesx($small), imagesy($small));

        return self::png($image);
    }

    /**
     * A stand-in for an image moderators already removed: the seeder puts its
     * hash on the blocklist, so uploading it demonstrates hash matching.
     */
    public static function knownBad(): string
    {
        $image = imagecreatetruecolor(800, 600);

        for ($y = 0; $y < 600; $y += 50) {
            for ($x = 0; $x < 800; $x += 50) {
                $on = (($x + $y) / 50) % 2 === 0;
                imagefilledrectangle($image, $x, $y, $x + 49, $y + 49, $on ? imagecolorallocate($image, 230, 60, 140) : imagecolorallocate($image, 30, 30, 60));
            }
        }

        $poster = imagecreatefromstring(self::poster(['REMOVED', 'MEME'], [255, 255, 255], [0, 0, 0], 400, 200));
        imagecopy($image, $poster, 200, 200, 0, 0, 400, 200);

        return self::png($image);
    }

    private static function png(GdImage $image): string
    {
        ob_start();
        imagepng($image, null, 6);

        return (string) ob_get_clean();
    }
}
