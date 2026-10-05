<?php

namespace App\Support;

final readonly class SanitizedImage
{
    public function __construct(
        public string $bytes,
        public string $thumbnailBytes,
        public string $mime,
        public string $extension,
        public int $width,
        public int $height,
        public bool $hadGps,
        public string $sha256,
        public string $dhash,
    ) {}
}
