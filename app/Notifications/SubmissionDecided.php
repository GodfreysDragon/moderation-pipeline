<?php

namespace App\Notifications;

use App\Enums\DecisionSource;
use App\Enums\SubmissionStatus;
use App\Http\Presenters\SubmissionPresenter;
use App\Models\Submission;
use App\Models\User;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * Tells the uploader a person decided their post, or that it was removed.
 * Everything is worked out now, so a queued email describes this decision
 * even if the submission changes again before it goes out.
 */
class SubmissionDecided extends Notification implements ShouldQueue
{
    use Queueable;

    public readonly string $title;

    public readonly string $body;

    public readonly string $url;

    public function __construct(Submission $submission)
    {
        $approved = $submission->status === SubmissionStatus::Approved;
        $appeal = $submission->decision_source === DecisionSource::Appeal;

        $this->title = match (true) {
            $appeal && $approved => 'Your appeal was upheld',
            $appeal => 'Your appeal was declined',
            $approved => 'Your post was approved',
            default => 'Your post was removed',
        };

        $what = filled($submission->caption) ? '"'.mb_strimwidth($submission->caption, 0, 60, '…').'"' : 'Your image post';
        $reason = SubmissionPresenter::reasonLabel($submission->decision_reason);
        $deadline = $submission->canBeAppealed()
            ? $submission->decided_at?->addDays(config('moderation.appeal_window_days'))->toFormattedDayDateString()
            : null;

        $this->body = trim(implode(' ', array_filter([
            $approved ? "{$what} was approved and is visible." : "{$what} breaks the content policy".($reason ? ": {$reason}." : '.'),
            $deadline ? "You can appeal until {$deadline}." : null,
        ])));
        $this->url = route('submissions.show', $submission);

        $this->afterCommit();
    }

    /**
     * Automated approvals are instant and expected, so only removals and
     * decisions made by a person are worth telling the uploader about.
     */
    public static function isWorthSending(Submission $submission): bool
    {
        return $submission->status === SubmissionStatus::Rejected
            || ! ($submission->decision_source?->isAutomated() ?? true);
    }

    /**
     * @return list<string>
     */
    public function via(User $notifiable): array
    {
        return $notifiable->wantsEmail('decisions') ? ['database', 'broadcast', 'mail'] : ['database', 'broadcast'];
    }

    public function toMail(User $notifiable): MailMessage
    {
        return (new MailMessage)
            ->subject($this->title)
            ->greeting($this->title)
            ->line($this->body)
            ->action('View the post', $this->url);
    }

    /**
     * @return array<string, string>
     */
    public function toArray(User $notifiable): array
    {
        return ['kind' => 'decision', 'title' => $this->title, 'body' => $this->body, 'url' => $this->url];
    }
}
