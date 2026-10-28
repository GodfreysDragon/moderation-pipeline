<?php

namespace App\Jobs;

use App\Ai\Contracts\BatchClassifier;
use App\Ai\Data\ClassificationInput;
use App\Enums\DecisionSource;
use App\Enums\EvaluationStatus;
use App\Enums\SubmissionStatus;
use App\Models\EvaluationRun;
use App\Models\Submission;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Storage;
use Throwable;

/**
 * Sends the most recent human-decided items to the batch classifier. People's
 * decisions are the ground truth both the current and the candidate scores are judged against.
 */
class StartEvaluation implements ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    public function __construct(public EvaluationRun $run) {}

    public function handle(BatchClassifier $classifier): void
    {
        $run = $this->run->refresh();

        if ($run->status !== EvaluationStatus::Pending) {
            return;
        }

        $sample = Submission::query()
            ->whereIn('status', [SubmissionStatus::Approved, SubmissionStatus::Rejected])
            ->whereNotIn('decision_source', [DecisionSource::Policy, DecisionSource::Blocklist])
            ->whereNotNull('decision_source')
            ->whereNotNull('ai_scores')
            ->latest('decided_at')
            ->latest('id')
            ->limit($run->sample_size)
            ->get();

        if ($sample->isEmpty()) {
            $run->fail('There are no human-decided items to compare against yet.');

            return;
        }

        $disk = Storage::disk(config('moderation.disk'));
        $inputs = [];

        foreach ($sample as $submission) {
            $image = $submission->image_path !== null && $disk->exists($submission->image_path)
                ? $disk->get($submission->image_path)
                : null;

            $inputs["submission-{$submission->id}"] = new ClassificationInput(
                caption: $submission->caption,
                imageBytes: $image,
                imageMime: $image !== null ? $submission->mime : null,
                filename: $submission->original_filename,
            );
        }

        $batchId = $classifier->submit($inputs, $run->model, $run->effort);

        $run->results()->createMany($sample->map(fn (Submission $s) => ['submission_id' => $s->id])->all());
        $run->update(['status' => EvaluationStatus::Running, 'batch_id' => $batchId, 'submitted_at' => now()]);

        PollEvaluation::dispatch($run)->delay(now()->addSeconds(config('moderation.evaluation_poll_seconds')));
    }

    public function failed(?Throwable $exception): void
    {
        $this->run->fail('Could not start the batch: '.($exception ? ClassifyContent::describe($exception) : 'unknown error'));
    }
}
