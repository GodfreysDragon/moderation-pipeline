<?php

namespace App\Jobs;

use App\Enums\DecisionSource;
use App\Enums\Outcome;
use App\Enums\PipelineStage;
use App\Enums\StepStatus;
use App\Jobs\Concerns\RecordsPipelineStep;
use App\Jobs\Concerns\SendsFailuresToReview;
use App\Models\BlockedHash;
use App\Models\PolicyVersion;
use App\Models\Submission;
use App\Moderation\ModerationService;
use App\Support\PerceptualHash;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/**
 * Stage 2: reject re-uploads of images a person already rejected, exactly or as
 * a near-duplicate, without spending an AI call on them.
 */
class MatchKnownContent implements ShouldQueue
{
    use Queueable, RecordsPipelineStep, SendsFailuresToReview;

    public int $tries = 3;

    public function __construct(public Submission $submission)
    {
        $this->onQueue('moderation');
    }

    protected function stage(): PipelineStage
    {
        return PipelineStage::MatchKnown;
    }

    public function handle(ModerationService $moderation): void
    {
        $submission = $this->submission->refresh();

        if ($submission->status->isFinal()) {
            $this->skipStep($submission, PipelineStage::MatchKnown, 'already_decided');

            return;
        }

        if ($submission->sha256 === null || $submission->dhash === null) {
            $this->skipStep($submission, PipelineStage::MatchKnown, 'text_only');

            return;
        }

        $step = $this->startStep($submission, PipelineStage::MatchKnown);
        $maxDistance = PolicyVersion::current()->blocklist_distance;

        $match = BlockedHash::query()->where('sha256', $submission->sha256)->first();
        $exact = $match !== null;
        $distance = $exact ? 0 : null;
        $checked = 0;

        if ($match === null) {
            // Linear scan is fine for thousands of hashes; a BK-tree or multi-index
            // hashing would be the next step for millions.
            $hashes = BlockedHash::query()->select(['id', 'dhash', 'category', 'source_submission_id'])->lazyById(1000);

            foreach ($hashes as $hash) {
                $checked++;
                $d = PerceptualHash::distance($submission->dhash, $hash->dhash);

                if ($distance === null || $d < $distance) {
                    $distance = $d;
                    $match = $hash;
                }
            }
        }

        $isMatch = $match !== null && $distance !== null && $distance <= $maxDistance;

        $this->finishStep($step, StepStatus::Succeeded, [
            'match' => $isMatch ? ($exact ? 'exact' : 'near_duplicate') : 'none',
            'closest_distance' => $distance,
            'max_distance' => $maxDistance,
            'blocked_hash_id' => $isMatch ? $match->id : null,
            'source_submission_id' => $isMatch ? $match->source_submission_id : null,
            'hashes_checked' => $checked ?: (int) ($match !== null),
        ]);

        if ($isMatch) {
            $moderation->finalize($submission, Outcome::Reject, DecisionSource::Blocklist, $match->category->value ?? 'known_content');
        }
    }
}
