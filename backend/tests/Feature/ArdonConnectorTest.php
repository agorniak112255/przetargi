<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Jobs\TranslateB2bProductTextJob;
use App\Models\B2bAccount;
use App\Models\B2bProductLink;
use App\Models\B2bSyncRun;
use App\Models\Product;
use App\Models\ProductDocument;
use App\Models\ProductSourcePrice;
use App\Services\B2b\ArdonB2bClient;
use App\Services\B2b\ArdonB2bConnector;
use App\Services\B2b\B2bAccountSyncRunner;
use App\Services\B2b\B2bConnectorRegistry;
use App\Services\B2b\B2bFatalException;
use App\Services\B2b\B2bManufacturerSite;
use App\Services\B2b\B2bRemoteDocument;
use App\Services\B2b\B2bRemoteProduct;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use RuntimeException;
use Tests\TestCase;

/**
 * Łącznik www.ardon.pl na atrapie sklepu (Http::fake, bez prawdziwego logowania). Układ odpowiedzi jak w sklepie
 * 21.09.2026: formularz Nette z tokenem CSRF, odnośnik wylogowania na stronach konta, cennik konta w CSV (BOM,
 * średniki, przecinek dziesiętny), podpowiedzi wyszukiwarki w JSON, strona produktu z danymi strukturalnymi
 * (mpn, nazwa prawna w brand) i akordeonem „Opis i informacje techniczne” / „Parametry” / „Do pobrania”.
 * Wszystkie dane (pozycje, ceny, strony, pliki) są SYNTETYCZNE.
 */
final class ArdonConnectorTest extends TestCase
{
    use RefreshDatabase;

    private const JPEG = "\xFF\xD8\xFF\xE0\x00\x10JFIF\x00\x01syntetyczny\xFF\xD9";

    private const HEADER = 'artykuł;nazwa;Sugerowana cena detaliczna bez VAT;Twój rabat;Twoja cena po rabacie bez VAT';

    /** @var list<string> wiersze cennika CSV (bez nagłówka) */
    private array $rows = [];

    /** @var list<string> */
    private array $validSessions = [];

    private int $logins = 0;

    /** Sesje wygasają przy pierwszym pobraniu cennika. */
    private bool $dropSessionsOnPriceList = false;

    /** Sklep nigdy nie wydaje cennika (np. konto bez dostępu). */
    private bool $priceListAlwaysHtml = false;

    /** Wyszukiwarka nic nie znajduje dla tych kodów. */
    private array $searchMisses = [];

    /** Strony produktu nigdy nie mają odnośnika wylogowania (sklep zmienił wygląd strony). */
    private bool $pagesWithoutSignOut = false;

    protected function setUp(): void
    {
        parent::setUp();

        $this->rows = [
            // cennik nie jest ułożony wg rozmiaru; rozmiar 11 w innej cenie — osobna karta
            'A5001/09;Rękawice testowe ARDON®ALFA;7,000;43,000;4,000',
            'A5001/08;Rękawice testowe ARDON®ALFA;7,000;43,000;4,000',
            'A5001/11;Rękawice testowe ARDON®ALFA;7,900;43,000;4,500',
            'A5001/10;Rękawice testowe ARDON®ALFA;7,000;43,000;4,000',
            // ATG: numer artykułu producenta na końcu nazwy, rabat pusty (cena netto wprost)
            'A3031/07;ATG® NBR-Lite® 24-985;10,610;;5,260',
            'A3031/08;ATG® NBR-Lite® 24-985;10,610;;5,260',
            // jedna pozycja bez rozmiaru; tabelka strony podaje producenta; opis po słowacku
            'C1020;Wkładki przeciwhałasowe 3M 1100;0,778;43,000;0,443',
            // producent tylko z nazwy prawnej ze strony — z listy marek
            'E4085;Okulary UNIVET 506UP zielone;60,000;43,000;34,200',
            // nazwa prawna spoza listy marek — dosłownie
            'I4999;Rękawice spawalnicze testowe;50,000;43,000;28,500',
            // sklep nie ma strony tego wyrobu
            'X9999;Pozycja bez strony;10,000;43,000;5,700',
        ];
    }

    public function test_login_sends_the_form_with_its_csrf_token_and_confirms_the_session_by_the_sign_out_link(): void
    {
        $this->fakeSite();
        $client = $this->client();

        $client->login();

        $this->assertTrue($client->isLoggedIn());
        $post = Http::recorded(fn (Request $r): bool => $r->method() === 'POST')->first()[0];
        $this->assertSame('https://www.ardon.pl/eshop/user/sign-in?returnKey=abc', $post->url());
        $this->assertSame([
            'email' => 'jan@example.com',
            'password' => 'dobre-haslo',
            '_token_' => 'token-logowania',
            '_do' => 'userSignInForm-form-submit',
            '_submit' => 'Zaloguj sie',
        ], $post->data());
    }

    public function test_wrong_password_fails_with_a_clear_message_although_the_shop_answers_http_200(): void
    {
        $this->fakeSite();
        $client = new ArdonB2bClient('jan@example.com', 'zle-haslo', 0, static function (int $ms): void {});

        try {
            $client->login();
            $this->fail('Logowanie powinno się nie udać');
        } catch (RuntimeException $e) {
            $this->assertNotInstanceOf(B2bFatalException::class, $e);
            $this->assertSame('Logowanie do ardon.pl nieudane: sklep nie potwierdził zalogowania — sprawdź login i hasło', $e->getMessage());
        }
        $this->assertFalse($client->isLoggedIn());
    }

    public function test_price_list_with_other_columns_is_an_error_not_a_guess(): void
    {
        $this->expectExceptionMessage('Cennik CSV ardon.pl ma inne kolumny niż oczekiwane');

        ArdonB2bConnector::parsePriceList("artykuł;nazwa;cena\nA1;X;1,0");
    }

    public function test_sizes_in_one_price_are_one_card_another_price_gets_its_ardon_code_and_atg_keeps_its_article_number(): void
    {
        $cards = ArdonB2bConnector::group(ArdonB2bConnector::parsePriceList(self::csv($this->rows)));
        $bySku = array_column($cards, null, 'sku');

        $this->assertSame(['A5001', 'A5001/11', '24-985', 'C1020', 'E4085', 'I4999', 'X9999'], array_column($cards, 'sku'));
        $this->assertSame(['A5001/08', 'A5001/09', 'A5001/10'], array_column($bySku['A5001']['rows'], 'code'));
        $this->assertSame(['A5001/11'], array_column($bySku['A5001/11']['rows'], 'code'));
        $this->assertSame(['A3031/07', 'A3031/08'], array_column($bySku['24-985']['rows'], 'code'));
        $this->assertNull($bySku['24-985']['rows'][0]['discount']);
        $this->assertSame(5.26, $bySku['24-985']['rows'][0]['net']);
        $this->assertSame('24-985', ArdonB2bConnector::atgArticle('ATG® NBR-Lite® 24-985'));
        $this->assertSame('76-730', ArdonB2bConnector::atgArticle('ATG®MaxiChem® z TRItech™ 76-730'));
        $this->assertNull(ArdonB2bConnector::atgArticle('Klips do rękawic ATG'));
        $this->assertNull(ArdonB2bConnector::atgArticle('Rękawice 34-504 innej marki'));
    }

    public function test_largest_price_group_of_a_code_gets_the_plain_code_whatever_its_order_in_the_price_list(): void
    {
        $cards = ArdonB2bConnector::group(ArdonB2bConnector::parsePriceList(self::csv([
            'A7000/12;Rękawice;1,000;;0,600',
            'A7000/08;Rękawice;1,000;;0,500',
            'A7000/09;Rękawice;1,000;;0,500',
            // ceny różne dopiero w czwartym miejscu po przecinku — osobne karty, bez zaokrąglania
            'A7001/08;Inne;1,000;;0,5001',
            'A7001/09;Inne;1,000;;0,5004',
        ])));

        $this->assertSame(['A7000/12', 'A7000', 'A7001', 'A7001/09'], array_column($cards, 'sku'));
    }

    public function test_products_find_the_page_by_code_and_take_the_brand_from_the_page(): void
    {
        $this->fakeSite();
        $connector = $this->connector();

        $products = $this->productsBySku($connector);

        $this->assertSame(7, $connector->totalProducts());
        $alfa = $products['A5001'];
        $this->assertSame('A5001/08', $alfa->remoteId);
        $this->assertSame('Rękawice testowe ARDON®ALFA', $alfa->name);
        // pierwsza podpowiedź wyszukiwarki to inny wyrób (inny mpn) — łącznik bierze drugą
        $this->assertSame('https://www.ardon.pl/rekawice-a5001', $alfa->sourceUrl);
        $this->assertSame('Rękawice robocze powlekane', $alfa->category);
        $this->assertSame('Rozmiary: 08 (A5001/08); 09 (A5001/09); 10 (A5001/10)', $alfa->variantSummary);
        $this->assertSame(
            [
                ['remote_id' => 'A5001/08', 'sku' => 'A5001/08', 'name' => 'Rękawice testowe ARDON®ALFA'],
                ['remote_id' => 'A5001/09', 'sku' => 'A5001/09', 'name' => 'Rękawice testowe ARDON®ALFA'],
                ['remote_id' => 'A5001/10', 'sku' => 'A5001/10', 'name' => 'Rękawice testowe ARDON®ALFA'],
            ],
            $alfa->members,
        );
        $this->assertSame('ARDON', $connector->manufacturer($alfa));
        $this->assertSame('3M', $connector->manufacturer($products['C1020']));
        $this->assertSame('Univet', $connector->manufacturer($products['E4085']));
        $this->assertSame('HARPS Investment Asia Pte. Ltd.', $connector->manufacturer($products['I4999']));
        $this->assertSame('ATG', $connector->manufacturer($products['24-985']));
        $this->assertSame([], $products['C1020']->members);
        $this->assertNull($products['C1020']->variantSummary);

        try {
            $connector->manufacturer($products['X9999']);
            $this->fail('Bez strony producent jest nieznany');
        } catch (RuntimeException $e) {
            $this->assertSame('nie znaleziono strony produktu w sklepie — producent nieznany, pozycja pominięta', $e->getMessage());
        }

        $summary = $connector->runSummary();
        $this->assertSame('Cennik Ardon: 10 pozycji → 7 kart (2 grup rozmiarów o tej samej cenie)', $summary[0]);
        $this->assertStringContainsString('Bez strony produktu w sklepie: 1 kart', $summary[1]);
        $this->assertStringContainsString('X9999', $summary[1]);
        $this->assertSame('Producent spoza listy marek — zapisany dosłownie nazwą ze sklepu: HARPS Investment Asia Pte. Ltd. (1)', $summary[2]);
    }

    public function test_page_is_found_by_the_image_named_with_the_code_or_by_the_name_when_the_code_search_misses(): void
    {
        $this->rows = [
            'A1020/08;Rękawice wzmacniane skórą ARDON®MECHANIK;5,568;43,000;3,174',
            'A2010/09;Rękawice spawalnicze ARDON®GLEN;16,869;43,000;9,615',
            // pozycja bez nazwy w cenniku — sklep jej nie pokazuje, nie szukamy
            'A3045;;11,645;42,000;6,754',
        ];
        $this->fakeSite();
        $connector = $this->connector();

        $products = $this->productsBySku($connector);

        $this->assertSame('https://www.ardon.pl/rekawice-mechanik', $products['A1020']->sourceUrl);
        $this->assertSame('https://www.ardon.pl/rekawice-glen', $products['A2010']->sourceUrl);
        // uprzęże otwiera tylko A2010 (3 pierwsze podpowiedzi po kodzie); A1020 zaczyna od podpowiedzi ze swoim zdjęciem
        $this->assertCount(3, Http::recorded(fn (Request $r): bool => (bool) preg_match('#/(uprzaz|lina)-fa#', $r->url())));
        $this->assertNull($products['A3045']->sourceUrl);
        $this->assertCount(0, Http::recorded(fn (Request $r): bool => str_contains($r->url(), 'query=A3045')));
        $summary = $connector->runSummary();
        $this->assertCount(2, $summary);
        $this->assertSame('Bez nazwy w cenniku Ardona: 1 kart (pominięte — sklep nie podaje nazwy ani strony), np. A3045', $summary[1]);
    }

    public function test_brand_fixes_inflected_producer_row_ardon_made_elsewhere_and_known_company_names(): void
    {
        $this->rows = [
            'F8000;Filtr SR 510;10,000;43,000;5,700',
            'G3300/40;Kalosze robocze ARDON®NIGHTFISH OB;10,000;43,000;5,700',
            'A9500/09;Rękawice chemiczne AlphaTec® 37-676;10,000;43,000;5,700',
        ];
        $this->fakeSite();
        $connector = $this->connector();

        $products = $this->productsBySku($connector);

        $this->assertSame('Sundström', $connector->manufacturer($products['F8000']));
        $this->assertSame('ARDON', $connector->manufacturer($products['G3300']));
        $this->assertSame('Ansell', $connector->manufacturer($products['A9500']));
        $this->assertCount(1, $connector->runSummary());
    }

    public function test_a_code_in_two_prices_is_searched_and_read_once(): void
    {
        $this->fakeSite();

        $products = $this->productsBySku($this->connector());

        $this->assertSame('https://www.ardon.pl/rekawice-a5001', $products['A5001/11']->sourceUrl);
        $this->assertSame('ARDON', $this->connector()->manufacturer($products['A5001/11']));
        $this->assertCount(1, Http::recorded(fn (Request $r): bool => str_contains($r->url(), '/full-text/query') && str_contains($r->url(), 'query=A5001')));
        $this->assertCount(1, Http::recorded(fn (Request $r): bool => $r->url() === 'https://www.ardon.pl/rekawice-a5001'));
    }

    public function test_product_page_without_the_sign_out_link_logs_in_again_only_once(): void
    {
        $this->fakeSite();
        $this->pagesWithoutSignOut = true;

        $this->productsBySku($this->connector());

        // pierwsze logowanie + jedno ponowne; potem sprawdzanie sesji na stronach wyłączone
        $this->assertSame(2, $this->logins);
    }

    public function test_price_is_the_account_price_rounded_to_grosze_with_the_suggested_price_and_the_discount(): void
    {
        $this->fakeSite();
        $products = $this->productsBySku($this->connector());
        $connector = $this->connector();

        $price = $connector->price($products['C1020']);
        $this->assertSame(0.44, $price?->net);
        $this->assertSame(0.78, $price?->base);
        $this->assertSame(43.0, $price?->discountPercent);
        $this->assertSame('PLN', $price?->currency);
        $this->assertSame(0.0, $connector->price($products['24-985'])?->discountPercent);
    }

    public function test_description_parameters_and_trade_data_come_verbatim_from_the_page(): void
    {
        $this->fakeSite();
        $connector = $this->connector();
        $alfa = $this->productsBySku($connector)['A5001'];

        $this->assertSame(
            "Rękawice powlekane lateksem.\nmateriał: PES + bawełna\nnormy i certyfikaty:\n• EN 388:2016 + A1:2018: 3242X",
            $connector->description($alfa),
        );
        $this->assertFalse($connector->hasForeignDescription($alfa));
        $fields = array_map(static fn ($f): string => $f->section.' | '.$f->name.' | '.$f->value, $connector->shopFields($alfa));
        $this->assertSame([
            'Parametry | Środowisko | Suchy',
            'Parametry | Rozmiar | 08, 09, 10',
            'Informacje handlowe | Marka w danych sklepu | ARDON s.r.o.',
            'Informacje handlowe | Kategoria | Mężczyźni > Rękawice robocze > Rękawice robocze powlekane',
            'Informacje handlowe | Kod towaru | A5001/08',
            'Informacje handlowe | Kod towaru | A5001/09',
            'Informacje handlowe | Kod towaru | A5001/10',
        ], $fields);
    }

    public function test_slovak_description_on_the_polish_page_is_marked_for_translation(): void
    {
        $this->fakeSite();
        $connector = $this->connector();

        $this->assertTrue($connector->hasForeignDescription($this->productsBySku($connector)['C1020']));
        $this->assertTrue(ArdonB2bConnector::isForeignText("zátkový chránič sluchu s oblým predným koncom\nmäkká hypoalergénna PU pena"));
        $this->assertFalse(ArdonB2bConnector::isForeignText('Okulary ochronne Bollé z powłoką, zauszniki Sundström'));
    }

    public function test_documents_are_polish_and_multilingual_english_only_without_a_polish_one_and_never_czech(): void
    {
        $connector = new ArdonB2bConnector($this->client());
        $file = static fn (string $name): array => [$name, 'https://www.ardon.pl/eshop/download/product-attachment?id='.crc32($name)];
        $product = new B2bRemoteProduct('A1', 'A1', 'X', raw: ['documents' => [
            $file('A1-DoC2026-EN.pdf'),
            $file('A1-DZ2026-PL.pdf'),
            $file('A1-karta-charakterystyki-PL.pdf'),
            $file('A1-msds-EN.pdf'),
            $file('A1-TDS-EN.pdf'),
            $file('A1-TL-CZ.pdf'),
            $file('A1_navod(instructions)_uni.pdf'),
            $file('A1-pranie-PL.pdf'),
            $file('OEKOTEX-STANDARD100-PG020-128167.pdf'),
            $file('A1-KT-PL.pdf'),
            $file('A1-MBL-CZ.pdf'),
        ]]);

        $documents = $connector->documents($product);

        $this->assertSame(
            ['A1-KT-PL.pdf', 'A1_navod(instructions)_uni.pdf', 'A1-DZ2026-PL.pdf', 'OEKOTEX-STANDARD100-PG020-128167.pdf', 'A1-karta-charakterystyki-PL.pdf', 'A1-pranie-PL.pdf'],
            array_map(static fn (B2bRemoteDocument $d): string => $d->title, $documents),
        );
        $this->assertSame(
            [ProductDocument::KIND_DATASHEET, ProductDocument::KIND_MANUAL, ProductDocument::KIND_CERTIFICATE, ProductDocument::KIND_CERTIFICATE, ProductDocument::KIND_OTHER, ProductDocument::KIND_OTHER],
            array_map(static fn (B2bRemoteDocument $d): string => $d->kind, $documents),
        );

        // bez polskiej karty technicznej — angielska
        $english = new B2bRemoteProduct('A2', 'A2', 'X', raw: ['documents' => [$file('A2_TDS_EN.pdf'), $file('A2_TL_CZ.pdf'), $file('A2_PoS(DoC)_uni.pdf')]]);
        $this->assertSame(['A2_TDS_EN.pdf', 'A2_PoS(DoC)_uni.pdf'], array_map(static fn (B2bRemoteDocument $d): string => $d->title, $connector->documents($english)));
    }

    public function test_atg_items_take_no_files_from_ardon_because_the_manufacturer_site_supplies_them(): void
    {
        $this->fakeSite();
        $connector = $this->connector();

        $this->assertSame([], $connector->documents($this->productsBySku($connector)['24-985']));
    }

    public function test_price_list_without_session_logs_in_again_once(): void
    {
        $this->fakeSite();
        $this->dropSessionsOnPriceList = true;

        $this->productsBySku($this->connector());

        $this->assertSame(2, $this->logins);
    }

    public function test_price_list_refused_to_a_fresh_session_is_fatal(): void
    {
        $this->fakeSite();
        $this->priceListAlwaysHtml = true;

        $this->expectException(B2bFatalException::class);
        $this->productsBySku($this->connector());
    }

    public function test_sync_creates_cards_joins_the_existing_atg_card_and_skips_the_item_without_a_page(): void
    {
        Storage::fake('public');
        Queue::fake();
        $this->fakeSite();
        $atg = Product::query()->create([
            'sku' => '24-985', 'name' => 'NBR-Lite® (classicRange®)', 'manufacturer' => 'ATG',
            'description' => 'Opis z witryny producenta ATG.', 'catalog_price_net' => 0, 'purchase_price' => 0,
        ]);

        $result = app(B2bAccountSyncRunner::class)->run($this->account(), delayMs: 0);

        $this->assertSame(7, $result['total_remote']);
        $this->assertSame(5, $result['created']);
        $this->assertSame(1, $result['updated']);
        $this->assertContains('X9999: nie znaleziono strony produktu w sklepie — producent nieznany, pozycja pominięta', $result['errors']);
        $this->assertFalse(Product::query()->where('sku', 'X9999')->exists());

        $alfa = Product::query()->where('sku', 'A5001')->sole();
        $this->assertSame('ARDON', $alfa->manufacturer);
        $this->assertSame('Rozmiary: 08 (A5001/08); 09 (A5001/09); 10 (A5001/10)', $alfa->variant_summary);
        $this->assertSame(3, B2bProductLink::query()->where('product_id', $alfa->id)->count());
        $this->assertSame(
            ['A5001-KT-PL.pdf', 'A5001-PoS-DoC-uni.pdf'],
            ProductDocument::query()->where('product_id', $alfa->id)->orderBy('sort_order')->pluck('title')->all(),
        );
        $this->assertSame('4.50', (string) ProductSourcePrice::query()
            ->where('product_id', Product::query()->where('sku', 'A5001/11')->value('id'))->value('purchase_price'));

        // istniejąca karta ATG: ta sama karta, cena konta w slocie, opis producenta i nazwa bez zmian, bez plików z Ardona
        $atg->refresh();
        $this->assertSame('Opis z witryny producenta ATG.', $atg->description);
        $this->assertSame('NBR-Lite® (classicRange®)', $atg->name);
        $this->assertSame('ATG', $atg->manufacturer);
        $this->assertSame(['A3031/07', 'A3031/08'], B2bProductLink::query()->where('product_id', $atg->id)->orderBy('remote_id')->pluck('remote_id')->all());
        $this->assertSame('5.26', (string) ProductSourcePrice::query()->where('product_id', $atg->id)->value('purchase_price'));
        $this->assertSame(0, ProductDocument::query()->where('product_id', $atg->id)->count());

        $this->assertSame('3M', Product::query()->where('sku', 'C1020')->value('manufacturer'));
        Queue::assertPushed(TranslateB2bProductTextJob::class, 1);

        $log = array_column((array) B2bSyncRun::query()->latest('id')->firstOrFail()->log, 'text');
        $this->assertContains('Cennik Ardon: 10 pozycji → 7 kart (2 grup rozmiarów o tej samej cenie)', $log);
    }

    public function test_page_missing_on_a_later_run_skips_the_card_instead_of_changing_its_manufacturer(): void
    {
        Storage::fake('public');
        Queue::fake();
        $this->fakeSite();
        app(B2bAccountSyncRunner::class)->run($this->account(), delayMs: 0);
        $this->searchMisses = ['C1020'];

        $second = app(B2bAccountSyncRunner::class)->run($this->account(), delayMs: 0);

        $this->assertContains('C1020: nie znaleziono strony produktu w sklepie — producent nieznany, pozycja pominięta', $second['errors']);
        $this->assertSame('3M', Product::query()->where('sku', 'C1020')->value('manufacturer'));
        $this->assertSame(0, $second['created']);
    }

    public function test_registry_detects_ardon_by_host_and_it_is_not_a_manufacturer_site(): void
    {
        $registry = app(B2bConnectorRegistry::class);

        $this->assertSame('ardon', $registry->keyForSites(['https://www.ardon.pl/']));
        $this->assertSame('Ardon', $registry->label('ardon'));
        $this->assertTrue($registry->requiresPassword('ardon'));
        $account = B2bAccount::query()->create(['username' => 'jan@example.com', 'password' => 'sekret', 'sites' => ['https://www.ardon.pl/']]);
        $connector = $registry->make($account, 0);
        $this->assertInstanceOf(ArdonB2bConnector::class, $connector);
        $this->assertNotInstanceOf(B2bManufacturerSite::class, $connector);
    }

    private function client(): ArdonB2bClient
    {
        return new ArdonB2bClient('jan@example.com', 'dobre-haslo', 0, static function (int $ms): void {});
    }

    private function connector(): ArdonB2bConnector
    {
        $connector = new ArdonB2bConnector($this->client());
        $connector->login();

        return $connector;
    }

    private function account(): B2bAccount
    {
        return B2bAccount::query()->firstOrCreate(
            ['username' => 'jan@example.com'],
            ['password' => 'dobre-haslo', 'sites' => ['https://www.ardon.pl/'], 'connector' => 'ardon', 'sync_images' => false],
        )->fresh();
    }

    /**
     * @return array<string, B2bRemoteProduct>
     */
    private function productsBySku(ArdonB2bConnector $connector): array
    {
        $out = [];
        foreach ($connector->products() as $product) {
            $out[$product->sku] = $product;
        }

        return $out;
    }

    /**
     * @param  list<string>  $rows
     */
    private static function csv(array $rows): string
    {
        return "\xEF\xBB\xBF".self::HEADER."\r\n".implode("\r\n", $rows)."\r\n";
    }

    /** Strona sklepu z odnośnikiem wylogowania (zalogowany) albo bez. */
    private static function shell(string $body, bool $loggedIn): string
    {
        $account = $loggedIn
            ? '<a href="https://www.ardon.pl/eshop/user/sign-out">Wyloguj</a>'
            : '<a href="https://www.ardon.pl/ajax/modal-sign-form">Zaloguj</a>';

        return '<!DOCTYPE html><html><head><meta charset="utf-8"></head><body><nav>'.$account.'</nav>'.$body.'</body></html>';
    }

    private static function signInPage(): string
    {
        // jak w sklepie: formularz wyszukiwarki z własnym tokenem stoi przed formularzem logowania
        return self::shell(
            '<form action="https://www.ardon.pl/eshop/user/sign-in?returnKey=abc" method="post" id="frm-searchForm-form">'
            .'<input type="hidden" name="_token_" value="token-wyszukiwarki"></form>'
            .'<form class="form" action="https://www.ardon.pl/eshop/user/sign-in?returnKey=abc" method="post" id="frm-userSignInForm-form">'
            .'<input type="email" name="email"><input type="password" name="password">'
            .'<input type="hidden" name="_token_" value="token-logowania">'
            .'<input type="hidden" name="_do" value="userSignInForm-form-submit"></form>',
            false,
        );
    }

    /**
     * @param  array{mpn: string, name: string, brand: string, description: string, parameters: array<string, string>, files: list<string>}  $p
     */
    private static function productPage(array $p, bool $loggedIn): string
    {
        $jsonLd = json_encode([
            '@context' => 'http://schema.org', '@type' => 'Product', 'name' => $p['name'],
            'offers' => ['@type' => 'Offer', 'price' => 9.99, 'priceCurrency' => 'PLN'],
            'mpn' => $p['mpn'],
            'image' => 'https://www.ardon.cz/images/palette/shared/www/multimedia/products/'.$p['mpn'].'_001.jpg.webp',
            'brand' => ['@type' => 'Thing', 'name' => $p['brand']],
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        $breadcrumb = json_encode(['@context' => 'http://schema.org', '@type' => 'BreadcrumbList', 'itemListElement' => [
            ['@type' => 'ListItem', 'position' => 1, 'item' => ['@id' => 'https://www.ardon.pl/produkty/mezczyzni', 'name' => 'Mężczyźni']],
            ['@type' => 'ListItem', 'position' => 2, 'item' => ['@id' => 'https://www.ardon.pl/produkty/rekawice-robocze', 'name' => 'Rękawice robocze']],
            ['@type' => 'ListItem', 'position' => 3, 'item' => ['@id' => 'https://www.ardon.pl/produkty/rekawice-robocze-powlekane', 'name' => 'Rękawice robocze powlekane']],
            ['@type' => 'ListItem', 'position' => 4, 'item' => ['@id' => 'https://www.ardon.pl/x', 'name' => $p['name']]],
        ]], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        $tab = static fn (string $title, string $content): string => '<div class="accordion__tab switchable"><div class="accordion__container">'
            .'<div class="accordion__title switchable__trigger"><h3 class="h3">'.$title.'</h3><img class="accordion__icon" alt="Ikona toggle"></div>'
            .'<div class="accordion__content">'.$content.'</div></div></div>';
        $rows = '';
        foreach ($p['parameters'] as $name => $value) {
            $rows .= '<tr><th class="text-wrap parameters-width">'.$name.':</th><td class="table__text--right"> '.$value.'</td></tr>';
        }
        $files = '';
        foreach ($p['files'] as $i => $file) {
            $files .= '<li><a class="list__link" href="https://www.ardon.pl/eshop/download/product-attachment?id='.(100 + $i).crc32($p['mpn']).'">'.$file.'</a><span>(PDF, 186 kB)</span></li>';
        }

        return self::shell(
            '<script type="application/ld+json">'.$jsonLd.'</script>'
            .'<script type="application/ld+json">'.$breadcrumb.'</script>'
            .'<h1>'.$p['name'].'</h1>'
            .'<section class="product-detail-accordion"><div class="accordion">'
            .$tab('Opis i informacje techniczne', '<div class="list-arrow">'.$p['description'].'</div>'
                .'<div class="product-icon-line"><div class="product-icon-line__item"><a href="https://www.ardon.pl/wyjasnienia-piktogramow/"><img alt="GRIPtech"></a></div></div>')
            .$tab('Parametry', '<table class="table table-align-block">'.$rows.'</table>')
            .$tab('Do pobrania', '<ul class="list">'.$files.'</ul>')
            .'</div></section>',
            $loggedIn,
        );
    }

    /**
     * @return array<string, array{mpn: string, name: string, brand: string, description: string, parameters: array<string, string>, files: list<string>}>
     */
    private static function pages(): array
    {
        return [
            '/rekawice-a5001' => [
                'mpn' => 'A5001', 'name' => 'Rękawice testowe ARDON®ALFA', 'brand' => 'ARDON s.r.o.',
                'description' => '<p style="text-align: justify;">Rękawice powlekane lateksem.</p><ul><li><strong>materiał:</strong> PES + bawełna</li>'
                    .'<li><strong>normy i certyfikaty:</strong><br>• EN 388:2016 + A1:2018: 3242X</li></ul>',
                'parameters' => ['Środowisko' => 'Suchy', 'Rozmiar' => '08, 09, 10'],
                'files' => ['A5001-KT-PL.pdf', 'A5001-TDS-EN.pdf', 'A5001-TL-CZ.pdf', 'A5001-PoS-DoC-uni.pdf'],
            ],
            '/rekawice-a50010' => [
                'mpn' => 'A50010', 'name' => 'Inny wyrób', 'brand' => 'ARDON s.r.o.', 'description' => '<p>Inny.</p>',
                'parameters' => [], 'files' => [],
            ],
            '/atg-r-nbr-lite-24-985' => [
                'mpn' => 'A3031', 'name' => 'ATG® NBR-Lite® 24-985', 'brand' => 'ATG HAND CARE (PVT) LTD.',
                'description' => '<p>Rękawice nitrylowe.</p>', 'parameters' => ['Rozmiar' => '07, 08'],
                'files' => ['A3031-KT-PL.pdf', 'A3031-DZ2026-PL.pdf'],
            ],
            '/wkladki-przeciwhalasowe-3m-1100' => [
                'mpn' => 'C1020', 'name' => 'Wkładki przeciwhałasowe 3M 1100', 'brand' => '3M Česko, spol. s r.o. ',
                'description' => '<ul><li>zátkový chránič sluchu s oblým predným koncom</li><li>mäkká hypoalergénna PU pena</li><li>norma: EN 352-2</li></ul>',
                'parameters' => ['Zabezpieczenia wtyczek' => 'Jednorazowe', 'Producent' => '3M'],
                'files' => ['C1020-PoS-DoC-2025-uni.pdf'],
            ],
            '/okulary-univet-506up' => [
                'mpn' => 'E4085', 'name' => 'Okulary UNIVET 506UP zielone', 'brand' => 'UNIVET s.r.l.',
                'description' => '<p>Okulary ochronne.</p>', 'parameters' => [], 'files' => [],
            ],
            '/rekawice-spawalnicze-i4999' => [
                'mpn' => 'I4999', 'name' => 'Rękawice spawalnicze testowe', 'brand' => 'HARPS Investment Asia Pte. Ltd.',
                'description' => '<p>Rękawice spawalnicze.</p>', 'parameters' => [], 'files' => ['I4999-DoC-EN.pdf'],
            ],
            // uprzęże o kodach „FA1020…” — po zalogowaniu wyszukiwarka podaje je dla „A1020” i „A2010”
            '/uprzaz-fa1020600a' => ['mpn' => 'I4001', 'name' => 'Uprząż FA1020600A', 'brand' => 'Kratos Safety', 'description' => '', 'parameters' => [], 'files' => []],
            '/uprzaz-fa1020300' => ['mpn' => 'I4002', 'name' => 'Uprząż FA1020300', 'brand' => 'Kratos Safety', 'description' => '', 'parameters' => [], 'files' => []],
            '/uprzaz-fa1020700' => ['mpn' => 'I4024', 'name' => 'Uprząż FA1020700', 'brand' => 'Kratos Safety', 'description' => '', 'parameters' => [], 'files' => []],
            '/lina-fa2010010' => ['mpn' => 'I4039', 'name' => 'Lina FA2010010', 'brand' => 'Kratos Safety', 'description' => '', 'parameters' => [], 'files' => []],
            '/rekawice-mechanik' => [
                'mpn' => 'A1020', 'name' => 'Rękawice wzmacniane skórą ARDON®MECHANIK', 'brand' => 'ARDON s.r.o.',
                'description' => '<p>Rękawice robocze.</p>', 'parameters' => [], 'files' => [],
            ],
            '/rekawice-glen' => [
                'mpn' => 'A2010', 'name' => 'Rękawice spawalnicze ARDON®GLEN', 'brand' => 'ARDON s.r.o.',
                'description' => '<p>Rękawice spawalnicze.</p>', 'parameters' => [], 'files' => [],
            ],
            '/filtr-sr-510' => [
                'mpn' => 'F8000', 'name' => 'Filtr SR 510', 'brand' => 'Sundström Safety AB',
                'description' => '<p>Filtr.</p>', 'parameters' => ['Producent' => 'Sundströma'], 'files' => [],
            ],
            '/kalosze-nightfish' => [
                'mpn' => 'G3300', 'name' => 'Kalosze robocze ARDON®NIGHTFISH OB', 'brand' => 'Dikamar S.A.',
                'description' => '<p>Kalosze.</p>', 'parameters' => [], 'files' => [],
            ],
            '/rekawice-alphatec' => [
                'mpn' => 'A9500', 'name' => 'Rękawice chemiczne AlphaTec® 37-676', 'brand' => 'ANSELL HEALTHCARE EUROPE N.V.',
                'description' => '<p>Rękawice chemiczne.</p>', 'parameters' => [], 'files' => [],
            ],
        ];
    }

    /**
     * Podpowiedzi wyszukiwarki (kolejność jak w sklepie — pierwsza bywa innym wyrobem): adres i zdjęcie podpowiedzi;
     * zdjęcie wyrobu nazywa się kodem („A1020_001.jpg.webp”), zdjęcia uprzęży — swoim kodem.
     *
     * @return list<array{0: string, 1: string}>
     */
    private function suggestions(string $query): array
    {
        if (in_array($query, $this->searchMisses, true)) {
            return [];
        }
        $image = static fn (string $code): string => 'https://www.ardon.cz/images/palette/shared/www/multimedia/products/'.$code.'_001.39686828.jpg.webp';
        $harnesses = [
            ['/uprzaz-fa1020600a', $image('I4001')],
            ['/uprzaz-fa1020300', $image('I4002')],
            ['/uprzaz-fa1020700', $image('I4024')],
            ['/lina-fa2010010', $image('I4039')],
        ];

        return match ($query) {
            'A5001' => [['/rekawice-a50010', ''], ['/rekawice-a5001', '']],
            'A3031' => [['/atg-r-nbr-lite-24-985', '']],
            'C1020' => [['/wkladki-przeciwhalasowe-3m-1100', '']],
            'E4085' => [['/okulary-univet-506up', '']],
            'I4999' => [['/rekawice-spawalnicze-i4999', '']],
            // właściwy wyrób dopiero piąty, ale ma zdjęcie nazwane swoim kodem
            'A1020' => [...$harnesses, ['/rekawice-mechanik', $image('A1020')]],
            // po kodzie wyrobu w ogóle nie ma — jest dopiero po nazwie z cennika
            'A2010' => $harnesses,
            'Rękawice spawalnicze ARDON®GLEN' => [['/rekawice-glen', $image('A2010')]],
            'F8000' => [['/filtr-sr-510', '']],
            'G3300' => [['/kalosze-nightfish', '']],
            'A9500' => [['/rekawice-alphatec', '']],
            default => [],
        };
    }

    private function fakeSite(): void
    {
        Http::fake(function (Request $request) {
            $url = $request->url();
            $path = (string) parse_url($url, PHP_URL_PATH);
            preg_match('/PHPSESSID=(sess\d+)/', $request->header('Cookie')[0] ?? '', $m);
            $loggedIn = isset($m[1]) && in_array($m[1], $this->validSessions, true);

            if ($path === '/eshop/user/sign-in' && $request->method() === 'GET') {
                return Http::response(self::signInPage(), 200, ['Set-Cookie' => 'PHPSESSID=anon; path=/; HttpOnly']);
            }
            if ($path === '/eshop/user/sign-in' && $request->method() === 'POST') {
                $data = $request->data();
                // jak sklep: złe dane = ta sama strona logowania z HTTP 200, bez sesji konta
                if (($data['email'] ?? null) !== 'jan@example.com' || ($data['password'] ?? null) !== 'dobre-haslo'
                    || ($data['_token_'] ?? null) !== 'token-logowania') {
                    return Http::response(self::signInPage(), 200);
                }
                $this->logins++;
                $this->validSessions[] = 'sess'.$this->logins;

                return Http::response(self::shell('<h1>Strona główna</h1>', true), 200, ['Set-Cookie' => 'PHPSESSID=sess'.$this->logins.'; path=/; HttpOnly']);
            }
            if ($path === '/eshop/download/price-list-csv') {
                if ($this->dropSessionsOnPriceList && $this->logins === 1) {
                    $this->validSessions = [];
                    $loggedIn = false;
                }
                if (! $loggedIn || $this->priceListAlwaysHtml) {
                    // bez sesji sklep odsyła stronę logowania, nie plik
                    return Http::response(self::signInPage(), 200, ['Content-Type' => 'text/html; charset=utf-8']);
                }

                return Http::response(self::csv($this->rows), 200, [
                    'Content-Type' => 'text/csv;charset=UTF-8',
                    'Content-Disposition' => 'attachment; filename="cennik.csv"',
                ]);
            }
            if ($path === '/full-text/query') {
                parse_str((string) parse_url($url, PHP_URL_QUERY), $query);

                return Http::response(['categories' => [], 'products' => array_map(
                    static fn (array $p): array => ['label' => 'x', 'url' => 'https://www.ardon.pl'.$p[0], 'image' => $p[1]],
                    $this->suggestions((string) ($query['query'] ?? '')),
                ), 'articles' => [], 'advices' => []]);
            }
            if (isset(self::pages()[$path])) {
                return Http::response(self::productPage(self::pages()[$path], $loggedIn && ! $this->pagesWithoutSignOut), 200, ['Content-Type' => 'text/html; charset=utf-8']);
            }
            if ($path === '/eshop/download/product-attachment') {
                // każdy plik z inną treścią — zapis plików rozpoznaje powtórzony plik po sumie kontrolnej
                // jak w sklepie: załączniki idą jako application/octet-stream, nie application/pdf
                return Http::response("%PDF-1.4\n".$url."\n%%EOF", 200, ['Content-Type' => 'application/octet-stream']);
            }
            if (str_starts_with($url, 'https://www.ardon.cz/images/')) {
                return Http::response(self::JPEG, 200, ['Content-Type' => 'image/jpeg']);
            }

            return Http::response('nieznany adres w teście: '.$url, 404);
        });
    }
}
