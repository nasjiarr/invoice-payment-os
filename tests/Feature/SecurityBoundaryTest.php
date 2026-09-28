<?php

namespace Tests\Feature;

use App\Enums\BusinessRole;
use App\Enums\InvoiceStatus;
use App\Enums\PaymentStatus;
use App\Models\Business;
use App\Models\Customer;
use App\Models\Invoice;
use App\Models\Payment;
use App\Models\Product;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class SecurityBoundaryTest extends TestCase
{
    use RefreshDatabase;

    private User $userA;

    private Business $businessA;

    private Customer $customerA;

    private Product $productA;

    private Invoice $invoiceA;

    private Payment $paymentA;

    private User $userB;

    private Business $businessB;

    private Customer $customerB;

    private Product $productB;

    private Invoice $invoiceB;

    private Payment $paymentB;

    protected function setUp(): void
    {
        parent::setUp();

        // Tenant A Setup
        $this->userA = User::factory()->create(['name' => 'Tenant Owner A', 'email' => 'user.a@tenant-a.com']);
        $this->businessA = Business::factory()->create(['owner_id' => $this->userA->id, 'name' => 'Business A Alpha']);
        $this->businessA->users()->attach($this->userA->id, ['role' => BusinessRole::Owner->value]);
        $this->customerA = Customer::factory()->create(['business_id' => $this->businessA->id, 'name' => 'Customer A']);
        $this->productA = Product::factory()->create(['business_id' => $this->businessA->id, 'name' => 'Product A', 'price' => 100000.00]);
        $this->invoiceA = Invoice::factory()->create([
            'business_id' => $this->businessA->id,
            'customer_id' => $this->customerA->id,
            'status' => InvoiceStatus::Sent,
            'subtotal' => 100000.00,
            'total' => 100000.00,
            'currency' => 'IDR',
            'notes' => 'Original Invoice A Notes',
        ]);
        $this->paymentA = Payment::factory()->pending()->create([
            'business_id' => $this->businessA->id,
            'invoice_id' => $this->invoiceA->id,
            'amount' => 50000.00,
            'status' => PaymentStatus::Pending,
        ]);

        // Tenant B Setup
        $this->userB = User::factory()->create(['name' => 'Tenant Owner B', 'email' => 'user.b@tenant-b.com']);
        $this->businessB = Business::factory()->create(['owner_id' => $this->userB->id, 'name' => 'Business B Beta']);
        $this->businessB->users()->attach($this->userB->id, ['role' => BusinessRole::Owner->value]);
        $this->customerB = Customer::factory()->create(['business_id' => $this->businessB->id, 'name' => 'Customer B']);
        $this->productB = Product::factory()->create(['business_id' => $this->businessB->id, 'name' => 'Product B', 'price' => 500000.00]);
        $this->invoiceB = Invoice::factory()->create([
            'business_id' => $this->businessB->id,
            'customer_id' => $this->customerB->id,
            'status' => InvoiceStatus::Sent,
            'subtotal' => 500000.00,
            'total' => 500000.00,
            'currency' => 'IDR',
            'notes' => 'Original Invoice B Notes',
        ]);
        $this->paymentB = Payment::factory()->pending()->create([
            'business_id' => $this->businessB->id,
            'invoice_id' => $this->invoiceB->id,
            'amount' => 200000.00,
            'status' => PaymentStatus::Pending,
        ]);
    }

    public function test_user_a_cannot_read_update_or_delete_user_b_business(): void
    {
        Sanctum::actingAs($this->userA);

        // Read Business B -> 403
        $this->getJson("/api/business/{$this->businessB->id}")
            ->assertStatus(403);

        // Update Business B -> 403
        $this->putJson("/api/business/{$this->businessB->id}", [
            'name' => 'Tampered Business B Name',
        ])->assertStatus(403);

        // Delete Business B -> 403
        $this->deleteJson("/api/business/{$this->businessB->id}")
            ->assertStatus(403);

        // Business result verification: Business B is unmodified
        $freshB = $this->businessB->fresh();
        $this->assertEquals('Business B Beta', $freshB->name);
        $this->assertEquals($this->userB->id, $freshB->owner_id);
    }

    public function test_user_a_cannot_read_update_or_delete_user_b_customer(): void
    {
        Sanctum::actingAs($this->userA);

        // Customer B must not appear in User A customer list
        $listResponse = $this->getJson('/api/customers');
        $listResponse->assertStatus(200);
        $ids = collect($listResponse->json('data'))->pluck('id')->all();
        $this->assertNotContains($this->customerB->id, $ids);

        // Direct read -> 403
        $this->getJson("/api/customers/{$this->customerB->id}")
            ->assertStatus(403);

        // Update -> 403
        $this->putJson("/api/customers/{$this->customerB->id}", [
            'name' => 'Tampered Customer B Name',
        ])->assertStatus(403);

        // Delete -> 403
        $this->deleteJson("/api/customers/{$this->customerB->id}")
            ->assertStatus(403);

        // Cross-tenant create attempt in Business B
        $this->postJson('/api/customers', [
            'business_id' => $this->businessB->id,
            'name' => 'Malicious Customer in B',
            'email' => 'malicious@hack.com',
        ])->assertStatus(403);

        // Business result verification: Customer B is unmodified
        $this->assertEquals('Customer B', $this->customerB->fresh()->name);
        $this->assertDatabaseMissing('customers', ['name' => 'Malicious Customer in B']);
    }

    public function test_user_a_cannot_read_update_or_delete_user_b_product(): void
    {
        Sanctum::actingAs($this->userA);

        // Product B must not appear in User A product list
        $listResponse = $this->getJson('/api/products');
        $listResponse->assertStatus(200);
        $ids = collect($listResponse->json('data'))->pluck('id')->all();
        $this->assertNotContains($this->productB->id, $ids);

        // Direct read -> 403
        $this->getJson("/api/products/{$this->productB->id}")
            ->assertStatus(403);

        // Update -> 403
        $this->putJson("/api/products/{$this->productB->id}", [
            'name' => 'Tampered Product B Name',
            'price' => 10.00,
        ])->assertStatus(403);

        // Delete -> 403
        $this->deleteJson("/api/products/{$this->productB->id}")
            ->assertStatus(403);

        // Cross-tenant create attempt in Business B
        $this->postJson('/api/products', [
            'business_id' => $this->businessB->id,
            'name' => 'Malicious Product in B',
            'price' => 1000.00,
        ])->assertStatus(403);

        // Business result verification: Product B is unmodified
        $freshProductB = $this->productB->fresh();
        $this->assertEquals('Product B', $freshProductB->name);
        $this->assertEquals('500000.00', $freshProductB->price);
        $this->assertDatabaseMissing('products', ['name' => 'Malicious Product in B']);
    }

    public function test_user_a_cannot_access_or_alter_user_b_invoice_and_actions(): void
    {
        Sanctum::actingAs($this->userA);

        // Invoice B must not appear in User A invoice list
        $listResponse = $this->getJson('/api/invoices');
        $listResponse->assertStatus(200);
        $ids = collect($listResponse->json('data'))->pluck('id')->all();
        $this->assertNotContains($this->invoiceB->id, $ids);

        // Direct read -> 403
        $this->getJson("/api/invoices/{$this->invoiceB->id}")
            ->assertStatus(403);

        // Update -> 403
        $this->putJson("/api/invoices/{$this->invoiceB->id}", [
            'notes' => 'Tampered Invoice B Notes',
        ])->assertStatus(403);

        // Delete -> 403
        $this->deleteJson("/api/invoices/{$this->invoiceB->id}")
            ->assertStatus(403);

        // Actions: send, void, cancel -> 403
        $this->postJson("/api/invoices/{$this->invoiceB->id}/send")
            ->assertStatus(403);
        $this->postJson("/api/invoices/{$this->invoiceB->id}/void")
            ->assertStatus(403);
        $this->postJson("/api/invoices/{$this->invoiceB->id}/cancel")
            ->assertStatus(403);

        // PDF Generation -> 403
        $this->getJson("/api/invoices/{$this->invoiceB->id}/pdf")
            ->assertStatus(403);

        // Create invoice directly inside Business B -> 403
        $this->postJson('/api/invoices', [
            'business_id' => $this->businessB->id,
            'customer_id' => $this->customerB->id,
            'issue_date' => now()->toDateString(),
            'due_date' => now()->addDays(7)->toDateString(),
            'items' => [
                ['description' => 'Malicious Item', 'quantity' => 1, 'unit_price' => 1000.00],
            ],
        ])->assertStatus(403);

        // Business result verification: Invoice B remains untouched
        $freshInvoiceB = $this->invoiceB->fresh();
        $this->assertEquals(InvoiceStatus::Sent, $freshInvoiceB->status);
        $this->assertEquals('Original Invoice B Notes', $freshInvoiceB->notes);
        $this->assertDatabaseMissing('invoices', ['notes' => 'Tampered Invoice B Notes']);
    }

    public function test_user_a_cannot_cross_reference_customer_or_product_belonging_to_business_b(): void
    {
        Sanctum::actingAs($this->userA);

        // Attempt 1: User A creates invoice in Business A, but passes customer_id of Business B
        $responseCustomerSpoof = $this->postJson('/api/invoices', [
            'business_id' => $this->businessA->id,
            'customer_id' => $this->customerB->id, // Belongs to Business B!
            'issue_date' => now()->toDateString(),
            'due_date' => now()->addDays(14)->toDateString(),
            'items' => [
                ['description' => 'Valid Service', 'quantity' => 1, 'unit_price' => 100000.00],
            ],
        ]);

        $responseCustomerSpoof->assertStatus(422)
            ->assertJsonValidationErrors(['customer_id']);

        // Attempt 2: User A creates invoice in Business A, but uses product_id of Business B
        $responseProductSpoof = $this->postJson('/api/invoices', [
            'business_id' => $this->businessA->id,
            'customer_id' => $this->customerA->id,
            'issue_date' => now()->toDateString(),
            'due_date' => now()->addDays(14)->toDateString(),
            'items' => [
                [
                    'product_id' => $this->productB->id, // Belongs to Business B!
                    'description' => 'Product B Borrowed',
                    'quantity' => 1,
                    'unit_price' => 500000.00,
                ],
            ],
        ]);

        $responseProductSpoof->assertStatus(422)
            ->assertJsonValidationErrors(['items.0.product_id']);

        // Business result verification: No invoice was created for either attempt
        $this->assertDatabaseMissing('invoices', ['customer_id' => $this->customerB->id, 'business_id' => $this->businessA->id]);
        $this->assertDatabaseMissing('invoice_items', ['product_id' => $this->productB->id, 'description' => 'Product B Borrowed']);
    }

    public function test_user_a_cannot_access_or_manipulate_payment_for_invoice_b(): void
    {
        Sanctum::actingAs($this->userA);

        // 1. User A cannot list payments of Invoice B -> 403
        $this->getJson("/api/invoices/{$this->invoiceB->id}/payments")
            ->assertStatus(403);

        // 2. User A cannot record payment for Invoice B -> 403
        $createPaymentResponse = $this->postJson("/api/invoices/{$this->invoiceB->id}/payments", [
            'amount' => 100000.00,
            'status' => 'paid',
        ]);
        $createPaymentResponse->assertStatus(403);

        // 3. User A cannot view Payment B -> 403
        $this->getJson("/api/payments/{$this->paymentB->id}")
            ->assertStatus(403);

        // 4. User A cannot cancel Payment B -> 403
        $this->postJson("/api/payments/{$this->paymentB->id}/cancel")
            ->assertStatus(403);

        // 5. User A cannot process Payment B -> 403
        $this->postJson("/api/payments/{$this->paymentB->id}/process")
            ->assertStatus(403);

        // Business result verification: Payment B and Invoice B are unchanged
        $this->assertEquals(PaymentStatus::Pending, $this->paymentB->fresh()->status);
        $this->assertEquals(InvoiceStatus::Sent, $this->invoiceB->fresh()->status);
        $this->assertDatabaseMissing('payments', [
            'invoice_id' => $this->invoiceB->id,
            'amount' => 100000.00,
        ]);
    }

    public function test_user_a_cannot_view_revenue_report_of_business_b(): void
    {
        Sanctum::actingAs($this->userA);

        // Attempting to filter revenue report by Business B -> 403 Forbidden
        $response = $this->getJson("/api/reports/revenue?business_id={$this->businessB->id}");
        $response->assertStatus(403);
    }

    public function test_revoked_token_is_immediately_rejected_with_401(): void
    {
        $token = $this->userA->createToken('disposable_token')->plainTextToken;

        // Valid access with token
        $this->withHeader('Authorization', 'Bearer '.$token)
            ->getJson('/api/user')
            ->assertStatus(200);

        // Revoke all tokens for User A
        $this->userA->tokens()->delete();

        // Flush in-memory auth guard cache from previous request
        $this->app['auth']->forgetGuards();

        // Attempt access with the revoked token -> 401 Unauthenticated
        $this->withHeader('Authorization', 'Bearer '.$token)
            ->getJson('/api/user')
            ->assertStatus(401);

        $this->withHeader('Authorization', 'Bearer '.$token)
            ->getJson('/api/invoices')
            ->assertStatus(401);

        $this->withHeader('Authorization', 'Bearer '.$token)
            ->getJson('/api/customers')
            ->assertStatus(401);
    }
}
