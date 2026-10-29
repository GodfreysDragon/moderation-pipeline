<?php

namespace App\Events;

use App\Models\Submission;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;
use Illuminate\Foundation\Events\Dispatchable;

/**
 * A submission moved: a pipeline stage started or finished, or its status changed.
 * Pages that show it reload their own props, so the payload carries no content and
 * channel authorisation only decides who learns that something changed.
 */
class SubmissionChanged implements ShouldBroadcast, ShouldDispatchAfterCommit
{
    use Dispatchable, InteractsWithSockets;

    public function __construct(
        public readonly int $submissionId,
        public readonly int $uploaderId,
        public readonly string $status,
    ) {}

    public static function for(Submission $submission): self
    {
        return new self($submission->id, $submission->user_id, $submission->status->value);
    }

    /**
     * @return list<PrivateChannel>
     */
    public function broadcastOn(): array
    {
        return [
            new PrivateChannel("submissions.{$this->submissionId}"),
            new PrivateChannel("App.Models.User.{$this->uploaderId}"),
            new PrivateChannel('moderation'),
        ];
    }

    public function broadcastAs(): string
    {
        return 'submission.changed';
    }

    /**
     * @return array{id: int, status: string}
     */
    public function broadcastWith(): array
    {
        return ['id' => $this->submissionId, 'status' => $this->status];
    }
}
