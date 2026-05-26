<?php

namespace App\Http\Requests\Api\V1\Concerns;

use App\Models\Team;

trait AuthorizesTeamAccess
{
    protected function userCanAccessTeam(int $teamId): bool
    {
        $user = $this->user();

        if (! $user) {
            return false;
        }

        if ($user->team_id === $teamId) {
            return true;
        }

        return $user->teams()->whereKey($teamId)->exists();
    }

    protected function teamFromInput(): ?Team
    {
        $teamId = (int) $this->input('team_id');

        if ($teamId <= 0) {
            return null;
        }

        return Team::query()->find($teamId);
    }
}
