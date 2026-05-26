<?php

namespace App\Http\Controllers\Api\V1\Analytics;

use App\Http\Controllers\Controller;
use App\Models\AnalyticsRollup;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;

class DashboardController extends Controller
{
    public function summary(Request $request): JsonResponse
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

        $rows = Cache::remember($cacheKey, now()->addSeconds(30), function () use ($validated, $from, $to): array {
            $rollups = AnalyticsRollup::query()
                ->where('team_id', $validated['team_id'])
                ->whereBetween('metric_date', [$from, $to])
                ->get();

            $metrics = $rollups
                ->groupBy('metric_name')
                ->map(fn ($items) => [
                    'count' => (int) $items->sum('value_unsigned'),
                    'value' => (float) $items->sum('value_decimal'),
                ])
                ->all();

            $groups = [
                'video' => ['video_view', 'video_complete', 'watch_time'],
                'engagement' => ['reaction', 'comment_submitted', 'share', 'save'],
                'commerce' => ['add_to_cart', 'checkout_started', 'checkout_completed', 'checkout_external_redirect'],
                'live' => ['live_show_view', 'live_reaction_spike'],
            ];

            $grouped = [];
            foreach ($groups as $groupName => $metricNames) {
                $grouped[$groupName] = collect($metricNames)
                    ->mapWithKeys(fn (string $name) => [
                        $name => $metrics[$name] ?? ['count' => 0, 'value' => 0.0],
                    ])
                    ->all();
            }

            return [
                'metrics' => $metrics,
                'groups' => $grouped,
                'top_events' => collect($metrics)
                    ->sortByDesc(fn (array $metric): int => $metric['count'])
                    ->take(8)
                    ->all(),
            ];
        });

        return response()->json([
            'team_id' => $validated['team_id'],
            'from' => $from,
            'to' => $to,
            ...$rows,
        ]);
    }
}
