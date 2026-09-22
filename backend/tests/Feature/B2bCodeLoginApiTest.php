<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\B2bAccount;
use App\Models\B2bSyncRun;
use App\Models\User;
use App\Services\B2b\B2bCodeLoginSite;
use App\Services\B2b\B2bConnector;
use App\Services\B2b\B2bConnectorRegistry;
use App\Services\B2b\B2bFatalException;
use App\Services\B2b\B2bRemoteImage;
use App\Services\B2b\B2bRemotePrice;
use App\Services\B2b\B2bRemoteProduct;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;
use RuntimeException;
use Tests\TestCase;

/**
 * „Zaloguj kodem” z panelu: rejestr łączników podmieniony na atrapę — test nie zależy od prawdziwego łącznika 3M.
 */
final class B2bCodeLoginApiTest extends TestCase
{
    use RefreshDatabase;

    private FakeCodeLoginConnector $connector;

    private B2bAccount $account;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolesAndPermissionsSeeder::class);

        $this->connector = new FakeCodeLoginConnector;
        $this->useConnector($this->connector);

        $this->account = B2bAccount::query()->create([
            'username' => 'handel@supon.test',
            'password' => 'sekret',
            'sites' => ['order.example.test'],
            'connector' => 'anro',
        ]);
    }

    public function test_start_saves_encrypted_state_in_cache_and_returns_message(): void
    {
        Sanctum::actingAs(User::factory()->withRole('admin')->create());

        $this->postJson("/api/b2b-accounts/{$this->account->id}/login-code")
            ->assertOk()
            ->assertExactJson(['message' => 'Kod wysłany na handel@supon.test.']);

        $cached = Cache::get("b2b-code-login:{$this->account->id}");
        $this->assertIsString($cached);
        $this->assertStringNotContainsString('tx-123', $cached);
        $this->assertSame(
            ['cookies' => [['Name' => 'x', 'Value' => 'y']], 'tx' => 'tx-123'],
            json_decode(Crypt::decryptString($cached), true),
        );
    }

    public function test_start_expires_state_after_fifteen_minutes(): void
    {
        Sanctum::actingAs(User::factory()->withRole('admin')->create());

        $this->postJson("/api/b2b-accounts/{$this->account->id}/login-code")->assertOk();

        $this->travel(14)->minutes();
        $this->assertNotNull(Cache::get("b2b-code-login:{$this->account->id}"));
        $this->travel(2)->minutes();
        $this->assertNull(Cache::get("b2b-code-login:{$this->account->id}"));
    }

    public function test_start_for_connector_without_code_login_is_rejected(): void
    {
        Sanctum::actingAs(User::factory()->withRole('admin')->create());
        $this->useConnector(new FakePlainConnector);

        $this->postJson("/api/b2b-accounts/{$this->account->id}/login-code")
            ->assertUnprocessable()
            ->assertJsonPath('message', 'Łącznik tego konta nie loguje się kodem z e-maila.');

        $this->assertNull(Cache::get("b2b-code-login:{$this->account->id}"));
    }

    public function test_start_reports_login_failure_from_connector(): void
    {
        Sanctum::actingAs(User::factory()->withRole('admin')->create());
        $this->connector->startFailure = new RuntimeException('3M: nieprawidłowe hasło.');

        $this->postJson("/api/b2b-accounts/{$this->account->id}/login-code")
            ->assertUnprocessable()
            ->assertJsonPath('message', '3M: nieprawidłowe hasło.');

        $this->connector->startFailure = new B2bFatalException('3M zmienił stronę logowania.');
        $this->postJson("/api/b2b-accounts/{$this->account->id}/login-code")
            ->assertUnprocessable()
            ->assertJsonPath('message', '3M zmienił stronę logowania.');

        $this->assertNull(Cache::get("b2b-code-login:{$this->account->id}"));
    }

    public function test_start_while_sync_is_running_returns_conflict(): void
    {
        Sanctum::actingAs(User::factory()->withRole('admin')->create());
        $this->account->syncRuns()->create(['status' => B2bSyncRun::STATUS_RUNNING, 'trigger' => 'manual', 'started_at' => now()]);

        $this->postJson("/api/b2b-accounts/{$this->account->id}/login-code")
            ->assertStatus(409)
            ->assertJsonPath('message', 'Pobieranie już trwa.');

        $this->assertSame(0, $this->connector->startCalls);
    }

    public function test_verify_without_state_is_rejected(): void
    {
        Sanctum::actingAs(User::factory()->withRole('admin')->create());

        $this->postJson("/api/b2b-accounts/{$this->account->id}/login-code/verify", ['code' => '123456'])
            ->assertUnprocessable()
            ->assertJsonPath('message', 'Kod wygasł albo nie wysłano go — kliknij „Wyślij kod” jeszcze raz.');

        $this->assertSame(0, $this->connector->finishCalls);
    }

    public function test_verify_with_unreadable_state_is_treated_as_missing(): void
    {
        Sanctum::actingAs(User::factory()->withRole('admin')->create());

        Cache::put("b2b-code-login:{$this->account->id}", 'nie-zaszyfrowane', now()->addMinutes(15));
        $this->postJson("/api/b2b-accounts/{$this->account->id}/login-code/verify", ['code' => '123456'])
            ->assertUnprocessable()
            ->assertJsonPath('message', 'Kod wygasł albo nie wysłano go — kliknij „Wyślij kod” jeszcze raz.');

        Cache::put("b2b-code-login:{$this->account->id}", Crypt::encryptString('{zły json'), now()->addMinutes(15));
        $this->postJson("/api/b2b-accounts/{$this->account->id}/login-code/verify", ['code' => '123456'])
            ->assertUnprocessable()
            ->assertJsonPath('message', 'Kod wygasł albo nie wysłano go — kliknij „Wyślij kod” jeszcze raz.');

        $this->assertSame(0, $this->connector->finishCalls);
    }

    public function test_verify_with_wrong_code_keeps_state(): void
    {
        Sanctum::actingAs(User::factory()->withRole('admin')->create());
        $this->postJson("/api/b2b-accounts/{$this->account->id}/login-code")->assertOk();

        $this->postJson("/api/b2b-accounts/{$this->account->id}/login-code/verify", ['code' => '000000'])
            ->assertUnprocessable()
            ->assertJsonPath('message', 'Kod jest nieprawidłowy albo wygasł.');

        $this->assertNotNull(Cache::get("b2b-code-login:{$this->account->id}"));
        $account = $this->account->fresh();
        $this->assertNull($account->connector_session);
        $this->assertNull($account->connector_session_saved_at);
        $this->assertNull($account->sync_requested_at);
    }

    public function test_verify_with_good_code_saves_encrypted_session_and_requests_sync(): void
    {
        Sanctum::actingAs(User::factory()->withRole('admin')->create());
        $this->travelTo(now()->startOfSecond());
        $this->postJson("/api/b2b-accounts/{$this->account->id}/login-code")->assertOk();

        $response = $this->postJson("/api/b2b-accounts/{$this->account->id}/login-code/verify", ['code' => ' 123456 '])
            ->assertOk()
            ->assertJsonPath('id', $this->account->id)
            ->assertJsonPath('connector_session_saved_at', now()->toIso8601String())
            ->assertJsonPath('sync_requested_at', now()->toIso8601String())
            ->assertJsonMissingPath('connector_session');

        $this->assertStringNotContainsString('sesja-sklepu', (string) $response->getContent());
        // Kod przycięty ze spacji, stan z kroku 1 przekazany łącznikowi.
        $this->assertSame('123456', $this->connector->lastCode);
        $this->assertSame('tx-123', $this->connector->lastState['tx'] ?? null);

        $account = $this->account->fresh();
        $this->assertSame(['cookies' => [['Name' => 'sesja-sklepu', 'Value' => 'abc']]], $account->connector_session);
        $this->assertNotNull($account->connector_session_saved_at);
        $this->assertNotNull($account->sync_requested_at);
        $this->assertNull(Cache::get("b2b-code-login:{$this->account->id}"));

        $raw = (string) DB::table('b2b_accounts')->where('id', $this->account->id)->value('connector_session');
        $this->assertStringNotContainsString('sesja-sklepu', $raw);
        $this->assertNull(json_decode($raw, true));
    }

    public function test_verify_while_sync_is_running_returns_conflict_without_saving(): void
    {
        Sanctum::actingAs(User::factory()->withRole('admin')->create());
        $this->postJson("/api/b2b-accounts/{$this->account->id}/login-code")->assertOk();
        $this->account->forceFill(['last_sync_status' => B2bSyncRun::STATUS_RUNNING])->save();

        $this->postJson("/api/b2b-accounts/{$this->account->id}/login-code/verify", ['code' => '123456'])
            ->assertStatus(409);

        $this->assertSame(0, $this->connector->finishCalls);
        $this->assertNull($this->account->fresh()->connector_session);
    }

    public function test_verify_validates_code(): void
    {
        Sanctum::actingAs(User::factory()->withRole('admin')->create());
        $this->postJson("/api/b2b-accounts/{$this->account->id}/login-code")->assertOk();

        foreach (['', 'abc123', '12a456', '123', '12345678901', '12 34'] as $code) {
            $this->postJson("/api/b2b-accounts/{$this->account->id}/login-code/verify", ['code' => $code])
                ->assertUnprocessable()
                ->assertJsonValidationErrors(['code']);
        }
        $this->postJson("/api/b2b-accounts/{$this->account->id}/login-code/verify", [])
            ->assertJsonValidationErrors(['code']);

        $this->assertSame(0, $this->connector->finishCalls);
    }

    public function test_view_reports_session_time_and_whether_connector_needs_code(): void
    {
        Sanctum::actingAs(User::factory()->withRole('admin')->create());
        // stała chwila — pod obciążeniem zapis i asercja trafiały w różne sekundy
        $savedAt = now()->startOfSecond()->subMinutes(3);
        $this->account->forceFill(['connector_session' => ['cookies' => []], 'connector_session_saved_at' => $savedAt])->save();

        $this->getJson('/api/b2b-accounts')
            ->assertOk()
            ->assertJsonPath('0.connector_session_saved_at', $savedAt->toIso8601String())
            ->assertJsonPath('0.requires_login_code', false)
            ->assertJsonMissingPath('0.connector_session');

        $this->getJson('/api/b2b-connectors')
            ->assertOk()
            ->assertJsonPath('0.requires_login_code', false);
    }

    public function test_changing_password_username_or_connector_clears_session_and_pending_code(): void
    {
        Sanctum::actingAs(User::factory()->withRole('admin')->create());

        foreach ([
            ['password' => 'nowe-haslo'],
            ['username' => 'inny@supon.test'],
            ['connector' => 'jsp'],
        ] as $change) {
            $this->account->forceFill(['connector_session' => ['cookies' => []], 'connector_session_saved_at' => now()])->save();
            Cache::put("b2b-code-login:{$this->account->id}", Crypt::encryptString('{}'), now()->addMinutes(15));
            $fresh = $this->account->fresh();

            $this->patchJson("/api/b2b-accounts/{$this->account->id}", [
                'username' => $fresh->username,
                'sites' => $fresh->sites,
                'connector' => $fresh->connector,
                ...$change,
            ])->assertOk()->assertJsonPath('connector_session_saved_at', null);

            $this->assertNull($this->account->fresh()->connector_session, json_encode($change));
            $this->assertNull(Cache::get("b2b-code-login:{$this->account->id}"));
        }
    }

    public function test_editing_note_keeps_session(): void
    {
        Sanctum::actingAs(User::factory()->withRole('admin')->create());
        $this->account->forceFill(['connector_session' => ['cookies' => []], 'connector_session_saved_at' => now()])->save();

        $this->patchJson("/api/b2b-accounts/{$this->account->id}", [
            'username' => $this->account->username,
            'password' => '',
            'sites' => $this->account->sites,
            'connector' => $this->account->connector,
            'note' => 'Nowa notatka',
        ])->assertOk();

        $this->assertSame(['cookies' => []], $this->account->fresh()->connector_session);
    }

    public function test_code_login_needs_manage_permission(): void
    {
        Sanctum::actingAs(User::factory()->withRole('kierownik')->create());

        $this->postJson("/api/b2b-accounts/{$this->account->id}/login-code")->assertForbidden();
        $this->postJson("/api/b2b-accounts/{$this->account->id}/login-code/verify", ['code' => '123456'])->assertForbidden();

        $this->assertSame(0, $this->connector->startCalls);
    }

    private function useConnector(B2bConnector $connector): void
    {
        $this->app->instance(B2bConnectorRegistry::class, new class($connector) extends B2bConnectorRegistry
        {
            public function __construct(private readonly B2bConnector $fake) {}

            public function make(B2bAccount $account, int $delayMs = 150): B2bConnector
            {
                return $this->fake;
            }
        });
    }
}

class FakePlainConnector implements B2bConnector
{
    public static function key(): string
    {
        return 'fake';
    }

    public static function label(): string
    {
        return 'Atrapa';
    }

    public static function host(): string
    {
        return 'order.example.test';
    }

    public static function forAccount(B2bAccount $account, int $delayMs): self
    {
        return new static;
    }

    public function login(): void {}

    public function products(): iterable
    {
        return [];
    }

    public function totalProducts(): int
    {
        return 0;
    }

    public function manufacturer(B2bRemoteProduct $product): string
    {
        return '';
    }

    public function price(B2bRemoteProduct $product): ?B2bRemotePrice
    {
        return null;
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

final class FakeCodeLoginConnector extends FakePlainConnector implements B2bCodeLoginSite
{
    public int $startCalls = 0;

    public int $finishCalls = 0;

    public ?RuntimeException $startFailure = null;

    public ?string $lastCode = null;

    /** @var array<string, mixed> */
    public array $lastState = [];

    public function startCodeLogin(): array
    {
        $this->startCalls++;
        if ($this->startFailure !== null) {
            throw $this->startFailure;
        }

        return [
            'state' => ['cookies' => [['Name' => 'x', 'Value' => 'y']], 'tx' => 'tx-123'],
            'message' => 'Kod wysłany na handel@supon.test.',
        ];
    }

    public function finishCodeLogin(array $state, string $code): array
    {
        $this->finishCalls++;
        $this->lastState = $state;
        $this->lastCode = $code;
        if ($code !== '123456') {
            throw new RuntimeException('Kod jest nieprawidłowy albo wygasł.');
        }

        return ['cookies' => [['Name' => 'sesja-sklepu', 'Value' => 'abc']]];
    }
}
