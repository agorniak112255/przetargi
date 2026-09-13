<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Models\AiSetting;
use App\Models\Product;
use App\Services\Ai\OpenAiCompatibleClient;
use App\Services\ProductAiSearchService;
use App\Services\ProductMatchService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Mockery;
use PHPUnit\Framework\Attributes\Test;
use Tests\Support\FakeSearchLlm;
use Tests\Support\Opisowy15Fixture;
use Tests\TestCase;

/**
 * Wybór kandydata w dopasowaniu przetargu (pickAuto / resolveBestPick i okno kandydatów AI):
 * karta wygrywa dowodami z karty, nie ceną przy płaskim procencie; wiersz listy katalogowej
 * bez zgody admina nie dochodzi do zapisu; okno kandydatów obejmuje 10 pierwszych wierszy
 * odpowiedzi wyszukiwarki w jej kolejności. Model zastąpiony stubem — bez wywołań AI.
 */
final class ProductMatchPickTest extends TestCase
{
    use RefreshDatabase;

    /**
     * Poz. 13 bez liczb (°C, rok normy) — te do scalenia W1 dają igłę „modelu” i przełączają
     * wyszukiwarkę w tryb zakotwiczony na modelu, a tu badamy samo okno kandydatów.
     */
    private const REUSABLE_HALF_MASK = 'Półmaska wielokrotnego użytku do ochrony układu oddechowego, korpus z dwoma '
        .'zaworami wdechowymi z łącznikami bagnetowymi, zawór wydechowy z pokrywą, jednoczęściowe nagłowie tekstylne';

    /** Wymaganie bez liczb i kodów — żadna igła „modelu”, żaden kod SKU; liczy się tylko karta. */
    private const LONG_JOHNS = 'Kalesony bawełniane męskie';

    protected function setUp(): void
    {
        parent::setUp();
        Http::fake();
    }

    // ------------------------------------------------------------ okno i kolejność kandydatów

    /**
     * Poz. 13 (półmaska wielokrotnego użytku): wyszukiwarka miała SECURA 3000 na 8. miejscu
     * z 16, a przetarg brał 5 pierwszych wierszy — właściwa karta nigdy nie była kandydatem.
     */
    #[Test]
    public function candidate_window_keeps_eighth_search_row_for_reusable_half_mask(): void
    {
        $this->settings();
        $ids = Opisowy15Fixture::seed(['9310+', '9312+', '9914', '7100329384', 'S56T0SM0']);
        $ids += $this->respirators([
            '7100113104' => 'Niewymagająca konserwacji półmaska wielokrotnego użytku 3M, FFP2',
            '9320+' => '3M Aura półmaska filtrująca, FFP2, bez zaworu, 9320+',
            '9322+' => '3M Aura półmaska filtrująca, FFP2, z zaworem, 9322+',
            'AURA 9322+GEN3' => '3M Aura półmaska filtrująca, FFP2, z zaworem, 9322+Gen3',
            '6581-EN' => 'Półmaska wielokrotnego użytku 3M, mechanizm szybkozatrzaskowy, 6500',
            '9926' => '3M Półmaska filtrująca 9926, specjalistyczna, z zaworem, FFP2',
        ]);
        // Kolejność odpowiedzi wyszukiwarki z produkcji (r_12.json): SECURA 3000 jako 8. wiersz.
        // Malejący procent utrwala tę kolejność w rankingu wyszukiwarki (procent, potem cena).
        $order = ['9310+', '7100113104', '9312+', '9320+', '9322+', 'AURA 9322+GEN3', '6581-EN',
            'S56T0SM0', '9914', '7100329384', '9926'];
        $matches = [];
        foreach ($order as $i => $sku) {
            $matches[] = ['id' => $ids[$sku], 'score' => 72 - $i, 'reason' => 'stub: półmaska'];
        }
        $this->stubSearchModel($matches);

        $matcher = app(ProductMatchService::class);
        $this->invoke($matcher, 'prefetchAiCandidates', [self::REUSABLE_HALF_MASK]);
        $candidates = $this->invoke($matcher, 'aiTopCandidates', self::REUSABLE_HALF_MASK);

        $skus = array_column($candidates, 'sku');
        $this->assertContains('S56T0SM0', $skus, 'SECURA 3000 (8. wiersz odpowiedzi) musi być kandydatem');
        $this->assertNotContains('9926', $skus, 'okno obejmuje 10 pierwszych wierszy odpowiedzi');
        $this->assertSame(array_slice($order, 0, 10), $skus, 'kolejność kandydatów = kolejność odpowiedzi wyszukiwarki');
    }

    #[Test]
    public function search_order_is_kept_and_catalog_rows_follow_model_rows(): void
    {
        $matcher = app(ProductMatchService::class);
        $rows = [
            ['id' => 1, 'sku' => 'A', 'ai_match_percent' => 80],
            ['id' => 2, 'sku' => 'B', 'ai_match_percent' => 90, 'ai_match_source' => ProductAiSearchService::MATCH_SOURCE_CATALOG],
            ['id' => 3, 'sku' => 'C', 'ai_match_percent' => 70],
            ['id' => 4, 'sku' => 'D', 'ai_match_percent' => 60, 'ai_match_source' => ProductAiSearchService::MATCH_SOURCE_CATALOG],
            // skrót deterministyczny (klasa obuwia 92 / cut 80) — literał 'rule' z kontraktu W2↔W4
            ['id' => 5, 'sku' => 'E', 'ai_match_percent' => 92, 'ai_match_source' => 'rule'],
        ];

        $mapped = $this->invoke($matcher, 'mapAiSearchRows', $rows, 10, 'ai');

        $this->assertSame(['A', 'C', 'B', 'D', 'E'], array_column($mapped, 'sku'));
        $this->assertSame(['ai', 'ai', 'catalog', 'catalog', 'rule'], array_column($mapped, 'source'));
        $this->assertSame(['A', 'C', 'B'], array_column($this->invoke($matcher, 'mapAiSearchRows', $rows, 3, 'ai'), 'sku'));
    }

    // ------------------------------------------------------------------- dowody przed ceną

    /**
     * Poz. 12: wyszukiwarka dała 92% półbutom elektroizolacyjnym T5912100 i zwykłym półbutom
     * OB ART 702 (płaskie 92 skrótu klasy obuwia). Przetarg brał tańsze ART 702, bo przy równym
     * procencie rozstrzygała cena, a persistableScore ufał modelowi mimo explain 35 vs 99.
     * Źródła (ai / ai_substitute) nie asertujemy — po W1 zmieni się z zamiennika na ai.
     */
    #[Test]
    public function insulating_shoes_beat_cheaper_ob_shoes_at_equal_percent(): void
    {
        $this->settings();
        $matcher = app(ProductMatchService::class);
        $requirement = Opisowy15Fixture::requirement(12);
        $ids = Opisowy15Fixture::seed(['T5912100', 'ART 702 Air 6660 OB A E FO']);
        $insulating = Product::query()->findOrFail($ids['T5912100']);
        $ob = Product::query()->findOrFail($ids['ART 702 Air 6660 OB A E FO']);
        $this->assertLessThan(
            $this->invoke($matcher, 'purchasePln', $insulating),
            $this->invoke($matcher, 'purchasePln', $ob),
            'test ma sens tylko, gdy zła karta jest tańsza',
        );

        $pick = $this->invoke($matcher, 'resolveBestPick', $requirement, collect([$insulating, $ob]), [
            $this->candidate($insulating, 92),
            $this->candidate($ob, 92),
        ]);

        $this->assertNotNull($pick);
        $this->assertSame('T5912100', $pick['product']->sku);
        $this->assertSame(92, $pick['score']);
    }

    /**
     * Dawna klauzula „każdy kandydat ≥ min” wpuszczała do okna ceny 65% obok 96% — wygrywał
     * tańszy. Przy równych dowodach o wyborze decyduje procent modelu, cena tylko w oknie 8 pkt.
     */
    #[Test]
    public function higher_percent_wins_over_cheaper_card_when_gap_exceeds_price_window(): void
    {
        $this->settings();
        $matcher = app(ProductMatchService::class);
        $pricey = $this->apparel('KAL-P', 'Kalesony bawełniane męskie', 30.0);
        $cheap = $this->apparel('KAL-C', 'Kalesony bawełniane męskie', 10.0);

        $pick = $this->invoke($matcher, 'pickAuto', self::LONG_JOHNS, null, [
            $this->candidate($pricey, 96),
            $this->candidate($cheap, 65),
        ], collect([$pricey, $cheap]));

        $this->assertNotNull($pick);
        $this->assertSame('KAL-P', $pick['product']->sku);
        $this->assertSame(96, $pick['score']);
    }

    /**
     * Ten sam procent modelu, obie karty z explain ≥ apply i bez twardych dowodów (bez SKU,
     * modelu, klasy), ale karta dowodząca wymagania (nitryl, ściągacz) wygrywa z tańszą, ogólną
     * kartą tej samej rodziny — cena rozstrzyga dopiero w oknie EVIDENCE_TIE_MARGIN.
     * Procent modelu celowo bliski explain ogólnej karty, żeby o wyniku nie decydowało
     * okno procentu (8 pkt), tylko okno dowodów.
     */
    #[Test]
    public function better_proven_card_beats_cheaper_vaguer_card_at_equal_percent(): void
    {
        $this->settings();
        $matcher = app(ProductMatchService::class);
        $requirement = 'Rękawice nitrylowe ze ściągaczem';
        $proven = $this->glove('NIT-FULL', 3.0);
        $vague = Product::query()->findOrFail(Product::query()->create([
            'sku' => 'RB-1',
            'name' => 'Rękawice robocze',
            'manufacturer' => 'REJS',
            'category' => 'Rękawice',
            'description' => 'Rękawice robocze wzmacniane, dzianina bawełniana, rozmiary 7-11.',
            'catalog_price_net' => 2.5,
            'purchase_price' => 2.0,
            'currency' => 'PLN',
            'stock' => 50,
            'enrichment_status' => Product::ENRICHMENT_DONE,
            'enriched_at' => now(),
        ])->id);
        $margin = (new \ReflectionClassConstant(ProductMatchService::class, 'EVIDENCE_TIE_MARGIN'))->getValue();
        $provenExplained = $matcher->explainMatch($requirement, $proven);
        $vagueExplained = $matcher->explainMatch($requirement, $vague);
        $this->assertGreaterThanOrEqual($matcher->applyMatchScore(), $vagueExplained['score'], 'ogólna karta ma przejść próg apply — test bada okno dowodów, nie odcięcie');
        $this->assertGreaterThan($margin, $provenExplained['score'] - $vagueExplained['score'], 'różnica dowodów musi przekraczać margines remisu');
        $this->assertSame(
            $this->invoke($matcher, 'hardEvidenceLevel', $requirement, $proven, $provenExplained),
            $this->invoke($matcher, 'hardEvidenceLevel', $requirement, $vague, $vagueExplained),
            'karty mają różnić się tylko dowodami miękkimi',
        );

        $pick = $this->invoke($matcher, 'pickAuto', $requirement, null, [
            $this->candidate($proven, 78),
            $this->candidate($vague, 78),
        ], collect([$proven, $vague]));

        $this->assertNotNull($pick);
        $this->assertSame('NIT-FULL', $pick['product']->sku);
    }

    /** Identyczne karty, równe dowody i równy procent → tańsza (intencja 976aefb zostaje). */
    #[Test]
    public function equal_evidence_and_percent_prefer_cheaper_card(): void
    {
        $this->settings();
        $matcher = app(ProductMatchService::class);
        $pricey = $this->apparel('KAL-P', 'Kalesony bawełniane męskie', 30.0);
        $cheap = $this->apparel('KAL-C', 'Kalesony bawełniane męskie', 10.0);

        $pick = $this->invoke($matcher, 'pickAuto', self::LONG_JOHNS, null, [
            $this->candidate($pricey, 92),
            $this->candidate($cheap, 92),
        ], collect([$pricey, $cheap]));

        $this->assertNotNull($pick);
        $this->assertSame('KAL-C', $pick['product']->sku);
    }

    /**
     * Twardy dowód (kod RNITZ z SIWZ trafia w SKU) rozstrzyga przed ceną nawet wtedy, gdy obie
     * karty saturują explainMatch — bliźniak bez kodu jest tańszy, ale nie jest tym, o co proszono.
     */
    #[Test]
    public function sku_hit_beats_cheaper_twin_at_equal_percent(): void
    {
        $this->settings();
        $matcher = app(ProductMatchService::class);
        $requirement = 'Rękawice robocze nitrylowe REJS RNITZ kat. 2 ze ściągaczem';
        $coded = $this->glove('RNITZ-9', 3.0);
        $twin = $this->glove('NIT-2', 2.0);
        $this->assertGreaterThanOrEqual(
            $matcher->minMatchScore(),
            $matcher->explainMatch($requirement, $twin)['score'],
            'bliźniak bez kodu ma być równie „udowodniony” — inaczej test nie sprawdza twardych dowodów',
        );

        $pick = $this->invoke($matcher, 'pickAuto', $requirement, null, [
            $this->candidate($coded, 92),
            $this->candidate($twin, 92),
        ], collect([$coded, $twin]));

        $this->assertNotNull($pick);
        $this->assertSame('RNITZ-9', $pick['product']->sku);
    }

    // ------------------------------------------------------ twarda bramka wierszy katalogowych

    /**
     * Wiersz listy katalogowej („Żargon SIWZ → …”, stały procent 55–88) i skrótu ('rule') nie
     * są oceną modelu. Dotąd przy explain ≥ apply przechodziły przez persistableScore mimo
     * wyłączonego ustawienia — bramka match_allow_catalog_rows działała tylko dla kart
     * o niskim explain (AUDYT_A 1.3c, przyczyna 8).
     */
    #[Test]
    public function catalog_and_rule_rows_are_dropped_when_admin_did_not_allow_them(): void
    {
        $this->settings();
        $matcher = app(ProductMatchService::class);
        $product = $this->apparel('KAL-1', 'Kalesony bawełniane męskie');
        $this->assertGreaterThanOrEqual(
            $matcher->applyMatchScore(),
            $matcher->explainMatch(self::LONG_JOHNS, $product)['score'],
            'karta ma wysoki explain — inaczej test nie sprawdza bramki, tylko progu',
        );

        foreach (['catalog', 'rule'] as $source) {
            $pick = $this->invoke($matcher, 'pickAuto', self::LONG_JOHNS, null, [$this->candidate($product, 66, $source)], collect([$product]));
            $this->assertNull($pick, "wiersz '{$source}' bez zgody admina nie może wypełnić pozycji");
        }
    }

    /** Za jawną zgodą admina wiersz katalogowy wypełnia pozycję jako wiersz katalogowy (D8). */
    #[Test]
    public function catalog_row_fills_the_line_with_explicit_admin_consent(): void
    {
        $this->settings(['match_allow_catalog_rows' => true]);
        $matcher = app(ProductMatchService::class);
        $product = $this->apparel('KAL-1', 'Kalesony bawełniane męskie');

        $pick = $this->invoke($matcher, 'pickAuto', self::LONG_JOHNS, null, [$this->candidate($product, 66, 'catalog')], collect([$product]));

        $this->assertNotNull($pick);
        $this->assertSame('KAL-1', $pick['product']->sku);
        $this->assertSame('catalog', $pick['source']);
        $this->assertSame(66, $pick['score']);
    }

    // ------------------------------------------------------------------------- pomocnicze

    /**
     * @param  array<string, mixed>  $extra
     */
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
            ...$extra,
        ]);
    }

    /**
     * Stub modelu dla fali przetargu (searchMany → chatJsonMany): „zrozum” bez odpowiedzi
     * (intencja lokalna), ranking z podanymi trafieniami. Pojedyncze wyszukiwanie (chatJson)
     * jest zakazane — oznaczałoby chybienie cache między prefetch a odczytem kandydatów.
     *
     * @param  list<array{id: int, score: int, reason: string}>  $matches
     */
    private function stubSearchModel(array $matches): void
    {
        $llm = Mockery::mock(OpenAiCompatibleClient::class);
        $llm->shouldReceive('chatJsonMany')->andReturnUsing(static function (array $messageSets) use ($matches): array {
            $out = [];
            foreach ($messageSets as $messages) {
                $out[] = FakeSearchLlm::kind($messages) === FakeSearchLlm::KIND_RANK ? ['matches' => $matches] : [];
            }

            return $out;
        });
        $llm->shouldNotReceive('chatJson');
        $llm->shouldNotReceive('chat');
        $this->app->instance(OpenAiCompatibleClient::class, $llm);
    }

    /**
     * Karty półmasek spoza fixture (z odpowiedzi wyszukiwarki poz. 13) — tylko rodzaj i nazwa.
     *
     * @param  array<string, string>  $bySku  sku => nazwa
     * @return array<string, int> sku => id
     */
    private function respirators(array $bySku): array
    {
        $ids = [];
        $price = 1.0;
        foreach ($bySku as $sku => $name) {
            $product = Product::query()->create([
                'sku' => $sku,
                'name' => $name,
                'manufacturer' => '3M',
                'category' => 'Ochrona dróg oddechowych',
                'description' => $name.' — ochrona układu oddechowego przed aerozolami.',
                'catalog_price_net' => $price,
                'purchase_price' => $price,
                'currency' => 'PLN',
                'stock' => 5,
                'enrichment_status' => Product::ENRICHMENT_DONE,
                'enriched_at' => now(),
            ]);
            $price += 1.0;
            $ids[$sku] = (int) $product->id;
        }

        return $ids;
    }

    /** Karta odzieży z opisem — kandydat do pozycji „Kalesony bawełniane męskie”. */
    private function apparel(string $sku, string $name, float $purchase = 20.0): Product
    {
        $product = Product::query()->create([
            'sku' => $sku,
            'name' => $name,
            'manufacturer' => 'Urgent',
            'category' => 'Odzież robocza',
            'description' => $name.' — wyrób dziewiarski z bawełny, rozmiary od S do XXXXL.',
            'catalog_price_net' => $purchase * 1.25,
            'purchase_price' => $purchase,
            'currency' => 'PLN',
            'stock' => 5,
            'enrichment_status' => Product::ENRICHMENT_DONE,
            'enriched_at' => now(),
        ]);

        return Product::query()->findOrFail($product->id);
    }

    /** Rękawice nitrylowe REJS kat. 2 — ta sama karta pod różnym SKU (z kodem z SIWZ albo bez). */
    private function glove(string $sku, float $purchase): Product
    {
        $product = Product::query()->create([
            'sku' => $sku,
            'name' => 'Rękawice nitrylowe ze ściągaczem',
            'manufacturer' => 'REJS',
            'category' => 'Rękawice',
            'description' => 'Rękawice robocze nitrylowe kat. 2 ze ściągaczem. Materiał: nitryl.',
            'enrichment_payload' => ['materials' => ['nitryl']],
            'catalog_price_net' => $purchase * 1.25,
            'purchase_price' => $purchase,
            'currency' => 'PLN',
            'stock' => 50,
            'enrichment_status' => Product::ENRICHMENT_DONE,
            'enriched_at' => now(),
        ]);

        return Product::query()->findOrFail($product->id);
    }

    /**
     * Wiersz kandydata AI w kształcie, jaki pickAuto dostaje z aiTopCandidates.
     *
     * @return array{id: int, sku: string, name: string, score: int, reason: ?string, source: string}
     */
    private function candidate(Product $product, int $score, string $source = 'ai'): array
    {
        return [
            'id' => (int) $product->id,
            'sku' => (string) $product->sku,
            'name' => (string) $product->name,
            'score' => $score,
            'reason' => null,
            'source' => $source,
        ];
    }

    private function invoke(ProductMatchService $matcher, string $method, mixed ...$args): mixed
    {
        return (fn () => $this->{$method}(...$args))->call($matcher);
    }
}
