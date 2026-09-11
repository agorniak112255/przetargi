<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Models\Product;
use App\Services\Enrichment\ProductImageDownloader;
use App\Services\Enrichment\ProductPageFetcher;
use Illuminate\Support\Facades\Http;
use ReflectionClass;
use Tests\TestCase;

final class ProductPageTextExtractionTest extends TestCase
{
    public function test_strips_shop_chrome_and_keeps_product_facts(): void
    {
        $html = <<<'HTML'
<html><body>
<nav>Logowanie Rejestracja Obserwowane (0) Do koszyka</nav>
<div class="breadcrumb">Jesteś tutaj: Strona główna / Trzewiki</div>
<div class="product-description">
Trzewiki ochronne GLOSS UP 2 L WINTER S3 SRC. Obuwie ochronne z podnoskiem stalowym, podeszwa SRC, norma EN ISO 20345.
Producent: Demar. Przeznaczone do pracy w warunkach zimowych.
</div>
<footer>Regulamin Polityka prywatności Odstąpienie od umowy Łatwy zwrot towaru 14 dni</footer>
</body></html>
HTML;

        $fetcher = new ProductPageFetcher;
        $ref = new ReflectionClass($fetcher);
        $method = $ref->getMethod('extractProductPageText');
        $method->setAccessible(true);
        $text = (string) $method->invoke($fetcher, $html, 'gloss up 2 l winter s3 src');

        $this->assertStringContainsString('GLOSS UP', $text);
        $this->assertStringContainsString('S3', $text);
        $this->assertStringNotContainsString('Logowanie', $text);
        $this->assertStringNotContainsString('Do koszyka', $text);
        $this->assertStringNotContainsString('Odstąpienie od umowy', $text);
    }

    public function test_drops_company_imprint_footer(): void
    {
        $html = <<<'HTML'
<html><body>
<p>INFIELD Safety GmbH Nordstraße 10a 42719 Solingen Telefon: +49 212 23234 0 Telefax: +49 212 23234 99 So finden Sie uns: mit Google-Maps</p>
</body></html>
HTML;
        $fetcher = new ProductPageFetcher;
        $ref = new ReflectionClass($fetcher);
        $method = $ref->getMethod('extractProductPageText');
        $method->setAccessible(true);
        $text = (string) $method->invoke($fetcher, $html, 'T5163000');

        $this->assertTrue(ProductPageFetcher::looksLikeCompanyImprint(
            'INFIELD Safety GmbH Nordstraße 10a Telefon: +49 212 23234 0'
        ));
        $this->assertStringNotContainsString('Nordstraße', $text);
        $this->assertStringNotContainsString('Telefax', $text);
    }

    public function test_keeps_full_shop_description_and_drops_zobacz_teaser(): void
    {
        $html = <<<'HTML'
<html><head>
<meta name="description" content="Cena netto: 13,33 zł/szt. - Filtry 3M serii 2000 przeznaczone są do skompletowania z półmaskami 3M serii 6000, 6500 i serii 7500 oraz z maską pełnotwarzową 3M serii 6000. Zgodnie z normą EN143 filtr klasy P2 posiada skuteczność filtracji 94% i przeznaczony jest do ochrony przed: (Zobacz klasy ..."/>
</head><body>
<section class="data item content" id="description">
<div class="description-main">
<p>Filtry 3M serii 2000 przeznaczone są do skompletowania z półmaskami 3M serii 6000, 6500 i serii 7500 oraz z maską pełnotwarzową 3M serii 6000.</p>
<p>Zgodnie z normą EN143 filtr klasy P2 posiada skuteczność filtracji 94% i przeznaczony jest do ochrony przed: (<a href="/klasyfikacja.xml">Zobacz klasyfikację filtrów i pochłaniaczy</a>)</p>
<ul><li>cząstkami stałymi i ciekłymi o niskiej i średniej toksyczności dla których NDS≥0,05mg/m3 — w połączeniu z półmaską do 50xNDS</li></ul>
<p>Mocowane do części twarzowej za pomocą złącza bagnetowego.</p>
</div>
</section>
</body></html>
HTML;

        $fetcher = new ProductPageFetcher;
        $ref = new ReflectionClass($fetcher);
        $method = $ref->getMethod('extractProductPageText');
        $method->setAccessible(true);
        $text = (string) $method->invoke($fetcher, $html, '3M-2125');

        $this->assertStringContainsString('cząstkami stałymi', $text);
        $this->assertStringContainsString('50xNDS', $text);
        $this->assertStringContainsString('złącza bagnetowego', $text);
        $this->assertStringNotContainsString('Zobacz klasy', $text);
        $this->assertStringNotContainsString('Cena netto', $text);
    }

    public function test_extracts_certificate_pdf_by_link_label_without_sku_in_url(): void
    {
        $html = <<<'HTML'
<html><body>
<a href="/files/uvex-glove-doc.pdf">Deklaracja zgodności UE</a>
<a href="/files/random-brochure.pdf">Broszura marketingowa</a>
</body></html>
HTML;

        $fetcher = new ProductPageFetcher;
        $ref = new ReflectionClass($fetcher);
        $method = $ref->getMethod('extractDocumentUrls');
        $method->setAccessible(true);
        /** @var list<string> $docs */
        $docs = $method->invoke($fetcher, $html, 'https://shop.example.com/produkt/c300', '60549', false);

        $this->assertCount(1, $docs);
        $this->assertStringContainsString('uvex-glove-doc.pdf', $docs[0]);
    }

    public function test_extracts_prestashop_og_image_for_brand_model_sku(): void
    {
        $html = <<<'HTML'
<html><head>
<meta property="og:image" content="https://bogarobhp.pl/34818-large_default/kurtka-przeciwdeszczowa-pros-101s-34-aj-group-niebieska.jpg"/>
</head><body>
<img src="https://bogarobhp.pl/34818-medium_default/kurtka-przeciwdeszczowa-pros-101s-34-aj-group-niebieska.jpg"/>
<img src="https://bogarobhp.pl/img/bogaro-logo-1640085971.jpg" class="logo"/>
</body></html>
HTML;

        $fetcher = new ProductPageFetcher;
        $ref = new ReflectionClass($fetcher);
        $pageMethod = $ref->getMethod('pageMentionsSku');
        $pageMethod->setAccessible(true);
        $imgMethod = $ref->getMethod('extractImageUrls');
        $imgMethod->setAccessible(true);

        $pageUrl = 'https://bogarobhp.pl/kurtki-przeciwdeszczowe-robocze/kurtka-przeciwdeszczowa-pros-101s-34-aj-group-niebieska';
        $this->assertTrue($pageMethod->invoke(
            $fetcher,
            $pageUrl,
            'Kurtka przeciwdeszczowa PROS 101/S',
            'Kurtka PROS 101/S',
            'pros-101-s1-max'
        ));

        /** @var list<string> $imgs */
        $imgs = $imgMethod->invoke($fetcher, $html, $pageUrl, 'pros-101-s1-max');
        $this->assertNotEmpty($imgs);
        $this->assertTrue(collect($imgs)->contains(
            fn (string $u): bool => str_contains($u, '34818') && str_contains($u, '.jpg')
        ));
        $this->assertFalse(collect($imgs)->contains(fn (string $u): bool => str_contains($u, 'logo')));
    }

    public function test_extracts_urgent_product_image_from_javascript_gallery_attributes(): void
    {
        $html = <<<'HTML'
<html><body>
<ul id="lightslider">
    <li
        data-big="/file/show/file/5ed0cac81fb5d/type/big/filename/1005_EU_1200.jpg"
        data-full="/file/show/file/5ed0cac81fb5d/filename/1005_EU_1200.jpg"
    ></li>
</ul>
</body></html>
HTML;

        $fetcher = new ProductPageFetcher;
        $ref = new ReflectionClass($fetcher);
        $method = $ref->getMethod('extractImageUrls');
        $method->setAccessible(true);

        /** @var list<string> $images */
        $images = $method->invoke(
            $fetcher,
            $html,
            'https://urgent.pl/product/show/productid/439',
            'urgent-1005'
        );

        $this->assertSame(
            'https://urgent.pl/file/show/file/5ed0cac81fb5d/type/big/filename/1005_EU_1200.jpg',
            $images[0] ?? null
        );
    }

    public function test_marks_structured_product_image_as_trusted_for_matching_page(): void
    {
        $pageUrl = 'https://shop.example.com/product/ansell/wh25t-00122-04';
        $imageUrl = 'https://res.cloudinary.com/rsc/image/upload/w_700/Y0428245-01.jpg';
        Http::fake([
            $pageUrl => Http::response(
                '<html><body>Ansell WH25T-00122-04 AlphaTec 2500 Plus'
                .'<script type="application/ld+json">'
                .'{"@type":"Product","mpn":"WH25T-00122-04","image":["'.$imageUrl.'"]}'
                .'</script><p>'.str_repeat('Opis produktu ochronnego. ', 50).'</p></body></html>',
                200,
                ['Content-Type' => 'text/html']
            ),
        ]);

        $result = (new ProductPageFetcher)->fetch([[
            'url' => $pageUrl,
            'title' => 'Ansell WH25T-00122-04',
            'snippet' => 'AlphaTec 2500 Plus',
        ]], 'WH25T-00122-04', 1);

        $this->assertContains($imageUrl, $result['image_urls']);
        $this->assertSame([$imageUrl], $result['trusted_image_urls']);
    }

    public function test_collects_images_from_page_without_our_internal_sku(): void
    {
        $pageUrl = 'https://www.supon.rzeszow.pl/rekawice/4946-rekawice-termiczne-rostaing-heatresist.html';
        $imageUrl = 'https://www.supon.rzeszow.pl/4946-large_default/rekawice-termiczne-rostaing-heatresist.jpg';
        Http::fake([
            $pageUrl => Http::response(
                '<html><body><h1>Rękawice termiczne Rostaing HEATRESIST</h1>'
                .'<img src="'.$imageUrl.'">'
                .'<p>'.str_repeat('Rękawice odporne na temperaturę 350 stopni. ', 40).'</p>'
                .'</body></html>',
                200,
                ['Content-Type' => 'text/html']
            ),
        ]);

        $result = (new ProductPageFetcher)->fetch([[
            'url' => $pageUrl,
            'title' => 'Rękawice termiczne Rostaing',
            'snippet' => 'HEATRESIST',
        ]], 'HEATRESIST-GAT11', 1);

        // kod magazynowy nie występuje na karcie, ale zdjęcie i tak musi trafić do kandydatów
        $this->assertContains($imageUrl, $result['image_urls']);
        $this->assertSame([], $result['trusted_image_urls']);
    }

    public function test_reads_card_through_reader_when_shop_waf_returns_403(): void
    {
        $pageUrl = 'https://www.gloves.co.uk/rostaing-carpro-gloves.html';
        // reader musi być pierwszy: atrapy Laravela dopasowują z gwiazdką z przodu,
        // więc wzorzec sklepu złapałby też adres „r.jina.ai/<url sklepu>”
        Http::fake([
            'https://r.jina.ai/*' => Http::response(
                "Title: Rostaing CARPRO\n\nMarkdown Content:\n"
                .str_repeat('Rękawice Rostaing CARPRO do prac ogrodowych. ', 20),
                200
            ),
            $pageUrl => Http::response('Access denied', 403),
        ]);

        $result = (new ProductPageFetcher)->fetch([[
            'url' => $pageUrl,
            'title' => 'Rostaing CARPRO',
            'snippet' => '',
        ]], 'CARPRO/IT10', 1);

        $this->assertCount(1, $result['pages']);
        $this->assertSame($pageUrl, $result['pages'][0]['url']);
        $this->assertStringContainsString('CARPRO', $result['pages'][0]['text']);
    }

    public function test_reader_drops_shop_navigation_links(): void
    {
        $pageUrl = 'https://www.gloves.co.uk/rostaing-ripdexg-gloves.html';
        Http::fake([
            'https://r.jina.ai/*' => Http::response(
                "Title: Rostaing RIPDEXG\n\nMarkdown Content:\n"
                ."[Popular Styles](https://www.gloves.co.uk/rostaing-ripdexg-gloves.html#)\n"
                ."*   [Cut Resistant Gloves](https://www.gloves.co.uk/cut-resistant-gloves-oa.html)\n"
                ."*   [Thermal Gloves](https://www.gloves.co.uk/thermal-gloves.html)\n\n"
                .str_repeat('Rękawice Rostaing RIPDEXG ze skóry bydlęcej. ', 10),
                200
            ),
            $pageUrl => Http::response('Access denied', 403),
        ]);

        $result = (new ProductPageFetcher)->fetch([[
            'url' => $pageUrl,
            'title' => 'Rostaing RIPDEXG',
            'snippet' => '',
        ]], 'RIPDEXG-IT10', 1);

        $text = $result['pages'][0]['text'];
        $this->assertStringNotContainsString('Popular Styles', $text);
        $this->assertStringNotContainsString('cut-resistant-gloves-oa', $text);
        $this->assertStringContainsString('RIPDEXG ze skóry', $text);
    }

    public function test_skips_dead_first_link_and_keeps_best_of_working_pages(): void
    {
        $dead = 'https://roboczystyl.pl/product/robfm';
        $thin = 'https://shop-b.example/robfm';
        $rich = 'https://shop-c.example/produkt/robfm-js-gloves';
        $next = 'https://shop-d.example/product/robfm-mid';
        Http::fake([
            $dead => Http::response('gone', 404),
            $thin => Http::response(
                '<html><body><h1>ROBFM</h1><p>Krótki opis ROBFM. '
                .str_repeat('Strona sklepu BHP. ', 50)
                .'</p></body></html>',
                200,
                ['Content-Type' => 'text/html']
            ),
            $rich => Http::response(
                '<html><body><h1>Rękawice ROBFM JS Gloves</h1><p>'
                .str_repeat('Rękawice ochronne bawełniane ROBFM do 250C. ', 40)
                .'</p></body></html>',
                200,
                ['Content-Type' => 'text/html']
            ),
            $next => Http::response(
                '<html><body><h1>ROBFM</h1><p>'
                .str_repeat('Rękawice ROBFM bawełniane do pracy. ', 30)
                .'</p></body></html>',
                200,
                ['Content-Type' => 'text/html']
            ),
        ]);

        $result = (new ProductPageFetcher)->fetch([
            ['url' => $dead, 'title' => 'ROBFM martwy', 'snippet' => 'snippet martwy ROBFM'],
            ['url' => $thin, 'title' => 'ROBFM krótki', 'snippet' => 'ROBFM'],
            ['url' => $rich, 'title' => 'ROBFM JS Gloves', 'snippet' => 'JS Gloves ROBFM'],
            ['url' => $next, 'title' => 'ROBFM extra', 'snippet' => 'ROBFM'],
        ], 'ROBFM', 3);

        $urls = array_column($result['pages'], 'url');
        $this->assertSame([$rich, $next, $thin], $urls);
        $this->assertStringContainsString('JS Gloves', $result['pages'][0]['text']);
        $this->assertNotContains($dead, $urls);
    }

    public function test_second_fetch_of_same_url_uses_html_cache(): void
    {
        $pageUrl = 'https://shop.example.com/produkt/uvex-60549';
        Http::fake([
            $pageUrl => Http::response(
                '<html><body><h1>Uvex 60549</h1><p>'
                .str_repeat('Rękawice ochronne Uvex 60549 EN 388. ', 40)
                .'</p></body></html>',
                200,
                ['Content-Type' => 'text/html']
            ),
        ]);

        $fetcher = new ProductPageFetcher;
        $row = ['url' => $pageUrl, 'title' => 'Uvex 60549', 'snippet' => '60549'];
        $first = $fetcher->fetch([$row], '60549', 1);
        $second = $fetcher->fetch([$row], '60549', 1);

        $this->assertSame($first['pages'][0]['text'] ?? '', $second['pages'][0]['text'] ?? '');
        Http::assertSentCount(1);
    }

    public function test_force_bypass_reads_html_again(): void
    {
        $pageUrl = 'https://shop.example.com/produkt/uvex-60550';
        Http::fake([
            $pageUrl => Http::response(
                '<html><body><h1>Uvex 60550</h1><p>'
                .str_repeat('Rękawice ochronne Uvex 60550 EN 388. ', 40)
                .'</p></body></html>',
                200,
                ['Content-Type' => 'text/html']
            ),
        ]);

        $fetcher = new ProductPageFetcher;
        $row = ['url' => $pageUrl, 'title' => 'Uvex 60550', 'snippet' => '60550'];
        $fetcher->fetch([$row], '60550', 1);
        $fetcher->bypassCache(true)->fetch([$row], '60550', 1);

        Http::assertSentCount(2);
    }

    public function test_manufacturer_page_keeps_product_pdfs_and_skips_csr(): void
    {
        $html = <<<'HTML'
<html><body>
<a href="https://cdn.example.com/DATASHEET/60549_PDB_EN.pdf">Data sheet</a>
<a href="/files/product-info.pdf">Informacje o produkcie</a>
<a href="/files/sustainability-report-2024.pdf">Sustainability report</a>
</body></html>
HTML;

        $fetcher = new ProductPageFetcher;
        $ref = new ReflectionClass($fetcher);
        $method = $ref->getMethod('extractDocumentUrls');
        $method->setAccessible(true);
        /** @var list<string> $docs */
        $docs = $method->invoke(
            $fetcher,
            $html,
            'https://www.uvex-safety.com/en/products/glove-60549/',
            '60549',
            true
        );

        $this->assertTrue(collect($docs)->contains(fn (string $u): bool => str_contains($u, '60549_PDB_EN.pdf')));
        $this->assertTrue(collect($docs)->contains(fn (string $u): bool => str_contains($u, 'product-info.pdf')));
        $this->assertFalse(collect($docs)->contains(fn (string $u): bool => str_contains($u, 'sustainability')));
    }

    public function test_image_search_hit_is_not_read_as_product_page(): void
    {
        $imageUrl = 'https://balticbhp.pl/33141-large_default/buty-robocze-jalas-zenit-1718.jpg';
        $jpeg = "\xFF\xD8\xFF\xE0\x00\x10JFIF".str_repeat("\x00", 40);
        Http::fake([
            $imageUrl => Http::response($jpeg, 200, ['Content-Type' => 'image/jpeg']),
        ]);

        $result = (new ProductPageFetcher)->fetch([[
            'url' => $imageUrl,
            'title' => 'buty robocze jalas zenit',
            'snippet' => '',
        ]], '7168', 1);

        $this->assertSame([], $result['pages']);
        $this->assertContains($imageUrl, $result['image_urls']);
        $this->assertTrue(ProductPageFetcher::looksLikeBinaryMedia($jpeg));
        $this->assertTrue(ProductPageFetcher::looksLikeBinaryMedia("????\x10JFIF creator: gd-jpeg"));
        $this->assertFalse(ProductPageFetcher::looksLikeBinaryMedia('Obuwie ochronne Jalas 7168 Zenit Evo S3'));
    }

    public function test_trusts_3mpolska_og_image_from_name_attribute(): void
    {
        $pageUrl = 'https://www.3mpolska.pl/3M/pl_PL/p/d/b40069952/';
        $og = 'https://multimedia.3m.com/mws/media/1421372J/3m-pps-kit.jpg';
        $html = '<html><head><meta name="og:image" content="'.$og.'"></head><body>'
            .'<h1>Kubek wewnętrzny rPPS 3M PPS 16743</h1>'
            .'<p>'.str_repeat('Kubek wewnętrzny 3M PPS 16743 do natrysku farby. ', 40)
            .'</p></body></html>';
        Http::fake([
            $pageUrl => Http::response($html, 200, ['Content-Type' => 'text/html']),
        ]);

        $product = new Product([
            'sku' => '16743-KT',
            'name' => 'Kubek wewnętrzny rPPS 3M™ PPS™, 650 ml, 200 µm, 16743',
            'manufacturer' => '3M',
        ]);
        $result = (new ProductPageFetcher)->fetch([[
            'url' => $pageUrl,
            'title' => 'Kubek wewnętrzny rPPS 3M PPS 16743',
            'snippet' => '',
        ]], (string) $product->sku, 1, [], $product);

        $this->assertContains($og, $result['trusted_image_urls']);
        $this->assertContains($og, $result['image_urls']);
    }

    public function test_reads_3mpolska_images_through_reader_on_timeout(): void
    {
        $pageUrl = 'https://www.3mpolska.pl/3M/pl_PL/p/d/b40069952/';
        $imageUrl = 'https://multimedia.3m.com/mws/media/1421372J/3m-pps-kit.jpg?width=506';
        Http::fake(function (\Illuminate\Http\Client\Request $request) use ($imageUrl) {
            if (str_contains($request->url(), 'r.jina.ai')) {
                return Http::response(
                    "Title: Kubek wewnętrzny rPPS 3M PPS 16743\n\nMarkdown Content:\n"
                    .'[![kit]('.$imageUrl.')]('.$imageUrl.")\n\n"
                    .str_repeat('Kubek wewnętrzny 3M PPS 16743 do natrysku farby. ', 20),
                    200
                );
            }
            throw new \Illuminate\Http\Client\ConnectionException('cURL error 28');
        });

        $product = new Product([
            'sku' => '16743-KT',
            'name' => 'Kubek wewnętrzny rPPS 3M™ PPS™, 650 ml, 200 µm, 16743',
            'manufacturer' => '3M',
        ]);
        $result = (new ProductPageFetcher)->fetch([[
            'url' => $pageUrl,
            'title' => 'Kubek wewnętrzny rPPS 3M PPS 16743',
            'snippet' => '',
        ]], (string) $product->sku, 1, [], $product);

        $this->assertCount(1, $result['pages']);
        $this->assertContains($imageUrl, $result['image_urls']);
        $this->assertContains($imageUrl, $result['trusted_image_urls']);
    }

    public function test_trusts_og_image_when_page_has_model_not_warehouse_sku(): void
    {
        $pageUrl = 'https://www.professionalbhp.com/pl/p/Kurtka-meska-odblaskowa-HSV-3-W-1-URG/1136';
        $og = 'https://www.professionalbhp.com/userdata/public/gfx/1136.jpg';
        $html = '<html><head><meta property="og:image" content="'.$og.'"></head><body>'
            .'<h1>Kurtka męska odblaskowa HSV 3 W 1 URG</h1>'
            .'<p>KURTKA MĘSKA ODBLASKOWA HSV 3W1 URG. Wodoodporna, odpinane rękawy. '
            .str_repeat('Opis karty produktu BHP. ', 40)
            .'</p></body></html>';
        Http::fake([
            $pageUrl => Http::response($html, 200, ['Content-Type' => 'text/html']),
        ]);

        $product = new Product([
            'sku' => 'PROS-KURTKA-MESKA-HSV-3W1',
            'name' => 'KURTKA MĘSKA HSV KRÓTKA 3 W 1',
            'manufacturer' => 'URGENT',
        ]);
        $result = (new ProductPageFetcher)->fetch([[
            'url' => $pageUrl,
            'title' => 'Kurtka męska odblaskowa HSV 3 W 1 URG',
            'snippet' => '',
        ]], (string) $product->sku, 1, [], $product);

        $this->assertContains($og, $result['trusted_image_urls']);
        $this->assertContains($og, $result['image_urls']);
    }

    public function test_drops_related_and_foreign_brand_images_from_matching_card(): void
    {
        $pageUrl = 'https://shop.example.com/ansell-kleenguard-g10-flex-54335.html';
        $main = 'https://shop.example.com/media/kleenguard-g10-flex-54335.jpg';
        $related = 'https://shop.example.com/user/products/PORTWEST-A620-PU-COATED-GLOVES.jpg';
        $html = '<html><head><meta property="og:image" content="'.$main.'"></head><body>'
            .'<h1>Ansell KleenGuard G10 Flex 54335</h1>'
            .'<img src="'.$main.'">'
            .'<p>'.str_repeat('KleenGuard G10 Flex Blue Nitrile Gloves 54335 disposable. ', 40).'</p>'
            .'<h2>Customers also bought</h2>'
            .'<img src="'.$related.'">'
            .'<p>Portwest A620 PU coated cut resistant gloves.</p>'
            .'</body></html>';
        Http::fake([
            $pageUrl => Http::response($html, 200, ['Content-Type' => 'text/html']),
        ]);

        $product = new Product([
            'sku' => '54335',
            'name' => 'KG G10 Flex Ntrl Glv Blue XL',
            'manufacturer' => 'Ansell',
        ]);
        $result = (new ProductPageFetcher)->fetch([[
            'url' => $pageUrl,
            'title' => 'Ansell KleenGuard G10 Flex 54335',
            'snippet' => '',
        ]], (string) $product->sku, 1, [], $product);

        $this->assertContains($main, $result['image_urls']);
        $this->assertNotContains($related, $result['image_urls']);
        $this->assertNotContains($related, $result['trusted_image_urls']);
    }

    public function test_skips_images_from_other_ansell_flex_card(): void
    {
        $pageUrl = 'https://www.gloves.co.uk/ansell-easy-flex-47-200-palm-coated-general-handling-gloves.html';
        $main = 'https://www.gloves.co.uk/user/products/ansell-easy-flex-47-200.jpg';
        $related = 'https://www.gloves.co.uk/user/products/PORTWEST-A620-PU-COATED-GLOVES.jpg';
        $html = '<html><body>'
            .'<h1>Ansell ActivArmr 47-200 Easy Flex</h1>'
            .'<img src="'.$main.'">'
            .'<p>'.str_repeat('Ansell Easy Flex 47-200 palm coated general handling gloves. ', 40).'</p>'
            .'<h2>Customers also bought</h2>'
            .'<img src="'.$related.'">'
            .'</body></html>';
        Http::fake([
            $pageUrl => Http::response($html, 200, ['Content-Type' => 'text/html']),
        ]);

        $product = new Product([
            'sku' => '54335',
            'name' => 'KG G10 Flex Ntrl Glv Blue XL',
            'manufacturer' => 'Ansell',
        ]);
        $result = (new ProductPageFetcher)->fetch([[
            'url' => $pageUrl,
            'title' => 'Ansell Easy Flex 47-200',
            'snippet' => '',
        ]], (string) $product->sku, 1, [], $product);

        $this->assertSame([], $result['image_urls']);
        $this->assertSame([], $result['trusted_image_urls']);
    }

    public function test_picks_canis_gallery_image_over_menu_tiles(): void
    {
        $pageUrl = 'https://www.canis.cz/pl/maski-spawalnicze/naglowie-do-przylbicy_p13074';
        $productImage = 'https://www.canis.cz/imgserver/eshop/CANIS/19/2000000326/13074_2216-00.JPG';
        $html = <<<HTML
<html><head>
<meta property="og:image" content="/template/eshop5/special/image/logo-OpenGraph.png">
</head><body>
<img src="https://www.canis.cz/imgserver/eshop/CANIS/781/2000000329/88493506174645_PRAC_ODEVY.PNG?w=95" alt="menu">
<div class="gallery_js">
<a href="{$productImage}?w=1920" class="gallery_item_js">
<img src="data:image/gif;base64,R0lGODlhAQABAIAAAP///wAAACH5BAEAAAAALAAAAAABAAEAAAICRAEAOw=="
 data-src="{$productImage}?w=800"
 class="js_lazy_img"
 itemprop="image"
 content="{$productImage}"
 alt="13074_6_2216-00">
</a>
</div>
<p>Nagłowie do przyłbicy. Kod 4200-003-000-00. Przeznaczone do przyłbicy 2215-00.</p>
</body></html>
HTML;

        $fetcher = new ProductPageFetcher;
        $ref = new ReflectionClass($fetcher);
        $method = $ref->getMethod('extractImageUrls');
        $method->setAccessible(true);

        /** @var list<string> $images */
        $images = $method->invoke($fetcher, $html, $pageUrl, '4200-003-000-00');

        $this->assertNotEmpty($images);
        $this->assertTrue(collect($images)->contains(
            static fn (string $u): bool => str_contains(mb_strtolower($u), '13074_2216-00.jpg')
        ));
        $this->assertFalse(collect($images)->contains(
            static fn (string $u): bool => str_contains($u, 'PRAC_ODEVY') || str_contains($u, 'w=95')
        ));
        $this->assertFalse(collect($images)->contains(
            static fn (string $u): bool => str_contains($u, 'logo-OpenGraph')
        ));
    }

    public function test_collapses_magento_thumbnail_cache_to_original_catalog_image(): void
    {
        $pageUrl = 'https://icd.pl/fartuch-wodoochronny-pros-121-bialy.html';
        $original = 'https://icd.pl/media/catalog/product/f/a/fartuch-pros-wodoochronny-121-1.jpg';
        $og = 'https://icd.pl/media/catalog/product/cache/aa69c2be92cae7e9c97cb544ca63fc52/f/a/fartuch-pros-wodoochronny-121-1.jpg';
        $thumb = 'https://icd.pl/media/catalog/product/cache/619fea8990fc50f1f0f0c116cd818ee3/f/a/fartuch-pros-wodoochronny-121-1.jpg';
        $html = '<html><head><meta property="og:image" content="'.$og.'"></head><body>'
            .'<h1>Fartuch wodoochronny przedni z rękawami model 121 - biały</h1>'
            .'<img src="'.$thumb.'">'
            .'<p>'.str_repeat('Fartuch przedni z rękawami model 121 PROS Plavitex EN ISO 13688. ', 40)
            .'</p></body></html>';
        Http::fake([
            $pageUrl => Http::response($html, 200, ['Content-Type' => 'text/html']),
        ]);

        $product = new Product([
            'sku' => '121',
            'name' => 'Fartuch przedni z rękawami 120/100',
            'manufacturer' => 'AJ GROUP',
        ]);
        $result = (new ProductPageFetcher)->fetch([[
            'url' => $pageUrl,
            'title' => 'Fartuch wodoochronny przedni z rękawami model 121 - biały',
            'snippet' => '',
        ]], (string) $product->sku, 1, [], $product);

        $this->assertContains($original, $result['image_urls']);
        $this->assertContains($original, $result['trusted_image_urls']);
        $this->assertFalse(collect($result['image_urls'])->contains(
            static fn (string $u): bool => str_contains($u, '/cache/')
        ));
    }

    public function test_reads_shoper_gallery_original_and_skips_related_thumbs(): void
    {
        $pageUrl = 'https://behapownia.pl/damska-kurtka-przeciwdeszczowa-bemoregreen-903';
        $og = 'https://behapownia.pl/environment/cache/images/productGfx_17565_500_500/Damska-kurtka-przeciwdeszczowa-BEMOREGREEN-903-id-2674.webp';
        $full = 'https://behapownia.pl/environment/cache/images/productGfx_17565_0_0/Damska-kurtka-przeciwdeszczowa-BEMOREGREEN-903-id-2674.webp';
        $original = 'https://behapownia.pl/userdata/public/gfx/17565/Damska-kurtka-przeciwdeszczowa-BEMOREGREEN-903-id-2674.jpg';
        $thumb = '/environment/cache/images/productGfx_17565_120_120/Damska-kurtka-przeciwdeszczowa-BEMOREGREEN-903-id-2674.webp';
        $related = '/environment/cache/images/productGfx_17787_300_300/Polbuty-SAPHIR-S3-SRC-HRO-id-2707.webp?overlay=1';
        $html = <<<HTML
<html><head><meta property="og:image" content="{$og}"></head><body>
<h1>Damska kurtka przeciwdeszczowa BEMOREGREEN 903</h1>
<div class="productimg">
<img class="photo productimg gallery_17565" src="/environment/cache/images/productGfx_17565_500_500/Damska-kurtka-przeciwdeszczowa-BEMOREGREEN-903-id-2674.webp?overlay=1" alt="Damska kurtka przeciwdeszczowa BEMOREGREEN 903">
<a class="gallery js__gallery-anchor-image" href="/userdata/public/gfx/17565/Damska-kurtka-przeciwdeszczowa-BEMOREGREEN-903-id-2674.jpg" title="Damska kurtka przeciwdeszczowa BEMOREGREEN 903">
<img src="{$thumb}" data-img-name="/environment/cache/images/productGfx_17565_500_500/Damska-kurtka-przeciwdeszczowa-BEMOREGREEN-903-id-2674.webp" alt="miniatura">
</a>
</div>
<div class="product-description">
<p>Damska kurtka przeciwdeszczowa BEMOREGREEN 903. Produkt zaprojektowany i wyprodukowany w Polsce.
Kurtka nieprzemakalna, wiatroszczelna, z recyklingowego Plavitexu Eco. Marka AJ Group.</p>
</div>
<img data-src="{$related}" alt="Półbuty SAPHIR">
</body></html>
HTML;
        Http::fake([
            $pageUrl => Http::response($html, 200, ['Content-Type' => 'text/html']),
        ]);

        $product = new Product([
            'sku' => '903',
            'name' => 'Damska kurtka przeciwdeszczowa',
            'manufacturer' => 'AJ GROUP',
        ]);
        $fetcher = new ProductPageFetcher;
        $extract = new ReflectionClass($fetcher);
        $method = $extract->getMethod('extractImageUrls');
        $method->setAccessible(true);
        /** @var list<string> $extracted */
        $extracted = $method->invoke($fetcher, $html, $pageUrl, '903');
        $this->assertContains($original, $extracted);
        $this->assertContains($full, $extracted);
        $this->assertFalse(collect($extracted)->contains(
            static fn (string $u): bool => str_contains($u, '_120_120') || str_contains($u, '_300_300')
        ));

        $result = $fetcher->fetch([[
            'url' => $pageUrl,
            'title' => 'Damska kurtka przeciwdeszczowa BEMOREGREEN 903',
            'snippet' => '',
        ]], (string) $product->sku, 1, [], $product);

        $this->assertContains($original, $result['image_urls']);
        $this->assertContains($original, $result['trusted_image_urls']);
        $this->assertContains($full, $result['trusted_image_urls']);
        $this->assertFalse(collect($result['image_urls'])->contains(
            static fn (string $u): bool => str_contains($u, '_120_120') || str_contains($u, '_300_300')
        ));
    }

    public function test_reads_shoper_jsonld_original_and_skips_seo_logo_and_keeps_fullsize_zero(): void
    {
        $pageUrl = 'https://centrumelektronarzedzi.pl/pl/p/Chodnik-elektroizolacyjny-20-KV-wymiary-1%2C1-x-2-m-Secura/48601';
        $seo = 'https://centrumelektronarzedzi.pl/upload/img/seo/centrumelektronarzedzi-pl.png';
        $og = 'https://centrumelektronarzedzi.pl/environment/cache/images/productGfx_46764_500_500/Chodnik-i-dywanik-elektroizolacyjny.jpg';
        $full = 'https://centrumelektronarzedzi.pl/environment/cache/images/productGfx_46764_0_0/Chodnik-i-dywanik-elektroizolacyjny.webp';
        $original = 'https://centrumelektronarzedzi.pl/userdata/public/gfx/46764/Chodnik-i-dywanik-elektroizolacyjny.jpg';
        $thumb = '/environment/cache/images/productGfx_46764_120_120/Chodnik-i-dywanik-elektroizolacyjny.webp';
        $html = <<<HTML
<html><head>
<meta property="og:image" content="{$seo}">
<meta property="og:image" content="{$og}">
<script type="application/ld+json">{"@id":"/pl/p/Chodnik/48601","image":["https:\\/\\/centrumelektronarzedzi.pl\\/userdata\\/public\\/gfx\\/46764\\/Chodnik-i-dywanik-elektroizolacyjny.jpg"]}</script>
</head><body>
<h1>Chodnik elektroizolacyjny 20 KV (wymiary 1,1 x 2 m) Secura</h1>
<a class="js__open-gallery" href="{$full}">
<img class="product-gallery__main-image" src="/environment/cache/images/productGfx_46764_750_750/Chodnik-i-dywanik-elektroizolacyjny.webp" alt="Chodnik">
</a>
<img src="{$thumb}" alt="miniatura">
<p>Chodniki elektroizolacyjne w kl. 2 są przeznaczone do wykładania podłóg.
Ochrona pracowników przed zagrożeniami elektrycznymi. Marka Secura. Klasa 2.</p>
</body></html>
HTML;
        Http::fake([
            $pageUrl => Http::response($html, 200, ['Content-Type' => 'text/html']),
        ]);

        $product = new Product([
            'sku' => 'CH-20KV',
            'name' => 'Chodnik elektroizolacyjny 20 KV (wymiary 1,1 x 2 m) Secura',
            'manufacturer' => 'SECURA',
            'shop_source_url' => $pageUrl,
        ]);
        $result = (new ProductPageFetcher)->fetch([[
            'url' => $pageUrl,
            'title' => 'Chodnik elektroizolacyjny 20 KV (wymiary 1,1 x 2 m) Secura',
            'snippet' => '',
        ]], (string) $product->sku, 1, [], $product);

        $this->assertContains($original, $result['trusted_image_urls']);
        $this->assertContains($original, $result['image_urls']);
        $this->assertContains($full, $result['image_urls']);
        $this->assertNotContains($seo, $result['image_urls']);
        $this->assertNotContains($seo, $result['trusted_image_urls']);
        $this->assertFalse(collect($result['image_urls'])->contains(
            static fn (string $u): bool => str_contains($u, '_120_120')
        ));
        $this->assertSame(
            'https://centrumelektronarzedzi.pl/environment/cache/images/productGfx_46764_0_0/Chodnik-i-dywanik-elektroizolacyjny.jpg',
            ProductImageDownloader::preferFullSizeUrl($og)
        );
    }

    public function test_fetches_images_from_centrumelektronarzedzi_48607_card(): void
    {
        $pageUrl = 'https://centrumelektronarzedzi.pl/pl/p/Chodnik-elektroizolacyjny-20-KV-wymiary-1%2C1-x-8-m-Secura/48607';
        $original = 'https://centrumelektronarzedzi.pl/userdata/public/gfx/46771/Chodnik-i-dywanik-elektroizolacyjny.jpg';
        $html = '<html><head>'
            .'<meta property="og:image" content="https://centrumelektronarzedzi.pl/upload/img/seo/centrumelektronarzedzi-pl.png">'
            .'<script type="application/ld+json">{"image":["https:\\/\\/centrumelektronarzedzi.pl\\/userdata\\/public\\/gfx\\/46771\\/Chodnik-i-dywanik-elektroizolacyjny.jpg"]}</script>'
            .'</head><body>'
            .'<h1>Chodnik elektroizolacyjny 20 KV (wymiary 1,1 x 8 m) Secura</h1>'
            .'<div class="resetcss"><p>Chodniki elektroizolacyjne w kl. 2 są przeznaczone do wykładania podłóg w celu ochrony pracowników. Marka Secura.</p></div>'
            .'<a href="'.$original.'"><img src="https://centrumelektronarzedzi.pl/environment/cache/images/productGfx_46771_750_750/Chodnik-i-dywanik-elektroizolacyjny.webp" alt="Chodnik"></a>'
            .'</body></html>';
        Http::fake([
            $pageUrl => Http::response($html, 200, ['Content-Type' => 'text/html']),
        ]);
        $product = new Product([
            'sku' => 'CH-20KV-8',
            'name' => 'Chodnik elektroizolacyjny 20 KV (wymiary 1,1 x 8 m) Secura',
            'manufacturer' => 'SECURA',
            'shop_source_url' => $pageUrl,
        ]);
        $result = (new ProductPageFetcher)->bypassCache()->fetch([[
            'url' => $pageUrl,
            'title' => 'Chodnik elektroizolacyjny 20 KV',
            'snippet' => '',
        ]], (string) $product->sku, 1, [], $product);

        $this->assertNotSame([], $result['pages']);
        $this->assertContains($original, $result['trusted_image_urls']);
        $this->assertContains($original, $result['image_urls']);
    }

    public function test_reads_48602_gallery_and_skips_related_jsonld(): void
    {
        $pageUrl = 'https://centrumelektronarzedzi.pl/pl/p/Chodnik-elektroizolacyjny-20-KV-wymiary-1%2C1-x-3-m-Secura/48602';
        $original = 'https://centrumelektronarzedzi.pl/userdata/public/gfx/46766/Chodnik-i-dywanik-elektroizolacyjny.jpg';
        $full = 'https://centrumelektronarzedzi.pl/environment/cache/images/productGfx_46766_0_0/Chodnik-i-dywanik-elektroizolacyjny.webp';
        $kit = 'https://centrumelektronarzedzi.pl/userdata/public/gfx/46294/Zestaw-SECURA-3100-LAK.jpg';
        $mask = 'https://centrumelektronarzedzi.pl/userdata/public/gfx/46287/Polmaska-SECURA-3100.jpg';
        $relatedMat = 'https://centrumelektronarzedzi.pl/userdata/public/gfx/46763/Chodnik-i-dywanik-elektroizolacyjny.jpg';
        $html = <<<HTML
<html><head>
<meta property="og:image" content="https://centrumelektronarzedzi.pl/environment/cache/images/productGfx_46766_500_500/Chodnik-i-dywanik-elektroizolacyjny.jpg">
<meta property="og:image" content="https://centrumelektronarzedzi.pl/upload/img/seo/centrumelektronarzedzi-pl.png">
<script type="application/ld+json">{"@id":"/pl/p/Chodnik/48602","image":["https:\\/\\/centrumelektronarzedzi.pl\\/userdata\\/public\\/gfx\\/46766\\/Chodnik-i-dywanik-elektroizolacyjny.jpg"]}</script>
<script type="application/ld+json">{"@type":"Product","isRelatedTo":[{"@type":"Thing","name":"Zestaw SECURA 3100 LAK","image":"{$kit}"},{"@type":"Thing","name":"Półmaska SECURA 3100","image":"{$mask}"},{"@type":"Thing","name":"Dywanik 0,75 x 0,75","image":"{$relatedMat}"}]}</script>
</head><body>
<h1>Chodnik elektroizolacyjny 20 KV (wymiary 1,1 x 3 m) Secura</h1>
<a class="js__gallery-anchor-image" href="{$full}">
<img class="product-gallery__main-image" src="/environment/cache/images/productGfx_46766_750_750/Chodnik-i-dywanik-elektroizolacyjny.webp" alt="Chodnik">
</a>
<div class="related-products"><img src="/environment/cache/images/productGfx_46294_150_150/Zestaw-SECURA-3100-LAK.jpg" alt="Zestaw"></div>
<p>Chodniki elektroizolacyjne w kl. 2 są przeznaczone do wykładania podłóg. Marka Secura. Klasa 2. Wymiary 1,1 x 3 m.</p>
</body></html>
HTML;
        Http::fake([
            $pageUrl => Http::response($html, 200, ['Content-Type' => 'text/html']),
        ]);
        $product = new Product([
            'sku' => 'T59210033',
            'name' => 'Chodnik elektroizolacyjny 20 kV',
            'manufacturer' => 'SECURA',
            'shop_source_url' => $pageUrl,
        ]);
        $result = (new ProductPageFetcher)->bypassCache()->fetch([[
            'url' => $pageUrl,
            'title' => 'Chodnik elektroizolacyjny 20 KV (wymiary 1,1 x 3 m) Secura',
            'snippet' => '',
        ]], (string) $product->sku, 1, [], $product);

        $this->assertContains($original, $result['trusted_image_urls']);
        $this->assertContains($original, $result['image_urls']);
        $this->assertNotContains($kit, $result['trusted_image_urls']);
        $this->assertNotContains($mask, $result['trusted_image_urls']);
        $this->assertNotContains($relatedMat, $result['trusted_image_urls']);
        $this->assertSame(
            $original,
            ProductImageDownloader::shoperOriginalUrl($full)
        );
    }

    public function test_reads_bpbhp_magento_og_image_as_trusted(): void
    {
        $pageUrl = 'https://bpbhp.pl/rekawice-jednorazowe-mapa-solo-987';
        $cached = 'https://bpbhp.pl/media/catalog/product/cache/5cf0cd67d985c5a2729a2007397294b6/s/o/solo_987_1.jpg';
        $original = 'https://bpbhp.pl/media/catalog/product/s/o/solo_987_1.jpg';
        // poniżej 800 B fetcher uważa odpowiedź za bot-wall i idzie w reader
        $html = '<html><head>'
            .'<meta property="og:image" content="'.$cached.'">'
            .'</head><body>'
            .'<h1>RĘKAWICE JEDNORAZOWE MAPA SOLO 987</h1>'
            .'<img class="gallery-placeholder__image" src="https://bpbhp.pl/media/catalog/product/cache/207e23213cf636ccdef205098cf3c8a3/s/o/solo_987_1.jpg" width="700" height="700">'
            .'<p>'.str_repeat('Rękawice MAPA SOLO 987. Doskonała odporność mechaniczna, idealna do środowiska oleistego. ', 8).'</p>'
            .'</body></html>';
        Http::fake([
            $pageUrl => Http::response($html, 200, ['Content-Type' => 'text/html']),
        ]);
        $product = new Product([
            'sku' => '349870338',
            'name' => 'SOLO 987',
            'manufacturer' => 'MAPA',
            'shop_source_url' => $pageUrl,
        ]);
        $result = (new ProductPageFetcher)->bypassCache()->fetch([[
            'url' => $pageUrl,
            'title' => 'RĘKAWICE JEDNORAZOWE MAPA SOLO 987',
            'snippet' => '',
        ]], (string) $product->sku, 1, [], $product);

        $this->assertContains($original, $result['trusted_image_urls']);
        $this->assertContains($original, $result['image_urls']);
        $this->assertSame($original, ProductImageDownloader::preferFullSizeUrl($cached));
    }

    public function test_reads_shoper_resetcss_description_instead_of_newsletter(): void
    {
        $html = <<<'HTML'
<html><head>
<meta name="description" content="Centrum Elektronarzedzi - elektronarzędzia, Milwaukee, DeWALT, Makita...">
</head><body>
<div class="description newsletter__description mb-xs-2">Podaj swój adres e-mail, jeżeli chcesz otrzymywać informacje o nowościach i promocjach.</div>
<div class="wce-short-description resetcss"><script>var lazyObserver = new IntersectionObserver(function(){});</script>Chodnik 20 KV Secura Szczegóły</div>
<div class="ce-product-page-desc"><style>#wce-tabs { --wce-tab-theme-bg: #fff; }</style>
<div class="resetcss"><p>Chodniki elektroizolacyjne w kl. 2 są przeznaczone do wykładania podłóg w celu ochrony pracowników przed zagrożeniami elektrycznymi przy urządzeniach o napięciu 17000 V. Wymiary 1,1 x 8 m. Marka Secura. Klasa 2.</p></div>
</div>
</body></html>
HTML;
        $fetcher = new ProductPageFetcher;
        $ref = new ReflectionClass($fetcher);
        $method = $ref->getMethod('extractProductPageText');
        $method->setAccessible(true);
        $text = (string) $method->invoke($fetcher, $html, 'CH-20KV-8');

        $this->assertStringContainsString('Chodniki elektroizolacyjne', $text);
        $this->assertStringContainsString('ochrony pracowników', $text);
        $this->assertStringNotContainsString('Podaj swój adres e-mail', $text);
        $this->assertStringNotContainsString('IntersectionObserver', $text);
        $this->assertStringNotContainsString('--wce-', $text);
    }
}
