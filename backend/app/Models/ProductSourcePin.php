<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Mapa karty (10.10.2026): strona, z której importer cennika każe brać opis i zdjęcie. Zapisuje wyłącznie
 * MapPriceListSourcesJob / price-lists:map --apply. url null = nierozwiązana (unresolved_reason, candidates).
 * Adres wskazany przez człowieka (products.shop_source_url) zawsze wygrywa — nie trafia tutaj.
 */
class ProductSourcePin extends Model
{
    public const KIND_MANUFACTURER = 'manufacturer';

    public const KIND_SUPPLIER = 'supplier';

    public const KIND_SHOP = 'shop';

    public const KINDS = [self::KIND_MANUFACTURER, self::KIND_SUPPLIER, self::KIND_SHOP];

    public const MATCH_EXACT_CODE = 'exact_code';

    public const MATCH_SHORT_CODE = 'short_code';

    public const MATCH_EAN = 'ean';

    public const MATCH_MODEL = 'model';

    public const MATCH_PARTS_TABLE = 'parts_table';

    public const MATCH_KINDS = [
        self::MATCH_EXACT_CODE,
        self::MATCH_SHORT_CODE,
        self::MATCH_EAN,
        self::MATCH_MODEL,
        self::MATCH_PARTS_TABLE,
    ];

    protected $fillable = [
        'product_id',
        'price_list_id',
        'importer_key',
        'importer_version',
        'url',
        'source_kind',
        'page_title',
        'image_url',
        'match_kind',
        'match_key',
        'spec',
        'evidence',
        'unresolved_reason',
        'candidates',
        'checked_at',
    ];

    protected function casts(): array
    {
        return [
            'importer_version' => 'integer',
            'spec' => 'array',
            'evidence' => 'array',
            'candidates' => 'array',
            'checked_at' => 'datetime',
        ];
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    public function priceList(): BelongsTo
    {
        return $this->belongsTo(PriceList::class);
    }

    public function isResolved(): bool
    {
        return is_string($this->url) && trim($this->url) !== '';
    }
}
