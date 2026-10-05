<?php

namespace App\Models;

use App\Enums\Category;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * An immutable snapshot of the moderation policy. Saving a change creates a new
 * version, so every past decision can be traced to the thresholds it used.
 *
 * @property int $id
 * @property array<string, array{review: float, reject: float|null}> $thresholds
 * @property float $audit_sample_rate
 * @property int $blocklist_distance
 * @property string|null $note
 * @property int|null $created_by
 * @property Carbon $created_at
 * @property-read User|null $author
 */
#[Fillable(['thresholds', 'audit_sample_rate', 'blocklist_distance', 'note', 'created_by'])]
class PolicyVersion extends Model
{
    public const UPDATED_AT = null;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'thresholds' => 'array',
            'audit_sample_rate' => 'float',
            'blocklist_distance' => 'integer',
            'created_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function author(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /**
     * The policy in force: the newest version, created from the defaults on first use.
     */
    public static function current(): self
    {
        return self::query()->latest('id')->first() ?? self::create([
            'thresholds' => self::defaultThresholds(),
            'audit_sample_rate' => config('moderation.audit_sample_rate'),
            'blocklist_distance' => config('moderation.blocklist_distance'),
            'note' => 'Initial policy',
        ]);
    }

    /**
     * @return array<string, array{review: float, reject: float|null}>
     */
    public static function defaultThresholds(): array
    {
        $thresholds = [];

        foreach (Category::cases() as $category) {
            $thresholds[$category->value] = $category->defaultThresholds();
        }

        return $thresholds;
    }

    /**
     * @return array{review: float, reject: float|null}
     */
    public function thresholdsFor(Category $category): array
    {
        return $this->thresholds[$category->value] ?? $category->defaultThresholds();
    }
}
