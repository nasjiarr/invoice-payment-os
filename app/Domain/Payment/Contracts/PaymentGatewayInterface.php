<?php

namespace App\Domain\Payment\Contracts;

use App\Domain\Payment\Gateways\PaymentGatewayResponse;
use App\Models\Payment;

interface PaymentGatewayInterface
{
    /**
     * Create or initiate a payment with the payment gateway.
     *
     * @param  array<string, mixed>  $options
     */
    public function createPayment(Payment $payment, array $options = []): PaymentGatewayResponse;

    /**
     * Get the latest payment status from the payment gateway.
     */
    public function getPaymentStatus(string $transactionId): PaymentGatewayResponse;

    /**
     * Refund a paid payment through the payment gateway.
     */
    public function refundPayment(Payment $payment, ?float $amount = null, ?string $reason = null): PaymentGatewayResponse;
}
