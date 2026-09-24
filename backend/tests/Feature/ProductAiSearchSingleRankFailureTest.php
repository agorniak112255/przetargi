<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Product;
use App\Models\User;
use App\Services\Ai\AiTask;
use App\Services\Ai\OpenAiCompatibleClient;
use App\Services\ProductAiSearchService;
use App\Services\Search\AiProductSearch;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Mockery;
use RuntimeException;
use Tests\Support\FakeSearchLlm;
use Tests\TestCase;

/**
 * Ścieżka pojedyncza (wyszukiwarka, dopasowanie jednej pozycji, battlecard) — ten sam błąd co w fali (1e8526e):
 * po awarii oceny analyzeAndRank oddawał intencję lokalną z całym wymaganiem jako „szukany produkt”, a
 * finishSearch szukał z niej kart od nowa i pytał model drugi raz. Karta z tej oceny wracała ze stanem „ranked”.
 */
final class ProductAiSearchSingleRankFailureTest extends TestCase
{
    use RefreshDatabase;

    private const GLOVES = 'Rękawice powlekane nitrylem EN 388 do prac montażowych';

    private const GLOVES_NEEDED = 'rękawice powlekane nitrylem';

    protected function setUp(): void
    {
        parent::setUp();
        $this->freezeTime();
        $this->seed(RolesAndPermissionsSeeder::class);
        Sanctum::actingAs(User::factory()->withRole('admin')->create());
    }

    public function test_rank_exception_is_not_followed_by_second_search_and_rank(): void
    {
        $glove = $this->glove();
        $rankCalls = 0;
        $rewrites = 0;
        $this->llm(function (string $kind) use ($glove, &$rankCalls, &$rewrites): array {
            if ($kind === FakeSearchLlm::KIND_REWRITE) {
                $rewrites++;
            }
            if ($kind !== FakeSearchLlm::KIND_RANK) {
                return $this->glovesIntent();
            }
            if (++$rankCalls === 1) {
                throw new RuntimeException('Limit zapytań modelu AI (HTTP 429).');
            }

            return ['matches' => [['id' => $glove->id, 'score' => 95, 'reason' => 'ocena puli z intencji zbudowanej z awarii']]];
        });

        $result = $this->app->make(AiProductSearch::class)->find(self::GLOVES, 10, AiTask::ProductSearch);

        $this->assertSame([], array_column($result['products'] ?? [], 'sku'), 'karta z drugiej oceny weszła do wyniku, choć pierwsza ocena padła');
        $this->assertSame(1, $rankCalls, 'po awarii oceny wyszukiwanie zapytało model drugi raz');
        // Wprost, nie przez intentChanged(): przepisanie zapytania to też pytanie do modelu, który przed chwilą nie odpowiedział.
        $this->assertSame(0, $rewrites, 'po awarii oceny wyszukiwanie przepisywało zapytanie');
        $this->assertSame(ProductAiSearchService::MODEL_STATE_UNAVAILABLE, $result['model_state'] ?? null);
        $this->assertSame(ProductAiSearchService::NOTE_MODEL_FAILED, $result['ai_note'] ?? null);
        $this->assertSame(self::GLOVES_NEEDED, $result['needed'] ?? null, 'awaria oceny podmieniła intencję z kroku „zrozum” na cały tekst wymagania');
    }

    public function test_empty_rank_answer_is_a_failure_like_in_batch(): void
    {
        // Kontrakt jak w fali: pusta tablica = brak odpowiedzi modelu. Stan był „unavailable”, ale intencja
        // pochodziła z pustej odpowiedzi, a wymaganie z normą dostawało nieocenione karty z katalogu.
        $glove = $this->glove();
        $rankCalls = 0;
        $rewrites = 0;
        $this->llm(function (string $kind) use ($glove, &$rankCalls, &$rewrites): array {
            if ($kind === FakeSearchLlm::KIND_REWRITE) {
                $rewrites++;
            }
            if ($kind !== FakeSearchLlm::KIND_RANK) {
                return $this->glovesIntent();
            }

            return ++$rankCalls === 1
                ? []
                : ['matches' => [['id' => $glove->id, 'score' => 95, 'reason' => 'ocena puli z intencji zbudowanej z awarii']]];
        });

        $result = $this->app->make(AiProductSearch::class)->find(self::GLOVES, 10, AiTask::ProductSearch);

        $this->assertSame([], array_column($result['products'] ?? [], 'sku'), 'po pustej odpowiedzi wymaganie z normą dostało karty bez oceny modelu');
        $this->assertSame(1, $rankCalls);
        $this->assertSame(0, $rewrites, 'po pustej odpowiedzi wyszukiwanie przepisywało zapytanie');
        $this->assertSame(ProductAiSearchService::MODEL_STATE_UNAVAILABLE, $result['model_state'] ?? null);
        $this->assertSame(ProductAiSearchService::NOTE_MODEL_FAILED, $result['ai_note'] ?? null);
        $this->assertSame(self::GLOVES_NEEDED, $result['needed'] ?? null);
    }

    public function test_model_that_answered_is_still_used(): void
    {
        // Kontrola: poprawka dotyczy tylko awarii — odpowiedź modelu dalej daje ocenioną kartę.
        $glove = $this->glove();
        $this->llm(fn (string $kind): array => $kind === FakeSearchLlm::KIND_RANK
            ? ['matches' => [['id' => $glove->id, 'score' => 90, 'reason' => 'nitryl, EN 388']]]
            : $this->glovesIntent());

        $result = $this->app->make(AiProductSearch::class)->find(self::GLOVES, 10, AiTask::ProductSearch);

        $this->assertSame(['RKW-NITRYL-OPIS'], array_column($result['products'] ?? [], 'sku'));
        $this->assertSame(ProductAiSearchService::MODEL_STATE_RANKED, $result['model_state'] ?? null);
    }

    private function glove(): Product
    {
        return Product::query()->create([
            'sku' => 'RKW-NITRYL-OPIS',
            'name' => 'Rękawice robocze powlekane nitrylem',
            'manufacturer' => 'TEST',
            'description' => 'Rękawice robocze powlekane nitrylem, EN 388, do prac montażowych.',
            'catalog_price_net' => 20,
            'purchase_price' => 12,
            'stock' => 10,
            'enrichment_status' => Product::ENRICHMENT_DONE,
            'enriched_at' => now(),
        ]);
    }

    /** @return array<string, mixed> */
    private function glovesIntent(): array
    {
        return [
            'needed' => self::GLOVES_NEEDED,
            'search_steps' => ['rękawice', 'powlekane nitrylem'],
            'manufacturer' => null,
            'model_name' => null,
            'size_note' => null,
            'search_phrases' => ['rękawice powlekane nitrylem', 'rękawice nitrylowe', 'rękawice robocze'],
            'constraints' => ['EN 388'],
        ];
    }

    /** @param  callable(string): array<string, mixed>  $answer  odpowiedź na chatJson wg rodzaju promptu (może rzucić) */
    private function llm(callable $answer): void
    {
        $llm = Mockery::mock(OpenAiCompatibleClient::class);
        $llm->shouldReceive('chatJson')->andReturnUsing(
            static fn (array $messages): array => $answer(FakeSearchLlm::kind($messages))
        );
        $llm->shouldNotReceive('chatJsonMany');
        $llm->shouldNotReceive('chat');
        $this->app->instance(OpenAiCompatibleClient::class, $llm);
    }
}
