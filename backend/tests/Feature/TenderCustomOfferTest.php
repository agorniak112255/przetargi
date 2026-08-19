<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\AiSetting;
use App\Models\Client;
use App\Models\Product;
use App\Models\Tender;
use App\Models\TenderItem;
use App\Models\User;
use App\Services\Ai\OpenAiCompatibleClient;
use App\Services\TenderOfferExportService;
use Illuminate\Support\Facades\Http;
use Mockery;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

final class TenderCustomOfferTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolesAndPermissionsSeeder::class);
    }

    public function test_custom_offer_saves_name_url_price_and_counts_in_coverage(): void
    {
        Sanctum::actingAs(User::factory()->withRole('admin')->create());

        $tender = $this->makeTender();
        $item = TenderItem::query()->create([
            'tender_id' => $tender->id,
            'line_no' => 1,
            'requirement' => 'Gaśnica proszkowa 4 kg',
            'quantity' => 2,
            'status' => 'brak',
        ]);

        $this->patchJson("/api/tenders/{$tender->id}/items/{$item->id}", [
            'custom_name' => 'Gaśnica GP-4x ABC',
            'custom_url' => 'https://sklep.example/produkt/gasnica-4kg',
            'offer_price' => 89.5,
            'quantity' => 2,
        ])
            ->assertOk()
            ->assertJsonPath('custom_name', 'Gaśnica GP-4x ABC')
            ->assertJsonPath('custom_url', 'https://sklep.example/produkt/gasnica-4kg')
            ->assertJsonPath('match_source', 'custom')
            ->assertJsonPath('status', 'matched')
            ->assertJsonPath('main_product_id', null);

        $item->refresh();
        $this->assertSame('89.50', (string) $item->offer_price);

        $this->getJson("/api/tenders/{$tender->id}/coverage")
            ->assertOk()
            ->assertJsonPath('without_product', 0)
            ->assertJsonPath('without_price', 0)
            ->assertJsonPath('with_product', 1);

        $rows = $this->app->make(TenderOfferExportService::class)->rows($tender->fresh());
        $this->assertSame('Gaśnica GP-4x ABC', $rows[0]['product_name']);
        $this->assertSame('https://sklep.example/produkt/gasnica-4kg', $rows[0]['custom_url']);
    }

    public function test_rejects_custom_url_without_http(): void
    {
        Sanctum::actingAs(User::factory()->withRole('admin')->create());
        $tender = $this->makeTender();
        $item = TenderItem::query()->create([
            'tender_id' => $tender->id,
            'line_no' => 1,
            'requirement' => 'Gaśnica',
            'quantity' => 1,
            'status' => 'brak',
        ]);

        $this->patchJson("/api/tenders/{$tender->id}/items/{$item->id}", [
            'custom_name' => 'Gaśnica',
            'custom_url' => 'sklep.example/gasnica',
        ])->assertStatus(422);
    }

    public function test_rematch_does_not_wipe_custom_offer(): void
    {
        Sanctum::actingAs(User::factory()->withRole('admin')->create());
        $tender = $this->makeTender();
        $item = TenderItem::query()->create([
            'tender_id' => $tender->id,
            'line_no' => 1,
            'requirement' => 'Czapka ocieplana pod hełm',
            'quantity' => 1,
            'status' => 'matched',
            'custom_name' => 'Czapka polarowa',
            'custom_url' => 'https://sklep.example/czapka',
            'offer_price' => 25,
            'match_source' => 'custom',
        ]);

        $this->postJson("/api/tenders/{$tender->id}/match", ['only_empty' => true])
            ->assertOk();
        $this->postJson("/api/tenders/{$tender->id}/match", ['only_empty' => false])
            ->assertOk();

        $item->refresh();
        $this->assertSame('Czapka polarowa', $item->custom_name);
        $this->assertSame('https://sklep.example/czapka', $item->custom_url);
        $this->assertSame('custom', $item->match_source);
        $this->assertSame('matched', $item->status);
    }

    public function test_rematch_all_reprocesses_existing_catalog_product(): void
    {
        Sanctum::actingAs(User::factory()->withRole('admin')->create());
        AiSetting::query()->create([
            'enabled' => true,
            'provider' => 'openai_compatible',
            'base_url' => 'https://api.openai.com/v1',
            'api_key' => 'sk-test-key-1234567890',
            'model' => 'gpt-4o-mini',
            'timeout_seconds' => 60,
            'temperature' => 0.1,
            'tavily_api_key' => 'tvly-test',
        ]);
        $llm = Mockery::mock(OpenAiCompatibleClient::class);
        $llm->shouldReceive('chatJson')->andReturn(
            [
                'needed' => 'kalesony bawelniane meskie',
                'search_phrases' => ['kalesony', 'bawelniane'],
            ],
            ['matches' => []],
        );
        $this->app->instance(OpenAiCompatibleClient::class, $llm);
        Http::fake();

        $tender = $this->makeTender();
        $product = Product::query()->create([
            'sku' => '07-755-XXXXL',
            'name' => 'GVS Heavy Duty Blast Suit - XXXX Large',
            'manufacturer' => 'GVS',
            'category' => 'odziez',
            'description' => 'Kombinezon ochronny blast suit heavy duty.',
            'catalog_price_net' => 100,
            'purchase_price' => 80,
            'stock' => 1,
        ]);
        $item = TenderItem::query()->create([
            'tender_id' => $tender->id,
            'line_no' => 1,
            'requirement' => 'KALESONY bawełniane (100% bawełny) męskie (niebieskie) rozmiar od S do XXXXL',
            'quantity' => 1,
            'status' => 'matched',
            'main_product_id' => $product->id,
            'ai_match_percent' => 71,
            'match_source' => 'ai',
        ]);

        $this->postJson("/api/tenders/{$tender->id}/match", ['only_empty' => true])
            ->assertOk();
        $item->refresh();
        $this->assertSame($product->id, $item->main_product_id);

        $this->postJson("/api/tenders/{$tender->id}/match", ['only_empty' => false])
            ->assertOk();
        $item->refresh();
        $this->assertNull($item->main_product_id);
    }

    public function test_rematch_item_ids_only_touches_listed_items(): void
    {
        Sanctum::actingAs(User::factory()->withRole('admin')->create());
        AiSetting::query()->create([
            'enabled' => true,
            'provider' => 'openai_compatible',
            'base_url' => 'https://api.openai.com/v1',
            'api_key' => 'sk-test-key-1234567890',
            'model' => 'gpt-4o-mini',
            'timeout_seconds' => 60,
            'temperature' => 0.1,
            'tavily_api_key' => 'tvly-test',
        ]);
        $llm = Mockery::mock(OpenAiCompatibleClient::class);
        $llm->shouldReceive('chatJson')->andReturn(
            [
                'needed' => 'kalesony',
                'search_phrases' => ['kalesony'],
            ],
            ['matches' => []],
        );
        $this->app->instance(OpenAiCompatibleClient::class, $llm);
        Http::fake();

        $tender = $this->makeTender();
        $wrong = Product::query()->create([
            'sku' => '07-755-XXXXL',
            'name' => 'GVS Heavy Duty Blast Suit - XXXX Large',
            'manufacturer' => 'GVS',
            'description' => 'Kombinezon ochronny blast suit heavy duty.',
            'catalog_price_net' => 100,
            'purchase_price' => 80,
            'stock' => 1,
        ]);
        $keep = Product::query()->create([
            'sku' => 'HAT-KEEP',
            'name' => 'Czapka ocieplana',
            'manufacturer' => 'X',
            'description' => 'Czapka ocieplana pod hełm, polar.',
            'catalog_price_net' => 20,
            'purchase_price' => 12,
            'stock' => 5,
        ]);
        $filtered = TenderItem::query()->create([
            'tender_id' => $tender->id,
            'line_no' => 1,
            'requirement' => 'KALESONY bawełniane męskie rozmiar XXXXL',
            'quantity' => 1,
            'status' => 'matched',
            'main_product_id' => $wrong->id,
            'ai_match_percent' => 71,
            'match_source' => 'ai',
        ]);
        $other = TenderItem::query()->create([
            'tender_id' => $tender->id,
            'line_no' => 2,
            'requirement' => 'Czapka ocieplana pod hełm',
            'quantity' => 1,
            'status' => 'matched',
            'main_product_id' => $keep->id,
            'ai_match_percent' => 85,
            'match_source' => 'ai',
        ]);

        $this->postJson("/api/tenders/{$tender->id}/match", [
            'only_empty' => false,
            'item_ids' => [$filtered->id],
        ])->assertOk();

        $filtered->refresh();
        $other->refresh();
        $this->assertNull($filtered->main_product_id);
        $this->assertSame($keep->id, $other->main_product_id);
    }

    public function test_catalog_product_clears_custom_offer(): void
    {
        Sanctum::actingAs(User::factory()->withRole('admin')->create());
        $tender = $this->makeTender();
        $product = Product::query()->create([
            'sku' => 'HAT-1',
            'name' => 'Czapka ocieplana',
            'manufacturer' => 'X',
            'description' => 'Czapka ocieplana pod hełm, polar.',
            'catalog_price_net' => 20,
            'purchase_price' => 12,
            'stock' => 5,
        ]);
        $item = TenderItem::query()->create([
            'tender_id' => $tender->id,
            'line_no' => 1,
            'requirement' => 'Czapka ocieplana pod hełm',
            'quantity' => 1,
            'status' => 'matched',
            'custom_name' => 'Czapka ze sklepu',
            'custom_url' => 'https://sklep.example/czapka',
            'offer_price' => 25,
            'match_source' => 'custom',
        ]);

        $this->patchJson("/api/tenders/{$tender->id}/items/{$item->id}", [
            'main_product_id' => $product->id,
            'quantity' => 1,
        ])
            ->assertOk()
            ->assertJsonPath('main_product_id', $product->id)
            ->assertJsonPath('custom_name', null)
            ->assertJsonPath('custom_url', null);

        $item->refresh();
        $this->assertNull($item->custom_name);
        $this->assertSame('HAT-1', $item->mainProduct?->sku);
    }

    public function test_no_catalog_match_does_not_store_fake_percent(): void
    {
        Sanctum::actingAs(User::factory()->withRole('admin')->create());
        AiSetting::query()->create([
            'enabled' => true,
            'provider' => 'openai_compatible',
            'base_url' => 'https://api.openai.com/v1',
            'api_key' => 'sk-test-key-1234567890',
            'model' => 'gpt-4o-mini',
            'timeout_seconds' => 60,
            'temperature' => 0.1,
            'tavily_api_key' => 'tvly-test',
        ]);
        $llm = Mockery::mock(OpenAiCompatibleClient::class);
        $llm->shouldReceive('chatJson')->andReturn(
            [
                'needed' => 'czapka ocieplana pod hełm',
                'search_phrases' => ['czapka ocieplana', 'kominiarka'],
            ],
            ['matches' => []],
        );
        $this->app->instance(OpenAiCompatibleClient::class, $llm);
        Http::fake([
            'api.tavily.com/search' => Http::response([
                'results' => [[
                    'url' => 'https://sklep.example/czapka-polarowa',
                    'title' => 'Czapka polarowa pod hełm',
                ]],
            ], 200),
        ]);

        $tender = $this->makeTender();
        $item = TenderItem::query()->create([
            'tender_id' => $tender->id,
            'line_no' => 1,
            'requirement' => 'Czapka ocieplana pod hełm do pracy',
            'quantity' => 1,
            'status' => 'brak',
        ]);

        $this->postJson("/api/tenders/{$tender->id}/items/{$item->id}/match", ['force' => true])
            ->assertOk()
            ->assertJsonPath('matched', false);

        $item->refresh();
        $this->assertNull($item->main_product_id);
        $this->assertNull($item->ai_match_percent);
        $this->assertSame('external', $item->match_source);
    }

    public function test_bulk_saves_custom_offer(): void
    {
        Sanctum::actingAs(User::factory()->withRole('admin')->create());
        $tender = $this->makeTender();
        $item = TenderItem::query()->create([
            'tender_id' => $tender->id,
            'line_no' => 1,
            'requirement' => 'Czapka ocieplana',
            'quantity' => 3,
            'status' => 'brak',
        ]);

        $this->postJson("/api/tenders/{$tender->id}/items/bulk", [
            'items' => [[
                'id' => $item->id,
                'main_product_id' => null,
                'quantity' => 3,
                'offer_price' => 19.9,
                'custom_name' => 'Czapka polarowa',
                'custom_url' => 'https://sklep.example/czapka',
            ]],
        ])->assertOk();

        $item->refresh();
        $this->assertSame('Czapka polarowa', $item->custom_name);
        $this->assertSame('https://sklep.example/czapka', $item->custom_url);
        $this->assertSame('matched', $item->status);
        $this->assertSame('custom', $item->match_source);
    }

    private function makeTender(): Tender
    {
        $owner = User::factory()->create();

        return Tender::query()->create([
            'number' => 'PRZ/CUST/1',
            'title' => 'Własna oferta',
            'client_id' => Client::query()->create(['name' => 'K'])->id,
            'owner_id' => $owner->id,
            'status' => 'wycena',
            'ai_percent' => 0,
            'last_activity_at' => now(),
        ]);
    }
}
