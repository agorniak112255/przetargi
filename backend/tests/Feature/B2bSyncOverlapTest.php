<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\B2bAccount;
use App\Models\B2bSyncRun;
use App\Models\User;
use App\Services\B2b\B2bAccountSyncRunner;
use Carbon\CarbonImmutable;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use RuntimeException;
use Tests\TestCase;

/**
 * Brak podwójnych przebiegów: długie pobranie (SignProject — kilka godzin) nie może dostać drugiego przebiegu
 * z harmonogramu ani z CLI; przerwany przebieg wykrywa tylko brak postępu (sygnał życia).
 */
final class B2bSyncOverlapTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolesAndPermissionsSeeder::class);
        Queue::fake();
        $this->user = User::factory()->withRole('admin')->create();
        Http::fake(['b2b.anro.net.pl/api-zami/api/token' => Http::response(['error_description' => 'Nieprawidłowy login lub hasło'], 400)]);
    }

    public function test_run_started_at_18_is_not_started_again_at_02_while_it_makes_progress(): void
    {
        $this->travelTo(CarbonImmutable::parse('2026-09-16 02:00', B2bAccount::SYNC_TIMEZONE));
        [$account, $run] = $this->runningSince('2026-09-15 18:00', heartbeatMinutesAgo: 2);

        $this->assertFalse($account->isSyncDue(now()));
        $this->artisan('b2b:sync-due')->assertSuccessful();

        $this->assertSame(1, B2bSyncRun::query()->count());
        $this->assertSame('running', $run->fresh()->status);
        $this->assertSame('running', $account->fresh()->last_sync_status);
        Http::assertNothingSent();
    }

    public function test_runner_refuses_account_that_is_already_running(): void
    {
        [$account, $run] = $this->runningSince('2026-09-15 18:00', heartbeatMinutesAgo: 1);

        try {
            app(B2bAccountSyncRunner::class)->run($account, delayMs: 0);
            $this->fail('Drugi przebieg nie powinien ruszyć.');
        } catch (RuntimeException $e) {
            $this->assertSame(B2bAccountSyncRunner::ALREADY_RUNNING, $e->getMessage());
        }

        $this->assertSame(1, B2bSyncRun::query()->count());
        $this->assertSame('running', $run->fresh()->status);
        $account = $account->fresh();
        $this->assertSame('running', $account->last_sync_status);
        $this->assertNull($account->last_sync_message);
        Http::assertNothingSent();
    }

    public function test_cli_sync_reports_that_download_is_already_running(): void
    {
        [$account] = $this->runningSince('2026-09-15 18:00', heartbeatMinutesAgo: 1);

        $this->artisan('b2b:sync', ['account' => $account->id])
            ->expectsOutputToContain('Pobieranie tego konta już trwa.')
            ->assertFailed();

        $this->assertSame('running', $account->fresh()->last_sync_status);
        $this->assertSame(1, B2bSyncRun::query()->count());
    }

    public function test_run_without_progress_for_30_minutes_is_swept_and_account_runs_again(): void
    {
        $this->travelTo(CarbonImmutable::parse('2026-09-16 02:00', B2bAccount::SYNC_TIMEZONE));
        [$account, $run] = $this->runningSince('2026-09-15 18:00', heartbeatMinutesAgo: 31);

        $this->artisan('b2b:sync-due')->assertSuccessful();

        $this->assertSame('failed', $run->fresh()->status);
        $runs = $account->syncRuns()->orderBy('id')->get();
        $this->assertCount(2, $runs);
        $this->assertSame('schedule', $runs[1]->trigger);
        // nowy przebieg naprawdę ruszył (logowanie w teście się nie udaje)
        $this->assertSame('failed', $runs[1]->status);
        $this->assertStringContainsString('Nieprawidłowy login lub hasło', (string) $account->fresh()->last_sync_message);
    }

    public function test_dry_run_does_not_claim_account(): void
    {
        $account = $this->makeAccount(['sync_frequency' => 'daily']);

        try {
            app(B2bAccountSyncRunner::class)->run($account, dryRun: true, delayMs: 0);
        } catch (RuntimeException) {
            // logowanie w teście się nie udaje
        }

        $this->assertNull($account->fresh()->last_sync_status);
        $this->assertSame(0, B2bSyncRun::query()->count());
    }

    /**
     * @return array{0: B2bAccount, 1: B2bSyncRun}
     */
    private function runningSince(string $startedAt, int $heartbeatMinutesAgo): array
    {
        $started = CarbonImmutable::parse($startedAt, B2bAccount::SYNC_TIMEZONE);
        $account = $this->makeAccount([
            'sync_frequency' => 'daily',
            'last_sync_status' => 'running',
            'last_sync_started_at' => $started,
        ]);
        $run = $account->syncRuns()->create([
            'status' => 'running',
            'trigger' => 'manual',
            'progress_unit' => 'variants',
            'started_at' => $started,
            'processed' => 30000,
        ]);
        // now() w strefie aplikacji (UTC) — surowy update zapisuje czas bez przeliczenia strefy
        B2bSyncRun::query()->whereKey($run->id)->update(['updated_at' => now()->subMinutes($heartbeatMinutesAgo)]);

        return [$account->fresh(), $run->fresh()];
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    private function makeAccount(array $attributes): B2bAccount
    {
        $account = B2bAccount::query()->create([
            'username' => 'konto',
            'password' => 'sekret',
            'sites' => ['b2b.anro.net.pl'],
            'connector' => 'anro',
            'created_by' => $this->user->id,
            'updated_by' => $this->user->id,
        ]);
        $account->forceFill($attributes)->save();

        return $account->fresh();
    }
}
