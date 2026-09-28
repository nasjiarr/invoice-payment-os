<?php

namespace App\Domain\Invoice;

use App\Enums\InvoiceStatus;
use App\Exceptions\InvalidStatusTransitionException;

class InvoiceStatusTransition
{
    /**
     * Determine if a transition from one status to another is valid.
     */
    public static function canTransition(InvoiceStatus $from, InvoiceStatus $to): bool
    {
        if ($from === $to) {
            return false;
        }

        return match ($from) {
            InvoiceStatus::Draft => in_array($to, [
                InvoiceStatus::Sent,
                InvoiceStatus::PartiallyPaid,
                InvoiceStatus::Paid,
                InvoiceStatus::Cancelled,
            ], true),

            InvoiceStatus::Sent => in_array($to, [
                InvoiceStatus::PartiallyPaid,
                InvoiceStatus::Paid,
                InvoiceStatus::Void,
            ], true),

            InvoiceStatus::PartiallyPaid => in_array($to, [
                InvoiceStatus::Paid,
                InvoiceStatus::Void,
            ], true),

            InvoiceStatus::Paid => false, // Paid invoices cannot transition back to draft or change
            InvoiceStatus::Void => false,
            InvoiceStatus::Cancelled => false,
        };
    }

    /**
     * Validate the transition and throw an exception if invalid.
     *
     * @throws InvalidStatusTransitionException
     */
    public static function validate(InvoiceStatus $from, InvoiceStatus $to): void
    {
        if (! self::canTransition($from, $to)) {
            throw new InvalidStatusTransitionException($from, $to);
        }
    }
}
