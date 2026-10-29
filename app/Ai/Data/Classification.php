<?php

namespace App\Ai\Data;

use App\Enums\Category;
use UnexpectedValueException;

/**
 * Per-category risk scores (0-1) with the evidence behind each, as returned by a classifier.
 */
final readonly class Classification
{
    /**
     * @param  array<string, array{score: float, evidence: string}>  $categories  keyed by Category value
     */
    public function __construct(
        public array $categories,
        public string $summary,
        public string $model,
        public ?TokenUsage $usage = null,
        public ?string $imageText = null,
    ) {}

    /**
     * JSON schema for structured outputs: one {score, evidence} object per category, all required.
     *
     * @return array<string, mixed>
     */
    public static function schema(): array
    {
        $properties = [];

        foreach (Category::cases() as $category) {
            $properties[$category->value] = [
                'type' => 'object',
                'properties' => [
                    'score' => [
                        'type' => 'number',
                        'description' => 'Likelihood from 0.0 (clearly absent) to 1.0 (clearly present) that the content violates this category.',
                    ],
                    'evidence' => [
                        'type' => 'string',
                        'description' => 'One short sentence naming what in the image or caption drove the score, or "none".',
                    ],
                ],
                'required' => ['score', 'evidence'],
                'additionalProperties' => false,
            ];
        }

        return [
            'type' => 'object',
            'properties' => [
                'categories' => [
                    'type' => 'object',
                    'properties' => $properties,
                    'required' => array_keys($properties),
                    'additionalProperties' => false,
                ],
                'summary' => [
                    'type' => 'string',
                    'description' => 'One neutral sentence describing the content for a human moderator.',
                ],
                'image_text' => [
                    'type' => 'string',
                    'description' => 'Any words legible in the image, transcribed verbatim with line breaks as spaces, or "" when the image has no text or there is no image. Do not include the caption.',
                ],
            ],
            'required' => ['categories', 'summary', 'image_text'],
            'additionalProperties' => false,
        ];
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public static function fromArray(array $data, string $model, ?TokenUsage $usage = null): self
    {
        if (! isset($data['categories']) || ! is_array($data['categories'])) {
            throw new UnexpectedValueException('Classification is missing its categories.');
        }

        $categories = [];

        foreach (Category::cases() as $category) {
            $entry = $data['categories'][$category->value] ?? null;

            if (! is_array($entry) || ! is_numeric($entry['score'] ?? null)) {
                throw new UnexpectedValueException("Classification has no score for {$category->value}.");
            }

            $categories[$category->value] = [
                // Schemas can't bound numbers, so clamp here.
                'score' => round(max(0.0, min(1.0, (float) $entry['score'])), 3),
                'evidence' => mb_substr((string) ($entry['evidence'] ?? ''), 0, 300),
            ];
        }

        $imageText = trim(preg_replace('/\s+/u', ' ', (string) ($data['image_text'] ?? '')) ?? '');

        return new self(
            $categories,
            mb_substr((string) ($data['summary'] ?? ''), 0, 500),
            $model,
            $usage,
            $imageText === '' ? null : mb_substr($imageText, 0, 1000),
        );
    }

    /**
     * @return array<string, float>
     */
    public function scores(): array
    {
        return array_map(fn (array $entry) => $entry['score'], $this->categories);
    }
}
