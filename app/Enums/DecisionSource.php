<?php

namespace App\Enums;

/**
 * Who made the submission's current decision.
 */
enum DecisionSource: string
{
    case Blocklist = 'blocklist';
    case Policy = 'policy';
    case Moderator = 'moderator';
    case Appeal = 'appeal';
    case Audit = 'audit';

    public function isAutomated(): bool
    {
        return $this === self::Blocklist || $this === self::Policy;
    }
}
