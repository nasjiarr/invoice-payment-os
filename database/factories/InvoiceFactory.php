<?php

namespace Database\Factories;

use App\Enums\InvoiceStatus;
use App\Models\Business;
use App\Models\Customer;
use App\Models\Invoice;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Invoice>
 */
class InvoiceFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $business = Business::factory();
        $subtotal = fake()->randomFloat(2, 500000, 10000000);
        $tax = round($subtotal * 0.11, 2);
        $discount = 0.00;
        $total = $subtotal + $tax - $discount;

        return [
            'business_id' => $business,
            'customer_id' => Customer::factory()->state(fn () => [
                'business_id' => $business,
            ]),
            'invoice_number' => 'INV-'.date('Ymd').'-'.fake()->unique()->numerify('####'),
            'status' => fake()->randomElement(InvoiceStatus::cases()),
            'issue_date' => now()->toDateString(),
            'due_date' => now()->addDays(14)->toDateString(),
            'currency' => 'IDR',
            'subtotal' => $subtotal,
            'tax' => $tax,
            'discount' => $discount,
            'total' => $total,
            'notes' => fake()->sentence(),
        ];
    }

    /**
     * Indicate that the invoice is paid.
     */
    public function paid(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => InvoiceStatus::Paid,
        ]);
    }

    /**
     * Indicate that the invoice is draft.
     */
    public function draft(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => InvoiceStatus::Draft,
        ]);
    }
}
