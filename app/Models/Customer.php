<?php

namespace App\Models;

use Database\Factories\CustomerFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable(['business_id', 'name', 'email', 'phone', 'address', 'notes'])]
class Customer extends Model
{
    /** @use HasFactory<CustomerFactory> */
    use HasFactory;

    /**
     * Scope a query to only include customers accessible by a specific user.
     *
     * @param  Builder<Customer>  $query
     * @return Builder<Customer>
     */
    public function scopeAccessibleBy(Builder $query, User $user): Builder
    {
        return $query->whereHas('business', function (Builder $bQuery) use ($user): void {
            $bQuery->where('owner_id', $user->id)
                ->orWhereHas('users', fn (Builder $uQuery) => $uQuery->where('users.id', $user->id));
        });
    }

    /**
     * Get the business that owns the customer.
     *
     * @return BelongsTo<Business, $this>
     */
    public function business(): BelongsTo
    {
        return $this->belongsTo(Business::class);
    }

    /**
     * Get the invoices for the customer.
     *
     * @return HasMany<Invoice, $this>
     */
    public function invoices(): HasMany
    {
        return $this->hasMany(Invoice::class);
    }
}
