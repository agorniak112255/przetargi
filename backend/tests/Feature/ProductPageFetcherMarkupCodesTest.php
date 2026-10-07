<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Services\Enrichment\ProductPageFetcher;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * Kody z mikrodanych (markupIdentifiers), adres po przekierowaniu i dziennik stron przebiegu — dane dla werdyktu
 * tożsamości źródła (SourceIdentity) i zapisu źródeł.
 */
final class ProductPageFetcherMarkupCodesTest extends TestCase
{
    public function test_markup_identifiers_read_microdata_json_ld_variants_and_part_numbers(): void
    {
        $html = '<html><head>'
            .'<meta content="5901234567890" itemprop="gtin13">'
            .'<script type="application/ld+json">'.json_encode([
                '@context' => 'https://schema.org',
                '@graph' => [
                    ['@type' => 'BreadcrumbList', 'sku' => 'NIE-TO'],
                    ['@type' => 'ProductGroup', 'name' => 'Orthomat Standard', 'sku' => 'AF06', 'hasVariant' => [
                        ['@type' => 'Product', 'sku' => 'AF060001', 'gtin14' => '05012345678900'],
                        ['@type' => 'Product', 'sku' => 'AF060002'],
                    ]],
                ],
            ]).'</script></head><body class="catalog_product_view_sku_MAG-123">'
            .'<span itemprop="sku">CCLIP25</span><span itemprop="mpn" content="MPN-77">x</span>'
            .'<a data-part="AF060003C" data-colour="Grey">Request Price</a>'
            .'</body></html>';

        $codes = (new ProductPageFetcher)->markupIdentifiers($html);

        foreach ([
            ['type' => 'gtin', 'value' => '5901234567890'],
            ['type' => 'sku', 'value' => 'CCLIP25'],
            ['type' => 'mpn', 'value' => 'MPN-77'],
            ['type' => 'sku', 'value' => 'MAG-123'],
            ['type' => 'part', 'value' => 'AF060003C'],
            ['type' => 'sku', 'value' => 'AF06'],
            ['type' => 'sku', 'value' => 'AF060001'],
            ['type' => 'gtin', 'value' => '05012345678900'],
            ['type' => 'sku', 'value' => 'AF060002'],
        ] as $expected) {
            $this->assertContains($expected, $codes);
        }
        // węzeł spoza Product/ProductGroup nie niesie kodu wyrobu
        $this->assertNotContains(['type' => 'sku', 'value' => 'NIE-TO'], $codes);
        $this->assertCount(9, $codes, 'każdy kod raz');
    }

    public function test_markup_identifiers_take_only_the_main_product(): void
    {
        $html = '<html><head>'
            .'<script type="application/ld+json">'.json_encode([
                '@context' => 'https://schema.org',
                '@graph' => [
                    ['@type' => 'ProductGroup', '@id' => '#grupa', 'name' => 'Orthomat Standard', 'sku' => 'AF06',
                        'offers' => ['@type' => 'AggregateOffer', 'offers' => [['@type' => 'Offer', 'sku' => 'AF06-OFERTA']]],
                        'hasVariant' => [['@id' => '#v1']],
                        'isRelatedTo' => ['@type' => 'Product', 'sku' => 'POWIAZANY-1'],
                        'isAccessoryOrSparePartFor' => ['@type' => 'Product', 'sku' => 'CZESC-DO-1']],
                    ['@type' => 'Product', '@id' => '#v1', 'sku' => 'AF060001'],
                    ['@type' => 'Product', 'sku' => 'AF060002', 'isVariantOf' => ['@id' => '#grupa']],
                    ['@type' => 'Product', 'sku' => 'KARUZELA-JSON'],
                ],
            ]).'</script></head><body>'
            .'<section class="recently-viewed"><p>Ostatnio oglądane</p></section>'
            .'<div itemscope itemtype="https://schema.org/Product"><h1 itemprop="name">Orthomat</h1>'
            .'<span itemprop="sku">AF060001</span>'
            .'<div itemprop="offers" itemscope itemtype="https://schema.org/Offer"><meta itemprop="gtin13" content="5901234567890"></div>'
            .'<div itemprop="isSimilarTo" itemscope itemtype="https://schema.org/Product"><span itemprop="sku">PODOBNY-1</span></div>'
            .'<div itemscope itemtype="https://schema.org/Brand"><span itemprop="sku">MARKA-1</span></div>'
            .'</div>'
            .'<section class="crosssell"><div itemscope itemtype="http://schema.org/Product"><span itemprop="sku">KARUZELA-1</span></div>'
            .'<div itemscope itemtype="http://schema.org/Product"><span itemprop="mpn">KARUZELA-2</span></div></section>'
            .'</body></html>';

        $values = array_column((new ProductPageFetcher)->markupIdentifiers($html), 'value');
        sort($values);

        $this->assertSame(['5901234567890', 'AF06', 'AF06-OFERTA', 'AF060001', 'AF060002'], $values);
    }

    public function test_loose_itemprops_count_only_when_single_value(): void
    {
        $fetcher = new ProductPageFetcher;
        $carousel = '<html><body><ul><li><span itemprop="sku">TILE-111</span></li><li><span itemprop="sku">TILE-222</span></li></ul>'
            .'<meta itemprop="gtin13" content="5901234567890"></body></html>';

        $this->assertSame([['type' => 'gtin', 'value' => '5901234567890']], $fetcher->markupIdentifiers($carousel));
        $this->assertSame([['type' => 'sku', 'value' => 'ONE-111']], $fetcher->markupIdentifiers('<p><span itemprop="sku"> ONE-111 </span></p>'));
    }

    public function test_fetched_page_carries_final_url_and_markup_codes(): void
    {
        $url = 'https://www.coba.com/product/orthomat-standard';
        Http::fake([$url => Http::response($this->card('AF060001'), 200, ['Content-Type' => 'text/html'])]);

        $pages = (new ProductPageFetcher)->bypassCache()->fetch([['url' => $url, 'title' => 'Orthomat Standard']], 'AF060001', 1)['pages'];

        $this->assertCount(1, $pages);
        $this->assertSame($url, $pages[0]['final_url']);
        $this->assertContains(['type' => 'sku', 'value' => 'AF060001'], $pages[0]['markup_codes']);
    }

    public function test_run_log_collects_pages_of_all_fetches_until_stopped(): void
    {
        Http::fake(fn ($request) => Http::response($this->card('X'.substr(md5($request->url()), 0, 6)), 200, ['Content-Type' => 'text/html']));
        $fetcher = (new ProductPageFetcher)->bypassCache();
        $fetch = static fn (string $url): array => $fetcher->fetch([['url' => $url, 'title' => 'Mata']], '', 1);

        $fetch('https://a.example/1');
        $this->assertSame([], $fetcher->runLog(), 'bez startRunLog dziennik jest pusty');

        $fetcher->startRunLog();
        $fetch('https://a.example/2');
        $fetch('https://a.example/3');
        $fetch('https://a.example/2/');
        $this->assertSame(
            ['https://a.example/3', 'https://a.example/2/'],
            array_column($fetcher->runLog(), 'url'),
            'ten sam adres raz, ostatnie pobranie na końcu'
        );

        for ($i = 0; $i < 45; $i++) {
            $fetch('https://b.example/'.$i);
        }
        $log = array_column($fetcher->runLog(), 'url');
        $this->assertCount(40, $log, 'najwyżej 40 stron');
        $this->assertSame('https://b.example/44', end($log));
        $this->assertSame('https://b.example/5', $log[0], 'wypadają najstarsze');

        $fetcher->stopRunLog();
        $this->assertSame([], $fetcher->runLog());
        $fetch('https://a.example/4');
        $this->assertSame([], $fetcher->runLog());
    }

    public function test_run_log_over_limit_drops_shortest_pages_and_restarts_clean(): void
    {
        // Strona producenta (pierwsza, z treścią) zostaje, choć po niej przychodzi ponad 40 krótszych stron sklepów.
        Http::fake(fn ($request) => Http::response(
            $this->card('X'.substr(md5($request->url()), 0, 6), str_contains($request->url(), 'producent') ? 30 : 10),
            200,
            ['Content-Type' => 'text/html']
        ));
        $fetcher = (new ProductPageFetcher)->bypassCache();
        $fetch = static fn (string $url): array => $fetcher->fetch([['url' => $url, 'title' => 'Mata']], '', 1);

        $fetcher->startRunLog();
        $fetch('https://producent.example/mata');
        for ($i = 0; $i < 45; $i++) {
            $fetch('https://sklep.example/'.$i);
        }
        $log = array_column($fetcher->runLog(), 'url');
        $this->assertCount(40, $log);
        $this->assertSame('https://producent.example/mata', $log[0], 'najdłuższa strona nie wypada');
        $this->assertSame('https://sklep.example/6', $log[1], 'z równych wypadają najstarsze');
        $this->assertSame('https://sklep.example/44', end($log));

        // Przebieg bez stopRunLog (wyjątek przed try): następny start zaczyna od pustego dziennika.
        $fetcher->startRunLog();
        $this->assertSame([], $fetcher->runLog());
        $fetch('https://sklep.example/nowa');
        $this->assertSame(['https://sklep.example/nowa'], array_column($fetcher->runLog(), 'url'));
    }

    private function card(string $code, int $paragraphs = 10): string
    {
        return '<html><head><title>Mata '.$code.'</title></head><body><h1>Mata antyzmęczeniowa '.$code.'</h1>'
            .'<div class="product-description"><p>Jednowarstwowa mata piankowa '.$code.' do suchych pomieszczeń, '
            .'izoluje od zimnej podłogi i zmniejsza zmęczenie przy pracy stojącej przez wiele godzin.</p>'
            // strona krótsza niż 800 znaków to dla pobieracza zapora — szłaby przez czytnik bez mikrodanych
            .str_repeat('<p>Lekka, łatwa do przenoszenia mata o teksturowanej powierzchni, odporna na wilgoć.</p>', $paragraphs).'</div>'
            .'<span itemprop="sku">'.$code.'</span></body></html>';
    }
}
