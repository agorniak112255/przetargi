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
use App\Services\B2b\B2bRemoteProduct;
use App\Services\B2b\B2bShopFieldNormSource;
use App\Services\B2b\B2bShopFieldSource;
use App\Services\B2b\B2bSizePriceSource;
use App\Services\B2b\CanisB2bClient;
use App\Services\B2b\CanisB2bConnector;
use App\Services\B2b\CanisPageParser;
use App\Services\B2b\ShopCardNormFacts;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use RuntimeException;
use Tests\TestCase;

/**
 * Łącznik portal.canis.cz na atrapie portalu (Http::fake, bez prawdziwego logowania). Strony wyrobów w
 * tests/Fixtures/canis to prawdziwe strony zalogowanego konta z 04.10.2026 (kody, nazwy, ceny, EAN-y), przycięte do
 * treści <main> (bez okienek „Pilnuj towaru” i formularza ocen), z nagłówkiem zawierającym tylko odnośnik wylogowania —
 * znacznik sesji jak na portalu. Odpowiedzi poza stronami odwzorowują portal: logowanie POST post.php → „1” albo kod
 * błędu, strona bez sesji bez odnośnika wylogowania, „/pl/x_p18278” → 404, PDF bez Content-Type, zdjęcia „.jpg” jako
 * WebP. Menu kategorii i strony list są zbudowane z prawdziwych znaczników, ale z wybranymi kaflami.
 */
final class CanisConnectorTest extends TestCase
{
    use RefreshDatabase;

    private const USER = 'zakupy@example.test';

    private const PASSWORD = 'dobre-haslo';

    private const BLUZY = '/pl/odziez-robocza_c5829687959888112/bluzy_c5829687959889155';

    private const SKORZANE = '/pl/rekawice-robocze_c5829687959889797/skorzane_c5829687959890218';

    private const FILTRY = '/pl/filtry-3m_c5829687959886335';

    /** Strony wyrobów: adres → plik w tests/Fixtures/canis. */
    private const PAGES = [
        '/pl/bluza-cxs-sirius-lucius-meska_p17590' => 'model-17590.html',
        '/pl/bluza-cxs-sirius-lucius-meska-kolor-niebiesko-szary_p88905' => 'colour-88905.html',
        '/pl/rekawice-astar-rozmiar-09_p12898' => 'size-12898.html',
        '/pl/rekawice-astar-rozmiar-10_p13962' => 'size-12898.html',
        '/pl/bluza-cxs-ps-ii-pro-hasici-meska-ciemnoniebieska-roz.-64_p129729' => 'size-129729.html',
        '/pl/x_p129718' => 'colour-129718.html',
        '/pl/filtr-3m-5911-1-para-2-szt._p97960' => 'single-97960.html',
    ];

    /** @var array<string, list<list<array{id: int, url: string, name: string}>>> kategoria → strony listy → kafle */
    private array $lists = [];

    /** @var list<string> kolejność liści w menu */
    private array $menuOrder = [self::BLUZY, self::SKORZANE, self::FILTRY];

    private bool $loggedIn = false;

    private int $logins = 0;

    /** Portal zapomina sesję po tylu stronach (0 = nigdy). */
    private int $forgetAfterPages = 0;

    /** Portal nie uznaje żadnej sesji (logowanie przechodzi, strony są stronami gościa). */
    private bool $neverLoggedIn = false;

    private int $pagesServed = 0;

    /** Waluta stron wyrobów przed przełączeniem ?cid=PLN. */
    private string $currency = 'PLN';

    /** @var list<string> */
    private array $failingPages = [];

    /** @var list<string> strony bez wiersza „Znak” (jak odzież ostrzegawcza LEEDS w portalu) */
    private array $withoutMark = [];

    /** @var array<string, string> strona → nowa wartość pola Producent/Dostawca */
    private array $suppliers = [];

    protected function setUp(): void
    {
        parent::setUp();
        Queue::fake();
        $this->lists = [
            self::BLUZY => [
                [self::tile(17590, 'Bluza CXS SIRIUS LUCIUS, męska,'), self::tile(88905, 'Bluza CXS SIRIUS LUCIUS, męska, kolor niebiesko-szary')],
                [self::tile(129729, 'Bluza CXS PS II PRO HASIČI, męska, ciemnoniebieska, roz. 64')],
            ],
            self::SKORZANE => [
                [self::tile(13962, 'Rękawice ASTAR, rozmiar 10'), self::tile(12898, 'Rękawice ASTAR, rozmiar 09')],
            ],
            self::FILTRY => [
                [self::tile(97960, 'Filtr 3M 5911, 1 para=2 szt.'), self::tile(88905, 'Bluza CXS SIRIUS LUCIUS, męska, kolor niebiesko-szary')],
            ],
        ];
    }

    public function test_parser_reads_menu_list_and_product_pages(): void
    {
        $categories = CanisPageParser::categories($this->home());
        $this->assertSame([
            ['path' => self::FILTRY, 'label' => 'Filtry 3M'],
            ['path' => self::BLUZY, 'label' => 'Odzież robocza > Bluzy'],
            ['path' => self::SKORZANE, 'label' => 'Rękawice robocze > Skórzane'],
        ], $categories);

        $list = CanisPageParser::categoryPage(CanisB2bClient::mainOf($this->fixture('list-skorzane.html')));
        $this->assertCount(12, $list['tiles']);
        $this->assertSame(1, $list['last_page']);
        $this->assertSame(['id' => 12898, 'url' => '/pl/rekawice-astar-rozmiar-09_p12898', 'name' => 'Rękawice ASTAR, rozmiar 09'], $list['tiles'][1]);

        $model = CanisPageParser::productPage(CanisB2bClient::mainOf($this->fixture('model-17590.html')));
        $this->assertSame(17590, $model['id']);
        $this->assertTrue($model['is_master']);
        $this->assertSame('Bluza CXS SIRIUS LUCIUS, męska', $model['name']);
        $this->assertSame('1010-001-000-00', $model['code']);
        $this->assertSame('1 krt jest 20 szt.', $model['alt_units']);
        $this->assertCount(48, $model['rows']);
        $this->assertSame(
            ['id' => 57446, 'master' => 6182, 'code' => '1010-001-708-44', 'name' => 'Bluza CXS SIRIUS LUCIUS, męska, kolor szaro-zielony, roz. 44', 'pack' => '1/20', 'kind' => 'Produkty całoroczne', 'size' => '44'],
            array_intersect_key($model['rows'][0], array_flip(['id', 'master', 'code', 'name', 'pack', 'kind', 'size'])),
        );
        $this->assertSame(67.27, $model['rows'][0]['price']);
        $this->assertSame('zł', $model['rows'][0]['currency']);
        $this->assertContains(['section' => 'Parametry towaru', 'name' => 'Normy', 'value' => 'EN 340'], $model['params']);
        $this->assertSame('https://portal.canis.cz/imgserver/eshop/canis/19/2000000352/17590-product.pdf', $model['documents'][0]['url']);
        $this->assertSame('https://portal.canis.cz/imgserver/eshop/canis/19/2000000326/17590-1010_001_00.jpg', $model['gallery'][0]);

        $single = CanisPageParser::productPage(CanisB2bClient::mainOf($this->fixture('single-97960.html')));
        $this->assertSame([], $single['rows']);
        $this->assertSame('4520-008-000-01', $single['code']);
        $this->assertSame(9.164, $single['price']);
        $this->assertSame('PLN', $single['currency']);
        $this->assertSame('Na stanie (5106 para)', $single['stock']);
        $this->assertSame(['4046719550104', '4046719550111', '452000800001'], $single['eans']);

        $size = CanisPageParser::productPage(CanisB2bClient::mainOf($this->fixture('size-12898.html')));
        $this->assertSame(12898, $size['id']);
        $this->assertSame(18278, $size['master']);
        $this->assertSame(['3100-003-000-09', '3100-003-000-10'], array_column($size['rows'], 'code'));
    }

    public function test_helpers_build_keys_names_and_document_kinds(): void
    {
        $this->assertSame("1010-001\nbluza cxs sirius lucius", CanisB2bConnector::groupKey('1010-001-708-44', 'Bluza CXS SIRIUS LUCIUS, męska, kolor szaro-zielony, roz. 44'));
        $this->assertSame("1010-001\nbluza cxs sirius brighton", CanisB2bConnector::groupKey('1010-001-802-44', 'Bluza CXS SIRIUS BRIGHTON, męska, kolor czarno-żółty, roz. 44'));
        $this->assertSame("3310-024\nrękawice mawa biały", CanisB2bConnector::groupKey('3310-024-100-06', 'Rękawice MAWA biały roz. 06'));
        $this->assertSame('Rękawice ASTAR', CanisB2bConnector::withoutSize('Rękawice ASTAR, rozmiar 09'));
        $this->assertSame('Rękawice OLAS', CanisB2bConnector::withoutSize('Rękawice OLAS, roz. 6-7'));
        $this->assertSame('Bluza CXS SIRIUS LUCIUS, męska', CanisB2bConnector::cardName([
            'Bluza CXS SIRIUS LUCIUS, męska, kolor szaro-zielony, roz. 44',
            'Bluza CXS SIRIUS LUCIUS, męska, kolor niebiesko-szary, roz. 46',
        ]));
        $this->assertSame(ProductDocument::KIND_DATASHEET, CanisB2bConnector::documentKind('17590-product'));
        $this->assertSame(ProductDocument::KIND_MANUAL, CanisB2bConnector::documentKind('17590-manual'));
        $this->assertSame(ProductDocument::KIND_CERTIFICATE, CanisB2bConnector::documentKind('17590-deklarace eu'));
        $this->assertSame(ProductDocument::KIND_CERTIFICATE, CanisB2bConnector::documentKind('97960-odkaz na vyrobky - link to products 3m, eu prohlaseni - declaration'));
        $this->assertNull(CanisB2bConnector::documentKind('123-letak-cz'));
        $this->assertFalse(CanisPageParser::validGtin('310000300009'));
        $this->assertTrue(CanisPageParser::validGtin('8591940000110'));
    }

    public function test_login_posts_the_form_and_a_refused_login_is_not_fatal(): void
    {
        $this->fakeSite();
        $client = $this->client();
        $client->login();
        $this->assertTrue($client->isLoggedIn());
        $post = Http::recorded(static fn (Request $r): bool => $r->method() === 'POST')->first()[0];
        $this->assertSame('https://portal.canis.cz/standard/m2user/post.php', $post->url());
        $this->assertSame(['l' => '1', 'usr' => self::USER, 'pwd' => self::PASSWORD], $post->data());

        $bad = new CanisB2bClient(self::USER, 'zle-haslo', 0, static function (int $ms): void {});
        try {
            $bad->login();
            $this->fail('logowanie złym hasłem powinno się nie udać');
        } catch (B2bFatalException $e) {
            $this->fail('złe hasło to nie błąd krytyczny: '.$e->getMessage());
        } catch (RuntimeException $e) {
            $this->assertStringContainsString('„7”', $e->getMessage());
            $this->assertStringContainsString('sprawdź e-mail i hasło', $e->getMessage());
            $this->assertStringNotContainsString('zle-haslo', $e->getMessage());
        }
        $this->assertFalse($bad->isLoggedIn());
    }

    public function test_cards_group_rows_by_model_and_name_across_pages(): void
    {
        $this->fakeSite();
        $products = $this->productsBySku();

        $this->assertSame([
            '1010-001-410-00', '1010-001-802-44', '1010-035-708-44', '1010-035-802-44', '1010-144-414-00', '3100-003-000-09', '4520-008-000-01',
        ], array_keys($products));

        // LUCIUS: kolor 708 ze strony modelu i 410 z osobnej strony koloru — jedna karta; BRIGHTON (802) i skrócone osobno
        $lucius = $products['1010-001-410-00'];
        $this->assertSame('Bluza CXS SIRIUS LUCIUS, męska', $lucius->name);
        $this->assertSame('1010-001-410-46', $lucius->remoteId);
        $this->assertSame(['410', '708'], array_values(array_unique(array_map(static fn (array $m): string => substr($m['remote_id'], 9, 3), $lucius->members))));
        $this->assertSame('kolor niebiesko-szary / 46', $lucius->members[0]['size']);
        $this->assertSame(49.09, $lucius->members[0]['price']->net);
        $this->assertSame('Na stanie: 11 szt. (Wyprzedaż)', $lucius->members[0]['availability']);
        $this->assertSame('https://portal.canis.cz/pl/bluza-cxs-sirius-lucius-meska-kolor-niebiesko-szary_p88905', $lucius->sourceUrl);
        $this->assertStringStartsWith('Kolory: kolor niebiesko-szary, kolor szaro-zielony; rozmiary: ', (string) $lucius->variantSummary);
        $this->assertSame('Canis', $lucius->raw['manufacturer']);
        $this->assertSame(49.09, $lucius->raw['price']->net);
        $this->assertSame('Odzież robocza > Bluzy', $lucius->category);

        $fields = array_map(static fn (array $f): string => $f['name'].' | '.$f['value'], $lucius->raw['fields']);
        // normy obu stron jako osobne wiersze „Normy” — ShopCardNormFacts czyta z nich same oznaczenia
        $this->assertContains('Normy | EN ISO 13688', $fields);
        $this->assertContains('Normy | EN 340', $fields);
        $this->assertNotContains('Kolor | Niebieski/Szary', $fields);
        $this->assertSame(
            [['label' => 'EN ISO 13688', 'value' => null], ['label' => 'EN 340', 'value' => null]],
            ShopCardNormFacts::facts([['section' => 'x', 'rows' => $lucius->raw['fields']]], ['Normy']),
        );
        $this->assertContains('Rodzaj towaru | Wyprzedaż: '.implode(', ', array_map(static fn (int $s): string => '1010-001-410-'.$s, [46, 48, 50, 52, 54, 56, 58, 60, 62, 64])).'; Produkty całoroczne: '.implode(', ', array_filter(array_column($lucius->members, 'remote_id'), static fn (string $c): bool => str_starts_with($c, '1010-001-708'))), $fields);
        $this->assertSame(
            ['https://portal.canis.cz/imgserver/eshop/canis/19/2000000352/17590-product.pdf', 'https://portal.canis.cz/imgserver/eshop/canis/19/2000000352/17590-deklarace%20eu.pdf', 'https://portal.canis.cz/imgserver/eshop/canis/19/2000000352/17590-manual.pdf'],
            array_column($lucius->raw['documents'], 'url'),
        );
        // kod modelu 1010-001-000-00 obejmuje też BRIGHTON — nie jest identyfikatorem tej karty; kod koloru 410 jest
        $codes = array_map(static fn ($i): string => $i->type.' '.$i->value.' → '.$i->remoteId, $lucius->identifiers);
        $this->assertSame(['source_code 1010-001-410-00 → 1010-001-410-46'], $codes);

        $this->assertSame(['https://portal.canis.cz/imgserver/eshop/canis/19/2000000326/88905-1010_001_410_00.jpg'], $lucius->raw['images']);

        $brighton = $products['1010-001-802-44'];
        $this->assertSame([], $brighton->raw['images']);
        $this->assertSame('Bluza CXS SIRIUS BRIGHTON, męska, kolor czarno-żółty', $brighton->name);
        $this->assertSame([], $brighton->identifiers);
        $this->assertSame('44', $brighton->members[0]['size']);

        // rękawice: strona rozmiaru (wyrób nadrzędny 18278 → 404), kafel 10 nie jest pobierany drugi raz
        $gloves = $products['3100-003-000-09'];
        $this->assertSame('Rękawice ASTAR', $gloves->name);
        $this->assertSame(['3100-003-000-09', '3100-003-000-10'], array_column($gloves->members, 'remote_id'));
        $this->assertSame(['09', '10'], array_column($gloves->members, 'size'));
        $this->assertSame('rozmiary: 09, 10', $gloves->variantSummary);
        $this->assertSame(
            ['ean 8591940000110 → 3100-003-000-09', 'ean 8591940047696 → 3100-003-000-09'],
            array_map(static fn ($i): string => $i->type.' '.$i->value.' → '.$i->remoteId, $gloves->identifiers),
        );
        $this->assertContains('Jednostki alternatywne | 1 krt jest 120 para', array_map(static fn (array $f): string => $f['name'].' | '.$f['value'], $gloves->raw['fields']));
        $this->assertSame(0, $this->requestsTo('/pl/rekawice-astar-rozmiar-10_p13962'));
        $this->assertSame(1, $this->requestsTo('/pl/x_p18278'));

        // kafel rozmiaru PS II PRO prowadzi do strony koloru 129718 (kod -00, wszystkie rozmiary)
        $ps = $products['1010-144-414-00'];
        $this->assertSame('Bluza CXS PS II PRO HASIČI, męska, ciemnoniebieska', $ps->name);
        $this->assertCount(10, $ps->members);
        $this->assertSame('https://portal.canis.cz/pl/x_p129718', $ps->sourceUrl);
        $this->assertSame('Na stanie: 1 szt. (Na zamówienie)', $ps->members[0]['availability']);

        // 3M bez tabeli wariantów: jedna pozycja z nagłówka, marka ze „Znaku”, cena zaokrąglona jak w tabelach
        $filter = $products['4520-008-000-01'];
        $this->assertSame('3M', $filter->raw['manufacturer']);
        $this->assertSame(9.16, $filter->raw['price']->net);
        $this->assertSame([['remote_id' => '4520-008-000-01', 'availability' => 'Na stanie: 5106 para', 'size' => '4520-008-000-01']], array_map(
            static fn (array $m): array => array_intersect_key($m, array_flip(['remote_id', 'size', 'availability'])),
            $filter->members,
        ));
        $this->assertSame(['4046719550104', '4046719550111'], array_map(static fn ($i): string => $i->value, $filter->identifiers));
        $this->assertSame(CanisB2bConnector::documentKind('declaration'), $filter->raw['documents'][0]['kind']);
    }

    public function test_cards_do_not_depend_on_menu_or_list_order(): void
    {
        $this->fakeSite();
        $first = $this->cardsSignature($this->productsBySku());

        Http::fake([]);
        $this->menuOrder = array_reverse($this->menuOrder);
        foreach ($this->lists as $path => $pages) {
            $this->lists[$path] = array_reverse(array_map('array_reverse', $pages));
        }
        $this->fakeSite();

        $this->assertSame($first, $this->cardsSignature($this->productsBySku()));
    }

    public function test_lost_session_is_renewed_once_and_a_portal_refusing_every_session_is_fatal(): void
    {
        $this->forgetAfterPages = 3;
        $this->fakeSite();
        $products = $this->productsBySku();
        $this->assertCount(7, $products);
        $this->assertSame(2, $this->logins);

        // portal przyjmuje hasło, ale żadna strona nie ma już sesji: jedno ponowne logowanie, potem błąd krytyczny
        $connector = $this->connector();
        $this->neverLoggedIn = true;
        try {
            iterator_to_array($connector->products(), false);
            $this->fail('portal bez sesji powinien przerwać przebieg');
        } catch (B2bFatalException $e) {
            $this->assertStringContainsString('Utracono sesję konta portal.canis.cz', $e->getMessage());
        }
    }

    public function test_foreign_currency_switches_the_session_to_pln_once(): void
    {
        $this->currency = 'EUR';
        $this->fakeSite();
        $products = $this->productsBySku();

        $this->assertSame(9.16, $products['4520-008-000-01']->raw['price']->net);
        $this->assertGreaterThan(0, $this->requestsTo('/pl?cid=PLN'));
    }

    public function test_short_list_and_too_many_unreadable_pages_stop_the_run_without_cards(): void
    {
        $this->fakeSite();
        $connector = new CanisB2bConnector($this->client());
        try {
            iterator_to_array($connector->products(), false);
            $this->fail('lista 6 kafli przy minimum 200 powinna przerwać przebieg');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString('ma tylko 6 kafli', $e->getMessage());
        }

        // jedna strona nieodczytana (po drugiej próbie) mieści się w tolerancji — w dzienniku, reszta kart jest
        $this->failingPages = ['/pl/filtr-3m-5911-1-para-2-szt._p97960'];
        $connector = $this->connector();
        $skus = array_map(static fn (B2bRemoteProduct $p): string => $p->sku, iterator_to_array($connector->products(), false));
        $this->assertNotContains('4520-008-000-01', $skus);
        $this->assertCount(6, $skus);
        $this->assertSame(2, $this->requestsTo('/pl/filtr-3m-5911-1-para-2-szt._p97960'));
        $this->assertStringContainsString('Strony wyrobów nieodczytane', implode("\n", $connector->runSummary()));

        // dwie z pięciu — ponad 2%: przebieg przerwany przed oddaniem kart
        $this->failingPages[] = '/pl/bluza-cxs-sirius-lucius-meska-kolor-niebiesko-szary_p88905';
        try {
            iterator_to_array($this->connector()->products(), false);
            $this->fail('dwie nieodczytane strony z pięciu powinny przerwać przebieg');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString('2 stron wyrobów portal.canis.cz nieodczytanych także za drugim razem', $e->getMessage());
        }
    }

    public function test_page_without_mark_is_canis_unless_the_supplier_is_a_known_brand_and_both_are_logged(): void
    {
        $colour = '/pl/bluza-cxs-sirius-lucius-meska-kolor-niebiesko-szary_p88905';
        $filter = '/pl/filtr-3m-5911-1-para-2-szt._p97960';
        $this->withoutMark = [$colour, $filter];
        $this->suppliers = [$colour => 'VIZWELL INTERNATIONAL INC, 15/F, Highgrade Bldng, 117 Chatham Rd., Tsimshatsui'];
        $this->fakeSite();
        $connector = $this->connector();
        $products = [];
        foreach ($connector->products() as $product) {
            $products[$product->sku] = $product;
        }

        // wyrób katalogu Canis bez marki CXS (fabryka OEM) — Canis; filtr bez „Znaku”, dostawca 3M Česko — 3M
        $this->assertSame('Canis', $products['1010-001-410-00']->raw['manufacturer']);
        $this->assertContains(
            'Producent/Dostawca | VIZWELL INTERNATIONAL INC, 15/F, Highgrade Bldng, 117 Chatham Rd., Tsimshatsui',
            array_map(static fn (array $f): string => $f['name'].' | '.$f['value'], $products['1010-001-410-00']->raw['fields']),
        );
        $this->assertSame('3M', $products['4520-008-000-01']->raw['manufacturer']);
        $summary = implode("\n", $connector->runSummary());
        $this->assertStringContainsString('1010-001-410-46 → Canis (VIZWELL INTERNATIONAL INC)', $summary);
        $this->assertStringContainsString('4520-008-000-01 → 3M (3M Česko)', $summary);
    }

    public function test_files_and_images_are_typed_from_their_bytes(): void
    {
        $this->fakeSite();
        $client = $this->client();

        $pdf = $client->fileBytes('https://portal.canis.cz/imgserver/eshop/canis/19/2000000352/17590-product.pdf');
        $this->assertSame('application/pdf', $pdf['mime']);
        $image = $client->fileBytes('https://portal.canis.cz/imgserver/eshop/canis/19/2000000326/17590-1010_001_00.jpg');
        $this->assertSame('image/webp', $image['mime']);

        $this->expectException(RuntimeException::class);
        $client->fileBytes('https://example.com/imgserver/x.pdf');
    }

    public function test_sync_creates_cards_with_size_rows_norms_documents_and_images_and_a_second_run_changes_nothing(): void
    {
        Storage::fake('public');
        Storage::fake('local');
        $this->fakeSite();
        $result = app(B2bAccountSyncRunner::class)->run($this->account(), delayMs: 0, withImages: true, connector: new CanisB2bConnector($this->client(), null, 1));

        $this->assertSame(7, $result['created'], implode(' | ', $result['errors']));
        $card = Product::query()->where('sku', '1010-001-410-00')->sole();
        $this->assertSame('Canis', $card->manufacturer);
        $this->assertSame('Bluza CXS SIRIUS LUCIUS, męska', $card->name);
        $this->assertStringContainsString('Męska bluza robocza', (string) $card->description);
        $slot = ProductSourcePrice::query()->where('product_id', $card->id)->sole();
        $this->assertSame('49.09', (string) $slot->purchase_price);
        // portal nie podaje ceny katalogowej — synchronizacja zapisuje wtedy cenę konta (jak u innych łączników)
        $this->assertSame('49.09', (string) $slot->catalog_price_net);
        $this->assertSame('67.27', (string) $slot->size_price_max);
        $this->assertSame('49.09', (string) ProductVariant::query()->where('product_id', $card->id)->where('kind', ProductVariant::KIND_SIZE)->where('label', 'kolor niebiesko-szary / 46')->value('purchase_price'));
        $this->assertSame('canis', $card->manufacturer_norms['source']['connector'] ?? null);
        $this->assertEqualsCanonicalizing(['EN ISO 13688', 'EN 340'], array_column($card->manufacturer_norms['rows'] ?? [], 'label'));
        $this->assertSame(3, ProductDocument::query()->where('product_id', $card->id)->count());
        // kolor 410 ma własną stronę ze zdjęciem; 708 tylko zdjęcie całego modelu SIRIUS (może to być BRIGHTON) — bez zdjęcia
        $this->assertSame(1, ProductImage::query()->where('product_id', $card->id)->count());
        $this->assertNotNull(ProductShopCard::query()->where('product_id', $card->id)->value('fields'));

        // obca marka: producent 3M, bez norm i opisu „producenta” z witryny Canis
        $filter = Product::query()->where('sku', '4520-008-000-01')->sole();
        $this->assertSame('3M', $filter->manufacturer);
        $this->assertNull($filter->manufacturer_norms);
        $this->assertSame(
            ['4046719550104', '4046719550111'],
            ProductIdentifier::query()->where('product_id', $filter->id)->where('type', ProductIdentifier::TYPE_EAN)->orderBy('value')->pluck('value')->all(),
        );

        $before = $this->snapshot();
        $second = app(B2bAccountSyncRunner::class)->run($this->account(), delayMs: 0, withImages: true, connector: new CanisB2bConnector($this->client(), null, 1));

        $this->assertSame(0, $second['created'], implode(' | ', $second['errors']));
        $this->assertSame(0, $second['updated'], implode(' | ', $second['errors']));
        $this->assertSame(7, $second['unchanged'], implode(' | ', $second['errors']));
        $this->assertSame($before, $this->snapshot());
    }

    public function test_registry_detects_canis_by_host_as_the_manufacturer_site(): void
    {
        $registry = app(B2bConnectorRegistry::class);

        $this->assertSame('canis', $registry->keyForSites(['https://portal.canis.cz/pl']));
        $this->assertSame('Canis', $registry->label('canis'));
        $this->assertTrue($registry->requiresPassword('canis'));
        $this->assertTrue($registry->isManufacturerSite('canis'));
        $this->assertTrue($registry->groupsSizes('canis'));
        $this->assertTrue($registry->sendsSizePrices('canis'));
        $this->assertSame(['brand' => 'Canis', 'names' => ['Normy']], $registry->shopFieldNormSource('canis'));

        $account = B2bAccount::query()->create(['username' => self::USER, 'password' => 'sekret', 'sites' => ['https://portal.canis.cz/pl']]);
        $connector = $registry->make($account, 0);

        $this->assertInstanceOf(CanisB2bConnector::class, $connector);
        foreach ([B2bManufacturerSite::class, B2bShopFieldSource::class, B2bShopFieldNormSource::class, B2bDocumentSource::class, B2bImageGallery::class, B2bGroupsSizes::class, B2bSizePriceSource::class] as $interface) {
            $this->assertInstanceOf($interface, $connector);
        }
    }

    // ---- pomocnicze ----

    private function client(): CanisB2bClient
    {
        return new CanisB2bClient(self::USER, self::PASSWORD, 0, static function (int $ms): void {});
    }

    private function connector(): CanisB2bConnector
    {
        $connector = new CanisB2bConnector($this->client(), null, 1);
        $connector->login();

        return $connector;
    }

    /**
     * @return array<string, B2bRemoteProduct>
     */
    private function productsBySku(): array
    {
        $out = [];
        foreach ($this->connector()->products() as $product) {
            $this->assertSame('ok', $product->raw['status'] ?? null, (string) ($product->raw['reason'] ?? ''));
            $out[$product->sku] = $product;
        }
        ksort($out);

        return $out;
    }

    /**
     * @param  array<string, B2bRemoteProduct>  $products
     * @return array<string, mixed>
     */
    private function cardsSignature(array $products): array
    {
        return array_map(static fn (B2bRemoteProduct $p): array => [
            'remote' => $p->remoteId,
            'name' => $p->name,
            'members' => array_column($p->members, 'remote_id'),
            'source' => $p->sourceUrl,
            'fields' => $p->raw['fields'],
            'images' => $p->raw['images'],
        ], $products);
    }

    private function account(): B2bAccount
    {
        return B2bAccount::query()->firstOrCreate(
            ['username' => self::USER],
            ['password' => self::PASSWORD, 'sites' => ['https://portal.canis.cz/pl'], 'connector' => 'canis', 'sync_images' => true],
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
            'images' => ProductImage::query()->where('product_id', $p->id)->count(),
            'documents' => ProductDocument::query()->where('product_id', $p->id)->count(),
        ]])->all();
    }

    private function requestsTo(string $pathAndQuery): int
    {
        return Http::recorded(static function (Request $r) use ($pathAndQuery): bool {
            $url = $r->url();
            $path = (string) parse_url($url, PHP_URL_PATH).(($q = parse_url($url, PHP_URL_QUERY)) ? '?'.$q : '');

            return $path === $pathAndQuery;
        })->count();
    }

    private function fixture(string $name): string
    {
        return (string) file_get_contents(base_path('tests/Fixtures/canis/'.$name));
    }

    /**
     * Kafel listy w znacznikach portalu (jak list-skorzane.html).
     *
     * @return array{id: int, url: string, name: string}
     */
    private static function tile(int $id, string $name): array
    {
        $url = array_search($id, array_map(static fn (string $p): int => (int) substr($p, strrpos($p, '_p') + 2), array_combine(array_keys(self::PAGES), array_keys(self::PAGES))), true);

        return ['id' => $id, 'url' => (string) $url, 'name' => $name];
    }

    /** Strona główna: odnośnik wylogowania i menu kategorii w znacznikach portalu (obrazek + tytuł, jak w home). */
    private function home(): string
    {
        $menu = [
            '/pl/odziez-robocza_c5829687959888112' => 'Odzież robocza',
            self::BLUZY => 'Bluzy',
            '/pl/rekawice-robocze_c5829687959889797' => 'Rękawice robocze',
            self::SKORZANE => 'Skórzane',
            self::FILTRY => 'Filtry 3M',
        ];
        $items = '';
        foreach ($this->menuOrder as $leaf) {
            $parent = substr($leaf, 0, (int) strrpos($leaf, '/'));
            foreach (array_filter([$parent !== '/pl' ? $parent : null, $leaf]) as $path) {
                $items .= '<li data-k2="item" class="flex "><a href="'.$path.'" title="'.$menu[$path].'" class="img_catg flex justify_center align_top"><img data-src="https://portal.canis.cz/imgserver/eshop/canis/781/2000000329/x.png?w=95" alt="x" class="js_lazy_img"></a>'
                    .'<span class="sub_wrap spacing_left"><span class="flex"><a href="'.$path.'" title="'.$menu[$path].'" class="title flex"><span>'.$menu[$path].'</span></a></span></span></li>';
                $items .= '<li><a href="'.$path.'" class="flex">Wszystko z kategorii</a></li>';
            }
        }

        return '<!DOCTYPE html><html lang="pl"><head><meta charset="UTF-8"><title>CANIS B2B portál</title></head><body><header>'
            .($this->sessionValid() ? '<a href="/pl/wylogowanie-uzytkownika" title="Wyloguj">Wyloguj</a>' : '')
            .'<nav class="k2tx k2tx1menu"><ul data-k2="container">'.$items.'</ul></nav></header><main id="k2axPageContent"><section class="section"></section></main></body></html>';
    }

    /**
     * Strona listy w znacznikach portalu: kafle z ceną „od”, blok bestsellerów z obcym kaflem (nie liczy się) i odnośnik
     * ostatniej strony.
     *
     * @param  list<array{id: int, url: string, name: string}>  $tiles
     */
    private function listPage(array $tiles, int $page, int $last): string
    {
        $html = '<div class="bestseller_item"><div class="product_item_best" data-product-id="555"><a href="/pl/obcy_p555" class="product_item_title">Obcy</a></div></div>';
        $html .= '<div id="products" class="products_wrap"><div data-k2="container" class="row col flex flex_wrap">';
        foreach ($tiles as $tile) {
            $html .= '<div data-k2="item" class="col_4 col_4_lg col_6_md col_12_sm  k2item k2item'.$tile['id'].'"><div class="product_item spacing relative full_height flex flex_col" data-product-id="'.$tile['id'].'">'
                .'<a href="'.$tile['url'].'" title="'.$tile['name'].'" class="product_item_imgwrap full_wdith relative product_link_click gtag_product_click"><div class="product_item_img flex align_center justify_center"><img data-src="https://portal.canis.cz/imgserver/eshop/canis/19/2000000325/'.$tile['id'].'-x.jpg?w=408" class="js_lazy_img"></div></a>'
                .'<div class="item_data_wrap flex flex_col justify_between full_height"><div class="item_text_info"><a href="'.$tile['url'].'" title="'.$tile['name'].'" class="product_item_title product_link_click gtag_product_click text_decoration_none block text_center underline bold ">'.$tile['name'].'</a></div>'
                .'<div class="product_item_price_wrap text_center bold"><span><span>od </span><span class="symbol_left color_main" data-left="0"><span data-price="1,00">1,00</span><span> zł</span></span></span></div></div></div></div>';
        }
        $html .= '</div></div><div data-k2="pagination" class="pagination flex">';
        if ($last > 1) {
            $html .= '<a data-k2="pagLast" class="pagination_last pagination_link k2ajax" href="?p='.$last.'"></a>';
        }

        return '<!DOCTYPE html><html lang="pl"><body><header>'.($this->sessionValid() ? '<a href="/pl/wylogowanie-uzytkownika">Wyloguj</a>' : '')
            .'</header><main id="k2axPageContent">'.$html.'</div></main></body></html>';
    }

    private function sessionValid(): bool
    {
        return $this->loggedIn && ! $this->neverLoggedIn;
    }

    private function fakeSite(): void
    {
        Http::fake(function (Request $request) {
            $url = $request->url();
            $path = (string) parse_url($url, PHP_URL_PATH);
            parse_str((string) parse_url($url, PHP_URL_QUERY), $query);
            if (! str_starts_with($url, 'https://portal.canis.cz/')) {
                return Http::response('', 404);
            }
            if (str_starts_with($path, '/imgserver/')) {
                // jak portal: PDF bez Content-Type, zdjęcie „.jpg” jako WebP; treść różna dla każdego pliku
                return str_ends_with($path, '.pdf')
                    ? Http::response("%PDF-1.7\n% ".$path."\n", 200, [])
                    : Http::response('RIFF'.pack('V', 4).'WEBPVP8 '.$path, 200, ['Content-Type' => 'image/webp']);
            }
            if ($request->method() === 'POST' && $path === '/standard/m2user/post.php') {
                $data = $request->data();
                if (($data['l'] ?? '') === '1' && ($data['usr'] ?? '') === self::USER && ($data['pwd'] ?? '') === self::PASSWORD) {
                    $this->loggedIn = true;
                    $this->logins++;
                    $this->pagesServed = 0;

                    return Http::response('1');
                }

                return Http::response('7');
            }
            if ($path === '/pl') {
                if (($query['cid'] ?? null) === 'PLN') {
                    $this->currency = 'PLN';
                }

                return Http::response($this->home());
            }
            if ($this->forgetAfterPages > 0 && ++$this->pagesServed > $this->forgetAfterPages) {
                $this->loggedIn = false;
                $this->forgetAfterPages = 0;
            }
            if (isset($this->lists[$path])) {
                $pages = $this->lists[$path];
                $page = max(1, (int) ($query['p'] ?? 1));
                if (($query['s'] ?? null) !== '1') {
                    return Http::response('sortowanie nie podane', 500);
                }

                return Http::response($this->listPage($pages[$page - 1] ?? [], $page, count($pages)));
            }
            if (in_array($path, $this->failingPages, true)) {
                return Http::response('błąd', 500);
            }
            if ($path === '/pl/x_p18278') {
                return Http::response($this->guarded($this->fixture('not-found.html')), 404);
            }
            if (isset(self::PAGES[$path])) {
                $html = $this->guarded($this->fixture(self::PAGES[$path]));
                if (in_array($path, $this->withoutMark, true)) {
                    $html = (string) preg_replace('#<tr data-k2="parametersItemItem"><td>Znak</td>.*?</tr>#s', '', $html);
                }
                if (isset($this->suppliers[$path])) {
                    $html = (string) preg_replace('#(<td>Producent/Dostawca</td><td><div class="hide param_title">\s*Producent/Dostawca</div><span>\s*)[^<]*#', '${1}'.$this->suppliers[$path], $html);
                }
                if ($this->currency !== 'PLN') {
                    $html = str_replace(['content="PLN"', 'data-currency="zł"'], ['content="'.$this->currency.'"', 'data-currency="€"'], $html);
                }

                return Http::response($html);
            }

            return Http::response('nie ma', 404);
        });
    }

    /** Strona bez sesji = bez odnośnika wylogowania (portal pokazuje gościowi stronę zamkniętą). */
    private function guarded(string $html): string
    {
        return $this->sessionValid() ? $html : str_replace('/pl/wylogowanie-uzytkownika', '/pl/login', $html);
    }
}
