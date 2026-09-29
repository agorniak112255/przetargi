<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\ErpItem;
use App\Models\ErpItemLink;
use App\Models\ErpItemPurchase;
use App\Models\Product;
use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/** Zakładka „Zapasy”: towary XL ze stanem bez sprzedaży od N miesięcy. */
final class InventoryApiTest extends TestCase
{
    use RefreshDatabase;

    private int $gid = 1;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolesAndPermissionsSeeder::class);
        $this->travelTo(now()->setDate(2026, 9, 30)->setTime(10, 0));
    }

    public function test_only_admin_sees_inventory(): void
    {
        Sanctum::actingAs(User::factory()->withRole('handlowiec')->create());
        $this->getJson('/api/inventory')->assertForbidden();

        Sanctum::actingAs(User::factory()->withRole('admin')->create());
        $this->getJson('/api/inventory')->assertOk()->assertJsonPath('cutoff', '2026-03-30');
    }

    public function test_default_six_months_stock_in_any_warehouse_and_never_sold_only_when_lot_is_old(): void
    {
        // wartość = partie na stanie, a bez nich stan × cena z ostatniej PZ
        $this->item('OLD', stock: 10, price: 50, lastSale: '2026-01-10');
        $this->item('RECENT', stock: 10, price: 90, lastSale: '2026-07-01');
        $this->item('NOSTOCK', stock: 0, price: 1, lastSale: '2025-01-01');
        // stan tylko poza HANDEL — liczy się (wszystkie magazyny)
        $this->item('OUTSIDE', stock: 4, price: 10, lastSale: '2025-12-01', trade: 0);
        // PZ błędna (1 szt. za całą dostawę), partie po korekcie RW/PW mają właściwą wartość
        $this->item('NEVER-OLD', stock: 3, price: 11600.6, lastSale: null, oldestLot: '2025-11-01', book: 30);
        $this->item('NEVER-FRESH', stock: 3, price: 10, lastSale: null, oldestLot: '2026-09-01');
        // ani partii, ani PZ z ceną — wartość nieznana
        $this->item('NEVER-UNKNOWN', stock: 2, price: null, lastSale: null, oldestLot: null);
        $removed = $this->item('REMOVED', stock: 5, price: 10, lastSale: '2025-01-01');
        $removed->update(['removed_at' => now()]);

        Sanctum::actingAs(User::factory()->withRole('admin')->create());
        $res = $this->getJson('/api/inventory')->assertOk();

        // domyślnie według wartości malejąco; nieznana wartość na końcu w obu kierunkach
        $this->assertSame(['OLD', 'OUTSIDE', 'NEVER-OLD', 'NEVER-UNKNOWN'], array_column($res->json('data'), 'code'));
        $this->assertSame(['NEVER-OLD', 'OUTSIDE', 'OLD', 'NEVER-UNKNOWN'], $this->codes('dir=asc'));
        $this->assertNull($res->json('data.3.stock_value'));
        $this->assertNull($res->json('data.3.value_source'));
        $this->assertEquals(30, $res->json('data.2.stock_value'));
        // cena za jednostkę z partii (30 zł ÷ 3 szt.), nie z błędnej PZ (11 600,60)
        $this->assertEquals(10, $res->json('data.2.unit_cost'));
        $this->assertNull($res->json('data.0.unit_cost'));
        $this->assertSame('lots', $res->json('data.2.value_source'));
        $this->assertSame('last_purchase', $res->json('data.0.value_source'));
        $this->assertSame(['items' => 4, 'value' => 570, 'value_unknown' => 1, 'without_card' => 4, 'never_sold' => 2], $res->json('summary'));

        $this->assertSame(['OLD', 'OUTSIDE'], $this->codes('never_sold=0'));
        // próg 1 mies. (30.08): partia z 1.09 nadal świeższa niż próg
        $this->assertSame(['NEVER-OLD', 'NEVER-UNKNOWN', 'OLD', 'OUTSIDE', 'RECENT'], $this->codes('months=1&sort=code&dir=asc'));
        $this->assertSame(['OUTSIDE'], $this->codes('months=9&never_sold=0'));
        $this->getJson('/api/inventory?months=5')->assertUnprocessable();
    }

    public function test_cutoff_does_not_overflow_at_month_end(): void
    {
        $this->travelTo(now()->setDate(2026, 3, 31));
        Sanctum::actingAs(User::factory()->withRole('admin')->create());

        $this->getJson('/api/inventory?months=1')->assertJsonPath('cutoff', '2026-02-28');
        $this->getJson('/api/inventory?months=24')->assertJsonPath('cutoff', '2024-03-31');
    }

    public function test_row_shows_card_warehouses_value_and_last_purchase(): void
    {
        $item = $this->item('SZAT2112103', stock: 4400, price: 0.4165, lastSale: '2025-12-15', oldestLot: '2025-10-02', book: 1830.5);
        // starsza PZ z wyższym numerem dokumentu — liczy się data, nie numer
        ErpItemPurchase::query()->create([
            'erp_item_id' => $item->id, 'document_type' => 1489, 'document_id' => 99, 'document_line' => 1, 'purchased_at' => '2025-05-01',
            'supplier' => 'UVEX', 'quantity' => 1000, 'document_unit' => 'szt', 'net_value_pln' => 400, 'unit_price_pln' => 0.4,
            'document_price' => 0.4, 'currency' => 'PLN',
        ]);
        $card = Product::query()->create(['sku' => '2112.103', 'name' => 'Zatyczki UVEX', 'manufacturer' => 'UVEX', 'catalog_price_net' => 1, 'purchase_price' => 0.4, 'stock' => 0]);
        $other = Product::query()->create(['sku' => '2112.103-K', 'name' => 'Zatyczki karton', 'manufacturer' => 'UVEX', 'catalog_price_net' => 1, 'purchase_price' => 0.4, 'stock' => 0]);
        $this->link($item, $other, ErpItemLink::STATUS_AUTO);
        $this->link($item, $card, ErpItemLink::STATUS_CONFIRMED);
        $this->link($item, Product::query()->create(['sku' => 'S', 'name' => 'S', 'manufacturer' => 'X', 'catalog_price_net' => 1, 'purchase_price' => 1, 'stock' => 0]), ErpItemLink::STATUS_SUGGESTED);
        $this->item('BEZKARTY', stock: 1, price: 1, lastSale: '2025-01-01');

        Sanctum::actingAs(User::factory()->withRole('admin')->create());
        $row = $this->getJson('/api/inventory?card=with')->assertOk()->json('data.0');

        $this->assertSame('SZAT2112103', $row['code']);
        // wartość partii z XL ma pierwszeństwo przed 4400 × 0,4165 z ostatniej PZ
        $this->assertEquals(1830.5, $row['stock_value']);
        $this->assertSame('lots', $row['value_source']);
        $this->assertSame('2025-10-02', $row['oldest_lot_at']);
        $this->assertSame('2025-12-15', $row['last_sale_at']);
        $this->assertSame(['01H', '01BSH'], array_column($row['warehouses'], 'code'));
        // potwierdzona karta przed automatyczną; propozycja się nie liczy
        $this->assertSame('2112.103', $row['card']['sku']);
        $this->assertSame('confirmed', $row['card']['link_status']);
        $this->assertSame(2, $row['cards_count']);
        $this->assertEquals(0.4165, $row['last_purchase']['unit_price_pln']);
        $this->assertSame('2025-08-20', $row['last_purchase']['date']);

        $this->assertSame(['BEZKARTY'], $this->codes('card=without'));
        $this->assertSame(['SZAT2112103'], $this->codes('search=2112.103-K'));
        $this->assertSame(['SZAT2112103'], $this->codes('group=S'));
        $this->assertSame(['BEZKARTY'], $this->codes('group=B'));
    }

    /** @return list<string> */
    private function codes(string $params): array
    {
        return array_column($this->getJson('/api/inventory?'.$params)->assertOk()->json('data'), 'code');
    }

    /** $price — cena jednostkowa z ostatniej PZ (null = towar bez PZ), $book — wartość księgowa partii z XL. */
    private function item(string $code, float $stock, ?float $price, ?string $lastSale, ?string $oldestLot = null, ?float $trade = null, ?float $book = null): ErpItem
    {
        $item = ErpItem::query()->create([
            'xl_gid' => $this->gid, 'code' => $code, 'name' => 'Towar '.$code, 'unit' => 'szt', 'archived' => false,
            'stock_trade' => $trade ?? min($stock, 3800), 'stock_total' => $stock, 'stock_value' => $book, 'oldest_lot_at' => $oldestLot,
            'stock_by_warehouse' => $stock > 0 ? [
                ['code' => '01H', 'name' => 'Magazyn HANDEL - Rzeszów', 'quantity' => min($stock, 3800), 'value' => null],
                ...($stock > 3800 ? [['code' => '01BSH', 'name' => 'Magazyn BSH Rzeszów', 'quantity' => $stock - 3800, 'value' => null]] : []),
            ] : [],
            'last_sale_at' => $lastSale, 'synced_at' => now(),
        ]);
        if ($price !== null) {
            ErpItemPurchase::query()->create([
                'erp_item_id' => $item->id, 'document_type' => 1489, 'document_id' => $this->gid, 'document_line' => 1, 'purchased_at' => '2025-08-20',
                'supplier' => 'UVEX', 'quantity' => 2000, 'document_unit' => 'szt', 'net_value_pln' => round(2000 * $price, 2), 'unit_price_pln' => $price,
                'document_price' => $price, 'currency' => 'PLN',
            ]);
        }
        $this->gid++;

        return $item;
    }

    private function link(ErpItem $item, Product $card, string $status): void
    {
        ErpItemLink::query()->create([
            'erp_item_id' => $item->id, 'product_id' => $card->id, 'status' => $status,
            'method' => ErpItemLink::METHOD_NAME, 'matched_value' => $item->code, 'last_seen_at' => now(),
        ]);
    }
}
