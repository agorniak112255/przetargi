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

    public function test_hint_keeps_the_manufacturer_filter(): void
    {
        $this->productWithMess();

        $this->artisan('products:images-audit --manufacturer=ARTRA')
            ->expectsOutputToContain('products:images-audit --manufacturer=ARTRA --apply')
            ->assertSuccessful();
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

    public function test_web_photos_go_only_with_the_flag_and_only_when_the_manufacturer_account_has_a_photo(): void
    {
        // Bolle 28.09.2026: karta z importu pliku ze zdjęciami ze sklepów, a konto producenta dało już swoje
        $user = User::factory()->create();
        $bolle = B2bAccount::query()->create([
            'username' => 'bolle', 'password' => 'sekret', 'sites' => ['b2b.bolle-safety.com'], 'connector' => 'bolle',
            'created_by' => $user->id, 'updated_by' => $user->id,
        ]);
        $withOwn = Product::query()->create(['sku' => 'BAXCSP', 'name' => 'BAXTER – Miedziane okulary ochronne', 'manufacturer' => 'Bolle']);
        $this->image($withOwn, 'https://b2b.bolle-safety.com/core/media/media.nl?id=900&h=main', $bolle->id, 0);
        $shop = $this->image($withOwn, 'https://www.specshop.pl/okulary-bolle-baxter.jpg', null, 1);
        // karta bez zdjęcia producenta — zdjęcie ze sklepu zostaje, innego nie ma
        $withoutOwn = Product::query()->create(['sku' => 'BAXPSI', 'name' => 'BAXTER – Przezroczyste okulary ochronne', 'manufacturer' => 'Bolle']);
        $this->image($withoutOwn, 'https://www.specshop.pl/okulary-bolle-baxter-clear.jpg', null, 0);
        // zdjęcie od konta, które nie jest producentem marki karty (dystrybutor) nie wystarcza
        $distributor = $this->account();
        $viaDistributor = Product::query()->create(['sku' => 'BAXPSF', 'name' => 'BAXTER – Przyciemniane okulary ochronne', 'manufacturer' => 'Bolle']);
        $this->image($viaDistributor, 'https://artra.example.test/baxpsf.jpg', $distributor->id, 0);
        $this->image($viaDistributor, 'https://www.specshop.pl/okulary-bolle-baxter-smoke.jpg', null, 1);

        $this->artisan('products:images-audit --apply')->assertSuccessful();
        $this->assertSame(5, ProductImage::query()->count());

        $this->artisan('products:images-audit --web-with-manufacturer')
            ->expectsOutputToContain('Obce zdjęcie z sieci:    1')
            ->expectsOutputToContain('products:images-audit --web-with-manufacturer --apply')
            ->assertSuccessful();
        $this->artisan('products:images-audit --web-with-manufacturer --apply')->assertSuccessful();

        $this->assertNull(ProductImage::query()->find($shop->id));
        $this->assertSame(1, ProductImage::query()->where('product_id', $withOwn->id)->count());
        $this->assertSame(1, ProductImage::query()->where('product_id', $withoutOwn->id)->count());
        $this->assertSame(2, ProductImage::query()->where('product_id', $viaDistributor->id)->count());
        $this->assertSame(ProductImageRejection::REASON_AUDIT, ProductImageRejection::query()->where('product_id', $withOwn->id)->sole()->reason);
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

    /** 05.10.2026: zdjęcia ze sklepów za zablokowany plik ansell.com — inny model w nazwie pliku i logo witryny. */
    public function test_foreign_glove_model_and_site_logo_go_and_unclear_cases_are_only_listed(): void
    {
        $alphatec = $this->ansell('8352100', 'AlphaTec 08352');
        $wrongModel = $this->image($alphatec, 'https://cas-technik.eu/media/75/1c/80/1689063534/08-354tuseouxsipd4p.jpg?ts=1756127514', null, 0);
        $ringers = $this->ansell('259-13', 'Ringers 259');
        $logo = $this->image($ringers, 'https://portolana.pl/wp-content/uploads/2025/07/cropped-photo_2025-07-14_18-39-36-Edited-1.png', null, 0);
        // ten sam plik na dwóch modelach — nie wiadomo, który jest właściwy
        $shared = 'https://imagedelivery.net/ICWTp6FWPGokq8hKKaA1Qg/62c65932-d041-4246-2dd9-8fca64eb0800/large';
        $r169 = $this->image($this->ansell('R169SD-13', 'Ringers 169SD'), $shared, null, 0);
        $r840 = $this->image($this->ansell('840-12VP', 'Ringers R840VP'), $shared, null, 0);
        // warianty szerokości tego samego modelu dzielą zdjęcie — to w porządku
        $sizeShared = 'https://bhp-sklep.com.pl/wp-content/uploads/2023/11/01180035-17286.png';
        $this->image($this->ansell('11250160-N', 'HyFlex 11250 NARROW NO THUMB S'), $sizeShared, null, 0);
        $this->image($this->ansell('11250160-W', 'HyFlex 11250 WIDE NO THUMB SLO'), $sizeShared, null, 0);
        // karta bez opisu ze zdjęciem, którego adres nie nazywa wyrobu
        $kleenguard = $this->ansell('36920', 'KLNGD A10 Accessories Bouffant Wht/Grn L');
        $kleenguard->forceFill(['enrichment_status' => Product::ENRICHMENT_MANUAL])->save();
        $game = $this->image($kleenguard, 'https://agamecdn.com/assets/a10/og_image-0235b0fe.jpg', null, 0);

        $this->artisan('products:images-audit --manufacturer=Ansell')
            ->expectsOutputToContain('Obce zdjęcie z sieci:    2')
            ->expectsOutputToContain('Do przejrzenia (--apply ich nie usuwa): 3 wierszy')
            ->doesntExpectOutputToContain('11250160-N  ←')
            ->assertSuccessful();

        $this->artisan('products:images-audit --manufacturer=Ansell --apply')->assertSuccessful();

        $this->assertNull(ProductImage::query()->find($wrongModel->id));
        $this->assertNull(ProductImage::query()->find($logo->id));
        foreach ([$r169, $r840, $game] as $kept) {
            $this->assertNotNull(ProductImage::query()->find($kept->id), 'lista do przejrzenia nie jest kasowana');
        }
        $this->assertSame(2, ProductImageRejection::query()->count());
    }

    private function ansell(string $sku, string $name): Product
    {
        return Product::query()->create(['sku' => $sku, 'name' => $name, 'manufacturer' => 'Ansell']);
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
