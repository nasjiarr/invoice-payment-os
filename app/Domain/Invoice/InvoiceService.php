<?php

namespace App\Domain\Invoice;

use App\Enums\InvoiceStatus;
use App\Models\AuditLog;
use App\Models\Business;
use App\Models\Customer;
use App\Models\Invoice;
use App\Models\InvoiceItem;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class InvoiceService
{
    /**
     * Create a new invoice with its items inside a database transaction.
     *
     * @param  array<string, mixed>  $data
     */
    public function createInvoice(User $user, array $data): Invoice
    {
        $business = Business::findOrFail($data['business_id']);

        // Verify customer belongs to this business
        $customer = Customer::where('id', $data['customer_id'])
            ->where('business_id', $business->id)
            ->firstOrFail();

        $invoiceNumber = $data['invoice_number'] ?? $this->generateUniqueInvoiceNumber($business->id);

        // Calculate all items and totals via InvoiceCalculator
        $calculation = InvoiceCalculator::calculate($data['items']);

        // Timezone-aware dates
        $issueDate = Carbon::parse($data['issue_date'])->toDateString();
        $dueDate = Carbon::parse($data['due_date'])->toDateString();

        return DB::transaction(function () use ($user, $business, $customer, $invoiceNumber, $issueDate, $dueDate, $calculation, $data): Invoice {
            $invoice = Invoice::create([
                'business_id' => $business->id,
                'customer_id' => $customer->id,
                'invoice_number' => $invoiceNumber,
                'status' => InvoiceStatus::Draft,
                'issue_date' => $issueDate,
                'due_date' => $dueDate,
                'currency' => $data['currency'] ?? $business->currency ?? 'IDR',
                'subtotal' => $calculation['subtotal'],
                'discount' => $calculation['discount'],
                'tax' => $calculation['tax'],
                'total' => $calculation['total'],
                'notes' => $data['notes'] ?? null,
            ]);

            foreach ($calculation['items'] as $itemData) {
                InvoiceItem::create([
                    'invoice_id' => $invoice->id,
                    'product_id' => $itemData['product_id'],
                    'description' => $itemData['description'],
                    'quantity' => $itemData['quantity'],
                    'unit_price' => $itemData['unit_price'],
                    'discount' => $itemData['discount'],
                    'tax' => $itemData['tax'],
                    'subtotal' => $itemData['subtotal'],
                    'total' => $itemData['total'],
                ]);
            }

            AuditLog::create([
                'business_id' => $invoice->business_id,
                'user_id' => $user->id,
                'action' => 'invoice.created',
                'auditable_type' => Invoice::class,
                'auditable_id' => $invoice->id,
                'description' => "Invoice {$invoice->invoice_number} created",
                'metadata' => [
                    'invoice_number' => $invoice->invoice_number,
                    'total' => $invoice->total,
                ],
            ]);

            return $invoice->load(['items', 'customer', 'business']);
        });
    }

    /**
     * Update an invoice and its items. Only draft invoices may have items/pricing updated.
     *
     * @param  array<string, mixed>  $data
     */
    public function updateInvoice(Invoice $invoice, User $user, array $data): Invoice
    {
        if ($invoice->status !== InvoiceStatus::Draft) {
            throw ValidationException::withMessages([
                'status' => ['Only draft invoices can be modified.'],
            ]);
        }

        if (isset($data['customer_id'])) {
            Customer::where('id', $data['customer_id'])
                ->where('business_id', $invoice->business_id)
                ->firstOrFail();
        }

        return DB::transaction(function () use ($invoice, $user, $data): Invoice {
            $updateData = [];

            if (isset($data['customer_id'])) {
                $updateData['customer_id'] = $data['customer_id'];
            }
            if (isset($data['issue_date'])) {
                $updateData['issue_date'] = Carbon::parse($data['issue_date'])->toDateString();
            }
            if (isset($data['due_date'])) {
                $updateData['due_date'] = Carbon::parse($data['due_date'])->toDateString();
            }
            if (isset($data['currency'])) {
                $updateData['currency'] = $data['currency'];
            }
            if (array_key_exists('notes', $data)) {
                $updateData['notes'] = $data['notes'];
            }

            if (isset($data['items']) && is_array($data['items'])) {
                $calculation = InvoiceCalculator::calculate($data['items']);
                $updateData['subtotal'] = $calculation['subtotal'];
                $updateData['discount'] = $calculation['discount'];
                $updateData['tax'] = $calculation['tax'];
                $updateData['total'] = $calculation['total'];

                // Recreate items
                $invoice->items()->delete();
                foreach ($calculation['items'] as $itemData) {
                    InvoiceItem::create([
                        'invoice_id' => $invoice->id,
                        'product_id' => $itemData['product_id'],
                        'description' => $itemData['description'],
                        'quantity' => $itemData['quantity'],
                        'unit_price' => $itemData['unit_price'],
                        'discount' => $itemData['discount'],
                        'tax' => $itemData['tax'],
                        'subtotal' => $itemData['subtotal'],
                        'total' => $itemData['total'],
                    ]);
                }
            }

            $invoice->update($updateData);

            AuditLog::create([
                'business_id' => $invoice->business_id,
                'user_id' => $user->id,
                'action' => 'invoice.updated',
                'auditable_type' => Invoice::class,
                'auditable_id' => $invoice->id,
                'description' => "Invoice {$invoice->invoice_number} updated",
                'metadata' => [
                    'invoice_number' => $invoice->invoice_number,
                    'total' => $invoice->total,
                ],
            ]);

            return $invoice->fresh(['items', 'customer', 'business']);
        });
    }

    /**
     * Transition invoice status to 'sent'.
     */
    public function sendInvoice(Invoice $invoice, User $user): Invoice
    {
        InvoiceStatusTransition::validate($invoice->status, InvoiceStatus::Sent);

        return DB::transaction(function () use ($invoice, $user): Invoice {
            $invoice->update(['status' => InvoiceStatus::Sent]);

            AuditLog::create([
                'business_id' => $invoice->business_id,
                'user_id' => $user->id,
                'action' => 'invoice.sent',
                'auditable_type' => Invoice::class,
                'auditable_id' => $invoice->id,
                'description' => "Invoice {$invoice->invoice_number} sent",
            ]);

            return $invoice->fresh(['items', 'customer', 'business']);
        });
    }

    /**
     * Transition invoice status to 'void'.
     */
    public function voidInvoice(Invoice $invoice, User $user): Invoice
    {
        InvoiceStatusTransition::validate($invoice->status, InvoiceStatus::Void);

        return DB::transaction(function () use ($invoice, $user): Invoice {
            $invoice->update(['status' => InvoiceStatus::Void]);

            AuditLog::create([
                'business_id' => $invoice->business_id,
                'user_id' => $user->id,
                'action' => 'invoice.voided',
                'auditable_type' => Invoice::class,
                'auditable_id' => $invoice->id,
                'description' => "Invoice {$invoice->invoice_number} voided",
            ]);

            return $invoice->fresh(['items', 'customer', 'business']);
        });
    }

    /**
     * Transition invoice status to 'cancelled'.
     */
    public function cancelInvoice(Invoice $invoice, User $user): Invoice
    {
        InvoiceStatusTransition::validate($invoice->status, InvoiceStatus::Cancelled);

        return DB::transaction(function () use ($invoice, $user): Invoice {
            $invoice->update(['status' => InvoiceStatus::Cancelled]);

            AuditLog::create([
                'business_id' => $invoice->business_id,
                'user_id' => $user->id,
                'action' => 'invoice.cancelled',
                'auditable_type' => Invoice::class,
                'auditable_id' => $invoice->id,
                'description' => "Invoice {$invoice->invoice_number} cancelled",
            ]);

            return $invoice->fresh(['items', 'customer', 'business']);
        });
    }

    /**
     * Delete an invoice. Only draft or cancelled invoices can be deleted.
     */
    public function deleteInvoice(Invoice $invoice, User $user): void
    {
        if (! in_array($invoice->status, [InvoiceStatus::Draft, InvoiceStatus::Cancelled], true)) {
            throw ValidationException::withMessages([
                'status' => ['Only draft or cancelled invoices can be deleted.'],
            ]);
        }

        DB::transaction(function () use ($invoice, $user): void {
            AuditLog::create([
                'business_id' => $invoice->business_id,
                'user_id' => $user->id,
                'action' => 'invoice.deleted',
                'auditable_type' => Invoice::class,
                'auditable_id' => $invoice->id,
                'description' => "Invoice {$invoice->invoice_number} deleted",
            ]);

            $invoice->delete();
        });
    }

    /**
     * Generate a unique invoice number for a given business.
     */
    protected function generateUniqueInvoiceNumber(int $businessId): string
    {
        $prefix = 'INV-'.date('Ymd').'-';
        $attempts = 0;

        do {
            $attempts++;
            $candidate = $prefix.str_pad((string) random_int(1, 9999), 4, '0', STR_PAD_LEFT);
            $exists = Invoice::where('business_id', $businessId)->where('invoice_number', $candidate)->exists();
        } while ($exists && $attempts < 100);

        return $candidate;
    }
}
