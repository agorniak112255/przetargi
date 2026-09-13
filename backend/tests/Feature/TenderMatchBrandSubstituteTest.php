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
use Laravel\Sanctum\Sanctum;
use Mockery;
use Tests\TestCase;

final class TenderMatchBrandSubstituteTest extends TestCase
{
    use RefreshDatabase;

    private const CERVA_BOOTS = 'BUTY gumowe DAMSKIE antyelektrostatyczne rozm. 35-41 TRONCHETTO OB. SRA prod.CERVA · EN ISO 20347';

    protected function setUp(): void
    {
        parent::setUp();
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
    }

    public function test_absent_manufacturer_saves_substitute_from_ai_at_fifty_five_percent(): void
    {
        $sub = Product::query()->create([
            'sku' => 'ESD-SUB-1',
            'name' => 'Kalosze damskie gumowe ESD',
            'manufacturer' => 'KCL',
            'category' => 'Obuwie',
            'ppe_family' => 'footwear',
            'norms' => 'ESD EN 1149',
            'description' => 'Kalosze antyelektrostatyczne gumowe.',
            'catalog_price_net' => 100,
            'purchase_price' => 80,
            'stock' => 5,
            'enrichment_status' => Product::ENRICHMENT_DONE,
        ]);

        $llm = Mockery::mock(OpenAiCompatibleClient::class);
        $llm->shouldReceive('chatJson')->andReturn([
            'needed' => 'buty gumowe damskie antyelektrostatyczne',
            'search_phrases' => ['buty gumowe', 'antyelektrostatyczne'],
            'constraints' => ['antyelektrostatyczne'],
            'matches' => [
                ['id' => $sub->id, 'score' => 82, 'reason' => 'ESD obuwie damskie'],
            ],
        ]);
        $this->app->instance(OpenAiCompatibleClient::class, $llm);

        $owner = User::factory()->create();
        $tender = Tender::query()->create([
            'number' => 'PRZ/SUB/1',
            'title' => 'Test',
            'client_id' => Client::query()->create(['name' => 'K'])->id,
            'owner_id' => $owner->id,
            'status' => 'wycena',
            'ai_percent' => 0,
            'last_activity_at' => now(),
        ]);
        $item = TenderItem::query()->create([
            'tender_id' => $tender->id,
            'line_no' => 77,
            'requirement' => self::CERVA_BOOTS,
            'quantity' => 1,
            'status' => 'brak',
        ]);

        $this->postJson("/api/tenders/{$tender->id}/match", ['only_empty' => true])
            ->assertOk()
            ->assertJsonPath('matched', 1)
            ->assertJsonPath('changed', 1);

        $item->refresh();
        $this->assertSame($sub->id, $item->main_product_id);
        $this->assertSame('ai_substitute', $item->match_source);
        $this->assertGreaterThanOrEqual(55, (int) $item->ai_match_percent);
        $this->assertSame(
            'brand_substitute',
            $item->ai_match_reasons[0]['code'] ?? null,
        );
    }

    /**
     * Poz. 14 z produkcji: pochłaniacz A2 „(5000 ppm)” trafiał we właściwą kartę S565A202 jako
     * `ai_substitute` 99% „Zamiennik — inna marka/model niż w SIWZ”, bo „5000” uchodziło za kod
     * modelu. Opis bez marki i modelu nie ma czego zastępować — to zwykłe trafienie modelu.
     */
    public function test_descriptive_line_without_brand_or_model_is_plain_ai_match_not_substitute(): void
    {
        $absorber = Product::query()->create([
            'sku' => 'S565A202',
            'name' => 'Pochłaniacz gazów i par A2, bagnetowy',
            'manufacturer' => 'SECURA',
            'category' => 'Ochrona dróg oddechowych',
            'ppe_family' => 'respiratory',
            'norms' => 'EN 14387',
            'description' => 'Pochłaniacz A2 do półmasek i masek pełnotwarzowych ze złączem bagnetowym; gazy i pary organiczne o temperaturze wrzenia powyżej 65°C; obudowa z tworzywa sztucznego; łączony z filtrami cząstek tej samej serii; 2 sztuki na maskę.',
            'catalog_price_net' => 40,
            'purchase_price' => 30,
            'stock' => 20,
            'enrichment_status' => Product::ENRICHMENT_DONE,
        ]);
        $requirement = 'Pochłaniacz gazów i par klasy A2 – element oczyszczający do sprzętu ochrony układu oddechowego, chroniący przed gazami i parami substancji organicznych o temperaturze wrzenia powyżej 65°C (m.in. alkohole, aldehydy, estry, etery, ketony, kwasy organiczne, styren) przy łącznym stężeniu objętościowym w powietrzu do 0,5% (5000 ppm). Wymagane: klasa A2 zgodnie z normą EN 14387; obudowa z tworzywa sztucznego; bagnetowy system mocowania zapewniający dokładne i bezpieczne osadzenie na półmaskach i maskach pełnotwarzowych ze złączem bagnetowym; możliwość łączenia z filtrami cząstek tej samej serii; stosowany w komplecie po 2 sztuki na maskę lub półmaskę.';

        $llm = Mockery::mock(OpenAiCompatibleClient::class);
        $llm->shouldReceive('chatJson')->andReturn([
            'needed' => 'pochłaniacz gazów i par A2 bagnetowy',
            'search_phrases' => ['pochłaniacz A2', 'bagnetowy'],
            'constraints' => ['klasa A2', 'EN 14387'],
            'matches' => [
                ['id' => $absorber->id, 'score' => 96, 'reason' => 'pochłaniacz A2 bagnetowy EN 14387'],
            ],
        ]);
        $this->app->instance(OpenAiCompatibleClient::class, $llm);

        $item = $this->itemFor('PRZ/SUB/3', $requirement);

        $this->postJson("/api/tenders/{$item->tender_id}/match", ['only_empty' => true])
            ->assertOk()
            ->assertJsonPath('matched', 1);

        $item->refresh();
        $this->assertSame($absorber->id, $item->main_product_id);
        $this->assertSame('ai', $item->match_source, 'bez marki i modelu w SIWZ nie ma zamiennika');
        $this->assertNotContains('brand_substitute', array_column($item->ai_match_reasons ?? [], 'code'));
        $this->assertGreaterThanOrEqual(65, (int) $item->ai_match_percent);
    }

    private function itemFor(string $number, string $requirement): TenderItem
    {
        $tender = Tender::query()->create([
            'number' => $number,
            'title' => 'Test',
            'client_id' => Client::query()->create(['name' => 'K'])->id,
            'owner_id' => User::factory()->create()->id,
            'status' => 'wycena',
            'ai_percent' => 0,
            'last_activity_at' => now(),
        ]);

        return TenderItem::query()->create([
            'tender_id' => $tender->id,
            'line_no' => 1,
            'requirement' => $requirement,
            'quantity' => 1,
            'status' => 'brak',
        ]);
    }

    public function test_named_model_with_number_picks_that_model_not_sibling(): void
    {
        $glasses = Product::query()->create([
            'sku' => '10045641',
            'name' => 'Okulary PERSPECTA 010 (12szt), bezbarwne',
            'manufacturer' => 'MSA',
            'ppe_family' => 'eyes',
            'category' => 'Sklep - kategorie / Ochrona wzroku i twarzy / Akcesoria do okularów i gogli',
            'description' => 'Okulary ochronne MSA PERSPECTA 010.',
            'catalog_price_net' => 351,
            'purchase_price' => 294,
            'stock' => 10,
            'enrichment_status' => Product::ENRICHMENT_DONE,
        ]);
        Product::query()->create([
            'sku' => '10045516',
            'name' => 'Okulary PERSPECTA 9000 (12szt), bezbarwne',
            'manufacturer' => 'MSA',
            'ppe_family' => 'eyes',
            'category' => 'Ochrona oczu',
            'description' => 'Okulary ochronne MSA PERSPECTA 9000.',
            'catalog_price_net' => 195,
            'purchase_price' => 163,
            'stock' => 10,
            'enrichment_status' => Product::ENRICHMENT_DONE,
        ]);
        Product::query()->create([
            'sku' => '10081939',
            'name' => 'Sztywne etui na okulary Perspecta (6szt)',
            'manufacturer' => 'MSA',
            'ppe_family' => 'eyes',
            'category' => 'Ochrona oczu',
            'description' => 'Etui na okulary Perspecta.',
            'catalog_price_net' => 155,
            'purchase_price' => 130,
            'stock' => 10,
            'enrichment_status' => Product::ENRICHMENT_DONE,
        ]);

        $llm = Mockery::mock(OpenAiCompatibleClient::class);
        $llm->shouldReceive('chatJson')->zeroOrMoreTimes()->andReturn(['matches' => []]);
        $this->app->instance(OpenAiCompatibleClient::class, $llm);

        $owner = User::factory()->create();
        $tender = Tender::query()->create([
            'number' => 'PRZ/SUB/2',
            'title' => 'Test',
            'client_id' => Client::query()->create(['name' => 'K'])->id,
            'owner_id' => $owner->id,
            'status' => 'wycena',
            'ai_percent' => 0,
            'last_activity_at' => now(),
        ]);
        $item = TenderItem::query()->create([
            'tender_id' => $tender->id,
            'line_no' => 153,
            'requirement' => 'OKULARY OCHRONNE MSA PERSPECTA 010',
            'quantity' => 1,
            'status' => 'brak',
        ]);

        $this->postJson("/api/tenders/{$tender->id}/match", ['only_empty' => true])
            ->assertOk()
            ->assertJsonPath('matched', 1);

        $item->refresh();
        $this->assertSame($glasses->id, $item->main_product_id);
        $this->assertNotSame('ai_substitute', $item->match_source);
    }
}
