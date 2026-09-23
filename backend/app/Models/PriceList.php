<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Cennik producenta — jeden wpis na producenta, niezależnie od liczby aktualizacji i od tego, czy dane
 * przyszły z pliku, czy z konta B2B. Pola wersji, pliku i liczników opisują OSTATNIĄ aktualizację;
 * pełna historia leży w price_list_imports.
 */
class PriceList extends Model
{
    protected $fillable = [
        'manufacturer',
        'manufacturer_key',
        // ceny sugerowane bez cen zakupu — plik nie ma pierwszeństwa przed kontem B2B (ProductEffectivePrice)
        'suggested_prices',
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
    ];

    protected function casts(): array
    {
        return [
            'suggested_prices' => 'boolean',
            'errors' => 'array',
            'price_changes' => 'array',
            'updated_products' => 'array',
            'skipped_details' => 'array',
            'product_ids' => 'array',
        ];
    }

    public function importer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'imported_by');
    }

    public function imports(): HasMany
    {
        return $this->hasMany(PriceListImport::class)->orderByDesc('id');
    }

    /**
     * Klucz, po którym kolejna aktualizacja odnajduje cennik producenta. Różnice w wielkości liter,
     * kropkach i odstępach to ten sam dostawca; różnica w treści nazwy to już inny wpis, bo scalanie
     * „ARTRA” z „ARTRA SAFETY” na wyczucie połączyłoby dwa katalogi bez pytania.
     */
    public static function manufacturerKey(string $manufacturer): string
    {
        $key = mb_strtolower(trim($manufacturer));
        $key = preg_replace('/[^\p{L}\p{N}]+/u', ' ', $key) ?? $key;

        return trim(preg_replace('/\s+/u', ' ', $key) ?? $key);
    }
}
