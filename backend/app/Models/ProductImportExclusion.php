<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Pozycja źródła usuniętej karty, której import nie zakłada od nowa („Usuń i pomijaj przy imporcie”). Aktywna, dopóki
 * restored_at jest null. Zapis i odczyt: App\Services\Catalog\ProductImportExclusions.
 */
class ProductImportExclusion extends Model
{
    /** Pozycja źródła: remote_id powiązania B2B albo kod wiersza cennika z pliku. */
    public const KIND_POSITION = 'position';

    /** Karta z ceną z pliku bez zapisanych kodów wierszy — dopasowanie po SKU karty (na najlepsze starania). */
    public const KIND_SKU = 'sku';

    protected $fillable = [
        'deletion_id',
        'source_key',
        'scope_key',
        'match_kind',
        'position_key',
        'match_key',
        'b2b_account_id',
        'price_list_id',
        'manufacturer_key',
        'product_id',
        'product_sku',
        'product_name',
        'product_manufacturer',
        'product_snapshot',
        'remote_sku',
        'position_label',
        'deleted_by',
        'restored_at',
        'restored_by',
        'hits',
        'last_hit_at',
    ];

    protected function casts(): array
    {
        return [
            'b2b_account_id' => 'integer',
            'price_list_id' => 'integer',
            'product_id' => 'integer',
            'product_snapshot' => 'array',
            'deleted_by' => 'integer',
            'restored_at' => 'datetime',
            'restored_by' => 'integer',
            'hits' => 'integer',
            'last_hit_at' => 'datetime',
        ];
    }

    public function account(): BelongsTo
    {
        return $this->belongsTo(B2bAccount::class, 'b2b_account_id');
    }

    public function priceList(): BelongsTo
    {
        return $this->belongsTo(PriceList::class);
    }

    public function deletedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'deleted_by');
    }

    public function restoredBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'restored_by');
    }
}
