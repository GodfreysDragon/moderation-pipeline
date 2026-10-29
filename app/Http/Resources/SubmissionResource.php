<?php

namespace App\Http\Resources;

use App\Http\Presenters\SubmissionPresenter;
use App\Models\Submission;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * A submission as the API and webhooks show it to the uploading platform: where it
 * stands and why, never the model's scores or reviewers' notes.
 *
 * @mixin Submission
 */
class SubmissionResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(?Request $request = null): array
    {
        return [
            'id' => $this->id,
            'reference' => $this->external_ref,
            'status' => $this->status->value,
            'caption' => $this->caption,
            'has_image' => $this->image_path !== null || $this->upload_path !== null,
            'decision' => $this->status->isFinal() ? [
                'outcome' => $this->status->value,
                'source' => $this->decision_source?->value,
                'reason' => $this->decision_reason,
                'reason_label' => SubmissionPresenter::reasonLabel($this->decision_reason),
                'decided_at' => $this->decided_at?->toIso8601String(),
            ] : null,
            'appeal' => [
                'appealed_at' => $this->appealed_at?->toIso8601String(),
                'open_until' => $this->canBeAppealed()
                    ? $this->decided_at?->addDays(config('moderation.appeal_window_days'))->toIso8601String()
                    : null,
            ],
            'created_at' => $this->created_at->toIso8601String(),
            'links' => [
                'self' => route('api.submissions.show', $this->id),
                'web' => route('submissions.show', $this->id),
            ],
        ];
    }
}
