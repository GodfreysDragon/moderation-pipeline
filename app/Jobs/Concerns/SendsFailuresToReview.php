<?php

namespace App\Jobs\Concerns;

use App\Enums\Outcome;
use App\Enums\PipelineStage;
use App\Enums\StepStatus;
use App\Enums\SubmissionStatus;
use App\Enums\TaskKind;
use App\Models\PipelineStep;
use App\Moderation\ModerationService;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * A stage that ran out of retries on an unexpected error. The chain stops there,
 * so the item goes to a person instead of staying "processing" forever.
 */
trait SendsFailuresToReview
{
    abstract protected function stage(): PipelineStage;

    public function failed(?Throwable $exception): void
    {
        $submission = $this->submission->refresh();
        $message = mb_substr($exception?->getMessage() ?? 'unknown error', 0, 200);

        PipelineStep::query()
            ->where('submission_id', $submission->id)
            ->where('stage', $this->stage())
            ->where('status', StepStatus::Running)
            ->update(['status' => StepStatus::Failed, 'error' => $message, 'finished_at' => now()]);

        if ($submission->status !== SubmissionStatus::Processing) {
            return;
        }

        DB::transaction(function () use ($submission, $message) {
            $submission->update(['status' => SubmissionStatus::PendingReview, 'decision_reason' => 'pipeline_error']);

            app(ModerationService::class)->openTask(
                $submission,
                TaskKind::Triage,
                50,
                Outcome::Review,
                note: "The {$this->stage()->label()} stage failed: {$message}",
            );
        });
    }
}
