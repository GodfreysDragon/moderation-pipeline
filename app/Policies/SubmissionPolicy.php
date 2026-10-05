<?php

namespace App\Policies;

use App\Models\Submission;
use App\Models\User;

class SubmissionPolicy
{
    /**
     * Uploaders see their own submissions; staff see everything.
     */
    public function view(User $user, Submission $submission): bool
    {
        return $user->id === $submission->user_id || $user->isStaff();
    }

    /**
     * Only the uploader may appeal, once, within the appeal window.
     */
    public function appeal(User $user, Submission $submission): bool
    {
        return $user->id === $submission->user_id && $submission->canBeAppealed();
    }
}
