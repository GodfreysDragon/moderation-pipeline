<?php

namespace Database\Factories;

use App\Enums\Outcome;
use App\Enums\TaskKind;
use App\Models\ReviewTask;
use App\Models\Submission;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<ReviewTask>
 */
class ReviewTaskFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'submission_id' => Submission::factory()->scored(['violence' => 0.6])->pendingReview(),
            'kind' => TaskKind::Triage,
            'priority' => 40,
            'automated_outcome' => Outcome::Review,
        ];
    }

    public function kind(TaskKind $kind, ?Outcome $automated = null): static
    {
        return $this->state(fn () => ['kind' => $kind, 'automated_outcome' => $automated ?? Outcome::Review]);
    }

    public function claimedBy(User $user, int $minutes = 10): static
    {
        return $this->state(fn () => [
            'claimed_by' => $user->id,
            'claimed_at' => now(),
            'claim_expires_at' => now()->addMinutes($minutes),
        ]);
    }
}
