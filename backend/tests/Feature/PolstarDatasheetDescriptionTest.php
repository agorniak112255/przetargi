<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Jobs\DescribeB2bProductFromDatasheetJob;
use App\Models\B2bAccount;
use App\Models\B2bProductLink;
use App\Models\Product;
use App\Services\Ai\OpenAiCompatibleClient;
use App\Services\B2b\B2bAccountSyncRunner;
use App\Services\Enrichment\HybridWebSearchService;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Mockery;
use Tests\TestCase;

/**
 * Opis karty Polstar z dwóch źródeł — opisu z pliku XML sklepu i instrukcji PDF ze strony produktu (decyzja
 * użytkownika 21.09.2026, jak u Tegro). Polstar jest witryną producenta, więc sprawdzamy też, że kolejny import
 * z tym samym tekstem sklepu nie wraca z nim na miejsce opisu od modelu. Sklep, PDF i odpowiedzi modelu są
 * SYNTETYCZNE; kolejka w testach jest synchroniczna.
 */
final class PolstarDatasheetDescriptionTest extends TestCase
{
    use RefreshDatabase;

    private const MANUAL_URL = 'https://polstar.com.pl/media/medias/download/2473';

    private const PAGE_URL = 'https://polstar.com.pl/produkt/alaska_damskie';

    private const SHOP_TEXT = "Rodzaj rękawicy: rękawica ocieplana,\nMateriał/Dzianina: w kolorze czarnym, wykonane z grubego termoweluru,";

    private const AI_TEXT = 'Rękawica ocieplana kat. I z grubego termoweluru w kolorze czarnym, zakończona ściągaczem. Chroni przed '
        .'minimalnymi urazami mechanicznymi i przed gorącymi przedmiotami do 50°C. Do lekkich prac manualnych i technicznych.';

    private string $shopValue = 'w kolorze czarnym, wykonane z grubego termoweluru,';

    /** @var \ArrayObject<int, string> */
    private \ArrayObject $prompts;

    /** @var array<string, mixed> */
    private array $answer = [];

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('public');
        $this->prompts = new \ArrayObject;
        $this->answer = [
            'description' => self::AI_TEXT,
            'features' => ['ochrona przed gorącymi przedmiotami do 50°C'],
            'specs' => ['Rozmiar 8: długość 24 cm'],
            'norms' => [],
            'certificates' => [],
            'materials' => ['termowelur'],
            'use_cases' => ['lekkie prace manualne'],
            'source_urls' => [self::MANUAL_URL],
            'confidence' => 0.9,
        ];
        $handler = function (array $messages): array {
            $this->prompts->append((string) ($messages[1]['content'] ?? ''));

            return $this->answer;
        };
        $llm = Mockery::mock(OpenAiCompatibleClient::class);
        $llm->shouldReceive('chatJsonEnrichment')->andReturnUsing($handler);
        $llm->shouldReceive('chatJson')->andReturnUsing($handler);
        $this->app->instance(OpenAiCompatibleClient::class, $llm);
        // opis z dwóch źródeł nie szuka niczego w internecie
        $search = Mockery::mock(HybridWebSearchService::class);
        $search->shouldNotReceive('searchBothPhases');
        $search->shouldNotReceive('searchWebWithoutLocalIndex');
        $this->app->instance(HybridWebSearchService::class, $search);
    }

    public function test_sync_describes_the_card_from_the_shop_text_and_the_manual_only_with_provenance(): void
    {
        $this->fakeSite();

        $this->sync();

        $this->assertCount(1, $this->prompts);
        $this->assertStringContainsString('termoweluru', $this->prompts[0]);
        $this->assertStringContainsString('temperatury przekraczającej 50', $this->prompts[0]);
        $card = $this->card();
        $this->assertSame(self::AI_TEXT, $card->description);
        $payload = (array) $card->enrichment_payload;
        $this->assertSame(self::MANUAL_URL, $payload['primary_source_url']);
        $this->assertSame(DescribeB2bProductFromDatasheetJob::PRIMARY_SOURCE_KIND, $payload['primary_source_kind']);
        $this->assertSame(self::SHOP_TEXT, $payload['b2b_sources']['shop_text']);
        $this->assertSame(self::MANUAL_URL, $payload['b2b_sources']['datasheet_url']);
        $this->assertSame(sha1(self::SHOP_TEXT), B2bProductLink::query()->where('product_id', $card->id)->value('source_description_hash'));
    }

    public function test_next_sync_of_the_manufacturer_site_keeps_the_model_description_and_asks_nothing(): void
    {
        $this->fakeSite();
        $this->sync();

        $this->sync();

        $this->assertCount(1, $this->prompts);
        $this->assertSame(self::AI_TEXT, $this->card()->description);
    }

    public function test_new_shop_text_comes_back_to_the_card_and_is_described_again(): void
    {
        $this->fakeSite();
        $this->sync();
        $this->shopValue = 'z grubego termoweluru, nowy opis w sklepie Polstar,';

        $this->sync();

        $this->assertCount(2, $this->prompts);
        $this->assertStringContainsString('nowy opis w sklepie Polstar', $this->prompts[1]);
        $card = $this->card();
        $this->assertSame(self::AI_TEXT, $card->description);
        $this->assertStringContainsString('nowy opis w sklepie Polstar', (string) $card->enrichment_payload['b2b_sources']['shop_text']);
    }

    private function sync(): void
    {
        app(B2bAccountSyncRunner::class)->run($this->account(), delayMs: 0);
    }

    private function card(): Product
    {
        return Product::query()->where('sku', 'ROAD-58')->sole();
    }

    private function account(): B2bAccount
    {
        return B2bAccount::query()->firstOrCreate(
            ['username' => 'PHT TEST'],
            ['password' => 'dobre-haslo', 'sites' => ['polstar.com.pl'], 'connector' => 'polstar', 'sync_images' => false],
        )->fresh();
    }

    /** Instrukcja w układzie PDF-ów Polstaru: jedna instrukcja na dwa artykuły. */
    private function manualPdf(): string
    {
        return Pdf::loadHTML('<html><head><meta charset="utf-8"><style>body{font-family:"DejaVu Sans";font-size:11px}</style></head><body>'
            .'<p>INSTRUKCJA UŻYTKOWANIA, PRZECHOWYWANIA I KONSERWACJI</p><p>KAT. I</p>'
            .'<p>SYMBOL I OPIS: art. ALASKA 26 cm- Rękawice wykonane z polaru w kolorze czarnym, rozmiar 10.</p>'
            .'<p>art. ALASKA 24 cm- Rękawice wykonane z polaru w kolorze czarnym, rozmiar 8.</p>'
            .'<p>ZASTOSOWANIE: Do wszechstronnego użytkowania przy pracach manualnych, technicznych, lekkich; ochrona przed '
            .'minimalnymi urazami mechanicznymi; ochrona przed zagrożeniami związanymi z manipulowaniem gorącymi przedmiotami, '
            .'nie narażającymi pracownika na działanie temperatury przekraczającej 50°C.</p>'
            .'<p>Dostępne rozmiary: 8 i 10, odpowiadające długości: 24 cm i 26 cm.</p>'
            .'</body></html>')->output();
    }

    private function fakeSite(): void
    {
        $session = false;
        Http::fake(function (Request $request) use (&$session) {
            $path = (string) parse_url($request->url(), PHP_URL_PATH);
            $account = '<a href="/wyloguj">Wyloguj się</a><a href="/katalog/rekawice">RĘKAWICE</a>';
            $xml = '<?xml version="1.0" encoding="utf-8"?><produkty><produkt><id>58</id><nazwa>ALASKA RĘKAWICZKI POLAR CZARNE DAMSKIE</nazwa>'
                .'<kod_produktu>ROAD</kod_produktu><kategorie>RĘKAWICE-&gt;OCIEPLANE</kategorie><kolekcja>ALASKA</kolekcja>'
                .'<producent>Polstar Holding Wołoszczuk sp.k.</producent><cena_detaliczna>5.40</cena_detaliczna><opis/><zdjecia/>'
                .'<opakowanie_zbiorcze>300</opakowanie_zbiorcze><normy><norma>EN ISO 21420:2020</norma></normy>'
                .'<warianty><wariant><kolor>Czarny</kolor><rozmiar>8</rozmiar><ean13>5900000000581</ean13><kod_produktu>ROAD-8</kod_produktu></wariant></warianty><cechy/>'
                .'<dane_szczegolowe><element><nazwa>..Rodzaj rękawicy:</nazwa><wartosci><wartosc>rękawica ocieplana,</wartosc></wartosci></element>'
                .'<element><nazwa>..Materiał/Dzianina:</nazwa><wartosci><wartosc>'.$this->shopValue.'</wartosc></wartosci></element></dane_szczegolowe>'
                .'</produkt></produkty>';

            return match (true) {
                $path === '/users/users/login' => Http::response('<input type="hidden" name="_csrfToken" value="t1" />'),
                $path === '/login' => (function () use (&$session, $account) {
                    $session = true;

                    return Http::response($account, 200, ['Set-Cookie' => 'CAKEPHP=s1; path=/']);
                })(),
                $path === '/customers/customers/downloadProductsList/xml' => Http::response($xml, 200, ['Content-Type' => 'application/xml; charset=UTF-8']),
                $path === '/katalog/rekawice' => Http::response($account
                    .'<div class="product-card"><a href="#" data-id="58" class="product-compare"></a>'
                    .'<div class="product-card-symbol">ROAD</div><div class="product-card-price"> 3,73&nbsp;zł <span>TWOJA CENA</span></div>'
                    .'<a href="#" class="add-to" data-slug="alaska_damskie"></a></div>'),
                $path === '/produkt/alaska_damskie' => Http::response($account
                    .'<dl class="product-info"><dt>Kategoria ochrony:</dt><dd>I</dd><dt>Pliki:</dt><dd>'
                    .'<a href="/media/medias/download/2473" class="product-file">ALASKA M,D instrukcja 2019.pdf</a></dd></dl>'),
                $path === '/media/medias/download/2473' => Http::response($this->manualPdf(), 200, ['Content-Type' => 'application/pdf']),
                default => Http::response('nieznany adres w teście', 404),
            };
        });
    }
}
