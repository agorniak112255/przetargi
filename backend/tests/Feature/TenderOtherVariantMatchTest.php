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
use App\Services\ProductMatchService;
use App\Support\PpeAssortment;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Laravel\Sanctum\Sanctum;
use Mockery;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * Inny wariant nazwanego modelu (pod „ARMEN 9007 1010 S1” kolor 6660) nie jest zapisem automatu przetargu na żadnej
 * drodze wyboru — najwyżej propozycją poniżej progu, jak w wyszukiwarce. Dwa przeglądy 26.09.2026 odtworzyły przez ten
 * sam punkt API: kolor 6660 wchodził 99% przez heurystykę, 60% przez wiersz wyszukiwania i 70% przez heurystykę bez
 * modelu; przy braku żądanego wariantu mógł wejść inny wyrób dobrany po słowach; numery serii („serii 6000 i 7500”)
 * i rozporządzeń udawały kod wariantu; „tylko inny wariant” wynikało z przyciętej puli.
 */
final class TenderOtherVariantMatchTest extends TestCase
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
        $this->llm([]);
    }

    public function test_requested_variant_is_saved_before_cheaper_other_colours(): void
    {
        $this->shoe('ARMEN 9007 6660 S1', 28.67);
        $this->shoe('ARMEN 9007 Clip 1010 S1', 29.69);
        $this->shoe('ARMEN 9007 1010 S1', 30.44);

        $item = $this->match(self::REQUIREMENT);

        $this->assertSame('ARMEN 9007 1010 S1', $item->mainProduct?->sku, 'kod przepisany przez klienta, nie tańszy 6660 ani Clip');
        $this->assertGreaterThanOrEqual($this->minScore(), (int) $item->ai_match_percent);
    }

    public function test_only_other_variants_end_as_proposal_below_threshold(): void
    {
        $this->shoe('ARMEN 9007 6660 S1', 100);
        $this->shoe('ARMEN 9007 9360 S1', 110);

        $item = $this->match(self::REQUIREMENT);

        $this->assertSame('ARMEN 9007 6660 S1', $item->mainProduct?->sku, 'propozycja: inny kolor z wyniku wyszukiwania');
        $this->assertLessThan($this->minScore(), (int) $item->ai_match_percent, 'inny kolor nie jest zapisem');
        $this->assertSame(ProductMatchService::PROPOSAL, $item->ai_match_reasons[0]['code'] ?? null);
        $this->assertStringContainsString('inny wariant', (string) ($item->ai_match_reasons[0]['label'] ?? ''));
        $this->assertStringNotContainsString('ocena modelu', (string) ($item->ai_match_reasons[0]['label'] ?? ''), 'model tej karty nie oceniał');
    }

    public function test_other_variant_without_description_does_not_let_an_unrelated_card_in(): void
    {
        $this->shoe('ARMEN 9007 6660 S1', 100, null);
        $this->shoe('ART 100 S1', 50, 'Półbuty bezpieczne ARTRA S1 z podnoskiem, obuwie robocze.');

        $item = $this->match(self::REQUIREMENT);

        $this->assertNull($item->main_product_id, 'inny wyrób dobrany po słowach karty nie zastępuje nazwanego modelu');
        $this->assertSame(ProductMatchService::NO_MATCH_OTHER_VARIANT, $item->ai_match_reasons[0]['code'] ?? null);
        $this->assertStringContainsString('1010', (string) ($item->ai_match_reasons[0]['label'] ?? ''));
        $this->assertStringContainsString('ARMEN 9007 6660 S1', (string) ($item->ai_match_reasons[0]['label'] ?? ''));
    }

    /**
     * Kolor zapisany przez automat przed poprawką (99%) nie zostaje trafieniem po ponownym dopasowaniu. Propozycja
     * (tańszy 6660) nie wypiera zapisanej karty 9360, więc zadziałać musi sufit w markExistingNotReconfirmed.
     */
    public function test_full_rematch_demotes_other_variant_saved_before(): void
    {
        $this->shoe('ARMEN 9007 6660 S1', 100);
        $saved = $this->shoe('ARMEN 9007 9360 S1', 110);

        $item = $this->match(self::REQUIREMENT, [
            'main_product_id' => $saved->id,
            'ai_match_percent' => 99,
            'match_source' => 'heuristic',
            'status' => 'matched',
        ]);

        $this->assertSame((int) $saved->id, (int) $item->main_product_id, 'fixture: zapisana karta zostaje w pozycji');
        $this->assertLessThan($this->minScore(), (int) $item->ai_match_percent);
        $this->assertStringContainsString('inny wariant', (string) ($item->ai_match_reasons[0]['label'] ?? ''));
    }

    public function test_manual_choice_of_other_variant_survives_rematch(): void
    {
        $chosen = $this->shoe('ARMEN 9007 6660 S1', 100);
        $this->shoe('ARMEN 9007 9360 S1', 110);

        $item = $this->match(self::REQUIREMENT, [
            'main_product_id' => $chosen->id,
            'ai_match_percent' => 99,
            'match_source' => 'manual',
            'status' => 'matched',
        ]);

        $this->assertSame((int) $chosen->id, (int) $item->main_product_id, 'wybór człowieka zostaje');
        $this->assertSame('manual', $item->match_source);
        $this->assertSame(99, (int) $item->ai_match_percent);
    }

    /**
     * Numer rozporządzenia to nie oznaczenie wariantu: z „(UE) 2016/425” wszystkie karty modelu wyglądały na inny
     * wariant (brak „2016”) i wracał stary błąd.
     */
    public function test_regulation_clause_does_not_hide_the_requested_variant(): void
    {
        $this->shoe('ARMEN 9007 6660 S1', 28.67);
        $this->shoe('ARMEN 9007 Clip 1010 S1', 29.69);
        $this->shoe('ARMEN 9007 1010 S1', 30.44);

        $item = $this->match(self::REQUIREMENT.' zgodne z rozporządzeniem (UE) 2016/425');

        $this->assertSame('ARMEN 9007 1010 S1', $item->mainProduct?->sku);
    }

    /** Karta wskazana nazwanym modelem bez opisu: pozycja czeka na opis, także z klauzulą rozporządzenia. */
    public function test_named_card_without_description_still_waits_with_regulation_clause(): void
    {
        $this->shoe('ARMEN 9007 S1', 100, null);
        $this->shoe('ART 100 S1', 50, 'Półbuty bezpieczne ARTRA S1 z podnoskiem, obuwie robocze.');

        $item = $this->match('Półbuty ARTRA ARMEN 9007 S1 zgodne z rozporządzeniem (UE) 2016/425');

        $this->assertNull($item->main_product_id);
        $this->assertSame(ProductMatchService::NO_MATCH_NO_DESCRIPTION, $item->ai_match_reasons[0]['code'] ?? null);
    }

    /** Numery serii półmasek za filtrem to nie oznaczenie wariantu filtra — pochłaniacz 6059 dalej jest zapisem. */
    public function test_series_list_after_the_filter_does_not_block_it(): void
    {
        $this->product([
            'sku' => '6059',
            'name' => 'Pochłaniacz 3M 6059 A2B2E2K2',
            'manufacturer' => '3M',
            'category' => 'Pochłaniacze',
            'ppe_family' => PpeAssortment::FAMILY_RESPIRATORY,
            'description' => 'Pochłaniacz 3M 6059 ABEK1 do półmasek i masek pełnotwarzowych 3M, złącze bagnetowe.',
            'norms' => 'EN 14387',
        ]);

        $item = $this->match('Pochłaniacz 3M 6059 ABEK1 do półmasek 3M serii 6000 i 7500');

        $this->assertSame('6059', $item->mainProduct?->sku);
        $this->assertGreaterThanOrEqual($this->minScore(), (int) $item->ai_match_percent);
    }

    /** „Lub równoważne” z kodem koloru: wyrób innej marki nie jest „innym wariantem” i dalej jest zamiennikiem. */
    public function test_cross_brand_equivalent_is_still_saved_with_colour_code(): void
    {
        $sub = $this->product([
            'sku' => 'BRS-S1',
            'name' => 'Półbuty bezpieczne REIS BRS S1',
            'manufacturer' => 'REIS',
            'category' => 'Obuwie',
            'ppe_family' => PpeAssortment::FAMILY_FOOTWEAR,
            'description' => 'Półbuty bezpieczne S1 z podnoskiem, obuwie robocze.',
            'norms' => 'EN ISO 20345 S1',
        ]);
        $this->llm([['id' => $sub->id, 'score' => 90, 'reason' => 'półbuty S1 równoważne']]);

        $item = $this->match(self::REQUIREMENT.' lub równoważne');

        $this->assertSame('BRS-S1', $item->mainProduct?->sku);
        $this->assertGreaterThanOrEqual($this->minScore(), (int) $item->ai_match_percent);
    }

    public function test_cross_brand_equivalent_of_absent_model_with_catalogue_number_is_saved(): void
    {
        $sub = $this->product([
            'sku' => '11-541',
            'name' => 'Rękawice antyprzecięciowe Ansell HyFlex 11-541',
            'manufacturer' => 'Ansell',
            'category' => 'Rękawice',
            'ppe_family' => PpeAssortment::FAMILY_GLOVES,
            'description' => 'Rękawice antyprzecięciowe powlekane PU, EN 388 4X42C, do prac precyzyjnych z ostrymi krawędziami.',
            'norms' => 'EN 388',
        ]);
        $this->llm([['id' => $sub->id, 'score' => 90, 'reason' => 'rękawica antyprzecięciowa, równoważna']]);

        $item = $this->match('Rękawice antyprzecięciowe ATG MaxiFlex Cut 34-8743 lub równoważne, EN 388');

        $this->assertSame('11-541', $item->mainProduct?->sku);
    }

    /**
     * Pula po kodzie to LIKE z limitem 80: przy 130 kartach „… 1010 …” innych modeli żądany ARMEN 9007 1010 wypadał
     * z niej, zostawał 6660 i wychodziło „w katalogu tylko inny wariant”, choć wyszukiwarka dawała 1010 99%.
     */
    public function test_truncated_code_pool_still_finds_the_requested_variant(): void
    {
        $this->shoe('ARMEN 9007 6660 S1', 20);
        $this->manyArica();
        $this->shoe('ARMEN 9007 1010 S1', 30);

        $item = $this->match(self::REQUIREMENT);

        $this->assertSame('ARMEN 9007 1010 S1', $item->mainProduct?->sku);
        $this->assertGreaterThanOrEqual($this->minScore(), (int) $item->ai_match_percent);
    }

    public function test_truncated_code_pool_waits_for_description_of_requested_variant(): void
    {
        $this->shoe('ARMEN 9007 6660 S1', 20);
        $this->manyArica();
        $this->shoe('ARMEN 9007 1010 S1', 30, null);

        $item = $this->match(self::REQUIREMENT);

        $this->assertNull($item->main_product_id);
        $this->assertSame(ProductMatchService::NO_MATCH_NO_DESCRIPTION, $item->ai_match_reasons[0]['code'] ?? null);
    }

    /**
     * Pula po kodzie bierze tylko karty z rodziny wymagania, a karta bez rodziny (nowy import przed klasyfikacją) jest
     * tylko w wyniku wyszukiwania — żądany wariant stamtąd wygrywa z innym kolorem z puli.
     */
    public function test_requested_variant_found_only_by_search_is_saved(): void
    {
        $this->shoe('ARMEN 9007 6660 S1', 20);
        $requested = $this->shoe('ARMEN 9007 1010 S1', 30);
        $requested->forceFill(['ppe_family' => null])->save();

        $item = $this->match(self::REQUIREMENT);

        $this->assertSame('ARMEN 9007 1010 S1', $item->mainProduct?->sku);
        $this->assertGreaterThanOrEqual($this->minScore(), (int) $item->ai_match_percent);
    }

    /**
     * Przy samych innych kolorach SKU innego wyrobu równe kodowi koloru albo numerowi modelu to przypadek, nie kod
     * z SIWZ — przegląd 26.09.2026: taka karta wchodziła do oferty z 85%, a bliższy wyrób (inny kolor tego modelu)
     * był tylko propozycją.
     */
    #[DataProvider('modelDesignationPartSkus')]
    public function test_unrelated_card_whose_sku_is_part_of_the_model_designation_is_not_saved(string $sku): void
    {
        $this->shoe('ARMEN 9007 6660 S1', 28.67);
        $this->shoe('ARMEN 9007 9360 S1', 29.0);
        $this->product([
            'sku' => $sku,
            'name' => 'Półbuty robocze S1 czarne',
            'manufacturer' => 'OTHER',
            'category' => 'Obuwie',
            'ppe_family' => PpeAssortment::FAMILY_FOOTWEAR,
            'description' => 'Półbuty bezpieczne S1 czarne z podnoskiem.',
            'norms' => 'EN ISO 20345 S1',
            'purchase_price' => 15,
            'catalog_price_net' => 22.5,
        ]);

        $item = $this->match(self::REQUIREMENT);

        $this->assertContains($item->mainProduct?->sku, ['ARMEN 9007 6660 S1', 'ARMEN 9007 9360 S1'], 'propozycja innego koloru, nie inny wyrób');
        $this->assertLessThan($this->minScore(), (int) $item->ai_match_percent);
    }

    /** @return array<string, array{string}> */
    public static function modelDesignationPartSkus(): array
    {
        return [
            'kod koloru' => ['1010'],
            'kod koloru z dopiskiem' => ['1010-S1'],
            'numer modelu' => ['9007'],
        ];
    }

    /** Ta sama zasada dla kart z wyniku wyszukiwania (karta bez rodziny, spoza puli po kodzie). */
    public function test_unrelated_card_with_colour_code_sku_from_search_is_not_a_code_match(): void
    {
        $this->shoe('ARMEN 9007 6660 S1', 28.67);
        $unrelated = $this->product([
            'sku' => '1010',
            'name' => 'Półbuty robocze S1 czarne',
            'manufacturer' => 'OTHER',
            'category' => 'Obuwie',
            'description' => 'Półbuty bezpieczne S1 czarne z podnoskiem.',
            'norms' => 'EN ISO 20345 S1',
        ]);
        $matcher = app(ProductMatchService::class);

        $matches = (new \ReflectionMethod($matcher, 'withSearchMatches'))->invoke(
            $matcher,
            self::REQUIREMENT,
            [],
            [['id' => (int) $unrelated->id, 'sku' => '1010', 'name' => (string) $unrelated->name, 'score' => 90, 'reason' => null, 'source' => 'ai']],
            Product::query()->where('ppe_family', PpeAssortment::FAMILY_FOOTWEAR)->get(),
        );

        $this->assertSame([], $matches);
    }

    /**
     * Karta modelu zapisana inaczej („ARMEN czarne S1 (9007/1010)”, SKU „9007”) należy do modelu, więc numer modelu
     * dalej jest jej dowodem kodu — wykluczenie części oznaczenia dotyczy tylko kart innych wyrobów.
     */
    public function test_card_of_the_model_written_apart_keeps_its_code_evidence(): void
    {
        $this->shoe('ARMEN 9007 6660 S1', 28.67);
        $this->product([
            'sku' => '9007',
            'name' => 'Półbuty ARTRA ARMEN czarne S1 (9007/1010)',
            'manufacturer' => 'ARTRA',
            'category' => 'Obuwie',
            'ppe_family' => PpeAssortment::FAMILY_FOOTWEAR,
            'description' => 'Półbuty bezpieczne ARMEN czarne S1 z podnoskiem.',
            'norms' => 'EN ISO 20345 S1',
        ]);

        $item = $this->match(self::REQUIREMENT);

        $this->assertSame('9007', $item->mainProduct?->sku);
        $this->assertGreaterThanOrEqual($this->minScore(), (int) $item->ai_match_percent);
    }

    /**
     * Linia bez igły z cyfrą (MASCOT ACCELERATE) nie ma dowodu z nazwanego modelu, więc karcie modelu z SKU równym
     * kodowi koloru zostaje tylko dowód kodu — wykluczenie części oznaczenia jej nie dotyczy, bo należy do modelu.
     */
    public function test_model_card_whose_sku_is_the_colour_code_stays_a_code_match(): void
    {
        $card = $this->product([
            'sku' => '1809',
            'name' => 'MASCOT ACCELERATE Kurtka membranowa 19999-249-1809',
            'manufacturer' => 'MASCOT',
            'category' => 'Odzież robocza',
            'ppe_family' => PpeAssortment::FAMILY_APPAREL,
            'description' => 'Kurtka membranowa MASCOT ACCELERATE, ciemny antracyt/czerń.',
        ]);
        $matcher = app(ProductMatchService::class);

        $matches = (new \ReflectionMethod($matcher, 'strongSkuMatches'))->invoke(
            $matcher,
            'Kurtka membranowa MASCOT ACCELERATE 19999-249-1809, ciemny antracyt/czerń',
            Product::query()->get(),
        );

        $this->assertSame([(int) $card->id], array_map(static fn (array $match): int => (int) $match['product']->id, $matches));
    }

    /** Bez oznaczenia wariantu nic się nie zmienia: numer modelu dalej jest dowodem kodu, jak przed poprawką. */
    public function test_without_variant_code_model_number_sku_keeps_its_code_evidence(): void
    {
        $card = $this->product([
            'sku' => '9007',
            'name' => 'Półbuty robocze S1 czarne',
            'manufacturer' => 'OTHER',
            'category' => 'Obuwie',
            'ppe_family' => PpeAssortment::FAMILY_FOOTWEAR,
            'description' => 'Półbuty bezpieczne S1 czarne z podnoskiem.',
            'norms' => 'EN ISO 20345 S1',
        ]);
        $matcher = app(ProductMatchService::class);

        $matches = (new \ReflectionMethod($matcher, 'strongSkuMatches'))->invoke($matcher, 'Półbuty ARTRA ARMEN 9007 S1', Product::query()->get());

        $this->assertSame([[(int) $card->id, 85]], array_map(
            static fn (array $match): array => [(int) $match['product']->id, $match['score']],
            $matches
        ));
    }

    /** Numer katalogowy spoza oznaczenia modelu („nr kat. 900710104”) dalej wskazuje kartę, choć jej nazwa nie ma modelu. */
    public function test_catalogue_number_outside_the_model_designation_still_picks_the_card(): void
    {
        $this->shoe('ARMEN 9007 6660 S1', 28.67);
        $this->product([
            'sku' => '900710104',
            'name' => 'Półbuty robocze S1 czarne',
            'manufacturer' => 'ARTRA',
            'category' => 'Obuwie',
            'ppe_family' => PpeAssortment::FAMILY_FOOTWEAR,
            'description' => 'Półbuty bezpieczne S1 czarne z podnoskiem.',
            'norms' => 'EN ISO 20345 S1',
        ]);

        $item = $this->match(self::REQUIREMENT.' nr kat. 900710104');

        $this->assertSame('900710104', $item->mainProduct?->sku);
        $this->assertGreaterThanOrEqual($this->minScore(), (int) $item->ai_match_percent);
    }

    /**
     * Ten sam model w dwóch kolorach do wyboru („1010 lub 6660”): obie karty są żądanym wariantem, więc automat
     * zapisuje jedną z nich, a nie tańszy trzeci kolor ani propozycję „inny wariant”.
     */
    #[DataProvider('alternativeColours')]
    public function test_alternative_colours_of_one_model_are_both_requested(string $requirement): void
    {
        $this->shoe('ARMEN 9007 6660 S1', 28.67);
        $this->shoe('ARMEN 9007 1010 S1', 30.44);
        $this->shoe('ARMEN 9007 9360 S1', 25.0);

        $item = $this->match($requirement);

        $this->assertContains($item->mainProduct?->sku, ['ARMEN 9007 6660 S1', 'ARMEN 9007 1010 S1']);
        $this->assertGreaterThanOrEqual($this->minScore(), (int) $item->ai_match_percent);
    }

    /**
     * „w kolorze” przy innej rzeczy z tej samej pozycji (sznurówki) nie robi z butów 6660 żądanego wariantu — trzeci
     * przegląd 26.09.2026: przy kolorach do wyboru kotwica „kolor” wygrywała z kodem stojącym przy modelu.
     */
    public function test_colour_of_another_item_in_the_line_does_not_make_that_colour_requested(): void
    {
        $this->shoe('ARMEN 9007 6660 S1', 28.67);
        $this->shoe('ARMEN 9007 1010 S1', 30.44);

        $item = $this->match('Półbuty ARTRA ARMEN 9007 1010 S1, sznurówki zapasowe w kolorze 6660');

        $this->assertSame('ARMEN 9007 1010 S1', $item->mainProduct?->sku);
        $this->assertGreaterThanOrEqual($this->minScore(), (int) $item->ai_match_percent);
    }

    /** @return array<string, array{string}> */
    public static function alternativeColours(): array
    {
        return [
            'lub' => ['Półbuty ARTRA ARMEN 9007 1010 S1 lub ARMEN 9007 6660 S1'],
            'przecinek' => ['Półbuty ARTRA ARMEN 9007 1010 S1, ARMEN 9007 6660 S1'],
        ];
    }

    /** Powód „tylko inny wariant” nie zdejmuje z pozycji zapisanej karty z żądanym wariantem. */
    public function test_saved_requested_variant_is_never_dropped_for_other_variant_reason(): void
    {
        $other = $this->shoe('ARMEN 9007 6660 S1', 20);
        $requested = $this->shoe('ARMEN 9007 1010 S1', 30);
        $item = TenderItem::query()->create([
            'tender_id' => $this->tender('PRZ/WARIANT/ZAPIS')->id,
            'line_no' => 1,
            'requirement' => self::REQUIREMENT,
            'quantity' => 1,
            'main_product_id' => $requested->id,
            'ai_match_percent' => 99,
            'match_source' => 'heuristic',
            'status' => 'matched',
        ]);
        $matcher = app(ProductMatchService::class);
        $state = new \ReflectionProperty($matcher, 'lastOtherVariantOnly');
        $state->setValue($matcher, ['codes' => ['1010'], 'ids' => [(int) $other->id], 'skus' => ['ARMEN 9007 6660 S1']]);

        (new \ReflectionMethod($matcher, 'applyNoCatalogMatch'))->invoke($matcher, $item, Product::query()->get());

        $this->assertSame((int) $requested->id, (int) $item->fresh()->main_product_id);
    }

    /**
     * Sufit innego wariantu w markExistingNotReconfirmed działa też bez wiersza wyszukiwania tej karty (model nie
     * odpowiedział, karta spoza wyniku) — inaczej zostawało 70%, czyli trafienie.
     */
    public function test_saved_other_variant_is_capped_without_a_search_row(): void
    {
        $saved = $this->shoe('ARMEN 9007 9360 S1', 110);
        $item = TenderItem::query()->create([
            'tender_id' => $this->tender('PRZ/WARIANT/SUFIT')->id,
            'line_no' => 1,
            'requirement' => self::REQUIREMENT,
            'quantity' => 1,
            'main_product_id' => $saved->id,
            'ai_match_percent' => 99,
            'match_source' => 'heuristic',
            'status' => 'matched',
        ]);
        $matcher = app(ProductMatchService::class);

        (new \ReflectionMethod($matcher, 'markExistingNotReconfirmed'))->invoke($matcher, $item, $saved, 99);

        $this->assertLessThan($this->minScore(), (int) $item->ai_match_percent);
        $this->assertStringContainsString('inny wariant', (string) ($item->ai_match_reasons[0]['label'] ?? ''));
    }

    /** Inna karta automatyczna (inny wyrób) nie zostaje w pozycji, gdy nazwany model jest w katalogu tylko w innym wariancie. */
    public function test_unrelated_automatic_card_is_dropped_when_only_other_variant_exists(): void
    {
        $this->shoe('ARMEN 9007 6660 S1', 100, null);
        $unrelated = $this->shoe('ART 100 S1', 50, 'Półbuty bezpieczne ARTRA S1 z podnoskiem, obuwie robocze.');

        $item = $this->match(self::REQUIREMENT, [
            'main_product_id' => $unrelated->id,
            'ai_match_percent' => 80,
            'match_source' => 'ai',
            'status' => 'matched',
        ]);

        $this->assertNull($item->main_product_id);
        $this->assertSame(ProductMatchService::NO_MATCH_OTHER_VARIANT, $item->ai_match_reasons[0]['code'] ?? null);
    }

    /** Model ocenił inny kolor wysoko — to dalej propozycja poniżej progu, a nie pusta pozycja ani zapis. */
    public function test_other_variant_rated_high_by_the_model_is_still_a_proposal(): void
    {
        $other = $this->shoe('ARMEN 9007 6660 S1', 100);
        $this->llm([['id' => $other->id, 'score' => 95, 'reason' => 'półbuty ARMEN 9007 S1']]);

        $item = $this->match(self::REQUIREMENT);

        $this->assertSame('ARMEN 9007 6660 S1', $item->mainProduct?->sku);
        $this->assertLessThan($this->minScore(), (int) $item->ai_match_percent);
        $this->assertSame(ProductMatchService::PROPOSAL, $item->ai_match_reasons[0]['code'] ?? null);
    }

    /** Heurystyka (bestMatch) bierze żądany wariant przed tańszym innym kolorem — ją dostaje pickAuto i ekran pozycji. */
    public function test_heuristic_best_match_prefers_requested_variant_over_cheaper_colour(): void
    {
        $this->shoe('ARMEN 9007 6660 S1', 20);
        $this->shoe('ARMEN 9007 1010 S1', 30);

        $best = app(ProductMatchService::class)->bestMatch(self::REQUIREMENT, Product::query()->get());

        $this->assertSame('ARMEN 9007 1010 S1', $best['product']->sku ?? null);
    }

    /**
     * Bez modelu (AI wyłączone) nie ma wyniku wyszukiwania — przyciętą pulę po kodzie ratuje tylko kolejność: karta
     * z numerem modelu i kodem wariantu przed 130 kartami z samym „1010”.
     */
    public function test_truncated_code_pool_without_search_keeps_the_requested_variant(): void
    {
        AiSetting::query()->update(['enabled' => false]);
        $this->shoe('ARMEN 9007 6660 S1', 20);
        $this->manyArica();
        $this->shoe('ARMEN 9007 1010 S1', 30);

        $item = $this->match(self::REQUIREMENT);

        $this->assertSame('ARMEN 9007 1010 S1', $item->mainProduct?->sku);
    }

    /**
     * Propozycja przy braku żądanego wariantu to tylko inny wariant modelu — nie wyrób innej marki, który w wyniku
     * wyszukiwania stoi w paśmie propozycji (40–64) obok koloru bez opisu.
     */
    public function test_proposal_for_missing_variant_is_never_another_product(): void
    {
        $other = $this->shoe('ARMEN 9007 6660 S1', 100, null);
        $sub = $this->product([
            'sku' => 'BRS-S1',
            'name' => 'Półbuty bezpieczne REIS BRS S1',
            'manufacturer' => 'REIS',
            'category' => 'Obuwie',
            'ppe_family' => PpeAssortment::FAMILY_FOOTWEAR,
            'description' => 'Półbuty bezpieczne S1 z podnoskiem, obuwie robocze.',
            'norms' => 'EN ISO 20345 S1',
        ]);
        $matcher = app(ProductMatchService::class);

        $pick = (new \ReflectionMethod($matcher, 'otherVariantProposal'))->invoke(
            $matcher,
            self::REQUIREMENT,
            [['product' => $other, 'score' => 99]],
            [
                ['id' => (int) $other->id, 'sku' => (string) $other->sku, 'name' => (string) $other->name, 'score' => 60, 'reason' => null, 'source' => 'ai'],
                ['id' => (int) $sub->id, 'sku' => (string) $sub->sku, 'name' => (string) $sub->name, 'score' => 55, 'reason' => null, 'source' => 'ai'],
            ],
            Product::query()->get(),
        );

        $this->assertNull($pick, 'REIS nie jest propozycją za brakujący kolor ARMEN');
    }

    /** Próg zapisu z ustawień poniżej 60 — propozycja innego wariantu dalej jest poniżej niego, nie trafieniem. */
    public function test_other_variant_proposal_stays_below_a_low_threshold(): void
    {
        AiSetting::query()->update(['match_min_score' => 55]);
        $this->shoe('ARMEN 9007 6660 S1', 100);

        $item = $this->match(self::REQUIREMENT);

        $this->assertSame('ARMEN 9007 6660 S1', $item->mainProduct?->sku);
        $this->assertLessThan(55, (int) $item->ai_match_percent);
    }

    /** Wiersz innego wariantu z oceną ponad pasmo propozycji (95) jest przycinany, więc propozycja nie znika. */
    public function test_other_variant_row_above_the_proposal_band_is_clamped(): void
    {
        $other = $this->shoe('ARMEN 9007 6660 S1', 100);
        $matcher = app(ProductMatchService::class);

        $pick = (new \ReflectionMethod($matcher, 'otherVariantProposal'))->invoke(
            $matcher,
            self::REQUIREMENT,
            [['product' => $other, 'score' => 99]],
            [['id' => (int) $other->id, 'sku' => (string) $other->sku, 'name' => (string) $other->name, 'score' => 95, 'reason' => null, 'source' => 'ai']],
            Product::query()->get(),
        );

        $this->assertSame('ARMEN 9007 6660 S1', $pick['product']->sku ?? null);
        $this->assertTrue($pick['proposal'] ?? false);
        $this->assertLessThan($this->minScore(), (int) ($pick['score'] ?? 100));
    }

    /** tenders:eval i tenders:debug-match (debugPick) pokazują ten sam werdykt i tę samą decyzję co dopasowanie. */
    public function test_debug_pick_reports_other_variant_verdict_and_proposal(): void
    {
        $other = $this->shoe('ARMEN 9007 6660 S1', 100);
        $item = new TenderItem;
        $item->forceFill(['requirement' => self::REQUIREMENT]);

        $debug = app(ProductMatchService::class)->debugPick($item, [
            'products' => [['id' => (int) $other->id, 'sku' => (string) $other->sku, 'name' => (string) $other->name, 'ai_match_percent' => 60, 'ai_match_source' => 'ai']],
        ]);

        $this->assertStringContainsString('inny wariant', (string) ($debug['candidates'][0]['verdict'] ?? ''));
        $this->assertSame('ARMEN 9007 6660 S1', $debug['pick']['sku'] ?? null);
        $this->assertTrue($debug['pick']['proposal'] ?? false);
    }

    /** Strażnik gałęzi heurystyki z kodem i gałęzi heurystyki bez modelu (każdy sam z siebie). */
    public function test_pick_auto_heuristic_branches_do_not_save_other_variant(): void
    {
        $other = $this->shoe('ARMEN 9007 6660 S1', 20);
        $this->shoe('ARMEN 9007 9360 S1', 25);
        $matcher = app(ProductMatchService::class);

        $pick = (new \ReflectionMethod($matcher, 'pickAuto'))->invoke(
            $matcher,
            self::REQUIREMENT,
            ['product' => $other, 'score' => 99],
            [],
            Product::query()->get(),
        );

        $this->assertTrue($pick === null || ($pick['proposal'] ?? false), 'inny kolor z heurystyki nie jest zapisem');
    }

    /** Strażnik pętli ocen modelu: inny kolor oceniony przez model na 95 nie jest zapisem. */
    public function test_pick_auto_model_rows_do_not_save_other_variant(): void
    {
        $other = $this->shoe('ARMEN 9007 6660 S1', 20);
        $matcher = app(ProductMatchService::class);

        $pick = (new \ReflectionMethod($matcher, 'pickAuto'))->invoke(
            $matcher,
            self::REQUIREMENT,
            null,
            [['id' => (int) $other->id, 'sku' => (string) $other->sku, 'name' => (string) $other->name, 'score' => 95, 'reason' => null, 'source' => 'ai']],
            Product::query()->get(),
        );

        $this->assertTrue($pick === null || ($pick['proposal'] ?? false), 'wiersz modelu z innym kolorem nie jest zapisem');
    }

    /**
     * @param  list<array{id: int, score: int, reason: string}>  $matches
     */
    private function llm(array $matches): void
    {
        $llm = Mockery::mock(OpenAiCompatibleClient::class);
        $llm->shouldReceive('chatJson')->andReturn(['matches' => $matches]);
        $llm->shouldReceive('chatJsonMany')->andReturnUsing(
            static fn (array $sets): array => array_fill(0, count($sets), ['matches' => $matches])
        );
        $this->app->instance(OpenAiCompatibleClient::class, $llm);
    }

    private function minScore(): int
    {
        return app(ProductMatchService::class)->minMatchScore();
    }

    private function tender(string $number): Tender
    {
        return Tender::query()->create([
            'number' => $number,
            'title' => 'Wariant',
            'client_id' => Client::query()->create(['name' => 'Klient'])->id,
            'owner_id' => User::factory()->create()->id,
            'status' => 'wycena',
            'ai_percent' => 0,
            'last_activity_at' => now(),
        ]);
    }

    /**
     * @param  array<string, mixed>  $existing
     */
    private function match(string $requirement, array $existing = []): TenderItem
    {
        $tender = $this->tender('PRZ/WARIANT/'.md5($requirement.json_encode($existing)));
        $item = TenderItem::query()->create([
            'tender_id' => $tender->id,
            'line_no' => 1,
            'requirement' => $requirement,
            'quantity' => 1,
            'status' => 'brak',
            ...$existing,
        ]);

        $this->postJson("/api/tenders/{$tender->id}/match", ['only_empty' => false])->assertOk();

        return $item->fresh(['mainProduct']);
    }

    private function manyArica(): void
    {
        for ($i = 0; $i < 130; $i++) {
            $this->shoe('ARICA '.(6000 + $i).' 1010 S2', 40 + $i);
        }
    }

    private function shoe(string $sku, float $purchase, ?string $description = 'default'): Product
    {
        return $this->product([
            'sku' => $sku,
            'name' => $sku,
            'manufacturer' => 'ARTRA',
            'category' => 'Obuwie',
            'description' => $description === 'default' ? 'Półbuty bezpieczne '.$sku.' z podnoskiem.' : $description,
            'norms' => 'EN ISO 20345 S1',
            'ppe_family' => PpeAssortment::FAMILY_FOOTWEAR,
            'catalog_price_net' => $purchase * 1.5,
            'purchase_price' => $purchase,
        ]);
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    private function product(array $attributes): Product
    {
        return Product::query()->create($attributes + [
            'catalog_price_net' => 30,
            'purchase_price' => 20,
            'currency' => 'PLN',
            'stock' => 5,
            'enrichment_status' => Product::ENRICHMENT_DONE,
            'enriched_at' => now()->subYear(),
        ]);
    }
}
