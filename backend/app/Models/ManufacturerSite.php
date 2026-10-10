<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Schema;
use Throwable;

/**
 * Strona katalogu producenta — config albo wykryta przy imporcie nowej marki.
 */
class ManufacturerSite extends Model
{
    protected $fillable = [
        'brand_key',
        'manufacturer',
        'host',
        'source',
    ];

    /**
     * Ranga źródła wpisu: człowiek (manual) > config > wykryte automatem (discovered). Zapis źródłem o niższej randze
     * nie nadpisuje wiersza o wyższej — wykrywanie przy imporcie marki (RegisterManufacturerCatalogJob) zamieniało
     * dotąd ręczne przypisanie w „discovered” i domena wypadała z assignedDomainsFor (10.10.2026).
     */
    public const RANK = ['discovered' => 1, 'config' => 2, 'manual' => 3];

    /**
     * @param  list<string>  $hosts
     */
    public static function remember(string $brandKey, string $manufacturer, array $hosts, string $source): void
    {
        $brandKey = mb_strtolower(trim($brandKey));
        if ($brandKey === '' || ! self::tableReady()) {
            return;
        }

        foreach ($hosts as $host) {
            $host = self::normalizeHost($host);
            if ($host === '') {
                continue;
            }
            $existing = self::query()->where('brand_key', $brandKey)->where('host', $host)->value('source');
            if ($existing !== null && (self::RANK[(string) $existing] ?? 0) > (self::RANK[$source] ?? 0)) {
                continue;
            }
            self::query()->updateOrCreate(
                ['brand_key' => $brandKey, 'host' => $host],
                [
                    'manufacturer' => mb_substr(trim($manufacturer), 0, 100),
                    'source' => $source,
                ]
            );
        }
    }

    /**
     * @param  list<string>|null  $sources  null = wszystkie źródła; np. ['manual', 'config'] bez wykrytych automatem
     * @return list<string>
     */
    public static function hostsForBrand(string $brandKey, ?array $sources = null): array
    {
        $brandKey = mb_strtolower(trim($brandKey));
        if ($brandKey === '' || ! self::tableReady()) {
            return [];
        }

        return self::query()
            ->where('brand_key', $brandKey)
            ->when($sources !== null, static fn ($q) => $q->whereIn('source', $sources))
            ->pluck('host')
            ->all();
    }

    /**
     * Marki przypisane do hostów — panel pokazuje, czyją stroną producenta jest domena,
     * i czy przypisał ją człowiek, czy wykrywanie przy imporcie marki.
     *
     * @return array<string, list<array{brand_key: string, manufacturer: string, source: string}>>
     */
    public static function brandsByHost(): array
    {
        if (! self::tableReady()) {
            return [];
        }

        $out = [];
        foreach (self::query()->get(['brand_key', 'manufacturer', 'host', 'source']) as $row) {
            $host = self::normalizeHost((string) $row->host);
            if ($host === '') {
                continue;
            }
            $out[$host][] = [
                'brand_key' => (string) $row->brand_key,
                'manufacturer' => (string) $row->manufacturer,
                'source' => (string) $row->source,
            ];
        }

        return $out;
    }

    /**
     * @return list<string>
     */
    public static function allHosts(): array
    {
        if (! self::tableReady()) {
            return [];
        }

        return self::query()->pluck('host')->unique()->values()->all();
    }

    public static function normalizeHost(string $domain): string
    {
        $clean = mb_strtolower(trim(preg_replace('#^https?://#i', '', $domain) ?? $domain));
        $clean = rtrim(explode('/', $clean)[0] ?? $clean, '/');
        $clean = preg_replace('/^www\./', '', $clean) ?? $clean;

        return $clean;
    }

    private static function tableReady(): bool
    {
        try {
            return Schema::hasTable('manufacturer_sites');
        } catch (Throwable) {
            return false;
        }
    }
}
