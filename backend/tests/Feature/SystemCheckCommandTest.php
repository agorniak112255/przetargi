<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\B2bAccount;
use App\Models\B2bSyncRun;
use App\Models\ScheduledTaskRun;
use App\Models\SystemAlert;
use App\Models\User;
use App\Services\MailSettingsService;
use App\Services\Notifications\NotificationDispatcher;
use App\Services\Notifications\NotificationPreferences;
use App\Services\System\SystemAlertService;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Contracts\Console\Kernel as ConsoleKernel;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Symfony\Component\Mailer\Exception\TransportException;
use Tests\Support\FakeSystemAlertDispatcher;
use Tests\TestCase;

/**
 * system:check — konta dostawców i sygnał harmonogramu: alert i e-mail raz na incydent, wyciszenie, zamknięcie.
 */
class SystemCheckCommandTest extends TestCase
{
    use RefreshDatabase;

    private FakeSystemAlertDispatcher $dispatcher;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolesAndPermissionsSeeder::class);
        $this->dispatcher = new FakeSystemAlertDispatcher;
        $this->app->instance(NotificationDispatcher::class, $this->dispatcher);
        $this->admin = User::factory()->withRole('admin')->create();
        User::factory()->withRole('handlowiec')->create();
        $this->travelTo(Carbon::parse('2026-10-03 07:00:00', 'UTC'));
        $this->heartbeat(now());
    }

    public function test_failed_b2b_account_raises_one_alert_and_one_mail_to_system_admins(): void
    {
        $account = $this->account('portwest', 'daily', B2bSyncRun::STATUS_FAILED, now()->subHours(3), 'Logowanie nieudane');

        $this->artisan('system:check')->assertSuccessful();
        $this->artisan('system:check')->assertSuccessful();

        $alert = SystemAlert::query()->sole();
        $this->assertSame('b2b', $alert->kind);
        $this->assertSame('b2b:'.$account->id, $alert->subject_key);
        $this->assertSame('Konto Portwest · pobieranie cen', $alert->title);
        $this->assertSame('Logowanie nieudane', $alert->last_message);
        $this->assertSame(1, $alert->failures, 'Ten sam nieudany przebieg sprawdzony dwa razy to jeden błąd.');
        $this->assertNotNull($alert->emailed_at);
        $this->assertCount(1, $this->dispatcher->calls);
        $this->assertSame([$this->admin->id], $this->dispatcher->calls[0]['users'], 'Tylko osoby z admin.system.view.');
        $this->assertSame('system_alert', $this->dispatcher->calls[0]['message']->event);

        // kolejny nieudany przebieg tego samego konta: licznik rośnie, bez drugiego e-maila
        $account->forceFill(['last_sync_finished_at' => now()->addHour(), 'last_sync_message' => 'Hasło wysłane na e-mail'])->save();
        $this->artisan('system:check')->assertSuccessful();
        $alert->refresh();
        $this->assertSame(2, $alert->failures);
        $this->assertSame('Hasło wysłane na e-mail', $alert->last_message);
        $this->assertCount(1, $this->dispatcher->calls);
    }

    public function test_account_back_to_normal_resolves_the_incident_and_running_keeps_it_open(): void
    {
        $account = $this->account('anro', 'daily', B2bSyncRun::STATUS_FAILED, now()->subHour(), 'Błąd');
        $this->artisan('system:check')->assertSuccessful();

        $account->forceFill(['last_sync_status' => B2bSyncRun::STATUS_RUNNING])->save();
        $this->artisan('system:check')->assertSuccessful();
        $this->assertSame(1, SystemAlert::query()->open()->count(), 'Przebieg w toku — jeszcze nie wiadomo, czy działa.');

        $account->forceFill(['last_sync_status' => B2bSyncRun::STATUS_OK, 'last_sync_finished_at' => now()])->save();
        $this->artisan('system:check')->assertSuccessful();
        $this->assertSame(0, SystemAlert::query()->open()->count());
        $this->assertNotNull(SystemAlert::query()->sole()->resolved_at);
    }

    public function test_disabled_or_deleted_account_closes_its_incident_and_is_not_checked(): void
    {
        $off = $this->account('anro', 'off', B2bSyncRun::STATUS_FAILED, now()->subHour(), 'Stary błąd');
        $noConnector = $this->account(null, 'daily', B2bSyncRun::STATUS_FAILED, now()->subHour(), 'Bez łącznika');
        $this->artisan('system:check')->assertSuccessful();
        $this->assertSame(0, SystemAlert::query()->count(), 'Wyłączone konto i konto bez łącznika nie straszą.');

        $gone = $this->account('anro', 'daily', B2bSyncRun::STATUS_FAILED, now()->subHour(), 'Błąd');
        $this->artisan('system:check')->assertSuccessful();
        $this->assertSame(1, SystemAlert::query()->open()->count());
        $gone->delete();
        $this->artisan('system:check')->assertSuccessful();
        $this->assertSame(0, SystemAlert::query()->open()->count());
        $this->assertNotNull($off);
        $this->assertNotNull($noConnector);
    }

    public function test_muted_incident_sends_no_mail_and_unmute_allows_it(): void
    {
        $account = $this->account('anro', 'daily', B2bSyncRun::STATUS_FAILED, now()->subHour(), 'Błąd');
        $this->dispatcher->mailResult = false; // poczta nie działa — nie udajemy „wysłany”
        $this->artisan('system:check')->assertSuccessful();
        $alert = SystemAlert::query()->sole();
        $this->assertNull($alert->emailed_at);

        $alert->forceFill(['muted_at' => now(), 'muted_by' => $this->admin->id])->save();
        $this->dispatcher->mailResult = true;
        $account->forceFill(['last_sync_finished_at' => now()->addMinutes(30)])->save();
        $this->artisan('system:check')->assertSuccessful();
        $this->assertCount(1, $this->dispatcher->calls, 'Wyciszony incydent — bez wysyłki.');
        $this->assertSame(2, $alert->fresh()->failures);

        $alert->forceFill(['muted_at' => null, 'muted_by' => null])->save();
        $account->forceFill(['last_sync_finished_at' => now()->addMinutes(40)])->save();
        $this->artisan('system:check')->assertSuccessful();
        $this->assertCount(2, $this->dispatcher->calls);
        $this->assertNotNull($alert->fresh()->emailed_at);
    }

    public function test_stale_scheduler_heartbeat_raises_alert_and_fresh_one_resolves_it(): void
    {
        config(['system_health.scheduler_stale_minutes' => 5]);
        $this->heartbeat(now()->subMinutes(6));
        $this->artisan('system:check')->assertSuccessful();

        $alert = SystemAlert::query()->open()->sole();
        $this->assertSame('scheduler', $alert->kind);
        $this->assertSame('Harmonogram zadań nie działa', $alert->title);
        $this->assertStringContainsString('3.10.2026, 08:54', (string) $alert->last_message, 'Ostatni sygnał w czasie polskim.');

        $this->heartbeat(now()->subMinutes(4));
        $this->artisan('system:check')->assertSuccessful();
        $this->assertSame(0, SystemAlert::query()->open()->count());
    }

    public function test_missing_heartbeat_is_an_alert(): void
    {
        Cache::forget(B2bSyncRun::SCHEDULER_HEARTBEAT_KEY);
        $this->artisan('system:check')->assertSuccessful();
        $this->assertStringContainsString('Brak sygnału', (string) SystemAlert::query()->sole()->last_message);
    }

    public function test_nightly_task_that_did_not_start_raises_one_alert_after_grace_and_a_run_resolves_it(): void
    {
        $this->nightlySchedule();

        // pierwsze sprawdzenie po wdrożeniu (7:00 UTC): dzisiejszy termin 2:00 sprzed obserwacji — nie awaria
        $this->checkAt('2026-10-03 07:00:00');
        $this->assertSame(0, SystemAlert::query()->count());

        // następna noc: przed upływem zapasu (2 h po 2:00) jeszcze nic
        $this->checkAt('2026-10-04 03:50:00');
        $this->assertSame(0, SystemAlert::query()->count());

        $this->checkAt('2026-10-04 04:10:00');
        $alert = SystemAlert::query()->open()->sole();
        $this->assertSame('stale', $alert->kind);
        $this->assertSame('stale-tasks', $alert->subject_key);
        $this->assertSame('Zadanie nocne nie ruszyło o czasie: Zadanie nocne próbne', $alert->title);
        $this->assertStringContainsString('planowo 4.10.2026, 04:00', (string) $alert->last_message, 'Termin w czasie polskim.');
        $this->assertStringContainsString('ostatni przebieg: brak zapisanego', (string) $alert->last_message);
        $this->assertStringNotContainsString('wyłączone', (string) $alert->title);
        $this->assertStringNotContainsString('Zadanie wyłączone próbne', (string) $alert->last_message, 'Zadanie wyłączone warunkiem when() nie jest sprawdzane.');
        $this->assertCount(1, $this->dispatcher->calls);

        // to samo zgłoszenie co 10 minut: bez licznika i bez drugiego e-maila
        $this->checkAt('2026-10-04 04:20:00');
        $this->assertSame(1, $alert->fresh()->failures);
        $this->assertCount(1, $this->dispatcher->calls);

        // zadanie ruszyło (choćby z błędem — ten zgłasza zapis przebiegu) → incydent zamknięty
        ScheduledTaskRun::query()->create(['task' => 'demo:nightly', 'started_at' => now(), 'status' => ScheduledTaskRun::STATUS_FAILED]);
        $this->checkAt('2026-10-04 04:30:00');
        $this->assertNotNull($alert->fresh()->resolved_at);
    }

    public function test_stale_tasks_are_not_checked_while_the_scheduler_is_down(): void
    {
        $this->nightlySchedule();
        $this->checkAt('2026-10-03 07:00:00');

        $this->travelTo(Carbon::parse('2026-10-04 05:00:00', 'UTC'));
        $this->heartbeat(now()->subHours(4));
        $this->artisan('system:check')->assertSuccessful();

        $this->assertSame(['scheduler'], SystemAlert::query()->open()->pluck('kind')->all(), 'Martwy harmonogram ma własny alert — bez lawiny alertów o zadaniach.');
    }

    public function test_old_b2b_failure_opens_incident_without_mail_and_a_fresh_failure_mails(): void
    {
        $account = $this->account('portwest', 'daily', B2bSyncRun::STATUS_FAILED, now()->subHours(49), 'Stary błąd sprzed wdrożenia');

        $this->artisan('system:check')->assertSuccessful();
        $alert = SystemAlert::query()->open()->sole();
        $this->assertNull($alert->emailed_at);
        $this->assertSame([], $this->dispatcher->calls, 'Zastany stary błąd — widać go na ekranie, ale bez e-maila.');

        $this->artisan('system:check')->assertSuccessful();
        $this->assertSame([], $this->dispatcher->calls);

        $account->forceFill(['last_sync_finished_at' => now()->subMinutes(5), 'last_sync_message' => 'Nowy błąd'])->save();
        $this->artisan('system:check')->assertSuccessful();
        $this->assertCount(1, $this->dispatcher->calls);
        $this->assertNotNull($alert->fresh()->emailed_at);
    }

    public function test_failure_series_older_than_the_mail_limit_sends_no_mail_also_the_next_day(): void
    {
        config(['system_health.tasks' => []]);
        $account = $this->account('portwest', 'daily', B2bSyncRun::STATUS_OK, now()->subDays(12), 'OK');
        $this->syncRun($account, B2bSyncRun::STATUS_OK, now()->subDays(12));
        // konto psuje się codziennie od 10 dni; przerwany ręcznie przebieg nie przerywa serii
        for ($day = 10; $day >= 1; $day--) {
            $this->syncRun($account, $day === 5 ? B2bSyncRun::STATUS_CANCELLED : B2bSyncRun::STATUS_FAILED, now()->subDays($day));
        }
        $this->syncRun($account, B2bSyncRun::STATUS_FAILED, now()->subHour());

        $this->check();
        $alert = SystemAlert::query()->open()->sole();
        $this->assertNull($alert->emailed_at);
        $this->assertSame([], $this->dispatcher->calls, 'Seria błędów sprzed 10 dni — incydent na ekranie, bez e-maila.');

        // następny dzień, kolejny nieudany przebieg — wciąż ta sama stara seria
        $this->travel(1)->days();
        $this->syncRun($account, B2bSyncRun::STATUS_FAILED, now()->subMinutes(30));
        $this->check();
        $this->assertSame([], $this->dispatcher->calls);
        $this->assertSame(2, $alert->fresh()->failures);
    }

    public function test_new_failure_series_after_a_successful_run_mails(): void
    {
        config(['system_health.tasks' => []]);
        $account = $this->account('portwest', 'daily', B2bSyncRun::STATUS_OK, now()->subDays(12), 'OK');
        $this->syncRun($account, B2bSyncRun::STATUS_FAILED, now()->subDays(10));
        $this->syncRun($account, B2bSyncRun::STATUS_OK, now()->subDays(3));
        $this->syncRun($account, B2bSyncRun::STATUS_FAILED, now()->subHour());

        $this->check();

        $this->assertCount(1, $this->dispatcher->calls);
        $this->assertNotNull(SystemAlert::query()->open()->sole()->emailed_at);
    }

    public function test_daily_account_failing_every_other_day_sends_one_mail(): void
    {
        config(['system_health.tasks' => []]);
        $account = $this->account('portwest', 'daily', B2bSyncRun::STATUS_OK, now()->subDays(2), 'OK');
        $this->syncRun($account, B2bSyncRun::STATUS_OK, now()->subDays(2));
        $this->syncRun($account, B2bSyncRun::STATUS_FAILED, now()->subHour());
        $this->check();
        $alert = SystemAlert::query()->open()->sole();
        $this->assertCount(1, $this->dispatcher->calls);

        // błąd – działa – błąd w ciągu doby: ten sam incydent otwarty na nowo, bez drugiego e-maila
        $this->travel(2)->hours();
        $this->syncRun($account, B2bSyncRun::STATUS_OK, now()->subMinutes(30));
        $this->check();
        $this->assertNotNull($alert->fresh()->resolved_at);
        $this->travel(22)->hours();
        $this->syncRun($account, B2bSyncRun::STATUS_FAILED, now()->subMinutes(30));
        $this->check();
        $this->assertSame(1, SystemAlert::query()->count());
        $this->assertNull($alert->fresh()->resolved_at);
        $this->assertCount(1, $this->dispatcher->calls);

        // spokój dłużej niż 26 godzin od zamknięcia — nowy błąd to nowy incydent i nowy e-mail
        $this->syncRun($account, B2bSyncRun::STATUS_OK, now()->subMinutes(10));
        $this->check();
        $this->travel(27)->hours();
        $this->syncRun($account, B2bSyncRun::STATUS_FAILED, now()->subMinutes(10));
        $this->check();
        $this->assertSame(2, SystemAlert::query()->count());
        $this->assertCount(2, $this->dispatcher->calls);
    }

    public function test_weekly_account_reopens_its_incident_within_eight_days(): void
    {
        config(['system_health.tasks' => []]);
        $weekly = $this->account('portwest', 'weekly', B2bSyncRun::STATUS_OK, now()->subDays(8), 'OK');
        $daily = $this->account('anro', 'daily', B2bSyncRun::STATUS_OK, now()->subDays(8), 'OK');
        foreach ([$weekly, $daily] as $account) {
            $this->syncRun($account, B2bSyncRun::STATUS_FAILED, now()->subHour());
        }
        $this->check();
        $this->assertCount(2, $this->dispatcher->calls);

        $this->travel(1)->hours();
        foreach ([$weekly, $daily] as $account) {
            $this->syncRun($account, B2bSyncRun::STATUS_OK, now()->subMinutes(10));
        }
        $this->check();
        $this->assertSame(0, SystemAlert::query()->open()->count());

        // tydzień później oba konta znowu z błędem: tygodniowe — ten sam incydent (okno 8 dni), dzienne — nowy e-mail
        $this->travel(7)->days();
        foreach ([$weekly, $daily] as $account) {
            $this->syncRun($account, B2bSyncRun::STATUS_FAILED, now()->subMinutes(10));
        }
        $this->check();
        $this->assertSame(1, SystemAlert::query()->where('subject_key', 'b2b:'.$weekly->id)->count());
        $this->assertSame(2, SystemAlert::query()->where('subject_key', 'b2b:'.$daily->id)->count());
        $this->assertSame(2, SystemAlert::query()->open()->count());
        $this->assertCount(3, $this->dispatcher->calls);
    }

    public function test_alert_goes_only_to_people_who_can_open_the_system_status_screen(): void
    {
        // uprawnienie do ekranu bez dostępu do Administracji: link dałby 403
        $withoutAdmin = User::factory()->withRole('handlowiec')->create();
        $withoutAdmin->givePermissionTo('admin.system.view');
        $this->assertFalse($withoutAdmin->can('admin.access'));

        $this->account('anro', 'daily', B2bSyncRun::STATUS_FAILED, now()->subHour(), 'Błąd');
        $this->artisan('system:check')->assertSuccessful();

        $this->assertSame([$this->admin->id], $this->dispatcher->calls[0]['users']);
        $this->assertFalse(app(NotificationPreferences::class)->available($withoutAdmin->fresh(), 'system_alert'));
        $this->assertTrue(app(NotificationPreferences::class)->available($this->admin, 'system_alert'));
    }

    public function test_alert_mail_lost_to_a_mail_error_is_sent_again_by_the_next_checks(): void
    {
        $this->app->instance(NotificationDispatcher::class, new NotificationDispatcher(app(NotificationPreferences::class), app(MailSettingsService::class)));
        Mail::fake();
        $fake = Mail::getFacadeRoot();
        Mail::shouldReceive('to')->once()->andThrow(new TransportException('Connection refused'));

        // błąd zadania nocnego zgłoszony raz, w chwili błędu — w system:check nie powtarza się
        $alert = app(SystemAlertService::class)->failed(SystemAlertService::KIND_TASK, 'task:erp:clients', 'Zadanie: Klienci z ERP XL', 'Brak połączenia');
        $this->assertNull($alert->emailed_at);
        $this->assertSame(1, $this->admin->notifications()->count());

        Mail::swap($fake);
        $this->checkAt('2026-10-03 07:10:00');
        Mail::assertNothingSent(); // przerwa przed ponowieniem jeszcze nie minęła

        $this->checkAt('2026-10-03 07:20:00');
        Mail::assertSentCount(1);
        $this->assertNotNull($alert->fresh()->emailed_at);
        $this->assertSame(1, $this->admin->notifications()->count(), 'Dzwonek raz.');

        $this->checkAt('2026-10-03 08:00:00');
        Mail::assertSentCount(1);
    }

    public function test_mail_of_a_resolved_incident_is_not_retried_until_the_incident_reopens(): void
    {
        $this->app->instance(NotificationDispatcher::class, new NotificationDispatcher(app(NotificationPreferences::class), app(MailSettingsService::class)));
        Mail::fake();
        $fake = Mail::getFacadeRoot();
        Mail::shouldReceive('to')->once()->andThrow(new TransportException('Connection refused'));
        $service = app(SystemAlertService::class);
        $alert = $service->failed(SystemAlertService::KIND_TASK, 'task:demo:often', 'Zadanie: Zadanie częste próbne', 'Limit skrzynki');
        Mail::swap($fake);
        $this->assertSame(['system_alert:'.$alert->id], app(NotificationDispatcher::class)->pendingMailSubjects('system_alert'));

        // zadanie znowu działa — e-mail zamkniętego incydentu nie czeka już na ponowienie
        $this->assertSame(1, $service->resolved('task:demo:often'));
        $this->assertSame(['failed'], DB::table('notification_dispatches')->where('subject_key', 'system_alert:'.$alert->id)->pluck('mail_status')->all());
        $this->assertSame([], app(NotificationDispatcher::class)->pendingMailSubjects('system_alert'));
        $this->travel(30)->minutes();
        $this->assertSame(0, $service->retryPendingMail());
        Mail::assertNothingSent();

        // incydent otwarty na nowo (błąd tuż po zamknięciu) — niewysłany e-mail znowu czeka i wychodzi
        $service->failed(SystemAlertService::KIND_TASK, 'task:demo:often', 'Zadanie: Zadanie częste próbne', 'Znowu limit', reopenWithinMinutes: 360);
        $this->assertSame(1, SystemAlert::query()->count());
        Mail::assertSentCount(1);
        $this->assertNotNull($alert->fresh()->emailed_at);
        $this->assertSame(1, $this->admin->notifications()->count(), 'Dzwonek raz.');
    }

    public function test_muted_incident_mail_waits_and_is_retried_after_unmute(): void
    {
        $this->app->instance(NotificationDispatcher::class, new NotificationDispatcher(app(NotificationPreferences::class), app(MailSettingsService::class)));
        Mail::fake();
        $fake = Mail::getFacadeRoot();
        Mail::shouldReceive('to')->once()->andThrow(new TransportException('Connection refused'));
        $service = app(SystemAlertService::class);
        $alert = $service->failed(SystemAlertService::KIND_TASK, 'task:erp:clients', 'Zadanie: Klienci z ERP XL', 'Brak połączenia');
        Mail::swap($fake);

        $service->mute($alert, $this->admin);
        $this->travel(30)->minutes();
        $this->assertSame(0, $service->retryPendingMail());
        Mail::assertNothingSent();
        $this->assertSame(['system_alert:'.$alert->id], app(NotificationDispatcher::class)->pendingMailSubjects('system_alert'), 'Wyciszenie nie poddaje e-maila.');

        $service->unmute($alert->fresh());
        $this->assertSame(1, $service->retryPendingMail());
        Mail::assertSentCount(1);
        $this->assertNotNull($alert->fresh()->emailed_at);
    }

    /** Harmonogram z zadaniem nocnym 2:00 UTC i drugim, wyłączonym warunkiem when(). */
    private function nightlySchedule(): void
    {
        config(['system_health.tasks' => [
            'demo:nightly' => ['label' => 'Zadanie nocne próbne', 'schedule' => 'codziennie w nocy', 'nightly' => true, 'only_failures' => false],
            'demo:disabled' => ['label' => 'Zadanie wyłączone próbne', 'schedule' => 'codziennie w nocy', 'nightly' => true, 'only_failures' => false],
        ]]);
        app(ConsoleKernel::class)->all();
        $schedule = app(Schedule::class);
        $schedule->command('demo:nightly')->dailyAt('02:00');
        $schedule->command('demo:disabled')->dailyAt('02:00')->when(static fn (): bool => false);
    }

    private function checkAt(string $utc): void
    {
        $this->travelTo(Carbon::parse($utc, 'UTC'));
        $this->heartbeat(now());
        $this->artisan('system:check')->assertSuccessful();
    }

    private function account(?string $connector, string $frequency, string $status, \DateTimeInterface $finishedAt, string $message): B2bAccount
    {
        $account = B2bAccount::query()->create([
            'username' => 'konto-'.uniqid(), 'password' => 'x', 'sites' => ['b2b.anro.net.pl'], 'connector' => $connector, 'sync_frequency' => $frequency,
        ]);
        $account->forceFill(['last_sync_status' => $status, 'last_sync_finished_at' => $finishedAt, 'last_sync_message' => $message])->save();

        return $account;
    }

    /** Przebieg pobierania konta (start = $startedAt, koniec 10 minut później) i stan konta jak po nim. */
    private function syncRun(B2bAccount $account, string $status, \DateTimeInterface $startedAt): void
    {
        $finishedAt = Carbon::instance($startedAt)->addMinutes(10);
        B2bSyncRun::query()->create([
            'b2b_account_id' => $account->id,
            'status' => $status,
            'trigger' => B2bSyncRun::TRIGGER_SCHEDULE,
            'started_at' => $startedAt,
            'finished_at' => $finishedAt,
            'message' => $status === B2bSyncRun::STATUS_FAILED ? 'Logowanie nieudane' : null,
        ]);
        if ($status !== B2bSyncRun::STATUS_CANCELLED) {
            $account->forceFill([
                'last_sync_status' => $status,
                'last_sync_finished_at' => $finishedAt,
                'last_sync_message' => $status === B2bSyncRun::STATUS_FAILED ? 'Logowanie nieudane' : null,
            ])->save();
        }
    }

    private function check(): void
    {
        $this->heartbeat(now());
        $this->artisan('system:check')->assertSuccessful();
    }

    private function heartbeat(\DateTimeInterface $at): void
    {
        Cache::forever(B2bSyncRun::SCHEDULER_HEARTBEAT_KEY, Carbon::instance($at)->toIso8601String());
    }
}
