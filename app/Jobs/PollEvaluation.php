<?php

namespace App\Jobs;

use App\Ai\Contracts\BatchClassifier;
use App\Enums\EvaluationStatus;
use App\Models\EvaluationRun;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Throwable;

/**
 * Checks a running batch and stores its results once it has ended; until then
 * it schedules itself again. Batches expire after 24 hours.
 */
class PollEvaluation implements ShouldQueue
{
    use Queueable;

    public int $tries = 4;

    /**
     * @var list<int>
     */
    public array $backoff = [30, 120, 300];

    public function __construct(public EvaluationRun $run) {}

    public function handle(BatchClassifier $classifier): void
    {
        $run = $this->run->refresh();

        if ($run->status !== EvaluationStatus::Running || $run->batch_id === null) {
            return;
        }

        $outcome = $classifier->poll($run->batch_id);

        if ($outcome === null) {
            if ($run->submitted_at?->lt(now()->subHours(25))) {
                $run->fail('The batch did not finish within 24 hours.');

                return;
            }

            self::dispatch($run)->delay(now()->addSeconds(config('moderation.evaluation_poll_seconds')));

            return;
        }

        foreach ($run->results as $result) {
            $key = "submission-{$result->submission_id}";
            $classification = $outcome->classifications[$key] ?? null;

            $result->update($classification === null
                ? ['error' => $outcome->errors[$key] ?? 'The batch returned no result for this item.']
                : [
                    'scores' => $classification->categories,
                    'summary' => $classification->summary,
                    'input_tokens' => $classification->usage?->input,
                    'cache_read_tokens' => $classification->usage?->cacheRead,
                    'cache_write_tokens' => $classification->usage?->cacheWrite,
                    'output_tokens' => $classification->usage?->output,
                ]);
        }

        $run->update(['status' => EvaluationStatus::Completed, 'completed_at' => now()]);
    }

    public function failed(?Throwable $exception): void
    {
        $this->run->fail('Could not read the batch: '.($exception ? ClassifyContent::describe($exception) : 'unknown error'));
    }
}
