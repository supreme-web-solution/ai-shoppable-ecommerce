<?php

use App\Jobs\FlushLiveCountersJob;
use App\Jobs\TransitionLiveShowsJob;
use App\Models\Video;
use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

Schedule::command('horizon:snapshot')->everyFiveMinutes();
Schedule::call(function (): void {
    $queue = config('queue.names.critical', 'critical');

    TransitionLiveShowsJob::dispatch()->onQueue($queue);
})->everyMinute();

Schedule::call(function (): void {
    Video::query()
        ->select(['id', 'team_id'])
        ->whereIn('status', ['ready', 'published'])
        ->chunkById(200, function ($videos): void {
            foreach ($videos as $video) {
                FlushLiveCountersJob::dispatch((int) $video->team_id, (int) $video->id)
                    ->onQueue(config('queue.names.analytics', 'analytics'));
            }
        });
})->everyTenMinutes();

Schedule::command('videos:prune-staging --hours=6')->hourly();
