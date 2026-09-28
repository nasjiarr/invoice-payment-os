<?php

namespace App\Policies;

use App\Models\Invoice;
use App\Models\User;

class InvoicePolicy
{
    /**
     * Determine whether the user can view any invoices.
     */
    public function viewAny(User $user): bool
    {
        return true;
    }

    /**
     * Determine whether the user can view the invoice.
     */
    public function view(User $user, Invoice $invoice): bool
    {
        return $this->userBelongsToBusiness($user, $invoice->business_id);
    }

    /**
     * Determine whether the user can create an invoice in a business.
     */
    public function create(User $user, ?int $businessId = null): bool
    {
        if ($businessId === null) {
            return true;
        }

        return $this->userBelongsToBusiness($user, $businessId);
    }

    /**
     * Determine whether the user can update the invoice.
     */
    public function update(User $user, Invoice $invoice): bool
    {
        return $this->userBelongsToBusiness($user, $invoice->business_id);
    }

    /**
     * Determine whether the user can delete the invoice.
     */
    public function delete(User $user, Invoice $invoice): bool
    {
        return $this->userBelongsToBusiness($user, $invoice->business_id);
    }

    /**
     * Determine whether the user can send the invoice.
     */
    public function send(User $user, Invoice $invoice): bool
    {
        return $this->userBelongsToBusiness($user, $invoice->business_id);
    }

    /**
     * Determine whether the user can void the invoice.
     */
    public function void(User $user, Invoice $invoice): bool
    {
        return $this->userBelongsToBusiness($user, $invoice->business_id);
    }

    /**
     * Determine whether the user can cancel the invoice.
     */
    public function cancel(User $user, Invoice $invoice): bool
    {
        return $this->userBelongsToBusiness($user, $invoice->business_id);
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
