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
     * @return list<string>
     */
    public static function hostsForBrand(string $brandKey): array
    {
        $brandKey = mb_strtolower(trim($brandKey));
        if ($brandKey === '' || ! self::tableReady()) {
            return [];
        }

        return self::query()
            ->where('brand_key', $brandKey)
            ->pluck('host')
            ->all();
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
