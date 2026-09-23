<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\B2bAccount;
use App\Models\B2bProductLink;
use App\Models\Product;
use App\Models\ProductDocument;
use App\Models\ProductImage;
use App\Models\ProductShopCard;
use App\Models\ProductSourcePrice;
use App\Models\ProductSpecialPrice;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

final class MergeDuplicateProductsCommandTest extends TestCase
{
    use RefreshDatabase;

    public function test_distributor_duplicate_merges_into_manufacturer_card(): void
    {
        Queue::fake();

        $uvex = B2bAccount::query()->create(['username' => 'uvex', 'password' => 'x', 'sites' => ['b2b.uvex.pl'], 'connector' => 'uvex']);
        $p4s = B2bAccount::query()->create(['username' => 'p4s', 'password' => 'x', 'sites' => ['b2b.p4s.pl'], 'connector' => 'p4s']);
        // karta producenta z obciętym SKU i karta dystrybutora z pełnym kodem
        $keep = Product::query()->create([
            'sku' => '9169.5', 'name' => 'Okulary Super f OTG spawalnicze 9169.541', 'manufacturer' => 'UVEX',
            'description' => str_repeat('Okulary spawalnicze UVEX super f OTG. ', 3),
            'catalog_price_net' => 33.15, 'purchase_price' => 33.15,
        ]);
        $drop = Product::query()->create([
            'sku' => '9169.541', 'name' => 'Okulary UVEX SUPER f OTG 9169 szare, Infradur AF st. ochrony 1,7',
            'manufacturer' => 'uvex', 'category' => 'Ochrona wzroku, twarzy, głowy',
            'description' => 'Lekkie okulary nakładkowe dla osób noszących okulary korekcyjne.',
            'catalog_price_net' => 43.26, 'purchase_price' => 43.26,
        ]);
        B2bProductLink::query()->create(['b2b_account_id' => $uvex->id, 'remote_id' => '9169.541', 'product_id' => $keep->id]);
        B2bProductLink::query()->create(['b2b_account_id' => $p4s->id, 'remote_id' => '99254', 'product_id' => $drop->id]);
        foreach ([[$keep, $uvex, 33.15], [$drop, $p4s, 43.26]] as [$product, $account, $price]) {
            ProductSourcePrice::query()->create([
                'product_id' => $product->id, 'source_key' => ProductSourcePrice::b2bKey($account->id),
                'b2b_account_id' => $account->id, 'catalog_price_net' => $price, 'purchase_price' => $price,
                'currency' => 'PLN', 'checked_at' => now(),
            ]);
        }
        ProductShopCard::query()->create([
            'product_id' => $drop->id, 'b2b_account_id' => $p4s->id, 'synced_at' => now(),
            'fields' => [['section' => '', 'rows' => [['name' => 'Stopień ochrony', 'value' => '1,7']]]],
        ]);
        ProductImage::query()->create(['product_id' => $drop->id, 'path' => 'p/a.jpg', 'is_primary' => true, 'sort_order' => 0, 'checksum' => 'aaa']);
        ProductDocument::query()->create(['product_id' => $drop->id, 'path' => 'd/a.pdf', 'kind' => 'datasheet', 'checksum' => 'bbb']);
        // pary pominięte: inny producent i duplikat z ceną specjalną
        $otherKeep = Product::query()->create(['sku' => 'A-1', 'name' => 'Rękawice A', 'manufacturer' => 'UVEX', 'catalog_price_net' => 1, 'purchase_price' => 1]);
        $otherDrop = Product::query()->create(['sku' => 'A-2', 'name' => 'Rękawice A', 'manufacturer' => '3M', 'catalog_price_net' => 1, 'purchase_price' => 1]);
        $specialKeep = Product::query()->create(['sku' => 'B-1', 'name' => 'Rękawice B', 'manufacturer' => 'UVEX', 'catalog_price_net' => 1, 'purchase_price' => 1]);
        $specialDrop = Product::query()->create(['sku' => 'B-2', 'name' => 'Rękawice B', 'manufacturer' => 'UVEX', 'catalog_price_net' => 1, 'purchase_price' => 1]);
        ProductSpecialPrice::query()->create(['product_id' => $specialDrop->id, 'client_name' => 'Klient', 'price' => 1]);

        $pairs = [$keep->id.':'.$drop->id, $otherKeep->id.':'.$otherDrop->id, $specialKeep->id.':'.$specialDrop->id];

        $this->artisan('products:merge-duplicate', ['--pair' => $pairs, '--take-sku' => true])
            ->expectsOutputToContain('inny producent')
            ->expectsOutputToContain('ceny specjalne')
            ->expectsOutputToContain('Podgląd: 1 par do scalenia.')
            ->assertSuccessful();
        $this->assertNotNull($drop->fresh());

        $backup = storage_path('framework/testing/merge-duplicate.json');
        $this->artisan('products:merge-duplicate', ['--pair' => $pairs, '--take-sku' => true, '--apply' => true, '--backup' => $backup])
            ->expectsOutputToContain('Scalono 1 par.')
            ->assertSuccessful();

        $this->assertNull(Product::query()->find($drop->id));
        $kept = $keep->fresh();
        $this->assertSame('9169.541', $kept->sku);
        $this->assertSame('Okulary Super f OTG spawalnicze 9169.541', $kept->name);
        $this->assertStringStartsWith('Okulary spawalnicze UVEX', (string) $kept->description);
        $this->assertSame('Ochrona wzroku, twarzy, głowy', $kept->category);
        $this->assertSame(['9169.541'], $kept->enrichment_payload['merged_duplicate_skus']);
        $this->assertArrayNotHasKey('merged_size_skus', $kept->enrichment_payload);
        $this->assertSame(2, B2bProductLink::query()->where('product_id', $keep->id)->count());
        $this->assertSame(2, ProductSourcePrice::query()->where('product_id', $keep->id)->count());
        $this->assertSame(1, ProductShopCard::query()->where('product_id', $keep->id)->count());
        $this->assertSame(1, ProductImage::query()->where('product_id', $keep->id)->count());
        $this->assertSame(1, ProductDocument::query()->where('product_id', $keep->id)->count());
        $this->assertStringContainsString('Stopień ochrony: 1,7', (string) $kept->shop_fields_summary);
        $this->assertNotNull($otherDrop->fresh());
        $this->assertNotNull($specialDrop->fresh());
        $this->assertFileExists($backup);
        @unlink($backup);
    }

    public function test_card_in_two_pairs_is_refused(): void
    {
        $this->artisan('products:merge-duplicate', ['--pair' => ['1:2', '1:3']])
            ->expectsOutputToContain('tylko w jednej parze')
            ->assertFailed();
    }
}
