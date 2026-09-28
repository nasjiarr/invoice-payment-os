<?php

namespace App\Models;

use Database\Factories\ProductFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable(['business_id', 'name', 'description', 'price', 'active'])]
class Product extends Model
{
    /** @use HasFactory<ProductFactory> */
    use HasFactory;

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'price' => 'decimal:2',
            'active' => 'boolean',
        ];
    }

    /**
     * Scope a query to only include products accessible by a specific user.
     *
     * @param  Builder<Product>  $query
     * @return Builder<Product>
     */
    public function scopeAccessibleBy(Builder $query, User $user): Builder
    {
        return $query->whereHas('business', function (Builder $bQuery) use ($user): void {
            $bQuery->where('owner_id', $user->id)
                ->orWhereHas('users', fn (Builder $uQuery) => $uQuery->where('users.id', $user->id));
        });
    }

    /**
     * Get the business that owns the product.
     *
     * @return BelongsTo<Business, $this>
     */
    public function business(): BelongsTo
    {
        return $this->belongsTo(Business::class);
    }

    /**
     * Get the invoice items associated with this product.
     *
     * @return HasMany<InvoiceItem, $this>
     */
    public function invoiceItems(): HasMany
    {
        return $this->hasMany(InvoiceItem::class);
    }
}
