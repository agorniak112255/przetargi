<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\AiSetting;
use App\Models\Product;
use App\Models\User;
use App\Services\Ai\AiTask;
use App\Services\Ai\OpenAiCompatibleClient;
use App\Services\ProductAiSearchService;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Laravel\Sanctum\Sanctum;
use Mockery;
use Tests\TestCase;

/**
 * Statystyki AI z 25.09.2026: 13 z 60 ocen kart kończyło się na limicie 2500 tokenów wyjścia. Ucięty JSON przechodził
 * jako częściowa odpowiedź — model oceniał tylko część z 24 kart (rękawice antyprzecięciowe: 1), bez śladu w logu.
 */
final class RankAnswerLengthTest extends TestCase
{
    use RefreshDatabase;

    public function test_rank_with_full_cards_has_room_for_24_reasons_and_asks_for_short_ones(): void
    {
        $this->seed(RolesAndPermissionsSeeder::class);
        Sanctum::actingAs(User::factory()->withRole('admin')->create());
        Product::query()->create([
            'sku' => 'SP-01',
            'name' => 'Spodnie robocze do pasa',
            'manufacturer' => 'X',
            'category' => 'Odzież robocza',
            'description' => 'Spodnie robocze z kieszeniami.',
            'catalog_price_net' => 50,
            'purchase_price' => 30,
            'stock' => 3,
            'enrichment_status' => Product::ENRICHMENT_DONE,
            'enriched_at' => now(),
        ]);

        $rank = null;
        $llm = Mockery::mock(OpenAiCompatibleClient::class);
        $llm->shouldReceive('chatJson')
            ->andReturnUsing(function (array $messages, ?float $temperature = null, ?int $maxTokens = null) use (&$rank): array {
                if ($rank === null && str_contains((string) $messages[1]['content'], 'Karty katalogu:')) {
                    $rank = ['system' => (string) $messages[0]['content'], 'max_tokens' => $maxTokens];
                }

                return ['needed' => 'spodnie robocze', 'search_phrases' => ['spodnie', 'spodnie robocze'], 'matches' => []];
            });
        $this->app->instance(OpenAiCompatibleClient::class, $llm);

        $this->postJson('/api/products/ai-search', ['query' => 'spodnie robocze do magazynu'])->assertOk();

        $this->assertNotNull($rank);
        $this->assertGreaterThanOrEqual(4000, $rank['max_tokens']);
        $this->assertStringContainsString('reason: max 20 słów', $rank['system']);
        // M11 (26.09.2026): cecha innej karty, serii albo wyrobu wskazanego kodem to nie dowód; brak z reason → missing_key
        $this->assertStringContainsString('Dowód tylko z tej karty', $rank['system']);
        $this->assertStringContainsString('każdy kluczowy warunek, którego brak opisujesz w reason, musi być w missing_key', $rank['system']);
        $this->assertSame('rank-2026-09-26-dowod-z-karty', ProductAiSearchService::RANK_PROMPT_VERSION);
    }

    public function test_truncated_answer_in_batch_is_logged_with_number_of_rated_cards(): void
    {
        $this->seedAi();
        Http::fake(['*' => Http::sequence()
            ->push(self::reply('{"matches":[{"id":1,"score":95,"reason":"ok","missing_key":[]}]}', 'stop'))
            ->push(self::reply('{"matches":[{"id":1,"score":95,"reason":"ok","missing_key":[]},{"id":2,"score":9', 'length')),
        ]);
        Log::spy();

        $batch = app(OpenAiCompatibleClient::class)->chatJsonMany([self::messages(), self::messages()], 4000, AiTask::ProductSearch, 1);

        $this->assertCount(1, $batch[1]['matches'] ?? []);
        Log::shouldHaveReceived('warning')->once()->withArgs(
            static fn (string $message, array $context): bool => str_contains($message, 'ucięta na limicie')
                && $context['matches'] === 1 && $context['max_tokens'] === 4000
        );
    }

    public function test_truncated_single_answer_with_ratings_is_logged(): void
    {
        $this->seedAi();
        Http::fake(['*' => Http::response(self::reply('{"matches":[{"id":1,"score":95,"reason":"ok","missing_key":[]},{"id":2', 'length'))]);
        Log::spy();

        $parsed = app(OpenAiCompatibleClient::class)->chatJson(self::messages(), null, 4000, null, AiTask::ProductSearch);

        $this->assertCount(1, $parsed['matches'] ?? []);
        Log::shouldHaveReceived('warning')->once()->withArgs(
            static fn (string $message, array $context): bool => str_contains($message, 'ucięta na limicie') && $context['matches'] === 1
        );
    }

    public function test_complete_answer_is_not_logged_as_truncated(): void
    {
        $this->seedAi();
        Http::fake(['*' => Http::response(self::reply('{"matches":[{"id":1,"score":95,"reason":"ok","missing_key":[]}]}', 'stop'))]);
        Log::spy();

        app(OpenAiCompatibleClient::class)->chatJsonMany([self::messages(), self::messages()], 4000, AiTask::ProductSearch, 2);

        Log::shouldNotHaveReceived('warning');
    }

    private function seedAi(): void
    {
        AiSetting::query()->create([
            'enabled' => true,
            'provider' => 'openai_compatible',
            'base_url' => 'https://ai.example.test/v1',
            'api_key' => 'sk-test-key-1234567890',
            'model' => 'test-model',
            'timeout_seconds' => 30,
            'temperature' => 0.1,
        ]);
    }

    /** @return list<array{role: string, content: string}> */
    private static function messages(): array
    {
        return [
            ['role' => 'system', 'content' => 'Oceń karty. JSON: {"matches":[]}'],
            ['role' => 'user', 'content' => "Wymaganie: rękawice\n\nKarty katalogu:\n[]"],
        ];
    }

    /** @return array<string, mixed> */
    private static function reply(string $content, string $finish): array
    {
        return [
            'model' => 'test-model',
            'choices' => [['message' => ['content' => $content], 'finish_reason' => $finish]],
            'usage' => ['prompt_tokens' => 100, 'completion_tokens' => 50],
        ];
    }
}
