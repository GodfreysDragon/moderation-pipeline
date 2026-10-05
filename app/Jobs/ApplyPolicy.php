<?php

namespace App\Jobs;

use App\Enums\DecisionSource;
use App\Enums\Outcome;
use App\Enums\PipelineStage;
use App\Enums\StepStatus;
use App\Enums\SubmissionStatus;
use App\Enums\TaskKind;
use App\Jobs\Concerns\RecordsPipelineStep;
use App\Models\PolicyVersion;
use App\Models\Submission;
use App\Moderation\ModerationService;
use App\Moderation\PolicyEngine;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Lottery;

/**
 * Stage 4: apply the current policy version to the scores. Confident results are
 * decided automatically (with a random sample audited by a person); anything in
 * between, or anything the AI couldn't score, goes to the review queue.
 */
class ApplyPolicy implements ShouldQueue
{
    use Queueable, RecordsPipelineStep;

    public int $tries = 3;

    public function __construct(public Submission $submission)
    {
        $this->onQueue('moderation');
    }

    public function handle(PolicyEngine $engine, ModerationService $moderation): void
    {
        $submission = $this->submission->refresh();

        if ($submission->status !== SubmissionStatus::Processing) {
            $this->skipStep($submission, PipelineStage::ApplyPolicy, 'already_decided');

            return;
        }

        $step = $this->startStep($submission, PipelineStage::ApplyPolicy);
        $policy = PolicyVersion::current();
        $result = $engine->evaluate($submission->ai_scores === null ? null : $submission->scores(), $policy->thresholds);
        $audited = false;

        DB::transaction(function () use ($submission, $policy, $result, $moderation, &$audited) {
            $submission->update([
                'policy_outcome' => $result->outcome,
                'policy_version_id' => $policy->id,
            ]);

            if ($result->outcome === Outcome::Review) {
                $submission->update(['status' => SubmissionStatus::PendingReview, 'decision_reason' => $result->reason]);
                $moderation->openTask($submission, TaskKind::Triage, $result->priority, Outcome::Review);

                return;
            }

            $moderation->finalize($submission, $result->outcome, DecisionSource::Policy, $result->reason);

            $audited = $policy->audit_sample_rate > 0 && Lottery::odds($policy->audit_sample_rate)->choose();

            if ($audited) {
                $moderation->openTask($submission, TaskKind::Audit, $result->priority, $result->outcome);
            }
        });

        $this->finishStep($step, StepStatus::Succeeded, [
            'policy_version' => $policy->id,
            'outcome' => $result->outcome->value,
            'reason' => $result->reason,
            'priority' => $result->priority,
            'triggered' => $result->triggered,
            'audit_sampled' => $audited,
        ]);
    }
}
