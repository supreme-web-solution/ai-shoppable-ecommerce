<?php

namespace App\Http\Resources\Api\V1;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class EmbedResource extends JsonResource
{
    protected function scriptEmbedCode(): string
    {
        $src = e(url('/embed/embed.js'));
        $slug = e($this->slug);
        $type = e($this->type);

        return '<script async src="'.$src.'" data-embed="'.$slug.'" data-type="'.$type.'" data-height="700"></script>';
    }

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
            'playlist_id' => $this->playlist_id,
            'video_id' => $this->video_id,
            'name' => $this->name,
            'type' => $this->type,
            'slug' => $this->slug,
            'signed_key' => $this->signed_key,
            'is_active' => $this->is_active,
            'allowed_domains' => $this->allowed_domains,
            'settings' => $this->settings,
            'embed_url' => url('/embed/'.$this->slug),
            'script_url' => url('/embed/embed.js'),
            'embed_code' => $this->scriptEmbedCode(),
            'iframe_code' => $this->scriptEmbedCode(),
            'created_at' => $this->created_at,
            'updated_at' => $this->updated_at,
        ];
    }
}
