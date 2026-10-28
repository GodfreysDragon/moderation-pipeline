<?php

namespace App\Ai;

use App\Ai\Data\TokenUsage;

/**
 * Estimated spend from token counts, using the per-model list prices in config.
 * Cache writes (5-minute TTL) bill at 1.25x input; batch requests at half price.
 */
final class Pricing
{
    public const CACHE_WRITE_MULTIPLIER = 1.25;

    public const BATCH_DISCOUNT = 0.5;

    /**
     * Dollars for one request, or null for a model with no configured price.
     */
    public static function cost(?string $model, TokenUsage $usage, bool $batch = false): ?float
    {
        $prices = config("moderation.pricing.{$model}");

        if (! is_array($prices)) {
            return null;
        }

        $dollars = (
            $usage->input * $prices['input']
            + $usage->cacheWrite * $prices['input'] * self::CACHE_WRITE_MULTIPLIER
            + $usage->cacheRead * $prices['cache_read']
            + $usage->output * $prices['output']
        ) / 1_000_000;

        return $batch ? $dollars * self::BATCH_DISCOUNT : $dollars;
    }
}
