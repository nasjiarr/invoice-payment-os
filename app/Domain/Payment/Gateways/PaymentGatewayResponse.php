<?php

namespace App\Domain\Payment\Gateways;

use App\Enums\PaymentStatus;

class PaymentGatewayResponse
{
    /**
     * @param  array<string, mixed>  $payload
     */
    public function __construct(
        public readonly bool $success,
        public readonly string $transactionId,
        public readonly PaymentStatus $status,
        public readonly ?string $paymentUrl = null,
        public readonly ?string $message = null,
        public readonly array $payload = [],
    ) {}

    /**
     * Create a successful response instance.
     *
     * @param  array<string, mixed>  $payload
     */
    public static function success(
        string $transactionId,
        PaymentStatus $status = PaymentStatus::Paid,
        ?string $paymentUrl = null,
        array $payload = [],
        ?string $message = 'Payment processed successfully'
    ): self {
        return new self(
            success: true,
            transactionId: $transactionId,
            status: $status,
            paymentUrl: $paymentUrl,
            message: $message,
            payload: $payload
        );
    }

    /**
     * Create a pending response instance (e.g., checkout session / pending transfer).
     *
     * @param  array<string, mixed>  $payload
     */
    public static function pending(
        string $transactionId,
        ?string $paymentUrl = null,
        array $payload = [],
        ?string $message = 'Payment pending completion'
    ): self {
        return new self(
            success: true,
            transactionId: $transactionId,
            status: PaymentStatus::Pending,
            paymentUrl: $paymentUrl,
            message: $message,
            payload: $payload
        );
    }

    /**
     * Create a failed response instance.
     *
     * @param  array<string, mixed>  $payload
     */
    public static function failed(
        string $transactionId,
        string $message = 'Payment failed',
        array $payload = []
    ): self {
        return new self(
            success: false,
            transactionId: $transactionId,
            status: PaymentStatus::Failed,
            paymentUrl: null,
            message: $message,
            payload: $payload
        );
    }

    /**
     * Create a refunded response instance.
     *
     * @param  array<string, mixed>  $payload
     */
    public static function refunded(
        string $transactionId,
        ?string $message = 'Payment refunded successfully',
        array $payload = []
    ): self {
        return new self(
            success: true,
            transactionId: $transactionId,
            status: PaymentStatus::Refunded,
            paymentUrl: null,
            message: $message,
            payload: $payload
        );
    }

    /**
     * Determine if the gateway operation was successful.
     */
    public function isSuccessful(): bool
    {
        return $this->success;
    }

    /**
     * Determine if the payment status is pending.
     */
    public function isPending(): bool
    {
        return $this->status === PaymentStatus::Pending;
    }

    /**
     * Determine if the payment status is paid.
     */
    public function isPaid(): bool
    {
        return $this->status === PaymentStatus::Paid;
    }

    /**
     * Determine if the payment status is failed.
     */
    public function isFailed(): bool
    {
        return $this->status === PaymentStatus::Failed;
    }

    /**
     * Determine if the payment status is refunded.
     */
    public function isRefunded(): bool
    {
        return $this->status === PaymentStatus::Refunded;
    }

    /**
     * Get the payment URL.
     */
    public function getPaymentUrl(): ?string
    {
        return $this->paymentUrl;
    }

    /**
     * Get the transaction ID.
     */
    public function getTransactionId(): string
    {
        return $this->transactionId;
    }

    /**
     * Get the payment status.
     */
    public function getStatus(): PaymentStatus
    {
        return $this->status;
    }

    /**
     * Get the gateway response message.
     */
    public function getMessage(): ?string
    {
        return $this->message;
    }

    /**
     * Get the raw payload.
     *
     * @return array<string, mixed>
     */
    public function getPayload(): array
    {
        return $this->payload;
    }

    /**
     * Convert the response into an array.
     *
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'success' => $this->success,
            'transaction_id' => $this->transactionId,
            'status' => $this->status->value,
            'payment_url' => $this->paymentUrl,
            'message' => $this->message,
            'payload' => $this->payload,
        ];
    }
}
