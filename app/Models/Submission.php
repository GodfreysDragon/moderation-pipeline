<?php

namespace App\Models;

use App\Ai\Data\TokenUsage;
use App\Enums\Category;
use App\Enums\DecisionSource;
use App\Enums\Outcome;
use App\Enums\SubmissionStatus;
use App\Observers\SubmissionObserver;
use Database\Factories\SubmissionFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\ObservedBy;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property int $user_id
 * @property string|null $caption
 * @property string|null $upload_path
 * @property string|null $original_filename
 * @property string|null $external_ref
 * @property string|null $image_path
 * @property string|null $thumbnail_path
 * @property string|null $mime
 * @property int|null $width
 * @property int|null $height
 * @property int|null $bytes
 * @property bool $had_gps
 * @property string|null $sha256
 * @property string|null $dhash
 * @property Carbon|null $media_pruned_at
 * @property array<string, array{score: float, evidence: string}>|null $ai_scores
 * @property string|null $ai_summary
 * @property string|null $ai_image_text
 * @property string|null $ai_model
 * @property string|null $ai_error
 * @property int|null $ai_input_tokens
 * @property int|null $ai_cache_read_tokens
 * @property int|null $ai_cache_write_tokens
 * @property int|null $ai_output_tokens
 * @property SubmissionStatus $status
 * @property Outcome|null $policy_outcome
 * @property int|null $policy_version_id
 * @property DecisionSource|null $decision_source
 * @property string|null $decision_reason
 * @property int|null $decided_by
 * @property Carbon|null $decided_at
 * @property string|null $appeal_message
 * @property Carbon|null $appealed_at
 * @property Carbon $created_at
 * @property Carbon $updated_at
 * @property-read User $user
 * @property-read User|null $decider
 * @property-read PolicyVersion|null $policyVersion
 */
#[ObservedBy(SubmissionObserver::class)]
#[Fillable([
    'user_id', 'caption', 'upload_path', 'original_filename', 'external_ref', 'image_path', 'thumbnail_path', 'mime', 'width',
    'height', 'bytes', 'had_gps', 'sha256', 'dhash', 'media_pruned_at', 'ai_scores', 'ai_summary', 'ai_image_text', 'ai_model',
    'ai_error', 'ai_input_tokens', 'ai_cache_read_tokens', 'ai_cache_write_tokens', 'ai_output_tokens', 'status',
    'policy_outcome', 'policy_version_id', 'decision_source', 'decision_reason', 'decided_by', 'decided_at',
    'appeal_message', 'appealed_at',
])]
class Submission extends Model
{
    /** @use HasFactory<SubmissionFactory> */
    use HasFactory;

    protected $attributes = [
        'status' => 'processing',
        'had_gps' => false,
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'had_gps' => 'boolean',
            'ai_scores' => 'array',
            'status' => SubmissionStatus::class,
            'policy_outcome' => Outcome::class,
            'decision_source' => DecisionSource::class,
            'decided_at' => 'datetime',
            'media_pruned_at' => 'datetime',
            'appealed_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function decider(): BelongsTo
    {
        return $this->belongsTo(User::class, 'decided_by');
    }

    /**
     * @return BelongsTo<PolicyVersion, $this>
     */
    public function policyVersion(): BelongsTo
    {
        return $this->belongsTo(PolicyVersion::class);
    }

    /**
     * @return HasMany<PipelineStep, $this>
     */
    public function steps(): HasMany
    {
        return $this->hasMany(PipelineStep::class)->orderBy('started_at')->orderBy('id');
    }

    /**
     * @return HasMany<ReviewTask, $this>
     */
    public function tasks(): HasMany
    {
        return $this->hasMany(ReviewTask::class)->orderBy('created_at')->orderBy('id');
    }

    public function hasImage(): bool
    {
        return $this->image_path !== null;
    }

    /**
     * Whether a reviewer should see the image blurred first: a high enough sexual,
     * violence or self-harm score, or no scores at all (nobody has checked it).
     */
    public function isGraphic(): bool
    {
        if (! $this->hasImage()) {
            return false;
        }

        if ($this->ai_scores === null) {
            return true;
        }

        $scores = $this->scores();

        return max($scores['sexual'] ?? 0, $scores['violence'] ?? 0, $scores['self_harm'] ?? 0) >= config('moderation.graphic_score');
    }

    public function tokenUsage(): ?TokenUsage
    {
        return $this->ai_input_tokens === null ? null : new TokenUsage(
            input: $this->ai_input_tokens,
            output: (int) $this->ai_output_tokens,
            cacheRead: (int) $this->ai_cache_read_tokens,
            cacheWrite: (int) $this->ai_cache_write_tokens,
        );
    }

    /**
     * Scores keyed by category value, with any category the model left out as 0.
     *
     * @return array<string, float>
     */
    public function scores(): array
    {
        $scores = [];

        foreach (Category::cases() as $category) {
            $scores[$category->value] = (float) ($this->ai_scores[$category->value]['score'] ?? 0.0);
        }

        return $scores;
    }

    /**
     * The category with the highest score, if the item has been classified.
     */
    public function topCategory(): ?Category
    {
        if ($this->ai_scores === null) {
            return null;
        }

        $scores = $this->scores();
        arsort($scores);

        return Category::from(array_key_first($scores));
    }

    public function canBeAppealed(): bool
    {
        return $this->status === SubmissionStatus::Rejected
            && $this->appealed_at === null
            && $this->decided_at !== null
            && $this->decided_at->gt(now()->subDays(config('moderation.appeal_window_days')));
    }
}
