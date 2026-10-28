<?php

use App\Http\Controllers\DashboardController;
use App\Http\Controllers\DecisionLogController;
use App\Http\Controllers\EvaluationController;
use App\Http\Controllers\PolicyController;
use App\Http\Controllers\ReviewController;
use App\Http\Controllers\ReviewerController;
use App\Http\Controllers\SubmissionController;
use Illuminate\Support\Facades\Route;

Route::inertia('/', 'Welcome')->name('home');

Route::middleware(['auth', 'verified'])->group(function () {
    Route::get('dashboard', DashboardController::class)->name('dashboard');

    // Uploads
    Route::get('submissions', [SubmissionController::class, 'index'])->name('submissions.index');
    Route::get('submissions/create', [SubmissionController::class, 'create'])->name('submissions.create');
    Route::post('submissions', [SubmissionController::class, 'store'])->middleware('throttle:submissions')->name('submissions.store');
    Route::get('submissions/{submission}', [SubmissionController::class, 'show'])->name('submissions.show');
    Route::get('submissions/{submission}/media/{variant}', [SubmissionController::class, 'media'])
        ->whereIn('variant', ['image', 'thumb'])
        ->name('submissions.media');
    Route::post('submissions/{submission}/appeal', [SubmissionController::class, 'appeal'])->name('submissions.appeal');

    // Review queue (staff)
    Route::get('review', [ReviewController::class, 'index'])->name('review.index');
    Route::post('review/next', [ReviewController::class, 'next'])->name('review.next');
    Route::get('review/{task}', [ReviewController::class, 'show'])->name('review.show');
    Route::post('review/{task}/claim', [ReviewController::class, 'claim'])->name('review.claim');
    Route::post('review/{task}/release', [ReviewController::class, 'release'])->name('review.release');
    Route::post('review/{task}/decide', [ReviewController::class, 'decide'])->name('review.decide');

    // Policy (staff read, admins write)
    Route::get('policy', [PolicyController::class, 'edit'])->name('policy.edit');
    Route::post('policy/preview', [PolicyController::class, 'preview'])->name('policy.preview');
    Route::post('policy', [PolicyController::class, 'store'])->name('policy.store');

    // Audit trail (admins)
    Route::get('exports/decision-log', DecisionLogController::class)->name('exports.decision-log');

    // Reviewer quality (admins)
    Route::get('reviewers', ReviewerController::class)->name('reviewers.index');

    // Model evaluation (admins)
    Route::get('evaluations', [EvaluationController::class, 'index'])->name('evaluations.index');
    Route::post('evaluations', [EvaluationController::class, 'store'])->name('evaluations.store');
    Route::get('evaluations/{evaluation}', [EvaluationController::class, 'show'])->name('evaluations.show');
});

require __DIR__.'/settings.php';
