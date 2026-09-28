<?php

namespace Database\Factories;

use App\Enums\PaymentEventStatus;
use App\Models\PaymentEvent;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<PaymentEvent>
 */
class PaymentEventFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'provider' => fake()->randomElement(['midtrans', 'xendit', 'stripe']),
            'event_id' => 'evt_'.fake()->unique()->regexify('[a-zA-Z0-9]{16}'),
            'event_type' => fake()->randomElement(['payment.success', 'settlement', 'charge.refunded', 'payment.failed']),
            'payload' => [
                'order_id' => 'ORDER-'.fake()->numerify('#####'),
                'gross_amount' => 500000,
                'status' => 'settlement',
            ],
            'status' => fake()->randomElement(PaymentEventStatus::cases()),
            'processed_at' => now(),
        ];
    }
}
