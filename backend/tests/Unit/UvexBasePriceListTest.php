<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Services\B2b\UvexBasePriceList;
use Carbon\CarbonImmutable;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use PHPUnit\Framework\TestCase;
use RuntimeException;

/**
 * Cennik bazowy UVEX na pliku zbudowanym w teście, w układzie pliku z konta (22.09.2026): arkusz na grupę wyrobów,
 * kod w A, nazwa w B (w „Buty Heckel” seria B + model C), cena w kolumnie z „KATALOG” w nagłówku. Pary kodów sklep ↔
 * cennik to przypadki sprawdzone na koncie.
 */
final class UvexBasePriceListTest extends TestCase
{
    private const FILE = 'cenniki USPL od 1 X 25 ver.2.xlsx';

    public function test_every_verified_code_pattern_finds_its_row(): void
    {
        $list = $this->list();

        $this->assertSame(['sheet' => 'Hełmy', 'code' => '9705014', 'name' => 'Hełm uvex pheos B-WR', 'price' => 120.5], $list->match(['9705.014']));
        $this->assertSame(['sheet' => 'Ochrona wzroku', 'code' => '2112003', 'name' => 'Okulary uvex i-5', 'price' => 80.0], $list->match(['2112.003']));
        // rękawice: dwucyfrowy rozmiar na końcu kodu sklepu; długi ułamek ceny zaokrąglony do groszy
        $this->assertSame(['sheet' => 'Rękawice uvex', 'code' => '60023', 'name' => 'Rękawice uvex unidur 6643', 'price' => 916.85], $list->match(['6002306']));
        $this->assertSame('65902', $list->match(['6590/2/35'])['code'] ?? null);
        // Heckel: nazwa z serii i modelu, cena z kolumny D
        $this->assertSame(
            ['sheet' => 'Buty Heckel', 'code' => '62733', 'name' => 'Suxxeed Offroad SUXXEED OFFROAD HIGH S3', 'price' => 255.31],
            $list->match(['HECKEL6273/3/37']),
        );
    }

    public function test_hexarmor_codes_match_by_the_model_token_in_the_row_name(): void
    {
        $list = $this->list();

        $this->assertSame('60101', $list->match(['HA2039(L)'])['code'] ?? null);
        $this->assertSame('60102', $list->match(['HA3041NSR(M)'])['code'] ?? null);
        $this->assertSame('60105', $list->match(['HA3013IMP (L)'])['code'] ?? null);
        $this->assertSame('60104', $list->match(['HAAG10009S(L)'])['code'] ?? null);
        $this->assertSame('60103', $list->match(['HAAP322'])['code'] ?? null);
        $this->assertSame('60106', $list->match(['HA3023/8'])['code'] ?? null);
    }

    public function test_hexarmor_digits_fallback_only_when_the_full_model_is_not_in_any_row(): void
    {
        $list = $this->list();

        // „4072WX” nie ma w nazwach — ponownie po „4072”, a ten jest w jednym wierszu
        $this->assertSame('60108', $list->match(['HA4072WX(9)'])['code'] ?? null);
        // „2039” jako część innej liczby (20390) nie jest całym słowem
        $this->assertNull($list->match(['HA203(L)']));
    }

    public function test_ambiguous_rows_give_no_match(): void
    {
        $list = $this->list();

        // model „4021” w dwóch wierszach HexArmor
        $this->assertNull($list->match(['HA4021(L)']));
        // ten sam kod w dwóch arkuszach z różnymi cenami
        $this->assertNull($list->match(['11111']));
        // ten sam kod powtórzony z tą samą ceną w tym samym arkuszu — to ten sam wiersz
        $this->assertSame('22222', $list->match(['22222'])['code'] ?? null);

        $this->assertSame(2, $list->counters()['conflicts']);
    }

    public function test_excluded_sheets_are_not_loaded_at_all(): void
    {
        $list = $this->list();

        $this->assertNull($list->match(['77777']));
        $this->assertNotContains('Odzież', $list->sheets());
        $this->assertNotContains('Ogólne', $list->sheets());
        $this->assertSame(['Bez ceny (brak kolumny „KATALOG” w nagłówku)'], $list->skippedSheets());
    }

    public function test_size_rules_apply_only_in_their_own_sheets(): void
    {
        $list = $this->list();

        // 12345 jest w „Hełmy”, nie w „Rękawice…” — dwie ostatnie cyfry nie są tam rozmiarem
        $this->assertNull($list->match(['1234567']));
        // 65912 jest w „Hełmy”, nie w „Buty…”
        $this->assertNull($list->match(['6591/2/40']));
        // dokładny kod działa w każdym arkuszu
        $this->assertSame('12345', $list->match(['12345'])['code'] ?? null);
    }

    public function test_card_members_must_resolve_to_one_row(): void
    {
        $list = $this->list();

        $this->assertSame('65902', $list->match(['6590/2/35', '6590/2/36', 'NIEZNANY-1'])['code'] ?? null);
        $this->assertNull($list->match(['9705.014', '2112.003']));
        $this->assertNull($list->match(['NIEZNANY-2']));

        $this->assertSame(
            ['matched' => 1, 'partial' => 1, 'missing' => 1, 'conflicts' => 1, 'conflict_examples' => ['9705.014']],
            $list->counters(),
        );
    }

    public function test_source_names_file_sheet_row_and_download_date(): void
    {
        $list = $this->list();
        $row = $list->match(['HECKEL6273/3/37']);

        $this->assertNotNull($row);
        $this->assertSame(
            self::FILE.' · arkusz Buty Heckel · Suxxeed Offroad SUXXEED OFFROAD HIGH S3 · pobrano 2026-09-22',
            $list->sourceOf($row),
        );
    }

    public function test_file_without_any_priced_row_is_an_error_not_an_empty_list(): void
    {
        $this->expectException(RuntimeException::class);

        UvexBasePriceList::fromXlsx(self::workbook([
            'Ogólne' => [],
            'Odzież' => [['Kod', 'Nazwa', 'CENA KATALOGOWA'], [77777, 'Kurtka', 500]],
        ]), self::FILE, CarbonImmutable::parse('2026-09-22'));
    }

    public function test_unreadable_bytes_throw(): void
    {
        $this->expectException(\Throwable::class);

        UvexBasePriceList::fromXlsx('to nie jest xlsx', self::FILE, CarbonImmutable::parse('2026-09-22'));
    }

    /**
     * Arkusze w układzie cennika UVEX (22.09.2026).
     *
     * @return array<string, list<list<mixed>>>
     */
    public static function sheets(): array
    {
        $header = ['Kod', 'Nazwa', 'CENA KATALOGOWA NETTO PLN'];

        return [
            'Ogólne' => [],
            'Hełmy' => [
                $header,
                [9705014, 'Hełm uvex pheos B-WR', 120.5],
                [11111, 'Wyrób A', 10],
                [12345, 'Nie rękawica', 5],
                [65912, 'Nie but', 6],
                [22222, 'Powtórzony', 7],
                [22222, 'Powtórzony', 7],
            ],
            // nagłówek kodu z „katalog” w nazwie nie jest kolumną ceny
            'Ochrona wzroku' => [['Nr katalogowy', 'Nazwa', 'CENA KATALOGOWA'], ['2112003', 'Okulary uvex i-5', 80]],
            'Ochrona słuchu' => [$header, [11111, 'Wyrób B', 12]],
            'Rękawice uvex' => [$header, [60023, 'Rękawice uvex unidur 6643', 916.8525000000001]],
            'Rękawice HEXArmor' => [
                $header,
                [60101, 'HexArmor Rig Lizard® 2039', 200],
                [60102, 'HexArmor Hercules NSR 3041', 150],
                [60103, 'HexArmor fartuch AP322', 300],
                [60104, 'HexArmor zarękawek AG10009S', 50],
                [60105, 'HexArmor Helix® 3013IMP', 80],
                [60106, 'HexArmor Chrome 3023', 90],
                [60107, 'HexArmor Chrome 4021', 10],
                [60108, 'HexArmor Hex1™ 4072', 70],
                [60109, 'HexArmor Chrome 4021 SL', 11],
                [60110, 'HexArmor Mechanics 20390', 33],
            ],
            'Buty Heckel' => [
                ['Kod', 'Seria', 'Model', 'KATALOG EUR→PLN'],
                [62733, 'Suxxeed Offroad', 'SUXXEED OFFROAD HIGH S3', 255.30601],
            ],
            'Buty Uvex' => [$header, [65902, 'Półbuty uvex 1 sport', 400]],
            'Bez ceny' => [['Kod', 'Nazwa', 'Uwagi'], [99999, 'Coś', 'x']],
            'Odzież' => [$header, [77777, 'Kurtka uvex suXXeed', 500]],
        ];
    }

    /**
     * @param  array<string, list<list<mixed>>>  $sheets
     */
    public static function workbook(array $sheets): string
    {
        $book = new Spreadsheet;
        $book->removeSheetByIndex(0);
        foreach ($sheets as $title => $rows) {
            $sheet = $book->createSheet();
            $sheet->setTitle($title);
            if ($rows !== []) {
                $sheet->fromArray($rows, null, 'A1', true);
            }
        }
        $writer = new Xlsx($book);
        ob_start();
        $writer->save('php://output');

        return (string) ob_get_clean();
    }

    private function list(): UvexBasePriceList
    {
        return UvexBasePriceList::fromXlsx(self::workbook(self::sheets()), self::FILE, CarbonImmutable::parse('2026-09-22'));
    }
}
