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
 * Stary import (POST /price-lists/import) nie przyjmuje pliku cennika przyjmowanego nowym sposobem (source_policy albo
 * importer_key, 10.10.2026) — zwykłe cenniki importują się jak dotąd.
 */
final class PriceListLegacyImportBlockTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolesAndPermissionsSeeder::class);
        Sanctum::actingAs(User::factory()->withRole('admin')->create());
    }

    public function test_import_is_blocked_for_intake_list(): void
    {
        $list = $this->list('ARTRA', ['source_policy' => PriceList::POLICY_MAP_ONLY]);

        $this->import('artra')
            ->assertStatus(422)
            ->assertJsonPath('price_list_id', $list->id)
            ->assertJsonPath('message', 'Ten cennik przyjmuje się nowym sposobem — dodaj plik w Cenniki → Z pliku (cennik ARTRA).');

        $this->assertSame(0, Product::query()->count());
        $this->assertSame('2025', $list->fresh()->version);
    }

    public function test_import_is_blocked_for_list_with_importer_only(): void
    {
        $this->list('ARTRA', ['importer_key' => 'artra-2026']);

        $this->import('Artra.')->assertStatus(422);
        $this->assertSame(0, Product::query()->count());
    }

    public function test_regular_list_imports_as_before(): void
    {
        $this->list('ARTRA');
        // inny producent z nowym sposobem nie blokuje
        $this->list('MAPA', ['source_policy' => PriceList::POLICY_MAP_ONLY]);

        $this->import('ARTRA')->assertCreated();

        $this->assertSame('ARTRA', Product::query()->sole()->manufacturer);
        $this->assertSame('2026-09', PriceList::query()->where('manufacturer_key', 'artra')->sole()->version);
    }

    /** @param  array<string, mixed>  $attributes */
    private function list(string $manufacturer, array $attributes = []): PriceList
    {
        return PriceList::query()->create([
            'manufacturer' => $manufacturer,
            'manufacturer_key' => PriceList::manufacturerKey($manufacturer),
            'version' => '2025',
            'rows_total' => 0,
            'products_created' => 0,
            'products_updated' => 0,
            'rows_skipped' => 0,
            'product_ids' => [],
            ...$attributes,
        ])->fresh();
    }

    private function import(string $manufacturer): TestResponse
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

        $response = $this->post('/api/price-lists/import', [
            'file' => new UploadedFile($path, 'ARTRA - cennik.xlsx', null, null, true),
            'manufacturer' => $manufacturer,
            'version' => '2026-09',
            'mapping' => json_encode([
                'manufacturer_detected' => $manufacturer,
                'currency' => 'PLN',
                'sheets' => [[
                    'sheet' => 'PL',
                    'include' => true,
                    'header_excel_row' => 1,
                    'columns' => ['sku' => 0, 'name' => 0, 'catalog_price' => 2],
                    'repeating_headers' => false,
                    'confidence' => 1.0,
                ]],
            ], JSON_THROW_ON_ERROR),
        ], ['Accept' => 'application/json']);
        @unlink($path);

        return $response;
    }
}
