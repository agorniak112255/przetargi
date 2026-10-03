<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\B2bAccount;
use App\Models\B2bSyncRun;
use App\Models\Client;
use App\Models\ClientInquiry;
use App\Models\ErpItem;
use App\Models\ScheduledTaskRun;
use App\Models\SystemAlert;
use App\Models\Tender;
use App\Models\User;
use App\Services\System\ScheduledTaskRecorder;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

/**
 * Administracja › Stan systemu: uprawnienie, kafle, zadania w czasie polskim, alerty z wyciszaniem, „Dane do
 * uzupełnienia”.
 */
class SystemStatusApiTest extends TestCase
{
    use RefreshDatabase;

    private int $gid = 1;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolesAndPermissionsSeeder::class);
        config(['erpxl.enabled' => false]);
        // sobota 3.10.2026, 9:14 w Polsce (czas letni, UTC+2)
        $this->travelTo(Carbon::parse('2026-10-03 07:14:00', 'UTC'));
        Cache::forever(B2bSyncRun::SCHEDULER_HEARTBEAT_KEY, now()->subMinute()->toIso8601String());
    }

    public function test_needs_admin_access_and_system_view(): void
    {
        Sanctum::actingAs($this->userWith(['admin.access']));
        $this->getJson('/api/admin/system-status')->assertForbidden();
        $this->getJson('/api/admin/system-status/gaps/tenders_without_time')->assertForbidden();

        Sanctum::actingAs($this->userWith(['admin.system.view']));
        $this->getJson('/api/admin/system-status')->assertForbidden();

        Sanctum::actingAs($this->userWith(['admin.access', 'admin.system.view']));
        $this->getJson('/api/admin/system-status')->assertOk()
            ->assertJsonPath('scheduler.ok', true)
            ->assertJsonStructure(['checked_at', 'scheduler', 'tiles' => ['tasks', 'b2b', 'inquiries_queue', 'model'], 'alerts', 'tasks', 'gaps']);
        $this->getJson('/api/admin/system-status/gaps/nieznany')->assertNotFound();
    }

    public function test_every_configured_task_exists_in_the_real_schedule(): void
    {
        $events = app(ScheduledTaskRecorder::class)->scheduledEvents();

        $this->assertEqualsCanonicalizing(array_keys(config('system_health.tasks')), array_keys($events), 'Klucz w config/system_health.php = polecenie albo nazwa z bootstrap/app.php.');
    }

    public function test_tasks_show_polish_time_and_last_runs_and_tile_counts_enabled_nightly_tasks(): void
    {
        $this->taskRun('activity-logs:prune', 'ok', now()->subHours(5), 6 * 60000, 'Usunięto 120 wpisów.');
        $this->taskRun('search-events:prune', 'failed', now()->subHours(5), 1000, 'SQLSTATE błąd');
        $this->taskRun('search-events:prune', 'running', now()->subMinute(), null, null);
        $this->taskRun('erp:clients', 'ok', now()->subHours(4), 60000, null);

        Sanctum::actingAs($this->userWith(['admin.access', 'admin.system.view']));
        $data = $this->getJson('/api/admin/system-status')->assertOk()->json();
        $tasks = collect($data['tasks'])->keyBy('task');

        $this->assertSame('codziennie 4:15', $tasks['activity-logs:prune']['schedule_pl'], '02:15 UTC = 4:15 czasu polskiego w październiku.');
        $this->assertSame('codziennie 6:30', $tasks['bzp:fetch']['schedule_pl'], 'Zadanie planowane w czasie polskim.');
        $this->assertSame('co 15 minut', $tasks['tenders:remind']['schedule_pl']);
        $this->assertFalse($tasks['erp:clients']['enabled'], 'Bez ERPXL_ENABLED zadanie się nie uruchamia.');
        $this->assertTrue($tasks['activity-logs:prune']['enabled']);
        $this->assertSame('ok', $tasks['activity-logs:prune']['last']['status']);
        $this->assertSame(360000, $tasks['activity-logs:prune']['last']['duration_ms']);
        $this->assertSame('Usunięto 120 wpisów.', $tasks['activity-logs:prune']['last']['output_tail']);
        $this->assertSame('running', $tasks['search-events:prune']['last']['status'], 'Ostatni wiersz — przebieg w toku.');
        $this->assertNull($tasks['bzp:fetch']['last']);
        $this->assertArrayNotHasKey('last_finished', $tasks['bzp:fetch']);

        // kolejność: zadania nocne po godzinie startu w czasie polskim, potem częste
        $nightly = collect($data['tasks'])->where('nightly', true)->values();
        $this->assertSame(collect($data['tasks'])->take($nightly->count())->pluck('task')->all(), $nightly->pluck('task')->all());

        $enabledNightly = $nightly->where('enabled', true);
        $this->assertSame($enabledNightly->count(), $data['tiles']['tasks']['total']);
        $this->assertSame(1, $data['tiles']['tasks']['ok'], 'Ostatni zakończony przebieg „ok” — tylko czyszczenie dziennika (search-events: ostatni zakończony to błąd).');
        $this->assertSame(Carbon::parse('2026-10-03 02:20:00', 'UTC')->toIso8601String(), $data['tiles']['tasks']['last_finished_at']);
    }

    public function test_b2b_inquiry_queue_and_model_tiles(): void
    {
        $this->account('anro', 'daily', B2bSyncRun::STATUS_OK);
        $this->account('anro', 'daily', B2bSyncRun::STATUS_FAILED);
        $this->account('anro', 'daily', B2bSyncRun::STATUS_RUNNING);
        $this->account('anro', 'off', B2bSyncRun::STATUS_FAILED);
        $this->account(null, 'daily', B2bSyncRun::STATUS_OK);

        $user = User::factory()->create();
        $queued = ClientInquiry::query()->create(['user_id' => $user->id, 'source_body' => 'x', 'analysis_status' => ClientInquiry::ANALYSIS_QUEUED]);
        $queued->forceFill(['updated_at' => now()->subSeconds(40)])->save();
        ClientInquiry::query()->create(['user_id' => $user->id, 'source_body' => 'y', 'analysis_status' => ClientInquiry::ANALYSIS_QUEUED]);
        ClientInquiry::query()->create(['user_id' => $user->id, 'source_body' => 'z', 'analysis_status' => ClientInquiry::ANALYSIS_DONE]);

        $this->searchEvent('tender_item', 3000, null, now()->subHours(2));
        $this->searchEvent('inquiry', 5000, 'unavailable', now()->subHour());
        $this->searchEvent('tender_item', 9000, null, now()->subDays(2));
        $this->searchEvent('product_search_web', 99000, null, now()->subHour());

        Sanctum::actingAs($this->userWith(['admin.access', 'admin.system.view']));
        $tiles = $this->getJson('/api/admin/system-status')->assertOk()->json('tiles');

        $this->assertSame(['ok' => 1, 'total' => 3, 'failing' => 1], $tiles['b2b'], 'Tylko konta z łącznikiem i harmonogramem.');
        $this->assertSame(['waiting' => 2, 'oldest_wait_seconds' => 40], $tiles['inquiries_queue']);
        $this->assertSame(2, $tiles['model']['searches_24h']);
        $this->assertEqualsWithDelta(4.0, $tiles['model']['avg_seconds'], 0.001);
        $this->assertSame(1, $tiles['model']['unavailable_24h']);
        $this->assertSame(now()->subHour()->toIso8601String(), $tiles['model']['last_at']);
    }

    public function test_model_tile_is_null_without_any_search_and_keeps_last_use_after_quiet_day(): void
    {
        Sanctum::actingAs($this->userWith(['admin.access', 'admin.system.view']));
        $this->getJson('/api/admin/system-status')->assertOk()->assertJsonPath('tiles.model', null);

        $this->searchEvent('tender_item', 3000, null, now()->subDays(3));
        $model = $this->getJson('/api/admin/system-status')->assertOk()->json('tiles.model');
        $this->assertSame(0, $model['searches_24h']);
        $this->assertNull($model['avg_seconds']);
        $this->assertSame(now()->subDays(3)->toIso8601String(), $model['last_at']);
    }

    public function test_open_alerts_are_listed_and_can_be_muted_and_unmuted(): void
    {
        $alert = SystemAlert::query()->create([
            'kind' => 'b2b', 'subject_key' => 'b2b:7', 'title' => 'Konto Portwest · pobieranie cen', 'first_failed_at' => now()->subDays(2),
            'last_failed_at' => now()->subHour(), 'failures' => 3, 'last_message' => 'Logowanie nieudane', 'emailed_at' => now()->subDays(2),
        ]);
        SystemAlert::query()->create([
            'kind' => 'task', 'subject_key' => 'task:x', 'title' => 'Zamknięty', 'first_failed_at' => now()->subDays(5),
            'last_failed_at' => now()->subDays(5), 'failures' => 1, 'resolved_at' => now()->subDays(4),
        ]);
        $admin = $this->userWith(['admin.access', 'admin.system.view']);
        Sanctum::actingAs($admin);

        $alerts = $this->getJson('/api/admin/system-status')->assertOk()->json('alerts');
        $this->assertCount(1, $alerts);
        $this->assertSame($alert->id, $alerts[0]['id']);
        $this->assertSame(now()->subDays(2)->toIso8601String(), $alerts[0]['since']);
        $this->assertSame('/price-lists/b2b', $alerts[0]['url']);
        $this->assertFalse($alerts[0]['muted']);

        $this->postJson("/api/admin/system-alerts/{$alert->id}/mute")->assertOk()->assertJsonPath('muted', true)->assertJsonPath('id', $alert->id);
        $this->assertSame($admin->id, (int) $alert->fresh()->muted_by);
        $this->postJson("/api/admin/system-alerts/{$alert->id}/unmute")->assertOk()->assertJsonPath('muted', false);
        $this->assertNull($alert->fresh()->muted_at);

        Sanctum::actingAs($this->userWith(['admin.access']));
        $this->postJson("/api/admin/system-alerts/{$alert->id}/mute")->assertForbidden();
    }

    public function test_gap_counts_and_rows(): void
    {
        $t1 = $this->tender('wycena', '2026-10-05', null, null, 'Szpital');
        $this->tender('wycena', '2026-10-06', '10:00', '2026/BZP 00431178/01', 'Gmina');
        $t3 = $this->tender('exported', '2026-09-20', null, null, 'Zakład');
        $this->tender('exported', '2026-07-01', null, null, 'Dawno');
        $this->tender('odrzucony', '2026-10-08', null, null, 'Odrzucony');
        $lost = $this->tender('exported', '2026-09-25', null, null, 'Z wynikiem');
        $lost->forceFill(['result_status' => 'lost'])->save();
        $this->tender('wycena', '2026-10-02', null, null, 'Po terminie w wycenie');
        $inXl = Client::query()->create(['name' => 'Klient w XL']);
        $inXl->forceFill(['xl_gid' => 555])->save();
        $this->tender('archiwum', '2026-06-01', '09:00', '2026/BZP 00000001/01', 'x', $inXl);
        Client::query()->create(['name' => 'Bez przetargów']);

        $seller = User::factory()->withRole('handlowiec')->create(['name' => 'Anna Nowak']);
        $withIdent = User::factory()->withRole('handlowiec')->create();
        $withIdent->forceFill(['erp_operator_ident' => 'ANOWAK'])->save();
        User::factory()->withRole('kierownik')->create();

        $sold = $this->erpItem('A100', 'Rękawice nitrylowe', null, '2026-08-01');
        $this->erpItem('A101', 'Powiązany', 'auto', '2026-08-01');
        $this->erpItem('A102', 'Dawno sprzedany', null, '2025-09-01');
        $this->erpItem('A103', 'Nigdy', null, null);
        $this->erpItem('A104', 'Zarchiwizowany', null, '2026-08-01', archived: true);
        $this->erpItem('A105', 'Propozycja', 'suggested', '2026-09-01');

        Sanctum::actingAs($this->userWith(['admin.access', 'admin.system.view']));
        $gaps = collect($this->getJson('/api/admin/system-status')->assertOk()->json('gaps'))->keyBy('kind');

        $this->assertSame(['tenders_without_time', 'tenders_without_notice', 'salespeople_without_operator', 'clients_without_xl', 'sold_items_without_card'], $gaps->keys()->all());
        $this->assertSame('Przetargi w toku bez godziny składania', $gaps['tenders_without_time']['label']);
        $this->assertSame(1, $gaps['tenders_without_time']['count'], 'Tylko w toku i z terminem od dziś.');
        $this->assertSame(3, $gaps['tenders_without_notice']['count'], 'W toku, złożone bez wyniku (do 60 dni po terminie); bez odrzuconych i z wynikiem.');
        $this->assertSame(1, $gaps['salespeople_without_operator']['count']);
        $this->assertSame(7, $gaps['clients_without_xl']['count'], 'Zamawiający z przetargami, bez numeru kontrahenta XL.');
        $this->assertSame(2, $gaps['sold_items_without_card']['count'], 'Sprzedaż w 12 miesiącach, bez potwierdzonego powiązania.');

        $rows = $this->getJson('/api/admin/system-status/gaps/tenders_without_time')->assertOk()->json('data');
        $this->assertSame([['id' => $t1->id, 'label' => 'Szpital · '.$t1->number, 'detail' => 'Termin składania 5.10.2026', 'url' => '/tenders/'.$t1->id]], $rows);

        $rows = $this->getJson('/api/admin/system-status/gaps/tenders_without_notice')->assertOk()->json('data');
        $this->assertSame($t3->id, $rows[0]['id'], 'Najstarszy termin pierwszy.');

        $rows = $this->getJson('/api/admin/system-status/gaps/salespeople_without_operator')->assertOk()->json('data');
        $this->assertSame([$seller->id], array_column($rows, 'id'));

        $rows = $this->getJson('/api/admin/system-status/gaps/clients_without_xl')->assertOk()->json('data');
        $this->assertNotContains($inXl->id, array_column($rows, 'id'));
        $this->assertStringContainsString('przetargi: 1', (string) $rows[0]['detail']);

        $rows = $this->getJson('/api/admin/system-status/gaps/sold_items_without_card')->assertOk()->json('data');
        $this->assertSame('A105 · Propozycja', $rows[0]['label'], 'Ostatnio sprzedawane pierwsze.');
        $this->assertSame($sold->id, $rows[1]['id']);
        $this->assertSame('Ostatnia sprzedaż 1.8.2026', $rows[1]['detail']);
        $this->assertSame('/admin/erp-xl?status=unlinked&search=A100', $rows[1]['url']);

        config(['system_health.gap_rows_limit' => 1]);
        $this->assertCount(1, $this->getJson('/api/admin/system-status/gaps/clients_without_xl')->json('data'));
    }

    /** @param  list<string>  $permissions */
    private function userWith(array $permissions): User
    {
        $role = Role::findOrCreate('stan-'.Str::random(6), 'web');
        foreach ($permissions as $permission) {
            $role->givePermissionTo(Permission::findOrCreate($permission, 'web'));
        }
        app()[PermissionRegistrar::class]->forgetCachedPermissions();
        $user = User::factory()->create();
        $user->assignRole($role);

        return $user;
    }

    private function taskRun(string $task, string $status, \DateTimeInterface $startedAt, ?int $durationMs, ?string $tail): void
    {
        ScheduledTaskRun::query()->create([
            'task' => $task, 'started_at' => $startedAt, 'status' => $status, 'duration_ms' => $durationMs, 'output_tail' => $tail,
            'finished_at' => $durationMs !== null ? Carbon::instance($startedAt)->addMilliseconds($durationMs) : null,
            'exit_code' => $status === 'ok' ? 0 : ($status === 'failed' ? 1 : null),
        ]);
    }

    private function account(?string $connector, string $frequency, string $status): void
    {
        $account = B2bAccount::query()->create([
            'username' => 'konto-'.uniqid(), 'password' => 'x', 'sites' => ['b2b.anro.net.pl'], 'connector' => $connector, 'sync_frequency' => $frequency,
        ]);
        $account->forceFill(['last_sync_status' => $status, 'last_sync_finished_at' => now()->subHour()])->save();
    }

    private function searchEvent(string $task, int $durationMs, ?string $modelState, \DateTimeInterface $at): void
    {
        DB::table('search_events')->insert([
            'task' => $task, 'query' => 'kurtka', 'duration_ms' => $durationMs, 'model_state' => $modelState, 'created_at' => $at, 'updated_at' => $at,
        ]);
    }

    private function tender(string $status, ?string $deadline, ?string $time, ?string $notice, string $client, ?Client $existing = null): Tender
    {
        $tender = Tender::query()->create([
            'number' => 'PRZ/'.uniqid('', true),
            'title' => 'Przetarg '.$client,
            'client_id' => ($existing ?? Client::query()->create(['name' => $client]))->id,
            'owner_id' => User::factory()->create()->id,
            'status' => $status,
            'deadline' => $deadline,
            'deadline_time' => $time,
            'ai_percent' => 0,
            'last_activity_at' => now(),
        ]);
        $tender->forceFill(['notice_number' => $notice])->save();

        return $tender;
    }

    private function erpItem(string $code, string $name, ?string $outcome, ?string $lastSale, bool $archived = false): ErpItem
    {
        return ErpItem::query()->create([
            'xl_gid' => $this->gid++, 'code' => $code, 'name' => $name, 'archived' => $archived, 'match_outcome' => $outcome,
            'last_sale_at' => $lastSale, 'synced_at' => now(),
        ]);
    }
}
