<?php

namespace App\Ai\Exceptions;

use RuntimeException;

/**
 * The model declined to classify (stop_reason "refusal") and no fallback model
 * completed it. Retrying the same input won't help, so the item goes to a person.
 */
class ClassifierRefusedException extends RuntimeException
{
    public static function withCategory(?string $category): self
    {
        return new self($category
            ? "The classifier declined this item (category: {$category})."
            : 'The classifier declined this item.');
    }
}
