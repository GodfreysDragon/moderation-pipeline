<?php

namespace App\Webhooks;

use App\Http\Resources\SubmissionResource;
use App\Jobs\DeliverWebhook;
use App\Models\Submission;
use App\Models\WebhookDelivery;
use App\Models\WebhookEndpoint;

/**
 * Records one delivery per endpoint and queues it. The payload is a snapshot taken
 * now, so a retry an hour later still describes the decision that triggered it.
 */
class WebhookDispatcher
{
    public const SUBMISSION_DECIDED = 'submission.decided';

    public const PING = 'ping';

    public function submissionDecided(Submission $submission): void
    {
        $endpoints = WebhookEndpoint::query()->where('user_id', $submission->user_id)->get();

        if ($endpoints->isEmpty()) {
            return;
        }

        $data = (new SubmissionResource($submission))->resolve();

        foreach ($endpoints as $endpoint) {
            $this->send($endpoint, self::SUBMISSION_DECIDED, $data, $submission->id);
        }
    }

    public function ping(WebhookEndpoint $endpoint): WebhookDelivery
    {
        return $this->send($endpoint, self::PING, ['message' => 'Webhook test from '.config('app.name').'.']);
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function send(WebhookEndpoint $endpoint, string $event, array $data, ?int $submissionId = null): WebhookDelivery
    {
        $delivery = $endpoint->deliveries()->create([
            'submission_id' => $submissionId,
            'event' => $event,
            'payload' => ['event' => $event, 'created_at' => now()->toIso8601String(), 'data' => $data],
        ]);

        DeliverWebhook::dispatch($delivery)->afterCommit();

        return $delivery;
    }
}
