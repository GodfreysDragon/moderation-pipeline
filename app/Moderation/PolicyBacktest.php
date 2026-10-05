<?php

namespace App\Moderation;

use App\Enums\DecisionSource;
use App\Enums\Outcome;
use App\Enums\SubmissionStatus;
use App\Models\Submission;

/**
 * Replays recent classified items under the current and a proposed set of
 * thresholds, so an admin sees the effect of a change before saving it.
 *
 * Items a person decided give ground truth: the backtest counts how many
 * of those each policy would get wrong automatically.
 */
class PolicyBacktest
{
    public function __construct(private readonly PolicyEngine $engine) {}

    /**
     * @param  array<string, array{review: float, reject: float|null}>  $current
     * @param  array<string, array{review: float, reject: float|null}>  $proposed
     * @return array{
     *     sample: int,
     *     labelled: int,
     *     current: array<string, int>,
     *     proposed: array<string, int>,
     *     changed: int,
     *     transitions: array<string, int>,
     *     errors: array{current: array{missed: int, wrongful: int}, proposed: array{missed: int, wrongful: int}},
     *     examples: list<array{id: int, from: string, to: string, human: string|null}>
     * }
     */
    public function run(array $current, array $proposed, int $limit = 1000): array
    {
        $outcomes = fn () => array_fill_keys(array_map(fn (Outcome $o) => $o->value, Outcome::cases()), 0);

        $result = [
            'sample' => 0,
            'labelled' => 0,
            'current' => $outcomes(),
            'proposed' => $outcomes(),
            'changed' => 0,
            'transitions' => [],
            'errors' => ['current' => ['missed' => 0, 'wrongful' => 0], 'proposed' => ['missed' => 0, 'wrongful' => 0]],
            'examples' => [],
        ];

        Submission::query()
            ->whereNotNull('ai_scores')
            ->latest('id')
            ->limit($limit)
            ->get(['id', 'ai_scores', 'status', 'decision_source'])
            ->each(function (Submission $submission) use ($current, $proposed, &$result) {
                $before = $this->engine->evaluate($submission->scores(), $current)->outcome;
                $after = $this->engine->evaluate($submission->scores(), $proposed)->outcome;
                $human = $this->humanVerdict($submission);

                $result['sample']++;
                $result['current'][$before->value]++;
                $result['proposed'][$after->value]++;

                if ($human !== null) {
                    $result['labelled']++;
                    $this->countError($result['errors']['current'], $before, $human);
                    $this->countError($result['errors']['proposed'], $after, $human);
                }

                if ($before !== $after) {
                    $result['changed']++;
                    $key = "{$before->value}->{$after->value}";
                    $result['transitions'][$key] = ($result['transitions'][$key] ?? 0) + 1;

                    if (count($result['examples']) < 8) {
                        $result['examples'][] = ['id' => $submission->id, 'from' => $before->value, 'to' => $after->value, 'human' => $human?->value];
                    }
                }
            });

        return $result;
    }

    /**
     * The final decision, if a person made it.
     */
    private function humanVerdict(Submission $submission): ?Outcome
    {
        if ($submission->decision_source === null || $submission->decision_source->isAutomated() || ! $submission->status->isFinal()) {
            return null;
        }

        return $submission->status === SubmissionStatus::Approved ? Outcome::Approve : Outcome::Reject;
    }

    /**
     * @param  array{missed: int, wrongful: int}  $errors
     */
    private function countError(array &$errors, Outcome $automated, Outcome $human): void
    {
        if ($automated === Outcome::Approve && $human === Outcome::Reject) {
            $errors['missed']++;
        } elseif ($automated === Outcome::Reject && $human === Outcome::Approve) {
            $errors['wrongful']++;
        }
    }
}
