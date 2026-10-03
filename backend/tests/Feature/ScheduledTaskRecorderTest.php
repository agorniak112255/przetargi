<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\ScheduledTaskRun;
use App\Models\SystemAlert;
use App\Models\User;
use App\Services\Notifications\NotificationDispatcher;
use App\Services\System\ScheduledTaskRecorder;
use App\Services\System\SystemAlertService;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Console\Scheduling\Event;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Schema;
use RuntimeException;
use Tests\Support\FakeSystemAlertDispatcher;
use Tests\TestCase;

/**
 * Zapis przebiegów zadań harmonogramu: wywołania zwrotne zdarzenia (before / onSuccess / onFailure) bez
 * uruchamiania procesów — przebieg symulujemy przez callBeforeCallbacks() i finish().
 */
class ScheduledTaskRecorderTest extends TestCase
{
    use RefreshDatabase;

    private FakeSystemAlertDispatcher $dispatcher;

    /** @var list<string> */
    private array $outputFiles = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolesAndPermissionsSeeder::class);
        config(['system_health.tasks' => [
            'demo:nightly --full' => ['label' => 'Zadanie nocne próbne', 'schedule' => 'codziennie w nocy', 'nightly' => true, 'only_failures' => false],
            'demo:often' => ['label' => 'Zadanie częste próbne', 'schedule' => 'co minutę', 'nightly' => false, 'only_failures' => true],
            'demo-callback' => ['label' => 'Funkcja próbna', 'schedule' => 'co godzinę', 'nightly' => false, 'only_failures' => false],
        ]]);
        $this->dispatcher = new FakeSystemAlertDispatcher;
        $this->app->instance(NotificationDispatcher::class, $this->dispatcher);
        $admin = User::factory()->withRole('admin')->create();
        $this->assertTrue($admin->can('admin.system.view'));
        $this->travelTo(Carbon::parse('2026-10-03 02:00:00', 'UTC'));
    }

    protected function tearDown(): void
    {
        foreach ($this->outputFiles as $file) {
            if (is_file($file)) {
                @unlink($file);
            }
        }
        parent::tearDown();
    }

    public function test_only_configured_tasks_get_callbacks_and_stored_output(): void
    {
        $schedule = new Schedule;
        $nightly = $schedule->command('demo:nightly --full')->dailyAt('02:00');
        $other = $schedule->command('demo:unknown')->dailyAt('03:00');
        app(ScheduledTaskRecorder::class)->attach($schedule);

        $this->assertNotSame($nightly->getDefaultOutput(), $nightly->output, 'storeOutput: komunikat trafia do pliku.');
        $this->assertSame($other->getDefaultOutput(), $other->output, 'Zadanie spoza config bez zmian.');

        $this->assertSame(['demo:nightly --full'], array_keys(app(ScheduledTaskRecorder::class)->match($schedule->events())));
    }

    public function test_successful_nightly_run_is_recorded_with_duration_and_output_tail(): void
    {
        $event = $this->nightlyEvent();
        $event->callBeforeCallbacks($this->app);

        $run = ScheduledTaskRun::query()->sole();
        $this->assertSame('demo:nightly --full', $run->task);
        $this->assertSame(ScheduledTaskRun::STATUS_RUNNING, $run->status);

        $this->travel(95)->seconds();
        $this->writeOutput($event, str_repeat('x', 5000)."\nZapisano 12 towarów.\n");
        $event->finish($this->app, 0);

        $run->refresh();
        $this->assertSame(ScheduledTaskRun::STATUS_OK, $run->status);
        $this->assertSame(0, $run->exit_code);
        $this->assertSame(95000, $run->duration_ms);
        $this->assertNotNull($run->finished_at);
        $this->assertStringEndsWith('Zapisano 12 towarów.', (string) $run->output_tail);
        $this->assertSame(2000, mb_strlen((string) $run->output_tail), 'Tylko końcówka komunikatu.');
        $this->assertSame([], $this->dispatcher->calls);
    }

    public function test_failure_opens_one_alert_and_mails_once_until_the_task_succeeds(): void
    {
        $event = $this->nightlyEvent();

        $this->runOnce($event, 1, "Łączenie z ERP XL…\nSQLSTATE[08001] Brak połączenia z serwerem\n");
        $run = ScheduledTaskRun::query()->sole();
        $this->assertSame(ScheduledTaskRun::STATUS_FAILED, $run->status);
        $this->assertSame(1, $run->exit_code);

        $alert = SystemAlert::query()->sole();
        $this->assertSame('task', $alert->kind);
        $this->assertSame('task:demo:nightly --full', $alert->subject_key);
        $this->assertSame('Zadanie: Zadanie nocne próbne', $alert->title);
        $this->assertStringContainsString('Brak połączenia z serwerem', (string) $alert->last_message);
        $this->assertNotNull($alert->emailed_at);
        $this->assertCount(1, $this->dispatcher->calls);
        $this->assertSame('system_alert', $this->dispatcher->calls[0]['message']->event);
        $this->assertSame('/admin/stan-systemu', $this->dispatcher->calls[0]['message']->url);

        // drugi błąd tego samego incydentu: licznik rośnie, e-mail już nie
        $this->travel(1)->days();
        $this->runOnce($event, 1, 'Znowu błąd');
        $alert->refresh();
        $this->assertSame(2, $alert->failures);
        $this->assertSame('Znowu błąd', $alert->last_message);
        $this->assertCount(1, $this->dispatcher->calls);

        // udany przebieg zamyka incydent; następny błąd = nowy incydent i nowy e-mail
        $this->runOnce($event, 0, 'OK');
        $this->assertNotNull($alert->fresh()->resolved_at);
        $this->runOnce($event, 1, 'Nowy błąd');
        $this->assertSame(2, SystemAlert::query()->count());
        $this->assertCount(2, $this->dispatcher->calls);
        $this->assertSame(4, ScheduledTaskRun::query()->count());
    }

    public function test_frequent_task_records_only_failures(): void
    {
        $schedule = new Schedule;
        $event = $schedule->command('demo:often')->everyMinute();
        app(ScheduledTaskRecorder::class)->attach($schedule);

        $this->runOnce($event, 0, 'ok');
        $this->runOnce($event, 0, 'ok');
        $this->assertSame(0, ScheduledTaskRun::query()->count(), 'Udane przebiegi zadania częstego nie są zapisywane.');

        $event->callBeforeCallbacks($this->app);
        $this->travel(3)->seconds();
        $this->writeOutput($event, 'Limit skrzynki');
        $event->finish($this->app, 2);

        $run = ScheduledTaskRun::query()->sole();
        $this->assertSame(ScheduledTaskRun::STATUS_FAILED, $run->status);
        $this->assertSame(2, $run->exit_code);
        $this->assertSame(3000, $run->duration_ms, 'Start z pamięci podręcznej.');
        $this->assertSame('Limit skrzynki', $run->output_tail);
        $this->assertSame(1, SystemAlert::query()->open()->count());
    }

    public function test_frequent_task_failing_again_soon_after_recovery_reopens_the_incident_without_new_mail(): void
    {
        config(['system_health.reopen_within_minutes' => 360]);
        $schedule = new Schedule;
        $event = $schedule->command('demo:often')->everyMinute();
        app(ScheduledTaskRecorder::class)->attach($schedule);

        $this->runOnce($event, 1, 'Limit skrzynki');
        $alert = SystemAlert::query()->sole();
        $this->assertCount(1, $this->dispatcher->calls);

        // raz działa, raz nie — ten sam incydent otwarty na nowo, bez drugiego e-maila
        $this->travel(1)->minutes();
        $this->runOnce($event, 0, 'ok');
        $this->assertNotNull($alert->fresh()->resolved_at);
        $this->travel(1)->minutes();
        $this->runOnce($event, 1, 'Znowu limit');
        $this->assertSame(1, SystemAlert::query()->count());
        $alert->refresh();
        $this->assertNull($alert->resolved_at);
        $this->assertSame(2, $alert->failures);
        $this->assertSame('Znowu limit', $alert->last_message);
        $this->assertCount(1, $this->dispatcher->calls);

        // długo spokojnie (ponad 6 h od zamknięcia) — nowy błąd to nowy incydent i nowy e-mail
        $this->runOnce($event, 0, 'ok');
        $this->travel(361)->minutes();
        $this->runOnce($event, 1, 'Inny błąd');
        $this->assertSame(2, SystemAlert::query()->count());
        $this->assertCount(2, $this->dispatcher->calls);
    }

    public function test_background_run_is_finished_by_another_process_through_the_cache(): void
    {
        // proces schedule:run: before
        $event = $this->nightlyEvent();
        $event->runInBackground();
        $event->callBeforeCallbacks($this->app);

        // proces schedule:finish: harmonogram zbudowany od nowa, ten sam mutexName
        $schedule = new Schedule;
        $again = $schedule->command('demo:nightly --full')->dailyAt('02:00')->runInBackground();
        (new ScheduledTaskRecorder(app(SystemAlertService::class)))->attach($schedule);
        $this->assertSame($event->mutexName(), $again->mutexName());
        $this->travel(10)->minutes();
        $this->writeOutput($again, 'Gotowe');
        $again->finish($this->app, 0);

        $run = ScheduledTaskRun::query()->sole();
        $this->assertSame(ScheduledTaskRun::STATUS_OK, $run->status);
        $this->assertSame(600000, $run->duration_ms);
    }

    public function test_attaching_twice_does_not_duplicate_callbacks(): void
    {
        $schedule = new Schedule;
        $event = $schedule->command('demo:nightly --full')->dailyAt('02:00');
        app(ScheduledTaskRecorder::class)->attach($schedule);
        app(ScheduledTaskRecorder::class)->attach($schedule);

        $this->runOnce($event, 0, 'ok');
        $this->assertSame(1, ScheduledTaskRun::query()->count());
    }

    public function test_failing_closure_task_is_recorded_with_exception_message(): void
    {
        $schedule = new Schedule;
        $event = $schedule->call(static function (): void {
            throw new RuntimeException('Saldo niedostępne');
        })->hourly()->name('demo-callback');
        app(ScheduledTaskRecorder::class)->attach($schedule);

        try {
            $event->run($this->app);
            $this->fail('Wyjątek zadania-funkcji wychodzi dalej (schedule:run go raportuje).');
        } catch (RuntimeException $e) {
            $this->assertSame('Saldo niedostępne', $e->getMessage());
        }

        $run = ScheduledTaskRun::query()->sole();
        $this->assertSame(ScheduledTaskRun::STATUS_FAILED, $run->status);
        $this->assertStringContainsString('RuntimeException: Saldo niedostępne', (string) $run->output_tail);
        $this->assertStringContainsString('Saldo niedostępne', (string) SystemAlert::query()->sole()->last_message);
    }

    public function test_recording_problems_never_break_the_task(): void
    {
        $event = $this->nightlyEvent();
        Schema::drop('scheduled_task_runs');

        $event->callBeforeCallbacks($this->app);
        $event->finish($this->app, 0);

        $this->assertSame(0, $event->exitCode);
    }

    private function nightlyEvent(): Event
    {
        $schedule = new Schedule;
        $event = $schedule->command('demo:nightly --full')->dailyAt('02:00');
        app(ScheduledTaskRecorder::class)->attach($schedule);

        return $event;
    }

    private function runOnce(Event $event, int $exitCode, string $output): void
    {
        $event->callBeforeCallbacks($this->app);
        $this->writeOutput($event, $output);
        $event->finish($this->app, $exitCode);
    }

    private function writeOutput(Event $event, string $text): void
    {
        $this->outputFiles[] = $event->output;
        file_put_contents($event->output, $text);
    }
}
