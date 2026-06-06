<?php

namespace App\Services\Analytics;

use App\Models\AnalyticsEvent;
use App\Models\AnalyticsRollup;
use App\Models\Cart;
use App\Models\Embed;
use App\Models\LiveShow;
use App\Models\Order;
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
            'top_videos_by_revenue' => $this->topVideosByRevenue($teamId, $from, $to),
            'commerce_roi' => $this->commerceRoi($teamId, $from, $to, $metrics),
            'video_conversion' => $this->videoConversion($teamId, $from, $to, $eventQuery),
            'abandoned_carts' => $this->abandonedCartsSummary($teamId, $from, $to),
            'period_comparison' => $this->periodComparison($teamId, $from, $to),
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

    /**
     * @param  array<string, array{count: int, value: float}>  $metrics
     * @return array<string, mixed>
     */
    protected function commerceRoi(int $teamId, string $from, string $to, array $metrics): array
    {
        $views = (int) ($metrics['video_view']['count'] ?? 0);
        $carts = (int) ($metrics['add_to_cart']['count'] ?? 0);
        $checkouts = (int) ($metrics['checkout_completed']['count'] ?? 0);

        $paidOrders = Order::query()
            ->where('team_id', $teamId)
            ->where('status', 'paid')
            ->whereDate('ordered_at', '>=', $from)
            ->whereDate('ordered_at', '<=', $to);

        $totalRevenue = (float) (clone $paidOrders)->sum('total_amount');
        $paidOrderCount = (int) (clone $paidOrders)->count();

        $attributedRevenue = (float) Order::query()
            ->where('team_id', $teamId)
            ->where('status', 'paid')
            ->whereDate('ordered_at', '>=', $from)
            ->whereDate('ordered_at', '<=', $to)
            ->get()
            ->filter(fn (Order $order): bool => data_get($order->metadata, 'attribution.video_id') !== null)
            ->sum('total_amount');

        $eventRevenue = (float) ($metrics['checkout_completed']['value'] ?? 0);

        return [
            'total_revenue' => round($totalRevenue, 2),
            'attributed_revenue' => round($attributedRevenue, 2),
            'event_revenue' => round($eventRevenue, 2),
            'paid_orders' => $paidOrderCount,
            'funnel' => [
                'video_views' => $views,
                'add_to_cart' => $carts,
                'checkouts_completed' => $checkouts,
                'view_to_cart_rate' => $views > 0 ? round(($carts / $views) * 100, 2) : 0.0,
                'cart_to_checkout_rate' => $carts > 0 ? round(($checkouts / $carts) * 100, 2) : 0.0,
                'view_to_checkout_rate' => $views > 0 ? round(($checkouts / $views) * 100, 2) : 0.0,
            ],
        ];
    }

    /**
     * @return list<array{video_id: int, title: string, revenue: float, orders: int, views: int, add_to_cart: int, checkouts: int, conversion_rate: float}>
     */
    protected function videoConversion(int $teamId, string $from, string $to, Builder $eventQuery): array
    {
        $eventRows = (clone $eventQuery)
            ->whereNotNull('video_id')
            ->whereIn('event_name', ['video_view', 'add_to_cart', 'checkout_completed'])
            ->selectRaw('video_id, event_name, COUNT(*) as total')
            ->groupBy('video_id', 'event_name')
            ->get();

        $stats = [];

        foreach ($eventRows as $row) {
            $videoId = (int) $row->video_id;

            if (! isset($stats[$videoId])) {
                $stats[$videoId] = [
                    'video_id' => $videoId,
                    'views' => 0,
                    'add_to_cart' => 0,
                    'checkouts' => 0,
                    'revenue' => 0.0,
                    'orders' => 0,
                ];
            }

            match ($row->event_name) {
                'video_view' => $stats[$videoId]['views'] = (int) $row->total,
                'add_to_cart' => $stats[$videoId]['add_to_cart'] = (int) $row->total,
                'checkout_completed' => $stats[$videoId]['checkouts'] = (int) $row->total,
                default => null,
            };
        }

        Order::query()
            ->where('team_id', $teamId)
            ->where('status', 'paid')
            ->whereDate('ordered_at', '>=', $from)
            ->whereDate('ordered_at', '<=', $to)
            ->get()
            ->each(function (Order $order) use (&$stats): void {
                $videoId = data_get($order->metadata, 'attribution.video_id');

                if (! is_numeric($videoId)) {
                    return;
                }

                $videoId = (int) $videoId;

                if (! isset($stats[$videoId])) {
                    $stats[$videoId] = [
                        'video_id' => $videoId,
                        'views' => 0,
                        'add_to_cart' => 0,
                        'checkouts' => 0,
                        'revenue' => 0.0,
                        'orders' => 0,
                    ];
                }

                $stats[$videoId]['revenue'] += (float) $order->total_amount;
                $stats[$videoId]['orders']++;
            });

        if ($stats === []) {
            return [];
        }

        $titles = Video::query()
            ->whereIn('id', array_keys($stats))
            ->pluck('title', 'id');

        return collect($stats)
            ->map(function (array $row) use ($titles): array {
                $views = (int) $row['views'];

                return [
                    'video_id' => $row['video_id'],
                    'title' => (string) ($titles[$row['video_id']] ?? 'Video #'.$row['video_id']),
                    'revenue' => round((float) $row['revenue'], 2),
                    'orders' => (int) $row['orders'],
                    'views' => $views,
                    'add_to_cart' => (int) $row['add_to_cart'],
                    'checkouts' => (int) $row['checkouts'],
                    'conversion_rate' => $views > 0
                        ? round(((int) $row['checkouts'] / $views) * 100, 2)
                        : 0.0,
                ];
            })
            ->sortByDesc(fn (array $row): float => $row['revenue'])
            ->values()
            ->take(12)
            ->all();
    }

    /**
     * @return list<array{video_id: int, title: string, revenue: float, orders: int}>
     */
    protected function topVideosByRevenue(int $teamId, string $from, string $to): array
    {
        $rows = Order::query()
            ->where('team_id', $teamId)
            ->where('status', 'paid')
            ->whereDate('ordered_at', '>=', $from)
            ->whereDate('ordered_at', '<=', $to)
            ->get()
            ->groupBy(fn (Order $order): int => (int) data_get($order->metadata, 'attribution.video_id', 0))
            ->filter(fn (Collection $orders, int $videoId): bool => $videoId > 0)
            ->map(fn (Collection $orders, int $videoId): array => [
                'video_id' => $videoId,
                'revenue' => round((float) $orders->sum('total_amount'), 2),
                'orders' => $orders->count(),
            ])
            ->sortByDesc('revenue')
            ->take(6)
            ->values();

        if ($rows->isEmpty()) {
            return [];
        }

        $titles = Video::query()
            ->whereIn('id', $rows->pluck('video_id'))
            ->pluck('title', 'id');

        return $rows
            ->map(fn (array $row): array => [
                'video_id' => $row['video_id'],
                'title' => (string) ($titles[$row['video_id']] ?? 'Video #'.$row['video_id']),
                'revenue' => $row['revenue'],
                'orders' => $row['orders'],
            ])
            ->all();
    }

    /**
     * @return array{count: int, recoverable_value: float, items: int, recent: list<array<string, mixed>>}
     */
    protected function abandonedCartsSummary(int $teamId, string $from, string $to): array
    {
        $idleHours = 1;

        $carts = Cart::query()
            ->where('team_id', $teamId)
            ->whereHas('items')
            ->whereDate('updated_at', '>=', $from)
            ->whereDate('updated_at', '<=', $to)
            ->where(function (Builder $query) use ($idleHours): void {
                $query->where('status', 'abandoned')
                    ->orWhere(function (Builder $nested) use ($idleHours): void {
                        $nested->where('status', 'active')
                            ->where('updated_at', '<', now()->subHours($idleHours));
                    });
            })
            ->withCount('items')
            ->with(['items.product'])
            ->latest('updated_at')
            ->get();

        return [
            'count' => $carts->count(),
            'recoverable_value' => round((float) $carts->sum('total_amount'), 2),
            'items' => (int) $carts->sum('items_count'),
            'recent' => $carts->take(5)->map(fn (Cart $cart): array => [
                'cart_id' => $cart->id,
                'session_key' => $cart->session_key,
                'total_amount' => round((float) $cart->total_amount, 2),
                'currency' => $cart->currency,
                'items_count' => (int) $cart->items_count,
                'status' => $cart->status,
                'updated_at' => $cart->updated_at?->toIso8601String(),
                'preview' => $cart->items->take(2)->map(fn ($item): string => (string) ($item->product?->title ?? 'Item'))->values()->all(),
            ])->values()->all(),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    protected function periodComparison(int $teamId, string $from, string $to): array
    {
        $currentStart = Carbon::parse($from)->startOfDay();
        $currentEnd = Carbon::parse($to)->startOfDay();
        $days = max($currentStart->diffInDays($currentEnd) + 1, 1);

        $previousEnd = $currentStart->copy()->subDay();
        $previousStart = $previousEnd->copy()->subDays($days - 1);

        $previousFrom = $previousStart->toDateString();
        $previousTo = $previousEnd->toDateString();

        $current = $this->executiveSnapshot($teamId, $from, $to);
        $previous = $this->executiveSnapshot($teamId, $previousFrom, $previousTo);

        return [
            'current' => $current,
            'previous' => $previous,
            'previous_from' => $previousFrom,
            'previous_to' => $previousTo,
            'changes' => [
                'revenue_pct' => $this->pctChange($previous['revenue'], $current['revenue']),
                'orders_pct' => $this->pctChange((float) $previous['orders'], (float) $current['orders']),
                'views_pct' => $this->pctChange((float) $previous['views'], (float) $current['views']),
                'checkouts_pct' => $this->pctChange((float) $previous['checkouts'], (float) $current['checkouts']),
                'abandoned_carts_pct' => $this->pctChange((float) $previous['abandoned_carts'], (float) $current['abandoned_carts']),
            ],
        ];
    }

    /**
     * @return array{revenue: float, orders: int, views: int, checkouts: int, abandoned_carts: int, abandoned_value: float}
     */
    protected function executiveSnapshot(int $teamId, string $from, string $to): array
    {
        $rollupMetrics = $this->metricsFromRollups($teamId, $from, $to);
        $rollupTotal = (int) collect($rollupMetrics)->sum('count');
        $metrics = $rollupTotal > 0
            ? $rollupMetrics
            : $this->metricsFromEvents($teamId, $from, $to);

        $roi = $this->commerceRoi($teamId, $from, $to, $metrics);
        $abandoned = $this->abandonedCartsSummary($teamId, $from, $to);

        return [
            'revenue' => (float) $roi['total_revenue'],
            'orders' => (int) $roi['paid_orders'],
            'views' => (int) $roi['funnel']['video_views'],
            'checkouts' => (int) $roi['funnel']['checkouts_completed'],
            'abandoned_carts' => (int) $abandoned['count'],
            'abandoned_value' => (float) $abandoned['recoverable_value'],
        ];
    }

    protected function pctChange(float $previous, float $current): ?float
    {
        if ($previous <= 0 && $current <= 0) {
            return 0.0;
        }

        if ($previous <= 0) {
            return 100.0;
        }

        return round((($current - $previous) / $previous) * 100, 1);
    }

    protected function eventQuery(int $teamId, string $from, string $to): Builder
    {
        return AnalyticsEvent::query()
            ->where('team_id', $teamId)
            ->whereDate('occurred_at', '>=', $from)
            ->whereDate('occurred_at', '<=', $to);
    }
}
