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
        'model_name',
        'manufacturer',
        'ean',
        'category',
        'assortment_group_id',
        'description',
        'variant_summary',
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

    /**
     * Karta ma tekst opisu (co najmniej 24 znaki), który mówi coś ponad nazwę. Status „done” bez tekstu nie wystarcza — w katalogu
     * jest takich kart kilkanaście, a model i dowody ze słów nie mają wtedy czego potwierdzić. Opis powtarzający nazwę też nie:
     * import cennika 3M 2026 zapisał długie nazwy produktów jako opis (2939 kart bez pobranego opisu).
     */
    public function hasDescriptionText(): bool
    {
        $d = trim((string) ($this->description ?? ''));

        return $d !== '' && mb_strlen($d) >= 24 && ! $this->descriptionRepeatsName($d);
    }

    public function hasUsableDescription(): bool
    {
        if ($this->enrichment_status === self::ENRICHMENT_DONE) {
            return true;
        }

        return $this->hasDescriptionText();
    }

    /**
     * Opis to tekst nazwy z cennika: równy nazwie, jej początek albo długa nazwa ucięta przy imporcie (≥ 60 znaków) z krótką
     * resztą (< 80 znaków). Krótka nazwa na początku zwykłego opisu („Rękawice robocze wzmacniane, dzianina…”) to nadal opis.
     */
    private function descriptionRepeatsName(string $description): bool
    {
        $normalize = static fn (string $text): string => trim((string) preg_replace('/[^\p{L}\p{N}]+/u', ' ', mb_strtolower($text)));
        $name = $normalize((string) ($this->name ?? ''));
        $text = $normalize($description);
        if ($name === '' || $text === '') {
            return false;
        }

        return str_starts_with($name, $text)
            || (mb_strlen($name) >= 60 && str_starts_with($text, $name) && mb_strlen($text) - mb_strlen($name) < 80);
    }

    public function priceHistory(): HasMany
    {
        return $this->hasMany(ProductPriceHistory::class)->latest('id');
    }

    /** Wersje karty u dostawcy B2B (np. format × podłoże) z cenami konta; także wycofane (removed_at). */
    public function variants(): HasMany
    {
        return $this->hasMany(ProductVariant::class)->orderBy('sort_order')->orderBy('id');
    }

    public function specialPrices(): HasMany
    {
        return $this->hasMany(ProductSpecialPrice::class)->orderBy('client_name');
    }

    public function accessories(): HasMany
    {
        return $this->hasMany(ProductAccessory::class);
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
        $path = rtrim(rawurldecode((string) ($parts['path'] ?? '')), '/');
        $query = isset($parts['query']) && $parts['query'] !== '' ? '?'.$parts['query'] : '';

        return $host.$path.$query;
    }
}
