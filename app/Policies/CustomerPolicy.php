<?php

namespace App\Policies;

use App\Models\Customer;
use App\Models\User;

class CustomerPolicy
{
    /**
     * Determine whether the user can view any customers.
     */
    public function viewAny(User $user): bool
    {
        return true;
    }

    /**
     * Determine whether the user can view the customer.
     */
    public function view(User $user, Customer $customer): bool
    {
        return $this->userBelongsToBusiness($user, $customer->business_id);
    }

    /**
     * Determine whether the user can create customers for a business.
     */
    public function create(User $user, ?int $businessId = null): bool
    {
        if ($businessId === null) {
            return true;
        }

        return $this->userBelongsToBusiness($user, $businessId);
    }

    /**
     * Determine whether the user can update the customer.
     */
    public function update(User $user, Customer $customer): bool
    {
        return $this->userBelongsToBusiness($user, $customer->business_id);
    }

    /**
     * Determine whether the user can delete the customer.
     */
    public function delete(User $user, Customer $customer): bool
    {
        return $this->userBelongsToBusiness($user, $customer->business_id);
    }

    /**
     * Helper to verify if user owns or belongs to the business.
     */
    protected function userBelongsToBusiness(User $user, int $businessId): bool
    {
        return $user->ownedBusinesses()->where('id', $businessId)->exists()
            || $user->businesses()->where('businesses.id', $businessId)->exists();
    }
}
