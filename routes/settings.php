<?php

use App\Http\Controllers\Settings\ApiAccessController;
use App\Http\Controllers\Settings\NotificationPreferencesController;
use App\Http\Controllers\Settings\ProfileController;
use App\Http\Controllers\Settings\SecurityController;
use Illuminate\Auth\Middleware\RequirePassword;
use Illuminate\Support\Facades\Route;

Route::middleware(['auth'])->group(function () {
    Route::redirect('settings', '/settings/profile');

    Route::get('settings/profile', [ProfileController::class, 'edit'])->name('profile.edit');
    Route::patch('settings/profile', [ProfileController::class, 'update'])->name('profile.update');
});

Route::middleware(['auth', 'verified'])->group(function () {
    Route::delete('settings/profile', [ProfileController::class, 'destroy'])->name('profile.destroy');

    Route::get('settings/security', [SecurityController::class, 'edit'])
        ->middleware(RequirePassword::class)
        ->name('security.edit');

    Route::put('settings/password', [SecurityController::class, 'update'])
        ->middleware('throttle:6,1')
        ->name('user-password.update');

    Route::inertia('settings/appearance', 'settings/Appearance')->name('appearance.edit');

    Route::get('settings/notifications', [NotificationPreferencesController::class, 'edit'])->name('notification-preferences.edit');
    Route::put('settings/notifications', [NotificationPreferencesController::class, 'update'])->name('notification-preferences.update');

    // Tokens act as the user, so managing them asks for the password like Security does.
    Route::middleware(RequirePassword::class)->prefix('settings/api')->name('api-access.')->controller(ApiAccessController::class)->group(function () {
        Route::get('/', 'edit')->name('edit');
        Route::post('tokens', 'storeToken')->name('tokens.store');
        Route::delete('tokens/{token}', 'destroyToken')->whereNumber('token')->name('tokens.destroy');
        Route::post('webhooks', 'storeEndpoint')->middleware('throttle:10,1')->name('webhooks.store');
        Route::delete('webhooks/{endpoint}', 'destroyEndpoint')->name('webhooks.destroy');
        Route::post('webhooks/{endpoint}/ping', 'ping')->middleware('throttle:10,1')->name('webhooks.ping');
    });
});

Route::get('.well-known/passkey-endpoints', function () {
    return response()->json([
        'enroll' => route('security.edit'),
        'manage' => route('security.edit'),
    ]);
})->name('well-known.passkeys');
