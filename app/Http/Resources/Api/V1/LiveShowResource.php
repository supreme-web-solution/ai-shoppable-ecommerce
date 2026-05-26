<?php

namespace App\Http\Resources\Api\V1;

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
        $thumbnailUrl = data_get($settings, 'thumbnail_url') ?: optional($this->video)->thumbnail_url;

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
            'video_url' => data_get($settings, 'video_url'),
            'source_type' => data_get($settings, 'source_type', 'upload'),
            'registration_title' => data_get($settings, 'registration_title'),
            'registration_description' => data_get($settings, 'registration_description'),
            'room_title' => data_get($settings, 'room_title'),
            'chat_enabled' => (bool) data_get($settings, 'chat_enabled', true),
            'ai_assistant_enabled' => (bool) data_get($settings, 'ai_assistant_enabled', false),
            'views_count' => (int) data_get($settings, 'views_count', 0),
            'registration_url' => url("/webinars/{$this->id}/register"),
            'room_url' => url("/webinars/{$this->id}/room"),
            'featured_products' => $this->whenLoaded('featuredProducts'),
            'video' => $this->whenLoaded('video', function (): array {
                return [
                    'id' => $this->video?->id,
                    'title' => $this->video?->title,
                    'thumbnail_url' => $this->video?->thumbnail_url,
                    'playback_url' => $this->video?->playback_url,
                ];
            }),
            'registrants_count' => (int) ($this->registrations_count ?? 0),
            'messages_count' => (int) ($this->messages_count ?? 0),
            'created_at' => $this->created_at,
            'updated_at' => $this->updated_at,
        ];
    }
}
