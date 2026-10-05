<?php

namespace App\Enums;

/**
 * What the policy engine (or a person) decided for a submission.
 */
enum Outcome: string
{
    case Approve = 'approve';
    case Review = 'review';
    case Reject = 'reject';

    public function status(): SubmissionStatus
    {
        return match ($this) {
            self::Approve => SubmissionStatus::Approved,
            self::Review => SubmissionStatus::PendingReview,
            self::Reject => SubmissionStatus::Rejected,
        };
    }
}
