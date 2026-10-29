<?php

namespace App\Observers;

use App\Events\ReviewQueueChanged;
use App\Models\ReviewTask;

class ReviewTaskObserver
{
    /**
     * A new task joined the queue. Claims, releases and decisions are conditional
     * query updates that skip model events; ModerationService announces those.
     */
    public function created(ReviewTask $task): void
    {
        ReviewQueueChanged::dispatch($task->id);
    }
}
