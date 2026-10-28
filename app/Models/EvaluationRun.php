<?php

namespace App\Models;

use App\Enums\EvaluationStatus;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property string $model
 * @property string $effort
 * @property int $sample_size
 * @property EvaluationStatus $status
 * @property string|null $batch_id
 * @property string|null $error
 * @property int|null $policy_version_id
 * @property int|null $created_by
 * @property Carbon|null $submitted_at
 * @property Carbon|null $completed_at
 * @property Carbon $created_at
 * @property Carbon $updated_at
 * @property-read PolicyVersion|null $policyVersion
 * @property-read User|null $creator
 */
#[Fillable(['model', 'effort', 'sample_size', 'status', 'batch_id', 'error', 'policy_version_id', 'created_by', 'submitted_at', 'completed_at'])]
class EvaluationRun extends Model
{
    protected $attributes = [
        'status' => 'pending',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'status' => EvaluationStatus::class,
            'submitted_at' => 'datetime',
            'completed_at' => 'datetime',
        ];
    }

    /**
     * @return HasMany<EvaluationResult, $this>
     */
    public function results(): HasMany
    {
        return $this->hasMany(EvaluationResult::class);
    }

    /**
     * @return BelongsTo<PolicyVersion, $this>
     */
    public function policyVersion(): BelongsTo
    {
        return $this->belongsTo(PolicyVersion::class);
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function fail(string $error): void
    {
        $this->update(['status' => EvaluationStatus::Failed, 'error' => mb_substr($error, 0, 250), 'completed_at' => now()]);
    }
}
