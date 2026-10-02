<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Product;
use App\Models\ProductSourcePrice;
use App\Models\User;
use App\Services\PriceListImportService;
use App\Services\SpreadsheetColumnMapper;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Laravel\Sanctum\Sanctum;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use Tests\TestCase;

/**
 * Ręczna korekta mapowania kolumn przed importem.
 *
 * Cennik ATG: „Opis rękawicy” („Ściągacz, oblanie 3/4”) wygrywa punktację nazwy, choć wyrób nazywa
 * kolumna „Rodzina rękawic” (MaxiFlex® Elite™). Sama rodzina powtarza się jednak na kilkunastu
 * pozycjach, więc poprawna nazwa powstaje dopiero ze złączenia obu kolumn — a wyboru dokonuje
 * człowiek w oknie importu i automat nie może mu go cofnąć.
 */
final class PriceListManualColumnMappingTest extends TestCase
{
    use RefreshDatabase;

    public function test_manual_name_column_is_not_overridden_by_correction(): void
    {
        // cennik ARTRY: „typ” to pół tuzina wartości na kilkanaście wierszy, więc korekta nazwy przestawia
        // wybór na kolumnę opisu. Kiedy kolumnę wskazał człowiek, ta sama korekta nie ma prawa jej cofnąć.
        $path = $this->makeRepeatedTypeSpreadsheet();
        try {
            $service = app(PriceListImportService::class);

            $auto = $service->productNamesFromMapping(
                $path,
                $this->mapping(['sku' => 0, 'name' => 1, 'catalog_price' => 3], [], 'Obuwie'),
                'ARTRA',
            );
            $this->assertStringContainsString(
                'ARAL',
                $auto['0-1234'] ?? '',
                'bez blokady korekta przestawia nazwę na kolumnę opisu — to jest stan, przed którym chroni okno importu',
            );

            $locked = $service->productNamesFromMapping(
                $path,
                $this->mapping(['sku' => 0, 'name' => 1, 'catalog_price' => 3], ['name'], 'Obuwie'),
                'ARTRA',
            );
            $this->assertSame('półbuty', $locked['0-1234'] ?? null);
        } finally {
            @unlink($path);
        }
    }

    public function test_name_is_composed_from_two_columns(): void
    {
        $path = $this->makeAtgSpreadsheet();
        try {
            $result = app(PriceListImportService::class)->importWithMapping(
                new UploadedFile($path, 'atg.xlsx', null, null, true),
                'ATG',
                '2026-09',
                User::factory()->create(),
                $this->mapping(
                    ['sku' => 0, 'name' => 1, 'name_extra' => 2, 'catalog_price' => 3],
                    ['name', 'name_extra'],
                ),
            );
            $this->assertNotNull($result['price_list'], implode('; ', $result['errors'] ?? []));

            $this->assertSame(
                'MaxiFlex® Elite™ — Ściągacz, oblanie części chwytnej',
                Product::query()->where('sku', '34-244')->value('name'),
            );
            $this->assertSame(
                'MaxiFlex® Elite™ — Ściągacz, pełne oblanie',
                Product::query()->where('sku', '34-274')->value('name'),
                'dwie pozycje tej samej rodziny różnią się nazwą',
            );
        } finally {
            @unlink($path);
        }
    }

    public function test_locked_role_without_column_stays_empty(): void
    {
        $path = $this->makeAtgSpreadsheet();
        try {
            // człowiek odznaczył kategorię — automat nie ma prawa jej przywrócić
            $preview = app(PriceListImportService::class)->previewFromMapping(
                $path,
                $this->mapping(
                    ['sku' => 0, 'name' => 1, 'catalog_price' => 3],
                    ['name', 'category'],
                ),
            );
            $columns = $preview['sheets'][0]['columns'] ?? [];
            $this->assertArrayNotHasKey('category', $columns);
            $this->assertSame(1, $columns['name'] ?? null);
        } finally {
            @unlink($path);
        }
    }

    public function test_preview_returns_column_labels_with_samples(): void
    {
        $path = $this->makeAtgSpreadsheet();
        try {
            $preview = app(PriceListImportService::class)->previewFromMapping(
                $path,
                $this->mapping(['sku' => 0, 'name' => 1, 'catalog_price' => 3]),
            );
            $columns = $preview['sheets'][0]['available_columns'] ?? [];
            $byIndex = [];
            foreach ($columns as $column) {
                $byIndex[$column['index']] = $column;
            }

            $this->assertSame('Rodzina rękawic', $byIndex[1]['label'] ?? null);
            $this->assertSame('MaxiDex®', $byIndex[1]['sample'] ?? null);
            $this->assertSame('Opis rękawicy', $byIndex[2]['label'] ?? null);
            $this->assertSame('Referencja', $byIndex[0]['label'] ?? null);
        } finally {
            @unlink($path);
        }
    }

    public function test_describe_columns_skips_empty_columns(): void
    {
        $path = $this->makeAtgSpreadsheet();
        try {
            $spreadsheet = IOFactory::load($path);
            $columns = app(SpreadsheetColumnMapper::class)->describeColumns(
                $spreadsheet->getActiveSheet(),
                1,
                8,
            );
            $spreadsheet->disconnectWorksheets();

            $this->assertCount(4, $columns, 'kolumny bez nagłówka i bez danych nie trafiają na listę wyboru');
            $this->assertSame([0, 1, 2, 3], array_column($columns, 'index'));
        } finally {
            @unlink($path);
        }
    }

    public function test_preview_endpoint_reads_file_by_corrected_mapping(): void
    {
        $this->seed(RolesAndPermissionsSeeder::class);
        Sanctum::actingAs(User::factory()->withRole('admin')->create());

        $path = $this->makeAtgSpreadsheet();
        try {
            $response = $this->post('/api/price-lists/preview', [
                'file' => new UploadedFile($path, 'atg.xlsx', null, null, true),
                'mapping' => json_encode($this->mapping(
                    ['sku' => 0, 'name' => 1, 'name_extra' => 2, 'catalog_price' => 3],
                    ['name', 'name_extra'],
                )),
                'manufacturer' => 'ATG',
            ]);

            $response->assertOk();
            $response->assertJsonPath('products_found', 14);
            $response->assertJsonPath(
                'preview.0.name',
                'MaxiDex® — Ściągacz, pełne oblanie',
            );
            $response->assertJsonPath('mapping.sheets.0.columns.name_extra', 2);
            $response->assertJsonPath('mapping.sheets.0.available_columns.1.label', 'Rodzina rękawic');
        } finally {
            @unlink($path);
        }
    }

    public function test_preview_endpoint_rejects_mapping_without_sheets(): void
    {
        $this->seed(RolesAndPermissionsSeeder::class);
        Sanctum::actingAs(User::factory()->withRole('admin')->create());

        $path = $this->makeAtgSpreadsheet();
        try {
            $this->postJson('/api/price-lists/preview', [
                'file' => new UploadedFile($path, 'atg.xlsx', null, null, true),
                'mapping' => json_encode(['currency' => 'PLN']),
            ])->assertStatus(422)->assertJsonValidationErrors('mapping');
        } finally {
            @unlink($path);
        }
    }

    /**
     * Cennik Ansell: „Price UOM” mówi, za co jest cena. PAI/PCE — zakup z „Final Invoice Price” (z dopłatą,
     * bywa wyższy od ceny katalogowej), CAR — zakup z dopisanej kolumny „Cena za opak.”, a katalogowa za
     * karton przeliczona na opakowanie. Karton bez ceny za opakowanie nie wchodzi.
     */
    public function test_carton_rows_take_pack_price_and_scale_catalog_price(): void
    {
        $path = $this->makeAnsellSpreadsheet();
        try {
            $preview = app(PriceListImportService::class)->previewFromMapping(
                $path,
                $this->ansellMapping(['purchase', 'price_unit', 'pack_price']),
                50,
            );
            $bySku = [];
            foreach ($preview['products'] as $product) {
                $bySku[$product['sku']] = $product;
            }

            // para: cena faktury z dopłatą (18,30) wyższa od katalogowej (18,09) zostaje — upust 0
            $this->assertEqualsWithDelta(18.30, $bySku['065-07']['purchase_price'] ?? null, 0.001);
            $this->assertEqualsWithDelta(18.09, $bySku['065-07']['catalog_price_net'] ?? null, 0.001);
            $this->assertEqualsWithDelta(0.0, $bySku['065-07']['discount_percent'] ?? null, 0.001);
            $this->assertTrue($bySku['065-07']['_purchase_from_file'] ?? false);

            // karton 5 opakowań: zakup = cena za opakowanie, katalogowa 107,22 / 5
            $this->assertEqualsWithDelta(20.714, $bySku['13837']['purchase_price'] ?? null, 0.001);
            $this->assertEqualsWithDelta(21.44, $bySku['13837']['catalog_price_net'] ?? null, 0.001);
            // karton jednego opakowania: obie ceny bez zmian
            $this->assertEqualsWithDelta(52.03, $bySku['13823']['purchase_price'] ?? null, 0.001);
            $this->assertEqualsWithDelta(53.86, $bySku['13823']['catalog_price_net'] ?? null, 0.001);

            $this->assertArrayNotHasKey('67308', $bySku, 'karton bez ceny za opakowanie nie wchodzi');
            $this->assertCount(1, $preview['errors']);
            $this->assertStringContainsString('67308', $preview['errors'][0]);
            $this->assertStringContainsString('bez ceny za opakowanie', $preview['errors'][0]);
        } finally {
            @unlink($path);
        }
    }

    /**
     * Cena faktury Ansell (P) zawiera dopłatę % (kolumna M), cena katalogowa (E) — nie. Przy 25% dopłaty zakup
     * przebijał katalog (TouchNTuff 92600VP: katalog 3,68, zakup 4,24 za opakowanie). Katalogowa z dopłatą:
     * 88,38 × 1,25 / 24 opakowania = 4,60, a upust wraca do 8% z pliku.
     */
    public function test_surcharge_column_raises_catalog_price(): void
    {
        $path = $this->makeAnsellSpreadsheet();
        try {
            $preview = app(PriceListImportService::class)->previewFromMapping(
                $path,
                $this->ansellMapping(['purchase', 'surcharge', 'price_unit', 'pack_price'], true),
                50,
            );
            $byName = [];
            foreach ($preview['products'] as $product) {
                $byName[$product['name']] = $product;
            }

            $touch = $byName['TouchNTuff 92600VP VEND'] ?? [];
            $this->assertEqualsWithDelta(4.60, $touch['catalog_price_net'] ?? null, 0.001);
            $this->assertEqualsWithDelta(4.235, $touch['purchase_price'] ?? null, 0.001);
            $this->assertEqualsWithDelta(7.93, $touch['discount_percent'] ?? null, 0.01);

            // para: 18,09 + 10% = 19,90; zakup 18,30 → upust 8%
            $ringers = $byName['RINGERS 065'] ?? $byName['RINGERS 065 SIZE 7,0'] ?? [];
            $this->assertEqualsWithDelta(19.90, $ringers['catalog_price_net'] ?? null, 0.001);
            $this->assertEqualsWithDelta(18.30, $ringers['purchase_price'] ?? null, 0.001);
            $this->assertEqualsWithDelta(8.04, $ringers['discount_percent'] ?? null, 0.01);

            foreach ($preview['products'] as $product) {
                $this->assertLessThanOrEqual(
                    $product['catalog_price_net'],
                    $product['purchase_price'],
                    $product['name'].': zakup z dopłatą nie przebija katalogu z dopłatą',
                );
            }
        } finally {
            @unlink($path);
        }
    }

    public function test_carton_rows_import_pack_price_into_file_slot(): void
    {
        $path = $this->makeAnsellSpreadsheet();
        try {
            $result = app(PriceListImportService::class)->importWithMapping(
                new UploadedFile($path, 'ansell.xlsx', null, null, true),
                'Ansell',
                '2026-10',
                User::factory()->create(),
                $this->ansellMapping(['purchase', 'price_unit', 'pack_price']),
            );
            $this->assertNotNull($result['price_list'], implode('; ', $result['errors'] ?? []));

            $slot = static fn (string $sku): ?ProductSourcePrice => ProductSourcePrice::query()
                ->where('source_key', ProductSourcePrice::SOURCE_FILE)
                ->where('product_id', Product::query()->where('sku', $sku)->value('id'))
                ->first();

            $this->assertEqualsWithDelta(20.71, (float) $slot('13837')?->purchase_price, 0.001);
            $this->assertEqualsWithDelta(21.44, (float) $slot('13837')?->catalog_price_net, 0.001);
            $this->assertEqualsWithDelta(18.30, (float) $slot('065-07')?->purchase_price, 0.001);
            $this->assertSame('EUR', $slot('065-07')?->currency);
            $this->assertFalse(Product::query()->where('sku', '67308')->exists());
        } finally {
            @unlink($path);
        }
    }

    public function test_purchase_above_catalog_from_automatic_mapping_is_still_rejected(): void
    {
        // ARTRA: kolumnę zakupu zgadł automat i wskazał cenę brutto — bez potwierdzenia człowieka zakup
        // wyższy od katalogowej dalej liczymy z upustu
        $path = $this->makeAnsellSpreadsheet();
        try {
            $preview = app(PriceListImportService::class)->previewFromMapping(
                $path,
                $this->ansellMapping([]),
                50,
            );
            $row = collect($preview['products'])->firstWhere('sku', '065-07');

            $this->assertNotNull($row);
            $this->assertEqualsWithDelta(16.64, $row['purchase_price'], 0.001);
            $this->assertFalse($row['_purchase_from_file']);
        } finally {
            @unlink($path);
        }
    }

    /**
     * @param  list<string>  $locked
     * @return array<string, mixed>
     */
    private function ansellMapping(array $locked, bool $surcharge = false): array
    {
        return $this->mapping(
            [
                'sku' => 0, 'name' => 1, 'catalog_price' => 2, 'discount' => 3, 'purchase' => 4,
                'currency' => 5, 'price_unit' => 6, 'pack_price' => 7,
            ] + ($surcharge ? ['surcharge' => 8] : []),
            $locked,
            'Price List & SPO',
        );
    }

    private function makeAnsellSpreadsheet(): string
    {
        $rows = [
            ['Product Reference', 'Description', 'Price List Price', 'PL Discount %', 'Final Invoice Price', 'Currency', 'Price UOM', 'Cena za opak.', '%'],
            ['065-07', 'RINGERS 065 SIZE 7,0', 18.09, 0.08, 18.30, 'EUR', 'PAI', null, 10],
            ['11200000', 'HyFlex 11200 SIZE 19', 8.41, 0.08, 8.83, 'EUR', 'PCE', null, 5],
            ['13823', 'KLNGD G60 PolyU Lvl 3 Gloves PalmGry', 53.86, 0.08, 52.03, 'EUR', 'CAR', 52.03, 5],
            ['13837', 'KLNGD G40 Gloves PU Black', 107.22, 0.08, 103.57, 'EUR', 'CAR', 20.714, 5],
            ['92600VP070', 'TouchNTuff 92600VP VEND', 88.38, 0.08, 101.64, 'EUR', 'CAR', 4.235, 25],
            ['67308', 'KLNGD KGA10 Coverall Hood EWA M', 95.00, 0.08, 92.06, 'EUR', 'CAR', null, 5],
        ];

        $spreadsheet = new Spreadsheet;
        $sheet = $spreadsheet->getActiveSheet();
        $sheet->setTitle('Price List & SPO');
        $sheet->fromArray($rows, null, 'A1', true);
        $path = tempnam(sys_get_temp_dir(), 'ansell').'.xlsx';
        (new Xlsx($spreadsheet))->save($path);
        $spreadsheet->disconnectWorksheets();

        return $path;
    }

    /**
     * @param  array<string, int>  $columns
     * @param  list<string>  $locked
     * @return array<string, mixed>
     */
    private function mapping(array $columns, array $locked = [], string $sheet = 'Cennik'): array
    {
        return [
            'currency' => 'PLN',
            'sheets' => [[
                'sheet' => $sheet,
                'include' => true,
                'header_excel_row' => 1,
                'columns' => $columns,
                'locked_columns' => $locked,
                'repeating_headers' => false,
                'confidence' => 1.0,
            ]],
        ];
    }

    private function makeRepeatedTypeSpreadsheet(): string
    {
        $rows = [['Kod', 'Typ', 'Opis wyrobu', 'Cena netto']];
        $types = ['półbuty', 'trzewiki', 'sandały'];
        $models = ['ARAL', 'ARLOW', 'AREZZO', 'ARENA', 'ARGON'];
        $i = 0;
        foreach ($types as $type) {
            foreach ($models as $model) {
                $rows[] = [
                    '0-'.(1234 + $i),
                    $type,
                    'Obuwie ochronne ARTRA '.$model.' '.$type.' S3 SRC, skóra licowa, podnosek kompozytowy',
                    120.0 + $i,
                ];
                $i++;
            }
        }

        $spreadsheet = new Spreadsheet;
        $sheet = $spreadsheet->getActiveSheet();
        $sheet->setTitle('Obuwie');
        $sheet->fromArray($rows, null, 'A1', true);
        $path = tempnam(sys_get_temp_dir(), 'artra').'.xlsx';
        (new Xlsx($spreadsheet))->save($path);
        $spreadsheet->disconnectWorksheets();

        return $path;
    }

    private function makeAtgSpreadsheet(): string
    {
        $rows = [
            ['Referencja', 'Rodzina rękawic', 'Opis rękawicy', 'Cena podstawowa'],
            ['19-007', 'MaxiDex®', 'Ściągacz, pełne oblanie', 17.88],
            ['24-985', 'NBR-Lite® (classicRange®)', 'Ściągacz, oblanie 3/4, żółta', 10.61],
            ['24-986', 'NBR-Lite® (classicRange®)', 'Ściągacz, pełne oblanie, żółta', 12.30],
            ['30-201', 'MaxiTherm® (classicRange®)', 'Ściągacz, oblanie części chwytnej', 29.21],
            ['30-202', 'MaxiTherm® (classicRange®)', 'Ściągacz, oblanie 3/4', 33.18],
            ['34-1743', 'MaxiFlex® Cut™', 'Ściągacz, oblanie części chwytnej, EN388: 3', 71.00],
            ['34-244', 'MaxiFlex® Elite™', 'Ściągacz, oblanie części chwytnej', 20.08],
            ['34-274', 'MaxiFlex® Elite™', 'Ściągacz, pełne oblanie', 16.76],
            ['34-304', 'MaxiCut® Oil™', 'Ściągacz, oblanie części chwytnej', 31.41],
            ['34-305', 'MaxiCut® Oil™', 'Ściągacz, oblanie 3/4', 33.96],
            ['34-450', 'MaxiCut®', 'Ściągacz, oblanie części chwytnej, EN388: 3', 39.55],
            ['34-450LP', 'MaxiCut®', 'Ściągacz, oblanie 3/4, skóra, EN388: 3', 64.85],
            ['34-504', 'MaxiCut® Oil™', 'Ściągacz, oblanie części chwytnej', 64.22],
            ['34-505', 'MaxiCut® Oil™', 'Ściągacz, oblanie 3/4', 66.71],
        ];

        $spreadsheet = new Spreadsheet;
        $sheet = $spreadsheet->getActiveSheet();
        $sheet->setTitle('Cennik');
        $sheet->fromArray($rows, null, 'A1', true);
        $path = tempnam(sys_get_temp_dir(), 'atg').'.xlsx';
        (new Xlsx($spreadsheet))->save($path);
        $spreadsheet->disconnectWorksheets();

        return $path;
    }
}
