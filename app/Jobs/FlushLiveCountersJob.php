<?php

namespace App\Jobs;

use App\Models\AnalyticsRollup;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Redis;

class FlushLiveCountersJob implements ShouldQueue
{
    use Queueable;

    public function __construct(
        public int $teamId,
        public int $videoId,
    ) {}

    /**
     * Execute the job.
     */
    public function handle(): void
    {
        $key = "live:{$this->teamId}:{$this->videoId}:reactions";
        $count = (int) (Redis::get($key) ?? 0);

        if ($count <= 0) {
            return;
        }

        AnalyticsRollup::query()->updateOrCreate(
            [
                'team_id' => $this->teamId,
                'video_id' => $this->videoId,
                'metric_date' => now()->toDateString(),
                'metric_name' => 'live_reactions',
            ],
            [
                'value_unsigned' => $count,
                'value_decimal' => 0,
            ],
        );

        Redis::del($key);
    }
}
