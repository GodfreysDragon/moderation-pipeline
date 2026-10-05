<?php

use App\Enums\DecisionSource;
use App\Enums\Outcome;
use App\Models\PolicyVersion;
use App\Models\Submission;
use App\Models\User;

function thresholds(array $overrides = []): array
{
    return array_replace_recursive(PolicyVersion::defaultThresholds(), $overrides);
}

function policyPayload(array $overrides = []): array
{
    return ['thresholds' => thresholds($overrides), 'audit_sample_rate' => 0.1, 'blocklist_distance' => 5, 'note' => 'Tighten spam'];
}

test('only admins can change the policy', function () {
    $this->actingAs(User::factory()->moderator()->create())->post(route('policy.store'), policyPayload())->assertForbidden();
    $this->actingAs(User::factory()->moderator()->create())->postJson(route('policy.preview'), policyPayload())->assertForbidden();
    $this->actingAs(User::factory()->create())->get(route('policy.edit'))->assertForbidden();
});

test('saving creates a new version and keeps the old one', function () {
    $admin = User::factory()->admin()->create();
    $first = PolicyVersion::current();

    $this->actingAs($admin)
        ->post(route('policy.store'), policyPayload(['spam' => ['review' => 0.3, 'reject' => 0.6]]))
        ->assertRedirect(route('policy.edit'));

    $current = PolicyVersion::current();
    expect($current->id)->not->toBe($first->id)
        ->and($current->thresholds['spam'])->toBe(['review' => 0.3, 'reject' => 0.6])
        ->and($current->thresholds['self_harm']['reject'])->toBeNull()
        ->and($current->audit_sample_rate)->toBe(0.1)
        ->and($current->created_by)->toBe($admin->id)
        ->and(PolicyVersion::query()->count())->toBe(2);
});

test('a reject threshold below the review threshold is refused', function () {
    $this->actingAs(User::factory()->admin()->create())
        ->post(route('policy.store'), policyPayload(['hate' => ['review' => 0.7, 'reject' => 0.5]]))
        ->assertSessionHasErrors('thresholds.hate.reject');
});

test('the backtest shows what would change and the mistakes it would make', function () {
    // Human-confirmed spam that the current policy only sends to review.
    Submission::factory()->scored(['spam' => 0.65])->decided(Outcome::Reject, DecisionSource::Moderator, 'spam')->create();
    // Human-cleared post with a moderate spam score.
    Submission::factory()->scored(['spam' => 0.55])->decided(Outcome::Approve, DecisionSource::Moderator)->create();
    // Clean, automated.
    Submission::factory()->scored()->decided(Outcome::Approve)->create();

    $response = $this->actingAs(User::factory()->admin()->create())
        ->postJson(route('policy.preview'), policyPayload(['spam' => ['review' => 0.5, 'reject' => 0.5]]))
        ->assertOk();

    expect($response->json())->toMatchArray([
        'sample' => 3,
        'labelled' => 2,
        'current' => ['approve' => 1, 'review' => 2, 'reject' => 0],
        'proposed' => ['approve' => 1, 'review' => 0, 'reject' => 2],
        'changed' => 2,
        'transitions' => ['review->reject' => 2],
        'errors' => ['current' => ['missed' => 0, 'wrongful' => 0], 'proposed' => ['missed' => 0, 'wrongful' => 1]],
    ]);
});

test('new submissions are decided under the newest policy', function () {
    $admin = User::factory()->admin()->create();
    $this->actingAs($admin)->post(route('policy.store'), policyPayload(['spam' => ['review' => 0.2, 'reject' => 0.3]]));

    $this->actingAs(User::factory()->create())->post(route('submissions.store'), ['caption' => 'buy now [spam:0.35]']);

    $submission = Submission::query()->sole();
    expect($submission->status->value)->toBe('rejected')
        ->and($submission->policy_version_id)->toBe(PolicyVersion::current()->id);
});
