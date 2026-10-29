<?php

namespace App\Observers;

use App\Events\SubmissionChanged;
use App\Models\PipelineStep;

class PipelineStepObserver
{
    /**
     * A stage started (created) or finished (updated).
     */
    public function saved(PipelineStep $step): void
    {
        event(SubmissionChanged::for($step->submission));
    }
}
