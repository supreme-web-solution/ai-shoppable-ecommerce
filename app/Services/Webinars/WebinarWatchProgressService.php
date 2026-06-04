<?php

namespace App\Services\Webinars;

use App\Models\LiveShow;
use App\Models\LiveShowRegistration;

class WebinarWatchProgressService
{
    public function durationMs(LiveShow $liveShow): ?int
    {
        $settings = is_array($liveShow->settings) ? $liveShow->settings : [];
        $seconds = (int) ($settings['video_duration_seconds'] ?? 0);

        if ($seconds < 1) {
            return null;
        }

        return $seconds * 1000;
    }

    public function record(
        LiveShow $liveShow,
        LiveShowRegistration $registration,
        int $positionMs,
        bool $completed = false,
    ): LiveShowRegistration {
        $durationMs = $this->durationMs($liveShow);

        if ($durationMs === null) {
            return $registration;
        }

        $positionMs = max(0, min($positionMs, $durationMs));
        $halfMs = (int) floor($durationMs * 0.5);
        $endThresholdMs = max(0, $durationMs - 2000);

        $maxWatchMs = max((int) $registration->max_watch_ms, $positionMs);
        $updates = ['max_watch_ms' => $maxWatchMs];

        if ($registration->reached_half_at === null && ($completed || $positionMs >= $halfMs)) {
            $updates['reached_half_at'] = now();
        }

        if ($registration->watched_to_end_at === null && ($completed || $positionMs >= $endThresholdMs)) {
            $updates['watched_to_end_at'] = now();
        }

        if (count($updates) > 1) {
            $registration->update($updates);
        } elseif ($maxWatchMs !== (int) $registration->max_watch_ms) {
            $registration->update(['max_watch_ms' => $maxWatchMs]);
        }

        return $registration->fresh();
    }
}
