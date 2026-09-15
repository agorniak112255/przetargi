<?php

declare(strict_types=1);

namespace App\Support\RequirementCheck;

use App\Models\Product;

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
    ];

    /** @var list<ParameterChecker> */
    private array $checkers;

    public function __construct(
        DimensionChecker $dimensions,
        LevelChecker $levels,
        FeatureFlagChecker $flags,
        ColorChecker $color,
    ) {
        $this->checkers = [$dimensions, $levels, $flags, $color];
    }

    /**
     * @return array{groups: list<array{key: string, label: string, rows: list<array<string, mixed>>}>}
     */
    public function compare(string $requirement, Product $product): array
    {
        $sources = CardSources::fromProduct($product);
        $groups = [];
        foreach ($this->checkers as $checker) {
            $rows = $checker->check($requirement, $sources);
            if ($rows === []) {
                continue;
            }
            $groups[] = [
                'key' => $checker->group(),
                'label' => self::GROUP_LABELS[$checker->group()] ?? $checker->group(),
                'rows' => array_map(static fn (CheckRow $row): array => $row->toArray(), $rows),
            ];
        }

        return ['groups' => $groups];
    }
}
