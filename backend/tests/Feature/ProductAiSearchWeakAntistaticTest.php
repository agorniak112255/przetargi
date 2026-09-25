<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Product;
use App\Models\User;
use App\Services\Ai\OpenAiCompatibleClient;
use App\Services\ProductAiSearchService;
use App\Services\Search\AiProductSearch;
use App\Support\PpeAssortment;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Mockery;
use Tests\Support\FakeSearchLlm;
use Tests\TestCase;

/**
 * Decyzje właściciela z 25.09.2026: dowód ESD dla rękawic i odzieży to „ESD”, EN 1149 albo EN 16350. Gdy wymaganie żąda
 * ESD albo normy antystatyki, karta z samym słowem („antyelektrostatyczne”) jest propozycją do sprawdzenia, nie
 * trafieniem — limit egzekwuje kod, poniżej progu zapisu. Wymaganie z samym słowem normy nie żąda: to samo słowo na
 * karcie wystarcza (rękaw HyFlex 11-202 z przetargu 1, rękawice chemoodporne z zapytania 374).
 */
final class ProductAiSearchWeakAntistaticTest extends TestCase
{
    use RefreshDatabase;

    private const QUERY = 'Rękawice chemoodporne antyelektrostatyczne (ESD) z normą EN ISO 374-1, rozmiar 10';

    private const WORD_QUERY = 'Rękawice chemoodporne, antyelektrostatyczne z normą EN ISO 374-1, rozmiar 10';

    protected function setUp(): void
    {
        parent::setUp();
        $this->freezeTime();
        $this->seed(RolesAndPermissionsSeeder::class);
        Sanctum::actingAs(User::factory()->withRole('admin')->create());
    }

    public function test_card_with_antistatic_word_only_is_a_proposal_below_the_save_threshold(): void
    {
        $word = $this->glove('WORD-1', 'Rękawice chemoodporne nitrylowe antyelektrostatyczne', 'EN ISO 374-1:2016 typ A');
        $norm = $this->glove('NORM-1', 'Rękawice chemoodporne nitrylowe', 'EN ISO 374-1:2016 typ A, EN 1149-5:2018');
        $this->llmScoring([$word->id => 95, $norm->id => 95]);

        $result = $this->app->make(AiProductSearch::class)->find(self::QUERY, 10);

        $rows = collect($result['products'])->keyBy('sku');
        $this->assertTrue($rows->has('WORD-1') && $rows->has('NORM-1'), 'fixture: obie karty ocenione');
        $this->assertLessThanOrEqual(60, (int) $rows['WORD-1']['ai_match_percent']);
        $this->assertStringContainsString('tylko słownie', (string) $rows['WORD-1']['ai_match_reason']);
        $this->assertSame(95, (int) $rows['NORM-1']['ai_match_percent'], 'EN 1149 na karcie to dowód — bez limitu');
        $this->assertSame('NORM-1', $result['products'][0]['sku']);
    }

    /**
     * Przegląd tury B: karta z normą, która dla tego wyrobu nie jest dowodem ESD (EN 61340 na rękawicy), dostaje limit
     * z uzasadnieniem nazywającym tę normę — nie „tylko słownie”, bo norma na karcie jest. Wyjątek: wymaganie samo
     * wymienia tę normę — wtedy karta z nią spełnia wymaganie wprost.
     */
    public function test_norm_that_is_not_esd_proof_for_the_product_is_named_in_the_cap_reason(): void
    {
        $iec = $this->glove('IEC-1', 'Rękawice chemoodporne nitrylowe', 'EN ISO 374-1:2016 typ A, IEC 61340-5-1');
        $this->llmScoring([$iec->id => 95]);

        $capped = collect($this->app->make(AiProductSearch::class)->find(self::QUERY, 10)['products'])->firstWhere('sku', 'IEC-1');
        $named = collect($this->app->make(AiProductSearch::class)->find(
            'Rękawice chemoodporne antyelektrostatyczne wg IEC 61340-5-1, EN ISO 374-1, rozmiar 10',
            10,
        )['products'])->firstWhere('sku', 'IEC-1');

        $this->assertNotNull($capped, 'fixture');
        $this->assertLessThanOrEqual(60, (int) $capped['ai_match_percent']);
        $this->assertStringContainsString('61340', (string) $capped['ai_match_reason']);
        $this->assertStringNotContainsString('tylko słownie', (string) $capped['ai_match_reason']);
        $this->assertNotNull($named, 'fixture');
        $this->assertSame(95, (int) $named['ai_match_percent'], 'wymaganie wymienia normę, którą karta ma — bez limitu');
    }

    public function test_requirement_without_antistatic_does_not_cap(): void
    {
        $word = $this->glove('WORD-1', 'Rękawice chemoodporne nitrylowe antyelektrostatyczne', 'EN ISO 374-1:2016 typ A');
        $this->llmScoring([$word->id => 95]);

        $result = $this->app->make(AiProductSearch::class)->find('Rękawice chemoodporne z normą EN ISO 374-1, rozmiar 10', 10);

        $row = collect($result['products'])->firstWhere('sku', 'WORD-1');
        $this->assertNotNull($row, 'fixture');
        $this->assertSame(95, (int) $row['ai_match_percent']);
    }

    /** Wymaganie z samym słowem nie żąda normy — to samo słowo na karcie jest trafieniem, bez limitu. */
    public function test_requirement_with_antistatic_word_only_accepts_the_word_on_the_card(): void
    {
        $word = $this->glove('WORD-1', 'Rękawice chemoodporne nitrylowe antyelektrostatyczne', 'EN ISO 374-1:2016 typ A');
        $this->llmScoring([$word->id => 95]);

        $result = $this->app->make(AiProductSearch::class)->find(self::WORD_QUERY, 10);

        $row = collect($result['products'])->firstWhere('sku', 'WORD-1');
        $this->assertNotNull($row, 'fixture');
        $this->assertSame(95, (int) $row['ai_match_percent']);
        $this->assertStringNotContainsString('tylko słownie', (string) $row['ai_match_reason']);
    }

    /**
     * Decyzja właściciela z 25.09.2026 („wszędzie 60%”): nazwany model idzie bez oceny modelu (trafienie jak kod), ale
     * przy żądaniu ESD karta modelu z samym słowem jest propozycją 60 z uzasadnieniem — jak inny kolor modelu.
     */
    public function test_named_model_with_esd_demand_caps_word_only_card(): void
    {
        foreach ([
            ['6003805', 'Rękawice Phynomic airLite A ESD', 'Lekkie rękawice montażowe powlekane.'],
            ['6004006', 'Rękawice Phynomic Foam', 'Lekkie rękawice montażowe powlekane, antystatyczne.'],
        ] as [$sku, $name, $description]) {
            Product::query()->create([
                'sku' => $sku,
                'name' => $name,
                'manufacturer' => 'UVEX',
                'description' => $description,
                'catalog_price_net' => 20,
                'purchase_price' => 10,
                'stock' => 5,
            ]);
        }
        $this->app->instance(OpenAiCompatibleClient::class, FakeSearchLlm::empty());

        $result = $this->app->make(ProductAiSearchService::class)->search('Rękawice montażowe powlekane uvex phynomic z funkcją ESD', 10);

        $rows = collect($result['products'])->keyBy('sku');
        $this->assertSame(ProductAiSearchService::MODEL_STATE_SKIPPED, $result['model_state'] ?? null, 'fixture: ścieżka nazwanego modelu');
        $this->assertTrue($rows->has('6003805') && $rows->has('6004006'), 'fixture: obie karty modelu w wyniku');
        $this->assertGreaterThanOrEqual(80, (int) $rows['6003805']['ai_match_percent']);
        $this->assertLessThanOrEqual(60, (int) $rows['6004006']['ai_match_percent']);
        $this->assertStringContainsString('tylko słownie', (string) $rows['6004006']['ai_match_reason']);
        $this->assertSame('6003805', $result['products'][0]['sku']);
    }

    /**
     * Wiersze reguły klasy obuwia (92) zostają wynikiem, gdy model nic nie zwróci — przy żądaniu ESD but z samym słowem
     * „antyelektrostatyczna” w opisie to propozycja 60 za trafieniami reguły.
     */
    public function test_footwear_class_rule_rows_cap_word_only_boot_when_model_returns_nothing(): void
    {
        $this->boot('P-WORD', 'Półbuty ochronne S1P', 'Półbuty ochronne, podeszwa antyelektrostatyczna, podnosek kompozytowy.');
        $this->boot('P-ESD', 'Półbuty ochronne S1P ESD', 'Półbuty ochronne ESD, podnosek kompozytowy.');
        $this->app->instance(OpenAiCompatibleClient::class, FakeSearchLlm::empty());

        $result = $this->app->make(ProductAiSearchService::class)->search('Półbuty ochronne S1P ESD', 10);

        $rows = collect($result['products'])->keyBy('sku');
        $this->assertTrue($rows->has('P-ESD') && $rows->has('P-WORD'), 'fixture: oba buty z reguły klasy');
        $this->assertSame(ProductAiSearchService::MATCH_SOURCE_RULE, $rows['P-WORD']['ai_match_source'] ?? null, 'fixture: wiersz reguły');
        $this->assertSame(92, (int) $rows['P-ESD']['ai_match_percent']);
        $this->assertLessThanOrEqual(60, (int) $rows['P-WORD']['ai_match_percent']);
        $this->assertStringContainsString('tylko słownie', (string) $rows['P-WORD']['ai_match_reason']);
        $this->assertSame('P-ESD', $result['products'][0]['sku']);
    }

    /** Drugi element kompletu dokładany z katalogu (68) — przy żądaniu ESD karta z samym słowem to propozycja 60. */
    public function test_apparel_set_complement_with_word_only_evidence_is_capped(): void
    {
        $jacket = $this->apparel('B-ESD', 'Bluza robocza ESD', 'Bluza robocza ESD z tkaniny z włóknem węglowym.');
        $this->apparel('S-WORD', 'Spodnie robocze antyelektrostatyczne', 'Spodnie robocze do pracy w strefach zagrożenia wyładowaniem.');
        $this->llmScoring([$jacket->id => 95], [
            'needed' => 'bluza i spodnie robocze ESD',
            'search_steps' => ['bluza', 'spodnie'],
            'search_phrases' => ['bluza robocza ESD', 'spodnie robocze ESD'],
            'constraints' => [],
        ]);

        $result = $this->app->make(AiProductSearch::class)->find('Ubranie robocze ESD: bluza + spodnie', 10);

        $rows = collect($result['products'])->keyBy('sku');
        $this->assertSame(95, (int) ($rows['B-ESD']['ai_match_percent'] ?? 0), 'fixture: bluza oceniona przez model');
        $this->assertTrue($rows->has('S-WORD'), 'fixture: spodnie dołożone jako drugi element kompletu');
        $this->assertLessThanOrEqual(60, (int) $rows['S-WORD']['ai_match_percent']);
        $this->assertStringContainsString('tylko słownie', (string) $rows['S-WORD']['ai_match_reason']);
    }

    /**
     * Jedna definicja dowodu w bramce i w prompcie (D7a, 25.09.2026). Prompt mówił „antystatyczny = EN 1149”, a reguła
     * żargonu „sama norma EN nie zastępuje tego słowa” — model mógł odrzucić rękawicę z samym EN 16350 albo wpisać ją do
     * missing_key (limit 50), choć bramka i grupa dowodu żargonu uznają normę za dowód. Inny żargon zostaje przy słowie.
     */
    public function test_rank_prompt_names_esd_en_1149_and_en_16350_as_antistatic_proof(): void
    {
        $prompt = $this->rankPromptFor(self::QUERY, $this->glove('G-16350', 'Rękawice chemoodporne butylowe', 'EN ISO 374-1:2016 typ A, EN 16350:2014'));

        $this->assertMatchesRegularExpression('/antystatyczny = „ESD”, EN 1149 albo EN 16350/u', $prompt);
        $this->assertStringContainsString('albo normę antystatyki (EN 1149, EN 16350, EN 61340) w polach dowodu', $prompt);
        $this->assertStringNotContainsString('sama norma EN nie zastępuje tego słowa', $prompt);
    }

    public function test_other_slang_still_requires_the_word_in_rank_prompt(): void
    {
        $prompt = $this->rankPromptFor(
            'Rękawice wampirki, rozmiar 9',
            $this->glove('W-1', 'Rękawice dzianinowe powlekane nitrylem na dłoni', 'EN 388:2016 4121X'),
        );

        $this->assertStringContainsString('sama norma EN nie zastępuje tego słowa', $prompt);
        $this->assertStringNotContainsString('normę antystatyki', $prompt);
    }

    /**
     * Żądanie ESD liczy się z tekstu klienta, nie ze streszczenia modelu: rękaw HyFlex 11-202 z przetargu 1 („wyrób
     * antystatyczny”) — model streścił wymaganie jako narękawniki „(ESD)”, a rodzaju wyrobu nie ma w tekście klienta,
     * więc streszczenie wchodzi do tekstu bramek. Klient normy nie żądał, więc słowo na karcie jest trafieniem.
     */
    public function test_esd_added_by_model_understanding_does_not_cap_word_only_card(): void
    {
        $requirement = 'Ochraniacz przedramienia chroniący przed przecięciem, wyrób antystatyczny, bez lateksu';
        $sleeve = Product::query()->create([
            'sku' => '11202000',
            'name' => 'HyFlex 11202 SIZE 19\'\'/47,5 cm',
            'manufacturer' => 'Ansell',
            'description' => 'Rękaw ochronny Ansell HyFlex 11-202 o wysokiej widoczności chroni przedramię przed przecięciem, '
                .'wyrób antystatyczny, zapięcie na rzep.',
            'norms' => 'EN 407: poziom 1 (ochrona termiczna do 100°C), EN ISO 13997: odporność na przecięcie poziom C',
            'catalog_price_net' => 40,
            'purchase_price' => 30,
            'stock' => 5,
            'enrichment_status' => Product::ENRICHMENT_DONE,
            'enriched_at' => now()->subYear(),
        ]);
        $this->assertNull((new PpeAssortment)->family($requirement), 'fixture: rodzaj wyrobu tylko ze streszczenia modelu');
        $this->llmScoring([$sleeve->id => 95], [
            'needed' => 'narękawniki ochronne przeciwprzecięciowe antystatyczne (ESD)',
            'search_steps' => ['rękaw', 'ochronny'],
            'search_phrases' => ['rękaw ochronny antystatyczny'],
            'constraints' => [],
        ]);

        $row = collect($this->app->make(AiProductSearch::class)->find($requirement, 10)['products'])->firstWhere('sku', '11202000');

        $this->assertNotNull($row, 'fixture');
        $this->assertSame(95, (int) $row['ai_match_percent']);
        $this->assertStringNotContainsString('propozycja do sprawdzenia', (string) $row['ai_match_reason']);
    }

    /**
     * @param  array<int, int>  $scores  id karty => ocena modelu
     * @param  array<string, mixed>  $understanding  odpowiedź kroku „zrozum”; `needed` wraca też w ocenie
     */
    private function llmScoring(array $scores, array $understanding = [
        'needed' => 'rękawice chemoodporne',
        'search_steps' => ['rękawice', 'chemoodporne'],
        'search_phrases' => ['rękawice chemoodporne'],
        'constraints' => ['EN ISO 374-1'],
    ]): void
    {
        $llm = Mockery::mock(OpenAiCompatibleClient::class);
        $needed = (string) $understanding['needed'];
        $answer = static function (array $messages) use ($scores, $understanding, $needed): array {
            if (FakeSearchLlm::kind($messages) !== FakeSearchLlm::KIND_RANK) {
                return $understanding;
            }
            $matches = [];
            foreach ($scores as $id => $score) {
                if (str_contains((string) ($messages[1]['content'] ?? ''), '"id":'.$id)) {
                    $matches[] = ['id' => $id, 'score' => $score, 'reason' => 'spełnia', 'missing_key' => []];
                }
            }

            return ['needed' => $needed, 'matches' => $matches];
        };
        $llm->shouldReceive('chatJson')->andReturnUsing($answer);
        $llm->shouldReceive('chatJsonMany')->andReturnUsing(static fn (array $sets): array => array_map($answer, $sets));
        $this->app->instance(OpenAiCompatibleClient::class, $llm);
    }

    /** Prompt systemowy rankingu wysłany dla zapytania (model ocenia kartę na 95). */
    private function rankPromptFor(string $query, Product $card): string
    {
        $prompts = [];
        $answer = static function (array $messages) use ($card, &$prompts): array {
            if (FakeSearchLlm::kind($messages) !== FakeSearchLlm::KIND_RANK) {
                return [
                    'needed' => 'rękawice',
                    'search_steps' => ['rękawice'],
                    'search_phrases' => ['rękawice'],
                    'constraints' => [],
                ];
            }
            $prompts[] = (string) ($messages[0]['content'] ?? '');

            return ['matches' => [['id' => $card->id, 'score' => 95, 'reason' => 'spełnia', 'missing_key' => []]]];
        };
        $llm = Mockery::mock(OpenAiCompatibleClient::class);
        $llm->shouldReceive('chatJson')->andReturnUsing($answer);
        $llm->shouldReceive('chatJsonMany')->andReturnUsing(static fn (array $sets): array => array_map($answer, $sets));
        $this->app->instance(OpenAiCompatibleClient::class, $llm);

        $this->app->make(AiProductSearch::class)->find($query, 10);

        $this->assertNotEmpty($prompts, 'fixture: ranking wysłany do modelu');
        $this->assertStringContainsString('Gdy wymaganie ma żargon/słowo cechy', $prompts[0], 'fixture: zapytanie ma żargon');

        return $prompts[0];
    }

    private function apparel(string $sku, string $name, string $description): Product
    {
        return Product::query()->create([
            'sku' => $sku,
            'name' => $name,
            'manufacturer' => 'Portwest',
            'category' => 'Odzież robocza',
            'description' => $description,
            'catalog_price_net' => 90,
            'purchase_price' => 60,
            'stock' => 5,
            'ppe_family' => PpeAssortment::FAMILY_APPAREL,
            'enrichment_status' => Product::ENRICHMENT_DONE,
            'enriched_at' => now()->subYear(),
        ]);
    }

    private function boot(string $sku, string $name, string $description): Product
    {
        return Product::query()->create([
            'sku' => $sku,
            'name' => $name,
            'manufacturer' => 'ARTRA',
            'category' => 'Obuwie',
            'description' => $description,
            'norms' => 'EN ISO 20345:2011 S1P',
            'catalog_price_net' => 150,
            'purchase_price' => 100,
            'stock' => 5,
            'ppe_family' => PpeAssortment::FAMILY_FOOTWEAR,
            'enrichment_status' => Product::ENRICHMENT_DONE,
            'enriched_at' => now()->subYear(),
        ]);
    }

    private function glove(string $sku, string $name, string $norms): Product
    {
        return Product::query()->create([
            'sku' => $sku,
            'name' => $name,
            'manufacturer' => 'Ansell',
            'category' => 'Rękawice chemoodporne',
            'description' => $name.'. Ochrona przed chemikaliami.',
            'norms' => $norms,
            'catalog_price_net' => 20,
            'purchase_price' => 12,
            'stock' => 5,
            'ppe_family' => PpeAssortment::FAMILY_GLOVES,
            'enrichment_status' => Product::ENRICHMENT_DONE,
            'enriched_at' => now()->subYear(),
        ]);
    }
}
