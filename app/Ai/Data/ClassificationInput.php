<?php

namespace App\Ai\Data;

final readonly class ClassificationInput
{
    public function __construct(
        public ?string $caption,
        public ?string $imageBytes = null,
        public ?string $imageMime = null,
        public ?string $filename = null,
    ) {}

    public function hasImage(): bool
    {
        return $this->imageBytes !== null;
    }
}
