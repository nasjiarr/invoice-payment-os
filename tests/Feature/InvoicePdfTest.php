<?php

namespace Tests\Feature;

use App\Enums\BusinessRole;
use App\Models\Business;
use App\Models\Customer;
use App\Models\Invoice;
use App\Models\InvoiceItem;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class InvoicePdfTest extends TestCase
{
    use RefreshDatabase;

    public function test_user_can_download_pdf_for_own_business_invoice(): void
    {
        $user = User::factory()->create();
        $business = Business::factory()->create([
            'owner_id' => $user->id,
            'name' => 'Nasjiar Web Studio',
            'email' => 'studio@nasjiar.com',
            'phone' => '08123456789',
            'address' => 'Jakarta, Indonesia',
            'tax_id' => 'ID-TAX-8888',
        ]);
        $business->users()->attach($user->id, ['role' => BusinessRole::Owner->value]);

        $customer = Customer::factory()->create([
            'business_id' => $business->id,
            'name' => 'PT Maju Bersama',
            'email' => 'finance@majubersama.com',
            'phone' => '0877777777',
            'address' => 'Surabaya, Indonesia',
        ]);

        $invoice = Invoice::factory()->create([
            'business_id' => $business->id,
            'customer_id' => $customer->id,
            'invoice_number' => 'INV-2026-999',
            'issue_date' => '2026-10-01',
            'due_date' => '2026-10-15',
            'currency' => 'IDR',
            'notes' => 'Please pay via bank transfer.',
            'subtotal' => 1000000.00,
            'discount' => 50000.00,
            'tax' => 104500.00,
            'total' => 1054500.00,
        ]);

        InvoiceItem::factory()->create([
            'invoice_id' => $invoice->id,
            'description' => 'Website Development Service',
            'quantity' => 1,
            'unit_price' => 1000000.00,
            'discount' => 50000.00,
            'tax' => 104500.00,
            'subtotal' => 1000000.00,
            'total' => 1054500.00,
        ]);

        Sanctum::actingAs($user);

        $response = $this->get('/api/invoices/'.$invoice->id.'/pdf');

        $response->assertStatus(200);
        $this->assertEquals('application/pdf', $response->headers->get('Content-Type'));
        $this->assertStringContainsString('invoice-INV-2026-999.pdf', (string) $response->headers->get('Content-Disposition'));

        // Verify valid PDF binary output
        $this->assertStringStartsWith('%PDF-', $response->getContent());
    }

    public function test_user_cannot_download_pdf_for_another_business_invoice(): void
    {
        $userA = User::factory()->create();
        $userB = User::factory()->create();

        $businessA = Business::factory()->create(['owner_id' => $userA->id]);
        $businessA->users()->attach($userA->id, ['role' => BusinessRole::Owner->value]);

        $businessB = Business::factory()->create(['owner_id' => $userB->id]);
        $businessB->users()->attach($userB->id, ['role' => BusinessRole::Owner->value]);

        $invoiceA = Invoice::factory()->create([
            'business_id' => $businessA->id,
        ]);
        InvoiceItem::factory()->create(['invoice_id' => $invoiceA->id]);

        Sanctum::actingAs($userB);

        // User B attempts to download User A's invoice PDF (IDOR check)
        $response = $this->get('/api/invoices/'.$invoiceA->id.'/pdf');
        $response->assertStatus(403);
    }

    public function test_unauthenticated_user_cannot_download_pdf(): void
    {
        $invoice = Invoice::factory()->create();

        $response = $this->getJson('/api/invoices/'.$invoice->id.'/pdf');
        $response->assertStatus(401);
    }
}
