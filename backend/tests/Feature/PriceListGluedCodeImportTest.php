<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\PriceListImport;
use App\Models\Product;
use App\Models\ProductIdentifier;
use App\Models\User;
use App\Services\PriceListImportService;
use App\Services\SpreadsheetColumnMapper;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Queue;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use Tests\TestCase;

/**
 * Kody z „rozmiarem” doklejonym na końcu (Coba AF010005, DP010004, HI010004, 620010X10, 650010X50RBM) to wymiary
 * mat, moduły i opakowania, nie rozmiary — import z 12.09.2026 uciął 167 kodów Coby i zlał pozycje o tej samej
 * cenie. Wiersze pochodzą z cennika „PLN - COBA Price Book 2026.xlsx” (przycięty arkusz w pamięci); AF010006 dopisany,
 * żeby pokazać dwa wymiary w tej samej cenie.
 */
final class PriceListGluedCodeImportTest extends TestCase
{
    use RefreshDatabase;

    private const HEADER = ['Numer produktu', 'Nazwa. kolor. wymiar produktu', 'Waga [kg]', "ExWorks'26"];

    private const MATS = [
        [null, 'Orthomat Standard', null, null],
        ['AF010003', 'Orthomat Standard Czarny 0.9m x 18.3m (9.5mm)', 45, 1934.02],
        ['AF010005', 'Orthomat Standard Czarny 1.2m x 18.3m (9.5mm)', 51, 2608.70],
        ['AF010006', 'Orthomat Standard Czarny 1.5m x 18.3m (9.5mm)', 60, 2608.70],
        ['UWAGA: akcesoria do maty dodatkowo płatne.', null, null, null],
        [null, 'Deckplate', null, null],
        ['DP010004', 'Deckplate Czarny 0.6m x 18.3m (15mm)', 93, 4107.35],
        ['DP010005', 'Deckplate Czarny 0.9m x 18.3m (15mm)', 146.4, 6623.02],
        [null, 'High-Duty', null, null],
        ['HI010002', 'High-Duty Czarny 0.9m x 1.5m (12mm) - moduł boczny (2 dł./1 kr.)', 11, 302.32],
        ['HI010003', 'High-Duty Czarny 0.9m x 1.5m (12mm) - moduł środkowy (2 dł.)', 11, 302.32],
        ['HI010004', 'High-Duty Czarny 0.9m x 1.5m (12mm) - moduł boczny (2 kr./1 dł.)', 11, 302.32],
        ['HI010005', 'High-Duty Czarny 0.9m x 1.5m (12mm) - moduł środkowy (2 kr.)', 11, 302.32],
    ];

    private const KNIVES = [
        [null, 'Noże bezpieczne z ukrytym ostrzem', null, null],
        [741242, 'GR8 Pro - Czerwony', 0.13, 26.61],
        [742242, 'GR8 Pro - Zielony', 0.13, 26.61],
        ['620010X10', 'Wymienne ostrza GR8 - w opakowaniu 10 sztuk', 0.05, 34.65],
        ['650010X50RB', 'Wymienne ostrza Anti-Stab AutoSafe - w opakowaniu 50 sztuk', 0.18, 77.20],
        ['650010X50RBM', 'Wymienne ostrza Anti-Stab AutoSafe Pro - w opakowaniu 50 sztuk', 0.18, 78.72],
        ['YO YO METAL', 'Zaczep Heavy Duty YoYo - GR8 Pro, AutoSafe Pro, AutoSlide', 0.05, 27.37],
    ];

    protected function setUp(): void
    {
        parent::setUp();
        Queue::fake();
    }

    public function test_every_row_of_mats_and_knives_keeps_its_own_code_name_and_card(): void
    {
        $result = $this->import(['Maty przemysłowe' => self::MATS, 'Noże bezpieczne' => self::KNIVES]);

        $expected = [];
        foreach ([...self::MATS, ...self::KNIVES] as $row) {
            if ($row[0] !== null && $row[3] !== null) {
                $expected[(string) $row[0]] = $row[1];
            }
        }
        ksort($expected);
        $cards = Product::query()->orderBy('sku')->pluck('name', 'sku')->all();
        ksort($cards);
        // jedna karta na wiersz, kod z pliku, nazwa z własnego wiersza (nie z nagłówka grupy ani z sąsiedniego wiersza)
        $this->assertSame($expected, $cards);
        $this->assertSame(count($expected), $result['created']);

        // AF010005 i AF010006 (ta sama cena) to dwie karty z własnymi cenami
        $this->assertEqualsWithDelta(2608.70, (float) Product::query()->where('sku', 'AF010006')->value('catalog_price_net'), 0.001);
        $this->assertEqualsWithDelta(6623.02, (float) Product::query()->where('sku', 'DP010005')->value('catalog_price_net'), 0.001);

        // identyfikatory: każda karta ma kod swojego wiersza i tylko jego
        foreach (array_keys($expected) as $sku) {
            $sku = (string) $sku;
            $card = Product::query()->where('sku', $sku)->sole();
            $this->assertSame(
                [$sku],
                ProductIdentifier::query()->where('product_id', $card->id)->where('type', 'source_code')->pluck('value')->all(),
                $sku,
            );
        }
        $this->assertSame([], array_values(array_filter(
            $result['errors'],
            static fn (string $note): bool => str_contains($note, 'repair-price-list-codes'),
        )));
    }

    public function test_glued_glove_sizes_in_one_price_still_collapse_into_one_card(): void
    {
        $this->import(['Rękawice' => [
            ['CRIOT08', 'Rękawice kriogeniczne CRIOT', 0.3, 82.99],
            ['CRIOT09', 'Rękawice kriogeniczne CRIOT', 0.3, 82.99],
            ['CRIOT10', 'Rękawice kriogeniczne CRIOT', 0.3, 82.99],
        ]], 'Rostaing');

        $card = Product::query()->sole();
        $this->assertSame('CRIOT', $card->sku);
        $this->assertSame(
            ['CRIOT08', 'CRIOT09', 'CRIOT10'],
            ProductIdentifier::query()->where('product_id', $card->id)->where('type', 'source_code')->orderBy('value')->pluck('value')->all(),
        );
    }

    public function test_code_core_group_collapses_only_rows_with_compatible_names(): void
    {
        // ten sam rdzeń kodu (A5016) i ta sama cena, ale różne wyroby — grupa „sku:” nie zlewa ich w jedną kartę
        $this->import(['Rękawice' => [
            ['A501609', 'Rękawice montażowe skórzane z mankietem', 0.2, 12.50],
            ['A501610', 'Rękawice spawalnicze długie dwoina', 0.2, 12.50],
        ]], 'PIP');

        $this->assertSame(['A501609', 'A501610'], Product::query()->orderBy('sku')->pluck('sku')->all());
    }

    public function test_rows_of_different_products_whose_codes_collide_keep_the_codes_from_the_file(): void
    {
        // ten sam rdzeń (A5016) w różnych cenach, ale różne wyroby — bez siatki drugi wiersz odnalazłby kartę pierwszego
        // po wspólnym kodzie i nadpisał jej cenę i nazwę
        $this->import(['Rękawice' => [
            ['A501609', 'Rękawice montażowe skórzane z mankietem', 0.2, 12.50],
            ['A501611', 'Rękawice spawalnicze długie dwoina', 0.2, 14.00],
        ]], 'PIP');

        $this->assertSame(
            ['A501609' => 12.5, 'A501611' => 14.0],
            Product::query()->orderBy('sku')->get()
                ->mapWithKeys(static fn (Product $p): array => [$p->sku => round((float) $p->catalog_price_net, 2)])->all(),
        );
    }

    public function test_price_tiers_of_one_core_keep_the_shared_code_and_reimport_adds_no_cards(): void
    {
        // progi cen jednego modelu (rozmiar po separatorze, rękawice z rozmiarem w kodzie) — jak przed 10.10.2026: jeden
        // kod modelu, karta odnajdywana po nim przy każdym imporcie, bez uwagi o kodach uciętych
        $baxjk = Product::query()->create(['sku' => 'BAXJK', 'name' => 'Kurtka ostrzegawcza BAXJK', 'manufacturer' => 'Coba', 'catalog_price_net' => 50, 'purchase_price' => 50]);
        $criot = Product::query()->create(['sku' => 'CRIOT', 'name' => 'Rękawice kriogeniczne CRIOT', 'manufacturer' => 'Coba', 'catalog_price_net' => 10, 'purchase_price' => 10]);
        $sheet = ['Odzież i rękawice' => [
            ['BAXJK-M', 'Kurtka ostrzegawcza BAXJK rozmiar M', 1, 50.00],
            ['BAXJK-L', 'Kurtka ostrzegawcza BAXJK rozmiar L', 1, 50.00],
            ['BAXJK-XXL', 'Kurtka ostrzegawcza BAXJK rozmiar XXL', 1, 55.00],
            ['CRIOT07', 'Rękawice kriogeniczne CRIOT rozmiar 7', 0.3, 10.00],
            ['CRIOT08', 'Rękawice kriogeniczne CRIOT rozmiar 8', 0.3, 10.00],
            ['CRIOT11', 'Rękawice kriogeniczne CRIOT rozmiar 11', 0.3, 12.00],
        ]];

        foreach ([1, 2] as $run) {
            $result = $this->import($sheet);
            $this->assertSame(0, $result['created'], 'import '.$run);
            $this->assertSame(['BAXJK', 'CRIOT'], Product::query()->orderBy('sku')->pluck('sku')->all(), 'import '.$run);
            $this->assertSame([], array_values(array_filter(
                $result['errors'],
                static fn (string $note): bool => str_contains($note, 'repair-price-list-codes'),
            )), 'import '.$run);
        }
        $this->assertSame(
            ['BAXJK-L', 'BAXJK-M', 'BAXJK-XXL'],
            ProductIdentifier::query()->where('product_id', $baxjk->id)->where('type', 'source_code')->whereNull('removed_at')->orderBy('value')->pluck('value')->all(),
        );
        $this->assertSame(
            ['CRIOT07', 'CRIOT08', 'CRIOT11'],
            ProductIdentifier::query()->where('product_id', $criot->id)->where('type', 'source_code')->whereNull('removed_at')->orderBy('value')->pluck('value')->all(),
        );
    }

    public function test_letter_size_in_name_with_number_in_glove_code_is_the_same_size_by_en420(): void
    {
        // „Rękawice … M” przy NITRO08: EN 420 8 = M — rozmiar z kodu potwierdzony, jak przed 10.10.2026 jeden kod modelu
        $result = $this->import(['Rękawice' => [
            ['NITRO08', 'Rękawice nitrylowe NITRO M', 0.1, 10.00],
            ['NITRO09', 'Rękawice nitrylowe NITRO L', 0.1, 10.00],
            ['NITRO10', 'Rękawice nitrylowe NITRO XL', 0.1, 12.00],
        ]], 'Ansell');

        $card = Product::query()->sole();
        $this->assertSame('NITRO', $card->sku);
        $this->assertSame(1, $result['created']);
        $this->assertSame(
            ['NITRO08', 'NITRO09', 'NITRO10'],
            ProductIdentifier::query()->where('product_id', $card->id)->where('type', 'source_code')->orderBy('value')->pluck('value')->all(),
        );
    }

    public function test_reimport_finds_cards_cut_by_the_old_rule_without_new_cards_or_code_change(): void
    {
        // stan po imporcie z 12.09.2026: kody ucięte, HI010004 i HI010005 zlane w jedną kartę z cudzą nazwą
        $af = $this->legacyCard('AF0100', 'Orthomat Standard Czarny 1.2m x 18.3m (9.5mm)', 2608.70);
        $dp = $this->legacyCard('DP0100-4', 'Deckplate Czarny 0.6m x 18.3m (15mm)', 4107.35);
        $hi = $this->legacyCard('HI0100', 'High-Duty Czarny 0.9m x 1.5m (12mm) - mata standardowa', 302.32);

        $result = $this->import(['Maty przemysłowe' => [
            ['AF010005', 'Orthomat Standard Czarny 1.2m x 18.3m (9.5mm)', 51, 2608.70],
            ['DP010004', 'Deckplate Czarny 0.6m x 18.3m (15mm)', 93, 4107.35],
            ['HI010004', 'High-Duty Czarny 0.9m x 1.5m (12mm) - moduł boczny (2 kr./1 dł.)', 11, 302.32],
            ['HI010005', 'High-Duty Czarny 0.9m x 1.5m (12mm) - moduł środkowy (2 kr.)', 11, 302.32],
            ['AF010003', 'Orthomat Standard Czarny 0.9m x 18.3m (9.5mm)', 45, 1934.02],
        ]]);

        // nowa karta tylko dla wiersza, którego dawna reguła nie ucięła
        $this->assertSame(1, $result['created']);
        $this->assertSame(4, Product::query()->count());
        $this->assertSame(['AF010003'], Product::query()->whereNotIn('id', [$af->id, $dp->id, $hi->id])->pluck('sku')->all());
        // kod i nazwa uciętych kart bez zmian — naprawia je osobne polecenie
        $this->assertSame(['AF0100', 'Orthomat Standard Czarny 1.2m x 18.3m (9.5mm)'], [$af->fresh()->sku, $af->fresh()->name]);
        $this->assertSame('DP0100-4', $dp->fresh()->sku);
        $this->assertSame(['HI0100', 'High-Duty Czarny 0.9m x 1.5m (12mm) - mata standardowa'], [$hi->fresh()->sku, $hi->fresh()->name]);
        // cena z pliku trafia na kartę, a kody wierszy do jej identyfikatorów
        $this->assertEqualsWithDelta(4107.35, (float) $dp->fresh()->catalog_price_net, 0.001);
        $this->assertSame(
            ['HI010004', 'HI010005'],
            ProductIdentifier::query()->where('product_id', $hi->id)->where('type', 'source_code')->orderBy('value')->pluck('value')->all(),
        );

        $notes = array_values(array_filter(
            $result['errors'],
            static fn (string $note): bool => str_contains($note, 'products:repair-price-list-codes'),
        ));
        $this->assertCount(1, $notes);
        $this->assertStringContainsString('dawną regułą rozmiaru: 3', $notes[0]);
        $this->assertStringContainsString('--price-list='.$result['price_list']->id, $notes[0]);
        $this->assertContains($notes[0], PriceListImport::query()->sole()->errors);
    }

    public function test_cut_card_whose_code_is_another_row_of_the_file_is_left_to_that_row(): void
    {
        // karta „SP070001C” z dawnego ucięcia SP070001C5, a nowy plik ma też pozycję o kodzie SP070001C
        $cut = $this->legacyCard('SP070001C', 'SitePath Żółty 1m x 5m (2mm)', 309.37);

        $this->import(['Maty przemysłowe' => [
            ['SP070001C5', 'SitePath Żółty 1m x 5m (2mm)', 14, 309.37],
            ['SP070001C', 'SitePath Żółty 1m x mb. (2mm)', 3, 309.37],
        ]]);

        $this->assertSame(['SP070001C', 'SP070001C5'], Product::query()->orderBy('sku')->pluck('sku')->all());
        $this->assertSame('SitePath Żółty 1m x mb. (2mm)', $cut->fresh()->name);
    }

    private function legacyCard(string $sku, string $name, float $price): Product
    {
        return Product::query()->create([
            'sku' => $sku,
            'name' => $name,
            'manufacturer' => 'Coba',
            'catalog_price_net' => $price,
            'purchase_price' => $price,
        ]);
    }

    /**
     * @param  array<string, list<array{0: string|int|null, 1: string|null, 2: float|int|null, 3: float|null}>>  $sheets
     * @return array<string, mixed>
     */
    private function import(array $sheets, string $manufacturer = 'Coba'): array
    {
        $spreadsheet = new Spreadsheet;
        $spreadsheet->removeSheetByIndex(0);
        $mapping = ['currency' => 'PLN', 'sheets' => []];
        foreach ($sheets as $title => $rows) {
            $sheet = $spreadsheet->createSheet();
            $sheet->setTitle($title);
            $sheet->fromArray([self::HEADER, ...$rows], null, 'A1', true);
            $mapping['sheets'][] = [
                'sheet' => $title,
                'include' => true,
                'header_excel_row' => 1,
                'columns' => ['sku' => 0, 'name' => 1, 'catalog_price' => 3],
                'repeating_headers' => false,
                'confidence' => 1.0,
            ];
        }
        $path = tempnam(sys_get_temp_dir(), 'glued').'.xlsx';
        (new Xlsx($spreadsheet))->save($path);
        $spreadsheet->disconnectWorksheets();

        try {
            $result = app(PriceListImportService::class)->importWithMapping(
                new UploadedFile($path, 'PLN - COBA Price Book 2026.xlsx', null, null, true),
                $manufacturer,
                '2026',
                User::factory()->create(),
                app(SpreadsheetColumnMapper::class)->refineMapping($path, $mapping),
            );
            $this->assertNotNull($result['price_list'], implode('; ', $result['errors'] ?? []));

            return $result;
        } finally {
            @unlink($path);
        }
    }
}
