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
        $this->assertStringContainsString('wampirki', mb_strtolower((string) $response->json('query')));
        $this->assertStringContainsString('powlekan', $phrases);
        $this->assertStringNotContainsString('uniwersaln', $phrases);
        $this->assertNotSame([], $response->json('parsed_intent.search_steps') ?? []);
    }

    public function test_wampirki_does_not_return_esd_fingertip_gloves(): void
    {
        Sanctum::actingAs(User::factory()->withRole('admin')->create());

        $urgent = Product::query()->create([
            'sku' => 'URGENT-1016',
            'name' => '1016 (NOWO)',
            'manufacturer' => 'URGENT',
            'category' => 'Rękawice',
            'description' => 'Rękawice dziane powlekane do oleju, ochrona przed cieczą.',
            'catalog_price_net' => 1.11,
            'purchase_price' => 1.11,
            'stock' => 20,
            'enrichment_status' => Product::ENRICHMENT_DONE,
            'enriched_at' => now(),
        ]);
        $esd = Product::query()->create([
            'sku' => 'PK404',
            'name' => 'RĘKAWICE ANTYSTATYCZNE WĘGLOWE nakrapiane PCV oraz PALCE POWLEKANE POLIURETANEM Made in Korea',
            'manufacturer' => 'POLROK',
            'category' => 'Rękawice',
            'description' => 'Rękawice antystatyczne ESD, dziane, palce powlekane poliuretanem.',
            'catalog_price_net' => 3.9,
            'purchase_price' => 3.43,
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
                    ['id' => $esd->id, 'score' => 86, 'reason' => 'dziane powlekane'],
                    ['id' => $urgent->id, 'score' => 80, 'reason' => 'Rękawice dziane powlekane do oleju'],
                ],
            ]);
        $this->app->instance(OpenAiCompatibleClient::class, $llm);

        $skus = $this->postJson('/api/products/ai-search', [
            'query' => 'Rękawice wampirki uniwersalne',
            'limit' => 5,
        ])
            ->assertOk()
            ->json('products');

        $this->assertContains('URGENT-1016', array_column($skus ?? [], 'sku'));
        $this->assertNotContains('PK404', array_column($skus ?? [], 'sku'));
    }

    public function test_wampirki_does_not_return_fully_coated_gloves(): void
    {
        Sanctum::actingAs(User::factory()->withRole('admin')->create());

        $palm = Product::query()->create([
            'sku' => 'URGENT-1016',
            'name' => '1016 (NOWO)',
            'manufacturer' => 'URGENT',
            'category' => 'Rękawice',
            'description' => 'Rękawice dziane powlekane do oleju, dłoń powlekana lateksem.',
            'catalog_price_net' => 1.11,
            'purchase_price' => 1.11,
            'stock' => 20,
            'enrichment_status' => Product::ENRICHMENT_DONE,
            'enriched_at' => now(),
        ]);
        $full = Product::query()->create([
            'sku' => 'NI155',
            'name' => 'RĘKAWICE Z GRUBEGO NITRYLU NA WKŁADZIE Z DŻERSEJU, POWLEKANE W CAŁOŚCI',
            'manufacturer' => 'Delta Plus',
            'category' => 'Rękawice',
            'description' => 'Rękawice całkowicie powlekane nitrylem, ochrona przed cieczą.',
            'catalog_price_net' => 9.87,
            'purchase_price' => 7.6,
            'stock' => 8,
            'enrichment_status' => Product::ENRICHMENT_DONE,
            'enriched_at' => now(),
        ]);
        Product::query()->create([
            'sku' => '58-270',
            'name' => 'Całkowicie powlekane rękawice z długim mankietem',
            'manufacturer' => 'Ansell',
            'category' => 'Rękawice',
            'description' => 'Rękawice chemiczne powlekane w całości, długi mankiet.',
            'catalog_price_net' => 20,
            'purchase_price' => 16,
            'stock' => 4,
            'enrichment_status' => Product::ENRICHMENT_DONE,
            'enriched_at' => now(),
        ]);

        $llm = Mockery::mock(OpenAiCompatibleClient::class);
        $llm->shouldReceive('chatJson')
            ->andReturn([
                'needed' => 'rękawice dzianinowe powlekane, ochrona przed cieczą',
                'search_phrases' => ['rękawice dzianinowe powlekane'],
                'matches' => [
                    ['id' => $full->id, 'score' => 90, 'reason' => 'powlekane'],
                    ['id' => $palm->id, 'score' => 70, 'reason' => 'dłoń powlekana'],
                ],
            ]);
        $this->app->instance(OpenAiCompatibleClient::class, $llm);

        $five = $this->postJson('/api/products/ai-search', [
            'query' => 'Rękawice wampirki uniwersalne',
            'limit' => 5,
        ])->assertOk();
        $forty = $this->postJson('/api/products/ai-search', [
            'query' => 'Rękawice wampirki uniwersalne',
            'limit' => 40,
        ])->assertOk();

        $fiveSkus = array_column($five->json('products') ?? [], 'sku');
        $fortySkus = array_column($forty->json('products') ?? [], 'sku');

        $this->assertContains('URGENT-1016', $fiveSkus);
        $this->assertNotContains('NI155', $fiveSkus);
        $this->assertNotContains('58-270', $fiveSkus);
        $this->assertSame($fiveSkus[0] ?? null, $fortySkus[0] ?? null);
        $this->assertLessThanOrEqual(5, count($fiveSkus));
        $this->assertSame(
            \App\Services\ProductAiSearchService::CATALOG_LIMIT,
            40,
        );
    }

    public function test_wampirki_does_not_return_disposable_cuffs_or_cold(): void
    {
        Sanctum::actingAs(User::factory()->withRole('admin')->create());

        Product::query()->create([
            'sku' => 'VE712GRG10',
            'name' => 'OPAKOWANIE 10 PAR RĘKAWIC DZIANYCH Z POLIESTRU, DŁOŃ POWLEKANA NITRYLEM',
            'manufacturer' => 'Canis',
            'category' => 'Rękawice',
            'description' => 'Rękawice dzianinowe powlekane nitrylem, dłoń powlekana.',
            'catalog_price_net' => 8,
            'purchase_price' => 4,
            'stock' => 40,
            'ppe_family' => 'gloves',
            'enrichment_status' => Product::ENRICHMENT_DONE,
            'enriched_at' => now(),
        ]);
        Product::query()->create([
            'sku' => '58-008',
            'name' => 'Rękawice nitrylowe nieflokowane',
            'manufacturer' => 'Ansell',
            'category' => 'Rękawice',
            'description' => 'Jednorazowe rękawice nitrylowe, ochrona przed cieczą.',
            'catalog_price_net' => 16,
            'purchase_price' => 13,
            'stock' => 10,
            'ppe_family' => 'gloves',
            'enrichment_status' => Product::ENRICHMENT_DONE,
            'enriched_at' => now(),
        ]);
        Product::query()->create([
            'sku' => 'PRIMACUFF35PO',
            'name' => '35CM CUT-RESISTANT KNITTED CUFFS',
            'manufacturer' => 'Rostaing',
            'category' => 'Rękawice',
            'description' => 'Knitted cuffs, cut protection.',
            'catalog_price_net' => 7,
            'purchase_price' => 3,
            'stock' => 5,
            'ppe_family' => 'gloves',
            'enrichment_status' => Product::ENRICHMENT_DONE,
            'enriched_at' => now(),
        ]);
        Product::query()->create([
            'sku' => 'ZPP25T',
            'name' => 'T6 COLD GLOVES 0°C POLYPRO BLUE',
            'manufacturer' => 'Rostaing',
            'category' => 'Rękawice',
            'description' => 'Cold gloves 0 C.',
            'catalog_price_net' => 5,
            'purchase_price' => 2,
            'stock' => 5,
            'ppe_family' => 'gloves',
            'enrichment_status' => Product::ENRICHMENT_DONE,
            'enriched_at' => now(),
        ]);
        Product::query()->create([
            'sku' => 'STOP-OILT',
            'name' => 'DOUBLE-ENDED SANDY NITRILE HANDLING GLOVES',
            'manufacturer' => 'Rostaing',
            'category' => 'Rękawice',
            'description' => 'Sandy nitrile handling gloves, ochrona przed cieczą.',
            'catalog_price_net' => 5,
            'purchase_price' => 2,
            'stock' => 5,
            'ppe_family' => 'gloves',
            'enrichment_status' => Product::ENRICHMENT_DONE,
            'enriched_at' => now(),
        ]);

        $llm = Mockery::mock(OpenAiCompatibleClient::class);
        $llm->shouldReceive('chatJson')
            ->andReturn([
                'needed' => 'rękawice dzianinowe powlekane',
                'search_steps' => ['rękawice', 'dzianinowe', 'dłoń powlekana'],
                'search_phrases' => ['rękawice dzianinowe powlekane'],
                'matches' => [],
            ]);
        $this->app->instance(OpenAiCompatibleClient::class, $llm);

        $skus = array_column($this->postJson('/api/products/ai-search', [
            'query' => 'Rękawice wampirki uniwersalne',
            'limit' => 10,
        ])->assertOk()->json('products') ?? [], 'sku');

        $this->assertContains('VE712GRG10', $skus);
        $this->assertNotContains('58-008', $skus);
        $this->assertNotContains('PRIMACUFF35PO', $skus);
        $this->assertNotContains('ZPP25T', $skus);
        $this->assertNotContains('STOP-OILT', $skus);
    }

    public function test_nitrile_light_does_not_return_knit_palm_coat(): void
    {
        Sanctum::actingAs(User::factory()->withRole('admin')->create());

        $coated = Product::query()->create([
            'sku' => 'R840',
            'name' => 'Dziane rękawice przeznaczone do prac lekkich z powlekaną nitrylem dłonią',
            'manufacturer' => 'Ansell',
            'category' => 'Rękawice',
            'description' => 'Rękawice do prac lekkich powlekane nitrylem.',
            'catalog_price_net' => 9.2,
            'purchase_price' => 7.27,
            'stock' => 5,
            'ppe_family' => 'gloves',
            'enrichment_status' => Product::ENRICHMENT_DONE,
            'enriched_at' => now(),
        ]);
        $nitrile = Product::query()->create([
            'sku' => '93-843',
            'name' => 'Niebieskie bezpudrowe rękawice nitrylowe',
            'manufacturer' => 'Ansell',
            'category' => 'Rękawice',
            'description' => 'Jednorazowe rękawice nitrylowe.',
            'catalog_price_net' => 12,
            'purchase_price' => 6,
            'stock' => 20,
            'ppe_family' => 'gloves',
            'enrichment_status' => Product::ENRICHMENT_DONE,
            'enriched_at' => now(),
        ]);

        $llm = Mockery::mock(OpenAiCompatibleClient::class);
        $llm->shouldReceive('chatJson')
            ->andReturn([
                'needed' => 'rękawice nitrylowe',
                'search_steps' => ['rękawice', 'nitrylowe', 'lekkie'],
                'search_phrases' => ['rękawice nitrylowe'],
                'matches' => [
                    ['id' => $coated->id, 'score' => 95, 'reason' => 'nitryl + lekkie'],
                    ['id' => $nitrile->id, 'score' => 70, 'reason' => 'nitrylowe'],
                ],
            ]);
        $this->app->instance(OpenAiCompatibleClient::class, $llm);

        $skus = array_column($this->postJson('/api/products/ai-search', [
            'query' => 'Rękawice nitrylowe lekkie',
            'limit' => 5,
        ])->assertOk()->json('products') ?? [], 'sku');

        $this->assertContains('93-843', $skus);
        $this->assertNotContains('R840', $skus);
    }

    public function test_pcv_long_gloves_are_found_in_catalog(): void
    {
        Sanctum::actingAs(User::factory()->withRole('admin')->create());

        $pvc = Product::query()->create([
            'sku' => 'A835',
            'name' => 'Rękawice PCV długie do łokcia',
            'manufacturer' => 'Portwest',
            'category' => 'Rękawice',
            'description' => 'Rękawice z PCV, mankiet do łokcia.',
            'catalog_price_net' => 12,
            'purchase_price' => 9.25,
            'currency' => 'PLN',
            'stock' => 8,
            'ppe_family' => 'gloves',
            'enrichment_status' => Product::ENRICHMENT_DONE,
            'enriched_at' => now(),
        ]);
        Product::query()->create([
            'sku' => '58-270',
            'name' => 'Całkowicie powlekane rękawice z długim mankietem',
            'manufacturer' => 'Ansell',
            'category' => 'Rękawice',
            'description' => 'Rękawice chemiczne powlekane nitrylem.',
            'catalog_price_net' => 20,
            'purchase_price' => 16,
            'currency' => 'PLN',
            'stock' => 4,
            'ppe_family' => 'gloves',
            'enrichment_status' => Product::ENRICHMENT_DONE,
            'enriched_at' => now(),
        ]);

        $llm = Mockery::mock(OpenAiCompatibleClient::class);
        $llm->shouldReceive('chatJson')
            ->andReturn([
                'needed' => 'rękawice PVC',
                'search_steps' => ['rękawice', 'PCV', 'długie'],
                'search_phrases' => ['rękawice PVC'],
                'matches' => [
                    ['id' => $pvc->id, 'score' => 88, 'reason' => 'PCV długie'],
                ],
            ]);
        $this->app->instance(OpenAiCompatibleClient::class, $llm);

        $skus = array_column($this->postJson('/api/products/ai-search', [
            'query' => 'Rękawice PCV długie do łokci',
            'limit' => 5,
        ])->assertOk()->json('products') ?? [], 'sku');

        $this->assertContains('A835', $skus);
        $this->assertNotContains('58-270', $skus);
    }

    public function test_qualifying_products_are_ordered_by_purchase_price(): void
    {
        Sanctum::actingAs(User::factory()->withRole('admin')->create());

        $expensive = Product::query()->create([
            'sku' => 'NIT-HI',
            'name' => 'Niebieskie bezpudrowe rękawice nitrylowe Ansell',
            'manufacturer' => 'Ansell',
            'category' => 'Rękawice',
            'description' => 'Jednorazowe rękawice nitrylowe.',
            'catalog_price_net' => 20,
            'purchase_price' => 12,
            'currency' => 'PLN',
            'stock' => 10,
            'ppe_family' => 'gloves',
            'enrichment_status' => Product::ENRICHMENT_DONE,
            'enriched_at' => now(),
        ]);
        $cheap = Product::query()->create([
            'sku' => 'NIT-LO',
            'name' => 'Niebieskie bezpudrowe rękawice nitrylowe Delta',
            'manufacturer' => 'Delta Plus',
            'category' => 'Rękawice',
            'description' => 'Jednorazowe rękawice nitrylowe.',
            'catalog_price_net' => 9,
            'purchase_price' => 4.5,
            'currency' => 'PLN',
            'stock' => 20,
            'ppe_family' => 'gloves',
            'enrichment_status' => Product::ENRICHMENT_DONE,
            'enriched_at' => now(),
        ]);

        $llm = Mockery::mock(OpenAiCompatibleClient::class);
        $llm->shouldReceive('chatJson')
            ->andReturn([
                'needed' => 'rękawice nitrylowe',
                'search_steps' => ['rękawice', 'nitrylowe'],
                'search_phrases' => ['rękawice nitrylowe'],
                'matches' => [
                    ['id' => $expensive->id, 'score' => 95, 'reason' => 'Ansell'],
                    ['id' => $cheap->id, 'score' => 70, 'reason' => 'nitrylowe'],
                ],
            ]);
        $this->app->instance(OpenAiCompatibleClient::class, $llm);

        $this->postJson('/api/products/ai-search', [
            'query' => 'Rękawice nitrylowe lekkie',
            'limit' => 5,
        ])
            ->assertOk()
            ->assertJsonPath('products.0.sku', 'NIT-LO')
            ->assertJsonPath('products.1.sku', 'NIT-HI');
    }
}
