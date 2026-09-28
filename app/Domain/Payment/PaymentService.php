<?php

namespace App\Domain\Payment;

use App\Domain\Invoice\InvoiceStatusTransition;
use App\Domain\Payment\Contracts\PaymentGatewayInterface;
use App\Domain\Payment\Gateways\PaymentGatewayResponse;
use App\Enums\InvoiceStatus;
use App\Enums\PaymentStatus;
use App\Models\AuditLog;
use App\Models\Invoice;
use App\Models\Payment;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class PaymentService
{
    /**
     * PaymentService depends exclusively on the PaymentGatewayInterface abstraction.
     */
    public function __construct(
        protected PaymentGatewayInterface $gateway
    ) {}

    /**
     * Get the configured payment gateway instance.
     */
    public function getGateway(): PaymentGatewayInterface
    {
        return $this->gateway;
    }

    /**
     * Get the total amount paid for an invoice.
     */
    public function getTotalPaid(Invoice $invoice): float
    {
        return round((float) $invoice->payments()
            ->where('status', PaymentStatus::Paid)
            ->sum('amount'), 2);
    }

    /**
     * Calculate the outstanding balance for an invoice.
     */
    public function getOutstandingBalance(Invoice $invoice): float
    {
        $totalPaid = $this->getTotalPaid($invoice);

        return max(0.0, round((float) $invoice->total - $totalPaid, 2));
    }

    /**
     * Record a new payment for an invoice inside a database transaction.
     *
     * @param  array<string, mixed>  $data
     */
    public function createPayment(Invoice $invoice, ?User $user = null, array $data = []): Payment
    {
        // Terminal invoice states check
        if (in_array($invoice->status, [InvoiceStatus::Void, InvoiceStatus::Cancelled], true)) {
            throw ValidationException::withMessages([
                'status' => ["Cannot process payment for an invoice with status '{$invoice->status->value}'."],
            ]);
        }

        // Currency consistency check (Rule 10)
        $currency = $data['currency'] ?? $invoice->currency;
        if ($currency !== $invoice->currency) {
            throw ValidationException::withMessages([
                'currency' => ['Payment currency must match invoice currency.'],
            ]);
        }

        // Amount must be positive (Rule 9)
        $amount = round((float) $data['amount'], 2);
        if ($amount <= 0.0) {
            throw ValidationException::withMessages([
                'amount' => ['Payment amount must be greater than zero.'],
            ]);
        }

        // Determine target payment status
        $targetStatus = isset($data['status'])
            ? PaymentStatus::from($data['status'])
            : PaymentStatus::Paid;

        // Transaction ID validation and generation (Rule 11)
        $transactionId = $data['transaction_id'] ?? $this->generateUniqueTransactionId();
        if (Payment::where('business_id', $invoice->business_id)->where('transaction_id', $transactionId)->exists()) {
            throw ValidationException::withMessages([
                'transaction_id' => ['Payment with this transaction ID already exists.'],
            ]);
        }

        // Outstanding balance and overpayment check (Rule 2)
        if ($targetStatus === PaymentStatus::Paid) {
            $outstandingBalance = $this->getOutstandingBalance($invoice);

            if ($amount > $outstandingBalance + 0.0001) {
                throw ValidationException::withMessages([
                    'amount' => ['Payment amount exceeds invoice outstanding balance.'],
                ]);
            }
        }

        return DB::transaction(function () use ($invoice, $user, $data, $amount, $currency, $targetStatus, $transactionId): Payment {
            /** @var Invoice $lockedInvoice */
            $lockedInvoice = Invoice::where('id', $invoice->id)->lockForUpdate()->firstOrFail();

            // Re-verify outstanding balance inside transaction under row lock
            if ($targetStatus === PaymentStatus::Paid) {
                $outstandingBalance = $this->getOutstandingBalance($lockedInvoice);

                if ($amount > $outstandingBalance + 0.0001) {
                    throw ValidationException::withMessages([
                        'amount' => ['Payment amount exceeds invoice outstanding balance.'],
                    ]);
                }
            }

            $payment = Payment::create([
                'business_id' => $lockedInvoice->business_id,
                'invoice_id' => $lockedInvoice->id,
                'amount' => $amount,
                'currency' => $currency,
                'status' => $targetStatus,
                'payment_method' => $data['payment_method'] ?? 'internal',
                'transaction_id' => $transactionId,
                'paid_at' => $targetStatus === PaymentStatus::Paid ? ($data['paid_at'] ?? now()) : null,
                'notes' => $data['notes'] ?? null,
            ]);

            // Interact with the abstracted payment gateway
            $gatewayResponse = $this->gateway->createPayment($payment, $data);

            if ($targetStatus === PaymentStatus::Paid && ! $gatewayResponse->isSuccessful()) {
                throw ValidationException::withMessages([
                    'gateway' => [$gatewayResponse->getMessage() ?? 'Payment gateway declined transaction.'],
                ]);
            }

            if ($targetStatus === PaymentStatus::Paid) {
                // Rule 7: Recalculate invoice status based on total paid vs invoice total
                $totalPaid = $this->getTotalPaid($invoice);
                $newStatus = ($totalPaid >= (float) $invoice->total)
                    ? InvoiceStatus::Paid
                    : InvoiceStatus::PartiallyPaid;

                if ($invoice->status !== $newStatus) {
                    InvoiceStatusTransition::validate($invoice->status, $newStatus);
                    $invoice->update(['status' => $newStatus]);
                }

                AuditLog::create([
                    'business_id' => $invoice->business_id,
                    'user_id' => $user?->id,
                    'action' => 'payment.received',
                    'auditable_type' => Payment::class,
                    'auditable_id' => $payment->id,
                    'description' => "Payment of {$payment->currency} {$payment->amount} received for invoice {$invoice->invoice_number}",
                    'metadata' => [
                        'amount' => $payment->amount,
                        'currency' => $payment->currency,
                        'invoice_id' => $invoice->id,
                        'new_invoice_status' => $invoice->fresh()->status->value,
                        'transaction_id' => $payment->transaction_id,
                        'payment_url' => $gatewayResponse->getPaymentUrl(),
                    ],
                ]);
            } elseif ($targetStatus === PaymentStatus::Failed) {
                AuditLog::create([
                    'business_id' => $invoice->business_id,
                    'user_id' => $user?->id,
                    'action' => 'payment.failed',
                    'auditable_type' => Payment::class,
                    'auditable_id' => $payment->id,
                    'description' => "Payment attempt failed for invoice {$invoice->invoice_number}",
                    'metadata' => [
                        'amount' => $payment->amount,
                        'currency' => $payment->currency,
                        'invoice_id' => $invoice->id,
                        'transaction_id' => $payment->transaction_id,
                    ],
                ]);
            } elseif ($targetStatus === PaymentStatus::Cancelled) {
                AuditLog::create([
                    'business_id' => $invoice->business_id,
                    'user_id' => $user?->id,
                    'action' => 'payment.cancelled',
                    'auditable_type' => Payment::class,
                    'auditable_id' => $payment->id,
                    'description' => "Payment cancelled for invoice {$invoice->invoice_number}",
                    'metadata' => [
                        'amount' => $payment->amount,
                        'currency' => $payment->currency,
                        'invoice_id' => $invoice->id,
                        'transaction_id' => $payment->transaction_id,
                    ],
                ]);
            } else {
                AuditLog::create([
                    'business_id' => $invoice->business_id,
                    'user_id' => $user?->id,
                    'action' => 'payment.created',
                    'auditable_type' => Payment::class,
                    'auditable_id' => $payment->id,
                    'description' => "Pending payment recorded for invoice {$invoice->invoice_number}",
                    'metadata' => [
                        'amount' => $payment->amount,
                        'currency' => $payment->currency,
                        'invoice_id' => $invoice->id,
                        'transaction_id' => $payment->transaction_id,
                        'payment_url' => $gatewayResponse->getPaymentUrl(),
                    ],
                ]);
            }

            return $payment->load(['invoice', 'business']);
        });
    }

    /**
     * Process a pending payment to paid status (Rule 8: cannot process twice).
     */
    public function processPayment(Payment $payment, ?User $user = null): Payment
    {
        return DB::transaction(function () use ($payment, $user): Payment {
            /** @var Payment $lockedPayment */
            $lockedPayment = Payment::where('id', $payment->id)->lockForUpdate()->firstOrFail();

            // Rule 8: Payment cannot be processed twice
            if ($lockedPayment->status !== PaymentStatus::Pending) {
                throw ValidationException::withMessages([
                    'status' => ['Payment has already been processed and cannot be processed again.'],
                ]);
            }

            /** @var Invoice $invoice */
            $invoice = Invoice::where('id', $lockedPayment->invoice_id)->lockForUpdate()->firstOrFail();

            // Terminal invoice states check
            if (in_array($invoice->status, [InvoiceStatus::Void, InvoiceStatus::Cancelled], true)) {
                throw ValidationException::withMessages([
                    'status' => ["Cannot process payment for an invoice with status '{$invoice->status->value}'."],
                ]);
            }

            // Outstanding balance and overpayment check (Rule 2)
            $outstandingBalance = $this->getOutstandingBalance($invoice);
            if ((float) $lockedPayment->amount > $outstandingBalance + 0.0001) {
                throw ValidationException::withMessages([
                    'amount' => ['Payment amount exceeds invoice outstanding balance.'],
                ]);
            }

            $lockedPayment->update([
                'status' => PaymentStatus::Paid,
                'paid_at' => now(),
            ]);

            // Rule 7: Recalculate invoice status based on total paid vs invoice total
            $totalPaid = $this->getTotalPaid($invoice);
            $newStatus = ($totalPaid >= (float) $invoice->total)
                ? InvoiceStatus::Paid
                : InvoiceStatus::PartiallyPaid;

            if ($invoice->status !== $newStatus) {
                InvoiceStatusTransition::validate($invoice->status, $newStatus);
                $invoice->update(['status' => $newStatus]);
            }

            AuditLog::create([
                'business_id' => $invoice->business_id,
                'user_id' => $user?->id,
                'action' => 'payment.received',
                'auditable_type' => Payment::class,
                'auditable_id' => $lockedPayment->id,
                'description' => "Payment of {$lockedPayment->currency} {$lockedPayment->amount} received for invoice {$invoice->invoice_number}",
                'metadata' => [
                    'amount' => $lockedPayment->amount,
                    'currency' => $lockedPayment->currency,
                    'invoice_id' => $invoice->id,
                    'new_invoice_status' => $invoice->fresh()->status->value,
                    'transaction_id' => $lockedPayment->transaction_id,
                ],
            ]);

            return $lockedPayment->fresh(['invoice', 'business']);
        });
    }

    /**
     * Cancel a pending payment.
     */
    public function cancelPayment(Payment $payment, ?User $user = null): Payment
    {
        return DB::transaction(function () use ($payment, $user): Payment {
            /** @var Payment $lockedPayment */
            $lockedPayment = Payment::where('id', $payment->id)->lockForUpdate()->firstOrFail();

            if ($lockedPayment->status !== PaymentStatus::Pending) {
                throw ValidationException::withMessages([
                    'status' => ['Only pending payments can be cancelled.'],
                ]);
            }

            $lockedPayment->update([
                'status' => PaymentStatus::Cancelled,
            ]);

            AuditLog::create([
                'business_id' => $lockedPayment->business_id,
                'user_id' => $user?->id,
                'action' => 'payment.cancelled',
                'auditable_type' => Payment::class,
                'auditable_id' => $lockedPayment->id,
                'description' => "Payment {$lockedPayment->transaction_id} cancelled",
                'metadata' => [
                    'payment_id' => $lockedPayment->id,
                    'invoice_id' => $lockedPayment->invoice_id,
                ],
            ]);

            return $lockedPayment->fresh(['invoice', 'business']);
        });
    }

    /**
     * Get payment status from the payment gateway.
     */
    public function getPaymentStatus(Payment $payment): PaymentGatewayResponse
    {
        return $this->gateway->getPaymentStatus($payment->transaction_id);
    }

    /**
     * Synchronize a pending payment with the gateway.
     */
    public function syncPaymentStatus(Payment $payment, ?User $user = null): Payment
    {
        $response = $this->getPaymentStatus($payment);

        if ($payment->status === PaymentStatus::Pending && $response->isPaid()) {
            return $this->processPayment($payment, $user);
        }

        return $payment;
    }

    /**
     * Refund a paid payment through the payment gateway inside a database transaction.
     */
    public function refundPayment(Payment $payment, ?User $user = null, ?float $amount = null, ?string $reason = null): Payment
    {
        return DB::transaction(function () use ($payment, $user, $amount, $reason): Payment {
            /** @var Payment $lockedPayment */
            $lockedPayment = Payment::where('id', $payment->id)->lockForUpdate()->firstOrFail();

            if ($lockedPayment->status !== PaymentStatus::Paid) {
                throw ValidationException::withMessages([
                    'status' => ['Only paid payments can be refunded.'],
                ]);
            }

            $refundAmount = $amount ?? (float) $lockedPayment->amount;
            if ($refundAmount <= 0.0 || $refundAmount > (float) $lockedPayment->amount) {
                throw ValidationException::withMessages([
                    'amount' => ['Refund amount must be between 0.01 and the original payment amount.'],
                ]);
            }

            // Delegate refund to the abstracted payment gateway
            $gatewayResponse = $this->gateway->refundPayment($lockedPayment, $refundAmount, $reason);

            if (! $gatewayResponse->isSuccessful()) {
                throw ValidationException::withMessages([
                    'gateway' => [$gatewayResponse->getMessage() ?? 'Payment gateway declined refund request.'],
                ]);
            }

            $lockedPayment->update([
                'status' => PaymentStatus::Refunded,
            ]);

            $invoice = $payment->invoice;

            // Recalculate invoice status based on remaining total paid
            $totalPaid = $this->getTotalPaid($invoice);

            if ($totalPaid >= (float) $invoice->total) {
                $newStatus = InvoiceStatus::Paid;
            } elseif ($totalPaid > 0.0) {
                $newStatus = InvoiceStatus::PartiallyPaid;
            } else {
                $newStatus = InvoiceStatus::Sent;
            }

            if ($invoice->status !== $newStatus) {
                $invoice->update(['status' => $newStatus]);
            }

            AuditLog::create([
                'business_id' => $payment->business_id,
                'user_id' => $user?->id,
                'action' => 'payment.refunded',
                'auditable_type' => Payment::class,
                'auditable_id' => $payment->id,
                'description' => "Payment of {$payment->currency} {$refundAmount} refunded for invoice {$invoice->invoice_number}",
                'metadata' => [
                    'amount' => $refundAmount,
                    'currency' => $payment->currency,
                    'invoice_id' => $invoice->id,
                    'new_invoice_status' => $invoice->fresh()->status->value,
                    'refund_transaction_id' => $gatewayResponse->getTransactionId(),
                    'reason' => $reason,
                ],
            ]);

            return $payment->fresh(['invoice', 'business']);
        });
    }

    /**
     * Generate a unique transaction ID.
     */
    protected function generateUniqueTransactionId(): string
    {
        $prefix = 'TRX-'.date('Ymd').'-';
        $attempts = 0;

        do {
            $attempts++;
            $candidate = $prefix.strtoupper(Str::random(10));
            $exists = Payment::where('transaction_id', $candidate)->exists();
        } while ($exists && $attempts < 100);

        return $candidate;
    }
}
