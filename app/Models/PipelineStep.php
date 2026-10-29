<?php

namespace App\Models;

use App\Enums\PipelineStage;
use App\Enums\StepStatus;
use App\Observers\PipelineStepObserver;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\ObservedBy;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * One attempt at one pipeline stage for one submission.
 *
 * @property int $id
 * @property int $submission_id
 * @property PipelineStage $stage
 * @property StepStatus $status
 * @property int $attempt
 * @property array<string, mixed>|null $detail
 * @property string|null $error
 * @property Carbon $started_at
 * @property Carbon|null $finished_at
 * @property int|null $duration_ms
 */
#[ObservedBy(PipelineStepObserver::class)]
#[Fillable(['submission_id', 'stage', 'status', 'attempt', 'detail', 'error', 'started_at', 'finished_at', 'duration_ms'])]
class PipelineStep extends Model
{
    public $timestamps = false;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'stage' => PipelineStage::class,
            'status' => StepStatus::class,
            'detail' => 'array',
            'started_at' => 'datetime',
            'finished_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<Submission, $this>
     */
    public function submission(): BelongsTo
    {
        return $this->belongsTo(Submission::class);
    }
}
