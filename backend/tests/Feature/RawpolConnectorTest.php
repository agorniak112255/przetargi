<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\B2bAccount;
use App\Models\B2bProductLink;
use App\Models\B2bSyncRun;
use App\Models\Product;
use App\Models\ProductDocument;
use App\Models\ProductShopCard;
use App\Models\ProductSourcePrice;
use App\Services\B2b\B2bAccountSyncRunner;
use App\Services\B2b\B2bConnectorRegistry;
use App\Services\B2b\B2bFatalException;
use App\Services\B2b\B2bManufacturerSite;
use App\Services\B2b\B2bRemoteProduct;
use App\Services\B2b\RawpolB2bClient;
use App\Services\B2b\RawpolB2bConnector;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use RuntimeException;
use Tests\TestCase;

/**
 * Łącznik web.rawpol.com na atrapie serwisu (Http::fake, bez prawdziwego logowania). Układ danych jak w serwisie
 * 21.09.2026: publishInfo.js z pulą danych, pliki def_*.js jako tablice pozycyjne JSON, treść wyrobu w info_pl
 * (opis z liniami rozdzielonymi CR, wersje z plikami i klasyfikacją NORMAn), API service.aspx z treścią w polu
 * formularza „body” i kopertą {status, logged, body}, pliki na media.rawpol.com wymagające Referer.
 * Wszystkie dane (wyroby, ceny, pliki) są SYNTETYCZNE.
 */
final class RawpolConnectorTest extends TestCase
{
    use RefreshDatabase;

    private const JPEG = "\xFF\xD8\xFF\xE0\x00\x10JFIF\x00\x01syntetyczny\xFF\xD9";

    private const POOL = 'https://web.rawpol.com/rtm_dat/pool/dat09x/';

    /** @var list<string> */
    private array $validSessions = [];

    private int $logins = 0;

    /** Sesje wygasają przy pierwszym zapytaniu o ceny. */
    private bool $dropSessionsOnPrices = false;

    /** Serwis nigdy nie widzi konta przy zapytaniu o ceny. */
    private bool $pricesAlwaysAnonymous = false;

    /** @var list<list<string>> kody wyrobów w kolejnych zapytaniach o ceny */
    private array $priceRequests = [];

    public function test_login_sends_the_sha1_of_the_utf16_password_in_the_body_field(): void
    {
        $this->fakeSite();
        $client = $this->client();

        $client->login();

        $this->assertTrue($client->isLoggedIn());
        $post = Http::recorded(fn (Request $r): bool => str_contains($r->url(), 'req=LogujKlienta'))->first()[0];
        $this->assertSame([
            'login' => 'supon-test',
            'haslo' => '',
            'hasloSHA1' => strtoupper(sha1(mb_convert_encoding('dobre-hasło', 'UTF-16LE', 'UTF-8'))),
        ], self::body($post));
        $this->assertSame('https://web.rawpol.com', $post->header('Origin')[0]);
    }

    public function test_wrong_password_fails_with_the_reason_from_the_logon_status(): void
    {
        $this->fakeSite();
        $client = new RawpolB2bClient('supon-test', 'zle-haslo', 0, static function (int $ms): void {});

        try {
            $client->login();
            $this->fail('Logowanie powinno się nie udać');
        } catch (RuntimeException $e) {
            $this->assertNotInstanceOf(B2bFatalException::class, $e);
            $this->assertSame('Logowanie do rawpol.com nieudane: zły login lub hasło — sprawdź login i hasło', $e->getMessage());
        }
        $this->assertFalse($client->isLoggedIn());
    }

    public function test_list_skips_presentation_only_items_and_promotions_and_prefers_the_basic_form_of_a_symbol(): void
    {
        $listed = RawpolB2bConnector::listedProducts(self::coreList());
        $products = array_column($listed['products'], null, 'id');

        $this->assertSame(5, $listed['all']);
        // hn-1000001 ma tylko wersję z numerem ujemnym (sama prezentacja)
        $this->assertSame(['rtest', 'ox-test', 'zz-test', 'x-bez-marki'], array_keys($products));
        // RTESTS: forma „OLD” przed „Podstawowa” pod tym samym symbolem — zostaje podstawowa; promocja pominięta
        $this->assertSame(
            [['RTESTS', 1001], ['RTESTM', 1002], ['RTESTL', 1003]],
            array_map(static fn (array $v): array => [$v['symbol'], $v['ref']], $products['rtest']['versions']),
        );
        $this->assertSame('WS', $products['ox-test']['versions'][0]['color']);
    }

    public function test_versions_in_one_price_are_one_card_the_largest_group_gets_the_product_code(): void
    {
        $product = RawpolB2bConnector::listedProducts(self::coreList())['products'][1];

        $cards = RawpolB2bConnector::priceGroups($product, self::dynamic()['ox-test']);

        $this->assertSame(['OX-TEST_WS7', 'OX-TEST'], array_column($cards, 'sku'));
        $this->assertSame(['OX-TEST_WS7'], array_column($cards[0]['versions'], 'symbol'));
        $this->assertSame(['OX-TEST_BS7', 'OX-TEST_BS8'], array_column($cards[1]['versions'], 'symbol'));
        // wersja bez ceny konta nie trafia do żadnej karty
        $this->assertSame([], RawpolB2bConnector::priceGroups($product, ['wersje' => []]));
    }

    public function test_products_carry_the_account_price_brand_description_norms_and_availability_from_the_site(): void
    {
        $this->fakeSite();
        $products = $this->productsBySku($this->connector());

        $this->assertSame(['RTEST', 'OX-TEST_WS7', 'OX-TEST', 'ZZ-TEST', 'X-BEZ-MARKI'], array_keys($products));
        $connector = $this->connector();
        $rtest = $products['RTEST'];
        $this->assertSame('RTESTS', $rtest->remoteId);
        $this->assertSame('Rękawice testowe RTEST.', $rtest->name);
        $this->assertSame('RĘKAWICE NITRYLOWE', $rtest->category);
        $this->assertSame('https://web.rawpol.com/?v=RTEST&lang=pl', $rtest->sourceUrl);
        $this->assertSame('Reis', $connector->manufacturer($rtest));
        $price = $connector->price($rtest);
        $this->assertSame(12.7, $price->net);
        $this->assertSame(18.71, $price->base);
        $this->assertSame(32.12, $price->discountPercent);
        $this->assertSame("Rękawice testowe RTEST.\n- wykonane z nitrylu\n- pakowane po 100 szt.", $connector->description($rtest));
        $this->assertSame('Wersje: niebieski s (RTESTS); niebieski m (RTESTM); niebieski l (RTESTL)', $rtest->variantSummary);
        $this->assertSame(['RTESTS', 'RTESTM', 'RTESTL'], array_column($rtest->members, 'remote_id'));
        $this->assertSame(
            'RTESTS: Produkt dostępny; RTESTM: Produkt chwilowo niedostępny, czas realizacji 14-21 dni; RTESTL: Produkt dostępny',
            $rtest->availability,
        );

        $fields = array_map(
            static fn ($f): string => $f->section.' | '.$f->name.' | '.$f->value,
            $connector->shopFields($rtest),
        );
        $this->assertSame([
            'Dane wyrobu | Marka | REIS',
            'Dane wyrobu | Grupa | ochrona rąk',
            'Dane wyrobu | Kategoria | RĘKAWICE NITRYLOWE',
            'Dane wyrobu | Norma | EN ISO 21420',
            'Dane wyrobu | Norma | EN388 4121X',
            'Dane wyrobu | Norma | OEKO-TEX Standard 100* — *dotyczy materiału',
            'Dane wyrobu | Kategoria ochrony | Kategoria I',
            'Dane wyrobu | Branże | przemysł spożywczy',
            'Informacje handlowe | Kod towaru | RTESTS (EAN 5900000000011)',
            'Informacje handlowe | Kod towaru | RTESTM (EAN 5900000000012)',
            'Informacje handlowe | Kod towaru | RTESTL (EAN 5900000000013)',
            'Informacje handlowe | Cena za | opak.',
        ], $fields);

        // brak norm w klasyfikacji wersji — normy z listy wyrobu (słownik norm bazowych, dosłownie)
        $this->assertContains('Dane wyrobu | Norma | ENISO21420', array_map(
            static fn ($f): string => $f->section.' | '.$f->name.' | '.$f->value,
            $connector->shopFields($products['OX-TEST']),
        ));
        // marka spoza BRANDS — nazwa ze słownika serwisu, linia ze słownika linii
        $this->assertSame('OGRIFOX', $connector->manufacturer($products['OX-TEST']));
        $this->assertContains('Dane wyrobu | Linia | TACTICAL GUARD', array_map(
            static fn ($f): string => $f->section.' | '.$f->name.' | '.$f->value,
            $connector->shopFields($products['OX-TEST']),
        ));
        // „-” w słowniku kategorii ŚOI zastąpione objaśnieniem serwisu
        $this->assertContains('Dane wyrobu | Kategoria ochrony | Produkt nie jest środkiem ochrony indywidualnej (ŚOI)', array_map(
            static fn ($f): string => $f->section.' | '.$f->name.' | '.$f->value,
            $connector->shopFields($products['ZZ-TEST']),
        ));
    }

    public function test_item_without_brand_is_skipped_instead_of_getting_the_wholesaler_as_manufacturer(): void
    {
        $this->fakeSite();
        $products = $this->productsBySku($this->connector());

        $this->expectExceptionMessage('serwis nie podaje marki wyrobu — pozycja pominięta');

        $this->connector()->manufacturer($products['X-BEZ-MARKI']);
    }

    public function test_documents_take_one_file_per_title_polish_first_english_only_without_polish_never_romanian(): void
    {
        $this->fakeSite();
        $connector = $this->connector();
        $products = $this->productsBySku($connector);

        $documents = $connector->documents($products['RTEST']);

        $this->assertSame([
            ['Deklaracja zgodności - UE - PL', ProductDocument::KIND_CERTIFICATE, RawpolB2bClient::MEDIA.'11/0000000011.pdf'],
            ['Broszura informacyjna/ostrzeżenia - PL', ProductDocument::KIND_MANUAL, RawpolB2bClient::MEDIA.'14/0000000014.pdf'],
        ], array_map(static fn ($d): array => [$d->title, $d->kind, $d->sourceUrl], $documents));
        $this->assertSame(
            [['Deklaracja zgodności - EN', ProductDocument::KIND_CERTIFICATE]],
            array_map(static fn ($d): array => [$d->title, $d->kind], $connector->documents($products['OX-TEST'])),
        );

        $file = $connector->documentBytes($documents[0]);
        $this->assertSame('application/pdf', $file['mime']);
        $download = Http::recorded(fn (Request $r): bool => str_contains($r->url(), '0000000011.pdf'))->first()[0];
        $this->assertSame('https://web.rawpol.com/', $download->header('Referer')[0]);
    }

    public function test_prices_are_asked_in_batches_of_whole_products(): void
    {
        $this->fakeSite();

        $this->productsBySku($this->connector());

        $this->assertSame([['rtest', 'ox-test', 'zz-test', 'x-bez-marki']], $this->priceRequests);
    }

    public function test_price_request_without_session_logs_in_again_once(): void
    {
        $this->fakeSite();
        $this->dropSessionsOnPrices = true;

        $products = $this->productsBySku($this->connector());

        $this->assertCount(5, $products);
        $this->assertSame(2, $this->logins);
    }

    public function test_prices_refused_to_a_fresh_session_are_fatal(): void
    {
        $this->fakeSite();
        $this->pricesAlwaysAnonymous = true;

        $this->expectException(B2bFatalException::class);
        $this->expectExceptionMessage('Utracono sesję konta rawpol.com — ceny konta niedostępne');

        $this->productsBySku($this->connector());
    }

    public function test_sync_creates_cards_with_all_versions_prices_files_and_skips_the_item_without_brand(): void
    {
        Storage::fake('public');
        Queue::fake();
        $this->fakeSite();

        $result = app(B2bAccountSyncRunner::class)->run($this->account(), delayMs: 0);

        // 4 wyroby w sprzedaży, OX-TEST w dwóch cenach = 5 kart
        $this->assertSame(5, $result['total_remote']);
        $this->assertSame(4, $result['created']);
        $this->assertContains('X-BEZ-MARKI: serwis nie podaje marki wyrobu — pozycja pominięta', $result['errors']);

        $rtest = Product::query()->where('sku', 'RTEST')->sole();
        $this->assertSame('Reis', $rtest->manufacturer);
        $this->assertSame('Rękawice testowe RTEST.', $rtest->name);
        $this->assertSame(
            ['RTESTL', 'RTESTM', 'RTESTS'],
            B2bProductLink::query()->where('product_id', $rtest->id)->orderBy('remote_id')->pluck('remote_id')->all(),
        );
        $slot = ProductSourcePrice::query()->where('product_id', $rtest->id)->sole();
        $this->assertSame('12.70', (string) $slot->purchase_price);
        $this->assertSame(
            ['Deklaracja zgodności - UE - PL', 'Broszura informacyjna/ostrzeżenia - PL'],
            ProductDocument::query()->where('product_id', $rtest->id)->orderBy('sort_order')->pluck('title')->all(),
        );
        $this->assertTrue(ProductShopCard::query()->where('product_id', $rtest->id)->exists());
        $this->assertSame('1.02', (string) ProductSourcePrice::query()
            ->where('product_id', Product::query()->where('sku', 'OX-TEST_WS7')->value('id'))->value('purchase_price'));
        $this->assertSame('Anro', Product::query()->where('sku', 'ZZ-TEST')->value('manufacturer'));

        $log = array_column((array) B2bSyncRun::query()->latest('id')->firstOrFail()->log, 'text');
        $this->assertContains('Katalog Raw-Pol: 5 wyrobów, w sprzedaży 4 (wersji: 8)', $log);
        $this->assertContains('Karty: 5 (1 wyrobów w kilku cenach — rozmiar w innej cenie to osobna karta)', $log);
    }

    public function test_registry_detects_rawpol_by_host_and_it_is_not_a_manufacturer_site(): void
    {
        $registry = app(B2bConnectorRegistry::class);

        $this->assertSame('rawpol', $registry->keyForSites(['https://web.rawpol.com/?lang=pl']));
        $this->assertSame('Raw-Pol', $registry->label('rawpol'));
        $this->assertTrue($registry->requiresPassword('rawpol'));
        $account = B2bAccount::query()->create(['username' => 'supon-test', 'password' => 'sekret', 'sites' => ['web.rawpol.com']]);
        $connector = $registry->make($account, 0);
        $this->assertInstanceOf(RawpolB2bConnector::class, $connector);
        $this->assertNotInstanceOf(B2bManufacturerSite::class, $connector);
    }

    private function client(): RawpolB2bClient
    {
        return new RawpolB2bClient('supon-test', 'dobre-hasło', 0, static function (int $ms): void {});
    }

    private function connector(): RawpolB2bConnector
    {
        $connector = new RawpolB2bConnector($this->client());
        $connector->login();

        return $connector;
    }

    private function account(): B2bAccount
    {
        return B2bAccount::query()->firstOrCreate(
            ['username' => 'supon-test'],
            ['password' => 'dobre-hasło', 'sites' => ['https://web.rawpol.com/'], 'connector' => 'rawpol', 'sync_images' => false],
        )->fresh();
    }

    /**
     * @return array<string, B2bRemoteProduct>
     */
    private function productsBySku(RawpolB2bConnector $connector): array
    {
        $out = [];
        foreach ($connector->products() as $product) {
            $out[$product->sku] = $product;
        }

        return $out;
    }

    /**
     * Treść zapytania API z pola formularza „body”.
     *
     * @return array<string, mixed>
     */
    private static function body(Request $request): array
    {
        foreach ((array) $request->data() as $part) {
            if (is_array($part) && ($part['name'] ?? null) === 'body') {
                return (array) json_decode((string) $part['contents'], true);
            }
        }

        return [];
    }

    /**
     * Lista wyrobów w układzie def_produkty_core_list_pl: [base64url(kod), kod, marka, linia, nazwa, kategoria,
     * zdjęcie, miniatura, grupy kolorów [kod koloru, …, …, …, …, wersje [numer, forma, uwaga, rozmiar, EAN, …, …, symbol, flagi, 1]]].
     *
     * @return list<array<mixed>>
     */
    private static function coreList(): array
    {
        $v = static fn (int $ref, string $form, string $size, string $ean, string $symbol): array => [$ref, $form, '', $size, $ean, '', '', $symbol, 0, 1];

        return [
            ['aG4tMTAwMDAwMQ', 'hn-1000001', 'honeywell', '', 'MILLENNIA BLACK FRAME', 'pz', '1/0000000001.jpg', '', [['', '', '', '', '', [$v(-4903, '', '', '7312550000014', '1000001')], 8]], 8],
            ['cnRlc3Q', 'rtest', 'reis', '', 'Rękawice testowe RTEST.', 'rtn', '1/0000000002.jpg', '', [['N', '', '', '', '', [
                $v(1000, 'OLD', 's', '5900000000011', 'RTESTS'),
                $v(1001, 'Podstawowa', 's', '5900000000011', 'RTESTS'),
                $v(1002, 'Podstawowa', 'm', '5900000000012', 'RTESTM'),
                $v(1003, 'Podstawowa', 'l', '5900000000013', 'RTESTL'),
                $v(1004, 'Podstawowa_Promocyjna', 'l', '5900000000013', 'RTESTL'),
            ], 0]], 0],
            ['b3gtdGVzdA', 'ox-test', 'ogrifox', 'tactical guard', 'Rękawice ochronne OX-TEST.', 'rpn', '', '', [
                // mniejsza grupa cenowa pierwsza na liście — kod wyrobu i tak dostaje największa
                ['WS', '', '', '', '', [$v(2003, 'Podstawowa', '7', '5900000000023', 'OX-TEST_WS7')], 0],
                ['BS', '', '', '', '', [$v(2001, 'Podstawowa', '7', '5900000000021', 'OX-TEST_BS7'), $v(2002, 'Podstawowa', '8', '5900000000022', 'OX-TEST_BS8')], 0],
            ], 0],
            ['enotdGVzdA', 'zz-test', 'anro', '', 'Tablica testowa.', 'zb', '', '', [['', 'P', '', '', '', [$v(3001, 'Podstawowa', '700x500 mm', '', 'ZZ-TESTP700x500')], 40]], 40],
            ['eC1iZXotbWFya2k', 'x-bez-marki', '', '', 'Wyrób bez marki.', 'x', '', '', [['', '', '', '', '', [$v(4001, 'Podstawowa', '', '', 'X-BEZ-MARKI')], 0]], 0],
        ];
    }

    /**
     * Dane zmienne z API (GetProduktyDynamicData) wg kodu wyrobu.
     *
     * @return array<string, array<string, mixed>>
     */
    private static function dynamic(): array
    {
        $w = static fn (float $net, ?float $base, string $unit, string $info, array $params = []): array => array_filter([
            'cenaCennikowa' => $net, 'cenaBazowa' => $base, 'jednostka' => $unit,
            'dostepnoscInfo' => $info, 'dostepnoscInfoParams' => $params, 'status' => 1,
        ], static fn (mixed $value): bool => $value !== null);

        return [
            'rtest' => ['wersje' => [
                '1001' => $w(12.7, 18.71, 'opak.', 'srv_produkt_dostepny'),
                '1002' => $w(12.7, 18.71, 'opak.', 'srvmsg_stan_czas_real_zam', ['14-21']),
                '1003' => $w(12.7, 18.71, 'opak.', 'srv_produkt_dostepny'),
                // forma promocyjna: cena promocji nie jest ceną zakupu
                '1004' => $w(9.99, 18.71, 'opak.', 'srv_produkt_dostepny'),
            ]],
            'ox-test' => ['wersje' => [
                '2001' => $w(1.06, 1.51, 'para', 'srv_produkt_dostepny'),
                '2002' => $w(1.06, 1.51, 'para', 'srv_produkt_dostepny'),
                '2003' => $w(1.02, null, 'para', 'srv_produkt_dostepny'),
            ]],
            'zz-test' => ['wersje' => ['3001' => $w(14.71, 20.99, 'szt.', 'srv_produkt_dostepny')]],
            'x-bez-marki' => ['wersje' => ['4001' => $w(5.0, null, 'szt.', 'srv_produkt_dostepny')]],
        ];
    }

    /**
     * Wersja w pliku treści wyrobu: 63 pozycje; 0 = numer, 32 = pliki, 44 = klasyfikacja, 55 = kategoria ŚOI.
     *
     * @param  list<array<mixed>>  $documents
     * @param  array<string, mixed>  $classification
     * @return list<mixed>
     */
    private static function infoVersion(int $ref, array $documents, array $classification, string $ce): array
    {
        $version = array_fill(0, 63, '');
        $version[0] = $ref;
        $version[32] = $documents;
        $version[44] = $classification;
        $version[55] = $ce;

        return $version;
    }

    /**
     * Plik treści wyrobu (info_pl): 68 pozycji; 1 = opis (linie CR), 2 = kategoria, 24 = branże, 25 = normy wyrobu,
     * 28 = grupa, 33 = grupy kolorów [[wersje, …]].
     *
     * @param  list<list<mixed>>  $versions
     * @param  list<array<string, mixed>>  $norms
     * @return list<mixed>
     */
    private static function info(string $id, string $description, string $category, array $versions, array $norms = [], array $branches = []): array
    {
        $info = array_fill(0, 68, '');
        $info[0] = $id;
        $info[1] = $description;
        $info[2] = $category;
        $info[24] = $branches;
        $info[25] = $norms;
        $info[28] = 'ochrona rąk';
        $info[33] = [[$versions, 'opis koloru']];

        return $info;
    }

    /**
     * @return array<string, list<mixed>> plik treści wg kodu wyrobu w base64url
     */
    private static function infos(): array
    {
        $doc = static fn (string $n, string $title, string $type): array => [$n.'.pdf'.$type, substr($n, -2).'/'.$n.'.pdf', '', $title, $type, 1, null];
        $norms = [
            'NORMA1' => ['NORMA1', 'EN ISO 21420', ''],
            'NORMA2' => ['NORMA2', 'EN388', ''],
            'NORMA2_PARAMETRY' => ['NORMA2_PARAMETRY', '4121X', ''],
            'NORMA3' => ['NORMA3', 'OEKO-TEX Standard 100*', '*dotyczy materiału'],
            'KLASYFIKACJA1' => ['KLASYFIKACJA1', 'KONTAKT Z ŻYWNOŚCIĄ', ''],
        ];

        return [
            'cnRlc3Q' => self::info('rtest', "Rękawice testowe RTEST.\r- wykonane z nitrylu\r- pakowane po 100 szt.", 'RĘKAWICE NITRYLOWE', [
                self::infoVersion(1001, [
                    $doc('0000000011', 'Deklaracja zgodności - UE - PL', '22'),
                    $doc('0000000012', 'Deklaracja zgodności - EN', '27'),
                    $doc('0000000013', 'Deklaracja RO', '67'),
                ], $norms, 'ce_kat1'),
                // każdy rozmiar ma własny egzemplarz deklaracji pod tą samą nazwą
                self::infoVersion(1002, [
                    $doc('0000000021', 'Deklaracja zgodności - UE - PL', '22'),
                    $doc('0000000014', 'Broszura informacyjna/ostrzeżenia - PL', '33'),
                ], $norms, 'ce_kat1'),
                self::infoVersion(1003, [], $norms, 'ce_kat1'),
            ], [], ['przemysł spożywczy']),
            'b3gtdGVzdA' => self::info('ox-test', 'Rękawice ochronne OX-TEST.', 'RĘKAWICE OCHRONNE', [
                self::infoVersion(2001, [$doc('0000000031', 'Deklaracja zgodności - EN', '27')], [], 'ce_kat2'),
                self::infoVersion(2002, [], [], 'ce_kat2'),
                self::infoVersion(2003, [], [], 'ce_kat2'),
            ], [['id' => 'lokalne1', 'normaBazowaIdRef' => 'a27PDw', 'symbolBazowy' => 'eniso21420', 'parametry' => '', 'czyWartosciPrzykladowe' => false, 'czegoDotyczyNorma' => '']]),
            'enotdGVzdA' => self::info('zz-test', 'Tablica testowa.', 'ZNAK BEZPIECZEŃSTWA', [self::infoVersion(3001, [], [], 'ce_x')]),
            'eC1iZXotbWFya2k' => self::info('x-bez-marki', 'Wyrób bez marki.', 'INNE', [self::infoVersion(4001, [], [], '')]),
        ];
    }

    private function fakeSite(): void
    {
        $dictionaries = [
            'def_marki_pl' => ['' => ['', 'Brak'], 'reis' => ['reis', 'REIS'], 'ogrifox' => ['ogrifox', 'OGRIFOX'], 'anro' => ['anro', 'ANRO'], 'honeywell' => ['honeywell', 'HONEYWELL']],
            'def_linie_pl' => ['tactical guard' => ['tactical guard', 'TACTICAL GUARD']],
            'def_kolory_pl' => ['n' => ['n', 'niebieski'], 'bs' => ['bs', 'czarno-szary'], 'ws' => ['ws', 'biało-szary']],
            'def_kategorie_ce_pl' => [
                'ce_x' => ['ce_x', '-', 'Produkt nie jest środkiem ochrony indywidualnej (ŚOI)'],
                'ce_kat1' => ['ce_kat1', 'Kategoria I', 'Kategoria I obejmuje wyłącznie zagrożenia minimalne'],
                'ce_kat2' => ['ce_kat2', 'Kategoria II', 'Kategoria II obejmuje zagrożenia inne niż minimalne'],
            ],
            'def_normybazowe_pl' => ['a27PDw' => ['a27PDw', 'ENISO21420', '', 188]],
            'def_produkty_core_list_pl' => self::coreList(),
        ];
        $infos = self::infos();
        $dynamic = self::dynamic();

        Http::fake(function (Request $request) use ($dictionaries, $infos, $dynamic) {
            $url = $request->url();

            if ($url === 'https://web.rawpol.com/rtm_dat/pool/publishInfo.js') {
                return Http::response(['publishVersion' => '202609211710', 'typesVersion' => 10, 'publishPoolId' => 'dat09x']);
            }
            if ($url === 'https://web.rawpol.com/rtm_server/serverdict.js') {
                return Http::response(json_encode([
                    'srv_produkt_dostepny' => ['pl' => 'Produkt dostępny', 'en' => 'The product is available'],
                    'srvmsg_stan_czas_real_zam' => ['pl' => 'Produkt chwilowo niedostępny, czas realizacji <$0$> dni', 'en' => '…'],
                ], JSON_UNESCAPED_UNICODE), 200, ['Content-Type' => 'application/javascript']);
            }
            if (str_starts_with($url, self::POOL.'resources/asset/product/info_pl/')) {
                $id = basename($url, '.js');

                return isset($infos[$id]) ? Http::response($infos[$id]) : Http::response('', 404);
            }
            if (str_starts_with($url, self::POOL)) {
                $name = basename($url, '.js');

                return isset($dictionaries[$name]) ? Http::response($dictionaries[$name]) : Http::response('', 404);
            }
            if (str_starts_with($url, RawpolB2bClient::MEDIA)) {
                if (($request->header('Referer')[0] ?? '') !== 'https://web.rawpol.com/') {
                    return Http::response('<html>403</html>', 403, ['Content-Type' => 'text/html']);
                }

                return str_ends_with($url, '.pdf')
                    ? Http::response('%PDF-1.4 syntetyczny '.basename($url), 200, ['Content-Type' => 'application/pdf'])
                    : Http::response(self::JPEG, 200, ['Content-Type' => 'image/jpeg']);
            }
            if (str_starts_with($url, 'https://api.rawpol.com/service.aspx?')) {
                parse_str((string) parse_url($url, PHP_URL_QUERY), $query);
                $body = self::body($request);
                $session = $request->header('Cookie')[0] ?? '';
                $logged = $session !== '' && in_array($session, $this->validSessions, true);

                if (($query['req'] ?? null) === 'LogujKlienta') {
                    $good = ($body['login'] ?? null) === 'supon-test'
                        && ($body['hasloSHA1'] ?? null) === strtoupper(sha1(mb_convert_encoding('dobre-hasło', 'UTF-16LE', 'UTF-8')));
                    if (! $good) {
                        return Http::response(['status' => 0, 'logged' => false, 'body' => ['logonStatus' => -1]]);
                    }
                    $this->logins++;
                    $sid = 'sesja-'.$this->logins;
                    $this->validSessions[] = 'ASP.NET_SessionId_API='.$sid;

                    return Http::response(['status' => 0, 'logged' => true, 'body' => ['logonStatus' => 1]], 200, [
                        'Set-Cookie' => 'ASP.NET_SessionId_API='.$sid.'; path=/; secure; HttpOnly',
                    ]);
                }
                if (($query['req'] ?? null) === 'GetProduktyDynamicData') {
                    if ($this->dropSessionsOnPrices) {
                        $this->dropSessionsOnPrices = false;
                        $this->validSessions = [];
                        $logged = false;
                    }
                    if (! $logged || $this->pricesAlwaysAnonymous) {
                        return Http::response(['status' => 0, 'logged' => false, 'body' => ['produkty' => []]]);
                    }
                    $this->priceRequests[] = $body['symbole'];

                    return Http::response(['status' => 0, 'logged' => true, 'body' => [
                        'produkty' => array_intersect_key($dynamic, array_flip($body['symbole'])),
                    ]]);
                }
            }

            return Http::response('nie ma', 404);
        });
    }
}
