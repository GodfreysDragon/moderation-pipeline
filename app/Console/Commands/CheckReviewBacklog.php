<?php

namespace App\Console\Commands;

use App\Enums\UserRole;
use App\Models\ReviewTask;
use App\Models\User;
use App\Notifications\ReviewBacklog;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Notification;

#[Signature('moderation:check-backlog')]
#[Description('Tell staff when review tasks have waited longer than the alert threshold')]
class CheckReviewBacklog extends Command
{
    public function handle(): int
    {
        $threshold = config('moderation.backlog_alert_minutes');
        $overdue = ReviewTask::query()->open()->where('created_at', '<', now()->subMinutes($threshold));
        $waiting = $overdue->count();

        if ($waiting === 0) {
            $this->info("Nothing has waited more than {$threshold} minutes.");

            return self::SUCCESS;
        }

        // At most one alert per cooldown period, however long the backlog lasts.
        if (! Cache::add('moderation:backlog-alerted', true, now()->addMinutes(config('moderation.backlog_alert_cooldown_minutes')))) {
            $this->info("{$waiting} overdue; already alerted recently.");

            return self::SUCCESS;
        }

        $oldest = (int) Carbon::parse($overdue->min('created_at'))->diffInMinutes(now());
        $staff = User::query()->whereIn('role', [UserRole::Moderator, UserRole::Admin])->get();

        Notification::send($staff, new ReviewBacklog($waiting, $oldest));
        $this->info("{$waiting} overdue; alerted {$staff->count()} staff.");

        return self::SUCCESS;
    }
}
