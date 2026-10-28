<?php

namespace App\Moderation;

use App\Enums\Decision;
use App\Enums\Outcome;
use App\Enums\TaskKind;
use App\Models\ReviewTask;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;

/**
 * How each reviewer's decisions hold up, and how often two reviewers agree.
 */
class ReviewerStats
{
    public function __construct(private readonly ModerationService $moderation) {}

    /**
     * @return array{
     *     days: int,
     *     agreement: array{pairs: int, agreed: int, rate: float|null, kappa: float|null},
     *     reviewers: list<array{id: int, name: string, role: string, decided: int, approved: int, rejected: int, escalated: int, handling_seconds_p50: int|null, appealed: int, overturned: int, second_reviewed: int, second_agreed: int, graphic_today: int}>
     * }
     */
    public function summary(int $days = 30): array
    {
        $since = CarbonImmutable::now()->subDays($days);

        $resolved = ReviewTask::query()
            ->whereNotNull('resolved_at')
            ->where('resolved_at', '>=', $since)
            ->with('reviewedTask:id,decision,resolved_by')
            ->get(['id', 'kind', 'decision', 'claimed_at', 'resolved_at', 'resolved_by', 'excluded_reviewer_id', 'reviews_task_id']);

        $secondReviews = $resolved->filter(fn (ReviewTask $t) => $t->kind === TaskKind::SecondReview && $t->reviewedTask?->decision !== null);

        $reviewers = array_values(User::query()
            ->whereIn('role', ['moderator', 'admin'])
            ->orderBy('name')
            ->get()
            ->map(function (User $user) use ($resolved, $secondReviews) {
                $mine = $resolved->where('resolved_by', $user->id);
                // Appeals of a decision this user made: they are the excluded reviewer on the appeal.
                $appeals = $resolved->filter(fn (ReviewTask $t) => $t->kind === TaskKind::Appeal && $t->excluded_reviewer_id === $user->id);
                $checked = $secondReviews->filter(fn (ReviewTask $t) => $t->reviewedTask?->resolved_by === $user->id);

                return [
                    'id' => $user->id,
                    'name' => $user->name,
                    'role' => $user->role->value,
                    'decided' => $mine->count(),
                    'approved' => $mine->where('decision', Decision::Approve)->count(),
                    'rejected' => $mine->where('decision', Decision::Reject)->count(),
                    'escalated' => $mine->where('decision', Decision::Escalate)->count(),
                    'handling_seconds_p50' => $this->median($mine),
                    'appealed' => $appeals->count(),
                    'overturned' => $appeals->where('decision', Decision::Approve)->count(),
                    'second_reviewed' => $checked->count(),
                    'second_agreed' => $checked->filter(fn (ReviewTask $t) => $t->decision === $t->reviewedTask?->decision)->count(),
                    'graphic_today' => $this->moderation->graphicExposure($user),
                ];
            })
            ->all());

        return [
            'days' => $days,
            'agreement' => $this->agreement($secondReviews),
            'reviewers' => $reviewers,
        ];
    }

    /**
     * Percent agreement and Cohen's kappa over approve/reject pairs. Kappa corrects for
     * the agreement two reviewers would reach by chance given how often each approves.
     *
     * @param  Collection<int, ReviewTask>  $secondReviews
     * @return array{pairs: int, agreed: int, rate: float|null, kappa: float|null}
     */
    private function agreement(Collection $secondReviews): array
    {
        $pairs = $secondReviews
            ->map(fn (ReviewTask $t) => [$t->reviewedTask?->decision?->outcome(), $t->decision?->outcome()])
            ->filter(fn (array $pair) => in_array($pair[0], [Outcome::Approve, Outcome::Reject], true)
                && in_array($pair[1], [Outcome::Approve, Outcome::Reject], true));

        $n = $pairs->count();

        if ($n === 0) {
            return ['pairs' => 0, 'agreed' => 0, 'rate' => null, 'kappa' => null];
        }

        $agreed = $pairs->filter(fn (array $pair) => $pair[0] === $pair[1])->count();
        $firstApproves = $pairs->filter(fn (array $pair) => $pair[0] === Outcome::Approve)->count() / $n;
        $secondApproves = $pairs->filter(fn (array $pair) => $pair[1] === Outcome::Approve)->count() / $n;

        $observed = $agreed / $n;
        $chance = $firstApproves * $secondApproves + (1 - $firstApproves) * (1 - $secondApproves);

        return [
            'pairs' => $n,
            'agreed' => $agreed,
            'rate' => round($observed, 4),
            // With no variation at all (both always approve), kappa is undefined.
            'kappa' => $chance < 1 ? round(($observed - $chance) / (1 - $chance), 3) : null,
        ];
    }

    /**
     * @param  Collection<int, ReviewTask>  $tasks
     */
    private function median(Collection $tasks): ?int
    {
        $seconds = $tasks
            ->filter(fn (ReviewTask $t) => $t->claimed_at !== null && $t->resolved_at !== null)
            ->map(fn (ReviewTask $t) => (int) $t->claimed_at->diffInSeconds($t->resolved_at))
            ->sort()
            ->values();

        return $seconds->isEmpty() ? null : $seconds[intdiv($seconds->count() - 1, 2)];
    }
}
