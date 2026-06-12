<?php

namespace App\Services\Integrations;

use App\Models\LiveShow;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

class DailyService
{
    public function enabled(): bool
    {
        if (! filter_var(config('services.daily.enabled', true), FILTER_VALIDATE_BOOLEAN)) {
            return false;
        }

        return $this->apiKey() !== '';
    }

    public function ready(): bool
    {
        return $this->enabled();
    }

    public function configurationHint(): string
    {
        if ($this->ready()) {
            return '';
        }

        $enabled = config('services.daily.enabled', true);

        if (! filter_var($enabled, FILTER_VALIDATE_BOOLEAN)) {
            return 'Daily live streaming is disabled (DAILY_ENABLED=false). Set DAILY_ENABLED=true on the server.';
        }

        if ($this->apiKey() === '') {
            return 'Daily live streaming is not configured. Set DAILY_API_KEY in your production server environment (e.g. Laravel Forge → Environment), then redeploy or run: php artisan config:cache';
        }

        return 'Daily live streaming is not configured.';
    }

    /**
     * @return array<string, mixed>
     */
    public function createRoomForLiveShow(LiveShow $liveShow): array
    {
        $roomName = $this->roomNameForLiveShow($liveShow);
        $now = now();
        $startsAt = $liveShow->starts_at instanceof Carbon ? $liveShow->starts_at : $now;
        $endsAt = $liveShow->ends_at instanceof Carbon
            ? $liveShow->ends_at
            : $startsAt->copy()->addHours(6);

        $exp = $endsAt->copy()->addHours(2)->timestamp;
        $nbf = $startsAt->isFuture()
            ? $startsAt->copy()->subMinutes(30)->timestamp
            : null;

        $properties = array_filter([
            'owner_only_broadcast' => true,
            // Viewers land straight in the room — no Daily welcome / prejoin screens.
            'enable_prejoin_ui' => false,
            'enable_people_ui' => false,
            'enable_hidden_participants' => true,
            'enable_knocking' => false,
            'enable_chat' => false,
            'enable_emoji_reactions' => false,
            'enable_hand_raising' => false,
            'enable_screenshare' => true,
            'max_participants' => max(2, (int) config('services.daily.max_participants', 200)),
            'exp' => $exp,
            'nbf' => $nbf,
        ], fn (mixed $value): bool => $value !== null);

        $response = $this->client()->post('/rooms', [
            'name' => $roomName,
            'privacy' => 'private',
            'properties' => $properties,
        ]);

        if ($response->status() === 400 && str_contains((string) $response->body(), 'already exists')) {
            $existing = $this->client()->get("/rooms/{$roomName}");

            if ($existing->successful()) {
                return $existing->json();
            }
        }

        if (! $response->successful()) {
            Log::warning('Daily room creation failed', [
                'live_show_id' => $liveShow->id,
                'status' => $response->status(),
                'body' => $response->body(),
            ]);

            $response->throw();
        }

        return $response->json();
    }

    /**
     * Ensure the Daily room exists (create or recreate if missing/expired) and return its name + URL.
     *
     * @return array{name: string, url: string}
     */
    public function provisionRoomForLiveShow(LiveShow $liveShow): array
    {
        $roomName = $this->roomNameForLiveShow($liveShow);

        if ($this->roomExists($roomName)) {
            $this->syncRoomProperties($roomName);
            $roomUrl = trim((string) data_get($liveShow->settings, 'daily.room_url', ''));
            $existing = $this->getRoom($roomName);

            if ($roomUrl === '' && is_array($existing)) {
                $roomUrl = trim((string) ($existing['url'] ?? ''));
            }

            return [
                'name' => $roomName,
                'url' => $roomUrl,
            ];
        }

        $room = $this->createRoomForLiveShow($liveShow);
        $resolvedName = trim((string) ($room['name'] ?? $roomName));
        $resolvedUrl = trim((string) ($room['url'] ?? ''));

        abort_if($resolvedName === '' || $resolvedUrl === '', 422, 'Daily did not return a room name or URL.');

        return [
            'name' => $resolvedName,
            'url' => $resolvedUrl,
        ];
    }

    public function syncLiveShowRoom(LiveShow $liveShow): LiveShow
    {
        $room = $this->provisionRoomForLiveShow($liveShow);
        $settings = is_array($liveShow->settings) ? $liveShow->settings : [];
        $existingEndpoints = data_get($settings, 'daily.streaming_endpoints');
        $settings['source_type'] = 'daily';
        $settings['daily'] = array_filter([
            'room_name' => $room['name'],
            'room_url' => $room['url'],
            'streaming_endpoints' => is_array($existingEndpoints) ? $existingEndpoints : null,
        ], fn (mixed $value): bool => $value !== null && $value !== '');

        $liveShow->update(['settings' => $settings]);

        return $liveShow->fresh();
    }

    public function ensureLiveShowRoomReady(LiveShow $liveShow): LiveShow
    {
        $roomName = trim((string) data_get($liveShow->settings, 'daily.room_name', ''));

        if ($roomName !== '' && $this->roomExists($roomName)) {
            $this->syncRoomProperties($roomName);

            return $liveShow;
        }

        return $this->syncLiveShowRoom($liveShow);
    }

    public function roomExists(string $roomName): bool
    {
        return $this->getRoom($roomName) !== null;
    }

    /**
     * @return array<string, mixed>|null
     */
    public function getRoom(string $roomName): ?array
    {
        $roomName = trim($roomName);

        if ($roomName === '') {
            return null;
        }

        $response = $this->client()->get("/rooms/{$roomName}");

        return $response->successful() ? $response->json() : null;
    }

    public function createHostToken(LiveShow $liveShow, string $userName): string
    {
        $roomName = $this->requireRoomName($liveShow);
        $this->syncRoomProperties($roomName);

        return $this->createMeetingToken($roomName, array_filter([
            'is_owner' => true,
            'user_name' => $this->sanitizeUserName($userName, 'Host'),
            'exp' => $this->tokenExpiry($liveShow),
            'enable_prejoin_ui' => false,
            'enable_recording_ui' => false,
            'start_video_off' => false,
            'start_audio_off' => false,
        ]));
    }

    public function createViewerToken(LiveShow $liveShow, string $userName): string
    {
        $roomName = $this->requireRoomName($liveShow);
        $this->syncRoomProperties($roomName);

        return $this->createMeetingToken($roomName, array_filter([
            'is_owner' => false,
            'user_name' => $this->sanitizeUserName($userName, 'Viewer'),
            'exp' => $this->tokenExpiry($liveShow),
            'enable_prejoin_ui' => false,
            'start_audio_off' => true,
            'start_video_off' => true,
            'enable_screenshare' => false,
            'enable_recording_ui' => false,
        ]));
    }

    public function roomNameForLiveShow(LiveShow $liveShow): string
    {
        $existing = trim((string) data_get($liveShow->settings, 'daily.room_name', ''));

        if ($existing !== '') {
            return $existing;
        }

        return 'ls-'.$liveShow->team_id.'-'.$liveShow->id.'-'.Str::lower(Str::random(6));
    }

    /**
     * @param  array<string, mixed>  $properties
     */
    protected function createMeetingToken(string $roomName, array $properties): string
    {
        $response = $this->client()->post('/meeting-tokens', [
            'properties' => array_merge(['room_name' => $roomName], $properties),
        ]);

        if (! $response->successful()) {
            Log::warning('Daily meeting token creation failed', [
                'room_name' => $roomName,
                'status' => $response->status(),
                'body' => $response->body(),
            ]);

            $response->throw();
        }

        return (string) $response->json('token');
    }

    protected function requireRoomName(LiveShow $liveShow): string
    {
        $roomName = trim((string) data_get($liveShow->settings, 'daily.room_name', ''));
        abort_if($roomName === '', 422, 'Daily room has not been provisioned for this live cast.');

        return $roomName;
    }

    /**
     * Viewers should enter the room immediately when the page loads.
     */
    protected function syncRoomProperties(string $roomName): void
    {
        $response = $this->client()->post("/rooms/{$roomName}", [
            'properties' => [
                'enable_prejoin_ui' => false,
                'enable_people_ui' => false,
                'enable_hidden_participants' => true,
                'enable_knocking' => false,
                'owner_only_broadcast' => true,
                'enable_chat' => false,
                'enable_emoji_reactions' => false,
                'enable_hand_raising' => false,
            ],
        ]);

        if (! $response->successful()) {
            Log::warning('Daily room property sync failed', [
                'room_name' => $roomName,
                'status' => $response->status(),
                'body' => $response->body(),
            ]);
        }
    }

    protected function tokenExpiry(LiveShow $liveShow): int
    {
        $endsAt = $liveShow->ends_at instanceof Carbon
            ? $liveShow->ends_at
            : ($liveShow->starts_at instanceof Carbon ? $liveShow->starts_at->copy()->addHours(6) : now()->addHours(6));

        return $endsAt->copy()->addHours(4)->timestamp;
    }

    protected function sanitizeUserName(string $userName, string $fallback): string
    {
        $trimmed = trim($userName);

        if ($trimmed === '') {
            return $fallback;
        }

        return Str::limit($trimmed, 36, '');
    }

    protected function apiKey(): string
    {
        return trim((string) config('services.daily.api_key', ''));
    }

    protected function client()
    {
        return Http::baseUrl(rtrim((string) config('services.daily.base_url', 'https://api.daily.co/v1'), '/'))
            ->withToken($this->apiKey())
            ->acceptJson()
            ->timeout((int) config('services.daily.timeout', 30));
    }
}
