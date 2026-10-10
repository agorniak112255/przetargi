<?php

declare(strict_types=1);

namespace App\Models;

use App\Services\PriceLists\Importers\PriceListImporterRegistry;
use App\Support\EnrichmentSiteList;
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
    /** Źródła opisów: strony cennika, potem dotychczasowa hierarchia (lista „Strony wyszukiwarka”, reszta internetu). */
    public const MODE_FIRST = 'first';

    /** Źródła opisów: tylko producent i strony cennika — bez reszty internetu. */
    public const MODE_ONLY = 'only';

    public const MODES = [self::MODE_FIRST, self::MODE_ONLY];

    /**
     * Polityka źródeł opisu cennika z importerem (10.10.2026, decyzja właściciela): karta z mapą (product_source_pins)
     * czyta tylko przypiętą stronę; karta bez mapy NIE dostaje opisu z wyszukiwarki — idzie do „Do przeglądu”
     * (review_reason source_unmapped). null = dawny sposób (stare cenniki bez zmian).
     */
    public const POLICY_MAP_ONLY = 'map_only';

    public const POLICIES = [self::POLICY_MAP_ONLY];

    /** Stan przyjęcia cennika (liczony, nie zapisany — intakeStatus()). */
    public const INTAKE_LEGACY = 'legacy';

    public const INTAKE_AWAITING_FILE = 'awaiting_file';

    public const INTAKE_AWAITING_IMPORTER = 'awaiting_importer';

    public const INTAKE_IMPORTER_MISSING = 'importer_missing';

    public const INTAKE_READY = 'ready';

    public const INTAKE_IMPORTED = 'imported';

    public const INTAKE_FAILED = 'failed';

    protected $fillable = [
        'manufacturer',
        'manufacturer_key',
        // ceny sugerowane bez cen zakupu — plik nie ma pierwszeństwa przed kontem B2B (ProductEffectivePrice)
        'suggested_prices',
        // cennik z cenami specjalnymi dostawcy (kolumna ceny normalnej) — ustawia import, nie zdejmuje go nic automatycznie
        'has_supplier_special',
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
        'enrichment_sites',
        'enrichment_sites_mode',
        'enrichment_sites_updated_at',
        // importer per cennik (klucz z PriceListImporterRegistry), polityka źródeł opisu, uwagi dla programisty
        'importer_key',
        'source_policy',
        'importer_notes',
    ];

    protected function casts(): array
    {
        return [
            'suggested_prices' => 'boolean',
            'has_supplier_special' => 'boolean',
            'errors' => 'array',
            'price_changes' => 'array',
            'updated_products' => 'array',
            'skipped_details' => 'array',
            'product_ids' => 'array',
            'enrichment_sites' => 'array',
            'enrichment_sites_updated_at' => 'datetime',
        ];
    }

    /**
     * Strony cennika do opisów (hosty) w kolejności ważności.
     *
     * @return list<string>
     */
    public function enrichmentHosts(): array
    {
        return EnrichmentSiteList::hosts($this->enrichment_sites);
    }

    public function enrichmentSitesMode(): string
    {
        return in_array($this->enrichment_sites_mode, self::MODES, true) ? $this->enrichment_sites_mode : self::MODE_FIRST;
    }

    /** Odcisk ustawień: tryb + hosty w kolejności (kolejność to ważność, więc bez sortowania). */
    public function enrichmentHostsSha1(): string
    {
        return sha1($this->enrichmentSitesMode()."\n".implode("\n", $this->enrichmentHosts()));
    }

    public function importer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'imported_by');
    }

    public function imports(): HasMany
    {
        return $this->hasMany(PriceListImport::class)->orderByDesc('id');
    }

    /** Zapisane pliki cennika, najnowszy pierwszy. */
    public function files(): HasMany
    {
        return $this->hasMany(PriceListFile::class)->orderByDesc('id');
    }

    /** Cennik przyjmowany nowym sposobem (formularz → plik → importer → mapa kart). */
    public function usesIntake(): bool
    {
        return in_array($this->source_policy, self::POLICIES, true);
    }

    /**
     * Stan przyjęcia liczony z danych (status zapisany rozjechałby się przy wdrożeniu bez importera albo cofnięciu
     * wdrożenia): legacy → awaiting_file → awaiting_importer / importer_missing → ready / imported / failed.
     */
    public function intakeStatus(): string
    {
        if (! $this->usesIntake()) {
            return self::INTAKE_LEGACY;
        }
        $latest = $this->relationLoaded('files') ? $this->files->first() : $this->files()->first();
        if ($latest === null) {
            return self::INTAKE_AWAITING_FILE;
        }
        $key = trim((string) $this->importer_key);
        if ($key === '') {
            return self::INTAKE_AWAITING_IMPORTER;
        }
        if (app(PriceListImporterRegistry::class)->classFor($key) === null) {
            return self::INTAKE_IMPORTER_MISSING;
        }

        return match ($latest->status) {
            PriceListFile::STATUS_IMPORTED => self::INTAKE_IMPORTED,
            PriceListFile::STATUS_FAILED => self::INTAKE_FAILED,
            default => self::INTAKE_READY,
        };
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
