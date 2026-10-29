<?php

namespace App\Console\Commands;

use App\Enums\SubmissionStatus;
use App\Models\Submission;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Storage;

#[Signature('moderation:prune-media {--days= : Override the retention period} {--dry-run : List what would be deleted}')]
#[Description('Delete the image files of rejected submissions past the retention period, keeping their hashes')]
class PruneMedia extends Command
{
    public function handle(): int
    {
        // Never before the appeal window closes: an appeal needs the image.
        $days = max((int) ($this->option('days') ?? config('moderation.retention_days')), config('moderation.appeal_window_days'));
        $disk = Storage::disk(config('moderation.disk'));

        $query = Submission::query()
            ->where('status', SubmissionStatus::Rejected)
            ->whereNotNull('image_path')
            ->where('decided_at', '<', now()->subDays($days))
            ->whereDoesntHave('tasks', fn ($q) => $q->whereNull('resolved_at'));

        if ($this->option('dry-run')) {
            $this->info("{$query->count()} rejected submissions have images older than {$days} days.");

            return self::SUCCESS;
        }

        $pruned = 0;

        $query->lazyById(200)->each(function (Submission $submission) use ($disk, &$pruned) {
            $disk->delete(array_filter([$submission->image_path, $submission->thumbnail_path]));
            // The transcribed image text goes with the image; it can hold the same personal data.
            $submission->update(['image_path' => null, 'thumbnail_path' => null, 'ai_image_text' => null, 'media_pruned_at' => now()]);
            $pruned++;
        });

        $this->info("Deleted the images of {$pruned} rejected submissions older than {$days} days. Their hashes are kept.");

        return self::SUCCESS;
    }
}
