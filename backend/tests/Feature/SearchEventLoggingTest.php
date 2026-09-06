<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Product;
use App\Models\SearchEvent;
use App\Models\SearchEventAction;
use App\Models\User;
use App\Services\Ai\OpenAiCompatibleClient;
use App\Services\ProductAiSearchService;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Mockery;
use Tests\TestCase;

final class SearchEventLoggingTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolesAndPermissionsSeeder::class);
    }

    private function catalog(): Product
    {
        Product::query()->create([
            'sku' => 'BOOT-01',
            'name' => 'Buty robocze S3',
            'manufacturer' => 'X',
            'description' => 'Obuwie ochronne S3.',
            'catalog_price_net' => 90,
            'purchase_price' => 50,
            'stock' => 3,
            'enrichment_status' => Product::ENRICHMENT_DONE,
            'enriched_at' => now(),
        ]);

        return Product::query()->create([
            'sku' => 'GLOVE-NH3',
            'name' => 'Rękawice chemiczne AlphaTec',
            'manufacturer' => 'Ansell',
            'category' => 'Rękawice',
            'norms' => 'EN 374',
            'description' => 'Rękawice odporne na amoniak i kwasy.',
            'catalog_price_net' => 12.5,
            'purchase_price' => 8,
            'stock' => 10,
            'enrichment_status' => Product::ENRICHMENT_DONE,
            'enrichment_payload' => ['use_cases' => ['praca z amoniakiem']],
            'enriched_at' => now(),
        ]);
    }

    private function mockLlm(Product $match): void
    {
        $llm = Mockery::mock(OpenAiCompatibleClient::class);
        $llm->shouldReceive('chatJson')->andReturn([
            'needed' => 'rękawice do amoniaku',
            'search_phrases' => ['rękawice chemiczne', 'amoniak'],
            'constraints' => ['amoniak'],
            'matches' => [
                ['id' => $match->id, 'score' => 92, 'reason' => 'Odporność na amoniak'],
            ],
        ]);
        $this->app->instance(OpenAiCompatibleClient::class, $llm);
    }

    public function test_search_records_event_with_retrieval_pool_and_result(): void
    {
        Sanctum::actingAs($user = User::factory()->withRole('admin')->create());
        $match = $this->catalog();
        $this->mockLlm($match);

        $response = $this->postJson('/api/products/ai-search', [
            'query' => 'rękawice do pracy z amoniakiem',
        ])->assertOk();

        $eventId = $response->json('search_event_id');
        $this->assertIsInt($eventId);

        $event = SearchEvent::query()->findOrFail($eventId);
        $this->assertSame($user->id, $event->user_id);
        $this->assertSame(SearchEvent::TASK_PRODUCT_SEARCH, $event->task);
        $this->assertSame(ProductAiSearchService::RANK_PROMPT_VERSION, $event->prompt_version);
        $this->assertSame('rękawice do pracy z amoniakiem', $event->query);
        $this->assertSame(1, $event->result_count);
        // Pula z retrievalu — bez niej recall etapu 1 byłby niemierzalny.
        $this->assertContains($match->id, $event->candidate_ids);
        $this->assertGreaterThan(0, $event->candidate_count);
        $this->assertSame([['id' => $match->id, 'score' => 92]], $event->llm_matches);
        $this->assertSame($match->id, $event->returned[0]['id']);
        $this->assertSame('GLOVE-NH3', $event->returned[0]['sku']);
        $this->assertSame(92, $event->returned[0]['score']);
        $this->assertArrayHasKey('total', $event->timings_ms);
    }

    public function test_action_endpoint_records_feedback_with_position(): void
    {
        Sanctum::actingAs($user = User::factory()->withRole('admin')->create());
        $match = $this->catalog();
        $this->mockLlm($match);

        $eventId = $this->postJson('/api/products/ai-search', [
            'query' => 'rękawice do pracy z amoniakiem',
        ])->assertOk()->json('search_event_id');

        $this->postJson("/api/products/ai-search/{$eventId}/action", [
            'product_id' => $match->id,
            'action' => SearchEventAction::ACTION_ADD_TO_OFFER,
        ])->assertOk()->assertJsonPath('recorded', true);

        $action = SearchEventAction::query()->firstOrFail();
        $this->assertSame($eventId, $action->search_event_id);
        $this->assertSame($match->id, $action->product_id);
        $this->assertSame($user->id, $action->user_id);
        // Pozycja odczytana z zapisanej listy wyników, gdy front jej nie poda.
        $this->assertSame(1, $action->position);
    }

    public function test_repeated_action_does_not_duplicate_rows(): void
    {
        Sanctum::actingAs(User::factory()->withRole('admin')->create());
        $match = $this->catalog();
        $this->mockLlm($match);

        $eventId = $this->postJson('/api/products/ai-search', [
            'query' => 'rękawice do pracy z amoniakiem',
        ])->assertOk()->json('search_event_id');

        foreach ([1, 2] as $ignored) {
            $this->postJson("/api/products/ai-search/{$eventId}/action", [
                'product_id' => $match->id,
                'action' => SearchEventAction::ACTION_OPEN,
                'position' => 1,
            ])->assertOk();
        }

        $this->assertSame(1, SearchEventAction::query()->count());
    }

    public function test_unknown_action_is_rejected(): void
    {
        Sanctum::actingAs(User::factory()->withRole('admin')->create());
        $match = $this->catalog();
        $this->mockLlm($match);

        $eventId = $this->postJson('/api/products/ai-search', [
            'query' => 'rękawice do pracy z amoniakiem',
        ])->assertOk()->json('search_event_id');

        $this->postJson("/api/products/ai-search/{$eventId}/action", [
            'product_id' => $match->id,
            'action' => 'sabotage',
        ])->assertStatus(422);
    }

    public function test_logging_can_be_switched_off(): void
    {
        config()->set('ai.search_events_enabled', false);
        Sanctum::actingAs(User::factory()->withRole('admin')->create());
        $match = $this->catalog();
        $this->mockLlm($match);

        $this->postJson('/api/products/ai-search', [
            'query' => 'rękawice do pracy z amoniakiem',
        ])->assertOk()->assertJsonPath('search_event_id', null);

        $this->assertSame(0, SearchEvent::query()->count());
    }
}
