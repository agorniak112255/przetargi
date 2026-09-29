<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\ErpItem;
use App\Models\ErpItemLink;
use App\Models\ErpRwPwPair;
use App\Models\Product;
use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/** Zapasy → RW → PW: lista par, widoki po towarze i po osobie, filtry. */
final class InventoryRwPwApiTest extends TestCase
{
    use RefreshDatabase;

    private int $doc = 1;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolesAndPermissionsSeeder::class);
        $this->travelTo(now()->setDate(2026, 9, 30)->setTime(10, 0));
    }

    public function test_only_admin_and_default_filters_twelve_months_gap_three_days(): void
    {
        Sanctum::actingAs(User::factory()->withRole('handlowiec')->create());
        $this->getJson('/api/inventory/rw-pw')->assertForbidden();

        $sandals = $this->item(7, 'BSNSL46', 'SANDAŁY ATLAS SL 46');
        $this->pair($sandals, '2026-09-21', 0, 1100, 1100, 'TAIZ');
        $this->pair($sandals, '2026-08-20', 2, 880, 880, 'TAIZ', pwOperator: 'NOMA');
        // odstęp 10 dni — poza domyślnym progiem 3 dni
        $this->pair($sandals, '2026-07-01', 10, 220, 220, 'CZAL');
        // starsze niż 12 mies.
        $this->pair($sandals, '2025-09-29', 0, 50, 50, 'CZAL');
        $helmet = $this->item(8, 'SHEG3001NUV', 'HEŁM PELTOR G-3000');
        $this->pair($helmet, '2026-08-21', 0, 610.37, 700, 'CZAL', approver: 'SOAG');

        Sanctum::actingAs(User::factory()->withRole('admin')->create());
        $res = $this->getJson('/api/inventory/rw-pw')->assertOk();

        $this->assertSame('pairs', $res->json('view'));
        $this->assertSame('2025-09-30', $res->json('from'));
        $this->assertSame(['pairs' => 3, 'items' => 2, 'value' => 2590.37, 'same_value' => 2, 'operators' => 2], $res->json('summary'));
        // domyślnie od najnowszego RW
        $this->assertSame(['2026-09-21', '2026-08-21', '2026-08-20'], array_column(array_column($res->json('data'), 'rw'), 'date'));
        $this->assertSame('RW-01H/1/26/09', $res->json('data.0.rw.number'));
        $this->assertSame('BSNSL46', $res->json('data.0.item.code'));
        $this->assertSame(['CZAL', 'NOMA', 'SOAG', 'TAIZ'], $res->json('operators'));

        $this->assertCount(4, $this->getJson('/api/inventory/rw-pw?gap=30')->json('data'));
        // odstęp do 30 dni, tylko sandały: 3 pary z 12 mies. (ta sprzed 30.09.2025 odpada)
        $this->assertSame(['2026-09-21', '2026-08-20', '2026-07-01'], $this->dates('gap=30&search=BSN'));
        // 3 mies. = od 30.06 — para z 1.07 jeszcze w okresie
        $this->assertSame('2026-06-30', $this->getJson('/api/inventory/rw-pw?months=3')->json('from'));
        $this->assertSame(['2026-09-21', '2026-08-20', '2026-07-01'], $this->dates('months=3&gap=30&search=BSN'));
        $this->assertSame(['2026-09-21', '2026-08-20'], $this->dates('same_value=1&search=atlas'));
        // osoba jako wystawiający, zatwierdzający albo po stronie PW
        $this->assertSame(['2026-08-21'], $this->dates('operator=SOAG'));
        $this->assertSame(['2026-08-20'], $this->dates('operator=NOMA'));
        $this->assertSame(['2026-09-21'], $this->dates('search=RW-01H/1/'));
        $this->getJson('/api/inventory/rw-pw?gap=5')->assertUnprocessable();
    }

    public function test_items_and_operators_views(): void
    {
        $sandals = $this->item(7, 'BSNSL46', 'SANDAŁY ATLAS SL 46');
        $card = Product::query()->create(['sku' => 'SL46', 'name' => 'Sandały Atlas SL 46', 'manufacturer' => 'Atlas', 'catalog_price_net' => 1, 'purchase_price' => 1, 'stock' => 0]);
        ErpItemLink::query()->create(['erp_item_id' => $sandals->id, 'product_id' => $card->id, 'status' => ErpItemLink::STATUS_AUTO, 'method' => ErpItemLink::METHOD_NAME, 'matched_value' => 'SL46']);
        $this->pair($sandals, '2026-09-21', 0, 1100, 1100, 'TAIZ');
        $this->pair($sandals, '2026-08-20', 1, 880, 880, 'TAIZ', pwOperator: 'NOMA');
        $helmet = $this->item(8, 'SHEG3001NUV', 'HEŁM PELTOR G-3000');
        $this->pair($helmet, '2026-08-21', 0, 610.37, 700, 'CZAL');

        Sanctum::actingAs(User::factory()->withRole('admin')->create());
        $items = $this->getJson('/api/inventory/rw-pw?view=items')->assertOk();
        $this->assertSame('items', $items->json('view'));
        $this->assertSame(2, $items->json('meta.total'));
        $row = $items->json('data.0');
        $this->assertSame('BSNSL46', $row['item']['code']);
        $this->assertSame('SL46', $row['item']['card']['sku']);
        $this->assertSame(2, $row['pairs']);
        $this->assertEquals(1980, $row['value']);
        $this->assertSame(2, $row['same_value']);
        $this->assertSame(['NOMA', 'TAIZ'], $row['operators']);
        $this->assertSame(['2026-08-20', '2026-09-21'], [$row['first_date'], $row['last_date']]);
        $this->assertSame(['SHEG3001NUV', 'BSNSL46'], array_column(array_column($this->getJson('/api/inventory/rw-pw?view=items&sort=code&dir=desc')->json('data'), 'item'), 'code'));

        $people = $this->getJson('/api/inventory/rw-pw?view=operators')->assertOk();
        $this->assertNull($people->json('meta'));
        $this->assertSame([
            ['operator' => 'TAIZ', 'pairs' => 2, 'items' => 1, 'value' => 1980, 'same_value' => 2, 'last_date' => '2026-09-21', 'pw_by_other' => 1],
            ['operator' => 'CZAL', 'pairs' => 1, 'items' => 1, 'value' => 610.37, 'same_value' => 0, 'last_date' => '2026-08-21', 'pw_by_other' => 0],
        ], $people->json('data'));
    }

    public function test_inventory_row_counts_pairs_like_default_filters(): void
    {
        $item = ErpItem::query()->create([
            'xl_gid' => 7, 'code' => 'BSNSL46', 'name' => 'SANDAŁY', 'unit' => 'par', 'archived' => false,
            'stock_trade' => 5, 'stock_total' => 5, 'last_sale_at' => '2025-12-01', 'synced_at' => now(),
        ]);
        $this->pair($item, '2026-09-21', 0, 10, 10, 'TAIZ');
        $this->pair($item, '2026-08-20', 3, 10, 10, 'TAIZ');
        $this->pair($item, '2026-07-01', 4, 10, 10, 'TAIZ');
        $this->pair($item, '2025-09-01', 0, 10, 10, 'TAIZ');

        Sanctum::actingAs(User::factory()->withRole('admin')->create());
        $this->getJson('/api/inventory')->assertOk()->assertJsonPath('data.0.rw_pw_pairs', 2);
    }

    /** @return list<string> */
    private function dates(string $params): array
    {
        return array_column(array_column($this->getJson('/api/inventory/rw-pw?'.$params)->assertOk()->json('data'), 'rw'), 'date');
    }

    private function item(int $gid, string $code, string $name): ErpItem
    {
        return ErpItem::query()->create(['xl_gid' => $gid, 'code' => $code, 'name' => $name, 'unit' => 'szt', 'archived' => false, 'stock_trade' => 1, 'stock_total' => 1]);
    }

    private function pair(ErpItem $item, string $rwDate, int $gap, float $rwValue, float $pwValue, string $operator, ?string $approver = null, ?string $pwOperator = null): void
    {
        $rw = $this->doc++;
        $pw = $this->doc++;
        $pwDate = now()->parse($rwDate)->addDays($gap)->toDateString();
        ErpRwPwPair::query()->create([
            'xl_gid' => $item->xl_gid, 'erp_item_id' => $item->id,
            'rw_document_id' => $rw, 'rw_number' => sprintf('RW-01H/%d/%s', $rw, substr($rwDate, 2, 2).'/'.substr($rwDate, 5, 2)), 'rw_date' => $rwDate,
            'rw_warehouse' => '01H', 'rw_quantity' => 5, 'rw_value' => $rwValue, 'rw_operator' => $operator, 'rw_approver' => $approver ?? $operator,
            'pw_document_id' => $pw, 'pw_number' => sprintf('PW-01H/%d/%s', $pw, substr($pwDate, 2, 2).'/'.substr($pwDate, 5, 2)), 'pw_date' => $pwDate,
            'pw_warehouse' => '01H', 'pw_quantity' => 5, 'pw_value' => $pwValue, 'pw_operator' => $pwOperator ?? $operator, 'pw_approver' => $pwOperator ?? $operator,
            'gap_days' => $gap, 'same_value' => abs($rwValue - $pwValue) < 0.005, 'same_warehouse' => true, 'synced_at' => now(),
        ]);
    }
}
