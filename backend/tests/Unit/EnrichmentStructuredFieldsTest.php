<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Models\Product;
use App\Services\Enrichment\ProductEnrichmentService;
use App\Services\Enrichment\ProductPageFetcher;
use ReflectionClass;
use Tests\TestCase;

final class EnrichmentStructuredFieldsTest extends TestCase
{
    public function test_extracts_norms_materials_and_use_cases_from_page_text(): void
    {
        $service = app(ProductEnrichmentService::class);
        $text = <<<'TXT'
uvex C300 Dry. Materiały: wiskoza bambusowa, Dyneema, szkło i poliamid.
Norma EN 388:2016 4X42C. Przeznaczone do montażu i prac z ryzykiem przecięcia w warunkach suchych.
TXT;

        $extracted = $this->invoke($service, 'enrichStructuredFieldsFromPages', [
            [
                'description' => 'Krótki opis.',
                'norms' => [],
                'materials' => [],
                'use_cases' => [],
            ],
            [['url' => 'https://shop.example/p', 'text' => $text]],
            'Krótki opis.',
        ]);

        $this->assertNotEmpty($extracted['norms']);
        $this->assertTrue(collect($extracted['norms'])->contains(
            fn (string $n): bool => str_contains(mb_strtolower($n), 'en 388')
        ));
        $this->assertContains('Dyneema', $extracted['materials']);
        $this->assertContains('wiskoza bambusowa', $extracted['materials']);
        $this->assertNotEmpty($extracted['use_cases']);
    }

    public function test_sparse_payload_detection(): void
    {
        $service = app(ProductEnrichmentService::class);

        $this->assertTrue($this->invoke($service, 'looksLikeSparsePayload', [[
            'norms' => [],
            'materials' => [],
            'use_cases' => [],
            'features' => [],
        ]]));

        $this->assertFalse($this->invoke($service, 'looksLikeSparsePayload', [[
            'norms' => ['EN 388'],
            'materials' => ['nitryl'],
            'use_cases' => [],
            'features' => [],
        ]]));
    }

    public function test_rejects_real_estate_description_for_gloves(): void
    {
        $service = app(ProductEnrichmentService::class);
        $product = new Product([
            'sku' => 'PILNE-1019',
            'name' => '1019 ZIMA Z POLARU',
            'manufacturer' => 'PILNE',
            'category' => 'REKAWICE',
        ]);
        $junk = 'Find the perfect office, industrial or commercial real estate for your team '
            .'or get specialized space for multi-family housing, healthcare, technology and others. '
            .'Let us help you find your next investment or leasing opportunity.';

        $this->assertTrue($this->invoke($service, 'looksLikeOffTopicDescription', [$junk]));
        $this->assertFalse($this->invoke($service, 'isUsableProductDescription', [$junk, $product]));
        $this->assertTrue($this->invoke($service, 'isUsableProductDescription', [
            'Rękawice zimowe 1019 z polaru marki Urgent. Przeznaczone do prac na zewnątrz w niskich temperaturach. '
            .'Materiał polarowy zapewnia izolację termiczną. Stosowane w magazynach, transporcie i na budowie zimą. '
            .'Model katalogowy 1019. Kategoria PPE — rękawice ochronne.',
            $product,
        ]));
    }

    public function test_rejects_shop_category_index_as_mat_description(): void
    {
        $service = app(ProductEnrichmentService::class);
        $product = new Product([
            'sku' => 'T5921002',
            'name' => 'Chodnik elektroizolacyjny 20KV',
            'manufacturer' => 'SECURA',
        ]);
        $junk = <<<'TXT'
* Amortyzatory bezpieczeństwa
* Gotowe zestawy asekuracyjne
* Linki asekuracyjne
* Punkty kotwiczenia
* Sploplazy / drzewolazy
* Sprzęt arboristyczny
* Sprzęt do pracy w podparciu
* Szelki bezpieczeństwa
* Urządzenia ewakuacyjne
* Zatrzaśniki
* Buty robocze damskie
* Buty robocze męskie
* Buty robocze kompozytowe
TXT;

        $this->assertTrue($this->invoke($service, 'looksLikeCategoryIndexDescription', [$junk]));
        $this->assertFalse($this->invoke($service, 'isUsableProductDescription', [$junk, $product]));
        $this->assertTrue($this->invoke($service, 'isUsableProductDescription', [
            'Chodniki elektroizolacyjne w kl. 2 są przeznaczone do wykładania podłóg w celu ochrony '
            .'pracowników przed zagrożeniami elektrycznymi przy urządzeniach o napięciu do 17 kV. '
            .'Wymiary 1,1 x 2 m. Marka Secura. Klasa 2, gumowy chodnik elektroizolacyjny.',
            $product,
        ]));
    }

    public function test_full_name_confirms_description_without_sku(): void
    {
        $service = app(ProductEnrichmentService::class);
        $product = new Product([
            'sku' => 'T5920000',
            'name' => 'Dywanik elektroizolacyjny 20 KV',
            'manufacturer' => 'SECURA',
        ]);

        $this->assertTrue($this->invoke($service, 'descriptionMentionsProduct', [
            'Chodnik elektroizolacyjny 20 KV (wymiary 1,1 x 2 m) Secura. '
            .'Chodniki elektroizolacyjne w kl. 2 są przeznaczone do wykładania podłóg.',
            $product,
        ]));
    }

    public function test_rejects_foreign_card_that_only_shares_bhp_wording(): void
    {
        $service = app(ProductEnrichmentService::class);
        $product = new Product([
            'sku' => 'PROS-121-S1-GUMA',
            'name' => '121 S1 GUMA',
            'manufacturer' => 'URGENT',
        ]);

        $this->assertFalse($this->invoke($service, 'descriptionMentionsProduct', [
            'Damskie spodnie robocze antystatyczne ESD Portwest AS12 w kolorze granatowym. '
            .'Do skutecznej ochrony ESD wymagane jest zastosowanie pełnego systemu.',
            $product,
        ]));
        $this->assertTrue($this->invoke($service, 'descriptionMentionsProduct', [
            'URGENT 121 S1. Trzewik bezpieczny z metalowym podnoskiem, zamknięty obszar pięty, '
            .'właściwości antyelektrostatyczne i absorpcja energii w pięcie.',
            $product,
        ]));
    }

    public function test_generic_name_falls_back_to_brand_and_ppe_family(): void
    {
        $service = app(ProductEnrichmentService::class);
        $product = new Product([
            'sku' => 'URG-TOP',
            'name' => 'Rękawice robocze',
            'manufacturer' => 'Urgent',
        ]);

        $this->assertTrue($this->invoke($service, 'descriptionMentionsProduct', [
            'Rękawice robocze Urgent z powłoką nitrylową, chwyt w warunkach wilgotnych.',
            $product,
        ]));
        $this->assertFalse($this->invoke($service, 'descriptionMentionsProduct', [
            'Trzewiki robocze Urgent z podnoskiem kompozytowym i podeszwą SRC.',
            $product,
        ]));
    }

    public function test_warehouse_model_description_confirms_without_article_number(): void
    {
        $service = app(ProductEnrichmentService::class);
        $beagle = new Product([
            'sku' => '211600170000',
            'name' => 'BEAGLE',
            'manufacturer' => 'CANIS SAFETY',
        ]);

        $this->assertTrue($this->invoke($service, 'descriptionMentionsProduct', [
            'Półbuty ochronne BEAGLE marki CXS Canis z podnoskiem kompozytowym.',
            $beagle,
        ]));
        $this->assertFalse($this->invoke($service, 'descriptionMentionsProduct', [
            'Półbuty ochronne Marble marki CXS Canis z podnoskiem kompozytowym.',
            $beagle,
        ]));
        $this->assertFalse($this->invoke($service, 'descriptionMentionsProduct', [
            'Tablica informacyjna „Uwaga pies Beagle” marki CXS Canis z twardego PVC.',
            $beagle,
        ]));
    }

    public function test_rejects_zobacz_teaser_and_fallback_keeps_list_after_it(): void
    {
        $teaser = 'Cena netto: 13,33 zł/szt. - Filtry 3M serii 2000 przeznaczone są do skompletowania '
            .'z półmaskami 3M serii 6000. Zgodnie z normą EN143 filtr klasy P2 posiada skuteczność '
            .'filtracji 94% i przeznaczony jest do ochrony przed: (Zobacz klasy ...';
        $full = 'Filtry 3M serii 2000 przeznaczone są do skompletowania z półmaskami 3M serii 6000, '
            .'6500 i 7500 oraz z maską pełnotwarzową 3M serii 6000. Zgodnie z normą EN143 filtr klasy P2 '
            .'posiada skuteczność filtracji 94% i przeznaczony jest do ochrony przed: '
            .'(Zobacz klasyfikację filtrów i pochłaniaczy) cząstkami stałymi i ciekłymi o niskiej '
            .'i średniej toksyczności, NDS≥0,05mg/m3. Mocowane złączen bagnetowym. Spełnia EN143. '
            .'Stosowane przy pracach w pyle i w magazynie.';

        $this->assertTrue(ProductPageFetcher::looksLikeTruncatedShopTeaser($teaser));
        $this->assertFalse(ProductPageFetcher::looksLikeTruncatedShopTeaser($full));
        $this->assertStringNotContainsString('Zobacz', ProductPageFetcher::stripExpandLinkChrome($full));

        $service = app(ProductEnrichmentService::class);
        $this->assertTrue($this->invoke($service, 'looksLikeThinDescription', [$teaser]));
        $this->assertTrue($this->invoke($service, 'looksLikeIncompleteDescription', [$teaser]));

        $fallback = $this->invoke($service, 'fallbackDescriptionFromPages', [
            [
                ['url' => 'https://icd.pl/filtr-3m-2125.html', 'text' => $teaser."\n\n".$full],
            ],
            new Product([
                'sku' => '3M-2125',
                'name' => 'Filtry 3M serii 2000',
                'manufacturer' => '3M',
            ]),
        ]);

        $this->assertStringContainsString('cząstkami stałymi', $fallback);
        $this->assertStringNotContainsString('Zobacz klasy', $fallback);
        $this->assertGreaterThan(mb_strlen($teaser), mb_strlen($fallback));
    }

    public function test_rejects_infield_imprint_as_raptor_description(): void
    {
        $service = app(ProductEnrichmentService::class);
        $product = new Product([
            'sku' => 'T5163000',
            'name' => 'Okulary Raptor przezroczyste',
            'manufacturer' => 'SECURA',
        ]);
        $imprint = "INFIELD Safety GmbH\nNordstraße 10a\n42719 Solingen\n"
            ."Telefon: +49 212 23234 0\nTelefax: +49 212 23234 99\n"
            .'So finden Sie uns: mit Google-Maps';

        $this->assertTrue(ProductPageFetcher::looksLikeCompanyImprint($imprint));
        $this->assertTrue($this->invoke($service, 'looksLikeThinDescription', [$imprint]));
        $this->assertFalse($this->invoke($service, 'isUsableProductDescription', [$imprint, $product]));
        $this->assertSame('', $this->invoke($service, 'fallbackDescriptionFromPages', [
            [['url' => 'https://infield-safety.com/impressum/', 'text' => $imprint]],
            $product,
        ]));
    }

    public function test_compose_full_description_strips_html(): void
    {
        $service = app(ProductEnrichmentService::class);
        $composed = $this->invoke($service, 'composeFullDescription', [[
            'description' => '<div style="white-space:nowrap">Rękawice nitrylowe do montażu.</div>',
        ]]);

        $this->assertSame('Rękawice nitrylowe do montażu.', $composed);
        $this->assertStringNotContainsString('<div', $composed);
    }

    public function test_rejects_ansell_cookie_and_cjk_dump_as_description(): void
    {
        $service = app(ProductEnrichmentService::class);
        $product = new Product([
            'sku' => 'WH20T-00111-09',
            'name' => '2000-WH TSPLUS CVRL HOOD 111.5XL',
            'manufacturer' => 'ANSELL',
        ]);
        $cookies = 'When you visit our website, we store cookies on your browser to collect information. '
            .'You cannot opt-out of our First Party Strictly Necessary Cookies as they are deployed '
            .'in order to ensure the proper functioning of our website. '
            .'Under the California Consumer Privacy Act, you have the right to opt-out of the sale '
            .'of your personal information to third parties. These cookies collect information for analytics.';
        $cjk = "| 项目编号 | 产品名称 | 产品描述 | 包装 | 箱数 |\n"
            ."| WH20-B-BC-111-02-ST1 | BioClean | S | 每个密封PE内包装袋1件 | 25 |\n"
            .str_repeat('无菌洁净服 2000 型号 111 ', 20);

        $this->assertTrue(ProductPageFetcher::looksLikeCookieConsent($cookies));
        $this->assertTrue(ProductPageFetcher::looksLikeCjkDump($cjk));
        $this->assertTrue($this->invoke($service, 'looksLikeRawLocaleDump', [$cookies]));
        $this->assertFalse($this->invoke($service, 'isUsableProductDescription', [$cookies, $product]));
        $this->assertSame('', $this->invoke($service, 'fallbackDescriptionFromPages', [
            [['url' => 'https://www.ansell.com/cn/zh-hans/products/bioclean-2000', 'text' => $cookies."\n\n".$cjk]],
            $product,
        ]));
        $this->assertSame('', $this->invoke($service, 'descriptionFromConfirmedCards', [[
            ['url' => 'https://www.ansell.com/lac/es/products/bioclean-2000', 'text' => $cookies],
        ]]));
    }

    /**
     * @param  list<mixed>  $args
     */
    private function invoke(object $service, string $method, array $args): mixed
    {
        $ref = new ReflectionClass($service);
        $m = $ref->getMethod($method);
        $m->setAccessible(true);

        return $m->invoke($service, ...$args);
    }
}
