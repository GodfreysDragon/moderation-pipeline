<?php

namespace App\Enums;

enum PipelineStage: string
{
    case Prepare = 'prepare';
    case MatchKnown = 'match_known';
    case Classify = 'classify';
    case ApplyPolicy = 'apply_policy';

    public function label(): string
    {
        return match ($this) {
            self::Prepare => 'Prepare media',
            self::MatchKnown => 'Match known content',
            self::Classify => 'AI classification',
            self::ApplyPolicy => 'Apply policy',
        };
    }
}
