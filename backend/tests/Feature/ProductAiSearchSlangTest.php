<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Product;
use App\Models\User;
use App\Services\Ai\OpenAiCompatibleClient;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Mockery;
use Tests\TestCase;

final class ProductAiSearchSlangTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolesAndPermissionsSeeder::class);
    }

    public function test_wampirki_search_uses_note_and_drops_universal_word_hits(): void
    {
        Sanctum::actingAs(User::factory()->withRole('admin')->create());

        $gloves = Product::query()->create([
            'sku' => 'VE712GRG10',
            'name' => 'OPAKOWANIE 10 PAR RĘKAWIC DZIANYCH Z POLIESTRU, DŁOŃ POWLEKANA NITRYLEM',
            'manufacturer' => 'Canis',
            'category' => 'Rękawice',
            'description' => 'Rękawice dzianinowe powlekane nitrylem, ochrona przed cieczą.',
            'catalog_price_net' => 8,
            'purchase_price' => 4,
            'stock' => 40,
            'enrichment_status' => Product::ENRICHMENT_DONE,
            'enriched_at' => now(),
        ]);
        $stretchers = Product::query()->create([
            'sku' => 'TC104',
            'name' => 'NOSZE RATOWNICZE UNIWERSALNE Z REGULOWANYMI TASMAMI',
            'manufacturer' => 'Delta Plus',
            'description' => null,
            'catalog_price_net' => 400,
            'purchase_price' => 280,
            'stock' => 2,
            'enrichment_status' => Product::ENRICHMENT_NONE,
        ]);
        $arm = Product::query()->create([
            'sku' => '1259913',
            'name' => '3M ramię do maszyny pilniczkowej (do naroży - uniwersalne) 13mm x 457mm',
            'manufacturer' => '3M',
            'description' => null,
            'catalog_price_net' => 90,
            'purchase_price' => 60,
            'stock' => 1,
            'enrichment_status' => Product::ENRICHMENT_NONE,
        ]);

        $llm = Mockery::mock(OpenAiCompatibleClient::class);
        $llm->shouldReceive('chatJson')
            ->andReturn([
                'needed' => 'rękawice wampirki uniwersalne',
                'search_phrases' => ['uniwersalne', 'wampirki'],
                'matches' => [
                    ['id' => $stretchers->id, 'score' => 66, 'reason' => 'uniwersalne'],
                    ['id' => $arm->id, 'score' => 66, 'reason' => 'uniwersalne'],
                    ['id' => $gloves->id, 'score' => 65, 'reason' => 'rękawice'],
                ],
            ]);
        $this->app->instance(OpenAiCompatibleClient::class, $llm);

        $response = $this->postJson('/api/products/ai-search', [
            'query' => 'Rękawice wampirki uniwersalne',
            'limit' => 5,
        ])->assertOk();

        $skus = array_column($response->json('products') ?? [], 'sku');
        $phrases = mb_strtolower(implode(' ', $response->json('search_phrases') ?? []));

        $this->assertContains('VE712GRG10', $skus);
        $this->assertNotContains('TC104', $skus);
        $this->assertNotContains('1259913', $skus);
        $this->assertStringContainsString('ciecz', mb_strtolower((string) $response->json('needed')));
        $this->assertStringContainsString('powlekan', $phrases);
        $this->assertStringNotContainsString('uniwersaln', $phrases);
    }
}
