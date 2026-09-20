<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Product;
use App\Models\User;
use App\Services\Ai\AiSettingsService;
use App\Services\Ai\OpenAiCompatibleClient;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Mockery;
use Tests\TestCase;

final class AdminAiTuningApiTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolesAndPermissionsSeeder::class);
    }

    public function test_admin_can_read_and_save_catalog_search_limit(): void
    {
        Sanctum::actingAs(User::factory()->withRole('admin')->create());

        $this->getJson('/api/admin/ai-tuning')
            ->assertOk()
            ->assertJsonPath('catalog_search_limit', AiSettingsService::CATALOG_SEARCH_LIMIT_DEFAULT)
            ->assertJsonPath('default', 40)
            ->assertJsonPath('min', 1)
            ->assertJsonPath('max', AiSettingsService::CATALOG_SEARCH_LIMIT_MAX);

        $this->putJson('/api/admin/ai-tuning', ['catalog_search_limit' => 12])
            ->assertOk()
            ->assertJsonPath('catalog_search_limit', 12);

        $this->getJson('/api/admin/ai-tuning')
            ->assertOk()
            ->assertJsonPath('catalog_search_limit', 12);
    }

    public function test_admin_can_read_and_save_match_thresholds(): void
    {
        Sanctum::actingAs(User::factory()->withRole('admin')->create());

        $this->getJson('/api/admin/ai-tuning')
            ->assertOk()
            ->assertJsonPath('match_apply_score', AiSettingsService::MATCH_APPLY_SCORE_DEFAULT)
            ->assertJsonPath('match_substitute_score', AiSettingsService::MATCH_SUBSTITUTE_SCORE_DEFAULT)
            ->assertJsonPath('match_min_score', AiSettingsService::MATCH_MIN_SCORE_DEFAULT)
            ->assertJsonPath('match_allow_catalog_rows', false);

        $this->putJson('/api/admin/ai-tuning', [
            'catalog_search_limit' => 40,
            'match_apply_score' => 30,
            'match_substitute_score' => 45,
            'match_min_score' => 50,
            'match_allow_catalog_rows' => true,
        ])
            ->assertOk()
            ->assertJsonPath('match_apply_score', 30)
            ->assertJsonPath('match_min_score', 50)
            ->assertJsonPath('match_allow_catalog_rows', true);

        $settings = app(AiSettingsService::class);
        $this->assertSame(30, $settings->matchApplyScore());
        $this->assertSame(45, $settings->matchSubstituteScore());
        $this->assertSame(50, $settings->matchMinScore());
        $this->assertTrue($settings->matchAllowsCatalogRows());
    }

    public function test_match_thresholds_reject_out_of_range_and_bad_order(): void
    {
        Sanctum::actingAs(User::factory()->withRole('admin')->create());

        $this->putJson('/api/admin/ai-tuning', [
            'catalog_search_limit' => 40,
            'match_apply_score' => 0,
        ])->assertStatus(422);

        $this->putJson('/api/admin/ai-tuning', [
            'catalog_search_limit' => 40,
            'match_min_score' => 100,
        ])->assertStatus(422);

        // Zamiennik nie może być łatwiejszy niż zwykłe dopasowanie.
        $this->putJson('/api/admin/ai-tuning', [
            'catalog_search_limit' => 40,
            'match_apply_score' => 60,
            'match_substitute_score' => 50,
        ])->assertStatus(422);
    }

    public function test_saving_only_the_limit_keeps_tuned_thresholds(): void
    {
        Sanctum::actingAs(User::factory()->withRole('admin')->create());

        $this->putJson('/api/admin/ai-tuning', [
            'catalog_search_limit' => 40,
            'match_apply_score' => 25,
        ])->assertOk();

        $this->putJson('/api/admin/ai-tuning', ['catalog_search_limit' => 10])
            ->assertOk()
            ->assertJsonPath('catalog_search_limit', 10)
            ->assertJsonPath('match_apply_score', 25);
    }

    public function test_catalog_search_limit_rejects_out_of_range(): void
    {
        Sanctum::actingAs(User::factory()->withRole('admin')->create());

        $this->putJson('/api/admin/ai-tuning', ['catalog_search_limit' => 0])
            ->assertStatus(422);
        $this->putJson('/api/admin/ai-tuning', ['catalog_search_limit' => 81])
            ->assertStatus(422);
    }

    public function test_handlowiec_cannot_access_ai_tuning(): void
    {
        Sanctum::actingAs(User::factory()->withRole('handlowiec')->create());

        $this->getJson('/api/admin/ai-tuning')->assertForbidden();
        $this->putJson('/api/admin/ai-tuning', ['catalog_search_limit' => 10])->assertForbidden();
    }

    public function test_catalog_search_uses_configured_limit_when_client_omits_it(): void
    {
        Sanctum::actingAs(User::factory()->withRole('admin')->create());

        $this->putJson('/api/admin/ai-tuning', ['catalog_search_limit' => 2])->assertOk();

        $first = Product::query()->create([
            'sku' => 'GLOVE-1',
            'name' => 'Rękawice dzianinowe powlekane dłoń',
            'manufacturer' => 'URGENT',
            'category' => 'Rękawice',
            'description' => 'Rękawice dziane powlekane do oleju, dłoń powlekana lateksem.',
            'catalog_price_net' => 1,
            'purchase_price' => 1,
            'stock' => 10,
            'enrichment_status' => Product::ENRICHMENT_DONE,
            'enriched_at' => now(),
        ]);
        $second = Product::query()->create([
            'sku' => 'GLOVE-2',
            'name' => 'Rękawice dzianinowe powlekane nitrylem',
            'manufacturer' => 'Canis',
            'category' => 'Rękawice',
            'description' => 'Rękawice dzianinowe powlekane nitrylem, ochrona przed cieczą.',
            'catalog_price_net' => 2,
            'purchase_price' => 2,
            'stock' => 10,
            'enrichment_status' => Product::ENRICHMENT_DONE,
            'enriched_at' => now(),
        ]);
        Product::query()->create([
            'sku' => 'GLOVE-3',
            'name' => 'Rękawice dzianinowe powlekane lateksem',
            'manufacturer' => 'Canis',
            'category' => 'Rękawice',
            'description' => 'Rękawice dzianinowe powlekane, ochrona przed cieczą.',
            'catalog_price_net' => 3,
            'purchase_price' => 3,
            'stock' => 10,
            'enrichment_status' => Product::ENRICHMENT_DONE,
            'enriched_at' => now(),
        ]);

        $llm = Mockery::mock(OpenAiCompatibleClient::class);
        $llm->shouldReceive('chatJson')
            ->andReturn([
                'needed' => 'rękawice dzianinowe powlekane, ochrona przed cieczą',
                'search_phrases' => ['rękawice dzianinowe powlekane'],
                'matches' => [
                    ['id' => $first->id, 'score' => 90, 'reason' => 'dłoń'],
                    ['id' => $second->id, 'score' => 80, 'reason' => 'nitryl'],
                ],
            ]);
        $this->app->instance(OpenAiCompatibleClient::class, $llm);

        $this->postJson('/api/products/ai-search', [
            'query' => 'Rękawice wampirki uniwersalne',
        ])
            ->assertOk()
            ->assertJsonPath('total', 2);
    }
}
