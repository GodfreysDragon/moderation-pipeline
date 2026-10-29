<?php

use App\Enums\Decision;
use App\Enums\DecisionSource;
use App\Enums\Outcome;
use App\Models\ReviewTask;
use App\Models\Submission;
use App\Models\User;
use App\Moderation\ModerationService;
use App\Notifications\EscalationOpened;
use App\Notifications\ReviewBacklog;
use App\Notifications\SubmissionDecided;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Lottery;
use Inertia\Testing\AssertableInertia as Assert;

beforeEach(function () {
    Storage::fake('local');
    Lottery::alwaysLose();
    $this->moderation = app(ModerationService::class);
    $this->moderator = User::factory()->moderator()->create();
    $this->admin = User::factory()->admin()->create();
    $this->uploader = User::factory()->create();
});

afterEach(fn () => Lottery::determineResultNormally());

test('the uploader hears when a moderator decides their post, by email unless they opted out', function () {
    Notification::fake();
    $submission = Submission::factory()->for($this->uploader)->scored(['spam' => 0.6])->pendingReview()->create(['caption' => 'Free followers']);
    $task = ReviewTask::factory()->for($submission)->claimedBy($this->moderator)->create();

    $this->moderation->decide($task, $this->moderator, Decision::Reject, 'spam');

    Notification::assertSentTo($this->uploader, SubmissionDecided::class, function (SubmissionDecided $n, array $channels) {
        return $n->title === 'Your post was removed'
            && str_contains($n->body, 'Spam')
            && str_contains($n->body, 'You can appeal until')
            && $channels === ['database', 'mail'];
    });

    $this->uploader->update(['notification_preferences' => ['decisions' => false]]);
    expect((new SubmissionDecided($submission))->via($this->uploader->refresh()))->toBe(['database']);
});

test('automatic approvals are not announced; automatic removals are', function () {
    Notification::fake();

    $this->actingAs($this->uploader)->post(route('submissions.store'), ['caption' => 'Sunset over the harbour']);
    Notification::assertNothingSent();

    $this->actingAs($this->uploader)->post(route('submissions.store'), ['caption' => 'Guaranteed returns, click the link']);
    Notification::assertSentTo($this->uploader, SubmissionDecided::class, fn (SubmissionDecided $n) => $n->title === 'Your post was removed');
});

test('an appeal outcome says so', function () {
    Notification::fake();
    $submission = Submission::factory()->for($this->uploader)->scored(['spam' => 0.95])->decided(Outcome::Reject, DecisionSource::Policy, 'spam')->create();
    $appeal = $this->moderation->appeal($submission, 'This is my own art, not spam.');
    $appeal->update(['claimed_by' => $this->moderator->id, 'claimed_at' => now(), 'claim_expires_at' => now()->addMinutes(10)]);

    $this->moderation->decide($appeal->refresh(), $this->moderator, Decision::Approve, null);

    Notification::assertSentTo($this->uploader, SubmissionDecided::class, fn (SubmissionDecided $n) => $n->title === 'Your appeal was upheld');
});

test('an escalation notifies every admin and nobody else', function () {
    Notification::fake();
    $otherAdmin = User::factory()->admin()->create();
    $task = ReviewTask::factory()->claimedBy($this->moderator)->create();

    $this->moderation->decide($task, $this->moderator, Decision::Escalate, null, 'Credible threat?');

    Notification::assertSentTo([$this->admin, $otherAdmin], EscalationOpened::class, fn (EscalationOpened $n) => $n->body === '"Credible threat?"');
    Notification::assertNotSentTo([$this->moderator, $this->uploader], EscalationOpened::class);
});

test('a backlog alert goes to staff once per cooldown, and only when something is overdue', function () {
    Notification::fake();
    Cache::flush();
    config(['moderation.backlog_alert_minutes' => 60]);

    ReviewTask::factory()->create(['created_at' => now()->subMinutes(30)]);
    $this->artisan('moderation:check-backlog')->assertSuccessful();
    Notification::assertNothingSent();

    ReviewTask::factory()->create(['created_at' => now()->subMinutes(95)]);
    $this->artisan('moderation:check-backlog')->expectsOutputToContain('1 overdue; alerted 2 staff')->assertSuccessful();
    $this->artisan('moderation:check-backlog')->expectsOutputToContain('already alerted')->assertSuccessful();

    Notification::assertSentTo([$this->moderator, $this->admin], ReviewBacklog::class, fn (ReviewBacklog $n, array $channels) => $n->oldestMinutes === 95 && $channels === ['database']);
    Notification::assertNotSentTo($this->uploader, ReviewBacklog::class);
});

test('the bell shows unread notifications; opening one marks it read and follows its link', function () {
    $submission = Submission::factory()->for($this->uploader)->decided(Outcome::Reject, DecisionSource::Moderator, 'spam')->create();
    $this->uploader->notifyNow(new SubmissionDecided($submission), ['database']);
    $this->uploader->notifyNow(new SubmissionDecided($submission), ['database']);
    $first = $this->uploader->notifications()->first();

    $this->actingAs($this->uploader)->get(route('submissions.index'))
        ->assertInertia(fn (Assert $page) => $page->where('notifications.unread', 2)->where('notifications.recent.0.title', 'Your post was removed'));

    $this->get(route('notifications.open', $first->id))->assertRedirect(route('submissions.show', $submission));
    expect($first->refresh()->read_at)->not->toBeNull();

    $this->post(route('notifications.read-all'))->assertRedirect();
    expect($this->uploader->unreadNotifications()->count())->toBe(0);

    $this->actingAs($this->moderator)->get(route('notifications.open', $first->id))->assertNotFound();
});

test('people can only switch email on for notifications their role receives', function () {
    $this->actingAs($this->uploader)->put(route('notification-preferences.update'), ['email' => ['backlog']])->assertRedirect();

    expect($this->uploader->refresh()->notification_preferences)->toBe(['decisions' => false])
        ->and($this->uploader->wantsEmail('backlog'))->toBeFalse();

    $this->actingAs($this->admin)->get(route('notification-preferences.edit'))
        ->assertInertia(fn (Assert $page) => $page->component('settings/Notifications')->where('options', [
            ['kind' => 'decisions', 'enabled' => true],
            ['kind' => 'escalations', 'enabled' => true],
            ['kind' => 'backlog', 'enabled' => false],
        ]));
});
