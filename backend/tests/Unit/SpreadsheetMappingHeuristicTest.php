<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Services\PriceListImportService;
use App\Services\SpreadsheetMappingHeuristic;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use Tests\TestCase;

final class SpreadsheetMappingHeuristicTest extends TestCase
{
    public function test_prefers_article_number_over_sparse_reference(): void
    {
        $path = $this->makeDupontLikeSpreadsheet();
        try {
            $mapping = (new SpreadsheetMappingHeuristic)->detect($path);
            $this->assertNotNull($mapping);
            $sheet = $mapping['sheets'][0];
            $cols = $sheet['columns'];

            $this->assertSame(5, $cols['sku'], 'SKU = Article Number');
            $this->assertSame(2, $cols['model_key'], 'model_key = Reference');
            $this->assertSame(4, $cols['name'], 'Name = Model Name and Description');
            $this->assertSame(9, $cols['catalog_price']);
            $this->assertSame(2, $sheet['header_excel_row']);
            $this->assertSame('EUR', $mapping['currency']);
        } finally {
            @unlink($path);
        }
    }

    public function test_skips_rostaing_cover_and_maps_base_and_discounted(): void
    {
        $path = $this->writeSheet('ROSTAING 2026', [
            ['Language:', 'Anglais', null, null, null, 'DISCOUNT RATE:'],
            [null, null, null, null, '2026 PRICE LIST'],
            ['17, Avenue Charles de Gaulle', null, null, null, 'Applicable as of 1 January 2026. Subject to change until 31 December 2026.'],
            [],
            ['EAN Code', 'Product Description', 'Range of products', 'Catalogue page', 'Reference', 'Unit or Pair', 'Quantity per bag', 'Quantity per box', 'Base price 2026 in Euro', 'Discounted price'],
            ['3353090016794', 'RECTANGLE 35X30 CM MICROFIBRE', 'HANDLING', '-', '35X30SPEEDNET', 'U', '50', '50', '4.94', '2.23'],
            ['3353090115213', '1 RIGHT HAND GLOVE T7 WELDER', 'WELDING', '76', 'ALUWELD-DRT07', 'U', '5', '40', '23.51', '10.63'],
            ['3353090112991', '1 RIGHT HAND GLOVE SIZE 8 WELDER', 'WELDING', '76', 'ALUWELD-DRT08', 'U', '5', '40', '23.51', '10.63'],
        ]);
        try {
            $mapping = (new SpreadsheetMappingHeuristic)->detect($path);
            $this->assertNotNull($mapping);
            $sheet = $mapping['sheets'][0];
            $this->assertSame(5, $sheet['header_excel_row']);
            $this->assertSame(4, $sheet['columns']['sku']);
            $this->assertSame(1, $sheet['columns']['name']);
            $this->assertSame(8, $sheet['columns']['catalog_price']);
            $this->assertSame(9, $sheet['columns']['purchase']);
        } finally {
            @unlink($path);
        }
    }

    public function test_maps_artra_article_and_net_without_vat(): void
    {
        $path = $this->writeSheet('PL', [
            ['PHT Supon'],
            [],
            ['lp.', 'kat.', 'artykuł', 'zdjęcie', 'typ', 'ochrony', 'podeszwa', 'metal free', 'kolekcja', 'rozm.', 'bez VAT', '* NCD z VAT'],
            ['1', 'NEW', 'AROX 7333 641460 S1 PL ESD', '', 'półbuty', 'S1 PL', 'LYFTOR', 'metal free', 'PINKYUM', '35-48', '36.19', '429'],
            ['2', 'NEW', 'AROX 733 648080 S1 PL ESD', '', 'półbuty', 'S1 PL', 'RAPTOR', '', 'PINKYUM', '35-48', '35.59', '429'],
            ['3', '76', 'ARDEA 310 618080 S1 PL ESD', '', 'sandały', 'S1 PL', 'LYFTOR', 'metal free', 'PINKYUM', '35-48', '44.12', '539'],
        ]);
        try {
            $mapping = (new SpreadsheetMappingHeuristic)->detect($path);
            $this->assertNotNull($mapping);
            $cols = $mapping['sheets'][0]['columns'];
            $this->assertSame(2, $cols['sku']);
            $this->assertSame(10, $cols['catalog_price']);
            $this->assertNotSame(11, $cols['catalog_price']);
            $this->assertNotSame(10, $cols['name']);
        } finally {
            @unlink($path);
        }
    }

    public function test_ppo_name_is_not_the_price_column(): void
    {
        $path = $this->writeSheet('2026 PL', [
            ['Zał. Nr 1'],
            ['L.p.', 'Wyszczególnienie', 'Kategoria', 'Model', 'Indeks nadrzędny', 'Cena fabryczna netto I gatunek od 01.06.2026'],
            [],
            ['1', 'Trzewiki zawodowe bez podnoska', 'EN ISO 20347', 'Model 305', '305', '139.00'],
            ['2', 'Półbuty bezpieczne z metalowym podnoskiem', 'EN ISO 20345', 'Model 317', '317', '161.00'],
            ['3', 'Półbuty zawodowe bez podnoska', 'EN ISO 20347', 'Model 318', '318', '155.00'],
        ]);
        try {
            $mapping = (new SpreadsheetMappingHeuristic)->detect($path);
            $this->assertNotNull($mapping);
            $cols = $mapping['sheets'][0]['columns'];
            $this->assertSame(4, $cols['sku']);
            $this->assertSame(1, $cols['name']);
            $this->assertSame(5, $cols['catalog_price']);
        } finally {
            @unlink($path);
        }
    }

    public function test_canis_uses_dash_code_not_unit_and_pln_price(): void
    {
        $path = $this->writeSheet('Price list', [
            [null, null, 'Název', null, null, 'MJ', 'bal./kar.', 'Kč', 'EUR', 'USD', 'Price PLN'],
            [],
            ['WORKING SUITS'],
            ['Strana', 'Pomlčkový kód', 'Název', null, 'Men´s working garments CXS SOLIS', 'UNIT', 'bal./kar.', 'Price CZK', 'Price EUR', 'Price USD', 'Price PLN'],
            ['41', '1010-130-260-00', 'SOLIS FLEX', null, 'Men´s jacket CXS SOLIS FLEX, redblack, size 46', 'pcs', '1/20', '430.00', '17.917', '20.98', '78.182'],
            ['41', '1010-130-411-00', 'SOLIS FLEX', null, 'Men´s jacket CXS SOLIS FLEX, blue-black, size 46', 'pcs', '1/20', '430.00', '17.917', '20.98', '78.182'],
            ['41', '1020-130-260-00', 'SOLIS FLEX', null, 'Men´s trousers CXS SOLIS FLEX, red-black, size 46', 'pcs', '1/20', '400.00', '16.667', '19.51', '72.727'],
        ]);
        try {
            $mapping = (new SpreadsheetMappingHeuristic)->detect($path);
            $this->assertNotNull($mapping);
            $cols = $mapping['sheets'][0]['columns'];
            $this->assertSame(1, $cols['sku']);
            $this->assertSame(2, $cols['name']);
            $this->assertSame(10, $cols['catalog_price']);
            $this->assertNotSame(5, $cols['sku']);
        } finally {
            @unlink($path);
        }
    }

    public function test_dupont_ignores_ref_errors_and_finds_article_and_unit_price(): void
    {
        $path = $this->writeSheet('Industrial  ', [
            ['Line1', '#REF!', '#REF!'],
            ['Line2', 'Category/Type', '#REF!', '#REF!', '#REF!', '#REF!', '#REF!', '#REF!', 'Box Price (€/pc.)', '#REF!', 'Price (€/pc.)', '', '', '', '', '', '', '', '', '', 'Price Lists', 'Column1'],
            ['Line3', '#REF!', '', '', '', '', '', '', '', '', '', '', '', '', '', '', '', '', '', '', 'SuperPartnerSpecialistEUR', 'Price (€/pc.)  Min. 5000€'],
            ['TS CHF5 S WH DE', 'Cat. III', 'TS CHF5 S WH DE', '', '#REF!', 'D14886039', 'S', '100', '#REF!', '1600', '3.06', '', '', '', '', '', '', '', '', '', 'SuperPartnerGeneralistEUR', 'Price (€/pc.)  Min. 5000€'],
            ['TS CHF5 S WH DE', '', '', '', '#REF!', 'D14886047', 'M', '100', '#REF!', '1600', '3.06', '', '', '', '', '', '', '', '', '', 'CoreSpecialistEUR', 'Price (€/pc.)  Min. 5000€'],
            ['TS CHF5 S WH DE', '', '', '', '', 'D14886050', 'L', '100', '#REF!', '1600', '3.06', '', '', '', '', '', '', '', '', '', 'CoreGeneralistEUR', 'Price (€/pc.)  Min. 5000€'],
        ]);
        try {
            $mapping = (new SpreadsheetMappingHeuristic)->detect($path);
            $this->assertNotNull($mapping);
            $cols = $mapping['sheets'][0]['columns'];
            $this->assertSame(10, $cols['catalog_price']);
            $this->assertSame(5, $cols['sku']);
            $this->assertSame(0, $cols['model_key']);
            $this->assertSame(0, $cols['name'], 'nazwa = Reference, nie kolumna cennika');
            $this->assertSame(6, $cols['packaging']);
            $this->assertSame(7, $cols['pack_qty']);
            $this->assertNotSame(21, $cols['name']);
            $this->assertNotSame(6, $cols['name']);

            $preview = app(PriceListImportService::class)->previewFromMapping($path, $mapping, 20);
            $this->assertSame(1, $preview['products_found']);
            $this->assertSame('TS CHF5 S WH DE', $preview['products'][0]['sku']);
            $this->assertSame('TS CHF5 S WH DE', $preview['products'][0]['name']);
            $this->assertEqualsWithDelta(3.06, (float) $preview['products'][0]['catalog_price_net'], 0.001);
        } finally {
            @unlink($path);
        }
    }

    public function test_dupont_sparse_reference_still_maps_model_key(): void
    {
        $path = $this->writeSheet('Controlled Environment  ', [
            ['Line2', '#REF!', '#REF!', '#REF!', '#REF!', '#REF!', '#REF!', '#REF!', "Price\n(€/pc.)"],
            ['Line3', '#REF!'],
            ['IC 270 B WH 0B', 'Cat III. PB [6]', 'IC 270 B WH 0B', '', '#REF!', 'D15535577', 'S', '30', '4.60'],
            ['', '', '', '', '#REF!', 'D15535578', 'M', '30', '4.60'],
            ['', '', '', '', '', 'D15535579', 'L', '30', '4.60'],
            ['IC 451 S WH 00', 'Cat III. PB [6]', 'IC 451 S WH 00', '', '#REF!', 'D15531633', 'M', '100', '0.84'],
            ['', '', '', '', '', 'D15531634', 'L', '100', '0.84'],
        ]);
        try {
            $mapping = (new SpreadsheetMappingHeuristic)->detect($path);
            $this->assertNotNull($mapping);
            $cols = $mapping['sheets'][0]['columns'];
            $this->assertSame(8, $cols['catalog_price']);
            $this->assertSame(5, $cols['sku']);
            $this->assertSame(0, $cols['model_key']);
            $this->assertSame(0, $cols['name']);
        } finally {
            @unlink($path);
        }
    }

    private function makeDupontLikeSpreadsheet(): string
    {
        $spreadsheet = new Spreadsheet;
        $sheet = $spreadsheet->getActiveSheet();
        $sheet->setTitle('Industrial (2)');
        $sheet->fromArray([
            [null, 'Pricelist 2018', null, null, 'Core', null, null, null, null, '10/12/2017'],
            [null, 'Category/Type', 'Reference', 'Product Image', 'Model Name and Description', 'Article Number', 'Size', 'Quantity per box', 'Minimum Order Quantity', 'Price(€/pc.) for Min. of 5000€'],
            [null, 'TYVEK®', null, null, null, null, null, null, null, null],
            ['TD 0125 S WH 00', 'Cat.III', 'TD 0125 S WH 00', null, 'NEW! TYVEK Dual Combi', 'D14681379', 'S', '25', '1600', '2.68'],
            ['TD 0125 S WH 00', null, null, null, 'Collared coverall combining Tyvek with a light polypropylene back panel. Elasticated wrists, waist and ankles. Zipper flap.', 'D14681380', 'M', '25', '1600', '2.68'],
            ['TD 0125 S WH 00', null, null, null, null, 'D14681398', 'L', '25', '1600', '2.68'],
            ['TK GEVJ T YL 00', null, null, null, 'For use with SCBA. Attached (detachable) overboots. Attached double gloves (removable). Wide panoramic visor.', 'D13495380*', 'M*', '1', 'N/A', '1074.89'],
        ]);

        $path = tempnam(sys_get_temp_dir(), 'dupont').'.xlsx';
        (new Xlsx($spreadsheet))->save($path);

        return $path;
    }

    /**
     * @param  list<list<mixed>>  $rows
     */
    private function writeSheet(string $title, array $rows): string
    {
        $spreadsheet = new Spreadsheet;
        $sheet = $spreadsheet->getActiveSheet();
        $sheet->setTitle($title);
        $sheet->fromArray($rows);
        $path = tempnam(sys_get_temp_dir(), 'map').'.xlsx';
        (new Xlsx($spreadsheet))->save($path);

        return $path;
    }
}
