<?php

namespace App\Events;

use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;
use Illuminate\Foundation\Events\Dispatchable;

/**
 * A review task was opened, claimed, released or resolved. Staff pages refresh
 * the queue and the sidebar count.
 */
class ReviewQueueChanged implements ShouldBroadcast, ShouldDispatchAfterCommit
{
    use Dispatchable, InteractsWithSockets;

    public function __construct(public readonly int $taskId) {}

    public function broadcastOn(): PrivateChannel
    {
        return new PrivateChannel('moderation');
    }

    public function broadcastAs(): string
    {
        return 'queue.changed';
    }

    /**
     * @return array{task: int}
     */
    public function broadcastWith(): array
    {
        return ['task' => $this->taskId];
    }
}
