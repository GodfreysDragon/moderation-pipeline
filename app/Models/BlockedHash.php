<?php

namespace App\Models;

use App\Enums\Category;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * An image a person has confirmed breaks the policy. New uploads matching it,
 * exactly or as a near-duplicate, are rejected without calling the AI model.
 *
 * @property int $id
 * @property string $sha256
 * @property string $dhash
 * @property Category|null $category
 * @property int|null $source_submission_id
 * @property int|null $added_by
 * @property Carbon $created_at
 */
#[Fillable(['sha256', 'dhash', 'category', 'source_submission_id', 'added_by'])]
class BlockedHash extends Model
{
    public const UPDATED_AT = null;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'category' => Category::class,
            'created_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<Submission, $this>
     */
    public function sourceSubmission(): BelongsTo
    {
        return $this->belongsTo(Submission::class, 'source_submission_id');
    }
}
