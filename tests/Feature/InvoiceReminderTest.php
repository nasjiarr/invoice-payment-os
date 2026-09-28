<?php

namespace Tests\Feature;

use App\Enums\BusinessRole;
use App\Enums\InvoiceStatus;
use App\Jobs\SendInvoiceReminderJob;
use App\Models\Business;
use App\Models\Customer;
use App\Models\Invoice;
use App\Models\InvoiceReminder;
use App\Models\User;
use App\Notifications\InvoiceDueReminderNotification;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class InvoiceReminderTest extends TestCase
{
    use RefreshDatabase;

    private function createInvoiceFixture(
        string $dueDate,
        InvoiceStatus $status = InvoiceStatus::Sent,
        float $total = 1000000.00
    ): array {
        $user = User::factory()->create();
        $business = Business::factory()->create(['owner_id' => $user->id]);
        $business->users()->attach($user->id, ['role' => BusinessRole::Owner->value]);
        $customer = Customer::factory()->create([
            'business_id' => $business->id,
            'name' => 'John Doe',
            'email' => 'john.doe@example.com',
        ]);

        $invoice = Invoice::factory()->create([
            'business_id' => $business->id,
            'customer_id' => $customer->id,
            'status' => $status,
            'issue_date' => Carbon::parse($dueDate)->subDays(14)->toDateString(),
            'due_date' => $dueDate,
            'subtotal' => $total,
            'tax' => 0.00,
            'discount' => 0.00,
            'total' => $total,
            'currency' => 'IDR',
        ]);

        return [$user, $business, $customer, $invoice];
    }

    public function test_job_sends_notification_and_records_reminder(): void
    {
        Notification::fake();

        [, , $customer, $invoice] = $this->createInvoiceFixture('2026-09-30');

        SendInvoiceReminderJob::dispatchSync($invoice, 'before_7_days');

        Notification::assertSentTo(
            $customer,
            InvoiceDueReminderNotification::class,
            function (InvoiceDueReminderNotification $notification) use ($invoice) {
                return $notification->invoice->id === $invoice->id
                    && $notification->reminderType === 'before_7_days';
            }
        );

        $this->assertDatabaseHas('invoice_reminders', [
            'invoice_id' => $invoice->id,
            'reminder_type' => 'before_7_days',
            'sent_to' => 'john.doe@example.com',
            'status' => 'sent',
        ]);

        $this->assertDatabaseHas('audit_logs', [
            'business_id' => $invoice->business_id,
            'action' => 'invoice.reminder_sent',
        ]);
    }

    public function test_job_is_idempotent_and_does_not_send_duplicate_reminder(): void
    {
        Notification::fake();

        [, , $customer, $invoice] = $this->createInvoiceFixture('2026-09-30');

        // First delivery: sent successfully
        SendInvoiceReminderJob::dispatchSync($invoice, 'before_7_days');

        Notification::assertSentTimes(InvoiceDueReminderNotification::class, 1);

        // Second delivery (duplicate): skipped idempotently
        SendInvoiceReminderJob::dispatchSync($invoice, 'before_7_days');

        // Notification must still be sent only ONCE
        Notification::assertSentTimes(InvoiceDueReminderNotification::class, 1);

        // Only 1 tracking record exists
        $this->assertEquals(1, InvoiceReminder::where('invoice_id', $invoice->id)->count());
    }

    public function test_job_skips_paid_draft_and_cancelled_invoices(): void
    {
        Notification::fake();

        [, , , $paidInvoice] = $this->createInvoiceFixture('2026-09-30', InvoiceStatus::Paid);
        [, , , $draftInvoice] = $this->createInvoiceFixture('2026-09-30', InvoiceStatus::Draft);
        [, , , $cancelledInvoice] = $this->createInvoiceFixture('2026-09-30', InvoiceStatus::Cancelled);

        SendInvoiceReminderJob::dispatchSync($paidInvoice, 'due_today');
        SendInvoiceReminderJob::dispatchSync($draftInvoice, 'due_today');
        SendInvoiceReminderJob::dispatchSync($cancelledInvoice, 'due_today');

        Notification::assertNothingSent();
        $this->assertDatabaseCount('invoice_reminders', 0);
    }

    public function test_scheduler_finds_and_dispatches_reminders_for_all_four_stages(): void
    {
        Queue::fake();

        $refDate = Carbon::parse('2026-09-30');

        // 1. 7 days before due date: due on 2026-10-07
        [, , , $inv7Days] = $this->createInvoiceFixture('2026-10-07');

        // 2. 3 days before due date: due on 2026-10-03
        [, , , $inv3Days] = $this->createInvoiceFixture('2026-10-03');

        // 3. Due today: due on 2026-09-30
        [, , , $invToday] = $this->createInvoiceFixture('2026-09-30');

        // 4. Overdue: due on 2026-09-25
        [, , , $invOverdue] = $this->createInvoiceFixture('2026-09-25');

        // 5. Far future: due on 2026-10-15 (not due for reminder)
        [, , , $invFuture] = $this->createInvoiceFixture('2026-10-15');

        // 6. Due today but already Paid
        [, , , $invPaid] = $this->createInvoiceFixture('2026-09-30', InvoiceStatus::Paid);

        $this->artisan('invoices:send-reminders', ['--date' => '2026-09-30'])
            ->assertSuccessful();

        // Verify correct jobs pushed
        Queue::assertPushed(SendInvoiceReminderJob::class, function (SendInvoiceReminderJob $job) use ($inv7Days) {
            return $job->invoice->id === $inv7Days->id && $job->reminderType === 'before_7_days';
        });

        Queue::assertPushed(SendInvoiceReminderJob::class, function (SendInvoiceReminderJob $job) use ($inv3Days) {
            return $job->invoice->id === $inv3Days->id && $job->reminderType === 'before_3_days';
        });

        Queue::assertPushed(SendInvoiceReminderJob::class, function (SendInvoiceReminderJob $job) use ($invToday) {
            return $job->invoice->id === $invToday->id && $job->reminderType === 'due_today';
        });

        Queue::assertPushed(SendInvoiceReminderJob::class, function (SendInvoiceReminderJob $job) use ($invOverdue) {
            return $job->invoice->id === $invOverdue->id && $job->reminderType === 'overdue';
        });

        // Ensure non-eligible invoices were not queued
        Queue::assertNotPushed(SendInvoiceReminderJob::class, function (SendInvoiceReminderJob $job) use ($invFuture) {
            return $job->invoice->id === $invFuture->id;
        });

        Queue::assertNotPushed(SendInvoiceReminderJob::class, function (SendInvoiceReminderJob $job) use ($invPaid) {
            return $job->invoice->id === $invPaid->id;
        });
    }

    public function test_scheduler_skips_invoices_that_already_received_that_reminder(): void
    {
        Queue::fake();

        [, , , $invoice] = $this->createInvoiceFixture('2026-10-07');

        // Pre-record that before_7_days was already sent
        InvoiceReminder::create([
            'invoice_id' => $invoice->id,
            'reminder_type' => 'before_7_days',
            'sent_to' => 'john.doe@example.com',
            'sent_at' => now()->subDay(),
            'status' => 'sent',
        ]);

        $this->artisan('invoices:send-reminders', ['--date' => '2026-09-30'])
            ->assertSuccessful();

        Queue::assertNotPushed(SendInvoiceReminderJob::class, function (SendInvoiceReminderJob $job) use ($invoice) {
            return $job->invoice->id === $invoice->id && $job->reminderType === 'before_7_days';
        });
    }

    public function test_job_retry_and_backoff_configuration(): void
    {
        [, , , $invoice] = $this->createInvoiceFixture('2026-09-30');

        $job = new SendInvoiceReminderJob($invoice, 'due_today');

        $this->assertEquals(3, $job->tries);
        $this->assertEquals([60, 300, 900], $job->backoff);
        $this->assertEquals(3, $job->maxExceptions);
    }

    public function test_notification_structure_and_mail_content(): void
    {
        [, , $customer, $invoice] = $this->createInvoiceFixture('2026-09-30');

        // Test Mail representation for each stage
        $notification7Days = new InvoiceDueReminderNotification($invoice, 'before_7_days');
        $mail7Days = $notification7Days->toMail($customer);
        $this->assertStringContainsString('is due in 7 days', $mail7Days->subject);

        $notification3Days = new InvoiceDueReminderNotification($invoice, 'before_3_days');
        $mail3Days = $notification3Days->toMail($customer);
        $this->assertStringContainsString('is due in 3 days', $mail3Days->subject);

        $notificationToday = new InvoiceDueReminderNotification($invoice, 'due_today');
        $mailToday = $notificationToday->toMail($customer);
        $this->assertStringContainsString('Invoice Due Today', $mailToday->subject);

        $notificationOverdue = new InvoiceDueReminderNotification($invoice, 'overdue');
        $mailOverdue = $notificationOverdue->toMail($customer);
        $this->assertStringContainsString('Overdue Notice', $mailOverdue->subject);

        // Test Database representation
        $arrayData = $notificationToday->toArray($customer);
        $this->assertEquals($invoice->id, $arrayData['invoice_id']);
        $this->assertEquals('due_today', $arrayData['reminder_type']);
        $this->assertEquals('2026-09-30', $arrayData['due_date']);
    }

    public function test_job_failure_handler_records_failed_reminder(): void
    {
        [, , , $invoice] = $this->createInvoiceFixture('2026-09-30');

        $job = new SendInvoiceReminderJob($invoice, 'due_today');
        $job->failed(new \RuntimeException('SMTP host connection timeout'));

        $this->assertDatabaseHas('invoice_reminders', [
            'invoice_id' => $invoice->id,
            'reminder_type' => 'due_today',
            'status' => 'failed',
            'error_message' => 'SMTP host connection timeout',
        ]);
    }
}
