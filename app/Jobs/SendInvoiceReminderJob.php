<?php

namespace App\Jobs;

use App\Enums\InvoiceStatus;
use App\Models\AuditLog;
use App\Models\Invoice;
use App\Models\InvoiceReminder;
use App\Notifications\InvoiceDueReminderNotification;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class SendInvoiceReminderJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    /**
     * The number of times the job may be attempted.
     */
    public int $tries = 3;

    /**
     * The number of seconds to wait before retrying the job.
     * Backoff sequence: 1 minute, 5 minutes, 15 minutes.
     *
     * @var array<int, int>
     */
    public array $backoff = [60, 300, 900];

    /**
     * The maximum number of unhandled exceptions to allow before failing.
     */
    public int $maxExceptions = 3;

    /**
     * Create a new job instance.
     */
    public function __construct(
        public Invoice $invoice,
        public string $reminderType
    ) {}

    /**
     * Execute the job.
     */
    public function handle(): void
    {
        // Guard 1: Only unpaid invoices (sent or partially_paid) receive due reminders
        if (! in_array($this->invoice->status, [InvoiceStatus::Sent, InvoiceStatus::PartiallyPaid], true)) {
            Log::info("Skipping reminder for invoice {$this->invoice->id}: status is {$this->invoice->status->value}");

            return;
        }

        // Guard 2: Customer must exist and have an email address
        $customer = $this->invoice->customer;
        if (! $customer || ! $customer->email) {
            Log::warning("Skipping reminder for invoice {$this->invoice->id}: no customer email found");

            return;
        }

        // Guard 3: Idempotency check - skip if already sent
        $alreadySent = InvoiceReminder::where('invoice_id', $this->invoice->id)
            ->where('reminder_type', $this->reminderType)
            ->exists();

        if ($alreadySent) {
            Log::info("Idempotently skipping reminder '{$this->reminderType}' for invoice {$this->invoice->id}: already recorded");

            return;
        }

        // Send notification and record tracking atomically
        try {
            DB::transaction(function () use ($customer): void {
                // Record reminder tracking (unique constraint prevents concurrent duplicates)
                InvoiceReminder::create([
                    'invoice_id' => $this->invoice->id,
                    'reminder_type' => $this->reminderType,
                    'sent_to' => $customer->email,
                    'sent_at' => now(),
                    'status' => 'sent',
                ]);

                // Dispatch notification
                $customer->notify(new InvoiceDueReminderNotification($this->invoice, $this->reminderType));

                // Record audit log
                AuditLog::create([
                    'business_id' => $this->invoice->business_id,
                    'user_id' => null,
                    'action' => 'invoice.reminder_sent',
                    'auditable_type' => Invoice::class,
                    'auditable_id' => $this->invoice->id,
                    'description' => "Reminder '{$this->reminderType}' sent for invoice {$this->invoice->invoice_number} to {$customer->email}",
                    'metadata' => [
                        'reminder_type' => $this->reminderType,
                        'customer_email' => $customer->email,
                        'due_date' => $this->invoice->due_date->toDateString(),
                    ],
                ]);
            });
        } catch (UniqueConstraintViolationException $e) {
            // Concurrent duplicate delivery caught safely
            Log::info("Concurrent reminder prevented for invoice {$this->invoice->id}, type '{$this->reminderType}'");

            return;
        }
    }

    /**
     * Handle a job failure after all retries are exhausted.
     */
    public function failed(?\Throwable $exception): void
    {
        Log::error("SendInvoiceReminderJob permanently failed for invoice {$this->invoice->id}, type '{$this->reminderType}': ".($exception?->getMessage() ?? 'Unknown error'));

        InvoiceReminder::updateOrCreate(
            [
                'invoice_id' => $this->invoice->id,
                'reminder_type' => $this->reminderType,
            ],
            [
                'sent_to' => $this->invoice->customer->email ?? 'unknown',
                'sent_at' => now(),
                'status' => 'failed',
                'error_message' => $exception?->getMessage(),
            ]
        );
    }
}
