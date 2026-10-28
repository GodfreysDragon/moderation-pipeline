<?php

namespace App\Moderation;

use App\Enums\Category;
use App\Enums\Decision;
use App\Enums\DecisionSource;
use App\Enums\Outcome;
use App\Enums\PipelineStage;
use App\Enums\SubmissionStatus;
use App\Enums\TaskKind;
use App\Exceptions\ClaimLostException;
use App\Models\BlockedHash;
use App\Models\PipelineStep;
use App\Models\ReviewTask;
use App\Models\Submission;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use LogicException;

/**
 * Every state change to a submission's decision or its review tasks goes through here.
 */
class ModerationService
{
    /**
     * Record a final decision on a submission.
     */
    public function finalize(Submission $submission, Outcome $outcome, DecisionSource $source, ?string $reason = null, ?User $by = null): void
    {
        if ($outcome === Outcome::Review) {
            throw new LogicException('A final decision must approve or reject.');
        }

        $submission->update([
            'status' => $outcome->status(),
            'decision_source' => $source,
            'decision_reason' => $reason,
            'decided_by' => $by?->id,
            'decided_at' => now(),
        ]);
    }

    public function openTask(
        Submission $submission,
        TaskKind $kind,
        int $basePriority,
        ?Outcome $automatedOutcome = null,
        ?int $excludedReviewerId = null,
        ?string $note = null,
    ): ReviewTask {
        return $submission->tasks()->create([
            'kind' => $kind,
            'priority' => max(-100, min(200, $basePriority + $kind->priorityBoost())),
            'automated_outcome' => $automatedOutcome,
            'excluded_reviewer_id' => $excludedReviewerId,
            'note' => $note,
        ]);
    }

    /**
     * Kinds of work this user may take.
     *
     * @return list<TaskKind>
     */
    public function kindsFor(User $user): array
    {
        if (! $user->isStaff()) {
            return [];
        }

        return $user->isAdmin()
            ? TaskKind::cases()
            : [TaskKind::Triage, TaskKind::Appeal, TaskKind::Audit];
    }

    public function mayWork(User $user, ReviewTask $task): bool
    {
        return in_array($task->kind, $this->kindsFor($user), true)
            && $task->excluded_reviewer_id !== $user->id;
    }

    /**
     * Atomically take a task. Two moderators racing for the same task: exactly one wins.
     */
    public function claim(ReviewTask $task, User $user): bool
    {
        if (! $this->mayWork($user, $task)) {
            return false;
        }

        if ($task->isClaimedBy($user)) {
            return $this->extend($task, $user);
        }

        $won = ReviewTask::query()
            ->whereKey($task->id)
            ->available()
            ->update([
                'claimed_by' => $user->id,
                'claimed_at' => now(),
                'claim_expires_at' => now()->addMinutes(config('moderation.claim_minutes')),
            ]) === 1;

        $task->refresh();

        return $won;
    }

    /**
     * The user's current task if they hold one, otherwise the most urgent one they may take.
     */
    public function claimNext(User $user, ?TaskKind $kind = null): ?ReviewTask
    {
        $held = ReviewTask::query()->open()
            ->where('claimed_by', $user->id)
            ->where('claim_expires_at', '>', now())
            ->first();

        if ($held) {
            return $held;
        }

        $kinds = $this->kindsFor($user);

        if ($kind !== null) {
            $kinds = in_array($kind, $kinds, true) ? [$kind] : [];
        }

        if ($kinds === []) {
            return null;
        }

        // Retry a few times: a candidate may be taken between the read and the claim.
        for ($round = 0; $round < 3; $round++) {
            $candidates = ReviewTask::query()
                ->available()
                ->whereIn('kind', array_map(fn (TaskKind $k) => $k->value, $kinds))
                ->where(fn ($q) => $q->whereNull('excluded_reviewer_id')->orWhere('excluded_reviewer_id', '!=', $user->id))
                ->orderByDesc('priority')
                ->orderBy('created_at')
                ->orderBy('id')
                ->limit(5)
                ->get();

            if ($candidates->isEmpty()) {
                return null;
            }

            foreach ($candidates as $task) {
                if ($this->claim($task, $user)) {
                    return $task;
                }
            }
        }

        return null;
    }

    public function extend(ReviewTask $task, User $user): bool
    {
        $extended = ReviewTask::query()
            ->whereKey($task->id)
            ->open()
            ->where('claimed_by', $user->id)
            ->update(['claim_expires_at' => now()->addMinutes(config('moderation.claim_minutes'))]) === 1;

        $task->refresh();

        return $extended;
    }

    public function release(ReviewTask $task, User $user): void
    {
        ReviewTask::query()
            ->whereKey($task->id)
            ->open()
            ->where('claimed_by', $user->id)
            ->update(['claimed_by' => null, 'claimed_at' => null, 'claim_expires_at' => null]);

        $task->refresh();
    }

    /**
     * Resolve a task the user has claimed.
     */
    public function decide(
        ReviewTask $task,
        User $user,
        Decision $decision,
        ?string $reason,
        ?string $note = null,
        bool $blockImage = true,
    ): void {
        if (! $task->isClaimedBy($user)) {
            throw new LogicException('Claim the task before deciding it.');
        }

        if ($decision === Decision::Escalate && $task->kind === TaskKind::Escalation) {
            throw new LogicException('An escalation cannot be escalated again.');
        }

        DB::transaction(function () use ($task, $user, $decision, $reason, $note, $blockImage) {
            $submission = $task->submission()->lockForUpdate()->firstOrFail();

            // Resolve only if the claim is still live in the database: a double submit,
            // or a lease that lapsed and was taken by someone else, must not decide twice.
            $resolved = ReviewTask::query()
                ->whereKey($task->id)
                ->open()
                ->where('claimed_by', $user->id)
                ->where('claim_expires_at', '>', now())
                ->update([
                    'decision' => $decision,
                    'reason' => $reason,
                    'decision_note' => $note,
                    'resolved_by' => $user->id,
                    'resolved_at' => now(),
                    'claim_expires_at' => null,
                ]) === 1;

            $task->refresh();

            if (! $resolved) {
                throw new ClaimLostException('Your claim on this task expired or was taken.');
            }

            if ($decision === Decision::Escalate) {
                $this->openTask(
                    $submission,
                    TaskKind::Escalation,
                    $task->priority - $task->kind->priorityBoost(),
                    $task->automated_outcome,
                    note: $note,
                );

                return;
            }

            $outcome = $decision->outcome();

            match ($task->kind) {
                TaskKind::Triage, TaskKind::Escalation => $this->finalize($submission, $outcome, DecisionSource::Moderator, $reason, $user),
                TaskKind::Appeal => $this->finalize($submission, $outcome, DecisionSource::Appeal, $reason, $user),
                // An audit only changes the decision when the person disagrees with the automation.
                TaskKind::Audit => $outcome !== $task->automated_outcome
                    ? $this->finalize($submission, $outcome, DecisionSource::Audit, $reason, $user)
                    : null,
            };

            if ($outcome === Outcome::Reject && $blockImage) {
                $this->blockImage($submission, $user, Category::tryFrom((string) $reason));
            }

            if ($outcome === Outcome::Approve) {
                $this->unblockImage($submission);
            }
        });
    }

    /**
     * The uploader disputes a rejection. A different person reviews it.
     */
    public function appeal(Submission $submission, string $message): ReviewTask
    {
        if (! $submission->canBeAppealed()) {
            throw new LogicException('This submission cannot be appealed.');
        }

        return DB::transaction(function () use ($submission, $message) {
            $previousDecider = $submission->decided_by;

            $submission->update([
                'appeal_message' => $message,
                'appealed_at' => now(),
                'status' => SubmissionStatus::PendingReview,
            ]);

            $priority = (int) round(max([0, ...array_values($submission->scores())]) * 100);

            return $this->openTask($submission, TaskKind::Appeal, $priority, Outcome::Reject, $previousDecider);
        });
    }

    /**
     * A person cleared the image, so it must not keep matching future uploads: drop
     * hashes it created, and the blocklist entry that rejected it if it was a match.
     */
    private function unblockImage(Submission $submission): void
    {
        $matchedIds = $submission->steps()
            ->where('stage', PipelineStage::MatchKnown)
            ->get()
            ->map(fn (PipelineStep $step) => $step->detail['blocked_hash_id'] ?? null)
            ->filter()
            ->values()
            ->all();

        BlockedHash::query()
            ->where(fn ($q) => $q
                ->where('source_submission_id', $submission->id)
                ->orWhereIn('id', $matchedIds)
                ->when($submission->sha256 !== null, fn ($q) => $q->orWhere('sha256', $submission->sha256)))
            ->delete();
    }

    private function blockImage(Submission $submission, User $user, ?Category $category): void
    {
        if ($submission->sha256 === null || $submission->dhash === null) {
            return;
        }

        BlockedHash::query()->firstOrCreate(
            ['sha256' => $submission->sha256],
            [
                'dhash' => $submission->dhash,
                'category' => $category,
                'source_submission_id' => $submission->id,
                'added_by' => $user->id,
            ],
        );
    }
}
