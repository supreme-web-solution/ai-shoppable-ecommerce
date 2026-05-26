<?php

namespace App\Http\Resources\Api\V1;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class AiGenerationResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'team_id' => $this->team_id,
            'video_id' => $this->video_id,
            'type' => $this->type,
            'provider' => $this->provider,
            'status' => $this->status,
            'external_id' => $this->external_id,
            'input' => $this->input,
            'output' => $this->output,
            'error_message' => $this->error_message,
            'completed_at' => $this->completed_at,
            'created_at' => $this->created_at,
            'updated_at' => $this->updated_at,
        ];
    }
}
