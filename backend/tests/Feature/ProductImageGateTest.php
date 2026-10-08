<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Product;
use App\Models\ProductImage;
use App\Services\Enrichment\ProductImageDownloader;
use App\Services\Enrichment\ProductPageFetcher;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * Bramka zdjęć po audycie kart Coby (08.10.2026, etap 2b pkt C): strona błędu „404 Not Found nginx” zapisana jako
 * zdjęcie (karty 10799, 10801, 10806 HR Matting), ekran weryfikacji Cloudflare (11089), schematy 172×111 px jako
 * zdjęcie główne (HI010002, HI010003), grafiki reklamowe coba.com (StandUpforHealth-PL.png, Modal_Elephant.png).
 */
final class ProductImageGateTest extends TestCase
{
    use RefreshDatabase;

    private const HTML_AS_IMAGE = 'https://hrmatting.example/media/catalog/product/hr-mat-grey.jpg';

    private const SVG_AS_IMAGE = 'https://hrmatting.example/media/catalog/product/hr-mat-plan.png';

    private const TINY = 'https://www.coba.com/wp-content/uploads/2021/03/HI010002-diagram.png';

    private const GOOD = 'https://www.coba.com/wp-content/uploads/2021/03/HI010002-hygimat.png';

    private const ELEPHANT = 'https://www.coba.com/wp-content/uploads/2024/01/Modal_Elephant.png';

    private const HEALTH = 'https://www.coba.com/wp-content/uploads/2024/01/StandUpforHealth-PL.png';

    private const LOGO = 'https://sklep.example/media/catalog/product/logo.png';

    public function test_html_page_under_image_header_is_not_saved_as_a_photo(): void
    {
        Storage::fake('public');
        $html = "<!DOCTYPE html>\n<html>\n<head><title>404 Not Found</title></head>\n<body>\n"
            ."<center><h1>404 Not Found</h1></center>\n<hr><center>nginx</center>\n</body>\n</html>\n";
        Http::fake([
            self::HTML_AS_IMAGE => Http::response($html, 200, ['Content-Type' => 'image/jpeg']),
            '*' => Http::response('', 404),
        ]);
        $downloader = new ProductImageDownloader;

        $saved = $downloader->downloadMany($this->product('Coba'), [self::HTML_AS_IMAGE], 1);

        $this->assertSame([], $saved);
        $this->assertSame(0, ProductImage::query()->count());
        $this->assertSame([], Storage::disk('public')->allFiles());
        $this->assertStringContainsString('strona HTML', $downloader->lastFailures()[self::HTML_AS_IMAGE] ?? '');
        $this->assertSame([], $downloader->lastRetryLaterUrls(), 'strona zamiast pliku pod kłamliwym nagłówkiem nie wraca do ponowienia');
    }

    public function test_svg_under_image_header_is_not_saved_as_a_photo(): void
    {
        Storage::fake('public');
        $svg = '<?xml version="1.0" encoding="UTF-8"?>'."\n"
            .'<svg xmlns="http://www.w3.org/2000/svg" width="600" height="400"><rect width="600" height="400"/></svg>';
        Http::fake([
            self::SVG_AS_IMAGE => Http::response($svg, 200, ['Content-Type' => 'image/png']),
            '*' => Http::response('', 404),
        ]);
        $downloader = new ProductImageDownloader;

        $this->assertSame([], $downloader->downloadMany($this->product('Coba'), [self::SVG_AS_IMAGE], 1));
        $this->assertStringContainsString('SVG', $downloader->lastFailures()[self::SVG_AS_IMAGE] ?? '');
    }

    public function test_firewall_page_under_image_header_goes_to_retry(): void
    {
        Storage::fake('public');
        $challenge = '<!DOCTYPE html><html lang="en-US"><head><title>Just a moment...</title></head><body>'
            .'<script src="/cdn-cgi/challenge-platform/h/b/orchestrate/chl_page/v1"></script></body></html>';
        Http::fake([
            self::HTML_AS_IMAGE => Http::response($challenge, 200, ['Content-Type' => 'image/jpeg']),
            '*' => Http::response('', 404),
        ]);
        $downloader = new ProductImageDownloader;

        $this->assertSame([], $downloader->downloadMany($this->product('Coba'), [self::HTML_AS_IMAGE], 1));
        $this->assertSame(0, ProductImage::query()->count());
        $this->assertStringContainsString('zapory', $downloader->lastFailures()[self::HTML_AS_IMAGE] ?? '');
        $this->assertSame([self::HTML_AS_IMAGE], $downloader->lastRetryLaterUrls(), 'zapora to odmowa chwilowa');
    }

    /** Próg z b20e290 (bok 100 px i pole 40 000): schemat 172x111 i kafel 150x150 odpadają, wąski kadr 600x160 nie. */
    public function test_image_smaller_than_minimum_is_rejected_with_its_size(): void
    {
        Storage::fake('public');
        $thumb = 'https://www.coba.com/wp-content/uploads/2021/03/HI010002-kafel.png';
        $strip = 'https://www.coba.com/wp-content/uploads/2021/03/HI010002-listwa.png';
        Http::fake([
            self::TINY => Http::response($this->png(172, 111), 200, ['Content-Type' => 'image/png']),
            $thumb => Http::response($this->png(150, 150), 200, ['Content-Type' => 'image/png']),
            $strip => Http::response($this->png(600, 160), 200, ['Content-Type' => 'image/png']),
            self::GOOD => Http::response($this->png(400, 300), 200, ['Content-Type' => 'image/png']),
            '*' => Http::response('', 404),
        ]);
        $downloader = new ProductImageDownloader;

        $saved = $downloader->downloadMany($this->product('Coba'), [self::TINY, $thumb, $strip, self::GOOD], 3);

        $this->assertSame([$strip, self::GOOD], array_map(static fn (ProductImage $i): string => (string) $i->source_url, $saved));
        $failures = $downloader->lastFailures();
        $this->assertStringContainsString('za mały (172x111)', $failures[self::TINY] ?? '');
        $this->assertStringContainsString('za mały (150x150)', $failures[$thumb] ?? '');
        $this->assertSame([], $downloader->lastRetryLaterUrls());
    }

    /** Import z Presty: nazwa pliku to link_rewrite wyrobu — „kamizelka-z-logo” to nazwa towaru, nie logo witryny. */
    public function test_presta_product_image_named_after_the_product_is_downloaded(): void
    {
        Storage::fake('public');
        $presta = 'https://supon.rzeszow.pl/1234-large_default/kamizelka-z-logo.jpg';
        $shopFile = 'https://supon.rzeszow.pl/img/p/1/2/3/4/baner-uwaga.jpg';
        Http::fake([
            $presta => Http::response($this->png(400, 300), 200, ['Content-Type' => 'image/png']),
            $shopFile => Http::response($this->png(410, 300), 200, ['Content-Type' => 'image/png']),
            '*' => Http::response('', 404),
        ]);
        $downloader = new ProductImageDownloader;

        $saved = $downloader->downloadMany($this->product('Coba'), [$presta, $shopFile], 2);

        $this->assertCount(2, $saved, json_encode($downloader->lastFailures(), JSON_UNESCAPED_UNICODE));
        $this->assertSame($presta, $saved[0]->source_url);
    }

    public function test_manufacturer_blocklist_skips_the_address_without_downloading(): void
    {
        Storage::fake('public');
        Http::fake([
            self::GOOD => Http::response($this->png(400, 300), 200, ['Content-Type' => 'image/png']),
            '*' => Http::response($this->png(400, 300), 200, ['Content-Type' => 'image/png']),
        ]);
        $downloader = new ProductImageDownloader;

        $saved = $downloader->downloadMany($this->product('Coba'), [self::ELEPHANT, self::LOGO, self::GOOD], 1);

        $this->assertCount(1, $saved);
        $this->assertSame(self::GOOD, $saved[0]->source_url);
        $failures = $downloader->lastFailures();
        $this->assertStringContainsString('coba', $failures[self::ELEPHANT] ?? '');
        $this->assertStringContainsString('logo', $failures[self::LOGO] ?? '');
        Http::assertNotSent(static fn (Request $request): bool => in_array($request->url(), [self::ELEPHANT, self::LOGO], true));
    }

    /** Wzorce profilu obowiązują tylko dla kart tego producenta; ogólne — dla każdej. */
    public function test_manufacturer_blocklist_does_not_apply_to_another_brand(): void
    {
        Storage::fake('public');
        Http::fake(['*' => Http::response($this->png(400, 300), 200, ['Content-Type' => 'image/png'])]);
        $downloader = new ProductImageDownloader;

        $saved = $downloader->downloadMany($this->product('Ansell'), [self::ELEPHANT], 1);

        $this->assertCount(1, $saved);
        $this->assertSame(self::ELEPHANT, $saved[0]->source_url);
    }

    public function test_page_image_list_leaves_out_blocked_addresses(): void
    {
        $page = 'https://www.coba.com/pl/produkt/hygimat';
        $html = '<!DOCTYPE html><html lang="pl"><head><meta charset="utf-8"><title>Hygimat | COBA Europe</title>'
            .'<meta property="og:image" content="'.self::ELEPHANT.'"></head>'
            .'<body><main><h1>Hygimat®</h1>'
            .'<img src="'.self::HEALTH.'" alt="Stand up for health">'
            .'<img src="'.self::GOOD.'" alt="Hygimat">'
            .'<p>Mata higieniczna COBA Europe Hygimat do stref czystych. '
            .str_repeat('Warstwa wierzchnia z kauczuku o dużej odporności na ścieranie i chemikalia, spód antypoślizgowy. ', 12)
            .'</p><table><tr><th>Numer części</th><th>Rozmiar</th><th>Kolor</th></tr>'
            .'<tr><td data-label="Numer części">HI010002</td><td>0,6 m x 0,9 m</td><td>Szary</td></tr></table>'
            .'</main></body></html>';
        Http::fake([$page => Http::response($html, 200), '*' => Http::response('', 404)]);
        $product = new Product(['sku' => 'HI010002', 'name' => 'Hygimat Szary 0.6m x 0.9m', 'manufacturer' => 'Coba']);

        $fetched = app(ProductPageFetcher::class)->fetch(
            [['url' => $page, 'title' => 'Hygimat | COBA Europe', 'snippet' => '']],
            (string) $product->sku,
            1,
            [],
            $product
        );

        $this->assertSame([$page], array_column($fetched['pages'], 'url'));
        $this->assertContains(self::GOOD, $fetched['image_urls']);
        $this->assertNotContains(self::HEALTH, $fetched['image_urls'], 'grafika reklamowa z profilu coba');
        $this->assertNotContains(self::ELEPHANT, $fetched['image_urls']);
        $this->assertNotContains(self::ELEPHANT, $fetched['trusted_image_urls'], 'og:image też przechodzi przez bramkę');
        $this->assertNotContains(self::ELEPHANT, $fetched['pages'][0]['trusted_image_urls'] ?? []);
        $this->assertNotContains(self::HEALTH, $fetched['pages'][0]['image_urls'] ?? []);
    }

    public function test_known_placeholder_image_is_rejected_by_checksum(): void
    {
        // prawdziwy plik z produkcji (products/10806, źródło fachhandel.pl): „404 Not Found nginx” jako poprawny PNG
        // 1280×1280 — przechodził bramkę typu i wymiarów; był na kartach HR Matting 10799, 10801, 10804–10806
        Storage::fake('public');
        $bytes = (string) file_get_contents(base_path('tests/Fixtures/images/nginx-404-fachhandel.png'));
        $this->assertSame('c057d3c30474558a036ea52ac00cc1cb3814ec41dd40b40ed419f5403d5365b0', hash('sha256', $bytes));
        $url = 'https://fachhandel.pl/img/imagecache/540001-541000/680x680/1/product-media/540001-541000/HR060004C_1.png';
        Http::fake([$url => Http::response($bytes, 200, ['Content-Type' => 'image/png']), '*' => Http::response('', 404)]);
        $downloader = new ProductImageDownloader;
        $product = $this->product('Coba');

        $this->assertSame([], $downloader->downloadMany($product, [$url], 1));
        $this->assertStringContainsString('Znana zaślepka', $downloader->lastFailures()[$url] ?? '');
        $this->assertSame([], $downloader->lastRetryLaterUrls(), 'zaślepka to trwałe odrzucenie, nie ponowienie');
        // zapis gotowych bajtów (łączniki B2B) — też odrzucony
        $this->assertNull($downloader->storeBytes($product, $bytes, 'image/png', 'https://b2b.example/media/HR060004C.png', 0));
        $this->assertSame(0, ProductImage::query()->count());
        $this->assertSame([], Storage::disk('public')->allFiles());

        // bez wpisu na liście ten sam plik przechodzi bramkę — łapie go tylko suma kontrolna
        config(['image_blocklist.checksums' => []]);
        $this->assertCount(1, $downloader->downloadMany($product, [$url], 1));
    }

    private function product(string $manufacturer): Product
    {
        return Product::query()->create([
            'sku' => 'HI010002',
            'name' => 'Hygimat Szary 0.6m x 0.9m',
            'manufacturer' => $manufacturer,
            'catalog_price_net' => 10,
            'purchase_price' => 8,
            'stock' => 1,
        ]);
    }

    private function png(int $w, int $h): string
    {
        $im = imagecreatetruecolor($w, $h);
        imagefill($im, 0, 0, imagecolorallocate($im, 120, 120, 120));
        ob_start();
        imagepng($im);
        imagedestroy($im);

        return (string) ob_get_clean();
    }
}
