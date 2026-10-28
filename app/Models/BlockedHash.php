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
 * @property int $band_0
 * @property int $band_1
 * @property int $band_2
 * @property int $band_3
 * @property int $band_4
 * @property int $band_5
 * @property int $band_6
 * @property int $band_7
 */
#[Fillable(['sha256', 'dhash', 'category', 'source_submission_id', 'added_by'])]
class BlockedHash extends Model
{
    public const UPDATED_AT = null;

    /**
     * The dHash is split into this many 8-bit bands, each in its own indexed column.
     * Two hashes within BANDS - 1 bits of each other must agree exactly on at least
     * one band (pigeonhole), so a near-duplicate lookup only has to check the rows
     * that share a band instead of every row.
     */
    public const BANDS = 8;

    protected static function booted(): void
    {
        static::saving(function (BlockedHash $hash) {
            foreach (self::bands($hash->dhash) as $i => $value) {
                $hash->setAttribute("band_{$i}", $value);
            }
        });
    }

    /**
     * @return list<int> one 0-255 value per band
     */
    public static function bands(string $dhash): array
    {
        return array_map(fn (string $byte) => (int) hexdec($byte), str_split($dhash, 2));
    }

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
