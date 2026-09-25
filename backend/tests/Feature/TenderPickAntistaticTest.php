<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\AiSetting;
use App\Models\Product;
use App\Models\TenderItem;
use App\Services\Ai\AiTask;
use App\Services\Ai\OpenAiCompatibleClient;
use App\Services\ProductAiSearchService;
use App\Services\ProductMatchService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\Support\FakeSearchLlm;
use Tests\TestCase;

/**
 * tenders:eval 20260925_155130 (produkcja): pod „uvex phynomic z funkcją ESD” wyszukiwarka zwróciła tylko karty z ESD
 * (6003805, 6004806), a automat przetargu zapisał 6004105 „Phynomic lite W” (99%, heuristic) — bez ESD i bez słowa
 * o antystatyce. strongSkuPick sprawdzał tylko bramkę asortymentu (compatibleProduct), która antystatykę rękawic
 * pomija; bramka antystatyki jest w wyszukiwarce. Decyzja właściciela z 25.09.2026 („wszędzie 60%”): karta ze
 * słabym dowodem przy żądaniu ESD to propozycja, nie wybór automatu — a karta bez dowodu nie przechodzi wcale.
 */
final class TenderPickAntistaticTest extends TestCase
{
    use RefreshDatabase;

    private const REQUIREMENT = 'Rękawice montażowe powlekane uvex phynomic z funkcją ESD';

    public function test_named_model_pick_skips_cards_without_strong_antistatic_evidence(): void
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
        // Karty bez ESD tańsze — dotąd remis 99% rozstrzygała cena na ich korzyść.
        $this->glove('6003805', 'Rękawice Phynomic airLite A ESD', 'Lekkie rękawice montażowe powlekane, obsługa ekranów dotykowych.', 30);
        $this->glove('6004105', 'Rękawice Phynomic lite W', 'Lekkie rękawice robocze do montażu i kontroli jakości.', 10);
        $this->glove('6004006', 'Rękawice Phynomic Foam', 'Lekkie rękawice montażowe powlekane, antystatyczne.', 12);
        $this->app->instance(OpenAiCompatibleClient::class, FakeSearchLlm::empty());

        $rows = app(ProductAiSearchService::class)->searchMany([self::REQUIREMENT], 80, false, AiTask::ProductSearch, 4);
        $item = new TenderItem;
        $item->forceFill(['requirement' => self::REQUIREMENT]);
        $decision = app(ProductMatchService::class)->debugPick($item, $rows[0]);

        $this->assertNotNull($decision['pick'], 'karta z ESD w nazwie to trafienie nazwanego modelu');
        $this->assertSame('6003805', $decision['pick']['sku']);
    }

    private function glove(string $sku, string $name, string $description, float $price): Product
    {
        return Product::query()->create([
            'sku' => $sku,
            'name' => $name,
            'manufacturer' => 'UVEX',
            'description' => $description,
            'catalog_price_net' => $price * 2,
            'purchase_price' => $price,
            'stock' => 5,
            'enrichment_status' => Product::ENRICHMENT_DONE,
            'enriched_at' => now()->subYear(),
        ]);
    }
}
