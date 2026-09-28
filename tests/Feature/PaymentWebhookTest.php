<?php

namespace Tests\Feature;

use App\Enums\BusinessRole;
use App\Enums\InvoiceStatus;
use App\Enums\PaymentEventStatus;
use App\Enums\PaymentStatus;
use App\Models\Business;
use App\Models\Customer;
use App\Models\Invoice;
use App\Models\Payment;
use App\Models\PaymentEvent;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PaymentWebhookTest extends TestCase
{
    use RefreshDatabase;

    private function createPaymentFixture(float $amount = 500000.00, PaymentStatus $status = PaymentStatus::Pending): array
    {
        $user = User::factory()->create();
        $business = Business::factory()->create(['owner_id' => $user->id]);
        $business->users()->attach($user->id, ['role' => BusinessRole::Owner->value]);
        $customer = Customer::factory()->create(['business_id' => $business->id]);

        $invoice = Invoice::factory()->create([
            'business_id' => $business->id,
            'customer_id' => $customer->id,
            'status' => InvoiceStatus::Sent,
            'subtotal' => $amount,
            'tax' => 0.00,
            'discount' => 0.00,
            'total' => $amount,
            'currency' => 'IDR',
        ]);

        $payment = Payment::factory()->create([
            'business_id' => $business->id,
            'invoice_id' => $invoice->id,
            'amount' => $amount,
            'currency' => 'IDR',
            'status' => $status,
            'transaction_id' => 'TRX-WEBHOOK-'.fake()->unique()->numerify('######'),
            'paid_at' => $status === PaymentStatus::Paid ? now() : null,
        ]);

        return [$user, $business, $customer, $invoice, $payment];
    }

    public function test_first_webhook_processes_payment_and_invoice_successfully(): void
    {
        [, , , $invoice, $payment] = $this->createPaymentFixture(500000.00, PaymentStatus::Pending);

        $payload = [
            'event_id' => 'evt_first_001',
            'event_type' => 'payment.success',
            'transaction_id' => $payment->transaction_id,
            'data' => [
                'amount' => 500000.00,
            ],
        ];

        $response = $this->postJson('/api/webhooks/payment/mock', $payload);

        $response->assertStatus(200)
            ->assertJson([
                'status' => 'processed',
                'message' => 'Webhook processed successfully',
                'event_id' => 'evt_first_001',
                'provider' => 'mock',
            ]);

        // Payment status updated to paid
        $this->assertEquals(PaymentStatus::Paid, $payment->fresh()->status);
        $this->assertNotNull($payment->fresh()->paid_at);

        // Invoice status transitioned to paid
        $this->assertEquals(InvoiceStatus::Paid, $invoice->fresh()->status);

        // PaymentEvent record created as processed
        $this->assertDatabaseHas('payment_events', [
            'provider' => 'mock',
            'event_id' => 'evt_first_001',
            'event_type' => 'payment.success',
            'status' => PaymentEventStatus::Processed->value,
        ]);
    }

    public function test_duplicate_webhook_is_detected_and_skipped(): void
    {
        [, , , $invoice, $payment] = $this->createPaymentFixture(500000.00, PaymentStatus::Pending);

        $payload = [
            'event_id' => 'evt_dup_001',
            'event_type' => 'payment.success',
            'transaction_id' => $payment->transaction_id,
        ];

        // First delivery: processes successfully
        $firstResponse = $this->postJson('/api/webhooks/payment/mock', $payload);
        $firstResponse->assertStatus(200)
            ->assertJson(['status' => 'processed']);

        $this->assertEquals(PaymentStatus::Paid, $payment->fresh()->status);

        // Second delivery (duplicate): detected and skipped
        $secondResponse = $this->postJson('/api/webhooks/payment/mock', $payload);
        $secondResponse->assertStatus(200)
            ->assertJson([
                'status' => 'duplicate',
                'message' => 'Duplicate webhook event detected, skipping',
                'event_id' => 'evt_dup_001',
            ]);

        // Payment and invoice remain unchanged
        $this->assertEquals(PaymentStatus::Paid, $payment->fresh()->status);
        $this->assertEquals(InvoiceStatus::Paid, $invoice->fresh()->status);

        // Still only 1 payment event in the database
        $this->assertDatabaseCount('payment_events', 1);
    }

    public function test_concurrent_duplicate_webhook_is_handled_safely(): void
    {
        [, , , , $payment] = $this->createPaymentFixture(500000.00, PaymentStatus::Pending);

        // Simulate Thread 1 having already inserted an in-flight pending event record
        PaymentEvent::create([
            'provider' => 'mock',
            'event_id' => 'evt_concurrent_001',
            'event_type' => 'payment.success',
            'payload' => ['transaction_id' => $payment->transaction_id],
            'status' => PaymentEventStatus::Pending,
        ]);

        $payload = [
            'event_id' => 'evt_concurrent_001',
            'event_type' => 'payment.success',
            'transaction_id' => $payment->transaction_id,
        ];

        // Thread 2 arrives while Thread 1 is in-flight
        $response = $this->postJson('/api/webhooks/payment/mock', $payload);

        $response->assertStatus(200)
            ->assertJson([
                'status' => 'duplicate',
                'event_id' => 'evt_concurrent_001',
            ]);

        // Only 1 record exists
        $this->assertEquals(1, PaymentEvent::where('event_id', 'evt_concurrent_001')->count());
    }

    public function test_invalid_webhook_returns_422(): void
    {
        // Missing event_id and event_type
        $response = $this->postJson('/api/webhooks/payment/mock', [
            'foo' => 'bar',
        ]);

        $response->assertStatus(422)
            ->assertJsonValidationErrors(['event']);
    }

    public function test_unknown_payment_returns_404_and_records_failed_event(): void
    {
        $payload = [
            'event_id' => 'evt_unknown_001',
            'event_type' => 'payment.success',
            'transaction_id' => 'TRX-NONEXISTENT-999999',
        ];

        $response = $this->postJson('/api/webhooks/payment/mock', $payload);

        $response->assertStatus(404)
            ->assertJson([
                'message' => "Payment with transaction ID 'TRX-NONEXISTENT-999999' not found.",
            ]);

        // Failed event recorded
        $this->assertDatabaseHas('payment_events', [
            'provider' => 'mock',
            'event_id' => 'evt_unknown_001',
            'status' => PaymentEventStatus::Failed->value,
        ]);
    }

    public function test_failed_processing_rolls_back_and_marks_event_failed(): void
    {
        [, , , $invoice, $payment] = $this->createPaymentFixture(500000.00, PaymentStatus::Pending);

        // Put invoice in terminal Cancelled status to cause business logic rejection
        $invoice->update(['status' => InvoiceStatus::Cancelled]);

        $payload = [
            'event_id' => 'evt_failed_001',
            'event_type' => 'payment.success',
            'transaction_id' => $payment->transaction_id,
        ];

        $response = $this->postJson('/api/webhooks/payment/mock', $payload);

        $response->assertStatus(422);

        // Payment status remained pending (rolled back)
        $this->assertEquals(PaymentStatus::Pending, $payment->fresh()->status);

        // Event recorded as failed
        $this->assertDatabaseHas('payment_events', [
            'provider' => 'mock',
            'event_id' => 'evt_failed_001',
            'status' => PaymentEventStatus::Failed->value,
        ]);
    }

    public function test_retryable_processing_allows_failed_event_to_be_reprocessed(): void
    {
        [, , , $invoice, $payment] = $this->createPaymentFixture(500000.00, PaymentStatus::Pending);

        // Pre-create an event with status 'failed' (simulating a prior failed delivery attempt)
        PaymentEvent::create([
            'provider' => 'mock',
            'event_id' => 'evt_retry_001',
            'event_type' => 'payment.success',
            'payload' => ['transaction_id' => $payment->transaction_id],
            'status' => PaymentEventStatus::Failed,
        ]);

        $payload = [
            'event_id' => 'evt_retry_001',
            'event_type' => 'payment.success',
            'transaction_id' => $payment->transaction_id,
        ];

        // Redelivery by provider
        $response = $this->postJson('/api/webhooks/payment/mock', $payload);

        $response->assertStatus(200)
            ->assertJson([
                'status' => 'processed',
                'event_id' => 'evt_retry_001',
            ]);

        // Payment status is now paid
        $this->assertEquals(PaymentStatus::Paid, $payment->fresh()->status);
        $this->assertEquals(InvoiceStatus::Paid, $invoice->fresh()->status);

        // Event status transitioned from failed to processed
        $this->assertDatabaseHas('payment_events', [
            'provider' => 'mock',
            'event_id' => 'evt_retry_001',
            'status' => PaymentEventStatus::Processed->value,
        ]);
    }

    public function test_webhook_handles_failed_payment_event(): void
    {
        [, , , $invoice, $payment] = $this->createPaymentFixture(500000.00, PaymentStatus::Pending);

        $payload = [
            'event_id' => 'evt_failure_notice_001',
            'event_type' => 'payment.failed',
            'transaction_id' => $payment->transaction_id,
        ];

        $response = $this->postJson('/api/webhooks/payment/mock', $payload);

        $response->assertStatus(200)
            ->assertJson(['status' => 'processed']);

        $this->assertEquals(PaymentStatus::Failed, $payment->fresh()->status);
        $this->assertEquals(InvoiceStatus::Sent, $invoice->fresh()->status);

        $this->assertDatabaseHas('audit_logs', [
            'action' => 'payment.failed',
        ]);
    }

    public function test_webhook_handles_refund_event(): void
    {
        [, , , $invoice, $payment] = $this->createPaymentFixture(500000.00, PaymentStatus::Paid);
        $invoice->update(['status' => InvoiceStatus::Paid]);

        $payload = [
            'event_id' => 'evt_refund_001',
            'event_type' => 'payment.refunded',
            'transaction_id' => $payment->transaction_id,
            'data' => [
                'amount' => 500000.00,
                'reason' => 'Customer request via gateway',
            ],
        ];

        $response = $this->postJson('/api/webhooks/payment/mock', $payload);

        $response->assertStatus(200)
            ->assertJson(['status' => 'processed']);

        $this->assertEquals(PaymentStatus::Refunded, $payment->fresh()->status);
        $this->assertEquals(InvoiceStatus::Sent, $invoice->fresh()->status);

        $this->assertDatabaseHas('audit_logs', [
            'action' => 'payment.refunded',
        ]);
    }
}
