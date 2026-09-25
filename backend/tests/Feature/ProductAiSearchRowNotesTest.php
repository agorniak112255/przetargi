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
 * (kolor ARTRA 6660 pod zapytaniem o 1010) i karta innego producenta niż w wymaganiu zostają w wyniku, ale wiersz
 * mówi wprost, czym się różni — handlowiec widział dotąd tylko „nie ma oznaczenia z zapytania” albo nic.
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

    public function test_fallback_row_of_other_manufacturer_than_requested_is_marked(): void
    {
        // Jak na produkcji (uvex-phynomic-esd): wymaganie z marką, modelem i cechą ESD, lista zapasowa szuka po cesze
        // (bez marki i modelu). Karta innej marki zostaje propozycją, ale z dopiskiem; karta marki z wymagania — bez.
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

        $this->assertTrue($rows->has('ANS-ESD'), 'fixture: karta innej marki w liście zapasowej');
        $this->assertStringContainsString('inny producent niż w wymaganiu (UVEX)', (string) $rows['ANS-ESD']['ai_match_reason']);
        $this->assertTrue($rows->has('UVX-ESD'), 'fixture: karta marki z wymagania w liście zapasowej');
        $this->assertStringNotContainsString('inny producent', (string) $rows['UVX-ESD']['ai_match_reason']);
    }

    /**
     * Recenzja 25.09.2026: dopisek po producencie z intencji, nie po surowych słowach wymagania — bez nazwanego
     * producenta nie ma dopisku, a karta producenta podmarki (Peltor → 3M) nie jest „innym producentem”.
     */
    public function test_fallback_note_follows_producer_from_intent_not_raw_words(): void
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
        $this->assertTrue($noProducer->has('ANS-ESD'), 'fixture');
        foreach ($noProducer as $sku => $row) {
            $this->assertStringNotContainsString('inny producent', (string) $row['ai_match_reason'], $sku.': wymaganie bez producenta');
        }

        $subBrand = collect($merge->invoke($engine, 'Rękawice montażowe Peltor z funkcją ESD', [], 10, [...$base, 'manufacturer' => '3M', 'manufacturer_requested' => 'Peltor']))->keyBy('sku');
        $this->assertTrue($subBrand->has('3M-ESD'), 'fixture');
        $this->assertStringNotContainsString('inny producent', (string) $subBrand['3M-ESD']['ai_match_reason'], 'karta producenta podmarki');
        $this->assertStringContainsString('inny producent niż w wymaganiu (3M)', (string) $subBrand['ANS-ESD']['ai_match_reason']);
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
        $requested = (new \ReflectionMethod($engine, 'requestedProducerForNote'))->invoke($engine, [
            'needed' => 'x', 'manufacturer' => 'Delta Plus', 'manufacturer_requested' => 'DELTA', 'manufacturer_absent_in_catalog' => false,
        ]);
        $note = (new \ReflectionMethod($engine, 'otherManufacturerNote'))->invoke(
            $engine,
            new Product(['sku' => 'X-1', 'name' => 'Kamizelka linia Delta', 'manufacturer' => 'Portwest']),
            $requested,
        );

        $this->assertSame(['delta plus'], $requested['keys']);
        $this->assertStringContainsString('inny producent niż w wymaganiu (Delta Plus)', $note);
    }

    #[DataProvider('producerNoteCases')]
    public function test_other_producer_is_recognized_by_whole_words(string $manufacturer, string $name, string $producer, bool $noted): void
    {
        $engine = $this->app->make(ProductAiSearchService::class);
        $requested = (new \ReflectionMethod($engine, 'requestedProducerForNote'))->invoke($engine, [
            'needed' => 'x',
            'manufacturer' => $producer,
            'manufacturer_requested' => $producer === '3M' ? 'Peltor' : $producer,
            'manufacturer_absent_in_catalog' => false,
        ]);
        $note = (new \ReflectionMethod($engine, 'otherManufacturerNote'))->invoke(
            $engine,
            new Product(['sku' => 'X-1', 'name' => $name, 'manufacturer' => $manufacturer]),
            $requested,
        );

        $this->assertSame($noted, str_contains($note, 'inny producent'), $name);
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
