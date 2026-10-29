<?php

use App\Models\WebhookDelivery;
use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

Schedule::command('moderation:prune-media')->dailyAt('03:30');

// Webhook delivery records older than 30 days (see WebhookDelivery::prunable).
Schedule::command('model:prune', ['--model' => [WebhookDelivery::class]])->dailyAt('03:45');
