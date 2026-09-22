<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Cena karty z jednego źródła: cennik z pliku (source_key „file”) albo konto B2B („b2b:{id}”).
 * Cenę obowiązującą na karcie liczy App\Services\Pricing\ProductEffectivePrice.
 */
class ProductSourcePrice extends Model
{
    public const SOURCE_FILE = 'file';

    private const B2B_PREFIX = 'b2b:';

    protected $fillable = [
        'product_id',
        'source_key',
        'b2b_account_id',
        'price_list_id',
        'catalog_price_net',
        'purchase_price',
        'discount_percent',
        // cennik bazowy dostawcy obok ceny konta (App\Support\SupplierSpecialPrice); catalog_price_net bez zmian
        'base_price_net',
        'base_price_category',
        'base_price_code',
        'base_price_source',
        'standard_discount_percent',
        'currency',
        'pack_qty',
        // dostępność u dostawcy dosłownie ze źródła (tylko sloty B2B; null = źródło jej nie podaje)
        'availability',
        'checked_at',
        'migrated',
    ];

    protected function casts(): array
    {
        return [
            'catalog_price_net' => 'decimal:2',
            'purchase_price' => 'decimal:2',
            'discount_percent' => 'decimal:2',
            'base_price_net' => 'decimal:2',
            'standard_discount_percent' => 'decimal:2',
            'pack_qty' => 'integer',
            'checked_at' => 'datetime',
            'migrated' => 'boolean',
        ];
    }

    public static function b2bKey(int $accountId): string
    {
        return self::B2B_PREFIX.$accountId;
    }

    public function isB2b(): bool
    {
        return str_starts_with((string) $this->source_key, self::B2B_PREFIX);
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    public function account(): BelongsTo
    {
        return $this->belongsTo(B2bAccount::class, 'b2b_account_id');
    }

    public function priceList(): BelongsTo
    {
        return $this->belongsTo(PriceList::class);
    }
}
