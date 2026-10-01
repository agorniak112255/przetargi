<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\B2bAccount;
use App\Models\B2bProductLink;
use App\Models\Product;
use App\Models\ProductDocument;
use App\Models\ProductIdentifier;
use App\Models\ProductImage;
use App\Models\ProductShopCard;
use App\Models\ProductSourcePrice;
use App\Models\ProductVariant;
use App\Services\B2b\B2bAccountSyncRunner;
use App\Services\B2b\B2bConnectorRegistry;
use App\Services\B2b\B2bDocumentSource;
use App\Services\B2b\B2bFatalException;
use App\Services\B2b\B2bGroupsSizes;
use App\Services\B2b\B2bImageGallery;
use App\Services\B2b\B2bManufacturerSite;
use App\Services\B2b\B2bRemoteDocument;
use App\Services\B2b\B2bRemoteProduct;
use App\Services\B2b\B2bShopFieldNormSource;
use App\Services\B2b\B2bShopFieldSource;
use App\Services\B2b\B2bSizePriceSource;
use App\Services\B2b\EltenB2bClient;
use App\Services\B2b\EltenB2bConnector;
use GuzzleHttp\Promise\PromiseInterface;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use RuntimeException;
use Tests\TestCase;

/**
 * Łącznik b2b.elten.com na atrapie sklepu i witryny elten.com (Http::fake). Kształt stron odwzorowuje zalogowane konto
 * #26 z 01.10.2026: strona logowania z _csrf_token, formularz username/partnr/password, gość = HTTP 401, język z flagi
 * w nagłówku (?switch_locale=en), lista /search?&pg=N (liczba artykułów w nagłówku, strona za ostatnią oddaje ostatnią
 * jeszcze raz), strona artykułu /detail/{id} (cechy feature-name/feature-value, „List price” i „Purchase price”,
 * data-article z ceną konta rozmiaru o trzech miejscach po przecinku i ilościami tygodni, galeria /cache/bilder
 * tylko z sesją), mapa elten.com /portfolio-sitemap*.xml i polska strona wyrobu (tytuł „… - {numer} - ELTEN GmbH”,
 * zakładki „Nasza opinia”, „Details” z cudzym tekstem, „Orto / wkładek”, przyciski PDF / ADT / CE).
 *
 * Wszystkie dane (numery, ceny, nazwy, teksty) są SYNTETYCZNE.
 */
final class EltenConnectorTest extends TestCase
{
    use RefreshDatabase;

    private const USER = '10001';

    private const PASSWORD = 'dobre-haslo';

    private const TOKEN = 'csrf-abc.123';

    /** Artykułów na stronę listy (prawdziwy sklep: 24). */
    private const PER_PAGE = 3;

    /** @var array<string, array<string, mixed>> id strony → artykuł */
    private array $articles = [];

    /** @var array<string, array{title: string, opinion: list<string>, pdf: string|null}> numer elten.com → polska strona */
    private array $polish = [];

    /** @var list<string> ważne sesje */
    private array $sessions = [];

    private int $logins = 0;

    /** @var list<array<string, string>> */
    private array $loginForms = [];

    /** Sesja wygasa po tylu stronach konta (0 = nigdy). */
    private int $expireAfterPages = 0;

    private int $pages = 0;

    /** Sklep odmawia każdej sesji (logowanie się udaje). */
    private bool $sessionRefused = false;

    /** Sklep nie przełącza języka (zostaje niemiecki). */
    private bool $stuckInGerman = false;

    /** Polskie strony elten.com odpowiadają HTTP 500. */
    private bool $sitePagesDown = false;

    /** Polskie strony elten.com odpowiadają HTTP 410 (wyrób zdjęty z witryny). */
    private bool $sitePagesGone = false;

    /** Liczba artykułów w nagłówku listy (null = prawdziwa). */
    private ?int $reportedTotal = null;

    /** @var list<string> id stron artykułów odpowiadających HTTP 500 */
    private array $brokenDetails = [];

    protected function setUp(): void
    {
        parent::setUp();
        Queue::fake();
        $this->freezeTime();
        $this->travelTo('2026-10-01 10:00:00');
    }

    public function test_login_posts_the_form_with_token_and_partner_number_and_switches_to_english(): void
    {
        $this->addZephyrColours();
        $this->fakeSite();
        $connector = $this->connector();

        iterator_to_array($connector->products(), false);

        $this->assertSame(
            ['_csrf_token' => self::TOKEN, 'username' => self::USER, 'partnr' => '0', 'password' => self::PASSWORD],
            $this->loginForms[0],
        );
        $this->assertTrue(Http::recorded(static fn (Request $r): bool => str_contains($r->url(), '/shop-login?switch_locale=en'))->isNotEmpty());
    }

    public function test_wrong_password_is_not_fatal_and_partner_number_is_sent(): void
    {
        $this->fakeSite();
        $client = new EltenB2bClient(self::USER, 'zle-haslo', '7', 0, static function (int $ms): void {});

        try {
            $client->login();
            $this->fail('logowanie złym hasłem powinno się nie udać');
        } catch (B2bFatalException $e) {
            $this->fail('złe hasło to nie błąd krytyczny: '.$e->getMessage());
        } catch (RuntimeException $e) {
            $this->assertStringContainsString('sprawdź numer klienta, numer partnera i hasło', $e->getMessage());
        }
        $this->assertFalse($client->isLoggedIn());
        $this->assertSame('7', $this->loginForms[0]['partnr']);
    }

    public function test_shop_stuck_in_another_language_stops_the_run(): void
    {
        $this->addZephyrColours();
        $this->stuckInGerman = true;
        $this->fakeSite();

        $this->expectException(B2bFatalException::class);
        $this->expectExceptionMessage('nie przełączył się na język angielski');
        $this->client()->login();
    }

    public function test_price_text_colour_and_site_number_helpers(): void
    {
        $this->assertSame(10719, EltenB2bConnector::priceCents(107.185));
        $this->assertSame(4739, EltenB2bConnector::priceCents(47.385000000000005));
        $this->assertSame(2673, EltenB2bConnector::priceCents(26.732999999999997));
        $this->assertSame(3018, EltenB2bConnector::priceCents(30.18));
        $this->assertNull(EltenB2bConnector::priceCents(0));
        $this->assertNull(EltenB2bConnector::priceCents(null));
        $this->assertNull(EltenB2bConnector::priceCents('abc'));

        $this->assertSame(16490, EltenB2bConnector::textCents('164,90 EUR'));
        $this->assertSame(193053, EltenB2bConnector::textCents(' 1.930,53 EUR'));
        $this->assertNull(EltenB2bConnector::textCents('164.90'));

        $this->assertSame(['ZEPHYR Work GTX® Mid ESD S3S WR', 'ranger green'], EltenB2bConnector::splitColour('ZEPHYR Work GTX® ranger green Mid ESD S3S WR'));
        $this->assertSame(['ALAN XXTP Low ESD S3S', 'black-red'], EltenB2bConnector::splitColour('ALAN XXTP black-red Low ESD S3S'));
        // słowo spoza listy kolorów i wielkie litery zostają w nazwie
        $this->assertSame(['Laces 80 cm (SU=50 p.) Poly', ''], EltenB2bConnector::splitColour('Laces 80 cm (SU=50 p.) Poly'));
        $this->assertSame(['WHITE Loop Low ESD S2', ''], EltenB2bConnector::splitColour('WHITE Loop Low ESD S2'));

        $this->assertSame('5304', EltenB2bConnector::siteNumber('0005304-0'));
        $this->assertSame('7273101', EltenB2bConnector::siteNumber('7273101-0'));
        $this->assertNull(EltenB2bConnector::siteNumber('0260001-3'));
    }

    public function test_price_cents_do_not_depend_on_the_decimal_separator_of_the_locale(): void
    {
        $previous = setlocale(LC_NUMERIC, '0');
        $locale = setlocale(LC_NUMERIC, ['pl_PL.UTF-8', 'pl_PL.utf8', 'pl_PL', 'Polish_Poland.1250', 'Polish', 'de_DE.UTF-8', 'de_DE', 'German']);
        try {
            if ($locale === false || ! str_contains(sprintf('%.1f', 1.5), ',')) {
                $this->markTestSkipped('brak ustawień regionalnych z przecinkiem dziesiętnym w tym środowisku');
            }
            // na serwerze (przecinek) „%f” dawało „107,185” → 0,11 EUR
            $this->assertSame(10719, EltenB2bConnector::priceCents(107.185));
            $this->assertSame(4739, EltenB2bConnector::priceCents(47.385000000000005));
        } finally {
            setlocale(LC_NUMERIC, $previous !== false ? $previous : 'C');
        }
    }

    public function test_polish_page_gives_only_the_opinion_list_and_the_allowed_pdf(): void
    {
        $html = self::polishPage('ZEPHYR Work GTX® black Mid ESD S3S WR - 5304', ['Hydrofobizowana skóra welurowa', 'Podnosek z tworzywa sztucznego'], 'https://elten.com/data/media/products/pdf/PL/PL 5304 ZEPHYR Work GTX® black Mid ESD S3S WR.pdf');

        $page = EltenB2bConnector::parsePolishPage($html, '5304');

        $this->assertSame(['Hydrofobizowana skóra welurowa', 'Podnosek z tworzywa sztucznego'], $page['opinion'] ?? null);
        $this->assertSame('https://elten.com/data/media/products/pdf/PL/PL%205304%20ZEPHYR%20Work%20GTX%C2%AE%20black%20Mid%20ESD%20S3S%20WR.pdf', $page['pdf'] ?? null);
        // strona innego numeru
        $this->assertNull(EltenB2bConnector::parsePolishPage($html, '5305'));
        // zakładka bez listy — lista następnej zakładki („Orto / wkładek”) nie jest opisem
        $empty = EltenB2bConnector::parsePolishPage(self::polishPage('X - 1', [], null), '1');
        $this->assertSame([], $empty['opinion'] ?? null);
        $this->assertArrayHasKey('pdf', (array) $empty);
        $this->assertNull($empty['pdf']);
    }

    public function test_colours_and_size_extensions_become_one_card_and_different_features_stay_apart(): void
    {
        $this->addZephyrColours();
        $this->addTillBoa();
        $this->addLaces();
        $this->fakeSite();
        $connector = $this->connector();

        $products = iterator_to_array($connector->products(), false);

        $this->assertSame(
            ['0005304-0', '0005311-0', '0076651-0', '0276651-0', '0260001-0'],
            array_map(static fn (B2bRemoteProduct $p): string => $p->sku, $products),
        );
        [$zephyr, $other, $till, $tillApart, $laces] = $products;

        // kolory black + wolf (te same cechy) — jedna karta; nazwa nowej karty z marką i bez koloru
        $this->assertSame('ok', $zephyr->raw['status']);
        $this->assertSame('0005304-0/41', $zephyr->remoteId);
        $this->assertSame('ZEPHYR Work GTX® black Mid ESD S3S WR', $zephyr->name);
        $this->assertSame('LOWA ZEPHYR Work GTX® Mid ESD S3S WR', $zephyr->cardName);
        $this->assertSame('Obuwie > LOWA', $zephyr->category);
        $this->assertSame('https://b2b.elten.com/detail/10001', $zephyr->sourceUrl);
        $this->assertSame('ELTEN', $connector->manufacturer($zephyr));
        $this->assertSame('Kolory: black, wolf; rozmiary: 41, 42', $zephyr->variantSummary);
        $this->assertSame(
            [
                ['0005304-0/41', '0005304-0 41', 'black / 41', 107.19, 164.9, 'Na stanie: 5'],
                ['0005304-0/42', '0005304-0 42', 'black / 42', 107.19, 164.9, 'Brak na stanie, dostawa od 23.11.2026: 24'],
                ['0005305-0/41', '0005305-0 41', 'wolf / 41', 99.99, 164.9, 'Brak na stanie'],
            ],
            array_map(static fn (array $m): array => [$m['remote_id'], $m['sku'], $m['size'], $m['price']->net, $m['price']->base, $m['availability']], $zephyr->members),
        );
        $this->assertSame(
            'Na stanie: black / 41; Brak na stanie, dostawa od 23.11.2026: black / 42; Brak na stanie: wolf / 41',
            $zephyr->availability,
        );
        $price = $connector->price($zephyr);
        $this->assertSame(99.99, $price->net);
        $this->assertSame(164.9, $price->base);
        $this->assertSame('EUR', $price->currency);
        $this->assertSame(39.36, $price->discountPercent);
        $this->assertFalse($price->order->restricts());
        $this->assertSame(
            [
                [ProductIdentifier::TYPE_SOURCE_CODE, '0005304-0', '0005304-0/41', 'black'],
                [ProductIdentifier::TYPE_SOURCE_CODE, '0005305-0', '0005305-0/41', 'wolf'],
            ],
            array_map(static fn ($i): array => [$i->type, $i->value, $i->remoteId, $i->label], $zephyr->identifiers ?? []),
        );
        // opis = polska lista cech z elten.com, bez cudzej zakładki „Details”
        $this->assertSame("Hydrofobizowana skóra welurowa\nPodnosek z tworzywa sztucznego\nEN ISO 20345:2022 S3S WR/FO/SR/HI/HRO/CI, typ B (trzewiki)", $connector->description($zephyr));
        $this->assertSame(
            [
                'Informacje ze sklepu ELTEN | Brand | LOWA WORK',
                'Informacje ze sklepu ELTEN | Colour | Black; Grey, Green',
                'Informacje ze sklepu ELTEN | Commodity group | shoes',
                'Informacje ze sklepu ELTEN | Upper material | Hydrophobized suede',
                'Informacje ze sklepu ELTEN | Product group | LOWA',
                'Informacje ze sklepu ELTEN | Standard protection class | S3S',
                'Informacje ze sklepu ELTEN | Uwagi | Leather-free equipment',
                'Informacje ze sklepu ELTEN | Numer artykułu | 0005304-0, 0005305-0',
                'Normy | Norma | EN ISO 20345:2022 S3S WR/FO/SR/HI/HRO/CI, form B',
            ],
            array_map(static fn ($f): string => $f->section.' | '.$f->name.' | '.$f->value, $connector->shopFields($zephyr)),
        );
        // tylko PDF wyrobu z dozwolonej ścieżki — arkusz techniczny (ADT) i certyfikat nie
        $this->assertSame(
            [['PL 5304 ZEPHYR Work GTX® black Mid ESD S3S WR.pdf', ProductDocument::KIND_DATASHEET]],
            array_map(static fn (B2bRemoteDocument $d): array => [$d->title, $d->kind], $connector->documents($zephyr)),
        );
        // jedno zdjęcie na kolor
        $this->assertSame(
            ['https://b2b.elten.com/cache/bilder/a10001.jpeg', 'https://b2b.elten.com/cache/bilder/a10002.jpeg'],
            $connector->imageUrls($zephyr),
        );

        // inna norma (coyote) — osobna karta z dosłowną nazwą; jej polska strona ma tytuł innego numeru — bez opisu
        $this->assertSame('LOWA ZEPHYR Work GTX® coyote Mid ESD S3S WR', $other->cardName);
        $this->assertSame('Rozmiary: 43', $other->variantSummary);
        $this->assertSame('', $connector->description($other));
        $this->assertSame([], $connector->documents($other));
        $this->assertSame(['https://b2b.elten.com/cache/bilder/a10003.jpeg', 'https://b2b.elten.com/cache/bilder/b10003.jpeg'], $connector->imageUrls($other));

        // rozmiary dodatkowe pod osobnym numerem (rozłączne) — jedna karta bez kolorów w etykietach
        $this->assertSame('ELTEN TILL BOA® Mid ESD S3S', $till->cardName);
        $this->assertSame('Rozmiary: 37, 38, 36', $till->variantSummary);
        $this->assertSame(['37', '38', '36'], array_column($till->members, 'size'));
        $this->assertSame('Na stanie', $till->availability);
        // rozmiary dodatkowe to ten sam kolor — galeria pierwszego artykułu, nie zdjęcie każdego numeru
        $this->assertSame(['https://b2b.elten.com/cache/bilder/a3630.jpeg', 'https://b2b.elten.com/cache/bilder/b3630.jpeg'], $connector->imageUrls($till));
        // ten sam numer rozmiaru w trzecim artykule o tej samej nazwie — osobna karta z dopiskiem numeru
        $this->assertSame('ELTEN TILL BOA® Mid ESD S3S – 0276651-0', $tillApart->cardName);

        // „VE” to jednostka sprzedaży, nie rozmiar
        $this->assertSame('', $laces->variantSummary);
        $this->assertSame([['0260001-0/VE', '0260001-0', '0260001-0']], array_map(static fn (array $m): array => [$m['remote_id'], $m['sku'], $m['size']], $laces->members));
        $this->assertSame('opak.', $connector->price($laces)->order->unit);
        $this->assertSame('ELTEN Laces 80 cm (SU=50 p.) Poly', $laces->cardName);
        $this->assertSame('Akcesoria > ACCESSORIES', $laces->category);

        $summary = implode("\n", $connector->runSummary());
        $this->assertStringContainsString('Lista ELTEN: 7 artykułów', $summary);
        $this->assertStringContainsString('Karty: 5 (1 z kolorami w jednej karcie, 1 z rozmiarami z kilku numerów artykułu', $summary);
        $this->assertStringContainsString('Polskie strony elten.com pominięte: 1, np. 0005311-0 (strona innego numeru)', $summary);
        $this->assertStringContainsString('Karty bez polskiej strony elten.com (bez opisu i PDF): 4', $summary);
        $this->assertSame(5, $connector->totalProducts());
        // pliki z zakazanej w robots.txt ścieżki nie były pobierane
        $this->assertTrue(Http::recorded(static fn (Request $r): bool => str_contains($r->url(), '/data/media/documents/'))->isEmpty());
    }

    public function test_elten_site_failures_stop_asking_the_site_but_not_the_prices(): void
    {
        for ($i = 1; $i <= 7; $i++) {
            $number = sprintf('%07d-0', 500000 + $i);
            $this->addArticle((string) (40000 + $i), $number, 'MODEL'.$i.' Low ESD S3', brand: 'ELTEN', sizes: [['size' => '40', 'price' => 50.0, 'stocks' => ['2026/09/28' => 1]]]);
            $this->polish[(string) (500000 + $i)] = ['title' => 'MODEL'.$i.' - '.(500000 + $i), 'opinion' => ['Opis'], 'pdf' => null];
        }
        $this->sitePagesDown = true;
        $this->fakeSite();
        $connector = $this->connector();

        $products = iterator_to_array($connector->products(), false);

        $this->assertSame(array_fill(0, 7, 'ok'), array_map(static fn (B2bRemoteProduct $p): string => $p->raw['status'], $products));
        // 5 nieudanych stron, potem witryna wyłączona do końca przebiegu
        $this->assertCount(5, Http::recorded(static fn (Request $r): bool => str_contains($r->url(), 'elten.com/pl/products/')));
        $this->assertStringContainsString('elten.com wyłączone w tym przebiegu', implode('
', $connector->runSummary()));
    }

    public function test_pages_gone_from_elten_site_are_not_a_site_failure(): void
    {
        for ($i = 1; $i <= 7; $i++) {
            $number = sprintf('%07d-0', 500000 + $i);
            $this->addArticle((string) (40000 + $i), $number, 'MODEL'.$i.' Low ESD S3', brand: 'ELTEN', sizes: [['size' => '40', 'price' => 50.0, 'stocks' => ['2026/09/28' => 1]]]);
            $this->polish[(string) (500000 + $i)] = ['title' => 'MODEL'.$i.' - '.(500000 + $i), 'opinion' => ['Opis'], 'pdf' => null];
        }
        $this->sitePagesGone = true;
        $this->fakeSite();
        $connector = $this->connector();

        iterator_to_array($connector->products(), false);

        // wycofane wyroby (410) nie wyłączają witryny — każda strona była sprawdzona
        $this->assertCount(7, Http::recorded(static fn (Request $r): bool => str_contains($r->url(), 'elten.com/pl/products/')));
        $summary = implode("\n", $connector->runSummary());
        $this->assertStringNotContainsString('wyłączone w tym przebiegu', $summary);
        $this->assertStringContainsString('strony nie ma — HTTP 404/410', $summary);
    }

    public function test_article_without_account_price_or_in_another_currency_is_skipped_with_a_reason(): void
    {
        $this->addZephyrColours();
        $this->addArticle('20001', '0300001-0', 'NOVA Low ESD S3', brand: 'ELTEN', sizes: [['size' => '40', 'price' => 50.0, 'stocks' => ['2026/09/28' => 1]]], currency: 'CHF');
        $this->addArticle('20002', '0300002-0', 'NOVA Mid ESD S3', brand: 'ELTEN', sizes: [['size' => '40', 'price' => 0, 'stocks' => []]]);
        $this->fakeSite();
        $connector = $this->connector();

        $products = iterator_to_array($connector->products(), false);
        $skipped = array_values(array_filter($products, static fn (B2bRemoteProduct $p): bool => $p->raw['status'] !== 'ok'));

        $this->assertSame(['0300001-0', '0300002-0'], array_map(static fn (B2bRemoteProduct $p): string => $p->sku, $skipped));
        $this->assertStringContainsString('walucie „CHF”', $skipped[0]->raw['reason']);
        $this->assertStringContainsString('rozmiar 40 bez ceny konta', $skipped[1]->raw['reason']);
        $this->expectExceptionMessage('walucie „CHF”');
        $connector->price($skipped[0]);
    }

    public function test_many_articles_without_any_account_price_stop_the_run(): void
    {
        for ($i = 1; $i <= 21; $i++) {
            $this->addArticle((string) (30000 + $i), sprintf('%07d-0', 400000 + $i), 'MODEL'.$i.' S3', brand: 'ELTEN', sizes: [['size' => '40', 'price' => 0, 'stocks' => []]]);
        }
        $this->fakeSite();
        $connector = $this->connector();

        $this->expectException(B2bFatalException::class);
        $this->expectExceptionMessage('bez ceny konta w EUR');
        iterator_to_array($connector->products(), false);
    }

    public function test_unreadable_article_holds_the_other_articles_of_the_same_product(): void
    {
        $this->addZephyrColours();
        $this->brokenDetails = ['10002'];
        $this->fakeSite();
        $connector = $this->connector();

        $products = iterator_to_array($connector->products(), false);
        $bySku = [];
        foreach ($products as $product) {
            $bySku[$product->sku] = $product;
        }

        $this->assertSame('skipped', $bySku['0005305-0']->raw['status']);
        $this->assertStringContainsString('strona artykułu nieodczytana', $bySku['0005305-0']->raw['reason']);
        $this->assertSame('skipped', $bySku['0005304-0']->raw['status']);
        $this->assertStringContainsString('podział na karty w następnym przebiegu', $bySku['0005304-0']->raw['reason']);
        $this->assertSame('skipped', $bySku['0005311-0']->raw['status']);
    }

    public function test_list_stops_at_the_header_count_although_pages_after_the_last_repeat_it(): void
    {
        $this->addZephyrColours();
        $this->addTillBoa();
        $this->addLaces();
        $this->fakeSite();
        $client = $this->client();
        $client->login();

        $this->assertCount(EltenConnectorTest::PER_PAGE, EltenB2bConnector::parseListPage($client->listPage(1)));
        // strona za ostatnią oddaje ostatnią jeszcze raz (jak sklep)
        $this->assertSame(EltenB2bConnector::parseListPage($client->listPage(3)), EltenB2bConnector::parseListPage($client->listPage(9)));

        $connector = new EltenB2bConnector($client);
        $products = iterator_to_array($connector->products(), false);
        $this->assertCount(5, $products);
        $this->assertTrue(Http::recorded(static fn (Request $r): bool => str_contains($r->url(), 'pg=4'))->isEmpty());
    }

    public function test_list_with_a_wrong_header_count_is_read_again_once_then_fails(): void
    {
        $this->addZephyrColours();
        $this->reportedTotal = 9;
        $this->fakeSite();
        $connector = $this->connector();

        try {
            iterator_to_array($connector->products(), false);
            $this->fail('lista niespójna powinna przerwać przebieg');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString('3 z 9 artykułów', $e->getMessage());
        }
        $this->assertCount(2, Http::recorded(static fn (Request $r): bool => str_contains($r->url(), '/search?&pg=1')));
    }

    public function test_expired_session_is_renewed_once_and_a_session_never_restored_is_fatal(): void
    {
        $this->addZephyrColours();
        $this->expireAfterPages = 2;
        $this->fakeSite();
        $connector = $this->connector();

        $products = iterator_to_array($connector->products(), false);

        $this->assertSame('ok', $products[0]->raw['status']);
        $this->assertSame(2, $this->logins);

        $this->sessionRefused = true;
        $client = $this->client();
        $client->login();
        $this->expectException(B2bFatalException::class);
        $this->expectExceptionMessage('Utracono sesję konta b2b.elten.com');
        $client->detailPage('10001');
    }

    public function test_files_and_images_need_the_allowed_path_and_the_session(): void
    {
        $this->addZephyrColours();
        $this->fakeSite();
        $connector = $this->connector();
        $card = iterator_to_array($connector->products(), false)[0];

        $image = $connector->image($card);
        $file = $connector->documentBytes($connector->documents($card)[0]);

        $this->assertSame('image/jpeg', $image?->mime);
        $this->assertSame('application/pdf', $file['mime']);
        $this->assertStringStartsWith('%PDF-', $file['bytes']);

        // arkusz techniczny leży w ścieżce zakazanej w robots.txt elten.com
        try {
            $connector->documentBytes(new B2bRemoteDocument('TD', 'https://elten.com/data/media/documents/TDB/PL/LOWA%20WORK/TD%20PL%205304.pdf'));
            $this->fail('plik spoza dozwolonej ścieżki nie powinien być pobrany');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString('spoza dozwolonej ścieżki', $e->getMessage());
        }
        $this->assertTrue(Http::recorded(static fn (Request $r): bool => str_contains($r->url(), '/data/media/documents/'))->isEmpty());

        // zdjęcie sklepu bez sesji = 401 → nowa sesja, potem obraz
        $this->sessions = [];
        $this->assertSame('image/jpeg', $connector->imageAt('https://b2b.elten.com/cache/bilder/a10001.jpeg')?->mime);
    }

    public function test_sync_creates_cards_with_size_rows_norms_documents_and_images_and_a_second_run_changes_nothing(): void
    {
        Storage::fake('public');
        Storage::fake('local');
        $this->addZephyrColours();
        $this->addTillBoa();
        $this->addLaces();
        $this->fakeSite();

        $result = app(B2bAccountSyncRunner::class)->run($this->account(), delayMs: 0, withImages: true);

        $this->assertSame(5, $result['created'], implode(' | ', $result['errors']));
        $card = Product::query()->where('sku', '0005304-0')->sole();
        $this->assertSame('ELTEN', $card->manufacturer);
        $this->assertSame('LOWA ZEPHYR Work GTX® Mid ESD S3S WR', $card->name);
        $this->assertSame('EUR', $card->currency);
        $this->assertStringContainsString('Hydrofobizowana skóra welurowa', (string) $card->description);
        $this->assertEqualsCanonicalizing(
            ['0005304-0/41', '0005304-0/42', '0005305-0/41'],
            B2bProductLink::query()->where('product_id', $card->id)->pluck('remote_id')->all(),
        );
        $slot = ProductSourcePrice::query()->where('product_id', $card->id)->sole();
        $this->assertSame('99.99', (string) $slot->purchase_price);
        $this->assertSame('164.90', (string) $slot->catalog_price_net);
        $this->assertSame('107.19', (string) $slot->size_price_max);
        $this->assertSame(
            ['black / 41' => '107.19', 'black / 42' => '107.19', 'wolf / 41' => '99.99'],
            ProductVariant::query()->where('product_id', $card->id)->where('kind', ProductVariant::KIND_SIZE)->orderBy('label')->pluck('purchase_price', 'label')->map(static fn ($p): string => (string) $p)->all(),
        );
        $this->assertSame('elten', $card->manufacturer_norms['source']['connector'] ?? null);
        $this->assertContains('EN ISO 20345:2022', array_column($card->manufacturer_norms['rows'] ?? [], 'label'));
        $this->assertSame(
            ['PL 5304 ZEPHYR Work GTX® black Mid ESD S3S WR.pdf'],
            ProductDocument::query()->where('product_id', $card->id)->orderBy('sort_order')->pluck('title')->all(),
        );
        $this->assertSame(2, ProductImage::query()->where('product_id', $card->id)->count());
        $rows = collect(ProductShopCard::query()->where('product_id', $card->id)->sole()->fields)->flatMap(static fn (array $section): array => array_map(
            static fn (array $row): string => ($section['section'] ?? '').' | '.$row['name'].' | '.$row['value'],
            $section['rows'] ?? [],
        ))->all();
        $this->assertContains('Informacje ze sklepu ELTEN | Upper material | Hydrophobized suede', $rows);
        $this->assertSame(
            ['0005304-0', '0005305-0'],
            ProductIdentifier::query()->where('product_id', $card->id)->where('type', ProductIdentifier::TYPE_SOURCE_CODE)->orderBy('value')->pluck('value')->all(),
        );
        $till = Product::query()->where('sku', '0076651-0')->sole();
        $this->assertSame('ELTEN TILL BOA® Mid ESD S3S', $till->name);
        $this->assertSame(3, B2bProductLink::query()->where('product_id', $till->id)->count());

        $before = $this->snapshot();
        $second = app(B2bAccountSyncRunner::class)->run($this->account(), delayMs: 0, withImages: true);

        $this->assertSame(0, $second['created'], implode(' | ', $second['errors']));
        $this->assertSame(0, $second['updated'], implode(' | ', $second['errors']));
        $this->assertSame(5, $second['unchanged'], implode(' | ', $second['errors']));
        $this->assertSame($before, $this->snapshot());
    }

    public function test_registry_detects_elten_by_host_as_the_manufacturer_site(): void
    {
        $registry = app(B2bConnectorRegistry::class);

        $this->assertSame('elten', $registry->keyForSites(['https://b2b.elten.com/shop-login']));
        $this->assertSame('ELTEN', $registry->label('elten'));
        $this->assertTrue($registry->requiresPassword('elten'));
        $this->assertTrue($registry->isManufacturerSite('elten'));
        $this->assertTrue($registry->groupsSizes('elten'));
        $this->assertTrue($registry->sendsSizePrices('elten'));
        $this->assertSame(['brand' => 'ELTEN', 'names' => ['Norma']], $registry->shopFieldNormSource('elten'));

        $account = B2bAccount::query()->create([
            'username' => self::USER, 'password' => 'sekret', 'sites' => ['https://b2b.elten.com/shop-login'],
        ]);
        $connector = $registry->make($account, 0);

        $this->assertInstanceOf(EltenB2bConnector::class, $connector);
        foreach ([B2bManufacturerSite::class, B2bShopFieldSource::class, B2bShopFieldNormSource::class, B2bDocumentSource::class, B2bImageGallery::class, B2bGroupsSizes::class, B2bSizePriceSource::class] as $interface) {
            $this->assertInstanceOf($interface, $connector);
        }
    }

    // ---- pomocnicze ----

    private function client(): EltenB2bClient
    {
        return new EltenB2bClient(self::USER, self::PASSWORD, '', 0, static function (int $ms): void {});
    }

    private function connector(): EltenB2bConnector
    {
        $connector = new EltenB2bConnector($this->client());
        $connector->login();

        return $connector;
    }

    private function account(): B2bAccount
    {
        return B2bAccount::query()->firstOrCreate(
            ['username' => self::USER],
            ['password' => self::PASSWORD, 'sites' => ['https://b2b.elten.com/shop-login'], 'sync_images' => true],
        )->fresh();
    }

    /**
     * @return array<string, mixed>
     */
    private function snapshot(): array
    {
        return Product::query()->orderBy('sku')->get()->mapWithKeys(fn (Product $p): array => [$p->sku => [
            'name' => $p->name,
            'description' => $p->description,
            'variant_summary' => $p->variant_summary,
            'manufacturer_norms' => collect($p->manufacturer_norms ?? [])->except('source')->all(),
            'links' => B2bProductLink::query()->where('product_id', $p->id)->orderBy('remote_id')->pluck('remote_id')->all(),
            'variants' => ProductVariant::query()->where('product_id', $p->id)->orderBy('label')->get(['label', 'purchase_price', 'availability'])->toArray(),
            'shop_card' => ProductShopCard::query()->where('product_id', $p->id)->value('fields'),
            'images' => ProductImage::query()->where('product_id', $p->id)->orderBy('sort_order')->pluck('source_url')->all(),
            'documents' => ProductDocument::query()->where('product_id', $p->id)->orderBy('sort_order')->pluck('source_url')->all(),
            'price' => ProductSourcePrice::query()->where('product_id', $p->id)->get(['purchase_price', 'catalog_price_net', 'size_price_max', 'availability'])->toArray(),
        ]])->all();
    }

    /**
     * ZEPHYR black i wolf (te same cechy, wolf taniej) — jedna karta; coyote w innej normie — osobno. Polska strona
     * black z listą cech, PDF-em, ADT i certyfikatem; strona coyote ma tytuł innego numeru.
     */
    private function addZephyrColours(): void
    {
        $features = static fn (string $colour, string $standard, string $class): array => [
            ['Brand', 'LOWA WORK'],
            ['Colour', $colour],
            ['Commodity group', 'shoes'],
            ['Standard', $standard],
            ['Upper material', 'Hydrophobized suede'],
            ['Product group', 'LOWA'],
            ['Standard protection class', $class],
        ];
        $this->addArticle('10001', '0005304-0', 'ZEPHYR Work GTX® black Mid ESD S3S WR', features: $features('Black', 'EN ISO 20345:2022 S3S WR/FO/SR/HI/HRO/CI, form B', 'S3S'), subtitle: 'Leather-free equipment', listPrice: '164,90 EUR', accountText: '107,19 EUR', sizes: [
            ['size' => '41', 'price' => 107.185, 'stocks' => ['2026/09/28' => 5, '2026/10/05' => 0]],
            ['size' => '42', 'price' => 107.185, 'stocks' => ['2026/09/28' => 0, '2026/10/05' => 0, '2026/11/23' => 24]],
        ], images: 2);
        $this->addArticle('10002', '0005305-0', 'ZEPHYR Work GTX® wolf Mid ESD S3S WR', features: $features('Grey, Green', 'EN ISO 20345:2022 S3S WR/FO/SR/HI/HRO/CI, form B', 'S3S'), subtitle: 'Leather-free equipment', listPrice: '164,90 EUR', accountText: '99,99 EUR', sizes: [
            ['size' => '41', 'price' => 99.994, 'stocks' => []],
        ], images: 1);
        $this->addArticle('10003', '0005311-0', 'ZEPHYR Work GTX® coyote Mid ESD S3S WR', features: $features('Brown', 'EN ISO 20345:2022 S3 WR/FO/SR/HI/HRO/CI, form B', 'S3'), subtitle: 'Leather-free equipment', listPrice: '164,90 EUR', accountText: '107,19 EUR', sizes: [
            ['size' => '43', 'price' => 107.185, 'stocks' => ['2026/09/28' => 3]],
        ], images: 2);

        $this->polish['5304'] = [
            'title' => 'ZEPHYR Work GTX® black Mid ESD S3S WR - 5304',
            'opinion' => ['Hydrofobizowana skóra welurowa', 'Podnosek z tworzywa sztucznego', 'EN ISO 20345:2022 S3S WR/FO/SR/HI/HRO/CI, typ B (trzewiki)'],
            'pdf' => 'https://elten.com/data/media/products/pdf/PL/PL 5304 ZEPHYR Work GTX® black Mid ESD S3S WR.pdf',
        ];
        $this->polish['5311'] = ['title' => 'ZEPHYR Work GTX® brown Mid ESD S3S WR - 5308', 'opinion' => ['Cudza lista'], 'pdf' => null];
    }

    /** TILL BOA®: 0076651 (37, 38) + 0176651 (36) — jedna karta; 0276651 też z rozmiarem 38 — osobno. */
    private function addTillBoa(): void
    {
        $features = [['Brand', 'ELTEN'], ['Colour', 'Black, Red'], ['Commodity group', 'shoes'], ['Standard', 'EN ISO 20345:2022 S3S FO/SR/SC, form B'], ['Product group', 'BIOMEX DYNAMICS']];
        $this->addArticle('3630', '0076651-0', 'TILL BOA® Mid ESD S3S', features: $features, listPrice: '113,90 EUR', accountText: '74,04 EUR', sizes: [
            ['size' => '37', 'price' => 74.035, 'stocks' => ['2026/09/28' => 9]],
            ['size' => '38', 'price' => 74.035, 'stocks' => ['2026/09/28' => 4]],
        ], images: 2);
        $this->addArticle('3635', '0176651-0', 'TILL BOA® Mid ESD S3S', features: $features, listPrice: '113,90 EUR', accountText: '74,04 EUR', sizes: [
            ['size' => '36', 'price' => 74.035, 'stocks' => ['2026/09/28' => 2]],
        ], images: 2);
        $this->addArticle('3638', '0276651-0', 'TILL BOA® Mid ESD S3S', features: $features, listPrice: '113,90 EUR', accountText: '74,04 EUR', sizes: [
            ['size' => '38', 'price' => 74.035, 'stocks' => ['2026/09/28' => 1]],
        ]);
    }

    private function addLaces(): void
    {
        $this->addArticle('2893', '0260001-0', 'Laces 80 cm (SU=50 p.) Poly', features: [['Brand', 'ELTEN'], ['Colour', 'Black'], ['Commodity group', 'Zubehör'], ['Product group', 'ACCESSORIES']], listPrice: '18,90 EUR', accountText: '12,66 EUR', sizes: [
            ['size' => 'VE', 'price' => 12.663, 'stocks' => ['2026/09/28' => 73]],
        ], images: 1);
    }

    /**
     * @param  list<array{0: string, 1: string}>|null  $features
     * @param  list<array{size: string, price: float|int, stocks: array<string, int>}>  $sizes
     */
    private function addArticle(string $id, string $number, string $name, ?array $features = null, string $subtitle = '', string $listPrice = '100,00 EUR', string $accountText = '', array $sizes = [], int $images = 0, string $currency = 'EUR', string $brand = 'ELTEN'): void
    {
        $this->articles[$id] = [
            'id' => $id,
            'number' => $number,
            'name' => $name,
            'features' => $features ?? [['Brand', $brand], ['Commodity group', 'shoes'], ['Product group', 'NOVA']],
            'subtitle' => $subtitle,
            'list' => $listPrice,
            'account' => $accountText,
            'sizes' => $sizes,
            'images' => $images,
            'currency' => $currency,
        ];
    }

    private function fakeSite(): void
    {
        Http::fake(function (Request $request) {
            $url = $request->url();
            $host = (string) parse_url($url, PHP_URL_HOST);
            $path = rawurldecode((string) parse_url($url, PHP_URL_PATH));
            parse_str((string) parse_url($url, PHP_URL_QUERY), $query);

            if ($host === 'elten.com') {
                return $this->siteResponse($path);
            }

            preg_match('/PHPSESSID=([\w-]+)/', $request->header('Cookie')[0] ?? '', $m);
            $session = $m[1] ?? '';
            $signedIn = ! $this->sessionRefused && in_array($session, $this->sessions, true);

            if ($path === '/shop-login') {
                if ($request->method() === 'POST') {
                    parse_str($request->body(), $form);
                    $this->loginForms[] = $form;
                    if (($form['_csrf_token'] ?? null) === self::TOKEN && ($form['username'] ?? null) === self::USER && ($form['password'] ?? null) === self::PASSWORD) {
                        $this->logins++;
                        $id = 's'.$this->logins;
                        $this->sessions[] = $id;
                        $this->pages = 0;

                        return Http::response($this->page('<h1>Welcome</h1>', true), 200, ['Set-Cookie' => 'PHPSESSID='.$id.'; path=/; secure; HttpOnly', 'Content-Type' => 'text/html; charset=UTF-8']);
                    }

                    return Http::response($this->loginPage(), 200, ['Content-Type' => 'text/html; charset=UTF-8']);
                }

                return Http::response($this->loginPage(), 200, ['Set-Cookie' => 'PHPSESSID=guest; path=/', 'Content-Type' => 'text/html; charset=UTF-8']);
            }
            if (! $signedIn) {
                return Http::response('<html><body>Unauthorized</body></html>', 401, ['Content-Type' => 'text/html']);
            }
            if (str_starts_with($path, '/cache/bilder/')) {
                return Http::response(self::jpeg($path), 200, ['Content-Type' => 'image/jpeg']);
            }
            $this->pages++;
            if ($this->expireAfterPages > 0 && $this->pages > $this->expireAfterPages) {
                $this->expireAfterPages = 0;
                $this->sessions = [];

                return Http::response('<html><body>Unauthorized</body></html>', 401, ['Content-Type' => 'text/html']);
            }
            if ($path === '/') {
                return Http::response($this->page('<h1>Welcome</h1>', true), 200, ['Content-Type' => 'text/html; charset=UTF-8']);
            }
            if ($path === '/search') {
                return Http::response($this->listPage((int) ($query['pg'] ?? 1)), 200, ['Content-Type' => 'text/html; charset=UTF-8']);
            }
            if (preg_match('#^/detail/(\d+)$#', $path, $d) === 1 && isset($this->articles[$d[1]])) {
                if (in_array($d[1], $this->brokenDetails, true)) {
                    return Http::response('error', 500);
                }

                return Http::response($this->detailPage($this->articles[$d[1]]), 200, ['Content-Type' => 'text/html; charset=UTF-8']);
            }

            return Http::response('', 404);
        });
    }

    private function siteResponse(string $path): PromiseInterface
    {
        if ($path === '/portfolio-sitemap.xml') {
            $locs = [];
            foreach ($this->polish as $number => $page) {
                $slug = 'artykul';
                $locs[] = '<url><loc>https://elten.com/nl/products/'.$slug.'-'.$number.'/</loc></url>';
                $locs[] = '<url><loc>https://elten.com/pl/products/'.$slug.'-'.$number.'/</loc></url>';
            }

            return Http::response('<?xml version="1.0" encoding="UTF-8"?><urlset>'.implode('', $locs).'</urlset>', 200, ['Content-Type' => 'text/xml; charset=UTF-8']);
        }
        if (preg_match('#^/portfolio-sitemap\d\.xml$#', $path) === 1) {
            return Http::response('<?xml version="1.0" encoding="UTF-8"?><urlset></urlset>', 200, ['Content-Type' => 'text/xml; charset=UTF-8']);
        }
        if ($this->sitePagesDown && str_starts_with($path, '/pl/products/')) {
            return Http::response('error', 500);
        }
        if ($this->sitePagesGone && str_starts_with($path, '/pl/products/')) {
            return Http::response('gone', 410);
        }
        if (preg_match('#^/pl/products/artykul-(\d+)/$#', $path, $m) === 1 && isset($this->polish[$m[1]])) {
            $page = $this->polish[$m[1]];

            return Http::response(self::polishPage($page['title'], $page['opinion'], $page['pdf']), 200, ['Content-Type' => 'text/html; charset=UTF-8']);
        }
        if (str_starts_with($path, '/data/media/')) {
            return Http::response('%PDF-1.4 test '.$path, 200, ['Content-Type' => 'application/pdf']);
        }

        return Http::response('', 404);
    }

    private function loginPage(): string
    {
        return '<!DOCTYPE html><html lang="de"><head><title>Kundenlogin - Elten B2B OnlineShop</title></head><body>'
            .'<form id="login-form" action="/shop-login" method="post"><input type="hidden" name="_csrf_token" value="'.self::TOKEN.'" />'
            .'<input id="input-username" type="text" name="username" value="" /><input id="input-partnr" type="text" name="partnr" value="0" />'
            .'<input id="input-password" type="password" name="password" /></form></body></html>';
    }

    private function page(string $content, bool $signedIn): string
    {
        $language = $this->stuckInGerman ? 'de' : 'en';

        return '<!DOCTYPE html><html lang="de"><head><title>Elten B2B OnlineShop</title></head><body><header>'
            .'<span><img class="flag-globe" src="/build/images/flags/'.$language.'.eb9ff63e.png" alt="English" /></span>'
            .($signedIn ? '<a href="/account">My account</a><a'."\n".'href="/shop-logout"><i class="fa fa-sign-out"></i> Logout</a>' : '')
            .'</header><div id="main">'.$content.'</div></body></html>';
    }

    private function listPage(int $page): string
    {
        $articles = array_values($this->articles);
        $pages = max(1, (int) ceil(count($articles) / self::PER_PAGE));
        $page = min(max(1, $page), $pages);
        $tiles = '';
        foreach (array_slice($articles, ($page - 1) * self::PER_PAGE, self::PER_PAGE) as $a) {
            $tiles .= '<div class="col product-list-col product-scroll-box" data-matid="'.$a['id'].'">'
                .'<div class="product-list-box"><figure><a href="/detail/'.$a['id'].'"><img src="/cache/bilder/t'.$a['id'].'.jpeg" alt="" /></a></figure>'
                .'<div class="product-details"><div class="product-heading-row">'
                .'<h3><a href="/detail/'.$a['id'].'" title="Open details page">'.htmlspecialchars($a['name']).' &nbsp;</a></h3>'."\n"
                .'<span>'.$a['number'].' &nbsp;</span>'."\n".'<strong class="product-color">Black</strong></div>'
                .'<div class="price-row"><span class="pricetoggle-EK"><strong>List price:</strong><span>'.$a['list'].'</span></span></div>'
                .'</div></div></div>';
        }
        $total = $this->reportedTotal ?? count($articles);

        return $this->page('<div class="product-header"><h2>Article overview <sub>('.$total.' article(s))</sub></h2></div>'
            .'<div class="product-view-row row ias-container">'.$tiles.'</div>'
            .'<a href="/search?&pg='.min($pages, $page + 1).'">next</a>', true);
    }

    /**
     * @param  array<string, mixed>  $a
     */
    private function detailPage(array $a): string
    {
        $gallery = '';
        for ($i = 0; $i < $a['images']; $i++) {
            $file = ($i === 0 ? 'a' : 'b').$a['id'].'.jpeg';
            $gallery .= '<div class="carousel-cell">'."\n".'<a data-fancybox="detailpage" href="/cache/bilder/'.$file.'" title="Enlarge image in a new window">'
                .'<img  src="/cache/bilder/'.$file.'" alt="Bild #'.$i.'"/></a></div>';
        }
        $gallery .= '<div class="carousel-cell"><a data-fancybox="detailpage" data-src="#360image" href="#360image"><img src="/cache/bilder360/x.jpeg"></a></div>';
        $features = '';
        foreach ($a['features'] as [$label, $value]) {
            $features .= '<div class="col-12 col-sm-6"><div class="row mb-1"><div class="col-6 fw-bold feature-name">'."\n".$label.":\n</div>\n"
                .'<div class="col-6 feature-value">'."\n".htmlspecialchars($value)."\n</div></div></div>";
        }
        $features .= '<div class="col-12 col-sm-6"><div class="row mb-1"><div class="col-6 fw-bold feature-name">'."\nArticle name:\n</div>\n"
            .'<div class="col-6 feature-value">'."\n".htmlspecialchars($a['name'])."\n</div></div></div>";
        $sizes = [];
        foreach ($a['sizes'] as $i => $size) {
            $sizes[(string) ($i + 5)] = ['availableStocks' => $size['stocks'] === [] ? new \stdClass : $size['stocks'], 'price' => $size['price'], 'size' => $size['size']];
        }
        $data = htmlspecialchars((string) json_encode([
            'article' => $sizes,
            'currency' => $a['currency'],
            'translations' => ['available' => 'Deliverable'],
            'formattedDates' => [],
        ]), ENT_QUOTES);

        return $this->page(
            '<div class="product-img-slider">'.$gallery.'</div>'
            .'<div class="product-intro "><h2>'.htmlspecialchars($a['name']).' <sub>'.$a['number'].'</sub></h2>'
            .'<p><strong class="d-block mb-1">'.htmlspecialchars($a['subtitle']).' </strong></p>'
            .'<div class="product-features"><div class="row">'.$features.'</div></div></div>'
            .'<div class="pricing-row pricetoggle-Beide "><div class="pricing-inner-row">'
            .'<span class="pricetoggle-Beide pricetoggle-EK "><strong>List price:</strong>'."\n".'<span>                <span>'.$a['list'].'</span>'."\n</span>\n</span>"
            .'<span class="pricetoggle-Beide pricetoggle-EK  ms-4"><strong>Purchase price:</strong>'."\n".'<span> '.$a['account'].'</span></span></div></div>'
            .'<div class="size-select-box" data-controller="size-select-box" data-article="'.$data.'" data-ia="false"></div>',
            true,
        );
    }

    /**
     * Polska strona wyrobu elten.com: zakładki „Nasza opinia” (lista), „Details” (cudzy tekst po niemiecku), „Orto /
     * wkładek” (lista wkładek), przyciski PDF / ADT / CE.
     *
     * @param  list<string>  $opinion
     */
    private static function polishPage(string $title, array $opinion, ?string $pdf): string
    {
        $items = $opinion === [] ? '<p>brak</p>' : '<ul>'."\n".implode("\n", array_map(static fn (string $line): string => '<li>'.htmlspecialchars($line).'</li>', $opinion))."\n</ul>";
        $buttons = $pdf !== null
            ? '<a id="PDF-button" href="'.$pdf.'" title="PDF" target="_blank">PDF</a> <span>|</span> '
                .'<a id ="TD-button" href="https://elten.com/data/media/documents/TDB/PL/LOWA WORK/TD PL 5304.pdf" title="Arkusz Danych Technicznych">ADT</a> '
                .'<a id ="CE-button" href="https://elten.com/data/media/documents/CE/LOWA WORK/x.pdf" title="Deklaracja zgodności UE">Deklaracja zgodności UE</a>'
            : '';

        return '<!DOCTYPE html><html lang="pl-PL"><head><title>'.htmlspecialchars($title).' - ELTEN GmbH</title></head><body>'
            .'<div class="print-utilities">'.$buttons.'</div>'
            ."<div class='tab active_tab' role='tab' tabindex='0' data-fake-id='#tab-id-1' aria-controls='tab-id-1-content'  itemprop=\"headline\" >Nasza opinia</div>"
            ."<div id='tab-id-1-content' class='tab_content active_tab_content' aria-hidden=\"false\"><div class='tab_inner_content invers-color'  itemprop=\"text\" >".$items.'</div></div>'
            ."<div class='tab' role='tab' tabindex='0' data-fake-id='#tab-id-2' aria-controls='tab-id-2-content'  itemprop=\"headline\" >Details</div>"
            ."<div id='tab-id-2-content' class='tab_content' aria-hidden=\"true\"><div class='tab_inner_content invers-color'  itemprop=\"text\" ><div class=\"shoeDetailFurtherInfo\">Die Sicherheitsschuhe ADAM ESD S1 sind eine Sicherheitssandale.</div></div></div>"
            ."<div class='tab' role='tab' tabindex='0' data-fake-id='#tab-id-3' aria-controls='tab-id-3-content'  itemprop=\"headline\" >Orto / wkładek</div>"
            ."<div id='tab-id-3-content' class='tab_content' aria-hidden=\"true\"><div class='tab_inner_content invers-color'  itemprop=\"text\" ><h5>Wkładki</h5><ul><li>SensiCare</li></ul></div></div>"
            .'</body></html>';
    }

    private static function jpeg(string $seed): string
    {
        $image = imagecreatetruecolor(1, 1);
        imagesetpixel($image, 0, 0, crc32($seed) & 0xFFFFFF);
        ob_start();
        imagejpeg($image);

        return (string) ob_get_clean();
    }
}
