<?php

namespace App\Jobs;

use App\Models\LiveShow;
use App\Models\Video;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Collection;

class TransitionLiveShowsJob implements ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    public function handle(): void
    {
        $now = now();

        /** @var Collection<int, LiveShow> $toGoLive */
        $toGoLive = LiveShow::query()
            ->where('status', 'scheduled')
            ->where('starts_at', '<=', $now)
            ->get();

        foreach ($toGoLive as $show) {
            $show->update([
                'status' => 'live',
            ]);

            if ($show->video_id) {
                Video::query()
                    ->whereKey($show->video_id)
                    ->where('team_id', $show->team_id)
                    ->update([
                        'status' => 'published',
                        'published_at' => $now,
                    ]);
            }
        }

        LiveShow::query()
            ->where('status', 'live')
            ->whereNotNull('ends_at')
            ->where('ends_at', '<=', $now)
            ->update([
                'status' => 'ended',
            ]);
    }
}
