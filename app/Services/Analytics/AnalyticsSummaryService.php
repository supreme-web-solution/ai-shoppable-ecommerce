<?php

namespace App\Services\Analytics;

use App\Models\AnalyticsEvent;
use App\Models\AnalyticsRollup;
use App\Models\Embed;
use App\Models\LiveShow;
use App\Models\Playlist;
use App\Models\Product;
use App\Models\Video;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

class AnalyticsSummaryService
{
    /** @var array<string, list<string>> */
    public const METRIC_GROUPS = [
        'video' => ['video_view', 'video_complete', 'watch_time'],
        'engagement' => ['reaction', 'comment_submitted', 'share', 'save'],
        'commerce' => ['add_to_cart', 'checkout_started', 'checkout_completed', 'checkout_external_redirect'],
        'live' => ['live_show_view', 'live_reaction_spike'],
    ];

    /**
     * @return array<string, mixed>
     */
    public function build(int $teamId, string $from, string $to): array
    {
        $rollupMetrics = $this->metricsFromRollups($teamId, $from, $to);
        $rollupTotal = (int) collect($rollupMetrics)->sum('count');

        $metrics = $rollupTotal > 0
            ? $rollupMetrics
            : $this->metricsFromEvents($teamId, $from, $to);

        $eventQuery = $this->eventQuery($teamId, $from, $to);

        return [
            'metrics' => $metrics,
            'groups' => $this->groupMetrics($metrics),
            'top_events' => $this->topEvents($metrics),
            'daily_series' => $this->dailySeries($eventQuery, $from, $to),
            'platform_breakdown' => $this->platformBreakdown($eventQuery),
            'top_videos' => $this->topVideos($eventQuery),
            'totals' => [
                'events' => (int) collect($metrics)->sum('count'),
                'unique_sessions' => (int) (clone $eventQuery)
                    ->whereNotNull('session_key')
                    ->distinct('session_key')
                    ->count('session_key'),
            ],
            'catalog' => [
                'videos' => Video::query()->where('team_id', $teamId)->count(),
                'published_videos' => Video::query()->where('team_id', $teamId)->where('status', 'published')->count(),
                'products' => Product::query()->where('team_id', $teamId)->count(),
                'playlists' => Playlist::query()->where('team_id', $teamId)->count(),
                'embeds' => Embed::query()->where('team_id', $teamId)->count(),
                'live_shows' => LiveShow::query()->where('team_id', $teamId)->count(),
            ],
            'data_source' => $rollupTotal > 0 ? 'rollups' : 'events',
        ];
    }

    /**
     * @return array<string, array{count: int, value: float}>
     */
    protected function metricsFromRollups(int $teamId, string $from, string $to): array
    {
        return AnalyticsRollup::query()
            ->where('team_id', $teamId)
            ->whereBetween('metric_date', [$from, $to])
            ->get()
            ->groupBy('metric_name')
            ->map(fn (Collection $items): array => [
                'count' => (int) $items->sum('value_unsigned'),
                'value' => (float) $items->sum('value_decimal'),
            ])
            ->all();
    }

    /**
     * @return array<string, array{count: int, value: float}>
     */
    protected function metricsFromEvents(int $teamId, string $from, string $to): array
    {
        return $this->eventQuery($teamId, $from, $to)
            ->selectRaw('event_name, COUNT(*) as total')
            ->groupBy('event_name')
            ->pluck('total', 'event_name')
            ->map(fn (mixed $count): array => [
                'count' => (int) $count,
                'value' => 0.0,
            ])
            ->all();
    }

    /**
     * @param  array<string, array{count: int, value: float}>  $metrics
     * @return array<string, array<string, array{count: int, value: float}>>
     */
    protected function groupMetrics(array $metrics): array
    {
        $grouped = [];

        foreach (self::METRIC_GROUPS as $groupName => $metricNames) {
            $grouped[$groupName] = collect($metricNames)
                ->mapWithKeys(fn (string $name): array => [
                    $name => $metrics[$name] ?? ['count' => 0, 'value' => 0.0],
                ])
                ->all();
        }

        return $grouped;
    }

    /**
     * @param  array<string, array{count: int, value: float}>  $metrics
     * @return array<string, array{count: int, value: float}>
     */
    protected function topEvents(array $metrics): array
    {
        return collect($metrics)
            ->sortByDesc(fn (array $metric): int => $metric['count'])
            ->take(8)
            ->all();
    }

    /**
     * @return list<array{date: string, total: int}>
     */
    protected function dailySeries(Builder $eventQuery, string $from, string $to): array
    {
        $byDay = (clone $eventQuery)
            ->selectRaw('DATE(occurred_at) as day, COUNT(*) as total')
            ->groupBy('day')
            ->orderBy('day')
            ->pluck('total', 'day')
            ->map(fn (mixed $total): int => (int) $total);

        $series = [];
        $cursor = Carbon::parse($from)->startOfDay();
        $end = Carbon::parse($to)->startOfDay();

        while ($cursor->lte($end)) {
            $key = $cursor->toDateString();
            $series[] = [
                'date' => $key,
                'total' => (int) ($byDay[$key] ?? 0),
            ];
            $cursor->addDay();
        }

        return $series;
    }

    /**
     * @return list<array{platform: string, total: int}>
     */
    protected function platformBreakdown(Builder $eventQuery): array
    {
        return (clone $eventQuery)
            ->selectRaw("COALESCE(NULLIF(platform, ''), 'unknown') as platform, COUNT(*) as total")
            ->groupBy('platform')
            ->orderByDesc('total')
            ->get()
            ->map(fn ($row): array => [
                'platform' => (string) $row->platform,
                'total' => (int) $row->total,
            ])
            ->values()
            ->all();
    }

    /**
     * @return list<array{video_id: int, title: string, total: int}>
     */
    protected function topVideos(Builder $eventQuery): array
    {
        $rows = (clone $eventQuery)
            ->whereNotNull('video_id')
            ->selectRaw('video_id, COUNT(*) as total')
            ->groupBy('video_id')
            ->orderByDesc('total')
            ->limit(6)
            ->get();

        if ($rows->isEmpty()) {
            return [];
        }

        $titles = Video::query()
            ->whereIn('id', $rows->pluck('video_id'))
            ->pluck('title', 'id');

        return $rows
            ->map(fn ($row): array => [
                'video_id' => (int) $row->video_id,
                'title' => (string) ($titles[$row->video_id] ?? 'Video #'.$row->video_id),
                'total' => (int) $row->total,
            ])
            ->values()
            ->all();
    }

    protected function eventQuery(int $teamId, string $from, string $to): Builder
    {
        return AnalyticsEvent::query()
            ->where('team_id', $teamId)
            ->whereDate('occurred_at', '>=', $from)
            ->whereDate('occurred_at', '<=', $to);
    }
}
