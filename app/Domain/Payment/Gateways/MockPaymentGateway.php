<?php

namespace App\Domain\Payment\Gateways;

use App\Domain\Payment\Contracts\PaymentGatewayInterface;
use App\Enums\PaymentStatus;
use App\Models\Payment;
use Illuminate\Support\Str;

class MockPaymentGateway implements PaymentGatewayInterface
{
    /**
     * @var array<string, array<string, mixed>>
     */
    protected array $transactions = [];

    /**
     * @var array<int, array<string, mixed>>
     */
    protected array $refunds = [];

    protected bool $shouldFail = false;

    protected string $failureReason = 'Payment declined by mock gateway';

    protected ?PaymentStatus $forcedStatus = null;

    /**
     * Configure the mock gateway to simulate failure.
     */
    public function shouldFail(bool $fail = true, string $reason = 'Payment declined by mock gateway'): self
    {
        $this->shouldFail = $fail;
        $this->failureReason = $reason;

        return $this;
    }

    /**
     * Force a specific status on created payments.
     */
    public function forceStatus(?PaymentStatus $status): self
    {
        $this->forcedStatus = $status;

        return $this;
    }

    /**
     * Set the status of a specific transaction ID in memory.
     */
    public function setTransactionStatus(string $transactionId, PaymentStatus $status): self
    {
        if (isset($this->transactions[$transactionId])) {
            $this->transactions[$transactionId]['status'] = $status;
        } else {
            $this->transactions[$transactionId] = [
                'transaction_id' => $transactionId,
                'status' => $status,
                'payload' => [],
            ];
        }

        return $this;
    }

    /**
     * Reset the mock gateway internal state.
     */
    public function reset(): self
    {
        $this->transactions = [];
        $this->refunds = [];
        $this->shouldFail = false;
        $this->failureReason = 'Payment declined by mock gateway';
        $this->forcedStatus = null;

        return $this;
    }

    /**
     * Create or initiate a payment with the mock payment gateway.
     *
     * @param  array<string, mixed>  $options
     */
    public function createPayment(Payment $payment, array $options = []): PaymentGatewayResponse
    {
        $transactionId = $payment->transaction_id ?: ('MOCK-'.date('Ymd').'-'.strtoupper(Str::random(10)));

        if ($this->shouldFail) {
            return PaymentGatewayResponse::failed(
                transactionId: $transactionId,
                message: $this->failureReason,
                payload: ['provider' => 'mock', 'payment_id' => $payment->id]
            );
        }

        $status = $this->forcedStatus ?? $payment->status;
        $paymentUrl = 'https://checkout.mock-gateway.test/pay/'.$transactionId;

        $record = [
            'payment_id' => $payment->id,
            'invoice_id' => $payment->invoice_id,
            'business_id' => $payment->business_id,
            'amount' => $payment->amount,
            'currency' => $payment->currency,
            'status' => $status,
            'payment_url' => $paymentUrl,
            'transaction_id' => $transactionId,
            'created_at' => now()->toIso8601String(),
        ];

        $this->transactions[$transactionId] = $record;

        if ($status === PaymentStatus::Pending) {
            return PaymentGatewayResponse::pending(
                transactionId: $transactionId,
                paymentUrl: $paymentUrl,
                payload: $record,
                message: 'Mock payment created as pending'
            );
        }

        if ($status === PaymentStatus::Failed) {
            return PaymentGatewayResponse::failed(
                transactionId: $transactionId,
                message: 'Mock payment failed',
                payload: $record
            );
        }

        return PaymentGatewayResponse::success(
            transactionId: $transactionId,
            status: PaymentStatus::Paid,
            paymentUrl: $paymentUrl,
            payload: $record,
            message: 'Mock payment processed successfully'
        );
    }

    /**
     * Get the latest payment status from the mock payment gateway.
     */
    public function getPaymentStatus(string $transactionId): PaymentGatewayResponse
    {
        if (isset($this->transactions[$transactionId])) {
            $record = $this->transactions[$transactionId];
            $status = $record['status'];

            return new PaymentGatewayResponse(
                success: $status !== PaymentStatus::Failed,
                transactionId: $transactionId,
                status: $status,
                paymentUrl: $record['payment_url'] ?? null,
                message: "Status for {$transactionId} is {$status->value}",
                payload: $record
            );
        }

        // Default response when transaction was not stored in memory
        return PaymentGatewayResponse::success(
            transactionId: $transactionId,
            status: PaymentStatus::Paid,
            paymentUrl: null,
            payload: ['provider' => 'mock', 'transaction_id' => $transactionId],
            message: 'Mock transaction verified as paid'
        );
    }

    /**
     * Refund a paid payment through the mock payment gateway.
     */
    public function refundPayment(Payment $payment, ?float $amount = null, ?string $reason = null): PaymentGatewayResponse
    {
        $refundAmount = $amount ?? (float) $payment->amount;
        $refundTxId = 'REFUND-'.($payment->transaction_id ?: strtoupper(Str::random(10)));

        if ($this->shouldFail) {
            return PaymentGatewayResponse::failed(
                transactionId: $refundTxId,
                message: $this->failureReason,
                payload: ['provider' => 'mock', 'payment_id' => $payment->id]
            );
        }

        $record = [
            'refund_id' => $refundTxId,
            'payment_id' => $payment->id,
            'original_transaction_id' => $payment->transaction_id,
            'amount' => $refundAmount,
            'currency' => $payment->currency,
            'reason' => $reason ?? 'Customer request',
            'refunded_at' => now()->toIso8601String(),
        ];

        $this->refunds[] = $record;

        if (isset($this->transactions[$payment->transaction_id])) {
            $this->transactions[$payment->transaction_id]['status'] = PaymentStatus::Refunded;
        }

        return PaymentGatewayResponse::refunded(
            transactionId: $refundTxId,
            message: "Refund of {$payment->currency} {$refundAmount} processed successfully",
            payload: $record
        );
    }

    /**
     * Get recorded transactions in memory.
     *
     * @return array<string, array<string, mixed>>
     */
    public function getTransactions(): array
    {
        return $this->transactions;
    }

    /**
     * Get recorded refunds in memory.
     *
     * @return array<int, array<string, mixed>>
     */
    public function getRefunds(): array
    {
        return $this->refunds;
    }
}
