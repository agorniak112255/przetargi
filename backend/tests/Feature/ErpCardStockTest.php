<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\ErpItem;
use App\Models\ErpItemLink;
use App\Models\ErpItemPurchase;
use App\Models\Product;
use App\Models\User;
use App\Services\Erp\ErpCardStock;
use App\Services\ProductSizeMergeService;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

final class ErpCardStockTest extends TestCase
{
    use RefreshDatabase;

    private int $gid = 1;

    public function test_product_card_shows_stock_breakdown_and_last_purchase_of_linked_items(): void
    {
        $this->seed(RolesAndPermissionsSeeder::class);
        $card = $this->card('2112.103');
        $a = $this->item('SZAT2112103', 'szt', 3800, [['01H', 'Magazyn HANDEL - Rzeszów', 3800], ['01BSH', 'Magazyn BSH Rzeszów', 600]]);
        $b = $this->item('SZAT2112103B', 'szt', 50, [['01H', 'Magazyn HANDEL - Rzeszów', 30], ['15H', 'Magazyn HANDEL Kraków', 20]]);
        $this->purchase($a, 2220308, '2026-08-20', 'UVEX', 2000, 833.0);
        $this->purchase($b, 2230000, '2026-09-10', 'P4S', 100, 45.0, 'EUR', 10.5);
        $this->link($a, $card, ErpItemLink::STATUS_AUTO);
        $this->link($b, $card, ErpItemLink::STATUS_CONFIRMED);
        // niepewne, odrzucone i zniknięte z XL nie liczą się do stanu
        $this->link($this->item('X1', 'szt', 999, [['01H', 'Magazyn HANDEL - Rzeszów', 999]]), $card, ErpItemLink::STATUS_SUGGESTED);
        $this->link($this->item('X2', 'szt', 999, [['01H', 'Magazyn HANDEL - Rzeszów', 999]]), $card, ErpItemLink::STATUS_REJECTED);
        $removed = $this->item('X3', 'szt', 999, [['01H', 'Magazyn HANDEL - Rzeszów', 999]]);
        $removed->update(['removed_at' => now()]);
        $this->link($removed, $card, ErpItemLink::STATUS_AUTO);

        Sanctum::actingAs(User::factory()->withRole('admin')->create());
        $response = $this->getJson('/api/products/'.$card->id)->assertOk();

        $erp = $response->json('erp_xl');
        $this->assertSame(['SZAT2112103', 'SZAT2112103B'], array_column($erp['items'], 'code'));
        $this->assertEquals(3850, $erp['stock_trade']);
        $this->assertSame('szt', $erp['unit']);
        $this->assertSame(['01H', '01BSH', '15H'], array_column($erp['warehouses'], 'code'));
        $this->assertEquals(3830, $erp['warehouses'][0]['quantity']);
        $this->assertSame(1, $erp['suggested']);
        // najnowszy zakup z obu towarów; cena w PLN z wartości, cena dokumentu w walucie obok
        $this->assertSame('2026-09-10', $erp['last_purchase']['date']);
        $this->assertSame('SZAT2112103B', $erp['last_purchase']['xl_code']);
        $this->assertEquals(0.45, $erp['last_purchase']['unit_price_pln']);
        $this->assertSame('EUR', $erp['last_purchase']['currency']);
        $this->assertFalse($erp['stale']);
    }

    public function test_mixed_units_are_not_summed_and_old_copy_is_stale(): void
    {
        $card = $this->card('A1');
        $this->link($this->item('A-SZT', 'szt', 10, [['01H', 'Magazyn HANDEL', 10]]), $card, ErpItemLink::STATUS_AUTO);
        $this->link($this->item('A-OPK', 'opk', 3, [['01H', 'Magazyn HANDEL', 3]]), $card, ErpItemLink::STATUS_AUTO);
        ErpItem::query()->update(['synced_at' => now()->subHours(40)]);

        $erp = app(ErpCardStock::class)->forProduct($card->id);

        $this->assertNull($erp['stock_trade']);
        $this->assertNull($erp['warehouses']);
        $this->assertNull($erp['unit']);
        $this->assertCount(2, $erp['items']);
        $this->assertTrue($erp['stale']);
    }

    public function test_card_without_links_has_no_erp_block(): void
    {
        $card = $this->card('B1');
        $this->link($this->item('B-X', 'szt', 5, []), $card, ErpItemLink::STATUS_REJECTED);

        $this->assertNull(app(ErpCardStock::class)->forProduct($card->id));
    }

    public function test_card_merge_moves_links_and_keeps_the_human_decision(): void
    {
        $keep = $this->card('9174.065');
        $drop = $this->card('9174.065-DUP');
        $shared = $this->item('SOK9174065', 'szt', 5, []);
        $onlyDrop = $this->item('SOK9174065B', 'szt', 2, []);
        $this->link($shared, $keep, ErpItemLink::STATUS_AUTO);
        $this->link($shared, $drop, ErpItemLink::STATUS_CONFIRMED);
        $this->link($onlyDrop, $drop, ErpItemLink::STATUS_SUGGESTED);

        app(ProductSizeMergeService::class)->mergeDuplicate($keep, $drop);

        $this->assertNull(Product::query()->find($drop->id));
        $this->assertSame(ErpItemLink::STATUS_CONFIRMED, ErpItemLink::query()->where('erp_item_id', $shared->id)->sole()->status);
        $this->assertSame([$keep->id], ErpItemLink::query()->pluck('product_id')->unique()->values()->all());
        $this->assertSame(2, ErpItemLink::query()->count());
    }

    private function card(string $sku): Product
    {
        return Product::query()->create([
            'sku' => $sku, 'name' => 'Wyrób '.$sku, 'manufacturer' => 'UVEX',
            'catalog_price_net' => 10, 'purchase_price' => 5, 'stock' => 0,
        ]);
    }

    /** @param  list<array{0: string, 1: string, 2: float|int}>  $warehouses */
    private function item(string $code, string $unit, float|int $trade, array $warehouses): ErpItem
    {
        return ErpItem::query()->create([
            'xl_gid' => $this->gid++, 'code' => $code, 'name' => 'Towar '.$code, 'unit' => $unit, 'archived' => false,
            'stock_trade' => $trade, 'stock_total' => array_sum(array_column($warehouses, 2)),
            'stock_by_warehouse' => array_map(static fn (array $w): array => ['code' => $w[0], 'name' => $w[1], 'quantity' => $w[2]], $warehouses),
            'synced_at' => now(),
        ]);
    }

    private function purchase(ErpItem $item, int $document, string $date, string $supplier, float $quantity, float $net, string $currency = 'PLN', ?float $price = null): void
    {
        ErpItemPurchase::query()->create([
            'erp_item_id' => $item->id, 'document_type' => 1489, 'document_id' => $document, 'document_line' => 1,
            'purchased_at' => $date, 'supplier' => $supplier, 'quantity' => $quantity, 'document_unit' => 'szt',
            'net_value_pln' => $net, 'unit_price_pln' => round($net / $quantity, 4), 'document_price' => $price ?? $net / $quantity,
            'currency' => $currency,
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
