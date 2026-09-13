<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\AiSetting;
use App\Models\JinaUsageSnapshot;
use App\Models\User;
use App\Services\Enrichment\JinaAccountService;
use App\Services\Enrichment\JinaSearchClient;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

final class JinaUsageApiTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config(['enrichment.reader_api_key' => null]);
        $this->seed(RolesAndPermissionsSeeder::class);
        Sanctum::actingAs(User::factory()->withRole('admin')->create());
    }

    public function test_usage_reports_balance_and_estimates_when_tokens_run_out(): void
    {
        $this->settingsWithJinaKey('jina_panel_key_1234567890');
        $this->snapshot(100_000_000, 48);
        $this->snapshot(90_000_000, 24);
        Http::fake([
            JinaAccountService::BALANCE_ENDPOINT.'*' => Http::response(['wallet' => ['total_balance' => 80_000_000]], 200),
        ]);

        $response = $this->getJson('/api/ai-settings/jina-usage')->assertOk();

        Http::assertSent(static fn (Request $r): bool => str_starts_with($r->url(), JinaAccountService::BALANCE_ENDPOINT)
            && str_contains($r->url(), 'api_key=jina_panel_key_1234567890'));
        $response->assertJsonPath('configured', true)
            ->assertJsonPath('key_source', 'panel')
            ->assertJsonPath('tokens_left', 80_000_000)
            ->assertJsonPath('searches_left', 8000)
            ->assertJsonPath('snapshots', 3)
            ->assertJsonPath('error', null);
        // 80 mln tokenów × 0,05 USD/mln = 4 USD
        $this->assertSame(4.0, (float) $response->json('usd_left'));
        // 20 mln tokenów w 48 h = 10 mln/dobę → 80 mln starczy na 8 dni
        $this->assertSame(10_000_000, $response->json('tokens_per_day'));
        $this->assertEqualsWithDelta(8.0, (float) $response->json('days_left'), 0.05);
        $runsOutAt = $response->json('runs_out_at');
        $this->assertIsString($runsOutAt);
        $this->assertEqualsWithDelta(
            now()->addDays(8)->getTimestamp(),
            strtotime($runsOutAt),
            120
        );
    }

    public function test_top_up_is_not_counted_as_negative_usage(): void
    {
        $this->settingsWithJinaKey('jina_panel_key_1234567890');
        $this->snapshot(10_000_000, 30);
        $this->snapshot(5_000_000, 20);
        // doładowanie: saldo rośnie o 1 mld — to nie „ujemne zużycie”
        $this->snapshot(1_005_000_000, 10);
        Http::fake([
            JinaAccountService::BALANCE_ENDPOINT.'*' => Http::response(['wallet' => ['total_balance' => 1_000_000_000]], 200),
        ]);

        $response = $this->getJson('/api/ai-settings/jina-usage')->assertOk();

        // zużyte 5 mln + 5 mln w 30 h → 8 mln/dobę
        $this->assertSame(8_000_000, $response->json('tokens_per_day'));
        $this->assertSame(1_000_000_000, $response->json('tokens_left'));
        $this->assertSame(50.0, (float) $response->json('usd_left'));
    }

    public function test_invalid_key_reports_error_but_keeps_last_snapshot(): void
    {
        $this->settingsWithJinaKey('jina_bad_key_1234567890');
        $this->snapshot(7_000_000, 24);
        Http::fake([
            JinaAccountService::BALANCE_ENDPOINT.'*' => Http::response(['detail' => 'Invalid API key.'], 401),
        ]);

        $response = $this->getJson('/api/ai-settings/jina-usage')->assertOk();

        $this->assertStringContainsString('nieważny', (string) $response->json('error'));
        $response->assertJsonPath('tokens_left', 7_000_000)
            ->assertJsonPath('snapshots', 1)
            ->assertJsonPath('tokens_per_day', null)
            ->assertJsonPath('days_left', null);
    }

    public function test_without_key_nothing_is_fetched(): void
    {
        Http::fake();

        $this->getJson('/api/ai-settings/jina-usage')
            ->assertOk()
            ->assertJsonPath('configured', false)
            ->assertJsonPath('tokens_left', null)
            ->assertJsonPath('error', null);

        Http::assertNothingSent();
    }

    public function test_fresh_snapshot_is_reused_unless_refresh_is_forced(): void
    {
        $this->settingsWithJinaKey('jina_panel_key_1234567890');
        JinaUsageSnapshot::query()->create(['tokens_left' => 3_000_000, 'taken_at' => now()->subMinutes(2)]);
        Http::fake([
            JinaAccountService::BALANCE_ENDPOINT.'*' => Http::response(['wallet' => ['total_balance' => 2_500_000]], 200),
        ]);

        $this->getJson('/api/ai-settings/jina-usage')->assertOk()->assertJsonPath('tokens_left', 3_000_000);
        Http::assertNothingSent();

        $this->postJson('/api/ai-settings/jina-usage/refresh')->assertOk()->assertJsonPath('tokens_left', 2_500_000);
        Http::assertSentCount(1);
    }

    public function test_panel_key_overrides_env_key_and_is_masked(): void
    {
        config(['enrichment.reader_api_key' => 'jina_env_key_000000000']);
        AiSetting::query()->create([
            'enabled' => true,
            'provider' => 'openai_compatible',
            'base_url' => 'http://127.0.0.1:8081/v1',
            'api_key' => 'local',
            'model' => 'qwen',
            'timeout_seconds' => 30,
            'temperature' => 0.1,
        ]);

        $this->getJson('/api/ai-settings')
            ->assertOk()
            ->assertJsonPath('has_jina_api_key', true)
            ->assertJsonPath('jina_key_source', 'env');

        $this->putJson('/api/ai-settings', ['jina_api_key' => 'jina_panel_key_1234567890'])
            ->assertOk()
            ->assertJsonPath('has_jina_api_key', true)
            ->assertJsonPath('jina_key_source', 'panel')
            ->assertJsonPath('jina_api_key_masked', 'jin'.str_repeat('*', 18).'7890');

        // maska z UI („zostaw puste, by nie zmieniać”) nie nadpisuje klucza
        $this->putJson('/api/ai-settings', ['jina_api_key' => 'jin***7890'])->assertOk();
        $this->assertSame('jina_panel_key_1234567890', AiSetting::query()->first()?->jina_api_key);

        Http::fake(['s.jina.ai/*' => Http::response(['data' => []], 200)]);
        (new JinaSearchClient)->search('HyFlex 11-840 Ansell');
        Http::assertSent(static fn (Request $r): bool => $r->hasHeader('Authorization', 'Bearer jina_panel_key_1234567890'));
    }

    public function test_balance_is_read_from_alternative_payload_shapes(): void
    {
        $service = app(JinaAccountService::class);

        $this->assertSame(123, $service->tokensFromPayload(['wallet' => ['total_balance' => 123]]));
        $this->assertSame(456, $service->tokensFromPayload(['total_balance' => '456']));
        $this->assertSame(0, $service->tokensFromPayload(['balance' => -5]));
        $this->assertNull($service->tokensFromPayload(['detail' => 'Invalid API key.']));
        $this->assertNull($service->tokensFromPayload('nie json'));
    }

    private function settingsWithJinaKey(string $key): void
    {
        AiSetting::query()->create([
            'enabled' => true,
            'provider' => 'openai_compatible',
            'base_url' => 'http://127.0.0.1:8081/v1',
            'api_key' => 'local',
            'jina_api_key' => $key,
            'model' => 'qwen',
            'timeout_seconds' => 30,
            'temperature' => 0.1,
        ]);
    }

    private function snapshot(int $tokens, int $hoursAgo): void
    {
        JinaUsageSnapshot::query()->create([
            'tokens_left' => $tokens,
            'taken_at' => now()->subHours($hoursAgo),
        ]);
    }
}
