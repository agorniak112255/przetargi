<?php

declare(strict_types=1);

namespace App\Support\RequirementCheck;

use App\Models\Product;
use App\Support\ManufacturerNormFacts;
use App\Support\ProductVariantFacts;

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
        private readonly ?ProductVariantFacts $variants = null,
    ) {
        $this->checkers = [$dimensions, $levels, $flags, $color, $package];
    }

    /**
     * @param  bool  $withVariants  etykiety wariantów karty do porównania koloru; false tam, gdzie liczą się tylko
     *                              sprzeczności (kolor nigdy nie daje „nie spełnia”, więc warianty ich nie zmieniają)
     * @return array{
     *     groups: list<array{key: string, label: string, rows: list<array<string, mixed>>}>,
     *     conflicts: array{count: int, requirement: list<string>, card_fields: list<array<string, mixed>>}
     * }
     */
    public function compare(string $requirement, Product $product, bool $withVariants = true): array
    {
        $sources = CardSources::fromProduct($product);
        $variantSources = $withVariants ? $this->variantSources($requirement, $product) : [];
        $groups = [];
        $allRows = [];
        foreach ($this->checkers as $checker) {
            // warianty tylko do koloru — pozostałe porównania i sprzeczności karty bez zmian
            $rows = $checker->check($requirement, $checker instanceof ColorChecker && $variantSources !== []
                ? [...$sources, ...$variantSources]
                : $sources);
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
     * Po jednym fragmencie na etykietę aktywnego wariantu karty. Tylko przy wymaganiu z barwą (inaczej kolor
     * nie ma czego porównać) i dla karty z bazy — niezapisana karta (podgląd, atrapa) wierszy wariantów nie ma.
     *
     * @return list<CardSource>
     */
    private function variantSources(string $requirement, Product $product): array
    {
        if ($this->variants === null || ! $product->exists || ColorChecker::requiredColours($requirement) === []) {
            return [];
        }
        $out = [];
        $seen = [];
        foreach ($this->variants->activeRows($product) as $row) {
            $label = trim($row['label']);
            $key = mb_strtolower($label);
            if ($label === '' || isset($seen[$key])) {
                continue;
            }
            $seen[$key] = true;
            $out[] = new CardSource(CardSource::VARIANT, $label);
        }

        return $out;
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
