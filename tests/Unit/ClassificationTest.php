<?php

use App\Ai\Data\Classification;
use App\Enums\Category;

function fullReply(array $overrides = []): array
{
    $categories = [];

    foreach (Category::cases() as $category) {
        $categories[$category->value] = ['score' => 0.1, 'evidence' => 'none'];
    }

    return ['categories' => array_replace($categories, $overrides), 'summary' => 'A dog on a beach.'];
}

test('the schema requires every category and allows nothing else', function () {
    $schema = Classification::schema();

    expect($schema['properties']['categories']['required'])->toBe(array_map(fn (Category $c) => $c->value, Category::cases()))
        ->and($schema['additionalProperties'])->toBeFalse()
        ->and($schema['properties']['categories']['additionalProperties'])->toBeFalse()
        ->and($schema['properties']['categories']['properties']['spam']['required'])->toBe(['score', 'evidence']);
});

test('replies are parsed and scores are clamped to 0-1', function () {
    $classification = Classification::fromArray(fullReply(['spam' => ['score' => 1.7, 'evidence' => 'Link farm.']]), 'claude-opus-5-5');

    expect($classification->scores()['spam'])->toBe(1.0)
        ->and($classification->categories['spam']['evidence'])->toBe('Link farm.')
        ->and($classification->summary)->toBe('A dog on a beach.')
        ->and($classification->model)->toBe('claude-opus-5-5');
});

test('a reply missing a category is rejected', function () {
    $reply = fullReply();
    unset($reply['categories']['hate']);

    Classification::fromArray($reply, 'm');
})->throws(UnexpectedValueException::class, 'hate');
