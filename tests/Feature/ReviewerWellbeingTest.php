<?php

use App\Enums\Decision;
use App\Enums\Outcome;
use App\Enums\SubmissionStatus;
use App\Enums\TaskKind;
use App\Models\ReviewTask;
use App\Models\Submission;
use App\Models\User;
use App\Moderation\ModerationService;
use Inertia\Testing\AssertableInertia as Assert;

beforeEach(function () {
    $this->moderation = app(ModerationService::class);
    $this->alice = User::factory()->moderator()->create(['name' => 'Alice']);
    $this->bob = User::factory()->moderator()->create(['name' => 'Bob']);
    $this->admin = User::factory()->admin()->create();
});

test('tasks showing a graphic image are flagged when opened', function () {
    $graphic = Submission::factory()->scored(['violence' => 0.5])->pendingReview()->withImage()->create();
    $mild = Submission::factory()->scored(['spam' => 0.6])->pendingReview()->withImage()->create();
    $text = Submission::factory()->scored(['violence' => 0.9])->pendingReview()->create();
    $unscored = Submission::factory()->pendingReview()->withImage()->create(['ai_scores' => null]);

    $flag = fn (Submission $s) => $this->moderation->openTask($s, TaskKind::Triage, 40)->graphic;

    expect($flag($graphic))->toBeTrue()
        ->and($flag($mild))->toBeFalse()
        // Nothing to blur in a text post; an image nobody scored is treated as graphic.
        ->and($flag($text))->toBeFalse()
        ->and($flag($unscored))->toBeTrue();
});

test('past the daily limit, review next hands out only non-graphic work', function () {
    config(['moderation.graphic_daily_limit' => 2]);
    ReviewTask::factory()->count(2)->create([
        'graphic' => true, 'decision' => Decision::Reject, 'resolved_by' => $this->alice->id, 'resolved_at' => now()->subHours(3),
    ]);
    $graphic = ReviewTask::factory()->create(['graphic' => true, 'priority' => 90]);
    $plain = ReviewTask::factory()->create(['graphic' => false, 'priority' => 10]);

    expect($this->moderation->overGraphicLimit($this->alice))->toBeTrue()
        ->and($this->moderation->claimNext($this->alice)?->id)->toBe($plain->id)
        ->and($this->moderation->claimNext($this->bob)?->id)->toBe($graphic->id);

    $this->actingAs($this->alice)->get(route('review.index'))
        ->assertInertia(fn (Assert $page) => $page->where('exposure', ['graphic_today' => 2, 'limit' => 2]));
});

test('a sampled triage decision gets a blind second review by someone else', function () {
    config(['moderation.second_review_rate' => 1.0]);
    $submission = Submission::factory()->scored(['spam' => 0.6])->pendingReview()->create();
    $first = ReviewTask::factory()->for($submission)->claimedBy($this->alice)->create();

    $this->moderation->decide($first, $this->alice, Decision::Reject, 'spam');

    $second = ReviewTask::query()->open()->sole();
    expect($second->kind)->toBe(TaskKind::SecondReview)
        ->and($second->reviews_task_id)->toBe($first->id)
        ->and($second->excluded_reviewer_id)->toBe($this->alice->id)
        ->and($this->moderation->claimNext($this->alice))->toBeNull();

    // While it is open, the page doesn't show the first decision.
    $this->moderation->claim($second, $this->bob);
    $this->actingAs($this->bob)->get(route('review.show', $second))
        ->assertInertia(fn (Assert $page) => $page
            ->where('submission.status', 'pending_review')
            ->where('submission.decision_source', null)
            ->where('submission.decided_by', null)
            ->where('submission.tasks', []));

    $this->moderation->decide($second, $this->bob, Decision::Reject, 'spam');

    expect(ReviewTask::query()->open()->count())->toBe(0)
        ->and($submission->refresh()->status)->toBe(SubmissionStatus::Rejected)
        ->and($submission->decided_by)->toBe($this->alice->id);
});

test('when the second reviewer disagrees an admin settles it, and the decision stands until then', function () {
    $submission = Submission::factory()->scored(['spam' => 0.6])->decided(Outcome::Reject)->create();
    $first = ReviewTask::factory()->for($submission)->create([
        'decision' => Decision::Reject, 'resolved_by' => $this->alice->id, 'resolved_at' => now(),
    ]);
    $second = ReviewTask::factory()->for($submission)->kind(TaskKind::SecondReview)->claimedBy($this->bob)
        ->create(['reviews_task_id' => $first->id, 'excluded_reviewer_id' => $this->alice->id]);

    $this->actingAs($this->bob)
        ->post(route('review.decide', $second), ['decision' => 'escalate', 'note' => 'Not sure.'])
        ->assertSessionHasErrors('decision');

    $this->actingAs($this->bob)->post(route('review.decide', $second), ['decision' => 'approve']);

    $escalation = ReviewTask::query()->open()->sole();
    expect($escalation->kind)->toBe(TaskKind::Escalation)
        ->and($escalation->note)->toBe('Two reviewers disagreed: Alice chose to reject, Bob chose to approve.')
        ->and($submission->refresh()->status)->toBe(SubmissionStatus::Rejected);
});

test('the reviewers page reports agreement with Cohen’s kappa', function () {
    // First reviewer: approve, reject, approve, approve. Second: approve, reject, reject, approve.
    foreach ([['approve', 'approve'], ['reject', 'reject'], ['approve', 'reject'], ['approve', 'approve']] as [$a, $b]) {
        $submission = Submission::factory()->scored()->decided(Outcome::from($a))->create();
        $first = ReviewTask::factory()->for($submission)->create([
            'decision' => Decision::from($a), 'claimed_at' => now()->subMinutes(5), 'resolved_by' => $this->alice->id, 'resolved_at' => now()->subMinutes(4),
        ]);
        ReviewTask::factory()->for($submission)->kind(TaskKind::SecondReview)->create([
            'reviews_task_id' => $first->id, 'decision' => Decision::from($b), 'resolved_by' => $this->bob->id, 'resolved_at' => now(),
        ]);
    }

    $this->actingAs($this->alice)->get(route('reviewers.index'))->assertForbidden();

    // Observed 3/4; chance (3/4 x 2/4) + (1/4 x 2/4) = 1/2; kappa (0.75 - 0.5) / 0.5 = 0.5.
    $this->actingAs($this->admin)->get(route('reviewers.index'))
        ->assertInertia(fn (Assert $page) => $page
            ->component('reviewers/Index')
            ->where('stats.agreement', ['pairs' => 4, 'agreed' => 3, 'rate' => 0.75, 'kappa' => 0.5])
            ->where('stats.reviewers', fn ($rows) => collect($rows)->firstWhere('name', 'Alice')['second_reviewed'] === 4
                && collect($rows)->firstWhere('name', 'Alice')['second_agreed'] === 3
                && collect($rows)->firstWhere('name', 'Alice')['handling_seconds_p50'] === 60));
});
