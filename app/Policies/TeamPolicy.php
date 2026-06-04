<?php

namespace App\Policies;

use App\Models\Team;
use App\Models\User;

class TeamPolicy
{
    /**
     * Determine whether the user can view any models.
     */
    public function viewAny(User $user): bool
    {
        return true;
    }

    /**
     * Determine whether the user can view the model.
     */
    public function view(User $user, Team $team): bool
    {
        return $this->belongsToTeam($user, $team);
    }

    /**
     * Determine whether the user can create models.
     */
    public function create(User $user): bool
    {
        return true;
    }

    /**
     * Determine whether the user can update the model.
     */
    public function update(User $user, Team $team): bool
    {
        return $this->isOwnerOrAdmin($user, $team);
    }

    /**
     * Determine whether the user can delete the model.
     */
    public function delete(User $user, Team $team): bool
    {
        return $this->isOwnerOrAdmin($user, $team);
    }

    /**
     * Determine whether the user can restore the model.
     */
    public function restore(User $user, Team $team): bool
    {
        return $this->isOwnerOrAdmin($user, $team);
    }

    /**
     * Determine whether the user can permanently delete the model.
     */
    public function forceDelete(User $user, Team $team): bool
    {
        return $this->isOwnerOrAdmin($user, $team);
    }

    public function manageMembers(User $user, Team $team): bool
    {
        return $this->isOwnerOrAdmin($user, $team);
    }

    protected function belongsToTeam(User $user, Team $team): bool
    {
        if ($user->team_id === $team->id) {
            return true;
        }

        return $user->teams()->whereKey($team->id)->exists();
    }

    protected function isOwnerOrAdmin(User $user, Team $team): bool
    {
        if ($team->owner_user_id === $user->id) {
            return true;
        }

        return $user->teams()
            ->whereKey($team->id)
            ->wherePivotIn('role', ['owner', 'admin'])
            ->exists();
    }
}
