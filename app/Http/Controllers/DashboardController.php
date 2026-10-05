<?php

namespace App\Http\Controllers;

use App\Moderation\ModerationMetrics;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class DashboardController extends Controller
{
    /**
     * Staff get pipeline and queue metrics; members go to their submissions.
     */
    public function __invoke(Request $request, ModerationMetrics $metrics): Response|RedirectResponse
    {
        if (! $request->user()->isStaff()) {
            return to_route('submissions.index');
        }

        $days = in_array((int) $request->query('days'), [7, 14, 30], true) ? (int) $request->query('days') : 14;

        return Inertia::render('Dashboard', [
            'metrics' => $metrics->summary($days),
        ]);
    }
}
