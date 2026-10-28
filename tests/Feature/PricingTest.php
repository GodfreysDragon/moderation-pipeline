<?php

use App\Ai\Data\TokenUsage;
use App\Ai\Pricing;

test('it prices input, cache writes, cache reads and output separately', function () {
    // claude-opus-5-5: $4 input, $0.20 cache read, $20 output per million; writes at 1.25x input.
    $usage = new TokenUsage(input: 1_000_000, output: 1_000_000, cacheRead: 1_000_000, cacheWrite: 1_000_000);

    expect(Pricing::cost('claude-opus-5-5', $usage))->toBe(4.0 + 5.0 + 0.2 + 20.0)
        ->and(Pricing::cost('claude-opus-5-5', $usage, batch: true))->toBe((4.0 + 5.0 + 0.2 + 20.0) / 2);
});

test('a model with no configured price has no estimate', function () {
    expect(Pricing::cost('fake-keyword-classifier', new TokenUsage(100, 100)))->toBeNull();
});
