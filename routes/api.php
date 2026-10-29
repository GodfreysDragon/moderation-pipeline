<?php

use App\Http\Controllers\Api\SubmissionController;
use Illuminate\Support\Facades\Route;

Route::middleware(['auth:sanctum', 'throttle:api'])->name('api.')->group(function () {
    Route::get('submissions', [SubmissionController::class, 'index'])
        ->middleware('abilities:submissions:read')
        ->name('submissions.index');

    Route::post('submissions', [SubmissionController::class, 'store'])
        ->middleware(['abilities:submissions:write', 'throttle:submissions'])
        ->name('submissions.store');

    Route::get('submissions/{submission}', [SubmissionController::class, 'show'])
        ->middleware('abilities:submissions:read')
        ->name('submissions.show');
});
