<?php

namespace App\Http\Resources\Api\V1;

use App\Support\PlatformAdmin;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class PlatformUserResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'email' => $this->email,
            'email_verified_at' => $this->email_verified_at,
            'team_id' => $this->team_id,
            'is_platform_admin' => PlatformAdmin::isAllowedEmail($this->email),
            'current_team' => $this->whenLoaded('currentTeam', fn () => [
                'id' => $this->currentTeam?->id,
                'name' => $this->currentTeam?->name,
                'slug' => $this->currentTeam?->slug,
            ]),
            'teams_count' => (int) ($this->teams_count ?? 0),
            'owned_teams_count' => (int) ($this->owned_teams_count ?? 0),
            'created_at' => $this->created_at,
            'updated_at' => $this->updated_at,
        ];
    }
}
