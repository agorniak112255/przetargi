<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Cena wersji z datą i źródłem — wiersz tylko przy nowej wersji albo zmianie ceny.
 */
class ProductVariantPriceHistory extends Model
{
    protected $table = 'product_variant_price_history';

    protected $fillable = [
        'product_variant_id',
        'b2b_sync_run_id',
        'purchase_price',
        'list_price_net',
        'currency',
        'source',
    ];

    protected function casts(): array
    {
        return [
            'purchase_price' => 'decimal:2',
            'list_price_net' => 'decimal:2',
        ];
    }

    public function variant(): BelongsTo
    {
        return $this->belongsTo(ProductVariant::class, 'product_variant_id');
    }

    public function syncRun(): BelongsTo
    {
        return $this->belongsTo(B2bSyncRun::class, 'b2b_sync_run_id');
    }
}
