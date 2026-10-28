<?php

namespace App\Http\Controllers;

use App\Moderation\ReviewerStats;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response;

class ReviewerController extends Controller
{
    public function __invoke(Request $request, ReviewerStats $stats): Response
    {
        Gate::authorize('manage-policy');

        $days = in_array((int) $request->query('days'), [7, 30, 90], true) ? (int) $request->query('days') : 30;

        return Inertia::render('reviewers/Index', [
            'stats' => $stats->summary($days),
            'graphicLimit' => config('moderation.graphic_daily_limit'),
        ]);
    }
}
