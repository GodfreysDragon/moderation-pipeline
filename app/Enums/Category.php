<?php

namespace App\Enums;

/**
 * The policy categories every submission is scored against.
 */
enum Category: string
{
    case Sexual = 'sexual';
    case Violence = 'violence';
    case Hate = 'hate';
    case Harassment = 'harassment';
    case SelfHarm = 'self_harm';
    case IllegalGoods = 'illegal_goods';
    case Spam = 'spam';

    public function label(): string
    {
        return match ($this) {
            self::Sexual => 'Sexual content',
            self::Violence => 'Violence & gore',
            self::Hate => 'Hate',
            self::Harassment => 'Harassment',
            self::SelfHarm => 'Self-harm',
            self::IllegalGoods => 'Illegal goods',
            self::Spam => 'Spam & scams',
        };
    }

    /**
     * What the category covers. Sent to the model as the policy definition.
     */
    public function definition(): string
    {
        return match ($this) {
            self::Sexual => 'Nudity, sexual acts or sexually suggestive content involving adults. Any sexualisation of minors scores 1.0.',
            self::Violence => 'Graphic injury, blood, gore, or depictions or threats of physical violence against people or animals.',
            self::Hate => 'Attacks, slurs or dehumanising content targeting people for a protected attribute (race, religion, gender, sexuality, disability, nationality).',
            self::Harassment => 'Insults, intimidation, doxxing or targeted abuse of a specific individual.',
            self::SelfHarm => 'Depiction, encouragement or instructions for suicide, self-injury or eating disorders.',
            self::IllegalGoods => 'Offers to sell or trade drugs, weapons, counterfeit goods, stolen data or other illegal items.',
            self::Spam => 'Scams, phishing, get-rich-quick schemes, unsolicited advertising or misleading links.',
        };
    }

    /**
     * How much a high score in this category should move an item up the review queue (1-3).
     */
    public function severity(): int
    {
        return match ($this) {
            self::SelfHarm, self::Sexual => 3,
            self::Violence, self::Hate, self::IllegalGoods => 2,
            self::Harassment, self::Spam => 1,
        };
    }

    /**
     * @return array{review: float, reject: float|null}
     */
    public function defaultThresholds(): array
    {
        return match ($this) {
            // Never auto-reject self-harm: a person may need help, so a human always looks.
            self::SelfHarm => ['review' => 0.3, 'reject' => null],
            self::Sexual => ['review' => 0.4, 'reject' => 0.85],
            self::Violence => ['review' => 0.45, 'reject' => 0.9],
            self::Hate => ['review' => 0.35, 'reject' => 0.85],
            self::Harassment => ['review' => 0.45, 'reject' => 0.9],
            self::IllegalGoods => ['review' => 0.4, 'reject' => 0.85],
            self::Spam => ['review' => 0.5, 'reject' => 0.8],
        };
    }
}
