<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Services\B2b\UvexB2bConnector;
use App\Services\B2b\UvexSizeGroups;
use PHPUnit\Framework\TestCase;

/**
 * Reguła scalania rozmiarów UVEX na prawdziwych pozycjach listy konta (15.09.2026). Ostatnia kolumna
 * tests/Fixtures/uvex/size_groups.tsv to kod pierwszej pozycji grupy, do której pozycja trafiła przy analizie pełnej
 * listy (5750 pozycji) — wtedy z ceną w kluczu grupy (decyzja 15.09.2026). Od decyzji użytkownika 28.09.2026 cena nie
 * dzieli rozmiarów na karty; pozycje, których grupa zmieniła się przez to w tej próbce, są wypisane w teście wprost.
 */
final class UvexSizeGroupsTest extends TestCase
{
    public function test_real_rows_are_grouped_exactly_as_in_the_full_list_analysis(): void
    {
        $rows = [];
        $expected = [];
        foreach (file(__DIR__.'/../Fixtures/uvex/size_groups.tsv', FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [] as $line) {
            [$code, $name, $price, $unit, $groupFirst] = explode("\t", $line);
            $rows[] = ['code' => $code, 'name' => $name, 'price_cents' => UvexB2bConnector::priceCents($price), 'unit' => $unit];
            // „#” — kody czysto liczbowe (1723808) nie stają się kluczami int, ksort porządkuje wtedy stabilnie
            $expected['#'.$code] = $groupFirst;
        }
        $this->assertCount(111, $rows);

        // decyzja 28.09.2026: rozmiary jednego modelu w innej cenie dołączają do grupy modelu (jedna karta z tabelą
        // rozmiarów) — trzewik 6935/2 (38 za 358,70, 39 i 40 za 360,40) i HexArmor 2023 (M, L za 189,00, XXL i XXXL
        // za 193,20). Poza tymi dwoma modelami podział próbki bez zmian.
        $changed = [
            '6935/2/39' => '6935/2/38',
            '6935/2/40' => '6935/2/38',
            'HA2023(XXL)' => 'HA2023(L)',
            'HA2023(XXXL)' => 'HA2023(L)',
        ];
        foreach ($changed as $code => $groupFirst) {
            $this->assertArrayHasKey('#'.$code, $expected);
            $this->assertNotSame($groupFirst, $expected['#'.$code], 'pozycja '.$code.' miała już tę grupę — lista zmian nieaktualna');
            $expected['#'.$code] = $groupFirst;
        }

        $actual = [];
        foreach ((new UvexSizeGroups)->group($rows) as $group) {
            foreach ($group as $member) {
                $actual['#'.$member['row']['code']] = $group[0]['row']['code'];
            }
        }

        ksort($expected, SORT_STRING);
        ksort($actual, SORT_STRING);
        $this->assertSame($expected, $actual);
    }

    /**
     * Do 28.09.2026 (decyzja 15.09.2026) rozmiar w innej cenie był osobną grupą: [[38], [39, 40]]. Od decyzji
     * użytkownika 28.09.2026 rozmiary jednego modelu w różnych cenach to jedna grupa (jedna karta, cena każdego
     * rozmiaru w tabeli rozmiarów karty).
     */
    public function test_sizes_in_different_prices_merge_into_one_group(): void
    {
        $groups = $this->codes((new UvexSizeGroups)->group([
            $this->row('6935/2/40', 'Trzewik uvex 2 trend 6935/2/40', 36040),
            $this->row('6935/2/38', 'Trzewik uvex 2 trend 6935/2/38', 35870),
            $this->row('6935/2/39', 'Trzewik uvex 2 trend 6935/2/39', 36040),
        ]));

        $this->assertSame([['6935/2/38', '6935/2/39', '6935/2/40']], $groups);
    }

    /**
     * Bez ceny w kluczu zabezpieczenia grupy dalej rozdzielają różne wyroby: dwa modele o tej samej nazwie (powtórzony
     * rozmiar) — podział po rdzeniu kodu, także gdy mają różne ceny; kody z kropką (2600.010 / 2600.011 — kolory,
     * 9183.041 / 9183.043 — klasy) nie mają rozmiaru, więc zostają osobno mimo tej samej nazwy.
     */
    public function test_other_models_stay_apart_without_the_price_in_the_key(): void
    {
        $groups = $this->codes((new UvexSizeGroups)->group([
            $this->row('6823/2/40', 'Trzewik uvex testowy', 30000),
            $this->row('6823/2/41', 'Trzewik uvex testowy', 30000),
            $this->row('6824/2/40', 'Trzewik uvex testowy', 34000),
            $this->row('6824/2/41', 'Trzewik uvex testowy', 34000),
            $this->row('2600.010', 'Okulary uvex testowe', 5000, 'szt.'),
            $this->row('2600.011', 'Okulary uvex testowe', 5200, 'szt.'),
            $this->row('9183.041', 'Okulary uvex klasa', 6000, 'szt.'),
            $this->row('9183.043', 'Okulary uvex klasa', 6000, 'szt.'),
        ]));

        $this->assertSame([
            ['2600.010'], ['2600.011'], ['6823/2/40', '6823/2/41'], ['6824/2/40', '6824/2/41'], ['9183.041'], ['9183.043'],
        ], $groups);
    }

    public function test_rows_without_size_or_price_or_with_different_unit_stay_separate(): void
    {
        $groups = $this->codes((new UvexSizeGroups)->group([
            $this->row('XG20/7', 'Rękawice Profi XG20/7', 1890),
            $this->row('XG20/8', 'Rękawice Profi XG20/8', 1890, 'szt.'),
            $this->row('XG20/9', 'Rękawice Profi XG20/9', null),
            $this->row('9198.015', 'Okulary pheos cx2 9198.015', 5000),
        ]));

        $this->assertSame([['9198.015'], ['XG20/7'], ['XG20/8'], ['XG20/9']], $groups);
    }

    public function test_group_name_drops_size_and_shortens_code_with_size_to_its_stem(): void
    {
        $groups = new UvexSizeGroups;

        $this->assertSame('Półbuty ochronne uvex 1 business 8430/2', $groups->groupName(
            'Półbuty ochronne uvex 1 business 8430/2/39', '8430/2/39', (array) $groups->sizeOf('Półbuty ochronne uvex 1 business 8430/2/39', '8430/2/39'),
        ));
        $this->assertSame('HECKEL 6273/3 SUXXED OFFROAD HIGH S3', $groups->groupName(
            'HECKEL 6273/3/36 SUXXED OFFROAD HIGH S3', 'HECKEL6273/3/36', (array) $groups->sizeOf('HECKEL 6273/3/36 SUXXED OFFROAD HIGH S3', 'HECKEL6273/3/36'),
        ));
        $this->assertSame('Rękawice HexArmor Rig Lizard Arctic 2023', $groups->groupName(
            'Rękawice HexArmor Rig Lizard Arctic 2023 rozmiar 9 (L)', 'HA2023(L)', (array) $groups->sizeOf('Rękawice HexArmor Rig Lizard Arctic 2023 rozmiar 9 (L)', 'HA2023(L)'),
        ));
        $this->assertSame('Koszulka polo uvex fire & arc 17238', $groups->groupName(
            'Koszulka polo uvex fire & arc 17238 rozm. XS', '1723808', (array) $groups->sizeOf('Koszulka polo uvex fire & arc 17238 rozm. XS', '1723808'),
        ));
        $this->assertSame('Rękawice C300 Wet', $groups->groupName(
            'Rękawice C300 Wet/10', '60542/10', (array) $groups->sizeOf('Rękawice C300 Wet/10', '60542/10'),
        ));
    }

    /**
     * Decyzja użytkownika 30.09.2026: kod karty butów i rękawic bez rozmiaru. Kod modelu tylko wtedy, gdy rozmiar każdej
     * pozycji stoi na końcu kodu, a rdzeń nie jest kodem pozycji ani rdzeniem innej grupy — inaczej kod pierwszej pozycji.
     */
    public function test_model_code_is_the_common_stem_only_when_every_size_ends_the_code_and_the_stem_is_unique(): void
    {
        $groups = new UvexSizeGroups;
        $grouped = $groups->group([
            $this->row('6931/2/35', 'Półbut uvex 2 trend 6931/2/35', 30600),
            $this->row('6931/2/36', 'Półbut uvex 2 trend 6931/2/36', 30600),
            $this->row('NB60SZ/9', 'Rękawice Rubiflex S długie NB60SZ/9', 11130),
            $this->row('NB60SZ/10', 'Rękawice Rubiflex S długie NB60SZ/10', 11130),
            $this->row('1723808', 'Koszulka polo uvex fire & arc 17238 rozm. XS', 20000, 'szt.'),
            $this->row('1723809', 'Koszulka polo uvex fire & arc 17238 rozm. S', 20000, 'szt.'),
            $this->row('9970.005', 'Pojemnik mini 9970.005', 5000, 'szt.'),
            // rdzeń jest kodem innej pozycji listy
            $this->row('7777/2', 'Wkładki uvex testowe', 3000),
            $this->row('7777/2/40', 'Trzewik uvex testowy 7777/2/40', 30000),
            $this->row('7777/2/41', 'Trzewik uvex testowy 7777/2/41', 30000),
            // ten sam rdzeń w dwóch grupach (inne nazwy) — żadna nie dostaje kodu modelu
            $this->row('6823/2/40', 'Trzewik uvex A', 30000),
            $this->row('6823/2/41', 'Trzewik uvex A', 30000),
            $this->row('6823/2/42', 'Trzewik uvex B', 30000),
            $this->row('6823/2/43', 'Trzewik uvex B', 30000),
        ]);
        $byFirstCode = array_combine(
            array_map(static fn (array $group): string => $group[0]['row']['code'], $grouped),
            $groups->modelCodes($grouped),
        );

        $this->assertSame('6931/2', $byFirstCode['6931/2/35']);
        $this->assertSame('NB60SZ', $byFirstCode['NB60SZ/9']);
        $this->assertNull($byFirstCode['1723808']);
        $this->assertNull($byFirstCode['9970.005']);
        $this->assertNull($byFirstCode['7777/2/40']);
        $this->assertNull($byFirstCode['6823/2/40']);
        $this->assertNull($byFirstCode['6823/2/42']);
    }

    public function test_sizes_sort_numbers_then_letters(): void
    {
        $sizes = ['XL', '10', 'S', '7', '3XL', 'M', '042', '8.5'];
        usort($sizes, UvexSizeGroups::compareSizes(...));

        $this->assertSame(['7', '8.5', '10', '042', 'S', 'M', 'XL', '3XL'], $sizes);
    }

    /**
     * @return array{code: string, name: string, price_cents: int|null, unit: string}
     */
    private function row(string $code, string $name, ?int $cents, string $unit = 'par.'): array
    {
        return ['code' => $code, 'name' => $name, 'price_cents' => $cents, 'unit' => $unit];
    }

    /**
     * @param  list<list<array{row: array{code: string}}>>  $groups
     * @return list<list<string>>
     */
    private function codes(array $groups): array
    {
        return array_map(static fn (array $group): array => array_map(static fn (array $m): string => $m['row']['code'], $group), $groups);
    }
}
