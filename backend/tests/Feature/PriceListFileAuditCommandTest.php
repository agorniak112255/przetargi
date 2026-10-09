<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\PriceList;
use App\Models\Product;
use App\Models\ProductSourcePrice;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use Tests\TestCase;

/**
 * products:price-list-file-audit — tylko odczyt: karty cennika wobec pliku (plan naprawy importu 10.10.2026, część C).
 * Klasy: kod w pliku / podejrzenie ucięcia dawną regułą rozmiaru (AF0100 ← AF010005, DP0100-11 ← DP010011) / brak.
 */
final class PriceListFileAuditCommandTest extends TestCase
{
    use RefreshDatabase;

    private string $dir;

    protected function setUp(): void
    {
        parent::setUp();
        Queue::fake();
        $this->dir = sys_get_temp_dir().DIRECTORY_SEPARATOR.'audit-'.bin2hex(random_bytes(4));
        mkdir($this->dir.DIRECTORY_SEPARATOR.'2026', 0777, true);
        $spreadsheet = new Spreadsheet;
        $sheet = $spreadsheet->getActiveSheet();
        $sheet->setTitle('Maty przemysłowe');
        $sheet->fromArray([
            ['Numer produktu', 'Nazwa. kolor. wymiar produktu', 'Waga [kg]', "ExWorks'26"],
            [null, 'Orthomat Standard', null, null],
            ['AF010003', 'Orthomat Standard Czarny 0.9m x 18.3m (9.5mm)', 45, 1934.02],
            ['AF010005', 'Orthomat Standard Czarny 1.2m x 18.3m (9.5mm)', 51, 2608.70],
            ['AF010006', 'Orthomat Standard Czarny 1.5m x 18.3m (9.5mm)', 60, 2608.70],
            ['DP010011', 'Deckplate Czarny 1.5m x 15m (15mm)', 200, 9318.12],
            ['SP070001C', 'SitePath Żółty 1m x mb. (2mm)', 3, 61.87],
            ['SP070001C5', 'SitePath Żółty 1m x 5m (2mm)', 14, 309.37],
            ['YO YO METAL', 'Zaczep Heavy Duty YoYo', 0.05, 27.37],
        ], null, 'A1', true);
        (new Xlsx($spreadsheet))->save($this->path());
        $spreadsheet->disconnectWorksheets();
    }

    protected function tearDown(): void
    {
        @unlink($this->path());
        @unlink($this->dir.DIRECTORY_SEPARATOR.'export.json');
        @unlink($this->dir.DIRECTORY_SEPARATOR.'audit.csv');
        @rmdir($this->dir.DIRECTORY_SEPARATOR.'2026');
        @rmdir($this->dir);
        parent::tearDown();
    }

    public function test_cards_of_the_price_list_are_classified_against_the_file_without_writing(): void
    {
        $user = User::factory()->create();
        $list = PriceList::query()->create([
            'manufacturer' => 'Coba', 'manufacturer_key' => 'coba', 'version' => '2026',
            'original_filename' => 'PLN - COBA Price Book 2026.xlsx', 'imported_by' => $user->id, 'rows_total' => 0,
        ]);
        foreach (['AF010003', 'AF0100', 'DP0100-11', 'SP070001C', 'YO YO METAL', 'ZZ999999'] as $sku) {
            $card = Product::query()->create(['sku' => $sku, 'name' => 'Karta '.$sku, 'manufacturer' => 'Coba', 'catalog_price_net' => 1, 'purchase_price' => 1]);
            ProductSourcePrice::query()->create(['product_id' => $card->id, 'source_key' => ProductSourcePrice::SOURCE_FILE, 'price_list_id' => $list->id, 'catalog_price_net' => 1, 'purchase_price' => 1, 'currency' => 'PLN']);
        }
        $csv = $this->dir.DIRECTORY_SEPARATOR.'audit.csv';
        $before = Product::query()->orderBy('id')->pluck('sku', 'id')->all();

        $this->artisan('products:price-list-file-audit', ['file' => $this->path(), '--price-list' => $list->id, '--csv' => $csv])
            ->expectsOutputToContain('kod w pliku: 3')
            ->expectsOutputToContain('podejrzenie ucięcia: 2')
            ->expectsOutputToContain('brak w pliku: 1')
            ->assertSuccessful();

        $this->assertSame($before, Product::query()->orderBy('id')->pluck('sku', 'id')->all());
        $classes = [];
        foreach (array_slice(array_map(static fn (string $l): array => str_getcsv($l, ';'), file($csv, FILE_IGNORE_NEW_LINES)), 1) as $row) {
            $classes[$row[1]] = [$row[3], $row[4]];
        }
        // SP070001C to kod wiersza pliku, choć jest też dawnym rdzeniem SP070001C5 — kod w pliku
        $this->assertSame('kod w pliku', $classes['SP070001C'][0]);
        $this->assertSame('kod w pliku', $classes['YO YO METAL'][0]);
        $this->assertSame(['podejrzenie ucięcia', 'AF010005, AF010006'], $classes['AF0100']);
        // ten sam kod bez separatorów, ale zapis dawnej reguły (rdzeń-rozmiar)
        $this->assertSame(['podejrzenie ucięcia', 'DP010011'], $classes['DP0100-11']);
        $this->assertSame('brak w pliku', $classes['ZZ999999'][0]);
    }

    public function test_production_export_with_a_folder_of_price_lists(): void
    {
        file_put_contents($this->dir.DIRECTORY_SEPARATOR.'export.json', json_encode(['price_lists' => [
            ['id' => 14, 'manufacturer' => 'Coba', 'original_filename' => 'PLN - COBA Price Book 2026.xlsx', 'cards' => [
                ['id' => 1, 'sku' => 'AF010003', 'name' => 'a'], ['id' => 2, 'sku' => 'AF0100', 'name' => 'b'],
            ]],
            ['id' => 20, 'manufacturer' => 'Inny', 'original_filename' => 'nie-ma-takiego.xlsx', 'cards' => [['id' => 3, 'sku' => 'X1', 'name' => 'c']]],
        ]]));

        $this->artisan('products:price-list-file-audit', ['file' => $this->dir, '--cards-json' => $this->dir.DIRECTORY_SEPARATOR.'export.json'])
            ->expectsOutputToContain('brak pliku w katalogu')
            ->assertSuccessful();

        // pojedynczy cennik ze zrzutu
        $this->artisan('products:price-list-file-audit', [
            'file' => $this->path(), '--cards-json' => $this->dir.DIRECTORY_SEPARATOR.'export.json', '--price-list' => '14',
        ])->expectsOutputToContain('podejrzenie ucięcia: 1')->assertSuccessful();
    }

    private function path(): string
    {
        return $this->dir.DIRECTORY_SEPARATOR.'2026'.DIRECTORY_SEPARATOR.'PLN - COBA Price Book 2026.xlsx';
    }
}
