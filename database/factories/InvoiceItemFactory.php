<?php

namespace Database\Factories;

use App\Models\Invoice;
use App\Models\InvoiceItem;
use App\Models\Product;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<InvoiceItem>
 */
class InvoiceItemFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $quantity = fake()->numberBetween(1, 5);
        $unitPrice = fake()->randomElement([100000.00, 250000.00, 500000.00, 1000000.00]);
        $subtotal = round($quantity * $unitPrice, 2);
        $discount = 0.00;
        $tax = round($subtotal * 0.11, 2);
        $total = $subtotal + $tax - $discount;

        return [
            'invoice_id' => Invoice::factory(),
            'product_id' => Product::factory(),
            'description' => fake()->sentence(3),
            'quantity' => $quantity,
            'unit_price' => $unitPrice,
            'discount' => $discount,
            'tax' => $tax,
            'subtotal' => $subtotal,
            'total' => $total,
        ];
    }
}
