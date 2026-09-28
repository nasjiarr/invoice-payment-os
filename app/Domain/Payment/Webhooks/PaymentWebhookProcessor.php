<?php

namespace App\Domain\Payment\Webhooks;

use App\Domain\Payment\PaymentService;
use App\Enums\PaymentEventStatus;
use App\Enums\PaymentStatus;
use App\Exceptions\PaymentNotFoundException;
use App\Models\AuditLog;
use App\Models\Payment;
use App\Models\PaymentEvent;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * PaymentWebhookProcessor
 *
 * HOW IDEMPOTENCY & CONCURRENCY PROTECTION WORKS:
 * ------------------------------------------------
 * 1. Unique Identification:
 *    Every incoming webhook event has a unique identifier assigned by the provider
 *    (e.g., event_id: 'evt_123456'). The database table `payment_events` enforces
 *    a composite UNIQUE constraint on (`provider`, `event_id`).
 *
 * 2. Race Condition / Concurrency Protection:
 *    When two identical webhook deliveries arrive simultaneously across concurrent threads:
 *    - Both may not find an existing record initially.
 *    - Both attempt an atomic INSERT into `payment_events` with status `Pending`.
 *    - The database engine guarantees only one insert succeeds; the other throws
 *      a `UniqueConstraintViolationException`.
 *    - The catching thread detects this concurrent insert and gracefully retrieves
 *      the record, recognizing it as a duplicate without double-processing.
 *
 * 3. Idempotent Deduping:
 *    If an event is already marked as `Processed` or `Pending` (in-flight), the processor
 *    immediately skips execution and returns a success response with status 'duplicate'.
 *    The payment, invoice, and balance are untouched.
 *
 * 4. Atomic Execution:
 *    Processing the webhook, updating the payment, and transitioning invoice status
 *    run inside a single `DB::transaction()` with pessimistic row-locking (`lockForUpdate`).
 *    If any step fails, the transaction is completely rolled back, and the event status
 *    is recorded as `Failed`.
 *
 * 5. Retryable Processing:
 *    Payment gateways (like Midtrans or Stripe) retry webhooks upon failure. When a webhook
 *    is redelivered for an event previously recorded as `Failed`, the processor resets
 *    the status to `Pending` and allows it to be safely re-executed.
 */
class PaymentWebhookProcessor
{
    public function __construct(
        protected PaymentService $paymentService
    ) {}

    /**
     * Process an incoming webhook payload idempotently and securely.
     *
     * @param  array<string, mixed>  $payload
     * @param  array<string, mixed>  $headers
     * @return array<string, mixed>
     *
     * @throws ValidationException|PaymentNotFoundException|\Throwable
     */
    public function process(string $provider, array $payload, array $headers = [], ?string $rawContent = null): array
    {
        // Security Check 1: Provider whitelisting
        $allowedProviders = config('payment.webhook.allowed_providers', ['mock', 'midtrans', 'xendit']);
        if (! in_array(strtolower($provider), $allowedProviders, true)) {
            throw ValidationException::withMessages([
                'provider' => ["Unsupported payment webhook provider '{$provider}'."],
            ]);
        }

        // Security Check 2: Webhook signature / token verification
        $this->verifyWebhookAuthenticity($provider, $payload, $headers, $rawContent);

        // Step 1: Extract and validate required event metadata
        $eventId = $this->extractEventId($payload);
        $eventType = $this->extractEventType($payload);

        if (! $eventId || ! $eventType) {
            throw ValidationException::withMessages([
                'event' => ['The event_id and event_type fields are required in the webhook payload.'],
            ]);
        }

        // Step 2: Idempotency check with database-level concurrency protection
        $existing = PaymentEvent::where('provider', $provider)
            ->where('event_id', $eventId)
            ->first();

        if ($existing) {
            // Already processed duplicate
            if ($existing->status === PaymentEventStatus::Processed) {
                return [
                    'status' => 'duplicate',
                    'message' => 'Duplicate webhook event detected, skipping',
                    'event_id' => $eventId,
                    'provider' => $provider,
                ];
            }

            // In-flight concurrent duplicate
            if ($existing->status === PaymentEventStatus::Pending) {
                return [
                    'status' => 'duplicate',
                    'message' => 'Duplicate webhook event currently being processed, skipping',
                    'event_id' => $eventId,
                    'provider' => $provider,
                ];
            }

            // Retryable: Previous delivery failed, reset to pending and proceed with re-processing
            $existing->update([
                'status' => PaymentEventStatus::Pending,
                'payload' => $payload,
            ]);
            $event = $existing;
        } else {
            try {
                $event = PaymentEvent::create([
                    'provider' => $provider,
                    'event_id' => $eventId,
                    'event_type' => $eventType,
                    'payload' => $payload,
                    'status' => PaymentEventStatus::Pending,
                ]);
            } catch (UniqueConstraintViolationException $e) {
                // Concurrency catch: Parallel request inserted the exact same event_id
                $concurrent = PaymentEvent::where('provider', $provider)
                    ->where('event_id', $eventId)
                    ->first();

                if ($concurrent && $concurrent->status === PaymentEventStatus::Processed) {
                    return [
                        'status' => 'duplicate',
                        'message' => 'Duplicate webhook event detected, skipping',
                        'event_id' => $eventId,
                        'provider' => $provider,
                    ];
                }

                return [
                    'status' => 'duplicate',
                    'message' => 'Duplicate webhook event currently being processed, skipping',
                    'event_id' => $eventId,
                    'provider' => $provider,
                ];
            }
        }

        // Step 3: Extract payment transaction reference
        $transactionId = $this->extractTransactionId($payload);

        if (! $transactionId) {
            $event->update(['status' => PaymentEventStatus::Failed]);
            throw new PaymentNotFoundException('Payment transaction ID not provided in webhook payload.');
        }

        $payment = Payment::where('transaction_id', $transactionId)->first();

        if (! $payment) {
            $event->update(['status' => PaymentEventStatus::Failed]);
            throw new PaymentNotFoundException("Payment with transaction ID '{$transactionId}' not found.");
        }

        // Step 4: Atomic processing within database transaction
        try {
            DB::transaction(function () use ($event, $payment, $eventType, $payload): void {
                // Lock payment record for update to avoid race conditions with other workers
                /** @var Payment $lockedPayment */
                $lockedPayment = Payment::where('id', $payment->id)->lockForUpdate()->first();

                $this->applyEventAction($lockedPayment, $eventType, $payload);

                $event->update([
                    'status' => PaymentEventStatus::Processed,
                    'processed_at' => now(),
                ]);
            });
        } catch (\Throwable $e) {
            // Mark event as failed to allow future retries by the provider
            $event->update([
                'status' => PaymentEventStatus::Failed,
            ]);

            throw $e;
        }

        return [
            'status' => 'processed',
            'message' => 'Webhook processed successfully',
            'event_id' => $eventId,
            'provider' => $provider,
        ];
    }

    /**
     * Apply domain action based on event type.
     *
     * @param  array<string, mixed>  $payload
     */
    protected function applyEventAction(Payment $payment, string $eventType, array $payload): void
    {
        $normalizedType = strtolower($eventType);

        match (true) {
            in_array($normalizedType, ['payment.success', 'payment.paid', 'settlement', 'capture'], true) => $this->handleSuccess($payment),

            in_array($normalizedType, ['payment.failed', 'deny', 'cancel', 'expire'], true) => $this->handleFailed($payment),

            in_array($normalizedType, ['payment.refunded', 'refund'], true) => $this->handleRefund($payment, $payload),

            default => null, // Other notification types are acknowledged safely without state mutation
        };
    }

    /**
     * Handle payment success event.
     */
    protected function handleSuccess(Payment $payment): void
    {
        if ($payment->status === PaymentStatus::Pending) {
            $this->paymentService->processPayment($payment, null);
        }
    }

    /**
     * Handle payment failure event.
     */
    protected function handleFailed(Payment $payment): void
    {
        if ($payment->status === PaymentStatus::Pending) {
            $payment->update([
                'status' => PaymentStatus::Failed,
            ]);

            AuditLog::create([
                'business_id' => $payment->business_id,
                'user_id' => null,
                'action' => 'payment.failed',
                'auditable_type' => Payment::class,
                'auditable_id' => $payment->id,
                'description' => "Payment {$payment->transaction_id} marked as failed via webhook",
                'metadata' => [
                    'payment_id' => $payment->id,
                    'transaction_id' => $payment->transaction_id,
                ],
            ]);
        }
    }

    /**
     * Handle payment refund event.
     *
     * @param  array<string, mixed>  $payload
     */
    protected function handleRefund(Payment $payment, array $payload): void
    {
        if ($payment->status === PaymentStatus::Paid) {
            $data = $payload['data'] ?? $payload['payload'] ?? $payload;
            $amount = isset($data['amount']) ? (float) $data['amount'] : (float) $payment->amount;
            $reason = $data['reason'] ?? 'Webhook refund event';

            $this->paymentService->refundPayment($payment, null, $amount, $reason);
        }
    }

    /**
     * Extract event ID from payload or header-like keys.
     *
     * @param  array<string, mixed>  $payload
     */
    protected function extractEventId(array $payload): ?string
    {
        $id = $payload['event_id'] ?? $payload['id'] ?? null;

        return is_string($id) && trim($id) !== '' ? trim($id) : null;
    }

    /**
     * Extract event type from payload.
     *
     * @param  array<string, mixed>  $payload
     */
    protected function extractEventType(array $payload): ?string
    {
        $type = $payload['event_type'] ?? $payload['type'] ?? $payload['action'] ?? null;

        return is_string($type) && trim($type) !== '' ? trim($type) : null;
    }

    /**
     * Extract transaction ID reference from payload.
     *
     * @param  array<string, mixed>  $payload
     */
    protected function extractTransactionId(array $payload): ?string
    {
        $data = $payload['data'] ?? $payload['payload'] ?? $payload;

        $txId = $data['transaction_id']
            ?? $data['order_id']
            ?? $payload['transaction_id']
            ?? $payload['order_id']
            ?? null;

        return is_string($txId) && trim($txId) !== '' ? trim($txId) : null;
    }

    /**
     * Verify webhook signature or authenticity token if configured.
     *
     * @param  array<string, mixed>  $payload
     * @param  array<string, mixed>  $headers
     */
    protected function verifyWebhookAuthenticity(string $provider, array $payload, array $headers = [], ?string $rawContent = null): void
    {
        $secret = config('payment.webhook.secret');

        if (! empty($secret)) {
            $headerName = config('payment.webhook.signature_header', 'X-Webhook-Signature');
            $signature = $this->getHeaderValue($headers, $headerName)
                ?? $this->getHeaderValue($headers, 'X-Signature')
                ?? $this->getHeaderValue($headers, 'X-Callback-Token');

            if (! $signature) {
                throw ValidationException::withMessages([
                    'signature' => ['Webhook signature is required but missing from request headers.'],
                ]);
            }

            // Compute HMAC SHA-256 over raw content or JSON-encoded payload
            $contentToVerify = $rawContent ?: json_encode($payload);
            $expectedSignature = hash_hmac('sha256', $contentToVerify, $secret);

            // Also check direct token match for token-based gateways (e.g. Xendit callback token)
            $isHmacValid = hash_equals($expectedSignature, $signature);
            $isTokenValid = hash_equals($secret, $signature);

            if (! $isHmacValid && ! $isTokenValid) {
                throw ValidationException::withMessages([
                    'signature' => ['Invalid webhook signature.'],
                ]);
            }
        }
    }

    /**
     * Retrieve header value case-insensitively.
     *
     * @param  array<string, mixed>  $headers
     */
    protected function getHeaderValue(array $headers, string $key): ?string
    {
        $normalizedKey = strtolower($key);
        foreach ($headers as $k => $v) {
            if (strtolower($k) === $normalizedKey) {
                return is_array($v) ? ($v[0] ?? null) : (string) $v;
            }
        }

        return null;
    }
}
