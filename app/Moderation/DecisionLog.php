<?php

namespace App\Moderation;

use App\Enums\DecisionSource;
use App\Models\ReviewTask;
use App\Models\Submission;
use Carbon\CarbonImmutable;

/**
 * Every decision about submissions in a period, automated and human, oldest first:
 * the record an auditor or a regulator asks for.
 */
class DecisionLog
{
    public const COLUMNS = ['occurred_at', 'submission_id', 'task_id', 'actor', 'action', 'reason', 'note', 'policy_version', 'model'];

    /**
     * @return list<array<string, string|int|null>>
     */
    public function rows(CarbonImmutable $since): array
    {
        $rows = [];

        Submission::query()
            ->where(fn ($q) => $q
                ->where(fn ($q) => $q->whereIn('decision_source', [DecisionSource::Policy, DecisionSource::Blocklist])->where('decided_at', '>=', $since))
                ->orWhere('appealed_at', '>=', $since)
                ->orWhere('media_pruned_at', '>=', $since))
            ->get(['id', 'status', 'decision_source', 'decision_reason', 'decided_at', 'appealed_at', 'media_pruned_at', 'policy_version_id', 'ai_model'])
            ->each(function (Submission $s) use ($since, &$rows) {
                if ($s->decision_source?->isAutomated() && $s->decided_at?->gte($since)) {
                    $rows[] = $this->row($s->decided_at, $s->id, null, 'system', match ($s->decision_source) {
                        DecisionSource::Blocklist => 'blocklist_reject',
                        default => 'auto_'.($s->status->value === 'approved' ? 'approve' : 'reject'),
                    }, $s->decision_reason, null, $s->policy_version_id, $s->ai_model);
                }

                if ($s->appealed_at?->gte($since)) {
                    $rows[] = $this->row($s->appealed_at, $s->id, null, 'uploader', 'appeal_filed');
                }

                if ($s->media_pruned_at?->gte($since)) {
                    $rows[] = $this->row($s->media_pruned_at, $s->id, null, 'system', 'media_deleted');
                }
            });

        ReviewTask::query()
            ->with('resolver:id,name')
            ->where('resolved_at', '>=', $since)
            ->get(['id', 'submission_id', 'kind', 'decision', 'reason', 'decision_note', 'resolved_by', 'resolved_at'])
            ->each(function (ReviewTask $t) use (&$rows) {
                $rows[] = $this->row($t->resolved_at, $t->submission_id, $t->id, $t->resolver->name ?? 'unknown', "{$t->kind->value}_{$t->decision?->value}", $t->reason, $t->decision_note);
            });

        usort($rows, fn (array $a, array $b) => [$a['occurred_at'], $a['submission_id']] <=> [$b['occurred_at'], $b['submission_id']]);

        return $rows;
    }

    /**
     * @return array<string, string|int|null>
     */
    private function row(\DateTimeInterface $at, int $submissionId, ?int $taskId, string $actor, string $action, ?string $reason = null, ?string $note = null, ?int $policyVersion = null, ?string $model = null): array
    {
        return array_combine(self::COLUMNS, [
            $at->format(DATE_ATOM), $submissionId, $taskId, $actor, $action, $reason, $note, $policyVersion, $model,
        ]);
    }
}
