<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Schema;
use Throwable;

/**
 * Ręczna ranga domeny przy wyborze źródła opisu karty: 1 = najwyżej, 100 = najniżej.
 * Brak wiersza znaczy „bez rangi” — taka domena stoi niżej niż każda oceniona, a wyżej niż
 * strona spoza listy. Ranga dotyczy WYŁĄCZNIE kolejności źródeł opisu; nie czyni z domeny
 * sklepu ani strony producenta.
 */
class CatalogHostPriority extends Model
{
    public const MIN = 1;

    public const MAX = 100;

    protected $fillable = [
        'host',
        'priority',
    ];

    protected function casts(): array
    {
        return [
            'priority' => 'integer',
        ];
    }

    /**
     * Ranga wszystkich ocenionych domen: host → 1..100.
     *
     * @return array<string, int>
     */
    public static function map(): array
    {
        if (! self::tableReady()) {
            return [];
        }

        /** @var array<string, int> $rows */
        $rows = self::query()->pluck('priority', 'host')->all();

        return array_map(static fn ($p): int => (int) $p, $rows);
    }

    public static function set(string $host, int $priority): void
    {
        if ($host === '' || ! self::tableReady()) {
            return;
        }

        self::query()->updateOrCreate(['host' => $host], ['priority' => $priority]);
    }

    /**
     * @param  list<string>  $hosts
     */
    public static function forget(array $hosts): void
    {
        $hosts = array_values(array_filter($hosts));
        if ($hosts === [] || ! self::tableReady()) {
            return;
        }

        self::query()->whereIn('host', $hosts)->delete();
    }

    private static function tableReady(): bool
    {
        try {
            return Schema::hasTable('catalog_host_priorities');
        } catch (Throwable) {
            return false;
        }
    }
}
