<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\AiSetting;
use App\Models\Client;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\Tender;
use App\Models\TenderItem;
use App\Models\User;
use App\Services\Ai\OpenAiCompatibleClient;
use App\Services\ProductAiSearchService;
use App\Services\ProductMatchService;
use App\Support\PpeAssortment;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Http;
use Laravel\Sanctum\Sanctum;
use Mockery;
use Tests\TestCase;

/**
 * Karta scalona z kolorów („ARMEN 9007 S1” z wariantami 1010 i 6660) pod „ARMEN 9007 1010 S1” to żądany wariant
 * do wyboru w ofercie — automat przetargu zapisuje ją jak kartę z kodem w nazwie. Ta sama karta bez wierszy wariantów
 * zostaje, jak dotąd, propozycją innego wariantu (etap A łączenia wariantów kolorystycznych, 28.09.2026).
 */
final class TenderVariantCardMatchTest extends TestCase
{
    use RefreshDatabase;

    private const REQUIREMENT = 'buty firmy ARTRA model ARMEN 9007 1010 S1';

    protected function setUp(): void
    {
        parent::setUp();
        Http::fake();
        $this->seed(RolesAndPermissionsSeeder::class);
        Sanctum::actingAs(User::factory()->withRole('admin')->create());
        AiSetting::query()->create([
            'enabled' => true,
            'provider' => 'openai_compatible',
            'base_url' => 'https://api.openai.com/v1',
            'api_key' => 'sk-test-key-1234567890',
            'model' => 'gpt-4o-mini',
            'timeout_seconds' => 60,
            'temperature' => 0.1,
        ]);
        $llm = Mockery::mock(OpenAiCompatibleClient::class);
        $llm->shouldReceive('chatJson')->andReturn(['matches' => []]);
        $llm->shouldReceive('chatJsonMany')->andReturnUsing(
            static fn (array $sets): array => array_fill(0, count($sets), ['matches' => []])
        );
        $this->app->instance(OpenAiCompatibleClient::class, $llm);
    }

    public function test_card_whose_variant_rows_carry_the_colour_code_is_saved(): void
    {
        $card = $this->shoe('ARMEN 9007 S1');
        $this->variant($card, 'ARMEN-9007-1010-42', 'czarny 42');
        $this->variant($card, 'ARMEN-9007-6660-42', 'zielony 42');

        $item = $this->match();

        $this->assertSame($card->id, $item->main_product_id);
        $this->assertGreaterThanOrEqual($this->minScore(), (int) $item->ai_match_percent);
        $this->assertNotSame(ProductMatchService::PROPOSAL, $item->ai_match_reasons[0]['code'] ?? null);
    }

    public function test_same_card_without_variant_rows_stays_an_other_variant_proposal(): void
    {
        $card = $this->shoe('ARMEN 9007 S1');
        $this->variant($card, 'ARMEN-9007-1010-42', 'czarny 42', removed: true);

        $item = $this->match();

        $this->assertSame($card->id, $item->main_product_id);
        $this->assertLessThan($this->minScore(), (int) $item->ai_match_percent);
        $this->assertSame(ProductMatchService::PROPOSAL, $item->ai_match_reasons[0]['code'] ?? null);
    }

    public function test_model_code_lookup_falls_back_to_active_variant_codes(): void
    {
        $card = $this->shoe('ARMEN 9007 S1');
        $this->variant($card, 'ARMEN-9007-1010-42', 'czarny 42');
        $gone = $this->shoe('ARMEN 9007 S3');
        $this->variant($gone, 'ARMEN-9007-1010-43', 'czarny 43', removed: true);

        $this->assertSame([$card->id], $this->byModelCodes(['armen90071010'])->pluck('id')->all());
        $this->assertSame([], $this->byModelCodes(['armen90076660'])->all());
    }

    /**
     * @param  list<string>  $codes
     * @return Collection<int, Product>
     */
    private function byModelCodes(array $codes): Collection
    {
        $service = app(ProductAiSearchService::class);

        return (new \ReflectionMethod($service, 'productsByModelCodes'))->invoke($service, $codes, 10);
    }

    private function minScore(): int
    {
        return app(ProductMatchService::class)->minMatchScore();
    }

    private function match(): TenderItem
    {
        $tender = Tender::query()->create([
            'number' => 'PRZ/WARIANT/1',
            'title' => 'Wariant',
            'client_id' => Client::query()->create(['name' => 'Klient'])->id,
            'owner_id' => User::factory()->create()->id,
            'status' => 'wycena',
            'ai_percent' => 0,
            'last_activity_at' => now(),
        ]);
        $item = TenderItem::query()->create([
            'tender_id' => $tender->id,
            'line_no' => 1,
            'requirement' => self::REQUIREMENT,
            'quantity' => 1,
            'status' => 'brak',
        ]);

        $this->postJson("/api/tenders/{$tender->id}/match", ['only_empty' => false])->assertOk();

        return $item->fresh(['mainProduct']);
    }

    private function shoe(string $sku): Product
    {
        return Product::query()->create([
            'sku' => $sku,
            'name' => $sku,
            'manufacturer' => 'ARTRA',
            'category' => 'Obuwie',
            'description' => 'Półbuty bezpieczne '.$sku.' z podnoskiem.',
            'norms' => 'EN ISO 20345 S1',
            'ppe_family' => PpeAssortment::FAMILY_FOOTWEAR,
            'catalog_price_net' => 45,
            'purchase_price' => 30,
            'currency' => 'PLN',
            'stock' => 5,
            'enrichment_status' => Product::ENRICHMENT_DONE,
            'enriched_at' => now()->subYear(),
        ]);
    }

    private function variant(Product $card, string $sku, string $label, bool $removed = false): ProductVariant
    {
        return ProductVariant::query()->create([
            'product_id' => $card->id, 'kind' => ProductVariant::KIND_SIZE, 'source' => 'b2b:1', 'remote_id' => $sku,
            'sku' => $sku, 'label' => $label, 'purchase_price' => 30, 'currency' => 'PLN',
            'removed_at' => $removed ? now() : null,
        ]);
    }
}
