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
        private readonly LevelChecker $levels,
        FeatureFlagChecker $flags,
        ColorChecker $color,
    ) {
        $this->checkers = [$dimensions, $levels, $flags, $color];
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
