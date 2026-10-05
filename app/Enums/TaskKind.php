<?php

namespace App\Enums;

/**
 * Why a submission is in front of a person.
 */
enum TaskKind: string
{
    /** The policy wasn't confident either way (or the AI stage failed). */
    case Triage = 'triage';

    /** A moderator handed the item up to an admin. */
    case Escalation = 'escalation';

    /** The uploader disputed a rejection. */
    case Appeal = 'appeal';

    /** A random sample of automated decisions, checked to measure their precision. */
    case Audit = 'audit';

    public function label(): string
    {
        return match ($this) {
            self::Triage => 'Triage',
            self::Escalation => 'Escalation',
            self::Appeal => 'Appeal',
            self::Audit => 'QA audit',
        };
    }

    /**
     * Added to the AI-derived priority so some kinds jump the queue.
     */
    public function priorityBoost(): int
    {
        return match ($this) {
            self::Escalation => 30,
            self::Appeal => 20,
            self::Triage => 0,
            self::Audit => -20,
        };
    }
}
