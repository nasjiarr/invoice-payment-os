<?php

namespace App\Domain\Invoice;

class InvoiceCalculator
{
    /**
     * Calculate line items and totals for an invoice.
     *
     * @param  array<int, array<string, mixed>>  $rawItems
     * @return array{
     *     subtotal: float,
     *     discount: float,
     *     tax: float,
     *     total: float,
     *     items: array<int, array<string, mixed>>
     * }
     */
    public static function calculate(array $rawItems): array
    {
        $calculatedItems = [];
        $invoiceSubtotal = 0.00;
        $invoiceDiscount = 0.00;
        $invoiceTax = 0.00;

        foreach ($rawItems as $item) {
            $quantity = (float) ($item['quantity'] ?? 1);
            $unitPrice = (float) ($item['unit_price'] ?? 0);

            $itemSubtotal = round($quantity * $unitPrice, 2);
            $rawDiscount = (float) ($item['discount'] ?? 0);
            $itemDiscount = round(min($rawDiscount, $itemSubtotal), 2);
            $itemTax = round((float) ($item['tax'] ?? 0), 2);

            $itemTotal = round(max(0, $itemSubtotal - $itemDiscount) + $itemTax, 2);

            $calculatedItems[] = [
                'product_id' => $item['product_id'] ?? null,
                'description' => $item['description'],
                'quantity' => $quantity,
                'unit_price' => $unitPrice,
                'discount' => $itemDiscount,
                'tax' => $itemTax,
                'subtotal' => $itemSubtotal,
                'total' => $itemTotal,
            ];

            $invoiceSubtotal += $itemSubtotal;
            $invoiceDiscount += $itemDiscount;
            $invoiceTax += $itemTax;
        }

        $invoiceSubtotal = round($invoiceSubtotal, 2);
        $invoiceDiscount = round($invoiceDiscount, 2);
        $invoiceTax = round($invoiceTax, 2);
        $invoiceTotal = round(max(0, $invoiceSubtotal - $invoiceDiscount) + $invoiceTax, 2);

        return [
            'subtotal' => $invoiceSubtotal,
            'discount' => $invoiceDiscount,
            'tax' => $invoiceTax,
            'total' => $invoiceTotal,
            'items' => $calculatedItems,
        ];
    }
}
