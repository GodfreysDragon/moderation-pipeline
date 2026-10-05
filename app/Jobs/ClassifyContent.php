<?php

namespace App\Jobs;

use Anthropic\Core\Exceptions\APIConnectionException;
use Anthropic\Core\Exceptions\InternalServerException;
use Anthropic\Core\Exceptions\RateLimitException;
use App\Ai\Contracts\ContentClassifier;
use App\Ai\Data\ClassificationInput;
use App\Ai\Exceptions\ClassifierRefusedException;
use App\Enums\PipelineStage;
use App\Enums\StepStatus;
use App\Jobs\Concerns\RecordsPipelineStep;
use App\Models\Submission;
use DateTimeInterface;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\Middleware\RateLimited;
use Illuminate\Support\Facades\Storage;
use Throwable;

/**
 * Stage 3: score the image and caption against every policy category.
 *
 * Runs on its own queue so slow or rate-limited AI calls never hold up the
 * cheap stages. A failure here never approves anything: the item goes to a person.
 */
class ClassifyContent implements ShouldQueue
{
    use Queueable, RecordsPipelineStep;

    /**
     * Give up on transient errors after this many exceptions (rate-limit
     * releases from the middleware don't count).
     */
    public int $maxExceptions = 3;

    public function __construct(public Submission $submission)
    {
        $this->onQueue('moderation-ai');
    }

    /**
     * @return list<object>
     */
    public function middleware(): array
    {
        return [new RateLimited('moderation-ai')];
    }

    public function retryUntil(): DateTimeInterface
    {
        return now()->addMinutes(30);
    }

    /**
     * @return list<int>
     */
    public function backoff(): array
    {
        return [15, 60, 180];
    }

    public function handle(ContentClassifier $classifier): void
    {
        $submission = $this->submission->refresh();

        if ($submission->status->isFinal()) {
            $this->skipStep($submission, PipelineStage::Classify, 'already_decided');

            return;
        }

        $step = $this->startStep($submission, PipelineStage::Classify);

        try {
            $classification = $classifier->classify(new ClassificationInput(
                caption: $submission->caption,
                imageBytes: $submission->image_path ? Storage::disk(config('moderation.disk'))->get($submission->image_path) : null,
                imageMime: $submission->mime,
                filename: $submission->original_filename,
            ));
        } catch (RateLimitException|InternalServerException|APIConnectionException $e) {
            // Transient: the SDK already retried; let the queue retry later with backoff.
            $this->finishStep($step, StepStatus::Failed, ['retrying' => true], $e->getMessage());

            throw $e;
        } catch (Throwable $e) {
            // Refusals, bad requests, auth errors and malformed output won't get
            // better on retry. Record it and let the policy stage route it to a person.
            $this->finishStep($step, StepStatus::Failed, [
                'retrying' => false,
                'refused' => $e instanceof ClassifierRefusedException,
            ], $e->getMessage());

            $submission->update(['ai_error' => mb_substr($e->getMessage(), 0, 250)]);

            return;
        }

        $submission->update([
            'ai_scores' => $classification->categories,
            'ai_summary' => $classification->summary,
            'ai_model' => $classification->model,
            'ai_error' => null,
        ]);

        $top = collect($classification->scores())->sortDesc();

        $this->finishStep($step, StepStatus::Succeeded, [
            'model' => $classification->model,
            'top_category' => $top->keys()->first(),
            'top_score' => $top->first(),
        ]);
    }

    /**
     * Out of retries. The chain stops here, so run the policy stage directly:
     * with no scores it sends the item to human review.
     */
    public function failed(?Throwable $exception): void
    {
        $this->submission->refresh()->update([
            'ai_error' => mb_substr('Gave up after retries: '.($exception?->getMessage() ?? 'unknown error'), 0, 250),
        ]);

        ApplyPolicy::dispatchSync($this->submission);
    }
}
