<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Services\B2b\BolleDatasheetTable;
use PHPUnit\Framework\TestCase;
use RuntimeException;

/**
 * Tabela wersji z prawdziwych kart technicznych Bolle (tests/Fixtures/bolle/datasheet-*.pdf, pobrane z
 * b2b.bolle-safety.com 28.09.2026): kolumna STANDARD wiersza pozycji, bez oznaczeń oprawki z sąsiednich linii.
 */
final class BolleDatasheetTableTest extends TestCase
{
    /** @var array<string, list<array<string, mixed>>> */
    private static array $parsed = [];

    public function test_baxter_row_gives_standard_and_lens_marking_but_not_the_two_line_frame_marking(): void
    {
        $match = BolleDatasheetTable::match(self::rows('baxter'), 'BAXCSP', '3660740007768');

        $this->assertNotNull($match);
        // AI podawało „EN166, EN169” — karta techniczna: „EN166 - EN172”
        $this->assertSame(['EN166', 'EN172'], $match['norms']);
        $this->assertSame('5-1.4 1 BT K N', $match['lens']);
        $this->assertSame(2, $match['row']['page']);
        $this->assertSame('3660740007768', $match['row']['ean']);
        $this->assertStringContainsString('REFERENCE', $match['row']['block']);
        $this->assertStringContainsString('BAXCSP', $match['row']['block']);
        $this->assertStringContainsString('EN166 - EN172', $match['row']['block']);
        // „F : EN166 FT” nad i „B : EN166 3 4 5 BT” pod linią kodu — oznaczenie oprawki nie jest czytane
        $this->assertStringNotContainsString('EN166 3 4 5 BT', $match['row']['block']);

        // sąsiednie wiersze tej samej karty mają swoje normy
        $this->assertSame(['EN166', 'EN170'], BolleDatasheetTable::match(self::rows('baxter'), 'BAXPSI', '3660740007744')['norms'] ?? null);
    }

    public function test_ness_plus_drops_ukca_and_reference_with_size_suffix_needs_equal_ean(): void
    {
        $match = BolleDatasheetTable::match(self::rows('ness-plus'), 'PSSNESF028', '3660740011994');

        $this->assertSame(['EN166', 'EN170'], $match['norms'] ?? null);
        $this->assertSame('2C-1.2 1 FT KN', $match['lens'] ?? null);

        // komórka REFERENCE to „NESPSN10E S” (legenda karty: „S FOR SMALL SIZE”) — wiersz pozycji NESPSN10E tylko
        // przy równym EAN-ie; bez EAN-u pozycji albo z innym EAN-em dopisek mógłby oznaczać inny wyrób
        $this->assertContains('NESPSN10E S', array_column(self::rows('ness-plus'), 'reference'));
        $small = BolleDatasheetTable::match(self::rows('ness-plus'), 'NESPSN10E', '3660740018443');
        $this->assertSame(['EN166', 'EN170'], $small['norms'] ?? null);
        $this->assertNull(BolleDatasheetTable::match(self::rows('ness-plus'), 'NESPSN10E', null));
        $this->assertNull(BolleDatasheetTable::match(self::rows('ness-plus'), 'NESPSN10E', '3660740018450'));
        // sam równy EAN przy innym kodzie nie wystarcza
        $this->assertNull(BolleDatasheetTable::match(self::rows('ness-plus'), 'NESPSN1', '3660740018443'));
    }

    public function test_flash_welding_helmet_has_three_norms(): void
    {
        $match = BolleDatasheetTable::match(self::rows('flash'), 'FLASHV', '3660740007669');

        $this->assertSame(['EN166', 'EN175', 'EN379'], $match['norms'] ?? null);
        $this->assertSame('5-8/9-13 1/1/1/2/EN379', $match['lens'] ?? null);
    }

    public function test_volt_two_keeps_en_iso_norms_whole_and_row_without_standard_gives_nothing(): void
    {
        $match = BolleDatasheetTable::match(self::rows('volt2'), 'VOLT2N80W', '3660740026806');

        $this->assertSame(['EN ISO 16321-1', 'EN ISO 16321-2'], $match['norms'] ?? null);
        $this->assertSame('16321 W3/4-8/9-13 V2', $match['lens'] ?? null);
        // HEADGEAR: wiersz jest, normy w nim nie ma
        $this->assertNull(BolleDatasheetTable::match(self::rows('volt2'), 'VOLT2N03W', '3660740026837'));
    }

    public function test_tryon_rx_family_row_is_not_an_item_row(): void
    {
        // kolumna REFERENCE ma nazwę rodziny „TRYON”, a nie kod pozycji
        $this->assertNull(BolleDatasheetTable::match(self::rows('tryon-rx'), 'TRYONN10E', '3660740020132'));
        $this->assertSame(['TRYON'], array_column(self::rows('tryon-rx'), 'reference'));
    }

    public function test_rush_plus_two_with_size_column_parses(): void
    {
        $match = BolleDatasheetTable::match(self::rows('rush-plus2'), 'RUSPMN12E', '3660740023041');

        $this->assertSame(['EN ISO 16321-1'], $match['norms'] ?? null);
        $this->assertSame('UL1,2 CT 1 KN', $match['lens'] ?? null);
        $welding = BolleDatasheetTable::match(self::rows('rush-plus2'), 'RUSPMN80E', '3660740023287');
        $this->assertSame(['EN ISO 16321-1', 'EN ISO 16321-2'], $welding['norms'] ?? null);
        $this->assertSame('16321 W1,7 CT 1 KN', $welding['lens'] ?? null);
        // akcesoria pod tabelą — wiersz bez normy
        $this->assertNull(BolleDatasheetTable::match(self::rows('rush-plus2'), 'RUSXMN70E', null));
    }

    public function test_ean_must_match_when_both_sides_have_one(): void
    {
        $rows = self::rows('baxter');

        // EAN innej pozycji tej rodziny (BAXPSF)
        $this->assertNull(BolleDatasheetTable::match($rows, 'BAXCSP', '3660740007751'));
        // pozycja bez kodu kreskowego — sam kod wiersza
        $this->assertSame(['EN166', 'EN172'], BolleDatasheetTable::match($rows, 'BAXCSP', null)['norms'] ?? null);
        // ten sam GTIN z zerem wiodącym
        $this->assertNotNull(BolleDatasheetTable::match($rows, 'BAXCSP', '03660740007768'));
        // wielkość liter kodu ma znaczenie
        $this->assertNull(BolleDatasheetTable::match($rows, 'baxcsp', null));

        // wiersz bez EAN przechodzi na samym kodzie
        $withoutEan = [self::row('X1', 'EN166 - EN172', null)];
        $this->assertSame(['EN166', 'EN172'], BolleDatasheetTable::match($withoutEan, 'X1', '3660740007768')['norms'] ?? null);
    }

    public function test_ambiguous_or_conflicting_rows_give_nothing(): void
    {
        $this->assertNull(BolleDatasheetTable::match([self::row('X1', null, '3660740007768', ['standard'])], 'X1', null));
        $this->assertNull(BolleDatasheetTable::match([self::row('X1', 'EN166', '3660740007768', ['ean'])], 'X1', null));
        $this->assertNull(BolleDatasheetTable::match([
            self::row('X1', 'EN166 - EN170', null),
            self::row('X1', 'EN166 - EN172', null),
        ], 'X1', null));
        // dwa takie same wiersze — jeden odczyt
        $this->assertSame(['EN166'], BolleDatasheetTable::match([self::row('X1', 'EN166', null), self::row('X1', 'EN166', null)], 'X1', null)['norms'] ?? null);
        // niejednoznaczne oznaczenie soczewki odpada, normy zostają
        $lens = BolleDatasheetTable::match([self::row('X1', 'EN166', null, ['lens'])], 'X1', null);
        $this->assertNotNull($lens);
        $this->assertSame(['EN166'], $lens['norms']);
        $this->assertNull($lens['lens']);
    }

    public function test_standard_cell_split_keeps_only_whole_norm_codes(): void
    {
        $this->assertSame(['EN166', 'EN172'], BolleDatasheetTable::norms('EN166 - EN172'));
        $this->assertSame(['EN166', 'EN170'], BolleDatasheetTable::norms('EN166 -EN170'));
        $this->assertSame(['EN166', 'EN170'], BolleDatasheetTable::norms('EN166 - EN170 - UKCA'));
        $this->assertSame(['EN 166', 'EN 170'], BolleDatasheetTable::norms('EN 166 / EN 170'));
        $this->assertSame(['EN ISO 16321-1', 'EN ISO 16321-2'], BolleDatasheetTable::norms('EN ISO 16321-1 - EN ISO 16321-2'));
        $this->assertSame(['EN 166:2001'], BolleDatasheetTable::norms('EN 166:2001'));

        // dwie normy bez separatora, znak spoza norm bez separatora, sam znak — nieczytelne
        $this->assertNull(BolleDatasheetTable::norms('EN166 EN170'));
        $this->assertNull(BolleDatasheetTable::norms('EN166 - ANSI Z87.1'));
        $this->assertNull(BolleDatasheetTable::norms('EN166 FT'));
        $this->assertNull(BolleDatasheetTable::norms('UKCA'));
        $this->assertNull(BolleDatasheetTable::norms(''));
    }

    public function test_not_a_pdf_is_an_error(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('nie jest PDF');
        BolleDatasheetTable::parse('<html>403 Forbidden</html>');
    }

    /**
     * @return list<array<string, mixed>>
     */
    private static function rows(string $name): array
    {
        return self::$parsed[$name] ??= BolleDatasheetTable::parse(
            (string) file_get_contents(dirname(__DIR__).'/Fixtures/bolle/datasheet-'.$name.'.pdf')
        );
    }

    /**
     * @param  list<string>  $ambiguous
     * @return array{page: int, reference: string, lens: string|null, standard: string|null, ean: string|null, ambiguous: list<string>, block: string}
     */
    private static function row(string $reference, ?string $standard, ?string $ean, array $ambiguous = []): array
    {
        return [
            'page' => 1,
            'reference' => $reference,
            'lens' => '2C-1.2 1 FT',
            'standard' => $standard,
            'ean' => $ean,
            'ambiguous' => $ambiguous,
            'block' => $reference.' | '.$standard,
        ];
    }
}
