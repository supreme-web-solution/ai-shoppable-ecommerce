<?php

namespace App\Http\Controllers\Api\V1\Analytics;

use App\Http\Controllers\Controller;
use App\Services\Analytics\AnalyticsSummaryService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;

class DashboardController extends Controller
{
    public function summary(Request $request, AnalyticsSummaryService $summaryService): JsonResponse
    {
        $validated = $request->validate([
            'team_id' => ['required', 'integer', 'exists:teams,id'],
            'from' => ['nullable', 'date'],
            'to' => ['nullable', 'date'],
        ]);

        abort_unless(
            $request->user()->team_id === $validated['team_id']
            || $request->user()->teams()->whereKey($validated['team_id'])->exists(),
            403,
        );

        $from = $validated['from'] ?? now()->subDays(7)->toDateString();
        $to = $validated['to'] ?? now()->toDateString();

        $cacheKey = implode(':', [
            'analytics_summary',
            "team_{$validated['team_id']}",
            "from_{$from}",
            "to_{$to}",
        ]);

        $rows = Cache::remember(
            $cacheKey,
            now()->addSeconds(30),
            fn (): array => $summaryService->build((int) $validated['team_id'], $from, $to),
        );

        return response()->json([
            'team_id' => $validated['team_id'],
            'from' => $from,
            'to' => $to,
            ...$rows,
        ]);
    }
}
