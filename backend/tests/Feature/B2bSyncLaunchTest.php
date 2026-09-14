<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Console\Commands\B2bSyncDueCommand;
use App\Models\B2bAccount;
use App\Models\B2bSyncRun;
use App\Models\User;
use App\Services\B2b\AnroB2bConnector;
use App\Services\B2b\B2bConnector;
use App\Services\B2b\B2bSyncLauncher;
use App\Services\B2b\BackgroundB2bSyncLauncher;
use App\Services\B2b\InlineB2bSyncLauncher;
use App\Services\B2b\JspB2bConnector;
use App\Services\B2b\SignProjectB2bConnector;
use Carbon\CarbonImmutable;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use RuntimeException;
use Tests\TestCase;
use Throwable;

/**
 * b2b:sync-due uruchamia każde należne konto osobno (poza testami: proces w tle), więc konta różnych dostawców
 * pobierają się równolegle; blokada uruchomienia nie tworzy drugiego procesu, zanim pierwszy zajmie konto.
 */
final class B2bSyncLaunchTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    /** Zamiast procesów w tle: zapisuje, co zostało uruchomione; konto zostaje nietknięte (proces „jeszcze nie ruszył”). */
    private object $spy;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolesAndPermissionsSeeder::class);
        Queue::fake();
        $this->user = User::factory()->withRole('admin')->create();

        $this->spy = new class implements B2bSyncLauncher
        {
            /** @var list<array{0: int, 1: string}> */
            public array $launched = [];

            public ?Throwable $failNext = null;

            public function launch(B2bAccount $account, string $trigger): bool
            {
                if ($this->failNext !== null) {
                    $e = $this->failNext;
                    $this->failNext = null;
                    throw $e;
                }
                $this->launched[] = [(int) $account->id, $trigger];

                return false;
            }
        };
    }

    public function test_container_uses_inline_launcher_in_tests(): void
    {
        $this->assertInstanceOf(InlineB2bSyncLauncher::class, app(B2bSyncLauncher::class));
    }

    public function test_sync_due_launches_all_due_accounts_of_different_suppliers_in_one_run(): void
    {
        Http::fake();
        $this->app->instance(B2bSyncLauncher::class, $this->spy);
        $now = CarbonImmutable::parse('2026-09-15 10:00', B2bAccount::SYNC_TIMEZONE);
        $this->travelTo($now);

        $anroDaily = $this->makeAccount(AnroB2bConnector::class, ['sync_frequency' => 'daily', 'last_sync_started_at' => $now->subHours(25)]);
        $signRequested = $this->makeAccount(SignProjectB2bConnector::class, ['sync_frequency' => 'off', 'sync_requested_at' => $now->subMinutes(2)]);
        $jspRunning = $this->makeAccount(JspB2bConnector::class, ['sync_frequency' => 'daily', 'last_sync_status' => 'running', 'last_sync_started_at' => $now->subHour(), 'sync_requested_at' => null]);
        $anroNotDue = $this->makeAccount(AnroB2bConnector::class, ['sync_frequency' => 'weekly', 'last_sync_status' => 'ok', 'last_sync_started_at' => $now->subDays(2)]);
        $noConnector = $this->makeAccount(null, ['sync_frequency' => 'daily']);

        $this->artisan('b2b:sync-due')
            ->expectsOutputToContain('uruchomiono w tle')
            ->assertSuccessful();

        $this->assertSame([
            [$anroDaily->id, B2bSyncRun::TRIGGER_SCHEDULE],
            [$signRequested->id, B2bSyncRun::TRIGGER_MANUAL],
        ], $this->spy->launched);
        $launchedIds = array_column($this->spy->launched, 0);
        $this->assertNotContains($jspRunning->id, $launchedIds);
        $this->assertNotContains($anroNotDue->id, $launchedIds);
        $this->assertNotContains($noConnector->id, $launchedIds);

        // uruchomienie w tle nie zmienia konta — zajmuje je dopiero proces b2b:sync
        $this->assertNotNull($signRequested->fresh()->sync_requested_at);
        $this->assertSame(0, B2bSyncRun::query()->count());
        Http::assertNothingSent();
    }

    public function test_launch_guard_prevents_second_spawn_until_guard_expires(): void
    {
        $this->app->instance(B2bSyncLauncher::class, $this->spy);
        $this->travelTo(CarbonImmutable::parse('2026-09-15 10:00', B2bAccount::SYNC_TIMEZONE));
        $account = $this->makeAccount(AnroB2bConnector::class, ['sync_requested_at' => now()->subMinute()]);

        $this->artisan('b2b:sync-due')->assertSuccessful();
        $this->assertCount(1, $this->spy->launched);

        // następne wywołanie harmonogramu, a proces jeszcze nie zajął konta
        $this->travel(5)->minutes();
        $this->artisan('b2b:sync-due')
            ->expectsOutputToContain('czeka na start procesu w tle')
            ->assertSuccessful();
        $this->assertCount(1, $this->spy->launched);

        // proces nie ruszył wcale (np. padł przed zajęciem konta) — po wygaśnięciu blokady konto rusza ponownie
        $this->travel(B2bSyncDueCommand::LAUNCH_GUARD_MINUTES - 4)->minutes();
        $this->artisan('b2b:sync-due')->assertSuccessful();
        $this->assertSame([
            [$account->id, B2bSyncRun::TRIGGER_MANUAL],
            [$account->id, B2bSyncRun::TRIGGER_MANUAL],
        ], $this->spy->launched);
    }

    public function test_failed_launch_releases_guard_and_next_run_launches_again(): void
    {
        $this->app->instance(B2bSyncLauncher::class, $this->spy);
        $account = $this->makeAccount(AnroB2bConnector::class, ['sync_requested_at' => now()->subMinute()]);
        $this->spy->failNext = new RuntimeException('Nie można zapisać dziennika');

        $this->artisan('b2b:sync-due')
            ->expectsOutputToContain('Nie można zapisać dziennika')
            ->assertSuccessful();
        $this->assertSame([], $this->spy->launched);
        $this->assertFalse(Cache::has(B2bSyncDueCommand::launchGuardKey($account->id)));

        $this->artisan('b2b:sync-due')->assertSuccessful();
        $this->assertSame([[$account->id, B2bSyncRun::TRIGGER_MANUAL]], $this->spy->launched);
    }

    public function test_sync_with_manual_trigger_records_trigger_and_releases_launch_guard(): void
    {
        Http::fake(['b2b.anro.net.pl/api-zami/api/token' => Http::response(['error_description' => 'Nieprawidłowy login lub hasło'], 400)]);
        $account = $this->makeAccount(AnroB2bConnector::class, ['sync_requested_at' => now()->subMinute()]);
        Cache::put(B2bSyncDueCommand::launchGuardKey($account->id), now()->toIso8601String(), now()->addMinutes(10));

        $this->artisan('b2b:sync', ['account' => $account->id, '--trigger' => 'manual', '--delay' => 0])
            ->expectsOutputToContain('Nieprawidłowy login lub hasło')
            ->assertFailed();

        $run = $account->syncRuns()->sole();
        $this->assertSame(B2bSyncRun::TRIGGER_MANUAL, $run->trigger);
        $this->assertSame('failed', $run->status);
        $this->assertNull($account->fresh()->sync_requested_at);
        $this->assertFalse(Cache::has(B2bSyncDueCommand::launchGuardKey($account->id)));
    }

    public function test_sync_without_trigger_option_records_cli(): void
    {
        Http::fake(['b2b.anro.net.pl/api-zami/api/token' => Http::response(['error_description' => 'Nieprawidłowy login lub hasło'], 400)]);
        $account = $this->makeAccount(AnroB2bConnector::class, []);

        $this->artisan('b2b:sync', ['account' => $account->id, '--delay' => 0])->assertFailed();

        $this->assertSame(B2bSyncRun::TRIGGER_CLI, $account->syncRuns()->sole()->trigger);
    }

    public function test_sync_rejects_unknown_trigger_before_touching_account(): void
    {
        Http::fake();
        $account = $this->makeAccount(AnroB2bConnector::class, []);

        $this->artisan('b2b:sync', ['account' => $account->id, '--trigger' => 'cron'])
            ->expectsOutputToContain('Nieznany --trigger')
            ->assertFailed();

        $this->assertSame(0, B2bSyncRun::query()->count());
        $this->assertNull($account->fresh()->last_sync_status);
        Http::assertNothingSent();
    }

    public function test_background_launcher_builds_detached_shell_command(): void
    {
        $launcher = new BackgroundB2bSyncLauncher(
            app(InlineB2bSyncLauncher::class),
            '/opt/plesk/php/8.3/bin/php',
            "/var/www/vhosts/przetargi/it's/backend/artisan",
            '/var/www/vhosts/przetargi/backend/storage/logs/b2b-sync.log',
            windows: false,
        );

        $this->assertSame(
            "nohup '/opt/plesk/php/8.3/bin/php' '/var/www/vhosts/przetargi/it'\\''s/backend/artisan' b2b:sync 42 '--trigger=schedule'"
            ." >> '/var/www/vhosts/przetargi/backend/storage/logs/b2b-sync.log' 2>&1 < /dev/null &",
            $launcher->command(42, B2bSyncRun::TRIGGER_SCHEDULE),
        );
    }

    public function test_background_launcher_does_not_spawn_when_log_is_not_writable(): void
    {
        $account = $this->makeAccount(AnroB2bConnector::class, ['sync_requested_at' => now()->subMinute()]);
        $launcher = new BackgroundB2bSyncLauncher(
            app(InlineB2bSyncLauncher::class),
            PHP_BINARY,
            base_path('artisan'),
            storage_path('framework/testing/brak-katalogu-'.uniqid().'/b2b-sync.log'),
            windows: false,
        );

        try {
            $launcher->launch($account, B2bSyncRun::TRIGGER_MANUAL);
            $this->fail('Bez zapisu dziennika proces nie powinien ruszyć.');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString('Nie można zapisać dziennika', $e->getMessage());
        }

        $this->assertNull($account->fresh()->last_sync_status);
        $this->assertNotNull($account->fresh()->sync_requested_at);
    }

    public function test_background_launcher_on_windows_runs_in_current_process(): void
    {
        Http::fake(['b2b.anro.net.pl/api-zami/api/token' => Http::response(['error_description' => 'Nieprawidłowy login lub hasło'], 400)]);
        $account = $this->makeAccount(AnroB2bConnector::class, ['sync_requested_at' => now()->subMinute()]);
        $launcher = new BackgroundB2bSyncLauncher(
            app(InlineB2bSyncLauncher::class),
            PHP_BINARY,
            base_path('artisan'),
            storage_path('framework/testing/brak-katalogu-'.uniqid().'/b2b-sync.log'),
            windows: true,
        );

        try {
            $launcher->launch($account, B2bSyncRun::TRIGGER_MANUAL);
            $this->fail('Logowanie w teście się nie udaje — przebieg powinien zgłosić błąd.');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString('Nieprawidłowy login lub hasło', $e->getMessage());
        }

        $this->assertSame(B2bSyncRun::TRIGGER_MANUAL, $account->syncRuns()->sole()->trigger);
    }

    /**
     * @param  class-string<B2bConnector>|null  $connector
     * @param  array<string, mixed>  $attributes
     */
    private function makeAccount(?string $connector, array $attributes): B2bAccount
    {
        static $n = 0;
        $n++;
        $account = B2bAccount::query()->create([
            'username' => 'konto-'.$n,
            'password' => 'sekret',
            'sites' => [$connector !== null ? $connector::host() : 'b2b.example.test'],
            'connector' => $connector !== null ? $connector::key() : 'anro',
            'created_by' => $this->user->id,
            'updated_by' => $this->user->id,
        ]);
        $account->forceFill(['connector' => $connector !== null ? $connector::key() : null, ...$attributes])->save();

        return $account->fresh();
    }
}
