<?php

namespace App\Support;

use GdImage;
use RuntimeException;

/**
 * Checked wrappers for the GD calls that report failure as `false`.
 */
final class Gd
{
    public static function canvas(int $width, int $height): GdImage
    {
        return imagecreatetruecolor(max(1, $width), max(1, $height));
    }

    /**
     * Allocate a colour; channels are clamped to 0-255 and alpha to 0 (opaque) - 127 (transparent).
     */
    public static function color(GdImage $image, int $red, int $green, int $blue, int $alpha = 0): int
    {
        $color = imagecolorallocatealpha($image, self::channel($red), self::channel($green), self::channel($blue), max(0, min(127, $alpha)));

        if ($color === false) {
            throw new RuntimeException('Could not allocate a GD colour.');
        }

        return $color;
    }

    /**
     * @return int<0, 255>
     */
    private static function channel(int $value): int
    {
        return max(0, min(255, $value));
    }
}
