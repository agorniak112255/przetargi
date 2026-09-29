<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\ErpItem;
use App\Models\ErpItemLink;
use App\Models\Product;
use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/** Wyszukiwarka listy produktów po kodzie towaru ERP XL — karty przez pewne powiązania. */
final class ProductErpCodeSearchTest extends TestCase
{
    use RefreshDatabase;

    private int $gid = 1;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolesAndPermissionsSeeder::class);
        Cache::forget('nbp.table_a.rates');
        Http::fake(['api.nbp.pl/*' => Http::response([['effectiveDate' => '2026-09-29', 'rates' => [['code' => 'EUR', 'mid' => 4.0]]]])]);
        Sanctum::actingAs(User::factory()->withRole('admin')->create());
    }

    public function test_exact_xl_code_finds_linked_card_first_and_shows_the_code(): void
    {
        $pheos = $this->card('9198.014', 'Okulary Pheos CX2', 'UVEX');
        // karta z tym ciągiem w nazwie też zostaje — kod XL dochodzi do wyników, nie zastępuje ich
        $this->card('A-1', 'Etykieta SOK9198014 zapas', 'UVEX');
        $this->card('B-1', 'Kurtka', 'PROS');
        $this->link($this->item('SOK9198014'), $pheos, ErpItemLink::STATUS_AUTO);

        $rows = $this->getJson('/api/products?q=sok9198014')->assertOk()->json('data');

        $this->assertSame(['9198.014', 'A-1'], array_column($rows, 'sku'));
        $this->assertSame(['SOK9198014'], $rows[0]['erp_codes']);
        $this->assertSame([], $rows[1]['erp_codes']);
    }

    public function test_only_auto_and_confirmed_links_of_items_still_in_xl_count(): void
    {
        $confirmed = $this->card('C-1', 'Rękawice A', 'Ansell');
        $this->link($this->item('ARK1000A'), $confirmed, ErpItemLink::STATUS_CONFIRMED);
        $this->link($this->item('ARK1000B'), $this->card('S-1', 'Rękawice B', 'Ansell'), ErpItemLink::STATUS_SUGGESTED);
        $this->link($this->item('ARK1000C'), $this->card('R-1', 'Rękawice C', 'Ansell'), ErpItemLink::STATUS_REJECTED);
        $removed = $this->item('ARK1000D');
        $removed->update(['removed_at' => now()]);
        $this->link($removed, $this->card('D-1', 'Rękawice D', 'Ansell'), ErpItemLink::STATUS_AUTO);

        foreach (['ARK1000A' => ['C-1'], 'ARK1000B' => [], 'ARK1000C' => [], 'ARK1000D' => []] as $code => $expected) {
            $this->assertSame($expected, array_column($this->getJson('/api/products?q='.$code)->json('data'), 'sku'), $code);
        }
        // przedrostek wspólny dla wszystkich: tylko pewne powiązanie
        $this->assertSame(['C-1'], array_column($this->getJson('/api/products?q=ARK1000')->json('data'), 'sku'));
    }

    public function test_code_prefix_needs_five_characters_with_a_digit(): void
    {
        $a = $this->card('P-1', 'Półbuty A', 'UVEX');
        $b = $this->card('P-2', 'Trzewiki B', 'UVEX');
        $c = $this->card('Z-1', 'Znak trucizna', 'Anro');
        $this->link($this->item('BPBOCH8540/8'), $a, ErpItemLink::STATUS_AUTO);
        $this->link($this->item('BPBOCH8541'), $b, ErpItemLink::STATUS_AUTO);
        $this->link($this->item('SZNTRUN'), $c, ErpItemLink::STATUS_AUTO);

        $this->assertSame(['P-1', 'P-2'], $this->skus('BPBOCH854'));
        $this->assertSame(['P-1'], $this->skus('BPBOCH8540/8'));
        // krótki przedrostek grupy albo same litery — tylko dokładny kod
        $this->assertSame([], $this->skus('BPBOCH'));
        $this->assertSame([], $this->skus('SZNTR'));
        $this->assertSame(['Z-1'], $this->skus('szntrun'));
    }

    public function test_ean_matches_exactly_and_other_filters_still_apply(): void
    {
        $uvex = $this->card('U-1', 'Okulary', 'UVEX');
        $pros = $this->card('X-1', 'Kurtka', 'PROS');
        $item = $this->item('SOK1111111');
        $item->update(['ean' => '4031101234567']);
        $this->link($item, $uvex, ErpItemLink::STATUS_AUTO);
        $this->link($this->item('AKU2222222'), $pros, ErpItemLink::STATUS_AUTO);

        $this->assertSame(['U-1'], $this->skus('4031101234567'));
        $this->assertSame([], $this->skus('403110123456'));
        $this->assertSame([], array_column($this->getJson('/api/products?q=AKU2222222&manufacturer=UVEX')->json('data'), 'sku'));
    }

    /** @return list<string> */
    private function skus(string $q): array
    {
        return array_column($this->getJson('/api/products?sort=sku&q='.rawurlencode($q))->assertOk()->json('data'), 'sku');
    }

    private function card(string $sku, string $name, string $manufacturer): Product
    {
        return Product::query()->create([
            'sku' => $sku, 'name' => $name, 'manufacturer' => $manufacturer,
            'catalog_price_net' => 10, 'purchase_price' => 5, 'stock' => 0,
        ]);
    }

    private function item(string $code): ErpItem
    {
        return ErpItem::query()->create([
            'xl_gid' => $this->gid++, 'code' => $code, 'name' => 'Towar XL', 'unit' => 'szt', 'archived' => false,
            'stock_trade' => 0, 'stock_total' => 0, 'synced_at' => now(),
        ]);
    }

    private function link(ErpItem $item, Product $card, string $status): void
    {
        ErpItemLink::query()->create([
            'erp_item_id' => $item->id, 'product_id' => $card->id, 'status' => $status,
            'method' => ErpItemLink::METHOD_NAME, 'matched_value' => $item->code, 'last_seen_at' => now(),
        ]);
    }
}
