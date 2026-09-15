<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Services\B2b\UvexB2bConnector;
use App\Services\B2b\UvexSizeGroups;
use PHPUnit\Framework\TestCase;

/**
 * Reguła scalania rozmiarów UVEX na prawdziwych pozycjach listy konta (15.09.2026). Ostatnia kolumna
 * tests/Fixtures/uvex/size_groups.tsv to kod pierwszej pozycji grupy, do której pozycja trafiła przy analizie pełnej
 * listy (5750 pozycji) — łącznik ma dać ten sam podział.
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

    public function test_same_price_sizes_merge_and_other_price_splits_off(): void
    {
        $groups = $this->codes((new UvexSizeGroups)->group([
            $this->row('6935/2/40', 'Trzewik uvex 2 trend 6935/2/40', 36040),
            $this->row('6935/2/38', 'Trzewik uvex 2 trend 6935/2/38', 35870),
            $this->row('6935/2/39', 'Trzewik uvex 2 trend 6935/2/39', 36040),
        ]));

        $this->assertSame([['6935/2/38'], ['6935/2/39', '6935/2/40']], $groups);
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
