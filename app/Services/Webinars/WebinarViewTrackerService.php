<?php

namespace App\Services\Webinars;

use App\Models\LiveShow;
use App\Models\LiveShowRegistration;
use App\Models\LiveShowViewSession;
use Illuminate\Http\Request;

class WebinarViewTrackerService
{
    public function shouldTrackView(Request $request): bool
    {
        $raw = $request->query('track_view', '1');

        return filter_var($raw, FILTER_VALIDATE_BOOL, FILTER_NULL_ON_FAILURE) ?? true;
    }

    public function resolveViewerKey(Request $request, LiveShow $liveShow): ?string
    {
        $registrationId = (int) $request->query('registration_id', 0);

        if ($registrationId > 0) {
            $exists = LiveShowRegistration::query()
                ->whereKey($registrationId)
                ->where('live_show_id', $liveShow->id)
                ->exists();

            if ($exists) {
                return "reg:{$registrationId}";
            }
        }

        $guestKey = trim((string) $request->query('viewer_key', ''));

        if ($guestKey !== '' && preg_match('/^[a-zA-Z0-9_-]{8,64}$/', $guestKey) === 1) {
            return "guest:{$guestKey}";
        }

        return null;
    }

    public function recordRoomView(LiveShow $liveShow, string $viewerKey): bool
    {
        $session = LiveShowViewSession::query()
            ->where('live_show_id', $liveShow->id)
            ->where('viewer_key', $viewerKey)
            ->first();

        if ($session !== null) {
            $session->update(['last_seen_at' => now()]);

            return false;
        }

        LiveShowViewSession::query()->create([
            'live_show_id' => $liveShow->id,
            'viewer_key' => $viewerKey,
            'first_seen_at' => now(),
            'last_seen_at' => now(),
        ]);

        $settings = is_array($liveShow->settings) ? $liveShow->settings : [];
        $settings['views_count'] = (int) ($settings['views_count'] ?? 0) + 1;
        $liveShow->forceFill(['settings' => $settings])->save();

        return true;
    }
}
