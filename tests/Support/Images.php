<?php

namespace Tests\Support;

/**
 * Generated test images, so the suite needs no binary fixtures.
 */
final class Images
{
    /**
     * A PNG with a diagonal gradient and a few shapes, varied by $seed.
     */
    public static function png(int $width = 320, int $height = 240, int $seed = 1): string
    {
        return self::encode(self::draw($width, $height, $seed), 'png');
    }

    public static function jpeg(int $width = 320, int $height = 240, int $seed = 1): string
    {
        return self::encode(self::draw($width, $height, $seed), 'jpeg');
    }

    /**
     * A JPEG carrying an EXIF block with a GPS position, like a phone photo.
     */
    public static function jpegWithGps(int $seed = 1): string
    {
        $jpeg = self::jpeg(seed: $seed);
        $tiff = self::tiffWithGps();
        $app1 = "\xFF\xE1".pack('n', strlen($tiff) + 8)."Exif\0\0".$tiff;

        // Insert the APP1 segment straight after the SOI marker.
        return substr($jpeg, 0, 2).$app1.substr($jpeg, 2);
    }

    /**
     * Draw the scene for $seed at a fixed size, then resample, so every size of
     * one seed is the same picture.
     */
    private static function draw(int $width, int $height, int $seed): \GdImage
    {
        $scene = self::scene(640, 480, $seed);
        $image = imagecreatetruecolor($width, $height);
        imagecopyresampled($image, $scene, 0, 0, 0, 0, $width, $height, 640, 480);

        return $image;
    }

    private static function scene(int $width, int $height, int $seed): \GdImage
    {
        mt_srand($seed);
        $image = imagecreatetruecolor($width, $height);

        for ($x = 0; $x < $width; $x += 4) {
            $shade = (int) (255 * $x / $width);
            imagefilledrectangle($image, $x, 0, $x + 3, $height, imagecolorallocate($image, $shade, (int) ($shade / 2), 255 - $shade));
        }

        for ($i = 0; $i < 6; $i++) {
            $color = imagecolorallocate($image, mt_rand(0, 255), mt_rand(0, 255), mt_rand(0, 255));
            imagefilledellipse($image, mt_rand(0, $width), mt_rand(0, $height), mt_rand(20, $width / 2), mt_rand(20, $height / 2), $color);
        }

        mt_srand();

        return $image;
    }

    private static function encode(\GdImage $image, string $format): string
    {
        ob_start();
        $format === 'png' ? imagepng($image) : imagejpeg($image, null, 90);

        return (string) ob_get_clean();
    }

    /**
     * Little-endian TIFF: IFD0 -> GPS IFD with GPSLatitudeRef "N" and GPSLatitude 51/1 30/1 0/1.
     */
    private static function tiffWithGps(): string
    {
        $header = 'II'.pack('v', 42).pack('V', 8);

        // IFD0 at offset 8: one entry (GPSInfo pointer), then next-IFD = 0.
        $gpsIfdOffset = 8 + 2 + 12 + 4;
        $ifd0 = pack('v', 1).pack('vvVV', 0x8825, 4, 1, $gpsIfdOffset).pack('V', 0);

        // GPS IFD: two entries, next-IFD = 0, then the rational data.
        $rationalOffset = $gpsIfdOffset + 2 + 2 * 12 + 4;
        $gps = pack('v', 2)
            .pack('vvV', 0x0001, 2, 2)."N\0\0\0"
            .pack('vvVV', 0x0002, 5, 3, $rationalOffset)
            .pack('V', 0);
        $rationals = pack('VVVVVV', 51, 1, 30, 1, 0, 1);

        return $header.$ifd0.$gps.$rationals;
    }
}
