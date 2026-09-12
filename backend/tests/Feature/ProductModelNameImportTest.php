<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Product;
use App\Models\User;
use App\Services\Enrichment\ProductSearchIdentity;
use App\Services\PriceListImportService;
use App\Services\SpreadsheetColumnMapper;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use Tests\TestCase;

/**
 * Cennik Bollé: kod BAXCSP nie istnieje w sklepach jako słowo, nazwa produktu to
 * opis soczewek — tożsamość niesie kolumna „Nazwa Modelu” (BAXTER, TRACKER).
 */
final class ProductModelNameImportTest extends TestCase
{
    use RefreshDatabase;

    public function test_import_keeps_model_name_and_identity_uses_it(): void
    {
        $this->importBolle(true);

        $bax = Product::query()->where('sku', 'BAXCSP')->firstOrFail();
        $this->assertSame('BAXTER', $bax->model_name);
        $this->assertSame('TRACKER', Product::query()->where('sku', 'TRACPSF')->firstOrFail()->model_name);
        $this->assertStringContainsString('baxter', (string) $bax->search_blob);

        $id = new ProductSearchIdentity;
        $this->assertTrue($id->hayMentionsProduct(
            'https://sklep.pl/okulary-ochronne-bolle-baxter-baxcsp Okulary ochronne Bollé Baxter BAXCSP',
            $bax
        ));
        // karta rodzeństwa: ten sam model, inny kod z cennika (soczewki smoke zamiast CSP)
        $this->assertFalse($id->hayMentionsProduct(
            'https://sklep.pl/okulary-ochronne-bolle-baxter-baxpsf Okulary ochronne Bollé Baxter BAXPSF',
            $bax
        ));

        // kolejny cennik bez kolumny modelu nie kasuje nazwy modelu
        $this->importBolle(false);
        $this->assertSame('BAXTER', Product::query()->where('sku', 'BAXCSP')->firstOrFail()->model_name);
        $this->assertSame(3, Product::query()->count());
    }

    private function importBolle(bool $withModelColumn): void
    {
        $rows = [
            ['Product Category 1', 'Nazwa Modelu', 'SKU', 'STATUS', 'EMEA Price EUR 2026', 'Opis produktu', 'EAN unit'],
            ['INDUSTRIAL', 'BAXTER', 'BAXCSP', 'Active', 18.55, 'Soczewki Copper PC (CSP) - powłoki PLATINUM - uszczelka piankowa i taśma elastyczna w kompletacji', '3660740007768'],
            ['INDUSTRIAL', 'BAXTER', 'BAXPSF', 'Active', 18.95, 'Przyciemnione (smoke) soczewki PC - powłoki PLATINUM - uszczelka piankowa i taśma elastyczna w kompletacji', '3660740007751'],
            ['INDUSTRIAL', 'TRACKER', 'TRACPSF', 'Active', 17.45, 'Przyciemnione (smoke) soczewki PC - powłoki PLATINUM - oprawy z czarnego nylonu', '3660740004835'],
        ];
        if (! $withModelColumn) {
            $rows = array_map(static fn (array $row): array => [$row[0], ...array_slice($row, 2)], $rows);
        }
        $spreadsheet = new Spreadsheet;
        $sheet = $spreadsheet->getActiveSheet();
        $sheet->setTitle('INDUSTRIAL');
        $sheet->fromArray($rows, null, 'A1', true);
        $path = tempnam(sys_get_temp_dir(), 'bolle').'.xlsx';
        (new Xlsx($spreadsheet))->save($path);
        $spreadsheet->disconnectWorksheets();

        $shift = $withModelColumn ? 0 : -1;
        try {
            $result = app(PriceListImportService::class)->importWithMapping(
                new UploadedFile($path, 'bolle.xlsx', null, null, true),
                'Bole',
                $withModelColumn ? '2026-01' : '2026-02',
                User::factory()->create(),
                app(SpreadsheetColumnMapper::class)->refineMapping($path, [
                    'currency' => 'EUR',
                    'sheets' => [[
                        'sheet' => 'INDUSTRIAL',
                        'include' => true,
                        'header_excel_row' => 1,
                        'columns' => ['sku' => 2 + $shift, 'name' => 5 + $shift, 'catalog_price' => 4 + $shift],
                        'repeating_headers' => false,
                        'confidence' => 1.0,
                    ]],
                ]),
            );
            $this->assertNotNull($result['price_list'], implode('; ', $result['errors'] ?? []));
        } finally {
            @unlink($path);
        }
    }
}
