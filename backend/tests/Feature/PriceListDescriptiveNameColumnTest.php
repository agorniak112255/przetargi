<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Product;
use App\Models\User;
use App\Services\PriceListImportService;
use App\Services\SpreadsheetColumnMapper;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use Tests\TestCase;

/**
 * Cennik CXS: „Název” to powtarzana nazwa rodziny (SOLIS FLEX), pełna nazwa
 * wyrobu stoi w kolumnie, której nagłówkiem jest tytuł sekcji.
 */
final class PriceListDescriptiveNameColumnTest extends TestCase
{
    use RefreshDatabase;

    public function test_long_distinct_column_becomes_name_and_short_repeated_becomes_model(): void
    {
        $path = $this->makeCxsLikeSpreadsheet();
        try {
            $mapping = app(SpreadsheetColumnMapper::class)->refineMapping($path, $this->mapping());
            $columns = $mapping['sheets'][0]['columns'];
            $this->assertSame(4, $columns['name']);
            $this->assertSame(2, $columns['model_name']);
            $this->assertSame(1, $columns['sku']);

            $result = app(PriceListImportService::class)->importWithMapping(
                new UploadedFile($path, 'cxs.xlsx', null, null, true),
                'CXS',
                '2026-05',
                User::factory()->create(),
                $mapping,
            );
            $this->assertNotNull($result['price_list'], implode('; ', $result['errors'] ?? []));

            $jacket = Product::query()->where('sku', '1010-130-260-00')->firstOrFail();
            $this->assertStringContainsString('Men´s jacket CXS SOLIS FLEX, red-black', $jacket->name);
            $this->assertSame('SOLIS FLEX', $jacket->model_name);
            $this->assertStringContainsString('size 46 - 64', $this->rawNameFor($path, 3), 'fixture ma zakres rozmiarów');
            $this->assertSame('Men´s jacket CXS SOLIS FLEX, red-black', $jacket->name, 'zakres „size 46 - 64” odcięty w całości');

            $ladies = Product::query()->where('sku', '1010-135-710-00')->first();
            $this->assertNotNull($ladies, '„NEW 6/2026” z kolumny uwag nie może zastąpić kodu');
            $this->assertNull(Product::query()->where('sku', 'NEW 6/2026')->first());
        } finally {
            @unlink($path);
        }
    }

    public function test_sparse_name_column_is_not_replaced(): void
    {
        // rozmiarówka: nazwa tylko w 1. wierszu modelu, opis EN w każdym — nazwa zostaje
        $rows = [
            ['Article Number', 'Model Name', 'Description', 'Size', 'Price'],
            ['D14886039', 'NEW! TYVEK Dual Combi', 'Tyvek 500 Xpert hooded coverall with attached socks, white', 'S', 10.5],
            ['D14886040', '', 'Tyvek 500 Xpert hooded coverall with attached socks, white M', 'M', 10.5],
            ['D14886041', '', 'Tyvek 500 Xpert hooded coverall with attached socks, white L', 'L', 10.5],
            ['D14886042', '', 'Tyvek 500 Xpert hooded coverall with attached socks, white XL', 'XL', 10.5],
            ['D14886050', 'TYVEK Classic Xpert', 'Tyvek 500 Xpert hooded coverall, white, elastic cuffs', 'S', 9.5],
            ['D14886051', '', 'Tyvek 500 Xpert hooded coverall, white, elastic cuffs M', 'M', 9.5],
            ['D14886052', '', 'Tyvek 500 Xpert hooded coverall, white, elastic cuffs L', 'L', 9.5],
        ];
        $path = $this->saveRows($rows, 'Price list');
        try {
            $mapping = app(SpreadsheetColumnMapper::class)->refineMapping($path, [
                'currency' => 'EUR',
                'sheets' => [[
                    'sheet' => 'Price list', 'include' => true, 'header_excel_row' => 1,
                    'columns' => ['sku' => 0, 'name' => 1, 'catalog_price' => 4],
                    'repeating_headers' => false, 'confidence' => 1.0,
                ]],
            ]);
            $this->assertSame(1, $mapping['sheets'][0]['columns']['name']);
            $this->assertNull($mapping['sheets'][0]['columns']['model_name'] ?? null);
        } finally {
            @unlink($path);
        }
    }

    private function rawNameFor(string $path, int $excelRow): string
    {
        $spreadsheet = IOFactory::load($path);
        try {
            return (string) $spreadsheet->getActiveSheet()->getCell('E'.$excelRow)->getValue();
        } finally {
            $spreadsheet->disconnectWorksheets();
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function mapping(): array
    {
        return [
            'currency' => 'PLN',
            'sheets' => [[
                'sheet' => 'Price list',
                'include' => true,
                'header_excel_row' => 2,
                'columns' => ['sku' => 1, 'name' => 2, 'catalog_price' => 7],
                'repeating_headers' => false,
                'confidence' => 1.0,
            ]],
        ];
    }

    private function makeCxsLikeSpreadsheet(): string
    {
        $rows = [
            [null, null, 'Název', null, null, 'MJ', 'bal./kar.', 'Price PLN'],
            ['Strana', 'Pomlčkový kód', 'Název', null, 'Men´s working garments CXS SOLIS', 'UNIT', 'bal./kar.', 'Price PLN'],
        ];
        $colors = ['red-black', 'blue-black', 'green-black', 'camo-black', 'grey-black'];
        foreach ($colors as $i => $color) {
            $rows[] = [41, '1010-130-'.(260 + $i * 10).'-00', 'SOLIS FLEX', null, 'Men´s jacket CXS SOLIS FLEX, '.$color.', size 46 - 64', 'pcs', '1/20', 78.18];
        }
        foreach ($colors as $i => $color) {
            $rows[] = [42, '1020-130-'.(260 + $i * 10).'-00', 'SOLIS FLEX', null, 'Men´s trousers CXS SOLIS FLEX, '.$color.', size 46 - 68', 'pcs', '1/20', 72.73];
        }
        foreach ($colors as $i => $color) {
            $rows[] = [43, '1030-130-'.(260 + $i * 10).'-00', 'SOLIS PLUS', null, 'Men´s vest CXS SOLIS PLUS, '.$color.', size 46 - 64', 'pcs', '1/20', 54.55];
        }
        // nowość: w kolumnie uwag stoi „NEW 6/2026”, kod jest w kolumnie kodu
        $rows[] = ['x', '1010-135-710-00', 'SOLIS FLEX', 'NEW 6/2026', 'Jacket CXS SOLIS FLEX, ladies, grey-black, size S - 3XL', 'pcs', '1/20', 78.18];

        return $this->saveRows($rows, 'Price list');
    }

    /**
     * @param  list<list<mixed>>  $rows
     */
    private function saveRows(array $rows, string $title): string
    {
        $spreadsheet = new Spreadsheet;
        $sheet = $spreadsheet->getActiveSheet();
        $sheet->setTitle($title);
        $sheet->fromArray($rows, null, 'A1', true);
        $path = tempnam(sys_get_temp_dir(), 'cxs').'.xlsx';
        (new Xlsx($spreadsheet))->save($path);
        $spreadsheet->disconnectWorksheets();

        return $path;
    }
}
