<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Jedna aktualizacja cennika. Wpis w Cennikach jest jeden na producenta, a tu leży historia:
 * co przyszło którym przebiegiem, z jakiego pliku albo konta, i których kart dotyczyło.
 */
class PriceListImport extends Model
{
    public const SOURCE_FILE = 'file';

    public const SOURCE_B2B = 'b2b';

    protected $fillable = [
        'price_list_id',
        'source',
        'version',
        'original_filename',
        'imported_by',
        'rows_total',
        'products_created',
        'products_updated',
        'prices_changed',
        'rows_skipped',
        'errors',
        'price_changes',
        'updated_products',
        'skipped_details',
        'product_ids',
        'legacy_price_list_id',
    ];

    protected function casts(): array
    {
        return [
            'errors' => 'array',
            'price_changes' => 'array',
            'updated_products' => 'array',
            'skipped_details' => 'array',
            'product_ids' => 'array',
        ];
    }

    public function priceList(): BelongsTo
    {
        return $this->belongsTo(PriceList::class);
    }

    public function importer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'imported_by');
    }
}
