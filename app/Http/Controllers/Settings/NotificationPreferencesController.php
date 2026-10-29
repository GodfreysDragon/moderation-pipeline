<?php

namespace App\Http\Controllers\Settings;

use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class NotificationPreferencesController extends Controller
{
    public function edit(Request $request): Response
    {
        $user = $request->user();

        return Inertia::render('settings/Notifications', [
            'options' => collect($user->emailOptions())->map(fn (string $kind) => [
                'kind' => $kind,
                'enabled' => $user->wantsEmail($kind),
            ])->values()->all(),
        ]);
    }

    public function update(Request $request): RedirectResponse
    {
        $user = $request->user();
        $validated = $request->validate([
            'email' => ['present', 'array'],
            'email.*' => ['string', 'in:'.implode(',', array_keys(User::EMAIL_DEFAULTS))],
        ]);

        $user->update(['notification_preferences' => collect($user->emailOptions())
            ->mapWithKeys(fn (string $kind) => [$kind => in_array($kind, $validated['email'], true)])
            ->all()]);

        Inertia::flash('toast', ['type' => 'success', 'message' => 'Notification settings saved.']);

        return back();
    }
}
