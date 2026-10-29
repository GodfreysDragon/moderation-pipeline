<?php

namespace App\Providers;

use Anthropic\Client;
use App\Ai\ClaudeBatchClassifier;
use App\Ai\ClaudeContentClassifier;
use App\Ai\Contracts\BatchClassifier;
use App\Ai\Contracts\ContentClassifier;
use App\Ai\FakeBatchClassifier;
use App\Ai\FakeContentClassifier;
use App\Models\User;
use App\Support\MediaSanitizer;
use App\Webhooks\UrlGuard;
use Carbon\CarbonImmutable;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Foundation\Console\ServeCommand;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;
use Illuminate\Validation\Rules\Password;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        $this->app->singleton(Client::class, fn () => new Client(apiKey: config('moderation.anthropic.api_key')));

        $this->app->singleton(ClaudeContentClassifier::class, fn ($app) => new ClaudeContentClassifier(
            $app->make(Client::class),
            config('moderation.anthropic.model'),
        ));

        $this->app->singleton(ContentClassifier::class, fn ($app) => match (config('moderation.classifier')) {
            'fake' => new FakeContentClassifier,
            default => $app->make(ClaudeContentClassifier::class),
        });

        $this->app->singleton(BatchClassifier::class, fn ($app) => match (config('moderation.classifier')) {
            'fake' => new FakeBatchClassifier,
            default => new ClaudeBatchClassifier($app->make(Client::class), $app->make(ClaudeContentClassifier::class)),
        });

        $this->app->bind(UrlGuard::class, fn () => new UrlGuard(config('moderation.webhooks.allow_private_targets')));

        $this->app->bind(MediaSanitizer::class, fn () => new MediaSanitizer(
            config('moderation.max_dimension'),
            config('moderation.thumbnail_dimension'),
        ));
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        $this->configureDefaults();
        $this->configureAuthorization();
        $this->configureRateLimiting();
        $this->configureLocalServer();
    }

    /**
     * Configure default behaviors for production-ready applications.
     */
    protected function configureDefaults(): void
    {
        Date::use(CarbonImmutable::class);

        DB::prohibitDestructiveCommands(
            app()->isProduction(),
        );

        Password::defaults(fn (): ?Password => app()->isProduction()
            ? Password::min(12)
                ->mixedCase()
                ->letters()
                ->numbers()
                ->symbols()
                ->uncompromised()
            : null,
        );
    }

    protected function configureAuthorization(): void
    {
        Gate::define('moderate', fn (User $user) => $user->isStaff());
        Gate::define('manage-policy', fn (User $user) => $user->isAdmin());
    }

    protected function configureRateLimiting(): void
    {
        // Uploads per user.
        RateLimiter::for('api', fn (Request $request) => Limit::perMinute(120)->by($request->user()?->id ?: $request->ip()));

        RateLimiter::for('submissions', fn (Request $request) => Limit::perMinute(20)
            ->by($request->user()?->id ?: $request->ip()));

        // Classification calls across all workers (used by the ClassifyContent job middleware).
        RateLimiter::for('moderation-ai', fn () => Limit::perMinute(config('moderation.ai_calls_per_minute')));
    }

    protected function configureLocalServer(): void
    {
        /*
         * `artisan serve` strips the environment down to an allow-list. On
         * Windows that drops TEMP/TMP, leaving PHP nowhere to write file
         * uploads ("unable to create a temporary file").
         */
        array_push(ServeCommand::$passthroughVariables, 'TEMP', 'TMP');
    }
}
