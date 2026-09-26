<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Product;
use App\Services\ProductAiSearchService;
use App\Support\PpeAssortment;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * Lista „kaskada” w fuzji rang to znaleziska kroków z nazwy, nie karty reguł. Przy „nahełmowe” reguła montażu daje
 * dziesiątki kart bez kolejności; doklejone przed znaleziska kaskady zapełniały limit, więc karta znaleziona tylko
 * krokami (golden peltor-nahelmowe: wzorcowe 3M poza 24 kartami oceny) nie wchodziła do fuzji, a te same karty reguły
 * liczyły się drugi raz. Pomiar 26.09.2026 na produkcji (bez modelu): wzorcowych w 24 kartach 1 → 5.
 */
final class CascadeFusionListTest extends TestCase
{
    use RefreshDatabase;

    private const QUERY = 'Nauszniki nahełmowe mocowane do kasku';

    public function test_cascade_list_in_fusion_holds_step_findings_not_rule_cards(): void
    {
        Http::fake();
        for ($i = 1; $i <= 85; $i++) {
            $this->card('RULE-'.$i, 'Nauszniki nahełmowe RULE-'.$i, 'Nauszniki nahełmowe.');
        }
        $stepCard = $this->card('KASK-1', 'Nauszniki mocowane do kasku KASK-1', 'Ochronniki słuchu mocowane do kasku ochronnego.');
        $service = app(ProductAiSearchService::class);
        $service->enableSourceTrace();
        $intent = [
            'needed' => 'nauszniki nahełmowe',
            'search_steps' => ['nauszniki', 'mocowane do kasku'],
            'search_phrases' => ['nauszniki nahełmowe'],
            'constraints' => [],
        ];

        (new \ReflectionMethod($service, 'retrieveCandidates'))->invoke($service, self::QUERY, $intent, 80);

        $trace = $service->lastTrace();
        $cascade = end($trace['cascade']);
        $this->assertSame('steps_2', $cascade['level'], 'fixture: kaskada kończy na krokach z nazwy');
        $sources = $trace['sources'][0];
        $this->assertSame([(int) $stepCard->id], $sources['cascade_found'], 'fixture: kroki znajdują tylko kartę spoza reguły');
        $this->assertSame([(int) $stepCard->id], $sources['cascade'], 'lista kaskady w fuzji to jej znaleziska');
        $this->assertGreaterThanOrEqual(80, count($sources['priority']), 'karty reguły montażu zostają na liście priorytetu');
        $this->assertNotContains((int) $stepCard->id, $sources['priority']);
    }

    private function card(string $sku, string $name, string $description): Product
    {
        return Product::query()->create([
            'sku' => $sku,
            'name' => $name,
            'manufacturer' => 'X',
            'description' => $description,
            'ppe_family' => PpeAssortment::FAMILY_HEARING,
            'catalog_price_net' => 100,
            'purchase_price' => 50,
            'stock' => 1,
            'enrichment_status' => Product::ENRICHMENT_DONE,
            'enriched_at' => now()->subYear(),
        ]);
    }
}
