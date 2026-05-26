<?php

namespace App\Jobs;

use App\Models\AnalyticsEvent;
use App\Models\AnalyticsRollup;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

class AggregateAnalyticsJob implements ShouldQueue
{
    use Queueable;

    public int $tries = 5;

    public function __construct(
        public int $teamId,
        public string $metricDate,
        public string $metricName,
        public ?int $videoId = null,
    ) {}

    /**
     * Execute the job.
     */
    public function handle(): void
    {
        $count = AnalyticsEvent::query()
            ->where('team_id', $this->teamId)
            ->whereDate('occurred_at', $this->metricDate)
            ->where('event_name', $this->metricName)
            ->when($this->videoId, fn ($query) => $query->where('video_id', $this->videoId))
            ->count();

        AnalyticsRollup::query()->updateOrCreate(
            [
                'team_id' => $this->teamId,
                'video_id' => $this->videoId,
                'metric_date' => $this->metricDate,
                'metric_name' => $this->metricName,
            ],
            [
                'value_unsigned' => $count,
                'value_decimal' => 0,
            ],
        );
    }
}
