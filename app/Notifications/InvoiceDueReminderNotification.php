<?php

namespace App\Notifications;

use App\Models\Invoice;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class InvoiceDueReminderNotification extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(
        public Invoice $invoice,
        public string $reminderType
    ) {}

    /**
     * Get the notification's delivery channels.
     *
     * @return array<int, string>
     */
    public function via(object $notifiable): array
    {
        return ['database', 'mail'];
    }

    /**
     * Get the mail representation of the notification.
     */
    public function toMail(object $notifiable): MailMessage
    {
        $subject = match ($this->reminderType) {
            'before_7_days' => "Invoice Reminder: {$this->invoice->invoice_number} is due in 7 days",
            'before_3_days' => "Urgent Reminder: {$this->invoice->invoice_number} is due in 3 days",
            'due_today' => "Invoice Due Today: {$this->invoice->invoice_number}",
            'overdue' => "Overdue Notice: {$this->invoice->invoice_number} is past due",
            default => "Invoice Reminder: {$this->invoice->invoice_number}",
        };

        $greeting = "Hello {$notifiable->name},";

        $message = (new MailMessage)
            ->subject($subject)
            ->greeting($greeting)
            ->line("This is a reminder regarding invoice {$this->invoice->invoice_number}.")
            ->line("Amount Due: {$this->invoice->currency} ".number_format((float) $this->invoice->total, 2))
            ->line("Due Date: {$this->invoice->due_date->format('d F Y')}");

        if ($this->reminderType === 'overdue') {
            $message->line('Please settle this payment as soon as possible to avoid service disruption.');
        } else {
            $message->line('Please ensure payment is completed before the due date.');
        }

        return $message;
    }

    /**
     * Get the array representation of the notification for database storage.
     *
     * @return array<string, mixed>
     */
    public function toArray(object $notifiable): array
    {
        return [
            'invoice_id' => $this->invoice->id,
            'invoice_number' => $this->invoice->invoice_number,
            'reminder_type' => $this->reminderType,
            'amount' => $this->invoice->total,
            'currency' => $this->invoice->currency,
            'due_date' => $this->invoice->due_date->toDateString(),
            'business_id' => $this->invoice->business_id,
        ];
    }
}
