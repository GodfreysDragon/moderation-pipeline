<?php

namespace Database\Seeders;

use App\Enums\Category;
use App\Enums\Decision;
use App\Enums\DecisionSource;
use App\Enums\Outcome;
use App\Enums\PipelineStage;
use App\Enums\StepStatus;
use App\Enums\SubmissionStatus;
use App\Enums\TaskKind;
use App\Models\BlockedHash;
use App\Models\PipelineStep;
use App\Models\PolicyVersion;
use App\Models\ReviewTask;
use App\Models\Submission;
use App\Models\User;
use App\Moderation\PolicyEngine;
use App\Notifications\EscalationOpened;
use App\Notifications\SubmissionDecided;
use App\Support\MediaSanitizer;
use App\Support\SanitizedImage;
use Carbon\CarbonImmutable;
use Database\Seeders\Demo\SampleImages;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Storage;

/**
 * Two weeks of believable moderation history, so the dashboard, queue and
 * policy backtest have something to show. Decisions are made with the real
 * PolicyEngine against the policy version in force at the time.
 */
class DatabaseSeeder extends Seeder
{
    private const DAYS = 14;

    /** @var array<string, SanitizedImage> */
    private array $images = [];

    /** @var list<User> */
    private array $members = [];

    /** @var list<User> */
    private array $moderators = [];

    private User $admin;

    private PolicyEngine $engine;

    /** When set, the next submissions use this profile with mid-range (ambiguous) scores. */
    private ?string $backlogProfile = null;

    /**
     * Captions by profile. Violating examples are deliberately mild and synthetic.
     *
     * @var array<string, list<string>>
     */
    private const CAPTIONS = [
        'clean' => [
            'Morning coffee before the market opens', 'First attempt at sourdough, not bad!', 'Trail run views this weekend',
            'Our dog discovered the sprinkler', 'Rainy day at the harbour', 'New mural downtown, love the colours',
            'Garden tomatoes finally ripening', 'Throwback to last summer’s road trip', 'Finished the bookshelf build',
            'Sunset from the rooftop', 'Science fair volcano: success', 'Night market haul', 'Fog rolling over the hills',
            'Practising watercolour landscapes', 'Ferry ride home', 'Cousin’s wedding was beautiful',
        ],
        'spam' => [
            'Crypto giveaway, click the link in bio for guaranteed returns', 'Free followers! DM me for the link',
            'Earn $5k a week from home, DM me', 'Limited giveaway, click the link before it ends',
        ],
        'violence' => [
            'Aftermath of the crash on the highway, blood everywhere', 'Street fight outside the club last night',
            'Hunting trip, warning: graphic', 'Boxing night highlights',
        ],
        'harassment' => [
            '@sam you are such a loser, everyone knows it', 'Found her home address, maybe someone should visit',
            'Imagine being this much of an idiot', 'Tagging the guy who cut me off. Loser.',
        ],
        'hate' => ['People like them are vermin, honestly', 'Go back to where you came from'],
        'self_harm' => ['Some days I just want to die', 'Not sure why I keep trying anymore', 'Day 30 without self-harm, proud of myself'],
        'illegal_goods' => ['Unregistered parts for sale, DM to buy', 'Selling fake ID cards, any state', 'Party supplies this weekend, DM to buy'],
        'sexual' => ['Lingerie shoot behind the scenes', 'NSFW link in my bio'],
    ];

    public function run(): void
    {
        mt_srand(2026);
        Model::unguard();
        $this->engine = new PolicyEngine;

        $this->createUsers();
        $this->publishSamples();
        [$v1, $v2] = $this->createPolicies();
        $blocked = $this->seedBlocklist();

        $now = CarbonImmutable::now();
        $start = $now->startOfDay()->subDays(self::DAYS - 1);

        for ($day = 0; $day < self::DAYS; $day++) {
            $dayStart = $start->addDays($day);
            $isToday = $day === self::DAYS - 1;
            $count = $isToday ? 8 + (int) ($dayStart->diffInHours($now) * 1.2) : mt_rand(20, 32);
            $span = $isToday ? max(3600, $dayStart->diffInSeconds($now) - 60) : 86_000;

            for ($i = 0; $i < $count; $i++) {
                $createdAt = $dayStart->addSeconds(mt_rand(0, (int) $span));
                $policy = $createdAt->lt($v2->created_at) ? $v1 : $v2;
                $this->seedSubmission($createdAt, $policy, $now, $blocked);
            }
        }

        // A recent backlog of genuinely ambiguous items, so the queue has work in it.
        foreach (['harassment', 'violence', 'illegal_goods', 'spam', 'self_harm', 'hate', 'violence', 'harassment', 'sexual', 'illegal_goods'] as $profile) {
            $this->backlogProfile = $profile;
            $this->seedSubmission($now->subMinutes(mt_rand(4, 170)), $v2, $now, $blocked);
        }

        $this->backlogProfile = null;

        // One of them has already been handed up to an admin.
        $triage = ReviewTask::query()->open()->where('kind', TaskKind::Triage)->latest('priority')->first();

        if ($triage) {
            $note = 'Looks like a real threat against a named person. Admin call, please.';
            $triage->update([
                'claimed_by' => $this->moderators[1]->id, 'claimed_at' => $now->subMinutes(3),
                'decision' => Decision::Escalate, 'decision_note' => $note,
                'resolved_by' => $this->moderators[1]->id, 'resolved_at' => $now->subMinutes(2),
            ]);
            ReviewTask::create([
                'submission_id' => $triage->submission_id, 'kind' => TaskKind::Escalation,
                'priority' => $triage->priority + 30, 'automated_outcome' => Outcome::Review, 'note' => $note,
                'created_at' => $now->subMinutes(2), 'updated_at' => $now->subMinutes(2),
            ]);
        }

        // Flag tasks that show a graphic image, as ModerationService::openTask does.
        ReviewTask::query()->with('submission')->each(
            fn (ReviewTask $task) => $task->updateQuietly(['graphic' => $task->submission->isGraphic()]),
        );

        $this->seedNotifications();

        Model::reguard();
        mt_srand();
    }

    /**
     * A few bell notifications, as ModerationService would have sent them (in-app only:
     * the seeder bypasses the service, and demo data shouldn't send email).
     */
    private function seedNotifications(): void
    {
        $member = $this->members[0];

        Submission::query()->whereBelongsTo($member)->where('status', SubmissionStatus::Rejected)->latest('decided_at')->limit(3)->get()
            ->each(fn (Submission $s) => $member->notifyNow(new SubmissionDecided($s), ['database']));
        $member->notifications()->latest()->skip(1)->first()?->markAsRead();

        $escalation = ReviewTask::query()->open()->where('kind', TaskKind::Escalation)->first();

        if ($escalation) {
            $this->admin->notifyNow(new EscalationOpened($escalation), ['database']);
        }
    }

    private function createUsers(): void
    {
        $this->admin = User::factory()->admin()->create(['name' => 'Morgan Admin', 'email' => 'admin@example.com']);
        $this->moderators = [
            User::factory()->moderator()->create(['name' => 'Alex Moderator', 'email' => 'moderator@example.com']),
            User::factory()->moderator()->create(['name' => 'Priya Shah', 'email' => 'priya@example.com']),
            User::factory()->moderator()->create(['name' => 'Tomás Rivera', 'email' => 'tomas@example.com']),
        ];
        $this->members = [
            User::factory()->create(['name' => 'Jamie Member', 'email' => 'member@example.com']),
            ...User::factory(14)->create()->all(),
        ];
    }

    /**
     * Write the Submit page's sample images to public/samples.
     */
    private function publishSamples(): void
    {
        $dir = public_path('samples');

        if (! is_dir($dir)) {
            mkdir($dir, 0755, true);
        }

        foreach (SampleImages::uploadSamples() as $name => $bytes) {
            file_put_contents("{$dir}/{$name}", $bytes);
        }
    }

    /**
     * @return array{PolicyVersion, PolicyVersion}
     */
    private function createPolicies(): array
    {
        $thresholds = PolicyVersion::defaultThresholds();
        $older = $thresholds;
        $older['spam']['reject'] = 0.9;

        $v1 = PolicyVersion::create([
            'thresholds' => $older,
            'audit_sample_rate' => 0.05,
            'blocklist_distance' => 6,
            'note' => 'Initial policy',
            'created_by' => $this->admin->id,
            'created_at' => now()->subDays(30),
        ]);

        $v2 = PolicyVersion::create([
            'thresholds' => $thresholds,
            'audit_sample_rate' => 0.05,
            'blocklist_distance' => 6,
            'note' => 'Auto-reject spam from 0.80: audits found no false positives above it',
            'created_by' => $this->admin->id,
            'created_at' => now()->subDays(6)->setTime(10, 0),
        ]);

        return [$v1, $v2];
    }

    /**
     * The "known meme" sample was removed by a moderator before the demo window.
     */
    private function seedBlocklist(): SanitizedImage
    {
        $image = $this->sanitized('known-bad', fn () => SampleImages::knownBad());

        $source = Submission::create([
            'user_id' => $this->members[3]->id,
            'caption' => 'lol look at this',
            'original_filename' => 'meme.png',
            'status' => SubmissionStatus::Rejected,
            'policy_outcome' => Outcome::Review,
            'decision_source' => DecisionSource::Moderator,
            'decision_reason' => Category::Harassment->value,
            'decided_by' => $this->moderators[0]->id,
            'decided_at' => now()->subDays(20),
            'created_at' => now()->subDays(20),
            'updated_at' => now()->subDays(20),
        ]);
        $this->attachImage($source, $image);

        BlockedHash::create([
            'sha256' => $image->sha256,
            'dhash' => $image->dhash,
            'category' => Category::Harassment,
            'source_submission_id' => $source->id,
            'added_by' => $this->moderators[0]->id,
            'created_at' => now()->subDays(20),
        ]);

        return $image;
    }

    private function seedSubmission(CarbonImmutable $createdAt, PolicyVersion $policy, CarbonImmutable $now, SanitizedImage $blocked): void
    {
        $profile = $this->pickProfile();
        $hasImage = $profile === 'blocklist' || mt_rand(1, 100) <= 60;
        $caption = $profile === 'blocklist' ? 'Reposting this classic' : $this->pick(self::CAPTIONS[$profile]);

        $submission = Submission::create([
            'user_id' => $this->pick($this->members)->id,
            'caption' => mt_rand(1, 100) <= 8 && $hasImage ? null : $caption,
            'original_filename' => $hasImage ? 'IMG_'.mt_rand(1000, 9999).'.jpg' : null,
            'status' => SubmissionStatus::Processing,
            'created_at' => $createdAt,
            'updated_at' => $createdAt,
        ]);

        $clock = $createdAt->addMilliseconds(mt_rand(300, 1500));

        // Stage 1: prepare
        if ($hasImage) {
            $image = $profile === 'blocklist' ? $blocked : $this->imageFor($profile);
            $this->attachImage($submission, $image, mt_rand(1, 100) <= 15);
            $clock = $this->step($submission, PipelineStage::Prepare, StepStatus::Succeeded, $clock, mt_rand(40, 180), [
                'width' => $image->width, 'height' => $image->height, 'bytes' => strlen($image->bytes),
                'metadata_stripped' => true, 'had_gps' => $submission->had_gps, 'dhash' => $image->dhash,
            ]);
        } else {
            $clock = $this->step($submission, PipelineStage::Prepare, StepStatus::Skipped, $clock, 1, ['reason' => 'text_only']);
        }

        // Stage 2: known content
        if ($profile === 'blocklist') {
            $clock = $this->step($submission, PipelineStage::MatchKnown, StepStatus::Succeeded, $clock, mt_rand(2, 9), [
                'match' => 'exact', 'closest_distance' => 0, 'max_distance' => $policy->blocklist_distance, 'hashes_checked' => 1,
            ]);
            $this->step($submission, PipelineStage::Classify, StepStatus::Skipped, $clock, 1, ['reason' => 'already_decided']);
            $this->step($submission, PipelineStage::ApplyPolicy, StepStatus::Skipped, $clock, 1, ['reason' => 'already_decided']);
            $submission->update([
                'status' => SubmissionStatus::Rejected, 'decision_source' => DecisionSource::Blocklist,
                'decision_reason' => Category::Harassment->value, 'decided_at' => $clock, 'updated_at' => $clock,
            ]);

            return;
        }

        $clock = $hasImage
            ? $this->step($submission, PipelineStage::MatchKnown, StepStatus::Succeeded, $clock, mt_rand(2, 12), [
                'match' => 'none', 'closest_distance' => mt_rand(18, 40), 'max_distance' => $policy->blocklist_distance, 'hashes_checked' => 1,
            ])
            : $this->step($submission, PipelineStage::MatchKnown, StepStatus::Skipped, $clock, 1, ['reason' => 'text_only']);

        // Stage 3: classify (occasionally a retry, rarely a permanent failure)
        $failed = mt_rand(1, 1000) <= 15;

        if (! $failed && mt_rand(1, 100) <= 3) {
            $clock = $this->step($submission, PipelineStage::Classify, StepStatus::Failed, $clock, mt_rand(400, 1200), ['retrying' => true], 'Overloaded (529). Retrying with backoff.')
                ->addSeconds(15);
        }

        if ($failed) {
            $clock = $this->step($submission, PipelineStage::Classify, StepStatus::Failed, $clock, mt_rand(1500, 4000), ['retrying' => false, 'refused' => true], 'The classifier declined this item.');
            $submission->update(['ai_error' => 'The classifier declined this item.']);
            $scores = null;
        } else {
            $scores = $this->scoresFor($profile);
            $top = collect($scores)->sortDesc();
            $clock = $this->step($submission, PipelineStage::Classify, StepStatus::Succeeded, $clock, mt_rand(1800, 7000), [
                'model' => 'claude-opus-5-5', 'top_category' => $top->keys()->first(), 'top_score' => $top->first(),
            ], attempt: $submission->steps()->count() > 2 ? 2 : 1);
            $submission->update([
                'ai_scores' => collect($scores)->map(fn ($s) => ['score' => $s, 'evidence' => $s >= 0.3 ? $this->evidence($profile) : 'none'])->all(),
                'ai_summary' => $hasImage ? 'A photo with the caption shown.' : 'A text-only post.',
                'ai_image_text' => $hasImage ? $this->imageText($profile) : null,
                'ai_model' => 'claude-opus-5-5',
                ...$this->tokenUsage($submission->id, $hasImage),
            ]);
        }

        // Stage 4: policy
        $result = $this->engine->evaluate($scores, $policy->thresholds);
        $audit = $result->outcome !== Outcome::Review && mt_rand(1, 100) <= 6;
        $clock = $this->step($submission, PipelineStage::ApplyPolicy, StepStatus::Succeeded, $clock, mt_rand(3, 15), [
            'policy_version' => $policy->id, 'outcome' => $result->outcome->value, 'reason' => $result->reason,
            'priority' => $result->priority, 'triggered' => $result->triggered, 'audit_sampled' => $audit,
        ]);

        $submission->update(['policy_outcome' => $result->outcome, 'policy_version_id' => $policy->id]);
        $age = (int) $createdAt->diffInMinutes($now);

        if ($result->outcome === Outcome::Review) {
            $submission->update(['status' => SubmissionStatus::PendingReview, 'decision_reason' => $result->reason]);
            $this->humanReview($submission, $result->priority, $clock, $now, $age, $scores);

            return;
        }

        $submission->update([
            'status' => $result->outcome->status(), 'decision_source' => DecisionSource::Policy,
            'decision_reason' => $result->reason, 'decided_at' => $clock, 'updated_at' => $clock,
        ]);

        if ($audit) {
            $this->audit($submission, $result->outcome, $result->priority, $clock, $now);
        }

        if ($result->outcome === Outcome::Reject) {
            $this->maybeAppeal($submission, $clock, $now);
        }
    }

    /**
     * @param  array<string, float>|null  $scores
     */
    private function humanReview(Submission $submission, int $priority, CarbonImmutable $queuedAt, CarbonImmutable $now, int $age, ?array $scores): void
    {
        $task = ReviewTask::create([
            'submission_id' => $submission->id,
            'kind' => TaskKind::Triage,
            'priority' => $priority,
            'automated_outcome' => Outcome::Review,
            'created_at' => $queuedAt,
            'updated_at' => $queuedAt,
        ]);

        // Recent items are still waiting.
        if ($age < mt_rand(360, 600)) {
            return;
        }

        $moderator = $this->pick($this->moderators);
        $claimedAt = $queuedAt->addMinutes(mt_rand(3, min(240, max(4, $age - 30))));
        $resolvedAt = $claimedAt->addSeconds(mt_rand(25, 240));
        $top = $scores ? max($scores) : 0.5;

        if (mt_rand(1, 100) <= 4) {
            $task->update([
                'claimed_by' => $moderator->id, 'claimed_at' => $claimedAt, 'decision' => Decision::Escalate,
                'decision_note' => 'Context unclear, could be news footage. Second opinion please.',
                'resolved_by' => $moderator->id, 'resolved_at' => $resolvedAt,
            ]);
            $task = ReviewTask::create([
                'submission_id' => $submission->id, 'kind' => TaskKind::Escalation, 'priority' => $priority + 30,
                'automated_outcome' => Outcome::Review, 'note' => $task->decision_note,
                'created_at' => $resolvedAt, 'updated_at' => $resolvedAt,
            ]);
            $moderator = $this->admin;
            $claimedAt = $resolvedAt->addMinutes(mt_rand(10, 120));
            $resolvedAt = $claimedAt->addSeconds(mt_rand(60, 400));

            if ($resolvedAt->gt($now)) {
                return;
            }
        }

        $reject = mt_rand(1, 100) <= (int) round($top * 100);
        $reason = $reject ? ($submission->topCategory()->value ?? 'other') : null;

        $task->update([
            'claimed_by' => $moderator->id, 'claimed_at' => $claimedAt,
            'decision' => $reject ? Decision::Reject : Decision::Approve, 'reason' => $reason,
            'resolved_by' => $moderator->id, 'resolved_at' => $resolvedAt, 'updated_at' => $resolvedAt,
        ]);

        $submission->update([
            'status' => $reject ? SubmissionStatus::Rejected : SubmissionStatus::Approved,
            'decision_source' => DecisionSource::Moderator, 'decision_reason' => $reason,
            'decided_by' => $moderator->id, 'decided_at' => $resolvedAt, 'updated_at' => $resolvedAt,
        ]);

        $this->maybeSecondReview($task, $submission, $moderator, $resolvedAt, $now);

        if ($reject) {
            $this->maybeAppeal($submission, $resolvedAt, $now);
        }
    }

    /**
     * About one triage decision in seven gets a blind second review by a colleague,
     * agreeing about four times in five; a disagreement goes to the admin, who sides
     * with one of them. Chosen from the id, so the random stream is untouched.
     */
    private function maybeSecondReview(ReviewTask $first, Submission $submission, User $firstReviewer, CarbonImmutable $resolvedAt, CarbonImmutable $now): void
    {
        if ($first->kind !== TaskKind::Triage || $submission->id % 7 !== 0) {
            return;
        }

        $others = array_values(array_filter($this->moderators, fn (User $m) => $m->id !== $firstReviewer->id));
        $second = $others[$submission->id % count($others)];
        $claimedAt = $resolvedAt->addMinutes(20 + $submission->id % 90);
        $doneAt = $claimedAt->addSeconds(40 + $submission->id % 150);

        if ($doneAt->gt($now)) {
            return;
        }

        $agrees = $submission->id % 5 !== 0;
        $decision = $agrees ? $first->decision : ($first->decision === Decision::Reject ? Decision::Approve : Decision::Reject);

        ReviewTask::create([
            'submission_id' => $submission->id, 'kind' => TaskKind::SecondReview, 'priority' => $first->priority - 20,
            'automated_outcome' => Outcome::Review, 'excluded_reviewer_id' => $firstReviewer->id,
            'reviews_task_id' => $first->id, 'claimed_by' => $second->id, 'claimed_at' => $claimedAt,
            'decision' => $decision, 'reason' => $decision === Decision::Reject ? $first->reason ?? 'other' : null,
            'resolved_by' => $second->id, 'resolved_at' => $doneAt, 'created_at' => $resolvedAt, 'updated_at' => $doneAt,
        ]);

        if (! $agrees) {
            $settledAt = $doneAt->addMinutes(30 + $submission->id % 60);

            ReviewTask::create([
                'submission_id' => $submission->id, 'kind' => TaskKind::Escalation, 'priority' => $first->priority + 30,
                'automated_outcome' => Outcome::Review,
                'note' => "Two reviewers disagreed: {$firstReviewer->name} chose to {$first->decision?->value}, {$second->name} chose to {$decision->value}.",
                'created_at' => $doneAt, 'updated_at' => $doneAt,
                // Recent disagreements are still waiting for the admin.
                ...($settledAt->lt($now) ? [
                    'claimed_by' => $this->admin->id, 'claimed_at' => $settledAt->subSeconds(90),
                    'decision' => $first->decision, 'reason' => $first->reason,
                    'resolved_by' => $this->admin->id, 'resolved_at' => $settledAt, 'updated_at' => $settledAt,
                ] : []),
            ]);
        }
    }

    private function audit(Submission $submission, Outcome $automated, int $priority, CarbonImmutable $decidedAt, CarbonImmutable $now): void
    {
        $task = ReviewTask::create([
            'submission_id' => $submission->id, 'kind' => TaskKind::Audit, 'priority' => $priority - 20,
            'automated_outcome' => $automated, 'created_at' => $decidedAt, 'updated_at' => $decidedAt,
        ]);

        if ($decidedAt->diffInMinutes($now) < 720) {
            return;
        }

        $moderator = $this->pick($this->moderators);
        $resolvedAt = $decidedAt->addMinutes(mt_rand(30, 600));
        $agrees = mt_rand(1, 100) <= ($automated === Outcome::Approve ? 97 : 92);
        $decision = $agrees === ($automated === Outcome::Approve) ? Decision::Approve : Decision::Reject;
        $reason = $decision === Decision::Reject ? ($submission->topCategory()->value ?? 'other') : null;

        $task->update([
            'claimed_by' => $moderator->id, 'claimed_at' => $resolvedAt->subSeconds(mt_rand(20, 120)),
            'decision' => $decision, 'reason' => $reason,
            'resolved_by' => $moderator->id, 'resolved_at' => $resolvedAt, 'updated_at' => $resolvedAt,
        ]);

        if (! $agrees) {
            $submission->update([
                'status' => $decision->outcome()->status(), 'decision_source' => DecisionSource::Audit,
                'decision_reason' => $reason, 'decided_by' => $moderator->id, 'decided_at' => $resolvedAt,
            ]);
        }
    }

    private function maybeAppeal(Submission $submission, CarbonImmutable $decidedAt, CarbonImmutable $now): void
    {
        if (mt_rand(1, 100) > 9) {
            return;
        }

        $appealedAt = $decidedAt->addMinutes(mt_rand(20, 1200));

        if ($appealedAt->gt($now)) {
            return;
        }

        $previous = $submission->decided_by;
        $submission->update([
            'appeal_message' => $this->pick([
                'This is from a news report, not something I support.',
                'It is my own small business, not spam.',
                'You misread the context, this was a joke between friends.',
                'Please look again, nothing in the photo breaks the rules.',
            ]),
            'appealed_at' => $appealedAt,
            'status' => SubmissionStatus::PendingReview,
        ]);

        $task = ReviewTask::create([
            'submission_id' => $submission->id, 'kind' => TaskKind::Appeal,
            'priority' => (int) round(max([0, ...array_values($submission->scores())]) * 100) + 20,
            'automated_outcome' => Outcome::Reject, 'excluded_reviewer_id' => $previous,
            'created_at' => $appealedAt, 'updated_at' => $appealedAt,
        ]);

        if ($appealedAt->diffInHours($now) < 30) {
            return;
        }

        $moderator = $this->pick(array_values(array_filter($this->moderators, fn (User $m) => $m->id !== $previous)));
        $resolvedAt = $appealedAt->addMinutes(mt_rand(30, 900));
        $overturn = mt_rand(1, 100) <= 30;

        $task->update([
            'claimed_by' => $moderator->id, 'claimed_at' => $resolvedAt->subMinutes(2),
            'decision' => $overturn ? Decision::Approve : Decision::Reject,
            'reason' => $overturn ? null : $submission->decision_reason,
            'resolved_by' => $moderator->id, 'resolved_at' => $resolvedAt,
        ]);

        $submission->update([
            'status' => $overturn ? SubmissionStatus::Approved : SubmissionStatus::Rejected,
            'decision_source' => DecisionSource::Appeal, 'decided_by' => $moderator->id, 'decided_at' => $resolvedAt,
            'decision_reason' => $overturn ? null : $submission->decision_reason,
        ]);
    }

    private function pickProfile(): string
    {
        if ($this->backlogProfile !== null) {
            return $this->backlogProfile;
        }

        $roll = mt_rand(1, 1000);

        return match (true) {
            $roll <= 20 => 'blocklist',
            $roll <= 690 => 'clean',
            $roll <= 790 => 'spam',
            $roll <= 840 => 'violence',
            $roll <= 890 => 'harassment',
            $roll <= 915 => 'hate',
            $roll <= 940 => 'self_harm',
            $roll <= 975 => 'illegal_goods',
            default => 'sexual',
        };
    }

    /**
     * @return array<string, float>
     */
    private function scoresFor(string $profile): array
    {
        $scores = [];

        foreach (Category::cases() as $category) {
            $scores[$category->value] = round(mt_rand(0, 12) / 100, 2);
        }

        if ($this->backlogProfile !== null) {
            $limits = Category::from($profile)->defaultThresholds();
            $scores[$profile] = round($limits['review'] + (mt_rand(5, 30) / 100), 2);
        } elseif ($profile !== 'clean') {
            // Mostly clear-cut, with a long tail of ambiguous cases.
            $scores[$profile] = round(mt_rand(1, 100) <= 55 ? mt_rand(78, 99) / 100 : mt_rand(30, 80) / 100, 2);
        } elseif (mt_rand(1, 100) <= 12) {
            // Some harmless posts still look a bit like something.
            $category = $this->pick(Category::cases())->value;
            $scores[$category] = round(mt_rand(25, 55) / 100, 2);
        }

        return $scores;
    }

    /**
     * Token counts shaped like live claude-opus-5-5 calls: a 2,093-token cached
     * prefix (instructions and schema), about 40-60 fresh tokens of caption, about
     * 640 more for an image, and 190-310 output. Roughly one call in 25 finds the
     * cache expired and writes it again. Derived from the id so the random stream,
     * and with it the rest of the demo data, stays the same.
     *
     * @return array{ai_input_tokens: int, ai_cache_read_tokens: int, ai_cache_write_tokens: int, ai_output_tokens: int}
     */
    private function tokenUsage(int $id, bool $hasImage): array
    {
        $prefix = 2093;
        $written = $id % 25 === 0;

        return [
            'ai_input_tokens' => 40 + $id * 7 % 21 + ($hasImage ? 636 : 0),
            'ai_cache_read_tokens' => $written ? 0 : $prefix,
            'ai_cache_write_tokens' => $written ? $prefix : 0,
            'ai_output_tokens' => 190 + $id * 37 % 121,
        ];
    }

    private function evidence(string $profile): string
    {
        return match ($profile) {
            'spam' => 'Promises returns and pushes a link.',
            'violence' => 'Describes injury or a fight.',
            'harassment' => 'Insults or targets a named person.',
            'hate' => 'Dehumanising language about a group.',
            'self_harm' => 'Expresses a wish to die or self-injure.',
            'illegal_goods' => 'Offers restricted items for sale.',
            'sexual' => 'Sexually suggestive content.',
            default => 'Some resemblance to the category.',
        };
    }

    /**
     * The words drawn on the poster that imageFor() picks for a profile.
     */
    private function imageText(string $profile): ?string
    {
        return match ($profile) {
            'spam' => 'FREE FOLLOWERS LINK IN BIO',
            'illegal_goods' => 'FOR SALE DM TO BUY',
            default => null,
        };
    }

    private function imageFor(string $profile): SanitizedImage
    {
        return match ($profile) {
            'spam' => $this->sanitized('spam', fn () => SampleImages::poster(['FREE', 'FOLLOWERS', 'LINK IN BIO'], [17, 17, 17], [250, 190, 40])),
            'illegal_goods' => $this->sanitized('goods', fn () => SampleImages::poster(['FOR SALE', 'DM TO BUY'], [90, 20, 20], [255, 255, 255])),
            default => $this->sanitized('scene-'.($n = mt_rand(1, 18)), fn () => SampleImages::scene($n * 7)),
        };
    }

    /**
     * Run each distinct image through the real sanitizer once.
     *
     * @param  callable(): string  $make
     */
    private function sanitized(string $key, callable $make): SanitizedImage
    {
        return $this->images[$key] ??= app(MediaSanitizer::class)->sanitize($make());
    }

    private function attachImage(Submission $submission, SanitizedImage $image, bool $hadGps = false): void
    {
        $disk = Storage::disk(config('moderation.disk'));
        $base = "submissions/{$submission->id}";
        $disk->put("{$base}/image.{$image->extension}", $image->bytes);
        $disk->put("{$base}/thumb.{$image->extension}", $image->thumbnailBytes);

        $submission->update([
            'image_path' => "{$base}/image.{$image->extension}",
            'thumbnail_path' => "{$base}/thumb.{$image->extension}",
            'mime' => $image->mime,
            'width' => $image->width,
            'height' => $image->height,
            'bytes' => strlen($image->bytes),
            'had_gps' => $hadGps,
            'sha256' => $image->sha256,
            'dhash' => $image->dhash,
        ]);
    }

    /**
     * @param  array<string, mixed>  $detail
     */
    private function step(Submission $submission, PipelineStage $stage, StepStatus $status, CarbonImmutable $at, int $ms, array $detail = [], ?string $error = null, int $attempt = 1): CarbonImmutable
    {
        $finished = $at->addMilliseconds($ms);

        PipelineStep::create([
            'submission_id' => $submission->id,
            'stage' => $stage,
            'status' => $status,
            'attempt' => $attempt,
            'detail' => $detail,
            'error' => $error,
            'started_at' => $at,
            'finished_at' => $finished,
            'duration_ms' => $ms,
        ]);

        return $finished->addMilliseconds(mt_rand(5, 400));
    }

    /**
     * @template T
     *
     * @param  array<int, T>  $items
     * @return T
     */
    private function pick(array $items): mixed
    {
        return $items[array_rand($items)];
    }
}
