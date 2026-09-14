<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\B2bAccount;
use App\Models\B2bSyncRun;
use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

final class B2bAccountApiTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolesAndPermissionsSeeder::class);
    }

    public function test_admin_creates_account_and_password_is_encrypted_and_hidden(): void
    {
        Sanctum::actingAs(User::factory()->withRole('admin')->create());

        $id = $this->postJson('/api/b2b-accounts', [
            'username' => 'jan.kowalski',
            'password' => 'tajne-haslo-1',
            'sites' => [' b2b.example.test ', 'b2b.example.test', ''],
            'note' => null,
        ])
            ->assertCreated()
            ->assertJsonPath('username', 'jan.kowalski')
            ->assertJsonPath('has_password', true)
            ->assertJsonPath('sites', ['b2b.example.test'])
            ->assertJsonMissingPath('password')
            ->json('id');

        $raw = DB::table('b2b_accounts')->where('id', $id)->value('password');
        $this->assertNotSame('tajne-haslo-1', $raw);
        $this->assertSame('tajne-haslo-1', Crypt::decryptString((string) $raw));

        $this->getJson('/api/b2b-accounts')
            ->assertOk()
            ->assertJsonCount(1)
            ->assertJsonMissingPath('0.password');

        $this->postJson("/api/b2b-accounts/{$id}/password")
            ->assertOk()
            ->assertJsonPath('password', 'tajne-haslo-1');

        $this->deleteJson("/api/b2b-accounts/{$id}")->assertOk();
        $this->assertSame(0, B2bAccount::query()->count());
    }

    public function test_sync_progress_returns_latest_run_with_log_recent_runs_and_scheduler_health(): void
    {
        Sanctum::actingAs(User::factory()->withRole('admin')->create());
        $this->travelTo(now()->startOfMinute());

        $account = B2bAccount::query()->create(['username' => 'jan', 'password' => 'sekret', 'sites' => ['b2b.anro.net.pl'], 'connector' => 'anro']);
        $empty = B2bAccount::query()->create(['username' => 'pusty', 'password' => 'sekret', 'sites' => ['b2b.anro.net.pl'], 'connector' => 'anro']);
        $account->forceFill(['sync_requested_at' => now()->subMinute()])->save();

        $account->syncRuns()->create([
            'status' => 'ok', 'trigger' => 'schedule', 'started_at' => now()->subDay(), 'finished_at' => now()->subDay(),
            'total' => 5, 'processed' => 5, 'unchanged' => 5, 'message' => 'W B2B: 5', 'log' => [['at' => now()->toIso8601String(), 'level' => 'info', 'text' => 'stary']],
        ]);
        $latest = $account->syncRuns()->create([
            'status' => 'running', 'trigger' => 'manual', 'started_at' => now()->subMinutes(2),
            'total' => 5156, 'processed' => 120, 'created' => 3, 'updated' => 7, 'unchanged' => 108, 'skipped' => 2,
            'prices_changed' => 1, 'descriptions' => 4, 'images' => 3, 'current_sku' => 'N/IF005',
            'log' => [
                ['at' => now()->toIso8601String(), 'level' => 'info', 'text' => 'Logowanie…'],
                ['at' => now()->toIso8601String(), 'level' => 'warn', 'text' => '[2/5156] X — pominięty: brak ceny w B2B'],
            ],
            'price_changes' => [[
                'product_id' => 9, 'sku' => 'N/IF005', 'name' => 'Znak', 'catalog_old' => 40.8, 'catalog_new' => 42.0, 'catalog_pct' => 2.9,
                'purchase_old' => 36.72, 'purchase_new' => 37.8, 'discount_old' => 10.0, 'discount_new' => 10.0, 'direction' => 'up', 'at' => now()->toIso8601String(),
            ]],
        ]);

        Cache::forever(B2bSyncRun::SCHEDULER_HEARTBEAT_KEY, now()->subMinutes(1)->toIso8601String());

        $response = $this->getJson("/api/b2b-accounts/{$account->id}/sync-progress")
            ->assertOk()
            ->assertJsonStructure([
                'scheduler' => ['last_seen_at', 'healthy'],
                'sync_requested_at',
                'run' => [
                    'id', 'status', 'trigger', 'started_at', 'finished_at', 'updated_at', 'total', 'processed', 'created',
                    'updated', 'unchanged', 'skipped', 'prices_changed', 'descriptions', 'images', 'current_sku', 'message',
                    'cancel_requested', 'log' => [['at', 'level', 'text']], 'price_changes' => [[
                        'product_id', 'sku', 'name', 'catalog_old', 'catalog_new', 'catalog_pct', 'purchase_old', 'purchase_new',
                        'discount_old', 'discount_new', 'direction', 'at',
                    ]],
                ],
                'recent_runs' => [['id', 'status', 'trigger', 'started_at', 'finished_at', 'updated_at', 'total', 'processed', 'cancel_requested']],
            ])
            ->assertJsonPath('scheduler.healthy', true)
            ->assertJsonPath('sync_requested_at', $account->fresh()->sync_requested_at->toIso8601String())
            ->assertJsonPath('run.id', $latest->id)
            ->assertJsonPath('run.status', 'running')
            ->assertJsonPath('run.trigger', 'manual')
            ->assertJsonPath('run.total', 5156)
            ->assertJsonPath('run.processed', 120)
            ->assertJsonPath('run.current_sku', 'N/IF005')
            ->assertJsonPath('run.finished_at', null)
            ->assertJsonPath('run.cancel_requested', false)
            ->assertJsonPath('run.log.1.level', 'warn')
            ->assertJsonPath('run.price_changes.0.product_id', 9)
            ->assertJsonPath('run.price_changes.0.direction', 'up')
            ->assertJsonCount(2, 'recent_runs')
            ->assertJsonPath('recent_runs.0.id', $latest->id)
            ->assertJsonPath('recent_runs.1.status', 'ok')
            ->assertJsonMissingPath('run.price_list_id')
            ->assertJsonMissingPath('recent_runs.0.log')
            ->assertJsonMissingPath('recent_runs.0.price_changes')
            ->assertJsonMissingPath('recent_runs.0.price_list_id');
        $this->assertNotNull($response->json('scheduler.last_seen_at'));

        Cache::forever(B2bSyncRun::SCHEDULER_HEARTBEAT_KEY, now()->subMinutes(10)->toIso8601String());
        $this->getJson("/api/b2b-accounts/{$account->id}/sync-progress")->assertJsonPath('scheduler.healthy', false);

        Cache::forget(B2bSyncRun::SCHEDULER_HEARTBEAT_KEY);
        $this->getJson("/api/b2b-accounts/{$empty->id}/sync-progress")
            ->assertOk()
            ->assertJsonPath('scheduler.last_seen_at', null)
            ->assertJsonPath('scheduler.healthy', false)
            ->assertJsonPath('sync_requested_at', null)
            ->assertJsonPath('run', null)
            ->assertJsonPath('recent_runs', []);
    }

    public function test_cancel_marks_running_run_and_is_logged(): void
    {
        Sanctum::actingAs(User::factory()->withRole('admin')->create());
        $account = B2bAccount::query()->create(['username' => 'jan', 'password' => 'sekret', 'sites' => ['b2b.anro.net.pl'], 'connector' => 'anro']);
        $account->syncRuns()->create(['status' => 'ok', 'trigger' => 'cli', 'started_at' => now()->subHour()]);

        $this->postJson("/api/b2b-accounts/{$account->id}/sync-cancel")
            ->assertUnprocessable()
            ->assertExactJson(['message' => 'Nie trwa żadne pobieranie.']);

        $running = $account->syncRuns()->create(['status' => 'running', 'trigger' => 'manual', 'started_at' => now()]);
        $this->postJson("/api/b2b-accounts/{$account->id}/sync-cancel")
            ->assertOk()
            ->assertExactJson(['ok' => true]);

        $this->assertNotNull($running->fresh()->cancel_requested_at);
        $this->getJson("/api/b2b-accounts/{$account->id}/sync-progress")->assertJsonPath('run.cancel_requested', true);
        $this->assertDatabaseHas('activity_logs', ['action' => 'b2b_account.sync_cancelled']);
    }

    public function test_update_without_password_keeps_saved_password(): void
    {
        Sanctum::actingAs(User::factory()->withRole('admin')->create());

        $account = B2bAccount::query()->create([
            'username' => 'stary',
            'password' => 'haslo-bez-zmian',
            'sites' => ['b2b.example.test'],
        ]);

        $this->patchJson("/api/b2b-accounts/{$account->id}", [
            'username' => 'nowy',
            'password' => '',
            'sites' => ['b2b.example.test', 'sklep.example.test'],
            'note' => 'Konto działu zakupów',
        ])
            ->assertOk()
            ->assertJsonPath('username', 'nowy')
            ->assertJsonPath('note', 'Konto działu zakupów')
            ->assertJsonPath('sites', ['b2b.example.test', 'sklep.example.test']);

        $this->assertSame('haslo-bez-zmian', $account->fresh()->password);
    }

    public function test_create_requires_password_and_site(): void
    {
        Sanctum::actingAs(User::factory()->withRole('admin')->create());

        $this->postJson('/api/b2b-accounts', [
            'username' => 'jan',
            'sites' => [],
        ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['password', 'sites']);
    }

    public function test_connector_is_detected_from_site_and_sync_settings_are_saved(): void
    {
        Sanctum::actingAs(User::factory()->withRole('admin')->create());

        $this->getJson('/api/b2b-connectors')
            ->assertOk()
            ->assertJsonPath('0.key', 'anro')
            ->assertJsonPath('0.host', 'b2b.anro.net.pl');

        $id = $this->postJson('/api/b2b-accounts', [
            'username' => 'jan',
            'password' => 'haslo-testowe',
            'sites' => ['https://b2b.anro.net.pl/'],
            'sync_frequency' => 'daily',
            'sync_images' => false,
        ])
            ->assertCreated()
            ->assertJsonPath('connector', 'anro')
            ->assertJsonPath('connector_label', 'Anro')
            ->assertJsonPath('sync_frequency', 'daily')
            ->assertJsonPath('sync_images', false)
            ->assertJsonPath('last_sync_status', null)
            ->json('id');

        $this->postJson("/api/b2b-accounts/{$id}/sync")->assertOk();
        $this->assertNotNull(B2bAccount::query()->find($id)?->sync_requested_at);

        $this->postJson('/api/b2b-accounts', [
            'username' => 'jan',
            'password' => 'haslo-testowe',
            'sites' => ['b2b.anro.net.pl'],
            'sync_frequency' => 'hourly',
        ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['sync_frequency']);
    }

    public function test_sync_request_needs_connector_and_manage_permission(): void
    {
        $account = B2bAccount::query()->create([
            'username' => 'jan',
            'password' => 'sekret',
            'sites' => ['b2b.example.test'],
        ]);

        Sanctum::actingAs(User::factory()->withRole('admin')->create());
        $this->postJson("/api/b2b-accounts/{$account->id}/sync")->assertUnprocessable();

        Sanctum::actingAs(User::factory()->withRole('kierownik')->create());
        $this->postJson("/api/b2b-accounts/{$account->id}/sync")->assertForbidden();
        $this->getJson('/api/b2b-connectors')->assertForbidden();
    }

    public function test_user_without_b2b_permission_cannot_list_or_reveal(): void
    {
        $account = B2bAccount::query()->create([
            'username' => 'jan',
            'password' => 'sekret',
            'sites' => ['b2b.example.test'],
        ]);

        Sanctum::actingAs(User::factory()->withRole('kierownik')->create());

        $this->getJson('/api/b2b-accounts')->assertForbidden();
        $this->getJson("/api/b2b-accounts/{$account->id}/sync-progress")->assertForbidden();
        $this->postJson("/api/b2b-accounts/{$account->id}/sync-cancel")->assertForbidden();
        $this->postJson("/api/b2b-accounts/{$account->id}/password")->assertForbidden();
        $this->postJson('/api/b2b-accounts', [
            'username' => 'x',
            'password' => 'y',
            'sites' => ['b2b.example.test'],
        ])->assertForbidden();
    }
}
