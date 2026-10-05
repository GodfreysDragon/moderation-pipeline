<?php

namespace App\Moderation;

use App\Enums\Category;
use App\Enums\Outcome;

/**
 * Turns per-category AI scores into a decision. Pure and side-effect free, so the
 * same code makes live decisions and backtests proposed thresholds.
 *
 * For each category, with thresholds {review, reject}:
 *   score >= reject           -> reject (reject = null means "never automatically")
 *   score >= review           -> send to a person
 * The strictest category wins; if nothing trips, the item is approved.
 */
final class PolicyEngine
{
    /**
     * @param  array<string, float>|null  $scores  keyed by Category value; null if classification failed
     * @param  array<string, array{review: float, reject: float|null}>  $thresholds
     */
    public function evaluate(?array $scores, array $thresholds): PolicyResult
    {
        if ($scores === null) {
            // Fail safe: never auto-approve something nobody (human or model) has looked at.
            return new PolicyResult(Outcome::Review, null, [], 50, 'ai_unavailable');
        }

        $outcome = Outcome::Approve;
        $triggered = [];
        $primary = null;
        $primaryScore = -1.0;
        $priority = 0.0;

        foreach (Category::cases() as $category) {
            $score = max(0.0, min(1.0, $scores[$category->value] ?? 0.0));
            $limits = $thresholds[$category->value] ?? $category->defaultThresholds();

            $priority = max($priority, $score * $category->severity() / 3);

            $action = match (true) {
                $limits['reject'] !== null && $score >= $limits['reject'] => Outcome::Reject,
                $score >= $limits['review'] => Outcome::Review,
                default => null,
            };

            if ($action === null) {
                continue;
            }

            $triggered[] = ['category' => $category->value, 'score' => $score, 'action' => $action->value];

            if ($action === Outcome::Reject && $outcome !== Outcome::Reject) {
                $outcome = Outcome::Reject;
                $primary = $category;
                $primaryScore = $score;
            } elseif ($action === $outcome && $score > $primaryScore) {
                $primary = $category;
                $primaryScore = $score;
            } elseif ($action === Outcome::Review && $outcome === Outcome::Approve) {
                $outcome = Outcome::Review;
                $primary = $category;
                $primaryScore = $score;
            }
        }

        return new PolicyResult(
            outcome: $outcome,
            category: $primary,
            triggered: $triggered,
            priority: (int) round($priority * 100),
            reason: $primary?->value,
        );
    }
}
