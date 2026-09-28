<?php

namespace App\Policies;

use App\Models\Invoice;
use App\Models\Payment;
use App\Models\User;

class PaymentPolicy
{
    /**
     * Determine whether the user can view any payments.
     */
    public function viewAny(User $user): bool
    {
        return true;
    }

    /**
     * Determine whether the user can view the payment.
     */
    public function view(User $user, Payment $payment): bool
    {
        return $this->userBelongsToBusiness($user, $payment->business_id);
    }

    /**
     * Determine whether the user can create a payment for the invoice.
     */
    public function create(User $user, Invoice $invoice): bool
    {
        return $this->userBelongsToBusiness($user, $invoice->business_id);
    }

    /**
     * Determine whether the user can process the payment.
     */
    public function process(User $user, Payment $payment): bool
    {
        return $this->userBelongsToBusiness($user, $payment->business_id);
    }

    /**
     * Determine whether the user can cancel the payment.
     */
    public function cancel(User $user, Payment $payment): bool
    {
        return $this->userBelongsToBusiness($user, $payment->business_id);
    }

    /**
     * Determine whether the user can refund the payment.
     */
    public function refund(User $user, Payment $payment): bool
    {
        return $this->userBelongsToBusiness($user, $payment->business_id);
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
