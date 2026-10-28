<?php

use App\Enums\DecisionSource;
use App\Enums\EvaluationStatus;
use App\Enums\Outcome;
use App\Models\EvaluationRun;
use App\Models\Submission;
use App\Models\User;
use Inertia\Testing\AssertableInertia as Assert;

beforeEach(function () {
    config(['moderation.classifier' => 'fake']);
    $this->admin = User::factory()->admin()->create();
});

test('only admins can run evaluations', function () {
    $moderator = User::factory()->moderator()->create();

    $this->actingAs($moderator)->get(route('evaluations.index'))->assertForbidden();
    $this->actingAs($moderator)->post(route('evaluations.store'), ['model' => 'claude-opus-5-5', 'effort' => 'low', 'sample_size' => 20])->assertForbidden();
});

test('a run re-scores human-decided items and compares both against the people', function () {
    // The offline batch classifier scores captions by keyword, so each item's new score is known.
    $spam = Submission::factory()->scored(['spam' => 0.6])->decided(Outcome::Reject, DecisionSource::Moderator, 'spam')
        ->create(['caption' => 'Guaranteed returns, click the link']);
    $boxing = Submission::factory()->scored(['violence' => 0.95])->decided(Outcome::Approve, DecisionSource::Appeal)
        ->create(['caption' => 'Boxing night, what a fight']);
    Submission::factory()->scored()->decided(Outcome::Approve, DecisionSource::Moderator)->create(['caption' => 'A lovely day']);
    // Automated decisions have no human ground truth, so they are left out.
    Submission::factory()->scored()->decided(Outcome::Approve)->create(['caption' => 'Not in the sample']);

    $this->actingAs($this->admin)
        ->post(route('evaluations.store'), ['model' => 'claude-sonnet-5-5', 'effort' => 'medium', 'sample_size' => 50])
        ->assertRedirect();

    $run = EvaluationRun::query()->sole();

    expect($run->status)->toBe(EvaluationStatus::Completed)
        ->and($run->batch_id)->toStartWith('fakebatch_')
        ->and($run->results()->count())->toBe(3)
        ->and($run->results()->whereNull('scores')->count())->toBe(0);

    $this->actingAs($this->admin)
        ->get(route('evaluations.show', $run))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('evaluations/Show')
            ->where('run.scored', 3)
            ->where('report.scored', 3)
            // Recorded scores: spam to review, boxing wrongly rejected, the lovely day approved.
            ->where('report.current.outcomes', ['approve' => 1, 'review' => 1, 'reject' => 1])
            ->where('report.current.wrongful', 1)
            ->where('report.current.accuracy', 0.5)
            // New scores decide all three automatically, matching the people each time.
            ->where('report.candidate.model', 'claude-sonnet-5-5')
            ->where('report.candidate.outcomes', ['approve' => 2, 'review' => 0, 'reject' => 1])
            ->where('report.candidate.accuracy', 1)
            ->where('report.changed', 2)
            ->where('report.examples.0.id', fn ($id) => in_array($id, [$spam->id, $boxing->id], true))
        );
});

test('a run with nothing to compare against fails with a reason', function () {
    $this->actingAs($this->admin)
        ->post(route('evaluations.store'), ['model' => 'claude-opus-5-5', 'effort' => 'low', 'sample_size' => 20]);

    expect(EvaluationRun::query()->sole())
        ->status->toBe(EvaluationStatus::Failed)
        ->error->toContain('no human-decided items');
});

test('the form only accepts priced models, known effort levels and a sensible sample', function () {
    $this->actingAs($this->admin)
        ->post(route('evaluations.store'), ['model' => 'gpt-5', 'effort' => 'extreme', 'sample_size' => 5000])
        ->assertSessionHasErrors(['model', 'effort', 'sample_size']);
});
