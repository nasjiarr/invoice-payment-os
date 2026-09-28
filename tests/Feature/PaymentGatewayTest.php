<?php

namespace Tests\Feature;

use App\Domain\Payment\Contracts\PaymentGatewayInterface;
use App\Domain\Payment\Gateways\MockPaymentGateway;
use App\Domain\Payment\Gateways\PaymentGatewayResponse;
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

class PaymentGatewayTest extends TestCase
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

    private function createInvoice(Business $business, Customer $customer, float $total = 1000000.00): Invoice
    {
        return Invoice::factory()->create([
            'business_id' => $business->id,
            'customer_id' => $customer->id,
            'status' => InvoiceStatus::Sent,
            'subtotal' => $total,
            'tax' => 0.00,
            'discount' => 0.00,
            'total' => $total,
            'currency' => 'IDR',
        ]);
    }

    public function test_payment_gateway_interface_is_resolved_from_service_container(): void
    {
        $gateway = app(PaymentGatewayInterface::class);

        $this->assertInstanceOf(PaymentGatewayInterface::class, $gateway);
        $this->assertInstanceOf(MockPaymentGateway::class, $gateway);

        $service = app(PaymentService::class);
        $this->assertInstanceOf(PaymentGatewayInterface::class, $service->getGateway());
    }

    public function test_payment_creation_interacts_with_gateway(): void
    {
        [$user, $business, $customer] = $this->createBusinessWithUser();
        $invoice = $this->createInvoice($business, $customer, 500000.00);

        Sanctum::actingAs($user);

        $response = $this->postJson("/api/invoices/{$invoice->id}/payments", [
            'amount' => 500000.00,
            'status' => 'pending',
            'payment_method' => 'mock_gateway',
        ]);

        $response->assertStatus(201)
            ->assertJson([
                'data' => [
                    'status' => 'pending',
                    'amount' => '500000.00',
                ],
            ]);

        $this->assertDatabaseHas('audit_logs', [
            'business_id' => $business->id,
            'action' => 'payment.created',
        ]);
    }

    public function test_payment_service_handles_gateway_failure(): void
    {
        [$user, $business, $customer] = $this->createBusinessWithUser();
        $invoice = $this->createInvoice($business, $customer, 500000.00);

        // Configure mock gateway to simulate failure
        /** @var MockPaymentGateway $mockGateway */
        $mockGateway = app(PaymentGatewayInterface::class);
        $mockGateway->shouldFail(true, 'Payment rejected by bank partner');

        Sanctum::actingAs($user);

        $response = $this->postJson("/api/invoices/{$invoice->id}/payments", [
            'amount' => 500000.00,
            'status' => 'paid',
        ]);

        $response->assertStatus(422)
            ->assertJsonValidationErrors(['gateway']);
    }

    public function test_payment_gateway_status_can_be_queried(): void
    {
        [$user, $business, $customer] = $this->createBusinessWithUser();
        $invoice = $this->createInvoice($business, $customer, 500000.00);

        $payment = Payment::factory()->paid()->create([
            'business_id' => $business->id,
            'invoice_id' => $invoice->id,
            'amount' => 500000.00,
            'transaction_id' => 'MOCK-TEST-QUERY-01',
        ]);

        Sanctum::actingAs($user);

        $response = $this->getJson("/api/payments/{$payment->id}/status");

        $response->assertStatus(200)
            ->assertJson([
                'message' => 'Payment status retrieved successfully',
                'data' => [
                    'success' => true,
                    'transaction_id' => 'MOCK-TEST-QUERY-01',
                    'status' => 'paid',
                ],
            ]);
    }

    public function test_payment_status_can_be_synchronized_from_gateway(): void
    {
        [$user, $business, $customer] = $this->createBusinessWithUser();
        $invoice = $this->createInvoice($business, $customer, 1000000.00);

        $payment = Payment::factory()->pending()->create([
            'business_id' => $business->id,
            'invoice_id' => $invoice->id,
            'amount' => 1000000.00,
            'transaction_id' => 'MOCK-SYNC-TEST-02',
        ]);

        /** @var MockPaymentGateway $mockGateway */
        $mockGateway = app(PaymentGatewayInterface::class);
        $mockGateway->setTransactionStatus('MOCK-SYNC-TEST-02', PaymentStatus::Paid);

        /** @var PaymentService $service */
        $service = app(PaymentService::class);
        $updatedPayment = $service->syncPaymentStatus($payment, $user);

        $this->assertEquals(PaymentStatus::Paid, $updatedPayment->status);
        $this->assertEquals(InvoiceStatus::Paid, $invoice->fresh()->status);
    }

    public function test_payment_can_be_refunded_via_gateway(): void
    {
        [$user, $business, $customer] = $this->createBusinessWithUser();
        $invoice = $this->createInvoice($business, $customer, 500000.00);

        $payment = Payment::factory()->paid()->create([
            'business_id' => $business->id,
            'invoice_id' => $invoice->id,
            'amount' => 500000.00,
            'transaction_id' => 'MOCK-REFUND-001',
        ]);

        $invoice->update(['status' => InvoiceStatus::Paid]);

        Sanctum::actingAs($user);

        $response = $this->postJson("/api/payments/{$payment->id}/refund", [
            'amount' => 500000.00,
            'reason' => 'Customer requested cancellation',
        ]);

        $response->assertStatus(200)
            ->assertJson([
                'message' => 'Payment refunded successfully',
                'data' => [
                    'id' => $payment->id,
                    'status' => 'refunded',
                ],
            ]);

        $this->assertEquals(PaymentStatus::Refunded, $payment->fresh()->status);
        $this->assertEquals(InvoiceStatus::Sent, $invoice->fresh()->status);

        $this->assertDatabaseHas('audit_logs', [
            'business_id' => $business->id,
            'action' => 'payment.refunded',
        ]);
    }

    public function test_payment_refund_rejected_when_payment_not_paid(): void
    {
        [$user, $business, $customer] = $this->createBusinessWithUser();
        $invoice = $this->createInvoice($business, $customer, 500000.00);

        $payment = Payment::factory()->pending()->create([
            'business_id' => $business->id,
            'invoice_id' => $invoice->id,
            'amount' => 500000.00,
        ]);

        Sanctum::actingAs($user);

        $response = $this->postJson("/api/payments/{$payment->id}/refund", [
            'amount' => 500000.00,
        ]);

        $response->assertStatus(422)
            ->assertJsonValidationErrors(['status']);
    }

    public function test_refund_rejected_if_gateway_declines(): void
    {
        [$user, $business, $customer] = $this->createBusinessWithUser();
        $invoice = $this->createInvoice($business, $customer, 500000.00);

        $payment = Payment::factory()->paid()->create([
            'business_id' => $business->id,
            'invoice_id' => $invoice->id,
            'amount' => 500000.00,
        ]);

        /** @var MockPaymentGateway $mockGateway */
        $mockGateway = app(PaymentGatewayInterface::class);
        $mockGateway->shouldFail(true, 'Refund window expired');

        Sanctum::actingAs($user);

        $response = $this->postJson("/api/payments/{$payment->id}/refund", [
            'amount' => 500000.00,
        ]);

        $response->assertStatus(422)
            ->assertJsonValidationErrors(['gateway']);
    }

    public function test_swapping_gateway_implementation_in_service_container(): void
    {
        // Define an alternative fake gateway implementing the contract
        $customGateway = new class implements PaymentGatewayInterface
        {
            public bool $createPaymentCalled = false;

            public function createPayment(Payment $payment, array $options = []): PaymentGatewayResponse
            {
                $this->createPaymentCalled = true;

                return PaymentGatewayResponse::success(
                    transactionId: 'CUSTOM-PROVIDER-12345',
                    status: PaymentStatus::Paid,
                    paymentUrl: 'https://custom-gateway.test/checkout',
                    payload: ['provider' => 'custom-provider']
                );
            }

            public function getPaymentStatus(string $transactionId): PaymentGatewayResponse
            {
                return PaymentGatewayResponse::success($transactionId, PaymentStatus::Paid);
            }

            public function refundPayment(Payment $payment, ?float $amount = null, ?string $reason = null): PaymentGatewayResponse
            {
                return PaymentGatewayResponse::refunded('CUSTOM-REFUND-123');
            }
        };

        // Swap the binding in the Laravel Service Container
        $this->app->instance(PaymentGatewayInterface::class, $customGateway);

        // Resolve PaymentService - it automatically receives the swapped gateway
        $service = app(PaymentService::class);
        $this->assertSame($customGateway, $service->getGateway());

        [$user, $business, $customer] = $this->createBusinessWithUser();
        $invoice = $this->createInvoice($business, $customer, 300000.00);

        // Process a payment
        $payment = $service->createPayment($invoice, $user, [
            'amount' => 300000.00,
            'status' => 'paid',
        ]);

        $this->assertTrue($customGateway->createPaymentCalled);
        $this->assertEquals(PaymentStatus::Paid, $payment->status);
        $this->assertEquals(InvoiceStatus::Paid, $invoice->fresh()->status);
    }
}
