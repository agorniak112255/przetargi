<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Schema;
use Throwable;

/**
 * Domena dodana ręcznie do indeksu / wyszukiwania kart produktu.
 */
class CatalogSearchSite extends Model
{
    protected $fillable = [
        'host',
        'source',
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

    private static function tableReady(): bool
    {
        try {
            return Schema::hasTable('catalog_search_sites');
        } catch (Throwable) {
            return false;
        }
    }
}
