<?php

namespace App\Jobs;

use App\Models\WebhookDelivery;
use App\Webhooks\Signature;
use App\Webhooks\UnsafeWebhookUrl;
use App\Webhooks\UrlGuard;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use Throwable;

/**
 * POST one event to one endpoint, signed with the endpoint's secret. Anything but
 * a 2xx is retried with growing gaps for about three hours; an unsafe URL is not retried.
 */
class DeliverWebhook implements ShouldQueue
{
    use Queueable;

    public int $tries = 6;

    public function __construct(public WebhookDelivery $delivery) {}

    /**
     * @return list<int>
     */
    public function backoff(): array
    {
        return [10, 60, 300, 1800, 7200];
    }

    public function handle(UrlGuard $guard): void
    {
        $delivery = $this->delivery;
        $endpoint = $delivery->endpoint;

        try {
            $target = $guard->check($endpoint->url);
        } catch (UnsafeWebhookUrl $e) {
            $delivery->update(['attempts' => $delivery->attempts + 1, 'error' => mb_substr($e->getMessage(), 0, 250), 'failed_at' => now()]);
            $this->delete();

            return;
        }

        $body = (string) json_encode(['id' => $delivery->id, ...$delivery->payload], JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
        $status = null;
        $error = null;

        try {
            $response = Http::timeout(10)
                ->connectTimeout(5)
                ->withoutRedirecting()
                // Connect to the address the guard approved, not a fresh DNS answer.
                ->withOptions(['curl' => [CURLOPT_RESOLVE => ["{$target['host']}:{$target['port']}:{$target['ip']}"]]])
                ->withHeaders([
                    'User-Agent' => config('app.name').' webhooks',
                    'Moderation-Event' => $delivery->event,
                    'Moderation-Delivery' => (string) $delivery->id,
                    Signature::HEADER => Signature::make($body, $endpoint->secret, now()->getTimestamp()),
                ])
                ->withBody($body, 'application/json')
                ->post($endpoint->url);

            $status = $response->status();
            $error = $response->successful() ? null : "The endpoint answered HTTP {$status}.";
        } catch (ConnectionException $e) {
            $error = mb_substr('Could not connect: '.strtok($e->getMessage(), "\n"), 0, 250);
        }

        $delivery->update([
            'attempts' => $delivery->attempts + 1,
            'response_status' => $status,
            'error' => $error,
            'delivered_at' => $error === null ? now() : null,
        ]);

        if ($error === null) {
            return;
        }

        // Release rather than throw: a receiver being down is expected, not an
        // application error, and a sync queue must not fail the decision that
        // triggered this.
        if ($this->attempts() >= $this->tries) {
            $delivery->update(['failed_at' => now()]);
        } else {
            $this->release($this->backoff()[$this->attempts() - 1]);
        }
    }

    public function failed(?Throwable $exception): void
    {
        $this->delivery->refresh()->update(['failed_at' => now()]);
    }
}
