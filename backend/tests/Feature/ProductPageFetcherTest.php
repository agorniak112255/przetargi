<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Product;
use App\Services\Enrichment\CandidateRejection;
use App\Services\Enrichment\ProductPageFetcher;
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
}
