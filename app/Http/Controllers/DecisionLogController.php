<?php

namespace App\Http\Controllers;

use App\Moderation\DecisionLog;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use RuntimeException;
use Symfony\Component\HttpFoundation\StreamedResponse;

class DecisionLogController extends Controller
{
    public function __invoke(Request $request, DecisionLog $log): StreamedResponse
    {
        Gate::authorize('manage-policy');

        $days = in_array((int) $request->query('days'), [7, 14, 30, 90, 365], true) ? (int) $request->query('days') : 30;
        $since = CarbonImmutable::now()->startOfDay()->subDays($days - 1);

        return response()->streamDownload(function () use ($log, $since) {
            $out = fopen('php://output', 'w') ?: throw new RuntimeException('Could not open the output stream.');
            fputcsv($out, DecisionLog::COLUMNS, escape: '');

            foreach ($log->rows($since) as $row) {
                fputcsv($out, $row, escape: '');
            }

            fclose($out);
        }, 'decision-log-'.now()->format('Y-m-d').'.csv', ['Content-Type' => 'text/csv; charset=UTF-8']);
    }
}
