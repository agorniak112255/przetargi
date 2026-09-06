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
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Laravel\Sanctum\Sanctum;
use Mockery;
use Tests\TestCase;

/**
 * Progi z panelu „Strojenie AI” faktycznie sterują dopasowaniem SIWZ: domyślnie
 * pozycja zostaje pusta, a po poluzowaniu ustawień dostaje kartę z listy katalogowej.
 */
final class TenderMatchThresholdsTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolesAndPermissionsSeeder::class);
        Sanctum::actingAs(User::factory()->withRole('admin')->create());
        Http::fake();
    }

    private function settings(array $extra = []): void
    {
        AiSetting::query()->create([
            'enabled' => true,
            'provider' => 'openai_compatible',
            'base_url' => 'https://api.openai.com/v1',
            'api_key' => 'sk-test-key-1234567890',
            'model' => 'gpt-4o-mini',
            'timeout_seconds' => 60,
            'temperature' => 0.1,
            'tavily_api_key' => 'tvly-test',
            ...$extra,
        ]);
    }

    private function item(): TenderItem
    {
        $tender = Tender::query()->create([
            'number' => 'PRZ/PROG/1',
            'title' => 'Progi dopasowania',
            'client_id' => Client::query()->create(['name' => 'K'])->id,
            'owner_id' => User::factory()->create()->id,
            'status' => 'wycena',
            'ai_percent' => 0,
            'last_activity_at' => now(),
        ]);
        // Karta tego samego rodzaju (odzież), ale bez rzeczownika z wymagania —
        // model jej nie wskaże, więc może wejść wyłącznie z zapasowej listy katalogowej.
        Product::query()->create([
            'sku' => 'ODZ-1',
            'name' => 'ODZ-1',
            'manufacturer' => 'Urgent',
            'category' => 'Odzież robocza',
            'description' => 'Wyrób dziewiarski z bawełny, kolor niebieski, rozmiary od S do XXXXL.',
            'catalog_price_net' => 100,
            'purchase_price' => 80,
            'stock' => 1,
            'enrichment_status' => Product::ENRICHMENT_DONE,
            'enriched_at' => now(),
        ]);

        return TenderItem::query()->create([
            'tender_id' => $tender->id,
            'line_no' => 1,
            'requirement' => 'KALESONY bawełniane (100% bawełny) męskie rozmiar od S do XXXXL',
            'quantity' => 1,
            'status' => 'nowa',
        ]);
    }

    private function mockEmptyRanking(): void
    {
        $llm = Mockery::mock(OpenAiCompatibleClient::class);
        $llm->shouldReceive('chatJson')->andReturn([
            'needed' => 'kalesony bawelniane meskie',
            'search_phrases' => ['kalesony', 'bawelniane'],
            'matches' => [],
        ]);
        $this->app->instance(OpenAiCompatibleClient::class, $llm);
    }

    public function test_by_default_catalog_row_does_not_fill_the_line(): void
    {
        $this->settings();
        $item = $this->item();
        $this->mockEmptyRanking();

        $this->postJson("/api/tenders/{$item->tender_id}/match", ['only_empty' => false])->assertOk();

        $this->assertNull($item->refresh()->main_product_id);
    }

    public function test_enabling_catalog_rows_fills_the_line(): void
    {
        $this->settings(['match_allow_catalog_rows' => true]);
        $item = $this->item();
        $this->mockEmptyRanking();

        $this->postJson("/api/tenders/{$item->tender_id}/match", ['only_empty' => false])->assertOk();

        $this->assertNotNull($item->refresh()->main_product_id);
    }
}
