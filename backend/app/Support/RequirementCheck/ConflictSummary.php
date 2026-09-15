<?php

declare(strict_types=1);

namespace App\Support\RequirementCheck;

/**
 * Sekcja `conflicts` odpowiedzi porównania — źródło przycisku „Sprzeczności (N)”. Dwa rodzaje:
 * karta wprost nie spełnia wymagania (klucze wierszy fail; szczegóły frontend bierze z `groups`,
 * żeby ich nie dublować) oraz pola tej samej karty sobie przeczą. Liczone regułami — model
 * na żądanie ma osobny endpoint i nie wlicza się do N.
 */
final class ConflictSummary
{
    private const VERDICT_VALUES = [
        'ok' => 'spełnia',
        'fail' => 'nie spełnia',
    ];

    /**
     * Sprzeczności pól karty to: wiersze, w których jedno pole spełnia wymaganie, a drugie wprost nie (ARMEN: nazwa
     * „S1 P” ok, specyfikacja „Klasa ochrony: S1” fail), oraz sprzeczności z samej karty ($cardConflicts, także dla
     * parametrów spoza wymagania). Ten sam klucz z obu źródeł to jeden wpis; `row` wskazuje wiersz, gdy przetarg
     * pyta o parametr.
     *
     * @param  list<CheckRow>  $rows
     * @param  list<CardConflict>  $cardConflicts
     * @return array{count: int, requirement: list<string>, card_fields: list<array<string, mixed>>}
     */
    public static function build(array $rows, array $cardConflicts): array
    {
        $requirement = [];
        $rowKeys = [];
        /** @var array<string, CardConflict> $entries */
        $entries = [];
        foreach ($rows as $row) {
            $rowKeys[$row->key] = true;
            if ($row->status === Status::Fail) {
                $requirement[] = $row->key;
            }
            $conflict = self::fromRow($row);
            if ($conflict !== null) {
                $entries[$row->key] ??= $conflict;
            }
        }
        $requirement = array_values(array_unique($requirement));

        foreach ($cardConflicts as $conflict) {
            $existing = $entries[$conflict->key] ?? null;
            $entries[$conflict->key] = $existing === null
                ? new CardConflict($conflict->key, $conflict->label, $conflict->values, isset($rowKeys[$conflict->key]) ? $conflict->key : null)
                : new CardConflict($existing->key, $existing->label, self::mergeValues($existing->values, $conflict->values), $existing->row);
        }

        return [
            'count' => count(array_unique([...$requirement, ...array_keys($entries)])),
            'requirement' => $requirement,
            'card_fields' => array_values(array_map(static fn (CardConflict $c): array => $c->toArray(), $entries)),
        ];
    }

    /**
     * Znaleziska ok i fail pogrupowane po wartości. Znaleziska „do sprawdzenia” (warianty „I/II/III”, inny zapis) nie
     * są stroną sprzeczności — pomijamy je.
     */
    private static function fromRow(CheckRow $row): ?CardConflict
    {
        $verdicts = array_column($row->card, 'verdict');
        if (! in_array(Status::Ok->value, $verdicts, true) || ! in_array(Status::Fail->value, $verdicts, true)) {
            return null;
        }
        $grouped = [];
        foreach ($row->card as $finding) {
            if (isset(self::VERDICT_VALUES[$finding['verdict']])) {
                $grouped[self::value($finding)][] = $finding;
            }
        }
        if (count($grouped) < 2) {
            return null;
        }

        return new CardConflict($row->key, $row->label, self::values($grouped), $row->key);
    }

    /**
     * Wartość znaleziska z pól, które wystawiają checkery: `value` (poziomy), `code` (EN 388/407), `colors`, wymiary
     * w mm; cechy tak/nie nie mają wartości — wtedy werdykt („spełnia” / „nie spełnia”).
     *
     * @param  array<string, mixed>  $finding
     */
    private static function value(array $finding): string
    {
        return match (true) {
            isset($finding['value']) => (string) $finding['value'],
            isset($finding['code']) => (string) $finding['code'],
            is_array($finding['colors'] ?? null) && $finding['colors'] !== [] => implode(', ', $finding['colors']),
            isset($finding['value_mm']) => $finding['value_mm'].' mm',
            isset($finding['min_mm'], $finding['max_mm']) => $finding['min_mm'].'–'.$finding['max_mm'].' mm',
            is_array($finding['values_mm'] ?? null) => implode(' × ', $finding['values_mm']).' mm',
            default => self::VERDICT_VALUES[$finding['verdict']] ?? (string) $finding['verdict'],
        };
    }

    /**
     * Wartości z porównania najpierw, dopisane te z samej karty; to samo znalezisko (pole i zapis) raz.
     *
     * @param  list<array{value: string, findings: list<array<string, mixed>>}>  $first
     * @param  list<array{value: string, findings: list<array<string, mixed>>}>  $second
     * @return list<array{value: string, findings: list<array<string, mixed>>}>
     */
    private static function mergeValues(array $first, array $second): array
    {
        $grouped = [];
        foreach ([...$first, ...$second] as $value) {
            foreach ($value['findings'] as $finding) {
                $grouped[$value['value']][$finding['source']."\0".$finding['text']] ??= $finding;
            }
        }

        return self::values(array_map('array_values', $grouped));
    }

    /**
     * @param  array<string|int, list<array<string, mixed>>>  $grouped
     * @return list<array{value: string, findings: list<array<string, mixed>>}>
     */
    private static function values(array $grouped): array
    {
        $out = [];
        foreach ($grouped as $value => $findings) {
            // klucz tablicy „28” PHP zamienia na int
            $out[] = ['value' => (string) $value, 'findings' => $findings];
        }

        return $out;
    }
}
