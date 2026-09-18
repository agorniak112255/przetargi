<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Product;
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
