<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\AiSetting;
use App\Models\Client;
use App\Models\ClientInquiry;
use App\Models\Product;
use App\Models\SearchEvent;
use App\Models\Tender;
use App\Models\TenderItem;
use App\Models\User;
use App\Services\Ai\AiServedProviderTally;
use App\Services\Ai\AiTask;
use App\Services\Ai\OpenAiCompatibleClient;
use App\Services\ClientInquiryService;
use App\Services\ProductAiSearchService;
use App\Services\ProductInquirySearch;
use App\Services\ProductMatchService;
use App\Services\Search\AiProductSearch;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Laravel\Sanctum\Sanctum;
use Mockery;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Support\FakeSearchLlm;
use Tests\TestCase;

/**
 * Ekran „Statystyki AI”: koszt (tokeny ze wszystkich odpowiedzi modelu) i przebieg każdej pozycji wyszukiwania —
 * wyszukiwarka, „Dopasuj wszystkie”, pojedyncza pozycja przetargu, zapytania z poczty. Recenzja planu (25.09.2026):
 * przepisanie zapytania zeruje ślad pozycji, więc koszt nie może żyć w śladzie — inaczej najdroższe pozycje
 * (dwie oceny) traciłyby tokeny kroku „zrozum” i pierwszej oceny.
 */
final class SearchUsageStatsRecordingTest extends TestCase
{
    use RefreshDatabase;

    private const GLOVES = 'Rękawice powlekane nitrylem EN 388 do prac montażowych';

    /** Tokeny wejścia/wyjścia odpowiedzi atrapy dla każdego rodzaju wywołania. */
    private const COST = [
        FakeSearchLlm::KIND_UNDERSTAND => [1000, 100],
        FakeSearchLlm::KIND_RANK => [5000, 300],
        FakeSearchLlm::KIND_REWRITE => [800, 80],
    ];

    private int $gloveId = 0;

    private int $ranks = 0;

    protected function setUp(): void
    {
        parent::setUp();
        $this->freezeTime();
        $this->seed(RolesAndPermissionsSeeder::class);
        Sanctum::actingAs(User::factory()->withRole('admin')->create());
        // Karta bez opisu: wchodzi do oceny, ale nie do listy zapasowej — po „nic nie pasuje” pozycja jest pusta
        // i wyszukiwanie przepisuje zapytanie (jak ProductAiSearchRewriteAfterEmptyRankTest).
        $this->gloveId = (int) Product::query()->create([
            'sku' => 'RKW-NITRYL-1',
            'name' => 'Rękawice powlekane nitrylem',
            'manufacturer' => 'TEST',
            'description' => null,
            'catalog_price_net' => 20,
            'purchase_price' => 12,
            'stock' => 10,
            'enrichment_status' => Product::ENRICHMENT_NONE,
        ])->id;
    }

    /** @return iterable<string, array{0: bool}> */
    public static function paths(): iterable
    {
        yield 'fala' => [true];
        yield 'pojedyncze' => [false];
    }

    #[DataProvider('paths')]
    public function test_usage_keeps_every_stage_of_position_ranked_again_after_rewrite(bool $batch): void
    {
        $this->llmWithRewrite();

        $search = $this->app->make(AiProductSearch::class);
        $result = $batch
            ? $search->findMany([self::GLOVES], 10, AiTask::ProductSearch, 2)[0]
            : $search->find(self::GLOVES, 10, AiTask::ProductSearch);

        $usage = $result['ai_usage'] ?? null;
        $this->assertIsArray($usage);
        $this->assertSame(
            [
                ProductAiSearchService::USAGE_STAGE_UNDERSTAND,
                ProductAiSearchService::USAGE_STAGE_RANK,
                ProductAiSearchService::USAGE_STAGE_REWRITE,
                ProductAiSearchService::USAGE_STAGE_RANK_AFTER_REWRITE,
            ],
            array_column($usage['stages'], 'stage'),
        );
        $this->assertSame(1000 + 5000 + 800 + 5000, $usage['prompt_tokens'], 'przepisanie nie może zgubić kosztu pierwszej oceny');
        $this->assertSame(100 + 300 + 80 + 300, $usage['completion_tokens']);
        $this->assertSame(4, $usage['calls']);
        $this->assertSame(2, $usage['rank_calls']);
        $this->assertSame(1, $usage['rank_card_count']);
        $this->assertSame(ProductAiSearchService::UNDERSTAND_SOURCE_MODEL, $usage['understand_source']);
        $this->assertSame(ProductAiSearchService::UNDERSTAND_SOURCE_MODEL, $usage['stages'][0]['source'] ?? null, 'źródło zrozumienia idzie do zapisu z etapem');
        $this->assertSame(['RKW-NITRYL-1'], array_column($result['products'], 'sku'));
    }

    /** Druga ocena po zmianie intencji w odpowiedzi rankingu (bez przepisania) to zwykła ocena — w obu ścieżkach. */
    #[DataProvider('paths')]
    public function test_second_rank_after_changed_intent_without_rewrite_is_plain_rank(bool $batch): void
    {
        $this->llmWithRewrite(intentChangedInRank: true);

        $usage = $this->searchGloves($batch)['ai_usage'];

        $this->assertSame(
            [ProductAiSearchService::USAGE_STAGE_UNDERSTAND, ProductAiSearchService::USAGE_STAGE_RANK, ProductAiSearchService::USAGE_STAGE_RANK],
            array_column($usage['stages'], 'stage'),
        );
        $this->assertSame(2, $usage['rank_calls']);
    }

    /** AI wyłączone albo brak klucza: klient rzuca przed żądaniem — etap zostaje, ale model nie był pytany. */
    #[DataProvider('paths')]
    public function test_rank_without_any_request_is_not_counted_as_asked(bool $batch): void
    {
        $this->llmWithRewrite(reportUsage: false);

        $usage = $this->searchGloves($batch)['ai_usage'];

        $this->assertContains(ProductAiSearchService::USAGE_STAGE_RANK, array_column($usage['stages'], 'stage'));
        $this->assertSame(0, $usage['rank_calls']);
        $this->assertNull($usage['rank_card_count']);
        $this->assertSame(0, $usage['calls']);
    }

    public function test_product_search_event_stores_usage_and_api_response_does_not_expose_it(): void
    {
        $this->llmWithRewrite();

        $response = $this->postJson('/api/products/ai-search', ['query' => self::GLOVES, 'limit' => 10])->assertOk();

        $this->assertArrayNotHasKey('ai_usage', $response->json());
        $event = SearchEvent::query()->sole();
        $this->assertSame(SearchEvent::TASK_PRODUCT_SEARCH, $event->task);
        $this->assertSame(11800, $event->prompt_tokens);
        $this->assertSame(780, $event->completion_tokens);
        $this->assertSame(4, $event->llm_calls);
        $this->assertSame(2, $event->rank_calls);
        $this->assertSame(1, $event->rank_card_count);
        $this->assertSame(ProductAiSearchService::MODEL_STATE_RANKED, $event->model_state);
        $this->assertCount(4, $event->usage);
        $this->assertNull($event->context_type);
    }

    public function test_tender_match_records_one_event_per_requirement_with_tender_and_line_numbers(): void
    {
        $this->enableAi();
        $this->llmWithRewrite();
        $tender = $this->tender();
        foreach ([1, 2] as $line) {
            TenderItem::query()->create([
                'tender_id' => $tender->id, 'line_no' => $line, 'requirement' => self::GLOVES, 'quantity' => 10, 'status' => 'brak',
            ]);
        }

        app(ProductMatchService::class)->matchTender($tender, true);

        $event = SearchEvent::query()->sole();
        $this->assertSame(SearchEvent::TASK_TENDER_MATCH, $event->task);
        $this->assertSame(SearchEvent::CONTEXT_TENDER, $event->context_type);
        $this->assertSame((int) $tender->id, $event->context_id);
        $this->assertSame([1, 2], $event->context_items, 'ta sama treść w dwóch pozycjach = jedno wyszukiwanie');
        $this->assertNotNull($event->run_id);
        $this->assertSame(11800, $event->prompt_tokens);
    }

    /**
     * „Dopasuj wszystkie” wysyła pozycje osobnymi żądaniami, kilka naraz (przesuwane okno) — w statystykach to nadal
     * jeden przebieg: numer przebiegu podaje przeglądarka.
     */
    public function test_items_sent_as_separate_requests_share_run_id_from_browser(): void
    {
        $this->enableAi();
        $this->llmWithRewrite();
        $tender = $this->tender();
        $ids = [];
        foreach ([1, 2] as $line) {
            $ids[] = TenderItem::query()->create([
                'tender_id' => $tender->id, 'line_no' => $line, 'requirement' => self::GLOVES, 'quantity' => 10, 'status' => 'brak',
            ])->id;
        }
        $runId = '01JABCDEFGHJKMNPQRSTVWXYZ0';

        foreach ($ids as $id) {
            $this->postJson("/api/tenders/{$tender->id}/match", ['only_empty' => true, 'item_ids' => [$id], 'run_id' => strtolower($runId)])
                ->assertOk()
                ->assertJsonPath('processed', 1);
        }

        $events = SearchEvent::query()->orderBy('id')->get();
        $this->assertCount(2, $events);
        $this->assertSame([$runId, $runId], $events->pluck('run_id')->all());
        $this->assertSame([[1], [2]], $events->pluck('context_items')->all());
    }

    public function test_match_rejects_run_id_that_is_not_ulid(): void
    {
        $tender = $this->tender();

        $this->postJson("/api/tenders/{$tender->id}/match", ['run_id' => 'przebieg-1'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('run_id');
    }

    /** tenders:eval, jego odtworzenie i tenders:debug-match idą przez debugPick — pomiar nie brudzi statystyk. */
    public function test_debug_pick_used_by_tender_eval_records_nothing(): void
    {
        $this->enableAi();
        $this->llmWithRewrite();
        $rows = $this->app->make(AiProductSearch::class)->findMany([self::GLOVES], 10, AiTask::ProductSearch, 2);
        $item = new TenderItem;
        $item->forceFill(['requirement' => self::GLOVES]);

        app(ProductMatchService::class)->debugPick($item, $rows[0]);

        $this->assertSame(0, SearchEvent::query()->count());
    }

    public function test_inquiry_positions_are_recorded_with_inquiry_id_after_it_is_created(): void
    {
        $this->mock(OpenAiCompatibleClient::class, function ($mock): void {
            $mock->shouldReceive('chatJson')->once()->andReturn([
                'subject' => 'Rękawice', 'questions' => [], 'product_queries' => ['rękawice nitrylowe', 'gogle'], 'line_items' => [], 'cards' => [],
            ]);
        });
        $usage = ['prompt_tokens' => 9000, 'completion_tokens' => 400, 'reasoning_tokens' => 0, 'cached_tokens' => 0, 'cost' => null,
            'calls' => 2, 'failed_calls' => 0, 'rank_calls' => 1, 'rank_card_count' => 24, 'understand_source' => 'model',
            'model' => 'model-testowy', 'provider' => null, 'fallback' => false, 'stages' => []];
        $this->mock(ProductInquirySearch::class, function ($mock) use ($usage): void {
            $mock->shouldReceive('findMany')->once()->andReturn([
                ['query' => 'rękawice nitrylowe', 'products' => [], 'model_state' => 'empty', 'ai_usage' => $usage, 'trace' => ['candidate_ids' => [5, 6]]],
                // wiersz bez kosztu (np. zapas po awarii fali) — nie ma czego zapisać
                ['query' => 'gogle', 'products' => [], 'model_state' => 'unavailable'],
            ]);
        });

        $res = $this->postJson('/api/inquiries', ['body' => 'Proszę o ofertę na rękawice nitrylowe i gogle.', 'tone' => 'handlowy'])
            ->assertCreated();

        $event = SearchEvent::query()->sole();
        $inquiry = ClientInquiry::query()->findOrFail($res->json('id'));
        $this->assertSame(SearchEvent::TASK_INQUIRY, $event->task);
        $this->assertSame(SearchEvent::CONTEXT_INQUIRY, $event->context_type);
        $this->assertSame((int) $inquiry->id, $event->context_id);
        $this->assertSame(9000, $event->prompt_tokens);
        $this->assertSame(2, $event->llm_calls);
        $this->assertSame(24, $event->rank_card_count);
        $this->assertSame('model-testowy', $event->model);
        $this->assertSame(2, $event->candidate_count);
    }

    /** Podgląd inquiries:rematch obiecuje „nic się nie zapisuje” — zdarzenia zapisuje dopiero --apply. */
    public function test_inquiry_rematch_records_events_only_when_applied(): void
    {
        $this->mock(OpenAiCompatibleClient::class, function ($mock): void {
            $mock->shouldReceive('chatJson')->once()->andReturn([
                'subject' => 'Rękawice', 'questions' => [], 'product_queries' => ['rękawice nitrylowe'], 'line_items' => [], 'cards' => [],
            ]);
        });
        $this->inquirySearchReturns(withUsage: false);
        $inquiryId = (int) $this->postJson('/api/inquiries', ['body' => 'Proszę o ofertę na rękawice nitrylowe.', 'tone' => 'handlowy'])
            ->assertCreated()->json('id');
        $this->assertSame(0, SearchEvent::query()->count(), 'fixture: analiza bez kosztu nic nie zapisała');

        $this->inquirySearchReturns(withUsage: true);
        app(ClientInquiryService::class)->rematch(ClientInquiry::query()->findOrFail($inquiryId), false);
        $this->assertSame(0, SearchEvent::query()->count(), 'podgląd niczego nie zapisuje');

        $this->inquirySearchReturns(withUsage: true);
        app(ClientInquiryService::class)->rematch(ClientInquiry::query()->findOrFail($inquiryId), true);
        $event = SearchEvent::query()->sole();
        $this->assertSame(SearchEvent::TASK_INQUIRY, $event->task);
        $this->assertSame($inquiryId, $event->context_id);
        $this->assertSame(9000, $event->prompt_tokens);
    }

    private function inquirySearchReturns(bool $withUsage): void
    {
        $row = ['query' => 'rękawice nitrylowe', 'products' => [], 'model_state' => 'empty'];
        if ($withUsage) {
            $row['ai_usage'] = ['prompt_tokens' => 9000, 'completion_tokens' => 400, 'reasoning_tokens' => 0, 'cached_tokens' => 0,
                'cost' => null, 'calls' => 2, 'failed_calls' => 0, 'rank_calls' => 1, 'rank_card_count' => 24, 'stages' => []];
        }
        $this->mock(ProductInquirySearch::class, function ($mock) use ($row): void {
            $mock->shouldReceive('findMany')->once()->andReturn([$row]);
        });
    }

    /** @return array<string, mixed> */
    private function searchGloves(bool $batch): array
    {
        $search = $this->app->make(AiProductSearch::class);

        return $batch
            ? $search->findMany([self::GLOVES], 10, AiTask::ProductSearch, 2)[0]
            : $search->find(self::GLOVES, 10, AiTask::ProductSearch);
    }

    private function enableAi(): void
    {
        Http::fake();
        AiSetting::query()->create([
            'enabled' => true,
            'provider' => 'openai_compatible',
            'base_url' => 'https://api.openai.com/v1',
            'api_key' => 'sk-test-key-1234567890',
            'model' => 'gpt-4o-mini',
            'timeout_seconds' => 60,
            'temperature' => 0.1,
        ]);
    }

    private function tender(): Tender
    {
        return Tender::query()->create([
            'number' => 'PRZ/STAT/1',
            'title' => 'Statystyki AI',
            'client_id' => Client::query()->create(['name' => 'Klient'])->id,
            'owner_id' => User::factory()->create()->id,
            'status' => 'wycena',
            'ai_percent' => 0,
            'last_activity_at' => now(),
        ]);
    }

    /**
     * Atrapa klienta, która — jak prawdziwy — zgłasza koszt każdej odpowiedzi: pojedynczej do licznika narastającego,
     * fali do lastBatchUsage (per indeks). Zrozumienie → pierwsza ocena „nic nie pasuje” → przepisanie z nową
     * intencją → druga ocena z kartą.
     */
    private function llmWithRewrite(bool $intentChangedInRank = false, bool $reportUsage = true): void
    {
        $answer = function (array $messages) use ($intentChangedInRank): array {
            $kind = FakeSearchLlm::kind($messages);
            $intent = [
                'needed' => 'rękawice powlekane nitrylem',
                'search_steps' => ['rękawice', 'powlekane nitrylem'],
                'manufacturer' => null,
                'model_name' => null,
                'size_note' => null,
                'search_phrases' => ['rękawice powlekane nitrylem', 'rękawice nitrylowe'],
                'constraints' => ['EN 388'],
            ];
            $changed = [...$intent, 'needed' => 'rękawice montażowe nitrylowe', 'search_phrases' => ['rękawice montażowe', 'rękawice nitrylowe montażowe']];
            if ($kind === FakeSearchLlm::KIND_REWRITE) {
                return $changed;
            }
            if ($kind !== FakeSearchLlm::KIND_RANK) {
                return $intent;
            }

            return ++$this->ranks === 1
                ? [...($intentChangedInRank ? $changed : $intent), 'matches' => []]
                : ['matches' => [['id' => $this->gloveId, 'score' => 90, 'reason' => 'nitryl, montaż', 'missing_key' => []]]];
        };
        $usageOf = static function (array $messages): array {
            [$prompt, $completion] = self::COST[FakeSearchLlm::kind($messages)] ?? [0, 0];

            return ['prompt_tokens' => $prompt, 'completion_tokens' => $completion, 'reasoning_tokens' => 0, 'cached_tokens' => 0,
                'cost' => null, 'calls' => 1, 'failed_calls' => 0];
        };
        $llm = Mockery::mock(OpenAiCompatibleClient::class);
        $llm->shouldReceive('chatJson')->andReturnUsing(static function (array $messages) use ($answer, $usageOf, $reportUsage): array {
            if ($reportUsage) {
                app(AiServedProviderTally::class)->addUsage($usageOf($messages));
            }

            return $answer($messages);
        });
        $llm->shouldReceive('chatJsonMany')->andReturnUsing(static function (array $sets) use ($answer, $usageOf, $reportUsage): array {
            $usages = $reportUsage ? array_map($usageOf, $sets) : [];
            foreach ($usages as $usage) {
                app(AiServedProviderTally::class)->addUsage($usage);
            }
            $out = array_map($answer, $sets);
            app(AiServedProviderTally::class)->recordBatch(array_fill(0, count($sets), 'dostawca-testowy'), [], $usages);

            return $out;
        });
        $llm->shouldNotReceive('chat');
        $this->app->instance(OpenAiCompatibleClient::class, $llm);
    }
}
