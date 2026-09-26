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
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Support\FakeSearchLlm;
use Tests\TestCase;

/**
 * Decyzje właściciela z 25.09.2026 (diagnoza najsłabszych przypadków golden setu): propozycja w innym wariancie
 * (kolor ARTRA 6660 pod zapytaniem o 1010) zostaje w wyniku, ale wiersz mówi wprost, czym się różni — handlowiec
 * widział dotąd tylko „nie ma oznaczenia z zapytania”. Karta innego producenta niż nazwany w wymaganiu miała na liście
 * zapasowej dopisek; od decyzji D5 z 26.09.2026 lista zapasowa wyszukiwarki pokazuje tylko producenta z wymagania.
 */
final class ProductAiSearchRowNotesTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->freezeTime();
        $this->seed(RolesAndPermissionsSeeder::class);
        Sanctum::actingAs(User::factory()->withRole('admin')->create());
    }

    public function test_other_variant_row_names_requested_and_card_codes(): void
    {
        foreach (['ARMEN 9007 1010 S1', 'ARMEN 9007 6660 S1'] as $sku) {
            $this->shoe($sku);
        }
        $this->app->instance(OpenAiCompatibleClient::class, $this->emptyRankLlm());

        $result = $this->app->make(AiProductSearch::class)->find('buty firmy ARTRA model ARMEN 9007 1010 S1', 10);

        $rows = collect($result['products'])->keyBy('sku');
        $this->assertTrue($rows->has('ARMEN 9007 6660 S1'), 'fixture: inny kolor zostaje propozycją');
        $reason = (string) $rows['ARMEN 9007 6660 S1']['ai_match_reason'];
        $this->assertStringContainsString('1010', $reason);
        $this->assertStringContainsString('6660', $reason, 'wiersz nie mówi, jaki wariant ma karta');
        $this->assertStringContainsString('kolor', $reason);
        $this->assertStringNotContainsString('6660', (string) $rows['ARMEN 9007 1010 S1']['ai_match_reason']);
    }

    /**
     * Remis 99 kart z żądanym wariantem rozstrzygała cena, więc tańsza „ARMEN 9007 Clip 1010 S1” stała przed kartą,
     * której kod klient przepisał (golden mail-artra-armen, pomiar 26.09.2026 na produkcji).
     */
    public function test_card_whose_code_the_client_wrote_leads_the_named_model_tie(): void
    {
        $this->shoe('ARMEN 9007 1010 S1');
        $clip = $this->shoe('ARMEN 9007 Clip 1010 S1');
        $clip->forceFill(['catalog_price_net' => 190, 'purchase_price' => 120])->save();
        $this->app->instance(OpenAiCompatibleClient::class, $this->emptyRankLlm());

        $result = $this->app->make(AiProductSearch::class)->find('buty firmy ARTRA model ARMEN 9007 1010 S1', 10);

        $skus = array_column($result['products'], 'sku');
        $this->assertSame(['ARMEN 9007 1010 S1', 'ARMEN 9007 Clip 1010 S1'], array_slice($skus, 0, 2));
        $this->assertSame(
            $result['products'][0]['ai_match_percent'] ?? null,
            $result['products'][1]['ai_match_percent'] ?? null,
            'fixture: obie karty mają żądany wariant i remisują'
        );
    }

    public function test_fallback_list_keeps_only_the_requested_producer(): void
    {
        // Jak na produkcji (uvex-phynomic-esd): wymaganie z marką, modelem i cechą ESD, lista zapasowa szuka po cesze
        // (bez marki i modelu). Decyzja D5 (26.09.2026): karta innej marki nie wchodzi na listę, karta marki z wymagania tak.
        $this->glove('UVX-ESD', 'Rękawice montażowe uvex ESD', 'UVEX', 'Rękawice montażowe, ESD, EN 16350, EN 388.');
        $this->glove('ANS-ESD', 'Rękawice montażowe antyelektrostatyczne ESD', 'Ansell', 'Rękawice montażowe, ESD, EN 16350, EN 388.');
        $query = 'Rękawice montażowe powlekane uvex phynomic z funkcją ESD';
        $intent = [
            'needed' => 'rękawice montażowe powlekane ESD',
            'search_steps' => ['rękawice', 'montażowe', 'ESD', 'uvex'],
            'search_phrases' => ['rękawice montażowe', 'rękawice ESD'],
            'constraints' => ['ESD'],
            'manufacturer' => 'UVEX',
            'manufacturer_requested' => 'uvex',
            'model_name' => 'phynomic',
            'manufacturer_absent_in_catalog' => false,
        ];
        $engine = $this->app->make(ProductAiSearchService::class);
        $merge = new \ReflectionMethod($engine, 'mergeRequirementCatalogRows');

        $rows = collect($merge->invoke($engine, $query, [], 10, $intent))->keyBy('sku');

        $this->assertFalse($rows->has('ANS-ESD'), 'karta innej marki nie wchodzi na listę zapasową');
        $this->assertTrue($rows->has('UVX-ESD'), 'karta marki z wymagania na liście zapasowej');
    }

    /**
     * Recenzja 25.09.2026: producent z intencji, nie surowe słowa wymagania — bez nazwanego producenta lista zostaje
     * bez zawężenia, a karta producenta podmarki (Peltor → 3M) nie jest „innym producentem”.
     */
    public function test_fallback_producer_follows_intent_not_raw_words(): void
    {
        $this->glove('ANS-ESD', 'Rękawice montażowe antyelektrostatyczne ESD', 'Ansell', 'Rękawice montażowe, ESD, EN 16350, EN 388.');
        $this->glove('3M-ESD', 'Rękawice montażowe ESD', '3M', 'Rękawice montażowe, ESD, EN 16350, EN 388.');
        $engine = $this->app->make(ProductAiSearchService::class);
        $merge = new \ReflectionMethod($engine, 'mergeRequirementCatalogRows');
        $base = [
            'needed' => 'rękawice montażowe ESD',
            'search_steps' => ['rękawice', 'montażowe', 'ESD'],
            'search_phrases' => ['rękawice montażowe', 'rękawice ESD'],
            'constraints' => ['ESD'],
            'manufacturer_absent_in_catalog' => false,
        ];

        $noProducer = collect($merge->invoke($engine, 'Rękawice montażowe z funkcją ESD', [], 10, [...$base, 'manufacturer' => null]))->keyBy('sku');
        $this->assertTrue($noProducer->has('ANS-ESD'), 'wymaganie bez producenta: lista bez zawężenia');
        $this->assertTrue($noProducer->has('3M-ESD'), 'wymaganie bez producenta: lista bez zawężenia');

        $subBrand = collect($merge->invoke($engine, 'Rękawice montażowe Peltor z funkcją ESD', [], 10, [...$base, 'manufacturer' => '3M', 'manufacturer_requested' => 'Peltor']))->keyBy('sku');
        $this->assertTrue($subBrand->has('3M-ESD'), 'karta producenta podmarki zostaje');
        $this->assertFalse($subBrand->has('ANS-ESD'), 'karta innego producenta odpada');
    }

    /**
     * Producent z wymagania nie ma w katalogu karty z tą cechą (uvex jest, ale bez ESD) — wyszukiwarka zostaje bez
     * listy zapasowej zamiast podstawiać inną markę; drugie wywołanie listy w finishSearch też dostaje producenta.
     */
    public function test_search_fallback_stays_empty_when_producer_has_no_card_with_the_feature(): void
    {
        $this->glove('UVX-CUT', 'Rękawice antyprzecięciowe uvex', 'UVEX', 'Rękawice antyprzecięciowe, EN 388.');
        $this->glove('ANS-ESD', 'Rękawice montażowe antyelektrostatyczne ESD', 'Ansell', 'Rękawice montażowe, ESD, EN 16350, EN 388.');
        $llm = Mockery::mock(OpenAiCompatibleClient::class);
        $answer = static fn (array $messages): array => FakeSearchLlm::kind($messages) === FakeSearchLlm::KIND_RANK
            ? ['matches' => []]
            : [
                'needed' => 'rękawice montażowe',
                'search_steps' => ['rękawice', 'montażowe'],
                'search_phrases' => ['rękawice montażowe', 'rękawice ESD'],
                'constraints' => ['ESD'],
                'manufacturer' => 'UVEX',
                'manufacturer_requested' => 'uvex',
            ];
        $llm->shouldReceive('chatJson')->andReturnUsing($answer);
        $llm->shouldReceive('chatJsonMany')->andReturnUsing(static fn (array $sets): array => array_map($answer, $sets));
        $this->app->instance(OpenAiCompatibleClient::class, $llm);

        $result = $this->app->make(AiProductSearch::class)->find('Rękawice montażowe uvex z funkcją ESD', 10);

        $this->assertNotContains('ANS-ESD', array_column($result['products'], 'sku'));
    }

    /**
     * Zawężenie SQL jest luźniejsze niż reguła całych słów (LIKE „%3m%” łapie kod „H3M-100”) — kartę innego producenta
     * odcina dopiero bramka listy, ta sama co dawniej przy dopisku (druga recenzja 25.09.2026).
     */
    public function test_fallback_list_drops_card_with_producer_letters_inside_a_code(): void
    {
        $this->glove('3M-ESD', 'Rękawice montażowe ESD', '3M', 'Rękawice montażowe, ESD, EN 16350, EN 388.');
        $this->glove('JSP-ESD', 'Rękawice montażowe ESD H3M-100', 'JSP', 'Rękawice montażowe, ESD, EN 16350, EN 388.');
        $engine = $this->app->make(ProductAiSearchService::class);
        $merge = new \ReflectionMethod($engine, 'mergeRequirementCatalogRows');

        $rows = $merge->invoke($engine, 'Rękawice montażowe Peltor z funkcją ESD', [], 10, [
            'needed' => 'rękawice montażowe ESD',
            'search_steps' => ['rękawice', 'montażowe', 'ESD'],
            'search_phrases' => ['rękawice montażowe', 'rękawice ESD'],
            'constraints' => ['ESD'],
            'manufacturer' => '3M',
            'manufacturer_requested' => 'Peltor',
            'manufacturer_absent_in_catalog' => false,
        ]);

        $this->assertSame(['3M-ESD'], array_column($rows, 'sku'));
    }

    /**
     * Recall listy zapasowej bierze z bazy 500 kart bez kolejności i 40 najlepszych po ocenie cechy — zawężenie do
     * producenta musi stać przed tym limitem. Na produkcji (uvex-phynomic-esd, 25.09) w 40 najlepszych kartach ESD był
     * jeden uvex, więc filtr po nich zostawiłby prawie pustą listę.
     */
    public function test_fallback_list_reaches_producer_cards_beyond_the_recall_limit(): void
    {
        for ($i = 0; $i < 45; $i++) {
            $this->glove('ANS-ESD-'.$i, 'Rękawice montażowe antyelektrostatyczne ESD '.$i, 'Ansell', 'Rękawice montażowe, ESD, EN 16350, EN 388.');
        }
        $this->glove('UVX-ESD-1', 'Rękawice montażowe uvex ESD', 'UVEX', 'Rękawice montażowe, ESD, EN 16350, EN 388.');
        $this->glove('HURT-UVX', 'Rękawice Uvex montażowe ESD', 'Hurtownia BHP', 'Rękawice montażowe, ESD, EN 16350, EN 388.');
        $engine = $this->app->make(ProductAiSearchService::class);
        $merge = new \ReflectionMethod($engine, 'mergeRequirementCatalogRows');

        $rows = $merge->invoke($engine, 'Rękawice montażowe uvex z funkcją ESD', [], 40, [
            'needed' => 'rękawice montażowe ESD',
            'search_steps' => ['rękawice', 'montażowe', 'ESD', 'uvex'],
            'search_phrases' => ['rękawice montażowe', 'rękawice ESD'],
            'constraints' => ['ESD'],
            'manufacturer' => 'UVEX',
            'manufacturer_requested' => 'uvex',
            'manufacturer_absent_in_catalog' => false,
        ]);

        $skus = array_column($rows, 'sku');
        sort($skus);
        $this->assertSame(['HURT-UVX', 'UVX-ESD-1'], $skus, 'karta producenta i karta dystrybutora z marką w nazwie');
    }

    /**
     * Ta sama zasada przez wyszukiwarkę (find — też okno kandydatów przetargu): model rozumie wymaganie z marką, nic
     * nie ocenia, a lista zapasowa szuka po samej cesze („rękawice montażowe ESD”) — jak w uvex-phynomic-esd.
     */
    public function test_search_fallback_shows_only_the_requested_producer(): void
    {
        $this->glove('UVX-ESD', 'Rękawice montażowe uvex ESD', 'UVEX', 'Rękawice montażowe, ESD, EN 16350, EN 388.');
        $this->glove('ANS-ESD', 'Rękawice montażowe antyelektrostatyczne ESD', 'Ansell', 'Rękawice montażowe, ESD, EN 16350, EN 388.');
        $llm = Mockery::mock(OpenAiCompatibleClient::class);
        $answer = static fn (array $messages): array => FakeSearchLlm::kind($messages) === FakeSearchLlm::KIND_RANK
            ? ['matches' => []]
            : [
                'needed' => 'rękawice montażowe',
                'search_steps' => ['rękawice', 'montażowe'],
                'search_phrases' => ['rękawice montażowe', 'rękawice ESD'],
                'constraints' => ['ESD'],
                'manufacturer' => 'UVEX',
                'manufacturer_requested' => 'uvex',
            ];
        $llm->shouldReceive('chatJson')->andReturnUsing($answer);
        $llm->shouldReceive('chatJsonMany')->andReturnUsing(static fn (array $sets): array => array_map($answer, $sets));
        $this->app->instance(OpenAiCompatibleClient::class, $llm);

        $result = $this->app->make(AiProductSearch::class)->find('Rękawice montażowe uvex z funkcją ESD', 10);

        $this->assertSame(['UVX-ESD'], array_column($result['products'], 'sku'));
    }

    /**
     * Druga recenzja 25.09.2026: producent rozpoznawany po całych słowach nazwy — krótki „3M” nie może pasować do
     * „0,3 mm”, „dł. 3 m” ani kodu „H3M-100”; dystrybutor z podmarką w nazwie (PELTOR przy 3M) to ten sam producent.
     *
     * @return iterable<string, array{string, string, string, bool}>
     */
    public static function producerNoteCases(): iterable
    {
        yield 'grubość 0,3 mm' => ['Ansell', 'Rękawice nitrylowe grubość 0,3 mm', '3M', true];
        yield 'linka 3 m' => ['Ansell', 'Linka bezpieczeństwa dł. 3 m', '3M', true];
        yield 'blok 13 m' => ['Ansell', 'Blok samohamowny 13 m', '3M', true];
        yield 'taśma 33 m' => ['Portwest', 'Taśma ostrzegawcza 33 m', '3M', true];
        yield 'kod H3M' => ['JSP', 'Półmaska H3M-100', '3M', true];
        yield 'styk słów flat glass' => ['Uvex', 'Kask flat glass', 'ATG', true];
        yield 'karta producenta' => ['3M', 'Nauszniki Optime III', '3M', false];
        yield 'dystrybutor z podmarką' => ['Hurtownia BHP', 'Nauszniki PELTOR X2A', '3M', false];
        yield 'dystrybutor z producentem' => ['Hurtownia BHP', 'Rękawice Uvex phynomic ESD', 'UVEX', false];
        yield 'zapis sklejony' => ['DeltaPlus', 'Rękawice VV733', 'Delta Plus', false];
        yield 'zapis rozdzielony' => ['Delta Plus', 'Rękawice VV733', 'DeltaPlus', false];
        yield 'litera z akcentem' => ['Bolle', 'Okulary Rush', 'Bollé', false];
    }

    /** Uzgodnienie z drugim agentem: luźny alias („DELTA” → Delta Plus) nie jest osobnym kluczem producenta. */
    public function test_loose_requested_brand_is_not_a_producer_key(): void
    {
        $engine = $this->app->make(ProductAiSearchService::class);
        $requested = (new \ReflectionMethod($engine, 'requestedProducer'))->invoke($engine, [
            'needed' => 'x', 'manufacturer' => 'Delta Plus', 'manufacturer_requested' => 'DELTA', 'manufacturer_absent_in_catalog' => false,
        ]);
        $other = (new \ReflectionMethod($engine, 'isOtherManufacturer'))->invoke(
            $engine,
            new Product(['sku' => 'X-1', 'name' => 'Kamizelka linia Delta', 'manufacturer' => 'Portwest']),
            $requested,
        );

        $this->assertSame(['delta plus'], $requested['keys']);
        $this->assertTrue($other, 'linia „Delta” u Portwestu to inny producent niż Delta Plus');
    }

    #[DataProvider('producerNoteCases')]
    public function test_other_producer_is_recognized_by_whole_words(string $manufacturer, string $name, string $producer, bool $noted): void
    {
        $engine = $this->app->make(ProductAiSearchService::class);
        $requested = (new \ReflectionMethod($engine, 'requestedProducer'))->invoke($engine, [
            'needed' => 'x',
            'manufacturer' => $producer,
            'manufacturer_requested' => $producer === '3M' ? 'Peltor' : $producer,
            'manufacturer_absent_in_catalog' => false,
        ]);
        $other = (new \ReflectionMethod($engine, 'isOtherManufacturer'))->invoke(
            $engine,
            new Product(['sku' => 'X-1', 'name' => $name, 'manufacturer' => $manufacturer]),
            $requested,
        );

        $this->assertSame($noted, $other, $name);
    }

    /** Diagnoza remisów 50%: ślad oceny ma brakujący kluczowy warunek, który podał model. */
    public function test_trace_of_model_matches_keeps_missing_key(): void
    {
        $glove = $this->glove('RKW-NIT', 'Rękawice powlekane nitrylem', 'TEST', 'Rękawice robocze powlekane nitrylem, EN 388.');
        $llm = Mockery::mock(OpenAiCompatibleClient::class);
        $answer = static fn (array $messages): array => FakeSearchLlm::kind($messages) === FakeSearchLlm::KIND_RANK
            ? ['matches' => [['id' => $glove->id, 'score' => 80, 'reason' => 'powłoka nitrylowa', 'missing_key' => ['EN 388 4121']]]]
            : ['needed' => 'rękawice powlekane nitrylem', 'search_steps' => ['rękawice', 'powlekane nitrylem'], 'search_phrases' => ['rękawice powlekane nitrylem'], 'constraints' => ['EN 388 4121']];
        $llm->shouldReceive('chatJson')->andReturnUsing($answer);
        $llm->shouldReceive('chatJsonMany')->andReturnUsing(static fn (array $sets): array => array_map($answer, $sets));
        $this->app->instance(OpenAiCompatibleClient::class, $llm);

        $result = $this->app->make(AiProductSearch::class)->find('Rękawice powlekane nitrylem EN 388 4121 do prac montażowych', 10);

        $match = collect($result['trace']['llm_matches'] ?? [])->firstWhere('id', $glove->id);
        $this->assertNotNull($match, 'fixture: model ocenił kartę');
        $this->assertSame(['EN 388 4121'], $match['missing_key'] ?? null);
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
            'catalog_price_net' => str_contains($sku, '6660') ? 150 : 200,
            'purchase_price' => str_contains($sku, '6660') ? 100 : 140,
            'stock' => 5,
            'ppe_family' => PpeAssortment::FAMILY_FOOTWEAR,
            'enrichment_status' => Product::ENRICHMENT_DONE,
            'enriched_at' => now()->subYear(),
        ]);
    }

    private function glove(string $sku, string $name, string $manufacturer, string $description): Product
    {
        return Product::query()->create([
            'sku' => $sku,
            'name' => $name,
            'manufacturer' => $manufacturer,
            'category' => 'Rękawice',
            'description' => $description,
            'catalog_price_net' => 20,
            'purchase_price' => 12,
            'stock' => 5,
            'ppe_family' => PpeAssortment::FAMILY_GLOVES,
            'enrichment_status' => Product::ENRICHMENT_DONE,
            'enriched_at' => now()->subYear(),
        ]);
    }

    private function emptyRankLlm(): OpenAiCompatibleClient
    {
        $llm = Mockery::mock(OpenAiCompatibleClient::class);
        $llm->shouldReceive('chatJson')->andReturn(['matches' => []]);
        $llm->shouldReceive('chatJsonMany')->andReturnUsing(
            static fn (array $sets): array => array_fill(0, count($sets), ['matches' => []])
        );

        return $llm;
    }
}
