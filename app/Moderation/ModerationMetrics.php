<?php

namespace App\Moderation;

use App\Enums\Category;
use App\Enums\Decision;
use App\Enums\DecisionSource;
use App\Enums\Outcome;
use App\Enums\PipelineStage;
use App\Enums\StepStatus;
use App\Enums\SubmissionStatus;
use App\Enums\TaskKind;
use App\Models\PipelineStep;
use App\Models\ReviewTask;
use App\Models\Submission;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;

/**
 * Numbers for the staff dashboard. Percentiles are computed in PHP so the
 * queries stay portable (SQLite has no percentile functions).
 */
class ModerationMetrics
{
    /**
     * @return array<string, mixed>
     */
    public function summary(int $days = 14): array
    {
        $since = CarbonImmutable::now()->startOfDay()->subDays($days - 1);

        $submissions = Submission::query()
            ->where('created_at', '>=', $since)
            ->get(['id', 'status', 'decision_source', 'decision_reason', 'created_at', 'decided_at']);

        $decided = $submissions->filter(fn (Submission $s) => $s->status->isFinal());
        $automated = $decided->filter(fn (Submission $s) => $s->decision_source?->isAutomated() === true);

        $tasks = ReviewTask::query()
            ->where('created_at', '>=', $since)
            ->get(['id', 'kind', 'automated_outcome', 'decision', 'claimed_at', 'resolved_at', 'created_at']);

        return [
            'days' => $days,
            'totals' => [
                'submitted' => $submissions->count(),
                'approved' => $submissions->where('status', SubmissionStatus::Approved)->count(),
                'rejected' => $submissions->where('status', SubmissionStatus::Rejected)->count(),
                'pending_review' => $submissions->where('status', SubmissionStatus::PendingReview)->count(),
                'processing' => $submissions->where('status', SubmissionStatus::Processing)->count(),
            ],
            'automation_rate' => $this->ratio($automated->count(), $decided->count()),
            'blocklist_hits' => $submissions->where('decision_source', DecisionSource::Blocklist)->count(),
            'queue' => $this->queue(),
            'timing' => [
                'automated_minutes_p50' => $this->percentile($this->minutesToDecision($automated)->all(), 50),
                'human_minutes_p50' => $this->percentile($this->minutesToDecision($decided->diff($automated))->all(), 50),
                'handling_seconds_p50' => $this->percentile($this->handlingSeconds($tasks)->all(), 50),
            ],
            'audit' => $this->audit($tasks),
            'appeals' => $this->appeals($tasks),
            'daily' => $this->daily($submissions, $since, $days),
            'categories' => $this->categories($decided),
            'stages' => $this->stages($since),
        ];
    }

    /**
     * @return array{open: array<string, int>, total: int, oldest_minutes: int|null, claimed: int}
     */
    private function queue(): array
    {
        $open = ReviewTask::query()->open()->get(['kind', 'created_at', 'claimed_by', 'claim_expires_at']);
        $byKind = [];

        foreach (TaskKind::cases() as $kind) {
            $byKind[$kind->value] = $open->where('kind', $kind)->count();
        }

        $oldest = $open->min('created_at');

        return [
            'open' => $byKind,
            'total' => $open->count(),
            'oldest_minutes' => $oldest ? (int) $oldest->diffInMinutes(now()) : null,
            'claimed' => $open->filter(fn (ReviewTask $t) => $t->hasLiveClaim())->count(),
        ];
    }

    /**
     * How often a person agreed with the automated decision on sampled items: the
     * precision of auto-approve and auto-reject.
     *
     * @param  Collection<int, ReviewTask>  $tasks
     * @return array<string, array{checked: int, agreed: int, precision: float|null}>
     */
    private function audit(Collection $tasks): array
    {
        $audits = $tasks->filter(fn (ReviewTask $t) => $t->kind === TaskKind::Audit && $t->agreedWithAutomation() !== null);
        $result = [];

        foreach ([Outcome::Approve, Outcome::Reject] as $outcome) {
            $checked = $audits->where('automated_outcome', $outcome);
            $agreed = $checked->filter(fn (ReviewTask $t) => $t->agreedWithAutomation() === true)->count();
            $result[$outcome->value] = ['checked' => $checked->count(), 'agreed' => $agreed, 'precision' => $this->ratio($agreed, $checked->count())];
        }

        return $result;
    }

    /**
     * @param  Collection<int, ReviewTask>  $tasks
     * @return array{resolved: int, overturned: int, overturn_rate: float|null, open: int}
     */
    private function appeals(Collection $tasks): array
    {
        $appeals = $tasks->where('kind', TaskKind::Appeal);
        $resolved = $appeals->filter(fn (ReviewTask $t) => $t->decision !== null && $t->decision !== Decision::Escalate);
        $overturned = $resolved->where('decision', Decision::Approve)->count();

        return [
            'resolved' => $resolved->count(),
            'overturned' => $overturned,
            'overturn_rate' => $this->ratio($overturned, $resolved->count()),
            'open' => $appeals->whereNull('resolved_at')->count(),
        ];
    }

    /**
     * Submissions per day, split by how they ended up.
     *
     * @param  Collection<int, Submission>  $submissions
     * @return list<array<string, int|string>>
     */
    private function daily(Collection $submissions, CarbonImmutable $since, int $days): array
    {
        $series = [];

        for ($i = 0; $i < $days; $i++) {
            $series[$since->addDays($i)->toDateString()] = [
                'date' => $since->addDays($i)->toDateString(),
                'auto_approved' => 0, 'auto_rejected' => 0, 'human_approved' => 0, 'human_rejected' => 0, 'open' => 0,
            ];
        }

        foreach ($submissions as $submission) {
            $day = $submission->created_at->toDateString();

            if (! isset($series[$day])) {
                continue;
            }

            $path = $submission->decision_source?->isAutomated() ? 'auto' : 'human';

            $key = match ($submission->status) {
                SubmissionStatus::Approved => "{$path}_approved",
                SubmissionStatus::Rejected => "{$path}_rejected",
                default => 'open',
            };

            $series[$day][$key]++;
        }

        return array_values($series);
    }

    /**
     * Rejections by policy category, automated vs human.
     *
     * @param  Collection<int, Submission>  $decided
     * @return list<array{category: string, label: string, auto: int, human: int}>
     */
    private function categories(Collection $decided): array
    {
        $rejected = $decided->where('status', SubmissionStatus::Rejected);

        $rows = collect(Category::cases())
            ->map(fn (Category $c) => [
                'category' => $c->value,
                'label' => $c->label(),
                'auto' => $rejected->filter(fn (Submission $s) => $s->decision_reason === $c->value && $s->decision_source?->isAutomated())->count(),
                'human' => $rejected->filter(fn (Submission $s) => $s->decision_reason === $c->value && ! $s->decision_source?->isAutomated())->count(),
            ])
            ->sortByDesc(fn (array $row) => $row['auto'] + $row['human'])
            ->all();

        return array_values($rows);
    }

    /**
     * @return list<array{stage: string, label: string, runs: int, failed: int, skipped: int, p50_ms: int|null, p95_ms: int|null}>
     */
    private function stages(CarbonImmutable $since): array
    {
        $steps = PipelineStep::query()
            ->where('started_at', '>=', $since)
            ->get(['stage', 'status', 'duration_ms']);

        return array_map(function (PipelineStage $stage) use ($steps) {
            $runs = $steps->where('stage', $stage);
            $durations = $runs->where('status', StepStatus::Succeeded)->pluck('duration_ms')->filter(fn ($ms) => $ms !== null);

            return [
                'stage' => $stage->value,
                'label' => $stage->label(),
                'runs' => $runs->count(),
                'failed' => $runs->where('status', StepStatus::Failed)->count(),
                'skipped' => $runs->where('status', StepStatus::Skipped)->count(),
                'p50_ms' => $this->percentile($durations->all(), 50),
                'p95_ms' => $this->percentile($durations->all(), 95),
            ];
        }, PipelineStage::cases());
    }

    /**
     * @param  Collection<int, Submission>  $submissions
     * @return Collection<int, float>
     */
    private function minutesToDecision(Collection $submissions): Collection
    {
        return $submissions
            ->filter(fn (Submission $s) => $s->decided_at !== null)
            ->map(fn (Submission $s) => $s->created_at->diffInSeconds($s->decided_at) / 60)
            ->values();
    }

    /**
     * @param  Collection<int, ReviewTask>  $tasks
     * @return Collection<int, float>
     */
    private function handlingSeconds(Collection $tasks): Collection
    {
        return $tasks
            ->filter(fn (ReviewTask $t) => $t->claimed_at !== null && $t->resolved_at !== null)
            ->map(fn (ReviewTask $t) => $t->claimed_at->diffInSeconds($t->resolved_at))
            ->values();
    }

    /**
     * Nearest-rank percentile.
     *
     * @param  array<int|float>  $values
     */
    private function percentile(array $values, int $percentile): ?int
    {
        if ($values === []) {
            return null;
        }

        sort($values);
        $index = max(0, (int) ceil($percentile / 100 * count($values)) - 1);

        return (int) round($values[$index]);
    }

    private function ratio(int $part, int $whole): ?float
    {
        return $whole === 0 ? null : round($part / $whole, 4);
    }
}
