<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use LogicException;

/**
 * Cena karty z jednego źródła: cennik z pliku (source_key „file”) albo konto B2B („b2b:{id}”).
 * Cenę obowiązującą na karcie liczy App\Services\Pricing\ProductEffectivePrice.
 */
class ProductSourcePrice extends Model
{
    public const SOURCE_FILE = 'file';

    private const B2B_PREFIX = 'b2b:';

    /**
     * Klon z ceną w widoku standardowym (App\Services\Pricing\SupplierSpecialMask) — nie wolno go zapisać ani
     * skasować: zapis wpisałby cenę standardową w miejsce prawdziwej ceny konta.
     */
    public bool $priceMasked = false;

    protected $fillable = [
        'product_id',
        'source_key',
        'b2b_account_id',
        'price_list_id',
        'catalog_price_net',
        'purchase_price',
        // najwyższa cena rozmiaru, gdy purchase_price to najniższa z rozmiarów w różnych cenach (product_variants
        // „size”); null = jedna cena
        'size_price_max',
        'discount_percent',
        // cennik bazowy dostawcy obok ceny konta albo ceny specjalnej z pliku (App\Support\SupplierSpecialPrice);
        // catalog_price_net bez zmian
        'base_price_net',
        'base_price_category',
        'base_price_code',
        'base_price_source',
        'standard_discount_percent',
        'currency',
        'pack_qty',
        // dostępność u dostawcy dosłownie ze źródła (tylko sloty B2B; null = źródło jej nie podaje)
        'availability',
        // warunek zamawiania u dostawcy (tylko sloty B2B): najmniejsza ilość i krok; step null przy minimum = bez kroku
        'order_min_qty',
        'order_step_qty',
        'order_unit',
        // rozmiary karty mają różne warunki (min i step null) — widoki: „zależy od rozmiaru”
        'order_varies',
        // warunek ceny konta (Delta Plus: cena za pełny karton) — przypis dosłownie i ilość w kartonie
        'price_note',
        'price_carton_qty',
        // druga cena konta: niższa cena przy pełnym kartonie carton_qty (BIG, 01.10.2026); purchase_price zostaje ceną od minimum
        'carton_price_net',
        'carton_qty',
        'checked_at',
        'migrated',
    ];

    protected static function booted(): void
    {
        static::saving(static function (self $model): void {
            if ($model->priceMasked) {
                throw new LogicException('Maskowana kopia ceny nie może być zapisana');
            }
        });
        static::deleting(static function (self $model): void {
            if ($model->priceMasked) {
                throw new LogicException('Maskowana kopia ceny nie może być zapisana');
            }
        });
    }

    protected function casts(): array
    {
        return [
            'catalog_price_net' => 'decimal:2',
            'purchase_price' => 'decimal:2',
            'size_price_max' => 'decimal:2',
            'discount_percent' => 'decimal:2',
            'base_price_net' => 'decimal:2',
            'standard_discount_percent' => 'decimal:2',
            'pack_qty' => 'integer',
            'order_min_qty' => 'float',
            'order_step_qty' => 'float',
            'order_varies' => 'boolean',
            'price_carton_qty' => 'float',
            'carton_price_net' => 'decimal:2',
            'carton_qty' => 'float',
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

    /**
     * Slot, który może nieść ocenę ceny specjalnej (App\Support\SupplierSpecialPrice): konto B2B albo cennik z pliku
     * z kolumną ceny specjalnej (SECURA „40% s.dystryb.”, decyzja właściciela 10.10.2026). Sama ocena wymaga jeszcze
     * ceny bazowej i rabatu standardowego — slot pliku bez nich zachowuje się jak dotąd.
     */
    public function carriesSupplierSpecial(): bool
    {
        return $this->isB2b() || $this->source_key === self::SOURCE_FILE;
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
