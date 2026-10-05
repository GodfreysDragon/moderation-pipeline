<?php

namespace App\Jobs\Concerns;

use App\Enums\PipelineStage;
use App\Enums\StepStatus;
use App\Models\PipelineStep;
use App\Models\Submission;

/**
 * Writes one row per stage attempt, so each submission has a timeline of what
 * ran, how long it took, what it found, and what failed.
 */
trait RecordsPipelineStep
{
    private ?float $stepStartedAt = null;

    protected function startStep(Submission $submission, PipelineStage $stage): PipelineStep
    {
        $this->stepStartedAt = microtime(true);

        return PipelineStep::create([
            'submission_id' => $submission->id,
            'stage' => $stage,
            'status' => StepStatus::Running,
            'attempt' => method_exists($this, 'attempts') ? max(1, $this->attempts()) : 1,
            'started_at' => now(),
        ]);
    }

    /**
     * @param  array<string, mixed>  $detail
     */
    protected function finishStep(PipelineStep $step, StepStatus $status, array $detail = [], ?string $error = null): void
    {
        $step->update([
            'status' => $status,
            'detail' => $detail ?: null,
            'error' => $error === null ? null : mb_substr($error, 0, 250),
            'finished_at' => now(),
            'duration_ms' => $this->stepStartedAt === null ? null : (int) round((microtime(true) - $this->stepStartedAt) * 1000),
        ]);
    }

    /**
     * Record a stage that had nothing to do (text-only post, or already decided upstream).
     */
    protected function skipStep(Submission $submission, PipelineStage $stage, string $reason): void
    {
        $this->finishStep($this->startStep($submission, $stage), StepStatus::Skipped, ['reason' => $reason]);
    }
}
