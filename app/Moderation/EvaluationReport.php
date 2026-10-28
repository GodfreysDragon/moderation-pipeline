<?php

namespace App\Moderation;

use App\Ai\Pricing;
use App\Enums\Category;
use App\Enums\Outcome;
use App\Enums\SubmissionStatus;
use App\Models\EvaluationResult;
use App\Models\EvaluationRun;
use App\Models\PolicyVersion;

/**
 * Compares an evaluation run's scores with the scores the pipeline recorded,
 * judging both under the same policy against what people decided.
 */
class EvaluationReport
{
    public function __construct(private readonly PolicyEngine $engine) {}

    /**
     * @return array{
     *     scored: int,
     *     errored: int,
     *     current: array{model: string|null, outcomes: array<string, int>, correct: int, missed: int, wrongful: int, accuracy: float|null, cost_per_item: float|null},
     *     candidate: array{model: string|null, outcomes: array<string, int>, correct: int, missed: int, wrongful: int, accuracy: float|null, cost_per_item: float|null},
     *     changed: int,
     *     drift: list<array{category: string, label: string, mean_change: float}>,
     *     examples: list<array{id: int, human: string, current: string, candidate: string, caption: string|null}>
     * }
     */
    public function build(EvaluationRun $run): array
    {
        $thresholds = ($run->policyVersion ?? PolicyVersion::current())->thresholds;
        $results = $run->results()->with('submission')->get();
        $scored = $results->filter(fn (EvaluationResult $r) => $r->scores !== null);

        $current = $this->side($scored->first()?->submission->ai_model);
        $candidate = $this->side($run->model);
        $changed = 0;
        $drift = array_fill_keys(array_map(fn (Category $c) => $c->value, Category::cases()), 0.0);
        $examples = [];
        $currentCost = $candidateCost = 0.0;
        $currentPriced = $candidatePriced = 0;

        foreach ($scored as $result) {
            $submission = $result->submission;
            $human = $submission->status === SubmissionStatus::Approved ? Outcome::Approve : Outcome::Reject;
            $before = $this->engine->evaluate($submission->scores(), $thresholds)->outcome;
            $after = $this->engine->evaluate($result->scoreValues(), $thresholds)->outcome;

            $this->tally($current, $before, $human);
            $this->tally($candidate, $after, $human);

            foreach ($drift as $category => $sum) {
                $drift[$category] = $sum + ($result->scoreValues()[$category] ?? 0.0) - ($submission->scores()[$category] ?? 0.0);
            }

            if ($before !== $after) {
                $changed++;

                if (count($examples) < 10) {
                    $examples[] = [
                        'id' => $submission->id,
                        'human' => $human->value,
                        'current' => $before->value,
                        'candidate' => $after->value,
                        'caption' => $submission->caption,
                    ];
                }
            }

            if (($usage = $submission->tokenUsage()) && ($cost = Pricing::cost($submission->ai_model, $usage)) !== null) {
                $currentCost += $cost;
                $currentPriced++;
            }

            if (($usage = $result->tokenUsage()) && ($cost = Pricing::cost($run->model, $usage, batch: true)) !== null) {
                $candidateCost += $cost;
                $candidatePriced++;
            }
        }

        return [
            'scored' => $scored->count(),
            'errored' => $results->count() - $scored->count(),
            'current' => $this->finish($current, $currentPriced > 0 ? $currentCost / $currentPriced : null),
            'candidate' => $this->finish($candidate, $candidatePriced > 0 ? $candidateCost / $candidatePriced : null),
            'changed' => $changed,
            'drift' => array_map(fn (Category $c) => [
                'category' => $c->value,
                'label' => $c->label(),
                'mean_change' => $scored->isEmpty() ? 0.0 : round($drift[$c->value] / $scored->count(), 3),
            ], Category::cases()),
            'examples' => $examples,
        ];
    }

    /**
     * @return array{model: string|null, outcomes: array<string, int>, correct: int, missed: int, wrongful: int, accuracy: float|null, cost_per_item: float|null}
     */
    private function side(?string $model): array
    {
        return [
            'model' => $model,
            'outcomes' => array_fill_keys(array_map(fn (Outcome $o) => $o->value, Outcome::cases()), 0),
            'correct' => 0,
            'missed' => 0,
            'wrongful' => 0,
            'accuracy' => null,
            'cost_per_item' => null,
        ];
    }

    /**
     * Accuracy is the share of automated decisions (approve or reject) a person agreed with.
     *
     * @param  array{model: string|null, outcomes: array<string, int>, correct: int, missed: int, wrongful: int, accuracy: float|null, cost_per_item: float|null}  $side
     * @return array{model: string|null, outcomes: array<string, int>, correct: int, missed: int, wrongful: int, accuracy: float|null, cost_per_item: float|null}
     */
    private function finish(array $side, ?float $costPerItem): array
    {
        $automated = $side['outcomes'][Outcome::Approve->value] + $side['outcomes'][Outcome::Reject->value];

        return [
            ...$side,
            'accuracy' => $automated > 0 ? round($side['correct'] / $automated, 4) : null,
            'cost_per_item' => $costPerItem === null ? null : round($costPerItem, 5),
        ];
    }

    /**
     * An automated decision is correct when it matches the person's; review sends it to a person either way.
     *
     * @param  array{model: string|null, outcomes: array<string, int>, correct: int, missed: int, wrongful: int, accuracy: float|null, cost_per_item: float|null}  $side
     */
    private function tally(array &$side, Outcome $automated, Outcome $human): void
    {
        $side['outcomes'][$automated->value]++;

        match (true) {
            $automated === $human => $side['correct']++,
            $automated === Outcome::Approve => $side['missed']++,
            $automated === Outcome::Reject => $side['wrongful']++,
            default => null,
        };
    }
}
