<?php

namespace App\Http\Controllers\Settings;

use App\Http\Controllers\Controller;
use App\Models\WebhookDelivery;
use App\Models\WebhookEndpoint;
use App\Webhooks\UnsafeWebhookUrl;
use App\Webhooks\UrlGuard;
use App\Webhooks\WebhookDispatcher;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;
use Laravel\Sanctum\PersonalAccessToken;

/**
 * API tokens and webhook endpoints. Token values and webhook secrets are shown
 * once, in flash data, and never again.
 */
class ApiAccessController extends Controller
{
    public const ABILITIES = ['submissions:read', 'submissions:write'];

    public function edit(Request $request): Response
    {
        $user = $request->user();

        return Inertia::render('settings/ApiAccess', [
            'apiBase' => url('/api'),
            'abilities' => self::ABILITIES,
            'tokens' => $user->tokens()->latest('id')->get()->map(fn (PersonalAccessToken $token) => [
                'id' => $token->id,
                'name' => $token->name,
                'abilities' => $token->abilities,
                'created_at' => $token->created_at?->toIso8601String(),
                'last_used_at' => $token->last_used_at?->toIso8601String(),
            ])->values()->all(),
            'endpoints' => $user->webhookEndpoints()->oldest('id')->get()->map(fn (WebhookEndpoint $endpoint) => [
                'id' => $endpoint->id,
                'url' => $endpoint->url,
                'created_at' => $endpoint->created_at->toIso8601String(),
            ])->values()->all(),
            'maxEndpoints' => WebhookEndpoint::MAX_PER_USER,
            'deliveries' => WebhookDelivery::query()
                ->whereIn('webhook_endpoint_id', $user->webhookEndpoints()->select('id'))
                ->with('endpoint:id,url')
                ->latest('id')
                ->limit(15)
                ->get()
                ->map(fn (WebhookDelivery $delivery) => [
                    'id' => $delivery->id,
                    'event' => $delivery->event,
                    'url' => $delivery->endpoint->url,
                    'submission_id' => $delivery->submission_id,
                    'status' => $delivery->status(),
                    'attempts' => $delivery->attempts,
                    'response_status' => $delivery->response_status,
                    'error' => $delivery->error,
                    'created_at' => $delivery->created_at->toIso8601String(),
                ])->values()->all(),
        ]);
    }

    public function storeToken(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'name' => ['required', 'string', 'max:60'],
            'abilities' => ['required', 'array', 'min:1'],
            'abilities.*' => ['string', 'in:'.implode(',', self::ABILITIES)],
        ]);

        $token = $request->user()->createToken($validated['name'], array_values(array_unique($validated['abilities'])));

        Inertia::flash('secret', ['label' => "Token \"{$validated['name']}\"", 'value' => $token->plainTextToken]);

        return back();
    }

    public function destroyToken(Request $request, int $token): RedirectResponse
    {
        $request->user()->tokens()->whereKey($token)->delete();

        Inertia::flash('toast', ['type' => 'success', 'message' => 'Token revoked. Requests using it now get 401.']);

        return back();
    }

    public function storeEndpoint(Request $request, UrlGuard $guard): RedirectResponse
    {
        $validated = $request->validate([
            'url' => ['required', 'string', 'url:http,https', 'max:2048'],
        ]);

        $user = $request->user();

        if ($user->webhookEndpoints()->count() >= WebhookEndpoint::MAX_PER_USER) {
            throw ValidationException::withMessages(['url' => 'You can have up to '.WebhookEndpoint::MAX_PER_USER.' webhook endpoints. Remove one first.']);
        }

        try {
            $guard->check($validated['url']);
        } catch (UnsafeWebhookUrl $e) {
            throw ValidationException::withMessages(['url' => $e->getMessage()]);
        }

        $endpoint = $user->webhookEndpoints()->create(['url' => $validated['url'], 'secret' => WebhookEndpoint::newSecret()]);

        Inertia::flash('secret', ['label' => "Signing secret for {$endpoint->url}", 'value' => $endpoint->secret]);

        return back();
    }

    public function destroyEndpoint(Request $request, WebhookEndpoint $endpoint): RedirectResponse
    {
        abort_unless($endpoint->user_id === $request->user()->id, 404);

        $endpoint->delete();

        return back();
    }

    public function ping(Request $request, WebhookEndpoint $endpoint, WebhookDispatcher $webhooks): RedirectResponse
    {
        abort_unless($endpoint->user_id === $request->user()->id, 404);

        $webhooks->ping($endpoint);

        Inertia::flash('toast', ['type' => 'success', 'message' => 'Test event queued. Its result appears under recent deliveries.']);

        return back();
    }
}
