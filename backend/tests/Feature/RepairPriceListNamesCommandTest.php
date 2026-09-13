<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Jobs\ReindexProductEmbeddingJob;
use App\Models\Product;
use App\Models\ProductAccessory;
use App\Models\ProductDocument;
use App\Models\ProductEnrichmentCache;
use App\Models\ProductImage;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use Tests\TestCase;

/**
 * Batch #308/#312: polo DOVER zapisane jako „High visible trousers, padded” (nazwa z wiersza wyżej)
 * dostało opis, zdjęcia i dokumenty spodni. Naprawa bierze nazwę z tego samego pliku poprawionym
 * importem, robi kopię zapasową i pozwala ją przywrócić.
 */
final class RepairPriceListNamesCommandTest extends TestCase
{
    use RefreshDatabase;

    private string $path;

    private string $backup;

    protected function setUp(): void
    {
        parent::setUp();
        $this->path = $this->makeCanisLikeSpreadsheet();
        $this->backup = sys_get_temp_dir().DIRECTORY_SEPARATOR.'repair-names-'.uniqid('', true).'.json';
    }

    protected function tearDown(): void
    {
        @unlink($this->path);
        @unlink($this->backup);
        parent::tearDown();
    }

    public function test_dry_run_lists_but_does_not_change(): void
    {
        $dover = $this->wronglyNamedDover();
        Queue::fake(); // po zapisie produktu — sam zapis też reindeksuje

        $this->artisan('products:repair-price-list-names', ['file' => $this->path, '--manufacturer' => 'Canis'])
            ->expectsOutputToContain('Do poprawy: 1 nazw')
            ->assertSuccessful();

        $this->assertSame('High visible trousers, padded, colour yellow-blue, orange-blue, size M-3XL, EN 20471', $dover->fresh()->name);
        $this->assertNotNull($dover->fresh()->description);
        Queue::assertNothingPushed();
        $this->assertFileDoesNotExist($this->backup);
    }

    public function test_apply_resets_web_data_keeps_manual_work_and_restore_brings_state_back(): void
    {
        $dover = $this->wronglyNamedDover();
        $this->attachWebData($dover);
        ProductImage::query()->create([
            'product_id' => $dover->id, 'path' => 'products/'.$dover->id.'/wgrane-recznie.jpg',
            'source_url' => null, 'is_primary' => false, 'sort_order' => 1, 'checksum' => str_repeat('b', 64),
        ]);
        ProductAccessory::query()->create([
            'product_id' => $dover->id, 'source' => ProductAccessory::SOURCE_MANUAL, 'link_key' => 'manual:1', 'related_sku' => 'X-1',
        ]);
        $solis = $this->product('1010-130-260-00', 'Men´s jacket CXS SOLIS FLEX, red-black', 'Kurtka robocza CXS SOLIS FLEX.');
        $cutSize = $this->product('1010-130-270-00', 'Men´s jacket CXS SOLIS FLEX, blue-black, size 46 -', 'Kurtka robocza CXS SOLIS FLEX, niebiesko-czarna.');
        $handWritten = $this->product('1112-001-000-00', 'Filter type 6055 A2 against gases', 'Opis wpisany ręcznie przez handlowca.', Product::ENRICHMENT_MANUAL);
        Queue::fake(); // po zapisie produktów — sam zapis też reindeksuje

        $this->artisan('products:repair-price-list-names', [
            'file' => $this->path, '--manufacturer' => 'Canis', '--apply' => true, '--backup' => $this->backup,
        ])
            ->expectsOutputToContain('Poprawiono 3 nazw; 1 kart wraca')
            ->assertSuccessful();

        $this->assertFileExists($this->backup);
        $dover->refresh();
        $this->assertStringContainsString('polo shirt', $dover->name);
        $this->assertNull($dover->description);
        $this->assertNull($dover->shop_source_url);
        $this->assertNull($dover->packaging, 'rozmiary z cudzej karty znikają — cennik ich nie podaje');
        $this->assertSame(Product::ENRICHMENT_NONE, $dover->enrichment_status);
        $this->assertSame(0, ProductEnrichmentCache::query()->count(), 'cache SKU→karta spodni');
        $this->assertSame(['products/'.$dover->id.'/wgrane-recznie.jpg'], ProductImage::query()->pluck('path')->all(), 'zostaje tylko zdjęcie wgrane ręcznie');
        $this->assertSame(0, ProductDocument::query()->count(), 'karta charakterystyki spodni');
        $this->assertSame([ProductAccessory::SOURCE_MANUAL], ProductAccessory::query()->pluck('source')->all());
        Queue::assertPushed(ReindexProductEmbeddingJob::class);

        $this->assertSame('Kurtka robocza CXS SOLIS FLEX.', $solis->fresh()->description, 'karta z poprawną nazwą nietknięta');
        $this->assertSame('Men´s jacket CXS SOLIS FLEX, blue-black', $cutSize->fresh()->name, 'ucięty rozmiar poprawiony');
        $this->assertSame('Kurtka robocza CXS SOLIS FLEX, niebiesko-czarna.', $cutSize->fresh()->description, 'ten sam wyrób — opis zostaje');
        $this->assertSame('Men´s shorts CXS LEONIS, black with blue/red accessories', $handWritten->fresh()->name);
        $this->assertSame('Opis wpisany ręcznie przez handlowca.', $handWritten->fresh()->description, '„Wpisz ręcznie” z opisem człowieka nie jest kasowany');

        $this->artisan('products:repair-price-list-names', ['--restore' => $this->backup])
            ->expectsOutputToContain('Przywrócono 3 produktów')
            ->assertSuccessful();

        $dover->refresh();
        $this->assertSame('High visible trousers, padded, colour yellow-blue, orange-blue, size M-3XL, EN 20471', $dover->name);
        $this->assertSame('Spodnie ostrzegawcze ocieplane CXS.', $dover->description);
        $this->assertSame('M, L, XL', $dover->packaging);
        $this->assertSame(Product::ENRICHMENT_DONE, $dover->enrichment_status);
        $this->assertSame(['priority' => 'x'], $dover->enrichment_payload);
        $this->assertSame(2, ProductImage::query()->where('product_id', $dover->id)->count());
        $this->assertSame(1, ProductDocument::query()->count());
        $this->assertSame(2, ProductAccessory::query()->count());
        $this->assertSame(1, ProductEnrichmentCache::query()->count());
    }

    private function attachWebData(Product $product): void
    {
        ProductImage::query()->create([
            'product_id' => $product->id, 'path' => 'products/'.$product->id.'/spodnie.jpg',
            'source_url' => 'https://sklep.example.pl/spodnie-ocieplane.jpg', 'is_primary' => true, 'sort_order' => 0,
            'checksum' => str_repeat('a', 64),
        ]);
        ProductDocument::query()->create([
            'product_id' => $product->id, 'path' => 'products/'.$product->id.'/karta.pdf',
            'source_url' => 'https://sklep.example.pl/karta-spodni.pdf', 'title' => 'Karta spodni', 'kind' => 'datasheet',
            'sort_order' => 0, 'checksum' => str_repeat('c', 64), 'size_bytes' => 10,
        ]);
        ProductAccessory::query()->create([
            'product_id' => $product->id, 'source' => ProductAccessory::SOURCE_ENRICHMENT, 'link_key' => 'enrichment:pas',
            'related_sku' => 'PAS-1', 'related_name' => 'Pas do spodni',
        ]);
        ProductEnrichmentCache::query()->create([
            ...ProductEnrichmentCache::normalizeKey('Canis', (string) $product->sku),
            'description' => 'Spodnie ostrzegawcze ocieplane',
            'source_urls' => ['https://sklep.example.pl/spodnie-ocieplane'],
        ]);
    }

    private function wronglyNamedDover(): Product
    {
        $product = $this->product(
            '1113-001-000-00',
            'High visible trousers, padded, colour yellow-blue, orange-blue, size M-3XL, EN 20471',
            'Spodnie ostrzegawcze ocieplane CXS.',
        );
        $product->forceFill(['packaging' => 'M, L, XL', 'enrichment_payload' => ['priority' => 'x']])->save();

        return $product->fresh();
    }

    private function product(string $sku, string $name, string $description, string $status = Product::ENRICHMENT_DONE): Product
    {
        return Product::query()->create([
            'sku' => $sku,
            'name' => $name,
            'manufacturer' => 'Canis',
            'description' => $description,
            'shop_source_url' => 'https://sklep.example.pl/karta',
            'catalog_price_net' => 30,
            'purchase_price' => 30,
            'stock' => 1,
            'enrichment_status' => $status,
            'enriched_at' => $status === Product::ENRICHMENT_DONE ? now() : null,
        ]);
    }

    private function makeCanisLikeSpreadsheet(): string
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
        $rows[] = [287, '1112-001-000-00', 'LEONIS', null, 'Men´s shorts CXS LEONIS, black with blue/red accessories', 'pcs', '1/20', 40.0];
        $rows[] = [156, '1113-001-000-00', 'DOVER', null, 'High visible, polo shirt, 100% polyester, colour orange, yellow, size M – 3XL, EN ISO 20471, EN 13688', 'pcs', '1/20', 26.36];

        $spreadsheet = new Spreadsheet;
        $sheet = $spreadsheet->getActiveSheet();
        $sheet->setTitle('Price list');
        $sheet->fromArray($rows, null, 'A1', true);
        $path = tempnam(sys_get_temp_dir(), 'canis').'.xlsx';
        (new Xlsx($spreadsheet))->save($path);
        $spreadsheet->disconnectWorksheets();

        return $path;
    }
}
