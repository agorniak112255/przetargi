<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\B2bAccount;
use App\Models\Product;
use App\Models\ProductImage;
use App\Models\ProductImageRejection;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Sprzątanie galerii po regułach, które powstały później niż same wiersze: ten sam plik Shopify pod dwoma
 * adresami i zdjęcie innego wariantu tej samej serii, wyłowione kiedyś z sieci.
 */
final class ProductImageAuditCommandTest extends TestCase
{
    use RefreshDatabase;

    public function test_report_counts_without_deleting_anything(): void
    {
        $product = $this->productWithMess();

        $this->artisan('products:images-audit')
            ->expectsOutputToContain('Powtórzony plik:         1')
            ->expectsOutputToContain('Obce zdjęcie z sieci:    1')
            ->expectsOutputToContain('Raport — nic nie skasowano')
            ->assertSuccessful();

        $this->assertSame(4, ProductImage::query()->where('product_id', $product->id)->count());
    }

    public function test_apply_keeps_the_supplier_row_and_drops_the_mess(): void
    {
        $product = $this->productWithMess();

        $this->artisan('products:images-audit --apply')->assertSuccessful();

        $left = ProductImage::query()->where('product_id', $product->id)->orderBy('sort_order')->get();
        $this->assertSame(2, $left->count());
        $this->assertSame(
            ['https://cdn.shopify.com/s/files/1/2/3/files/ARDEA_310_618080_S1_PL_ESD.png', 'https://cdn.shopify.com/s/files/1/2/3/files/Lyftor-green.png'],
            $left->pluck('source_url')->map(static fn ($url): string => (string) $url)->all(),
        );
        $this->assertTrue((bool) $left->first()->is_primary);
        $this->assertSame([0, 1], $left->pluck('sort_order')->map(static fn ($o): int => (int) $o)->all());
    }

    public function test_supplier_photo_of_another_variant_is_left_alone(): void
    {
        $product = $this->product();
        $account = $this->account();
        // to, co dostawca pokazuje przy swojej karcie, jest jego decyzją — nawet gdy w nazwie ma inny wariant
        $this->image($product, 'https://cdn.shopify.com/s/files/1/2/3/files/ARDEA_310_619060_S1_ESD.png', $account->id, 0);

        $this->artisan('products:images-audit --apply')->assertSuccessful();

        $this->assertSame(1, ProductImage::query()->where('product_id', $product->id)->count());
    }

    public function test_web_photo_of_another_footwear_class_is_rejected_for_good(): void
    {
        // produkcja 23.09.2026: karta S1 PL Air ze zdjęciem wersji S3L z pierwszego pobierania
        $product = Product::query()->create([
            'sku' => 'ARDOR 330 Air 619060 S1 PL ESD',
            'name' => 'ARDOR 330 Air 619060 S1 PL ESD',
            'manufacturer' => 'ARTRA',
        ]);
        $account = $this->account();
        $this->image($product, 'https://cdn.shopify.com/s/files/1/2/3/files/ARDOR_330_Air_619060_S1_PL_ESD.png', $account->id, 0);
        $wrong = $this->image($product, 'https://artra.example.test/cdn/shop/files/ARDOR_330_619060_S3L_ESD.png?v=1764059517&width=1728', null, 1);
        // ta sama klasa innym zapisem (S1P = S1 PL) — zostaje
        $this->image($product, 'https://sklep.example/polbuty-artra-ardor-330-air-619060-s1p-esd.jpg', null, 2);

        $this->artisan('products:images-audit')
            ->expectsOutputToContain('Obce zdjęcie z sieci:    1')
            ->assertSuccessful();

        $this->artisan('products:images-audit --apply')->assertSuccessful();

        $this->assertNull(ProductImage::query()->find($wrong->id));
        $this->assertSame(2, ProductImage::query()->where('product_id', $product->id)->count());
        $rejection = ProductImageRejection::query()->where('product_id', $product->id)->sole();
        $this->assertSame(ProductImageRejection::REASON_AUDIT, $rejection->reason);
    }

    public function test_report_names_cards_left_without_a_photo(): void
    {
        $product = Product::query()->create([
            'sku' => 'ARAUKAN 940 1010 O2 FO',
            'name' => 'ARAUKAN 940 1010 O2 FO',
            'manufacturer' => 'ARTRA',
        ]);
        $this->image($product, 'https://sklep.example/buty-robocze-trzewiki-araukan-940-1010-s2-artra.jpg', null, 0);

        $this->artisan('products:images-audit')
            ->expectsOutputToContain('Po usunięciu zostaną bez zdjęcia (1): #'.$product->id)
            ->assertSuccessful();
    }

    private function productWithMess(): Product
    {
        $product = $this->product();
        $account = $this->account();
        $shop = 'https://cdn.shopify.com/s/files/1/2/3/files/';

        $this->image($product, $shop.'ARDEA_310_618080_S1_PL_ESD.png', $account->id, 0);
        $this->image($product, $shop.'Lyftor-green.png', $account->id, 1);
        // ten sam plik spod domeny sklepu — dla karty to drugi raz to samo zdjęcie
        $this->image($product, 'https://artra.example.test/cdn/shop/files/ARDEA_310_618080_S1_PL_ESD.png?v=1&width=1728', null, 2);
        // inny wariant tej samej serii, wyłowiony z sieci
        $this->image($product, 'https://sklep.example/buty-artra-ardea-310-619060-s1-esd.jpg', null, 3);

        return $product;
    }

    private function product(): Product
    {
        return Product::query()->create([
            'sku' => 'ARDEA 310 618080 S1 PL ESD',
            'name' => 'ARDEA 310 618080 S1 PL ESD',
            'manufacturer' => 'ARTRA',
        ]);
    }

    private function account(): B2bAccount
    {
        $user = User::factory()->create();

        return B2bAccount::query()->create([
            'username' => 'artra',
            'password' => 'sekret',
            'sites' => ['artra.example.test'],
            'connector' => 'artra',
            'created_by' => $user->id,
            'updated_by' => $user->id,
        ]);
    }

    private function image(Product $product, string $url, ?int $accountId, int $sortOrder): ProductImage
    {
        return ProductImage::query()->create([
            'product_id' => $product->id,
            'b2b_account_id' => $accountId,
            'path' => 'products/'.$product->id.'/'.md5($url).'.png',
            'source_url' => $url,
            'is_primary' => $sortOrder === 0,
            'sort_order' => $sortOrder,
            'checksum' => hash('sha256', $url),
        ]);
    }
}
