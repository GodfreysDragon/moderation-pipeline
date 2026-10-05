<?php

namespace App\Enums;

/**
 * A reviewer's resolution of a task.
 */
enum Decision: string
{
    case Approve = 'approve';
    case Reject = 'reject';
    case Escalate = 'escalate';

    public function outcome(): ?Outcome
    {
        return match ($this) {
            self::Approve => Outcome::Approve,
            self::Reject => Outcome::Reject,
            self::Escalate => null,
        };
    }
}
