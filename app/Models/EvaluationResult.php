<?php

namespace App\Models;

use App\Ai\Data\TokenUsage;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One item's scores from an evaluation run's model.
 *
 * @property int $id
 * @property int $evaluation_run_id
 * @property int $submission_id
 * @property array<string, array{score: float, evidence: string}>|null $scores
 * @property string|null $summary
 * @property string|null $error
 * @property int|null $input_tokens
 * @property int|null $cache_read_tokens
 * @property int|null $cache_write_tokens
 * @property int|null $output_tokens
 * @property-read Submission $submission
 */
#[Fillable(['evaluation_run_id', 'submission_id', 'scores', 'summary', 'error', 'input_tokens', 'cache_read_tokens', 'cache_write_tokens', 'output_tokens'])]
class EvaluationResult extends Model
{
    public $timestamps = false;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return ['scores' => 'array'];
    }

    /**
     * @return BelongsTo<Submission, $this>
     */
    public function submission(): BelongsTo
    {
        return $this->belongsTo(Submission::class);
    }

    /**
     * @return array<string, float>|null
     */
    public function scoreValues(): ?array
    {
        return $this->scores === null ? null : array_map(fn (array $entry) => $entry['score'], $this->scores);
    }

    public function tokenUsage(): ?TokenUsage
    {
        return $this->input_tokens === null ? null : new TokenUsage(
            input: $this->input_tokens,
            output: (int) $this->output_tokens,
            cacheRead: (int) $this->cache_read_tokens,
            cacheWrite: (int) $this->cache_write_tokens,
        );
    }
}
