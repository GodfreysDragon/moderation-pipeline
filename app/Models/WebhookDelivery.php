<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\MassPrunable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * One event sent (or being sent) to one endpoint, with the outcome of the last attempt.
 *
 * @property int $id
 * @property int $webhook_endpoint_id
 * @property int|null $submission_id
 * @property string $event
 * @property array<string, mixed> $payload
 * @property int $attempts
 * @property int|null $response_status
 * @property string|null $error
 * @property Carbon|null $delivered_at
 * @property Carbon|null $failed_at
 * @property Carbon $created_at
 * @property Carbon $updated_at
 * @property-read WebhookEndpoint $endpoint
 */
#[Fillable(['submission_id', 'event', 'payload', 'attempts', 'response_status', 'error', 'delivered_at', 'failed_at'])]
class WebhookDelivery extends Model
{
    use MassPrunable;

    /**
     * Kept for 30 days, long enough to debug a receiver.
     *
     * @return Builder<self>
     */
    public function prunable(): Builder
    {
        return self::query()->where('created_at', '<', now()->subDays(30));
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'payload' => 'array',
            'delivered_at' => 'datetime',
            'failed_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<WebhookEndpoint, $this>
     */
    public function endpoint(): BelongsTo
    {
        return $this->belongsTo(WebhookEndpoint::class, 'webhook_endpoint_id');
    }

    public function status(): string
    {
        return match (true) {
            $this->delivered_at !== null => 'delivered',
            $this->failed_at !== null => 'failed',
            $this->attempts > 0 => 'retrying',
            default => 'pending',
        };
    }
}
