<?php

namespace App\Ai\Data;

/**
 * What one classification cost in tokens, as the API reported it.
 */
final readonly class TokenUsage
{
    public function __construct(
        public int $input,
        public int $output,
        public int $cacheRead = 0,
        public int $cacheWrite = 0,
    ) {}

    /**
     * Every input token the request carried, whether billed full price, as a cache write or as a cache read.
     */
    public function totalInput(): int
    {
        return $this->input + $this->cacheRead + $this->cacheWrite;
    }
}
