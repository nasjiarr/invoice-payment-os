<?php

namespace App\Policies;

use App\Models\Product;
use App\Models\User;

class ProductPolicy
{
    /**
     * Determine whether the user can view any products.
     */
    public function viewAny(User $user): bool
    {
        return true;
    }

    /**
     * Determine whether the user can view the product.
     */
    public function view(User $user, Product $product): bool
    {
        return $this->userBelongsToBusiness($user, $product->business_id);
    }

    /**
     * Determine whether the user can create products for a business.
     */
    public function create(User $user, ?int $businessId = null): bool
    {
        if ($businessId === null) {
            return true;
        }

        return $this->userBelongsToBusiness($user, $businessId);
    }

    /**
     * Determine whether the user can update the product.
     */
    public function update(User $user, Product $product): bool
    {
        return $this->userBelongsToBusiness($user, $product->business_id);
    }

    /**
     * Determine whether the user can delete the product.
     */
    public function delete(User $user, Product $product): bool
    {
        return $this->userBelongsToBusiness($user, $product->business_id);
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
