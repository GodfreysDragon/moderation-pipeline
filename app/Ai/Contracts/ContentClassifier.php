<?php

namespace App\Ai\Contracts;

use App\Ai\Data\Classification;
use App\Ai\Data\ClassificationInput;

interface ContentClassifier
{
    /**
     * Score an image and/or caption against every policy category.
     *
     * @throws \App\Ai\Exceptions\ClassifierRefusedException when the model declines and no fallback completes
     */
    public function classify(ClassificationInput $input): Classification;
}
