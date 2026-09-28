<?php

namespace App\Models;

use App\Enums\PaymentEventStatus;
use Database\Factories\PaymentEventFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

#[Fillable([
    'provider',
    'event_id',
    'event_type',
    'payload',
    'status',
    'processed_at',
])]
class PaymentEvent extends Model
{
    /** @use HasFactory<PaymentEventFactory> */
    use HasFactory;

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'status' => PaymentEventStatus::class,
            'payload' => 'array',
            'processed_at' => 'datetime',
        ];
    }
}
