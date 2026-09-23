<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\B2bAccount;
use App\Models\B2bProductLink;
use App\Models\B2bSyncRun;
use App\Models\Product;
use App\Models\ProductDocument;
use App\Models\ProductIdentifier;
use App\Models\ProductImage;
use App\Models\ProductShopCard;
use App\Models\ProductSourcePrice;
use App\Services\B2b\B2bAccountSyncRunner;
use App\Services\B2b\B2bConnectorRegistry;
use App\Services\B2b\B2bDocumentSource;
use App\Services\B2b\B2bFatalException;
use App\Services\B2b\B2bImageGallery;
use App\Services\B2b\B2bListProgressAware;
use App\Services\B2b\B2bManufacturerSite;
use App\Services\B2b\B2bRemoteIdentifier;
use App\Services\B2b\B2bRemoteProduct;
use App\Services\B2b\B2bRunSummaryAware;
use App\Services\B2b\B2bShopFieldSource;
use App\Services\B2b\ProceraB2bClient;
use App\Services\B2b\ProceraB2bConnector;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use RuntimeException;
use Tests\TestCase;

/**
 * Łącznik b2b.procera.pl na atrapie panelu (Http::fake, bez prawdziwego logowania). Układ stron odwzorowuje panel
 * z 21.09.2026 (silnik „B2B 3.0.1.6”): formularz logowania na /login (action=""), link /logout tylko w widoku konta,
 * lista /product?query=%25&sort=code%2Basc&page=N z licznikiem „Wyświetlanie produktów od X do Y z Z” i wierszem
 * modelu (klasy …_{id}, kod JEDNEGO z rozmiarów, formularz koszyka w komórce), strona wyrobu z tabelą
 * „table-product-single” (Kod, Kod EAN, Dostępność), tabelą rozmiarów #variants-table (nagłówek Kod | cechy |
 * Dostępność | …, cena w data-price-net pola ilości), opisem w szablonie „product-description-modern” albo zwykłym
 * akapicie z liniami rozdzielonymi znakiem nowej linii, galerią #B2B_fotorama_details i tabelą #p-files-table
 * (adresy względne wobec <base href>), oraz cennik /pricelist/xml (gość dostaje stronę logowania).
 *
 * Wszystkie dane (wyroby, kody, EAN, ceny, pliki, zdjęcia) są SYNTETYCZNE.
 */
final class ProceraConnectorTest extends TestCase
{
    use RefreshDatabase;

    private const NIP = '1234567890';

    private const PERSON = 'Kowalska Anna';

    private const PASSWORD = 'dobre-haslo';

    private const TOKEN = 'TestToken123';

    private const PREVIEW = 'https://b2b.procera.pl/get-preview/product_images/';

    /** @var array<string, array<string, mixed>> modele wg id listy */
    private array $models = [];

    /** @var list<string> id modeli w kolejności listy */
    private array $order = [];

    private int $pageSize = 3;

    /** @var array<string, array{client: string, catalog: string, ean: string, currency?: string, more_eans?: list<string>}> cennik XML wg kodu */
    private array $xml = [];

    private bool $xmlBroken = false;

    /** @var list<string> */
    private array $validSessions = [];

    private int $logins = 0;

    /** Sesja wygasa przy następnym pobraniu strony wyrobu. */
    private bool $dropSessionOnProduct = false;

    /** Strony wyrobów zawsze w widoku gościa. */
    private bool $productsAlwaysAnonymous = false;

    /** Licznik listy zmienia się raz na stronie 2 (produkt dodany w trakcie). */
    private bool $counterChangesOnce = false;

    /** @var list<string> adresy stron wyrobów w kolejności pobrań */
    private array $productRequests = [];

    protected function setUp(): void
    {
        parent::setUp();
        Queue::fake();
    }

    public function test_login_posts_contractor_person_and_password_with_the_form_token(): void
    {
        $this->fakeSite();
        $client = $this->client();

        $client->login();

        $this->assertTrue($client->isLoggedIn());
        $post = Http::recorded(fn (Request $r): bool => $r->method() === 'POST')->first()[0];
        $this->assertSame('https://b2b.procera.pl/login', $post->url());
        $this->assertSame(
            ['_token' => self::TOKEN, 'contractor_code' => self::NIP, 'name' => self::PERSON, 'password' => self::PASSWORD],
            $post->data(),
        );
    }

    public function test_wrong_password_fails_with_a_plain_runtime_exception(): void
    {
        $this->fakeSite();
        $client = new ProceraB2bClient(self::NIP, self::PERSON, 'zle-haslo', 0, static function (int $ms): void {});

        try {
            $client->login();
            $this->fail('logowanie złym hasłem powinno się nie udać');
        } catch (B2bFatalException $e) {
            $this->fail('złe hasło to nie błąd krytyczny: '.$e->getMessage());
        } catch (RuntimeException $e) {
            $this->assertStringContainsString('sklep nie potwierdził zalogowania', $e->getMessage());
        }
        $this->assertFalse($client->isLoggedIn());
    }

    public function test_account_without_contractor_code_says_which_field_to_fill(): void
    {
        $this->fakeSite();
        $client = new ProceraB2bClient('', self::PERSON, self::PASSWORD, 0, static function (int $ms): void {});

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Kod kontrahenta');

        $client->login();
    }

    public function test_model_code_is_the_variant_code_without_its_size_and_nothing_when_they_disagree(): void
    {
        $this->assertSame('X-TESTGRIP', ProceraB2bConnector::modelCode([
            ['code' => 'X-TESTGRIP 9', 'size' => '9'],
            ['code' => 'X-TESTGRIP 10', 'size' => '10'],
        ]));
        $this->assertSame('EVO T1 PROMOCJA KARTON!', ProceraB2bConnector::modelCode([
            ['code' => 'EVO T1 36 PROMOCJA KARTON!', 'size' => '36'],
            ['code' => 'EVO T1 37 PROMOCJA KARTON!', 'size' => '37'],
        ]));
        // rozmiar „9” nie jest osobnym słowem w „X-TESTGRIP 19” — rdzenia nie zgadujemy
        $this->assertNull(ProceraB2bConnector::modelCode([['code' => 'X-TESTGRIP 19', 'size' => '9']]));
        $this->assertNull(ProceraB2bConnector::modelCode([
            ['code' => 'TESTA 9', 'size' => '9'],
            ['code' => 'TESTB 10', 'size' => '10'],
        ]));
        $this->assertNull(ProceraB2bConnector::modelCode([['code' => 'TESTA', 'size' => '']]));
    }

    public function test_price_cents_reads_polish_amounts_only(): void
    {
        $this->assertSame(222, ProceraB2bConnector::priceCents('2,22 PLN'));
        $this->assertSame(113305, ProceraB2bConnector::priceCents('1 133,05 PLN'));
        $this->assertSame(113305, ProceraB2bConnector::priceCents('1133,05 PLN'));
        $this->assertNull(ProceraB2bConnector::priceCents('2,22 EUR'));
        $this->assertNull(ProceraB2bConnector::priceCents('2.22'));
    }

    public function test_model_in_one_price_is_one_card_with_the_model_code_members_and_catalog_price_from_xml(): void
    {
        $this->addModel(self::glove());
        $this->fakeSite();
        $connector = $this->connector();

        $products = iterator_to_array($connector->products(), false);

        $this->assertCount(1, $products);
        $card = $products[0];
        $this->assertSame('X-TESTGRIP', $card->sku);
        $this->assertSame('X-TESTGRIP 9', $card->remoteId);
        $this->assertSame('RĘKAWICE OCHRONNE X-TESTGRIP', $card->name);
        $this->assertSame(
            [
                ['remote_id' => 'X-TESTGRIP 9', 'sku' => 'X-TESTGRIP 9', 'name' => 'RĘKAWICE OCHRONNE X-TESTGRIP 9'],
                ['remote_id' => 'X-TESTGRIP 10', 'sku' => 'X-TESTGRIP 10', 'name' => 'RĘKAWICE OCHRONNE X-TESTGRIP 10'],
            ],
            $card->members,
        );
        $this->assertSame('Rozmiary: 9 (X-TESTGRIP 9); 10 (X-TESTGRIP 10)', $card->variantSummary);
        $this->assertSame('Dostępny: 9; Na wyczerpaniu: 10', $card->availability);
        $this->assertSame('https://b2b.procera.pl/product-details/REKAWICE-OCHRONNE-X-TESTGRIP/uuid-7001?first_id=7002&group_id=0', $card->sourceUrl);
        $price = $connector->price($card);
        $this->assertSame(2.22, $price?->net);
        $this->assertSame(2.78, $price->base);
        $this->assertSame(20.14, $price->discountPercent);
        $this->assertSame('Procera', $connector->manufacturer($card));
    }

    public function test_sizes_in_two_prices_are_two_cards_named_with_their_sizes_and_first_size_codes(): void
    {
        $this->addModel(self::shoes());
        $this->fakeSite();
        $connector = $this->connector();

        $products = iterator_to_array($connector->products(), false);

        $this->assertCount(2, $products);
        $this->assertSame(2, $connector->totalProducts());
        [$cheap, $dear] = $products;
        $this->assertSame('TESTOS S1PL 39', $cheap->sku);
        $this->assertSame('TRZEWIKI BEZPIECZNE TESTOS S1P (rozm. 39)', $cheap->name);
        $this->assertSame(['TESTOS S1PL 39'], array_column($cheap->members, 'remote_id'));
        $this->assertSame(49.0, $connector->price($cheap)?->net);
        $this->assertSame('TESTOS S1PL 46', $dear->sku);
        $this->assertSame('TESTOS S1PL 46', $dear->remoteId);
        $this->assertSame('TRZEWIKI BEZPIECZNE TESTOS S1P (rozm. 46, 47)', $dear->name);
        $this->assertSame(['TESTOS S1PL 46', 'TESTOS S1PL 47'], array_column($dear->members, 'remote_id'));
        $this->assertSame('Czasowo niedostępny: 46; Dostępny: 47', $dear->availability);
        // XML podaje jedną cenę modelu (123,90), żadnej z kart — ceny katalogowej nie bierzemy
        $this->assertNull($connector->price($cheap)->base);
        $this->assertNull($connector->price($dear)?->base);
        $this->assertStringContainsString('1 modeli w kilku cenach', implode("\n", $connector->runSummary()));
    }

    public function test_size_without_account_price_stays_off_the_card_and_is_named_in_the_summary(): void
    {
        $shoes = self::shoes();
        $shoes['variants'][2]['net'] = '0';
        $shoes['variants'][1]['net'] = '89.0000';
        $this->addModel($shoes);
        $this->fakeSite();
        $connector = $this->connector();

        $products = iterator_to_array($connector->products(), false);

        $this->assertCount(2, $products);
        $this->assertSame(['TESTOS S1PL 46'], array_column($products[1]->members, 'remote_id'));
        $this->assertSame(89.0, $connector->price($products[1])?->net);
        $this->assertStringContainsString('Rozmiary bez ceny konta (poza kartami): 1, np. TESTOS S1PL 47', implode("\n", $connector->runSummary()));
    }

    public function test_delivery_date_column_is_read_by_header_and_list_may_show_a_later_size(): void
    {
        $this->addModel(self::shorts());
        $this->fakeSite();
        $connector = $this->connector();

        $products = iterator_to_array($connector->products(), false);

        $this->assertCount(1, $products);
        $card = $products[0];
        $this->assertSame('TESTLANDER SK', $card->sku);
        $this->assertSame('TESTLANDER SK 46', $card->remoteId);
        $this->assertSame('Czasowo niedostępny (termin dostawy 20.04.2027)', $card->availability);
        $this->assertSame('Rozmiary: 46 (TESTLANDER SK 46); 48 (TESTLANDER SK 48)', $card->variantSummary);
        $this->assertSame(62.38, $connector->price($card)?->base);
    }

    public function test_product_without_sizes_is_a_single_card_with_literal_lines_and_the_brand_from_its_code(): void
    {
        $this->addModel(self::filter());
        $this->fakeSite();
        $connector = $this->connector();

        $products = iterator_to_array($connector->products(), false);

        $this->assertCount(1, $products);
        $card = $products[0];
        $this->assertSame('3M9125', $card->sku);
        $this->assertSame('3M9125', $card->remoteId);
        $this->assertSame([], $card->members);
        $this->assertSame('', $card->variantSummary);
        $this->assertSame('Czasowo niedostępny', $card->availability);
        $this->assertSame('3M', $connector->manufacturer($card));
        $this->assertSame(
            "Filtr (P2) chroni przed pyłami. Pasuje do półmasek testowych.\nNiska masa, niski opór oddychania.\n"
            ."Dane techniczne\nRodzaj filtra: Filtr cząstek stałych\nZalecana klasa ochrony: P2",
            $connector->description($card),
        );
        $price = $connector->price($card);
        $this->assertSame(11.43, $price?->net);
        $this->assertSame(22.85, $price->base);
        // w tabeli plików jest samo zdjęcie — dokumentów brak, zdjęcie z galerii
        $this->assertSame([], $connector->documents($card));
        $this->assertSame([self::PREVIEW.'2A/2A0402FE.jpg'], $connector->imageUrls($card));
        $this->assertSame(
            [
                ['Informacje handlowe', 'Kod modelu', '3M9125'],
                ['Informacje handlowe', 'Jednostka sprzedaży', 'szt'],
                ['Informacje handlowe', 'EAN', '0511315290450 (3M9125)'],
            ],
            self::fields($connector, $card),
        );
    }

    public function test_model_code_unknown_to_the_price_list_leaves_the_first_size_code_and_no_catalog_price(): void
    {
        // XML zna „TESTSOCKS” (cena za parę), a rozmiary to „TESTSOCKS 39-42 OPAK” — kod „TESTSOCKS OPAK” nie istnieje
        $this->addModel(self::socks());
        $this->fakeSite();
        $connector = $this->connector();

        $products = iterator_to_array($connector->products(), false);

        $this->assertSame('TESTSOCKS 39-42 OPAK', $products[0]->sku);
        $this->assertSame(8.7, $connector->price($products[0])?->net);
        $this->assertNull($connector->price($products[0])->base);
        $this->assertSame([['Informacje handlowe', 'Jednostka sprzedaży', 'opak'], ['Informacje handlowe', 'EAN', '5900000000301 (TESTSOCKS 39-42 OPAK)']], self::fields($connector, $products[0]));
        $this->assertStringContainsString('Bez ceny katalogowej z cennika XML (cena katalogowa = cena konta): 1, np. TESTSOCKS 39-42 OPAK', implode("\n", $connector->runSummary()));
    }

    public function test_catalog_price_needs_the_same_client_price_in_xml(): void
    {
        $model = self::glove();
        $this->addModel($model);
        $this->xml['X-TESTGRIP']['client'] = '2.10';
        $this->fakeSite();
        $connector = $this->connector();

        $products = iterator_to_array($connector->products(), false);

        $this->assertSame('X-TESTGRIP', $products[0]->sku);
        $this->assertNull($connector->price($products[0])?->base);
        $this->assertSame(0.0, $connector->price($products[0])->discountPercent);
    }

    public function test_identifiers_are_size_codes_the_confirmed_model_code_and_eans_only_where_the_size_is_known(): void
    {
        $this->addModel(self::glove());
        $this->addModel(self::shoes());
        $this->addModel(self::filter());
        $this->addModel(self::socks());
        // wyrób bez rozmiarów bez EAN na stronie — EAN z cennika XML (product_code = kod pozycji)
        $xmlOnly = self::single('8201', 'TESTXML', 'WYRÓB Z EAN W CENNIKU', '5,00 PLN');
        $xmlOnly['xml'] = ['TESTXML' => ['client' => '5.00', 'catalog' => '6.0000', 'ean' => '5900000000509']];
        $this->addModel($xmlOnly);
        // dwa EAN-y w jednym wierszu cennika — nie wiadomo, który jest EAN-em sztuki
        $twoEans = self::single('8202', 'TESTDWA', 'WYRÓB Z DWOMA EAN', '5,00 PLN');
        $twoEans['xml'] = ['TESTDWA' => ['client' => '5.00', 'catalog' => '6.0000', 'ean' => '5900000000516', 'more_eans' => ['15900000000513']]];
        $this->addModel($twoEans);
        $this->fakeSite();
        $connector = $this->connector();

        $products = [];
        foreach ($connector->products() as $product) {
            $products[$product->sku] = array_map(
                static fn (B2bRemoteIdentifier $i): array => [$i->type, $i->value, $i->remoteId, $i->label, $i->field],
                $product->identifiers ?? [],
            );
        }

        // kod modelu z cennika XML; „Kod EAN” strony należy do kodu pokazanego obok (pierwszy rozmiar); EAN wiersza
        // XML kodu modelu (bez rozmiaru) pominięty — nie wiadomo, który to rozmiar
        $this->assertSame([
            ['model_code', 'X-TESTGRIP', null, null, 'product_code'],
            ['source_code', 'X-TESTGRIP 9', 'X-TESTGRIP 9', '9', 'Kod'],
            ['ean', '5900000000101', 'X-TESTGRIP 9', '9', 'Kod EAN'],
            ['source_code', 'X-TESTGRIP 10', 'X-TESTGRIP 10', '10', 'Kod'],
        ], $products['X-TESTGRIP']);
        // model w dwóch cenach: kod modelu na obu kartach, EAN strony tylko na karcie swojego rozmiaru
        $this->assertSame([
            ['model_code', 'TESTOS S1PL', null, null, 'product_code'],
            ['source_code', 'TESTOS S1PL 39', 'TESTOS S1PL 39', '39', 'Kod'],
            ['ean', '5900000000201', 'TESTOS S1PL 39', '39', 'Kod EAN'],
        ], $products['TESTOS S1PL 39']);
        $this->assertSame([
            ['model_code', 'TESTOS S1PL', null, null, 'product_code'],
            ['source_code', 'TESTOS S1PL 46', 'TESTOS S1PL 46', '46', 'Kod'],
            ['source_code', 'TESTOS S1PL 47', 'TESTOS S1PL 47', '47', 'Kod'],
        ], $products['TESTOS S1PL 46']);
        // wyrób bez rozmiarów: kod pozycji bez osobnego kodu modelu; EAN strony i ten sam EAN z cennika
        $this->assertSame([
            ['source_code', '3M9125', '3M9125', null, 'Kod'],
            ['ean', '0511315290450', '3M9125', null, 'Kod EAN'],
            ['ean', '0511315290450', '3M9125', null, 'barcodes/ean'],
        ], $products['3M9125']);
        // kod modelu wyliczony z rozmiarów, którego cennik nie zna („TESTSOCKS OPAK”) — nie jest identyfikatorem
        $this->assertSame([
            ['source_code', 'TESTSOCKS 39-42 OPAK', 'TESTSOCKS 39-42 OPAK', '39-42', 'Kod'],
            ['ean', '5900000000301', 'TESTSOCKS 39-42 OPAK', '39-42', 'Kod EAN'],
            ['source_code', 'TESTSOCKS 43-46 OPAK', 'TESTSOCKS 43-46 OPAK', '43-46', 'Kod'],
        ], $products['TESTSOCKS 39-42 OPAK']);
        $this->assertSame([
            ['source_code', 'TESTXML', 'TESTXML', null, 'Kod'],
            ['ean', '5900000000509', 'TESTXML', null, 'barcodes/ean'],
        ], $products['TESTXML']);
        $this->assertSame([['source_code', 'TESTDWA', 'TESTDWA', null, 'Kod']], $products['TESTDWA']);
    }

    public function test_modern_description_gives_prose_and_its_specs_and_icons_go_to_the_shop_card(): void
    {
        $this->addModel(self::glove());
        $this->fakeSite();
        $connector = $this->connector();

        $card = iterator_to_array($connector->products(), false)[0];

        $this->assertSame(
            "Wykonane z lekkiej dzianiny poliestrowej, powlekane lateksem w części chwytnej.\n"
            ."- Bardzo dobra przyczepność\n- Lekka i elastyczna konstrukcja",
            $connector->description($card),
        );
        $this->assertSame(
            [
                ['Informacje handlowe', 'Kod modelu', 'X-TESTGRIP'],
                ['Informacje handlowe', 'Jednostka sprzedaży', 'para'],
                ['Informacje handlowe', 'EAN', '5900000000101 (X-TESTGRIP 9)'],
                ['Dane techniczne', 'Kategoria', 'I'],
                ['Dane techniczne', 'Norma', 'EN21420:2020'],
                ['Dane techniczne', 'Rozmiary', '9, 10'],
                ['Oznaczenia', 'Piktogramy', 'Norma EN ISO 21420; Powleczenie lateksem'],
            ],
            self::fields($connector, $card),
        );
        $this->assertSame(
            [
                ['X-TESTGRIP_KARTA-PRODUKTOWA.pdf', 'https://b2b.procera.pl/assets/resources/products/7002/X-TESTGRIP_KARTA-PRODUKTOWA.pdf', ProductDocument::KIND_DATASHEET],
                ['MANUAL X-TESTGRIP ŁĄCZNIE.pdf', 'https://b2b.procera.pl/assets/resources/products/7002/MANUAL%20X-TESTGRIP%20%C5%81%C4%84CZNIE.pdf', ProductDocument::KIND_MANUAL],
            ],
            array_map(static fn ($d): array => [$d->title, $d->sourceUrl, $d->kind], $connector->documents($card)),
        );
        $this->assertSame([self::PREVIEW.'AA/AA11.jpg', self::PREVIEW.'BB/BB22.jpg'], $connector->imageUrls($card));
    }

    public function test_brand_from_the_name_and_supplier_for_unbranded_products_counted_in_the_summary(): void
    {
        $this->addModel(self::single('8001', 'COBTEST', 'OKULARY OCHRONNE BOLLE COBRA TEST', '28,00 PLN'));
        $this->addModel(self::single('8002', 'GP6TEST', 'GAŚNICA PROSZKOWA 6 KG TEST', '99,00 PLN'));
        $this->addModel(self::single('8003', 'BLS 999 TEST', 'BLS 999 filtr do masek BLS', '21,07 PLN'));
        $this->fakeSite();
        $connector = $this->connector();

        $products = iterator_to_array($connector->products(), false);
        $brands = array_map(static fn (B2bRemoteProduct $p): string => $connector->manufacturer($p), $products);

        $this->assertSame(['Bolle', 'Procera', 'BLS'], $brands);
        $this->assertStringContainsString('Bez marki w nazwie — producent przyjęty jako Procera: 1, np. GP6TEST', implode("\n", $connector->runSummary()));
    }

    public function test_list_row_disagreeing_with_its_page_or_an_unreadable_page_is_skipped_with_a_reason(): void
    {
        $wrongPrice = self::glove();
        $wrongPrice['list_price'] = '2,50 PLN';
        $this->addModel($wrongPrice);
        $wrongCode = self::single('8101', 'TESTCODE', 'WYRÓB TESTOWY', '5,00 PLN');
        $wrongCode['list_code'] = 'INNY KOD';
        $this->addModel($wrongCode);
        $missing = self::single('8102', 'ZNIKNIETY', 'WYRÓB ZNIKNIĘTY', '5,00 PLN');
        $missing['status'] = 404;
        $this->addModel($missing);
        $this->addModel(self::filter());
        $this->fakeSite();
        $connector = $this->connector();

        $products = iterator_to_array($connector->products(), false);

        $this->assertCount(4, $products);
        $reasons = [];
        foreach (array_slice($products, 0, 3) as $product) {
            try {
                $connector->price($product);
                $this->fail('pozycja '.$product->sku.' powinna być pominięta');
            } catch (RuntimeException $e) {
                $reasons[$product->sku] = $e->getMessage();
            }
        }
        $this->assertStringContainsString('cena z listy („2,50 PLN”) inna niż cena rozmiaru X-TESTGRIP 9', $reasons['X-TESTGRIP 9']);
        $this->assertStringContainsString('strona wyrobu nie pokazuje kodu z listy (INNY KOD)', $reasons['INNY KOD']);
        $this->assertStringContainsString('strona wyrobu', $reasons['ZNIKNIETY']);
        $this->assertSame(11.43, $connector->price($products[3])?->net);
        $this->assertStringContainsString('3 modeli pominiętych', implode("\n", $connector->runSummary()));
    }

    public function test_list_across_pages_changed_once_is_read_again_from_the_start(): void
    {
        foreach (self::manySingles(5) as $model) {
            $this->addModel($model);
        }
        $this->pageSize = 2;
        $this->counterChangesOnce = true;
        $this->fakeSite();
        $connector = $this->connector();
        $messages = [];
        $connector->onListProgress(static function (string $m) use (&$messages): void {
            $messages[] = $m;
        });

        $products = iterator_to_array($connector->products(), false);

        $this->assertSame(['T001', 'T002', 'T003', 'T004', 'T005'], array_map(static fn (B2bRemoteProduct $p): string => $p->sku, $products));
        $this->assertStringContainsString('pobieram od nowa', implode("\n", $messages));
        $this->assertStringContainsString('Lista produktów Procera: 5 modeli na 3 stronach', implode("\n", $messages));
    }

    public function test_list_row_without_account_price_stops_the_run(): void
    {
        $model = self::glove();
        $model['list_price'] = '';
        $this->addModel($model);
        $this->fakeSite();
        $connector = $this->connector();

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('bez ceny konta');

        iterator_to_array($connector->products(), false);
    }

    public function test_session_lost_on_a_product_page_logs_in_again_once(): void
    {
        $this->addModel(self::glove());
        $this->fakeSite();
        $connector = $this->connector();
        $this->dropSessionOnProduct = true;

        $products = iterator_to_array($connector->products(), false);

        $this->assertSame(2.22, $connector->price($products[0])?->net);
        $this->assertSame(2, $this->logins);
        $this->assertCount(2, $this->productRequests);
    }

    public function test_product_pages_never_signed_in_are_fatal(): void
    {
        $this->addModel(self::glove());
        $this->fakeSite();
        $connector = $this->connector();
        $this->productsAlwaysAnonymous = true;

        $this->expectException(B2bFatalException::class);
        $this->expectExceptionMessage('Utracono sesję konta b2b.procera.pl — ceny konta niedostępne');

        iterator_to_array($connector->products(), false);
    }

    public function test_price_list_for_a_guest_logs_in_again_and_a_broken_one_keeps_the_run_without_catalog_prices(): void
    {
        $this->addModel(self::glove());
        $this->fakeSite();
        $client = $this->client();
        $client->login();
        $this->validSessions = [];

        $xml = $client->priceListXml();

        $this->assertStringContainsString('<product_code>X-TESTGRIP</product_code>', $xml);
        $this->assertSame(2, $this->logins);

        $this->xmlBroken = true;
        $connector = $this->connector();
        $products = iterator_to_array($connector->products(), false);

        // bez cennika kod modelu nie jest potwierdzony — karta dostaje kod pierwszego rozmiaru
        $this->assertSame('X-TESTGRIP 9', $products[0]->sku);
        $this->assertNull($connector->price($products[0])?->base);
        $this->assertStringContainsString('Cennik XML nie został pobrany', implode("\n", $connector->runSummary()));
    }

    public function test_sync_creates_procera_cards_with_account_prices_links_shop_card_documents_and_images(): void
    {
        Storage::fake('public');
        Storage::fake('local');
        $this->fullSite();
        $this->fakeSite();

        $result = app(B2bAccountSyncRunner::class)->run($this->account(), delayMs: 0, withImages: true);

        // X-TESTGRIP, TESTOS w dwóch cenach = 2 karty, TESTLANDER, 3M9125; ZNIKNIETY pominięty
        $this->assertSame(5, $result['created'], implode(' | ', $result['errors']));
        $this->assertSame(1, $result['skipped'], implode(' | ', $result['errors']));

        $card = Product::query()->where('sku', 'X-TESTGRIP')->sole();
        $this->assertSame('Procera', $card->manufacturer);
        $this->assertSame('RĘKAWICE OCHRONNE X-TESTGRIP', $card->name);
        $this->assertSame(
            ['X-TESTGRIP 10', 'X-TESTGRIP 9'],
            B2bProductLink::query()->where('product_id', $card->id)->orderBy('remote_id')->pluck('remote_id')->all(),
        );
        $slot = ProductSourcePrice::query()->where('product_id', $card->id)->sole();
        $this->assertSame('2.22', (string) $slot->purchase_price);
        $this->assertSame('2.78', (string) $slot->catalog_price_net);
        $this->assertSame('Dostępny: 9; Na wyczerpaniu: 10', $slot->availability);
        $this->assertStringContainsString('Bardzo dobra przyczepność', (string) $card->description);

        $shopCard = ProductShopCard::query()->where('product_id', $card->id)->sole();
        $this->assertContains(['name' => 'Norma', 'value' => 'EN21420:2020'], self::sectionRows($shopCard, 'Dane techniczne'));
        $this->assertSame(
            [['X-TESTGRIP_KARTA-PRODUKTOWA.pdf', ProductDocument::KIND_DATASHEET], ['MANUAL X-TESTGRIP ŁĄCZNIE.pdf', ProductDocument::KIND_MANUAL]],
            ProductDocument::query()->where('product_id', $card->id)->orderBy('sort_order')->get()
                ->map(static fn (ProductDocument $d): array => [(string) $d->title, (string) $d->kind])->all(),
        );
        $this->assertSame(
            [self::PREVIEW.'AA/AA11.jpg', self::PREVIEW.'BB/BB22.jpg'],
            ProductImage::query()->where('product_id', $card->id)->orderBy('sort_order')->pluck('source_url')->all(),
        );

        $dear = Product::query()->where('sku', 'TESTOS S1PL 46')->sole();
        $this->assertSame('89.00', (string) ProductSourcePrice::query()->where('product_id', $dear->id)->value('purchase_price'));
        $this->assertSame('89.00', (string) ProductSourcePrice::query()->where('product_id', $dear->id)->value('catalog_price_net'));
        $this->assertSame('3M', Product::query()->where('sku', '3M9125')->value('manufacturer'));

        $log = implode("\n", array_column((array) B2bSyncRun::query()->latest('id')->firstOrFail()->log, 'text'));
        $this->assertStringContainsString('ZNIKNIETY', $log);
    }

    public function test_second_sync_on_the_same_site_changes_nothing(): void
    {
        Storage::fake('public');
        Storage::fake('local');
        $this->fullSite();
        $this->fakeSite();
        app(B2bAccountSyncRunner::class)->run($this->account(), delayMs: 0, withImages: true);
        $before = $this->snapshot();
        $identifiers = $this->identifierRows();

        $second = app(B2bAccountSyncRunner::class)->run($this->account(), delayMs: 0, withImages: true);

        $this->assertSame(0, $second['created'], implode(' | ', $second['errors']));
        $this->assertSame(0, $second['updated'], implode(' | ', $second['errors']));
        $this->assertSame(5, $second['unchanged'], implode(' | ', $second['errors']));
        $this->assertSame($before, $this->snapshot());

        // identyfikatory zapisane w pierwszym przebiegu (pod pozycjami swoich kart), drugi ich nie dubluje ani nie
        // oznacza jako zniknięte
        $grip = (int) Product::query()->where('sku', 'X-TESTGRIP')->value('id');
        $dear = (int) Product::query()->where('sku', 'TESTOS S1PL 46')->value('id');
        $filter = (int) Product::query()->where('sku', '3M9125')->value('id');
        $this->assertSame([
            [$filter, '3M9125', 'ean', '0511315290450', 'Kod EAN'],
            [$filter, '3M9125', 'source_code', '3M9125', 'Kod'],
            [$dear, 'TESTOS S1PL 46', 'model_code', 'TESTOS S1PL', 'product_code'],
            [$dear, 'TESTOS S1PL 46', 'source_code', 'TESTOS S1PL 46', 'Kod'],
            [$dear, 'TESTOS S1PL 47', 'source_code', 'TESTOS S1PL 47', 'Kod'],
            [$grip, 'X-TESTGRIP 10', 'source_code', 'X-TESTGRIP 10', 'Kod'],
            [$grip, 'X-TESTGRIP 9', 'ean', '5900000000101', 'Kod EAN'],
            [$grip, 'X-TESTGRIP 9', 'model_code', 'X-TESTGRIP', 'product_code'],
            [$grip, 'X-TESTGRIP 9', 'source_code', 'X-TESTGRIP 9', 'Kod'],
        ], array_values(array_filter(
            array_map(static fn (array $r): array => [$r['product_id'], $r['position_key'], $r['type'], $r['value'], $r['source_field']], $identifiers),
            static fn (array $r): bool => in_array($r[0], [$grip, $dear, $filter], true),
        )));
        $this->assertSame($identifiers, $this->identifierRows());
        $this->assertSame(0, ProductIdentifier::query()->whereNotNull('removed_at')->count());
        foreach (B2bSyncRun::query()->get() as $run) {
            $this->assertSame([], preg_grep('/: identyfikator /', array_column((array) $run->log, 'text')));
        }
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function identifierRows(): array
    {
        return ProductIdentifier::query()->orderBy('position_key')->orderBy('type')->orderBy('value')
            ->get(['id', 'product_id', 'position_key', 'type', 'value', 'source_field', 'variant_label', 'removed_at'])
            ->map(static fn (ProductIdentifier $i): array => [...$i->toArray(), 'product_id' => (int) $i->product_id])
            ->all();
    }

    public function test_second_sync_after_the_split_disappears_keeps_both_cards_without_a_sku_conflict(): void
    {
        Storage::fake('public');
        Storage::fake('local');
        $this->fullSite();
        $this->fakeSite();
        app(B2bAccountSyncRunner::class)->run($this->account(), delayMs: 0, withImages: false);
        $cheap = Product::query()->where('sku', 'TESTOS S1PL 39')->sole();
        $cards = Product::query()->count();

        // rozmiar 39 wraca do ceny pozostałych — model jest jedną kartą
        $shoes = self::shoes();
        $shoes['variants'][0]['net'] = '89';
        $shoes['list_price'] = '89,00 PLN';
        $this->models[$shoes['id']] = $shoes;

        $second = app(B2bAccountSyncRunner::class)->run($this->account(), delayMs: 0, withImages: false);

        $this->assertSame(0, $second['created'], implode(' | ', $second['errors']));
        $this->assertSame($cards, Product::query()->count());
        // model idzie na kartę powiązaną z jego pierwszym kodem (TESTOS S1PL 39), wszystkie rozmiary razem z nim
        $this->assertSame(
            [$cheap->id, $cheap->id, $cheap->id],
            B2bProductLink::query()->whereIn('remote_id', ['TESTOS S1PL 39', 'TESTOS S1PL 46', 'TESTOS S1PL 47'])
                ->orderBy('remote_id')->pluck('product_id')->map(static fn (mixed $id): int => (int) $id)->all(),
        );
        $this->assertSame('89.00', (string) ProductSourcePrice::query()->where('product_id', $cheap->id)->value('purchase_price'));
        // karta dawnych rozmiarów 46–47 zostaje w katalogu — niczego nie kasujemy, przebieg tylko ostrzega
        $this->assertTrue(Product::query()->where('sku', 'TESTOS S1PL 46')->exists());
    }

    public function test_registry_detects_procera_by_host_and_it_is_not_the_manufacturer_site(): void
    {
        $registry = app(B2bConnectorRegistry::class);

        $this->assertSame('procera', $registry->keyForSites(['https://b2b.procera.pl/start']));
        $this->assertSame('Procera', $registry->label('procera'));
        $this->assertTrue($registry->requiresPassword('procera'));

        $account = B2bAccount::query()->create([
            'contractor_code' => self::NIP, 'username' => self::PERSON, 'password' => 'sekret', 'sites' => ['https://b2b.procera.pl/'],
        ]);
        $connector = $registry->make($account, 0);

        $this->assertInstanceOf(ProceraB2bConnector::class, $connector);
        $this->assertNotInstanceOf(B2bManufacturerSite::class, $connector);
        foreach ([B2bDocumentSource::class, B2bImageGallery::class, B2bShopFieldSource::class, B2bRunSummaryAware::class, B2bListProgressAware::class] as $interface) {
            $this->assertInstanceOf($interface, $connector);
        }
    }

    private function client(): ProceraB2bClient
    {
        return new ProceraB2bClient(self::NIP, self::PERSON, self::PASSWORD, 0, static function (int $ms): void {});
    }

    private function connector(): ProceraB2bConnector
    {
        $connector = new ProceraB2bConnector($this->client());
        $connector->login();

        return $connector;
    }

    private function account(): B2bAccount
    {
        return B2bAccount::query()->firstOrCreate(
            ['username' => self::PERSON],
            ['contractor_code' => self::NIP, 'password' => self::PASSWORD, 'sites' => ['https://b2b.procera.pl/'], 'connector' => 'procera', 'sync_images' => true],
        )->fresh();
    }

    /** Panel do przebiegu synchronizacji: dwie strony listy, model w dwóch cenach, wyrób bez rozmiarów i strona 404. */
    private function fullSite(): void
    {
        $this->addModel(self::filter());
        $this->addModel(self::shoes());
        $this->addModel(self::shorts());
        $missing = self::single('8102', 'ZNIKNIETY', 'WYRÓB ZNIKNIĘTY', '5,00 PLN');
        $missing['status'] = 404;
        $this->addModel($missing);
        $this->addModel(self::glove());
    }

    /**
     * @param  array<string, mixed>  $model
     */
    private function addModel(array $model): void
    {
        $this->models[$model['id']] = $model;
        $this->order[] = $model['id'];
        foreach ($model['xml'] ?? [] as $code => $row) {
            $this->xml[$code] = $row;
        }
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
    private static function fields(ProceraB2bConnector $connector, B2bRemoteProduct $product): array
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

    // ---- modele atrapy ----

    /**
     * @return array<string, mixed>
     */
    private static function glove(): array
    {
        return [
            'id' => '7001',
            'slug' => 'REKAWICE-OCHRONNE-X-TESTGRIP/uuid-7001?first_id=7002&group_id=0',
            'name' => 'RĘKAWICE OCHRONNE X-TESTGRIP',
            'list_code' => 'X-TESTGRIP 9',
            'list_price' => '2,22 PLN',
            'unit' => 'para',
            'ean' => '5900000000101',
            'headers' => ['ROZMIAR'],
            'variants' => [
                ['id' => '7002', 'code' => 'X-TESTGRIP 9', 'cols' => ['9'], 'availability' => 'Dostępny', 'net' => '2.22'],
                ['id' => '7003', 'code' => 'X-TESTGRIP 10', 'cols' => ['10'], 'availability' => 'Na wyczerpaniu', 'net' => '2.22'],
            ],
            'description' => '<p></p><div class="product-description-modern">'
                .'<div class="product-hero-image"><img src="https://procera.cfolks.pl/slider/1/image" alt="X-INNY"></div>'
                .'<div class="product-intro"><h2>Rękawice X-TESTGRIP</h2>'
                .'<p class="lead">Wykonane z lekkiej dzianiny poliestrowej, powlekane lateksem w części chwytnej.</p></div>'
                .'<div class="product-icons">'
                .'<div class="icon-box"><img src="https://procera.cfolks.pl/media/piktograms/rekawice/EN21420.jpg" alt="Norma">Norma EN ISO 21420</div>'
                .'<div class="icon-box"><img src="https://procera.cfolks.pl/media/piktograms/rekawice/lateks.jpg" alt="Lateks">Powleczenie lateksem</div>'
                .'</div>'
                .'<div class="product-features">'
                .'<div class="feature-box"><span class="feature-icon">✓</span>Bardzo dobra przyczepność</div>'
                .'<div class="feature-box"><span class="feature-icon">✓</span>Lekka i elastyczna konstrukcja</div>'
                .'</div>'
                .'<div class="product-specs">'
                .'<div class="spec-row"><span class="spec-label">Kategoria</span> <span class="spec-value">I</span></div>'
                .'<div class="spec-row"><span class="spec-label">Norma</span> <span class="spec-value">EN21420:2020</span></div>'
                .'<div class="spec-row"><span class="spec-label">Rozmiary</span> <span class="spec-value">9, 10</span></div>'
                .'</div></div><p></p>',
            'files' => ['X-TESTGRIP_1.jpg', 'X-TESTGRIP_KARTA-PRODUKTOWA.pdf', 'MANUAL X-TESTGRIP ŁĄCZNIE.pdf'],
            'files_dir' => '7002',
            'gallery' => ['AA/AA11.jpg', 'BB/BB22.jpg'],
            'list_image' => 'AA/AA11.jpg',
            'xml' => ['X-TESTGRIP' => ['client' => '2.22', 'catalog' => '2.7800', 'ean' => '5900000000101']],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private static function shoes(): array
    {
        return [
            'id' => '7101',
            'slug' => 'TRZEWIKI-BEZPIECZNE-TESTOS-S1P/uuid-7101?first_id=7102&group_id=0',
            'name' => 'TRZEWIKI BEZPIECZNE TESTOS S1P',
            'list_code' => 'TESTOS S1PL 39',
            'list_price' => '49,00 PLN',
            'unit' => 'para',
            'ean' => '5900000000201',
            'headers' => ['ROZMIAR'],
            'variants' => [
                ['id' => '7102', 'code' => 'TESTOS S1PL 39', 'cols' => ['39'], 'availability' => 'Dostępny', 'net' => '49'],
                ['id' => '7103', 'code' => 'TESTOS S1PL 46', 'cols' => ['46'], 'availability' => 'Czasowo niedostępny', 'net' => '89'],
                ['id' => '7104', 'code' => 'TESTOS S1PL 47', 'cols' => ['47'], 'availability' => 'Dostępny', 'net' => '89'],
            ],
            'description' => '<p>Trzewiki bezpieczne TESTOS z podnoskiem kompozytowym.</p>',
            'files' => [],
            'files_dir' => '7102',
            'gallery' => ['CC/CC33.jpg'],
            'list_image' => 'CC/CC33.jpg',
            'xml' => ['TESTOS S1PL' => ['client' => '123.9', 'catalog' => '154.8800', 'ean' => '5900000000201']],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private static function shorts(): array
    {
        return [
            'id' => '7201',
            'slug' => 'SPODNIE-KROTKIE-TESTLANDER/uuid-7201?first_id=7202&group_id=0',
            'name' => 'SPODNIE KRÓTKIE TESTLANDER',
            // lista pokazuje drugi rozmiar, nie pierwszy (jak GRAF S1 w panelu)
            'list_code' => 'TESTLANDER SK 48',
            'list_price' => '49,90 PLN',
            'unit' => 'szt',
            'ean' => '5900000000401',
            'headers' => ['ROZMIAR', 'TERMIN DOSTAWY'],
            'variants' => [
                ['id' => '7202', 'code' => 'TESTLANDER SK 46', 'cols' => ['46', '20.04.2027'], 'availability' => 'Czasowo niedostępny', 'net' => '49.9'],
                ['id' => '7203', 'code' => 'TESTLANDER SK 48', 'cols' => ['48', '20.04.2027'], 'availability' => 'Czasowo niedostępny', 'net' => '49.9'],
            ],
            'description' => '<p>Spodnie krótkie TESTLANDER z tkaniny RIP-STOP.</p>',
            'files' => ['TESTLANDER_1.jpg'],
            'files_dir' => '7202',
            'gallery' => ['DD/DD44.jpg'],
            'list_image' => 'DD/DD44.jpg',
            'xml' => ['TESTLANDER SK' => ['client' => '49.9', 'catalog' => '62.3800', 'ean' => '5900000000401']],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private static function filter(): array
    {
        return [
            'id' => '1523',
            'slug' => 'FILTR-PRZECIWPYLOWY-3M-KAT-P2---9125/uuid-1523?first_id=1523&group_id=0',
            'name' => 'FILTR PRZECIWPYŁOWY 3M KAT. P2 - 9125',
            'list_code' => '3M9125',
            'list_price' => '11,43 PLN',
            'unit' => 'szt',
            'ean' => '0511315290450',
            'single_availability' => 'Czasowo niedostępny',
            'headers' => [],
            'variants' => [],
            // zwykły opis: linie rozdzielone znakiem nowej linii w jednym akapicie
            'description' => "<p>Filtr (P2) chroni przed pyłami. Pasuje do półmasek testowych.\nNiska masa, niski opór oddychania.\n"
                ."Dane techniczne\nRodzaj filtra: Filtr cząstek stałych\nZalecana klasa ochrony: P2</p>",
            'files' => ['3M9125.jpg'],
            'files_dir' => '1523',
            'gallery' => ['2A/2A0402FE.jpg'],
            'list_image' => '2A/2A0402FE.jpg',
            'xml' => ['3M9125' => ['client' => '11.43', 'catalog' => '22.8500', 'ean' => '0511315290450']],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private static function socks(): array
    {
        return [
            'id' => '7301',
            'slug' => 'SKARPETY-ROBOCZE-TESTSOCKS/uuid-7301?first_id=7302&group_id=0',
            'name' => 'SKARPETY ROBOCZE TESTSOCKS - 2 PARY',
            'list_code' => 'TESTSOCKS 39-42 OPAK',
            'list_price' => '8,70 PLN',
            'unit' => 'opak',
            'ean' => '5900000000301',
            'headers' => ['ROZMIAR'],
            'variants' => [
                ['id' => '7302', 'code' => 'TESTSOCKS 39-42 OPAK', 'cols' => ['39-42'], 'availability' => 'Dostępny', 'net' => '8.7'],
                ['id' => '7303', 'code' => 'TESTSOCKS 43-46 OPAK', 'cols' => ['43-46'], 'availability' => 'Dostępny', 'net' => '8.7'],
            ],
            'description' => '<p>Skarpety robocze, opakowanie 2 pary.</p>',
            'files' => [],
            'files_dir' => '7302',
            'gallery' => [],
            'list_image' => 'EE/EE55.jpg',
            'xml' => ['TESTSOCKS' => ['client' => '4.35', 'catalog' => '5.4400', 'ean' => '5900000000301']],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private static function single(string $id, string $code, string $name, string $price): array
    {
        return [
            'id' => $id,
            'slug' => 'WYROB-'.$id.'/uuid-'.$id.'?first_id='.$id.'&group_id=0',
            'name' => $name,
            'list_code' => $code,
            'list_price' => $price,
            'unit' => 'szt',
            'ean' => '',
            'single_code' => $code,
            'single_availability' => 'Dostępny',
            'headers' => [],
            'variants' => [],
            'description' => '<p>Opis wyrobu '.$code.'.</p>',
            'files' => [],
            'files_dir' => $id,
            'gallery' => [],
            'list_image' => '',
        ];
    }

    /**
     * @return list<array<string, mixed>>
     */
    private static function manySingles(int $count): array
    {
        $models = [];
        for ($i = 1; $i <= $count; $i++) {
            $models[] = self::single((string) (9000 + $i), sprintf('T%03d', $i), 'WYRÓB TESTOWY '.$i, '1'.$i.',00 PLN');
        }

        return $models;
    }

    // ---- atrapa panelu ----

    private function fakeSite(): void
    {
        $listCalls = 0;
        Http::fake(function (Request $request) use (&$listCalls) {
            $url = $request->url();
            $path = (string) parse_url($url, PHP_URL_PATH);
            parse_str((string) parse_url($url, PHP_URL_QUERY), $query);
            preg_match('/b2b_session=(\w+)/', $request->header('Cookie')[0] ?? '', $m);
            $signedIn = isset($m[1]) && in_array($m[1], $this->validSessions, true);
            $html = ['Content-Type' => 'text/html; charset=UTF-8'];

            if ($path === '/login' && $request->method() === 'GET') {
                return Http::response(self::loginPage(), 200, [...$html, 'Set-Cookie' => 'b2b_session=guest; Path=/; HttpOnly']);
            }
            if ($path === '/login' && $request->method() === 'POST') {
                $data = $request->data();
                $good = ($data['_token'] ?? null) === self::TOKEN
                    && ($data['contractor_code'] ?? null) === self::NIP
                    && ($data['name'] ?? null) === self::PERSON
                    && ($data['password'] ?? null) === self::PASSWORD;
                if (! $good) {
                    // jak panel: złe dane = strona logowania z komunikatem, bez sesji konta
                    return Http::response(self::loginPage(), 200, $html);
                }
                $this->logins++;
                $this->validSessions[] = 'sess'.$this->logins;

                return Http::response(self::shell('<h1>Start</h1>', true), 200, [
                    ...$html,
                    'Set-Cookie' => 'b2b_session=sess'.$this->logins.'; Path=/; HttpOnly',
                ]);
            }
            if ($path === '/start') {
                return Http::response($signedIn ? self::shell('<h1>Start</h1>', true) : self::loginPage(), 200, $html);
            }
            if ($path === '/pricelist/xml') {
                if (! $signedIn) {
                    return Http::response(self::loginPage(), 200, $html);
                }

                return $this->xmlBroken
                    ? Http::response('Server Error', 500)
                    : Http::response($this->priceListXml(), 200, [
                        'Content-Type' => 'text/xml; charset=utf-8',
                        'Content-Disposition' => 'attachment; filename=pricelist.xml',
                    ]);
            }
            if ($path === '/product') {
                $listCalls++;
                $page = max(1, (int) ($query['page'] ?? 1));
                $total = count($this->order);
                if ($this->counterChangesOnce && $listCalls === 2) {
                    $total++;
                }

                return Http::response($this->listHtml($page, $total, $signedIn), 200, $html);
            }
            if (str_starts_with($path, '/product-details/')) {
                $this->productRequests[] = $url;
                if ($this->dropSessionOnProduct) {
                    $this->dropSessionOnProduct = false;
                    $this->validSessions = [];
                    $signedIn = false;
                }
                foreach ($this->models as $model) {
                    if ($url === 'https://b2b.procera.pl/product-details/'.$model['slug']) {
                        if (($model['status'] ?? 200) !== 200) {
                            return Http::response('Nie znaleziono', $model['status'], $html);
                        }

                        return Http::response(self::productHtml($model, $signedIn && ! $this->productsAlwaysAnonymous), 200, $html);
                    }
                }

                return Http::response('Nie znaleziono', 404, $html);
            }
            if (str_starts_with($path, '/get-preview/')) {
                return Http::response(self::jpeg($path), 200, ['Content-Type' => 'image/jpeg']);
            }
            if (str_starts_with($path, '/assets/resources/products/')) {
                return Http::response("%PDF-1.4\n".rawurldecode(basename($path))."\n%%EOF", 200, ['Content-Type' => 'application/octet-stream']);
            }

            return Http::response('Nie znaleziono '.$url, 404, $html);
        });
    }

    private static function jpeg(string $path): string
    {
        // prawidłowy mały obraz (1x1), różny dla każdego adresu
        $image = imagecreatetruecolor(1, 1);
        imagesetpixel($image, 0, 0, crc32($path) & 0xFFFFFF);
        ob_start();
        imagejpeg($image);

        return (string) ob_get_clean();
    }

    private static function loginPage(): string
    {
        return '<!DOCTYPE html><html><head><title>PROCERA</title></head><body class="login-page"><div class="login-box">'
            .'<form action="" method="post" autocomplete="on">'
            .'<input type="hidden" name="_token" value="'.self::TOKEN.'" autocomplete="off">'
            .'<input type="text" class="form-control" name="contractor_code" placeholder="Kontrahent" value="">'
            .'<input type="text" class="form-control" name="name" placeholder="Osoba" value="">'
            .'<input type="password" class="form-control" name="password" placeholder="Hasło">'
            .'<button type="submit" class="btn">Zaloguj</button></form>'
            .'<a href="https://b2b.procera.pl/signup">Poproś o dostęp</a></div></body></html>';
    }

    /** Szkielet strony panelu; zalogowany ma link wylogowania, gość — odnośnik logowania. */
    private static function shell(string $content, bool $signedIn): string
    {
        $account = $signedIn
            ? '<a href="https://b2b.procera.pl/logout">Wyloguj</a>'
            : '<a href="https://b2b.procera.pl/login">Logowanie B2B</a>';

        return '<!DOCTYPE html><html><head><meta charset="utf-8"><meta name="generator" content="B2B 3.0.1.6">'
            .'<base href="https://b2b.procera.pl/"><title>PROCERA</title></head><body>'
            .'<header>'.$account.'<a href="https://b2b.procera.pl/pricelist/xml">Cennik XML</a></header>'
            .'<div class="content-wrapper">'.$content.'</div>'
            .'<footer><a href="https://b2b.procera.pl/login">Logowanie B2B</a> Procera © 2026</footer></body></html>';
    }

    private function listHtml(int $page, int $total, bool $signedIn): string
    {
        $ids = array_slice($this->order, ($page - 1) * $this->pageSize, $this->pageSize);
        $rows = '';
        foreach ($ids as $id) {
            $rows .= self::listRow($this->models[$id], $signedIn);
        }
        $from = ($page - 1) * $this->pageSize + 1;
        $to = min($page * $this->pageSize, $total);

        return self::shell(
            '<table class="table"><tbody>'.$rows.'</tbody></table>'
            .'<span class="pagination-info" style=" display: block; margin-top: 15px; ">Wyświetlanie produktów od '.$from.' do '.$to.' z '.$total.'</span>'
            .'<ul class="pagination"><li><a href="https://b2b.procera.pl/product?query=%25&amp;page='.($page + 1).'">»</a></li></ul>',
            $signedIn,
        );
    }

    /**
     * Wiersz listy jak w panelu: odnośnik ikony, galeria, EAN, nazwa z kodem, cena, dostępność, formularz koszyka
     * w komórce tabeli.
     *
     * @param  array<string, mixed>  $model
     */
    private static function listRow(array $model, bool $signedIn): string
    {
        $id = $model['id'];
        $href = htmlspecialchars('https://b2b.procera.pl/product-details/'.$model['slug']);
        $image = $model['list_image'] !== ''
            ? '<a data-img="'.self::PREVIEW.$model['list_image'].'" data-full="'.self::PREVIEW.$model['list_image'].'"></a>'
            : '';
        $price = $signedIn && $model['list_price'] !== ''
            ? '<span style="font-size:16px;color:#017A21;font-weight: 900;" class="twojaCenaNetto_'.$id.'">'.$model['list_price'].'</span>'
            : '';

        return '<tr><td class="align-middle"><div style=" font-size: 30px;text-align: center; z-index: 99;">'
            .'<a class="urlViewProductCart_'.$id.'" style="color: #000000;" href="'.$href.'"><i class="fal fa-info-circle"></i></a></div>'
            .'<div style=" font-size: 30px; text-align: center; z-index: 99;cursor:pointer;"><i class="fa-heart far dodajDoUlubionych" data-idproduktu="'.$id.'"></i></div></td>'
            .'<td class="align-middle clickable-table-view" href="'.$href.'"><div class="B2B_fotorama widget-user-header" id="B2B_fotorama-'.$id.'" data-id="'.$id.'">'.$image.'</div></td>'
            .'<td class="align-middle clickable-table-view eanViewProductDane_'.$id.' urlViewProductCart_'.$id.'" href="'.$href.'">'.$model['ean'].'</td>'
            .'<td class="align-middle clickable-table-view urlViewProductCart_'.$id.'" href="'.$href.'">'
            .'<span style="font-weight: bold;">'."\n".htmlspecialchars($model['name'])."\n".'</span>'
            .'<span style="font-size: 11px;color:#666666;"><br><span style="overflow-wrap: break-word; float: left;" class="codeViewProductDane_'.$id.'">'.htmlspecialchars($model['list_code']).'</span></span></td>'
            .'<td class="align-middle text-right clickable-table-view urlViewProductCart_'.$id.'" href="'.$href.'">'.$price.'</td>'
            .'<!-- Customowe ceny --><!-- Customowe ceny end -->'
            .'<td class="align-middle text-center clickable-table-view urlViewProductCart_'.$id.'" href="'.$href.'">'
            .'<span class="colorStanViewProductDane_'.$id.' stanViewProductDane_'.$id.'">'."\n".'Dostępny'."\n".'</span></td>'
            .'<td class="align-middle"><div class="panelPowiadomieniaODostepnosci_'.$id.'" data-product_id="'.$id.'" style="display:none;"><button type="button">Powiadom o dostępności</button></div>'
            .'<div class="panelDodawaniaProduktuDoKoszyka_'.$id.'" data-product_id="'.$id.'" style="display:block;">'
            .'<form class="ajax-basket add-item add-many-items" method="post"><input type="hidden" id="produktDodawanyDoKoszyka_'.$id.'" value="'.$id.'" name="product_id">'
            .'<div class="input-group"><input type="number" class="form-control input-basket" name="quantity" data-product="'.$id.'" min="0" step="any">'
            .'<span class="input-group-addon">'.$model['unit'].'</span></div>'
            .'<div class="input-group"><span class="input-group-btn"><button type="submit" title="Dodaj do koszyka">Dodaj do koszyka </button></span></div></form></div></td></tr>';
    }

    /**
     * Strona wyrobu: prawa sekcja (nazwa, cena, tabela Kod / Kod EAN / Dostępność, tabela rozmiarów), lewa (galeria,
     * zakładki opisu i plików). Gość widzi ceny rozmyte tak samo — różni go tylko brak linku wylogowania.
     *
     * @param  array<string, mixed>  $model
     */
    private static function productHtml(array $model, bool $signedIn): string
    {
        $variants = $model['variants'];
        $shown = $variants[0] ?? null;
        $code = $shown['code'] ?? $model['single_code'] ?? $model['list_code'];
        $availability = $shown['availability'] ?? $model['single_availability'] ?? 'Dostępny';
        $mainPrice = $shown !== null ? number_format((float) $shown['net'], 2, ',', ' ').' PLN' : $model['list_price'];

        $variantTable = '';
        if ($variants !== []) {
            $head = '<th class="common text-left">Kod</th>';
            foreach ($model['headers'] as $header) {
                $head .= '<th class="text-center" style="border-bottom:0px;"> '.$header.' </th>';
            }
            $head .= '<th class="common text-center">Dostępność</th><th class="common text-center">Ilość</th>'
                .'<th class="common text-right">Wartość netto</th><th class="common text-right">Wartość brutto</th>';
            $body = '';
            foreach ($variants as $v) {
                $cols = '';
                foreach ($v['cols'] as $col) {
                    $cols .= '<td class="text-center"> '.$col.' </td>';
                }
                $body .= '<tr style=""><td class="text-left"> '.htmlspecialchars($v['code']).' </td>'.$cols
                    .'<td class="text-center" style="white-space: nowrap;"><span style="display: inline-block;"> '.$v['availability'].' </span></td>'
                    .'<td class="text-center"><form id="form-id-'.$v['id'].'" method="post" class="ajax-basket">'
                    .'<input type="hidden" name="_token" value="'.self::TOKEN.'" autocomplete="off">'
                    .'<input type="hidden" name="product_id" id="p-id-'.$v['id'].'" value="'.$v['id'].'">'
                    .'<input type="number" min="0" class="form-control text-center input-sm p-quantity p-quantityWariant" data-price-net="'.$v['net'].'" data-price-gross="0" data-productwariant-id="'.$v['id'].'" name="quantity" id="p-quantity-'.$v['id'].'" value="0" required="" step="any">'
                    .'</form></td>'
                    .'<td class="text-right"><span class="p-price-net2 p-price-netWariant" id="p-price-netWariant-'.$v['id'].'">0,00</span> <span class="p-currency">PLN</span></td>'
                    .'<td class="text-right"><span class="p-price-gross2 p-price-grossWariant">0,00</span> <span class="p-currency">PLN</span></td>'
                    .'<td class="text-center"><span class="input-group-btn"><button class="btn" form="form-id-'.$v['id'].'" title="Dodaj do koszyka"><i class="fa fa-shopping-cart"></i></button></span></td></tr>';
            }
            $body .= '<tr><td colspan="4" class="text-right"><strong>Razem:</strong></td><td class="text-center" id="totalCart">'
                .'<form id="form-id-all" method="post" class="ajax-basket add-manyVariantList-items"><input type="number" id="total-quantityWariant" value="0" disabled=""></form></td></tr>';
            $variantTable = '<div id="variants-table" style="overflow:auto; margin-top:20px"><table class="table table-hover table-condensed" id="p-variant-matrix">'
                .'<thead id="variantsHeaderTemplate"><tr class="variantsHeader">'.$head.'</tr></thead>'
                .'<tbody id="variants-table-body">'.$body.'</tbody></table></div>';
        }

        $files = '';
        foreach ($model['files'] as $file) {
            $files .= '<tr><td class="text-left"><strong>'."\n".htmlspecialchars($file)."\n".'</strong></td>'
                .'<td style="text-align: right;"><a href="assets/resources/products/'.$model['files_dir'].'/'.htmlspecialchars($file).'" target="_blank">'
                .'<i class="fa fa-download"></i>&nbsp;&nbsp;Pobierz</a></td></tr>';
        }
        $gallery = '';
        foreach ($model['gallery'] as $image) {
            $gallery .= '<a data-img="'.self::PREVIEW.$image.'" data-full="'.self::PREVIEW.$image.'" data-thumb="'.self::PREVIEW.$image.'"></a>';
        }

        $content = '<div id="kartaSzczegolowaPelnaSekcja" class="row">'
            .'<div id="kartaSzczegolowaPrawaSekcja"><div class="box-body content-product" id="content-product">'
            .'<span class="header-product"><form action="https://b2b.procera.pl/product/favorites" name="form-name" method="post">'
            .'<input type="hidden" name="product_id" value="'.$model['id'].'"></form>'
            .'<h3 class="style-h3 nameViewProductDane">'.htmlspecialchars($model['name']).'</h3>'
            .'<span id="b2b-price-discounted" style="filter: blur(5px);">'."\n".$mainPrice."\n".'</span>'
            .'<span class="b2b-price-with-out-discount" id="b2b-price-with-out-discount">0,00 PLN brutto</span></span>'
            .'<table class="table table-hover-black table-product-single" style="margin-top:15px;"><tbody>'
            .'<tr><td><strong>Kod</strong></td><td class="codeViewProductDane">'.htmlspecialchars($code).'</td></tr>'
            .'<tr><td><strong>Kod EAN</strong></td><td class="eanViewProductDane">'.$model['ean'].'</td></tr>'
            .'<tr><td><strong>Dostępność</strong></td><td><span class="colorStanViewProductDane stanViewProductDane">'."\n".$availability."\n".'</span></td></tr>'
            .'</tbody></table>'
            .'<table class="table table-hover-black table-product-single" id="listaSpecyfikcajiRightTab"></table>'
            .'<div id="details-features"><input type="hidden" id="idProduktuPodgladProdutku" value="'.$model['id'].'"></div>'
            .$variantTable
            .'</div></div>'
            .'<div id="kartaSzczegolowaLewaSekcja"><div class="B2B_fotorama widget-user-header" id="B2B_fotorama_details" data-id="'.$model['id'].'">'.$gallery.'</div>'
            .'<ul class="nav nav-tabs"><li class="active"><a data-toggle="tab" href="#description">Opis</a></li><li><a data-toggle="tab" href="#p-tab-files">Pliki do pobrania</a></li></ul>'
            .'<div class="tab-content"><div id="description" class="tab-pane fade in active bookmarks-products">'.$model['description'].'</div>'
            .'<div id="p-tab-files" class="tab-pane fade bookmarks-products p-tab-files"><table class="table table-border" id="p-files-table"><tbody>'.$files.'</tbody></table></div>'
            .'<div class="tab-pane" id="p-tabNew-matrix"><!-- MATRIX START --></div></div></div></div>';

        return self::shell($content, $signedIn);
    }

    private function priceListXml(): string
    {
        $rows = '';
        foreach ($this->xml as $code => $row) {
            $rows .= "<product>\r\n<product_code>".htmlspecialchars($code)."</product_code>\r\n<name>WYRÓB</name>\r\n"
                .'<CENA_KLIENTA>'.$row['client']."</CENA_KLIENTA>\r\n<currency>".($row['currency'] ?? 'PLN')."</currency>\r\n<vat>23.00</vat>\r\n"
                ."<quantity>0.0000</quantity>\r\n<on_stock>false</on_stock>\r\n<CENA_CENNIKOWA_HURT>".$row['catalog']."</CENA_CENNIKOWA_HURT>\r\n"
                ."<CENA_DETAL>0.0000</CENA_DETAL>\r\n<barcodes>\r\n<ean>".$row['ean']."</ean>\r\n"
                .implode('', array_map(static fn (string $ean): string => '<ean>'.$ean."</ean>\r\n", $row['more_eans'] ?? []))
                ."</barcodes>\r\n</product>\r\n";
        }

        return "<?xml version='1.0' standalone='yes'?>\n                    <offer>\r\n".$rows.'</offer>';
    }
}
