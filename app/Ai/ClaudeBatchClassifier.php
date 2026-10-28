<?php

namespace App\Ai;

use Anthropic\Beta\Messages\Batches\BatchCreateParams\Request;
use Anthropic\Beta\Messages\Batches\BatchCreateParams\Request\Params;
use Anthropic\Beta\Messages\Batches\MessageBatchSucceededResult;
use Anthropic\Client;
use App\Ai\Contracts\BatchClassifier;
use App\Ai\Data\BatchOutcome;
use Throwable;

/**
 * Runs classifications through the Message Batches API: the same requests the
 * pipeline sends, at half the price, finished within 24 hours (usually minutes).
 * Batches don't accept refusal fallbacks, so a refusal comes back as an error.
 */
class ClaudeBatchClassifier implements BatchClassifier
{
    public function __construct(
        private readonly Client $client,
        private readonly ClaudeContentClassifier $classifier,
    ) {}

    public function submit(array $inputs, string $model, string $effort): string
    {
        $requests = [];

        foreach ($inputs as $id => $input) {
            $requests[] = Request::with(customID: (string) $id, params: Params::with(...$this->classifier->params($input, $model, $effort)));
        }

        return $this->client->beta->messages->batches->create(requests: $requests)->id;
    }

    public function poll(string $batchId): ?BatchOutcome
    {
        if ($this->client->beta->messages->batches->retrieve($batchId)->processingStatus !== 'ended') {
            return null;
        }

        $classifications = $errors = [];

        // Results arrive in any order: key them by custom id, never by position.
        foreach ($this->client->beta->messages->batches->resultsStream($batchId) as $response) {
            $result = $response->result;

            if (! $result instanceof MessageBatchSucceededResult) {
                $errors[$response->customID] = "The batch request {$result->type}.";

                continue;
            }

            try {
                $classifications[$response->customID] = $this->classifier->parse($result->message);
            } catch (Throwable $e) {
                $errors[$response->customID] = mb_substr((string) strtok($e->getMessage(), "\n"), 0, 250);
            }
        }

        return new BatchOutcome($classifications, $errors);
    }
}
