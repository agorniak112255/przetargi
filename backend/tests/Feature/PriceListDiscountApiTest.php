<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\AssortmentGroup;
use App\Models\B2bAccount;
use App\Models\PriceList;
use App\Models\Product;
use App\Models\ProductSourcePrice;
use App\Models\User;
use App\Services\Pricing\ProductEffectivePrice;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Zmiana rabatu cennika z pliku w edycji wpisu: przelicza zakup ze slotu „file” tego cennika,
 * a cena obowiązująca karty z ceną B2B zostaje z B2B.
 */
final class PriceListDiscountApiTest extends TestCase
{
    use RefreshDatabase;

    private PriceList $list;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolesAndPermissionsSeeder::class);
        Sanctum::actingAs(User::factory()->withRole('admin')->create());
        $this->list = PriceList::query()->create([
            'manufacturer' => 'Secura',
            'version' => '2026',
            'original_filename' => 'secura.pdf',
            'rows_total' => 0,
            'products_created' => 0,
            'products_updated' => 0,
            'rows_skipped' => 0,
        ]);
    }

    public function test_whole_list_discount_recomputes_purchase_from_catalog(): void
    {
        $a = $this->card('S-1', 100.00, 10);
        $b = $this->card('S-2', 50.00, 10);
        $other = $this->card('X-1', 100.00, 10, $this->otherList());

        $this->getJson("/api/price-lists/{$this->list->id}/discounts")
            ->assertOk()
            ->assertJsonPath('product_count', 2)
            ->assertJsonPath('ungrouped.discount_percent', 10)
            ->assertJsonPath('ungrouped.mixed', false);

        $this->putJson("/api/price-lists/{$this->list->id}/discounts", ['ungrouped_discount' => 25])
            ->assertOk()
            ->assertJsonPath('products_changed', 2)
            ->assertJsonPath('discounts.ungrouped.discount_percent', 25);

        $this->assertEquals(75.00, (float) $a->refresh()->purchase_price);
        $this->assertEquals(25.00, (float) $a->discount_percent);
        $this->assertEquals(100.00, (float) $a->catalog_price_net);
        $this->assertEquals(37.50, (float) $b->refresh()->purchase_price);
        // karta z innego cennika bez zmian
        $this->assertEquals(90.00, (float) $other->refresh()->purchase_price);
        $this->assertDatabaseHas('product_price_history', [
            'product_id' => $a->id,
            'price_list_id' => $this->list->id,
            'source' => 'price_list_discount',
        ]);
        // kolejny import tego producenta podpowie nowy rabat
        $this->assertDatabaseHas('assortment_groups', [
            'manufacturer' => 'Secura',
            'name' => AssortmentGroup::GLOBAL_NAME,
            'discount_percent' => 25,
        ]);
    }

    public function test_group_discounts_apply_per_group(): void
    {
        $gloves = AssortmentGroup::query()->create(['manufacturer' => 'Secura', 'name' => 'Rękawice', 'discount_percent' => 10, 'is_global' => false]);
        $shoes = AssortmentGroup::query()->create(['manufacturer' => 'Secura', 'name' => 'Obuwie', 'discount_percent' => 20, 'is_global' => false]);
        $g = $this->card('S-1', 100.00, 10, group: $gloves);
        $s = $this->card('S-2', 100.00, 20, group: $shoes);

        $this->getJson("/api/price-lists/{$this->list->id}/discounts")
            ->assertOk()
            ->assertJsonCount(2, 'groups')
            ->assertJsonPath('ungrouped.product_count', 0);

        $this->putJson("/api/price-lists/{$this->list->id}/discounts", [
            'groups' => [['id' => $gloves->id, 'discount_percent' => 30]],
        ])->assertOk()->assertJsonPath('products_changed', 1);

        $this->assertEquals(70.00, (float) $g->refresh()->purchase_price);
        $this->assertEquals(80.00, (float) $s->refresh()->purchase_price);
        $this->assertEquals(30.00, (float) $gloves->refresh()->discount_percent);
    }

    public function test_b2b_priced_card_keeps_b2b_price_but_file_slot_changes(): void
    {
        $card = $this->card('S-1', 100.00, 10);
        $account = B2bAccount::query()->create(['username' => 'jan', 'password' => 'sekret', 'sites' => ['b2b.example.pl']]);
        app(ProductEffectivePrice::class)->saveSlot($card, ProductSourcePrice::b2bKey($account->id), [
            'catalog_price_net' => 80.00,
            'purchase_price' => 60.00,
            'discount_percent' => 25,
        ]);

        $this->putJson("/api/price-lists/{$this->list->id}/discounts", ['ungrouped_discount' => 50])
            ->assertOk()
            ->assertJsonPath('products_changed', 1)
            ->assertJsonPath('b2b_priced', 1);

        $this->assertEquals(60.00, (float) $card->refresh()->purchase_price);
        $slot = ProductSourcePrice::query()->where('product_id', $card->id)->where('source_key', 'file')->first();
        $this->assertEquals(50.00, (float) $slot->purchase_price);
    }

    public function test_rejects_out_of_range_and_empty_payload(): void
    {
        $this->card('S-1', 100.00, 10);

        $this->putJson("/api/price-lists/{$this->list->id}/discounts", ['ungrouped_discount' => 120])
            ->assertStatus(422);
        $this->putJson("/api/price-lists/{$this->list->id}/discounts", [])
            ->assertStatus(422);
    }

    private function otherList(): PriceList
    {
        return PriceList::query()->create([
            'manufacturer' => 'Inny',
            'version' => '1',
            'rows_total' => 0,
            'products_created' => 0,
            'products_updated' => 0,
            'rows_skipped' => 0,
        ]);
    }

    private function card(string $sku, float $catalog, float $discount, ?PriceList $list = null, ?AssortmentGroup $group = null): Product
    {
        $purchase = round($catalog * (1 - $discount / 100), 2);
        $product = Product::query()->create([
            'sku' => $sku,
            'name' => 'Karta '.$sku,
            'manufacturer' => 'Secura',
            'catalog_price_net' => $catalog,
            'discount_percent' => $discount,
            'purchase_price' => $purchase,
            'stock' => 0,
            'assortment_group_id' => $group?->id,
        ]);
        app(ProductEffectivePrice::class)->saveSlot($product, ProductSourcePrice::SOURCE_FILE, [
            'catalog_price_net' => $catalog,
            'purchase_price' => $purchase,
            'discount_percent' => $discount,
            'price_list_id' => ($list ?? $this->list)->id,
        ]);

        return $product;
    }
}
