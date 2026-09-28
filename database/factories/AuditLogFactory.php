<?php

namespace Database\Factories;

use App\Models\AuditLog;
use App\Models\Business;
use App\Models\Invoice;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<AuditLog>
 */
class AuditLogFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'business_id' => Business::factory(),
            'user_id' => User::factory(),
            'action' => fake()->randomElement([
                'invoice.created',
                'invoice.sent',
                'invoice.voided',
                'payment.received',
                'payment.refunded',
            ]),
            'auditable_type' => Invoice::class,
            'auditable_id' => Invoice::factory(),
            'description' => fake()->sentence(),
            'metadata' => [
                'ip' => fake()->ipv4(),
                'change' => 'status updated',
            ],
            'ip_address' => fake()->ipv4(),
            'user_agent' => fake()->userAgent(),
            'created_at' => now(),
        ];
    }
}
