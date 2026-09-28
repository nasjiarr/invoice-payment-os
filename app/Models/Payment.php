<?php

namespace App\Models;

use App\Enums\PaymentStatus;
use Database\Factories\PaymentFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphMany;

#[Fillable([
    'business_id',
    'invoice_id',
    'amount',
    'currency',
    'status',
    'payment_method',
    'transaction_id',
    'paid_at',
    'notes',
])]
class Payment extends Model
{
    /** @use HasFactory<PaymentFactory> */
    use HasFactory;

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'amount' => 'decimal:2',
            'status' => PaymentStatus::class,
            'paid_at' => 'datetime',
        ];
    }

    /**
     * Scope a query to only include payments accessible by a specific user.
     *
     * @param  Builder<Payment>  $query
     * @return Builder<Payment>
     */
    public function scopeAccessibleBy(Builder $query, User $user): Builder
    {
        return $query->whereHas('business', function (Builder $bQuery) use ($user): void {
            $bQuery->where('owner_id', $user->id)
                ->orWhereHas('users', fn (Builder $uQuery) => $uQuery->where('users.id', $user->id));
        });
    }

    /**
     * Get the business that owns the payment.
     *
     * @return BelongsTo<Business, $this>
     */
    public function business(): BelongsTo
    {
        return $this->belongsTo(Business::class);
    }

    /**
     * Get the invoice for which this payment was made.
     *
     * @return BelongsTo<Invoice, $this>
     */
    public function invoice(): BelongsTo
    {
        return $this->belongsTo(Invoice::class);
    }

    /**
     * Get the audit logs for this payment.
     *
     * @return MorphMany<AuditLog, $this>
     */
    public function auditLogs(): MorphMany
    {
        return $this->morphMany(AuditLog::class, 'auditable');
    }
}
