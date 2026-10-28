<?php

namespace App\Ai\Data;

/**
 * The results of an ended batch: a classification or an error message per item.
 */
final readonly class BatchOutcome
{
    /**
     * @param  array<string, Classification>  $classifications  keyed by the id the item was submitted with
     * @param  array<string, string>  $errors
     */
    public function __construct(
        public array $classifications,
        public array $errors,
    ) {}
}
