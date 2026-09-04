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
        $this->assertSame('ST068GM', $search->query(new Product([
            'sku' => 'WST068GM',
            'name' => 'ALFA Grey Meteorite',
            'manufacturer' => 'Whirlpool',
        ])));
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

    public function test_query_uses_catalog_code_not_generic_model_word(): void
    {
        $query = app(RetailerOnSiteSearch::class)->query(new Product([
            'sku' => 'G3175/40',
            'name' => 'Obuv TRACK',
            'manufacturer' => 'ARDON SAFETY',
        ]));

        $this->assertMatchesRegularExpression('/G\\s*3175/i', $query);
        $this->assertStringNotContainsString('TRACK', $query);
    }

    public function test_idosell_kams_finds_product_from_relative_search_hit(): void
    {
        Http::fake([
            'https://kams.com.pl*' => Http::response(
                '<a href="p6119,track-ardon-buty-do-kostki-z-nubuku-material-tekstylny-g3175-38-46.html">'
                .'TRACK Ardon - buty do kostki z nubuku + materiał tekstylny G3175 - 38-46</a>'
                .'<a href="p100,kurtka-ardon-track.html">Kurtka ARDON TRACK EN 342</a>',
                200
            ),
            '*' => Http::response('empty', 200),
        ]);

        $hits = app(RetailerOnSiteSearch::class)->find(new Product([
            'sku' => 'G3175/40',
            'name' => 'Obuv TRACK',
            'manufacturer' => 'ARDON SAFETY',
        ]));
        $urls = array_column($hits, 'url');

        $this->assertContains(
            'https://kams.com.pl/p6119,track-ardon-buty-do-kostki-z-nubuku-material-tekstylny-g3175-38-46.html',
            $urls
        );
        $this->assertNotContains('https://kams.com.pl/p100,kurtka-ardon-track.html', $urls);
    }

    public function test_misterworker_clerk_resolves_catalog_code_without_gvarant(): void
    {
        $this->fakeMisterworkerResolve(
            'https://www.misterworker.com/en/u-power/alfa-grey-meteorite-four-seasons-work-pants-st068gm/74275.html'
        );

        $hits = app(RetailerOnSiteSearch::class)->find($this->upowerAlfa());
        $urls = array_column($hits, 'url');

        $this->assertContains(
            'https://www.misterworker.com/en/u-power/alfa-grey-meteorite-four-seasons-work-pants-st068gm/74275.html',
            $urls
        );
        Http::assertNotSent(static fn ($request): bool => str_contains($request->url(), 'gvarant.pl'));
        Http::assertNotSent(static fn ($request): bool => str_contains($request->url(), '/en/search'));
    }

    public function test_misterworker_uses_config_key_when_search_html_is_forbidden(): void
    {
        config(['enrichment.misterworker_clerk_key' => 'testClerkKey123456']);
        $this->fakeMisterworkerResolve(
            '/en/u-power/alfa-grey-meteorite-four-seasons-work-pants-st068gm/74275.html',
            searchStatus: 403
        );

        $urls = array_column(app(RetailerOnSiteSearch::class)->find($this->upowerAlfa()), 'url');
        $this->assertContains(
            'https://www.misterworker.com/en/u-power/alfa-grey-meteorite-four-seasons-work-pants-st068gm/74275.html',
            $urls
        );
    }

    public function test_misterworker_accepts_relative_and_protocol_relative_location(): void
    {
        foreach ([
            'en/u-power/alfa-grey-meteorite-four-seasons-work-pants-st068gm/74275.html',
            '//www.misterworker.com/en/u-power/alfa-grey-meteorite-four-seasons-work-pants-st068gm/74275.html',
        ] as $location) {
            $this->fakeMisterworkerResolve($location);
            $urls = array_column(app(RetailerOnSiteSearch::class)->find($this->upowerAlfa()), 'url');
            $this->assertContains(
                'https://www.misterworker.com/en/u-power/alfa-grey-meteorite-four-seasons-work-pants-st068gm/74275.html',
                $urls,
                $location
            );
        }
    }

    public function test_misterworker_reads_canonical_when_product_returns_200(): void
    {
        config(['enrichment.misterworker_clerk_key' => 'testClerkKey123456']);
        Http::fake([
            'https://api.clerk.io/v2/search/search*' => Http::response(['result' => [74275]], 200),
            'https://www.misterworker.com/en/index.php?controller=product&id_product=74275' => Http::response(
                '<link rel="canonical" href="https://www.misterworker.com/en/u-power/alfa-grey-meteorite-four-seasons-work-pants-st068gm/74275.html">',
                200
            ),
            '*' => Http::response('empty', 200),
        ]);

        $urls = array_column(app(RetailerOnSiteSearch::class)->find($this->upowerAlfa()), 'url');
        $this->assertContains(
            'https://www.misterworker.com/en/u-power/alfa-grey-meteorite-four-seasons-work-pants-st068gm/74275.html',
            $urls
        );
    }

    public function test_misterworker_rejects_foreign_host_and_card_without_sku(): void
    {
        config(['enrichment.misterworker_clerk_key' => 'testClerkKey123456']);
        Http::fake([
            'https://api.clerk.io/v2/search/search*' => Http::response(['result' => [1, 74275]], 200),
            'https://www.misterworker.com/en/index.php?controller=product&id_product=1' => Http::response(
                '',
                301,
                ['Location' => 'https://evil.example/phish']
            ),
            'https://www.misterworker.com/en/index.php?controller=product&id_product=74275' => Http::response(
                '',
                301,
                ['Location' => 'https://www.misterworker.com/en/u-power/other-alfa-pants/99.html']
            ),
            '*' => Http::response('empty', 200),
        ]);

        $this->assertSame([], app(RetailerOnSiteSearch::class)->find($this->upowerAlfa()));
    }

    public function test_misterworker_uses_jina_when_cloudflare_blocks_product(): void
    {
        config(['enrichment.misterworker_clerk_key' => 'testClerkKey123456']);
        Http::fake(function ($request) {
            $url = $request->url();
            if (str_contains($url, 'api.clerk.io')) {
                return Http::response(['result' => [74275]], 200);
            }
            if (str_contains($url, 'r.jina.ai')) {
                return Http::response(
                    'Title: U-POWER ST068GM Alfa Grey Meteorite'
                    ."\n[pants](https://www.misterworker.com/en/u-power/alfa-grey-meteorite-four-seasons-work-pants-st068gm/74275.html?id_currency=1)",
                    200
                );
            }
            if (str_contains($url, 'misterworker.com')) {
                return Http::response('<title>Attention Required! | Cloudflare</title>', 403);
            }

            return Http::response('empty', 200);
        });

        $urls = array_column(app(RetailerOnSiteSearch::class)->find($this->upowerAlfa()), 'url');
        $this->assertContains(
            'https://www.misterworker.com/en/u-power/alfa-grey-meteorite-four-seasons-work-pants-st068gm/74275.html',
            $urls
        );
    }

    public function test_misterworker_resolves_at_most_three_clerk_ids(): void
    {
        config(['enrichment.misterworker_clerk_key' => 'testClerkKey123456']);
        Http::fake([
            'https://api.clerk.io/v2/search/search*' => Http::response(['result' => [1, 2, 3, 4, 5]], 200),
            'https://www.misterworker.com/en/index.php*' => Http::response('', 404),
            '*' => Http::response('empty', 200),
        ]);

        app(RetailerOnSiteSearch::class)->find($this->upowerAlfa());
        Http::assertNotSent(static fn ($request): bool => str_contains($request->url(), 'id_product=4'));
        Http::assertNotSent(static fn ($request): bool => str_contains($request->url(), 'id_product=5'));
    }

    public function test_atlas_uses_antar_shoper_searchquery(): void
    {
        Http::fake([
            'https://antar.pl/pl/searchquery/*' => Http::response(
                '<a class="prodname" href="/pl/p/Sandaly-ATLAS-SL-46-niebieski-S1-ESD/15991">'
                .'<span class="productname">Sandały ATLAS SL 46 niebieski S1 ESD</span></a>'
                .'<a href="/pl/p/Sandaly-ATLAS-SL-26-zielony-S1-ESD/15990">Sandały ATLAS SL 26 zielony S1 ESD</a>',
                200
            ),
            '*' => Http::response('empty', 200),
        ]);

        $urls = array_column(app(RetailerOnSiteSearch::class)->find($this->atlasSl46()), 'url');

        $this->assertContains('https://antar.pl/pl/p/Sandaly-ATLAS-SL-46-niebieski-S1-ESD/15991', $urls);
        $this->assertNotContains('https://antar.pl/pl/p/Sandaly-ATLAS-SL-26-zielony-S1-ESD/15990', $urls);
    }

    public function test_shop_queries_add_hyphen_variant(): void
    {
        $queries = app(RetailerOnSiteSearch::class)->shopQueries($this->atlasSl46());

        $this->assertContains('SL 46', $queries);
        $this->assertContains('SL-46', $queries);
    }

    public function test_gvs_uses_idsblast_search(): void
    {
        Http::fake([
            'https://idsblast.com/search.php*' => Http::response(
                '<a aria-label="PX5 Battery Door Assembly" href="https://idsblast.com/03-815/?searchuuid=abc&search_query=03-815">'
                .'PX5 Battery Door Assembly 03-815</a>'
                .'<a href="https://idsblast.com/03-818/">RPB 03-818 Hinge</a>',
                200
            ),
            '*' => Http::response('empty', 200),
        ]);

        $urls = array_column(app(RetailerOnSiteSearch::class)->find(new Product([
            'sku' => '03-815',
            'name' => 'GVS Battery Door Assembly with Battery Door Hinge 03-818 for PX5',
            'manufacturer' => 'GVS',
        ])), 'url');

        $this->assertContains('https://idsblast.com/03-815/', $urls);
        $this->assertNotContains('https://idsblast.com/03-818/', $urls);
    }

    public function test_sordin_uses_customguns_woo_search(): void
    {
        Http::fake([
            'https://customguns.pl/?s=*' => Http::response(
                '<a href="https://customguns.pl/produkt/aktywne-ochronniki-sluchu-supreme-pro-x-nakarkowe-zielone-76302-x-g-s/">'
                .'Aktywne ochronniki słuchu Supreme Pro X Nakarkowe zielone 76302-X-G-S</a>'
                .'<a href="https://customguns.pl/produkt/pelttor-sporttac-oslona/">Ochronniki Peltor SportTac</a>',
                200
            ),
            '*' => Http::response('empty', 200),
        ]);

        $urls = array_column(app(RetailerOnSiteSearch::class)->find(new Product([
            'sku' => '76302-X-G-S',
            'name' => 'Aktywne ochronniki słuchu Supreme Pro X Nakarkowe zielone',
            'manufacturer' => 'Sordin',
        ])), 'url');

        $this->assertContains(
            'https://customguns.pl/produkt/aktywne-ochronniki-sluchu-supreme-pro-x-nakarkowe-zielone-76302-x-g-s/',
            $urls
        );
        $this->assertNotContains(
            'https://customguns.pl/produkt/pelttor-sporttac-oslona/',
            $urls
        );
    }

    public function test_portwest_fallback_uses_sklep_system(): void
    {
        Http::fake([
            'https://sklep-system.pl/?s=*' => Http::response(
                '<a href="/produkt/ogrodniczki-robocze-portwest-tx39-bremen">Ogrodniczki robocze Portwest TX39 BREMEN</a>'
                .'<a href="/produkt/softshell-portwest-tx40">Softshell Portwest TX40</a>',
                200
            ),
            '*' => Http::response('empty', 200),
        ]);

        $urls = array_column(app(RetailerOnSiteSearch::class)->find(new Product([
            'sku' => 'TX39',
            'name' => 'Ogrodniczki Bremen',
            'manufacturer' => 'Portwest',
        ])), 'url');

        $this->assertContains('https://sklep-system.pl/produkt/ogrodniczki-robocze-portwest-tx39-bremen', $urls);
        $this->assertNotContains('https://sklep-system.pl/produkt/softshell-portwest-tx40', $urls);
    }

    public function test_shopify_title_from_slug_when_anchor_is_empty(): void
    {
        Http::fake([
            'https://novarlo.com/search*' => Http::response(
                '<a href="/products/rpb-03-815-px5-battery-door-assembly?_pos=1&_sid=abc&_ss=r"></a>',
                200
            ),
            '*' => Http::response('empty', 200),
        ]);

        $urls = array_column(app(RetailerOnSiteSearch::class)->find(new Product([
            'sku' => '03-815',
            'name' => 'GVS Battery Door Assembly with Battery Door Hinge 03-818 for PX5',
            'manufacturer' => 'GVS',
        ])), 'url');

        $this->assertContains(
            'https://novarlo.com/products/rpb-03-815-px5-battery-door-assembly',
            $urls
        );
    }

    private function atlasSl46(): Product
    {
        return new Product([
            'sku' => 'SL-46',
            'name' => 'SL 46 S1 ESD',
            'manufacturer' => 'Atlas',
        ]);
    }

    private function upowerAlfa(): Product
    {
        return new Product([
            'sku' => 'WST068GM',
            'name' => 'ALFA Grey Meteorite',
            'manufacturer' => 'Whirlpool',
        ]);
    }

    private function fakeMisterworkerResolve(string $location, int $searchStatus = 200): void
    {
        config(['enrichment.misterworker_clerk_key' => 'testClerkKey123456']);
        Http::fake([
            'https://www.misterworker.com/en/search*' => Http::response('blocked', $searchStatus),
            'https://api.clerk.io/v2/search/search*' => Http::response([
                'status' => 'ok',
                'result' => [74275],
            ], 200),
            'https://www.misterworker.com/en/index.php?controller=product&id_product=74275' => Http::response(
                '',
                301,
                ['Location' => $location]
            ),
            '*' => Http::response('empty', 200),
        ]);
    }
}
