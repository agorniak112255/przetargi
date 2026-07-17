<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Models\User;
use App\Services\PriceListImportService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Tests\TestCase;

final class PriceListImportMappingTest extends TestCase
{
    use RefreshDatabase;

    public function test_imports_with_explicit_mapping(): void
    {
        $path = base_path('../samples/cennik_demo.xlsx');
        if (! is_file($path)) {
            $this->markTestSkipped('Brak samples/cennik_demo.xlsx');
        }

        $user = User::factory()->create();
        $service = app(PriceListImportService::class);
        $file = new UploadedFile($path, 'cennik_demo.xlsx', null, null, true);

        $mapping = [
            'manufacturer_detected' => 'Lebon',
            'currency' => 'PLN',
            'notes' => 'test',
            'sheets' => [
                [
                    'sheet' => 'Arkusz1',
                    'include' => true,
                    'header_excel_row' => 1,
                    'columns' => [
                        'sku' => 0,
                        'name' => 1,
                        'catalog_price' => 5,
                        'discount' => 6,
                        'purchase' => null,
                        'ean' => null,
                        'category' => 3,
                    ],
                    'repeating_headers' => false,
                    'confidence' => 1.0,
                ],
            ],
        ];

        // jeśli nazwa arkusza inna — wykryj
        $spreadsheet = \PhpOffice\PhpSpreadsheet\IOFactory::load($path);
        $mapping['sheets'][0]['sheet'] = $spreadsheet->getActiveSheet()->getTitle();

        $result = $service->importWithMapping($file, 'Lebon', 'demo', $user, $mapping, 'rekawice');

        $this->assertNotNull($result['price_list']);
        $this->assertGreaterThan(0, $result['created'] + $result['updated']);
    }
}
