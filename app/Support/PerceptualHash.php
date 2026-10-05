<?php

namespace App\Support;

use GdImage;
use InvalidArgumentException;

/**
 * Difference hash (dHash): a 64-bit fingerprint that stays nearly the same when
 * an image is resized, re-compressed, brightened or lightly edited, so
 * near-duplicates of blocked images can be caught without the AI model.
 */
final class PerceptualHash
{
    /**
     * Shrink to 9x8 greyscale and record whether each pixel is brighter than its
     * right-hand neighbour: 8 comparisons x 8 rows = 64 bits, as 16 hex chars.
     */
    public static function dhash(GdImage $image): string
    {
        $small = imagecreatetruecolor(9, 8);
        imagecopyresampled($small, $image, 0, 0, 0, 0, 9, 8, imagesx($image), imagesy($image));

        $bits = '';

        for ($y = 0; $y < 8; $y++) {
            $left = self::luminance($small, 0, $y);

            for ($x = 1; $x < 9; $x++) {
                $right = self::luminance($small, $x, $y);
                $bits .= $left > $right ? '1' : '0';
                $left = $right;
            }
        }

        // Two 32-bit halves keep base_convert exact on every platform.
        return str_pad(base_convert(substr($bits, 0, 32), 2, 16), 8, '0', STR_PAD_LEFT)
            .str_pad(base_convert(substr($bits, 32), 2, 16), 8, '0', STR_PAD_LEFT);
    }

    /**
     * Number of differing bits between two hashes (0 = identical, 64 = opposite).
     */
    public static function distance(string $a, string $b): int
    {
        if (strlen($a) !== 16 || strlen($b) !== 16 || ! ctype_xdigit($a.$b)) {
            throw new InvalidArgumentException('Perceptual hashes must be 16 hex characters.');
        }

        $distance = 0;

        foreach ([0, 8] as $offset) {
            $xor = hexdec(substr($a, $offset, 8)) ^ hexdec(substr($b, $offset, 8));

            while ($xor > 0) {
                $distance += $xor & 1;
                $xor >>= 1;
            }
        }

        return $distance;
    }

    private static function luminance(GdImage $image, int $x, int $y): float
    {
        $rgb = imagecolorat($image, $x, $y);

        return 0.299 * (($rgb >> 16) & 0xFF) + 0.587 * (($rgb >> 8) & 0xFF) + 0.114 * ($rgb & 0xFF);
    }
}
