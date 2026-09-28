<?php

namespace Database\Factories;

use App\Enums\PaymentStatus;
use App\Models\Business;
use App\Models\Invoice;
use App\Models\Payment;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Payment>
 */
class PaymentFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $business = Business::factory();

        return [
            'business_id' => $business,
            'invoice_id' => Invoice::factory()->state(fn () => [
                'business_id' => $business,
            ]),
            'amount' => fake()->randomFloat(2, 100000, 5000000),
            'currency' => 'IDR',
            'status' => fake()->randomElement(PaymentStatus::cases()),
            'payment_method' => fake()->randomElement(['bank_transfer', 'qris', 'credit_card', 'cash']),
            'transaction_id' => 'TRX-'.fake()->unique()->regexify('[A-Z0-9]{12}'),
            'paid_at' => now(),
            'notes' => fake()->sentence(),
        ];
    }

    /**
     * Indicate that the payment is paid.
     */
    public function paid(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => PaymentStatus::Paid,
            'paid_at' => now(),
        ]);
    }

    /**
     * Indicate that the payment is pending.
     */
    public function pending(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => PaymentStatus::Pending,
            'paid_at' => null,
        ]);
    }

    /**
     * Indicate that the payment is failed.
     */
    public function failed(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => PaymentStatus::Failed,
            'paid_at' => null,
        ]);
    }

    /**
     * Indicate that the payment is cancelled.
     */
    public function cancelled(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => PaymentStatus::Cancelled,
            'paid_at' => null,
        ]);
    }

    /**
     * Indicate that the payment is refunded.
     */
    public function refunded(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => PaymentStatus::Refunded,
            'paid_at' => now(),
        ]);
    }
}
