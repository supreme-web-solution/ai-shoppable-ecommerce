<?php

namespace App\Policies;

use App\Models\Embed;
use App\Models\User;

class EmbedPolicy
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
    public function view(User $user, Embed $embed): bool
    {
        return $user->team_id === $embed->team_id || $user->teams()->whereKey($embed->team_id)->exists();
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
    public function update(User $user, Embed $embed): bool
    {
        return $this->view($user, $embed);
    }

    /**
     * Determine whether the user can delete the model.
     */
    public function delete(User $user, Embed $embed): bool
    {
        return $this->view($user, $embed);
    }

    /**
     * Determine whether the user can restore the model.
     */
    public function restore(User $user, Embed $embed): bool
    {
        return $this->view($user, $embed);
    }

    /**
     * Determine whether the user can permanently delete the model.
     */
    public function forceDelete(User $user, Embed $embed): bool
    {
        return $this->view($user, $embed);
    }
}
