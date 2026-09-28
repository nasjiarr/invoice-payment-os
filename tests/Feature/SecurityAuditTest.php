<?php

namespace Tests\Feature;

use App\Enums\BusinessRole;
use App\Enums\InvoiceStatus;
use App\Enums\PaymentStatus;
use App\Models\Business;
use App\Models\Customer;
use App\Models\Invoice;
use App\Models\Payment;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\RateLimiter;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class SecurityAuditTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        RateLimiter::clear('login');
        RateLimiter::clear('register');
        RateLimiter::clear('api');
        RateLimiter::clear('webhook');
    }

    public function test_unsupported_webhook_provider_is_rejected(): void
    {
        $payload = [
            'event_id' => 'evt_evil_001',
            'event_type' => 'payment.success',
            'transaction_id' => 'TRX-12345',
        ];

        $response = $this->postJson('/api/webhooks/payment/unsupported_evil_gateway', $payload);

        $response->assertStatus(422)
            ->assertJsonValidationErrors(['provider']);
    }

    public function test_webhook_signature_verification_enforced_when_secret_is_configured(): void
    {
        $user = User::factory()->create();
        $business = Business::factory()->create(['owner_id' => $user->id]);
        $customer = Customer::factory()->create(['business_id' => $business->id]);
        $invoice = Invoice::factory()->create([
            'business_id' => $business->id,
            'customer_id' => $customer->id,
            'status' => InvoiceStatus::Sent,
            'total' => 500000.00,
        ]);
        $payment = Payment::factory()->create([
            'business_id' => $business->id,
            'invoice_id' => $invoice->id,
            'amount' => 500000.00,
            'status' => PaymentStatus::Pending,
            'transaction_id' => 'TRX-SIG-TEST-123',
        ]);

        $webhookSecret = 'super_secret_webhook_key_2026';
        Config::set('payment.webhook.secret', $webhookSecret);

        $payload = [
            'event_id' => 'evt_sig_test_001',
            'event_type' => 'payment.success',
            'transaction_id' => $payment->transaction_id,
        ];
        $rawPayload = json_encode($payload);

        // Case 1: Missing signature header -> 422
        $this->call('POST', '/api/webhooks/payment/mock', [], [], [], [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_ACCEPT' => 'application/json',
        ], $rawPayload)->assertStatus(422)
            ->assertJsonValidationErrors(['signature']);

        // Case 2: Tampered / Invalid signature -> 422
        $this->call('POST', '/api/webhooks/payment/mock', [], [], [], [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_ACCEPT' => 'application/json',
            'HTTP_X_WEBHOOK_SIGNATURE' => 'forged_fake_signature_hash',
        ], $rawPayload)->assertStatus(422)
            ->assertJsonValidationErrors(['signature']);

        // Case 3: Valid HMAC-SHA256 signature -> 200 OK
        $validSignature = hash_hmac('sha256', $rawPayload, $webhookSecret);

        $responseValid = $this->call('POST', '/api/webhooks/payment/mock', [], [], [], [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_ACCEPT' => 'application/json',
            'HTTP_X_WEBHOOK_SIGNATURE' => $validSignature,
        ], $rawPayload);

        $responseValid->assertStatus(200)
            ->assertJson(['status' => 'processed']);

        $this->assertEquals(PaymentStatus::Paid, $payment->fresh()->status);
        $this->assertEquals(InvoiceStatus::Paid, $invoice->fresh()->status);
    }

    public function test_login_rate_limiting_throttles_excessive_attempts(): void
    {
        User::factory()->create([
            'email' => 'victim@target.com',
            'password' => Hash::make('correct_password'),
        ]);

        // 5 consecutive failed login attempts
        for ($i = 0; $i < 5; $i++) {
            $response = $this->postJson('/api/login', [
                'email' => 'victim@target.com',
                'password' => 'wrong_password_attempt_'.$i,
            ]);
            $response->assertStatus(401);
        }

        // 6th attempt should be blocked by rate limiter with 429 Too Many Requests
        $throttledResponse = $this->postJson('/api/login', [
            'email' => 'victim@target.com',
            'password' => 'wrong_password_attempt_6',
        ]);

        $throttledResponse->assertStatus(429);
    }

    public function test_pdf_sanitizes_xss_payloads_in_customer_and_business_data(): void
    {
        $user = User::factory()->create();
        $business = Business::factory()->create([
            'owner_id' => $user->id,
            'name' => 'Secure Business',
            'address' => "Line 1\n<script>alert('xss-business')</script>",
        ]);
        $business->users()->attach($user->id, ['role' => BusinessRole::Owner->value]);

        $customer = Customer::factory()->create([
            'business_id' => $business->id,
            'name' => 'Victim <img src=x onerror=alert(1)>',
            'address' => "Street 10\n<script>alert('xss-customer')</script>",
        ]);

        $invoice = Invoice::factory()->create([
            'business_id' => $business->id,
            'customer_id' => $customer->id,
            'status' => InvoiceStatus::Sent,
            'notes' => "Terms:\n<script>document.cookie</script>",
        ]);

        Sanctum::actingAs($user);

        // 1. Verify that HTML view sanitizes dangerous script tags into HTML entities
        $html = view('invoices.pdf', [
            'invoice' => $invoice,
            'business' => $business,
            'customer' => $customer,
            'items' => $invoice->items,
        ])->render();

        // Raw script tags must NEVER be present in rendered HTML
        $this->assertStringNotContainsString("<script>alert('xss-business')</script>", $html);
        $this->assertStringNotContainsString("<script>alert('xss-customer')</script>", $html);
        $this->assertStringNotContainsString('<script>document.cookie</script>', $html);
        $this->assertStringNotContainsString('<img src=x onerror=alert(1)>', $html);

        // Sanitized HTML entities must be present
        $this->assertStringContainsString('&lt;script&gt;', $html);

        // 2. Verify that PDF endpoint returns HTTP 200 with application/pdf header safely
        $response = $this->get("/api/invoices/{$invoice->id}/pdf");
        $response->assertStatus(200);
        $this->assertEquals('application/pdf', $response->headers->get('Content-Type'));
    }

    public function test_sql_injection_attempt_in_search_and_sort_is_neutralized(): void
    {
        $user = User::factory()->create();
        $business = Business::factory()->create(['owner_id' => $user->id]);
        $business->users()->attach($user->id, ['role' => BusinessRole::Owner->value]);
        Customer::factory()->create(['business_id' => $business->id, 'name' => 'Safe Customer']);

        Sanctum::actingAs($user);

        // 1. SQL Injection attempt in search query
        $responseSearch = $this->getJson("/api/customers?search=' OR '1'='1");
        $responseSearch->assertStatus(200);
        $this->assertCount(0, $responseSearch->json('data'));

        // 2. SQL Injection attempt in sort query
        $responseSort = $this->getJson('/api/customers?sort=id; DROP TABLE customers;--&direction=asc');
        $responseSort->assertStatus(200);
        // Table still exists and query handled safely
        $this->assertDatabaseHas('customers', ['name' => 'Safe Customer']);
    }

    public function test_api_nonexistent_resource_returns_clean_json_without_internal_leakage(): void
    {
        $user = User::factory()->create();
        Sanctum::actingAs($user);

        $response = $this->getJson('/api/nonexistent-system-endpoint-999');

        $response->assertStatus(404)
            ->assertJson([
                'message' => 'Resource not found.',
            ]);

        // Ensure stack trace and debug info are absent
        $this->assertArrayNotHasKey('trace', $response->json());
        $this->assertArrayNotHasKey('exception', $response->json());
    }

    public function test_zero_trust_payment_amount_ignores_webhook_payload_amount_tampering(): void
    {
        $user = User::factory()->create();
        $business = Business::factory()->create(['owner_id' => $user->id]);
        $customer = Customer::factory()->create(['business_id' => $business->id]);
        $invoice = Invoice::factory()->create([
            'business_id' => $business->id,
            'customer_id' => $customer->id,
            'status' => InvoiceStatus::Sent,
            'total' => 1000000.00,
        ]);

        // Legitimate payment record created for Rp1.000.000
        $payment = Payment::factory()->create([
            'business_id' => $business->id,
            'invoice_id' => $invoice->id,
            'amount' => 1000000.00,
            'status' => PaymentStatus::Pending,
            'transaction_id' => 'TRX-ZERO-TRUST-001',
        ]);

        // Malicious webhook tries to claim payment was for 1 rupiah or 999 million
        $tamperedPayload = [
            'event_id' => 'evt_tampered_amount_001',
            'event_type' => 'payment.success',
            'transaction_id' => $payment->transaction_id,
            'data' => [
                'amount' => 1.00, // Attacker forged amount!
            ],
        ];

        $response = $this->postJson('/api/webhooks/payment/mock', $tamperedPayload);
        $response->assertStatus(200);

        // Business result: Database payment amount remains authentic Rp1.000.000
        $payment->refresh();
        $this->assertEquals(PaymentStatus::Paid, $payment->status);
        $this->assertEquals('1000000.00', $payment->amount);

        // Invoice status transitioned based on authentic 1,000,000 payment
        $invoice->refresh();
        $this->assertEquals(InvoiceStatus::Paid, $invoice->status);
    }
}
