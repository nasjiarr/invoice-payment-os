<?php

namespace App\Policies;

use App\Enums\BusinessRole;
use App\Models\Business;
use App\Models\User;

class BusinessPolicy
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
    public function view(User $user, Business $business): bool
    {
        return $business->owner_id === $user->id
            || $business->users()->where('users.id', $user->id)->exists();
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
    public function update(User $user, Business $business): bool
    {
        return $business->owner_id === $user->id
            || $business->users()
                ->where('users.id', $user->id)
                ->whereIn('role', [BusinessRole::Owner->value, BusinessRole::Admin->value])
                ->exists();
    }

    /**
     * Determine whether the user can delete the model.
     */
    public function delete(User $user, Business $business): bool
    {
        return $business->owner_id === $user->id;
    }
}
