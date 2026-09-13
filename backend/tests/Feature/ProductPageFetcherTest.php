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
                .'Taśma galwanizacyjna 3M 470, 25 mm x 33 m, kod 716973. Norma EN ISO 9001.',
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
    }
}
