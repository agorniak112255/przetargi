<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Product;
use App\Services\Enrichment\CandidateRejection;
use App\Services\Enrichment\ProductPageFetcher;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * Strona po przekierowaniu (etap 3 opisów z cenników, §1.6b; audyt Bolle 08.10.2026). specshop.pl (IdoSell) odsyła
 * wycofane karty SILEXPSF i RUSHPSPSIS na kategorię „Okulary ochronne Bolle”, a pobieranie potwierdzało stronę pod
 * adresem z wyników (z naszym kodem) — opis SILEX powstał z ogólnego tekstu kategorii. Teraz lista po przekierowaniu
 * odpada od razu, a inna strona docelowa musi potwierdzić wyrób pod własnym adresem.
 */
final class ProductPageFetcherRedirectTest extends TestCase
{
    private const SILEX_CARD = 'https://www.specshop.pl/product-pol-21689-Bolle-Safety-Okulary-ochronne-Silex-Przyciemniany-SILEXPSF.html';

    private const SPECSHOP_CATEGORY = 'https://www.specshop.pl/pol_m_Okulary-ochronne-Bolle-7342.html';

    private const MAPA_CARD = 'https://www.mapa-pro.pl/produkty/chemioodporne/strona-produktu/butoflex-650';

    public function test_shop_redirect_to_category_is_a_listing_not_the_card(): void
    {
        Http::fake([
            self::SILEX_CARD => Http::response('', 301, ['Location' => self::SPECSHOP_CATEGORY]),
            self::SPECSHOP_CATEGORY => Http::response($this->categoryPage(), 200, ['Content-Type' => 'text/html; charset=UTF-8']),
            '*' => Http::response('', 404),
        ]);

        $fetched = $this->fetchSilex();

        $this->assertSame([], $fetched['pages'], 'tekst kategorii nie jest kartą SILEXPSF');
        $this->assertSame([[
            'url' => self::SILEX_CARD,
            'reason' => CandidateRejection::LISTING,
            'detail' => 'przekierowanie na listę: '.self::SPECSHOP_CATEGORY,
        ]], $fetched['rejected']);
        $this->assertSame([], $fetched['image_urls']);
    }

    public function test_redirect_remembered_with_cached_html_is_still_checked(): void
    {
        Http::fake([
            self::SILEX_CARD => Http::response('', 301, ['Location' => self::SPECSHOP_CATEGORY]),
            self::SPECSHOP_CATEGORY => Http::response($this->categoryPage(), 200, ['Content-Type' => 'text/html; charset=UTF-8']),
            '*' => Http::response('', 404),
        ]);

        $this->fetchSilex();
        $again = $this->fetchSilex();

        // drugie pobranie z pamięci podręcznej — bez sieci, a przekierowanie nadal widać
        Http::assertSentCount(2);
        $this->assertSame([], $again['pages']);
        $this->assertSame(CandidateRejection::LISTING, $again['rejected'][0]['reason'] ?? null);
    }

    public function test_redirect_to_card_with_product_code_is_accepted(): void
    {
        $short = 'https://sklep-bhp.example.pl/p/21689';
        $final = 'https://sklep-bhp.example.pl/produkt/bolle-safety-okulary-ochronne-silex-przyciemniane-silexpsf.html';
        Http::fake([
            $short => Http::response('', 301, ['Location' => $final]),
            $final => Http::response($this->silexCard(), 200, ['Content-Type' => 'text/html; charset=UTF-8']),
            '*' => Http::response('', 404),
        ]);

        $fetched = app(ProductPageFetcher::class)->fetch(
            [['url' => $short, 'title' => '', 'snippet' => '']],
            'SILEXPSF',
            1,
            [],
            $this->silex(),
        );

        $this->assertSame([$short], array_column($fetched['pages'], 'url'));
        $this->assertSame($final, $fetched['pages'][0]['final_url']);
        $this->assertSame([], $fetched['rejected']);
    }

    public function test_redirect_to_card_of_other_model_is_judged_by_its_own_address(): void
    {
        $other = 'https://www.specshop.pl/product-pol-3920-Bolle-Safety-Przylbica-spawalnicza-FLASH-FLASHV.html';
        Http::fake([
            self::SILEX_CARD => Http::response('', 301, ['Location' => $other]),
            $other => Http::response(
                '<!doctype html><html><head><title>Bolle Safety Przyłbica spawalnicza FLASH FLASHV</title></head><body>'
                .'<h1>Bolle Safety Przyłbica spawalnicza FLASH FLASHV</h1>'
                .str_repeat('<p>Przyłbica spawalnicza Bolle Safety FLASH z filtrem samościemniającym B9V, regulowanym nagłowiem '
                    .'i osłoną szyi. Pole widzenia 98 x 43 mm, zasilanie bateriami słonecznymi.</p>', 8)
                .'</body></html>',
                200,
                ['Content-Type' => 'text/html; charset=UTF-8'],
            ),
            '*' => Http::response('', 404),
        ]);

        $fetched = $this->fetchSilex();

        // adres i tytuł z wyników niosły SILEXPSF i markę — same wystarczały za potwierdzenie strony przyłbicy
        $this->assertSame([], $fetched['pages']);
        $this->assertSame(self::SILEX_CARD, $fetched['rejected'][0]['url'] ?? null);
        $this->assertContains($fetched['rejected'][0]['reason'] ?? null, [CandidateRejection::UNCONFIRMED, CandidateRejection::UNCONFIRMED_STRICT]);
    }

    public function test_short_manufacturer_address_redirecting_to_model_page_is_accepted(): void
    {
        // Skrócony adres producenta bez kodu (mapa-pro.pl/pl/) — karta potwierdza się pod adresem docelowym, tytułem
        // i treścią (ryzyko z §7 planu etapu 3).
        $short = 'https://www.mapa-pro.pl/pl/';
        Http::fake([
            $short => Http::response('', 301, ['Location' => self::MAPA_CARD]),
            self::MAPA_CARD => Http::response(
                (string) file_get_contents(base_path('tests/Fixtures/enrichment/mapa/butoflex-650-pl.html')),
                200,
                ['Content-Type' => 'text/html; charset=UTF-8'],
            ),
            '*' => Http::response('', 404),
        ]);
        $glove = new Product([
            'sku' => '34650008',
            'name' => 'BUTOFLEX 650',
            'manufacturer' => 'MAPA',
            'category' => 'Sklep - kategorie / Rękawice ochronne / Rękawice montażowe / Rękawice do olejów i cieczy',
        ]);

        $fetched = app(ProductPageFetcher::class)->fetch(
            [['url' => $short, 'title' => '', 'snippet' => '']],
            '34650008',
            1,
            ['mapa-pro.pl', 'www.mapa-pro.pl'],
            $glove,
        );

        $this->assertSame([$short], array_column($fetched['pages'], 'url'));
        $this->assertSame(self::MAPA_CARD, $fetched['pages'][0]['final_url']);
        Http::assertSent(static fn (Request $r): bool => $r->url() === self::MAPA_CARD);
    }

    /**
     * @return array<string, mixed>
     */
    private function fetchSilex(): array
    {
        return app(ProductPageFetcher::class)->fetch(
            [['url' => self::SILEX_CARD, 'title' => 'Bolle Safety Okulary ochronne Silex Przyciemniany SILEXPSF', 'snippet' => '']],
            'SILEXPSF',
            1,
            [],
            $this->silex(),
        );
    }

    private function silex(): Product
    {
        return new Product([
            'sku' => 'SILEXPSF',
            'name' => 'SILEX – Okulary ochronne przyciemniane',
            'manufacturer' => 'Bolle',
        ]);
    }

    private function categoryPage(): string
    {
        // Lista kategorii z kafelkami modeli — wśród nich nasz SILEX, więc treść i marka „potwierdzały” kartę
        return '<!doctype html><html><head><title>Okulary ochronne Bolle Safety - Specshop.pl</title></head><body>'
            .'<h1>Okulary ochronne Bolle Safety</h1>'
            .str_repeat('<p>Okulary ochronne Bolle Safety to powłoki antyodblaskowe i przeciwmgielne, regulowane noski, '
                .'możliwość montażu wkładek RX. Sprawdzą się przy pracach ogrodowych, ASG i zadaniach taktycznych.</p>', 4)
            .'<ul><li>Bolle Safety Okulary ochronne Silex Przyciemniany SILEXPSF</li>'
            .'<li>Bolle Safety Okulary ochronne Rush+ Small SILEXPSI</li>'
            .'<li>Bolle Safety Okulary ochronne SILIUM Przyciemniany SILPPSF</li></ul>'
            .'</body></html>';
    }

    private function silexCard(): string
    {
        return '<!doctype html><html><head><title>Bolle Safety Okulary ochronne SILEX przyciemniane SILEXPSF</title></head><body>'
            .'<h1>Bolle Safety Okulary ochronne SILEX przyciemniane SILEXPSF</h1>'
            .str_repeat('<p>Okulary ochronne Bolle Safety SILEX z soczewką przyciemnianą (smoke) z poliwęglanu, powłoki '
                .'Platinum odporne na zarysowania i parowanie. Oznaczenie soczewki 5-3,1 1 FT, normy EN 166, EN 172.</p>', 4)
            .'</body></html>';
    }
}
