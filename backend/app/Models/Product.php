<?php

declare(strict_types=1);

namespace App\Models;

use App\Jobs\ReindexProductEmbeddingJob;
use App\Support\ProductSearchBlob;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

class Product extends Model
{
    public const ENRICHMENT_NONE = 'none';

    public const ENRICHMENT_QUEUED = 'queued';

    public const ENRICHMENT_RUNNING = 'running';

    public const ENRICHMENT_DONE = 'done';

    public const ENRICHMENT_FAILED = 'failed';

    /** Kod wewnętrzny, którego nie ma w internecie — opis wpisuje człowiek, kolejki go pomijają. */
    public const ENRICHMENT_MANUAL = 'manual';

    protected $fillable = [
        'sku',
        'name',
        'manufacturer',
        'ean',
        'category',
        'assortment_group_id',
        'description',
        'enrichment_status',
        'enriched_at',
        'enrichment_error',
        'enrichment_trace',
        'enrichment_payload',
        'embedding_synced_at',
        'embedding_hash',
        'norms',
        'catalog_price_net',
        'discount_percent',
        'purchase_price',
        'currency',
        'stock',
        'pack_qty',
        'packaging',
        'shop_source_url',
    ];

    /**
     * Indeks wyszukiwania jest wyliczany, nie podawany z zewnątrz — przeliczamy go
     * przy każdej zmianie pól źródłowych, żeby import, enrichment i Presta nie
     * musiały o nim pamiętać osobno.
     */
    protected static function booted(): void
    {
        static::saving(function (self $product): void {
            if ($product->exists && ! $product->isDirty(ProductSearchBlob::SOURCE_COLUMNS)) {
                return;
            }

            foreach (app(ProductSearchBlob::class)->build($product) as $column => $value) {
                $product->setAttribute($column, $value);
            }
        });

        // Wektor w Qdrant powstaje z tych samych kolumn co blob. Bez tego haka
        // ręczna edycja opisu odświeżała indeks tekstowy, a wektor zostawał stary
        // — hybryda przestawała mówić o tym samym produkcie.
        static::created(function (self $product): void {
            ReindexProductEmbeddingJob::dispatch((int) $product->id);
        });

        // Sam UPDATE ceny czy stanu magazynowego nie zmienia dokumentu embeddingu —
        // reindeks byłby czystym kosztem.
        static::updated(function (self $product): void {
            if (! $product->wasChanged(ProductSearchBlob::SOURCE_COLUMNS)) {
                return;
            }

            ReindexProductEmbeddingJob::dispatch((int) $product->id);
        });
    }

    protected function casts(): array
    {
        return [
            'catalog_price_net' => 'decimal:2',
            'discount_percent' => 'decimal:2',
            'purchase_price' => 'decimal:2',
            'pack_qty' => 'integer',
            'enriched_at' => 'datetime',
            'embedding_synced_at' => 'datetime',
            'enrichment_payload' => 'array',
            'enrichment_trace' => 'array',
        ];
    }

    public function assortmentGroup(): BelongsTo
    {
        return $this->belongsTo(AssortmentGroup::class);
    }

    public function substitutes(): HasMany
    {
        return $this->hasMany(ProductSubstitute::class, 'main_product_id');
    }

    public function images(): HasMany
    {
        return $this->hasMany(ProductImage::class)->orderBy('sort_order');
    }

    public function documents(): HasMany
    {
        return $this->hasMany(ProductDocument::class)->orderBy('sort_order');
    }

    public function hasUsableDescription(): bool
    {
        if ($this->enrichment_status === self::ENRICHMENT_DONE) {
            return true;
        }
        $d = trim((string) ($this->description ?? ''));

        return $d !== '' && mb_strlen($d) >= 24;
    }

    public function priceHistory(): HasMany
    {
        return $this->hasMany(ProductPriceHistory::class)->latest('id');
    }

    public function specialPrices(): HasMany
    {
        return $this->hasMany(ProductSpecialPrice::class)->orderBy('client_name');
    }

    public function prestaMatches(): HasMany
    {
        return $this->hasMany(PrestaProductMatch::class);
    }

    public function prestaExport(): HasOne
    {
        return $this->hasOne(PrestaProductMatch::class)->latestOfMany();
    }

    public function hintedShopUrl(): ?string
    {
        $raw = trim((string) ($this->shop_source_url ?? ''));
        if ($raw === '' || preg_match('#^https?://#i', $raw) !== 1) {
            return null;
        }

        return mb_substr($raw, 0, 2000);
    }

    public function isHintedShopUrl(string $url): bool
    {
        $hint = $this->hintedShopUrl();
        if ($hint === null || trim($url) === '') {
            return false;
        }

        return self::normalizeShopUrl($hint) === self::normalizeShopUrl($url);
    }

    public static function normalizeShopUrl(string $url): string
    {
        $url = trim($url);
        $parts = parse_url($url);
        if (! is_array($parts) || empty($parts['host'])) {
            return mb_strtolower(rtrim($url, '/'));
        }
        $host = mb_strtolower((string) $parts['host']);
        $path = rtrim((string) ($parts['path'] ?? ''), '/');
        $query = isset($parts['query']) && $parts['query'] !== '' ? '?'.$parts['query'] : '';

        return $host.$path.$query;
    }
}
