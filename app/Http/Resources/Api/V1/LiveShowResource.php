<?php

namespace App\Http\Resources\Api\V1;

use App\Support\ExternalVideoUrl;
use App\Services\Webinars\WebinarOfferService;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class LiveShowResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $settings = is_array($this->settings) ? $this->settings : [];
        $dailySettings = $this->resolvedDailySettings($settings);
        $thumbnailUrl = data_get($settings, 'thumbnail_url') ?: optional($this->video)->thumbnail_url;
        $resolvedVideoUrl = $this->resolvedVideoUrl($settings);
        $videoPlayback = ExternalVideoUrl::parse($resolvedVideoUrl);

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
    protected function resolvedVideoUrl(array $settings): ?string
    {
        $override = trim((string) data_get($settings, 'video_url', ''));

        if ($override !== '') {
            return $override;
        }

        $playback = trim((string) ($this->video?->playback_url ?? ''));

        return $playback !== '' ? $playback : null;
    }

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
}
