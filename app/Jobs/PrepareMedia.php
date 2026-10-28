<?php

namespace App\Jobs;

use App\Enums\DecisionSource;
use App\Enums\Outcome;
use App\Enums\PipelineStage;
use App\Enums\StepStatus;
use App\Exceptions\UnprocessableMediaException;
use App\Jobs\Concerns\RecordsPipelineStep;
use App\Jobs\Concerns\SendsFailuresToReview;
use App\Models\Submission;
use App\Moderation\ModerationService;
use App\Support\MediaSanitizer;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Storage;
use Throwable;

/**
 * Stage 1: re-encode the upload (stripping metadata), make a thumbnail and fingerprint it.
 */
class PrepareMedia implements ShouldQueue
{
    use Queueable, RecordsPipelineStep, SendsFailuresToReview;

    public int $tries = 3;

    public function __construct(public Submission $submission)
    {
        $this->onQueue('moderation');
    }

    protected function stage(): PipelineStage
    {
        return PipelineStage::Prepare;
    }

    public function handle(MediaSanitizer $sanitizer, ModerationService $moderation): void
    {
        $submission = $this->submission;

        if ($submission->upload_path === null) {
            $this->skipStep($submission, PipelineStage::Prepare, $submission->hasImage() ? 'already_prepared' : 'text_only');

            return;
        }

        $step = $this->startStep($submission, PipelineStage::Prepare);
        $disk = Storage::disk(config('moderation.disk'));

        try {
            $image = $sanitizer->sanitize((string) $disk->get($submission->upload_path));
        } catch (UnprocessableMediaException $e) {
            $this->finishStep($step, StepStatus::Failed, error: $e->getMessage());
            $disk->delete($submission->upload_path);
            $submission->update(['upload_path' => null]);
            $moderation->finalize($submission, Outcome::Reject, DecisionSource::Policy, 'unreadable_media');

            return;
        } catch (Throwable $e) {
            $this->finishStep($step, StepStatus::Failed, error: $e->getMessage());

            throw $e;
        }

        $base = "submissions/{$submission->id}";
        $disk->put("{$base}/image.{$image->extension}", $image->bytes);
        $disk->put("{$base}/thumb.{$image->extension}", $image->thumbnailBytes);
        $disk->delete($submission->upload_path);

        $submission->update([
            'upload_path' => null,
            'image_path' => "{$base}/image.{$image->extension}",
            'thumbnail_path' => "{$base}/thumb.{$image->extension}",
            'mime' => $image->mime,
            'width' => $image->width,
            'height' => $image->height,
            'bytes' => strlen($image->bytes),
            'had_gps' => $image->hadGps,
            'sha256' => $image->sha256,
            'dhash' => $image->dhash,
        ]);

        $this->finishStep($step, StepStatus::Succeeded, [
            'width' => $image->width,
            'height' => $image->height,
            'bytes' => strlen($image->bytes),
            'metadata_stripped' => true,
            'had_gps' => $image->hadGps,
            'dhash' => $image->dhash,
        ]);
    }
}
