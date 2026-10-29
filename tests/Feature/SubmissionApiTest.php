<?php

use App\Enums\SubmissionStatus;
use App\Models\Submission;
use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Lottery;
use Laravel\Sanctum\Sanctum;
use Tests\Support\Images;

beforeEach(function () {
    Storage::fake('local');
    Lottery::alwaysLose();
});

afterEach(fn () => Lottery::determineResultNormally());

test('the API needs a token with the right ability', function () {
    $this->postJson(route('api.submissions.store'), ['caption' => 'Hello'])->assertUnauthorized();

    Sanctum::actingAs(User::factory()->create(), ['submissions:read']);

    $this->postJson(route('api.submissions.store'), ['caption' => 'Hello'])->assertForbidden();
    $this->getJson(route('api.submissions.index'))->assertOk();
});

test('an upload is accepted with 202 and runs through the pipeline', function () {
    $user = User::factory()->create();
    Sanctum::actingAs($user, ['submissions:read', 'submissions:write']);

    $response = $this->post(route('api.submissions.store'), [
        'caption' => 'Evening at the harbour',
        'image' => UploadedFile::fake()->createWithContent('harbour.png', Images::png()),
        'reference' => 'photo-123',
    ], ['Accept' => 'application/json']);

    $submission = Submission::query()->sole();

    $response->assertAccepted()
        ->assertHeader('Location', route('api.submissions.show', $submission))
        ->assertJsonPath('data.id', $submission->id)
        ->assertJsonPath('data.reference', 'photo-123')
        ->assertJsonPath('data.has_image', true)
        // The test queue is synchronous, so the decision is already made.
        ->assertJsonPath('data.status', 'approved')
        ->assertJsonPath('data.decision.source', 'policy')
        ->assertJsonMissingPath('data.ai_scores');

    expect($submission->user_id)->toBe($user->id)
        ->and($submission->status)->toBe(SubmissionStatus::Approved);
});

test('re-sending a reference returns the original submission instead of a duplicate', function () {
    Sanctum::actingAs(User::factory()->create(), ['submissions:write']);

    $first = $this->postJson(route('api.submissions.store'), ['caption' => 'Hello', 'reference' => 'abc'])->assertAccepted();
    $again = $this->postJson(route('api.submissions.store'), ['caption' => 'Hello again', 'reference' => 'abc'])->assertOk();

    expect($again->json('data.id'))->toBe($first->json('data.id'))
        ->and(Submission::query()->count())->toBe(1);
});

test('the same reference from two different users makes two submissions', function () {
    Sanctum::actingAs(User::factory()->create(), ['submissions:write']);
    $this->postJson(route('api.submissions.store'), ['caption' => 'Hello', 'reference' => 'abc'])->assertAccepted();

    Sanctum::actingAs(User::factory()->create(), ['submissions:write']);
    $this->postJson(route('api.submissions.store'), ['caption' => 'Hello', 'reference' => 'abc'])->assertAccepted();

    expect(Submission::query()->count())->toBe(2);
});

test('an empty upload is rejected with JSON errors', function () {
    Sanctum::actingAs(User::factory()->create(), ['submissions:write']);

    $this->postJson(route('api.submissions.store'), [])
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['caption' => 'Add an image, a caption, or both.']);
});

test('tokens only see their own submissions', function () {
    $user = User::factory()->create();
    $own = Submission::factory()->for($user)->create();
    $other = Submission::factory()->create();

    Sanctum::actingAs($user, ['submissions:read']);

    $this->getJson(route('api.submissions.show', $own))->assertOk()->assertJsonPath('data.id', $own->id);
    $this->getJson(route('api.submissions.show', $other))->assertForbidden();
    $this->getJson(route('api.submissions.index'))->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.id', $own->id);
});
