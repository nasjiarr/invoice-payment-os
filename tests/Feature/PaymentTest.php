<?php

namespace Tests\Feature;

use App\Domain\Payment\PaymentService;
use App\Enums\BusinessRole;
use App\Enums\InvoiceStatus;
use App\Enums\PaymentStatus;
use App\Models\Business;
use App\Models\Customer;
use App\Models\Invoice;
use App\Models\Payment;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class PaymentTest extends TestCase
{
    use RefreshDatabase;

    private function createBusinessWithUser(): array
    {
        $user = User::factory()->create();
        $business = Business::factory()->create(['owner_id' => $user->id]);
        $business->users()->attach($user->id, ['role' => BusinessRole::Owner->value]);
        $customer = Customer::factory()->create(['business_id' => $business->id]);

        return [$user, $business, $customer];
    }

    private function createInvoice(Business $business, Customer $customer, float $total = 1000000.00, InvoiceStatus $status = InvoiceStatus::Sent): Invoice
    {
        return Invoice::factory()->create([
            'business_id' => $business->id,
            'customer_id' => $customer->id,
            'status' => $status,
            'subtotal' => $total,
            'tax' => 0.00,
            'discount' => 0.00,
            'total' => $total,
            'currency' => 'IDR',
        ]);
    }

    public function test_user_can_create_pending_payment(): void
    {
        [$user, $business, $customer] = $this->createBusinessWithUser();
        $invoice = $this->createInvoice($business, $customer, 1000000.00);

        Sanctum::actingAs($user);

        $payload = [
            'amount' => 400000.00,
            'status' => 'pending',
            'payment_method' => 'bank_transfer',
            'notes' => 'Awaiting bank transfer confirmation',
        ];

        $response = $this->postJson("/api/invoices/{$invoice->id}/payments", $payload);

        $response->assertStatus(201)
            ->assertJson([
                'message' => 'Payment recorded successfully',
                'data' => [
                    'business_id' => $business->id,
                    'invoice_id' => $invoice->id,
                    'amount' => '400000.00',
                    'currency' => 'IDR',
                    'status' => 'pending',
                    'payment_method' => 'bank_transfer',
                    'paid_at' => null,
                ],
            ]);

        $this->assertNotNull($response->json('data.transaction_id'));

        // Invoice status should remain unchanged
        $this->assertEquals(InvoiceStatus::Sent, $invoice->fresh()->status);

        // Audit log created
        $this->assertDatabaseHas('audit_logs', [
            'business_id' => $business->id,
            'action' => 'payment.created',
        ]);
    }

    public function test_user_can_record_successful_payment(): void
    {
        [$user, $business, $customer] = $this->createBusinessWithUser();
        $invoice = $this->createInvoice($business, $customer, 500000.00);

        Sanctum::actingAs($user);

        $payload = [
            'amount' => 500000.00,
            'status' => 'paid',
            'payment_method' => 'qris',
        ];

        $response = $this->postJson("/api/invoices/{$invoice->id}/payments", $payload);

        $response->assertStatus(201)
            ->assertJson([
                'message' => 'Payment recorded successfully',
                'data' => [
                    'amount' => '500000.00',
                    'status' => 'paid',
                    'payment_method' => 'qris',
                ],
            ]);

        $this->assertNotNull($response->json('data.paid_at'));
        $this->assertNotNull($response->json('data.transaction_id'));

        // Invoice status becomes paid
        $this->assertEquals(InvoiceStatus::Paid, $invoice->fresh()->status);

        $this->assertDatabaseHas('audit_logs', [
            'business_id' => $business->id,
            'action' => 'payment.received',
        ]);
    }

    public function test_partial_payment_updates_invoice_to_partially_paid(): void
    {
        [$user, $business, $customer] = $this->createBusinessWithUser();
        $invoice = $this->createInvoice($business, $customer, 1000000.00);

        Sanctum::actingAs($user);

        $payload = [
            'amount' => 350000.00,
            'status' => 'paid',
            'payment_method' => 'credit_card',
        ];

        $response = $this->postJson("/api/invoices/{$invoice->id}/payments", $payload);

        $response->assertStatus(201);
        $this->assertEquals(InvoiceStatus::PartiallyPaid, $invoice->fresh()->status);

        $this->assertDatabaseHas('payments', [
            'invoice_id' => $invoice->id,
            'amount' => 350000.00,
            'status' => PaymentStatus::Paid->value,
        ]);
    }

    public function test_full_payment_completes_invoice_after_partial_payment(): void
    {
        [$user, $business, $customer] = $this->createBusinessWithUser();
        $invoice = $this->createInvoice($business, $customer, 1000000.00);

        Sanctum::actingAs($user);

        // First partial payment: 400,000
        $this->postJson("/api/invoices/{$invoice->id}/payments", [
            'amount' => 400000.00,
            'status' => 'paid',
        ])->assertStatus(201);

        $this->assertEquals(InvoiceStatus::PartiallyPaid, $invoice->fresh()->status);

        // Second payment of remaining balance: 600,000
        $this->postJson("/api/invoices/{$invoice->id}/payments", [
            'amount' => 600000.00,
            'status' => 'paid',
        ])->assertStatus(201);

        $this->assertEquals(InvoiceStatus::Paid, $invoice->fresh()->status);
    }

    public function test_overpayment_is_rejected(): void
    {
        [$user, $business, $customer] = $this->createBusinessWithUser();
        $invoice = $this->createInvoice($business, $customer, 1000000.00);

        Sanctum::actingAs($user);

        // Attempting to pay more than the total
        $response = $this->postJson("/api/invoices/{$invoice->id}/payments", [
            'amount' => 1200000.00,
            'status' => 'paid',
        ]);

        $response->assertStatus(422)
            ->assertJsonValidationErrors(['amount']);

        $this->assertDatabaseCount('payments', 0);
        $this->assertEquals(InvoiceStatus::Sent, $invoice->fresh()->status);

        // Now pay 700,000 partially (succeeds)
        $this->postJson("/api/invoices/{$invoice->id}/payments", [
            'amount' => 700000.00,
            'status' => 'paid',
        ])->assertStatus(201);

        // Remaining balance is 300,000. Attempting to pay 350,000 must fail
        $overpaymentResponse = $this->postJson("/api/invoices/{$invoice->id}/payments", [
            'amount' => 350000.00,
            'status' => 'paid',
        ]);

        $overpaymentResponse->assertStatus(422)
            ->assertJsonValidationErrors(['amount']);
    }

    public function test_failed_payment_records_status_without_updating_invoice(): void
    {
        [$user, $business, $customer] = $this->createBusinessWithUser();
        $invoice = $this->createInvoice($business, $customer, 500000.00);

        Sanctum::actingAs($user);

        $response = $this->postJson("/api/invoices/{$invoice->id}/payments", [
            'amount' => 500000.00,
            'status' => 'failed',
            'payment_method' => 'credit_card',
        ]);

        $response->assertStatus(201)
            ->assertJson([
                'data' => [
                    'status' => 'failed',
                    'paid_at' => null,
                ],
            ]);

        $this->assertEquals(InvoiceStatus::Sent, $invoice->fresh()->status);

        $this->assertDatabaseHas('audit_logs', [
            'business_id' => $business->id,
            'action' => 'payment.failed',
        ]);
    }

    public function test_cancelled_payment_updates_status(): void
    {
        [$user, $business, $customer] = $this->createBusinessWithUser();
        $invoice = $this->createInvoice($business, $customer, 500000.00);

        $payment = Payment::factory()->pending()->create([
            'business_id' => $business->id,
            'invoice_id' => $invoice->id,
            'amount' => 500000.00,
        ]);

        Sanctum::actingAs($user);

        $response = $this->postJson("/api/payments/{$payment->id}/cancel");

        $response->assertStatus(200)
            ->assertJson([
                'message' => 'Payment cancelled successfully',
                'data' => [
                    'id' => $payment->id,
                    'status' => 'cancelled',
                ],
            ]);

        $this->assertEquals(PaymentStatus::Cancelled, $payment->fresh()->status);
        $this->assertEquals(InvoiceStatus::Sent, $invoice->fresh()->status);

        $this->assertDatabaseHas('audit_logs', [
            'business_id' => $business->id,
            'action' => 'payment.cancelled',
        ]);
    }

    public function test_payment_isolation_prevents_unauthorized_access(): void
    {
        [$userA, $businessA, $customerA] = $this->createBusinessWithUser();
        [$userB, $businessB, $customerB] = $this->createBusinessWithUser();

        $invoiceA = $this->createInvoice($businessA, $customerA, 500000.00);
        $paymentA = Payment::factory()->pending()->create([
            'business_id' => $businessA->id,
            'invoice_id' => $invoiceA->id,
            'amount' => 500000.00,
        ]);

        Sanctum::actingAs($userB);

        // User B cannot view payments of Invoice A
        $this->getJson("/api/invoices/{$invoiceA->id}/payments")
            ->assertStatus(403);

        // User B cannot create payment for Invoice A
        $this->postJson("/api/invoices/{$invoiceA->id}/payments", [
            'amount' => 500000.00,
            'status' => 'paid',
        ])->assertStatus(403);

        // User B cannot view Payment A
        $this->getJson("/api/payments/{$paymentA->id}")
            ->assertStatus(403);

        // User B cannot process Payment A
        $this->postJson("/api/payments/{$paymentA->id}/process")
            ->assertStatus(403);

        // User B cannot cancel Payment A
        $this->postJson("/api/payments/{$paymentA->id}/cancel")
            ->assertStatus(403);
    }

    public function test_duplicate_payment_processing_is_prevented(): void
    {
        [$user, $business, $customer] = $this->createBusinessWithUser();
        $invoice = $this->createInvoice($business, $customer, 1000000.00);

        $payment = Payment::factory()->pending()->create([
            'business_id' => $business->id,
            'invoice_id' => $invoice->id,
            'amount' => 500000.00,
        ]);

        Sanctum::actingAs($user);

        // First process call succeeds
        $response = $this->postJson("/api/payments/{$payment->id}/process");
        $response->assertStatus(200)
            ->assertJson([
                'data' => [
                    'status' => 'paid',
                ],
            ]);

        $this->assertEquals(PaymentStatus::Paid, $payment->fresh()->status);
        $this->assertEquals(InvoiceStatus::PartiallyPaid, $invoice->fresh()->status);

        // Second process call must fail with 422
        $duplicateResponse = $this->postJson("/api/payments/{$payment->id}/process");
        $duplicateResponse->assertStatus(422)
            ->assertJsonValidationErrors(['status']);

        // Test duplicate transaction_id prevention
        $customTrxId = 'TRX-UNIQUE-TEST-999';
        $this->postJson("/api/invoices/{$invoice->id}/payments", [
            'amount' => 100000.00,
            'status' => 'paid',
            'transaction_id' => $customTrxId,
        ])->assertStatus(201);

        // Reusing the same transaction ID must fail with 422
        $duplicateTrxResponse = $this->postJson("/api/invoices/{$invoice->id}/payments", [
            'amount' => 100000.00,
            'status' => 'paid',
            'transaction_id' => $customTrxId,
        ]);

        $duplicateTrxResponse->assertStatus(422)
            ->assertJsonValidationErrors(['transaction_id']);
    }

    public function test_negative_or_zero_amount_is_rejected(): void
    {
        [$user, $business, $customer] = $this->createBusinessWithUser();
        $invoice = $this->createInvoice($business, $customer, 500000.00);

        Sanctum::actingAs($user);

        // Negative amount
        $this->postJson("/api/invoices/{$invoice->id}/payments", [
            'amount' => -100000.00,
            'status' => 'paid',
        ])->assertStatus(422)
            ->assertJsonValidationErrors(['amount']);

        // Zero amount
        $this->postJson("/api/invoices/{$invoice->id}/payments", [
            'amount' => 0.00,
            'status' => 'paid',
        ])->assertStatus(422)
            ->assertJsonValidationErrors(['amount']);
    }

    public function test_payment_currency_mismatch_is_rejected(): void
    {
        [$user, $business, $customer] = $this->createBusinessWithUser();
        $invoice = $this->createInvoice($business, $customer, 500000.00);

        Sanctum::actingAs($user);

        $response = $this->postJson("/api/invoices/{$invoice->id}/payments", [
            'amount' => 500000.00,
            'currency' => 'USD', // Invoice is IDR
            'status' => 'paid',
        ]);

        $response->assertStatus(422)
            ->assertJsonValidationErrors(['currency']);
    }

    public function test_payment_on_void_or_cancelled_invoice_is_rejected(): void
    {
        [$user, $business, $customer] = $this->createBusinessWithUser();

        $voidInvoice = $this->createInvoice($business, $customer, 500000.00, InvoiceStatus::Void);
        $cancelledInvoice = $this->createInvoice($business, $customer, 500000.00, InvoiceStatus::Cancelled);

        Sanctum::actingAs($user);

        $this->postJson("/api/invoices/{$voidInvoice->id}/payments", [
            'amount' => 500000.00,
            'status' => 'paid',
        ])->assertStatus(422)
            ->assertJsonValidationErrors(['status']);

        $this->postJson("/api/invoices/{$cancelledInvoice->id}/payments", [
            'amount' => 500000.00,
            'status' => 'paid',
        ])->assertStatus(422)
            ->assertJsonValidationErrors(['status']);
    }

    public function test_user_can_view_payment_list_and_detail(): void
    {
        [$user, $business, $customer] = $this->createBusinessWithUser();
        $invoice = $this->createInvoice($business, $customer, 1000000.00);

        $payment = Payment::factory()->paid()->create([
            'business_id' => $business->id,
            'invoice_id' => $invoice->id,
            'amount' => 500000.00,
        ]);

        Sanctum::actingAs($user);

        // List payments for invoice
        $listResponse = $this->getJson("/api/invoices/{$invoice->id}/payments");
        $listResponse->assertStatus(200)
            ->assertJson([
                'message' => 'Payments retrieved successfully',
            ])
            ->assertJsonCount(1, 'data');

        // Show single payment
        $showResponse = $this->getJson("/api/payments/{$payment->id}");
        $showResponse->assertStatus(200)
            ->assertJson([
                'message' => 'Payment retrieved successfully',
                'data' => [
                    'id' => $payment->id,
                    'amount' => '500000.00',
                    'status' => 'paid',
                ],
            ]);
    }

    public function test_exact_business_result_partial_and_full_payment_lifecycle_with_outstanding_calculation(): void
    {
        [$user, $business, $customer] = $this->createBusinessWithUser();
        // Step 1: Create an invoice with Rp10.000.000
        $invoice = $this->createInvoice($business, $customer, 10000000.00);

        Sanctum::actingAs($user);
        $paymentService = app(PaymentService::class);

        // Pre-condition: Outstanding balance equals entire invoice total
        $this->assertEquals(0.00, $paymentService->getTotalPaid($invoice));
        $this->assertEquals(10000000.00, $paymentService->getOutstandingBalance($invoice));
        $this->assertEquals(InvoiceStatus::Sent, $invoice->status);

        // Step 2: Payment of Rp3.000.000
        $partialResponse = $this->postJson("/api/invoices/{$invoice->id}/payments", [
            'amount' => 3000000.00,
            'status' => 'paid',
            'payment_method' => 'bank_transfer',
            'notes' => 'First milestone payment of 30%',
        ]);

        $partialResponse->assertStatus(201)
            ->assertJson([
                'data' => [
                    'amount' => '3000000.00',
                    'status' => 'paid',
                ],
            ]);

        // Business Result Check #1:
        // Invoice status must be partially_paid
        $freshInvoice = $invoice->fresh();
        $this->assertEquals(InvoiceStatus::PartiallyPaid, $freshInvoice->status);
        // Total paid must be Rp3.000.000
        $this->assertEquals(3000000.00, $paymentService->getTotalPaid($freshInvoice));
        // Outstanding balance must be exactly Rp7.000.000
        $this->assertEquals(7000000.00, $paymentService->getOutstandingBalance($freshInvoice));

        // Step 3: Second payment of remaining Rp7.000.000
        $fullResponse = $this->postJson("/api/invoices/{$invoice->id}/payments", [
            'amount' => 7000000.00,
            'status' => 'paid',
            'payment_method' => 'bank_transfer',
            'notes' => 'Final settlement of remaining 70%',
        ]);

        $fullResponse->assertStatus(201)
            ->assertJson([
                'data' => [
                    'amount' => '7000000.00',
                    'status' => 'paid',
                ],
            ]);

        // Business Result Check #2:
        // Invoice status must be paid
        $settledInvoice = $invoice->fresh();
        $this->assertEquals(InvoiceStatus::Paid, $settledInvoice->status);
        // Total paid must be Rp10.000.000
        $this->assertEquals(10000000.00, $paymentService->getTotalPaid($settledInvoice));
        // Outstanding balance must be exactly Rp0
        $this->assertEquals(0.00, $paymentService->getOutstandingBalance($settledInvoice));

        // Step 4: Any further payment attempt must be rejected as overpayment
        $overpaymentResponse = $this->postJson("/api/invoices/{$invoice->id}/payments", [
            'amount' => 100000.00,
            'status' => 'paid',
        ]);

        $overpaymentResponse->assertStatus(422)
            ->assertJsonValidationErrors(['amount']);

        // Assert database state is preserved and not corrupted
        $this->assertDatabaseCount('payments', 2);
        $this->assertEquals(InvoiceStatus::Paid, $invoice->fresh()->status);
        $this->assertEquals(0.00, $paymentService->getOutstandingBalance($invoice->fresh()));
    }
}
