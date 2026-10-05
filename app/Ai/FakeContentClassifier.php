<?php

namespace App\Ai;

use App\Ai\Contracts\ContentClassifier;
use App\Ai\Data\Classification;
use App\Ai\Data\ClassificationInput;
use App\Ai\Exceptions\ClassifierRefusedException;
use App\Enums\Category;

/**
 * Deterministic offline stand-in for demos and tests. It can't see pixels: it
 * scores the caption and the original filename against a small keyword list.
 *
 * For demos and tests, a caption can also pin scores directly, e.g.
 * "[violence:0.62] [spam:0.9]", or force a refusal with "[refuse]".
 */
class FakeContentClassifier implements ContentClassifier
{
    public const MODEL = 'fake-keyword-classifier';

    /**
     * @var array<string, array<string, float>>
     */
    private const KEYWORDS = [
        'sexual' => ['nude' => 0.9, 'nsfw' => 0.88, 'explicit' => 0.7, 'lingerie' => 0.45, 'bikini' => 0.2],
        'violence' => ['gore' => 0.93, 'blood' => 0.6, 'beheading' => 0.97, 'fight' => 0.4, 'gun' => 0.35, 'wound' => 0.5],
        'hate' => ['subhuman' => 0.92, 'vermin' => 0.7, 'go back to' => 0.6, 'supremacy' => 0.75],
        'harassment' => ['idiot' => 0.45, 'loser' => 0.5, 'kill yourself' => 0.95, 'doxx' => 0.9, 'home address' => 0.7],
        'self_harm' => ['self-harm' => 0.8, 'suicide' => 0.7, 'cutting' => 0.55, 'want to die' => 0.85, 'pro-ana' => 0.85],
        'illegal_goods' => ['for sale' => 0.3, 'cocaine' => 0.92, 'mdma' => 0.88, 'unregistered' => 0.65, 'ghost gun' => 0.9, 'fake id' => 0.9, 'dm to buy' => 0.6],
        'spam' => ['crypto' => 0.45, 'giveaway' => 0.55, 'click the link' => 0.7, 'guaranteed returns' => 0.92, 'dm me' => 0.4, 'free followers' => 0.85],
    ];

    public function classify(ClassificationInput $input): Classification
    {
        $text = mb_strtolower(trim(($input->caption ?? '').' '.str_replace(['-', '_'], ' ', $input->filename ?? '')));

        if (str_contains($text, '[refuse]')) {
            throw ClassifierRefusedException::withCategory('general_harms');
        }

        $categories = [];

        foreach (Category::cases() as $category) {
            [$score, $evidence] = $this->score($category, $text);
            $categories[$category->value] = ['score' => $score, 'evidence' => $evidence];
        }

        $subject = $input->hasImage() ? 'An image' : 'A text post';
        $summary = filled($input->caption)
            ? "{$subject} captioned \"".mb_strimwidth($input->caption, 0, 80, '…').'".'
            : "{$subject} with no caption.";

        return new Classification($categories, $summary, self::MODEL);
    }

    /**
     * @return array{0: float, 1: string}
     */
    private function score(Category $category, string $text): array
    {
        if (preg_match('/\['.preg_quote($category->value, '/').':(0(?:\.\d+)?|1(?:\.0+)?)\]/', $text, $m)) {
            return [(float) $m[1], 'Score pinned in the caption.'];
        }

        $best = 0.02;
        $evidence = 'none';

        foreach (self::KEYWORDS[$category->value] as $keyword => $weight) {
            if ($weight > $best && preg_match('/\b'.preg_quote($keyword, '/').'\b/u', $text)) {
                $best = $weight;
                $evidence = "Mentions \"{$keyword}\".";
            }
        }

        return [$best, $evidence];
    }
}
