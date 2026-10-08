<?php

use App\Enums\Outcome;
use App\Models\ReviewTask;
use App\Models\Submission;
use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Support\Images;

beforeEach(fn () => Storage::fake('local'));

test('members only see their own submissions', function () {
    $member = User::factory()->create();
    Submission::factory()->for($member)->count(2)->create();
    $other = Submission::factory()->create();

    $this->actingAs($member)
        ->get(route('submissions.index'))
        ->assertInertia(fn (Assert $page) => $page
            ->component('submissions/Index')
            ->where('scope', 'mine')
            ->has('submissions.data', 2));

    $this->actingAs($member)->get(route('submissions.show', $other))->assertForbidden();
});

test('staff see every submission and can filter by status', function () {
    Submission::factory()->decided(Outcome::Reject)->create();
    Submission::factory()->count(2)->decided(Outcome::Approve)->create();

    $this->actingAs(User::factory()->moderator()->create())
        ->get(route('submissions.index', ['status' => 'rejected']))
        ->assertInertia(fn (Assert $page) => $page
            ->where('scope', 'all')
            ->has('submissions.data', 1)
            ->where('submissions.data.0.status', 'rejected'));
});

test('uploaders get the decision but not the AI internals', function () {
    $member = User::factory()->create();
    $submission = Submission::factory()->for($member)->scored(['spam' => 0.9])->decided(Outcome::Reject, reason: 'spam')->create();

    $this->actingAs($member)
        ->get(route('submissions.show', $submission))
        ->assertInertia(fn (Assert $page) => $page
            ->component('submissions/Show')
            ->where('submission.decision_reason', 'Spam & scams')
            ->where('submission.can_appeal', true)
            ->missing('submission.ai')
            ->missing('submission.sha256')
            ->missing('submission.tasks'));

    $this->actingAs(User::factory()->moderator()->create())
        ->get(route('submissions.show', $submission))
        ->assertInertia(fn (Assert $page) => $page
            ->where('submission.ai.categories.0.value', 'sexual')
            ->has('submission.ai.categories', 7)
            ->where('submission.can_appeal', false));
});

test('images are private: served to the uploader and staff only', function () {
    $member = User::factory()->create();
    $this->actingAs($member)->post(route('submissions.store'), [
        'image' => UploadedFile::fake()->createWithContent('cat.png', Images::png()),
    ]);
    $submission = Submission::query()->sole();

    $this->actingAs($member)->get(route('submissions.media', [$submission, 'thumb']))
        ->assertOk()
        ->assertHeader('Content-Type', 'image/png')
        ->assertHeader('X-Content-Type-Options', 'nosniff');
    $this->actingAs(User::factory()->moderator()->create())->get(route('submissions.media', [$submission, 'image']))->assertOk();
    $this->actingAs(User::factory()->create())->get(route('submissions.media', [$submission, 'image']))->assertForbidden();
    $this->get(route('submissions.media', [$submission, 'original']))->assertNotFound();
});

test('the review page shows the task, the submission and the reasons', function () {
    $moderator = User::factory()->moderator()->create();
    $task = ReviewTask::factory()->claimedBy($moderator)->create();

    $this->actingAs($moderator)
        ->get(route('review.show', $task))
        ->assertInertia(fn (Assert $page) => $page
            ->component('review/Show')
            ->where('task.mine', true)
            ->where('task.can_claim', false)
            ->where('submission.id', $task->submission_id)
            ->has('reasons', 8));

    $this->actingAs(User::factory()->moderator()->create())
        ->get(route('review.show', $task))
        ->assertInertia(fn (Assert $page) => $page
            ->where('task.mine', false)
            ->where('task.claimed_by', $moderator->name));
});

test('the queue lists open tasks most urgent first, with the sidebar count', function () {
    ReviewTask::factory()->create(['priority' => 10]);
    $urgent = ReviewTask::factory()->create(['priority' => 80]);

    $this->actingAs(User::factory()->moderator()->create())
        ->get(route('review.index'))
        ->assertInertia(fn (Assert $page) => $page
            ->component('review/Index')
            ->where('tasks.data.0.id', $urgent->id)
            ->has('tasks.data', 2)
            ->where('queueCount', 2)
            ->where('auth.can.moderate', true)
            ->where('auth.can.managePolicy', false));
});

test('the policy page is readable by moderators', function () {
    $this->actingAs(User::factory()->moderator()->create())
        ->get(route('policy.edit'))
        ->assertInertia(fn (Assert $page) => $page
            ->component('policy/Edit')
            ->where('canEdit', false)
            ->has('policy.categories', 7));
});
