<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Services\Enrichment\ProductPageFetcher;
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
}
