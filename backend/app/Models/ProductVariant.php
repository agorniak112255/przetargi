<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use LogicException;

/**
 * Wersja karty u dostawcy z ceną konta. Etykieta i atrybuty dosłownie ze źródła.
 * kind „version” — wersje Sign Project (format × podłoże znaku): karta ma cenę 0, ceny tylko tutaj.
 * kind „size” — rozmiary jednej karty w różnych cenach (decyzja użytkownika 28.09.2026): karta ma cenę
 * najniższego rozmiaru ze slotu konta, source = slot konta „b2b:{id}”.
 */
class ProductVariant extends Model
{
    public const KIND_VERSION = 'version';

    public const KIND_SIZE = 'size';

    /**
     * Klon z ceną w widoku standardowym (App\Services\Pricing\SupplierSpecialMask) — nie wolno go zapisać ani
     * skasować: zapis wpisałby cenę standardową w miejsce prawdziwej ceny konta.
     */
    public bool $priceMasked = false;

    protected $fillable = [
        'product_id',
        'kind',
        'b2b_account_id',
        'source',
        'remote_id',
        'sku',
        'label',
        'attributes',
        'purchase_price',
        'list_price_net',
        'currency',
        'vat_rate',
        'unit',
        'availability',
        'source_url',
        'sort_order',
        'price_checked_at',
        'last_seen_at',
        'removed_at',
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

    /**
     * Wersje Sign Project — tylko one oznaczają kartę z ceną 0.
     *
     * @param  Builder<ProductVariant>  $query
     */
    public function scopeVersions(Builder $query): void
    {
        $query->where('kind', self::KIND_VERSION);
    }

    /**
     * Rozmiary w różnych cenach — karta ma cenę najniższego rozmiaru.
     *
     * @param  Builder<ProductVariant>  $query
     */
    public function scopeSizes(Builder $query): void
    {
        $query->where('kind', self::KIND_SIZE);
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
