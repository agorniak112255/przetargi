<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Models\Product;
use App\Services\Enrichment\RetailerOnSiteSearch;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

final class RetailerOnSiteSearchTest extends TestCase
{
    public function test_keeps_matching_model_and_drops_neighbour(): void
    {
        Http::fake([
            'https://bpbhp.pl/catalogsearch/result/*' => Http::response(
                '<a href="https://bpbhp.pl/kombinezon-ansell-alphatec-4000-model-111">Kombinezon AlphaTec 4000 model 111</a>'
                .'<a href="https://bpbhp.pl/kombinezon-ansell-alphatec-4000-model-121">Kombinezon AlphaTec 4000 model 121</a>',
                200
            ),
            '*' => Http::response('empty', 200),
        ]);

        $product = new Product([
            'sku' => 'GR40T-00121-07',
            'name' => '4000-GR CVRL HOOD 121-G02.3XL',
            'manufacturer' => 'Ansell',
        ]);
        $hits = app(RetailerOnSiteSearch::class)->find($product);
        $urls = array_column($hits, 'url');

        $this->assertContains('https://bpbhp.pl/kombinezon-ansell-alphatec-4000-model-121', $urls);
        $this->assertNotContains('https://bpbhp.pl/kombinezon-ansell-alphatec-4000-model-111', $urls);
    }

    public function test_query_uses_style_not_article_number(): void
    {
        $search = app(RetailerOnSiteSearch::class);
        $query = $search->query(new Product([
            'sku' => 'GR40T-00121-07',
            'name' => '4000-GR CVRL HOOD 121-G02.3XL',
            'manufacturer' => 'Ansell',
        ]));

        $this->assertStringContainsString('AlphaTec', $query);
        $this->assertStringContainsString('4000', $query);
        $this->assertStringContainsString('121', $query);
        $this->assertStringNotContainsString('GR40T', $query);
        $this->assertStringContainsString('121', $search->queryBareModel(new Product([
            'sku' => 'GR40T-00121-07',
            'name' => '4000-GR CVRL HOOD 121-G02.3XL',
            'manufacturer' => 'Ansell',
        ])));
    }

    public function test_marel_query_uses_shop_model_not_price_list_tail(): void
    {
        $search = app(RetailerOnSiteSearch::class);
        $product = new Product([
            'sku' => 'CADIZ-42',
            'name' => 'PÓŁBUTY CADIZ S1PS FO SR',
            'manufacturer' => 'MAREL PLUS sp. z o.o.',
        ]);

        $this->assertSame('CADIZ', $search->query($product));
    }

    public function test_marel_keeps_matching_product_from_shop_search(): void
    {
        Http::fake([
            'https://marelplus.pl/szukaj*' => Http::response(
                '<a href="/polbuty-cadiz-s1ps-fo-sr">Półbuty Cadiz S1PS FO SR</a>'
                .'<a href="/trzewiki-sonora-s3">Trzewiki Sonora S3</a>',
                200
            ),
            '*' => Http::response('empty', 200),
        ]);

        $product = new Product([
            'sku' => 'CADIZ-S1PS',
            'name' => 'PÓŁBUTY CADIZ S1PS FO SR',
            'manufacturer' => 'MAREL PLUS',
        ]);
        $hits = app(RetailerOnSiteSearch::class)->find($product);
        $urls = array_column($hits, 'url');

        $this->assertContains('https://marelplus.pl/polbuty-cadiz-s1ps-fo-sr', $urls);
        $this->assertNotContains('https://marelplus.pl/trzewiki-sonora-s3', $urls);
    }

    public function test_warehouse_sku_is_searched_before_single_word_model(): void
    {
        $search = app(RetailerOnSiteSearch::class);
        $beagle = new Product([
            'sku' => '211600170000',
            'name' => 'BEAGLE',
            'manufacturer' => 'CANIS SAFETY',
        ]);

        $this->assertSame('211600170000', $search->query($beagle));
        $this->assertSame('BEAGLE', $search->queryBareModel($beagle));
    }

    public function test_mapa_query_uses_catalog_name_without_size(): void
    {
        $search = app(RetailerOnSiteSearch::class);
        $product = new Product([
            'sku' => 'KRYTECH-563-11',
            'name' => 'KRYTECH 563',
            'manufacturer' => 'MAPA',
        ]);

        $this->assertSame('KRYTECH 563', $search->query($product));
    }

    public function test_mapa_falls_back_to_icd_when_official_catalog_is_empty(): void
    {
        Http::fake([
            'https://www.mapa-pro.pl/wyszukiwanie-zaawansowane*' => Http::response('<p>brak</p>', 200),
            'https://icd.pl/szukaj*' => Http::response(
                '<a href="/rekawice-chemiczne-mapa-solo977.html">Rękawice chemiczne MAPA Solo 977</a>'
                .'<a href="/rekawice-chemiczne-mapa-ultranitril472.html">Rękawice MAPA Ultranitril 472</a>',
                200
            ),
            '*' => Http::response('empty', 200),
        ]);

        $product = new Product([
            'sku' => '34977068',
            'name' => 'SOLO 977',
            'manufacturer' => 'MAPA',
        ]);
        $hits = app(RetailerOnSiteSearch::class)->find($product);
        $urls = array_column($hits, 'url');

        $this->assertContains('https://icd.pl/rekawice-chemiczne-mapa-solo977.html', $urls);
        $this->assertNotContains('https://icd.pl/rekawice-chemiczne-mapa-ultranitril472.html', $urls);
    }

    public function test_mapa_keeps_matching_model_and_drops_neighbour(): void
    {
        Http::fake([
            'https://www.mapa-pro.pl/wyszukiwanie-zaawansowane*' => Http::response(
                '<h3 class="product-name"><a href="/produkty/odpornosc-na-przeciecie/prace-precyzyjne/strona-produktu/krytech-643">KryTech 643</a></h3>'
                .'<h3 class="product-name"><a href="/produkty/odpornosc-na-przeciecie/prace-precyzyjne/strona-produktu/krytech-563">KryTech 563</a></h3>',
                200
            ),
            '*' => Http::response('empty', 200),
        ]);

        $product = new Product([
            'sku' => 'KRYTECH-563-11',
            'name' => 'KRYTECH 563',
            'manufacturer' => 'MAPA',
        ]);
        $hits = app(RetailerOnSiteSearch::class)->find($product);
        $urls = array_column($hits, 'url');

        $this->assertContains(
            'https://mapa-pro.pl/produkty/odpornosc-na-przeciecie/prace-precyzyjne/strona-produktu/krytech-563',
            $urls
        );
        $this->assertNotContains(
            'https://mapa-pro.pl/produkty/odpornosc-na-przeciecie/prace-precyzyjne/strona-produktu/krytech-643',
            $urls
        );
    }
}
