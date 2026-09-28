<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\B2bAccount;
use App\Models\B2bProductLink;
use App\Models\B2bSyncRun;
use App\Models\PriceList;
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
use App\Services\B2b\B2bImageGallery;
use App\Services\B2b\B2bListProgressAware;
use App\Services\B2b\B2bManufacturerSite;
use App\Services\B2b\B2bNormFactSource;
use App\Services\B2b\B2bRemoteIdentifier;
use App\Services\B2b\B2bRemoteProduct;
use App\Services\B2b\B2bRunSummaryAware;
use App\Services\B2b\B2bShopFieldSource;
use App\Services\B2b\DeltaplusB2bClient;
use App\Services\B2b\DeltaplusB2bConnector;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use RuntimeException;
use Tests\TestCase;

/**
 * Łącznik www.deltaplus.eu na atrapie witryny (Http::fake, bez prawdziwego logowania). Układ stron odwzorowuje
 * witrynę z 21.09.2026 (Liferay DXP): formularz logowania portletu LoginPortlet na /pl/espace-pro-landing (obok
 * formularz innego portletu z polem hasła), strony list kategorii /pl/dp/{kategoria}?start=N z kafelkami /p/{slug},
 * karta wyrobu /pl/p/{slug} ze ścieżką nawigacji, nagłówkiem, kolorami, galerią (z elementem 360°), zakładkami
 * opisu, sektorów/zagrożeń, norm (kafelek kategorii CE z pustym nagłówkiem), zalet, tabelą referencji (w widoku
 * zalogowanym z kolumnami dostępności i ceny, notką nad tabelą, U+200B w kolorach i U+200E po „zł”) i plików
 * (href bez cudzysłowów), odnośnik /c/portal/logout tylko w widoku zalogowanym oraz strona oferty z linkiem do
 * „Cennika publicznego” w xlsx.
 *
 * Wszystkie dane (wyroby, kody, EAN, ceny, pliki, zdjęcia) są SYNTETYCZNE.
 */
final class DeltaplusConnectorTest extends TestCase
{
    use RefreshDatabase;

    private const USER = 'handel@example.com';

    private const PASSWORD = 'dobre-haslo';

    private const P_AUTH = 'Xy12AbCd';

    private const LOGIN_NS = '_com_liferay_login_web_portlet_LoginPortlet_';

    private const LOGIN_ACTION = 'https://www.deltaplus.eu/pl/espace-pro-landing?p_p_id=com_liferay_login_web_portlet_LoginPortlet'
        .'&p_p_lifecycle=1&p_p_state=normal&p_p_mode=view'
        .'&_com_liferay_login_web_portlet_LoginPortlet_javax.portlet.action=%2Flogin%2Flogin'
        .'&_com_liferay_login_web_portlet_LoginPortlet_mvcRenderCommandName=%2Flogin%2Flogin&p_auth='.self::P_AUTH;

    private const PRICE_LIST = 'delta-plus-cennik-publiczny-01-2026-1-xlsx';

    private const PROMO_LIST = 'promocja-zima-2026-2027-cennik-1-xlsx';

    private const ASSETS = '/documents/d/asset-library-13017324/';

    private const MENTION = '*Cena jednostkowa za pełny karton tego samego rozmiaru i koloru';

    private const NEPTUN_SHORT = 'Rękawice powlekane do prac w środowisku mokrym, dobrze widoczne dzięki elementom fluorescencyjnym';

    private const NEPTUN_SHORTCUT = 'Wkład : Dzianina - Poliester | Powłoka : Lateks - Teksturowana przyczepna | Ścieg : 13';

    private const MEDIA = 'https://media.deltaplus.eu/m/';

    private const ATTACHMENTS = '/o/commerce-media/accounts/-1/attachments/';

    private const ATTACHMENT_QUERY = '?download=true&locale=pl_PL&siteKey=pologne';

    private const PPE = 'https://delta-plus.ppe-analytics.com/p/pl/product/';

    /** @var array<string, array<string, mixed>> karty wyrobów wg slugu */
    private array $pages = [];

    /** @var array<string, array<int, list<string>>> slugi na stronach list: kategoria => [strona => slugi] */
    private array $lists = [];

    /** @var list<string> odnośniki na stronie oferty */
    private array $offerLinks = [];

    /** @var array<string, string> pliki biblioteki dokumentów: nazwa => bajty */
    private array $assetFiles = [];

    /** @var list<string> */
    private array $validSessions = [];

    private int $logins = 0;

    private int $anonymous = 0;

    /** Sesja wygasa przy następnym pobraniu karty wyrobu. */
    private bool $dropSessionOnProduct = false;

    /** Karty wyrobu nigdy nie widzą konta (witryna zawsze oddaje widok gościa). */
    private bool $productsAlwaysAnonymous = false;

    /** @var list<string> slugi kart w kolejności pobrań */
    private array $productRequests = [];

    protected function setUp(): void
    {
        parent::setUp();
        Queue::fake();
    }

    public function test_login_posts_the_liferay_form_fields_to_the_decoded_action_without_remember_me(): void
    {
        $this->fakeSite();
        $client = $this->client();

        $client->login();

        $this->assertTrue($client->isLoggedIn());
        $post = Http::recorded(fn (Request $r): bool => $r->method() === 'POST')->first()[0];
        // action formularza z &amp; — wysłany pod zdekodowany adres, z tokenem p_auth
        $this->assertSame(self::LOGIN_ACTION, $post->url());
        $data = $post->data();
        ksort($data);
        $expected = [
            self::LOGIN_NS.'formDate' => '1790000000000',
            self::LOGIN_NS.'saveLastPath' => 'false',
            self::LOGIN_NS.'redirect' => '',
            self::LOGIN_NS.'doActionAfterLogin' => 'false',
            self::LOGIN_NS.'checkboxNames' => 'rememberMe',
            self::LOGIN_NS.'login' => self::USER,
            self::LOGIN_NS.'password' => self::PASSWORD,
        ];
        ksort($expected);
        // tylko pola formularza logowania: bez pól formularza danych konta (drugie pole hasła na stronie)
        // i bez pola wyboru „Zapamiętaj mnie”
        $this->assertSame($expected, $data);
        $this->assertSame(DeltaplusB2bClient::BASE, $post->header('Origin')[0] ?? null);
        $this->assertSame(DeltaplusB2bClient::LOGIN_URL, $post->header('Referer')[0] ?? null);
        $this->assertSame(1, $this->logins);
    }

    public function test_wrong_password_fails_with_a_plain_runtime_exception(): void
    {
        $this->fakeSite();
        $client = new DeltaplusB2bClient(self::USER, 'zle-haslo', 0, static function (int $ms): void {});

        try {
            $client->login();
            $this->fail('Logowanie powinno się nie udać');
        } catch (RuntimeException $e) {
            $this->assertNotInstanceOf(B2bFatalException::class, $e);
            $this->assertStringStartsWith('Logowanie do deltaplus.eu nieudane', $e->getMessage());
        }
        $this->assertFalse($client->isLoggedIn());
        $this->assertSame(0, $this->logins);
    }

    public function test_list_page_gives_product_slugs_once_in_page_order_and_the_last_page(): void
    {
        // kafelek ma cztery odnośniki do tej samej karty; bywają adresy pełne, z /pl/, z zapytaniem i kotwicą
        $html = self::listHtml('hand-protection', [
            '/p/nx-100',
            '/p/nx-100',
            'https://www.deltaplus.eu/pl/p/nx-200?utm_source=lista#opis',
            '/pl/p/nx-300',
            'https://www.deltaplus.eu/p/nx-400',
            '/p/nx-100',
        ], lastPage: 4, page: 2, signedIn: true);

        $this->assertSame(['nx-100', 'nx-200', 'nx-300', 'nx-400'], DeltaplusB2bConnector::productSlugs($html));
        $this->assertSame(4, DeltaplusB2bConnector::lastPage($html));
        $this->assertSame(1, DeltaplusB2bConnector::lastPage(self::listHtml('head-protection', ['/p/nx-500'], 1, 1, true)));
        $this->assertSame([], DeltaplusB2bConnector::productSlugs(self::listHtml('foot-protection', [], 1, 1, true)));
    }

    public function test_parse_price_reads_polish_amounts_and_treats_zero_as_missing(): void
    {
        $this->assertSame(5.7, DeltaplusB2bConnector::parsePrice("5,70 zł\u{200E}"));
        $this->assertSame(1234.56, DeltaplusB2bConnector::parsePrice('1 234,56 zł'));
        $this->assertSame(1234.56, DeltaplusB2bConnector::parsePrice("1\u{00A0}234,56 zł"));
        $this->assertNull(DeltaplusB2bConnector::parsePrice("0,00 zł\u{200E}"));
        $this->assertNull(DeltaplusB2bConnector::parsePrice(''));
        $this->assertNull(DeltaplusB2bConnector::parsePrice('Termin do potwierdzenia'));
        // kropka jako separator tysięcy
        $this->assertSame(1234.56, DeltaplusB2bConnector::parsePrice('1.234,56 zł'));
        $this->assertSame(12.01, DeltaplusB2bConnector::parsePrice('12.01'));
    }

    public function test_price_list_reads_prices_written_as_text(): void
    {
        $prices = DeltaplusB2bConnector::publicPrices(self::xlsx([
            ['MODEL', 'STATUS', 'KOLOR', 'ILOŚĆ W KARTONIE', 'MIN ZAM.', 'OPIS', 'ROZMIARY', 'CENA PLN NETTO 01/2026'],
            ['NEPTUN TT733', '', 'ŻÓŁTY FLUO-CZARNY', 120, 12, 'Rękawice', '7-10', '12,01'],
            ['NEPTUN TT733', '', 'POMARAŃCZOWY FLUO-CZARNY', 120, 12, 'Rękawice', '7-10', 'na zapytanie'],
        ]));

        $this->assertSame(['NEPTUN TT733|ŻÓŁTY FLUO-CZARNY' => [12.01]], $prices);
    }

    /**
     * Do 28.09.2026 wersje w dwóch cenach były dwiema kartami, a Ref. równy referencji wersji („22180”) wymagał wyboru,
     * która karta dostaje Ref. Od decyzji użytkownika 28.09.2026 strona modelu to jedna karta ze wszystkimi wersjami
     * z ceną: SKU = Ref., bez Ref. — referencja pierwszej wersji z ceną; wersja bez ceny poza kartą.
     */
    public function test_all_priced_versions_of_a_model_page_are_one_card_with_the_page_ref_as_sku(): void
    {
        $version = static fn (string $ref, ?float $price): array => ['ref' => $ref, 'price' => $price];
        $versions = [$version('XA', 1.5), $version('X', 2.0), $version('XN', null), $version('XB', 1.5)];

        $cards = DeltaplusB2bConnector::pageCards(['ref' => 'X', 'versions' => $versions]);

        $this->assertSame(['X'], array_column($cards, 'sku'));
        $this->assertSame(['XA', 'X', 'XB'], array_column($cards[0]['versions'], 'ref'));
        $this->assertSame(['XA'], array_column(DeltaplusB2bConnector::pageCards(['ref' => '', 'versions' => $versions]), 'sku'));
        $this->assertSame([], DeltaplusB2bConnector::pageCards(['ref' => 'X', 'versions' => [$version('XN', null)]]));
    }

    /**
     * Kolumna Model tabeli referencji: różne modele na jednej stronie to różne wyroby (reguła 1 umowy 28.09.2026 —
     * z klucza znika tylko cena). Model bez wielkości liter i nadmiarowych odstępów; pusta komórka — model najczęstszy
     * na stronie. Ref. strony dostaje karta modelu z h1 albo — przed nim — karta z wersją, której referencja jest
     * równa Ref. (inaczej ta referencja zostałaby SKU drugiej karty); pozostałe karty — referencję pierwszej wersji.
     */
    public function test_page_with_two_models_is_one_card_per_model_with_the_ref_on_the_main_model(): void
    {
        $version = static fn (string $ref, string $model, float $price): array => ['ref' => $ref, 'model' => $model, 'price' => $price];
        $versions = [
            $version('YA', 'AERO Y', 2.0),
            $version('XA', 'AERO X', 1.5),
            $version('XB', '', 1.5),
            $version('YB', 'aero  y ', 2.1),
            $version('XC', 'AERO X', 1.6),
            $version('XD', 'AERO X', 1.5),
        ];

        $cards = DeltaplusB2bConnector::pageCards(['ref' => 'X', 'name' => 'AERO X', 'versions' => $versions]);

        $this->assertSame(
            [
                ['YA', 'AERO Y', false, ['YA', 'YB']],
                // pusta komórka Model (XB) — model najczęstszy (AERO X: 3 wersje)
                ['X', 'AERO X', true, ['XA', 'XB', 'XC', 'XD']],
            ],
            array_map(static fn (array $c): array => [$c['sku'], $c['model'], $c['main'], array_column($c['versions'], 'ref')], $cards),
        );

        // Ref. równy referencji wersji modelu AERO Y — Ref. dostaje karta AERO Y
        $cards = DeltaplusB2bConnector::pageCards(['ref' => 'YB', 'name' => 'AERO X', 'versions' => $versions]);
        $this->assertSame([['YB', true], ['XA', false]], array_map(static fn (array $c): array => [$c['sku'], $c['main']], $cards));
    }

    public function test_page_with_two_models_gives_two_cards_with_their_own_name_colours_and_catalog_price(): void
    {
        $page = self::aero();
        // wersja granatowo-pomarańczowa to na stronie inny model
        $page['models'] = ['TC100BMSH' => 'AERO TC200'];
        $this->pages['aero-tc100'] = $page;
        $this->lists['head-protection'] = [1 => ['aero-tc100']];
        $this->fakeSite();
        $connector = $this->connector();

        $cards = [];
        foreach ($connector->products() as $product) {
            $cards[$product->sku] = $product;
        }

        $this->assertSame(['TC100BMSH', 'TC100'], array_keys($cards));
        $this->assertSame(2, $connector->totalProducts());
        $main = $cards['TC100'];
        $other = $cards['TC100BMSH'];
        $this->assertSame(['AERO TC100', 'TC100NOSH', ['TC100NOSH', 'TC100NOLG']], [$main->name, $main->remoteId, array_column($main->members, 'remote_id')]);
        $this->assertSame(['AERO TC200', 'TC100BMSH', []], [$other->name, $other->remoteId, $other->members]);
        // Ref. strony opisuje model główny — tylko jego karta ma kod modelu (i wiersz „Ref.” w tabelce)
        $this->assertSame('TC100', $main->identifiers[0]->value);
        $this->assertNotContains(ProductIdentifier::TYPE_MODEL_CODE, array_map(static fn (B2bRemoteIdentifier $i): string => $i->type, $other->identifiers ?? []));
        $this->assertNotContains(['Informacje handlowe', 'Ref.', 'TC100'], self::fields($connector, $other));
        // cennik: AERO TC200 nie ma wiersza — bez ceny katalogowej (nie bierzemy ceny AERO TC100 z h1)
        $this->assertNull($connector->price($other)?->base);
        // zdjęcia i pliki: kolory swojej karty i te bez koloru
        $this->assertSame(
            [self::MEDIA.'a1b2c3d4e5f60001/Liferay_Product-AERO-TC100-NORO.png', self::MEDIA.'a1b2c3d4e5f60004/Liferay_Product-AERO-TC100-DETAIL.png'],
            $connector->imageUrls($main),
        );
        $this->assertSame(
            [self::MEDIA.'a1b2c3d4e5f60002/Liferay_Product-AERO-TC100-BMOR.png', self::MEDIA.'a1b2c3d4e5f60004/Liferay_Product-AERO-TC100-DETAIL.png'],
            $connector->imageUrls($other),
        );
        $this->assertSame(
            ['AERO TC100 BLEU MARINE-ORANGE (C036) technical-sheet PL', 'AERO TC100 UI'],
            array_map(static fn ($d): string => $d->title, $connector->documents($other)),
        );
    }

    /**
     * Zdjęcia i pliki liczone z pozycji produktu podanego łącznikowi, nie z całej strony: produkt z częścią pozycji
     * (dawna karta z podziału według ceny — B2bCatalogSync::syncMembersByCard) dostaje tylko kolory swoich pozycji.
     */
    public function test_images_and_documents_follow_the_colours_of_the_positions_of_the_given_product(): void
    {
        $this->pages['aero-tc100'] = self::aero();
        $this->lists['head-protection'] = [1 => ['aero-tc100']];
        $this->fakeSite();
        $connector = $this->connector();
        $card = iterator_to_array($connector->products(), false)[0];
        $part = static fn (array $refs): B2bRemoteProduct => new B2bRemoteProduct(
            remoteId: $refs[0],
            sku: $card->sku,
            name: $card->name,
            raw: $card->raw,
            members: count($refs) > 1
                ? array_values(array_filter($card->members, static fn (array $m): bool => in_array($m['remote_id'], $refs, true)))
                : [],
        );

        $this->assertSame(
            [self::MEDIA.'a1b2c3d4e5f60001/Liferay_Product-AERO-TC100-NORO.png', self::MEDIA.'a1b2c3d4e5f60004/Liferay_Product-AERO-TC100-DETAIL.png'],
            $connector->imageUrls($part(['TC100NOSH', 'TC100NOLG'])),
        );
        $this->assertSame(
            [self::MEDIA.'a1b2c3d4e5f60002/Liferay_Product-AERO-TC100-BMOR.png', self::MEDIA.'a1b2c3d4e5f60004/Liferay_Product-AERO-TC100-DETAIL.png'],
            $connector->imageUrls($part(['TC100BMSH'])),
        );
        $this->assertSame(
            ['AERO TC100 BLEU MARINE-ORANGE (C036) technical-sheet PL', 'AERO TC100 UI'],
            array_map(static fn ($d): string => $d->title, $connector->documents($part(['TC100BMSH']))),
        );
    }

    public function test_public_price_list_keys_rows_by_normalized_model_and_colour_and_skips_group_rows(): void
    {
        $prices = DeltaplusB2bConnector::publicPrices(self::xlsx(self::publicPriceRows(), [
            // drugi arkusz nie jest cennikiem publicznym
            ['MODEL', 'STATUS', 'KOLOR', 'ILOŚĆ W KARTONIE', 'MIN ZAM.', 'OPIS', 'ROZMIARY', 'CENA PLN NETTO 01/2026'],
            ['NEPTUN TT733', '', 'ŻÓŁTY FLUO-CZARNY', 120, 12, 'Promocja', '7-10', 1.0],
        ]));

        $this->assertSame([
            'NEPTUN TT733|ŻÓŁTY FLUO-CZARNY' => [12.01],
            'NEPTUN TT733|POMARAŃCZOWY FLUO-CZARNY' => [12.01],
            // ten sam model i kolor w dwóch wierszach o różnych cenach — obie ceny zostają
            'AERO TC100|CZARNO-CZERWONY' => [90.0, 95.0],
            // małe litery, podwójna spacja, spacja na końcu i U+200B — klucz znormalizowany
            'AERO TC100|GRANATOWO-POMARAŃCZOWY' => [97.0],
            'AM902|STALOWY' => [184.0],
        ], $prices);
    }

    public function test_price_list_link_is_the_public_xlsx_not_the_promotion_or_the_pdf(): void
    {
        $this->fakeSite();
        $client = $this->client();
        $client->login();

        $file = $client->publicPriceListXlsx();

        $this->assertNotNull($file);
        $this->assertSame(self::PRICE_LIST, $file['name']);
        $this->assertSame(DeltaplusB2bClient::BASE.self::ASSETS.self::PRICE_LIST, $file['url']);
        $this->assertSame($this->assetFiles[self::PRICE_LIST], $file['bytes']);

        $this->offerLinks = [self::ASSETS.self::PROMO_LIST];
        $this->assertNull($client->publicPriceListXlsx());
    }

    public function test_product_in_one_price_is_one_card_with_members_category_availability_and_versions(): void
    {
        $this->pages['neptun-tt733'] = self::neptun();
        $this->lists['hand-protection'] = [1 => ['neptun-tt733']];
        $this->fakeSite();
        $connector = $this->connector();

        $products = iterator_to_array($connector->products(), false);

        $this->assertCount(1, $products);
        $card = $products[0];
        $this->assertSame('TT733', $card->sku);
        $this->assertSame('TT733OR07', $card->remoteId);
        $this->assertSame('NEPTUN TT733', $card->name);
        $this->assertSame('Ochrona rąk > Ochrona mechaniczna do wszechstronnych zastosowań > Prace w środowisku mokrym', $card->category);
        $this->assertSame(DeltaplusB2bClient::productUrl('neptun-tt733'), $card->sourceUrl);
        $this->assertSame('https://www.deltaplus.eu/pl/p/neptun-tt733', $card->sourceUrl);
        $this->assertSame('Dostępne', $card->availability);
        $this->assertSame(
            'Wersje: Pomarańczowy fluo-czarny 07 (TT733OR07); Pomarańczowy fluo-czarny 08 (TT733OR08); '
            .'Pomarańczowy fluo-czarny 09 (TT733OR09); Pomarańczowy fluo-czarny 10 (TT733OR10); '
            .'Żółty fluo-czarny 07 (TT73307); Żółty fluo-czarny 08 (TT73308); '
            .'Żółty fluo-czarny 09 (TT73309); Żółty fluo-czarny 10 (TT73310)',
            $card->variantSummary,
        );
        $this->assertSame(
            ['TT733OR07', 'TT733OR08', 'TT733OR09', 'TT733OR10', 'TT73307', 'TT73308', 'TT73309', 'TT73310'],
            array_column($card->members, 'remote_id'),
        );
        $this->assertSame(array_column($card->members, 'remote_id'), array_column($card->members, 'sku'));
        $this->assertSame('NEPTUN TT733 Pomarańczowy fluo-czarny 07', $card->members[0]['name']);
        $this->assertSame('NEPTUN TT733 Żółty fluo-czarny 10', $card->members[7]['name']);
        // wersje w jednej cenie — każda i tak z własną ceną konta i katalogową (cennik: model + jej kolor)
        $this->assertSame(
            array_fill(0, 8, [5.7, 12.01, 'PLN']),
            array_map(static fn (array $m): array => [$m['price']->net, $m['price']->base, $m['price']->currency], $card->members),
        );
        $this->assertSame(['Pomarańczowy fluo-czarny 07', 'Dostępne'], [$card->members[0]['size'], $card->members[0]['availability']]);

        // identyfikatory dosłownie ze strony: Ref. modelu dla karty, referencja (kod producenta), EAN 13 i kod
        // kartonu każdej wersji na jej pozycji; SKU karty nie jest identyfikatorem
        $identifiers = array_map(
            static fn (B2bRemoteIdentifier $i): array => [$i->type, $i->value, $i->remoteId, $i->label, $i->field],
            $card->identifiers ?? [],
        );
        $this->assertCount(1 + 8 * 3, $identifiers);
        $this->assertSame(
            [
                [ProductIdentifier::TYPE_MODEL_CODE, 'TT733', null, null, 'Ref.'],
                [ProductIdentifier::TYPE_MANUFACTURER_CODE, 'TT733OR07', 'TT733OR07', 'Pomarańczowy fluo-czarny 07', 'Referencja'],
                [ProductIdentifier::TYPE_EAN, '3200000000011', 'TT733OR07', 'Pomarańczowy fluo-czarny 07', 'EAN 13'],
                [ProductIdentifier::TYPE_PACK_EAN, '13200000000011', 'TT733OR07', 'Pomarańczowy fluo-czarny 07', 'Kod kartonu'],
            ],
            array_slice($identifiers, 0, 4),
        );
        $this->assertSame(
            [
                [ProductIdentifier::TYPE_MANUFACTURER_CODE, 'TT73310', 'TT73310', 'Żółty fluo-czarny 10', 'Referencja'],
                [ProductIdentifier::TYPE_EAN, '3200000000080', 'TT73310', 'Żółty fluo-czarny 10', 'EAN 13'],
                [ProductIdentifier::TYPE_PACK_EAN, '13200000000080', 'TT73310', 'Żółty fluo-czarny 10', 'Kod kartonu'],
            ],
            array_slice($identifiers, -3),
        );
        // każdy identyfikator wersji wskazuje pozycję karty
        $this->assertSame([], array_diff(
            array_filter(array_column($identifiers, 2)),
            array_column($card->members, 'remote_id'),
        ));
        $this->assertNotContains('TT733', array_column(array_slice($identifiers, 1), 1));

        $this->assertSame('Delta Plus', $connector->manufacturer($card));
        $price = $connector->price($card);
        $this->assertNotNull($price);
        $this->assertSame(5.7, $price->net);
        $this->assertSame(12.01, $price->base);
        $this->assertSame(round((1 - 5.7 / 12.01) * 100, 2), $price->discountPercent);
        $this->assertSame('PLN', $price->currency);
        // „Min. zam.” 12 we wszystkich referencjach: samo minimum — kroku witryna nie podaje, koszyk go nie wymusza
        // (Liferay multipleOrderQuantity 0), jednostki tabela nie podaje
        $this->assertSame(
            ['order_min_qty' => 12.0, 'order_step_qty' => null, 'order_unit' => null, 'order_varies' => false],
            $price->order?->slotValues(),
        );
    }

    public function test_references_of_one_card_with_different_minimum_orders_give_a_size_dependent_condition(): void
    {
        $page = self::neptun();
        $page['rows'][0][6] = '6';
        $this->pages['neptun-tt733'] = $page;
        $this->lists['hand-protection'] = [1 => ['neptun-tt733']];
        $this->fakeSite();
        $connector = $this->connector();

        $card = iterator_to_array($connector->products(), false)[0];

        $this->assertSame(
            ['order_min_qty' => null, 'order_step_qty' => null, 'order_unit' => null, 'order_varies' => true],
            $connector->price($card)?->order?->slotValues(),
        );
    }

    public function test_account_price_carries_the_full_carton_condition_with_the_carton_of_all_references(): void
    {
        // NEPTUN: kartony 120 i 100 (rozmiar 10) — przypis jest, ilości kartonu karta nie ma jednej
        $this->pages['neptun-tt733'] = self::neptun();
        $uniform = self::neptun();
        foreach (array_keys($uniform['rows']) as $i) {
            $uniform['rows'][$i][5] = '120';
        }
        $this->lists['hand-protection'] = [1 => ['neptun-tt733']];
        $this->fakeSite();
        $connector = $this->connector();

        $card = iterator_to_array($connector->products(), false)[0];

        $this->assertSame(
            ['price_note' => 'Cena jednostkowa za pełny karton tego samego rozmiaru i koloru', 'price_carton_qty' => null],
            $connector->price($card)?->condition?->slotValues(),
        );

        $this->pages['neptun-tt733'] = $uniform;
        $card = iterator_to_array($this->connector()->products(), false)[0];
        $this->assertSame(
            ['price_note' => 'Cena jednostkowa za pełny karton tego samego rozmiaru i koloru', 'price_carton_qty' => 120.0],
            $this->connector()->price($card)?->condition?->slotValues(),
        );
    }

    public function test_sync_stores_the_price_condition_and_reports_only_a_change_of_a_known_one(): void
    {
        Storage::fake('public');
        Storage::fake('local');
        $page = self::neptun();
        foreach (array_keys($page['rows']) as $i) {
            $page['rows'][$i][5] = '120';
        }
        $this->pages['neptun-tt733'] = $page;
        $this->lists['hand-protection'] = [1 => ['neptun-tt733']];
        $this->fakeSite();
        $slot = fn (): ProductSourcePrice => ProductSourcePrice::query()
            ->where('product_id', Product::query()->where('sku', 'TT733')->value('id'))
            ->sole();
        $fields = fn (): array => collect(PriceList::query()->latest('id')->firstOrFail()->updated_products)
            ->firstWhere('sku', 'TT733')['fields'] ?? [];

        app(B2bAccountSyncRunner::class)->run($this->account(), delayMs: 0);
        $this->assertSame('Cena jednostkowa za pełny karton tego samego rozmiaru i koloru', $slot()->price_note);
        $this->assertSame(120.0, $slot()->price_carton_qty);

        // slot sprzed wdrożenia (bez przypisu): pierwszy zapis przypisu nie jest zmianą karty
        ProductSourcePrice::query()->update(['price_note' => null, 'price_carton_qty' => null]);
        app(B2bAccountSyncRunner::class)->run($this->account(), delayMs: 0);
        $this->assertSame(120.0, $slot()->price_carton_qty);
        $this->assertNotContains('warunek ceny', $fields());

        // karton zmienił się u dostawcy — to zmiana warunku ceny
        foreach (array_keys($page['rows']) as $i) {
            $page['rows'][$i][5] = '100';
        }
        $this->pages['neptun-tt733'] = $page;
        app(B2bAccountSyncRunner::class)->run($this->account(), delayMs: 0);
        $this->assertSame(100.0, $slot()->price_carton_qty);
        $this->assertContains('warunek ceny', $fields());
    }

    public function test_card_content_description_shop_fields_norms_documents_and_images(): void
    {
        $this->pages['neptun-tt733'] = self::neptun();
        $this->lists['hand-protection'] = [1 => ['neptun-tt733']];
        $this->fakeSite();
        $connector = $this->connector();
        $card = iterator_to_array($connector->products(), false)[0];

        // opis: tylko krótki opis i zalety, dosłownie; linia skrótu i tabelki idą do shopFields
        $this->assertSame(
            self::NEPTUN_SHORT."\n\nWidzialność\nElementy fluorescencyjne ułatwiają dostrzeżenie rękawicy"
            ."\n\nSprawność\nLekka konstrukcja zapewnia dobrą zręczność",
            $connector->description($card),
        );

        $references = [
            ['TT733OR07', 'Pomarańczowy fluo-czarny 07', '3200000000011'],
            ['TT733OR08', 'Pomarańczowy fluo-czarny 08', '3200000000028'],
            ['TT733OR09', 'Pomarańczowy fluo-czarny 09', '3200000000035'],
            ['TT733OR10', 'Pomarańczowy fluo-czarny 10', '3200000000042'],
            ['TT73307', 'Żółty fluo-czarny 07', '3200000000059'],
            ['TT73308', 'Żółty fluo-czarny 08', '3200000000066'],
            ['TT73309', 'Żółty fluo-czarny 09', '3200000000073'],
            ['TT73310', 'Żółty fluo-czarny 10', '3200000000080'],
        ];
        $this->assertSame([
            ['Opis', 'Skrót', self::NEPTUN_SHORTCUT],
            ['Rodzaj produktu', 'Kolekcja rękawic', 'Rękawice dzianinowe powlekane'],
            ['Materiał', 'Materiał powłoki', 'Lateks'],
            ['Materiał', 'Włókno', 'Poliester'],
            // rozcięcie na pierwszym „ : ” — reszta wartości dosłownie
            ['Koncepcja', 'Długość', '24 cm : mierzona od nadgarstka'],
            ['Rozmiary i kolory', 'Rozmiary', '7 - 8 - 9 - 10'],
            // wiersz bez „ : ” — nazwą jest tytuł strefy
            ['Opakowanie', 'Opakowanie', 'w indywidualnym opakowaniu'],
            ['Normy', 'Kategoria ŚOI', 'Kategoria II'],
            ['Normy', 'EN 388', '2 1 2 1 X'],
            ['Normy', 'Norma', 'EN ISO 21420'],
            ['Zastosowanie', 'Sektory', 'Rolnictwo; Roboty publiczne; Prace remontowe / Rzemiosło'],
            ['Zastosowanie', 'Zagrożenia', 'Zużycie'],
            ...array_map(
                static fn (array $r): array => ['Informacje handlowe', 'Referencja', $r[0].': '.$r[1].', EAN '.$r[2]],
                $references,
            ),
            // Ref. strony dosłownie — SKU karty to Ref. bez końcowego podkreślnika
            ['Informacje handlowe', 'Ref.', 'TT733'],
            ['Informacje handlowe', 'Ilość w kartonie', '120, 100'],
            ['Informacje handlowe', 'Minimalne zamówienie', '12'],
            ['Informacje handlowe', 'Cena', 'Cena jednostkowa za pełny karton tego samego rozmiaru i koloru'],
        ], self::fields($connector, $card));

        // kafelek kategorii CE nie jest normą; norma bez poziomu ma value null; poziom dosłownie
        $this->assertSame(
            [['EN 388', '2 1 2 1 X'], ['EN ISO 21420', null]],
            array_map(static fn ($f): array => [$f->label, $f->value], $connector->normFacts($card)),
        );

        // karty techniczne pierwsze, potem deklaracja, instrukcja; powtórzony plik raz; tytuł = tekst odnośnika
        $this->assertSame([
            ['NEPTUN TT733 ORANGE FLUO-NOIR (C224) technical-sheet PL', ProductDocument::KIND_DATASHEET, self::attachment('9000001')],
            ['NEPTUN TT733 JAUNE FLUO-NOIR (C218) technical-sheet PL', ProductDocument::KIND_DATASHEET, self::attachment('9000002')],
            ['NEPTUN TT733 dceuepi-certificate PL', ProductDocument::KIND_CERTIFICATE, self::PPE.'0123456789abcdef0123456789abcdef/pdf/dceuepi-certificate'],
            ['GLOVES EN ISO 21420 EN388 UI TEST', ProductDocument::KIND_MANUAL, self::MEDIA.'9f9f9f9f9f9f9f9f/original/UI-GLOVES-TEST.pdf'],
        ], array_map(static fn ($d): array => [$d->title, $d->kind, $d->sourceUrl], $connector->documents($card)));

        // zdjęcia galerii w kolejności, bez elementu 360° i bez powtórzeń
        $this->assertSame(self::neptunImages(), $connector->imageUrls($card));

        // plik z commerce-media przychodzi jako application/octet-stream — rozpoznany po sygnaturze
        $file = $connector->documentBytes($connector->documents($card)[0]);
        $this->assertSame('application/pdf', $file['mime']);
        $this->assertStringStartsWith('%PDF-', $file['bytes']);
    }

    /**
     * Do 28.09.2026 (decyzja 15.09.2026) wersje AERO w dwóch cenach były dwiema kartami („TC100” z wersjami
     * czarno-czerwonymi za 45,00 i „TC100BMSH” granatowo-pomarańczowa za 48,50), każda ze zdjęciami i plikami swoich
     * kolorów. Od decyzji użytkownika 28.09.2026 to jedna karta z Ref. modelu: każda wersja ze swoją ceną konta
     * i katalogową (cennik: model + kolor tej wersji, tylko jednoznaczna i wyższa od ceny konta wersji — nigdy cena
     * innej wersji), cena karty = najtańsza wersja, zdjęcia i pliki wszystkich kolorów z ceną.
     */
    public function test_versions_in_two_prices_are_one_card_with_all_colours_and_catalog_price_per_version_only_when_unambiguous(): void
    {
        $this->pages['aero-tc100'] = self::aero();
        $this->lists['head-protection'] = [1 => ['aero-tc100']];
        $this->fakeSite();
        $connector = $this->connector();

        $products = iterator_to_array($connector->products(), false);

        $this->assertCount(1, $products);
        $card = $products[0];
        $this->assertSame('TC100', $card->sku);
        // pierwsza wersja z ceną w tabeli
        $this->assertSame('TC100BMSH', $card->remoteId);
        $this->assertSame('AERO TC100', $card->name);
        // CZARNO-CZERWONY ma w cenniku dwa wiersze o różnych cenach — ta wersja nie ma ceny katalogowej
        $this->assertSame(
            [
                ['TC100BMSH', 'Granatowo-pomarańczowy Krótki daszek', 'Dostępne', 48.5, 97.0, 50.0],
                ['TC100NOSH', 'Czarno-czerwony Krótki daszek', 'Dostępne', 45.0, null, 0.0],
                ['TC100NOLG', 'Czarno-czerwony Długi daszek', 'Termin do potwierdzenia', 45.0, null, 0.0],
            ],
            array_map(static fn (array $m): array => [
                $m['remote_id'], $m['size'] ?? null, $m['availability'] ?? null,
                $m['price']->net, $m['price']->base, $m['price']->discountPercent,
            ], $card->members),
        );
        $this->assertSame('TC100BMSH: Dostępne; TC100NOSH: Dostępne; TC100NOLG: Termin do potwierdzenia', $card->availability);
        $this->assertSame(
            'Wersje: Granatowo-pomarańczowy Krótki daszek (TC100BMSH); Czarno-czerwony Krótki daszek (TC100NOSH); '
            .'Czarno-czerwony Długi daszek (TC100NOLG)',
            $card->variantSummary,
        );
        // Ref. modelu i wersje z ceną; wersja bez ceny (TC100JFSH) poza kartą
        $this->assertSame(
            [
                [ProductIdentifier::TYPE_MODEL_CODE, 'TC100', null],
                [ProductIdentifier::TYPE_MANUFACTURER_CODE, 'TC100BMSH', 'TC100BMSH'],
                [ProductIdentifier::TYPE_EAN, '3200000000110', 'TC100BMSH'],
                [ProductIdentifier::TYPE_PACK_EAN, '13200000000110', 'TC100BMSH'],
                [ProductIdentifier::TYPE_MANUFACTURER_CODE, 'TC100NOSH', 'TC100NOSH'],
                [ProductIdentifier::TYPE_EAN, '3200000000127', 'TC100NOSH'],
                [ProductIdentifier::TYPE_PACK_EAN, '13200000000127', 'TC100NOSH'],
                [ProductIdentifier::TYPE_MANUFACTURER_CODE, 'TC100NOLG', 'TC100NOLG'],
                [ProductIdentifier::TYPE_EAN, '3200000000141', 'TC100NOLG'],
                [ProductIdentifier::TYPE_PACK_EAN, '13200000000141', 'TC100NOLG'],
            ],
            array_map(static fn (B2bRemoteIdentifier $i): array => [$i->type, $i->value, $i->remoteId], $card->identifiers ?? []),
        );

        // cena karty = najtańsza wersja z jej własną (tu: brakującą) ceną katalogową — nie katalogowa droższej wersji
        $price = $connector->price($card);
        $this->assertSame(45.0, $price?->net);
        $this->assertNull($price->base);
        $this->assertSame(0.0, $price->discountPercent);
        $summary = implode("\n", $connector->runSummary());
        $this->assertStringContainsString('Bez ceny katalogowej z cennika publicznego (cena katalogowa = cena konta): 1, np. TC100 (2 z 3 wersji)', $summary);
        $this->assertStringContainsString('Pozycje: 1 (1 z wersjami w różnych cenach — jedna karta', $summary);
        $this->assertStringContainsString('Wyroby z wersjami bez ceny konta (te wersje poza kartami): 1, np. TC100', $summary);
        $this->assertSame(1, $connector->totalProducts());

        // zdjęcia i pliki: kolory wersji z ceną i te bez koloru; kolor tylko wersji bez ceny (c102) poza kartą
        $this->assertSame(
            [
                self::MEDIA.'a1b2c3d4e5f60001/Liferay_Product-AERO-TC100-NORO.png',
                self::MEDIA.'a1b2c3d4e5f60002/Liferay_Product-AERO-TC100-BMOR.png',
                self::MEDIA.'a1b2c3d4e5f60004/Liferay_Product-AERO-TC100-DETAIL.png',
            ],
            $connector->imageUrls($card),
        );
        $this->assertSame(
            ['AERO TC100 NOIR-ROUGE (C131) technical-sheet PL', 'AERO TC100 BLEU MARINE-ORANGE (C036) technical-sheet PL', 'AERO TC100 UI'],
            array_map(static fn ($d): string => $d->title, $connector->documents($card)),
        );
    }

    public function test_versions_without_price_stay_out_and_a_product_without_any_price_is_only_in_the_summary(): void
    {
        $this->pages['aero-tc100'] = self::aero();
        $this->pages['ts208'] = self::ts208();
        $this->lists['head-protection'] = [1 => ['aero-tc100']];
        $this->lists['body-protection'] = [1 => ['ts208']];
        $this->fakeSite();
        $connector = $this->connector();

        $products = iterator_to_array($connector->products(), false);

        // TC100JFSH („0,00 zł”, „Termin do potwierdzenia”) nie trafia do żadnej karty
        $remoteIds = [];
        foreach ($products as $product) {
            $remoteIds = [...$remoteIds, $product->remoteId, ...array_column($product->members, 'remote_id')];
            $this->assertStringNotContainsString('TC100JFSH', (string) $product->variantSummary);
            $this->assertNotContains(
                ['Informacje handlowe', 'Referencja', 'TC100JFSH: Żółty fluo-szary Krótki daszek, EAN 3200000000134'],
                self::fields($connector, $product),
            );
        }
        $this->assertNotContains('TC100JFSH', $remoteIds);
        // wyrób bez żadnej ceny nie jest wydany, tylko policzony w podsumowaniu
        $this->assertSame([], array_values(array_filter(
            $products,
            static fn (B2bRemoteProduct $p): bool => str_starts_with($p->remoteId, 'TS208') || $p->sku === 'TS208',
        )));
        $this->assertStringContainsStringIgnoringCase('TS208', implode("\n", $connector->runSummary()));
    }

    public function test_name_of_a_product_titled_with_its_code_gets_the_short_description_or_the_category(): void
    {
        $this->pages['am902'] = self::codeNamed('AM902', 'Łączniki', 'Zatrzaśnik śrubowy, zestaw 5 sztuk', 'AM902X5', 'Stalowy', 'Uniwersalny', '87,40 zł');
        // krótki opis dłuższy niż 60 znaków — nazwa z kategorii h3 (na stronie z U+200B na początku)
        $this->pages['ts209'] = self::codeNamed(
            'TS209',
            "\u{200B}Ostrzegawcza zewnętrzna",
            'Kamizelka ostrzegawcza z taśmami odblaskowymi, zapinana na rzepy, z kieszenią na identyfikator',
            'TS209JAM',
            'Żółty fluo',
            'M',
            '19,90 zł',
        );
        $this->pages['ts211'] = self::codeNamed('TS211', '', '', 'TS211JAM', 'Żółty fluo', 'M', '9,90 zł');
        $this->lists['body-protection'] = [1 => ['am902', 'ts209', 'ts211']];
        $this->fakeSite();
        $connector = $this->connector();

        $cards = [];
        foreach ($connector->products() as $product) {
            $cards[$product->sku] = $product;
        }

        $this->assertSame('Zatrzaśnik śrubowy, zestaw 5 sztuk AM902', $cards['AM902']->name);
        $this->assertSame('AM902X5', $cards['AM902']->remoteId);
        $this->assertSame([], $cards['AM902']->members);
        $this->assertSame('Wersje: Stalowy Uniwersalny (AM902X5)', $cards['AM902']->variantSummary);
        $price = $connector->price($cards['AM902']);
        $this->assertSame(87.4, $price?->net);
        $this->assertSame(184.0, $price->base);
        $this->assertSame(52.5, $price->discountPercent);
        $this->assertSame('Ostrzegawcza zewnętrzna TS209', $cards['TS209']->name);
        // ani krótkiego opisu, ani kategorii — sam kod
        $this->assertSame('TS211', $cards['TS211']->name);
    }

    /**
     * Decyzja właściciela 28.09.2026: przedrostek nazwy karty z kodem w h1 = OPIS z cennika publicznego do pierwszego
     * przecinka, dosłownie (wielkie litery, skróty jak FFP1 nietknięte). Wiersze grup nie mają OPIS; model
     * znormalizowany jak w kluczu ceny; różne przedrostki jednego modelu, przedrostek pusty albo dłuższy niż 80 znaków
     * — bez wpisu (nazwa z krótkiego opisu albo kategorii).
     */
    public function test_public_names_take_the_description_up_to_the_first_comma_literally(): void
    {
        $row = static fn (string $model, string $color, string $opis): array => [$model, '', $color, 10, 1, $opis, 'M-XL', 10.0];
        $names = DeltaplusB2bConnector::publicNames(self::xlsx([
            [null, null, null, 'CENNIK PUBLICZNY 01/01/2026'],
            ['MODEL', 'STATUS', 'KOLOR', 'ILOŚĆ W KARTONIE', 'MIN ZAM.', 'OPIS', 'ROZMIARY', 'CENA PLN NETTO 01/2026'],
            ['OCHRONA RĄK'],
            $row('CT402', 'BRĄZOWY', 'RĘKAWICE ZE SKÓRY LICOWEJ KOZIEJ, STRONA GRZBIETOWA Z DRELICHU BAWEŁNIANEGO'),
            $row('CT402', 'CZARNY', 'RĘKAWICE ZE SKÓRY LICOWEJ KOZIEJ, STRONA GRZBIETOWA Z DRELICHU BAWEŁNIANEGO'),
            // wiersz modelu bez OPIS nie jest sprzecznym opisem
            $row('CT402', 'SZARY', ''),
            // małe litery, podwójne spacje, U+200B w MODEL — klucz jak w cenniku; odstępy w OPIS zwinięte
            $row(" ca515r\u{200B} ", 'SZARY', "RĘKAWICE SPAWALNICZE Z DWOINY  BYDLĘCEJ\u{00A0}, 35 CM"),
            // różne OPIS, ten sam przedrostek — wpis zostaje
            $row('DC103', 'SZARY', 'RĘKAWICE DOKER Z DWOINY BYDLĘCEJ'),
            $row('DC103', 'ŻÓŁTY', 'RĘKAWICE DOKER Z DWOINY BYDLĘCEJ, WZMOCNIONE'),
            // różne przedrostki — nie wybieramy
            $row('HEKLA2', 'BEZBARWNY', 'NADOKULARY Z POLIWĘGLANU, AR*, UV400'),
            $row('HEKLA2', 'SZARY', 'OKULARY Z POLIWĘGLANU, UV400'),
            ['OCHRONA DRÓG ODDECHOWYCH'],
            $row('M1100VB', 'BIAŁY', 'OPAKOWANIE 10 PÓŁMASEK Z FILTREM FFP1, Z ZAWOREM, SKŁADANE W PIONIE, Z TESTEM DOLOMITOWYM'),
            // bez przecinka całe zdanie — ponad 80 znaków, bez wpisu
            $row('AIRSTORMREFP3', '', '4 X WKŁADY FILTRUJĄCE P3 DO PÓŁMASKI AIRSTORM W WORECZKACH STRUNOWYCH ZABEZPIECZAJĄCYCH PRZED WILGOCIĄ'),
            $row('LEN80', '', str_repeat('Ł', 80).', RESZTA'),
            $row('LEN81', '', str_repeat('Ł', 81).', RESZTA'),
            // pusty przedrostek
            $row('PRZECINEK1', '', ', SAM PRZECINEK'),
            // dosłownie — bez zmiany wielkości liter
            $row('M6200', '', 'Półmaska z filtrem FFP2, bez zaworu'),
            $row('22180', 'CZARNY', 'SZNURÓWKI OKRĄGŁE, 120 CM'),
            // przecinek dziesiętny nie kończy przedrostka
            $row('NI015', 'NIEBIESKI', 'RĘKAWICE NITRYLOWE 0,1 MM, 100 SZT'),
        ]));

        $this->assertSame([
            'CT402' => 'RĘKAWICE ZE SKÓRY LICOWEJ KOZIEJ',
            'CA515R' => 'RĘKAWICE SPAWALNICZE Z DWOINY BYDLĘCEJ',
            'DC103' => 'RĘKAWICE DOKER Z DWOINY BYDLĘCEJ',
            'M1100VB' => 'OPAKOWANIE 10 PÓŁMASEK Z FILTREM FFP1',
            'LEN80' => str_repeat('Ł', 80),
            'M6200' => 'Półmaska z filtrem FFP2',
            '22180' => 'SZNURÓWKI OKRĄGŁE',
            'NI015' => 'RĘKAWICE NITRYLOWE 0,1 MM',
        ], $names);
        // arkusz bez kolumny OPIS — żadnej nazwy (ceny czyta dalej)
        $withoutOpis = self::xlsx([['MODEL', 'KOLOR', 'CENA PLN NETTO 01/2026'], ['CT402', 'BRĄZOWY', 10.0]]);
        $this->assertSame([], DeltaplusB2bConnector::publicNames($withoutOpis));
        $this->assertSame(['CT402|BRĄZOWY' => [10.0]], DeltaplusB2bConnector::publicPrices($withoutOpis));
    }

    /**
     * Karta z kodem w h1 bierze nazwę z OPIS cennika przed krótkim opisem i kategorią (decyzja właściciela 28.09.2026);
     * karta innego modelu strony — po swoim modelu z tabeli, nie po h1. h1 z odstępem zostaje, choć jego model ma OPIS.
     * Model bez jednoznacznego OPIS — jak dotąd: krótki opis (≤ 60 znaków), dalej kategoria.
     */
    public function test_name_of_a_code_titled_product_takes_the_price_list_description_before_short_and_category(): void
    {
        $row = static fn (string $model, string $color, string $opis, float $price): array => [$model, '', $color, 10, 1, $opis, 'M-XL', $price];
        $this->assetFiles[self::PRICE_LIST] = self::xlsx([
            ['MODEL', 'STATUS', 'KOLOR', 'ILOŚĆ W KARTONIE', 'MIN ZAM.', 'OPIS', 'ROZMIARY', 'CENA PLN NETTO 01/2026'],
            ['OCHRONA RĄK'],
            $row('CT402', 'BRĄZOWY', 'RĘKAWICE ZE SKÓRY LICOWEJ KOZIEJ, STRONA GRZBIETOWA Z DRELICHU BAWEŁNIANEGO', 21.0),
            $row('HEKLA2', 'BEZBARWNY', 'NADOKULARY Z POLIWĘGLANU, AR*, UV400', 30.0),
            $row('HEKLA2', 'SZARY', 'OKULARY Z POLIWĘGLANU, UV400', 30.0),
            ['OCHRONA GŁOWY'],
            $row('AERO TC100', 'CZARNO-CZERWONY', 'HEŁM LEKKI, WENTYLOWANY', 90.0),
            $row('TC200', 'GRANATOWO-POMARAŃCZOWY', 'HEŁM OCHRONNY Z ABS, WENTYLOWANY', 97.0),
        ]);
        $this->pages['ct402'] = self::codeNamed('CT402', 'Prace w środowisku zaolejonym i tłustym', 'Pracujemy jak dorośli', 'CT402BR09', 'Brązowy', '09', '10,00 zł');
        $this->pages['hekla2'] = self::codeNamed('HEKLA2', 'Ochrona oczu', 'Nadokulary', 'HEKLA2IN', 'Bezbarwny', 'Uniwersalny', '14,00 zł');
        $aero = self::aero();
        $aero['models'] = ['TC100BMSH' => 'TC200'];
        $this->pages['aero-tc100'] = $aero;
        $this->lists['hand-protection'] = [1 => ['ct402', 'hekla2']];
        $this->lists['head-protection'] = [1 => ['aero-tc100']];
        $this->fakeSite();
        $connector = $this->connector();

        $names = [];
        foreach ($connector->products() as $product) {
            $names[$product->sku] = $product->name;
        }

        $this->assertSame([
            // inny model strony — OPIS jego modelu z tabeli
            'TC100BMSH' => 'HEŁM OCHRONNY Z ABS TC200',
            // h1 z odstępem bez zmian
            'TC100' => 'AERO TC100',
            'CT402' => 'RĘKAWICE ZE SKÓRY LICOWEJ KOZIEJ CT402',
            // dwa różne przedrostki w cenniku — krótki opis
            'HEKLA2' => 'Nadokulary HEKLA2',
        ], $names);
        $this->assertStringContainsString('nazwy z kolumny OPIS dla 3 modeli', implode("\n", $connector->runSummary()));

        // bez cennika publicznego — jak dotąd: krótki opis, a gdy dłuższy niż 60 znaków — kategoria
        $this->offerLinks = [self::ASSETS.self::PROMO_LIST];
        $names = [];
        foreach ($this->connector()->products() as $product) {
            $names[$product->sku] = $product->name;
        }
        $this->assertSame('Pracujemy jak dorośli CT402', $names['CT402']);
        $this->assertSame('Hełmy lekkie TC200', $names['TC100BMSH']);
    }

    public function test_ref_with_trailing_underscore_gives_the_sku_without_it_and_stays_literal_in_the_shop_fields(): void
    {
        // jak na żywej stronie 22180 (21.09.2026): h1 „22180”, Ref. „22180_”, jedna wersja o referencji „22180”
        $page = self::codeNamed('22990', 'Akcesoria', 'Sznurowadła okrągłe', '22990', 'Czarny', 'Uniwersalny', '1,66 zł');
        $page['ref'] = '22990_';
        $this->pages['22990'] = $page;
        $this->lists['foot-protection'] = [1 => ['22990']];
        $this->fakeSite();
        $connector = $this->connector();

        $cards = iterator_to_array($connector->products(), false);

        $this->assertCount(1, $cards);
        $this->assertSame('22990', $cards[0]->sku);
        $this->assertSame('22990', $cards[0]->remoteId);
        $this->assertSame('Sznurowadła okrągłe 22990', $cards[0]->name);
        $this->assertContains(['Informacje handlowe', 'Ref.', '22990_'], self::fields($connector, $cards[0]));
        // Ref. jako kod modelu dosłownie (z „_”), referencja wersji osobno; pojedyncza wersja = pozycja karty
        $this->assertSame(
            [
                [ProductIdentifier::TYPE_MODEL_CODE, '22990_', null, null],
                [ProductIdentifier::TYPE_MANUFACTURER_CODE, '22990', '22990', 'Czarny Uniwersalny'],
            ],
            array_map(
                static fn (B2bRemoteIdentifier $i): array => [$i->type, $i->value, $i->remoteId, $i->label],
                array_slice($cards[0]->identifiers ?? [], 0, 2),
            ),
        );
    }

    public function test_reference_keeps_its_case_from_the_page_in_the_identifier_while_the_position_is_upper_case(): void
    {
        $page = self::codeNamed('AM903', 'Łączniki', 'Zatrzaśnik', 'am903x5', 'Stalowy', 'Uniwersalny', '10,00 zł');
        $this->pages['am903'] = $page;
        $this->lists['hand-protection'] = [1 => ['am903']];
        $this->fakeSite();

        $cards = iterator_to_array($this->connector()->products(), false);

        $this->assertSame('AM903X5', $cards[0]->remoteId);
        $code = collect($cards[0]->identifiers ?? [])->firstWhere('type', ProductIdentifier::TYPE_MANUFACTURER_CODE);
        $this->assertSame(['am903x5', 'AM903X5'], [$code?->value, $code?->remoteId]);
    }

    public function test_name_of_a_code_titled_product_without_short_description_takes_the_category(): void
    {
        // Pytanie do kontraktu: pusty krótki opis ma ≤ 60 znaków, ale „{krótki opis} {h1}” dałoby sam kod
        // ze spacją. Przyjęte za decyzją 3 planu: „krótki opis albo kategoria z h3”.
        $this->pages['ts210'] = self::codeNamed('TS210', "\u{200B}Ostrzegawcza zewnętrzna", '', 'TS210JAM', 'Żółty fluo', 'M', '9,90 zł');
        $this->lists['body-protection'] = [1 => ['ts210']];
        $this->fakeSite();

        $cards = iterator_to_array($this->connector()->products(), false);

        $this->assertSame('Ostrzegawcza zewnętrzna TS210', $cards[0]->name);
    }

    public function test_unreadable_page_or_signed_in_page_without_price_column_is_skipped_with_a_reason(): void
    {
        $this->pages['bez-cen'] = [...self::codeNamed('BC100', 'Akcesoria', 'Sznurowadła', 'BC100N', 'Czarny', 'Uniwersalny', '1,20 zł'), 'priceColumns' => false];
        $this->lists['foot-protection'] = [1 => ['znikniety-wyrob', 'bez-cen']];
        $this->fakeSite();
        $connector = $this->connector();

        $products = iterator_to_array($connector->products(), false);

        $this->assertCount(2, $products);
        foreach ($products as $product) {
            try {
                $connector->price($product);
                $this->fail('Pozycja '.$product->sourceUrl.' powinna być pominięta');
            } catch (RuntimeException $e) {
                $this->assertNotInstanceOf(B2bFatalException::class, $e);
                $this->assertNotSame('', trim($e->getMessage()));
            }
        }
        $this->assertEqualsCanonicalizing(
            [DeltaplusB2bClient::productUrl('znikniety-wyrob'), DeltaplusB2bClient::productUrl('bez-cen')],
            array_map(static fn (B2bRemoteProduct $p): ?string => $p->sourceUrl, $products),
        );
    }

    public function test_twenty_signed_in_pages_without_price_column_in_a_row_are_fatal(): void
    {
        $slugs = [];
        for ($i = 1; $i <= 20; $i++) {
            $slugs[] = 'bez-cen-'.$i;
            $this->pages['bez-cen-'.$i] = [...self::codeNamed('BC'.$i, 'Akcesoria', 'Sznurowadła', 'BC'.$i.'N', 'Czarny', 'Uniwersalny', '1,20 zł'), 'priceColumns' => false];
        }
        $this->lists['foot-protection'] = [1 => $slugs];
        $this->fakeSite();
        $connector = $this->connector();

        $this->expectException(B2bFatalException::class);

        foreach ($connector->products() as $product) {
            // pojedyncze strony bez ceny to pominięcia — przerwać ma dopiero seria
        }
    }

    public function test_page_without_session_logs_in_again_once(): void
    {
        $this->pages['neptun-tt733'] = self::neptun();
        $this->fakeSite();
        $client = $this->client();
        $client->login();
        $this->dropSessionOnProduct = true;

        $html = $client->productPage('neptun-tt733');

        $this->assertNotNull($html);
        $this->assertTrue(DeltaplusB2bClient::isSignedIn($html));
        $this->assertStringContainsString('Cena jeśli cały karton', $html);
        $this->assertSame(2, $this->logins);
        $this->assertSame(['neptun-tt733', 'neptun-tt733'], $this->productRequests);
    }

    public function test_session_lost_during_the_run_gives_the_card_its_account_price_after_one_new_login(): void
    {
        $this->pages['neptun-tt733'] = self::neptun();
        $this->lists['hand-protection'] = [1 => ['neptun-tt733']];
        $this->fakeSite();
        $connector = $this->connector();
        $this->dropSessionOnProduct = true;

        $products = iterator_to_array($connector->products(), false);

        $this->assertCount(1, $products);
        $this->assertSame(5.7, $connector->price($products[0])?->net);
        $this->assertSame(2, $this->logins);
    }

    public function test_session_lost_again_after_a_new_login_is_fatal(): void
    {
        $this->pages['neptun-tt733'] = self::neptun();
        $this->fakeSite();
        $client = $this->client();
        $client->login();
        $this->productsAlwaysAnonymous = true;

        $this->expectException(B2bFatalException::class);
        $this->expectExceptionMessage('Utracono sesję konta deltaplus.eu — ceny konta niedostępne');

        $client->productPage('neptun-tt733');
    }

    public function test_missing_price_list_keeps_the_run_going_without_catalog_price(): void
    {
        $this->pages['neptun-tt733'] = self::neptun();
        $this->lists['hand-protection'] = [1 => ['neptun-tt733']];
        $this->fakeSite();
        // na stronie oferty tylko cennik promocji — cennika publicznego brak
        $this->offerLinks = [self::ASSETS.self::PROMO_LIST];
        $connector = $this->connector();

        $products = iterator_to_array($connector->products(), false);

        $this->assertCount(1, $products);
        $price = $connector->price($products[0]);
        $this->assertSame(5.7, $price?->net);
        $this->assertNull($price->base);
        $this->assertSame(0.0, $price->discountPercent);
        $this->assertStringContainsStringIgnoringCase('cennik', implode("\n", $connector->runSummary()));
    }

    public function test_sync_creates_delta_plus_cards_with_account_price_links_shop_card_documents_images_and_norms(): void
    {
        Storage::fake('public');
        Storage::fake('local');
        $this->fullSite();
        $this->fakeSite();

        $result = app(B2bAccountSyncRunner::class)->run($this->account(), delayMs: 0, withImages: true);

        // NEPTUN (jedna cena), AERO (dwie ceny — od 28.09.2026 jedna karta), AM902; TS208 bez ceny nie jest wydany
        $this->assertSame(3, $result['created'], implode(' | ', $result['errors']));
        $this->assertSame(1, $result['skipped'], implode(' | ', $result['errors']));
        $this->assertTrue(
            collect($result['errors'])->contains(static fn (string $e): bool => str_contains(mb_strtolower($e), 'znikniety-wyrob')),
            implode(' | ', $result['errors']),
        );
        $this->assertFalse(Product::query()->where('sku', 'like', 'TS208%')->exists());
        // NEPTUN jest w dwóch kategoriach — karta pobrana raz
        $this->assertSame(1, count(array_keys($this->productRequests, 'neptun-tt733', true)));

        $card = Product::query()->where('sku', 'TT733')->sole();
        $this->assertSame('Delta Plus', $card->manufacturer);
        $this->assertSame('NEPTUN TT733', $card->name);
        $this->assertSame(
            ['TT73307', 'TT73308', 'TT73309', 'TT73310', 'TT733OR07', 'TT733OR08', 'TT733OR09', 'TT733OR10'],
            B2bProductLink::query()->where('product_id', $card->id)->orderBy('remote_id')->pluck('remote_id')->all(),
        );
        $slot = ProductSourcePrice::query()->where('product_id', $card->id)->sole();
        $this->assertSame('5.70', (string) $slot->purchase_price);
        $this->assertSame('12.01', (string) $slot->catalog_price_net);
        $this->assertSame('Dostępne', $slot->availability);

        $shopCard = ProductShopCard::query()->where('product_id', $card->id)->sole();
        $this->assertSame('https://www.deltaplus.eu/pl/p/neptun-tt733', (string) $shopCard->source_url);
        $this->assertContains(
            ['name' => 'Referencja', 'value' => 'TT733OR07: Pomarańczowy fluo-czarny 07, EAN 3200000000011'],
            self::sectionRows($shopCard, 'Informacje handlowe'),
        );
        $this->assertSame(
            [
                ['NEPTUN TT733 ORANGE FLUO-NOIR (C224) technical-sheet PL', ProductDocument::KIND_DATASHEET],
                ['NEPTUN TT733 JAUNE FLUO-NOIR (C218) technical-sheet PL', ProductDocument::KIND_DATASHEET],
                ['NEPTUN TT733 dceuepi-certificate PL', ProductDocument::KIND_CERTIFICATE],
                ['GLOVES EN ISO 21420 EN388 UI TEST', ProductDocument::KIND_MANUAL],
            ],
            ProductDocument::query()->where('product_id', $card->id)->orderBy('sort_order')->get()
                ->map(static fn (ProductDocument $d): array => [(string) $d->title, (string) $d->kind])->all(),
        );
        $this->assertSame(
            self::neptunImages(),
            ProductImage::query()->where('product_id', $card->id)->orderBy('sort_order')->pluck('source_url')->all(),
        );

        $norms = $card->manufacturer_norms;
        $this->assertSame([['label' => 'EN 388', 'value' => '2 1 2 1 X'], ['label' => 'EN ISO 21420']], $norms['rows'] ?? null);
        $this->assertSame('2121X', $norms['en388'] ?? null);
        $this->assertSame('https://www.deltaplus.eu/pl/p/neptun-tt733', $norms['source']['url'] ?? null);
        $this->assertSame('deltaplus', $norms['source']['connector'] ?? null);

        // AERO: jedna karta, cena karty = najtańsza wersja; jej ceny katalogowej brak (cennik niejednoznaczny) —
        // katalogowa = cena konta; wersja granatowo-pomarańczowa ma w wierszu rozmiaru swoją cenę i katalogową
        $aeroCard = Product::query()->where('sku', 'TC100')->sole();
        $this->assertFalse(Product::query()->where('sku', 'TC100BMSH')->exists());
        $aero = ProductSourcePrice::query()->where('product_id', $aeroCard->id)->sole();
        $this->assertSame('45.00', (string) $aero->purchase_price);
        $this->assertSame('45.00', (string) $aero->catalog_price_net);
        $this->assertSame('48.50', (string) $aero->size_price_max);
        $this->assertSame(
            [
                ['TC100BMSH', 'Granatowo-pomarańczowy Krótki daszek', '48.50', '97.00'],
                ['TC100NOSH', 'Czarno-czerwony Krótki daszek', '45.00', null],
                ['TC100NOLG', 'Czarno-czerwony Długi daszek', '45.00', null],
            ],
            ProductVariant::query()->where('product_id', $aeroCard->id)->where('kind', ProductVariant::KIND_SIZE)->orderBy('sort_order')->get()
                ->map(static fn (ProductVariant $v): array => [$v->remote_id, $v->label, (string) $v->purchase_price, $v->list_price_net !== null ? (string) $v->list_price_net : null])->all(),
        );
        $this->assertSame(
            ['TC100BMSH', 'TC100NOLG', 'TC100NOSH'],
            B2bProductLink::query()->where('product_id', $aeroCard->id)->orderBy('remote_id')->pluck('remote_id')->all(),
        );
        $this->assertSame('Zatrzaśnik śrubowy, zestaw 5 sztuk AM902', Product::query()->where('sku', 'AM902')->value('name'));

        $log = implode("\n", array_column((array) B2bSyncRun::query()->latest('id')->firstOrFail()->log, 'text'));
        $this->assertStringContainsStringIgnoringCase('TS208', $log);
        $this->assertStringNotContainsString('spoza karty', $log);

        // identyfikatory: NEPTUN 1 + 8×3, AERO 1 + 3×3, AM902 1 + 3; Ref. modelu na pozycji karty
        $this->assertSame(25, ProductIdentifier::query()->where('product_id', $card->id)->count());
        $this->assertSame(39, ProductIdentifier::query()->count());
        $model = ProductIdentifier::query()->where('product_id', $card->id)->where('type', ProductIdentifier::TYPE_MODEL_CODE)->sole();
        $this->assertSame(['TT733', 'TT733OR07', 'Ref.', 'Delta Plus'], [$model->value, $model->position_key, $model->source_field, $model->manufacturer]);
        $ean = ProductIdentifier::query()->where('product_id', $card->id)->where('type', ProductIdentifier::TYPE_EAN)->where('position_key', 'TT73310')->sole();
        $this->assertSame(['3200000000080', 'Żółty fluo-czarny 10', 'EAN 13'], [$ean->value, $ean->variant_label, $ean->source_field]);
        $this->assertEqualsCanonicalizing(
            ['TC100BMSH', 'TC100NOSH', 'TC100NOLG'],
            ProductIdentifier::query()->where('product_id', $aeroCard->id)->distinct()->pluck('position_key')->all(),
        );
        $this->assertFalse(ProductIdentifier::query()->where('value', 'like', 'TC100JFSH%')->exists());
    }

    public function test_second_sync_on_the_same_site_changes_nothing(): void
    {
        Storage::fake('public');
        Storage::fake('local');
        $this->fullSite();
        $this->fakeSite();
        app(B2bAccountSyncRunner::class)->run($this->account(), delayMs: 0, withImages: true);
        $before = $this->snapshot();
        $identifiers = ProductIdentifier::query()->orderBy('id')->get(['id', 'product_id', 'position_key', 'type', 'value'])->toArray();

        $second = app(B2bAccountSyncRunner::class)->run($this->account(), delayMs: 0, withImages: true);

        $this->assertSame(0, $second['created'], implode(' | ', $second['errors']));
        $this->assertSame(0, $second['updated'], implode(' | ', $second['errors']));
        $this->assertSame(3, $second['unchanged'], implode(' | ', $second['errors']));
        // opis, normy producenta (z datą odczytu), tabelka, pliki, zdjęcia i powiązania — bez zmian
        $this->assertSame($before, $this->snapshot());
        // identyfikatory zapisane raz: drugi przebieg ich nie dubluje ani nie oznacza jako zniknięte
        $this->assertCount(39, $identifiers);
        $this->assertSame($identifiers, ProductIdentifier::query()->orderBy('id')->get(['id', 'product_id', 'position_key', 'type', 'value'])->toArray());
        $this->assertSame(0, ProductIdentifier::query()->whereNotNull('removed_at')->count());
    }

    public function test_second_sync_after_a_version_loses_its_price_keeps_the_same_card(): void
    {
        Storage::fake('public');
        Storage::fake('local');
        $this->fullSite();
        $this->fakeSite();
        app(B2bAccountSyncRunner::class)->run($this->account(), delayMs: 0, withImages: false);
        $card = Product::query()->where('sku', 'TT733')->sole();
        $cards = Product::query()->count();

        // pierwsza wersja karty (jej remote_id) traci cenę
        $spec = self::neptun();
        $spec['rows'][0][8] = 'Termin do potwierdzenia';
        $spec['rows'][0][9] = '0,00 zł';
        $this->pages['neptun-tt733'] = $spec;
        // drugi przebieg później niż pierwszy — sprzątanie wierszy rozmiarów porównuje czas ostatniego widzenia
        // z początkiem przebiegu (w tej samej sekundzie wynik zależałby od zegara)
        $this->travel(1)->minutes();

        $second = app(B2bAccountSyncRunner::class)->run($this->account(), delayMs: 0, withImages: false);

        $this->assertSame(0, $second['created'], implode(' | ', $second['errors']));
        $this->assertSame($cards, Product::query()->count());
        $this->assertSame(
            $card->id,
            B2bProductLink::query()->where('remote_id', 'TT733OR08')->value('product_id'),
        );
        $this->assertSame($card->id, Product::query()->where('sku', 'TT733')->value('id'));
        $this->assertStringNotContainsString('TT733OR07', (string) $card->refresh()->variant_summary);
        $this->assertSame('5.70', (string) ProductSourcePrice::query()->where('product_id', $card->id)->value('purchase_price'));
        // wiersz rozmiaru wersji bez ceny nie jest kasowany — dostaje removed_at, reszta zostaje
        $sizes = ProductVariant::query()->where('product_id', $card->id)->where('kind', ProductVariant::KIND_SIZE)->get()->keyBy('remote_id');
        $this->assertCount(8, $sizes);
        $this->assertNotNull($sizes['TT733OR07']->removed_at);
        $this->assertNull($sizes['TT733OR08']->removed_at);
    }

    /**
     * Karty sprzed 28.09.2026 (AERO rozbity według ceny na „TC100” za 45,00 i „TC100BMSH” za 48,50) — pierwszy i drugi
     * przebieg po zmianie: bez nowej karty, bez przepinania powiązań i bez zmian cen kart; każda karta dostaje swoje
     * wersje jako rozmiary, a wyrób trafia do size_spread przebiegu (scalenie — osobny krok).
     */
    public function test_legacy_price_split_cards_stay_and_get_their_own_versions_over_two_runs(): void
    {
        Storage::fake('public');
        Storage::fake('local');
        $this->pages['aero-tc100'] = self::aero();
        $this->lists['head-protection'] = [1 => ['aero-tc100']];
        $this->fakeSite();
        $account = $this->account();
        $black = $this->legacyCard($account, 'TC100', 45.0, ['TC100NOSH', 'TC100NOLG']);
        $blue = $this->legacyCard($account, 'TC100BMSH', 48.5, ['TC100BMSH'], 97.0);
        $cards = fn (): array => Product::query()->orderBy('id')->get()->map(fn (Product $p): array => [
            $p->sku, $p->name, (string) $p->purchase_price,
            B2bProductLink::query()->where('product_id', $p->id)->orderBy('remote_id')->pluck('remote_id')->all(),
            (string) ProductSourcePrice::query()->where('product_id', $p->id)->value('purchase_price'),
            ProductSourcePrice::query()->where('product_id', $p->id)->value('size_price_max'),
        ])->all();
        $before = $cards();

        $first = app(B2bAccountSyncRunner::class)->run($account, delayMs: 0, withImages: true);

        $this->assertSame(0, $first['created'], implode(' | ', $first['errors']));
        $this->assertSame(0, $first['skipped'], implode(' | ', $first['errors']));
        $this->assertSame(0, $first['prices_changed']);
        $this->assertSame($before, $cards());
        // zdjęcia i pliki: każda dawna karta tylko w kolorach swoich wersji (i bez koloru) — czarne zdjęcie nie trafia
        // na kartę granatową
        $images = static fn (Product $card): array => ProductImage::query()->where('product_id', $card->id)->orderBy('sort_order')->pluck('source_url')->all();
        $this->assertSame([self::MEDIA.'a1b2c3d4e5f60001/Liferay_Product-AERO-TC100-NORO.png', self::MEDIA.'a1b2c3d4e5f60004/Liferay_Product-AERO-TC100-DETAIL.png'], $images($black));
        $this->assertSame([self::MEDIA.'a1b2c3d4e5f60002/Liferay_Product-AERO-TC100-BMOR.png', self::MEDIA.'a1b2c3d4e5f60004/Liferay_Product-AERO-TC100-DETAIL.png'], $images($blue));
        $documents = static fn (Product $card): array => ProductDocument::query()->where('product_id', $card->id)->orderBy('sort_order')->pluck('title')->all();
        $this->assertSame(['AERO TC100 NOIR-ROUGE (C131) technical-sheet PL', 'AERO TC100 UI'], $documents($black));
        $this->assertSame(['AERO TC100 BLEU MARINE-ORANGE (C036) technical-sheet PL', 'AERO TC100 UI'], $documents($blue));
        $sizes = static fn (Product $card): array => ProductVariant::query()->where('product_id', $card->id)->orderBy('sort_order')->get()
            ->map(static fn (ProductVariant $v): array => [$v->remote_id, (string) $v->purchase_price])->all();
        $this->assertSame([['TC100NOSH', '45.00'], ['TC100NOLG', '45.00']], $sizes($black));
        $this->assertSame([['TC100BMSH', '48.50']], $sizes($blue));
        $spread = B2bSyncRun::query()->findOrFail($first['sync_run_id'])->size_spread;
        $this->assertSame(1, $spread['total']);
        $this->assertSame([$black->id, $blue->id], $spread['groups'][0]['cards']);

        $second = app(B2bAccountSyncRunner::class)->run($account, delayMs: 0, withImages: true);

        $this->assertSame(0, $second['created'], implode(' | ', $second['errors']));
        $this->assertSame(0, $second['prices_changed']);
        $this->assertSame($before, $cards());
        $this->assertSame([self::MEDIA.'a1b2c3d4e5f60002/Liferay_Product-AERO-TC100-BMOR.png', self::MEDIA.'a1b2c3d4e5f60004/Liferay_Product-AERO-TC100-DETAIL.png'], $images($blue));
        $this->assertSame(0, ProductVariant::query()->whereNotNull('removed_at')->count());
    }

    public function test_registry_detects_deltaplus_by_host_and_it_is_the_manufacturer_site(): void
    {
        $registry = app(B2bConnectorRegistry::class);

        $this->assertSame('deltaplus', $registry->keyForSites(['https://www.deltaplus.eu/pl/espace-pro-landing']));
        $this->assertSame('deltaplus', $registry->keyForSites(['deltaplus.eu']));
        $this->assertSame('Delta Plus', $registry->label('deltaplus'));
        $this->assertTrue($registry->requiresPassword('deltaplus'));
        // ceny wersji przy pozycjach (28.09.2026) — konto ma w panelu „Scal rozmiary”
        $this->assertTrue($registry->sendsSizePrices('deltaplus'));
        $this->assertSame('deltaplus.eu', DeltaplusB2bConnector::host());

        $account = B2bAccount::query()->create(['username' => self::USER, 'password' => 'sekret', 'sites' => ['https://www.deltaplus.eu/']]);
        $connector = $registry->make($account, 0);

        $this->assertInstanceOf(DeltaplusB2bConnector::class, $connector);
        $this->assertInstanceOf(B2bManufacturerSite::class, $connector);
        $this->assertSame('Delta Plus', DeltaplusB2bConnector::ownBrand());
        $this->assertSame('Delta Plus', $connector->manufacturer(new B2bRemoteProduct('X', 'X', 'X')));
        foreach ([B2bNormFactSource::class, B2bDocumentSource::class, B2bImageGallery::class, B2bShopFieldSource::class,
            B2bRunSummaryAware::class, B2bListProgressAware::class] as $interface) {
            $this->assertInstanceOf($interface, $connector);
        }
    }

    private function client(): DeltaplusB2bClient
    {
        return new DeltaplusB2bClient(self::USER, self::PASSWORD, 0, static function (int $ms): void {});
    }

    private function connector(): DeltaplusB2bConnector
    {
        $connector = new DeltaplusB2bConnector($this->client());
        $connector->login();

        return $connector;
    }

    /**
     * Karta AERO zapisana przez dawny podział według ceny: slot konta i powiązania wersji z odciskiem opisu.
     *
     * @param  list<string>  $refs
     */
    private function legacyCard(B2bAccount $account, string $sku, float $price, array $refs, ?float $base = null): Product
    {
        $description = "Hełm lekki z wentylacją, dostępny z dwiema długościami daszka\n\nKomfort\nWentylacja ogranicza pocenie";
        $catalog = $base ?? $price;
        $discount = $base !== null ? round((1 - $price / $base) * 100, 2) : 0;
        $card = Product::query()->create([
            'sku' => $sku, 'name' => 'AERO TC100', 'manufacturer' => 'Delta Plus', 'description' => $description,
            'catalog_price_net' => $catalog, 'discount_percent' => $discount, 'purchase_price' => $price, 'currency' => 'PLN',
        ]);
        foreach ($refs as $ref) {
            B2bProductLink::query()->create([
                'b2b_account_id' => $account->id, 'remote_id' => $ref, 'product_id' => $card->id,
                'remote_sku' => $ref, 'remote_name' => 'AERO TC100', 'manufacturer' => 'Delta Plus',
                'description_hash' => sha1($description), 'last_purchase_price' => $price, 'last_currency' => 'PLN',
            ]);
        }
        ProductSourcePrice::query()->create([
            'product_id' => $card->id, 'source_key' => ProductSourcePrice::b2bKey((int) $account->id), 'b2b_account_id' => $account->id,
            'catalog_price_net' => $catalog, 'purchase_price' => $price, 'discount_percent' => $discount, 'currency' => 'PLN',
            'availability' => 'Dostępne', 'checked_at' => now()->subDay(),
        ]);

        return $card;
    }

    private function account(): B2bAccount
    {
        return B2bAccount::query()->firstOrCreate(
            ['username' => self::USER],
            ['password' => self::PASSWORD, 'sites' => ['https://www.deltaplus.eu/'], 'connector' => 'deltaplus', 'sync_images' => true],
        )->fresh();
    }

    /** Witryna do przebiegu synchronizacji: kilka kategorii, dwie strony listy, powtórzony wyrób, 404 i wyrób bez ceny. */
    private function fullSite(): void
    {
        $this->pages = [
            'neptun-tt733' => self::neptun(),
            'aero-tc100' => self::aero(),
            'am902' => self::codeNamed('AM902', 'Łączniki', 'Zatrzaśnik śrubowy, zestaw 5 sztuk', 'AM902X5', 'Stalowy', 'Uniwersalny', '87,40 zł'),
            'ts208' => self::ts208(),
        ];
        $this->lists = [
            'head-protection' => [1 => ['aero-tc100']],
            'hand-protection' => [1 => ['neptun-tt733'], 2 => ['am902', 'znikniety-wyrob']],
            'body-protection' => [1 => ['ts208', 'neptun-tt733']],
        ];
    }

    /**
     * Stan kart po przebiegu — do porównania z drugim przebiegiem.
     *
     * @return array<string, mixed>
     */
    private function snapshot(): array
    {
        return Product::query()->orderBy('sku')->get()->mapWithKeys(fn (Product $p): array => [$p->sku => [
            'name' => $p->name,
            'description' => $p->description,
            'variant_summary' => $p->variant_summary,
            'manufacturer_norms' => $p->manufacturer_norms,
            'links' => B2bProductLink::query()->where('product_id', $p->id)->orderBy('remote_id')->pluck('remote_id')->all(),
            'shop_card' => ProductShopCard::query()->where('product_id', $p->id)->value('fields'),
            'documents' => ProductDocument::query()->where('product_id', $p->id)->orderBy('sort_order')->pluck('source_url')->all(),
            'images' => ProductImage::query()->where('product_id', $p->id)->orderBy('sort_order')->pluck('source_url')->all(),
            'price' => ProductSourcePrice::query()->where('product_id', $p->id)->get(['purchase_price', 'catalog_price_net', 'availability'])->toArray(),
        ]])->all();
    }

    /**
     * @return list<array{0: string, 1: string, 2: string}>
     */
    private static function fields(DeltaplusB2bConnector $connector, B2bRemoteProduct $product): array
    {
        return array_map(static fn ($f): array => [$f->section, $f->name, $f->value], $connector->shopFields($product));
    }

    /**
     * @return list<array{name: string, value: string}>
     */
    private static function sectionRows(ProductShopCard $card, string $section): array
    {
        foreach ((array) $card->fields as $entry) {
            if (($entry['section'] ?? null) === $section) {
                return $entry['rows'];
            }
        }

        return [];
    }

    private static function attachment(string $id): string
    {
        return DeltaplusB2bClient::BASE.self::ATTACHMENTS.$id.self::ATTACHMENT_QUERY;
    }

    /**
     * @return list<string>
     */
    private static function neptunImages(): array
    {
        return [
            self::MEDIA.'a1a1a1a1a1a1a1a1/Liferay_Product-NEPTUN-OR-TT733OR-eps.png',
            self::MEDIA.'b2b2b2b2b2b2b2b2/Liferay_Product-NEPTUN-OR-TT733OR-P-eps.png',
            self::MEDIA.'c3c3c3c3c3c3c3c3/Liferay_Product-NEPTUN-JA-TT733-eps.png',
            self::MEDIA.'d4d4d4d4d4d4d4d4/Liferay_Product-NEPTUN-JA-TT733-P-eps.png',
            self::MEDIA.'e5e5e5e5e5e5e5e5/Liferay_Product-NEPTUN-DETAIL-eps.png',
        ];
    }

    /**
     * Wiersz tabeli referencji: [referencja, kolor, rozmiar, EAN, kod kartonu, ilość w kartonie, min. zamówienie,
     * waga, dostępność, cena].
     *
     * @return list<string>
     */
    private static function row(string $ref, string $color, string $size, string $ean, string $qty, string $min, string $availability, string $price): array
    {
        return [$ref, $color, $size, $ean, '1'.$ean, $qty, $min, '8.00 kg', $availability, $price];
    }

    /**
     * @param  array<string, mixed>  $spec
     * @return array<string, mixed>
     */
    private static function spec(array $spec): array
    {
        return array_replace([
            'trail' => [['/pl/dp/hand-protection', 'Ochrona rąk']],
            'category' => '',
            'name' => '',
            'short' => '',
            'ref' => '',
            'colors' => [],
            'gallery' => [],
            'shortcut' => '',
            'zones' => [],
            'sectors' => [],
            'hazards' => [],
            'norms' => [],
            'advantages' => [],
            'rows' => [],
            'documents' => [],
            // kolumna Model: referencja => model; bez wpisu — nazwa z h1
            'models' => [],
            'priceColumns' => true,
        ], $spec);
    }

    /**
     * Rękawice jak APOLLON VV733: 8 wersji w dwóch kolorach, jedna cena konta; w cenniku publicznym oba kolory po 12,01.
     *
     * @return array<string, mixed>
     */
    private static function neptun(): array
    {
        $images = self::neptunImages();

        return self::spec([
            'trail' => [
                ['/pl/dp/hand-protection', 'Ochrona rąk'],
                ['/pl/dp/mechanical-protection-for-multi-purpose-works', 'Ochrona mechaniczna do wszechstronnych zastosowań'],
                ['/pl/dp/works-in-wet-environment', 'Prace w środowisku mokrym'],
            ],
            'category' => 'Prace w środowisku mokrym',
            'name' => 'NEPTUN TT733',
            'short' => self::NEPTUN_SHORT,
            'ref' => 'TT733',
            'colors' => ['c218' => 'Żółty fluo-czarny', 'c224' => 'Pomarańczowy fluo-czarny'],
            'gallery' => [
                ['c224', $images[0]],
                ['c224', $images[1]],
                ['c218', $images[2]],
                ['c218', $images[3]],
                ['', $images[2]],
                ['360', ''],
                ['', $images[3]],
                ['', $images[4]],
            ],
            'shortcut' => self::NEPTUN_SHORTCUT,
            'zones' => [
                'Rodzaj produktu' => ['Kolekcja rękawic : Rękawice dzianinowe powlekane'],
                'Materiał' => ['Materiał powłoki : Lateks', 'Włókno : Poliester'],
                'Koncepcja' => ['Długość : 24 cm : mierzona od nadgarstka'],
                'Rozmiary i kolory' => ['Rozmiary : 7 - 8 - 9 - 10'],
                'Opakowanie' => ['w indywidualnym opakowaniu'],
            ],
            'sectors' => ['Rolnictwo', 'Roboty publiczne', 'Prace remontowe / Rzemiosło'],
            'hazards' => ['Zużycie'],
            'norms' => [
                ['tile-hapr-delta-plus-ce-hand-protection', '', 'Kategoria II'],
                ['tile-hapr-delta-plus-en-388-2018a1', 'EN 388', '2 1 2 1 X'],
                ['tile-hapr-delta-plus-en-iso-21420-2020', 'EN ISO 21420', ''],
            ],
            'advantages' => [
                ['Widzialność', 'Elementy fluorescencyjne ułatwiają dostrzeżenie rękawicy'],
                ['Sprawność', 'Lekka konstrukcja zapewnia dobrą zręczność'],
            ],
            'rows' => [
                self::row('TT733OR07', 'Pomarańczowy fluo-czarny', '07', '3200000000011', '120', '12', 'Dostępne', '5,70 zł'),
                self::row('TT733OR08', 'Pomarańczowy fluo-czarny', '08', '3200000000028', '120', '12', 'Dostępne', '5,70 zł'),
                self::row('TT733OR09', 'Pomarańczowy fluo-czarny', '09', '3200000000035', '120', '12', 'Dostępne', '5,70 zł'),
                self::row('TT733OR10', 'Pomarańczowy fluo-czarny', '10', '3200000000042', '100', '12', 'Dostępne', '5,70 zł'),
                self::row('TT73307', 'Żółty fluo-czarny', '07', '3200000000059', '120', '12', 'Dostępne', '5,70 zł'),
                self::row('TT73308', 'Żółty fluo-czarny', '08', '3200000000066', '120', '12', 'Dostępne', '5,70 zł'),
                self::row('TT73309', 'Żółty fluo-czarny', '09', '3200000000073', '120', '12', 'Dostępne', '5,70 zł'),
                self::row('TT73310', 'Żółty fluo-czarny', '10', '3200000000080', '100', '12', 'Dostępne', '5,70 zł'),
            ],
            'documents' => [
                ['', 'Instrukcja użytkowania', 'GLOVES EN ISO 21420 EN388 UI TEST', self::MEDIA.'9f9f9f9f9f9f9f9f/original/UI-GLOVES-TEST.pdf'],
                ['c224', 'Karta techniczna', 'NEPTUN TT733 ORANGE FLUO-NOIR (C224) technical-sheet PL', self::ATTACHMENTS.'9000001'.self::ATTACHMENT_QUERY],
                ['c218', 'Karta techniczna', 'NEPTUN TT733 JAUNE FLUO-NOIR (C218) technical-sheet PL', self::ATTACHMENTS.'9000002'.self::ATTACHMENT_QUERY],
                ['', 'Deklaracja zgodności UE', 'NEPTUN TT733 dceuepi-certificate PL', self::PPE.'0123456789abcdef0123456789abcdef/pdf/dceuepi-certificate'],
                ['c224', 'Karta techniczna', 'NEPTUN TT733 ORANGE FLUO-NOIR (C224) technical-sheet PL', self::ATTACHMENTS.'9000001'.self::ATTACHMENT_QUERY],
            ],
        ]);
    }

    /**
     * Hełm jak AIR COLTAN: wersje w dwóch cenach (mniejsza grupa pierwsza w tabeli) i kolor bez ceny.
     *
     * @return array<string, mixed>
     */
    private static function aero(): array
    {
        return self::spec([
            'trail' => [['/pl/dp/head-protection', 'Ochrona głowy'], ['/pl/dp/light-helmets', 'Hełmy lekkie']],
            'category' => 'Hełmy lekkie',
            'name' => 'AERO TC100',
            'short' => 'Hełm lekki z wentylacją, dostępny z dwiema długościami daszka',
            'ref' => 'TC100',
            'colors' => ['c131' => 'Czarno-czerwony', 'c036' => 'Granatowo-pomarańczowy', 'c102' => 'Żółty fluo-szary'],
            'gallery' => [
                ['c131', self::MEDIA.'a1b2c3d4e5f60001/Liferay_Product-AERO-TC100-NORO.png'],
                ['c036', self::MEDIA.'a1b2c3d4e5f60002/Liferay_Product-AERO-TC100-BMOR.png'],
                ['c102', self::MEDIA.'a1b2c3d4e5f60003/Liferay_Product-AERO-TC100-JFGR.png'],
                ['360', ''],
                ['', self::MEDIA.'a1b2c3d4e5f60004/Liferay_Product-AERO-TC100-DETAIL.png'],
            ],
            'shortcut' => 'Skorupa : ABS | Więźba : Tekstylna',
            'zones' => ['Materiał' => ['Materiał skorupy : ABS']],
            'sectors' => ['Budownictwo'],
            'hazards' => ['Uderzenia'],
            'norms' => [
                ['tile-hepr-delta-plus-category-ce', '', 'Kategoria II'],
                ['tile-hepr-delta-plus-en-812', 'EN 812', ''],
            ],
            'advantages' => [['Komfort', 'Wentylacja ogranicza pocenie']],
            'rows' => [
                self::row('TC100BMSH', 'Granatowo-pomarańczowy', 'Krótki daszek', '3200000000110', '20', '1', 'Dostępne', '48,50 zł'),
                self::row('TC100NOSH', 'Czarno-czerwony', 'Krótki daszek', '3200000000127', '20', '1', 'Dostępne', '45,00 zł'),
                self::row('TC100JFSH', 'Żółty fluo-szary', 'Krótki daszek', '3200000000134', '20', '1', 'Termin do potwierdzenia', '0,00 zł'),
                self::row('TC100NOLG', 'Czarno-czerwony', 'Długi daszek', '3200000000141', '20', '1', 'Termin do potwierdzenia', '45,00 zł'),
            ],
            'documents' => [
                ['c131', 'Karta techniczna', 'AERO TC100 NOIR-ROUGE (C131) technical-sheet PL', self::ATTACHMENTS.'9000011'.self::ATTACHMENT_QUERY],
                ['c036', 'Karta techniczna', 'AERO TC100 BLEU MARINE-ORANGE (C036) technical-sheet PL', self::ATTACHMENTS.'9000012'.self::ATTACHMENT_QUERY],
                ['c102', 'Karta techniczna', 'AERO TC100 JAUNE FLUO-GRIS (C102) technical-sheet PL', self::ATTACHMENTS.'9000013'.self::ATTACHMENT_QUERY],
                ['', 'Instrukcja użytkowania', 'AERO TC100 UI', self::MEDIA.'8e8e8e8e8e8e8e8e/original/AERO-TC100-UI.pdf'],
            ],
        ]);
    }

    /**
     * Kamizelka jak 208V2 bez żadnej ceny konta: „0,00 zł” przy „Termin do potwierdzenia” i pusta komórka ceny.
     *
     * @return array<string, mixed>
     */
    private static function ts208(): array
    {
        return self::spec([
            'trail' => [['/pl/dp/body-protection', 'Ochrona ciała'], ['/pl/dp/high-visibility', "\u{200B}Ostrzegawcza zewnętrzna"]],
            'category' => "\u{200B}Ostrzegawcza zewnętrzna",
            'name' => 'TS208',
            'ref' => 'TS208',
            'colors' => ['c085' => 'Żółty fluo'],
            'rows' => [
                self::row('TS208JAM', 'Żółty fluo', 'M', '3200000000219', '10', '1', 'Termin do potwierdzenia', '0,00 zł'),
                self::row('TS208JAL', 'Żółty fluo', 'L', '3200000000226', '10', '1', 'Termin do potwierdzenia', ''),
            ],
        ]);
    }

    /**
     * Wyrób, którego h1 jest samym kodem (jak 22180, AM002, 208V2), z jedną wersją.
     *
     * @return array<string, mixed>
     */
    private static function codeNamed(string $code, string $category, string $short, string $ref, string $color, string $size, string $price): array
    {
        return self::spec([
            'category' => $category,
            'name' => $code,
            'short' => $short,
            'ref' => $code,
            'colors' => ['c204' => $color],
            'rows' => [self::row($ref, $color, $size, '32000000'.str_pad((string) (crc32($ref) % 100000), 5, '0', STR_PAD_LEFT), '10', '1', 'Dostępne', $price)],
        ]);
    }

    /**
     * Arkusz 1 „Cennika publicznego”: tytuł, nagłówek, wiersze grup bez ceny, wiersze wyrobów (MODEL = h1 karty,
     * KOLOR wielkimi literami, cena liczbowa).
     *
     * @return list<list<mixed>>
     */
    private static function publicPriceRows(): array
    {
        return [
            [null, null, null, 'CENNIK PUBLICZNY 01/01/2026'],
            ['MODEL', 'STATUS', 'KOLOR', 'ILOŚĆ W KARTONIE', 'MIN ZAM.', 'OPIS', 'ROZMIARY', 'CENA PLN NETTO 01/2026'],
            ['OCHRONA RĄK'],
            ['NEPTUN TT733', '', 'ŻÓŁTY FLUO-CZARNY', 120, 12, 'Rękawice powlekane lateksem', '7-8-9-10', 12.01],
            ['NEPTUN TT733', '', 'POMARAŃCZOWY FLUO-CZARNY', 120, 12, 'Rękawice powlekane lateksem', '7-8-9-10', 12.01],
            ['OCHRONA GŁOWY'],
            ['AERO TC100', '', 'CZARNO-CZERWONY', 20, 1, 'Hełm lekki', 'Krótki daszek', 90.0],
            ['AERO TC100', 'WYPRZEDAŻ', 'CZARNO-CZERWONY', 20, 1, 'Hełm lekki', 'Długi daszek', 95.0],
            ['aero  tc100 ', '', "\u{200B}granatowo-pomarańczowy ", 20, 1, 'Hełm lekki', 'Krótki daszek', 97.0],
            ['OCHRONA PRZED UPADKIEM'],
            // bez OPIS — karta AM902 (h1 = kod) nazwana z krótkiego opisu (reguła zapasowa cardName); nazwę z OPIS
            // sprawdza test_name_of_a_code_titled_product_takes_the_price_list_description_before_short_and_category
            ['AM902', '', 'STALOWY', 10, 1, '', 'Uniwersalny', 184],
            ['OCHRONA CIAŁA'],
            // cena zerowa — w cenniku brak ceny
            ['TS208', '', 'ŻÓŁTY FLUO', 10, 1, 'Kamizelka', 'M-XXL', 0],
        ];
    }

    /**
     * @param  list<list<mixed>>  $rows
     * @param  list<list<mixed>>  $secondSheet
     */
    private static function xlsx(array $rows, array $secondSheet = []): string
    {
        $spreadsheet = new Spreadsheet;
        $spreadsheet->getActiveSheet()->setTitle('Cennik')->fromArray($rows, null, 'A1', true);
        if ($secondSheet !== []) {
            $spreadsheet->createSheet()->setTitle('Promocja')->fromArray($secondSheet, null, 'A1', true);
        }
        $path = (string) tempnam(sys_get_temp_dir(), 'deltaplus');
        try {
            (new Xlsx($spreadsheet))->save($path);

            return (string) file_get_contents($path);
        } finally {
            @unlink($path);
        }
    }

    /** Najmniejszy poprawny PNG; wypełnienie różni się adresem, żeby zapis nie uznał zdjęć za ten sam plik. */
    private static function pngBytes(string $path): string
    {
        $image = imagecreatetruecolor(2, 2);
        imagefill($image, 0, 0, (int) (crc32($path) & 0xFFFFFF));
        ob_start();
        imagepng($image);

        return (string) ob_get_clean();
    }

    private static function e(string $text): string
    {
        return htmlspecialchars($text, ENT_QUOTES | ENT_HTML5, 'UTF-8');
    }

    /** Szkielet strony witryny: nagłówek z wyszukiwarką; w widoku zalogowanym odnośnik wylogowania. */
    private static function shell(string $body, bool $signedIn, string $title = 'Delta Plus'): string
    {
        $account = $signedIn
            ? '<a href="/group/delta-plus/local-sales-offer" class="header__link">Oferta handlowa</a>'
                .'<a href="/c/portal/logout" class="header__link header__link--logout">Wyloguj się</a>'
            : '<a href="/pl/espace-pro-landing" class="header__link">Przestrzeń partnera</a>';

        return '<!DOCTYPE html><html class="ltr" dir="ltr" lang="pl-PL"><head><title>'.self::e($title).'</title>'
            .'<meta content="text/html; charset=UTF-8" http-equiv="content-type" /></head><body>'
            ."\n<header id=\"banner\">"
            .'<form action="https://www.deltaplus.eu/pl/search" id="dkrk___fm" method="get" name="dkrk___fm">'
            .'<input name="q" type="text" value=""></form>'.$account."</header>\n"
            .$body
            ."\n</body></html>";
    }

    /**
     * Strona logowania: najpierw formularz danych konta innego portletu (też z polem hasła), potem formularz
     * LoginPortlet z action zakodowanym &amp;, polami ukrytymi, loginem, hasłem i polem wyboru „Zapamiętaj mnie”.
     */
    private static function loginPage(bool $failed): string
    {
        $ns = self::LOGIN_NS;
        $hidden = static fn (string $name, string $value): string => '<input class="field form-control" id="'.$ns.$name.'" name="'
            .$ns.$name.'" type="hidden" value="'.$value.'" />';
        $other = '_com_dp_minos_user_info_ManageUserInfoPortlet_';

        return self::shell(
            '<form action="https://www.deltaplus.eu/pl/espace-pro-landing?p_p_id=com_dp_minos_user_info_ManageUserInfoPortlet&amp;p_p_lifecycle=1&amp;p_auth='.self::P_AUTH.'"'
            .' id="'.$other.'fm" method="post" name="'.$other.'fm">'
            .'<input class="field form-control" id="'.$other.'formDate" name="'.$other.'formDate" type="hidden" value="1790000000001" />'
            .'<input class="field form-control" id="'.$other.'password" name="'.$other.'password" type="password" value="" />'
            ."</form>\n"
            .($failed ? '<div class="alert alert-danger" role="alert">Uwierzytelnienie nie powiodło się. Spróbuj ponownie.</div>' : '')
            .'<form action="'.htmlspecialchars(self::LOGIN_ACTION, ENT_QUOTES | ENT_HTML5).'" class="form sign-in-form"'
            .' data-fm-namespace="'.$ns.'" id="'.$ns.'loginForm" method="post" name="'.$ns.'loginForm" autocomplete="on">'
            ."\n".$hidden('formDate', '1790000000000')
            ."\n".$hidden('saveLastPath', 'false')
            ."\n".$hidden('redirect', '')
            ."\n".$hidden('doActionAfterLogin', 'false')
            ."\n".'<div class="form-group input-text-wrapper"><label class="control-label" for="'.$ns.'login">Adres e-mail</label>'
            .'<input autocomplete="username" class="field form-control" id="'.$ns.'login" name="'.$ns.'login" type="text" value="" /></div>'
            ."\n".'<div class="form-group input-text-wrapper"><label class="control-label" for="'.$ns.'password">Hasło</label>'
            .'<input autocomplete="current-password" class="field form-control" id="'.$ns.'password" name="'.$ns.'password" type="password" value="" /></div>'
            ."\n".'<div class="form-group form-inline input-checkbox-wrapper"><label for="'.$ns.'rememberMe">'
            .'<input class="field" id="'.$ns.'rememberMe" name="'.$ns.'rememberMe" type="checkbox" /> Zapamiętaj mnie</label></div>'
            ."\n".'<input class="field" id="'.$ns.'checkboxNames" name="'.$ns.'checkboxNames" type="hidden" value="rememberMe" />'
            ."\n".'<div class="button-holder"><button class="btn btn-primary" type="submit"><span class="lfr-btn-label">Zaloguj się</span></button></div>'
            .'</form>',
            false,
            'Przestrzeń partnera - Delta Plus',
        );
    }

    /**
     * Strona listy kategorii: kafelki z czterema odnośnikami do karty, licznik wyników, paginacja ?start=N.
     *
     * @param  list<string>  $hrefs
     */
    private static function listHtml(string $category, array $hrefs, int $lastPage, int $page, bool $signedIn): string
    {
        $tiles = '';
        $seen = [];
        foreach ($hrefs as $href) {
            $code = mb_strtoupper((string) preg_replace('/[?#].*$/', '', basename($href)));
            $seen[$code] = true;
            $tiles .= "\n".'<div class="product-card">'
                .'<a href="'.$href.'"><img class="product-card__image" src="'.self::MEDIA.'0000000000000000/Liferay_Product_Card-'.$code.'.png" alt="'.$code.'"></a>'
                .'<a href="'.$href.'"><div class="product-card__content"><h3 class="product-card__title">'.$code.'</h3></div></a>'
                .'<div class="product-card__footer"><a href="'.$href.'"><div><span>Ref.</span><span class="uppercase">'.$code.'</span></div></a>'
                .'<a href="'.$href.'" class="product-card__cta"><em class="product-card__cta__icon"></em></a></div>'
                .'</div>';
        }

        $pagination = '';
        if ($lastPage > 1) {
            $link = static fn (int $n, string $label, string $extra = ''): string => '<li class="page-item"><a class="page-link'.($extra !== '' ? ' lfr-portal-tooltip' : '').'"'
                .' href="/pl/dp/'.$category.'?start='.$n.'" onclick=""'.($extra !== '' ? ' title="'.$extra.'"' : '').'>'.$label.'</a></li>';
            $pagination = '<nav aria-label="Paginacja"><ul class="pagination">';
            if ($page > 1) {
                $pagination .= $link($page - 1, '&laquo;', 'Poprzednia strona');
            }
            for ($n = 1; $n <= $lastPage; $n++) {
                $pagination .= $link($n, '<span class="sr-only">Strona&nbsp;</span>'.$n);
            }
            if ($page < $lastPage) {
                $pagination .= $link($page + 1, '&raquo;', 'Następna strona');
            }
            $pagination .= '</ul></nav>';
        }

        return self::shell(
            '<nav aria-label="Breadcrumb"><section class="ariane-container"><a href="/pl/web/delta-plus" class="ariane__link">Delta Plus</a>'
            .'<a href="/pl/dp/'.$category.'" class="ariane__link">'.$category.'</a></section></nav>'
            .'<p class="search-total-label">'.(count($seen) + max(0, $lastPage - 1) * 15).' Results</p>'
            .'<div class="product-cards">'.$tiles."\n</div>".$pagination,
            $signedIn,
        );
    }

    /**
     * Karta wyrobu w znacznikach witryny. Widok zalogowany: notka nad tabelą i kolumny Dostępność / Cena / Ilość;
     * „priceColumns” = false daje stronę zalogowaną z tabelą gościa (bez ceny).
     *
     * @param  array<string, mixed>  $p
     */
    private static function productHtml(array $p, bool $signedIn, string $slug): string
    {
        $e = self::e(...);
        $withPrices = $signedIn && $p['priceColumns'];

        $crumbs = '<a href="/pl/web/delta-plus" class="ariane__link">Delta Plus</a>'
            ."\n".'<a href="https://www.deltaplus.eu/pl/ppe-solutions" class="ariane__link">PPE solutions</a>';
        foreach ($p['trail'] as [$href, $label]) {
            $crumbs .= "\n".'<a href="'.$href.'" class="ariane__link">'.$e($label).'</a>';
        }
        $crumbs .= "\n".'<span class="ariane__current">'.$e($p['name']).'</span>';

        $gallery = '';
        foreach ($p['gallery'] as $i => [$color, $preview]) {
            $index = $i + 1;
            if ($color === '360') {
                $gallery .= "\n".'<img data-index="'.$index.'" data-color="" src="/o/dp.product.renderer/icons/logo_360_thumbnail.svg"'
                    .' style="background-image: url('.self::MEDIA.'f6f6f6f6f6f6f6f6/Liferay_Product_Thumbnail_Crop-360-01.png)"'
                    .' data-preview=""'
                    ." data-360='[\"".self::MEDIA.'f6f6f6f6f6f6f6f6/Liferay_Product-360-01.png","'.self::MEDIA."f7f7f7f7f7f7f7f7/Liferay_Product-360-02.png\"]'"
                    .' alt="360" class="product-images__thumbnails__item " onClick="selectImage('.$index.')" />';

                continue;
            }
            $gallery .= "\n".'<img data-index="'.$index.'" data-color="'.$color.'"'
                .' src="'.str_replace('Liferay_Product-', 'Liferay_Product_Thumbnail_Crop-', $preview).'"'
                .' data-preview="'.$preview.'"'
                .' data-zoom="'.str_replace('Liferay_Product-', 'Liferay_Product_Zoom-', $preview).'"'
                .' alt="'.md5($preview).'-1.eps" class="product-images__thumbnails__item '.($index === 1 ? 'img-active' : '').'"'
                .' onClick="selectImage('.$index.')" />';
        }

        $colors = '';
        foreach ($p['colors'] as $code => $label) {
            $label = "\u{200B}".$label;
            $colors .= "\n".'<div class="custom-control custom-radio"><input type="radio" id="color-option-'.$code.'" name="colorOption"'
                .' value="'.$code.'" class="custom-control-input" aria-label="'.$label.'">'
                .'<label class="custom-control-label" for="color-option-'.$code.'">'.$label.'</label>'
                .'<img src="https://assets.ppe-analytics.com/delta-plus/00/00/kolor-'.$code.'-0-300.png" alt="'.$label.'" title="'.$label.'"'
                .' width="30" height="30" loading="lazy" data-color-id="'.$code.'"></div>';
        }

        $firstSheet = $p['documents'][0] ?? null;
        $topSheet = $firstSheet !== null
            ? '<a href="'.$e($firstSheet[3]).'" data-color="'.$firstSheet[0].'" target="_blank" class="hide btn btn--blue btn--tall btn-icon-file-download mr-xs">'
                ."\n                            Karta techniczna\n                        </a>"
            : '';

        $list = static function (array $items) use ($e): string {
            $out = '';
            foreach ($items as $i => $item) {
                $out .= "\n".'<li data-index="'.$i.'"> '.$e($item).' </li>';
            }

            return $out;
        };

        $zones = '';
        foreach ($p['zones'] as $title => $properties) {
            $zones .= "\n".'<div class="view__zone"><p class="view__zone_title">'.$e($title).'</p>';
            foreach ($properties as $property) {
                $zones .= "\n    ".'<p class="view__zone_property">'.$e($property).'</p>';
            }
            $zones .= "\n</div>";
        }

        $norms = '';
        foreach ($p['norms'] as [$test, $header, $value]) {
            $norms .= "\n".'<div class="norm-item-card"><table class="norms-table"><tr>'
                ."\n".'<td test="'.$test.'"><a href="/web/delta-plus/standards-and-directives?segment=5327619#'.$test.'" target="_blank">'
                .'<img src="https://assets.ppe-analytics.com/delta-plus/00/00/'.$test.'-0-300.png" alt="'.$e($header).'" />'
                ."\n".'<h5 class="norms-header">'."\n                    ".$e($header)."\n                </h5>"
                ."\n<a/></td>"
                ."\n<td>\n<div>\n                    ".$e($value)."\n                </div>\n</td>"
                ."\n</tr></table></div>";
        }

        $advantages = '';
        foreach ($p['advantages'] as [$title, $text]) {
            $advantages .= "\n".'<div class="flex product-advantage"><img alt="'.$e($title).'" src="https://assets.ppe-analytics.com/delta-plus/00/00/zaleta-0-300.png"'
                .' class="product-advantage__image" /><div><h5>'.$e($title).'</h5>'."\n    <p>".$e($text).'</p></div></div>';
        }

        $headers = ['Referencja', 'Kolor', 'Rozmiar', 'Model', 'EAN 13', 'Kod kartonu', 'Ilość w kart.', 'Min. zam.', 'Waga'];
        $head = '';
        foreach ($headers as $header) {
            $head .= "\n<th>".$e($header).'</th>';
        }
        if ($withPrices) {
            $head .= "\n<th>Dostępność</th>\n<th>Cena jeśli cały karton*</th>\n".'<th class="product-tabs__quantity-header">Ilość</th>';
        }
        $body = '';
        foreach ($p['rows'] as [$ref, $color, $size, $ean, $carton, $qty, $min, $weight, $availability, $price]) {
            $body .= "\n<tr>\n".'<td><div><a href="https://www.deltaplus.eu/pl/p/'.$slug.'">'.$ref.'</a></div></td>'
                // widok zalogowany ma U+200B jako encję, widok gościa — jako znak
                ."\n<td>".($withPrices ? '&#8203;' : "\u{200B}").$e($color).'</td>'
                ."\n<td>".$e($size)."</td>\n<td>".$e($p['models'][$ref] ?? $p['name'])."</td>\n<td>".$ean."</td>\n<td>".$carton
                ."</td>\n<td>".$qty."</td>\n<td>".$min."</td>\n<td>".$weight.'</td>';
            if ($withPrices) {
                $body .= "\n".'<td><span class="stock" style="background-color:#768987">'.$e($availability).'</span></td>'
                    ."\n<td>".($price !== '' ? $e($price).'&#8206;' : '').'</td>'
                    ."\n".'<td><div class="add-to-cart mb-2" id="zjob___add_to_cart" data-html2canvas-ignore></div></td>';
            }
            $body .= "\n</tr>";
        }
        $order = '<label class="product-tabs__tab-header" data-index="3">'.($withPrices ? 'Referencje do zamówienia' : 'Informacje logistyczne').'</label>'
            ."\n".'<div class="product-tabs__tab-content product_order_tab hide" data-index="3">'
            .($withPrices ? "\n".'<div class="m-1 product-tables__tab-content-mention">'."\n            ".self::MENTION."\n        </div>" : '')
            ."\n".'<div class="product-logistic table-responsive" id="product__tab_logistic__container">'
            ."\n".'<table class="logistic-table table-column-text-left"><thead><tr>'.$head."\n</tr></thead><tbody>".$body
            ."\n</tbody></table></div></div>";

        $documents = '';
        foreach ($p['documents'] as [$color, $type, $title, $href]) {
            // href bez cudzysłowów, jak na witrynie
            $documents .= "\n".'<div class="product-sheet" data-color="'.$color.'">'
                .'<div class="product-sheet__file-icon"><svg xmlns="http://www.w3.org/2000/svg" width="15" height="14" viewBox="0 0 25 24" fill="none"><path d="M19.5 9V17.8"/></svg></div>'
                ."\n".'<strong class="product-sheet__type">'."\n                    ".$e($type)."\n                </strong>"
                ."\n".'<a class="product-sheet__link" target="_blank" href='.$href.'>'.$e($title).'</a>'
                ."\n".'<div class="product-sheet__end-icons"><a href='.$href.' target="_blank" class="product-sheet__download icon-download2">'
                ."\n                        Pobierz\n                    </a>"
                .'<div class="add-to-cart" id="zjob___add_to_selection" data-html2canvas-ignore><div class=""><div class="skeleton"></div></div></div>'
                .'</div></div>';
        }

        $body = '<nav aria-label="Breadcrumb" id="_com_liferay_site_navigation_breadcrumb_web_portlet_SiteNavigationBreadcrumbPortlet_INSTANCE_dxbw_breadcrumbs-defaultScreen">'
            ."\n".'<section class="ariane-container flex flex-wrap align-center my-s">'."\n".$crumbs."\n</section>\n</nav>"
            ."\n".'<div class="flex flex-col flex m:d-none product-images__thumbnails">'.$gallery."\n</div>"
            ."\n".'<div class="product-description">'
            ."\n".'<h3 class="product-description__category">'.$e($p['category']).'</h3>'
            ."\n".'<h1 class="product-description__name">'.$e($p['name']).'</h1>'
            ."\n".'<p class="product-description__short-description">'.$e($p['short']).'</p>'
            ."\n".'<span class="product-description__ref">'."\n<div>\n".'<span>Ref. : </span>'."\n".'<span class="uppercase">'.$e($p['ref']).'</span>'
            ."\n</div>\n<div>\n</div>\n</span>"
            ."\n".'<div class="flex mt-s mb-xs"><div class="technical-documentation-container">'.$topSheet.'</div></div>'
            ."\n</div>"
            ."\n".'<div id="renderOptionsContainer"><fieldset class="option-image"><div class="lfr-ddm-legend"><span>Dostępne kolory</span></div>'
            .'<div class="ddm__radio-wrapper"><div class="ddm__radio" id="colorOptionsContainer">'.$colors."\n</div></div></fieldset></div>"
            ."\n".'<section class="product-description-tabs"><div class="tabs"><div class="tabs__bar">'
            .'<label for="description-tab-0" class="tabs__bar__item">Sektory</label><label for="description-tab-1" class="tabs__bar__item">Zagrożenia</label></div>'
            ."\n".'<div class="tabs__content" data-tab="0"><ul>'.$list($p['sectors'])."\n</ul></div>"
            ."\n".'<div class="tabs__content" data-tab="1" ><ul>'.$list($p['hazards'])."\n</ul></div>"
            ."\n".'<div class="tabs__content tab__situation" data-tab="2" data-lfr-editable-id="tabContent" data-lfr-editable-type="rich-text"><ul>'."\n</ul></div>"
            ."\n</div></section>"
            ."\n".'<label class="product-tabs__tab-header " data-index="0">Opis</label>'
            ."\n".'<div class="product-tabs__tab-content"  data-index="0"><div class="container description" id="product__tab_description__container">'
            ."\n<div>\n<div>\n                ".$e($p['shortcut'])."\n            </div>\n</div>"
            ."\n".'<div id="product__tab_description__labels__container">'.$zones."\n</div></div></div>"
            ."\n".'<label class="product-tabs__tab-header " data-index="1">Normy/certyfikaty</label>'
            ."\n".'<div class="product-tabs__tab-content hide" data-index="1"><div class="container product-standard-container" id="product__tab_standard__container">'
            .$norms
            ."\n".'<a href="/web/delta-plus/standards-and-directives?segment=5327619" target="_blank" class="btn btn--tall btn--primary" id="product__tab_standard__all_button">Zobacz wszystkie normy</a>'
            ."\n</div></div>"
            ."\n".'<label class="product-tabs__tab-header " data-index="2">Zalety produktu</label>'
            ."\n".'<div class="product-tabs__tab-content hide" data-index="2"><div class="container product-advantage-container" id="product__tab_advantage__container">'
            .$advantages."\n</div></div>"
            ."\n".$order
            ."\n".'<label class="product-tabs__tab-header " data-index="4">Dokumentacja</label>'
            ."\n".'<div class="product-tabs__tab-content hide" data-index="4"><div class="container product-documentation-container" id="product__tab_document__container">'
            .$documents."\n</div></div>";

        return self::shell($body, $signedIn, $p['name'].' - Delta Plus');
    }

    /** Strona oferty handlowej konta z odnośnikami do plików biblioteki dokumentów. */
    private function offerHtml(): string
    {
        $links = '';
        foreach ($this->offerLinks as $href) {
            $links .= "\n".'<li><a href="'.$href.'" target="_blank" class="document-link">'.basename($href).'</a></li>';
        }

        return self::shell('<h1>Oferta handlowa</h1><ul class="documents">'.$links."\n</ul>", true);
    }

    private function fakeSite(): void
    {
        $this->assetFiles += [
            self::PRICE_LIST => self::xlsx(self::publicPriceRows()),
            // cennik promocji: gdyby łącznik wziął ten plik, NEPTUN dostałby cenę katalogową 1,00
            self::PROMO_LIST => self::xlsx([
                ['MODEL', 'STATUS', 'KOLOR', 'ILOŚĆ W KARTONIE', 'MIN ZAM.', 'OPIS', 'ROZMIARY', 'CENA PROMOCYJNA'],
                ['NEPTUN TT733', '', 'ŻÓŁTY FLUO-CZARNY', 120, 12, 'Promocja', '7-10', 1.0],
                ['NEPTUN TT733', '', 'POMARAŃCZOWY FLUO-CZARNY', 120, 12, 'Promocja', '7-10', 1.0],
            ]),
            'delta-plus-cennik-publiczny-01-2026-1-pdf' => "%PDF-1.4\ncennik pdf\n%%EOF",
        ];
        if ($this->offerLinks === []) {
            $this->offerLinks = [
                self::ASSETS.self::PROMO_LIST,
                self::ASSETS.'delta-plus-cennik-publiczny-01-2026-1-pdf',
                self::ASSETS.self::PRICE_LIST,
            ];
        }

        Http::fake(function (Request $request) {
            $url = $request->url();
            $host = (string) parse_url($url, PHP_URL_HOST);
            $path = (string) parse_url($url, PHP_URL_PATH);
            parse_str((string) parse_url($url, PHP_URL_QUERY), $query);
            preg_match('/JSESSIONID=(\w+)/', $request->header('Cookie')[0] ?? '', $m);
            $signedIn = isset($m[1]) && in_array($m[1], $this->validSessions, true);
            $html = ['Content-Type' => 'text/html;charset=UTF-8'];

            if ($host === 'www.deltaplus.eu') {
                if ($path === '/pl/espace-pro-landing' && $request->method() === 'GET') {
                    $this->anonymous++;

                    return Http::response(self::loginPage(false), 200, [
                        ...$html,
                        'Set-Cookie' => 'JSESSIONID=anon'.$this->anonymous.'; Path=/; Secure; HttpOnly',
                    ]);
                }
                if ($path === '/pl/espace-pro-landing' && $request->method() === 'POST') {
                    $data = $request->data();
                    // jak Liferay: złe dane = ta sama strona logowania z komunikatem, HTTP 200, bez sesji konta
                    $good = ($query['p_auth'] ?? null) === self::P_AUTH
                        && ($query['p_p_id'] ?? null) === 'com_liferay_login_web_portlet_LoginPortlet'
                        && ($data[self::LOGIN_NS.'login'] ?? null) === self::USER
                        && ($data[self::LOGIN_NS.'password'] ?? null) === self::PASSWORD;
                    if (! $good) {
                        return Http::response(self::loginPage(true), 200, $html);
                    }
                    $this->logins++;
                    $this->validSessions[] = 'sess'.$this->logins;

                    return Http::response(self::shell('<h1>Przestrzeń partnera</h1>', true), 200, [
                        ...$html,
                        'Set-Cookie' => 'JSESSIONID=sess'.$this->logins.'; Path=/; Secure; HttpOnly',
                    ]);
                }
                if ($path === '/group/delta-plus/' || $path === '/group/delta-plus') {
                    return Http::response($signedIn ? self::shell('<h1>Przestrzeń partnera</h1>', true) : self::loginPage(false), 200, $html);
                }
                if ($path === '/group/delta-plus/local-sales-offer') {
                    return Http::response($signedIn ? $this->offerHtml() : self::loginPage(false), 200, $html);
                }
                if (str_starts_with($path, self::ASSETS)) {
                    $name = basename($path);

                    return isset($this->assetFiles[$name])
                        ? Http::response($this->assetFiles[$name], 200, [
                            'Content-Type' => str_ends_with($name, '-xlsx')
                                ? 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet'
                                : 'application/pdf',
                        ])
                        : Http::response('nie ma', 404);
                }
                if (preg_match('#^/pl/dp/([a-z0-9-]+)$#', $path, $c) === 1) {
                    $pages = $this->lists[$c[1]] ?? [1 => []];
                    $page = (int) ($query['start'] ?? 1);

                    return Http::response(self::listHtml(
                        $c[1],
                        array_map(static fn (string $slug): string => '/p/'.$slug, $pages[$page] ?? []),
                        count($pages),
                        $page,
                        $signedIn,
                    ), 200, $html);
                }
                if (preg_match('#^/pl/p/([a-z0-9-]+)$#', $path, $s) === 1) {
                    $this->productRequests[] = $s[1];
                    if ($this->dropSessionOnProduct) {
                        $this->dropSessionOnProduct = false;
                        $this->validSessions = [];
                        $signedIn = false;
                    }
                    $spec = $this->pages[$s[1]] ?? null;
                    if ($spec === null) {
                        return Http::response(self::shell('<h1>Nie znaleziono strony</h1>', $signedIn), 404, $html);
                    }

                    return Http::response(self::productHtml($spec, $signedIn && ! $this->productsAlwaysAnonymous, $s[1]), 200, $html);
                }
                if (str_starts_with($path, self::ATTACHMENTS)) {
                    // jak witryna: załączniki commerce-media idą jako application/octet-stream
                    return Http::response("%PDF-1.4\n".$url."\n%%EOF", 200, ['Content-Type' => 'application/octet-stream']);
                }
            }
            if ($host === 'media.deltaplus.eu') {
                return str_ends_with($path, '.pdf')
                    ? Http::response("%PDF-1.5\n".$url."\n%%EOF", 200, ['Content-Type' => 'application/pdf'])
                    : Http::response(self::pngBytes($path), 200, ['Content-Type' => 'image/png']);
            }
            if ($host === 'delta-plus.ppe-analytics.com') {
                return Http::response("%PDF-1.7\n".$url."\n%%EOF", 200, ['Content-Type' => 'application/pdf']);
            }

            return Http::response('nieznany adres w teście: '.$url, 404);
        });
    }
}
