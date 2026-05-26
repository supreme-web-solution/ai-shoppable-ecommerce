<?php

namespace App\Policies;

use App\Models\LiveShow;
use App\Models\User;

class LiveShowPolicy
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
    public function view(User $user, LiveShow $liveShow): bool
    {
        return $user->team_id === $liveShow->team_id || $user->teams()->whereKey($liveShow->team_id)->exists();
    }

    /**
     * Determine whether the user can create models.
     */
    public function create(User $user): bool
    {
        return $user->team_id !== null;
    }

    /**
     * Determine whether the user can update the model.
     */
    public function update(User $user, LiveShow $liveShow): bool
    {
        return $this->view($user, $liveShow);
    }

    /**
     * Determine whether the user can delete the model.
     */
    public function delete(User $user, LiveShow $liveShow): bool
    {
        return $this->view($user, $liveShow);
    }

    /**
     * Determine whether the user can restore the model.
     */
    public function restore(User $user, LiveShow $liveShow): bool
    {
        return $this->view($user, $liveShow);
    }

    /**
     * Determine whether the user can permanently delete the model.
     */
    public function forceDelete(User $user, LiveShow $liveShow): bool
    {
        return $this->view($user, $liveShow);
    }
}
