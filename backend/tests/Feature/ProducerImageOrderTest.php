<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\B2bAccount;
use App\Models\Product;
use App\Models\ProductImage;
use App\Services\Enrichment\ProductImageDownloader;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * Zdjęcie z witryny dostawcy przedstawia ten wariant wyrobu; zdjęcie wyłowione z internetu przez model
 * bywa innym kolorem albo innym modelem — testujący zgłosił buty w złym kolorze i ze znaczkiem ESD przy
 * modelu bez ESD. Kolejność zdjęć karty ma to odzwierciedlać, a ponowne wzbogacanie nie może tego cofać.
 */
final class ProducerImageOrderTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('public');
    }

    public function test_supplier_image_becomes_the_main_one_and_nothing_is_deleted(): void
    {
        $product = $this->product();
        $ai = $this->image($product, null, 0, true, 'https://sklep.example/foto.jpg');
        $producer = $this->image($product, $this->account()->id, 1, false, 'https://cdn.shopify.com/ARAL.png');

        ProductImage::resequence((int) $product->id);

        $this->assertSame([0, 1], [(int) $producer->fresh()->sort_order, (int) $ai->fresh()->sort_order]);
        $this->assertTrue((bool) $producer->fresh()->is_primary);
        $this->assertFalse((bool) $ai->fresh()->is_primary);
        $this->assertSame(2, ProductImage::query()->count());
    }

    public function test_second_run_does_not_touch_anything(): void
    {
        $product = $this->product();
        $this->image($product, $this->account()->id, 0, true, 'https://cdn.shopify.com/ARAL.png');
        $ai = $this->image($product, null, 1, false, 'https://sklep.example/foto.jpg');
        $before = $ai->fresh()->updated_at;

        ProductImage::resequence((int) $product->id);

        $this->assertEquals($before, $ai->fresh()->updated_at);
    }

    public function test_download_does_not_create_a_second_main_image(): void
    {
        $product = $this->product();
        $this->image($product, $this->account()->id, 0, true, 'https://cdn.shopify.com/ARAL.png');

        (new ProductImageDownloader)->storeBytes($product, $this->png(), 'image/png', 'https://sklep.example/foto.png', 0);
        ProductImage::resequence((int) $product->id);

        $primary = ProductImage::query()->where('product_id', $product->id)->where('is_primary', true)->get();
        $this->assertCount(1, $primary);
        $this->assertStringContainsString('cdn.shopify.com', (string) $primary->first()->source_url);
    }

    public function test_same_file_under_another_address_gets_the_supplier_stamp_instead_of_a_second_row(): void
    {
        $product = $this->product();
        $account = $this->account();
        $downloader = new ProductImageDownloader;
        $downloader->storeBytes($product, $this->png(), 'image/png', 'https://sklep.example/foto.png', 0);

        // ten sam plik, ale ze sklepu producenta — bez dopisania źródła pobieralibyśmy go w kółko
        $again = $downloader->storeBytes($product, $this->png(), 'image/png', 'https://cdn.shopify.com/ARAL.png', 1, (int) $account->id);

        $this->assertSame(1, ProductImage::query()->count());
        $this->assertSame('https://cdn.shopify.com/ARAL.png', (string) $again?->source_url);
        $this->assertSame((int) $account->id, (int) $again?->b2b_account_id);
    }

    private function product(): Product
    {
        return Product::query()->create([
            'sku' => 'ARAL 927 6160 O2 FO',
            'name' => 'ARAL 927 6160 O2 FO',
            'manufacturer' => 'ARTRA',
            'catalog_price_net' => 100,
            'purchase_price' => 80,
        ]);
    }

    private function account(): B2bAccount
    {
        return B2bAccount::query()->firstOrCreate(
            ['connector' => 'artra'],
            ['username' => 'ARTRA', 'sites' => ['artra.pl']],
        );
    }

    private function image(Product $product, ?int $accountId, int $sort, bool $primary, string $url): ProductImage
    {
        return ProductImage::query()->create([
            'product_id' => $product->id,
            'b2b_account_id' => $accountId,
            'path' => 'products/'.$product->id.'/'.md5($url).'.png',
            'source_url' => $url,
            'is_primary' => $primary,
            'sort_order' => $sort,
            'checksum' => hash('sha256', $url),
        ]);
    }

    private function png(): string
    {
        return base64_decode(
            'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mP8z8BQDwAEhQGAhKmMIQAAAABJRU5ErkJggg=='
        );
    }
}
