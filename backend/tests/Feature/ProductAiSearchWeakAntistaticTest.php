<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Product;
use App\Models\User;
use App\Services\Ai\OpenAiCompatibleClient;
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
