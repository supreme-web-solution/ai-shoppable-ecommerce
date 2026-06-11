<?php

namespace App\Http\Resources\Api\V1;

use App\Services\Integrations\RestreamService;
use App\Services\LiveBroadcast\LiveBroadcastSessionService;
use App\Support\ExternalVideoUrl;
use App\Services\Webinars\WebinarOfferService;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class LiveShowResource extends JsonResource
{
    /**
     * Transform the resource into an array.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $settings = is_array($this->settings) ? $this->settings : [];
        $restreamSettings = $this->resolvedRestreamSettings($settings);
        $dailySettings = $this->resolvedDailySettings($settings);
        $thumbnailUrl = data_get($settings, 'thumbnail_url') ?: optional($this->video)->thumbnail_url;
        $resolvedVideoUrl = $this->resolvedVideoUrl($settings, $restreamSettings);
        $videoPlayback = ExternalVideoUrl::parse($resolvedVideoUrl);
        $videoPlayback = $this->enrichRestreamPlayback($settings, $restreamSettings, $videoPlayback);

        if ($thumbnailUrl === null && is_array($videoPlayback)) {
            $thumbnailUrl = $videoPlayback['thumbnail_url'] ?? null;
        }

        return [
            'id' => $this->id,
            'team_id' => $this->team_id,
            'video_id' => $this->video_id,
            'title' => $this->title,
            'description' => $this->description,
            'status' => $this->status,
            'starts_at' => $this->starts_at,
            'ends_at' => $this->ends_at,
            'is_premiere' => $this->is_premiere,
            'settings' => $settings,
            'host_name' => data_get($settings, 'host_name'),
            'thumbnail_url' => $thumbnailUrl,
            'video_url' => $resolvedVideoUrl,
            'video_playback' => $videoPlayback,
            'source_type' => data_get($settings, 'source_type', 'upload'),
            'restream' => $restreamSettings,
            'daily' => $dailySettings,
            'registration_title' => data_get($settings, 'registration_title'),
            'registration_description' => data_get($settings, 'registration_description'),
            'room_title' => data_get($settings, 'room_title'),
            'chat_enabled' => (bool) data_get($settings, 'chat_enabled', true),
            'ai_assistant_enabled' => (bool) data_get($settings, 'ai_assistant_enabled', false),
            'video_duration_seconds' => data_get($settings, 'video_duration_seconds'),
            'views_count' => (int) data_get($settings, 'views_count', 0),
            'registration_url' => url("/webinars/{$this->id}/register"),
            'room_url' => url("/webinars/{$this->id}/room"),
            'featured_products' => $this->whenLoaded('featuredProducts', function () {
                $offerService = app(WebinarOfferService::class);

                return $offerService->formatOffersForLiveShow($this->resource);
            }),
            'video' => $this->whenLoaded('video', function (): array {
                return [
                    'id' => $this->video?->id,
                    'title' => $this->video?->title,
                    'thumbnail_url' => $this->video?->thumbnail_url,
                    'playback_url' => $this->video?->playback_url,
                ];
            }),
            'registrants_count' => (int) ($this->registrations_count ?? 0),
            'conversations_count' => (int) ($this->conversations_count ?? 0),
            'messages_count' => (int) ($this->messages_count ?? 0),
            'watched_half_count' => (int) ($this->watched_half_count ?? 0),
            'watched_end_count' => (int) ($this->watched_end_count ?? 0),
            'created_at' => $this->created_at,
            'updated_at' => $this->updated_at,
        ];
    }

    /**
     * @param  array<string, mixed>  $settings
     */
    /**
     * @param  array<string, mixed>  $settings
     * @param  array<string, mixed>|null  $restreamSettings
     */
    protected function resolvedVideoUrl(array $settings, ?array $restreamSettings): ?string
    {
        $override = trim((string) data_get($settings, 'video_url', ''));

        if ($override !== '') {
            return $override;
        }

        $playerUrl = trim((string) data_get($restreamSettings, 'player_url', ''));

        if ($playerUrl !== '') {
            return $playerUrl;
        }

        $playback = trim((string) ($this->video?->playback_url ?? ''));

        return $playback !== '' ? $playback : null;
    }

    /**
     * @param  array<string, mixed>  $settings
     * @return array<string, mixed>|null
     */
    protected function resolvedRestreamSettings(array $settings): ?array
    {
        $restream = data_get($settings, 'restream');

        if (! is_array($restream)) {
            return null;
        }

        $playerUrl = trim((string) ($restream['player_url'] ?? ''));

        if ($playerUrl === '') {
            $resolved = app(RestreamService::class)->resolvePlayerUrl();

            if ($resolved !== null) {
                $restream['player_url'] = $resolved;
            }
        }

        if (data_get($settings, 'source_type') === 'restream') {
            $restream['player_url'] = app(LiveBroadcastSessionService::class)
                ->playbackUrlForLiveShow($this->id);
        }

        return $restream;
    }

    /**
     * Ensure live streams expose playback URLs for the webinar room.
     *
     * @param  array<string, mixed>  $settings
     * @param  array<string, mixed>|null  $restreamSettings
     * @param  array<string, mixed>|null  $videoPlayback
     * @return array<string, mixed>|null
     */
    /**
     * @param  array<string, mixed>  $settings
     * @return array<string, mixed>|null
     */
    protected function resolvedDailySettings(array $settings): ?array
    {
        if (data_get($settings, 'source_type') !== 'daily') {
            return null;
        }

        $daily = data_get($settings, 'daily');

        if (! is_array($daily)) {
            return null;
        }

        return array_filter([
            'room_name' => isset($daily['room_name']) ? trim((string) $daily['room_name']) : null,
            'room_url' => isset($daily['room_url']) ? trim((string) $daily['room_url']) : null,
        ], fn (mixed $value): bool => $value !== null && $value !== '');
    }

    protected function enrichRestreamPlayback(array $settings, ?array $restreamSettings, ?array $videoPlayback): ?array
    {
        if (data_get($settings, 'source_type') !== 'restream') {
            return $videoPlayback;
        }

        $playerUrl = trim((string) data_get($restreamSettings, 'player_url', ''));

        if ($playerUrl === '') {
            return $videoPlayback;
        }

        $isHls = str_contains($playerUrl, '.m3u8');

        return [
            'provider' => ExternalVideoUrl::PROVIDER_RESTREAM,
            'source_url' => $playerUrl,
            'embed_url' => $isHls ? null : $playerUrl,
            'direct_url' => $isHls ? $playerUrl : null,
            'thumbnail_url' => is_array($videoPlayback) ? ($videoPlayback['thumbnail_url'] ?? null) : null,
        ];
    }
}
