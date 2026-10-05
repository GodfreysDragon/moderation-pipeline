<?php

use App\Enums\Category;
use App\Enums\Outcome;
use App\Models\PolicyVersion;
use App\Moderation\PolicyEngine;

function evaluate(?array $scores): App\Moderation\PolicyResult
{
    return (new PolicyEngine)->evaluate($scores, PolicyVersion::defaultThresholds());
}

test('low scores everywhere are approved', function () {
    $result = evaluate(['violence' => 0.1, 'spam' => 0.2]);

    expect($result->outcome)->toBe(Outcome::Approve)
        ->and($result->category)->toBeNull()
        ->and($result->triggered)->toBe([]);
});

test('a score between the review and reject thresholds goes to a person', function () {
    $result = evaluate(['violence' => 0.6]);

    expect($result->outcome)->toBe(Outcome::Review)
        ->and($result->category)->toBe(Category::Violence)
        ->and($result->reason)->toBe('violence');
});

test('a score at the reject threshold is rejected', function () {
    expect(evaluate(['spam' => 0.8])->outcome)->toBe(Outcome::Reject);
});

test('the strictest category wins', function () {
    $result = evaluate(['violence' => 0.6, 'spam' => 0.95, 'hate' => 0.5]);

    expect($result->outcome)->toBe(Outcome::Reject)
        ->and($result->category)->toBe(Category::Spam)
        ->and($result->triggered)->toHaveCount(3);
});

test('among reviews, the highest score names the reason', function () {
    expect(evaluate(['violence' => 0.5, 'hate' => 0.7])->category)->toBe(Category::Hate);
});

test('self-harm is never rejected automatically', function () {
    $result = evaluate(['self_harm' => 0.99]);

    expect($result->outcome)->toBe(Outcome::Review)
        ->and($result->category)->toBe(Category::SelfHarm);
});

test('no scores means a person must look', function () {
    $result = evaluate(null);

    expect($result->outcome)->toBe(Outcome::Review)
        ->and($result->reason)->toBe('ai_unavailable');
});

test('priority weighs score by severity', function () {
    // self-harm severity 3, spam severity 1
    expect(evaluate(['self_harm' => 0.5])->priority)->toBe(50)
        ->and(evaluate(['spam' => 0.6])->priority)->toBe(20);
});

test('out-of-range scores are clamped', function () {
    expect(evaluate(['violence' => 7.0])->outcome)->toBe(Outcome::Reject)
        ->and(evaluate(['violence' => -3.0])->outcome)->toBe(Outcome::Approve);
});
