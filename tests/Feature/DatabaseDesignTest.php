<?php

namespace Tests\Feature;

use App\Enums\BusinessRole;
use App\Enums\InvoiceStatus;
use App\Enums\PaymentEventStatus;
use App\Enums\PaymentStatus;
use App\Models\AuditLog;
use App\Models\Business;
use App\Models\Customer;
use App\Models\Invoice;
use App\Models\InvoiceItem;
use App\Models\Payment;
use App\Models\PaymentEvent;
use App\Models\Product;
use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class DatabaseDesignTest extends TestCase
{
    use RefreshDatabase;

    public function test_user_can_own_and_join_businesses(): void
    {
        $owner = User::factory()->create();
        $member = User::factory()->create();

        $business = Business::factory()->create([
            'owner_id' => $owner->id,
            'name' => 'Nasjiar Web Studio',
        ]);

        $business->users()->attach($owner->id, ['role' => BusinessRole::Owner->value]);
        $business->users()->attach($member->id, ['role' => BusinessRole::Member->value]);

        $this->assertTrue($owner->ownedBusinesses->contains($business));
        $this->assertTrue($owner->businesses->contains($business));
        $this->assertTrue($member->businesses->contains($business));
        $this->assertEquals(BusinessRole::Owner->value, $owner->businesses->first()->pivot->role);
        $this->assertEquals(BusinessRole::Member->value, $member->businesses->first()->pivot->role);
    }

    public function test_business_has_customers_and_products(): void
    {
        $business = Business::factory()->create();

        $customer = Customer::factory()->create([
            'business_id' => $business->id,
            'name' => 'PT Klien Maju Jaya',
        ]);

        $product = Product::factory()->create([
            'business_id' => $business->id,
            'name' => 'Web Development',
            'price' => 5000000.00,
            'active' => true,
        ]);

        $this->assertTrue($business->customers->contains($customer));
        $this->assertTrue($business->products->contains($product));
        $this->assertEquals($business->id, $customer->business->id);
        $this->assertEquals($business->id, $product->business->id);
    }

    public function test_invoice_and_invoice_item_snapshot_behavior(): void
    {
        $business = Business::factory()->create();
        $customer = Customer::factory()->create(['business_id' => $business->id]);
        $product = Product::factory()->create([
            'business_id' => $business->id,
            'name' => 'Original Web Design',
            'price' => 2000000.00,
        ]);

        $invoice = Invoice::factory()->create([
            'business_id' => $business->id,
            'customer_id' => $customer->id,
            'invoice_number' => 'INV-2026-001',
            'status' => InvoiceStatus::Draft,
            'subtotal' => 2000000.00,
            'tax' => 220000.00,
            'discount' => 0.00,
            'total' => 2220000.00,
        ]);

        $item = InvoiceItem::factory()->create([
            'invoice_id' => $invoice->id,
            'product_id' => $product->id,
            'description' => 'Original Web Design',
            'quantity' => 1.00,
            'unit_price' => 2000000.00,
            'subtotal' => 2000000.00,
            'tax' => 220000.00,
            'discount' => 0.00,
            'total' => 2220000.00,
        ]);

        // Product price changes later
        $product->update(['price' => 9999999.00]);

        // InvoiceItem snapshot must remain unchanged
        $item->refresh();
        $this->assertEquals('2000000.00', $item->unit_price);
        $this->assertEquals('2220000.00', $item->total);
        $this->assertInstanceOf(InvoiceStatus::class, $invoice->status);
        $this->assertEquals(InvoiceStatus::Draft, $invoice->status);

        // Even if product is deleted, snapshot survives (nullOnDelete)
        $product->delete();
        $item->refresh();
        $this->assertNull($item->product_id);
        $this->assertEquals('Original Web Design', $item->description);
    }

    public function test_invoice_number_must_be_unique_per_business(): void
    {
        $business = Business::factory()->create();
        $customer = Customer::factory()->create(['business_id' => $business->id]);

        Invoice::factory()->create([
            'business_id' => $business->id,
            'customer_id' => $customer->id,
            'invoice_number' => 'INV-001',
        ]);

        $this->expectException(QueryException::class);

        Invoice::factory()->create([
            'business_id' => $business->id,
            'customer_id' => $customer->id,
            'invoice_number' => 'INV-001',
        ]);
    }

    public function test_payments_and_status_enum(): void
    {
        $invoice = Invoice::factory()->create();

        $payment = Payment::factory()->create([
            'business_id' => $invoice->business_id,
            'invoice_id' => $invoice->id,
            'amount' => 500000.00,
            'status' => PaymentStatus::Paid,
            'paid_at' => now(),
        ]);

        $this->assertEquals($invoice->id, $payment->invoice->id);
        $this->assertEquals(PaymentStatus::Paid, $payment->status);
        $this->assertTrue($invoice->payments->contains($payment));
    }

    public function test_payment_events_prevent_duplicate_webhook_processing(): void
    {
        PaymentEvent::factory()->create([
            'provider' => 'midtrans',
            'event_id' => 'evt_12345',
            'event_type' => 'settlement',
            'status' => PaymentEventStatus::Processed,
        ]);

        $this->expectException(QueryException::class);

        // Attempt duplicate webhook event
        PaymentEvent::factory()->create([
            'provider' => 'midtrans',
            'event_id' => 'evt_12345',
            'event_type' => 'settlement',
            'status' => PaymentEventStatus::Processed,
        ]);
    }

    public function test_audit_logs_record_polymorphic_activity(): void
    {
        $business = Business::factory()->create();
        $user = User::factory()->create();
        $invoice = Invoice::factory()->create(['business_id' => $business->id]);

        $auditLog = AuditLog::create([
            'business_id' => $business->id,
            'user_id' => $user->id,
            'action' => 'invoice.created',
            'auditable_type' => Invoice::class,
            'auditable_id' => $invoice->id,
            'description' => 'Invoice '.$invoice->invoice_number.' created',
            'metadata' => [
                'invoice_number' => $invoice->invoice_number,
                'total' => $invoice->total,
            ],
            'ip_address' => '127.0.0.1',
            'user_agent' => 'PHPUnit',
        ]);

        $this->assertEquals($invoice->id, $auditLog->auditable->id);
        $this->assertEquals('invoice.created', $auditLog->action);
        $this->assertEquals($invoice->invoice_number, $auditLog->metadata['invoice_number']);
        $this->assertTrue($invoice->auditLogs->contains($auditLog));
    }
}
