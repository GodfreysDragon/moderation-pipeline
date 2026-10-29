<?php

namespace App\Observers;

use App\Models\Submission;
use App\Webhooks\WebhookDispatcher;

class SubmissionObserver
{
    public function __construct(private readonly WebhookDispatcher $webhooks) {}

    /**
     * Every path to a decision (policy, blocklist, reviewer, appeal, audit,
     * escalation) ends by setting a final status, so this catches all of them.
     */
    public function updated(Submission $submission): void
    {
        if ($submission->wasChanged('status') && $submission->status->isFinal()) {
            $this->webhooks->submissionDecided($submission);
        }
    }
}
