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
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class RevenueReportTest extends TestCase
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

    private function createInvoice(
        Business $business,
        Customer $customer,
        float $total,
        InvoiceStatus $status = InvoiceStatus::Sent,
        ?string $issueDate = null,
        ?string $dueDate = null
    ): Invoice {
        return Invoice::factory()->create([
            'business_id' => $business->id,
            'customer_id' => $customer->id,
            'status' => $status,
            'issue_date' => $issueDate ?? now()->toDateString(),
            'due_date' => $dueDate ?? now()->addDays(14)->toDateString(),
            'subtotal' => $total,
            'tax' => 0.00,
            'discount' => 0.00,
            'total' => $total,
            'currency' => 'IDR',
        ]);
    }

    private function recordPayment(Invoice $invoice, float $amount): Payment
    {
        return Payment::factory()->create([
            'business_id' => $invoice->business_id,
            'invoice_id' => $invoice->id,
            'amount' => $amount,
            'currency' => $invoice->currency,
            'status' => PaymentStatus::Paid,
            'paid_at' => now(),
        ]);
    }

    public function test_unauthenticated_user_cannot_access_revenue_report(): void
    {
        $this->getJson('/api/reports/revenue')
            ->assertStatus(401);
    }

    public function test_authenticated_user_can_get_revenue_report_summary(): void
    {
        [$user, $business, $customer] = $this->createBusinessWithUser();

        // Invoice 1: Paid (1,000,000)
        $inv1 = $this->createInvoice($business, $customer, 1000000.00, InvoiceStatus::Paid);
        $this->recordPayment($inv1, 1000000.00);

        // Invoice 2: Partially Paid (2,000,000 invoiced, 500,000 paid, 1,500,000 outstanding)
        $inv2 = $this->createInvoice($business, $customer, 2000000.00, InvoiceStatus::PartiallyPaid);
        $this->recordPayment($inv2, 500000.00);

        // Invoice 3: Sent (3,000,000 invoiced, 0 paid, 3,000,000 outstanding)
        $this->createInvoice($business, $customer, 3000000.00, InvoiceStatus::Sent);

        Sanctum::actingAs($user);

        $response = $this->getJson('/api/reports/revenue');

        $response->assertStatus(200)
            ->assertJson([
                'message' => 'Revenue report generated successfully',
                'data' => [
                    'total_invoices' => 3,
                    'total_invoice' => 3,
                    'total_invoiced' => '6000000.00',
                    'total_paid' => '1500000.00',
                    'total_outstanding' => '4500000.00',
                ],
            ]);
    }

    public function test_revenue_report_calculates_overdue_invoices_correctly(): void
    {
        [$user, $business, $customer] = $this->createBusinessWithUser();

        $pastDueDate = Carbon::today()->subDays(5)->toDateString();
        $futureDueDate = Carbon::today()->addDays(10)->toDateString();

        // Overdue Invoice: 2,000,000 total, 500,000 paid -> 1,500,000 overdue outstanding
        $overdueInv = $this->createInvoice(
            $business,
            $customer,
            2000000.00,
            InvoiceStatus::PartiallyPaid,
            now()->subDays(20)->toDateString(),
            $pastDueDate
        );
        $this->recordPayment($overdueInv, 500000.00);

        // Not Overdue Invoice: 3,000,000 total, due in 10 days
        $this->createInvoice(
            $business,
            $customer,
            3000000.00,
            InvoiceStatus::Sent,
            now()->subDays(5)->toDateString(),
            $futureDueDate
        );

        Sanctum::actingAs($user);

        $response = $this->getJson('/api/reports/revenue');

        $response->assertStatus(200)
            ->assertJson([
                'data' => [
                    'total_invoiced' => '5000000.00',
                    'total_paid' => '500000.00',
                    'total_outstanding' => '4500000.00',
                    'total_overdue' => '1500000.00',
                ],
            ]);
    }

    public function test_revenue_report_filters_by_date_range(): void
    {
        [$user, $business, $customer] = $this->createBusinessWithUser();

        // In range: 2026-09-05 (1,000,000)
        $this->createInvoice(
            $business,
            $customer,
            1000000.00,
            InvoiceStatus::Sent,
            '2026-09-05'
        );

        // In range: 2026-09-20 (2,000,000)
        $this->createInvoice(
            $business,
            $customer,
            2000000.00,
            InvoiceStatus::Sent,
            '2026-09-20'
        );

        // Outside range: 2026-08-15 (5,000,000)
        $this->createInvoice(
            $business,
            $customer,
            5000000.00,
            InvoiceStatus::Sent,
            '2026-08-15'
        );

        Sanctum::actingAs($user);

        $response = $this->getJson('/api/reports/revenue?date_from=2026-09-01&date_to=2026-09-30');

        $response->assertStatus(200)
            ->assertJson([
                'data' => [
                    'total_invoices' => 2,
                    'total_invoiced' => '3000000.00',
                ],
                'filters' => [
                    'date_from' => '2026-09-01',
                    'date_to' => '2026-09-30',
                ],
            ]);
    }

    public function test_revenue_report_filters_by_customer(): void
    {
        [$user, $business, $customerA] = $this->createBusinessWithUser();
        $customerB = Customer::factory()->create(['business_id' => $business->id]);

        $this->createInvoice($business, $customerA, 1000000.00);
        $this->createInvoice($business, $customerB, 3000000.00);

        Sanctum::actingAs($user);

        $response = $this->getJson("/api/reports/revenue?customer_id={$customerA->id}");

        $response->assertStatus(200)
            ->assertJson([
                'data' => [
                    'total_invoices' => 1,
                    'total_invoiced' => '1000000.00',
                ],
                'filters' => [
                    'customer_id' => $customerA->id,
                ],
            ]);
    }

    public function test_revenue_report_filters_by_payment_status(): void
    {
        [$user, $business, $customer] = $this->createBusinessWithUser();

        $invPaid = $this->createInvoice($business, $customer, 1000000.00, InvoiceStatus::Paid);
        $this->recordPayment($invPaid, 1000000.00);

        $this->createInvoice($business, $customer, 2000000.00, InvoiceStatus::Sent);

        Sanctum::actingAs($user);

        $response = $this->getJson('/api/reports/revenue?payment_status=paid');

        $response->assertStatus(200)
            ->assertJson([
                'data' => [
                    'total_invoices' => 1,
                    'total_invoiced' => '1000000.00',
                    'total_paid' => '1000000.00',
                    'total_outstanding' => '0.00',
                ],
            ]);
    }

    public function test_multi_tenant_isolation_prevents_viewing_other_business_data(): void
    {
        [$userA, $businessA, $customerA] = $this->createBusinessWithUser();
        [$userB, $businessB, $customerB] = $this->createBusinessWithUser();

        $this->createInvoice($businessA, $customerA, 5000000.00);
        $this->createInvoice($businessB, $customerB, 1000000.00);

        Sanctum::actingAs($userB);

        // User B only sees Business B revenue
        $response = $this->getJson('/api/reports/revenue');
        $response->assertStatus(200)
            ->assertJson([
                'data' => [
                    'total_invoices' => 1,
                    'total_invoiced' => '1000000.00',
                ],
            ]);

        // User B attempts to access Business A report explicitly -> 403 Forbidden
        $this->getJson("/api/reports/revenue?business_id={$businessA->id}")
            ->assertStatus(403);
    }

    public function test_void_and_cancelled_invoices_are_excluded_from_invoiced_totals(): void
    {
        [$user, $business, $customer] = $this->createBusinessWithUser();

        $this->createInvoice($business, $customer, 500000.00, InvoiceStatus::Void);
        $this->createInvoice($business, $customer, 500000.00, InvoiceStatus::Cancelled);
        $this->createInvoice($business, $customer, 1000000.00, InvoiceStatus::Sent);

        Sanctum::actingAs($user);

        $response = $this->getJson('/api/reports/revenue');

        $response->assertStatus(200)
            ->assertJson([
                'data' => [
                    'total_invoices' => 3,
                    'total_invoiced' => '1000000.00',
                    'total_outstanding' => '1000000.00',
                    'total_paid' => '0.00',
                ],
            ]);
    }
}
