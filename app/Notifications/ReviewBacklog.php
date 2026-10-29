<?php

namespace App\Notifications;

use App\Models\User;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * Items have waited longer than the alert threshold for a person.
 */
class ReviewBacklog extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(
        public readonly int $waiting,
        public readonly int $oldestMinutes,
    ) {}

    /**
     * @return list<string>
     */
    public function via(User $notifiable): array
    {
        return $notifiable->wantsEmail('backlog') ? ['database', 'mail'] : ['database'];
    }

    public function title(): string
    {
        return 'The review queue is backing up';
    }

    public function body(): string
    {
        $age = $this->oldestMinutes >= 120 ? intdiv($this->oldestMinutes, 60).' hours' : "{$this->oldestMinutes} minutes";

        return "{$this->waiting} ".($this->waiting === 1 ? 'item has' : 'items have')." waited too long; the oldest for {$age}.";
    }

    public function toMail(User $notifiable): MailMessage
    {
        return (new MailMessage)
            ->subject($this->title())
            ->line($this->body())
            ->action('Open the review queue', route('review.index'));
    }

    /**
     * @return array<string, string>
     */
    public function toArray(User $notifiable): array
    {
        return ['kind' => 'backlog', 'title' => $this->title(), 'body' => $this->body(), 'url' => route('review.index')];
    }
}
