<?php

namespace App\Services\Analytics;

use App\Models\AnalyticsEvent;
use App\Models\AnalyticsRollup;
use Illuminate\Support\Carbon;

class RollupService
{
    public function increment(
        int $teamId,
        string $metricDate,
        string $metricName,
        ?int $videoId = null,
        float $revenueDelta = 0.0,
    ): AnalyticsRollup {
        $rollup = AnalyticsRollup::query()
            ->where('team_id', $teamId)
            ->where('metric_date', $metricDate)
            ->where('metric_name', $metricName)
            ->when(
                $videoId === null,
                fn ($query) => $query->whereNull('video_id'),
                fn ($query) => $query->where('video_id', $videoId),
            )
            ->first();

        if ($rollup) {
            $rollup->increment('value_unsigned');

            if ($revenueDelta > 0) {
                $rollup->increment('value_decimal', $revenueDelta);
            }

            return $rollup->refresh();
        }

        return AnalyticsRollup::query()->create([
            'team_id' => $teamId,
            'video_id' => $videoId,
            'metric_date' => $metricDate,
            'metric_name' => $metricName,
            'value_unsigned' => 1,
            'value_decimal' => max($revenueDelta, 0),
        ]);
    }

    public function rebuildForTeam(int $teamId, ?string $from = null, ?string $to = null): int
    {
        $fromDate = $from ?? AnalyticsEvent::query()->where('team_id', $teamId)->min('occurred_at');
        $toDate = $to ?? now()->toDateString();

        if ($fromDate === null) {
            return 0;
        }

        $from = Carbon::parse($fromDate)->toDateString();
        $to = Carbon::parse($toDate)->toDateString();
        $updated = 0;

        $rows = AnalyticsEvent::query()
            ->where('team_id', $teamId)
            ->whereDate('occurred_at', '>=', $from)
            ->whereDate('occurred_at', '<=', $to)
            ->selectRaw('DATE(occurred_at) as metric_date, event_name, video_id, COUNT(*) as total')
            ->groupBy('metric_date', 'event_name', 'video_id')
            ->orderBy('metric_date')
            ->get();

        foreach ($rows as $row) {
            AnalyticsRollup::query()->updateOrCreate(
                [
                    'team_id' => $teamId,
                    'video_id' => $row->video_id,
                    'metric_date' => $row->metric_date,
                    'metric_name' => $row->event_name,
                ],
                [
                    'value_unsigned' => (int) $row->total,
                    'value_decimal' => 0,
                ],
            );
            $updated++;
        }

        return $updated;
    }
}
