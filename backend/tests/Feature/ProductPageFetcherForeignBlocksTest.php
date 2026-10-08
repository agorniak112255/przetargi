<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Product;
use App\Services\Enrichment\ProductPageFetcher;
use App\Support\HtmlBareLessThan;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * Tekst, zdjęcia i pliki strony bez bloków innych wyrobów, poradników i meta-opisu (etap 3, ponowny audyt Coby
 * i audyt UVEX, 08.10.2026). Fixture to okrojone prawdziwe strony (tests/Fixtures/pages, pobrane 08.10.2026):
 * nagłówek z meta, treść od <div id="primary"> / <main> do stopki, bez skryptów i stylów.
 */
final class ProductPageFetcherForeignBlocksTest extends TestCase
{
    use RefreshDatabase;

    /**
     * coba.com/pl/produkt/cobaswitch: kafelek „Podobne produkty” COBAswitch BS EN 61111 dawał zwykłemu COBAswitch
     * normę BS EN 61111:2009 w polu norm. Tabela części (kody wariantów) i opis główny zostają.
     */
    public function test_coba_related_tile_is_cut_but_parts_table_and_main_description_stay(): void
    {
        $url = 'https://www.coba.com/pl/produkt/cobaswitch';
        $page = $this->fetchOne($url, 'coba-pl-cobaswitch.html', new Product([
            'sku' => 'SM010010',
            'name' => 'COBAswitch Czarny 0.9m x 10m (6mm)',
            'manufacturer' => 'Coba',
        ]));

        $text = $page['text'];
        $this->assertStringNotContainsString('61111', $text, 'norma z kafelka innego wyrobu');
        $this->assertStringNotContainsString('Zgodna z VDE', $text);
        $this->assertStringNotContainsString('Podobne produkty', $text);
        $this->assertStringContainsString('Zaprojektowana do stosowania w obszarze rozdzielnic elektrycznych', $text);
        $this->assertStringContainsString('BS921:1976', $text, 'norma z opisu głównego zostaje');
        $this->assertStringContainsString('Materiał guma naturalna na bazie polimeru', $text, 'specyfikacja techniczna zostaje');
        foreach (['SM010010', 'SM010040C', 'SM0112059'] as $code) {
            $this->assertStringContainsString($code, $text, 'tabela części zostaje w tekście');
            $this->assertContains(['type' => 'part', 'value' => $code], $page['markup_codes']);
        }

        $images = $page['image_urls'];
        $this->assertContains('https://www.coba.com/pl/wp-content/uploads/sites/6/2022/10/af-cobaswitch-workplace-matting-1.jpg', $images);
        foreach ($images as $image) {
            $this->assertStringNotContainsString('bs-en-61111', $image, 'zdjęcie z kafelka nie trafia do galerii');
            $this->assertStringNotContainsString('cobaswitch-vde', $image);
        }
        $this->assertContains('https://www.coba.com/datasheets/cobaswitch-pl_PL.pdf', $page['document_urls']);
    }

    /**
     * coba.com/pl/produkt/tough-lock-eco: „właściwości elektrostatyczne” z kafelka Tough-Lock ESD. Zakładka „Akcesoria”
     * (<section id="accessories" class="is-section related products">) i kody krawędzi w tabeli części zostają.
     */
    public function test_coba_esd_tile_does_not_reach_tough_lock_eco_text_or_gallery(): void
    {
        $url = 'https://www.coba.com/pl/produkt/tough-lock-eco';
        $page = $this->fetchOne($url, 'coba-pl-tough-lock-eco.html', new Product([
            'sku' => 'TLS010001E',
            'name' => 'Tough-Lock Eco Płytka Czarny 500 x 500 mm (zestaw 4 szt.)',
            'manufacturer' => 'Coba',
        ]));

        $text = $page['text'];
        $this->assertStringNotContainsString('elektrostatyczn', $text);
        $this->assertStringNotContainsString('Studded Tile', $text);
        $this->assertStringContainsString('Płytki wytwarzane w 100% z PVC pochodzącego z recyklingu', $text);
        $this->assertStringContainsString('Akcesoria', $text);
        foreach (['TLS010001E', 'TLE010001E', 'TLC010001E'] as $code) {
            $this->assertStringContainsString($code, $text);
        }
        foreach ($page['image_urls'] as $image) {
            $this->assertStringNotContainsString('Tough-Lock-ESD', $image, 'zdjęcie kafelka Tough-Lock ESD');
            $this->assertStringNotContainsString('studded', $image);
        }
        $this->assertContains('https://www.coba.com/datasheets/tough-lock-eco-pl_PL.pdf', $page['document_urls']);
    }

    /**
     * coba.com/product/esd-senso-dial: „ESD approved, measured according to IEC 61340-4-5” stoi tylko w meta-opisie,
     * a karta szarego Senso Dial (bez ESD) dostała ESD i normę. Meta-opis nie wchodzi do tekstu strony; strona
     * nadal potwierdza wyrób (kod w tabeli części).
     */
    public function test_meta_description_is_not_page_text(): void
    {
        $url = 'https://www.coba.com/product/esd-senso-dial';
        $html = $this->fixture('coba-en-esd-senso-dial.html');
        $this->assertStringContainsString('measured according to IEC 61340-4-5', $html, 'fixture niesie meta-opis');

        $page = $this->fetchOne($url, 'coba-en-esd-senso-dial.html', new Product([
            'sku' => 'SN060002',
            'name' => 'Senso Dial Szary 1m x 10m (10mm)',
            'manufacturer' => 'Coba',
        ]));

        $text = $page['text'];
        $this->assertStringNotContainsString('61340-4-5', $text);
        $this->assertStringNotContainsString('measured according to', $text);
        $this->assertStringContainsString('Foam backing provides fatigue-relief.', $text);
        $this->assertStringContainsString('SN060002', $text);
        // kafelki „Related products” (Deckplate, Diamond Tread…) też odpadają
        $this->assertStringNotContainsString('Deckplate', $text);
        foreach ($page['image_urls'] as $image) {
            $this->assertStringNotContainsString('deckplate', $image);
            $this->assertStringNotContainsString('diamond-tread', $image);
        }
    }

    /**
     * coba.com/pc/cable-protectors: norma z tytułu poradnika („Slip resistance … according to DIN 51097 and DIN 51130”)
     * trafiła do pola norm CablePro Mat. Blok „Buying Guides” odpada z tekstem kafelków i zdjęciami.
     */
    public function test_buying_guide_teasers_are_cut(): void
    {
        $html = $this->fixture('coba-en-cable-protectors.html');
        $this->assertStringContainsString('DIN 51097', $html);

        $text = app(ProductPageFetcher::class)->mainTextFromHtml($html);

        $this->assertStringNotContainsString('DIN 51097', $text);
        $this->assertStringNotContainsString('Buying Guides', $text);
        $this->assertStringContainsString('CablePro is our range of cable protectors', $text);
        $this->assertStringContainsString('Why use a Cable Protector?', $text, 'treść za blokiem poradników zostaje');
    }

    /**
     * gloves.co.uk przy HexArmor Chrome SLT 4062: „class 00 … up to 500V AC” (Ansell ActivArmr z „Customers also
     * bought”) i „50 cal/cm²” (Delta Plus VV914 z „Related items”) szły do opisu, a zdjęcie Delta Plus do galerii.
     * Zagnieżdżone bloki — dawny regex kończył blok na pierwszym „</div>”.
     */
    public function test_shop_customers_also_bought_and_related_items_are_cut(): void
    {
        $url = 'https://www.gloves.co.uk/hexarmor-chrome-slt-4062-arc-flash-gloves-with-extended-cuffs.html';
        $page = $this->fetchOne($url, 'gloves-co-uk-hexarmor-4062.html', new Product([
            'sku' => 'HA4062',
            'name' => 'Rękawice HexArmor Chrome SLT 4062',
            'manufacturer' => 'HexArmor',
        ]));

        $text = $page['text'];
        foreach (['500V', 'class 00', '50 cal', 'neoprene', 'RIG0014Y', 'VV914'] as $foreign) {
            $this->assertStringNotContainsString($foreign, $text);
        }
        $this->assertStringContainsString('ISO Blade Cut Level E', $text);
        $this->assertStringNotContainsString('free UK delivery', $text, 'meta-opis sklepu');
        $this->assertContains('https://www.gloves.co.uk/user/products/large/hexarmor-slt-4062-gloves-1.jpg', $page['image_urls']);
        foreach ($page['image_urls'] as $image) {
            $this->assertStringNotContainsString('Delta_Plus', $image);
            $this->assertStringNotContainsString('eureka', $image);
        }
    }

    /**
     * Bloki zbudowane inaczej niż na fixture: kafelek w kilku poziomach <div>, sekcja za nagłówkiem bez klasy
     * („Zobacz także”), pliki „related-documents” tego wyrobu, kafelek z PDF innego wyrobu, węzeł JSON-LD innego
     * wyrobu i niemiecki „Ähnliche Produkte”.
     */
    public function test_nested_blocks_heading_sections_and_own_documents(): void
    {
        $url = 'https://sklep.example.pl/rekawice-x100';
        $html = '<!DOCTYPE html><html><head><title>Rękawice X100</title>'
            .'<meta name="description" content="Rękawice X100 z certyfikatem EN 60903 klasa 00">'
            .'<script type="application/ld+json">'.json_encode(['@context' => 'https://schema.org', '@graph' => [
                ['@type' => 'Product', 'name' => 'Rękawice X100', 'sku' => 'X100', 'description' => 'Rękawice nitrylowe X100 do prac montażowych.'],
                ['@type' => 'Product', 'name' => 'Rękawice Y200', 'sku' => 'Y200', 'description' => 'Rękawice elektroizolacyjne klasy 0 do 1000 V.'],
            ]]).'</script></head><body><main>'
            .'<h1>Rękawice X100</h1>'
            .'<div class="product-description"><p>Rękawice nitrylowe X100 do prac montażowych, odporne na ścieranie, '
            .'zgodne z EN 388:2016 4121X. Mankiet ściągacz, powlekana dłoń.</p></div>'
            .'<div class="related-documents"><a href="/pliki/deklaracja-zgodnosci-x100.pdf">Deklaracja zgodności</a></div>'
            .'<div class="block related"><div class="block-title"><strong>Podobne</strong></div><div class="items">'
            .'<div class="item"><div class="product-detail"><h3><a href="/y200">Rękawice Y200</a></h3>'
            .'<p>Rękawice elektroizolacyjne klasy 0 do 1000 V AC.</p></div></div>'
            .'<div class="item"><div><div><p>Rękawice Z300 z ochroną przed łukiem elektrycznym 40 cal/cm².</p>'
            .'<img src="https://sklep.example.pl/media/catalog/product/z300.jpg"></div></div>'
            .'<a href="/pliki/karta-katalogowa-z300.pdf">Karta katalogowa</a></div></div></div>'
            .'<h2>Zobacz także</h2><ul><li>Rękawice W400 kwasoodporne EN 374-1 typ A</li></ul>'
            .'<div><p>Kategoria III, EN 407 w wersji W500.</p></div>'
            .'<h2>Opis dodatkowy</h2><p>Rękawice X100 pakowane po 12 par w woreczku foliowym, rozmiary 7–11.</p>'
            .'<section class="cross-sell"><h2>Ähnliche Produkte</h2><p>Handschuh V600 mit Schnittschutz F.</p></section>'
            .'</main></body></html>';

        $page = $this->fetchOneHtml($url, $html, new Product(['sku' => 'X100', 'name' => 'Rękawice X100', 'manufacturer' => 'Example']));

        $text = $page['text'];
        $this->assertStringContainsString('EN 388:2016 4121X', $text);
        $this->assertStringContainsString('pakowane po 12 par', $text, 'sekcja za następnym nagłówkiem h2 zostaje');
        $this->assertStringContainsString('Rękawice nitrylowe X100 do prac montażowych.', $text, 'JSON-LD głównego wyrobu');
        foreach (['1000 V', '40 cal', 'EN 374', 'EN 407', 'V600', 'Y200', 'EN 60903', 'klasa 00'] as $foreign) {
            $this->assertStringNotContainsString($foreign, $text);
        }
        $this->assertSame([], array_values(array_filter($page['image_urls'], static fn (string $u): bool => str_contains($u, 'z300'))));
        $this->assertContains('https://sklep.example.pl/pliki/deklaracja-zgodnosci-x100.pdf', $page['document_urls']);
        $this->assertNotContains('https://sklep.example.pl/pliki/karta-katalogowa-z300.pdf', $page['document_urls']);
    }

    /**
     * Nagłówek polecanych na początku kontenera, który obejmuje też opis wyrobu pod innym <h2> (<h1> to logo
     * w nagłówku strony) — znika tylko blok polecanych, nie cały kontener. „Polecamy do …” to zdanie opisu.
     */
    public function test_heading_cut_never_takes_the_section_with_the_product_description(): void
    {
        $html = '<html><body><header><h1>Sklep BHP</h1></header><div id="content">'
            .'<h2>Polecane produkty</h2><div class="grid"><p>Rękawice Q1 elektroizolacyjne do 1000 V.</p></div>'
            .'<h2>Rękawice X100</h2><p>Rękawice nitrylowe X100 zgodne z EN 388:2016 4121X.</p>'
            .'<h3>Polecamy do prac montażowych</h3><p>Montaż drobnych elementów, magazyn, logistyka.</p>'
            .'</div></body></html>';

        $text = app(ProductPageFetcher::class)->mainTextFromHtml($html);

        $this->assertStringNotContainsString('1000 V', $text);
        $this->assertStringContainsString('EN 388:2016 4121X', $text);
        $this->assertStringContainsString('Montaż drobnych elementów', $text);
    }

    /**
     * „<” przed cyfrą albo spacją to tekst: strip_tags (też lokalnie) i libxml 2.9.10 z produkcji połykały go do
     * najbliższego „>” — ucięte opisy obuwia UVEX i sklejone tabele laserowe (08.10.2026). Przed parsowaniem strona
     * idzie przez HtmlBareLessThan::escape; prawdziwe znaczniki zostają.
     */
    public function test_bare_less_than_is_text_not_a_tag(): void
    {
        $html = '<html><body><h1>Półbuty uvex 2 S3</h1><div class="product-description">'
            .'<p>Rezystancja skrośna <35 megaomów, zakres 3950 - <4700 nm, napięcie < 1000 V, pozostałości <0,5%.</p>'
            .'<p>Podnosek kompozytowy, podeszwa PUR, norma EN ISO 20345:2022.</p></div>'
            .'<span itemprop="sku">6522239</span></body></html>';

        $escaped = HtmlBareLessThan::escape($html);
        $this->assertStringContainsString('skrośna &lt;35 megaomów', $escaped);
        $this->assertStringContainsString('3950 - &lt;4700 nm', $escaped);
        $this->assertStringContainsString('napięcie &lt; 1000 V', $escaped);
        $this->assertStringContainsString('&lt;0,5%', $escaped);
        foreach (['<html>', '<h1>', '</h1>', '<div class="product-description">', '<p>', '</p>', '<span itemprop="sku">'] as $tag) {
            $this->assertStringContainsString($tag, $escaped, 'prawdziwy znacznik zostaje');
        }

        $fetcher = app(ProductPageFetcher::class);
        $text = $fetcher->mainTextFromHtml($html);
        $this->assertStringContainsString('Rezystancja skrośna <35 megaomów, zakres 3950 - <4700 nm, napięcie < 1000 V, pozostałości <0,5%.', $text);
        $this->assertStringContainsString('norma EN ISO 20345:2022', $text);
        $this->assertSame(['Półbuty uvex 2 S3'], array_values(array_unique(array_filter($fetcher->pageTitles($html)))));
        $this->assertContains(['type' => 'sku', 'value' => '6522239'], $fetcher->markupIdentifiers($html));
    }

    /** Strona za zaporą przychodzi markdownem z czytnika — blok „Customers also bought” odpada tak samo. */
    public function test_reader_markdown_drops_related_sections(): void
    {
        $url = 'https://www.gloves.co.uk/hexarmor-chrome-slt-4062-arc-flash-gloves-with-extended-cuffs.html';
        Http::fake([
            'https://r.jina.ai/*' => Http::response(
                "Title: HexArmor Chrome SLT 4062\n\nMarkdown Content:\n# HexArmor Chrome SLT 4062 Arc Flash Gloves\n\n"
                ."Pair of electrical arc resistant safety gloves with ISO Blade Cut Level E protection, HRC 2.\n\n"
                ."## Product Detail\n\nHexArmor Chrome SLT 4062 leather arc flash gloves for utility work, EN 388 2X23E.\n\n"
                ."Customers also bought\n\n[Ansell ActivArmr RIG0014Y Class 00 Electrical Gloves](https://www.gloves.co.uk/ansell-rig0014y.html)\n\n"
                ."Protects against up to 500V AC and 750V DC\n\n"
                ."## Related items\n\n### Delta Plus VV914 Arc Flash Gloves\n\nArc flash rating of 50 cal/cm² on the palm\n\n"
                ."## Delivery\n\nFree UK delivery on orders over £40 for HexArmor 4062.",
                200
            ),
            '*' => Http::response('Access Denied', 403),
        ]);
        $product = new Product(['sku' => 'HA4062', 'name' => 'Rękawice HexArmor Chrome SLT 4062', 'manufacturer' => 'HexArmor']);

        $fetched = app(ProductPageFetcher::class)->fetch([['url' => $url, 'title' => '', 'snippet' => '']], 'HA4062', 1, [], $product);

        $this->assertCount(1, $fetched['pages']);
        $text = $fetched['pages'][0]['text'];
        $this->assertStringContainsString('EN 388 2X23E', $text);
        $this->assertStringContainsString('Free UK delivery', $text, 'sekcja po bloku polecanych zostaje');
        foreach (['500V', 'Class 00', '50 cal', 'VV914'] as $foreign) {
            $this->assertStringNotContainsString($foreign, $text);
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function fetchOne(string $url, string $fixture, Product $product): array
    {
        return $this->fetchOneHtml($url, $this->fixture($fixture), $product);
    }

    /**
     * @return array<string, mixed>
     */
    private function fetchOneHtml(string $url, string $html, Product $product): array
    {
        Http::fake([
            $url => Http::response($html, 200, ['Content-Type' => 'text/html; charset=UTF-8']),
            '*' => Http::response('', 404),
        ]);

        $fetched = app(ProductPageFetcher::class)->fetch(
            [['url' => $url, 'title' => '', 'snippet' => '']],
            (string) $product->sku,
            1,
            [],
            $product
        );

        $this->assertCount(1, $fetched['pages'], 'strona potwierdzona jako karta wyrobu: '.json_encode($fetched['rejected']));

        return $fetched['pages'][0];
    }

    private function fixture(string $name): string
    {
        return (string) file_get_contents(base_path('tests/Fixtures/pages/'.$name));
    }
}
