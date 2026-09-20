<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Product;
use App\Services\Enrichment\ProductPageFetcher;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * Przetarg 1, poz. 4 (20.09.2026): karta AJ GROUP 202 miała puste normy, choć strona producenta
 * (pros.pl) podaje EN ISO 13688 i EN 343. Strona ma blok opisu, więc czytnik brał tylko jego, a ramka
 * norm stoi osobno — w formularzu koszyka, który czyszczenie wycina. Model w dopasowaniu odrzucał
 * przez to właściwą kartę za brak normy. Tak samo 14 innych kart tego producenta.
 */
final class ProductPageFetcherNormBlockTest extends TestCase
{
    use RefreshDatabase;

    private const URL = 'https://pros.pl/pl/fartuchy-wodoochronne/211-fartuch-model-202.html';

    private const DESCRIPTION = 'Elegancki i bardzo lekki fartuch przedni w ciekawym designie. Wykonany z lekkiego, '
        .'zapewniającego wygodę użytkowania, materiału powleczonego poliuretanem. Przeznaczony w szczególności dla '
        .'pracowników barów i restauracji. Praktyczna regulacja paska szyjnego. Produkt zachowuje swoje naturalne '
        .'właściwości również w niskich temperaturach.';

    public function test_norm_frame_outside_description_block_is_read(): void
    {
        $text = $this->fetchText($this->page($this->prosNormFrame()));

        $this->assertStringContainsString('Elegancki i bardzo lekki fartuch', $text);
        $this->assertStringContainsString('Normy: -50ºC, EN ISO 13688, EN 343', $text, 'dosłownie, z minusem przy temperaturze');
    }

    public function test_page_without_norm_frame_gets_no_norm_line(): void
    {
        $text = $this->fetchText($this->page(''));

        $this->assertStringContainsString('Elegancki i bardzo lekki fartuch', $text);
        $this->assertStringNotContainsString('Normy:', $text);
    }

    public function test_menu_link_and_normal_price_class_are_not_norms(): void
    {
        $menu = '<ul class="cbp-links"><li><a href="https://pros.pl/pl/content/normy-14">Normy</a></li></ul>';
        $price = '<div class="price-normal">Cena 48,00 zł, zgodność z EN 343 sprawdź w karcie</div>';

        $text = $this->fetchText($this->page($menu.$price));

        $this->assertStringNotContainsString('Normy:', $text);
    }

    public function test_shop_norm_glossary_is_not_taken_as_product_norms(): void
    {
        $items = '';
        foreach (['EN ISO 20345', 'EN ISO 20347', 'EN 13832', 'EN 388', 'EN 407', 'EN 511', 'EN 374', 'EN 166', 'EN 397', 'EN 352'] as $norm) {
            $items .= '<li>'.$norm.' — opis wymagań normy, zakres badań i oznaczenia stosowane na wyrobach tego rodzaju</li>';
        }

        $text = $this->fetchText($this->page('<ul class="normy">'.$items.'</ul>'));

        $this->assertStringNotContainsString('Normy:', $text);
    }

    private function fetchText(string $html): string
    {
        Http::fake([self::URL => Http::response($html, 200), '*' => Http::response('', 404)]);
        $product = new Product(['sku' => '202', 'name' => 'Fartuch wodoochronny 120/75 PU Poliester', 'manufacturer' => 'AJ GROUP']);

        $fetched = app(ProductPageFetcher::class)->bypassCache()->fetch(
            [['url' => self::URL, 'title' => 'Fartuch model 202', 'snippet' => '']],
            '202',
            1,
            ['pros.pl'],
            $product,
        );

        $this->assertCount(1, $fetched['pages'], 'strona producenta przyjęta jako karta produktu');

        return (string) $fetched['pages'][0]['text'];
    }

    /** Ramka norm w układzie pros.pl — wraz z osieroconymi <tr>, które tam stoją. */
    private function prosNormFrame(): string
    {
        return '<div class="title normy" role="tab"> Normy: </div>'
            .'<div id="productdaas-accordion-normy" class="content collapse show" role="tabpanel"><div class="mt-ntzr mb-3">'
            .'<tr class="odd"> </tr><tr class="even"> </tr>'
            .'<ul class="norm-values"><li id="norm-14" data-value="-50ºC"> -50ºC </li>'
            .'<li id="norm-3" data-value="EN ISO 13688"> EN ISO 13688 </li><li id="norm-4" data-value="EN 343"> EN 343 </li></ul>'
            .'<ul class="norm-images"><li><img src="/img/EN_343.png" alt="EN 343" title="EN 343" /></li></ul>'
            .'</div></div>';
    }

    private function page(string $insideCartForm): string
    {
        return '<!doctype html><html lang="pl"><head><title>Fartuch model 202 - PROS</title>'
            .'<meta property="og:title" content="Fartuch model 202"></head><body>'
            .'<nav><ul><li><a href="/pl/content/normy-14">Normy</a></li></ul></nav>'
            .'<h1>Fartuch model 202</h1><p>SKU: 202-00005-75/75</p>'
            .'<form action="/pl/koszyk" method="post" id="add-to-cart-or-refresh">'.$insideCartForm
            .'<button type="submit">Do koszyka</button></form>'
            .'<div class="product-description"><p>'.self::DESCRIPTION.'</p>'
            .'<p>Model sugerowany dla branż: gastronomia, szkoły, sklepy spożywcze, rybne, mięsne.</p></div>'
            .str_repeat('<p>Fartuch model 202 PROS — odzież wodoochronna dla gastronomii i przetwórstwa spożywczego.</p>', 12)
            .'</body></html>';
    }
}
