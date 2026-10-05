<?php

namespace Database\Factories;

use App\Enums\Category;
use App\Enums\DecisionSource;
use App\Enums\Outcome;
use App\Enums\SubmissionStatus;
use App\Models\Submission;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Submission>
 */
class SubmissionFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'user_id' => User::factory(),
            'caption' => fake()->sentence(),
            'status' => SubmissionStatus::Processing,
        ];
    }

    /**
     * Give it AI scores. Unlisted categories get a low background score.
     *
     * @param  array<string, float>  $scores
     */
    public function scored(array $scores = []): static
    {
        return $this->state(function () use ($scores) {
            $categories = [];

            foreach (Category::cases() as $category) {
                $score = $scores[$category->value] ?? 0.02;
                $categories[$category->value] = ['score' => $score, 'evidence' => $score > 0.2 ? 'Test evidence.' : 'none'];
            }

            return [
                'ai_scores' => $categories,
                'ai_summary' => 'A test submission.',
                'ai_model' => 'fake-keyword-classifier',
            ];
        });
    }

    public function decided(Outcome $outcome, DecisionSource $source = DecisionSource::Policy, ?string $reason = null): static
    {
        return $this->state(fn () => [
            'status' => $outcome->status(),
            'policy_outcome' => $source->isAutomated() ? $outcome : Outcome::Review,
            'decision_source' => $source,
            'decision_reason' => $reason,
            'decided_at' => now(),
        ]);
    }

    public function pendingReview(): static
    {
        return $this->state(fn () => [
            'status' => SubmissionStatus::PendingReview,
            'policy_outcome' => Outcome::Review,
        ]);
    }

    public function withImage(?string $dhash = null): static
    {
        return $this->state(fn () => [
            'image_path' => 'submissions/test/image.png',
            'thumbnail_path' => 'submissions/test/thumb.png',
            'mime' => 'image/png',
            'width' => 640,
            'height' => 480,
            'sha256' => hash('sha256', fake()->uuid()),
            'dhash' => $dhash ?? bin2hex(random_bytes(8)),
        ]);
    }
}
