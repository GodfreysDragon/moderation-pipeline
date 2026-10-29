<?php

namespace App\Moderation;

use App\Models\Submission;
use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Str;

/**
 * Accepts an upload and hands it to the pipeline. Nothing is analysed here.
 */
class SubmissionIntake
{
    public function __construct(private readonly ModerationPipeline $pipeline) {}

    public function accept(User $user, ?string $caption, ?UploadedFile $image, ?string $reference = null): Submission
    {
        $path = $image?->storeAs('uploads', (string) Str::uuid(), config('moderation.disk'));

        $submission = $user->submissions()->create([
            'caption' => filled($caption) ? trim($caption) : null,
            'upload_path' => $path ?: null,
            'original_filename' => $image ? mb_substr(basename($image->getClientOriginalName()), 0, 200) : null,
            'external_ref' => filled($reference) ? trim($reference) : null,
        ]);

        $this->pipeline->start($submission);

        return $submission;
    }
}
