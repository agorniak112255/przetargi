<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\BrandDictionaryEntry;
use App\Models\Product;
use App\Services\Ai\OpenAiCompatibleClient;
use App\Services\ProductAiSearchService;
use App\Support\PpeAssortment;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Mockery;
use Tests\Support\FakeSearchLlm;
use Tests\TestCase;

/**
 * Zapytanie #87 (30.09.2026), dwa pliki OPZ: pod wszystkimi czterema pozycjami pusta lista, choć w katalogu są wodery,
 * spodniobuty, kombinezony z kaloszami i statywy PROTEKT. Trzy przyczyny po stronie wyszukiwarki (nie modelu):
 * „Buty gumowe rybackie (wodery)” było obuwiem, więc wodery (odzież) odpadały na bramce rodziny; zrozumienie „buty
 * ochronne” zamiast kombinezonu; nazwany model spoza katalogu (TM 9-N) kończył szukanie na zrzucie kart marki.
 */
final class ProductAiSearchWadersAndAbsentModelTest extends TestCase
{
    use RefreshDatabase;

    private ?ProductAiSearchService $lastSearch = null;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolesAndPermissionsSeeder::class);
    }

    public function test_waders_word_makes_apparel_even_after_boots_or_wellingtons(): void
    {
        $assortment = new PpeAssortment;

        $this->assertSame(PpeAssortment::FAMILY_APPAREL, $assortment->family('Buty gumowe rybackie (wodery) z podnoskiem PVC S5'));
        $this->assertSame(PpeAssortment::FAMILY_APPAREL, $assortment->family('Kalosze ochronne ARDON®CHEST WADERS Max S5 Fluo orange'));
        $this->assertSame(PpeAssortment::FAMILY_APPAREL, $assortment->family('Wodery Max kalosz typ S5'));
        $this->assertSame(PpeAssortment::FAMILY_FOOTWEAR, $assortment->family('Buty gumowe PVC S5 z podnoskiem'));
        $this->assertSame(PpeAssortment::FAMILY_FOOTWEAR, $assortment->family('Kalosze PCV S5 SRC'));
    }

    public function test_waders_requirement_sends_waders_cards_to_ranking(): void
    {
        $this->card('WRM02B', 'Wodery Max kalosz typ S5', 'AJ GROUP', 'Wodery z kaloszem S5, PVC, podeszwa SRC.');
        $this->card('SBM01B', 'Spodniobuty Max kalosz typ S5', 'AJ GROUP', 'Spodniobuty z kaloszem S5, PVC.');
        $this->card('140P', 'Kalosz bezpieczny PCV + wkładka + nosek S5', 'AJ GROUP', 'Kalosz PCV S5.');

        $ranked = $this->rankedCards(
            'Buty gumowe rybackie (wodery) z podnoskiem PVC EN ISO 20345 S5 SRC',
            ['needed' => 'wodery', 'search_phrases' => ['wodery', 'spodniobuty', 'buty gumowe rybackie', 'wodery S5']],
        );

        $this->assertContains('WRM02B', $ranked);
        $this->assertContains('SBM01B', $ranked);
    }

    /** Normy obuwia w wierszu kombinezonu nie zamieniają szukanego wyrobu na buty. */
    public function test_understanding_cannot_turn_a_coverall_into_boots(): void
    {
        $this->card('304/K', 'Kombinezon wodoochronny dwu kolorowy z wgrzanymi kaloszami typ S5', 'AJ GROUP', 'Kombinezon z kaloszami S5.');
        $this->card('140P', 'Kalosz bezpieczny PCV + wkładka + nosek S5', 'AJ GROUP', 'Kalosz PCV S5.');
        $prompts = [];

        $ranked = $this->rankedCards(
            'Kombinezon rybacki z podnoskiem PVC EN ISO 20345 S5 SRC EN 343',
            ['needed' => 'buty ochronne', 'search_phrases' => ['kombinezon', 'buty ochronne', 'kalosze ochronne']],
            $prompts,
        );

        $this->assertContains('304/K', $ranked);
        $this->assertNotContains('140P', $ranked);
        $this->assertStringNotContainsString("Szukany produkt (z analizy):\nbuty ochronne", implode("\n", $prompts));
    }

    /** Model nazwany w wymaganiu, którego nie ma w katalogu: szukanie idzie dalej po rodzaju wyrobu, nie po samej marce. */
    public function test_absent_named_model_does_not_end_search_on_brand_cards(): void
    {
        // marka ma więcej kart niż pula — zrzut kart marki kończył się na amortyzatorach, jak na produkcji
        for ($i = 1; $i <= 120; $i++) {
            $this->card('AW170/LB'.$i, 'AW170/LB'.$i.' - Amortyzator bezpieczeństwa z linką', 'PROTEKT', 'Amortyzator bezpieczeństwa z linką.');
        }
        $this->card('AT008', 'TM 6 - Statyw bezpieczeństwa na kółkach', 'PROTEKT', 'Statyw bezpieczeństwa do prac w zbiornikach.');
        // na produkcji PROTEKT jest w słowniku producentów — marka z wymagania dokłada wtedy karty marki
        BrandDictionaryEntry::query()->create(['term' => 'PROTEKT', 'kind' => BrandDictionaryEntry::KIND_PRODUCER]);

        $this->rankedCards(
            'TM 9-N aluminiowy statyw bezpieczeństwa PROTEKT',
            ['needed' => 'statyw bezpieczeństwa', 'search_phrases' => ['statyw bezpieczeństwa', 'TM 9-N'], 'manufacturer' => 'PROTEKT'],
        );
        $trace = $this->lastSearch?->lastTrace() ?? [];

        // Skrót „marka + model” kończył wyszukiwanie przed kaskadą i wyszukiwaniem tekstowym — ślad źródeł był pusty,
        // a pula to zrzut kart marki (na produkcji: 24 amortyzatory). Teraz szukanie idzie pełną ścieżką.
        $this->assertNotSame([], $trace['sources'] ?? [], 'wyszukiwanie przeszło przez źródła puli');
        // Pula kandydatów, nie 24 karty oceny: ich kolejność w SQLite (bez wektorów) nie odpowiada produkcji, gdzie
        // TM 6 stał na 5. miejscu puli.
        $pool = Product::query()->whereIn('id', $trace['candidate_ids'] ?? [])->pluck('sku')->all();
        $this->assertContains('AT008', $pool);
    }

    /**
     * SKU kart, które trafiły do oceny modelu.
     *
     * @param  array<string, mixed>  $understanding
     * @param  list<string>  $prompts  treści promptów oceny (do sprawdzenia linii „Szukany produkt”)
     * @return list<string>
     */
    private function rankedCards(string $query, array $understanding, array &$prompts = []): array
    {
        $bySku = Product::query()->pluck('sku', 'id')->all();
        $seen = [];
        $answer = static function (array $messages) use ($understanding, $bySku, &$seen, &$prompts): array {
            if (FakeSearchLlm::kind($messages) !== FakeSearchLlm::KIND_RANK) {
                return $understanding + ['constraints' => []];
            }
            $content = (string) ($messages[1]['content'] ?? '');
            $prompts[] = $content;
            foreach ($bySku as $id => $sku) {
                if (str_contains($content, '"id":'.$id.',') || str_contains($content, '"id":'.$id.'}')) {
                    $seen[$sku] = true;
                }
            }

            return ['matches' => []];
        };
        $llm = Mockery::mock(OpenAiCompatibleClient::class);
        $llm->shouldReceive('chatJson')->andReturnUsing(static fn (array $messages): array => $answer($messages));
        $llm->shouldReceive('chatJsonMany')->andReturnUsing(static fn (array $sets): array => array_map($answer, $sets));
        $this->app->instance(OpenAiCompatibleClient::class, $llm);

        $this->lastSearch = $this->app->make(ProductAiSearchService::class);
        $this->lastSearch->enableSourceTrace();
        $this->lastSearch->searchMany([$query], 10);

        return array_keys($seen);
    }

    private function card(string $sku, string $name, string $manufacturer, string $description): void
    {
        Product::query()->create([
            'sku' => $sku,
            'name' => $name,
            'manufacturer' => $manufacturer,
            'description' => $description,
            'stock' => 5,
            'enrichment_status' => Product::ENRICHMENT_DONE,
            'enriched_at' => now(),
            'currency' => 'PLN',
            'catalog_price_net' => 100,
            'purchase_price' => 80,
        ]);
    }
}
