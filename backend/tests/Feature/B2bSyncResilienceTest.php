<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\B2bAccount;
use App\Models\B2bSyncRun;
use App\Models\Product;
use App\Models\User;
use App\Services\B2b\B2bAccountSyncRunner;
use App\Services\B2b\B2bConnector;
use App\Services\B2b\B2bListProgressAware;
use App\Services\B2b\B2bRemoteImage;
use App\Services\B2b\B2bRemotePrice;
use App\Services\B2b\B2bRemoteProduct;
use App\Services\B2b\B2bSyncProgress;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use RuntimeException;
use Tests\TestCase;

/**
 * Odporność pełnego przebiegu na własną skalę: limit pamięci procesu (cennik UVEX ginął w połowie listy na
 * 128 MB z php.ini) i limit listy powodów pominięcia (cennik potrafi mieć ich tysiące).
 */
final class B2bSyncResilienceTest extends TestCase
{
    use RefreshDatabase;

    private B2bAccount $account;

    private ResilienceFakeConnector $connector;

    private string $memoryLimit;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolesAndPermissionsSeeder::class);
        Queue::fake();
        $user = User::factory()->withRole('admin')->create();
        $this->account = B2bAccount::query()->create([
            'username' => 'jan',
            'password' => 'sekret',
            'sites' => [ResilienceFakeConnector::host()],
            'connector' => ResilienceFakeConnector::key(),
            'created_by' => $user->id,
            'updated_by' => $user->id,
        ]);
        $this->connector = new ResilienceFakeConnector;
        $this->memoryLimit = (string) ini_get('memory_limit');
    }

    protected function tearDown(): void
    {
        ini_set('memory_limit', $this->memoryLimit);
        parent::tearDown();
    }

    public function test_memory_limit_is_read_from_ini_notation(): void
    {
        $this->assertSame(128 * 1024 * 1024, B2bAccountSyncRunner::memoryLimitBytes('128M'));
        $this->assertSame(1024 * 1024 * 1024, B2bAccountSyncRunner::memoryLimitBytes('1G'));
        $this->assertSame(524288, B2bAccountSyncRunner::memoryLimitBytes('512K'));
        $this->assertSame(1000, B2bAccountSyncRunner::memoryLimitBytes('1000'));
        $this->assertNull(B2bAccountSyncRunner::memoryLimitBytes('-1'));
        $this->assertNull(B2bAccountSyncRunner::memoryLimitBytes('sporo'));
    }

    public function test_run_raises_too_low_memory_limit_and_keeps_a_higher_one(): void
    {
        $this->connector->count = 1;

        ini_set('memory_limit', '128M');
        $this->sync();
        $this->assertSame(512 * 1024 * 1024, B2bAccountSyncRunner::memoryLimitBytes((string) ini_get('memory_limit')));

        ini_set('memory_limit', '1G');
        $this->sync();
        $this->assertSame(1024 * 1024 * 1024, B2bAccountSyncRunner::memoryLimitBytes((string) ini_get('memory_limit')));

        ini_set('memory_limit', '-1');
        $this->sync();
        $this->assertNull(B2bAccountSyncRunner::memoryLimitBytes((string) ini_get('memory_limit')));
    }

    public function test_skipped_reasons_stop_growing_and_the_rest_is_counted(): void
    {
        $this->connector->count = 205;

        $result = $this->sync();

        $this->assertSame(205, $result['skipped']);
        $this->assertSame(0, $result['created']);
        $this->assertSame(0, Product::query()->count());
        $this->assertCount(200, $result['errors']);
        $this->assertSame('U0001: brak ceny w B2B', $result['errors'][0]);
        $this->assertSame('U0200: brak ceny w B2B', $result['errors'][199]);

        $log = array_column(B2bSyncRun::query()->findOrFail($result['sync_run_id'])->log, 'text');
        $this->assertContains('Dalszych powodów pominięcia nie wypisujemy: 5', $log);
    }

    public function test_fatal_error_closes_the_run_with_the_real_reason(): void
    {
        $progress = B2bSyncProgress::start($this->account, B2bSyncRun::TRIGGER_SCHEDULE);
        $progress->advance('U0541', ['processed' => 541]);
        $this->account->forceFill(['last_sync_status' => 'running'])->save();

        app(B2bAccountSyncRunner::class)->failRunAfterFatal($this->account, $progress, [
            'type' => E_ERROR,
            'message' => 'Allowed memory size of 134217728 bytes exhausted (tried to allocate 454656 bytes)',
            'file' => '/var/www/backend/vendor/guzzlehttp/psr7/src/Utils.php',
            'line' => 679,
        ]);

        $run = B2bSyncRun::query()->findOrFail($progress->run()->id);
        $this->assertSame(B2bSyncRun::STATUS_FAILED, $run->status);
        $this->assertNotNull($run->finished_at);
        $this->assertStringContainsString('Allowed memory size', (string) $run->message);
        $this->assertStringContainsString('Utils.php:679', (string) $run->message);
        $this->assertStringContainsString('Ostatni produkt: U0541', (string) $run->message);
        $this->assertContains(
            'error',
            array_column($run->log, 'level'),
            'przyczyna musi być też w dzienniku przebiegu',
        );

        $account = $this->account->fresh();
        $this->assertSame('failed', $account?->last_sync_status);
        $this->assertStringContainsString('Allowed memory size', (string) $account?->last_sync_message);

        // przebieg jest już domknięty — b2b:sync-due nie ma go za co uznać za „bez postępu”
        $this->travel(B2bSyncRun::STALE_MINUTES + 1)->minutes();
        $this->artisan('b2b:sync-due')->assertSuccessful();
        $this->assertSame(B2bSyncRun::STATUS_FAILED, (string) B2bSyncRun::query()->findOrFail($run->id)->status);
        $this->assertStringContainsString('Allowed memory size', (string) B2bSyncRun::query()->findOrFail($run->id)->message);
    }

    public function test_stopping_during_the_supplier_list_ends_the_run_as_cancelled(): void
    {
        // „Zatrzymaj” wciśnięte, zanim ruszyła pętla produktów — pełna lista UVEX schodzi kilka minut
        $this->connector->count = 3;
        $this->connector->listMessages = ['Lista: strona 20/288', 'Lista: strona 40/288'];
        $this->connector->cancelAfterFirstMessage = true;

        $result = $this->sync();

        $this->assertTrue($result['cancelled']);
        $this->assertSame(0, $result['seen'], 'po zatrzymaniu nie zaczynamy przetwarzać produktów');

        $run = B2bSyncRun::query()->findOrFail($result['sync_run_id']);
        $this->assertSame(B2bSyncRun::STATUS_CANCELLED, $run->status);
        $this->assertStringContainsString('Zatrzymano ręcznie', (string) $run->message);
        $this->assertSame('cancelled', $this->account->fresh()?->last_sync_status);
    }

    public function test_process_killed_from_outside_closes_the_run_as_cancelled(): void
    {
        $progress = B2bSyncProgress::start($this->account, B2bSyncRun::TRIGGER_CLI);
        $progress->advance('U0100', ['processed' => 100]);
        $this->account->forceFill(['last_sync_status' => 'running'])->save();

        app(B2bAccountSyncRunner::class)->cancelRunAfterSignal($this->account, $progress, 2);

        $run = B2bSyncRun::query()->findOrFail($progress->run()->id);
        $this->assertSame(B2bSyncRun::STATUS_CANCELLED, $run->status);
        $this->assertNotNull($run->finished_at);
        $this->assertStringContainsString('Ctrl+C', (string) $run->message);
        $this->assertStringContainsString('U0100', (string) $run->message);
        $this->assertSame('cancelled', $this->account->fresh()?->last_sync_status);

        // przebieg jest domknięty — b2b:sync-due nie ma go za co uznać za „bez postępu”
        $this->travel(B2bSyncRun::STALE_MINUTES + 1)->minutes();
        $this->artisan('b2b:sync-due')->assertSuccessful();
        $this->assertSame(B2bSyncRun::STATUS_CANCELLED, (string) B2bSyncRun::query()->findOrFail($run->id)->status);
    }

    public function test_account_left_in_progress_without_a_run_is_released(): void
    {
        // przebieg domknięty inną drogą (ręcznie, sygnałem), status konta został jak był
        $this->account->forceFill([
            'last_sync_status' => 'running',
            'last_sync_started_at' => now()->subMinutes(B2bSyncRun::STALE_MINUTES + 5),
        ])->save();

        $this->artisan('b2b:sync-due')->assertSuccessful();

        $account = $this->account->fresh();
        $this->assertSame('failed', $account?->last_sync_status);
        $this->assertStringContainsString('żaden przebieg nie trwał', (string) $account?->last_sync_message);
    }

    public function test_account_with_a_running_sync_is_left_alone(): void
    {
        $progress = B2bSyncProgress::start($this->account, B2bSyncRun::TRIGGER_SCHEDULE);
        $this->account->forceFill([
            'last_sync_status' => 'running',
            'last_sync_started_at' => now()->subMinutes(B2bSyncRun::STALE_MINUTES + 5),
        ])->save();
        $progress->advance('U0001', ['processed' => 1]);

        $this->artisan('b2b:sync-due')->assertSuccessful();

        $this->assertSame('running', $this->account->fresh()?->last_sync_status);
    }

    /**
     * @return array<string, mixed>
     */
    private function sync(): array
    {
        return app(B2bAccountSyncRunner::class)->run(
            $this->account->fresh(),
            delayMs: 0,
            connector: $this->connector,
        );
    }
}

/** Łącznik testowy bez sieci: pozycje, których ceny nie da się odczytać (każda kończy się pominięciem). */
final class ResilienceFakeConnector implements B2bConnector, B2bListProgressAware
{
    public int $count = 0;

    /** @var list<string> komunikaty „pobieram listę” przed pierwszym produktem */
    public array $listMessages = [];

    /** Po pierwszym komunikacie listy zapisuje prośbę o zatrzymanie — jak kliknięcie „Zatrzymaj” w panelu. */
    public bool $cancelAfterFirstMessage = false;

    /** @var (callable(string): void)|null */
    private $listProgress = null;

    public function onListProgress(callable $callback): void
    {
        $this->listProgress = $callback;
    }

    public static function key(): string
    {
        return 'resilience';
    }

    public static function label(): string
    {
        return 'Test odporności';
    }

    public static function host(): string
    {
        return 'resilience.example.test';
    }

    public static function forAccount(B2bAccount $account, int $delayMs): self
    {
        return new self;
    }

    public function login(): void {}

    public function products(): iterable
    {
        foreach ($this->listMessages as $index => $message) {
            if ($this->listProgress !== null) {
                ($this->listProgress)($message);
            }
            if ($index === 0 && $this->cancelAfterFirstMessage) {
                B2bSyncRun::query()->latest('id')->firstOrFail()
                    ->forceFill(['cancel_requested_at' => now()])->save();
            }
        }

        for ($i = 1; $i <= $this->count; $i++) {
            $sku = sprintf('U%04d', $i);
            yield new B2bRemoteProduct(remoteId: $sku, sku: $sku, name: 'Pozycja '.$sku);
        }
    }

    public function totalProducts(): int
    {
        return $this->count;
    }

    public function manufacturer(B2bRemoteProduct $product): string
    {
        return 'UVEX';
    }

    public function price(B2bRemoteProduct $product): ?B2bRemotePrice
    {
        throw new RuntimeException('brak ceny w B2B');
    }

    public function description(B2bRemoteProduct $product): string
    {
        return '';
    }

    public function image(B2bRemoteProduct $product): ?B2bRemoteImage
    {
        return null;
    }
}
