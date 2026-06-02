<?php

namespace App\Support;

class PlaylistPlaybackSettings
{
    /**
     * @param  array<string, mixed>|null  $settings
     * @return array{auto_advance_enabled: bool, loops_per_video: int}
     */
    public static function normalize(?array $settings): array
    {
        return [
            'auto_advance_enabled' => (bool) data_get($settings, 'auto_advance_enabled', false),
            'loops_per_video' => max(1, min(20, (int) data_get($settings, 'loops_per_video', 1))),
        ];
    }
}
