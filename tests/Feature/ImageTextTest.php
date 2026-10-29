<?php

use App\Enums\Outcome;
use App\Models\Submission;
use App\Models\User;
use Database\Seeders\Demo\SampleImages;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Lottery;
use Inertia\Testing\AssertableInertia as Assert;

beforeEach(function () {
    Storage::fake('local');
    Lottery::alwaysLose();
});

afterEach(fn () => Lottery::determineResultNormally());

test('text read in an image is stored and shown to staff, not to the uploader', function () {
    $member = User::factory()->create();
    $poster = UploadedFile::fake()->createWithContent('crypto-giveaway.png', SampleImages::poster(['CRYPTO'], [0, 0, 0], [255, 255, 255]));

    $this->actingAs($member)->post(route('submissions.store'), ['image' => $poster])->assertRedirect();
    $submission = Submission::query()->latest('id')->firstOrFail();

    expect($submission->ai_image_text)->toBe('CRYPTO GIVEAWAY 500% RETURNS LINK IN BIO')
        ->and($submission->steps()->where('stage', 'classify')->first()->detail['image_text'])->toBeTrue();

    $this->actingAs(User::factory()->moderator()->create())->get(route('submissions.show', $submission))
        ->assertInertia(fn (Assert $page) => $page->where('submission.ai.image_text', 'CRYPTO GIVEAWAY 500% RETURNS LINK IN BIO'));

    $this->actingAs($member)->get(route('submissions.show', $submission))
        ->assertInertia(fn (Assert $page) => $page->missing('submission.ai'));
});

test('staff search matches captions and image text; members only search their own captions', function () {
    $member = User::factory()->create();
    $own = Submission::factory()->for($member)->create(['caption' => 'Free followers here', 'ai_image_text' => null]);
    $poster = Submission::factory()->for($member)->create(['caption' => 'Look at this', 'ai_image_text' => 'FREE FOLLOWERS LINK IN BIO']);
    Submission::factory()->create(['caption' => 'Sunset', 'ai_image_text' => null]);

    $ids = fn ($response) => collect($response->viewData('page')['props']['submissions']['data'])->pluck('id')->sort()->values()->all();

    expect($ids($this->actingAs(User::factory()->moderator()->create())->get(route('submissions.index', ['q' => 'free followers']))))
        ->toBe([$own->id, $poster->id])
        ->and($ids($this->actingAs($member)->get(route('submissions.index', ['q' => 'free followers']))))
        ->toBe([$own->id]);
});

test('deleting an image under the retention policy also deletes the text read from it', function () {
    config(['moderation.retention_days' => 30]);
    $submission = Submission::factory()->scored(['spam' => 0.95])->decided(Outcome::Reject, reason: 'spam')->withImage()->create([
        'decided_at' => now()->subDays(40),
        'ai_image_text' => 'FREE FOLLOWERS',
    ]);

    $this->artisan('moderation:prune-media')->assertSuccessful();

    expect($submission->refresh()->ai_image_text)->toBeNull();
});

test('automated decisions carry the image text into the decision log', function () {
    Submission::factory()->scored(['spam' => 0.95])->decided(Outcome::Reject, reason: 'spam')->create([
        'decided_at' => now()->subHour(),
        'ai_image_text' => 'FREE FOLLOWERS',
    ]);

    $csv = $this->actingAs(User::factory()->admin()->create())->get(route('exports.decision-log'))->streamedContent();
    $row = str_getcsv(explode("\n", trim($csv))[1]);

    expect($row[6])->toBe('Image text: FREE FOLLOWERS');
});
