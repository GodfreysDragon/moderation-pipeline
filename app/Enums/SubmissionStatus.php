<?php

namespace App\Enums;

enum SubmissionStatus: string
{
    case Processing = 'processing';
    case PendingReview = 'pending_review';
    case Approved = 'approved';
    case Rejected = 'rejected';

    public function isFinal(): bool
    {
        return $this === self::Approved || $this === self::Rejected;
    }
}
