<?php

namespace App\Http\Middleware;

use App\Models\ReviewTask;
use App\Moderation\ModerationService;
use Illuminate\Http\Request;
use Illuminate\Notifications\DatabaseNotification;
use Inertia\Middleware;

class HandleInertiaRequests extends Middleware
{
    /**
     * The root template that's loaded on the first page visit.
     *
     * @see https://inertiajs.com/server-side-setup#root-template
     *
     * @var string
     */
    protected $rootView = 'app';

    /**
     * Determines the current asset version.
     *
     * @see https://inertiajs.com/asset-versioning
     */
    public function version(Request $request): ?string
    {
        return parent::version($request);
    }

    /**
     * Define the props that are shared by default.
     *
     * @see https://inertiajs.com/shared-data
     *
     * @return array<string, mixed>
     */
    public function share(Request $request): array
    {
        return [
            ...parent::share($request),
            'name' => config('app.name'),
            'auth' => [
                'user' => $request->user(),
                'can' => [
                    'moderate' => (bool) $request->user()?->isStaff(),
                    'managePolicy' => (bool) $request->user()?->isAdmin(),
                ],
            ],
            // Open tasks this user may take, for the sidebar badge.
            'queueCount' => fn () => $request->user()?->isStaff()
                ? ReviewTask::query()->available()
                    ->whereIn('kind', array_map(fn ($k) => $k->value, app(ModerationService::class)->kindsFor($request->user())))
                    ->where(fn ($q) => $q->whereNull('excluded_reviewer_id')->orWhere('excluded_reviewer_id', '!=', $request->user()->id))
                    ->count()
                : null,
            // The header bell: unread count and the latest few.
            'notifications' => fn () => $request->user() ? [
                'unread' => $request->user()->unreadNotifications()->count(),
                'recent' => $request->user()->notifications()->limit(8)->get()->map(fn (DatabaseNotification $n) => [
                    'id' => $n->id,
                    'kind' => $n->data['kind'] ?? null,
                    'title' => $n->data['title'] ?? '',
                    'body' => $n->data['body'] ?? '',
                    'read' => $n->read_at !== null,
                    'created_at' => $n->created_at?->toIso8601String(),
                ])->values()->all(),
            ] : null,
            'sidebarOpen' => ! $request->hasCookie('sidebar_state') || $request->cookie('sidebar_state') === 'true',
        ];
    }
}
