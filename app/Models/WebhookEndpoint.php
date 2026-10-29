<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;

/**
 * A URL that is told, by a signed POST, when one of its owner's submissions is decided.
 *
 * @property int $id
 * @property int $user_id
 * @property string $url
 * @property string $secret
 * @property Carbon $created_at
 * @property Carbon $updated_at
 * @property-read User $user
 */
#[Fillable(['url', 'secret'])]
#[Hidden(['secret'])]
class WebhookEndpoint extends Model
{
    public const MAX_PER_USER = 3;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'secret' => 'encrypted',
        ];
    }

    public static function newSecret(): string
    {
        return 'whsec_'.Str::random(40);
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * @return HasMany<WebhookDelivery, $this>
     */
    public function deliveries(): HasMany
    {
        return $this->hasMany(WebhookDelivery::class);
    }
}
