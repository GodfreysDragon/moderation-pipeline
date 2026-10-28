<?php

namespace App\Ai\Contracts;

use App\Ai\Data\BatchOutcome;
use App\Ai\Data\ClassificationInput;

/**
 * Classifies many items asynchronously at batch prices, for offline evaluation.
 */
interface BatchClassifier
{
    /**
     * Start a batch and return its id.
     *
     * @param  array<string, ClassificationInput>  $inputs  keyed by a caller-chosen id
     */
    public function submit(array $inputs, string $model, string $effort): string;

    /**
     * The results once the batch has ended, or null while it is still running.
     */
    public function poll(string $batchId): ?BatchOutcome;
}
