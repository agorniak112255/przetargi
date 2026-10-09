<?php

declare(strict_types=1);

namespace App\Services\Enrichment\PartsTable;

use App\Models\ManufacturerPart;
use App\Models\Product;
use App\Services\Enrichment\ManufacturerProfile;
use App\Services\Enrichment\ManufacturerProfiles;
use Illuminate\Container\Attributes\Scoped;

/**
 * Rejestr tabel części: resolver z profilu marki (klucz `resolver` w config/manufacturer_profiles.php) i wiersze marki
 * z manufacturer_parts (klucz marki = klucz wpisu profilu, np. „coba” także dla „coba-europe”). Wiersze trzymane raz
 * na markę w przebiegu (#[Scoped]: kolejne zadanie kolejki czyta od nowa) i czytane ponownie, gdy zmieni się
 * max(fetched_at) — po products:parts-table --refresh. Niczego nie zapisuje.
 */
#[Scoped]
final class PartsTables
{
    /** @var array<string, array{stamp: string, rows: list<ManufacturerPart>}> */
    private array $rows = [];

    /** @var array<string, PartsTableResolver> */
    private array $resolvers = [];

    public function __construct(private readonly ManufacturerProfiles $profiles) {}

    /**
     * Przypięcie karty do tabeli części. Null = marka bez resolvera, karta bez profilu albo marka bez wczytanych wierszy
     * (tabela jeszcze nie pobrana) — wtedy wzbogacanie idzie starą ścieżką. PinResult bez pinu = karta nierozwiązana
     * z powodem (także „adres ręczny”: adres wskazany przez człowieka wygrywa).
     */
    public function pinFor(Product $product): ?PinResult
    {
        $profile = $this->profiles->for($product);
        $resolver = $profile !== null ? $this->resolverFor($profile) : null;
        if ($profile === null || $resolver === null) {
            return null;
        }
        $rows = $this->rowsFor(self::brandKeyOf($profile));
        if ($rows === []) {
            return null;
        }

        return $resolver->pinFor($product, $profile, $rows);
    }

    /**
     * Wiersze marki w stałej kolejności (strona, id).
     *
     * @return list<ManufacturerPart>
     */
    public function rowsFor(string $brandKey): array
    {
        // max(fetched_at) zmienia --refresh; liczba wierszy — także usunięcie stron bez nowego pobrania
        $state = ManufacturerPart::query()->toBase()->where('brand_key', $brandKey)
            ->selectRaw('MAX(fetched_at) as fetched, COUNT(*) as total')->first();
        $stamp = (string) ($state->fetched ?? '').'|'.(int) ($state->total ?? 0);
        if (isset($this->rows[$brandKey]) && $this->rows[$brandKey]['stamp'] === $stamp) {
            return $this->rows[$brandKey]['rows'];
        }
        $rows = ManufacturerPart::query()
            ->where('brand_key', $brandKey)
            ->orderBy('page_url')
            ->orderBy('id')
            ->get()
            ->all();
        $this->rows[$brandKey] = ['stamp' => $stamp, 'rows' => array_values($rows)];

        return $this->rows[$brandKey]['rows'];
    }

    public function flush(): void
    {
        $this->rows = [];
        $this->resolvers = [];
    }

    /** Resolver z profilu albo null (brak klucza, klasa nie istnieje albo nie jest PartsTableResolver). */
    public function resolverFor(ManufacturerProfile $profile): ?PartsTableResolver
    {
        $class = $profile->resolver;
        if ($class === null || ! class_exists($class) || ! is_subclass_of($class, PartsTableResolver::class)) {
            return null;
        }

        return $this->resolvers[$class] ??= app($class);
    }

    /** Klucz marki wierszy manufacturer_parts: klucz wpisu profilu („coba”), bez wpisu — klucz marki karty. */
    public static function brandKeyOf(ManufacturerProfile $profile): string
    {
        return $profile->profileKey ?? $profile->brandKey;
    }
}
