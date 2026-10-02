<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\B2bAccount;
use App\Models\B2bSyncRun;
use App\Models\PriceList;
use App\Models\PriceListImport;
use App\Models\Product;
use App\Models\User;
use Carbon\CarbonImmutable;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * GET /api/reports/sources — raport R3 „Źródła danych”. Teraz = 02.10.2026 12:00 w Warszawie (10:00 UTC).
 */
final class ReportSourcesApiTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolesAndPermissionsSeeder::class);
        $this->travelTo(CarbonImmutable::parse('2026-10-02 12:00:00', 'Europe/Warsaw'));
    }

    public function test_requires_reports_view_and_a_source_permission(): void
    {
        Sanctum::actingAs($this->userWith(['price_lists.view', 'b2b_accounts.view']));
        $this->getJson('/api/reports/sources')->assertForbidden();

        Sanctum::actingAs($this->userWith(['reports.view']));
        $this->getJson('/api/reports/sources')->assertForbidden();

        Sanctum::actingAs($this->userWith(['reports.view', 'b2b_accounts.view']));
        $this->getJson('/api/reports/sources')->assertOk();
    }

    public function test_accounts_states_freshness_streaks_series_and_permission_filter(): void
    {
        $anro = $this->account(['connector' => 'anro', 'sync_frequency' => 'daily']);
        $uvexWeekly = $this->account(['connector' => 'uvex', 'sync_frequency' => 'weekly']);
        $uvexOff = $this->account(['connector' => 'uvex', 'sync_frequency' => 'off']);
        $unknown = $this->account(['sites' => ['https://nieznany.example'], 'sync_frequency' => 'daily']);

        // poza 30 dniami — nie w serii ani w runs_30d
        $this->syncRun($anro, 'cancelled', '2026-08-01 02:00:00', '2026-08-01 02:10:00');
        // Anro: ok 30.09, potem dwa nieudane — świeżość z ok (54,5 h > 48 h), seria 2
        $this->syncRun($anro, 'ok', '2026-09-30 03:00:00', '2026-09-30 03:30:00', pricesChanged: 7);
        // 22:30 UTC 30.09 = 01.10 w Warszawie
        $this->syncRun($anro, 'failed', '2026-09-30 22:30:00', '2026-09-30 22:40:00');
        $this->syncRun($anro, 'failed', '2026-10-02 02:00:00', '2026-10-02 02:10:00', message: 'Błąd logowania');

        // UVEX tygodniowo: częściowy (status ok) — świeży, liczy się do last_ok_at
        $this->syncRun($uvexWeekly, 'ok', '2026-09-29 01:00:00', '2026-09-29 02:00:00', pricesChanged: 3,
            message: "Częściowy: 10/20 produktów — kontynuacja w następnym przebiegu\nPodsumowanie");

        // UVEX wyłączony: running bez sygnału życia od 60 min → przerwany
        $this->syncRun($uvexOff, 'running', '2026-10-02 08:00:00', null, updatedAt: '2026-10-02 09:00:00');

        $products = collect(range(1, 3))->map(fn (int $i): int => Product::query()->create([
            'sku' => 'S'.$i, 'name' => 'Karta '.$i, 'manufacturer' => 'Anro', 'catalog_price_net' => 1, 'purchase_price' => 1, 'stock' => 0,
        ])->id)->all();
        $this->link($anro, 'r1', $products[0], '2026-10-01 10:00:00');
        $this->link($anro, 'r2', $products[1], '2026-09-20 10:00:00');
        $this->link($anro, 'r3', $products[2], null);
        $this->link($anro, 'r4', $products[2], '2026-09-30 10:00:00');
        $this->link($anro, 'r5', $products[0], '2026-09-26 10:00:00');
        $this->link($anro, 'r6', $products[1], '2026-10-02 09:00:00');

        Sanctum::actingAs($this->userWith(['reports.view', 'b2b_accounts.view']));
        $r = $this->getJson('/api/reports/sources')->assertOk();

        $this->assertSame(['daily_hours' => 48, 'weekly_days' => 9, 'file_old_days' => 180], $r->json('thresholds'));
        $this->assertNull($r->json('files'));
        $this->assertSame([
            'accounts' => 4, 'scheduled' => 3, 'fresh' => 1, 'stale' => 2, 'failing' => 2, 'never_ok' => 2,
        ], $r->json('b2b.totals'));

        $accounts = $r->json('b2b.accounts');
        // problemy (nieudane/nieświeże) wg etykiety, potem reszta
        $this->assertSame([$anro->id, $unknown->id, $uvexOff->id, $uvexWeekly->id], array_column($accounts, 'id'));
        $byId = array_column($accounts, null, 'id');

        $this->assertSame([
            'id' => $anro->id,
            'label' => 'Anro',
            'connector' => 'anro',
            'frequency' => 'daily',
            'last_status' => 'failed',
            'last_run_at' => '2026-10-02T02:00:00+00:00',
            'last_ok_at' => '2026-09-30T03:30:00+00:00',
            'hours_since_ok' => 54.5,
            'stale' => true,
            'failed_streak' => 2,
            'runs_30d' => 3,
            'failed_30d' => 2,
            'products' => 3,
            'seen_7d_pct' => 66.7,
            'last_prices_changed' => 7,
            'message' => 'Błąd logowania',
        ], $byId[$anro->id]);

        $uvex = $byId[$uvexWeekly->id];
        $this->assertSame('UVEX · #'.$uvexWeekly->id, $uvex['label']);
        $this->assertSame('partial', $uvex['last_status']);
        $this->assertSame('2026-09-29T02:00:00+00:00', $uvex['last_ok_at']);
        $this->assertSame(80.0, (float) $uvex['hours_since_ok']);
        $this->assertFalse($uvex['stale']);
        $this->assertSame(0, $uvex['failed_streak']);
        $this->assertSame(3, $uvex['last_prices_changed']);
        $this->assertSame(0, $uvex['products']);
        $this->assertNull($uvex['seen_7d_pct']);

        $off = $byId[$uvexOff->id];
        $this->assertSame('UVEX · #'.$uvexOff->id, $off['label']);
        $this->assertSame('interrupted', $off['last_status']);
        $this->assertFalse($off['stale']);
        $this->assertSame(1, $off['failed_streak']);
        $this->assertSame(1, $off['failed_30d']);
        $this->assertNull($off['last_ok_at']);
        $this->assertNull($off['hours_since_ok']);
        $this->assertNull($off['last_prices_changed']);

        $none = $byId[$unknown->id];
        $this->assertSame('Konto #'.$unknown->id, $none['label']);
        $this->assertNull($none['connector']);
        $this->assertNull($none['last_status']);
        $this->assertNull($none['last_run_at']);
        $this->assertTrue($none['stale']);
        $this->assertSame(0, $none['runs_30d']);

        // żadnego loginu w odpowiedzi
        $this->assertStringNotContainsString('login-', $r->getContent());

        $daily = $r->json('b2b.daily');
        $this->assertCount(30, $daily);
        $this->assertSame('2026-09-03', $daily[0]['day']);
        $this->assertSame('2026-10-02', $daily[29]['day']);
        $byDay = array_column($daily, null, 'day');
        $zero = ['ok' => 0, 'partial' => 0, 'failed' => 0, 'cancelled' => 0, 'interrupted' => 0];
        $this->assertSame(['day' => '2026-09-29', ...$zero, 'partial' => 1], $byDay['2026-09-29']);
        $this->assertSame(['day' => '2026-09-30', ...$zero, 'ok' => 1], $byDay['2026-09-30']);
        $this->assertSame(['day' => '2026-10-01', ...$zero, 'failed' => 1], $byDay['2026-10-01']);
        $this->assertSame(['day' => '2026-10-02', ...$zero, 'failed' => 1, 'interrupted' => 1], $byDay['2026-10-02']);
        $this->assertSame(['day' => '2026-09-15', ...$zero], $byDay['2026-09-15']);

        // te same dane z cache — bez b2b_accounts.view komunikaty puste, z price_lists.view sekcja plików jest
        Sanctum::actingAs($this->userWith(['reports.view', 'price_lists.view']));
        $r = $this->getJson('/api/reports/sources')->assertOk();
        $this->assertSame([null, null, null, null], array_column($r->json('b2b.accounts'), 'message'));
        $this->assertSame([], $r->json('files'));
    }

    public function test_file_price_lists_from_latest_file_import(): void
    {
        $ansell = PriceList::query()->create(['manufacturer' => 'Ansell', 'version' => 'B2B · aktualizacja']);
        $atg = PriceList::query()->create(['manufacturer' => 'ATG', 'version' => 'x', 'suggested_prices' => true]);
        $b2bOnly = PriceList::query()->create(['manufacturer' => 'Tylko B2B', 'version' => 'x']);

        // sprzed 12 mies. — nie w imports_12m
        $this->import($ansell, PriceListImport::SOURCE_FILE, '2025-09-01 10:00:00', ['version' => 'v0', 'rows_total' => 90]);
        $this->import($ansell, PriceListImport::SOURCE_FILE, '2026-03-01 10:00:00', [
            'version' => 'v1', 'rows_total' => 100, 'prices_changed' => 5, 'rows_skipped' => 2,
        ]);
        // przebieg B2B nie jest importem pliku
        $this->import($ansell, PriceListImport::SOURCE_B2B, '2026-09-30 10:00:00', ['version' => 'B2B', 'rows_total' => 999]);
        // 23:30 UTC 30.09 = 01.10 w Warszawie → 1 dzień temu
        $this->import($atg, PriceListImport::SOURCE_FILE, '2026-09-30 23:30:00', ['version' => null, 'rows_total' => 50]);
        $this->import($b2bOnly, PriceListImport::SOURCE_B2B, '2026-09-30 10:00:00', []);

        Sanctum::actingAs($this->userWith(['reports.view', 'price_lists.view']));
        $r = $this->getJson('/api/reports/sources')->assertOk();

        $this->assertSame([
            [
                'price_list_id' => $ansell->id, 'manufacturer' => 'Ansell', 'version' => 'v1',
                'last_import_at' => '2026-03-01T10:00:00+00:00', 'days_ago' => 215, 'imports_12m' => 1,
                'rows_total' => 100, 'prices_changed' => 5, 'rows_skipped' => 2, 'suggested' => false, 'old' => true,
            ],
            [
                'price_list_id' => $atg->id, 'manufacturer' => 'ATG', 'version' => null,
                'last_import_at' => '2026-09-30T23:30:00+00:00', 'days_ago' => 1, 'imports_12m' => 1,
                'rows_total' => 50, 'prices_changed' => 0, 'rows_skipped' => 0, 'suggested' => true, 'old' => false,
            ],
        ], $r->json('files'));
        $this->assertSame(['accounts' => 0, 'scheduled' => 0, 'fresh' => 0, 'stale' => 0, 'failing' => 0, 'never_ok' => 0], $r->json('b2b.totals'));
        $this->assertCount(30, $r->json('b2b.daily'));
    }

    /**
     * @param  list<string>  $permissions
     */
    private function userWith(array $permissions): User
    {
        $user = User::factory()->create();
        $user->givePermissionTo($permissions);

        return $user->fresh();
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    private function account(array $attributes): B2bAccount
    {
        static $n = 0;
        $n++;

        return B2bAccount::query()->create([
            'username' => 'login-'.$n.'@example.com',
            'password' => 'sekret',
            'sites' => ['https://b2b.anro.net.pl'],
            ...$attributes,
        ]);
    }

    private function syncRun(
        B2bAccount $account,
        string $status,
        string $startedAt,
        ?string $finishedAt,
        int $pricesChanged = 0,
        ?string $message = null,
        ?string $updatedAt = null,
    ): void {
        DB::table('b2b_sync_runs')->insert([
            'b2b_account_id' => $account->id,
            'status' => $status,
            'trigger' => B2bSyncRun::TRIGGER_SCHEDULE,
            'started_at' => $startedAt,
            'finished_at' => $finishedAt,
            'prices_changed' => $pricesChanged,
            'message' => $message,
            'created_at' => $startedAt,
            'updated_at' => $updatedAt ?? $finishedAt ?? $startedAt,
        ]);
    }

    private function link(B2bAccount $account, string $remoteId, int $productId, ?string $lastSeenAt): void
    {
        DB::table('b2b_product_links')->insert([
            'b2b_account_id' => $account->id,
            'remote_id' => $remoteId,
            'product_id' => $productId,
            'last_seen_at' => $lastSeenAt,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    private function import(PriceList $list, string $source, string $createdAt, array $attributes): void
    {
        DB::table('price_list_imports')->insert([
            'price_list_id' => $list->id,
            'source' => $source,
            'created_at' => $createdAt,
            'updated_at' => $createdAt,
            ...$attributes,
        ]);
    }
}
