<?php

use App\Enums\Decision;
use App\Enums\DecisionSource;
use App\Enums\Outcome;
use App\Enums\SubmissionStatus;
use App\Enums\TaskKind;
use App\Exceptions\ClaimLostException;
use App\Models\BlockedHash;
use App\Models\ReviewTask;
use App\Models\Submission;
use App\Models\User;
use App\Moderation\ModerationService;

beforeEach(function () {
    $this->moderation = app(ModerationService::class);
    $this->alice = User::factory()->moderator()->create(['name' => 'Alice']);
    $this->bob = User::factory()->moderator()->create(['name' => 'Bob']);
    $this->admin = User::factory()->admin()->create();
});

test('members cannot see the review queue', function () {
    $this->actingAs(User::factory()->create())->get(route('review.index'))->assertForbidden();
    $this->actingAs(User::factory()->create())->post(route('review.next'))->assertForbidden();
});

test('next gives each moderator the most urgent task nobody else holds', function () {
    $low = ReviewTask::factory()->create(['priority' => 10]);
    $high = ReviewTask::factory()->create(['priority' => 90]);

    $this->actingAs($this->alice)->post(route('review.next'))->assertRedirect(route('review.show', $high));
    $this->actingAs($this->bob)->post(route('review.next'))->assertRedirect(route('review.show', $low));

    expect($high->refresh()->claimed_by)->toBe($this->alice->id)
        ->and($low->refresh()->claimed_by)->toBe($this->bob->id);
});

test('asking for next again returns the task you already hold', function () {
    $held = ReviewTask::factory()->claimedBy($this->alice)->create(['priority' => 5]);
    ReviewTask::factory()->create(['priority' => 90]);

    expect($this->moderation->claimNext($this->alice)->id)->toBe($held->id);
});

test('a claimed task cannot be taken until the lease runs out', function () {
    $task = ReviewTask::factory()->claimedBy($this->alice)->create();

    expect($this->moderation->claim($task, $this->bob))->toBeFalse();

    $this->travel(11)->minutes();

    expect($this->moderation->claim($task, $this->bob))->toBeTrue()
        ->and($task->refresh()->claimed_by)->toBe($this->bob->id);
});

test('deciding needs a live claim', function () {
    $task = ReviewTask::factory()->create();

    $this->actingAs($this->alice)
        ->post(route('review.decide', $task), ['decision' => 'approve'])
        ->assertRedirect(route('review.show', $task));

    expect($task->refresh()->isOpen())->toBeTrue();
});

test('rejecting needs a policy reason and blocks the image', function () {
    $submission = Submission::factory()->scored(['violence' => 0.6])->pendingReview()->withImage()->create();
    $task = ReviewTask::factory()->for($submission)->claimedBy($this->alice)->create();

    $this->actingAs($this->alice)
        ->post(route('review.decide', $task), ['decision' => 'reject'])
        ->assertSessionHasErrors('reason');

    $this->actingAs($this->alice)
        ->post(route('review.decide', $task), ['decision' => 'reject', 'reason' => 'violence', 'note' => 'Graphic.'])
        ->assertRedirect(route('review.index'));

    expect($submission->refresh()->status)->toBe(SubmissionStatus::Rejected)
        ->and($submission->decision_source)->toBe(DecisionSource::Moderator)
        ->and($submission->decided_by)->toBe($this->alice->id)
        ->and($task->refresh()->decision)->toBe(Decision::Reject)
        ->and(BlockedHash::query()->sole()->sha256)->toBe($submission->sha256);
});

test('a reviewer can opt out of blocking the image', function () {
    $submission = Submission::factory()->pendingReview()->withImage()->create();
    $task = ReviewTask::factory()->for($submission)->claimedBy($this->alice)->create();

    $this->actingAs($this->alice)->post(route('review.decide', $task), ['decision' => 'reject', 'reason' => 'spam', 'block_image' => false]);

    expect(BlockedHash::query()->count())->toBe(0);
});

test('approve and next moves straight to the following task', function () {
    $task = ReviewTask::factory()->claimedBy($this->alice)->create(['priority' => 50]);
    $following = ReviewTask::factory()->create(['priority' => 10]);

    $this->actingAs($this->alice)
        ->post(route('review.decide', $task), ['decision' => 'approve', 'next' => true])
        ->assertRedirect(route('review.show', $following));
});

test('escalations go to admins only', function () {
    $task = ReviewTask::factory()->claimedBy($this->alice)->create();

    $this->actingAs($this->alice)
        ->post(route('review.decide', $task), ['decision' => 'escalate'])
        ->assertSessionHasErrors('note');

    $this->actingAs($this->alice)->post(route('review.decide', $task), ['decision' => 'escalate', 'note' => 'Possible real threat, need a second opinion.']);

    $escalation = ReviewTask::query()->open()->sole();
    expect($escalation->kind)->toBe(TaskKind::Escalation)
        ->and($escalation->note)->toContain('second opinion')
        ->and($escalation->priority)->toBe(70)
        ->and($task->submission->refresh()->status)->toBe(SubmissionStatus::PendingReview)
        ->and($this->moderation->claimNext($this->bob))->toBeNull()
        ->and($this->moderation->claimNext($this->admin)?->id)->toBe($escalation->id);

    $this->actingAs($this->admin)->post(route('review.decide', $escalation), ['decision' => 'reject', 'reason' => 'violence']);

    expect($task->submission->refresh()->status)->toBe(SubmissionStatus::Rejected);
});

test('an uploader can appeal once, and a different moderator reviews it', function () {
    $uploader = User::factory()->create();
    $submission = Submission::factory()->for($uploader)->scored(['spam' => 0.7])->pendingReview()->withImage()->create();
    $task = ReviewTask::factory()->for($submission)->claimedBy($this->alice)->create();
    $this->moderation->decide($task, $this->alice, Decision::Reject, 'spam');
    expect(BlockedHash::query()->count())->toBe(1);

    $this->actingAs($uploader)
        ->post(route('submissions.appeal', $submission), ['message' => 'This is my own bakery, not spam.'])
        ->assertRedirect();

    $appeal = ReviewTask::query()->open()->sole();
    expect($submission->refresh()->status)->toBe(SubmissionStatus::PendingReview)
        ->and($appeal->kind)->toBe(TaskKind::Appeal)
        ->and($appeal->excluded_reviewer_id)->toBe($this->alice->id)
        ->and($this->moderation->claimNext($this->alice))->toBeNull()
        ->and($this->moderation->claim($appeal, $this->alice))->toBeFalse();

    $appeal = $this->moderation->claimNext($this->bob);
    expect($appeal->kind)->toBe(TaskKind::Appeal);
    $this->moderation->decide($appeal, $this->bob, Decision::Approve, null, 'Legitimate small business.');

    expect($submission->refresh()->status)->toBe(SubmissionStatus::Approved)
        ->and($submission->decision_source)->toBe(DecisionSource::Appeal)
        ->and(BlockedHash::query()->count())->toBe(0);
});

test('appeals are limited to rejected items, once, inside the window', function () {
    $uploader = User::factory()->create();
    $approved = Submission::factory()->for($uploader)->decided(Outcome::Approve)->create();
    $rejected = Submission::factory()->for($uploader)->decided(Outcome::Reject, reason: 'spam')->create();
    $old = Submission::factory()->for($uploader)->decided(Outcome::Reject, reason: 'spam')->create(['decided_at' => now()->subDays(20)]);
    $message = ['message' => 'Please take another look at this.'];

    $this->actingAs($uploader)->post(route('submissions.appeal', $approved), $message)->assertForbidden();
    $this->actingAs($uploader)->post(route('submissions.appeal', $old), $message)->assertForbidden();
    $this->actingAs(User::factory()->create())->post(route('submissions.appeal', $rejected), $message)->assertForbidden();

    $this->actingAs($uploader)->post(route('submissions.appeal', $rejected), $message)->assertRedirect();
    $this->actingAs($uploader)->post(route('submissions.appeal', $rejected), $message)->assertForbidden();
});

test('an audit that disagrees with the automation overturns it', function () {
    $submission = Submission::factory()->scored(['spam' => 0.3])->decided(Outcome::Approve)->create();
    $audit = ReviewTask::factory()->for($submission)->kind(TaskKind::Audit, Outcome::Approve)->claimedBy($this->alice)->create();

    $this->moderation->decide($audit, $this->alice, Decision::Reject, 'spam');

    expect($submission->refresh()->status)->toBe(SubmissionStatus::Rejected)
        ->and($submission->decision_source)->toBe(DecisionSource::Audit)
        ->and($audit->refresh()->agreedWithAutomation())->toBeFalse();
});

test('an audit that agrees leaves the automated decision in place', function () {
    $submission = Submission::factory()->decided(Outcome::Approve)->create();
    $audit = ReviewTask::factory()->for($submission)->kind(TaskKind::Audit, Outcome::Approve)->claimedBy($this->alice)->create();

    $this->moderation->decide($audit, $this->alice, Decision::Approve, null);

    expect($submission->refresh()->decision_source)->toBe(DecisionSource::Policy)
        ->and($audit->refresh()->agreedWithAutomation())->toBeTrue();
});

test('confirming an automated rejection in an audit does not block the image unless asked', function () {
    $submission = Submission::factory()->scored(['spam' => 0.95])->decided(Outcome::Reject)->withImage()->create();
    $audit = ReviewTask::factory()->for($submission)->kind(TaskKind::Audit, Outcome::Reject)->claimedBy($this->alice)->create();

    $this->actingAs($this->alice)->post(route('review.decide', $audit), ['decision' => 'reject', 'reason' => 'spam']);

    expect(BlockedHash::query()->count())->toBe(0);

    $second = Submission::factory()->scored(['spam' => 0.95])->decided(Outcome::Reject)->withImage()->create();
    $audit = ReviewTask::factory()->for($second)->kind(TaskKind::Audit, Outcome::Reject)->claimedBy($this->alice)->create();

    $this->actingAs($this->alice)->post(route('review.decide', $audit), ['decision' => 'reject', 'reason' => 'spam', 'block_image' => true]);

    expect(BlockedHash::query()->sole()->sha256)->toBe($second->sha256);
});

test('moderators cannot open escalations, even read-only', function () {
    $escalation = ReviewTask::factory()->kind(TaskKind::Escalation)->create(['note' => 'For an admin.']);

    $this->actingAs($this->alice)->get(route('review.show', $escalation))->assertForbidden();
    $this->actingAs($this->admin)->get(route('review.show', $escalation))->assertOk();
});

test('releasing a task puts it back in the queue', function () {
    $task = ReviewTask::factory()->claimedBy($this->alice)->create();

    $this->actingAs($this->alice)->post(route('review.release', $task))->assertRedirect(route('review.index'));

    expect($task->refresh()->claimed_by)->toBeNull()
        ->and($this->moderation->claim($task, $this->bob))->toBeTrue();
});

test('a double submit decides the task only once', function () {
    $task = ReviewTask::factory()->claimedBy($this->alice)->create();
    $payload = ['decision' => 'escalate', 'note' => 'Needs an admin to look.'];

    $this->actingAs($this->alice)->post(route('review.decide', $task), $payload)->assertRedirect(route('review.index'));
    $this->actingAs($this->alice)->post(route('review.decide', $task), $payload)->assertRedirect(route('review.show', $task));

    expect(ReviewTask::query()->where('kind', TaskKind::Escalation)->count())->toBe(1);
});

test('a stale claim cannot decide a task someone else has taken', function () {
    $task = ReviewTask::factory()->claimedBy($this->alice)->create();
    $stale = ReviewTask::query()->findOrFail($task->id);

    // Alice's lease lapsed and Bob took the task, but her page still holds the old claim.
    ReviewTask::query()->whereKey($task->id)->update(['claimed_by' => $this->bob->id]);

    expect(fn () => $this->moderation->decide($stale, $this->alice, Decision::Approve, null))->toThrow(ClaimLostException::class)
        ->and($task->refresh()->resolved_at)->toBeNull()
        ->and($task->claimed_by)->toBe($this->bob->id)
        ->and($task->submission->refresh()->status)->not->toBe(SubmissionStatus::Approved);
});

test('deciding the same task twice from a stale copy fails the second time', function () {
    $task = ReviewTask::factory()->claimedBy($this->alice)->create();
    $copy = ReviewTask::query()->findOrFail($task->id);

    $this->moderation->decide($task, $this->alice, Decision::Escalate, null, 'Needs an admin.');

    expect(fn () => $this->moderation->decide($copy, $this->alice, Decision::Escalate, null, 'Needs an admin.'))->toThrow(ClaimLostException::class)
        ->and(ReviewTask::query()->where('kind', TaskKind::Escalation)->count())->toBe(1);
});
