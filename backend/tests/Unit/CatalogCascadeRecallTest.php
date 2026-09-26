<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Models\Product;
use App\Support\CatalogCascadeRecall;
use App\Support\CatalogManufacturerContext;
use App\Support\PpeAssortment;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

final class CatalogCascadeRecallTest extends TestCase
{
    use RefreshDatabase;

    public function test_layers_drop_absent_brand_and_keep_nitrile_feature(): void
    {
        $layers = $this->app->make(CatalogCascadeRecall::class)->layers(
            'Rękawice nitrylowe RTELA',
            [
                'needed' => 'rękawice nitrylowe',
                'search_phrases' => ['rękawice nitrylowe'],
                'constraints' => [],
                'manufacturer' => null,
                'manufacturer_requested' => 'RTELA',
                'manufacturer_absent_in_catalog' => true,
            ]
        );

        $this->assertSame(PpeAssortment::FAMILY_GLOVES, $layers['family']);
        $this->assertNull($layers['manufacturer']);
        $this->assertNotEmpty(array_filter(
            $layers['features'],
            static fn (string $t): bool => str_contains($t, 'nitryl')
        ));
    }

    public function test_peels_brand_when_no_ansell_nitrile(): void
    {
        Product::query()->create([
            'sku' => 'ANS-LEATHER',
            'name' => 'Rękawice skórzane spawalnicze',
            'manufacturer' => 'Ansell',
            'description' => 'Skóra licowa.',
            'catalog_price_net' => 20,
            'purchase_price' => 10,
            'stock' => 2,
            'ppe_family' => PpeAssortment::FAMILY_GLOVES,
        ]);
        $delta = Product::query()->create([
            'sku' => '93-843',
            'name' => 'Niebieskie bezpudrowe rękawice nitrylowe',
            'manufacturer' => 'Delta Plus',
            'description' => 'Jednorazowe rękawice nitrylowe.',
            'catalog_price_net' => 8,
            'purchase_price' => 4,
            'stock' => 10,
            'ppe_family' => PpeAssortment::FAMILY_GLOVES,
        ]);
        CatalogManufacturerContext::forgetCache();

        $hit = $this->app->make(CatalogCascadeRecall::class)->retrieve(
            'Rękawice nitrylowe Ansell',
            [
                'needed' => 'rękawice nitrylowe',
                'search_phrases' => ['rękawice nitrylowe'],
                'constraints' => [],
                'manufacturer' => 'Ansell',
                'manufacturer_requested' => 'Ansell',
                'manufacturer_absent_in_catalog' => false,
            ],
            'Rękawice nitrylowe Ansell',
            20
        );

        $this->assertSame(CatalogCascadeRecall::LEVEL_FAMILY_FEATURE, $hit['level']);
        $skus = $hit['products']->pluck('sku')->all();
        $this->assertContains('93-843', $skus);
        $this->assertNotContains('ANS-LEATHER', $skus);
        $this->assertSame($delta->id, $hit['products']->first()?->id);
    }

    public function test_keeps_ansell_when_nitrile_exists(): void
    {
        Product::query()->create([
            'sku' => '93-843',
            'name' => 'Niebieskie bezpudrowe rękawice nitrylowe',
            'manufacturer' => 'Ansell',
            'description' => 'Jednorazowe rękawice nitrylowe.',
            'catalog_price_net' => 12,
            'purchase_price' => 6,
            'stock' => 20,
            'ppe_family' => PpeAssortment::FAMILY_GLOVES,
            'enrichment_status' => Product::ENRICHMENT_DONE,
            'enriched_at' => now(),
        ]);
        Product::query()->create([
            'sku' => 'VE727',
            'name' => 'Rękawice dziane, dłoń powlekana nitrylem',
            'manufacturer' => 'Delta Plus',
            'description' => 'Rękawice nitrylowe powlekane.',
            'catalog_price_net' => 8,
            'purchase_price' => 4,
            'stock' => 10,
            'ppe_family' => PpeAssortment::FAMILY_GLOVES,
        ]);
        CatalogManufacturerContext::forgetCache();

        $hit = $this->app->make(CatalogCascadeRecall::class)->retrieve(
            'Rękawice nitrylowe Ansell',
            [
                'needed' => 'rękawice nitrylowe',
                'search_phrases' => ['rękawice nitrylowe'],
                'constraints' => [],
                'manufacturer' => 'Ansell',
                'manufacturer_requested' => 'Ansell',
                'manufacturer_absent_in_catalog' => false,
            ],
            'Rękawice nitrylowe Ansell',
            20
        );

        $this->assertSame(CatalogCascadeRecall::LEVEL_FAMILY_FEATURE_BRAND, $hit['level']);
        $this->assertSame(['93-843'], $hit['products']->pluck('sku')->all());
    }

    public function test_search_steps_and_then_peel_manufacturer(): void
    {
        Product::query()->create([
            'sku' => 'ANS-LEATHER',
            'name' => 'Rękawice skórzane spawalnicze',
            'manufacturer' => 'Ansell',
            'description' => 'Skóra licowa.',
            'catalog_price_net' => 20,
            'purchase_price' => 10,
            'stock' => 2,
            'ppe_family' => PpeAssortment::FAMILY_GLOVES,
        ]);
        $delta = Product::query()->create([
            'sku' => '93-843',
            'name' => 'Niebieskie bezpudrowe rękawice nitrylowe',
            'manufacturer' => 'Delta Plus',
            'description' => 'Jednorazowe rękawice nitrylowe.',
            'catalog_price_net' => 8,
            'purchase_price' => 4,
            'stock' => 10,
            'ppe_family' => PpeAssortment::FAMILY_GLOVES,
        ]);
        CatalogManufacturerContext::forgetCache();

        $hit = $this->app->make(CatalogCascadeRecall::class)->retrieve(
            'Rękawice nitrylowe Ansell',
            [
                'needed' => 'rękawice nitrylowe',
                'search_phrases' => ['rękawice nitrylowe'],
                'search_steps' => ['rękawice', 'nitrylowe', 'Ansell'],
                'constraints' => [],
                'manufacturer' => 'Ansell',
                'manufacturer_requested' => 'Ansell',
                'manufacturer_absent_in_catalog' => false,
            ],
            'Rękawice nitrylowe Ansell',
            20
        );

        $this->assertSame('steps_2', $hit['level']);
        $this->assertContains('93-843', $hit['products']->pluck('sku')->all());
        $this->assertNotContains('ANS-LEATHER', $hit['products']->pluck('sku')->all());
        $this->assertSame($delta->id, $hit['products']->first()?->id);
    }

    /**
     * M10 z planu napraw 25.09.2026 (golden opisowy15-15): kroki „lateksowe”, „flokowane” wymagały dokładnie tych słów,
     * a karta AlphaTec 87-320 pisze „z naturalnego lateksu … wyłożone flokiem”. Rdzeń przymiotnika jest dodatkową igłą
     * OR, pełne słowo zostaje; karta bez „flok” dalej nie spełnia kroku „flokowane”.
     */
    public function test_adjective_step_also_matches_its_noun_stem(): void
    {
        $base = [
            'manufacturer' => 'Ansell',
            'catalog_price_net' => 10,
            'purchase_price' => 5,
            'stock' => 2,
            'ppe_family' => PpeAssortment::FAMILY_GLOVES,
        ];
        Product::query()->create($base + [
            'sku' => '87320100-PAIR',
            'name' => 'Rękawice AlphaTec 87-320',
            'description' => 'Rękawice z naturalnego lateksu, wnętrze wyłożone flokiem bawełnianym, mankiet rolowany.',
        ]);
        Product::query()->create($base + [
            'sku' => 'LATEX-NOFLOCK',
            'name' => 'Rękawice gospodarcze',
            'description' => 'Rękawice z naturalnego lateksu, wnętrze gładkie.',
        ]);

        $hit = $this->app->make(CatalogCascadeRecall::class)->retrieve(
            'Rękawice ochronne z lateksu naturalnego, flokowane',
            [
                'needed' => 'rękawice lateksowe flokowane',
                'search_phrases' => ['rękawice lateksowe flokowane'],
                'search_steps' => ['rękawice', 'lateksowe', 'flokowane'],
                'constraints' => [],
            ],
            'Rękawice ochronne z lateksu naturalnego, flokowane',
            20
        );

        $this->assertSame('steps_3', $hit['level']);
        $skus = $hit['products']->pluck('sku')->all();
        $this->assertContains('87320100-PAIR', $skus);
        $this->assertNotContains('LATEX-NOFLOCK', $skus, 'bez „flok” krok „flokowane” niespełniony');
    }

    public function test_pcv_query_keeps_pvc_token_and_finds_pvc_glove(): void
    {
        Product::query()->create([
            'sku' => 'COAT-NIT',
            'name' => 'Rękawice dziane, dłoń powlekana nitrylem',
            'manufacturer' => 'Delta Plus',
            'description' => 'Powlekane do oleju.',
            'catalog_price_net' => 8,
            'purchase_price' => 4,
            'stock' => 10,
            'ppe_family' => PpeAssortment::FAMILY_GLOVES,
        ]);
        $pvc = Product::query()->create([
            'sku' => 'A835',
            'name' => 'Rękawice PCV długie do łokcia',
            'manufacturer' => 'Portwest',
            'description' => 'Rękawice z PCV, mankiet do łokcia.',
            'catalog_price_net' => 12,
            'purchase_price' => 9.25,
            'stock' => 8,
            'ppe_family' => PpeAssortment::FAMILY_GLOVES,
            'enrichment_status' => Product::ENRICHMENT_DONE,
            'enriched_at' => now(),
        ]);
        CatalogManufacturerContext::forgetCache();

        $hit = $this->app->make(CatalogCascadeRecall::class)->retrieve(
            'Rękawice PCV długie do łokci',
            [
                'needed' => 'rękawice PVC',
                'search_phrases' => ['rękawice PVC', 'rękawice PCV'],
                'search_steps' => ['rękawice', 'PCV'],
                'constraints' => [],
            ],
            'Rękawice PCV długie do łokci',
            20
        );

        $skus = $hit['products']->pluck('sku')->all();
        $this->assertContains('A835', $skus);
        $this->assertNotContains('COAT-NIT', $skus);
        $this->assertSame($pvc->id, $hit['products']->first()?->id);
    }

    public function test_nitrile_material_drops_knit_palm_coat_even_when_lekkie_matches(): void
    {
        Product::query()->create([
            'sku' => 'R840',
            'name' => 'Dziane rękawice przeznaczone do prac lekkich z powlekaną nitrylem dłonią',
            'manufacturer' => 'Ansell',
            'description' => 'Rękawice do prac lekkich powlekane nitrylem.',
            'catalog_price_net' => 9.2,
            'purchase_price' => 7.27,
            'stock' => 5,
            'ppe_family' => PpeAssortment::FAMILY_GLOVES,
            'enrichment_status' => Product::ENRICHMENT_DONE,
            'enriched_at' => now(),
        ]);
        $nitrile = Product::query()->create([
            'sku' => '93-843',
            'name' => 'Niebieskie bezpudrowe rękawice nitrylowe',
            'manufacturer' => 'Ansell',
            'description' => 'Jednorazowe rękawice nitrylowe.',
            'catalog_price_net' => 12,
            'purchase_price' => 6,
            'stock' => 20,
            'ppe_family' => PpeAssortment::FAMILY_GLOVES,
            'enrichment_status' => Product::ENRICHMENT_DONE,
            'enriched_at' => now(),
        ]);
        CatalogManufacturerContext::forgetCache();

        $hit = $this->app->make(CatalogCascadeRecall::class)->retrieve(
            'Rękawice nitrylowe lekkie',
            [
                'needed' => 'rękawice nitrylowe',
                'search_phrases' => ['rękawice nitrylowe'],
                'search_steps' => ['rękawice', 'nitrylowe', 'lekkie'],
                'constraints' => [],
            ],
            'Rękawice nitrylowe lekkie',
            20
        );

        $skus = $hit['products']->pluck('sku')->all();
        $this->assertContains('93-843', $skus);
        $this->assertNotContains('R840', $skus);
        $this->assertSame($nitrile->id, $hit['products']->first()?->id);
    }

    public function test_last_remaining_word_is_not_truncated(): void
    {
        Product::query()->create([
            'sku' => 'SB085290N',
            'name' => 'Scotch-Brite Scierka z mikrowlokna',
            'manufacturer' => '3M',
            'description' => 'Scierka.',
            'catalog_price_net' => 6,
            'purchase_price' => 3,
            'stock' => 10,
        ]);
        Product::query()->create([
            'sku' => '51548',
            'name' => 'Krazek scierny 3M Hookit Gold 288U',
            'manufacturer' => '3M',
            'description' => 'Krazek scierny.',
            'catalog_price_net' => 2,
            'purchase_price' => 1,
            'stock' => 50,
        ]);

        $hit = $this->app->make(CatalogCascadeRecall::class)->retrieve(
            'ŚCIERKA TETRA 60 x 85 cm',
            [
                'needed' => 'ścierka tetra',
                'search_phrases' => ['ścierka tetra'],
                'search_steps' => ['ścierka', 'tetra'],
                'constraints' => [],
            ],
            'ŚCIERKA TETRA 60 x 85 cm',
            20
        );

        $skus = $hit['products']->pluck('sku')->all();
        $this->assertContains('SB085290N', $skus);
        $this->assertNotContains('51548', $skus);
    }

    public function test_norm_step_matches_norms_column_not_name(): void
    {
        Product::query()->create([
            'sku' => 'BLUZA-KOLPEO',
            'name' => 'Bluza KOLPEO BASIC ZIPPER',
            'manufacturer' => 'Cerva',
            'norms' => 'EN ISO 11611:2015, EN 1149-5:2018',
            'description' => 'Bluza trudnopalna.',
            'catalog_price_net' => 80,
            'purchase_price' => 50,
            'stock' => 3,
            'ppe_family' => PpeAssortment::FAMILY_APPAREL,
        ]);
        Product::query()->create([
            'sku' => 'BLUZA-PLAIN',
            'name' => 'Bluza robocza bez normy',
            'manufacturer' => 'Reis',
            'description' => 'Zwykla bluza.',
            'catalog_price_net' => 40,
            'purchase_price' => 20,
            'stock' => 5,
            'ppe_family' => PpeAssortment::FAMILY_APPAREL,
        ]);

        $hit = $this->app->make(CatalogCascadeRecall::class)->retrieve(
            'Ubranie antyelektrostatyczne trudnopalne (bluza + spodnie) EN ISO 11611 EN 1149-5',
            [
                'needed' => 'ubranie trudnopalne',
                'search_phrases' => ['bluza'],
                'search_steps' => ['11611', '1149'],
                'constraints' => [],
            ],
            'Ubranie antyelektrostatyczne trudnopalne (bluza + spodnie) EN ISO 11611 EN 1149-5',
            20
        );

        $skus = $hit['products']->pluck('sku')->all();
        $this->assertContains('BLUZA-KOLPEO', $skus);
        $this->assertNotContains('BLUZA-PLAIN', $skus);
    }

    public function test_named_garment_beats_recent_sku_only_norm_hits(): void
    {
        for ($i = 0; $i < 80; $i++) {
            Product::query()->create([
                'sku' => 'FR'.(740 + $i),
                'name' => 'FR'.(740 + $i),
                'manufacturer' => 'Portwest',
                'norms' => 'EN ISO 11611:2015, EN 1149-5:2018',
                'description' => 'FR',
                'catalog_price_net' => 10,
                'purchase_price' => 5,
                'stock' => 1,
                'ppe_family' => PpeAssortment::FAMILY_APPAREL,
                'enrichment_status' => Product::ENRICHMENT_DONE,
                'enriched_at' => now(),
            ]);
        }
        Product::query()->create([
            'sku' => 'BLUZA-KOLPEO',
            'name' => 'Bluza KOLPEO BASIC ZIPPER',
            'manufacturer' => 'Cerva',
            'norms' => 'EN ISO 11611:2015, EN 1149-5:2018',
            'description' => 'Bluza trudnopalna.',
            'catalog_price_net' => 80,
            'purchase_price' => 50,
            'stock' => 3,
            'ppe_family' => PpeAssortment::FAMILY_APPAREL,
            'enrichment_status' => Product::ENRICHMENT_DONE,
            'enriched_at' => now()->subYear(),
        ]);

        $hit = $this->app->make(CatalogCascadeRecall::class)->retrieve(
            'Ubranie antyelektrostatyczne trudnopalne (bluza + spodnie) EN ISO 11611 EN 1149-5',
            [
                'needed' => 'ubranie trudnopalne',
                'search_phrases' => ['bluza'],
                'search_steps' => ['11611', '1149'],
                'constraints' => [],
            ],
            'Ubranie antyelektrostatyczne trudnopalne (bluza + spodnie) EN ISO 11611 EN 1149-5',
            20
        );

        $skus = $hit['products']->pluck('sku')->all();
        $this->assertContains('BLUZA-KOLPEO', $skus);
        $this->assertNotContains('FR740', $skus);
    }

    public function test_kalesony_step_does_not_require_undershirt_and_skips_blouse(): void
    {
        Product::query()->create([
            'sku' => 'KOLDYPANTS',
            'name' => 'DŁUGIE KALESONY Z POLIAMIDU',
            'manufacturer' => 'Delta Plus',
            'description' => 'Kalesony z poliamidu.',
            'catalog_price_net' => 96,
            'purchase_price' => 74,
            'stock' => 2,
            'ppe_family' => PpeAssortment::FAMILY_APPAREL,
        ]);
        Product::query()->create([
            'sku' => 'H5131',
            'name' => 'Blůza dámská KLASIK stř.modrá',
            'manufacturer' => 'ARDON SAFETY',
            'description' => 'Odzież robocza.',
            'catalog_price_net' => 8,
            'purchase_price' => 7,
            'stock' => 10,
            'ppe_family' => PpeAssortment::FAMILY_APPAREL,
        ]);

        $hit = $this->app->make(CatalogCascadeRecall::class)->retrieve(
            'KALESONY bawełniane męskie',
            [
                'needed' => 'bielizna termiczna',
                'search_phrases' => ['kalesony', 'bielizna termiczna'],
                'search_steps' => ['kalesony', 'bielizna termiczna'],
                'constraints' => [],
            ],
            'KALESONY bawełniane męskie',
            20
        );

        $skus = $hit['products']->pluck('sku')->all();
        $this->assertStringStartsWith('steps_', (string) $hit['level']);
        $this->assertContains('KOLDYPANTS', $skus);
        $this->assertNotContains('H5131', $skus);
    }

    /**
     * Runda 7 przeglądu (25.09.2026): krok marki filtrował producenta po etykiecie kroku. Zapis z wymagania
     * („Mapa Professional”, „Rękawice Mapa Professional”) nie łapał kart producenta „MAPA”, krok spadał i kaskada
     * oddawała rękawice wszystkich marek — w pierwszym szukaniu i po przepisaniu zapytania.
     *
     * @return iterable<string, array{list<string>}>
     */
    public static function brandStepsInRequirementSpelling(): iterable
    {
        yield 'marka z wymagania jako osobny krok' => [['rękawice', 'Mapa Professional']];
        yield 'marka z wymagania w kroku z rzeczownikiem' => [['powlekane nitrylem', 'Rękawice Mapa Professional']];
    }

    /** @param  list<string>  $steps */
    #[DataProvider('brandStepsInRequirementSpelling')]
    public function test_brand_step_in_requirement_spelling_filters_by_catalog_manufacturer(array $steps): void
    {
        foreach (['MAPA' => 'MAPA-N1', 'TEST' => 'TEST-N1', 'Ansell' => 'ANS-N1'] as $maker => $sku) {
            $this->glove($sku, 'Rękawice montażowe powlekane nitrylem', $maker);
        }
        CatalogManufacturerContext::forgetCache();

        $hit = $this->app->make(CatalogCascadeRecall::class)->retrieve(
            'Rękawice Mapa Professional powlekane nitrylem EN 388',
            [
                'needed' => 'rękawice powlekane nitrylem',
                'search_phrases' => ['rękawice powlekane nitrylem'],
                'search_steps' => $steps,
                'constraints' => [],
                'manufacturer' => 'MAPA',
                'manufacturer_requested' => 'Mapa Professional',
                'manufacturer_absent_in_catalog' => false,
            ],
            'Rękawice Mapa Professional powlekane nitrylem EN 388',
            20
        );

        $this->assertSame('steps_2', $hit['level']);
        $this->assertSame(0, $hit['dropped_steps'], 'krok marki spadł — kaskada szuka bez marki');
        $this->assertSame(['MAPA'], $hit['products']->pluck('manufacturer')->unique()->values()->all());
    }

    /** Podmarka ze słownika („Peltor” → 3M): krok z podmarką zawęża do producenta z katalogu, zamiast spadać. */
    public function test_sub_brand_step_filters_by_its_catalog_manufacturer(): void
    {
        $this->earMuff('3M-X2A', 'Nauszniki przeciwhałasowe Peltor X2A', '3M');
        $this->earMuff('UVEX-K2', 'Nauszniki przeciwhałasowe K2', 'UVEX');
        CatalogManufacturerContext::forgetCache();

        $hit = $this->app->make(CatalogCascadeRecall::class)->retrieve(
            'Nauszniki przeciwhałasowe Peltor X2 EN 352-1',
            [
                'needed' => 'nauszniki przeciwhałasowe',
                'search_phrases' => ['nauszniki przeciwhałasowe'],
                'search_steps' => ['nauszniki', 'Peltor X2'],
                'constraints' => [],
                'manufacturer' => '3M',
                'manufacturer_requested' => 'Peltor',
                'manufacturer_absent_in_catalog' => false,
            ],
            'Nauszniki przeciwhałasowe Peltor X2 EN 352-1',
            20
        );

        $this->assertSame('steps_2', $hit['level']);
        $this->assertSame(['3M-X2A'], $hit['products']->pluck('sku')->all());
    }

    /** Marka spoza katalogu nie jest krokiem kaskady — bez zmian. */
    public function test_absent_brand_step_is_still_skipped(): void
    {
        $this->glove('TEST-N1', 'Rękawice montażowe powlekane nitrylem', 'TEST');
        CatalogManufacturerContext::forgetCache();

        $hit = $this->app->make(CatalogCascadeRecall::class)->retrieve(
            'Rękawice RTELA powlekane nitrylem',
            [
                'needed' => 'rękawice powlekane nitrylem',
                'search_phrases' => ['rękawice powlekane nitrylem'],
                'search_steps' => ['powlekane nitrylem', 'RTELA'],
                'constraints' => [],
                'manufacturer' => null,
                'manufacturer_requested' => 'RTELA',
                'manufacturer_absent_in_catalog' => true,
            ],
            'Rękawice RTELA powlekane nitrylem',
            20
        );

        $this->assertSame('steps_1', $hit['level']);
        $this->assertSame(0, $hit['dropped_steps']);
        $this->assertSame(['TEST-N1'], $hit['products']->pluck('sku')->all());
    }

    private function glove(string $sku, string $name, string $manufacturer): Product
    {
        return Product::query()->create([
            'sku' => $sku,
            'name' => $name,
            'manufacturer' => $manufacturer,
            'description' => $name.', EN 388.',
            'catalog_price_net' => 9,
            'purchase_price' => 6,
            'stock' => 10,
            'ppe_family' => PpeAssortment::FAMILY_GLOVES,
        ]);
    }

    private function earMuff(string $sku, string $name, string $manufacturer): Product
    {
        return Product::query()->create([
            'sku' => $sku,
            'name' => $name,
            'manufacturer' => $manufacturer,
            'description' => $name.', EN 352-1.',
            'catalog_price_net' => 60,
            'purchase_price' => 40,
            'stock' => 5,
            'ppe_family' => PpeAssortment::FAMILY_HEARING,
        ]);
    }
}
