<?php

use App\Ai\Contracts\ContentClassifier;
use App\Ai\Data\Classification;
use App\Ai\Data\ClassificationInput;
use App\Ai\FakeContentClassifier;
use App\Enums\DecisionSource;
use App\Enums\Outcome;
use App\Enums\PipelineStage;
use App\Enums\StepStatus;
use App\Enums\SubmissionStatus;
use App\Enums\TaskKind;
use App\Jobs\ClassifyContent;
use App\Jobs\PrepareMedia;
use App\Models\BlockedHash;
use App\Models\PolicyVersion;
use App\Models\Submission;
use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Lottery;
use Tests\Support\Images;

beforeEach(function () {
    Storage::fake('local');
    Lottery::alwaysLose();

    // Count classifier calls, to prove the blocklist saves them.
    $this->classifier = new class implements ContentClassifier
    {
        public int $calls = 0;

        public function classify(ClassificationInput $input): Classification
        {
            $this->calls++;

            return (new FakeContentClassifier)->classify($input);
        }
    };
    $this->app->instance(ContentClassifier::class, $this->classifier);

    $this->user = User::factory()->create();
});

afterEach(fn () => Lottery::determineResultNormally());

function submit(array $data): Submission
{
    test()->actingAs(test()->user)->post(route('submissions.store'), $data)->assertRedirect();

    return Submission::query()->latest('id')->firstOrFail();
}

function stageStatuses(Submission $submission): array
{
    return $submission->steps()->get()->mapWithKeys(fn ($s) => [$s->stage->value => $s->status->value])->all();
}

test('a harmless text post runs every stage and is approved automatically', function () {
    $submission = submit(['caption' => 'Sunset over the harbour']);

    expect($submission->status)->toBe(SubmissionStatus::Approved)
        ->and($submission->decision_source)->toBe(DecisionSource::Policy)
        ->and($submission->policy_version_id)->toBe(PolicyVersion::current()->id)
        ->and(stageStatuses($submission))->toBe([
            'prepare' => 'skipped',
            'match_known' => 'skipped',
            'classify' => 'succeeded',
            'apply_policy' => 'succeeded',
        ]);
});

test('an upload is re-encoded without its GPS metadata and the original is deleted', function () {
    $upload = UploadedFile::fake()->createWithContent('holiday.jpg', Images::jpegWithGps());
    expect(exif_read_data($upload->getPathname()))->toHaveKey('GPSLatitude');

    $submission = submit(['image' => $upload, 'caption' => 'Beach day']);

    expect($submission->had_gps)->toBeTrue()
        ->and($submission->upload_path)->toBeNull()
        ->and($submission->mime)->toBe('image/jpeg')
        ->and($submission->sha256)->toBe(hash('sha256', Images::jpegWithGps()))
        ->and($submission->dhash)->toMatch('/^[0-9a-f]{16}$/')
        ->and(Storage::disk('local')->allFiles('uploads'))->toBe([]);

    $stored = Storage::disk('local')->path($submission->image_path);
    $exif = @exif_read_data($stored);
    expect(is_array($exif) ? $exif : [])->not->toHaveKey('GPSLatitude');
    Storage::disk('local')->assertExists($submission->thumbnail_path);
});

test('a large image is scaled down and PNGs stay PNG', function () {
    $submission = submit(['image' => UploadedFile::fake()->createWithContent('big.png', Images::png(3000, 1500))]);

    expect($submission->mime)->toBe('image/png')
        ->and($submission->width)->toBe(1568)
        ->and($submission->height)->toBe(784);
});

test('a confident violation is rejected automatically', function () {
    $submission = submit(['caption' => 'graphic content [violence:0.95]']);

    expect($submission->status)->toBe(SubmissionStatus::Rejected)
        ->and($submission->policy_outcome)->toBe(Outcome::Reject)
        ->and($submission->decision_reason)->toBe('violence')
        ->and($submission->tasks)->toBeEmpty();
});

test('an uncertain item goes to the review queue with a priority', function () {
    $submission = submit(['caption' => 'is this ok? [violence:0.6]']);
    $task = $submission->tasks()->sole();

    expect($submission->status)->toBe(SubmissionStatus::PendingReview)
        ->and($submission->decision_reason)->toBe('violence')
        ->and($task->kind)->toBe(TaskKind::Triage)
        ->and($task->priority)->toBe(40)
        ->and($task->isOpen())->toBeTrue();
});

test('self-harm always reaches a person, even at a high score', function () {
    $submission = submit(['caption' => 'goodbye everyone [self_harm:0.99]']);

    expect($submission->status)->toBe(SubmissionStatus::PendingReview)
        ->and($submission->tasks()->sole()->priority)->toBe(99);
});

test('a refusal never approves anything: the item goes to a person', function () {
    $submission = submit(['caption' => 'something odd [refuse]']);

    expect($submission->status)->toBe(SubmissionStatus::PendingReview)
        ->and($submission->ai_scores)->toBeNull()
        ->and($submission->ai_error)->toContain('declined')
        ->and($submission->decision_reason)->toBe('ai_unavailable')
        ->and(stageStatuses($submission)['classify'])->toBe('failed');
});

test('running out of retries on the AI stage sends the item to review', function () {
    $submission = Submission::factory()->for($this->user)->create();

    (new ClassifyContent($submission))->failed(new RuntimeException('overloaded'));

    expect($submission->refresh()->status)->toBe(SubmissionStatus::PendingReview)
        ->and($submission->ai_error)->toContain('Gave up after retries')
        ->and($submission->tasks()->sole()->kind)->toBe(TaskKind::Triage);
});

test('a re-upload of a blocked image is rejected without an AI call', function () {
    $bytes = Images::png(seed: 3);
    $first = submit(['image' => UploadedFile::fake()->createWithContent('a.png', $bytes)]);
    BlockedHash::create(['sha256' => $first->sha256, 'dhash' => $first->dhash, 'category' => 'violence', 'source_submission_id' => $first->id]);
    $this->classifier->calls = 0;

    $again = submit(['image' => UploadedFile::fake()->createWithContent('b.png', $bytes)]);

    expect($again->status)->toBe(SubmissionStatus::Rejected)
        ->and($again->decision_source)->toBe(DecisionSource::Blocklist)
        ->and($again->decision_reason)->toBe('violence')
        ->and($this->classifier->calls)->toBe(0)
        ->and(stageStatuses($again))->toMatchArray(['match_known' => 'succeeded', 'classify' => 'skipped', 'apply_policy' => 'skipped'])
        ->and($again->steps()->where('stage', PipelineStage::MatchKnown)->sole()->detail['match'])->toBe('exact');
});

test('a resized near-duplicate of a blocked image is caught too', function () {
    $first = submit(['image' => UploadedFile::fake()->createWithContent('a.png', Images::png(640, 480, seed: 5))]);
    BlockedHash::create(['sha256' => $first->sha256, 'dhash' => $first->dhash, 'source_submission_id' => $first->id]);

    $copy = submit(['image' => UploadedFile::fake()->createWithContent('copy.jpg', Images::jpeg(300, 225, seed: 5))]);
    $other = submit(['image' => UploadedFile::fake()->createWithContent('other.png', Images::png(seed: 6))]);

    expect($copy->status)->toBe(SubmissionStatus::Rejected)
        ->and($copy->decision_reason)->toBe('known_content')
        ->and($copy->steps()->where('stage', PipelineStage::MatchKnown)->sole()->detail['match'])->toBe('near_duplicate')
        ->and($other->status)->toBe(SubmissionStatus::Approved);
});

test('an upload that is not really an image is rejected by the prepare stage', function () {
    Storage::disk('local')->put('uploads/bad', 'this is not an image');
    $submission = Submission::factory()->for($this->user)->create(['upload_path' => 'uploads/bad', 'caption' => null]);

    (new PrepareMedia($submission))->handle(app(App\Support\MediaSanitizer::class), app(App\Moderation\ModerationService::class));

    expect($submission->refresh()->status)->toBe(SubmissionStatus::Rejected)
        ->and($submission->decision_reason)->toBe('unreadable_media')
        ->and($submission->steps()->sole()->status)->toBe(StepStatus::Failed);
    Storage::disk('local')->assertMissing('uploads/bad');
});

test('a sample of automated decisions is queued for a QA audit', function () {
    Lottery::alwaysWin();

    $submission = submit(['caption' => 'Lovely dog']);
    $audit = $submission->tasks()->sole();

    expect($submission->status)->toBe(SubmissionStatus::Approved)
        ->and($audit->kind)->toBe(TaskKind::Audit)
        ->and($audit->automated_outcome)->toBe(Outcome::Approve);
});

test('the upload form needs an image or a caption', function () {
    $this->actingAs($this->user)
        ->post(route('submissions.store'), ['caption' => ''])
        ->assertSessionHasErrors('caption');

    $this->actingAs($this->user)
        ->post(route('submissions.store'), ['image' => UploadedFile::fake()->create('notes.pdf', 10, 'application/pdf')])
        ->assertSessionHasErrors('image');
});
