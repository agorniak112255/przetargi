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
        // 27.09.2026: reason bez wyliczania potwierdzonych cech — to one wydłużały odpowiedź (golden: ~440 zamiast ~970
        // tokenów, trafność w szumie). Brak drugorzędny oznaczony w reason nie idzie do missing_key (bez tego zdania model
        // wpisywał tam każdy brak i ocena spadała do 50). Puste pola model pomija — odczyt bierze brak pola jako pusty.
        $this->assertStringContainsString(
            'reason: tylko braki i sprzeczności karty wobec wymagania, bez wyliczania potwierdzonych cech, max 15 słów; '
            .'brak drugorzędny oznacz „(drugorzędne)” i nie wpisuj go do missing_key; nic nie brakuje → pomiń reason; '
            .'pusty missing_key pomiń. ',
            $rank['system'],
        );
        // Szablon odpowiedzi musi przejść przez podmianę w analyzeAndRankMessages — inaczej model po cichu przestaje
        // zwracać needed/search_phrases/constraints (intencja z oceny, przepisanie zapytania).
        $this->assertStringContainsString(
            'JSON: {"needed":"nazwa","search_phrases":["najpierw nazwa"],"constraints":[],'
            .'"matches":[{"id":1,"score":0-100,"reason":"uzasadnienie","missing_key":[]}]}.',
            $rank['system'],
        );
        $this->assertStringNotContainsString('JSON: {"matches":[', $rank['system'], 'szablon bez pól zrozumienia został w prompcie');
        // M11 (26.09.2026): cecha innej karty, serii albo wyrobu wskazanego kodem to nie dowód; brak z reason → missing_key
        $this->assertStringContainsString('Dowód tylko z tej karty', $rank['system']);
        $this->assertStringContainsString('każdy kluczowy warunek, którego brak opisujesz w reason, musi być w missing_key', $rank['system']);
        // 27.09.2026: najwolniejsze pozycje przetargu wypisywały po 20 kart (do 2500 tokenów), a przetarg bierze 10 najlepszych
        // — lista do 10 kart, przy nadmiarze te z najwyższym score. Karty < 40 wolno dalej oceniać jawnie (model_rejected
        // blokuje wiersz katalogowy w przetargu), więc prompt nie każe ich pomijać.
        $this->assertStringContainsString('Max 10 — gdy pasuje więcej kart, zwróć te z najwyższym score. ', $rank['system']);
        $this->assertStringNotContainsString('poniżej 40 nie zwracaj', $rank['system']);
        $this->assertStringNotContainsString('Max 20', $rank['system']);
        $this->assertSame('rank-2026-09-27-max10', ProductAiSearchService::RANK_PROMPT_VERSION);
    }

    /**
     * Odporność odczytu (27.09.2026: prompt każe pomijać pusty missing_key): karta bez reason i bez missing_key zachowuje
     * ocenę bez sztucznego uzasadnienia, brak drugorzędny nie obcina oceny, niepusty missing_key obcina do 50.
     */
    public function test_rank_answer_without_reason_and_empty_missing_key_keeps_model_score(): void
    {
        $this->seed(RolesAndPermissionsSeeder::class);
        Sanctum::actingAs(User::factory()->withRole('admin')->create());
        $full = $this->gloves('REK-PELNA', 'Rękawice nitrylowe odporne na oleje, długość 300 mm.');
        $secondary = $this->gloves('REK-DRUGO', 'Rękawice nitrylowe odporne na oleje.');
        $key = $this->gloves('REK-KLUCZ', 'Rękawice nitrylowe do prac ogólnych.');
        $matches = [
            ['id' => $full->id, 'score' => 95],
            ['id' => $secondary->id, 'score' => 80, 'reason' => 'Brak dowodu długości 300 mm (drugorzędne).'],
            ['id' => $key->id, 'score' => 85, 'reason' => 'Brak dowodu odporności na oleje.', 'missing_key' => ['odporność na oleje']],
        ];
        $llm = Mockery::mock(OpenAiCompatibleClient::class);
        $llm->shouldReceive('chatJson')->andReturnUsing(static function (array $messages) use ($matches): array {
            $intent = ['needed' => 'rękawice nitrylowe', 'search_phrases' => ['rękawice nitrylowe'], 'constraints' => ['odporność na oleje']];

            return str_contains((string) ($messages[0]['content'] ?? ''), '"matches"') ? $intent + ['matches' => $matches] : $intent;
        });
        $this->app->instance(OpenAiCompatibleClient::class, $llm);

        $rows = collect(
            $this->postJson('/api/products/ai-search', ['query' => 'rękawice nitrylowe olejoodporne długość 300 mm', 'limit' => 10])
                ->assertOk()
                ->json('products')
        )->keyBy('sku');

        $this->assertSame(95, $rows['REK-PELNA']['ai_match_percent'] ?? null);
        $this->assertNull($rows['REK-PELNA']['ai_match_reason'] ?? null, 'brak reason nie może stać się tekstem uzasadnienia');
        $this->assertSame(80, $rows['REK-DRUGO']['ai_match_percent'] ?? null, 'brak drugorzędny bez missing_key nie obcina oceny');
        $this->assertStringContainsString('(drugorzędne)', (string) ($rows['REK-DRUGO']['ai_match_reason'] ?? ''));
        $this->assertSame(50, $rows['REK-KLUCZ']['ai_match_percent'] ?? null, 'niepusty missing_key dalej obcina ocenę');
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

    private function gloves(string $sku, string $description): Product
    {
        return Product::query()->create([
            'sku' => $sku,
            'name' => 'Rękawice nitrylowe',
            'manufacturer' => 'TEST',
            'category' => 'Rękawice',
            'description' => $description,
            'catalog_price_net' => 60,
            'purchase_price' => 40,
            'stock' => 5,
            'enrichment_status' => Product::ENRICHMENT_DONE,
            'enriched_at' => now(),
        ]);
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
