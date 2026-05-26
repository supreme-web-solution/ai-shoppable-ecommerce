<?php

namespace App\Http\Resources\Api\V1;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class CommentResource extends JsonResource
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
            'video_id' => $this->video_id,
            'user_id' => $this->user_id,
            'parent_id' => $this->parent_id,
            'body' => $this->body,
            'is_pinned' => $this->is_pinned,
            'is_hidden' => $this->is_hidden,
            'metadata' => $this->metadata,
            'replies' => self::collection($this->whenLoaded('replies')),
            'created_at' => $this->created_at,
            'updated_at' => $this->updated_at,
        ];
    }
}
