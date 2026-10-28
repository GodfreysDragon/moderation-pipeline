<?php

namespace App\Http\Presenters;

use App\Enums\Category;
use App\Models\PipelineStep;
use App\Models\PolicyVersion;
use App\Models\ReviewTask;
use App\Models\Submission;
use App\Models\User;

/**
 * Shapes submissions for the frontend. Uploaders get the decision and its reason;
 * staff also get the AI scores, the thresholds and the review history.
 */
final class SubmissionPresenter
{
    public static function reasonLabel(?string $reason): ?string
    {
        if ($reason === null) {
            return null;
        }

        return Category::tryFrom($reason)?->label() ?? match ($reason) {
            'known_content' => 'Matches previously removed content',
            'unreadable_media' => 'The image could not be processed',
            'ai_unavailable' => 'Needs a manual check',
            'pipeline_error' => 'Processing failed; needs a manual check',
            'other' => 'Other policy violation',
            default => str($reason)->replace('_', ' ')->ucfirst()->toString(),
        };
    }

    /**
     * @return array<string, mixed>
     */
    public static function summary(Submission $submission, User $viewer): array
    {
        $staff = $viewer->isStaff();
        $top = $staff ? $submission->topCategory() : null;

        return [
            'id' => $submission->id,
            'caption' => $submission->caption,
            'status' => $submission->status->value,
            'has_image' => $submission->hasImage() || $submission->upload_path !== null,
            'thumbnail_url' => $submission->thumbnail_path ? route('submissions.media', [$submission, 'thumb']) : null,
            'decision_source' => $submission->decision_source?->value,
            'decision_reason' => self::reasonLabel($submission->decision_reason),
            'created_at' => $submission->created_at->toIso8601String(),
            'decided_at' => $submission->decided_at?->toIso8601String(),
            'appealed' => $submission->appealed_at !== null,
            'uploader' => $staff ? $submission->user->name : null,
            'top_category' => $top ? ['value' => $top->value, 'label' => $top->label(), 'score' => $submission->scores()[$top->value]] : null,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public static function detail(Submission $submission, User $viewer): array
    {
        $staff = $viewer->isStaff();

        $data = [
            ...self::summary($submission, $viewer),
            'image_url' => $submission->image_path ? route('submissions.media', [$submission, 'image']) : null,
            'width' => $submission->width,
            'height' => $submission->height,
            'had_gps' => $submission->had_gps,
            'appeal_message' => $submission->appeal_message,
            'appealed_at' => $submission->appealed_at?->toIso8601String(),
            'can_appeal' => $viewer->can('appeal', $submission),
            'appeal_deadline' => $submission->decided_at?->addDays(config('moderation.appeal_window_days'))->toIso8601String(),
            'steps' => $submission->steps->map(fn (PipelineStep $step) => [
                'id' => $step->id,
                'stage' => $step->stage->value,
                'label' => $step->stage->label(),
                'status' => $step->status->value,
                'attempt' => $step->attempt,
                'duration_ms' => $step->duration_ms,
                'started_at' => $step->started_at->toIso8601String(),
                // Members see what happened, not the internals (hashes, scores, errors).
                'detail' => $staff ? $step->detail : null,
                'error' => $staff ? $step->error : null,
            ])->values()->all(),
        ];

        if (! $staff) {
            return $data;
        }

        $policy = $submission->policyVersion ?? PolicyVersion::current();

        return [
            ...$data,
            'ai' => $submission->ai_scores === null && $submission->ai_error === null ? null : [
                'model' => $submission->ai_model,
                'summary' => $submission->ai_summary,
                'error' => $submission->ai_error,
                'categories' => $submission->ai_scores === null ? [] : collect(Category::cases())->map(fn (Category $c) => [
                    'value' => $c->value,
                    'label' => $c->label(),
                    'score' => $submission->scores()[$c->value],
                    'evidence' => $submission->ai_scores[$c->value]['evidence'] ?? '',
                    ...$policy->thresholdsFor($c),
                ])->values()->all(),
            ],
            'policy_outcome' => $submission->policy_outcome?->value,
            'policy_version' => $submission->policy_version_id,
            'decided_by' => $submission->decider?->name,
            'sha256' => $submission->sha256,
            'dhash' => $submission->dhash,
            'uploader_history' => self::uploaderHistory($submission),
            'tasks' => $submission->tasks->map(fn (ReviewTask $task) => self::task($task))->values()->all(),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public static function task(ReviewTask $task): array
    {
        return [
            'id' => $task->id,
            'kind' => $task->kind->value,
            'kind_label' => $task->kind->label(),
            'priority' => $task->priority,
            'graphic' => $task->graphic,
            'automated_outcome' => $task->automated_outcome?->value,
            'note' => $task->note,
            'claimed_by' => $task->hasLiveClaim() ? $task->claimant?->name : null,
            'claim_expires_at' => $task->hasLiveClaim() ? $task->claim_expires_at?->toIso8601String() : null,
            'decision' => $task->decision?->value,
            'reason' => self::reasonLabel($task->reason),
            'decision_note' => $task->decision_note,
            'resolved_by' => $task->resolver?->name,
            'resolved_at' => $task->resolved_at?->toIso8601String(),
            'created_at' => $task->created_at->toIso8601String(),
        ];
    }

    /**
     * @return array{total: int, rejected: int}
     */
    private static function uploaderHistory(Submission $submission): array
    {
        $others = Submission::query()->where('user_id', $submission->user_id)->whereKeyNot($submission->id);

        return [
            'total' => (clone $others)->count(),
            'rejected' => (clone $others)->where('status', 'rejected')->count(),
        ];
    }
}
