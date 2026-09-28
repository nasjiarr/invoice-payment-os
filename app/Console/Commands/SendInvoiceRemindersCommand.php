<?php

namespace App\Console\Commands;

use App\Domain\Invoice\InvoiceReminderService;
use Carbon\Carbon;
use Illuminate\Console\Command;

class SendInvoiceRemindersCommand extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'invoices:send-reminders {--date= : Reference date (YYYY-MM-DD)}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Scan for invoices needing due date reminders and dispatch queued reminder jobs';

    /**
     * Execute the console command.
     */
    public function handle(InvoiceReminderService $service): int
    {
        $dateOption = $this->option('date');
        $referenceDate = $dateOption ? Carbon::parse($dateOption)->startOfDay() : null;

        $this->info('Scanning invoices needing due reminders...');

        $counts = $service->sendRemindersForDate($referenceDate);

        $this->table(
            ['Reminder Stage', 'Dispatched Jobs'],
            [
                ['7 Days Before Due', $counts['before_7_days']],
                ['3 Days Before Due', $counts['before_3_days']],
                ['Due Today', $counts['due_today']],
                ['Overdue', $counts['overdue']],
                ['Total Dispatched', $counts['total']],
            ]
        );

        $this->info("Successfully dispatched {$counts['total']} reminder jobs to the queue.");

        return self::SUCCESS;
    }
}
