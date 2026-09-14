<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\AiSetting;
use App\Models\Product;
use App\Services\Ai\AiTask;
use App\Services\Ai\OpenAiCompatibleClient;
use App\Services\ProductAiSearchService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Mockery;
use Tests\Support\FakeSearchLlm;
use Tests\TestCase;

/**
 * Raport 20260914_161701 poz. 1: model zrozumiał rękaw jako „narękawniki ochronne przeciwprzecięciowe”, a wzorzec rękawa
 * nie znał „narękawnika”. Tekst zapytania katalogowego (zrozumiana nazwa + normy EN 388) był więc rękawicami, bramka po
 * rankingu odrzuciła rękaw HyFlex 11-202 oceniony przez model, a wynik wypełniła lista zapasowa rękawic ESD z oceną 48.
 */
final class ProductAiSearchUnderstoodSleeveTest extends TestCase
{
    use RefreshDatabase;

    private const REQUIREMENT = 'Ochraniacz przedramienia (rękaw) chroniący przed przecięciem, długość ok. 475 mm (19\'\'), w kolorze '
        .'fluorescencyjnym żółtym; regulowane zapięcie na rzep; wyrób antystatyczny, bez lateksu. Wymagane: ŚOI kategorii III; '
        .'EN 420:2003+A1:2009; EN 388 z poziomami min. 2.X.4.2.C; EN 407 – odporność na ciepło kontaktowe poziom 1.';

    public function test_sleeve_rated_by_model_survives_when_requirement_is_understood_as_narekawniki(): void
    {
        AiSetting::query()->create([
            'enabled' => true,
            'provider' => 'openai_compatible',
            'base_url' => 'https://api.openai.com/v1',
            'api_key' => 'sk-test-key-1234567890',
            'model' => 'gpt-4o-mini',
            'timeout_seconds' => 60,
            'temperature' => 0.1,
        ]);
        $sleeve = $this->card('11202000', 'HyFlex 11202 SIZE 19\'\'/47,5 cm', 'Ansell',
            'Rękaw ochronny Ansell HyFlex 11-202 o wysokiej widoczności chroni przedramię przed przecięciem, wyrób antystatyczny, zapięcie na rzep.',
            'EN 407: poziom 1 (ochrona termiczna do 100°C), EN ISO 13997: odporność na przecięcie poziom C');
        $this->card('48130110', 'HyFlex 48130 ESD', 'Ansell',
            'Rękawice ochronne Ansell HyFlex 48-130 ESD, antystatyczne, powłoka poliuretanowa na dłoni.',
            'EN 388:2016 4131X, EN 16350');
        $sleeveId = (int) $sleeve->id;
        $answer = static fn (array $messages): array => FakeSearchLlm::kind($messages) === FakeSearchLlm::KIND_RANK
            ? [
                'needed' => 'narękawniki ochronne przeciwprzecięciowe',
                'search_phrases' => ['narękawniki przeciwprzecięciowe'],
                'constraints' => ['EN 388 min. 2.X.4.2.C', 'EN 407 poziom 1', 'EN 420', 'ŚOI kategorii III'],
                'matches' => [['id' => $sleeveId, 'score' => 95, 'reason' => 'Rękaw ochronny HyFlex 11-202, 47,5 cm', 'missing_key' => []]],
            ]
            : [];
        $llm = Mockery::mock(OpenAiCompatibleClient::class);
        $llm->shouldReceive('chatJson')->andReturnUsing(static fn (array $messages): array => $answer($messages));
        $llm->shouldReceive('chatJsonMany')->andReturnUsing(static fn (array $sets): array => array_map($answer, $sets));
        $this->app->instance(OpenAiCompatibleClient::class, $llm);

        $rows = app(ProductAiSearchService::class)->searchMany([self::REQUIREMENT], 20, false, AiTask::ProductSearch, 4)[0]['products'];

        $this->assertSame('11202000', $rows[0]['sku'] ?? null, 'rękaw oceniony przez model zostaje: '.implode(', ', array_column($rows, 'sku')));
        $this->assertSame(95, (int) ($rows[0]['ai_match_percent'] ?? 0));
        $this->assertNotSame(ProductAiSearchService::MATCH_SOURCE_CATALOG, $rows[0]['ai_match_source'] ?? null);
        $this->assertNotContains('48130110', array_column($rows, 'sku'), 'rękawice nie są listą rękawa');
    }

    private function card(string $sku, string $name, string $manufacturer, string $description, string $norms): Product
    {
        return Product::query()->create([
            'sku' => $sku,
            'name' => $name,
            'manufacturer' => $manufacturer,
            'description' => $description,
            'norms' => $norms,
            'catalog_price_net' => 40,
            'purchase_price' => 30,
            'stock' => 5,
            'enrichment_status' => Product::ENRICHMENT_DONE,
            'enriched_at' => now(),
        ]);
    }
}
