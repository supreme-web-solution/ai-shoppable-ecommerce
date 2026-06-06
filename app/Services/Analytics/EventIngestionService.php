<?php

namespace App\Services\Analytics;

use App\Models\AnalyticsEvent;

class EventIngestionService
{
    public function __construct(
        protected RollupService $rollupService,
    ) {}

    /**
     * @param  array<string, mixed>  $payload
     */
    public function ingest(array $payload, ?float $revenueAmount = null): AnalyticsEvent
    {
        $event = AnalyticsEvent::query()->create([
            'team_id' => $payload['team_id'],
            'video_id' => $payload['video_id'] ?? null,
            'user_id' => $payload['user_id'] ?? null,
            'session_key' => $payload['session_key'] ?? null,
            'event_name' => $payload['event_name'],
            'source' => $payload['source'] ?? 'player',
            'platform' => $payload['platform'] ?? null,
            'payload' => $payload['payload'] ?? null,
            'occurred_at' => $payload['occurred_at'] ?? now(),
        ]);

        $revenueDelta = 0.0;

        if ($event->event_name === 'checkout_completed') {
            $revenueDelta = $revenueAmount ?? (float) data_get($event->payload, 'total_amount', 0);
        }

        $this->rollupService->increment(
            teamId: $event->team_id,
            metricDate: $event->occurred_at->toDateString(),
            metricName: $event->event_name,
            videoId: $event->video_id,
            revenueDelta: max($revenueDelta, 0),
        );

        return $event;
    }
}
