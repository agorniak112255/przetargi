<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Product;
use App\Services\Enrichment\CandidateRejection;
use App\Services\Enrichment\ProductPageFetcher;
use App\Services\Enrichment\ProductSearchIdentity;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

final class ProductPageFetcherTest extends TestCase
{
    use RefreshDatabase;

    private const WRONG = [
        'https://artra.pl/products/3815110-arya-300-619060-s1-pl-esd' => 'ARYA 300 619060 S1 PL ESD',
        'https://artra.pl/products/3815108-arya-300-618080-s1-pl-esd' => 'ARYA 300 618080 S1 PL ESD',
        'https://artra.pl/products/3815107-arya-300-618080-s1-esd' => 'ARYA 300 618080 S1 ESD',
    ];

    private const RIGHT = 'https://sklep-bhp.pl/produkt/12345';

    public function test_other_variants_do_not_stop_fetch_before_exact_card(): void
    {
        // Trzy inne warianty ARYA 300 wypełniały limit 3 kart i właściwej już nie otwierano,
        // a końcowe potwierdzenie i tak odrzucało wszystkie trzy.
        $this->assertExactCardFound(fn (string $model): string => $this->card($model));
    }

    public function test_reader_page_of_other_variant_does_not_count_as_card(): void
    {
        // Krótki HTML to dla fetchera blokada WAF — strona idzie przez czytnik,
        // który dotąd przyjmował każdą przeczytaną kartę bez sprawdzenia wariantu.
        $this->assertExactCardFound(fn (string $model): string => '<html><body><h1>'.$model.'</h1>'
            .'<p>Obuwie ochronne ARTRA '.$model.'. Norma EN ISO 20345:2022.</p></body></html>');
    }

    public function test_script_only_shop_is_not_fetched_as_a_card(): void
    {
        $shell = 'https://shop.ansell.com/eu/s/product/hyflex-1181';
        Http::fake([
            self::RIGHT => Http::response($this->card('HyFlex 11-618'), 200),
            '*' => Http::response('', 404),
        ]);

        $product = new Product([
            'sku' => '11618110',
            'name' => 'HyFlex 11-618',
            'manufacturer' => 'Ansell',
        ]);
        $fetched = app(ProductPageFetcher::class)->fetch(
            [
                ['url' => $shell, 'title' => 'HyFlex 11-618', 'snippet' => ''],
                ['url' => self::RIGHT, 'title' => 'HyFlex 11-618', 'snippet' => ''],
            ],
            (string) $product->sku,
            3,
            [],
            $product
        );

        // skorupa Salesforce nie jest nawet pobierana — nie zajmuje miejsca w limicie kart
        Http::assertNotSent(static fn ($request): bool => str_contains($request->url(), 'shop.ansell.com'));
        $this->assertNotContains($shell, array_column($fetched['pages'], 'url'));
        $this->assertContains(
            ['url' => $shell, 'reason' => CandidateRejection::SCRIPT_SHELL],
            $fetched['rejected']
        );
    }

    /**
     * Batch #293: coba.com/pl/produkt/cobastat wymienia „AS060003C” (na metr bieżący) i osobno
     * „AS060003” (rolka). Reguła „dłuższy wariant SKU” odrzucała kartę mimo dokładnego numeru.
     */
    public function test_exact_part_number_next_to_longer_variant_confirms_family_card(): void
    {
        $family = 'https://www.coba.com/pl/produkt/cobastat';
        $other = 'https://www.coba.com/pl/produkt/cobastat-metr';
        Http::fake([
            $family => Http::response($this->cobaFamilyCard(['AS060003C', 'AS060003', 'AS060001']), 200),
            $other => Http::response($this->cobaFamilyCard(['AS060003C', 'AS060001']), 200),
            '*' => Http::response('', 404),
        ]);
        $product = new Product([
            'sku' => 'AS060003',
            'name' => 'COBAstat Szary 0.9m x 18.3m (9mm)',
            'manufacturer' => 'Coba',
        ]);

        $fetched = app(ProductPageFetcher::class)->fetch(
            [
                ['url' => $family, 'title' => 'COBAstat | COBA Europe', 'snippet' => ''],
                ['url' => $other, 'title' => 'COBAstat | COBA Europe', 'snippet' => ''],
            ],
            (string) $product->sku,
            3,
            [],
            $product
        );

        $urls = array_column($fetched['pages'], 'url');
        $this->assertContains($family, $urls, 'dokładny numer części obok dłuższego wariantu to nasza karta');
        $this->assertNotContains($other, $urls);
        $this->assertContains(
            ['url' => $other, 'reason' => CandidateRejection::LONGER_VARIANT],
            $fetched['rejected'],
            'sam dłuższy wariant bez dokładnego numeru nadal odpada'
        );
    }

    /**
     * Batch #296: cennik Coba ma skrócony kod rodziny („LM0102”), a coba.com/pl/produkt/cobawash
     * tylko pełne kody z rozmiarem („LM010201”, „LM010202C”). Karta producenta przechodzi;
     * ta sama tabela u obcego sklepu, doklejona litera albo inny model z cennika — nie.
     */
    public function test_official_family_page_with_size_codes_confirms_short_catalog_code(): void
    {
        $official = 'https://www.coba.com/pl/produkt/cobawash';
        $shop = 'https://sklep-bhp.example.pl/cobawash';
        $letterVariant = 'https://www.coba.com/pl/produkt/cobawash-b';
        $sizeCodes = $this->cobaFamilyCard(['LM010201', 'LM010202', 'LM010202C'], 'COBAwash');
        Http::fake([
            $official => Http::response($sizeCodes, 200),
            $shop => Http::response($sizeCodes, 200),
            $letterVariant => Http::response($this->cobaFamilyCard(['LM0102B', 'LM010201'], 'COBAwash'), 200),
            '*' => Http::response('', 404),
        ]);
        $product = new Product([
            'sku' => 'LM0102',
            'name' => 'COBAwash Czarny/Niebieski 0.85m x 1.2m',
            'manufacturer' => 'Coba',
        ]);
        $fetch = static fn (string $url, Product $p): array => app(ProductPageFetcher::class)->fetch(
            [['url' => $url, 'title' => '', 'snippet' => '']],
            (string) $p->sku,
            3,
            [],
            $p
        );

        $fromOfficial = $fetch($official, $product);
        $this->assertSame([$official], array_column($fromOfficial['pages'], 'url'));
        $page = $fromOfficial['pages'][0];
        // ta sama reguła w końcowym potwierdzeniu karty — inaczej odpadłaby krok dalej
        $this->assertTrue(app(ProductSearchIdentity::class)->isConfirmedProductCard(
            $page['url'],
            (string) $page['title'],
            (string) $page['text'],
            $product
        ));

        $this->assertContains(
            ['url' => $shop, 'reason' => CandidateRejection::LONGER_VARIANT],
            $fetch($shop, $product)['rejected'],
            'obcy sklep z tą samą tabelą nie potwierdza skróconego kodu'
        );
        $this->assertSame([], $fetch($letterVariant, $product)['pages'], 'LM0102B dokleja literę — to inny model');

        $otherModel = new Product([
            'sku' => 'LM0102',
            'name' => 'Superdry Szary 1.15m x 1.75m',
            'manufacturer' => 'Coba',
        ]);
        $this->assertSame([], $fetch($official, $otherModel)['pages'], 'model z cennika musi stać w adresie albo tytule karty');
    }

    /**
     * Batch #305: karta cxs.net.pl ma kod tylko w itemprop="sku" i w klasie Magento, a nagłówek po
     * polsku nie pasuje do angielskiej nazwy z cennika — 37 właściwych kart Canis szło do kosza.
     */
    public function test_microdata_sku_confirms_card_with_localized_heading(): void
    {
        $right = 'https://cxs.net.pl/bluza-robocza-cxs-sirius-lucius-szaro-pomaranczowa.html';
        $otherColour = 'https://cxs.net.pl/bluza-robocza-cxs-sirius-lucius-szaro-zielona.html';
        Http::fake([
            $right => Http::response($this->magentoCard('Bluza robocza CXS Sirius Lucius szaro-pomarańczowa', '1010-001-703-00'), 200),
            $otherColour => Http::response($this->magentoCard('Bluza robocza CXS Sirius Lucius szaro-zielona', '1010-035-708-00'), 200),
            '*' => Http::response('', 404),
        ]);
        $product = new Product([
            'sku' => '1010-001-703-00',
            'name' => 'Men´s jacket SIRIUS, grey-orange, 65% polyester 35% cotton 270g/m2, sizes 44 - 68',
            'manufacturer' => 'Canis',
        ]);

        $fetched = app(ProductPageFetcher::class)->fetch(
            [
                ['url' => $otherColour, 'title' => '', 'snippet' => ''],
                ['url' => $right, 'title' => '', 'snippet' => ''],
            ],
            (string) $product->sku,
            3,
            [],
            $product
        );

        $this->assertSame([$right], array_column($fetched['pages'], 'url'), 'kod z mikrodanych potwierdza kartę, inny kolor odpada');
    }

    /**
     * Batch #312: TOM „1660-001-000-00” to wszystkie kolory; karta cxs.net.pl ma kod koloru
     * „1660-001-411-00” w itemprop sku i szła do kosza jako „inny kod”. Inny model nadal odpada.
     */
    public function test_all_colours_code_accepts_colour_card_of_same_model(): void
    {
        $tom = 'https://cxs.net.pl/koszula-flanelowa-cxs-tom.html';
        Http::fake([
            $tom => Http::response($this->magentoCard('Koszula flanelowa CXS Tom', '1660-001-411-00'), 200),
            '*' => Http::response('', 404),
        ]);
        $product = new Product(['sku' => '1660-001-000-00', 'name' => 'Flannel shirt, blue, green, flannel, 100% cotton', 'manufacturer' => 'Canis']);
        $product->model_name = 'TOM';

        $fetched = app(ProductPageFetcher::class)->fetch([['url' => $tom, 'title' => '', 'snippet' => '']], (string) $product->sku, 3, [], $product);

        $this->assertNotContains(CandidateRejection::CLAIMS_OTHER_CODE, array_column($fetched['rejected'], 'reason'));

        $otherModel = new Product(['sku' => '1660-002-000-00', 'name' => 'Flannel shirt, red, flannel, 100% cotton', 'manufacturer' => 'Canis']);
        $otherModel->model_name = 'TOM';
        $fetchedOther = app(ProductPageFetcher::class)->fetch([['url' => $tom, 'title' => '', 'snippet' => '']], (string) $otherModel->sku, 3, [], $otherModel);
        $this->assertSame([], $fetchedOther['pages']);
        $this->assertContains(CandidateRejection::CLAIMS_OTHER_CODE, array_column($fetchedOther['rejected'], 'reason'));
    }

    private function magentoCard(string $heading, string $sku): string
    {
        return '<!DOCTYPE html><html lang="pl"><head><meta charset="utf-8"><title>'.$heading.'</title></head>'
            .'<body class="catalog-product-view catalog_product_view_sku_'.$sku.'"><main>'
            .'<h1 class="page-title"><span itemprop="name">'.$heading.'</span></h1>'
            .'<div class="product-info-stock-sku"><div class="value" itemprop="sku">'.$sku.'</div></div>'
            .'<div class="product attribute description"><p>'
            .str_repeat('Odzież robocza CXS z tkaniny canvas 65% bawełna i 35% poliester o gramaturze 270 g/m², z kieszeniami i odblaskami. ', 14)
            .'</p></div></main></body></html>';
    }

    /**
     * @param  list<string>  $partNumbers
     */
    private function cobaFamilyCard(array $partNumbers, string $model = 'COBAstat'): string
    {
        $rows = '';
        foreach ($partNumbers as $code) {
            $rows .= '<tr><td data-label="Numer części">'.$code.'</td><td>0,9 m x 18,3 m</td><td>Szary</td></tr>';
        }

        return '<!DOCTYPE html><html lang="pl"><head><meta charset="utf-8"><title>'.$model.' | COBA Europe</title></head>'
            .'<body><main><h1>'.$model.'®</h1>'
            .'<p>Mata antystatyczna COBA Europe '.$model.' rozprasza ładunki elektrostatyczne w strefach EPA. '
            .str_repeat('Warstwa wierzchnia z kauczuku o dużej odporności na ścieranie i chemikalia, spód antypoślizgowy. ', 12)
            .'</p><table><tr><th>Numer części</th><th>Rozmiar</th><th>Kolor</th></tr>'.$rows.'</table>'
            .'</main></body></html>';
    }

    /**
     * @param  callable(string): string  $html
     */
    private function assertExactCardFound(callable $html): void
    {
        $fakes = [];
        $results = [];
        foreach (self::WRONG as $url => $model) {
            $fakes[$url] = Http::response($html($model), 200);
            $results[] = ['url' => $url, 'title' => '', 'snippet' => ''];
        }
        $fakes[self::RIGHT] = Http::response($html('ARYA 300 673560 S1 PL'), 200);
        $results[] = ['url' => self::RIGHT, 'title' => '', 'snippet' => ''];
        Http::fake($fakes + ['*' => Http::response('', 404)]);

        $product = new Product([
            'sku' => 'ARYA 300 673560 S1 P',
            'name' => 'ARYA 300 673560 S1 PL',
            'manufacturer' => 'ARTRA',
            'category' => 'Obuwie',
        ]);
        $fetched = app(ProductPageFetcher::class)->fetch($results, (string) $product->sku, 3, [], $product);
        $urls = array_column($fetched['pages'], 'url');

        $this->assertContains(self::RIGHT, $urls);
        $this->assertNotContains('https://artra.pl/products/3815110-arya-300-619060-s1-pl-esd', $urls);
        $this->assertContains(
            [
                'url' => 'https://artra.pl/products/3815110-arya-300-619060-s1-pl-esd',
                'reason' => CandidateRejection::UNCONFIRMED_STRICT,
            ],
            $fetched['rejected']
        );
    }

    /** Pełna karta sklepu — dłuższa niż próg, od którego fetcher uznaje stronę za blokadę WAF. */
    public function test_link_labels_travel_next_to_document_urls(): void
    {
        // Na kartach sklepów wszystkie pliki wiszą pod jednym „/download/file/id/…” i tylko tekst
        // odsyłacza mówi, czy to deklaracja, instrukcja czy tabela rozmiarów.
        $html = $this->card('ARYA 300 673560 S1 P')
            .'<a href="/pliki/instrukcja.pdf">Instrukcja obsługi</a>'
            .'<a href="/pliki/deklaracja-673560.pdf">Deklaracja zgodności UE</a>';
        Http::fake([
            self::RIGHT => Http::response($html, 200),
            '*' => Http::response('', 404),
        ]);

        $product = new Product([
            'sku' => '673560',
            'name' => 'ARYA 300 673560 S1 P',
            'manufacturer' => 'ARTRA',
        ]);
        $fetched = app(ProductPageFetcher::class)->fetch(
            [['url' => self::RIGHT, 'title' => 'ARYA 300 673560 S1 P', 'snippet' => '']],
            (string) $product->sku,
            3,
            [],
            $product,
        );

        $this->assertContains('https://sklep-bhp.pl/pliki/deklaracja-673560.pdf', $fetched['document_urls']);
        $this->assertSame(
            'Deklaracja zgodności UE',
            $fetched['document_labels']['https://sklep-bhp.pl/pliki/deklaracja-673560.pdf'] ?? null,
        );
        // mapa opisuje wyłącznie zwrócone adresy
        foreach (array_keys($fetched['document_labels']) as $url) {
            $this->assertContains($url, $fetched['document_urls']);
        }
    }

    /**
     * 27.09.2026: strona COBAswitch (mata elektroizolacyjna) przechodziła jako karta maty przewodzącej CBF0100 — marka
     * i słowa „mata”, „gumy”, wymiar się zgadzały. Jej tabela części wymienia same kody SM…, więc to karta innego wyrobu:
     * bez opisu z niej i bez jej arkusza danych, a w przebiegu osobny powód z rodziną kodów.
     */
    public function test_manufacturer_page_listing_only_other_code_family_is_not_the_card(): void
    {
        $switch = 'https://www.coba.com/pl/produkt/cobaswitch';
        $own = 'https://www.coba.com/pl/produkt/mata-przewodzaca-z-gumy-neoprenowej';
        $page = static fn (string $title, string $body, array $codes, string $sheet): string => '<!DOCTYPE html><html lang="pl"><head>'
            .'<meta charset="utf-8"><title>'.$title.' - COBA PL</title></head><body><main><h1>'.$title.'</h1><p>'
            .str_repeat($body.' ', 6).'</p><table><tr><th>Numer części</th><th>Rozmiar</th></tr>'
            .implode('', array_map(static fn (string $c): string => '<tr><td>'.$c.'</td><td>0,6 m x 1,2 m</td></tr>', $codes))
            .'</table><a href="https://www.coba.com/datasheets/'.$sheet.'-pl_PL.pdf">Arkusz danych</a></main></body></html>';
        Http::fake([
            $switch => Http::response($page('COBAswitch', 'Mata elektroizolacyjna COBA z gumy, czarna, 0,6 m x 1,2 m, do rozdzielnic. Norma EN 61111.',
                ['SM010020', 'SM010030', 'SM010040C'], 'cobaswitch'), 200),
            $own => Http::response($page('Mata przewodząca z gumy neoprenowej', 'Mata przewodząca COBA z gumy neoprenowej, 0,6 m x 1,2 m, zatrzask 10 mm. IEC 61340-5-1.',
                ['CBF010004'], 'mata-przewodzaca-z-gumy-neoprenowej'), 200),
            '*' => Http::response('', 404),
        ]);
        $product = new Product(['sku' => 'CBF0100', 'name' => 'Mata przewodząca z gumy neoprenowej 0.6m x 1.2m (2mm)', 'manufacturer' => 'Coba']);

        $fetched = app(ProductPageFetcher::class)->fetch(
            [['url' => $switch, 'title' => '', 'snippet' => ''], ['url' => $own, 'title' => '', 'snippet' => '']],
            'CBF0100',
            3,
            ['coba.com', 'www.coba.com'],
            $product,
        );

        $this->assertSame([$own], array_column($fetched['pages'], 'url'));
        $this->assertContains(['url' => $switch, 'reason' => CandidateRejection::OTHER_CODE_FAMILY, 'detail' => 'SM'], $fetched['rejected']);
        $this->assertSame(['https://www.coba.com/datasheets/mata-przewodzaca-z-gumy-neoprenowej-pl_PL.pdf'], $fetched['document_urls']);
    }

    /** Lista arkuszy producenta wymienia wszystkie wyroby — nie jest kartą żadnego z nich; sam arkusz PDF z niej tak. */
    public function test_manufacturer_download_center_is_not_a_product_card(): void
    {
        $identity = app(ProductSearchIdentity::class);

        $this->assertTrue($identity->looksLikeNonProductCardUrl('https://www.coba.com/pl/karty-produktow'));
        $this->assertTrue($identity->looksLikeNonProductCardUrl('https://www.coba.com/pl/arkusze-danych/'));
        $this->assertTrue($identity->looksLikeNonProductCardUrl('https://www.example.com/download-center'));
        $this->assertFalse($identity->looksLikeNonProductCardUrl('https://www.coba.com/datasheets/mata-przewodzaca-stolowa-pl_PL.pdf'));
        $this->assertFalse($identity->looksLikeNonProductCardUrl('https://www.coba.com/pl/produkt/mata-przewodzaca-stolowa'));
    }

    public function test_other_code_families_come_only_from_a_parts_table_of_another_family(): void
    {
        $identity = app(ProductSearchIdentity::class);
        $coba = static fn (string $sku): Product => new Product(['sku' => $sku, 'name' => 'Mata COBA', 'manufacturer' => 'Coba']);
        $deckplate = 'Deckplate Anti-Static. Numer części: DPS010005C, DPS010005, DPS010915, DPS010609. EN 14041, DIN 51130.';

        $this->assertSame(['dps'], $identity->officialPageOtherCodeFamilies('https://www.coba.com/pl/produkt/deckplate-anti-static', 'Deckplate Anti-Static', $deckplate, $coba('HR060004C')));
        $this->assertSame([], $identity->officialPageOtherCodeFamilies('https://www.coba.com/pl/produkt/deckplate-anti-static', 'Deckplate Anti-Static', $deckplate, $coba('DPS010005C')), 'nasza rodzina na stronie');
        $this->assertSame([], $identity->officialPageOtherCodeFamilies(
            'https://www.coba.com/product/orthomat-diamond',
            'Orthomat Diamond',
            'Part numbers: DAF010705C12, DAF01070512, DAF010703C09, DAF01070309.',
            $coba('DAF0107-4'),
        ), 'skrócony kod z cennika z dopiskiem: ta sama rodzina DAF');
        $this->assertSame([], $identity->officialPageOtherCodeFamilies(
            'https://www.coba.com/pl/produkt/cobagrip-light',
            'COBAGRiP Light',
            'Reakcja na ogień EN 13501-1, DIN 51130 R13, BS 7976-2, kolor RAL 7035, obciążenie do 1500 kg, both 2021, EAN 5012345678901.'
            .' Tabela: EN13501, DIN51130, BS7976, IEC61340, RAL7035, NZS1716.',
            $coba('GRP070001L'),
        ), 'same normy, kolor, wymiary i EAN to nie tabela części');
        $this->assertSame([], $identity->officialPageOtherCodeFamilies('https://sklep-bhp.pl/deckplate', 'Deckplate', $deckplate, $coba('HR060004C')), 'sklep, nie producent');
        $this->assertSame([], $identity->officialPageOtherCodeFamilies('https://www.coba.com/pl/produkt/deckplate-anti-static', 'Deckplate', $deckplate, $coba('P317-DRUM')), 'kod magazynowy spoza kształtu');
    }

    /**
     * 27.09.2026: strona innego wyrobu u producenta (coba.com/…/cobaswitch przy macie przewodzącej) dokładała swój arkusz
     * danych. Pliki z niepotwierdzonej strony zostają tylko z kodem naszego wyrobu — jak zdjęcia, które taka strona
     * i tak odrzucała.
     */
    public function test_unconfirmed_manufacturer_page_contributes_only_documents_with_product_code(): void
    {
        $other = 'https://artra.pl/products/3813240-aral-927-6160-s3';
        Http::fake([
            self::RIGHT => Http::response($this->card('ARYA 300 673560 S1 P')
                .'<a href="/pliki/deklaracja-673560.pdf">Deklaracja zgodności UE</a>', 200),
            $other => Http::response($this->card('ARAL 927 6160 S3')
                .'<a href="https://artra.pl/cdn/shop/files/PL-KP-ARAL_927_6160_S3.pdf">Karta produktu</a>'
                .'<a href="https://artra.pl/cdn/shop/files/deklaracja-zgodnosci-ue.pdf">Deklaracja zgodności UE</a>'
                .'<a href="https://artra.pl/cdn/shop/files/PL-KP-673560.pdf">Karta produktu</a>', 200),
            '*' => Http::response('', 404),
        ]);
        $product = new Product(['sku' => '673560', 'name' => 'ARYA 300 673560 S1 P', 'manufacturer' => 'ARTRA']);

        $fetched = app(ProductPageFetcher::class)->fetch(
            [['url' => self::RIGHT, 'title' => 'ARYA 300 673560 S1 P', 'snippet' => ''], ['url' => $other, 'title' => '', 'snippet' => '']],
            '673560',
            3,
            ['artra.pl'],
            $product,
        );

        $this->assertNotContains($other, array_column($fetched['pages'], 'url'), 'strona innego modelu nie jest kartą');
        $this->assertContains('https://sklep-bhp.pl/pliki/deklaracja-673560.pdf', $fetched['document_urls']);
        $this->assertContains('https://artra.pl/cdn/shop/files/PL-KP-673560.pdf', $fetched['document_urls'], 'plik z kodem wyrobu');
        $this->assertNotContains('https://artra.pl/cdn/shop/files/PL-KP-ARAL_927_6160_S3.pdf', $fetched['document_urls']);
        $this->assertNotContains('https://artra.pl/cdn/shop/files/deklaracja-zgodnosci-ue.pdf', $fetched['document_urls']);
    }

    /** Strona przeczytana czytnikiem, ale innego wyrobu — ta sama zasada co w zwykłym pobraniu. */
    public function test_reader_page_of_other_product_contributes_only_documents_with_product_code(): void
    {
        $card = 'https://www.3mpolska.pl/3M/pl_PL/p/d/v101476099/';
        Http::fake([
            'https://r.jina.ai/*' => Http::response(
                "Title: 3M 471\n\n# Taśma winylowa 3M 471\n\n"
                ."Taśma winylowa 3M 471 do oznaczania podłóg, kod 700001.\n\n"
                ."[Karta techniczna](https://multimedia.3m.com/mws/media/471-karta-techniczna.pdf)\n"
                .'[Karta 716973](https://multimedia.3m.com/mws/media/716973-karta.pdf)',
                200
            ),
            '*' => Http::response('', 403),
        ]);
        $product = new Product(['sku' => '716973', 'name' => 'Taśma do galwanizacji 3M 470', 'manufacturer' => '3M']);

        $fetched = app(ProductPageFetcher::class)->fetch([['url' => $card, 'title' => '', 'snippet' => '']], '716973', 1, [], $product);

        $this->assertSame([], $fetched['pages'], 'karta innej taśmy');
        $this->assertSame(['https://multimedia.3m.com/mws/media/716973-karta.pdf'], $fetched['document_urls']);
    }

    /** PDF podany wprost przez wyszukiwarkę nie ma strony, która wiąże go z wyrobem — liczy się kod w adresie albo tytule. */
    public function test_search_result_pdf_needs_product_code(): void
    {
        Http::fake(['*' => Http::response('', 404)]);
        $product = new Product(['sku' => '673560', 'name' => 'ARYA 300 673560 S1 P', 'manufacturer' => 'ARTRA']);

        $fetched = app(ProductPageFetcher::class)->fetch([
            ['url' => 'https://sklep-bhp.pl/pliki/karta-katalogowa-obuwia.pdf', 'title' => 'Karta katalogowa ARTRA', 'snippet' => ''],
            ['url' => 'https://sklep-bhp.pl/pliki/karta.pdf', 'title' => 'Karta katalogowa ARYA 300 673560', 'snippet' => ''],
        ], '673560', 3, [], $product);

        $this->assertSame(['https://sklep-bhp.pl/pliki/karta.pdf'], $fetched['document_urls']);
    }

    private function card(string $model): string
    {
        $specs = '';
        foreach ([
            'Cholewka' => 'wegańska skóra NUVYA SKINYUM, miękka i oddychająca',
            'Podszewka' => 'AEROLYUM zapewniająca przewiewność',
            'Wyściółka' => 'SOFTYUM 2D',
            'Podnosek' => 'kompozytowy LIBERYUM',
            'Wkładka' => 'FLEXYUM wspierająca naturalny ruch',
            'Podeszwa' => 'LYFTOR PU.2D z technologią LEVITARYUM',
            'Norma' => 'EN ISO 20345:2022',
            'Rozmiary' => 'EU 35–48',
        ] as $label => $value) {
            $specs .= '<li><strong>'.$label.':</strong> '.$value.'</li>';
        }

        return '<!DOCTYPE html><html lang="pl"><head><meta charset="utf-8"><title>'.$model.' | ARTRA</title></head>'
            .'<body><main><h1>'.$model.'</h1>'
            .'<p>Obuwie ochronne ARTRA '.$model.' to lekki model przeznaczony do pracy w przemyśle, '
            .'logistyce i magazynach. Konstrukcja ARELAX zwiększa przestrzeń na palce i redukuje zmęczenie stóp '
            .'podczas całej zmiany. Podeszwa jest odporna na oleje i paliwa oraz ma właściwości antypoślizgowe.</p>'
            .'<ul>'.$specs.'</ul></main></body></html>';
    }

    /** Adres i produkt wspólne dla trzech przypadków zapory — hahn-kolb (Akamai). */
    private function wallCase(): array
    {
        return [
            'https://www.hahn-kolb.net/ANSELL-Replacement-belt-and-buckle-AC01P-00014-00/95282536.sku/en/US/EUR/',
            new Product([
                'sku' => 'AC01P-00014-00',
                'name' => 'GREY PES BLT & YKK BCKL LENGTH 150CM',
                'manufacturer' => 'Ansell',
            ]),
        ];
    }

    public function test_waf_403_with_reader_refusal_is_a_permanent_bot_wall(): void
    {
        // hahn-kolb (Akamai): 403 „Access Denied” dla karty i dla readera — blokada stała
        [$card, $product] = $this->wallCase();
        Http::fake(['*' => Http::response('Access Denied', 403)]);

        $fetched = app(ProductPageFetcher::class)->fetch(
            [['url' => $card, 'title' => '', 'snippet' => '']], 'AC01P-00014-00', 1, [], $product,
        );

        $this->assertSame([], $fetched['pages']);
        $this->assertSame(
            [['url' => $card, 'reason' => CandidateRejection::BOT_WALL, 'detail' => 'reader: odmowa 403']],
            $fetched['rejected']
        );
    }

    public function test_connection_timeout_is_a_retryable_fetch_failure(): void
    {
        // timeout bez statusu to co innego: strona może odpowiedzieć za chwilę
        [$card, $product] = $this->wallCase();
        Http::fake(['*' => Http::failedConnection('cURL error 28: Connection timed out')]);

        $fetched = app(ProductPageFetcher::class)->fetch(
            [['url' => $card, 'title' => '', 'snippet' => '']], 'AC01P-00014-00', 1, [], $product,
        );

        $this->assertSame(
            [['url' => $card, 'reason' => CandidateRejection::FETCH_FAILED, 'detail' => 'brak odpowiedzi']],
            $fetched['rejected']
        );
    }

    public function test_waf_403_with_reader_rate_limit_is_retryable(): void
    {
        // zapora sklepu, ale reader odrzucił z limitem 429 — to jego chwilowa porażka,
        // nie blokada stała: karta wraca do ponowienia, nie do ręki
        [$card, $product] = $this->wallCase();
        Http::fake([
            'https://r.jina.ai/*' => Http::response('Rate limit exceeded', 429),
            '*' => Http::response('Access Denied', 403),
        ]);

        $fetched = app(ProductPageFetcher::class)->fetch(
            [['url' => $card, 'title' => '', 'snippet' => '']], 'AC01P-00014-00', 1, [], $product,
        );

        $this->assertSame(
            [['url' => $card, 'reason' => CandidateRejection::FETCH_FAILED, 'detail' => 'reader: limit 429']],
            $fetched['rejected']
        );
    }

    public function test_reader_page_carries_its_own_images_from_a_media_cdn(): void
    {
        // Karta 3M idzie przez czytnik (bezpośrednie pobranie blokowane), a packshoty
        // leżą na osobnym serwerze mediów. Strona z czytnika nie niosła własnych zdjęć,
        // więc zostawała pula zbiorcza filtrowana po hoście — i wycinała je co do jednego.
        $card = 'https://www.3mpolska.pl/3M/pl_PL/p/d/v101476018/';
        $photo = 'https://multimedia.3m.com/mws/media/51586J/3m-tm-470-electroplating-anaod.jpg';

        Http::fake([
            'https://r.jina.ai/*' => Http::response(
                "Title: 3M 470\n\n# Taśma do galwanizacji 3M 470\n\n"
                .'![packshot]('.$photo.")\n\n"
                .'Taśma galwanizacyjna 3M 470, 25 mm x 33 m, kod 716973. Norma EN ISO 9001.'
                ."\n\n[Karta techniczna](https://multimedia.3m.com/mws/media/470-karta-techniczna.pdf)",
                200
            ),
            '*' => Http::response('', 403),
        ]);

        $product = new Product([
            'sku' => '716973',
            'name' => 'Taśma do galwanizacji 3M 470',
            'manufacturer' => '3M',
        ]);

        $fetched = app(ProductPageFetcher::class)->fetch(
            [['url' => $card, 'title' => '', 'snippet' => '']],
            '716973',
            1,
            [],
            $product,
        );

        $this->assertNotSame([], $fetched['pages']);
        $page = $fetched['pages'][0];
        $this->assertSame($card, $page['url']);
        $this->assertContains($photo, $page['image_urls'] ?? []);
        $this->assertContains($photo, $fetched['image_urls']);
        // pliki strony z czytnika idą przy stronie — serwis bierze je tylko ze stron opisu
        $this->assertSame(['https://multimedia.3m.com/mws/media/470-karta-techniczna.pdf'], $page['document_urls'] ?? null);
    }

    /**
     * Batch #319: Akamai zrywa połączenie z 3m.com bez statusu. Karta /p/dc/ nie szła przez
     * czytnik — przebieg mówił „strona nie odpowiedziała”, a produkt trafiał do ręki.
     */
    public function test_three_m_variant_card_with_dropped_connection_is_read_via_reader(): void
    {
        $card = 'https://www.3m.com/3M/sl_SI/p/dc/v000059787/';
        $this->fakeThreeMDroppedConnection(Http::response(
            "Title: 3M™ Textured Surface Applicator TSA-2\n\n# Aplikator do płaszczyzn 3M™ TSA-2\n\n"
            .'Aplikator 3M TSA-2 do nakładania powłok teksturowanych na płaszczyznach, kod 887747.',
            200
        ));
        $product = new Product([
            'sku' => '887747',
            'name' => '3M™ Aplikator do płaszczyzn TSA-2',
            'manufacturer' => '3M',
        ]);

        $fetched = app(ProductPageFetcher::class)->fetch(
            [['url' => $card, 'title' => '', 'snippet' => '']], '887747', 1, [], $product,
        );

        $this->assertSame([$card], array_column($fetched['pages'], 'url'));
    }

    public function test_three_m_card_rejection_names_reader_failure(): void
    {
        // bez tego przebieg mówił „brak odpowiedzi”, choć próbował też czytnik — nie było wiadomo dlaczego padł
        $card = 'https://www.3m.com/3M/en_US/p/d/v000075479/';
        $this->fakeThreeMDroppedConnection(Http::response('Rate limit exceeded', 429));
        $product = new Product([
            'sku' => 'FF-400-01',
            'name' => 'Taśmy nagłowia 3M™ Secure Click™ do masek serii FF-800, FF-400-01',
            'manufacturer' => '3M',
        ]);

        $fetched = app(ProductPageFetcher::class)->fetch(
            [['url' => $card, 'title' => '', 'snippet' => '']], 'FF-400-01', 1, [], $product,
        );

        $this->assertSame(
            [['url' => $card, 'reason' => CandidateRejection::FETCH_FAILED, 'detail' => 'reader: limit 429']],
            $fetched['rejected']
        );
    }

    /**
     * Akamai zrywa połączenie z 3m.com; czytnik odpowiada podaną odpowiedzią. Jedna funkcja
     * zamiast mapy adresów: Http::fake woła każdy stub z mapy, a failedConnection pod „*”
     * rzucałby wyjątek także przy żądaniu do czytnika.
     */
    private function fakeThreeMDroppedConnection(mixed $readerResponse): void
    {
        Http::fake(static function ($request) use ($readerResponse) {
            if (str_starts_with($request->url(), 'https://r.jina.ai/')) {
                return $readerResponse;
            }

            return Http::failedConnection('cURL error 35: OpenSSL SSL_connect: Connection was reset');
        });
    }
}
