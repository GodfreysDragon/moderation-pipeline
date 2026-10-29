<?php

namespace App\Notifications;

use App\Http\Presenters\SubmissionPresenter;
use App\Models\ReviewTask;
use App\Models\User;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * A moderator handed an item up; only admins can decide it.
 */
class EscalationOpened extends Notification implements ShouldQueue
{
    use Queueable;

    public readonly string $title;

    public readonly string $body;

    public readonly string $url;

    public function __construct(ReviewTask $task)
    {
        $reason = SubmissionPresenter::reasonLabel($task->submission->decision_reason);

        $this->title = "Escalation waiting: submission #{$task->submission_id}";
        $this->body = filled($task->note) ? "\"{$task->note}\"" : ($reason ? "Flagged for {$reason}." : 'A moderator asked for an admin decision.');
        $this->url = route('review.show', $task);

        $this->afterCommit();
    }

    /**
     * @return list<string>
     */
    public function via(User $notifiable): array
    {
        return $notifiable->wantsEmail('escalations') ? ['database', 'broadcast', 'mail'] : ['database', 'broadcast'];
    }

    public function toMail(User $notifiable): MailMessage
    {
        return (new MailMessage)
            ->subject($this->title)
            ->line('A moderator escalated an item to the admins.')
            ->line($this->body)
            ->action('Open the escalation', $this->url);
    }

    /**
     * @return array<string, string>
     */
    public function toArray(User $notifiable): array
    {
        return ['kind' => 'escalation', 'title' => $this->title, 'body' => $this->body, 'url' => $this->url];
    }
}
