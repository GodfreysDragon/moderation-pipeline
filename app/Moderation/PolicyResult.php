<?php

namespace App\Moderation;

use App\Enums\Category;
use App\Enums\Outcome;

final readonly class PolicyResult
{
    /**
     * @param  list<array{category: string, score: float, action: string}>  $triggered
     * @param  int  $priority  0-100: how urgently a person should look, from score x severity
     */
    public function __construct(
        public Outcome $outcome,
        public ?Category $category,
        public array $triggered,
        public int $priority,
        public ?string $reason,
    ) {}
}
