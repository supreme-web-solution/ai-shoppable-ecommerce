<?php

namespace App\Http\Resources\Api\V1;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class VideoResource extends JsonResource
{
    /**
     * Transform the resource into an array.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'team_id' => $this->team_id,
            'title' => $this->title,
            'description' => $this->description,
            'source' => $this->source,
            'status' => $this->status,
            'visibility' => $this->visibility,
            'playback_url' => $this->playback_url,
            'cloudinary_public_id' => $this->cloudinary_public_id,
            'thumbnail_url' => $this->thumbnail_url,
            'duration_seconds' => $this->duration_seconds,
            'published_at' => $this->published_at,
            'metadata' => $this->metadata,
            'product_tags' => VideoProductTagResource::collection($this->whenLoaded('productTags')),
            'created_at' => $this->created_at,
            'updated_at' => $this->updated_at,
        ];
    }
}
