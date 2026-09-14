<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Wersja karty u dostawcy (np. format × podłoże znaku) z ceną konta. Etykieta i atrybuty dosłownie ze źródła.
 */
class ProductVariant extends Model
{
    protected $fillable = [
        'product_id',
        'b2b_account_id',
        'source',
        'remote_id',
        'label',
        'attributes',
        'purchase_price',
        'list_price_net',
        'currency',
        'vat_rate',
        'unit',
        'source_url',
        'sort_order',
        'price_checked_at',
        'last_seen_at',
        'removed_at',
    ];

    protected function casts(): array
    {
        return [
            'attributes' => 'array',
            'purchase_price' => 'decimal:2',
            'list_price_net' => 'decimal:2',
            'vat_rate' => 'decimal:2',
            'sort_order' => 'integer',
            'price_checked_at' => 'datetime',
            'last_seen_at' => 'datetime',
            'removed_at' => 'datetime',
        ];
    }

    /**
     * @param  Builder<ProductVariant>  $query
     */
    public function scopeActive(Builder $query): void
    {
        $query->whereNull('removed_at');
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    public function account(): BelongsTo
    {
        return $this->belongsTo(B2bAccount::class, 'b2b_account_id');
    }

    public function priceHistory(): HasMany
    {
        return $this->hasMany(ProductVariantPriceHistory::class);
    }
}
