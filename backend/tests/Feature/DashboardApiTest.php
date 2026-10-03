<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\B2bAccount;
use App\Models\Campaign;
use App\Models\CampaignRecipient;
use App\Models\Client;
use App\Models\ErpItem;
use App\Models\ErpWarehouse;
use App\Models\PriceList;
use App\Models\PriceListImport;
use App\Models\Product;
use App\Models\ProductImage;
use App\Models\Tender;
use App\Models\User;
use App\Services\Erp\InventorySnapshots;
use App\Services\Erp\WarehouseLocations;
use App\Services\Erp\WarehouseSplit;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

class DashboardApiTest extends TestCase
{
    use RefreshDatabase;

    private int $gid = 1;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolesAndPermissionsSeeder::class);
        $this->travelTo(now()->setDate(2026, 10, 2)->setTime(10, 0));
    }

    public function test_each_section_needs_its_module_permission(): void
    {
        Sanctum::actingAs($this->userWith(['dashboard.view']));
        // „Do zrobienia dziś” jest zawsze (trasa wymaga dashboard.view); bez modułów przetargów — bez kafelka wygranych
        $this->getJson('/api/dashboard')->assertOk()->assertExactJson([
            'todo' => ['items' => [], 'won_90d' => null],
            'tenders' => null, 'products' => null, 'stock' => null, 'prices' => null, 'campaigns' => null,
        ]);

        Sanctum::actingAs($this->userWith(['dashboard.view', 'price_lists.view']));
        $prices = $this->getJson('/api/dashboard')->assertOk()->json('prices');
        $this->assertNull($prices['accounts'], 'Konta B2B tylko z b2b_accounts.view.');
        $this->assertSame([], $prices['file_imports']);

        Sanctum::actingAs($this->userWith(['dashboard.view', 'b2b_accounts.view']));
        $prices = $this->getJson('/api/dashboard')->assertOk()->json('prices');
        $this->assertSame([], $prices['accounts']);
        $this->assertNull($prices['file_imports']);
        $this->assertNull($prices['prices_changed_7d']);

        Sanctum::actingAs(User::factory()->create());
        $this->getJson('/api/dashboard')->assertForbidden();
    }

    public function test_tenders_in_progress_stages_and_upcoming_deadlines(): void
    {
        $owner = $this->userWith(['dashboard.view', 'tenders.view_own']);
        $other = User::factory()->create();
        $this->tender($owner, 'wycena', 1000, '2026-10-05', 20);
        $this->tender($owner, 'wycena', 500, '2026-10-20', 10);
        $this->tender($owner, 'draft', null, '2026-10-03');
        $this->tender($owner, 'zatwierdzona', 300, '2026-10-01');
        $this->tender($owner, 'exported', 700, '2026-10-04', 50);
        $this->tender($owner, 'odrzucony', 900, '2026-10-04');
        $this->tender($owner, 'archiwum', 800, '2026-10-04');
        $this->tender($other, 'wycena', 5000, '2026-10-03');

        Sanctum::actingAs($owner);
        $t = $this->getJson('/api/dashboard')->assertOk()->json('tenders');

        // w toku: bez wyeksportowanych, odrzuconych, archiwum i cudzych
        $this->assertSame(4, $t['active']);
        $this->assertEqualsWithDelta(1800.0, $t['value_net'], 0.001);
        $this->assertEqualsWithDelta(15.0, $t['avg_margin_percent'], 0.001);
        $this->assertSame(2, $t['deadline_soon'], 'Termin 3 i 5 października; 1.10 minął, 20.10 poza tygodniem.');
        $stages = collect($t['stages'])->keyBy('status');
        $this->assertEqualsCanonicalizing(['draft', 'wycena', 'zatwierdzona', 'exported'], $stages->keys()->all());
        $this->assertSame(2, $stages['wycena']['count']);
        $this->assertEqualsWithDelta(1500.0, $stages['wycena']['value_net'], 0.001);
        $this->assertEqualsWithDelta(0.0, $stages['draft']['value_net'], 0.001);
        $this->assertSame(['2026-10-03', '2026-10-05', '2026-10-20'], array_column($t['upcoming'], 'deadline'));
        $this->assertSame('Klient wycena', $t['upcoming'][1]['client']);
        $this->assertSame(['id', 'number', 'title', 'client', 'status', 'deadline', 'deadline_time'], array_keys($t['upcoming'][0]), 'Wiersz terminu bez cen i marż.');
        $this->assertNull($t['upcoming'][0]['deadline_time'], 'Bez godziny składania — null, nie zmyślona godzina.');
    }

    public function test_margin_without_any_priced_tender_is_unknown_not_zero(): void
    {
        $owner = $this->userWith(['dashboard.view', 'tenders.view_own']);
        $this->tender($owner, 'draft', null, null);

        Sanctum::actingAs($owner);
        $this->getJson('/api/dashboard')->assertOk()
            ->assertJsonPath('tenders.active', 1)
            ->assertJsonPath('tenders.avg_margin_percent', null)
            ->assertJsonPath('tenders.upcoming', []);
    }

    public function test_products_counts_match_catalog_health_rules(): void
    {
        $described = $this->product('P1', 'Opis karty');
        $this->product('P2', null);
        $this->product('P3', '');
        $this->product('P4', null, Product::ENRICHMENT_MANUAL);
        $this->product('P5', '   ');
        ProductImage::query()->create([
            'product_id' => $described->id, 'path' => 'products/'.$described->id.'/a.png', 'is_primary' => true,
            'sort_order' => 0, 'checksum' => str_repeat('a', 64),
        ]);
        $described->forceFill(['embedding_synced_at' => now()])->save();

        Sanctum::actingAs($this->userWith(['dashboard.view', 'products.view']));
        $p = $this->getJson('/api/dashboard')->assertOk()->json('products');

        $this->assertSame(5, $p['total']);
        $this->assertSame(1, $p['with_description'], 'Same spacje to nie opis.');
        $this->assertSame(2, $p['missing_description'], 'Bez kart do ręcznego opisu — jak raport jakości katalogu.');
        $this->assertSame(4, $p['missing_images']);
        $this->assertSame(1, $p['manual_review']);
        $this->assertSame(1, $p['vector_indexed']);
        $this->assertSame(0, $p['substitutes_pending']);
        $this->assertSame(
            $this->getJson('/api/products/catalog-health')->json('missing_description'),
            $p['missing_description'],
        );
    }

    public function test_prices_account_states_and_file_imports(): void
    {
        $ok = B2bAccount::query()->create(['username' => 'ok', 'password' => 'x', 'sites' => ['b2b.anro.net.pl'], 'connector' => 'anro', 'sync_frequency' => 'daily']);
        $ok->forceFill(['last_sync_status' => 'ok', 'last_sync_finished_at' => now()->subHours(3)])->save();
        $failed = B2bAccount::query()->create(['username' => 'zly', 'password' => 'x', 'sites' => ['b2b.anro.net.pl'], 'connector' => 'anro', 'sync_frequency' => 'daily']);
        $failed->forceFill(['last_sync_status' => 'failed', 'last_sync_message' => 'Logowanie odrzucone'])->save();
        $running = B2bAccount::query()->create(['username' => 'trwa', 'password' => 'x', 'sites' => ['b2b.anro.net.pl'], 'connector' => 'anro', 'sync_frequency' => 'off']);
        $running->forceFill(['last_sync_status' => 'running'])->save();
        $running->syncRuns()->create(['status' => 'running', 'trigger' => 'manual', 'started_at' => now(), 'total' => 200, 'processed' => 125]);
        $off = B2bAccount::query()->create(['username' => 'wyl', 'password' => 'x', 'sites' => ['b2b.anro.net.pl'], 'connector' => 'anro', 'sync_frequency' => 'off']);
        $off->forceFill(['last_sync_status' => 'failed', 'last_sync_message' => 'stary błąd'])->save();
        B2bAccount::query()->create(['username' => 'bez', 'password' => 'x', 'sites' => ['b2b.anro.net.pl'], 'connector' => null, 'sync_frequency' => 'daily']);

        $list = PriceList::query()->create(['manufacturer' => 'Ansell', 'version' => '2026', 'original_filename' => 'ansell.xlsx']);
        $this->import($list, PriceListImport::SOURCE_FILE, 40, 1286, now()->subDays(2));
        $this->import($list, PriceListImport::SOURCE_FILE, 7, 10, now()->subDays(9));
        $this->import($list, PriceListImport::SOURCE_B2B, 100, 500, now()->subDay());

        Sanctum::actingAs($this->userWith(['dashboard.view', 'price_lists.view', 'b2b_accounts.view']));
        $p = $this->getJson('/api/dashboard')->assertOk()->json('prices');

        $states = array_column($p['accounts'], 'state', 'id');
        $this->assertSame([$ok->id => 'ok', $failed->id => 'failed', $running->id => 'running', $off->id => 'off'], array_slice($states, 0, 4, true));
        $this->assertSame('off', end($states), 'Konto bez łącznika nie ma harmonogramu.');
        $this->assertSame('Anro', $p['accounts'][0]['label']);
        $this->assertSame('Logowanie odrzucone', $p['accounts'][1]['message']);
        $this->assertNull($p['accounts'][3]['message'], 'Wyłączone konto nie straszy starym błędem.');
        $this->assertSame(62, $p['accounts'][2]['progress']);
        $this->assertSame(140, $p['prices_changed_7d'], 'Pliki i B2B z ostatnich 7 dni.');
        $this->assertSame(['Ansell', 'Ansell'], array_column($p['file_imports'], 'manufacturer'));
        $this->assertSame(1286, $p['file_imports'][0]['rows_total']);
    }

    public function test_campaigns_follow_list_visibility(): void
    {
        $author = User::factory()->create();
        Campaign::query()->create(['user_id' => $author->id, 'name' => 'Cudzy szkic', 'status' => Campaign::STATUS_DRAFT]);
        Campaign::query()->create(['user_id' => $author->id, 'name' => 'Cudza zaplanowana', 'status' => Campaign::STATUS_SCHEDULED, 'scheduled_at' => now()->addDay()]);
        $sent = Campaign::query()->create(['user_id' => $author->id, 'name' => 'Obuwie S3', 'status' => Campaign::STATUS_SENT, 'sent_at' => now()->subDays(3)]);
        $old = Campaign::query()->create(['user_id' => $author->id, 'name' => 'Kurtki', 'status' => Campaign::STATUS_SENT, 'sent_at' => now()->subDays(40)]);
        $r1 = $this->recipient($sent, 'a@alfa.pl', CampaignRecipient::STATUS_SENT, now()->subDays(3), clicks: 2);
        $this->recipient($sent, 'b@alfa.pl', CampaignRecipient::STATUS_SENT, now()->subDays(3));
        $this->recipient($sent, 'c@alfa.pl', CampaignRecipient::STATUS_FAILED, null);
        $this->recipient($old, 'd@alfa.pl', CampaignRecipient::STATUS_SENT, now()->subDays(40));
        DB::table('campaign_replies')->insert([
            'campaign_id' => $sent->id, 'campaign_recipient_id' => $r1->id, 'user_id' => $author->id, 'from_email' => 'a@alfa.pl',
            'subject' => 'Re: Obuwie', 'matched_by' => 'thread', 'message_id' => '<1@alfa.pl>', 'received_at' => now()->subDay(),
            'created_at' => now(), 'updated_at' => now(),
        ]);

        $viewer = $this->userWith(['dashboard.view', 'campaigns.view']);
        Campaign::query()->create(['user_id' => $viewer->id, 'name' => 'Mój szkic', 'status' => Campaign::STATUS_DRAFT]);
        Sanctum::actingAs($viewer);
        $c = $this->getJson('/api/dashboard')->assertOk()->json('campaigns');

        $this->assertSame(1, $c['drafts'], 'Cudzy szkic niewidoczny bez campaigns.manage.');
        $this->assertNull($c['next'], 'Cudza zaplanowana też.');
        $this->assertSame(['Obuwie S3', 'Kurtki'], array_column($c['recent'], 'name'));
        $this->assertSame([2, 1, 1], [$c['recent'][0]['sent'], $c['recent'][0]['clicked'], $c['recent'][0]['replies']]);
        $this->assertSame(2, $c['sent_30d']);
        $this->assertSame(1, $c['replies_30d']);

        Sanctum::actingAs($this->userWith(['dashboard.view', 'campaigns.use', 'campaigns.manage']));
        $c = $this->getJson('/api/dashboard')->assertOk()->json('campaigns');
        $this->assertSame(2, $c['drafts']);
        $this->assertSame('Cudza zaplanowana', $c['next']['name']);
    }

    public function test_stock_reads_latest_snapshot_and_top_unsold_items(): void
    {
        $this->erpItem('A1', 'Kurtka zimowa', 10, 3000.0, '2025-01-10');
        $this->erpItem('B1', 'Półbuty S3', 4, 900.0, null, '2024-01-01');
        $this->erpItem('S1', 'Półmaska', 2, 50.0, '2026-09-20');
        $this->snapshot('2026-10-01', ['stock' => ['items' => 3, 'value' => 3950.0, 'value_unknown' => 0], 'no_sale_12' => ['items' => 2, 'value' => 3900.0, 'value_unknown' => 0]]);
        $this->snapshot('2026-09-30', ['stock' => ['items' => 9, 'value' => 1.0, 'value_unknown' => 0]]);

        Sanctum::actingAs($this->userWith(['dashboard.view', 'inventory.report.view']));
        $s = $this->getJson('/api/dashboard')->assertOk()->json('stock');

        $this->assertSame('2026-10-01', $s['as_of']);
        $this->assertSame(3, $s['buckets']['stock']['items']);
        $this->assertEqualsWithDelta(3950.0, $s['buckets']['stock']['value'], 0.001);
        $this->assertNull($s['buckets']['no_sale_24'], 'Koszyka nie ma w zapisie — brak liczby, nie zero.');
        $this->assertSame(['A1', 'B1'], array_column($s['top_unsold'], 'code'));
        $this->assertEqualsWithDelta(3000.0, $s['top_unsold'][0]['value'], 0.001);
        $this->assertSame('2025-01-10', $s['top_unsold'][0]['last_sale_at']);
        $this->assertNull($s['top_unsold'][1]['last_sale_at']);

        DB::table(InventorySnapshots::TABLE)->delete();
        $this->assertNull($this->getJson('/api/dashboard')->json('stock.buckets'));
    }

    /** @param  list<string>  $permissions */
    private function userWith(array $permissions): User
    {
        $role = Role::findOrCreate('dash-'.Str::random(6), 'web');
        foreach ($permissions as $permission) {
            $role->givePermissionTo(Permission::findOrCreate($permission, 'web'));
        }
        app()[PermissionRegistrar::class]->forgetCachedPermissions();
        $user = User::factory()->create();
        $user->assignRole($role);

        return $user;
    }

    private function tender(User $owner, string $status, ?float $value, ?string $deadline, ?float $margin = null): Tender
    {
        $tender = Tender::query()->create([
            'number' => 'ZP/'.$status.'/'.uniqid(),
            'title' => 'Przetarg '.$status,
            'client_id' => Client::query()->create(['name' => 'Klient '.$status])->id,
            'owner_id' => $owner->id,
            'status' => $status,
            'deadline' => $deadline,
            'ai_percent' => 0,
            'last_activity_at' => now(),
        ]);
        // marża bliźniacza (bez ceny specjalnej) — tę widzi użytkownik bez prices.supplier_special.view
        $tender->forceFill(['offer_value_net' => $value, 'margin_percent' => $margin, 'margin_percent_standard' => $margin])->save();

        return $tender;
    }

    private function product(string $sku, ?string $description, string $status = Product::ENRICHMENT_NONE): Product
    {
        return Product::query()->create([
            'sku' => $sku, 'name' => 'Karta '.$sku, 'manufacturer' => 'ATG', 'description' => $description, 'enrichment_status' => $status,
        ]);
    }

    private function import(PriceList $list, string $source, int $changed, int $rows, \DateTimeInterface $at): void
    {
        $import = PriceListImport::query()->create([
            'price_list_id' => $list->id, 'source' => $source, 'version' => '2026', 'rows_total' => $rows, 'prices_changed' => $changed,
        ]);
        $import->forceFill(['created_at' => $at])->save();
    }

    private function recipient(Campaign $campaign, string $email, string $status, ?\DateTimeInterface $sentAt, int $clicks = 0): CampaignRecipient
    {
        return CampaignRecipient::query()->create([
            'campaign_id' => $campaign->id, 'email' => $email, 'source' => CampaignRecipient::SOURCE_LIST,
            'token' => Str::random(40), 'status' => $status, 'sent_at' => $sentAt, 'clicks' => $clicks,
        ]);
    }

    private function erpItem(string $code, string $name, float $stock, float $value, ?string $lastSale, string $oldestLot = '2024-06-01'): ErpItem
    {
        $warehouses = [['code' => '01H', 'name' => 'Magazyn HANDEL - Rzeszów', 'quantity' => $stock, 'value' => $value]];
        $split = WarehouseSplit::compute($warehouses, ErpWarehouse::serviceCodes(), $oldestLot);
        $item = ErpItem::query()->create([
            'xl_gid' => $this->gid++, 'code' => $code, 'name' => $name, 'unit' => 'szt', 'archived' => false,
            'stock_trade' => $stock, 'stock_total' => $stock, 'stock_value' => $value, 'oldest_lot_at' => $oldestLot,
            'stock_by_warehouse' => $warehouses, ...$split,
            'last_sale_at' => $lastSale, 'synced_at' => now(),
        ]);
        WarehouseLocations::replace((int) $item->id, $warehouses);

        return $item;
    }

    /** @param  array<string, array{items: int, value: float, value_unknown: int}>  $buckets */
    private function snapshot(string $day, array $buckets): void
    {
        DB::table(InventorySnapshots::TABLE)->insert([
            'taken_on' => $day, 'location' => '', 'scope' => 'trade', 'source' => 'live',
            'totals' => json_encode(['version' => InventorySnapshots::RULES_VERSION, 'buckets' => $buckets, 'lot_age' => null]),
            'read_at' => now(), 'created_at' => now(), 'updated_at' => now(),
        ]);
    }
}
