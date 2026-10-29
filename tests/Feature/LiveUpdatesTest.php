<?php

use App\Enums\Decision;
use App\Events\ReviewQueueChanged;
use App\Events\SubmissionChanged;
use App\Models\ReviewTask;
use App\Models\Submission;
use App\Models\User;
use App\Moderation\ModerationService;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Support\Facades\Broadcast;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Lottery;

beforeEach(function () {
    Storage::fake('local');
    Lottery::alwaysLose();
});

afterEach(fn () => Lottery::determineResultNormally());

test('each pipeline stage and the decision tell the uploader, the submission page and staff', function () {
    Event::fake([SubmissionChanged::class]);
    $user = User::factory()->create();

    $this->actingAs($user)->post(route('submissions.store'), ['caption' => 'Sunset over the harbour']);
    $submission = Submission::query()->sole();

    Event::assertDispatched(SubmissionChanged::class, fn (SubmissionChanged $event) => $event->status === 'processing');
    Event::assertDispatched(SubmissionChanged::class, function (SubmissionChanged $event) use ($submission, $user) {
        return $event->status === 'approved'
            && collect($event->broadcastOn())->map(fn (PrivateChannel $c) => $c->name)->all() === [
                "private-submissions.{$submission->id}",
                "private-App.Models.User.{$user->id}",
                'private-moderation',
            ];
    });
});

test('claiming and deciding a task refreshes staff queues', function () {
    $moderator = User::factory()->moderator()->create();
    $task = ReviewTask::factory()->create();
    Event::fake([ReviewQueueChanged::class]);

    $moderation = app(ModerationService::class);
    $moderation->claim($task, $moderator);
    $moderation->decide($task->refresh(), $moderator, Decision::Approve, null);

    Event::assertDispatched(ReviewQueueChanged::class, fn (ReviewQueueChanged $e) => $e->taskId === $task->id && $e->broadcastOn()->name === 'private-moderation');
});

test('broadcast payloads carry no content', function () {
    $submission = Submission::factory()->create(['caption' => 'Something private']);

    expect(SubmissionChanged::for($submission)->broadcastWith())->toBe(['id' => $submission->id, 'status' => $submission->status->value]);
});

test('channel access follows who may see the page', function () {
    // Tests broadcast to the null driver, which authorises nothing; register the
    // channels on a Reverb driver (signing is local, nothing is sent).
    config(['broadcasting.default' => 'reverb', 'broadcasting.connections.reverb.key' => 'k', 'broadcasting.connections.reverb.secret' => 's', 'broadcasting.connections.reverb.app_id' => 'a']);
    Broadcast::purge();
    require base_path('routes/channels.php');

    $uploader = User::factory()->create();
    $stranger = User::factory()->create();
    $moderator = User::factory()->moderator()->create();
    $submission = Submission::factory()->for($uploader)->create();

    $auth = fn (User $user, string $channel) => $this->actingAs($user)
        ->post('/broadcasting/auth', ['socket_id' => '123.456', 'channel_name' => "private-{$channel}"])
        ->status();

    expect($auth($uploader, "submissions.{$submission->id}"))->toBe(200)
        ->and($auth($moderator, "submissions.{$submission->id}"))->toBe(200)
        ->and($auth($stranger, "submissions.{$submission->id}"))->toBe(403)
        ->and($auth($uploader, "App.Models.User.{$uploader->id}"))->toBe(200)
        ->and($auth($stranger, "App.Models.User.{$uploader->id}"))->toBe(403)
        ->and($auth($moderator, 'moderation'))->toBe(200)
        ->and($auth($uploader, 'moderation'))->toBe(403);
});
