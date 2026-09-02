<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Schema;
use Throwable;

/**
 * Domena usunięta w administracji — nie wraca z konfiguracji ani z indeksu.
 */
class CatalogSearchSiteExclusion extends Model
{
    protected $fillable = [
        'host',
    ];

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

    public static function hasHost(string $host): bool
    {
        if ($host === '' || ! self::tableReady()) {
            return false;
        }

        return self::query()->where('host', $host)->exists();
    }

    public static function remember(string $host): void
    {
        if ($host === '' || ! self::tableReady()) {
            return;
        }

        self::query()->firstOrCreate(['host' => $host]);
    }

    public static function forget(string $host): void
    {
        if ($host === '' || ! self::tableReady()) {
            return;
        }

        self::query()->where('host', $host)->delete();
    }

    private static function tableReady(): bool
    {
        try {
            return Schema::hasTable('catalog_search_site_exclusions');
        } catch (Throwable) {
            return false;
        }
    }
}
