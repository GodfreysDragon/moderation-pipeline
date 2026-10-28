<?php

use App\Enums\Decision;
use App\Enums\DecisionSource;
use App\Enums\Outcome;
use App\Enums\TaskKind;
use App\Models\BlockedHash;
use App\Models\ReviewTask;
use App\Models\Submission;
use App\Models\User;
use Illuminate\Support\Facades\Storage;

beforeEach(function () {
    Storage::fake('local');
    config(['moderation.retention_days' => 30, 'moderation.appeal_window_days' => 14]);
});

function rejectedWithImage(int $daysAgo): Submission
{
    $submission = Submission::factory()->scored(['spam' => 0.95])->decided(Outcome::Reject, reason: 'spam')->withImage()->create([
        'decided_at' => now()->subDays($daysAgo),
    ]);
    $submission->update([
        'image_path' => "submissions/{$submission->id}/image.png",
        'thumbnail_path' => "submissions/{$submission->id}/thumb.png",
    ]);
    Storage::disk('local')->put($submission->image_path, 'image');
    Storage::disk('local')->put($submission->thumbnail_path, 'thumb');

    return $submission;
}

test('rejected images past the retention period are deleted, keeping their hashes', function () {
    $old = rejectedWithImage(40);
    $recent = rejectedWithImage(10);
    $approved = Submission::factory()->decided(Outcome::Approve)->withImage()->create(['decided_at' => now()->subDays(90)]);
    $underReview = rejectedWithImage(60);
    ReviewTask::factory()->for($underReview)->kind(TaskKind::Audit, Outcome::Reject)->create();
    BlockedHash::create(['sha256' => $old->sha256, 'dhash' => $old->dhash]);

    $this->artisan('moderation:prune-media --dry-run')->expectsOutputToContain('1 rejected submissions')->assertSuccessful();
    Storage::disk('local')->assertExists($old->image_path);

    $this->artisan('moderation:prune-media')->assertSuccessful();

    $old->refresh();
    expect($old->image_path)->toBeNull()
        ->and($old->thumbnail_path)->toBeNull()
        ->and($old->media_pruned_at)->not->toBeNull()
        ->and($old->sha256)->not->toBeNull()
        ->and(BlockedHash::query()->where('sha256', $old->sha256)->exists())->toBeTrue()
        ->and($recent->refresh()->image_path)->not->toBeNull()
        ->and($approved->refresh()->image_path)->not->toBeNull()
        ->and($underReview->refresh()->image_path)->not->toBeNull();

    Storage::disk('local')->assertMissing("submissions/{$old->id}/image.png");
    Storage::disk('local')->assertMissing("submissions/{$old->id}/thumb.png");
    Storage::disk('local')->assertExists($recent->image_path);
});

test('the retention period never undercuts the appeal window', function () {
    $insideWindow = rejectedWithImage(10);

    $this->artisan('moderation:prune-media --days=1')->assertSuccessful();

    expect($insideWindow->refresh()->image_path)->not->toBeNull();
});

test('admins can download the decision log as CSV, oldest first', function () {
    $moderator = User::factory()->moderator()->create(['name' => 'Alice']);
    $auto = Submission::factory()->scored(['spam' => 0.95])->decided(Outcome::Reject, reason: 'spam')->create(['decided_at' => now()->subHours(3)]);
    $human = Submission::factory()->scored(['hate' => 0.6])->decided(Outcome::Reject, DecisionSource::Moderator, 'hate')->create();
    ReviewTask::factory()->for($human)->create([
        'decision' => Decision::Reject, 'reason' => 'hate', 'decision_note' => 'Slur, "clearly".', 'resolved_by' => $moderator->id, 'resolved_at' => now()->subHour(),
    ]);

    $this->actingAs($moderator)->get(route('exports.decision-log'))->assertForbidden();

    $csv = $this->actingAs(User::factory()->admin()->create())
        ->get(route('exports.decision-log', ['days' => 7]))
        ->assertOk()
        ->assertHeader('content-type', 'text/csv; charset=UTF-8')
        ->streamedContent();

    $lines = array_map('str_getcsv', array_filter(explode("\n", $csv)));

    expect($lines[0])->toBe(['occurred_at', 'submission_id', 'task_id', 'actor', 'action', 'reason', 'note', 'policy_version', 'model'])
        ->and($lines[1][1])->toBe((string) $auto->id)
        ->and(array_slice($lines[1], 3, 3))->toBe(['system', 'auto_reject', 'spam'])
        ->and(array_slice($lines[2], 3, 4))->toBe(['Alice', 'triage_reject', 'hate', 'Slur, "clearly".']);
});
