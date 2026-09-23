<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Identyfikator wyrobu z jednego źródła ceny (konto B2B, cennik z pliku) — dosłownie ze źródła, z pochodzeniem.
 * Zapis: App\Services\Catalog\ProductIdentifierStore.
 */
class ProductIdentifier extends Model
{
    /** EAN/GTIN sztuki (rozmiaru, wersji). */
    public const TYPE_EAN = 'ean';

    /** GTIN kartonu — osobno, żeby karton nie łączył się ze sztuką. */
    public const TYPE_PACK_EAN = 'pack_ean';

    /** Własny kod pozycji u źródła (u producenta to zarazem kod producenta — rozstrzyga marka źródła). */
    public const TYPE_SOURCE_CODE = 'source_code';

    /** Kod producenta nazwany tak wprost przez źródło (MPN, „Kod producenta”, numer katalogowy). */
    public const TYPE_MANUFACTURER_CODE = 'manufacturer_code';

    /** Kod rodziny lub modelu bez rozmiaru. */
    public const TYPE_MODEL_CODE = 'model_code';

    /** Inny kod producenta (numer magazynowy 3M, drugi kod z cennika). */
    public const TYPE_ALT_CODE = 'alt_code';

    /** Poprzedni numer wyrobu. */
    public const TYPE_LEGACY_CODE = 'legacy_code';

    public const TYPES = [
        self::TYPE_EAN,
        self::TYPE_PACK_EAN,
        self::TYPE_SOURCE_CODE,
        self::TYPE_MANUFACTURER_CODE,
        self::TYPE_MODEL_CODE,
        self::TYPE_ALT_CODE,
        self::TYPE_LEGACY_CODE,
    ];

    protected $fillable = [
        'product_id',
        'source_key',
        'b2b_account_id',
        'price_list_id',
        'position_key',
        'type',
        'value',
        'normalized',
        'source_field',
        'variant_label',
        'manufacturer',
        'brand_key',
        'b2b_sync_run_id',
        'price_list_import_id',
        'first_seen_at',
        'last_seen_at',
        'removed_at',
    ];

    protected function casts(): array
    {
        return [
            'first_seen_at' => 'datetime',
            'last_seen_at' => 'datetime',
            'removed_at' => 'datetime',
        ];
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }
}
