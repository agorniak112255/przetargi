<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Jobs\ReindexProductEmbeddingJob;
use App\Models\Product;
use App\Models\ProductEnrichmentCache;
use App\Models\ProductImage;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use Tests\TestCase;

/**
 * Batch #308: polo DOVER zapisane jako „High visible trousers, padded” (nazwa z wiersza wyżej)
 * dostało opis i zdjęcia spodni. Naprawa bierze nazwę z tego samego pliku poprawionym importem.
 */
final class RepairPriceListNamesCommandTest extends TestCase
{
    use RefreshDatabase;

    private string $path;

    protected function setUp(): void
    {
        parent::setUp();
        $this->path = $this->makeCanisLikeSpreadsheet();
    }

    protected function tearDown(): void
    {
        @unlink($this->path);
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
    }

    public function test_apply_fixes_name_and_returns_card_to_enrichment(): void
    {
        Storage::fake('public');
        $dover = $this->wronglyNamedDover();
        Storage::disk('public')->put('products/'.$dover->id.'/spodnie.jpg', 'jpg');
        ProductImage::query()->create([
            'product_id' => $dover->id,
            'path' => 'products/'.$dover->id.'/spodnie.jpg',
            'source_url' => 'https://sklep.example.pl/spodnie-ocieplane.jpg',
            'is_primary' => true,
            'sort_order' => 0,
            'checksum' => str_repeat('a', 64),
        ]);
        ProductImage::query()->create([
            'product_id' => $dover->id,
            'path' => 'products/'.$dover->id.'/wgrane-recznie.jpg',
            'source_url' => null,
            'is_primary' => false,
            'sort_order' => 1,
            'checksum' => str_repeat('b', 64),
        ]);
        ProductEnrichmentCache::query()->create([
            ...ProductEnrichmentCache::normalizeKey('Canis', '1113-001-000-00'),
            'description' => 'Spodnie ostrzegawcze ocieplane',
            'source_urls' => ['https://sklep.example.pl/spodnie-ocieplane'],
        ]);
        $solis = $this->product('1010-130-260-00', 'Men´s jacket CXS SOLIS FLEX, red-black', 'Kurtka robocza CXS SOLIS FLEX.');
        $cutSize = $this->product('1010-130-270-00', 'Men´s jacket CXS SOLIS FLEX, blue-black, size 46 -', 'Kurtka robocza CXS SOLIS FLEX, niebiesko-czarna.');
        Queue::fake(); // po zapisie produktów — sam zapis też reindeksuje

        $this->artisan('products:repair-price-list-names', ['file' => $this->path, '--manufacturer' => 'Canis', '--apply' => true])
            ->expectsOutputToContain('Poprawiono 2 nazw; 1 kart wraca')
            ->assertSuccessful();

        $dover->refresh();
        $this->assertStringContainsString('polo shirt', $dover->name);
        $this->assertNull($dover->description);
        $this->assertNull($dover->shop_source_url);
        $this->assertSame(Product::ENRICHMENT_NONE, $dover->enrichment_status);
        $this->assertSame(0, ProductEnrichmentCache::query()->count(), 'cache SKU→karta spodni');
        $this->assertSame(['products/'.$dover->id.'/wgrane-recznie.jpg'], ProductImage::query()->pluck('path')->all(), 'zostaje tylko zdjęcie wgrane ręcznie');
        Storage::disk('public')->assertMissing('products/'.$dover->id.'/spodnie.jpg');
        Queue::assertPushed(ReindexProductEmbeddingJob::class);

        $this->assertSame('Kurtka robocza CXS SOLIS FLEX.', $solis->fresh()->description, 'karta z poprawną nazwą nietknięta');
        $this->assertSame(Product::ENRICHMENT_DONE, $solis->fresh()->enrichment_status);

        $cutSize->refresh();
        $this->assertSame('Men´s jacket CXS SOLIS FLEX, blue-black', $cutSize->name, 'ucięty rozmiar poprawiony');
        $this->assertSame('Kurtka robocza CXS SOLIS FLEX, niebiesko-czarna.', $cutSize->description, 'ten sam wyrób — opis zostaje');
        $this->assertSame(Product::ENRICHMENT_DONE, $cutSize->enrichment_status);
    }

    private function wronglyNamedDover(): Product
    {
        return $this->product(
            '1113-001-000-00',
            'High visible trousers, padded, colour yellow-blue, orange-blue, size M-3XL, EN 20471',
            'Spodnie ostrzegawcze ocieplane CXS.',
        );
    }

    private function product(string $sku, string $name, string $description): Product
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
            'enrichment_status' => Product::ENRICHMENT_DONE,
            'enriched_at' => now(),
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
