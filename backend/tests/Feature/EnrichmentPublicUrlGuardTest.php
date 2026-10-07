<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Product;
use App\Models\ProductDocument;
use App\Models\ProductImage;
use App\Services\Campaigns\SmtpHostGuard;
use App\Services\Enrichment\BlockedPageReader;
use App\Services\Enrichment\BlockedUrlException;
use App\Services\Enrichment\CandidateRejection;
use App\Services\Enrichment\CatalogSitemapIndexer;
use App\Services\Enrichment\ManufacturerCatalogPdf;
use App\Services\Enrichment\ProductDocumentDownloader;
use App\Services\Enrichment\ProductImageDownloader;
use App\Services\Enrichment\ProductPageFetcher;
use App\Services\Enrichment\PublicUrlFetcher;
use App\Services\Enrichment\RetailerOnSiteSearch;
use App\Services\Enrichment\ShopHtmlCrawler;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * Wzbogacanie pobiera strony, zdjęcia i PDF spod adresów z wyszukiwarek i z linku przy karcie — nie wolno mu sięgnąć
 * do sieci wewnętrznej (SSRF): localhost, adresy prywatne, metadane chmury 169.254.169.254, nazwa wskazująca na adres
 * prywatny, przekierowanie na taki adres. Adresy publiczne bez zmian, także z przekierowaniem na inny serwer.
 */
final class EnrichmentPublicUrlGuardTest extends TestCase
{
    use RefreshDatabase;

    private const PUBLIC_IP = '93.184.216.34';

    protected function setUp(): void
    {
        parent::setUp();
        $this->app->instance(SmtpHostGuard::class, new SmtpHostGuard(static fn (string $host): array => match ($host) {
            'intranet.example.com' => ['10.1.2.3'],
            'metadata.example.com' => ['169.254.169.254'],
            // jeden z adresów prywatny — całość odpada (inaczej curl mógłby wybrać ten prywatny)
            'mixed.example.com' => [self::PUBLIC_IP, '192.168.1.59'],
            'nxdomain.example.com' => [],
            default => [self::PUBLIC_IP],
        }));
    }

    /** @return iterable<string, array{string}> */
    public static function blockedUrls(): iterable
    {
        yield 'localhost' => ['http://localhost/admin'];
        yield 'localhost z portem' => ['http://localhost:8123/api/user'];
        yield 'poddomena localhost' => ['https://api.localhost/x'];
        yield '127.0.0.1' => ['http://127.0.0.1/karta.html'];
        yield '127.1' => ['http://127.1/karta.html'];
        yield 'liczba zamiast IP' => ['http://2130706433/karta.html'];
        yield '10.x' => ['http://10.0.0.5/karta.html'];
        yield '192.168.x' => ['http://192.168.1.59:4000/v1/models'];
        yield '172.16.x' => ['https://172.16.4.2/'];
        yield 'metadane chmury' => ['http://169.254.169.254/latest/meta-data/iam/security-credentials/'];
        yield 'IPv6 loopback' => ['http://[::1]/'];
        yield 'IPv6 z IPv4 w środku' => ['http://[::ffff:127.0.0.1]/'];
        yield 'nazwa na adres prywatny' => ['https://intranet.example.com/karta'];
        yield 'nazwa na metadane' => ['https://metadata.example.com/latest/meta-data/'];
        yield 'nazwa z jednym adresem prywatnym' => ['https://mixed.example.com/karta'];
        yield 'nieznana nazwa' => ['https://nxdomain.example.com/karta'];
        yield 'inny port' => ['https://shop.example.com:8443/karta'];
        yield 'inny schemat' => ['ftp://shop.example.com/karta.pdf'];
        yield 'plik lokalny' => ['file:///etc/passwd'];
    }

    #[DataProvider('blockedUrls')]
    public function test_private_and_unusual_addresses_are_not_allowed(string $url): void
    {
        $this->assertNull((new PublicUrlFetcher)->pins($url));
        $this->assertFalse((new PublicUrlFetcher)->allows($url));
    }

    public function test_public_host_is_pinned_to_the_checked_address(): void
    {
        $fetcher = new PublicUrlFetcher;

        $pins = $fetcher->pins('https://Shop.Example.com/karta?x=1');
        $this->assertContains('shop.example.com:443:'.self::PUBLIC_IP, $pins);
        $this->assertContains('shop.example.com:80:'.self::PUBLIC_IP, $pins);
        // publiczny adres IP wprost: nic do rozwiązywania, ale wolno
        $this->assertSame([], $fetcher->pins('http://'.self::PUBLIC_IP.'/karta'));
        $this->assertNotNull($fetcher->pins('http://shop.example.com:80/karta'));
    }

    public function test_each_request_is_pinned_and_redirects_are_not_left_to_guzzle(): void
    {
        $seen = [];
        Http::fake(function (Request $request, array $options) use (&$seen) {
            $seen[$request->url()] = $options;

            return $request->url() === 'https://shop.example.com/a.jpg'
                ? Http::response('', 301, ['Location' => 'https://cdn.example.net/a.jpg'])
                : Http::response('ok', 200);
        });

        $response = (new PublicUrlFetcher)->get(fn () => Http::timeout(5), 'https://shop.example.com/a.jpg', 1000);

        $this->assertSame('ok', $response->body());
        $this->assertSame(['https://shop.example.com/a.jpg', 'https://cdn.example.net/a.jpg'], array_keys($seen));
        foreach ($seen as $url => $options) {
            $host = (string) parse_url($url, PHP_URL_HOST);
            $this->assertFalse($options['allow_redirects']);
            $this->assertContains($host.':443:'.self::PUBLIC_IP, $options['curl'][CURLOPT_RESOLVE]);
            $this->assertSame(1000, $options['curl'][CURLOPT_MAXFILESIZE]);
        }
    }

    public function test_international_host_is_sent_as_the_checked_punycode_name(): void
    {
        if (! function_exists('idn_to_ascii')) {
            $this->markTestSkipped('intl jest wymagane');
        }
        // „ß” zamieniane inaczej przez intl (przejściowo: „ss”) i przez curl/libidn2 (xn--…) — połączenie musi iść
        // pod tę samą nazwę, którą sprawdzono i przypięto, a nie pod nazwę, którą curl zamieniłby sam
        $checked = [];
        $this->app->instance(SmtpHostGuard::class, new SmtpHostGuard(static function (string $host) use (&$checked): array {
            $checked[] = $host;

            return [self::PUBLIC_IP];
        }));
        $seen = [];
        Http::fake(function (Request $request, array $options) use (&$seen) {
            $seen[] = [(string) parse_url($request->url(), PHP_URL_HOST), $options['curl'][CURLOPT_RESOLVE]];

            return Http::response('ok', 200);
        });

        (new PublicUrlFetcher)->get(fn () => Http::timeout(5), 'https://straße-łódź.example.com/karta', 1000);

        $ascii = idn_to_ascii('straße-łódź.example.com', IDNA_NONTRANSITIONAL_TO_ASCII, INTL_IDNA_VARIANT_UTS46);
        $this->assertStringStartsWith('xn--', (string) $ascii);
        $this->assertSame([$ascii], $checked);
        $this->assertSame([[$ascii, [$ascii.':80:'.self::PUBLIC_IP, $ascii.':443:'.self::PUBLIC_IP]]], $seen);
    }

    public function test_redirect_loop_stops_after_five_hops(): void
    {
        Http::fake(['*' => Http::response('', 302, ['Location' => '/znowu'])]);

        try {
            (new PublicUrlFetcher)->get(fn () => Http::timeout(5), 'https://shop.example.com/start', 1000);
            $this->fail('Pętla przekierowań bez końca.');
        } catch (ConnectionException $e) {
            $this->assertSame(PublicUrlFetcher::TOO_MANY_REDIRECTS, $e->getMessage());
        }
        Http::assertSentCount(6);
    }

    public function test_page_on_private_address_is_rejected_without_request_or_reader(): void
    {
        Http::fake(['*' => Http::response('nie powinno paść', 500)]);
        $fetcher = (new ProductPageFetcher)->bypassCache();

        $fetched = $fetcher->fetch([
            ['url' => 'http://169.254.169.254/latest/meta-data/', 'title' => 'X', 'snippet' => 'X'],
            ['url' => 'http://10.0.0.5/karta.html', 'title' => 'X', 'snippet' => 'X'],
            ['url' => 'https://intranet.example.com/karta', 'title' => 'X', 'snippet' => 'X'],
        ], 'ABC-123', 3);

        Http::assertNothingSent();
        $this->assertSame([], $fetched['pages']);
        $this->assertCount(3, $fetched['rejected']);
        foreach ($fetched['rejected'] as $row) {
            $this->assertSame(CandidateRejection::BLOCKED_HOST, $row['reason']);
            $this->assertSame(BlockedUrlException::MESSAGE, $row['detail']);
        }
    }

    public function test_page_redirect_to_private_address_is_not_followed(): void
    {
        $start = 'https://shop.example.com/karta-abc-123';
        Http::fake([
            $start => Http::response('', 302, ['Location' => 'http://127.0.0.1:8123/api/user']),
            '*' => Http::response('nie powinno paść', 500),
        ]);
        $fetcher = (new ProductPageFetcher)->bypassCache();

        $fetched = $fetcher->fetch([['url' => $start, 'title' => 'ABC-123', 'snippet' => '']], 'ABC-123', 1);

        Http::assertSentCount(1);
        Http::assertNotSent(static fn (Request $r): bool => str_contains($r->url(), '127.0.0.1') || str_contains($r->url(), 'r.jina.ai'));
        $this->assertSame([['url' => $start, 'reason' => CandidateRejection::BLOCKED_HOST, 'detail' => BlockedUrlException::MESSAGE]], $fetched['rejected']);
        $this->assertNull($fetcher->fetchRaw($start));
    }

    public function test_page_redirect_to_other_public_host_still_works(): void
    {
        $start = 'https://sklep.example.com/p/abc-123';
        $final = 'https://www.sklep.example.com/produkt/abc-123.html';
        $html = '<!doctype html><html><head><title>ABC-123</title></head><body>'
            .str_repeat('<p>Rękawice ochronne ABC-123, norma EN 388:2016.</p>', 40).'</body></html>';
        Http::fake([
            $start => Http::response('', 301, ['Location' => $final]),
            $final => Http::response($html, 200, ['Content-Type' => 'text/html; charset=UTF-8']),
            '*' => Http::response('', 404),
        ]);

        $raw = (new ProductPageFetcher)->bypassCache()->fetchRaw($start);

        $this->assertIsArray($raw);
        $this->assertSame($html, $raw['html']);
        Http::assertSent(static fn (Request $r): bool => $r->url() === $final);
    }

    public function test_image_on_private_address_or_behind_private_redirect_is_not_downloaded(): void
    {
        Storage::fake('public');
        $metadata = 'http://169.254.169.254/latest/meta-data/zdjecie.jpg';
        $redirecting = 'https://cdn.example.com/media/abc-123.jpg';
        Http::fake([
            $redirecting => Http::response('', 302, ['Location' => 'http://localhost/abc-123.jpg']),
            '*' => Http::response('nie powinno paść', 500),
        ]);
        $product = $this->product();
        $downloader = new ProductImageDownloader;

        $saved = $downloader->downloadMany($product, [$metadata, $redirecting], 2);

        $this->assertSame([], $saved);
        $this->assertSame(0, ProductImage::query()->count());
        Http::assertSentCount(1);
        Http::assertNotSent(static fn (Request $r): bool => str_contains($r->url(), 'localhost') || str_contains($r->url(), '169.254'));
        $this->assertSame(BlockedUrlException::MESSAGE, $downloader->lastFailures()[$metadata] ?? null);
        $this->assertSame(BlockedUrlException::MESSAGE, $downloader->lastFailures()[$redirecting] ?? null);
        // odmowa stała — nie wraca do ponowienia
        $this->assertSame([], $downloader->lastRetryLaterUrls());
    }

    public function test_image_behind_public_redirect_is_downloaded(): void
    {
        if (! function_exists('imagecreatetruecolor')) {
            $this->markTestSkipped('GD jest wymagane');
        }
        Storage::fake('public');
        $start = 'https://shop.example.com/media/abc-123.jpg';
        $final = 'https://cdn.example.net/media/abc-123.jpg';
        $im = imagecreatetruecolor(300, 300);
        ob_start();
        imagejpeg($im);
        $jpeg = (string) ob_get_clean();
        Http::fake([
            $start => Http::response('', 301, ['Location' => $final]),
            $final => Http::response($jpeg, 200, ['Content-Type' => 'image/jpeg']),
        ]);

        $saved = (new ProductImageDownloader)->downloadMany($this->product(), [$start], 1);

        $this->assertCount(1, $saved);
        $this->assertSame($start, $saved[0]->source_url);
    }

    public function test_document_on_private_address_or_behind_private_redirect_is_not_downloaded(): void
    {
        Storage::fake('public');
        $redirecting = 'https://docs.example.com/abc-123-deklaracja-zgodnosci.pdf';
        Http::fake([
            $redirecting => Http::response('', 302, ['Location' => 'http://10.0.0.7/abc-123-deklaracja-zgodnosci.pdf']),
            '*' => Http::response('%PDF-1.4 nie powinno paść', 200, ['Content-Type' => 'application/pdf']),
        ]);

        $saved = (new ProductDocumentDownloader)->downloadMany($this->product(), [
            'http://192.168.1.10/abc-123-deklaracja-zgodnosci.pdf',
            $redirecting,
        ]);

        $this->assertSame([], $saved);
        $this->assertSame(0, ProductDocument::query()->count());
        Http::assertSentCount(1);
        Http::assertNotSent(static fn (Request $r): bool => str_contains($r->url(), '10.0.0.7') || str_contains($r->url(), '192.168.'));
    }

    public function test_reader_is_not_asked_for_private_address(): void
    {
        Http::fake(['https://r.jina.ai/*' => Http::response(str_repeat('Treść strony wewnętrznej. ', 20), 200)]);
        $reader = new BlockedPageReader;

        foreach (['http://169.254.169.254/latest/meta-data/', 'http://192.168.1.59:4000/v1/models', 'https://intranet.example.com/'] as $url) {
            $this->assertNull($reader->fetch($url));
            $this->assertNull($reader->fetchMarkdown($url));
            $this->assertNull($reader->fetchForCrawl($url));
            $this->assertSame('reader: '.BlockedUrlException::MESSAGE, $reader->failureFor($url));
            $this->assertFalse($reader->failureIsTransient($url));
        }
        Http::assertNothingSent();

        // adres publiczny idzie do czytnika jak dotąd
        $this->assertIsArray($reader->fetch('https://www.ansell.com/pl/pl/products/ringers-r259'));
        Http::assertSentCount(1);
    }

    public function test_unresolvable_host_stays_a_fetch_failure_not_a_private_address(): void
    {
        // chwilowa awaria DNS sklepu to nie sieć wewnętrzna — strona wraca do ponowienia jak dotąd (curl: Could not
        // resolve host), a zdjęcie nie (jak dotąd)
        Storage::fake('public');
        Http::fake(['*' => Http::response('nie powinno paść', 500)]);
        $page = 'https://nxdomain.example.com/karta-abc-123';
        $image = 'https://nxdomain.example.com/abc-123.jpg';

        $fetched = (new ProductPageFetcher)->bypassCache()->fetch([['url' => $page, 'title' => 'ABC-123', 'snippet' => '']], 'ABC-123', 1);
        $downloader = new ProductImageDownloader;
        $downloader->downloadMany($this->product(), [$image], 1);

        Http::assertNothingSent();
        $this->assertSame([['url' => $page, 'reason' => CandidateRejection::FETCH_FAILED, 'detail' => 'brak odpowiedzi']], $fetched['rejected']);
        $this->assertStringContainsString('Could not resolve host', $downloader->lastFailures()[$image] ?? '');
        $this->assertSame([], $downloader->lastRetryLaterUrls());
    }

    public function test_follow_pins_each_hop_and_stops_before_private_redirect(): void
    {
        $hops = [];
        try {
            (new PublicUrlFetcher)->follow(function (string $url, array $pins) use (&$hops): ?string {
                $hops[] = [$url, $pins];

                return $url === 'https://shop.example.com/robots.txt' ? 'https://www.shop.example.com/robots.txt' : 'http://10.0.0.5/robots.txt';
            }, 'https://shop.example.com/robots.txt');
            $this->fail('Przekierowanie na adres prywatny przeszło.');
        } catch (BlockedUrlException $e) {
            $this->assertSame('http://10.0.0.5/robots.txt', $e->url);
        }
        $this->assertSame([
            ['https://shop.example.com/robots.txt', ['shop.example.com:80:'.self::PUBLIC_IP, 'shop.example.com:443:'.self::PUBLIC_IP]],
            ['https://www.shop.example.com/robots.txt', ['www.shop.example.com:80:'.self::PUBLIC_IP, 'www.shop.example.com:443:'.self::PUBLIC_IP]],
        ], $hops);
    }

    public function test_shop_crawler_does_not_follow_redirects_into_private_network(): void
    {
        // Http::fake dopisuje „*” na początku wzorca — czytnik (r.jina.ai/<adres sklepu>) musi stać przed sklepem
        Http::fake([
            'https://r.jina.ai/*' => Http::response('', 404),
            'https://www.sklep.example.com/*' => Http::response('', 302, ['Location' => 'http://127.0.0.1/server-status']),
            'https://sklep.example.com/*' => Http::response('', 301, ['Location' => 'http://169.254.169.254/latest/meta-data/']),
            '*' => Http::response('', 404),
        ]);

        $rows = app(ShopHtmlCrawler::class)->crawl('sklep.example.com', 10, microtime(true) + 30, static fn (string $text) => null);

        $this->assertSame([], $rows);
        Http::assertSent(static fn (Request $r): bool => $r->url() === 'https://www.sklep.example.com/');
        Http::assertNotSent(static fn (Request $r): bool => str_contains($r->url(), '127.0.0.1') || str_contains($r->url(), '169.254'));
    }

    public function test_shop_crawler_reads_page_behind_public_redirect(): void
    {
        $html = '<!doctype html><html><head><title>Sklep</title></head><body>'
            .'<a href="/productpage/ABC-123">Rękawice ABC-123</a>'.str_repeat('<p>Odzież robocza</p>', 20).'</body></html>';
        Http::fake([
            'https://r.jina.ai/*' => Http::response('', 404),
            'https://www.sklep.example.com/' => Http::response('', 301, ['Location' => 'https://sklep.example.com/']),
            'https://sklep.example.com/' => Http::response($html, 200, ['Content-Type' => 'text/html']),
            '*' => Http::response('', 404),
        ]);

        $rows = app(ShopHtmlCrawler::class)->crawl('sklep.example.com', 10, microtime(true) + 30, static fn (string $text) => null);

        // strona główna z przekierowania www → apex przeczytana; link względny liczony od adresu startowego, jak dotąd
        $this->assertContains('https://www.sklep.example.com/productpage/ABC-123', array_column($rows, 'url'));
        Http::assertSent(static fn (Request $r): bool => $r->url() === 'https://sklep.example.com/');
    }

    public function test_retailer_search_does_not_follow_redirect_into_private_network(): void
    {
        Http::fake([
            'https://bpbhp.pl/catalogsearch/*' => Http::response('', 302, ['Location' => 'http://169.254.169.254/latest/meta-data/']),
            'https://optimumbhp.pl/produkt/abc-123.html' => Http::response('<html>karta ABC-123</html>', 200),
            'https://optimumbhp.pl/search*' => Http::response('', 302, ['Location' => 'https://optimumbhp.pl/produkt/abc-123.html']),
            '*' => Http::response('nie powinno paść', 500),
        ]);
        $search = app(RetailerOnSiteSearch::class);
        $fetch = (new \ReflectionMethod($search, 'fetch'))->getClosure($search);

        $this->assertSame(['html' => '', 'url' => ''], $fetch('https://bpbhp.pl/catalogsearch/result/?q=ABC-123'));
        Http::assertNotSent(static fn (Request $r): bool => str_contains($r->url(), '169.254'));
        // przekierowanie w obrębie publicznego sklepu — jak dotąd
        $page = $fetch('https://optimumbhp.pl/search?controller=search&s=ABC-123');
        $this->assertSame('<html>karta ABC-123</html>', $page['html']);
        // adres końcowy dla productPageFromRedirect — jak dotąd
        $this->assertSame('https://optimumbhp.pl/produkt/abc-123.html', $page['url']);
    }

    public function test_manufacturer_catalog_redirect_into_private_network_is_not_downloaded(): void
    {
        Http::fake([
            'https://www.producent.example.com/katalog.pdf' => Http::response('', 302, ['Location' => 'http://10.0.0.7/katalog.pdf']),
            '*' => Http::response('%PDF-1.4 nie powinno paść', 200, ['Content-Type' => 'application/pdf']),
        ]);
        $catalog = app(ManufacturerCatalogPdf::class);
        $download = (new \ReflectionMethod($catalog, 'download'))->getClosure($catalog);

        try {
            $download('https://www.producent.example.com/katalog.pdf');
            $this->fail('Przekierowanie na adres prywatny przeszło.');
        } catch (BlockedUrlException) {
        }
        Http::assertSentCount(1);
        Http::assertNotSent(static fn (Request $r): bool => str_contains($r->url(), '10.0.0.7'));
    }

    public function test_sitemap_index_does_not_reach_private_network(): void
    {
        // robots.txt i indeks map podają adresy na dowolny serwer — prywatne pomijamy, publiczne czytamy jak dotąd
        Http::fake([
            'https://r.jina.ai/*' => Http::response('', 404),
            'https://sklep.example.com/robots.txt' => Http::response(
                "User-agent: *\nSitemap: http://169.254.169.254/latest/meta-data/sitemap.xml\n"
                ."Sitemap: https://sklep.example.com/sitemap.xml\nSitemap: https://sklep.example.com/sitemap-old.xml\n",
                200
            ),
            'https://sklep.example.com/sitemap.xml' => Http::response(
                '<?xml version="1.0"?><sitemapindex>'
                .'<sitemap><loc>https://intranet.example.com/sitemap-products.xml</loc></sitemap>'
                .'<sitemap><loc>https://sklep.example.com/sitemap-products.xml</loc></sitemap>'
                .'</sitemapindex>',
                200
            ),
            'https://sklep.example.com/sitemap-products.xml' => Http::response(
                '<?xml version="1.0"?><urlset><url><loc>https://sklep.example.com/produkt/rekawice-abc-123</loc></url></urlset>',
                200
            ),
            'https://sklep.example.com/sitemap-old.xml' => Http::response('', 302, ['Location' => 'http://localhost/sitemap.xml']),
            '*' => Http::response('<!DOCTYPE html><html><head><title>404</title></head></html>', 404),
        ]);

        $result = app(CatalogSitemapIndexer::class)->index('sklep.example.com', 1000, 60);

        $this->assertGreaterThanOrEqual(1, $result['saved']);
        $this->assertDatabaseHas('catalog_pages', ['url' => 'https://sklep.example.com/produkt/rekawice-abc-123']);
        Http::assertSent(static fn (Request $r): bool => $r->url() === 'https://sklep.example.com/sitemap-old.xml');
        Http::assertNotSent(static fn (Request $r): bool => str_contains($r->url(), '169.254')
            || str_contains($r->url(), 'intranet.example.com')
            || str_contains($r->url(), 'localhost'));
    }

    public function test_robots_redirect_to_private_address_is_not_followed_nor_retried(): void
    {
        Http::fake([
            'https://sklep.example.com/robots.txt' => Http::response('', 302, ['Location' => 'http://10.0.0.5/robots.txt']),
            '*' => Http::response("Sitemap: http://10.0.0.5/sitemap.xml\n", 200),
        ]);
        $indexer = app(CatalogSitemapIndexer::class);
        $fetch = (new \ReflectionMethod($indexer, 'fetch'))->getClosure($indexer);

        $this->assertNull($fetch('https://sklep.example.com/robots.txt', 8, 2));
        Http::assertSentCount(1);
    }

    private function product(): Product
    {
        return Product::query()->create([
            'sku' => 'ABC-123',
            'name' => 'Rękawice ABC-123',
            'manufacturer' => 'Example',
            'catalog_price_net' => 5,
            'purchase_price' => 5,
            'stock' => 1,
        ]);
    }
}
