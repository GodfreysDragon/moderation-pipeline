<?php

namespace App\Support;

use App\Exceptions\UnprocessableMediaException;
use GdImage;

/**
 * Turns an untrusted upload into a safe, normalised image:
 *
 *  - checks the real type from the bytes (not the extension or client MIME);
 *  - refuses decompression bombs before decoding;
 *  - applies EXIF orientation, then re-encodes, which drops all metadata
 *    (GPS position, camera serials, embedded payloads);
 *  - caps the size and makes a thumbnail;
 *  - fingerprints it (SHA-256 of the original bytes + perceptual hash).
 */
final class MediaSanitizer
{
    private const MAX_PIXELS = 40_000_000;

    private const ALLOWED = [IMAGETYPE_JPEG, IMAGETYPE_PNG, IMAGETYPE_GIF, IMAGETYPE_WEBP];

    public function __construct(
        private readonly int $maxDimension,
        private readonly int $thumbnailDimension,
    ) {}

    public function sanitize(string $bytes): SanitizedImage
    {
        $info = @getimagesizefromstring($bytes);

        if ($info === false || ! in_array($info[2], self::ALLOWED, true)) {
            throw new UnprocessableMediaException('The file is not a supported image (JPEG, PNG, GIF or WebP).');
        }

        if ($info[0] * $info[1] > self::MAX_PIXELS) {
            throw new UnprocessableMediaException('The image has too many pixels to process safely.');
        }

        $image = @imagecreatefromstring($bytes);

        if ($image === false) {
            throw new UnprocessableMediaException('The image could not be decoded.');
        }

        $hadGps = false;

        if ($info[2] === IMAGETYPE_JPEG) {
            $exif = @exif_read_data('data://image/jpeg;base64,'.base64_encode($bytes));

            if (is_array($exif)) {
                $hadGps = isset($exif['GPSLatitude']) || isset($exif['GPSLongitude']);
                $image = $this->applyOrientation($image, (int) ($exif['Orientation'] ?? 1));
            }
        }

        // PNG and GIF may be transparent, so they stay PNG; everything else becomes JPEG.
        $keepsAlpha = in_array($info[2], [IMAGETYPE_PNG, IMAGETYPE_GIF], true);

        $full = $this->fit($image, $this->maxDimension, $keepsAlpha);
        $thumb = $this->fit($image, $this->thumbnailDimension, $keepsAlpha);

        return new SanitizedImage(
            bytes: $this->encode($full, $keepsAlpha),
            thumbnailBytes: $this->encode($thumb, $keepsAlpha),
            mime: $keepsAlpha ? 'image/png' : 'image/jpeg',
            extension: $keepsAlpha ? 'png' : 'jpg',
            width: imagesx($full),
            height: imagesy($full),
            hadGps: $hadGps,
            sha256: hash('sha256', $bytes),
            dhash: PerceptualHash::dhash($image),
        );
    }

    private function applyOrientation(GdImage $image, int $orientation): GdImage
    {
        $rotated = match ($orientation) {
            3 => imagerotate($image, 180, 0),
            6 => imagerotate($image, -90, 0),
            8 => imagerotate($image, 90, 0),
            default => $image,
        };

        return $rotated === false ? $image : $rotated;
    }

    private function fit(GdImage $image, int $max, bool $keepsAlpha): GdImage
    {
        $width = imagesx($image);
        $height = imagesy($image);
        $scale = min(1, $max / max($width, $height));
        $newWidth = max(1, (int) round($width * $scale));
        $newHeight = max(1, (int) round($height * $scale));

        $canvas = imagecreatetruecolor($newWidth, $newHeight);

        if ($keepsAlpha) {
            imagealphablending($canvas, false);
            imagesavealpha($canvas, true);
            imagefill($canvas, 0, 0, imagecolorallocatealpha($canvas, 0, 0, 0, 127));
        } else {
            imagefill($canvas, 0, 0, imagecolorallocate($canvas, 255, 255, 255));
        }

        imagecopyresampled($canvas, $image, 0, 0, 0, 0, $newWidth, $newHeight, $width, $height);

        return $canvas;
    }

    private function encode(GdImage $image, bool $png): string
    {
        ob_start();
        $png ? imagepng($image, null, 6) : imagejpeg($image, null, 85);

        return (string) ob_get_clean();
    }
}
