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
use App\Services\B2b\B2bAccountSyncRunner;
use App\Services\B2b\B2bConnectorRegistry;
use App\Services\B2b\B2bDocumentSource;
use App\Services\B2b\B2bFatalException;
use App\Services\B2b\B2bImageGallery;
use App\Services\B2b\B2bManufacturerSite;
use App\Services\B2b\B2bRemoteDocument;
use App\Services\B2b\B2bRemoteProduct;
use App\Services\B2b\B2bShopFieldSource;
use App\Services\B2b\MactronicB2bClient;
use App\Services\B2b\MactronicB2bConnector;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use RuntimeException;
use Tests\TestCase;

/**
 * Łącznik b2b.mactronic.pl (ImB2B) na atrapie sklepu (Http::fake). Znaczniki odwzorowują strony zalogowanego konta
 * z 01.10.2026: formularz /customer/login z żetonem customer[_token_], udane logowanie przekierowuje na
 * /customer/profile, strony konta mają „logged-in” w <body class>, gość jest przekierowywany na formularz (także
 * z /upload/getfile/), zdjęcia /upload/default/thumbs/ są publiczne. Lista /product/category z „Strona 1 z N”
 * i kafelkami div.product--{id}; strona produktu: nagłówek z „Kod produktu” i „Kod EAN”, cena z dymkiem „Cena
 * katalogowa” / „Twoja cena”, dostępność, „Producent” i krótki tekst w „Parametrach technicznych”, „Opis produktu”
 * z tabelą parametrów, pliki do pobrania i galeria 1000×1000.
 *
 * Wszystkie dane (kody, ceny, EAN-y, nazwy, opisy) są SYNTETYCZNE.
 */
final class MactronicConnectorTest extends TestCase
{
    use RefreshDatabase;

    private const USER = 'zakupy@example.test';

    private const PASSWORD = 'dobre-haslo';

    private const TOKEN = 'abc123token';

    /** @var array<int, array<string, mixed>> id produktu → produkt, w kolejności listy */
    private array $products = [];

    /** Tyle kafelków na stronie listy atrapy (sklep sam decyduje o liczbie). */
    private int $perPage = 2;

    /** @var list<string> ważne sesje */
    private array $sessions = [];

    private int $logins = 0;

    /** @var list<array<string, mixed>> */
    private array $loginBodies = [];

    /** Przy pierwszym wejściu na stronę tego produktu sesja wygasa. */
    private ?int $expireAtPageOf = null;

    /** Przy pierwszym pobraniu pliku sesja wygasa. */
    private bool $expireAtFile = false;

    /** Strony produktów zawsze bez sesji (sklep nie przyjmuje logowania do stron). */
    private bool $pagesWithoutSession = false;

    /** Ta strona listy przychodzi pusta. */
    private ?int $emptyListPage = null;

    /** Tyle razy strona 2 listy powtarza ostatni produkt strony 1 (produkt dodany w trakcie przesuwa listę). */
    private int $shiftedListPages = 0;

    private int $listRequests = 0;

    protected function setUp(): void
    {
        parent::setUp();
        Queue::fake();
    }

    public function test_login_posts_the_form_with_its_token_and_confirms_the_account_page(): void
    {
        $this->fakeSite();
        $client = $this->client();

        $client->login();

        $this->assertTrue($client->isLoggedIn());
        $this->assertSame([
            'customer' => ['_token_' => self::TOKEN, 'oryginal' => 'customer/login', 'mail' => self::USER, 'pass' => self::PASSWORD, 'submit' => 'Zaloguj się'],
        ], $this->loginBodies[0]);
    }

    public function test_wrong_password_is_not_fatal(): void
    {
        $this->fakeSite();
        $client = new MactronicB2bClient(self::USER, 'zle-haslo', 0, static function (int $ms): void {});

        try {
            $client->login();
            $this->fail('logowanie złym hasłem powinno się nie udać');
        } catch (B2bFatalException $e) {
            $this->fail('złe hasło to nie błąd krytyczny: '.$e->getMessage());
        } catch (RuntimeException $e) {
            $this->assertStringContainsString('sprawdź adres e-mail i hasło', $e->getMessage());
        }
        $this->assertFalse($client->isLoggedIn());
    }

    public function test_logged_in_marker_is_the_body_class_token(): void
    {
        $this->assertTrue(MactronicB2bClient::isLoggedInPage('<body class="layout-tpl product anonymous logged-in complex-default">'));
        $this->assertFalse(MactronicB2bClient::isLoggedInPage('<body class="layout-tpl restricted-access customer-guest customer customer-login anonymous">'));
        $this->assertFalse(MactronicB2bClient::isLoggedInPage('<body class="not-logged-in-yet"><a href="/customer/logout">x</a>'));
    }

    public function test_description_html_keeps_table_cells_apart(): void
    {
        $this->assertSame(
            "Latarka TEST do pracy.\n- 3 tryby\n| STRUMIEŃ | CZAS PRACY\nHIGH | 400 lm | 2 h\nLOW | | 20 h\nZESTAW ZAWIERA | etui\nakumulator",
            MactronicB2bConnector::descriptionText('<p>Latarka&nbsp;TEST <strong>do pracy</strong>.</p><ul><li>3 tryby</li></ul>'
                .'<table><tbody><tr><td>&nbsp;</td><td>STRUMIEŃ</td><td>CZAS PRACY</td></tr><tr><td>HIGH</td><td>400 lm</td><td>2 h</td></tr><tr><td>LOW</td><td> </td><td>20 h</td></tr>'
                .'<tr><td>&nbsp;</td><td colspan="2">&nbsp;</td></tr><tr><td>ZESTAW ZAWIERA</td><td>etui<br />akumulator</td></tr></tbody></table><p>&nbsp; &nbsp;</p>'),
        );
    }

    public function test_document_kind_comes_from_the_file_name(): void
    {
        $this->assertSame(ProductDocument::KIND_CERTIFICATE, MactronicB2bConnector::documentKind('CE_Mactronic_THH9999_EMC.pdf'));
        $this->assertSame(ProductDocument::KIND_CERTIFICATE, MactronicB2bConnector::documentKind('THL9999_TEST_deklaracja_CE.pdf'));
        $this->assertSame(ProductDocument::KIND_MANUAL, MactronicB2bConnector::documentKind('THH9999_TEST_manual.pdf'));
        $this->assertSame(ProductDocument::KIND_DATASHEET, MactronicB2bConnector::documentKind('THH9999_TEST_karta produktu-min.pdf'));
        $this->assertSame(ProductDocument::KIND_CERTIFICATE, MactronicB2bConnector::documentKind('DEKLARACJA FCL9999, FCL9998.pdf'));
        $this->assertSame(ProductDocument::KIND_CERTIFICATE, MactronicB2bConnector::documentKind('Certyfikat_Mactronic_PFL9999_IP.pdf'));
        $this->assertSame(ProductDocument::KIND_MANUAL, MactronicB2bConnector::documentKind('protac-test-2aa_op_PL.pdf'));
        $this->assertSame(ProductDocument::KIND_DATASHEET, MactronicB2bConnector::documentKind('AHL9999_TEST_karta-produktu.pdf'));
        $this->assertSame(ProductDocument::KIND_DATASHEET, MactronicB2bConnector::documentKind('FBS9999_Test_karta produktowa_PL.pdf'));
        $this->assertSame(ProductDocument::KIND_OTHER, MactronicB2bConnector::documentKind('PRICE_list_TEST.pdf'));
        $this->assertSame(ProductDocument::KIND_OTHER, MactronicB2bConnector::documentKind('ABR9999_TestLine 2.0.pdf'));
        $this->assertSame(ProductDocument::KIND_OTHER, MactronicB2bConnector::documentKind('Ceny_document_TEST.pdf'));
    }

    public function test_list_goes_through_all_pages_and_each_product_is_one_card(): void
    {
        $this->addLamp();
        $this->addBattery();
        $this->addLamp(id: 903, code: 'THL9999', ean: '5900000000301', manufacturer: 'Falcon Eye');
        // krótki tekst = nazwa produktu i dopisek o wyprzedaży — opisu z tego nie ma
        $this->addBattery(id: 904, code: 'B-TEST-AAA', technical: '<p>Akumulator TEST AA / blister 2 szt.</p><h2><span style="color:#8e44ad;">WYPRZEDAŻ.&nbsp;</span>Cena ostateczna, nie podlega rabatowaniu</h2>');
        $this->fakeSite();
        $connector = $this->connector();

        $products = iterator_to_array($connector->products(), false);

        $this->assertSame(['THH9999', 'B-TEST-AA/bl', 'THL9999', 'B-TEST-AAA'], array_map(static fn (B2bRemoteProduct $p): string => $p->sku, $products));
        $card = $products[0];
        $this->assertSame('901', $card->remoteId);
        $this->assertSame('TESTER IR latarka taktyczna, 400 lm', $card->name);
        $this->assertSame('Latarki ręczne > Taktyczne', $card->category);
        $this->assertSame('https://b2b.mactronic.pl/latarki-reczne/tester-ir-latarka-taktyczna-400-lm-901', $card->sourceUrl);
        $this->assertSame('Średnio', $card->availability);
        $this->assertSame([], $card->members);
        $price = $connector->price($card);
        $this->assertSame([1150.0, 1250.0, 8.0, 'PLN'], [$price->net, $price->base, $price->discountPercent, $price->currency]);
        $this->assertSame([null, null, 'szt.'], [$price->order?->min, $price->order?->step, $price->order?->unit]);
        $this->assertSame('Mactronic', $connector->manufacturer($card));
        $this->assertSame(
            [[ProductIdentifier::TYPE_SOURCE_CODE, 'THH9999', 'Kod produktu'], [ProductIdentifier::TYPE_EAN, '5900000000101', 'Kod EAN']],
            array_map(static fn ($i): array => [$i->type, $i->value, $i->field], $card->identifiers ?? []),
        );
        $this->assertSame(
            "TESTER IR – latarka taktyczna\nLatarka TEST emituje światło białe i IR.\nTRYB | STRUMIEŃ | CZAS PRACY\nHIGH | 400 lm | 2 h 30 min\nKLASA SZCZELNOŚCI | IPX7",
            $connector->description($card),
        );
        $this->assertSame(
            [
                'Informacje ze sklepu Mactronic | Kod produktu | THH9999',
                'Informacje ze sklepu Mactronic | Kod EAN | 5900000000101',
                'Informacje ze sklepu Mactronic | Producent | Mactronic',
                'Informacje ze sklepu Mactronic | Parametry techniczne | Latarka ręczna TEST ze światłem IR.',
                'Informacje ze sklepu Mactronic | Jednostka sprzedaży | szt.',
            ],
            array_map(static fn ($f): string => $f->section.' | '.$f->name.' | '.$f->value, $connector->shopFields($card)),
        );
        $this->assertSame(
            ['https://b2b.mactronic.pl/upload/default/thumbs/b2b/1000x1000/images/product-images/thh9999/test-01.jpg', 'https://b2b.mactronic.pl/upload/default/thumbs/b2b/1000x1000/images/product-images/thh9999/test-02.jpg'],
            $connector->imageUrls($card),
        );
        $this->assertSame(
            [
                ['CE_Mactronic_THH9999_EMC.pdf', 'https://b2b.mactronic.pl/upload/getfile/9101', ProductDocument::KIND_CERTIFICATE],
                ['THH9999_Tester IR_manual.pdf', 'https://b2b.mactronic.pl/upload/getfile/9102', ProductDocument::KIND_MANUAL],
                ['THH9999_Tester-IR_karta produktu-min.pdf', 'https://b2b.mactronic.pl/upload/getfile/9103', ProductDocument::KIND_DATASHEET],
            ],
            array_map(static fn (B2bRemoteDocument $d): array => [$d->title, $d->sourceUrl, $d->kind], $connector->documents($card)),
        );

        // blister: cena za „bl”, bez dymka z ceną katalogową, bez EAN-u i plików; bez „Opisu produktu” — opis z krótkiego
        // tekstu, dopisek o wyprzedaży osobno (nie w opisie i nie w krótkim tekście)
        $battery = $products[1];
        $batteryPrice = $connector->price($battery);
        $this->assertSame([22.08, null, 0.0, 'bl'], [$batteryPrice->net, $batteryPrice->base, $batteryPrice->discountPercent, $batteryPrice->order?->unit]);
        $this->assertSame('GP', $connector->manufacturer($battery));
        $this->assertSame([[ProductIdentifier::TYPE_SOURCE_CODE, 'B-TEST-AA/bl']], array_map(static fn ($i): array => [$i->type, $i->value], $battery->identifiers ?? []));
        $this->assertSame('Akumulator TEST AA w blistrze.', $connector->description($battery));
        $this->assertSame(
            [
                'Kod produktu | B-TEST-AA/bl',
                'Producent | GP',
                'Parametry techniczne | Akumulator TEST AA w blistrze.',
                'Uwaga sklepu | WYPRZEDAŻ. Cena ostateczna, nie podlega rabatowaniu',
                'Jednostka sprzedaży | bl',
            ],
            array_map(static fn ($f): string => $f->name.' | '.$f->value, $connector->shopFields($battery)),
        );
        $this->assertSame([], $connector->documents($battery));

        $this->assertSame('', $connector->description($products[3]));
        $this->assertSame(
            ['Parametry techniczne | Akumulator TEST AA / blister 2 szt.', 'Uwaga sklepu | WYPRZEDAŻ. Cena ostateczna, nie podlega rabatowaniu'],
            array_values(array_filter(array_map(static fn ($f): string => $f->name.' | '.$f->value, $connector->shopFields($products[3])), static fn (string $row): bool => str_starts_with($row, 'Uwaga') || str_starts_with($row, 'Parametry'))),
        );

        $summary = implode("\n", $connector->runSummary());
        $this->assertStringContainsString('Lista Mactronic: 4 produktów', $summary);
        $this->assertStringContainsString('Karty: 4', $summary);
        $this->assertStringContainsString('Producenci: GP 2, Mactronic 1, Falcon Eye 1', $summary);
        $this->assertStringContainsString('Bez ceny katalogowej: 2, np. B-TEST-AA/bl, B-TEST-AAA', $summary);
        $this->assertStringContainsString('Bez „Opisu produktu” — opis z krótkiego tekstu: 1, np. B-TEST-AA/bl', $summary);
        $this->assertStringContainsString('Bez opisu w sklepie: 1, np. B-TEST-AAA', $summary);
    }

    public function test_product_without_price_a_repeated_code_and_a_foreign_product_page_are_skipped_with_a_reason(): void
    {
        $this->addLamp();
        $this->addBattery(id: 902, code: 'BEZ-CENY', price: null);
        $this->addLamp(id: 904, ean: '5900000000404');
        $this->addBattery(id: 905, code: 'INNY', pageId: 999);
        $this->fakeSite();
        $connector = $this->connector();

        $products = iterator_to_array($connector->products(), false);

        $this->assertSame(['THH9999', 'BEZ-CENY', 'THH9999', 'INNY'], array_map(static fn (B2bRemoteProduct $p): string => $p->sku, $products));
        $this->assertSame(['ok', 'skipped', 'skipped', 'skipped'], array_map(static fn (B2bRemoteProduct $p): string => $p->raw['status'], $products));
        $this->assertSame(['901', '902', '904', '905'], array_map(static fn (B2bRemoteProduct $p): string => $p->remoteId, $products));
        $this->assertSame([], $connector->shopFields($products[1]));
        $summary = implode("\n", $connector->runSummary());
        $this->assertStringContainsString('Bez ceny konta (pominięte): 1, np. BEZ-CENY', $summary);
        $this->assertStringContainsString('Ten sam Kod produktu na kilku produktach (kolejne pominięte): 1, np. THH9999 (produkt 904)', $summary);
        try {
            $connector->price($products[3]);
            $this->fail('produkt z cudzą stroną nie ma ceny');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString('otworzył inny produkt (999)', $e->getMessage());
        }
        $this->expectExceptionMessage('ten sam Kod produktu');
        $connector->price($products[2]);
    }

    public function test_lost_session_on_a_product_page_logs_in_again_and_continues(): void
    {
        $this->addLamp();
        $this->addBattery();
        $this->fakeSite();
        $connector = $this->connector();
        $this->expireAtPageOf = 902;

        $products = iterator_to_array($connector->products(), false);

        $this->assertSame(['ok', 'ok'], array_map(static fn (B2bRemoteProduct $p): string => $p->raw['status'], $products));
        $this->assertSame(22.08, $connector->price($products[1])->net);
        $this->assertSame(2, $this->logins);
    }

    public function test_product_page_still_without_a_session_after_logging_in_again_stops_the_run(): void
    {
        $this->addLamp();
        $this->fakeSite();
        $connector = $this->connector();
        $this->pagesWithoutSession = true;

        $this->expectException(B2bFatalException::class);
        $this->expectExceptionMessage('Utracono sesję konta b2b.mactronic.pl');

        iterator_to_array($connector->products(), false);
    }

    public function test_an_empty_list_page_before_the_last_one_stops_the_list(): void
    {
        $this->addLamp();
        $this->addBattery();
        $this->addLamp(id: 903, code: 'THL9999', ean: '5900000000301');
        $this->fakeSite();
        $this->emptyListPage = 2;

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Strona 2/2 listy produktów b2b.mactronic.pl bez produktów');

        iterator_to_array($this->connector()->products(), false);
    }

    public function test_list_shifted_while_being_read_is_read_again_once(): void
    {
        $this->addLamp();
        $this->addBattery();
        $this->addLamp(id: 903, code: 'THL9999', ean: '5900000000301');
        $this->fakeSite();
        $this->shiftedListPages = 1;

        $products = iterator_to_array($this->connector()->products(), false);

        $this->assertSame(['901', '902', '903'], array_map(static fn (B2bRemoteProduct $p): string => $p->remoteId, $products));
        $this->assertSame(4, $this->listRequests);
    }

    public function test_list_shifted_in_both_reads_stops_the_run(): void
    {
        $this->addLamp();
        $this->addBattery();
        $this->addLamp(id: 903, code: 'THL9999', ean: '5900000000301');
        $this->fakeSite();
        $this->shiftedListPages = 2;

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('zmieniała się w trakcie dwóch pobrań (produkt 902 także na stronie 2)');

        iterator_to_array($this->connector()->products(), false);
    }

    public function test_catalog_price_below_the_account_price_is_not_a_base_price(): void
    {
        $this->addLamp(catalog: '1&nbsp;100,00&nbsp;zł');
        $this->fakeSite();
        $connector = $this->connector();

        $card = iterator_to_array($connector->products(), false)[0];

        $this->assertSame([1150.0, null, 0.0], [$connector->price($card)->net, $connector->price($card)->base, $connector->price($card)->discountPercent]);
        $summary = implode("\n", $connector->runSummary());
        $this->assertStringContainsString('Cena katalogowa niższa niż cena konta (bez ceny katalogowej): 1, np. THH9999 (1100,00 < 1150,00)', $summary);
        $this->assertStringNotContainsString('Bez ceny katalogowej:', $summary);
    }

    public function test_many_products_without_any_account_price_stop_the_run(): void
    {
        for ($i = 0; $i < 21; $i++) {
            $this->addBattery(id: 600 + $i, code: 'BEZ-'.$i, price: null);
        }
        $this->fakeSite();

        $this->expectException(B2bFatalException::class);
        $this->expectExceptionMessage('bez żadnej ceny konta');

        iterator_to_array($this->connector()->products(), false);
    }

    public function test_file_after_a_lost_session_is_downloaded_after_logging_in_again(): void
    {
        $this->addLamp();
        $this->fakeSite();
        $connector = $this->connector();
        $card = iterator_to_array($connector->products(), false)[0];
        $this->expireAtFile = true;

        $file = $connector->documentBytes($connector->documents($card)[0]);

        $this->assertSame('application/pdf', $file['mime']);
        $this->assertStringStartsWith('%PDF-', $file['bytes']);
        $this->assertSame(2, $this->logins);
    }

    public function test_sync_creates_the_card_with_price_identifiers_documents_and_images_and_a_second_run_changes_nothing(): void
    {
        Storage::fake('public');
        Storage::fake('local');
        $this->addLamp();
        $this->fakeSite();

        $result = app(B2bAccountSyncRunner::class)->run($this->account(), delayMs: 0, withImages: true);

        $this->assertSame(1, $result['created'], implode(' | ', $result['errors']));
        $card = Product::query()->where('sku', 'THH9999')->sole();
        $this->assertSame('Mactronic', $card->manufacturer);
        $this->assertStringContainsString('emituje światło białe i IR', (string) $card->description);
        $this->assertSame(['901'], B2bProductLink::query()->where('product_id', $card->id)->pluck('remote_id')->all());
        $slot = ProductSourcePrice::query()->where('product_id', $card->id)->sole();
        $this->assertSame('1150.00', (string) $slot->purchase_price);
        $this->assertSame('1250.00', (string) $slot->catalog_price_net);
        $this->assertSame('Średnio', $slot->availability);
        $this->assertSame(
            '5900000000101',
            ProductIdentifier::query()->where('product_id', $card->id)->where('type', ProductIdentifier::TYPE_EAN)->value('value'),
        );
        $this->assertSame(2, ProductImage::query()->where('product_id', $card->id)->count());
        $this->assertSame(
            [ProductDocument::KIND_CERTIFICATE, ProductDocument::KIND_MANUAL, ProductDocument::KIND_DATASHEET],
            ProductDocument::query()->where('product_id', $card->id)->orderBy('id')->pluck('kind')->all(),
        );
        $rows = collect(ProductShopCard::query()->where('product_id', $card->id)->sole()->fields)->flatMap(static fn (array $section): array => array_map(
            static fn (array $row): string => ($section['section'] ?? '').' | '.$row['name'].' | '.$row['value'],
            $section['rows'] ?? [],
        ))->all();
        $this->assertContains('Informacje ze sklepu Mactronic | Kod produktu | THH9999', $rows);

        $before = $this->snapshot();
        $second = app(B2bAccountSyncRunner::class)->run($this->account(), delayMs: 0, withImages: true);

        $this->assertSame(0, $second['created'], implode(' | ', $second['errors']));
        $this->assertSame(0, $second['updated'], implode(' | ', $second['errors']));
        $this->assertSame(1, $second['unchanged'], implode(' | ', $second['errors']));
        $this->assertSame($before, $this->snapshot());
    }

    public function test_registry_detects_mactronic_by_host_as_the_manufacturer_site(): void
    {
        $registry = app(B2bConnectorRegistry::class);

        $this->assertSame('mactronic', $registry->keyForSites(['https://b2b.mactronic.pl/customer/login']));
        $this->assertSame('Mactronic', $registry->label('mactronic'));
        $this->assertTrue($registry->requiresPassword('mactronic'));
        $this->assertTrue($registry->isManufacturerSite('mactronic'));
        $this->assertNull($registry->discountRulesMode('mactronic'));
        $this->assertTrue(B2bConnectorRegistry::isConnectorUrl('https://b2b.mactronic.pl/latarki-reczne/x'));

        // konto zapisane przed łącznikiem: adres strony logowania, bez klucza łącznika
        $account = B2bAccount::query()->create(['username' => self::USER, 'password' => 'sekret', 'sites' => ['https://b2b.mactronic.pl/customer/login']]);
        $connector = $registry->make($account, 0);

        $this->assertInstanceOf(MactronicB2bConnector::class, $connector);
        foreach ([B2bManufacturerSite::class, B2bShopFieldSource::class, B2bImageGallery::class, B2bDocumentSource::class] as $interface) {
            $this->assertInstanceOf($interface, $connector);
        }
    }

    // ---- pomocnicze ----

    private function client(): MactronicB2bClient
    {
        return new MactronicB2bClient(self::USER, self::PASSWORD, 0, static function (int $ms): void {});
    }

    private function connector(): MactronicB2bConnector
    {
        $connector = new MactronicB2bConnector($this->client());
        $connector->login();

        return $connector;
    }

    private function account(): B2bAccount
    {
        return B2bAccount::query()->firstOrCreate(
            ['username' => self::USER],
            ['password' => self::PASSWORD, 'sites' => ['https://b2b.mactronic.pl/customer/login'], 'connector' => 'mactronic', 'sync_images' => true],
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
            'links' => B2bProductLink::query()->where('product_id', $p->id)->orderBy('remote_id')->pluck('remote_id')->all(),
            'shop_card' => ProductShopCard::query()->where('product_id', $p->id)->value('fields'),
            'images' => ProductImage::query()->where('product_id', $p->id)->orderBy('sort_order')->pluck('source_url')->all(),
            'documents' => ProductDocument::query()->where('product_id', $p->id)->orderBy('id')->get(['title', 'kind', 'source_url'])->toArray(),
            'price' => ProductSourcePrice::query()->where('product_id', $p->id)->get(['purchase_price', 'catalog_price_net', 'availability', 'order_unit'])->toArray(),
            'identifiers' => ProductIdentifier::query()->where('product_id', $p->id)->orderBy('id')->get(['type', 'value', 'removed_at'])->toArray(),
        ]])->all();
    }

    /** Latarka: cena konta 1 150,00 zł (z twardą spacją jak w sklepie), katalogowa 1 250,00 zł, pliki, galeria. */
    private function addLamp(int $id = 901, string $code = 'THH9999', string $ean = '5900000000101', string $manufacturer = 'Mactronic', string $catalog = '1&nbsp;250,00&nbsp;zł'): void
    {
        $this->products[$id] = [
            'page_id' => $id,
            'path' => '/latarki-reczne/tester-ir-latarka-taktyczna-400-lm-'.$id,
            'name' => 'TESTER IR latarka taktyczna, 400 lm',
            'code' => $code,
            'ean' => $ean,
            'price' => ['1&nbsp;150,00&nbsp;zł', 'szt.'],
            'catalog' => $catalog,
            'availability' => 'Średnio',
            'manufacturer' => $manufacturer,
            'technical' => '<p>Latarka ręczna TEST ze światłem IR.</p>',
            'crumbs' => [['/latarki-reczne', 'Latarki ręczne'], ['/latarki-reczne/taktyczne', 'Taktyczne']],
            'description' => '<h2>TESTER IR &ndash; latarka taktyczna</h2><p>Latarka TEST emituje światło białe i IR.</p>'
                .'<div class="bottom-description"><table class="tg"><tbody><tr><td>TRYB</td><td>STRUMIEŃ</td><td>CZAS PRACY</td></tr>'
                .'<tr><td class="tg-fymr">HIGH</td><td>400 lm</td><td>2 h 30 min</td></tr><tr><td>KLASA SZCZELNOŚCI</td><td colspan="2">IPX7</td></tr></tbody></table></div><p>&nbsp; &nbsp;</p>',
            'files' => [[9101, 'CE_Mactronic_THH9999_EMC.pdf'], [9102, 'THH9999_Tester IR_manual.pdf'], [9103, 'THH9999_Tester-IR_karta produktu-min.pdf']],
            'images' => ['thh9999/test-01.jpg', 'thh9999/test-02.jpg'],
        ];
    }

    /**
     * Akumulatory w blistrze: cena za „bl”, bez ceny katalogowej w dymku, bez EAN-u, „Opisu produktu” i plików; krótki
     * tekst z dopiskiem o wyprzedaży (jak w sklepie: nagłówek z kolorowym „WYPRZEDAŻ.”).
     */
    private function addBattery(
        int $id = 902,
        string $code = 'B-TEST-AA/bl',
        ?string $price = '22,08&nbsp;zł',
        ?int $pageId = null,
        string $technical = '<p>Akumulator TEST AA w blistrze.</p><h2><span style="color:#8e44ad;">WYPRZEDAŻ.&nbsp;</span>Cena ostateczna, nie podlega rabatowaniu</h2>',
    ): void {
        $this->products[$id] = [
            'page_id' => $pageId ?? $id,
            'path' => '/akcesoria/akumulator-test-aa-'.$id,
            'name' => 'Akumulator TEST AA / blister 2 szt.',
            'code' => $code,
            'ean' => '',
            'price' => $price !== null ? [$price, 'bl'] : null,
            'catalog' => null,
            'availability' => 'Mało',
            'manufacturer' => 'GP',
            'technical' => $technical,
            'crumbs' => [['/akcesoria', 'Akcesoria']],
            'description' => '',
            'files' => [],
            'images' => ['b-test-aa/test.jpg'],
        ];
    }

    // ---- atrapa sklepu ----

    private function fakeSite(): void
    {
        Http::fake(function (Request $request) {
            $url = $request->url();
            $path = (string) parse_url($url, PHP_URL_PATH);
            preg_match('/(?:^|;\s*)PHPSESSID=([\w-]+)/', $request->header('Cookie')[0] ?? '', $m);
            $signedIn = in_array($m[1] ?? '', $this->sessions, true);
            $html = ['Content-Type' => 'text/html; charset=UTF-8'];

            if ($path === '/customer/login' && $request->method() === 'POST') {
                $data = [];
                parse_str($request->body(), $data);
                $this->loginBodies[] = $data;
                $form = $data['customer'] ?? [];
                if (($form['_token_'] ?? null) === self::TOKEN && ($form['mail'] ?? null) === self::USER && ($form['pass'] ?? null) === self::PASSWORD) {
                    $this->logins++;
                    $this->sessions[] = 's'.$this->logins;

                    // jak w sklepie: przekierowanie na profil konta (atrapa oddaje od razu stronę docelową)
                    return Http::response(self::page('<h1>Mój profil</h1>', true, 'customer-profile'), 200, ['Set-Cookie' => 'PHPSESSID=s'.$this->logins.'; path=/; HttpOnly'] + $html);
                }

                return Http::response(self::loginPage(), 200, $html);
            }
            if ($path === '/customer/login') {
                return Http::response(self::loginPage(), 200, ['Set-Cookie' => 'PHPSESSID=guest; path=/; HttpOnly'] + $html);
            }
            if (str_starts_with($path, '/upload/default/thumbs/')) {
                return Http::response(self::jpeg($path), 200, ['Content-Type' => 'image/jpeg']);
            }
            if (! $signedIn) {
                // jak w sklepie: gość trafia na formularz logowania (przekierowanie 302)
                return Http::response(self::loginPage(), 200, $html);
            }
            if ($path === '/product/category') {
                parse_str((string) parse_url($url, PHP_URL_QUERY), $query);
                $page = (int) ($query['sorter']['page'] ?? 1);
                $this->listRequests++;

                return Http::response(self::page($this->listPage($page), true, 'product-category'), 200, $html);
            }
            if (preg_match('#^/upload/getfile/(\d+)$#', $path, $file) === 1) {
                if ($this->expireAtFile) {
                    $this->expireAtFile = false;
                    $this->sessions = [];

                    return Http::response(self::loginPage(), 200, $html);
                }

                return Http::response("%PDF-1.4\n% TEST ".$file[1]."\n", 200, ['Content-Type' => 'application/pdf']);
            }
            foreach ($this->products as $id => $p) {
                if ($p['path'] === $path) {
                    if ($this->expireAtPageOf === $id) {
                        $this->expireAtPageOf = null;
                        $this->sessions = [];

                        return Http::response(self::loginPage(), 200, $html);
                    }
                    if ($this->pagesWithoutSession) {
                        return Http::response(self::loginPage(), 200, $html);
                    }

                    return Http::response(self::page($this->productPage($id), true, 'product product-view product-view-'.$p['page_id']), 200, $html);
                }
            }

            return Http::response(self::page('<h1>Nie znaleziono</h1>', true, 'error'), 404, $html);
        });
    }

    private function listPage(int $page): string
    {
        $chunks = array_chunk($this->products, $this->perPage, true);
        $pages = max(1, count($chunks));
        $tiles = '';
        $items = $chunks[$page - 1] ?? [];
        if ($page === 2 && $this->shiftedListPages > 0) {
            $this->shiftedListPages--;
            $last = array_key_last($chunks[0]);
            $items = [$last => $chunks[0][$last]] + $items;
        }
        if ($page !== $this->emptyListPage) {
            foreach ($items as $id => $p) {
                $price = $p['price'] !== null
                    ? '<div class="price price--main"> '.$p['price'][0].' <span class="price__unit">/&nbsp;'.$p['price'][1].'</span> </div>'
                    : '';
                $tiles .= '<div class="product btn__container product--'.$id.'" data-product="'.$id.'"> <a class="product__main-link" title="'.$p['name'].'" href="https://b2b.mactronic.pl'.$p['path'].'"></a>'
                    .'<div class="product__body"> <h3 class="product__title">'.$p['name'].'</h3> <div class="product__index"> <div class="index"> '.$p['code'].' </div> </div>'
                    .'<div class="product__details"><div class="product__price"><div class="price__wrapper">'.$price.'</div></div></div></div></div>';
            }
        }

        return '<div class="small-paginator"> <span class="label">Strona</span> <input name="sorter[page]" value="'.$page.'" type="text"/> z <span class="pages">'.$pages.'</span> </div>'
            .'<section class="products__list products__list--category"><div class="products__viewport">'.$tiles.'</div></section>';
    }

    private function productPage(int $id): string
    {
        $p = $this->products[$id];
        $crumbs = '<li class="level-0"><a class="first" href="https://b2b.mactronic.pl/">Strona główna</i></a><span class="separator">/</span></li>'
            .'<li class="level-1"><a href="https://b2b.mactronic.pl/product/category">Produkty</a><span class="separator">/</span></li>';
        foreach ($p['crumbs'] as $i => [$href, $text]) {
            $crumbs .= '<li class="level-'.($i + 2).'"><a href="https://b2b.mactronic.pl'.$href.'">'.$text.'</a><span class="separator">/</span></li>';
        }
        $gallery = '';
        foreach ($p['images'] as $i => $image) {
            $gallery .= '<a data-id="img-'.($i + 1).'" class="colorbox group'.($i === 0 ? ' bigimg' : '').'" title="'.$p['name'].'" href="https://b2b.mactronic.pl/upload/default/thumbs/b2b/1000x1000/images/product-images/'.$image.'">'
                .'<img src="https://b2b.mactronic.pl/upload/default/thumbs/b2b/karta_produktu/images/product-images/'.$image.'"><i class="icon-search"></i></a>';
        }
        $thumbs = '<div id="gallery-thumbs" class="gallery__thumbs"><div class="jcarousel"><ul><li class="active"><a data-id="img-1" class="colorbox" href="https://b2b.mactronic.pl/upload/default/thumbs/b2b/karta_produktu/images/product-images/'.$p['images'][0].'"><img src="x.jpg"></a></li></ul></div></div>';
        $price = '';
        if ($p['price'] !== null) {
            [$amount, $unit] = $p['price'];
            $row = static fn (string $label, string $value): string => '<div class="hover__row"> <label class="label">'.$label.'</label> <div class="product__price"> <div class="price__wrapper"> <div class="price price--main"> '.$value.' <span class="price__unit">/&nbsp;'.$unit.'</span> </div> <div class="price price--additional"> <span class="price--vat">VAT 23%</span><span class="price__additional--value">999,99 zł</span> </div> </div> </div> </div>';
            $tooltip = $p['catalog'] !== null
                ? '<span class="hover-tooltip"> <i class="icon-info icon-static"></i> <div class="product__hover-tooltip"> <div class="product__hover-tooltip-contents">'.$row('Cena katalogowa', $p['catalog']).$row('Twoja cena', $amount).'</div> </div> </span>'
                : '';
            $price = '<div class="product__row product__price-row"> <div class="product__column"> <div class="product__price"> <div class="price__wrapper"> <div class="price price--main"> '.$amount.' <span class="price__unit">/&nbsp;'.$unit.'</span> </div>'
                .'<div class="price price--additional">'.$tooltip.' <span class="price--vat">VAT 23%</span><span class="price__additional--value">999,99 zł <span class="price__unit">/&nbsp;'.$unit.'</span></span> </div> </div> </div> </div> </div>';
        }
        $files = '';
        foreach ($p['files'] as [$fileId, $title]) {
            $files .= '<li class="file"> <div class="file__data"> <a class="file__link" href="https://b2b.mactronic.pl/upload/getfile/'.$fileId.'">'.$title.'</a> <p class="file__description">Rozmiar pliku: 410 KB</p> </div> </li>';
        }
        $ean = $p['ean'] !== '' ? '<div class="product__ean"> <label>Kod EAN:</label> '.$p['ean'].' </div>' : '';

        return '<div id="breadcrumb-top"><ul class="breadcrumb">'.$crumbs.'</ul><div class="level-'.(count($p['crumbs']) + 2).' active"><span>'.$p['name'].'</span></div></div>'
            .'<article id="content" class="main-content"> <div class="product btn__container"> <section class="product__section product__section--main">'
            .'<div class="product__header__wrapper"> <h1 class="product__title">'.$p['name'].'</h1> <div class="product__indeks"> <label>Kod produktu:</label> '.$p['code'].' </div> '.$ean.'</div>'
            .'<div class="product__wrapper"> <div class="product__gallery" id="product-gallery"> <div class="product__upper "> <ul class="product__flags"> <li class="flag flag--sale" data-title="Wyprzedaż"></li> </ul> </div>'.$gallery.$thumbs.'</div>'
            .'<div class="product__body">'.$price
            .'<div class="product__row product__availability-class-row"> <div class="product__column product__availability-column"> <div class="product__availability" data-title="Stan magazynowy"> <span class="product__availability__value state--little"> <span class="available available-1" style="color:#000000"> <label>Dostępność:</label> '.$p['availability'].' </span> </span> </div> </div> </div>'
            .'<div class="product__row product__attributes-row"> <div class="product__attributes"> <div class="header"> <h2 class="nag">Parametry techniczne</h2> </div> <div class="product__attributes__content">'
            .'<div class="product__technical-manufacturer"><span>Producent</span><span class="title">'.$p['manufacturer'].'</span></div> <div class="product__technical"> '.$p['technical'].' </div> </div> </div> </div>'
            .'</div> </div> </section>'
            .'<section id="product-variants" class="product__section product__section--variants"> <div id="ajax-variants" data-loader="ajax" data-src="https://b2b.mactronic.pl/product/view/'.$p['page_id'].'/variants"></div> </section>'
            .'<section id="product-description" class="product__section product__section--description"> <div class="product__description"> <div class="header description"> <h2 class="nag">Opis produktu</h2> </div> <div class="description-content full-html">'.$p['description'].' </div> </div> </section>'
            .'<section id="product-download" class="product__files product__tabs tabs__container product__section"> <div class="container-fluid"> <div class="header files"> <h2 class="nag">Pliki do pobrania</h2> </div> <ul class="tabs"> <li> <a href="#tab#">Pozostałe</a> <div id="tab#"> <ul class="files__list">'.$files.'</ul> </div> </li> </ul> </div> </section>'
            .'<section id="product-similars" class="product__section--similars product__block"> <div class="products__viewport"> <div class="product btn__container product--1" data-product="1"> <a class="product__main-link" title="Podobny" href="https://b2b.mactronic.pl/akcesoria/podobny"></a> <div class="product__index"> <div class="index"> PODOBNY </div> </div> <div class="price price--main"> 5,00 zł <span class="price__unit">/&nbsp;szt.</span> </div> </div> </div> </section>'
            .'</div> </article>';
    }

    private static function page(string $body, bool $account, string $bodyClass): string
    {
        $classes = $account ? 'layout-tpl '.$bodyClass.' anonymous logged-in complex-default' : 'layout-tpl restricted-access customer-guest customer customer-login anonymous';

        return '<!DOCTYPE html><html lang="pl"><head><meta charset="utf-8" /><title>Mactronic</title></head><body class="'.$classes.'">'
            .($account ? '<a href="https://b2b.mactronic.pl/customer/logout">Wyloguj</a>' : '').'<main id="main">'.$body.'</main></body></html>';
    }

    private static function loginPage(): string
    {
        return self::page('<form id="form" class="form form-login" name="customer" method="post" action="https://b2b.mactronic.pl/customer/login">'
            .'<input type ="hidden" name="customer[_token_]" value="'.self::TOKEN.'" /><input type="hidden" value="customer/login" name="customer[oryginal]" />'
            .'<input type="text" name="customer[mail]" /><input type="password" name="customer[pass]" /><input type="submit" value="Zaloguj się" name="customer[submit]" ></form>', false, '');
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
