<?php

declare(strict_types=1);

namespace App\Support\RequirementCheck;

use App\Models\Product;
use App\Support\ManufacturerNormFacts;

/**
 * Porównanie parametrów wymagania przetargowego z kartą produktu do okna „Weryfikacja karty”.
 * Tylko wyświetlanie: bramki dopasowania świadomie przepuszczają brak danych i biorą poziomy
 * z innych pól, więc porównanie może pokazać ✗ tam, gdzie dopasowanie kartę przepuściło.
 */
final class RequirementCheck
{
    private const GROUP_LABELS = [
        'dimensions' => 'Wymiary',
        'levels' => 'Poziomy i klasy',
        'flags' => 'Cechy',
        'color' => 'Kolor',
        'package' => 'Opakowanie',
    ];

    /** @var list<ParameterChecker> */
    private array $checkers;

    public function __construct(
        DimensionChecker $dimensions,
        private readonly LevelChecker $levels,
        FeatureFlagChecker $flags,
        ColorChecker $color,
        PackageChecker $package = new PackageChecker,
    ) {
        $this->checkers = [$dimensions, $levels, $flags, $color, $package];
    }

    /**
     * @return array{
     *     groups: list<array{key: string, label: string, rows: list<array<string, mixed>>}>,
     *     conflicts: array{count: int, requirement: list<string>, card_fields: list<array<string, mixed>>}
     * }
     */
    public function compare(string $requirement, Product $product): array
    {
        $sources = CardSources::fromProduct($product);
        $groups = [];
        $allRows = [];
        foreach ($this->checkers as $checker) {
            $rows = $checker->check($requirement, $sources);
            if ($rows === []) {
                continue;
            }
            array_push($allRows, ...$rows);
            $groups[] = [
                'key' => $checker->group(),
                'label' => self::GROUP_LABELS[$checker->group()] ?? $checker->group(),
                'rows' => array_map(static fn (CheckRow $row): array => $row->toArray(), $rows),
            ];
        }

        return [
            'groups' => $groups,
            'conflicts' => ConflictSummary::build($allRows, $this->cardConflicts($sources)),
            'manufacturer_source' => self::manufacturerSource($product),
        ];
    }

    /**
     * Skąd są normy producenta (pola „producent” w porównaniu) — okno sprzeczności pokazuje przy nich adres karty
     * producenta i rodzaj źródła, żeby „producent podaje X” dało się sprawdzić jednym kliknięciem.
     *
     * @return array{url: ?string, connector: ?string, synced_at: ?string, verified: bool}|null
     */
    private static function manufacturerSource(Product $product): ?array
    {
        $column = $product->manufacturer_norms;
        if (ManufacturerNormFacts::rows($column) === [] || ! is_array($column)) {
            return null;
        }
        $source = is_array($column['source'] ?? null) ? $column['source'] : [];

        return [
            'url' => ManufacturerNormFacts::sourceUrl($column),
            'connector' => is_string($source['connector'] ?? null) ? $source['connector'] : null,
            'synced_at' => is_string($source['synced_at'] ?? null) ? $source['synced_at'] : null,
            'verified' => ManufacturerNormFacts::verified($column),
        ];
    }

    /**
     * Sprzeczności między polami karty, także w parametrach, o które przetarg nie pyta. Na razie tylko poziomy
     * z LevelChecker — cechy tak/nie w pomiarze dawały same fałszywe alarmy.
     *
     * @param  list<CardSource>  $sources
     * @return list<CardConflict>
     */
    private function cardConflicts(array $sources): array
    {
        return $this->levels->cardConflicts($sources);
    }
}
