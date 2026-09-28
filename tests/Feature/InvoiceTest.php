<?php

namespace Tests\Feature;

use App\Domain\Invoice\InvoiceService;
use App\Enums\BusinessRole;
use App\Enums\InvoiceStatus;
use App\Exceptions\InvalidStatusTransitionException;
use App\Models\Business;
use App\Models\Customer;
use App\Models\Invoice;
use App\Models\Product;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class InvoiceTest extends TestCase
{
    use RefreshDatabase;

    public function test_user_can_create_invoice(): void
    {
        $user = User::factory()->create();
        $business = Business::factory()->create(['owner_id' => $user->id]);
        $business->users()->attach($user->id, ['role' => BusinessRole::Owner->value]);
        $customer = Customer::factory()->create(['business_id' => $business->id]);
        $product = Product::factory()->create(['business_id' => $business->id, 'price' => 500000.00]);

        Sanctum::actingAs($user);

        $payload = [
            'business_id' => $business->id,
            'customer_id' => $customer->id,
            'invoice_number' => 'INV-TEST-001',
            'issue_date' => '2026-10-01',
            'due_date' => '2026-10-15',
            'currency' => 'IDR',
            'notes' => 'Thank you for your business!',
            'items' => [
                [
                    'product_id' => $product->id,
                    'description' => 'Web Development Package',
                    'quantity' => 1,
                    'unit_price' => 500000.00,
                    'discount' => 50000.00,
                    'tax' => 49500.00,
                ],
            ],
        ];

        $response = $this->postJson('/api/invoices', $payload);

        $response->assertStatus(201)
            ->assertJsonStructure([
                'message',
                'data' => [
                    'id',
                    'business_id',
                    'customer_id',
                    'invoice_number',
                    'status',
                    'issue_date',
                    'due_date',
                    'currency',
                    'subtotal',
                    'discount',
                    'tax',
                    'total',
                    'notes',
                    'items',
                ],
            ])
            ->assertJson([
                'message' => 'Invoice created successfully',
                'data' => [
                    'invoice_number' => 'INV-TEST-001',
                    'status' => 'draft',
                    'subtotal' => '500000.00',
                    'discount' => '50000.00',
                    'tax' => '49500.00',
                    'total' => '499500.00',
                ],
            ]);

        $this->assertDatabaseHas('invoices', [
            'business_id' => $business->id,
            'invoice_number' => 'INV-TEST-001',
            'status' => InvoiceStatus::Draft->value,
            'total' => 499500.00,
        ]);

        $this->assertDatabaseHas('audit_logs', [
            'business_id' => $business->id,
            'user_id' => $user->id,
            'action' => 'invoice.created',
        ]);
    }

    public function test_backend_calculates_totals_and_ignores_tampered_frontend_values(): void
    {
        $user = User::factory()->create();
        $business = Business::factory()->create(['owner_id' => $user->id]);
        $business->users()->attach($user->id, ['role' => BusinessRole::Owner->value]);
        $customer = Customer::factory()->create(['business_id' => $business->id]);

        Sanctum::actingAs($user);

        $payload = [
            'business_id' => $business->id,
            'customer_id' => $customer->id,
            'issue_date' => '2026-10-01',
            'due_date' => '2026-10-15',
            // Attacker attempts to forge total to 1 rupiah
            'subtotal' => 1.00,
            'total' => 1.00,
            'items' => [
                [
                    'description' => 'Server Maintenance',
                    'quantity' => 2,
                    'unit_price' => 750000.00,
                    'discount' => 100000.00,
                    'tax' => 154000.00,
                ],
            ],
        ];

        $response = $this->postJson('/api/invoices', $payload);

        $response->assertStatus(201);

        // Expected backend calculation:
        // subtotal = 2 * 750,000 = 1,500,000
        // discount = 100,000
        // tax = 154,000
        // total = 1,500,000 - 100,000 + 154,000 = 1,554,000
        $this->assertEquals('1500000.00', $response->json('data.subtotal'));
        $this->assertEquals('100000.00', number_format((float) $response->json('data.discount'), 2, '.', ''));
        $this->assertEquals('1554000.00', $response->json('data.total'));
    }

    public function test_invoice_calculation_with_multiple_items_discounts_and_taxes(): void
    {
        $user = User::factory()->create();
        $business = Business::factory()->create(['owner_id' => $user->id]);
        $business->users()->attach($user->id, ['role' => BusinessRole::Owner->value]);
        $customer = Customer::factory()->create(['business_id' => $business->id]);

        Sanctum::actingAs($user);

        $payload = [
            'business_id' => $business->id,
            'customer_id' => $customer->id,
            'issue_date' => '2026-10-01',
            'due_date' => '2026-10-20',
            'items' => [
                [
                    'description' => 'Item A',
                    'quantity' => 3,
                    'unit_price' => 100000.00, // 300,000
                    'discount' => 30000.00,
                    'tax' => 29700.00, // total = 299,700
                ],
                [
                    'description' => 'Item B',
                    'quantity' => 1,
                    'unit_price' => 400000.00, // 400,000
                    'discount' => 40000.00,
                    'tax' => 39600.00, // total = 399,600
                ],
            ],
        ];

        $response = $this->postJson('/api/invoices', $payload);

        $response->assertStatus(201);
        // Subtotal = 300,000 + 400,000 = 700,000
        // Discount = 30,000 + 40,000 = 70,000
        // Tax = 29,700 + 39,600 = 69,300
        // Total = 700,000 - 70,000 + 69,300 = 699,300
        $this->assertEquals('700000.00', $response->json('data.subtotal'));
        $this->assertEquals('70000.00', $response->json('data.discount'));
        $this->assertEquals('69300.00', $response->json('data.tax'));
        $this->assertEquals('699300.00', $response->json('data.total'));
    }

    public function test_invalid_status_transitions_are_rejected(): void
    {
        $user = User::factory()->create();
        $business = Business::factory()->create(['owner_id' => $user->id]);
        $business->users()->attach($user->id, ['role' => BusinessRole::Owner->value]);

        $invoiceDraft = Invoice::factory()->create([
            'business_id' => $business->id,
            'status' => InvoiceStatus::Draft,
        ]);

        $invoicePaid = Invoice::factory()->create([
            'business_id' => $business->id,
            'status' => InvoiceStatus::Paid,
        ]);

        Sanctum::actingAs($user);

        $service = app(InvoiceService::class);

        // Attempting to void draft directly (must be sent first)
        $this->postJson('/api/invoices/'.$invoiceDraft->id.'/void')
            ->assertStatus(422)
            ->assertJson([
                'message' => "Cannot transition invoice status from 'draft' to 'void'.",
            ]);

        // Paid invoice cannot transition back to draft
        $this->expectException(InvalidStatusTransitionException::class);
        $service->sendInvoice($invoicePaid, $user);
    }

    public function test_user_can_send_invoice(): void
    {
        $user = User::factory()->create();
        $business = Business::factory()->create(['owner_id' => $user->id]);
        $business->users()->attach($user->id, ['role' => BusinessRole::Owner->value]);

        $invoice = Invoice::factory()->create([
            'business_id' => $business->id,
            'status' => InvoiceStatus::Draft,
        ]);

        Sanctum::actingAs($user);

        $response = $this->postJson('/api/invoices/'.$invoice->id.'/send');

        $response->assertStatus(200)
            ->assertJson([
                'message' => 'Invoice marked as sent successfully',
                'data' => [
                    'id' => $invoice->id,
                    'status' => 'sent',
                ],
            ]);

        $this->assertEquals(InvoiceStatus::Sent, $invoice->fresh()->status);
        $this->assertDatabaseHas('audit_logs', [
            'business_id' => $business->id,
            'action' => 'invoice.sent',
        ]);
    }

    public function test_user_can_void_invoice(): void
    {
        $user = User::factory()->create();
        $business = Business::factory()->create(['owner_id' => $user->id]);
        $business->users()->attach($user->id, ['role' => BusinessRole::Owner->value]);

        $invoice = Invoice::factory()->create([
            'business_id' => $business->id,
            'status' => InvoiceStatus::Sent,
        ]);

        Sanctum::actingAs($user);

        $response = $this->postJson('/api/invoices/'.$invoice->id.'/void');

        $response->assertStatus(200)
            ->assertJson([
                'message' => 'Invoice marked as void successfully',
                'data' => [
                    'id' => $invoice->id,
                    'status' => 'void',
                ],
            ]);

        $this->assertEquals(InvoiceStatus::Void, $invoice->fresh()->status);
        $this->assertDatabaseHas('audit_logs', [
            'business_id' => $business->id,
            'action' => 'invoice.voided',
        ]);
    }

    public function test_user_can_cancel_invoice(): void
    {
        $user = User::factory()->create();
        $business = Business::factory()->create(['owner_id' => $user->id]);
        $business->users()->attach($user->id, ['role' => BusinessRole::Owner->value]);

        $invoice = Invoice::factory()->create([
            'business_id' => $business->id,
            'status' => InvoiceStatus::Draft,
        ]);

        Sanctum::actingAs($user);

        $response = $this->postJson('/api/invoices/'.$invoice->id.'/cancel');

        $response->assertStatus(200)
            ->assertJson([
                'message' => 'Invoice cancelled successfully',
                'data' => [
                    'id' => $invoice->id,
                    'status' => 'cancelled',
                ],
            ]);

        $this->assertEquals(InvoiceStatus::Cancelled, $invoice->fresh()->status);
        $this->assertDatabaseHas('audit_logs', [
            'business_id' => $business->id,
            'action' => 'invoice.cancelled',
        ]);
    }

    public function test_invoice_isolation_between_businesses(): void
    {
        $userA = User::factory()->create();
        $userB = User::factory()->create();

        $businessA = Business::factory()->create(['owner_id' => $userA->id]);
        $businessB = Business::factory()->create(['owner_id' => $userB->id]);

        $businessA->users()->attach($userA->id, ['role' => BusinessRole::Owner->value]);
        $businessB->users()->attach($userB->id, ['role' => BusinessRole::Owner->value]);

        $invoiceA = Invoice::factory()->create([
            'business_id' => $businessA->id,
            'status' => InvoiceStatus::Draft,
        ]);

        Sanctum::actingAs($userB);

        // 1. User B list does not show User A's invoice
        $listResponse = $this->getJson('/api/invoices');
        $listResponse->assertStatus(200);
        $ids = collect($listResponse->json('data'))->pluck('id')->all();
        $this->assertNotContains($invoiceA->id, $ids);

        // 2. User B cannot view User A's invoice
        $this->getJson('/api/invoices/'.$invoiceA->id)->assertStatus(403);

        // 3. User B cannot update User A's invoice
        $this->putJson('/api/invoices/'.$invoiceA->id, ['notes' => 'Hacked Notes'])->assertStatus(403);

        // 4. User B cannot delete User A's invoice
        $this->deleteJson('/api/invoices/'.$invoiceA->id)->assertStatus(403);

        // 5. User B cannot send/void/cancel User A's invoice
        $this->postJson('/api/invoices/'.$invoiceA->id.'/send')->assertStatus(403);
        $this->postJson('/api/invoices/'.$invoiceA->id.'/void')->assertStatus(403);
        $this->postJson('/api/invoices/'.$invoiceA->id.'/cancel')->assertStatus(403);

        // 6. User B cannot create an invoice in User A's business
        $this->postJson('/api/invoices', [
            'business_id' => $businessA->id,
            'customer_id' => 1,
            'issue_date' => '2026-10-01',
            'due_date' => '2026-10-15',
            'items' => [['description' => 'Test', 'quantity' => 1, 'unit_price' => 100]],
        ])->assertStatus(403);
    }

    public function test_invoice_historical_snapshot_remains_unchanged_when_product_changes(): void
    {
        $user = User::factory()->create();
        $business = Business::factory()->create(['owner_id' => $user->id]);
        $business->users()->attach($user->id, ['role' => BusinessRole::Owner->value]);
        $customer = Customer::factory()->create(['business_id' => $business->id]);

        $product = Product::factory()->create([
            'business_id' => $business->id,
            'name' => 'Original Product Name',
            'price' => 350000.00,
        ]);

        Sanctum::actingAs($user);

        // Create invoice using product
        $response = $this->postJson('/api/invoices', [
            'business_id' => $business->id,
            'customer_id' => $customer->id,
            'issue_date' => '2026-10-01',
            'due_date' => '2026-10-15',
            'items' => [
                [
                    'product_id' => $product->id,
                    'description' => 'Original Product Name',
                    'quantity' => 2,
                    'unit_price' => 350000.00,
                    'discount' => 0.00,
                    'tax' => 77000.00,
                ],
            ],
        ]);

        $response->assertStatus(201);
        $invoiceId = $response->json('data.id');

        // Alter product price & name
        $product->update([
            'name' => 'Super Expensive Product',
            'price' => 9999999.00,
        ]);

        // Invoice snapshot values MUST remain identical
        $invoice = Invoice::with('items')->find($invoiceId);
        $this->assertEquals('700000.00', $invoice->subtotal);
        $this->assertEquals('777000.00', $invoice->total);
        $item = $invoice->items->first();
        $this->assertEquals('Original Product Name', $item->description);
        $this->assertEquals('350000.00', $item->unit_price);
        $this->assertEquals('700000.00', $item->subtotal);
        $this->assertEquals('777000.00', $item->total);
    }

    public function test_due_date_must_be_after_or_equal_to_issue_date(): void
    {
        $user = User::factory()->create();
        $business = Business::factory()->create(['owner_id' => $user->id]);
        $business->users()->attach($user->id, ['role' => BusinessRole::Owner->value]);
        $customer = Customer::factory()->create(['business_id' => $business->id]);

        Sanctum::actingAs($user);

        $payload = [
            'business_id' => $business->id,
            'customer_id' => $customer->id,
            'issue_date' => '2026-10-15',
            'due_date' => '2026-10-01', // Due date before issue date!
            'items' => [
                [
                    'description' => 'Service',
                    'quantity' => 1,
                    'unit_price' => 100000.00,
                ],
            ],
        ];

        $response = $this->postJson('/api/invoices', $payload);

        $response->assertStatus(422)
            ->assertJsonValidationErrors(['due_date']);
    }
}
