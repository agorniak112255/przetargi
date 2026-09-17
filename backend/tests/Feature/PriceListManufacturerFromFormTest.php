<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\PriceList;
use App\Models\Product;
use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Testing\TestResponse;
use Laravel\Sanctum\Sanctum;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use Tests\TestCase;

/**
 * Producent wpisany w formularzu importu to decyzja człowieka i ma wygrywać z tym, co wykryła analiza.
 * W cenniku ARTRY nagłówek arkusza niesie markę konstrukcji ARELAX® i to ją rozpoznaje model, choć
 * producentem jest ARTRA — poprawka wpisana ręcznie przepadała, bo brana była tylko wtedy, gdy
 * zgadzała się z nazwą pliku albo z odpowiedzią modelu.
 */
final class PriceListManufacturerFromFormTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolesAndPermissionsSeeder::class);
        Sanctum::actingAs(User::factory()->withRole('admin')->create());
    }

    public function test_typed_manufacturer_wins_over_the_one_detected_by_ai(): void
    {
        $this->importArtra('ARTRA', 'ARELAX')->assertSuccessful();

        $this->assertSame('ARTRA', PriceList::query()->sole()->manufacturer);
        $this->assertSame('artra', PriceList::query()->sole()->manufacturer_key);
        $this->assertSame('ARTRA', Product::query()->sole()->manufacturer);
    }

    public function test_detected_manufacturer_is_used_when_the_form_is_empty(): void
    {
        $this->importArtra('', 'ARELAX')->assertSuccessful();

        $this->assertSame('ARELAX', PriceList::query()->sole()->manufacturer);
    }

    public function test_typed_version_wins_over_the_one_read_from_the_file_name(): void
    {
        $this->importArtra('ARTRA', 'ARELAX', '2026-09')->assertSuccessful();

        $this->assertSame('2026-09', PriceList::query()->sole()->version);
    }

    private function importArtra(string $formManufacturer, string $detected, string $version = ''): TestResponse
    {
        $path = $this->sheet();
        $payload = [
            'file' => new UploadedFile($path, 'ARTRA - cennik 2024-01.xlsx', null, null, true),
            'mapping' => json_encode([
                'manufacturer_detected' => $detected,
                'currency' => 'EUR',
                'sheets' => [[
                    'sheet' => 'PL',
                    'include' => true,
                    'header_excel_row' => 1,
                    'columns' => ['sku' => 0, 'name' => 0, 'catalog_price' => 2],
                    'repeating_headers' => false,
                    'confidence' => 1.0,
                ]],
            ], JSON_THROW_ON_ERROR),
        ];
        if ($formManufacturer !== '') {
            $payload['manufacturer'] = $formManufacturer;
        }
        if ($version !== '') {
            $payload['version'] = $version;
        }

        $response = $this->post('/api/price-lists/import', $payload);
        @unlink($path);

        return $response;
    }

    private function sheet(): string
    {
        $spreadsheet = new Spreadsheet;
        $sheet = $spreadsheet->getActiveSheet();
        $sheet->setTitle('PL');
        $sheet->fromArray([
            ['artykuł', 'typ', 'bez VAT'],
            ['ARAGON 920 6060 S2', 'półbuty', 36.19],
        ], null, 'A1');

        $path = tempnam(sys_get_temp_dir(), 'artra').'.xlsx';
        (new Xlsx($spreadsheet))->save($path);
        $spreadsheet->disconnectWorksheets();

        return $path;
    }
}
