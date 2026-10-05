<?php

namespace App\Moderation;

use App\Jobs\ApplyPolicy;
use App\Jobs\ClassifyContent;
use App\Jobs\MatchKnownContent;
use App\Jobs\PrepareMedia;
use App\Models\Submission;
use Illuminate\Support\Facades\Bus;

class ModerationPipeline
{
    /**
     * Queue the four stages in order. Each stage checks whether an earlier one
     * already decided the item (e.g. a blocklist hit) and records a skip if so.
     */
    public function start(Submission $submission): void
    {
        Bus::chain([
            new PrepareMedia($submission),
            new MatchKnownContent($submission),
            new ClassifyContent($submission),
            new ApplyPolicy($submission),
        ])->dispatch();
    }
}
