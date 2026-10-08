<?php

namespace App\Ai\Contracts;

use App\Ai\Data\Classification;
use App\Ai\Data\ClassificationInput;
use App\Ai\Exceptions\ClassifierRefusedException;

interface ContentClassifier
{
    /**
     * Score an image and/or caption against every policy category.
     *
     * @throws ClassifierRefusedException when the model declines and no fallback completes
     */
    public function classify(ClassificationInput $input): Classification;
}
