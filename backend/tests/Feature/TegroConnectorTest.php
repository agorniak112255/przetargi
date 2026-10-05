<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\B2bAccount;
use App\Models\B2bProductLink;
use App\Models\B2bSyncRun;
use App\Models\Product;
use App\Models\ProductDocument;
use App\Models\ProductIdentifier;
use App\Models\ProductShopCard;
use App\Models\ProductSourcePrice;
use App\Models\ProductVariant;
use App\Services\B2b\B2bAccountSyncRunner;
use App\Services\B2b\B2bConnectorRegistry;
use App\Services\B2b\B2bFatalException;
use App\Services\B2b\B2bManufacturerSite;
use App\Services\B2b\B2bRemoteDocument;
use App\Services\B2b\B2bRemoteIdentifier;
use App\Services\B2b\B2bRemoteProduct;
use App\Services\B2b\B2bRemoteShopField;
use App\Services\B2b\TegroB2bClient;
use App\Services\B2b\TegroB2bConnector;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use RuntimeException;
use Tests\TestCase;

/**
 * Łącznik b2b.tegro.pl (SolEx B2B) na atrapie sklepu (Http::fake, bez prawdziwego logowania). Układ odpowiedzi
 * jak w sklepie 21.09.2026: formularz /logowanie, stan sesji w JSON strony głównej („isLoggedIn”), API /api3
 * z HTTP 401 bez sesji, plik XML oferty z adresami stron, tabelka „Parametry produktu” i „Załączniki” na stronie.
 * Wszystkie dane (pozycje, ceny, EAN-y, strony) są SYNTETYCZNE.
 */
final class TegroConnectorTest extends TestCase
{
    use RefreshDatabase;

    private const JPEG = "\xFF\xD8\xFF\xE0\x00\x10JFIF\x00\x01syntetyczny\xFF\xD9";

    private const F09 = 'RĘKAWICE G-REX F09 PLUS';

    private const F09_PAGE = 'https://b2b.tegro.pl/pl/rekawice-g-rex-f09-plus-6';

    /** @var list<array<string, mixed>> */
    private array $items = [];

    /** @var list<string> */
    private array $validSessions = [];

    private int $logins = 0;

    /** Wszystkie sesje wygasają przy pierwszym zapytaniu listy (null = nigdy). */
    private bool $dropSessionsOnList = false;

    /** API odmawia każdej sesji (konto bez dostępu do API). */
    private bool $apiAlwaysUnauthorized = false;

    private bool $offerXmlFails = false;

    private ?int $countOverride = null;

    private int $pageHits = 0;

    /** @var array<string, int> pobrania strony wg ścieżki */
    private array $pageHitsByPath = [];

    /** @var array<string, string|null> ścieżka strony → wartość wiersza „Rozmiar” (null = bez wiersza); brak = „6-11” */
    private array $pageSizeRows = [];

    /** @var list<string> ścieżki stron odpowiadające HTTP 500 */
    private array $failingPages = [];

    protected function setUp(): void
    {
        parent::setUp();

        $this->items = [
            // lista sklepu nie jest ułożona wg rozmiaru
            $this->item(4635, 'F09 PLUS 7', self::F09.' 7', self::F09, 5.1, 6.0, '5900000000007'),
            // jak w sklepie 21.09.2026: najmniejszy rozmiar bez opisu, pozostałe z opisem
            $this->item(4634, 'F09 PLUS 6', self::F09.' 6', self::F09, 5.1, 6.0, '5900000000006', ['Description' => '']),
            $this->item(4638, 'F09 PLUS 10', self::F09.' 10', self::F09, 5.1, 6.0, '5900000000010'),
            // ten sam model, rozmiar w innej cenie — od 28.09.2026 jedna karta z ceną każdego rozmiaru
            $this->item(2001, 'HEAVY 9', 'RĘKAWICE RS ARBEITSSCHUTZ HEAVY 9', 'RĘKAWICE RS ARBEITSSCHUTZ HEAVY', 5.9, 6.24, '5900000000109'),
            $this->item(2000, 'HEAVY 8', 'RĘKAWICE RS ARBEITSSCHUTZ HEAVY 8', 'RĘKAWICE RS ARBEITSSCHUTZ HEAVY', 6.24, 6.24, '5900000000108'),
            // bez modelu; normy także w atrybutach API
            $this->item(1921, 'ALASKA 10', 'RĘKAWICE RS ARBEITSSCHUTZ ALASKA 10', null, 7.5, 9.0, '5900000000210', [
                'Attributes' => [['Id' => 135, 'Name' => 'Normy', 'Features' => [['Id' => 1, 'Name' => 'EN 388:2016(3122X), EN 511:2006(X1X)']]]],
                'Brand' => 'RS',
            ]),
            // nazwa nie jest „Model rozmiar” — bez zgadywania, osobna karta
            $this->item(3001, 'ODD 8', 'RĘKAWICE INNA NAZWA 8', 'RĘKAWICE ODD', 2.0, 2.5, ''),
            // bez ceny konta
            $this->item(3002, 'NOPRICE 9', 'RĘKAWICE NOPRICE 9', 'RĘKAWICE NOPRICE', 0.0, 3.0, ''),
        ];
    }

    public function test_login_posts_the_shop_form_and_confirms_the_session_on_the_home_page(): void
    {
        $this->fakeSite();
        $client = $this->client();

        $client->login();

        $this->assertTrue($client->isLoggedIn());
        $post = Http::recorded(fn (Request $r): bool => $r->method() === 'POST')->first()[0];
        $this->assertSame('https://b2b.tegro.pl/logowanie', $post->url());
        $this->assertSame(['Uzytkownik' => 'jan@example.com', 'Haslo' => 'dobre-haslo', 'logowanie' => ''], $post->data());
        $home = Http::recorded(fn (Request $r): bool => $r->url() === 'https://b2b.tegro.pl/pl/home')->first()[0];
        $this->assertStringContainsString('.ASPXAUTH=auth1', $home->header('Cookie')[0] ?? '');
    }

    public function test_wrong_password_fails_with_clear_message_although_the_shop_answers_http_200(): void
    {
        $this->fakeSite();
        $client = new TegroB2bClient('jan@example.com', 'zle-haslo', 0, static function (int $ms): void {});

        try {
            $client->login();
            $this->fail('Logowanie powinno się nie udać');
        } catch (RuntimeException $e) {
            $this->assertNotInstanceOf(B2bFatalException::class, $e);
            $this->assertSame('Logowanie do b2b.tegro.pl nieudane: sklep nie potwierdził zalogowania — sprawdź login i hasło', $e->getMessage());
        }
        $this->assertFalse($client->isLoggedIn());
    }

    /**
     * Do 28.09.2026 (decyzja 15.09.2026) rozmiar modelu w innej cenie był osobną kartą (HEAVY 8 i HEAVY 9). Od decyzji
     * użytkownika 28.09.2026 rozmiary jednego modelu w różnych cenach to jedna karta z ceną każdego rozmiaru. Kod karty
     * od decyzji właściciela 28.09.2026 = kod modelu bez rozmiaru („F09 PLUS”, „HEAVY”; dawniej kod najmniejszego
     * rozmiaru „F09 PLUS 6”, „HEAVY 8”) — kody rozmiarów zostają przy pozycjach i identyfikatorach.
     */
    public function test_sizes_of_one_model_are_one_card_also_when_their_prices_differ(): void
    {
        $this->fakeSite();

        $products = $this->productsBySku($this->connector());

        // ALASKA 10 bez modelu, strona z zakresem „6-11” — 10 mieści się w zakresie, kod bez rozmiaru (decyzja 28.09.2026)
        $this->assertSame(['F09 PLUS', 'HEAVY', 'ALASKA', 'ODD 8', 'NOPRICE 9'], array_keys($products));
        $f09 = $products['F09 PLUS'];
        $this->assertSame('4634', $f09->remoteId);
        $this->assertSame(self::F09, $f09->name);
        $this->assertSame('RĘKAWICE DZIANE POWLEKANE PIANĄ', $f09->category);
        $this->assertSame(self::F09_PAGE, $f09->sourceUrl);
        $this->assertSame('Rozmiary: 6 (F09 PLUS 6); 7 (F09 PLUS 7); 10 (F09 PLUS 10)', $f09->variantSummary);
        $this->assertSame(
            [
                ['4634', 'F09 PLUS 6', self::F09.' 6', '6', 5.1, 6.0, 'PLN'],
                ['4635', 'F09 PLUS 7', self::F09.' 7', '7', 5.1, 6.0, 'PLN'],
                ['4638', 'F09 PLUS 10', self::F09.' 10', '10', 5.1, 6.0, 'PLN'],
            ],
            self::members($f09),
        );
        // EAN i kod Tegro każdego rozmiaru, przy jego pozycji (Id z API)
        $this->assertSame(
            [
                ['ean', '5900000000006', '4634', '6', 'Ean'],
                ['source_code', 'F09 PLUS 6', '4634', '6', 'Sku'],
                ['ean', '5900000000007', '4635', '7', 'Ean'],
                ['source_code', 'F09 PLUS 7', '4635', '7', 'Sku'],
                ['ean', '5900000000010', '4638', '10', 'Ean'],
                ['source_code', 'F09 PLUS 10', '4638', '10', 'Sku'],
            ],
            array_map(static fn (B2bRemoteIdentifier $i): array => [$i->type, $i->value, $i->remoteId, $i->label, $i->field], $f09->identifiers ?? []),
        );
        // pozycja bez EAN w sklepie — sam kod, bez wymyślonego EAN
        $this->assertSame(
            [['source_code', 'ODD 8', '3001']],
            array_map(static fn (B2bRemoteIdentifier $i): array => [$i->type, $i->value, $i->remoteId], $products['ODD 8']->identifiers ?? []),
        );

        // rozmiary w dwóch cenach: jedna karta z nazwą i kodem modelu, pozycja wiodąca = najmniejszy rozmiar, cena i cena
        // katalogowa każdego rozmiaru przy jego pozycji, cena karty = najtańszy rozmiar
        $heavy = $products['HEAVY'];
        $this->assertSame('2000', $heavy->remoteId);
        $this->assertSame('RĘKAWICE RS ARBEITSSCHUTZ HEAVY', $heavy->name);
        $this->assertSame('Rozmiary: 8 (HEAVY 8); 9 (HEAVY 9)', $heavy->variantSummary);
        $this->assertSame(
            [
                ['2000', 'HEAVY 8', 'RĘKAWICE RS ARBEITSSCHUTZ HEAVY 8', '8', 6.24, 6.24, 'PLN'],
                ['2001', 'HEAVY 9', 'RĘKAWICE RS ARBEITSSCHUTZ HEAVY 9', '9', 5.9, 6.24, 'PLN'],
            ],
            self::members($heavy),
        );
        $price = $this->connector()->price($heavy);
        $this->assertSame(5.9, $price?->net);
        $this->assertSame(6.24, $price->base);

        // pojedyncza pozycja: pełna nazwa ze sklepu, bez listy rozmiarów i bez members
        $this->assertSame('RĘKAWICE INNA NAZWA 8', $products['ODD 8']->name);
        $this->assertSame([], $products['ODD 8']->members);
        $this->assertNull($products['ODD 8']->variantSummary);
    }

    /**
     * Remis ceny konta rozmiarów: cenę karty daje rozmiar ze znaną ceną katalogową (B2bCatalogSync::winsSizePriceTie),
     * nie pierwszy rozmiar bez niej.
     */
    public function test_equal_size_prices_take_the_size_with_a_known_catalog_price(): void
    {
        $this->items = [
            $this->item(6001, 'TIE 8', 'RĘKAWICE TIE 8', 'RĘKAWICE TIE', 5.0, 0.0, ''),
            $this->item(6002, 'TIE 9', 'RĘKAWICE TIE 9', 'RĘKAWICE TIE', 5.0, 6.5, ''),
        ];
        $this->fakeSite();
        $connector = $this->connector();

        $products = $this->productsBySku($connector);

        $this->assertSame([null, 6.5], array_map(static fn (array $m): ?float => $m['price']->base, $products['TIE']->members));
        $price = $connector->price($products['TIE']);
        $this->assertSame(5.0, $price?->net);
        $this->assertSame(6.5, $price->base);
    }

    /**
     * Cena rozmiaru nie dzieli karty, ale jednostka i waluta tak: rozmiar sprzedawany na opakowania albo w innej walucie
     * to nie rozmiar tej samej karty (synchronizacja nie porównuje jednostek).
     */
    public function test_sizes_in_another_unit_or_currency_stay_separate_cards(): void
    {
        $this->items = [
            $this->item(5001, 'UNIT 8', 'RĘKAWICE UNIT 8', 'RĘKAWICE UNIT', 5.0, 6.0, ''),
            $this->item(5002, 'UNIT 9', 'RĘKAWICE UNIT 9', 'RĘKAWICE UNIT', 5.5, 6.0, ''),
            $this->item(5003, 'UNIT 10', 'RĘKAWICE UNIT 10', 'RĘKAWICE UNIT', 50.0, 60.0, '', ['Unit' => 'opak']),
            $this->item(5004, 'UNIT 11', 'RĘKAWICE UNIT 11', 'RĘKAWICE UNIT', 1.5, 2.0, '', [
                'PriceAfterDiscountNet' => ['Value' => 1.5, 'Currency' => 'EUR'],
                'RetailPriceNet' => ['Value' => 2.0, 'Currency' => 'EUR'],
            ]),
        ];
        $this->fakeSite();
        $connector = $this->connector();

        $products = $this->productsBySku($connector);

        $this->assertSame(['UNIT 8', 'UNIT 10', 'UNIT 11'], array_keys($products));
        $this->assertSame(['UNIT 8', 'UNIT 9'], array_column($products['UNIT 8']->members, 'sku'));
        $this->assertSame(5.0, $connector->price($products['UNIT 8'])?->net);
        $this->assertSame([], $products['UNIT 10']->members);
        $this->assertSame(50.0, $connector->price($products['UNIT 10'])?->net);
        $this->assertSame('EUR', $connector->price($products['UNIT 11'])?->currency);
        // kod modelu „UNIT” wyszedłby na trzech kartach — wszystkie zostają z kodem i nazwą pozycji (bez zgadywania)
        $this->assertSame(
            ['UNIT 8' => 'RĘKAWICE UNIT', 'UNIT 10' => 'RĘKAWICE UNIT 10', 'UNIT 11' => 'RĘKAWICE UNIT 11'],
            array_map(static fn (B2bRemoteProduct $p): string => $p->cardName ?? $p->name, $products),
        );
        $this->assertContains(
            'Kod albo nazwa bez rozmiaru wspólne dla kilku kart — te karty zostają z kodem i nazwą pozycji: kod „UNIT”: UNIT 8, UNIT 10, UNIT 11',
            $connector->runSummary(),
        );
    }

    /**
     * Decyzja właściciela 28.09.2026: kod karty = kod modelu bez rozmiaru, także przy jednym rozmiarze z modelem (nazwa =
     * Model, lista rozmiarów z jednym rozmiarem). Kod pozycji, który nie jest „X rozmiar” z jednym X dla całej karty —
     * jak dotąd kod najmniejszego rozmiaru.
     */
    public function test_card_code_is_the_model_code_without_the_size_only_when_every_size_code_follows_it(): void
    {
        $this->items = [
            $this->item(7001, 'CITRIN 7', 'RĘKAWICE RS ARBEITSSCHUTZ CITRIN 7', 'RĘKAWICE RS ARBEITSSCHUTZ CITRIN', 3.0, 4.0, '5900000000707'),
            // kod bez spacji przed rozmiarem
            $this->item(7101, 'MIX-7', 'RĘKAWICE MIX 7', 'RĘKAWICE MIX', 3.0, 4.0, ''),
            $this->item(7102, 'MIX 8', 'RĘKAWICE MIX 8', 'RĘKAWICE MIX', 3.0, 4.0, ''),
            // dwa różne kody modelu w jednej karcie
            $this->item(7201, 'AAA 8', 'RĘKAWICE DUO 8', 'RĘKAWICE DUO', 3.0, 4.0, ''),
            $this->item(7202, 'BBB 9', 'RĘKAWICE DUO 9', 'RĘKAWICE DUO', 3.0, 4.0, ''),
        ];
        $this->fakeSite();

        $products = $this->productsBySku($this->connector());

        $this->assertSame(['CITRIN', 'MIX-7', 'AAA 8'], array_keys($products));
        $citrin = $products['CITRIN'];
        // nazwa nowej karty = Model (cardName); nazwa pozycji dosłownie zostaje dla powiązania
        $this->assertSame('RĘKAWICE RS ARBEITSSCHUTZ CITRIN', $citrin->cardName);
        $this->assertSame('RĘKAWICE RS ARBEITSSCHUTZ CITRIN 7', $citrin->name);
        $this->assertSame('Rozmiary: 7 (CITRIN 7)', $citrin->variantSummary);
        $this->assertSame([], $citrin->members);
        $this->assertSame(
            [['ean', '5900000000707', '7001', '7'], ['source_code', 'CITRIN 7', '7001', '7']],
            array_map(static fn (B2bRemoteIdentifier $i): array => [$i->type, $i->value, $i->remoteId, $i->label], $citrin->identifiers ?? []),
        );
        $this->assertSame('RĘKAWICE MIX', $products['MIX-7']->name);
        $this->assertNull($products['MIX-7']->cardName);
        $this->assertSame(['MIX-7', 'MIX 8'], array_column($products['MIX-7']->members, 'sku'));
        $this->assertSame('RĘKAWICE DUO', $products['AAA 8']->name);
        // pozycja z modelem nie czyta strony (rozmiar jest w Modelu)
        $this->assertSame(0, $this->pageHits);
    }

    /**
     * Pozycja z pustym Modelem (u Tegro 18 z 455): rozmiar tylko z wiersza „Rozmiar” strony produktu. Pojedyncza wartość,
     * na którą kończy się nazwa, albo liczba na końcu nazwy z zakresu liczbowego wiersza („6-11” przy „SPLIT 10”) schodzi
     * z nazwy i z kodu („POLAR I” — kod bez rozmiaru zostaje); liczba spoza zakresu, zakres literowy, brak wiersza, inna
     * wartość niż końcówka nazwy albo błąd strony — kod i nazwa jak w sklepie. Strona pobrana raz na kartę.
     */
    public function test_item_without_model_takes_its_single_size_from_the_page_row(): void
    {
        $this->items = [
            $this->item(8001, 'COMFORT PREMIUM 10', 'RĘKAWICE RS ARBEITSSCHUTZ COMFORT PREMIUM 10', null, 4.0, 5.0, '5900000000810'),
            $this->item(8002, 'POLAR I', 'RĘKAWICE RS ARBEITSSCHUTZ POLAR I 10', '', 4.0, 5.0, ''),
            // strona z zakresem „6-11” (domyślna), a nazwa kończy się liczbą z zakresu — rozmiar schodzi (decyzja 28.09.2026)
            $this->item(8003, 'SPLIT 10', 'RĘKAWICE RS ARBEITSSCHUTZ SPLIT 10', null, 4.0, 5.0, ''),
            // liczba spoza zakresu „6-11” i zakres literowy — bez zgadywania
            $this->item(8007, 'LUWAC 12', 'RĘKAWICE RS ARBEITSSCHUTZ LUWAC 12', null, 4.0, 5.0, ''),
            $this->item(8008, 'BUFFALO XL', 'RĘKAWICE RS ARBEITSSCHUTZ BUFFALO XL', null, 4.0, 5.0, ''),
            // strona bez wiersza „Rozmiar”
            $this->item(8004, 'BUDGIE 11', 'RĘKAWICE RS ARBEITSSCHUTZ BUDGIE 11', null, 4.0, 5.0, ''),
            // wiersz „Rozmiar” = 9, a nazwa kończy się na 10 — bez zgadywania
            $this->item(8005, 'DRUM 10', 'RĘKAWICE RS ARBEITSSCHUTZ DRUM 10', null, 4.0, 5.0, ''),
            // strona nie odpowiada
            $this->item(8006, 'ZIRKON 800 10', 'RĘKAWICE RS ARBEITSSCHUTZ ZIRKON 800 10', null, 4.0, 5.0, ''),
        ];
        $this->pageSizeRows = [
            '/pl/rekawice-comfort-premium-10' => '10',
            '/pl/rekawice-polar-i' => '10',
            '/pl/rekawice-budgie-11' => null,
            '/pl/rekawice-drum-10' => '9',
            '/pl/rekawice-zirkon-800-10' => '10',
            '/pl/rekawice-buffalo-xl' => 'S-XXL',
        ];
        $this->failingPages = ['/pl/rekawice-zirkon-800-10'];
        $this->fakeSite();
        $connector = $this->connector();

        $products = $this->productsBySku($connector);

        $this->assertSame(['COMFORT PREMIUM', 'POLAR I', 'SPLIT', 'LUWAC 12', 'BUFFALO XL', 'BUDGIE 11', 'DRUM 10', 'ZIRKON 800 10'], array_keys($products));
        $this->assertSame('RĘKAWICE RS ARBEITSSCHUTZ SPLIT', $products['SPLIT']->cardName);
        $this->assertSame('Rozmiary: 10 (SPLIT 10)', $products['SPLIT']->variantSummary);
        $comfort = $products['COMFORT PREMIUM'];
        $this->assertSame('RĘKAWICE RS ARBEITSSCHUTZ COMFORT PREMIUM', $comfort->cardName);
        $this->assertSame('RĘKAWICE RS ARBEITSSCHUTZ COMFORT PREMIUM 10', $comfort->name);
        $this->assertSame('Rozmiary: 10 (COMFORT PREMIUM 10)', $comfort->variantSummary);
        $this->assertSame([], $comfort->members);
        // kod pozycji w identyfikatorach dosłownie, z rozmiarem ze strony
        $this->assertSame(
            [['ean', '5900000000810', '10'], ['source_code', 'COMFORT PREMIUM 10', '10']],
            array_map(static fn (B2bRemoteIdentifier $i): array => [$i->type, $i->value, $i->label], $comfort->identifiers ?? []),
        );
        $this->assertSame('RĘKAWICE RS ARBEITSSCHUTZ POLAR I', $products['POLAR I']->cardName);
        $this->assertSame('Rozmiary: 10 (POLAR I)', $products['POLAR I']->variantSummary);
        foreach (['LUWAC 12', 'BUFFALO XL', 'BUDGIE 11', 'DRUM 10', 'ZIRKON 800 10'] as $sku) {
            $this->assertSame('RĘKAWICE RS ARBEITSSCHUTZ '.$sku, $products[$sku]->name);
            $this->assertNull($products[$sku]->cardName);
            $this->assertNull($products[$sku]->variantSummary);
            $this->assertSame([null], array_map(static fn (B2bRemoteIdentifier $i): ?string => $i->label, $products[$sku]->identifiers ?? []));
        }
        $this->assertSame([
            'Lista Tegro: 8 pozycji → 8 kart (0 grup rozmiarów, w tym 0 z rozmiarami w różnych cenach — jedna karta, cena karty = najniższa cena rozmiaru, ceny rozmiarów w tabeli rozmiarów karty)',
            'Pozycje bez modelu ze stroną produktu: 8, rozmiar z wiersza „Rozmiar” strony: 3 — ich kod i nazwa karty bez rozmiaru',
            'Strona produktu pozycji bez modelu nie została pobrana (1): ZIRKON 800 10 (b2b.tegro.pl odpowiedziało HTTP 500) — kod i nazwa tych kart zostają z rozmiarem',
        ], $connector->runSummary());

        // tabelka i pliki karty czytają stronę pobraną w products() — bez drugiego zapytania
        $this->assertSame(1, $this->pageHitsByPath['/pl/rekawice-comfort-premium-10']);
        $rows = array_map(static fn (B2bRemoteShopField $f): string => $f->name.' | '.$f->value, $connector->shopFields($comfort));
        $this->assertContains('Rozmiar | 10', $rows);
        $this->assertNotSame([], $connector->documents($comfort));
        $this->assertSame(1, $this->pageHitsByPath['/pl/rekawice-comfort-premium-10']);
    }

    /**
     * Kod albo nazwa bez rozmiaru, które wyszłyby na dwóch kartach przebiegu: obie karty zostają z kodem i nazwą sprzed
     * zmiany (nie zgadujemy, która jest właściwa).
     */
    public function test_code_or_name_shared_after_removing_the_size_keeps_the_shop_values(): void
    {
        $this->items = [
            // kod modelu „ZIRKON” to już kod innej pozycji tej listy
            $this->item(9001, 'ZIRKON 7', 'RĘKAWICE ZIRKON 7', 'RĘKAWICE ZIRKON', 3.0, 4.0, ''),
            $this->item(9002, 'ZIRKON', 'RĘKAWICE ZIRKON STARY', 'RĘKAWICE ZIRKON STARY', 3.0, 4.0, ''),
            // nazwa bez rozmiaru pozycji bez modelu = nazwa modelu innej karty
            $this->item(9101, 'DRUM 10', 'RĘKAWICE DRUM 10', null, 3.0, 4.0, ''),
            $this->item(9102, 'DRM 8', 'RĘKAWICE DRUM 8', 'RĘKAWICE DRUM', 3.0, 4.0, ''),
            $this->item(9103, 'DRM 9', 'RĘKAWICE DRUM 9', 'RĘKAWICE DRUM', 3.0, 4.0, ''),
            // bez kolizji — kod modelu
            $this->item(9201, 'BASS 7', 'RĘKAWICE BASS 7', 'RĘKAWICE BASS', 3.0, 4.0, ''),
        ];
        $this->pageSizeRows = ['/pl/rekawice-drum-10' => '10'];
        $this->fakeSite();
        $connector = $this->connector();

        $products = $this->productsBySku($connector);

        $this->assertSame(
            ['ZIRKON 7' => 'RĘKAWICE ZIRKON 7', 'ZIRKON' => 'RĘKAWICE ZIRKON STARY', 'DRUM 10' => 'RĘKAWICE DRUM 10', 'DRM 8' => 'RĘKAWICE DRUM', 'BASS' => 'RĘKAWICE BASS'],
            array_map(static fn (B2bRemoteProduct $p): string => $p->cardName ?? $p->name, $products),
        );
        // rozmiar ze sklepu zostaje przy karcie — wraca tylko kod i nazwa
        $this->assertSame('Rozmiary: 10 (DRUM 10)', $products['DRUM 10']->variantSummary);
        $this->assertContains(
            'Kod albo nazwa bez rozmiaru wspólne dla kilku kart — te karty zostają z kodem i nazwą pozycji: kod „ZIRKON”: ZIRKON 7, ZIRKON; nazwa „RĘKAWICE DRUM”: DRUM 10, DRM 8',
            $connector->runSummary(),
        );
    }

    /**
     * Nowe karty przez synchronizację: kod i nazwa bez rozmiaru, lista rozmiarów na karcie, jedno pobranie strony.
     */
    public function test_sync_creates_cards_with_the_model_code_and_the_size_in_the_size_summary(): void
    {
        Storage::fake('public');
        $this->items = [
            $this->item(7001, 'CITRIN 7', 'RĘKAWICE RS ARBEITSSCHUTZ CITRIN 7', 'RĘKAWICE RS ARBEITSSCHUTZ CITRIN', 3.0, 4.0, ''),
            $this->item(8001, 'COMFORT PREMIUM 10', 'RĘKAWICE RS ARBEITSSCHUTZ COMFORT PREMIUM 10', null, 4.0, 5.0, ''),
        ];
        $this->pageSizeRows = ['/pl/rekawice-comfort-premium-10' => '10'];
        $this->fakeSite();

        $result = app(B2bAccountSyncRunner::class)->run($this->account(), delayMs: 0);

        $this->assertSame(2, $result['created'], implode(' | ', $result['errors']));
        $citrin = Product::query()->where('sku', 'CITRIN')->sole();
        $this->assertSame('RĘKAWICE RS ARBEITSSCHUTZ CITRIN', $citrin->name);
        $this->assertSame('Rozmiary: 7 (CITRIN 7)', $citrin->variant_summary);
        $comfort = Product::query()->where('sku', 'COMFORT PREMIUM')->sole();
        $this->assertSame('RĘKAWICE RS ARBEITSSCHUTZ COMFORT PREMIUM', $comfort->name);
        $this->assertSame('Rozmiary: 10 (COMFORT PREMIUM 10)', $comfort->variant_summary);
        // powiązanie ma nazwę pozycji dosłownie (cardName tylko na kartę); kod powiązania pojedynczej karty synchronizacja
        // bierze z kodu karty (B2bCatalogSync) — kod pozycji dosłownie zostaje w identyfikatorze
        $this->assertSame(['COMFORT PREMIUM', 'RĘKAWICE RS ARBEITSSCHUTZ COMFORT PREMIUM 10'], [
            B2bProductLink::query()->where('product_id', $comfort->id)->value('remote_sku'),
            B2bProductLink::query()->where('product_id', $comfort->id)->value('remote_name'),
        ]);
        $this->assertSame(
            [['COMFORT PREMIUM 10', '10']],
            ProductIdentifier::query()->where('product_id', $comfort->id)->where('type', ProductIdentifier::TYPE_SOURCE_CODE)->get()
                ->map(static fn (ProductIdentifier $i): array => [$i->value, $i->variant_label])->all(),
        );
        $this->assertTrue(ProductShopCard::query()->where('product_id', $comfort->id)->exists());
        $this->assertSame(1, $this->pageHitsByPath['/pl/rekawice-comfort-premium-10']);
    }

    public function test_price_is_the_account_price_with_catalog_price_and_no_invented_discount(): void
    {
        $this->fakeSite();
        $connector = $this->connector();
        $products = $this->productsBySku($connector);

        $price = $connector->price($products['F09 PLUS']);
        $this->assertNotNull($price);
        $this->assertSame(5.1, $price->net);
        $this->assertSame(6.0, $price->base);
        $this->assertSame(0.0, $price->discountPercent);
        $this->assertSame('PLN', $price->currency);

        $this->assertNull($connector->price($products['NOPRICE 9']));
        $this->assertSame('Rękawica ochronna kat. II, nitryl.'."\n".'Druga linia opisu.', $connector->description($products['F09 PLUS']));
        $this->assertSame('G-REX', $connector->manufacturer($products['F09 PLUS']));
        $this->assertSame('Rękawica ochronna kat. II, nitryl.'."\n".'Druga linia opisu.', $connector->description($products['F09 PLUS']));
    }

    public function test_required_box_makes_the_box_quantity_the_minimum_and_the_step(): void
    {
        $this->items = [
            // CITRIN 7 jak w sklepie 05.10.2026: „Ilość w op. zbiorczym 12 para. Sprzedajemy wyłącznie wielokrotności tej liczby.”
            $this->item(1927, 'CITRIN 7', 'RĘKAWICE RS ARBEITSSCHUTZ CITRIN 7', 'RĘKAWICE RS ARBEITSSCHUTZ CITRIN', 1.75, 2.0, ''),
            $this->item(1928, 'CITRIN 8', 'RĘKAWICE RS ARBEITSSCHUTZ CITRIN 8', 'RĘKAWICE RS ARBEITSSCHUTZ CITRIN', 1.75, 2.0, ''),
            // bez wymogu opakowania (QuantityPerBox puste) — bez ograniczenia
            $this->item(2100, 'RACER 7', 'RĘKAWICE RS ARBEITSSCHUTZ RACER 7', 'RĘKAWICE RS ARBEITSSCHUTZ RACER', 3.0, 3.5, '', ['RequiredBox' => false, 'QuantityPerBox' => null]),
            // rozmiary w różnych opakowaniach — warunek zależy od rozmiaru
            $this->item(2200, 'MIX 8', 'RĘKAWICE MIX 8', 'RĘKAWICE MIX', 4.0, 4.5, ''),
            $this->item(2201, 'MIX 9', 'RĘKAWICE MIX 9', 'RĘKAWICE MIX', 4.0, 4.5, '', ['QuantityPerBox' => 6]),
            // wymagane opakowanie bez liczby i rozmiar bez pola — warunku nie znamy
            $this->item(2300, 'NOBOX 8', 'RĘKAWICE NOBOX 8', 'RĘKAWICE NOBOX', 4.0, 4.5, '', ['QuantityPerBox' => null]),
            $this->item(2400, 'PART 8', 'RĘKAWICE PART 8', 'RĘKAWICE PART', 4.0, 4.5, ''),
            $this->item(2401, 'PART 9', 'RĘKAWICE PART 9', 'RĘKAWICE PART', 4.0, 4.5, '', ['RequiredBox' => null]),
        ];
        $this->fakeSite();
        $connector = $this->connector();
        $products = $this->productsBySku($connector);

        $this->assertSame(
            ['order_min_qty' => 12.0, 'order_step_qty' => 12.0, 'order_unit' => 'para', 'order_varies' => false],
            $connector->price($products['CITRIN'])?->order?->slotValues(),
        );
        $racer = $connector->price($products['RACER'])?->order;
        $this->assertNotNull($racer);
        $this->assertFalse($racer->restricts());
        $this->assertSame(['order_min_qty' => 1.0, 'order_step_qty' => null, 'order_unit' => 'para', 'order_varies' => false], $racer->slotValues());
        $this->assertSame(
            ['order_min_qty' => null, 'order_step_qty' => null, 'order_unit' => 'para', 'order_varies' => true],
            $connector->price($products['MIX'])?->order?->slotValues(),
        );
        $this->assertNotNull($connector->price($products['NOBOX']));
        $this->assertNull($connector->price($products['NOBOX'])->order);
        $this->assertNull($connector->price($products['PART'])?->order);
    }

    public function test_sync_saves_the_order_condition_on_the_account_slot(): void
    {
        Storage::fake('public');
        $this->fakeSite();

        app(B2bAccountSyncRunner::class)->run($this->account(), delayMs: 0);

        $f09 = Product::query()->where('sku', 'F09 PLUS')->sole();
        $slot = ProductSourcePrice::query()->where('product_id', $f09->id)->where('source_key', ProductSourcePrice::b2bKey((int) $this->account()->id))->sole();
        $this->assertSame(12.0, (float) $slot->order_min_qty);
        $this->assertSame(12.0, (float) $slot->order_step_qty);
        $this->assertSame('para', $slot->order_unit);
        $this->assertFalse((bool) $slot->order_varies);
    }

    public function test_shop_card_has_page_parameters_norms_and_trade_data_of_every_size(): void
    {
        $this->fakeSite();
        $connector = $this->connector();
        $products = $this->productsBySku($connector);
        // od 28.09.2026 products() pobiera stronę pozycji bez modelu (ALASKA 10, wiersz „Rozmiar”) — liczymy od tego miejsca
        $hitsBefore = $this->pageHits;

        $rows = array_map(
            static fn (B2bRemoteShopField $f): string => $f->section.' | '.$f->name.' | '.$f->value,
            $connector->shopFields($products['F09 PLUS']),
        );

        $this->assertSame([
            'Parametry produktu | Nazwa produktu | RĘKAWICE G-REX F09 PLUS',
            'Parametry produktu | Pakowanie (wiązka/karton) | 12/144',
            'Parametry produktu | Rozmiar | 6-11',
            'Parametry produktu | KodCN | 61161020',
            'Parametry produktu | Normy | EN ISO 21420:2020;EN 388:2016+A1:2018;EN 407:2020',
            'Informacje handlowe | Marka | G-REX',
            'Informacje handlowe | Kategoria | RĘKAWICE DZIANE POWLEKANE PIANĄ',
            'Informacje handlowe | Jednostka | para',
            'Informacje handlowe | Kod towaru | F09 PLUS 6',
            'Informacje handlowe | EAN | 5900000000006 (F09 PLUS 6)',
            'Informacje handlowe | Kod towaru | F09 PLUS 7',
            'Informacje handlowe | EAN | 5900000000007 (F09 PLUS 7)',
            'Informacje handlowe | Kod towaru | F09 PLUS 10',
            'Informacje handlowe | EAN | 5900000000010 (F09 PLUS 10)',
        ], $rows);
        // tabelka i pliki czytają tę samą stronę
        $connector->documents($products['F09 PLUS']);
        $this->assertSame(1, $this->pageHits - $hitsBefore);
    }

    public function test_norms_from_the_api_are_kept_once_when_the_page_repeats_them(): void
    {
        $this->fakeSite();
        $connector = $this->connector();
        $products = $this->productsBySku($connector);

        $norms = array_values(array_filter(
            $connector->shopFields($products['ALASKA']),
            static fn (B2bRemoteShopField $f): bool => $f->name === 'Normy',
        ));

        $this->assertCount(1, $norms);
        $this->assertSame('EN 388:2016(3122X), EN 511:2006(X1X)', $norms[0]->value);
    }

    public function test_documents_put_the_polish_datasheet_first_and_ignore_files_outside_the_shop(): void
    {
        $this->fakeSite();
        $connector = $this->connector();
        $products = $this->productsBySku($connector);

        $documents = $connector->documents($products['F09 PLUS']);

        $this->assertSame([
            ['g-rex-f09-plus-karta-katalogowa-pl.pdf', 'https://b2b.tegro.pl/zasoby/import/g/g-rex-f09-plus-karta-katalogowa-pl.pdf', ProductDocument::KIND_DATASHEET],
            ['f09-plus-instrukcja.pdf', 'https://b2b.tegro.pl/zasoby/import/f/f09-plus-instrukcja.pdf', ProductDocument::KIND_MANUAL],
            ['g-rex-f09-plus-product-card.pdf', 'https://b2b.tegro.pl/zasoby/import/g/g-rex-f09-plus-product-card.pdf', ProductDocument::KIND_DATASHEET],
            ['g-rex-f09-plus-deklaracja.pdf', 'https://b2b.tegro.pl/zasoby/import/g/g-rex-f09-plus-deklaracja.pdf', ProductDocument::KIND_CERTIFICATE],
            ['v03.pdf', 'https://b2b.tegro.pl/zasoby/import/v/v03.pdf', ProductDocument::KIND_OTHER],
        ], array_map(static fn (B2bRemoteDocument $d): array => [$d->title, $d->sourceUrl, $d->kind], $documents));

        $this->expectException(RuntimeException::class);
        $connector->documentBytes(new B2bRemoteDocument('obcy.pdf', 'https://example.test/obcy.pdf'));
    }

    public function test_image_is_the_full_size_photo_without_the_thumbnail_preset(): void
    {
        $this->fakeSite();
        $connector = $this->connector();
        $products = $this->productsBySku($connector);

        $image = $connector->image($products['F09 PLUS']);

        $this->assertNotNull($image);
        $this->assertSame('https://b2b.tegro.pl/zasoby/import/f/f09-plus.jpg', $image->sourceUrl);
        $this->assertSame('image/jpeg', $image->mime);
        $this->assertSame(self::JPEG, $image->bytes);
    }

    public function test_expired_session_logs_in_again_once(): void
    {
        $this->dropSessionsOnList = true;
        $this->fakeSite();

        $products = $this->productsBySku($this->connector());

        // 8 pozycji = 5 kart (rozmiary HEAVY w dwóch cenach to od 28.09.2026 jedna karta)
        $this->assertCount(5, $products);
        $this->assertSame(2, $this->logins);
    }

    public function test_api_refusing_a_fresh_session_is_fatal(): void
    {
        $this->apiAlwaysUnauthorized = true;
        $this->fakeSite();

        $this->expectException(B2bFatalException::class);
        $this->expectExceptionMessage('Utracono sesję konta b2b.tegro.pl');
        iterator_to_array($this->connector()->products(), false);
    }

    public function test_incomplete_list_is_an_error_not_a_partial_price_list(): void
    {
        $this->countOverride = 500;
        $this->fakeSite();

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('API b2b.tegro.pl zwróciło niepełną listę produktów (8 z 500)');
        iterator_to_array($this->connector()->products(), false);
    }

    public function test_without_the_offer_file_prices_still_come_and_the_run_summary_says_what_is_missing(): void
    {
        $this->offerXmlFails = true;
        $this->fakeSite();
        $connector = $this->connector();

        $products = $this->productsBySku($connector);

        $this->assertNull($products['F09 PLUS']->sourceUrl);
        $this->assertSame([], $connector->documents($products['F09 PLUS']));
        $this->assertSame(0, $this->pageHits);
        $this->assertSame([
            'Lista Tegro: 8 pozycji → 5 kart (2 grup rozmiarów, w tym 1 z rozmiarami w różnych cenach — jedna karta, cena karty = najniższa cena rozmiaru, ceny rozmiarów w tabeli rozmiarów karty)',
            'Plik oferty XML nie został pobrany (b2b.tegro.pl odpowiedziało HTTP 500) — karty bez tabelki parametrów i plików PDF',
        ], $connector->runSummary());
    }

    public function test_sync_through_runner_creates_cards_links_shop_cards_and_files(): void
    {
        Storage::fake('public');
        $this->fakeSite();

        $result = app(B2bAccountSyncRunner::class)->run($this->account(), delayMs: 0);

        // HEAVY 8 i 9 (dwie ceny) = jedna karta od 28.09.2026
        $this->assertSame(5, $result['total_remote']);
        $this->assertSame(4, $result['created']);
        $this->assertContains('NOPRICE 9: brak ceny w B2B', $result['errors']);

        $f09 = Product::query()->where('sku', 'F09 PLUS')->sole();
        $this->assertSame(self::F09, $f09->name);
        $this->assertSame('G-REX', $f09->manufacturer);
        $this->assertSame('Rozmiary: 6 (F09 PLUS 6); 7 (F09 PLUS 7); 10 (F09 PLUS 10)', $f09->variant_summary);
        $this->assertFalse(Product::query()->whereIn('sku', ['F09 PLUS 6', 'F09 PLUS 7', 'F09 PLUS 10'])->exists());
        $this->assertSame(
            ['4634' => self::F09.' 6', '4635' => self::F09.' 7', '4638' => self::F09.' 10'],
            B2bProductLink::query()->where('product_id', $f09->id)->orderBy('remote_id')->pluck('remote_name', 'remote_id')->all(),
        );
        $slot = ProductSourcePrice::query()->where('product_id', $f09->id)->where('source_key', ProductSourcePrice::b2bKey((int) $this->account()->id))->sole();
        $this->assertSame('5.10', (string) $slot->purchase_price);

        $heavy = Product::query()->where('sku', 'HEAVY')->sole();
        $this->assertSame('RĘKAWICE RS ARBEITSSCHUTZ HEAVY', $heavy->name);
        $this->assertFalse(Product::query()->whereIn('sku', ['HEAVY 8', 'HEAVY 9'])->exists());
        $heavySlot = ProductSourcePrice::query()->where('product_id', $heavy->id)->sole();
        // cena karty = najtańszy rozmiar (9) z jego ceną katalogową, najwyższa cena rozmiaru przy slocie
        $this->assertSame('5.90', (string) $heavySlot->purchase_price);
        $this->assertSame('6.24', (string) $heavySlot->catalog_price_net);
        $this->assertSame('6.24', (string) $heavySlot->size_price_max);
        $this->assertSame(
            [['8', '6.24', '6.24'], ['9', '5.90', '6.24']],
            ProductVariant::query()->where('product_id', $heavy->id)->where('kind', ProductVariant::KIND_SIZE)->orderBy('sort_order')->get()
                ->map(static fn (ProductVariant $v): array => [$v->label, (string) $v->purchase_price, (string) $v->list_price_net])->all(),
        );
        $this->assertNull($slot->size_price_max);

        $this->assertTrue(ProductShopCard::query()->where('product_id', $f09->id)->exists());
        $this->assertStringContainsString('EN 388:2016+A1:2018', (string) $f09->fresh()?->shop_fields_summary);
        $this->assertSame(
            ['g-rex-f09-plus-karta-katalogowa-pl.pdf', 'f09-plus-instrukcja.pdf', 'g-rex-f09-plus-product-card.pdf', 'g-rex-f09-plus-deklaracja.pdf', 'v03.pdf'],
            ProductDocument::query()->where('product_id', $f09->id)->orderBy('sort_order')->pluck('title')->all(),
        );

        $log = array_column((array) B2bSyncRun::query()->latest('id')->firstOrFail()->log, 'text');
        $this->assertContains('Lista Tegro: 8 pozycji → 5 kart (2 grup rozmiarów, w tym 1 z rozmiarami w różnych cenach — jedna karta, cena karty = najniższa cena rozmiaru, ceny rozmiarów w tabeli rozmiarów karty)', $log);
    }

    public function test_second_run_on_the_same_cards_creates_nothing_and_keeps_description_and_files(): void
    {
        Storage::fake('public');
        $this->fakeSite();
        app(B2bAccountSyncRunner::class)->run($this->account(), delayMs: 0);
        $f09 = Product::query()->where('sku', 'F09 PLUS')->sole();
        $description = (string) $f09->description;
        $documents = ProductDocument::query()->where('product_id', $f09->id)->count();
        $pdfDownloads = count(Http::recorded(fn (Request $r): bool => str_ends_with((string) parse_url($r->url(), PHP_URL_PATH), '.pdf')));

        $second = app(B2bAccountSyncRunner::class)->run($this->account(), delayMs: 0);

        $this->assertSame(0, $second['created']);
        $this->assertSame(4, Product::query()->count());
        $this->assertSame('Rękawica ochronna kat. II, nitryl.'."\n".'Druga linia opisu.', $description);
        $this->assertSame($description, (string) $f09->fresh()?->description);
        $this->assertSame($documents, ProductDocument::query()->where('product_id', $f09->id)->count());
        $this->assertSame(3, B2bProductLink::query()->where('product_id', $f09->id)->count());
        // identyfikatory rozmiarów zapisane raz, drugi przebieg ich nie dubluje ani nie oznacza jako zniknięte
        $this->assertSame(6, ProductIdentifier::query()->where('product_id', $f09->id)->count());
        $this->assertSame(0, ProductIdentifier::query()->whereNotNull('removed_at')->count());
        // pliki, które karta już ma, nie są pobierane drugi raz
        $this->assertSame($pdfDownloads, count(Http::recorded(fn (Request $r): bool => str_ends_with((string) parse_url($r->url(), PHP_URL_PATH), '.pdf'))));
    }

    /**
     * Karty sprzed 28.09.2026 (HEAVY 8 i HEAVY 9 — rozmiary w różnych cenach jako osobne karty): przebieg po zmianie
     * nie zakłada nowej karty (karty grupy znalezione po powiązaniach rozmiarów; kod wyrobu od 28.09.2026 to „HEAVY”, nie
     * kod żadnej z dawnych kart — synchronizacja nie zmienia kodu zastanej karty), nie przepina
     * powiązań i nie zmienia cen kart; każda karta dostaje swój rozmiar, a wyrób trafia do size_spread przebiegu.
     */
    public function test_legacy_price_split_cards_stay_and_get_their_own_sizes(): void
    {
        Storage::fake('public');
        $this->items = array_values(array_filter($this->items, static fn (array $i): bool => in_array($i['Id'], [2000, 2001], true)));
        $this->fakeSite();
        $account = $this->account();
        $small = $this->legacyCard($account, 'HEAVY 8', 'RĘKAWICE RS ARBEITSSCHUTZ HEAVY 8', 6.24, '2000');
        $large = $this->legacyCard($account, 'HEAVY 9', 'RĘKAWICE RS ARBEITSSCHUTZ HEAVY 9', 5.9, '2001');

        $result = app(B2bAccountSyncRunner::class)->run($account, delayMs: 0);

        $this->assertSame(0, $result['created'], implode(' | ', $result['errors']));
        $this->assertSame(0, $result['skipped'], implode(' | ', $result['errors']));
        $this->assertSame(2, Product::query()->count());
        $this->assertSame([$small->id], B2bProductLink::query()->where('remote_id', '2000')->pluck('product_id')->map(static fn (mixed $id): int => (int) $id)->all());
        $this->assertSame([$large->id], B2bProductLink::query()->where('remote_id', '2001')->pluck('product_id')->map(static fn (mixed $id): int => (int) $id)->all());
        $this->assertSame('RĘKAWICE RS ARBEITSSCHUTZ HEAVY 8', $small->fresh()?->name);
        // kodu zastanej karty synchronizacja nie zmienia (tylko products:repair-tegro-codes)
        $this->assertSame(['HEAVY 8', 'HEAVY 9'], [$small->fresh()?->sku, $large->fresh()?->sku]);
        $this->assertSame('6.24', (string) ProductSourcePrice::query()->where('product_id', $small->id)->value('purchase_price'));
        $this->assertSame('5.90', (string) ProductSourcePrice::query()->where('product_id', $large->id)->value('purchase_price'));
        $this->assertSame(['8'], ProductVariant::query()->where('product_id', $small->id)->pluck('label')->all());
        $this->assertSame(['9'], ProductVariant::query()->where('product_id', $large->id)->pluck('label')->all());
        $spread = B2bSyncRun::query()->findOrFail($result['sync_run_id'])->size_spread;
        $this->assertSame(1, $spread['total']);
        $this->assertSame([$small->id, $large->id], $spread['groups'][0]['cards']);
    }

    /**
     * Karta zapisana przez dawny podział według ceny (do 28.09.2026): kod i nazwa pozycji, jej powiązanie i slot konta.
     */
    private function legacyCard(B2bAccount $account, string $sku, string $name, float $price, string $remoteId): Product
    {
        $card = Product::query()->create([
            'sku' => $sku, 'name' => $name, 'manufacturer' => 'G-REX', 'description' => 'Rękawica ochronna kat. II, nitryl.',
            'catalog_price_net' => 6.24, 'discount_percent' => 0, 'purchase_price' => $price, 'currency' => 'PLN',
        ]);
        B2bProductLink::query()->create([
            'b2b_account_id' => $account->id, 'remote_id' => $remoteId, 'product_id' => $card->id,
            'remote_sku' => $sku, 'remote_name' => $name, 'manufacturer' => 'G-REX',
            'last_purchase_price' => $price, 'last_currency' => 'PLN',
        ]);
        ProductSourcePrice::query()->create([
            'product_id' => $card->id, 'source_key' => ProductSourcePrice::b2bKey((int) $account->id), 'b2b_account_id' => $account->id,
            'catalog_price_net' => 6.24, 'purchase_price' => $price, 'discount_percent' => 0, 'currency' => 'PLN',
            'checked_at' => now()->subDay(),
        ]);

        return $card;
    }

    public function test_registry_detects_tegro_by_host_and_it_is_not_a_manufacturer_site(): void
    {
        $registry = app(B2bConnectorRegistry::class);

        $this->assertSame('tegro', $registry->keyForSites(['https://b2b.tegro.pl/logowanie']));
        $this->assertSame('Tegro', $registry->label('tegro'));
        $this->assertTrue($registry->requiresPassword('tegro'));
        $account = B2bAccount::query()->create(['username' => 'jan@example.com', 'password' => 'sekret', 'sites' => ['https://b2b.tegro.pl/']]);
        $connector = $registry->make($account, 0);
        $this->assertInstanceOf(TegroB2bConnector::class, $connector);
        $this->assertNotInstanceOf(B2bManufacturerSite::class, $connector);
        $this->assertSame('Tegro', $connector->manufacturer(new B2bRemoteProduct('1', 'X', 'X')));
    }

    private function client(): TegroB2bClient
    {
        return new TegroB2bClient('jan@example.com', 'dobre-haslo', 0, static function (int $ms): void {});
    }

    private function connector(): TegroB2bConnector
    {
        $connector = new TegroB2bConnector($this->client());
        $connector->login();

        return $connector;
    }

    private function account(): B2bAccount
    {
        return B2bAccount::query()->firstOrCreate(
            ['username' => 'jan@example.com'],
            ['password' => 'dobre-haslo', 'sites' => ['https://b2b.tegro.pl/'], 'connector' => 'tegro', 'sync_images' => false],
        )->fresh();
    }

    /**
     * Pozycje karty z ceną rozmiaru: [remote_id, sku, name, size, net, base, currency].
     *
     * @return list<list<mixed>>
     */
    private static function members(B2bRemoteProduct $product): array
    {
        return array_map(static fn (array $m): array => [
            $m['remote_id'], $m['sku'], $m['name'], $m['size'] ?? null,
            $m['price']?->net, $m['price']?->base, $m['price']?->currency,
        ], $product->members);
    }

    /**
     * @return array<string, B2bRemoteProduct>
     */
    private function productsBySku(TegroB2bConnector $connector): array
    {
        $out = [];
        foreach ($connector->products() as $product) {
            $out[$product->sku] = $product;
        }

        return $out;
    }

    /**
     * Pozycja listy API (ApiProduct) w kształcie ze sklepu.
     *
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    private function item(int $id, string $sku, string $name, ?string $model, float $net, float $retail, string $ean, array $overrides = []): array
    {
        return [
            'Id' => $id,
            'Name' => $name,
            'Ean' => $ean,
            'Sku' => $sku,
            'Description' => "Rękawica ochronna kat. II, nitryl.\r\n  Druga linia   opisu. ",
            'Model' => $model,
            'Brand' => 'G-REX',
            'Unit' => 'para',
            // jak u 420 z 455 pozycji sklepu 05.10.2026: tylko wielokrotności opakowania zbiorczego 12 par
            'RequiredBox' => true,
            'QuantityPerBox' => 12,
            'Vat' => 23,
            'InStock' => true,
            'RetailPriceNet' => ['Value' => $retail, 'Currency' => 'PLN'],
            'PriceAfterDiscountNet' => ['Value' => $net, 'Currency' => 'PLN'],
            'Attributes' => [],
            'Categories' => [['Id' => '8562462340266848137', 'Name' => 'RĘKAWICE DZIANE POWLEKANE PIANĄ', 'ParentId' => null, 'GroupId' => 1]],
            'Photo' => '/zasoby/import/f/f09-plus.jpg?preset=ico80x80wp&quality=30',
            ...$overrides,
        ];
    }

    private function offerXml(): string
    {
        $products = '';
        foreach ($this->items as $item) {
            $slug = 'rekawice-'.strtolower(str_replace(' ', '-', (string) $item['Sku']));
            if ($item['Id'] === 4634) {
                $slug = 'rekawice-g-rex-f09-plus-6';
            }
            $products .= '<product addedDate="19.10.2023 12:34:25"><ean><![CDATA['.$item['Ean'].']]></ean><id>'.$item['Id'].'</id>'
                .'<sku><![CDATA['.$item['Sku'].']]></sku><url><![CDATA[https://b2b.tegro.pl/pl/'.$slug.']]></url></product>';
        }

        return "<?xml version='1.0' encoding='utf-8' standalone='yes' ?>\r\n<products elments=\"".count($this->items).'" lang="pl" version="3">'.$products.'</products>';
    }

    private static function productPage(): string
    {
        return <<<'HTML'
            <!DOCTYPE html><html lang="pl"><head><meta charset="utf-8"><title>RĘKAWICE G-REX F09 PLUS - Platforma SolexB2B</title></head><body>
            <div id="kontrolka-167" class="kontrolka-PoleProduktu col-12"><div class="pole-opis-wartosc"><span class="wartosc"><a href="/pl/g-rex"><img src="/zasoby/obrazki/marki/G-rex.png?preset=producent" alt="G-REX/"></a></span></div></div>
            <div id="kontrolka-174" class="kontrolka-PoleProduktu col-12"><div class="naglowek-kontrolki"><div><h4 class="naglowek">Parametry produktu</h4></div></div>
              <div class="row"><div class="col-6"> Nazwa produktu </div><div class="col-6"> RĘKAWICE G-REX F09 PLUS </div></div></div>
            <div id="kontrolka-153" class="kontrolka-PoleProduktu col-12">
              <div class="row"><div class="col-6"> Pakowanie (wiązka/karton) </div><div class="col-6"><a>12/144</a></div></div>
              <div class="row"><div class="col-6"> Rozmiar </div><div class="col-6"><a>6-11</a></div></div></div>
            <div id="kontrolka-232" class="kontrolka-PoleProduktu col-12"><div class="row"><div class="col-6"> KodCN </div><div class="col-6"><a>61161020</a></div></div></div>
            <div id="kontrolka-223" class="kontrolka-PoleProduktu col-12 mt-2 mb-3"><div class="pole-opis-wartosc"><span class="wartosc"> Normy:EN ISO 21420:2020;EN 388:2016+A1:2018;EN 407:2020 </span></div></div>
            <div id="kontrolka-112" class="kontrolka-PoleProduktu col-12"><div class="naglowek-kontrolki"><h4 class="naglowek">Opis</h4></div>
              <div class="pole-opis-wartosc"><span class="wartosc"> Uwaga: opis nie jest wierszem tabelki. </span></div></div>
            <div id="kontrolka-155" class="kontrolka-ZalacznikiDoPropduktu col-12"><h4 class="naglowek">Załączniki</h4><div class="pliki-do-produktu">
              <div><a href="/zasoby/import/f/f09-plus-instrukcja.pdf" download><img src="/zasoby/obrazki/ikony_plikow/pdf.png"/> f09-plus-instrukcja.pdf </a></div>
              <div><a href="/zasoby/import/g/g-rex-f09-plus-deklaracja.pdf" download> g-rex-f09-plus-deklaracja.pdf </a></div>
              <div><a href="/zasoby/import/g/g-rex-f09-plus-karta-katalogowa-pl.pdf" download> g-rex-f09-plus-karta-katalogowa-pl.pdf </a></div>
              <div><a href="/zasoby/import/g/g-rex-f09-plus-product-card.pdf" download> g-rex-f09-plus-product-card.pdf </a></div>
              <div><a href="/zasoby/import/v/v03.pdf" download> v03.pdf </a></div>
              <div><a href="https://example.test/obcy.pdf" download> obcy.pdf </a></div>
            </div></div>
            </body></html>
            HTML;
    }

    private static function homePage(bool $loggedIn): string
    {
        return '<html><body><script id="s-a-p" type="application/json">{"recaptchaSiteKey":null,"langSymbol":"pl","isLoggedIn":'
            .($loggedIn ? 'true' : 'false').',"urlToLoginPage":"https://b2b.tegro.pl/pl/logowanie"}</script></body></html>';
    }

    private function fakeSite(): void
    {
        Http::fake(function (Request $request) {
            $url = $request->url();
            $path = (string) parse_url($url, PHP_URL_PATH);
            preg_match('/\.ASPXAUTH=(auth\d+)/', $request->header('Cookie')[0] ?? '', $m);
            $loggedIn = isset($m[1]) && in_array($m[1], $this->validSessions, true);

            if ($path === '/pl/logowanie') {
                return Http::response('<form method="POST" action="https://b2b.tegro.pl/logowanie"></form>', 200, [
                    'Set-Cookie' => 'ASP.NET_SessionId=anon; path=/; HttpOnly',
                ]);
            }
            if ($path === '/logowanie' && $request->method() === 'POST') {
                // jak sklep: złe dane = ta sama strona logowania z HTTP 200, bez ciasteczka konta
                if (($request->data()['Uzytkownik'] ?? null) !== 'jan@example.com' || ($request->data()['Haslo'] ?? null) !== 'dobre-haslo') {
                    return Http::response('<form>Nieprawidłowy login lub hasło</form>', 200);
                }
                $this->logins++;
                $this->validSessions[] = 'auth'.$this->logins;

                return Http::response(self::homePage(true), 200, ['Set-Cookie' => '.ASPXAUTH=auth'.$this->logins.'; path=/; HttpOnly']);
            }
            if ($path === '/pl/home') {
                return Http::response(self::homePage($loggedIn));
            }
            if ($path === '/api3/product/findProduct') {
                if ($this->dropSessionsOnList && $this->logins === 1) {
                    $this->validSessions = [];
                    $loggedIn = false;
                }
                if (! $loggedIn || $this->apiAlwaysUnauthorized) {
                    return Http::response('"Użytkownik niezalogowany nie ma dostępu do wywołania akcji"', 401, ['Content-Type' => 'application/json']);
                }
                parse_str((string) parse_url($url, PHP_URL_QUERY), $query);
                if (($query['field'] ?? '') !== TegroB2bClient::PRODUCT_FIELDS) {
                    return Http::response(['Message' => 'nieoczekiwane pola'], 400);
                }

                return Http::response([
                    'Count' => $this->countOverride ?? count($this->items),
                    'PageSize' => 2147483647,
                    'PageNumber' => 1,
                    'HasMore' => false,
                    'CollectionType' => 'Product',
                    'Items' => $this->items,
                ]);
            }
            if ($path === '/pl/xmlapi/1/3/UTF8') {
                if ($this->offerXmlFails) {
                    return Http::response('błąd', 500);
                }

                return $loggedIn
                    ? Http::response($this->offerXml(), 200, ['Content-Type' => 'text/xml'])
                    : Http::response('"Użytkownik niezalogowany"', 401);
            }
            if (str_starts_with($path, '/pl/rekawice-')) {
                $this->pageHits++;
                $this->pageHitsByPath[$path] = ($this->pageHitsByPath[$path] ?? 0) + 1;
                if (in_array($path, $this->failingPages, true)) {
                    return Http::response('błąd', 500);
                }
                $page = self::productPage();
                if (array_key_exists($path, $this->pageSizeRows)) {
                    $row = '<div class="row"><div class="col-6"> Rozmiar </div><div class="col-6"><a>6-11</a></div></div>';
                    $size = $this->pageSizeRows[$path];
                    $page = str_replace($row, $size === null ? '' : str_replace('6-11', $size, $row), $page);
                }
                if ($path === '/pl/rekawice-alaska-10') {
                    // jak w sklepie: wiersz „Normy” strony ma to samo brzmienie co atrybut API
                    $page = str_replace('EN ISO 21420:2020;EN 388:2016+A1:2018;EN 407:2020', 'EN 388:2016(3122X), EN 511:2006(X1X)', $page);
                }

                return Http::response($page, 200, ['Content-Type' => 'text/html; charset=utf-8']);
            }
            if (str_starts_with($path, '/zasoby/import/') && str_ends_with($path, '.jpg')) {
                return Http::response(self::JPEG, 200, ['Content-Type' => 'image/jpeg']);
            }
            if (str_starts_with($path, '/zasoby/import/') && str_ends_with($path, '.pdf')) {
                // każdy plik z inną treścią — zapis plików rozpoznaje powtórzony plik po sumie kontrolnej
                return Http::response("%PDF-1.4\n".$path."\n%%EOF", 200, ['Content-Type' => 'application/pdf']);
            }

            return Http::response('nieznany adres w teście: '.$url, 404);
        });
    }
}
