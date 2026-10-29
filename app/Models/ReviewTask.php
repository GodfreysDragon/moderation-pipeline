<?php

namespace App\Models;

use App\Enums\Decision;
use App\Enums\Outcome;
use App\Enums\TaskKind;
use App\Observers\ReviewTaskObserver;
use Database\Factories\ReviewTaskFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\ObservedBy;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * A unit of human review work on a submission.
 *
 * @property int $id
 * @property int $submission_id
 * @property TaskKind $kind
 * @property int $priority
 * @property bool $graphic
 * @property Outcome|null $automated_outcome
 * @property int|null $excluded_reviewer_id
 * @property int|null $reviews_task_id
 * @property string|null $note
 * @property int|null $claimed_by
 * @property Carbon|null $claimed_at
 * @property Carbon|null $claim_expires_at
 * @property Decision|null $decision
 * @property string|null $reason
 * @property string|null $decision_note
 * @property int|null $resolved_by
 * @property Carbon|null $resolved_at
 * @property Carbon $created_at
 * @property Carbon $updated_at
 * @property-read Submission $submission
 * @property-read User|null $claimant
 * @property-read User|null $resolver
 */
#[ObservedBy(ReviewTaskObserver::class)]
#[Fillable([
    'submission_id', 'kind', 'priority', 'graphic', 'automated_outcome', 'excluded_reviewer_id', 'reviews_task_id', 'note', 'claimed_by',
    'claimed_at', 'claim_expires_at', 'decision', 'reason', 'decision_note', 'resolved_by', 'resolved_at',
])]
class ReviewTask extends Model
{
    /** @use HasFactory<ReviewTaskFactory> */
    use HasFactory;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'kind' => TaskKind::class,
            'graphic' => 'boolean',
            'automated_outcome' => Outcome::class,
            'decision' => Decision::class,
            'claimed_at' => 'datetime',
            'claim_expires_at' => 'datetime',
            'resolved_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<Submission, $this>
     */
    public function submission(): BelongsTo
    {
        return $this->belongsTo(Submission::class);
    }

    /**
     * For a second review, the task it re-checks.
     *
     * @return BelongsTo<ReviewTask, $this>
     */
    public function reviewedTask(): BelongsTo
    {
        return $this->belongsTo(ReviewTask::class, 'reviews_task_id');
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function claimant(): BelongsTo
    {
        return $this->belongsTo(User::class, 'claimed_by');
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function resolver(): BelongsTo
    {
        return $this->belongsTo(User::class, 'resolved_by');
    }

    /**
     * @param  Builder<self>  $query
     */
    public function scopeOpen(Builder $query): void
    {
        $query->whereNull('resolved_at');
    }

    /**
     * Open tasks nobody holds a live claim on.
     *
     * @param  Builder<self>  $query
     */
    public function scopeAvailable(Builder $query): void
    {
        $query->whereNull('resolved_at')->where(fn (Builder $q) => $q
            ->whereNull('claimed_by')
            ->orWhere('claim_expires_at', '<', now()));
    }

    public function isOpen(): bool
    {
        return $this->resolved_at === null;
    }

    public function isClaimedBy(User $user): bool
    {
        return $this->isOpen()
            && $this->claimed_by === $user->id
            && $this->claim_expires_at?->isFuture() === true;
    }

    public function hasLiveClaim(): bool
    {
        return $this->claimed_by !== null && $this->claim_expires_at?->isFuture() === true;
    }

    /**
     * Whether the decision agreed with the automated outcome (null if there was nothing to compare).
     */
    public function agreedWithAutomation(): ?bool
    {
        $outcome = $this->decision?->outcome();

        if ($outcome === null || $this->automated_outcome === null || $this->automated_outcome === Outcome::Review) {
            return null;
        }

        return $outcome === $this->automated_outcome;
    }
}
