<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Http\Controllers\Controller;
use App\Models\Embed;
use App\Services\Analytics\AnalyticsSummaryService;
use App\Models\LiveShow;
use App\Models\Playlist;
use App\Models\Product;
use App\Models\Video;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class OverviewController extends Controller
{
    public function show(Request $request, AnalyticsSummaryService $summaryService): JsonResponse
    {
        $validated = $request->validate([
            'team_id' => ['required', 'integer', 'exists:teams,id'],
        ]);

        abort_unless(
            $request->user()->team_id === $validated['team_id']
            || $request->user()->teams()->whereKey($validated['team_id'])->exists(),
            403,
        );

        $teamId = (int) $validated['team_id'];
        $from = now()->subDays(7)->toDateString();
        $to = now()->toDateString();

        $summary = $summaryService->build($teamId, $from, $to);
        $metrics = collect($summary['metrics'] ?? [])
            ->mapWithKeys(fn (array $metric, string $name): array => [
                $name => (int) ($metric['count'] ?? 0),
            ])
            ->all();

        return response()->json([
            'team_id' => $teamId,
            'counts' => [
                'videos' => Video::query()->where('team_id', $teamId)->count(),
                'published_videos' => Video::query()->where('team_id', $teamId)->where('status', 'published')->count(),
                'products' => Product::query()->where('team_id', $teamId)->count(),
                'playlists' => Playlist::query()->where('team_id', $teamId)->count(),
                'embeds' => Embed::query()->where('team_id', $teamId)->count(),
                'live_shows' => LiveShow::query()->where('team_id', $teamId)->count(),
            ],
            'metrics_7d' => $metrics,
            'daily_series' => $summary['daily_series'] ?? [],
            'top_videos' => array_slice($summary['top_videos'] ?? [], 0, 4),
        ]);
    }
}
