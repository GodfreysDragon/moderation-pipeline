<?php

namespace App\Ai;

use App\Ai\Contracts\BatchClassifier;
use App\Ai\Data\BatchOutcome;
use App\Ai\Data\ClassificationInput;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;

/**
 * Offline stand-in: "submits" by remembering the inputs and finishes on the first poll.
 */
class FakeBatchClassifier implements BatchClassifier
{
    public function submit(array $inputs, string $model, string $effort): string
    {
        $id = 'fakebatch_'.Str::lower(Str::random(12));
        // The keyword classifier never looks at pixels, so the text is all it needs.
        Cache::put($id, array_map(fn (ClassificationInput $input) => [$input->caption, $input->filename], $inputs), now()->addDay());

        return $id;
    }

    public function poll(string $batchId): ?BatchOutcome
    {
        /** @var array<string, array{string|null, string|null}> $texts */
        $texts = Cache::pull($batchId, []);
        $classifier = new FakeContentClassifier;

        return new BatchOutcome(
            array_map(fn (array $text) => $classifier->classify(new ClassificationInput($text[0], filename: $text[1])), $texts),
            [],
        );
    }
}
