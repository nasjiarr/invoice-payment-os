<?php

namespace App\Domain\Invoice;

use App\Enums\InvoiceStatus;
use App\Jobs\SendInvoiceReminderJob;
use App\Models\Invoice;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;

class InvoiceReminderService
{
    /**
     * Eligible statuses for due reminders.
     */
    protected const ELIGIBLE_STATUSES = [
        InvoiceStatus::Sent,
        InvoiceStatus::PartiallyPaid,
    ];

    /**
     * Find invoices needing reminders grouped by reminder stage.
     *
     * @return array<string, Collection<int, Invoice>>
     */
    public function findInvoicesNeedingReminders(?Carbon $referenceDate = null): array
    {
        $today = ($referenceDate ?? Carbon::today())->startOfDay();

        return [
            'before_7_days' => $this->getInvoicesForExactDueDate(
                $today->copy()->addDays(7)->toDateString(),
                'before_7_days'
            ),
            'before_3_days' => $this->getInvoicesForExactDueDate(
                $today->copy()->addDays(3)->toDateString(),
                'before_3_days'
            ),
            'due_today' => $this->getInvoicesForExactDueDate(
                $today->toDateString(),
                'due_today'
            ),
            'overdue' => $this->getOverdueInvoices(
                $today->toDateString(),
                'overdue'
            ),
        ];
    }

    /**
     * Scan and dispatch reminder jobs for all eligible invoices on a given date.
     *
     * @return array<string, int>
     */
    public function sendRemindersForDate(?Carbon $referenceDate = null): array
    {
        $stages = $this->findInvoicesNeedingReminders($referenceDate);
        $counts = [
            'before_7_days' => 0,
            'before_3_days' => 0,
            'due_today' => 0,
            'overdue' => 0,
            'total' => 0,
        ];

        foreach ($stages as $stage => $invoices) {
            foreach ($invoices as $invoice) {
                SendInvoiceReminderJob::dispatch($invoice, $stage);
                $counts[$stage]++;
                $counts['total']++;
            }
        }

        return $counts;
    }

    /**
     * Query invoices with an exact due date that haven't received a specific reminder yet.
     *
     * @return Collection<int, Invoice>
     */
    protected function getInvoicesForExactDueDate(string $dateString, string $reminderType): Collection
    {
        return Invoice::query()
            ->whereIn('status', self::ELIGIBLE_STATUSES)
            ->whereDate('due_date', $dateString)
            ->whereDoesntHave('reminders', function (Builder $query) use ($reminderType): void {
                $query->where('reminder_type', $reminderType);
            })
            ->with(['customer', 'business'])
            ->get();
    }

    /**
     * Query overdue invoices that haven't received an overdue notice yet.
     *
     * @return Collection<int, Invoice>
     */
    protected function getOverdueInvoices(string $todayDateString, string $reminderType): Collection
    {
        return Invoice::query()
            ->whereIn('status', self::ELIGIBLE_STATUSES)
            ->whereDate('due_date', '<', $todayDateString)
            ->whereDoesntHave('reminders', function (Builder $query) use ($reminderType): void {
                $query->where('reminder_type', $reminderType);
            })
            ->with(['customer', 'business'])
            ->get();
    }
}
