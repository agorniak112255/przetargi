<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Jobs\TranslateB2bProductTextJob;
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
use App\Services\B2b\B2bForeignTextCards;
use App\Services\B2b\B2bGroupsSizes;
use App\Services\B2b\B2bImageGallery;
use App\Services\B2b\B2bManufacturerBrands;
use App\Services\B2b\B2bManufacturerSite;
use App\Services\B2b\B2bManufacturerSiteBrands;
use App\Services\B2b\B2bRemoteDocument;
use App\Services\B2b\B2bRemoteProduct;
use App\Services\B2b\B2bShopFieldNormSource;
use App\Services\B2b\B2bShopFieldSource;
use App\Services\B2b\B2bSizePriceSource;
use App\Services\B2b\HultaforsB2bClient;
use App\Services\B2b\HultaforsB2bConnector;
use App\Services\B2b\SafetyJoggerB2bConnector;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use RuntimeException;
use Tests\TestCase;

/**
 * Łącznik partnerportal.hultaforsgroup.pl na atrapie portalu (Http::fake). Kształt stron odwzorowuje zalogowane konto
 * z 01.10.2026: formularz logowania z tokenem (#loginform), sesja w ciasteczku, strona bez sesji = strona logowania;
 * lista i tabele pozycji z POST /pl/Catalog/ProductUpdateList (pola nawigacji .js-productlist_navigation_data, kafelki
 * „hover-product” z blokami „hover-product-…” w środku, wiersze „<tr\n class="productlistrow"”); dwa układy strony
 * wyrobu — Snickers (zakładki ładowane osobno, tabela pozycji sekcji 464 stronami po 100, kolor w matrycy, zakładka
 * certyfikacji) i Hellberg/Hultafors (zakładki w stronie, tabele 522 z kolumnami technicznymi i 533 z EAN-em i danymi
 * transportowymi); pliki /Image/GetDocument/… bez sesji = strona HTML z kodem 200.
 *
 * Wszystkie dane (kody, ceny, nazwy, opisy, EAN-y) są SYNTETYCZNE.
 */
final class HultaforsConnectorTest extends TestCase
{
    use RefreshDatabase;

    private const USER = 'zakupy@example.test';

    private const PASSWORD = 'dobre-haslo';

    private const FORM_TOKEN = 'tok-formularza';

    /** @var list<array{code: string, brand: string, url: string, price: string, range: string}> kafelki listy */
    private array $tiles = [];

    /** @var array<string, string> ścieżka strony wyrobu → HTML (bez otoczki konta) */
    private array $pages = [];

    /** @var array<string, string> kod wyrobu → HTML zakładek ładowanych osobno */
    private array $tabs = [];

    /** @var array<string, list<array<string, mixed>>> kod wyrobu → wiersze tabeli pozycji (sekcja 464) */
    private array $tables = [];

    /** @var array<string, int> kod wyrobu → licznik tabeli inny niż liczba wierszy */
    private array $tableTotals = [];

    /** @var list<string> ścieżki stron, które odpowiadają błędem 500 */
    private array $brokenPages = [];

    /** @var list<string> */
    private array $sessions = [];

    private int $logins = 0;

    /** @var list<array<string, mixed>> */
    private array $loginBodies = [];

    /** Sesja wygasa po tylu zapytaniach konta (0 = nigdy). */
    private int $expireAfter = 0;

    private int $calls = 0;

    /** Portal odrzuca każdą sesję (logowanie się udaje, ale każda strona konta to strona logowania). */
    private bool $sessionsRefused = false;

    /** Licznik listy inny niż prawdziwy (null = prawdziwy). */
    private ?int $reportedTotal = null;

    protected function setUp(): void
    {
        parent::setUp();
        Queue::fake();
    }

    public function test_login_sends_the_form_token_and_credentials_and_a_wrong_password_is_not_fatal(): void
    {
        $this->fakeSite();
        $this->client()->login();

        $this->assertSame(self::FORM_TOKEN, $this->loginBodies[0]['__RequestVerificationToken'] ?? null);
        $this->assertSame(self::USER, $this->loginBodies[0]['User.UserName'] ?? null);
        $this->assertSame(self::PASSWORD, $this->loginBodies[0]['User.Password'] ?? null);
        $this->assertSame('/', $this->loginBodies[0]['ReturnUrl'] ?? null);

        $wrong = new HultaforsB2bClient(self::USER, 'zle-haslo', 0, static function (int $ms): void {});
        try {
            $wrong->login();
            $this->fail('logowanie złym hasłem powinno się nie udać');
        } catch (B2bFatalException $e) {
            $this->fail('złe hasło to nie błąd krytyczny: '.$e->getMessage());
        } catch (RuntimeException $e) {
            $this->assertStringContainsString('nie potwierdził zalogowania', $e->getMessage());
        }
    }

    public function test_helpers_read_portal_amounts_and_english_text(): void
    {
        $this->assertSame(1267.5, HultaforsB2bConnector::amount("1\u{00A0}267,50 zł"));
        $this->assertSame(667.55, HultaforsB2bConnector::amount(' 667,55 zł '));
        $this->assertSame(0.0, HultaforsB2bConnector::amount('0,00 zł'));
        $this->assertNull(HultaforsB2bConnector::amount('12,50 EUR'));
        $this->assertNull(HultaforsB2bConnector::amount('Cena od 15,97 zł'));

        $this->assertTrue(HultaforsB2bConnector::isEnglishText('Pre-bent sleeves for freedom of movement'));
        $this->assertFalse(HultaforsB2bConnector::isEnglishText('Noise Protection Level'), 'krótka nazwa bez słów funkcyjnych');
        $this->assertFalse(HultaforsB2bConnector::isEnglishText('Kieszenie na nakolanniki'), 'polski bez polskich liter');
        $this->assertFalse(HultaforsB2bConnector::isEnglishText('Wodoodporne cholewki z membraną GORE-TEX® for outdoor and indoor'), 'polskie litery');
    }

    public function test_snickers_model_is_one_card_with_colour_size_rows_prices_fields_files_and_images(): void
    {
        $this->addTrousers();
        $this->fakeSite();
        $connector = $this->connector();

        $card = iterator_to_array($connector->products(), false)[0];

        $this->assertSame('ok', $card->raw['status'], (string) ($card->raw['reason'] ?? ''));
        $this->assertSame('9001', $card->sku);
        $this->assertSame('90010404044', $card->remoteId);
        $this->assertSame('Spodnie TEST robocze', $card->name);
        $this->assertSame('Spodnie TEST robocze Snickers Workwear 9001', $card->cardName);
        $this->assertSame('Snickers Workwear', $connector->manufacturer($card));
        $this->assertSame('Snickers Workwear > Spodnie', $card->category);
        $this->assertSame('https://partnerportal.hultaforsgroup.pl/pl/product/snickers-workwear-trousers/spodnie-test--9001', $card->sourceUrl);

        // pozycja 0,00 zł poza kartą; rozmiar specjalny (gwiazdka) z własną ceną
        $members = array_column($card->members, null, 'remote_id');
        $this->assertSame(['90010404044', '90010404046', '90019504044'], array_column($card->members, 'remote_id'));
        $this->assertSame('0404 - Black\Black / 44', $members['90010404044']['size']);
        $this->assertSame('0404 - Black\Black / 46*', $members['90010404046']['size']);
        $this->assertSame('9504 - Navy\Black / 44', $members['90019504044']['size']);
        $this->assertSame('Spodnie TEST rozm.: 44, 0404 - Black\Black', $members['90010404044']['name']);
        $this->assertSame([667.55, 845.0, 21.0], [$members['90010404044']['price']->net, $members['90010404044']['price']->base, $members['90010404044']['price']->discountPercent]);
        $this->assertSame([1001.33, 1267.5], [$members['90010404046']['price']->net, $members['90010404046']['price']->base]);
        $this->assertSame('Brak na stanie, dostawa 26.11.2026', $members['90010404046']['availability']);
        $this->assertSame(667.55, $connector->price($card)?->net);
        $this->assertSame('Kolory: 0404 - Black\Black, 9504 - Navy\Black; rozmiary: 44, 46*', $card->variantSummary);
        $this->assertSame('Na stanie: 0404 - Black\Black / 44, 9504 - Navy\Black / 44; Brak na stanie, dostawa 26.11.2026: 0404 - Black\Black / 46*', $card->availability);
        $this->assertSame(
            ['source_code=9001', 'ean=5900000000011', 'ean=5900000000028', 'ean=5900000000035'],
            array_map(static fn ($i): string => $i->type.'='.$i->value, $card->identifiers),
        );

        // opis: sekcje przed nagłówkiem „Rozmiar”, dosłownie; bez uwag portalu o rozmiarach specjalnych
        $this->assertSame("Wytrzymałe spodnie robocze z workami kieszeniowymi.\nKieszenie na nakolanniki wzmocnione materiałem.", $connector->description($card));
        $this->assertFalse($connector->hasForeignDescription($card));

        $fields = array_map(static fn ($f): string => $f->section.' | '.$f->name.' | '.$f->value, $connector->shopFields($card));
        $this->assertContains('Opis wyrobu | Rozmiar | 44-64; 146-162', $fields);
        $this->assertContains('Opis wyrobu | Material | Główny: 97% poliamid, 3% elastan', $fields);
        $this->assertContains('Opis wyrobu | Oznaczenia | Prać w pralce w temperaturze 40ºC.; Kieszenie na nakolanniki', $fields, 'długie objaśnienie materiału nie jest oznaczeniem');
        $this->assertContains('Certyfikacja | Kategoria CE | Kategoria II', $fields);
        $this->assertContains('Certyfikacja | Norma | EN ISO 20471 Klasa 2', $fields);
        $this->assertContains('Certyfikacja | EN ISO 20471: Odzież o intensywnej widzialności | Klasa 2', $fields);
        $this->assertEmpty(array_filter($fields, static fn (string $f): bool => str_contains($f, 'Launch')));
        $this->assertStringNotContainsString('Wytrzymałe', implode("\n", $fields));

        $this->assertSame(
            [
                ['9001 EU Declaration of Conformity', 'https://partnerportal.hultaforsgroup.pl/Image/GetDocument/pl/502/9001%20eu%20declaration%20of%20conformity.pdf', ProductDocument::KIND_CERTIFICATE],
                ['Tabela rozmiarów', 'https://partnerportal.hultaforsgroup.pl/Image/GetDocument/pl/14628', ProductDocument::KIND_SIZE_CHART],
            ],
            array_map(static fn (B2bRemoteDocument $d): array => [$d->title, $d->sourceUrl, $d->kind], $connector->documents($card)),
            'bez szablonu znakowania i deklaracji UKCA',
        );
        $this->assertSame(
            ['https://partnerportal.hultaforsgroup.pl/pl/image/getthumbnail/111?version=1&s=013', 'https://partnerportal.hultaforsgroup.pl/pl/image/getthumbnail/112?version=1&s=013'],
            $connector->imageUrls($card),
        );
    }

    public function test_hellberg_layout_joins_both_tables_and_marks_english_text_for_translation(): void
    {
        $this->addHelmets();
        $this->fakeSite();
        $connector = $this->connector();

        $card = iterator_to_array($connector->products(), false)[0];

        $this->assertSame('E9001', $card->sku);
        $this->assertSame('Hellberg Safety', $connector->manufacturer($card));
        $this->assertSame(['32001-001', '32002-001'], array_column($card->members, 'remote_id'));
        $this->assertSame(['Test Helmet Yellow', 'Test Helmet White'], array_column($card->members, 'size'), 'bez rozmiaru i matrycy — opis pozycji');
        $this->assertSame(
            ['source_code=E9001', 'ean=7390000000017', 'ean=7390000000024'],
            array_map(static fn ($i): string => $i->type.'='.$i->value, $card->identifiers),
            'EAN z drugiej tabeli',
        );
        $this->assertSame('Warianty: Test Helmet Yellow, Test Helmet White', $card->variantSummary);
        $this->assertTrue($connector->hasForeignDescription($card));
        $this->assertNull($card->cardName, 'nazwa po angielsku zostaje dosłowna — do tłumaczenia');

        $fields = array_map(static fn ($f): string => $f->section.' | '.$f->name.' | '.$f->value, $connector->shopFields($card));
        $this->assertContains('Dane techniczne pozycji | Waga | 350 g', $fields, 'wartość wspólna wszystkich pozycji — raz');
        $this->assertContains('Dane techniczne pozycji | Kolor | Test Helmet Yellow: żółty; Test Helmet White: biały', $fields);
        $this->assertEmpty(array_filter($fields, static fn (string $f): bool => str_contains($f, 'transport')), 'dane transportowe pomijane');
        $this->assertSame(
            ['User Manual Test Helmet'],
            array_map(static fn (B2bRemoteDocument $d): string => $d->title, $connector->documents($card)),
            'przewodnik w innym języku (DE) pomijany',
        );
    }

    public function test_second_product_with_the_same_positions_and_products_without_price_or_page_are_skipped_with_reasons(): void
    {
        $this->addHelmets(withTwin: true);
        $this->addUnpricedJacket();
        $this->tiles[] = ['code' => 'H9999', 'brand' => 'Hultafors', 'url' => '', 'price' => '29,90 zł', 'range' => '999999|999999'];
        $this->fakeSite();
        $connector = $this->connector();

        $products = array_column(iterator_to_array($connector->products(), false), null, 'sku');

        $this->assertSame('ok', $products['E9001']->raw['status']);
        $this->assertSame('te same pozycje co karta E9001', $products['E9002']->raw['reason']);
        $this->assertSame('żadna pozycja wyrobu nie ma ceny konta', $products['9002']->raw['reason']);
        $this->assertSame('kafelek listy bez strony wyrobu', $products['H9999']->raw['reason']);
        $this->assertSame('E9002', $products['E9002']->remoteId, 'pominięty wyrób bez pozycji karty');
        $this->expectExceptionMessage('te same pozycje');
        $connector->price($products['E9002']);
    }

    public function test_positions_of_a_product_whose_page_failed_do_not_move_to_a_later_product(): void
    {
        $this->addHelmets(withTwin: true);
        $this->brokenPages[] = '/pl/product/hellberg-safety-helmet/test-helmet-yellow--E9001';
        $this->fakeSite();

        $products = array_column(iterator_to_array($this->connector()->products(), false), null, 'sku');

        $this->assertStringStartsWith('strona wyrobu:', $products['E9001']->raw['reason']);
        $this->assertStringContainsString('pozycje wspólne z wyrobem E9001', $products['E9002']->raw['reason']);
    }

    public function test_position_table_is_read_in_pages_of_100_and_an_incomplete_table_skips_the_product(): void
    {
        $this->addTrousers(sizes: 105);
        $this->fakeSite();
        $card = iterator_to_array($this->connector()->products(), false)[0];

        $this->assertCount(105, $card->members);
        $tableCalls = Http::recorded(static fn (Request $r): bool => str_contains($r->url(), 'ProductUpdateList') && ($r->data()['listvalue'] ?? '') === '9001');
        $this->assertSame(['1', '2'], $tableCalls->map(static fn (array $call): string => (string) $call[0]->data()['page'])->values()->all());
        $this->assertSame('100', (string) $tableCalls->first()[0]->data()['PageSize']);

        $this->tableTotals['9001'] = 106;
        $skipped = iterator_to_array($this->connector()->products(), false)[0];
        $this->assertSame('skipped', $skipped->raw['status']);
        $this->assertStringContainsString('tabela pozycji 9001 niepełna (105 z 106)', $skipped->raw['reason']);
    }

    public function test_list_reads_every_page_once_retries_a_changed_total_once_and_stops_without_any_price(): void
    {
        $this->addTrousers();
        $this->addHelmets(withTwin: true);
        $this->addUnpricedJacket();
        $this->fakeSite();
        $connector = new HultaforsB2bConnector($this->client(), pageSize: 2);

        $codes = array_map(static fn (B2bRemoteProduct $p): string => $p->sku, iterator_to_array($connector->products(), false));

        $this->assertSame(['9001', '9002', 'E9001', 'E9002'], $codes, 'w kolejności kodu');
        $this->assertSame(4, $connector->totalProducts());
        $this->assertContains('Lista Hultafors Group: 4 wyrobów (Snickers Workwear 2, Hellberg Safety 2)', $connector->runSummary());

        $this->reportedTotal = 5;
        try {
            iterator_to_array((new HultaforsB2bConnector($this->client(), pageSize: 2))->products(), false);
            $this->fail('lista z innym licznikiem niż liczba wyrobów');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString('niespójna także po ponownym pobraniu', $e->getMessage());
        }

        $this->reportedTotal = null;
        foreach ($this->tiles as $index => $tile) {
            $this->tiles[$index]['price'] = '';
        }
        $this->expectException(B2bFatalException::class);
        iterator_to_array($this->connector()->products(), false);
    }

    public function test_expired_session_is_renewed_once_and_a_session_never_restored_is_fatal(): void
    {
        $this->addTrousers();
        $this->fakeSite();
        $connector = $this->connector();
        $this->expireAfter = 2;

        $card = iterator_to_array($connector->products(), false)[0];

        $this->assertSame('ok', $card->raw['status'], (string) ($card->raw['reason'] ?? ''));
        $this->assertSame(2, $this->logins);

        $this->sessionsRefused = true;
        $this->expectException(B2bFatalException::class);
        iterator_to_array($this->connector()->products(), false);
    }

    public function test_files_and_images_are_fetched_with_the_session_and_an_html_page_is_not_a_file(): void
    {
        $this->addTrousers();
        $this->fakeSite();
        $connector = $this->connector();
        $card = iterator_to_array($connector->products(), false)[0];

        $file = $connector->documentBytes($connector->documents($card)[0]);
        $image = $connector->image($card);

        $this->assertSame('application/pdf', $file['mime']);
        $this->assertStringStartsWith('%PDF-', $file['bytes']);
        $this->assertSame('image/jpeg', $image?->mime);

        $this->expectExceptionMessage('nie wydał pliku');
        $connector->documentBytes(new B2bRemoteDocument('x', 'https://partnerportal.hultaforsgroup.pl/Image/GetDocument/pl/9/brak.pdf'));
    }

    public function test_sync_creates_cards_of_each_brand_with_size_rows_norms_documents_images_and_a_second_run_changes_nothing(): void
    {
        Storage::fake('public');
        Storage::fake('local');
        $this->addTrousers();
        $this->addHelmets(withTwin: true, withCertification: true);
        $this->fakeSite();

        $result = app(B2bAccountSyncRunner::class)->run($this->account(), delayMs: 0, withImages: true);

        $this->assertSame(2, $result['created'], implode(' | ', $result['errors']));
        $trousers = Product::query()->where('sku', '9001')->sole();
        $this->assertSame('Snickers Workwear', $trousers->manufacturer);
        $this->assertSame('Spodnie TEST robocze Snickers Workwear 9001', $trousers->name);
        $this->assertEqualsCanonicalizing(
            ['90010404044', '90010404046', '90019504044'],
            B2bProductLink::query()->where('product_id', $trousers->id)->pluck('remote_id')->all(),
        );
        $slot = ProductSourcePrice::query()->where('product_id', $trousers->id)->sole();
        $this->assertSame('667.55', (string) $slot->purchase_price);
        $this->assertSame('845.00', (string) $slot->catalog_price_net);
        $this->assertSame('1001.33', (string) $slot->size_price_max);
        $this->assertSame(
            ['0404 - Black\Black / 44' => '667.55', '0404 - Black\Black / 46*' => '1001.33', '9504 - Navy\Black / 44' => '667.55'],
            ProductVariant::query()->where('product_id', $trousers->id)->where('kind', ProductVariant::KIND_SIZE)->orderBy('label')->pluck('purchase_price', 'label')->map(static fn ($p): string => (string) $p)->all(),
        );
        $this->assertSame('hultafors', $trousers->manufacturer_norms['source']['connector'] ?? null);
        $this->assertContains(['label' => 'EN ISO 20471', 'value' => 'Klasa 2'], $trousers->manufacturer_norms['rows'] ?? []);
        $this->assertSame(
            ['9001 EU Declaration of Conformity', 'Tabela rozmiarów'],
            ProductDocument::query()->where('product_id', $trousers->id)->orderBy('sort_order')->pluck('title')->all(),
        );
        $this->assertSame(2, ProductImage::query()->where('product_id', $trousers->id)->count());
        $this->assertSame(
            ['5900000000011', '5900000000028', '5900000000035'],
            ProductIdentifier::query()->where('product_id', $trousers->id)->where('type', ProductIdentifier::TYPE_EAN)->orderBy('value')->pluck('value')->all(),
        );

        // druga marka grupy: normy z tabelki jak u marki głównej, opis do tłumaczenia
        $helmet = Product::query()->where('sku', 'E9001')->sole();
        $this->assertSame('Hellberg Safety', $helmet->manufacturer);
        $this->assertSame('Test Helmet Yellow', $helmet->name);
        $this->assertSame('Hellberg Safety', $helmet->manufacturer_norms['source']['brand'] ?? null);
        $this->assertContains(['label' => 'EN 397', 'value' => 'Spełnia'], $helmet->manufacturer_norms['rows'] ?? [], json_encode($helmet->manufacturer_norms, JSON_UNESCAPED_UNICODE));
        Queue::assertPushed(TranslateB2bProductTextJob::class, static fn (TranslateB2bProductTextJob $job): bool => $job->productId === (int) $helmet->id && $job->translateName);
        Queue::assertNotPushed(TranslateB2bProductTextJob::class, static fn (TranslateB2bProductTextJob $job): bool => $job->productId === (int) $trousers->id);
        $this->assertNull(Product::query()->where('sku', 'E9002')->first(), 'wyrób z tymi samymi pozycjami nie dostaje karty');

        $shop = collect(ProductShopCard::query()->where('product_id', $trousers->id)->sole()->fields)->flatMap(static fn (array $section): array => array_map(
            static fn (array $row): string => ($section['section'] ?? '').' | '.$row['name'].' | '.$row['value'],
            $section['rows'] ?? [],
        ))->all();
        $this->assertContains('Certyfikacja | Norma | EN ISO 20471 Klasa 2', $shop);

        $before = $this->snapshot();
        $second = app(B2bAccountSyncRunner::class)->run($this->account(), delayMs: 0, withImages: true);

        $this->assertSame(0, $second['created'], implode(' | ', $second['errors']));
        $this->assertSame(0, $second['updated'], implode(' | ', $second['errors']));
        $this->assertSame(2, $second['unchanged'], implode(' | ', $second['errors']));
        $this->assertSame($before, $this->snapshot());
    }

    public function test_registry_knows_the_portal_and_all_its_brands_except_short_keys(): void
    {
        $registry = app(B2bConnectorRegistry::class);

        $this->assertSame('hultafors', $registry->keyForSites(['https://partnerportal.hultaforsgroup.pl/user/login?ReturnUrl=%2f']));
        $this->assertSame('Hultafors Group', $registry->label('hultafors'));
        $this->assertTrue($registry->isManufacturerSite('hultafors'));
        $this->assertTrue($registry->groupsSizes('hultafors'));
        $this->assertTrue($registry->sendsSizePrices('hultafors'));
        $brands = $registry->brandsForKey('hultafors');
        foreach (['Hultafors Group', 'Snickers Workwear', 'Hultafors', 'Hellberg Safety', 'Solid Gear', 'Emma Safety Footwear', 'CLC'] as $brand) {
            $this->assertContains($brand, $brands);
        }
        $this->assertNotContains('W.steps', $brands, 'klucz marki „w” pasowałby do każdej marki na W');
        $source = $registry->shopFieldNormSource('hultafors');
        $this->assertSame('Snickers Workwear', $source['brand'] ?? null);
        $this->assertSame(['Norma'], $source['names'] ?? null);
        $this->assertContains('Hellberg Safety', $source['brands'] ?? []);

        $account = B2bAccount::query()->create([
            'username' => self::USER, 'password' => 'sekret', 'sites' => ['https://partnerportal.hultaforsgroup.pl/user/login?ReturnUrl=%2f'],
        ]);
        $connector = $registry->make($account, 0);

        $this->assertInstanceOf(HultaforsB2bConnector::class, $connector);
        foreach ([B2bManufacturerSite::class, B2bManufacturerBrands::class, B2bShopFieldSource::class, B2bShopFieldNormSource::class, B2bDocumentSource::class, B2bImageGallery::class, B2bGroupsSizes::class, B2bSizePriceSource::class, B2bForeignTextCards::class] as $interface) {
            $this->assertInstanceOf($interface, $connector);
        }
    }

    public function test_manufacturer_site_brands_match_cards_of_every_own_brand_and_keep_single_brand_sites_unchanged(): void
    {
        $this->assertSame('Hellberg Safety', B2bManufacturerSiteBrands::matching(HultaforsB2bConnector::class, 'HELLBERG SAFETY'));
        $this->assertSame('Snickers Workwear', B2bManufacturerSiteBrands::matching(HultaforsB2bConnector::class, 'Snickers'));
        $this->assertNull(B2bManufacturerSiteBrands::matching(HultaforsB2bConnector::class, 'W.steps'));
        $this->assertNull(B2bManufacturerSiteBrands::matching(HultaforsB2bConnector::class, 'Uvex'));

        $this->assertSame(['Safety Jogger'], B2bManufacturerSiteBrands::all(SafetyJoggerB2bConnector::class));
        $this->assertSame('Safety Jogger', B2bManufacturerSiteBrands::matching(SafetyJoggerB2bConnector::class, 'SAFETY JOGGER'));
        $this->assertSame([], B2bManufacturerSiteBrands::all(\stdClass::class), 'nie witryna producenta');
        $this->assertSame(
            ['brand' => 'Safety Jogger', 'names' => ['Norma']],
            app(B2bConnectorRegistry::class)->shopFieldNormSource('safetyjogger'),
            'witryna jednej marki — kształt bez zmian',
        );
    }

    // ---- pomocnicze ----

    private function client(): HultaforsB2bClient
    {
        return new HultaforsB2bClient(self::USER, self::PASSWORD, 0, static function (int $ms): void {});
    }

    private function connector(): HultaforsB2bConnector
    {
        $connector = new HultaforsB2bConnector($this->client());
        $connector->login();

        return $connector;
    }

    private function account(): B2bAccount
    {
        return B2bAccount::query()->firstOrCreate(
            ['username' => self::USER],
            ['password' => self::PASSWORD, 'sites' => ['https://partnerportal.hultaforsgroup.pl/user/login?ReturnUrl=%2f'], 'connector' => 'hultafors', 'sync_images' => true],
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
     * Spodnie Snickers (układ z zakładkami ładowanymi osobno): dwa kolory w matrycy, rozmiar 44 w obu, rozmiar
     * specjalny 46* tylko w czarnym (droższy, z dostawą), granatowy 46* bez ceny (0,00 zł). $sizes > 0 — tyle
     * rozmiarów czarnego koloru (tabela na kilka stron).
     */
    private function addTrousers(int $sizes = 0): void
    {
        $path = '/pl/product/snickers-workwear-trousers/spodnie-test--9001';
        $this->tiles[] = ['code' => '9001', 'brand' => 'Snickers Workwear', 'url' => $path, 'price' => '667,55 zł', 'range' => '90010404044|90010404044'];
        $rows = [
            self::row('90010404044', 'Spodnie TEST rozm.: 44', '5900000000011', '44', true, '02.10.2026', '845,00 zł', '21,00', '667,55 zł'),
            self::row('90010404046', 'Spodnie TEST rozm.: 46', '5900000000028', '46*', false, '26.11.2026', '1 267,50 zł', '21,00', '1 001,33 zł'),
            self::row('90019504044', 'Spodnie TEST rozm.: 44', '5900000000035', '44', true, '02.10.2026', '845,00 zł', '21,00', '667,55 zł'),
            self::row('90019504046', 'Spodnie TEST rozm.: 46', '5900000000042', '46*', false, '26.11.2026', '0,00 zł', '21,00', '0,00 zł'),
        ];
        $matrix = ['90010404044' => 0, '90019504044' => 1, '90010404046' => 0, '90019504046' => 1];
        if ($sizes > 0) {
            $rows = [];
            $matrix = [];
            for ($i = 0; $i < $sizes; $i++) {
                $code = sprintf('9001040%04d', $i);
                $rows[] = self::row($code, 'Spodnie TEST rozm.: '.$i, sprintf('59%011d', $i), (string) (100 + $i), true, '02.10.2026', '845,00 zł', '21,00', '667,55 zł');
                $matrix[$code] = 0;
            }
        }
        $this->tables['9001'] = $rows;

        $this->pages[$path] = '<ol class="breadcrumb">'
            .'<li data-nodeid="0"><a data-nodeid=&quot;0&quot; href="/pl/catalog/node/snickers-workwear1">Snickers Workwear</a></li>'
            .'<li data-nodeid="0"><a href="/pl/catalog/node/snickers-workwear-trousers">Spodnie</a></li>'
            .'<li data-nodeid="0"><a href="/pl/catalog/node/snickers-workwear-trousers-trousers">Spodnie</a></li>'
            .'<li data-nodeid=""><a href="'.$path.'">Spodnie TEST&#160;robocze</a></li></ol>'
            .self::gallery([111, 112])
            .self::section(432, 'ProductDetail_Price', 'ProductDetail/_Price', '<h2 class="product-netprice netprice-lowest"><span class="amount">667,55 zł</span></h2>')
            .self::section(431, 'ProductDetail_Header', 'ProductDetail/_Header', '<h1 class="page-header">Spodnie TEST&#160;robocze</h1>')
            .self::description(433, 'Wytrzymałe spodnie robocze z workami kieszeniowymi.')
            .self::description(6810, '')
            .self::description(659, 'Kieszenie na nakolanniki wzmocnione materiałem.')
            .self::header(1056, 'Rozmiar')
            .self::description(488, '44-64; 146-162')
            .self::section(1045, 'Content', 'Content/_ContentDetail', '<p><a href="/Image/GetDocument/pl/14628" target="_blank" data-type="document">Zobacz tabelę rozmiarów</a></p>')
            .self::section(1066, 'Content', 'Content/_ContentDetail', '<p><em>* Odzież w rozmiarach specjalnych jest dostępna do zamówień.</em></p>')
            .self::header(1057, 'Material')
            .self::description(489, 'Główny: 97% poliamid, 3% elastan')
            .self::icons(495, ['Prać w&#160;pralce w&#160;temperaturze 40&#186;C.', 'Kieszenie na nakolanniki', str_repeat('An extremely tough and hardwearing material used to reinforce exposed parts. ', 2)])
            .'<section id="section_461" class="section section_ProductDetailTabs section_461 product-details-tabs" data-lazysection-routevalues="{&quot;sectionid&quot;:461}" data-lazyload="true" data-sectionurl=/pl/ProductDetail/TabsSection/9001?sectionid=461 data-action="ProductDetail/TabsSection" data-sectionid="461" data-view="ProductDetail/_PDP_Integrated_Tabs"></section>';

        $colours = ['0404 - Black\Black', '9504 - Navy\Black'];
        $this->tabs['9001'] = self::tabPane('Dodaj do koszyka', self::relationSection(464, '9001', count($rows), ''))
            .self::tabPane('Przelacz na widok matrycowy', self::matrix($colours, $matrix))
            .self::tabPane('Pliki i dokumenty', self::documents([
                ['9001 Profiling', '/Image/GetDocument/pl/501/9001%20profiling.pdf'],
                ['9001 EU Declaration of Conformity', '/Image/GetDocument/pl/502/9001%20eu%20declaration%20of%20conformity.pdf'],
                ['9001 UKCA Declaration of Conformity', '/Image/GetDocument/pl/503/9001%20ukca%20declaration%20of%20conformity.pdf'],
            ]))
            .self::tabPane('Certification', self::definitions(4008, [
                ['Kategoria CE:', 'Kategoria&#160;II'],
                ['EN&#160;ISO 20471: Odzież o intensywnej widzialności:', 'Klasa 2'],
            ]).self::definitions(4287, [['Launch Season:', 'AW']]));
    }

    /**
     * Kask Hellberg (układ z zakładkami w stronie): dwa kolory jako osobne pozycje bez rozmiaru, tabela 522 z kolumnami
     * technicznymi i 533 z EAN-em i danymi transportowymi, wstęp po angielsku. $withTwin — drugi wyrób (inny kolor
     * na liście) z tymi samymi pozycjami.
     */
    private function addHelmets(bool $withTwin = false, bool $withCertification = false): void
    {
        $products = ['E9001' => ['test-helmet-yellow', 'Test Helmet Yellow']];
        if ($withTwin) {
            $products['E9002'] = ['test-helmet-white', 'Test Helmet White'];
        }
        foreach ($products as $code => [$slug, $title]) {
            $path = '/pl/product/hellberg-safety-helmet/'.$slug.'--'.$code;
            $this->tiles[] = ['code' => $code, 'brand' => 'Hellberg Safety', 'url' => $path, 'price' => 'Cena od 223,30 zł', 'range' => '32001-001|32002-001'];
            $technical = [
                self::row('32001-001', 'Test Helmet Yellow', '', '', true, '02.10.2026', '319,00 zł', '30,00', '223,30 zł', ['Waga' => '350 g', 'Kolor' => 'żółty']),
                self::row('32002-001', 'Test Helmet White', '', '', false, '19.10.2026', '329,00 zł', '30,00', '230,30 zł', ['Waga' => '350 g', 'Kolor' => 'biały']),
            ];
            $logistics = [
                self::row('32001-001', 'Test Helmet Yellow', '7390000000017', '', true, '02.10.2026', '319,00 zł', '30,00', '223,30 zł', ['Waga transportowa' => '0.5 kg']),
                self::row('32002-001', 'Test Helmet White', '7390000000024', '', false, '19.10.2026', '329,00 zł', '30,00', '230,30 zł', ['Waga transportowa' => '0.5 kg']),
            ];
            $this->pages[$path] = '<ol class="breadcrumb"><li><a href="/pl/catalog/node/hellberg">Hellberg</a></li><li><a href="/pl/catalog/node/hellberg-safety-helmet">Safety Helmet</a></li><li><a href="'.$path.'">'.$title.'</a></li></ol>'
                .self::gallery([221])
                .self::section(4891, 'ProductDetail_Header', 'ProductDetail/_Header', '<h1 class="page-header">'.$title.'</h1>')
                .self::description(4892, 'Hellberg Test is a professional safety helmet designed for comfort and protection.')
                .self::description(4893, 'Regulacja pokrętłem')
                .self::section(4900, 'ProductDetailTabs', 'ProductDetail/_PDP_Integrated_Tabs', '<ul class="nav nav-pills"><li>Dodaj do koszyka</li></ul><div class="tab-content">')
                .self::tabPane('Dodaj do koszyka', self::relationSection(522, $code, 2, self::table($technical)))
                .self::tabPane('Pliki i dokumenty', self::documents([
                    ['User Manual Test Helmet', '/Image/GetDocument/pl/601/user%20manual%20test%20helmet.pdf'],
                    ['App Getting Started Guide DE', '/Image/GetDocument/pl/602/app%20getting%20started%20guide%20de.pdf'],
                ]))
                .self::tabPane('Distribution Information', self::relationSection(533, $code, 2, self::table($logistics)))
                .($withCertification ? self::tabPane('Certification', self::definitions(4008, [['EN 397: Przemysłowe hełmy ochronne:', 'Spełnia']])) : '')
                .'</div>';
        }
    }

    /** Kurtka Snickers bez ceny konta (wszystkie pozycje 0,00 zł) — tabela w zakładkach ładowanych osobno. */
    private function addUnpricedJacket(): void
    {
        $path = '/pl/product/snickers-workwear-high-vis/kurtka-test--9002';
        $this->tiles[] = ['code' => '9002', 'brand' => 'Snickers Workwear', 'url' => $path, 'price' => '', 'range' => ''];
        $this->tables['9002'] = [self::row('90025504003', 'Kurtka TEST rozm.: XS', '5900000000103', 'XS', true, '02.10.2026', '0,00 zł', '21,00', '0,00 zł')];
        $this->pages[$path] = self::section(431, 'ProductDetail_Header', 'ProductDetail/_Header', '<h1 class="page-header">Winter Jacket Test</h1>')
            .'<section id="section_461" class="section section_ProductDetailTabs section_461 product-details-tabs" data-lazyload="true" data-sectionurl=/pl/ProductDetail/TabsSection/9002?sectionid=461 data-action="ProductDetail/TabsSection" data-sectionid="461" data-view="ProductDetail/_PDP_Integrated_Tabs"></section>';
        $this->tabs['9002'] = self::tabPane('Dodaj do koszyka', self::relationSection(464, '9002', 1, ''));
    }

    // ---- znaczniki portalu ----

    /**
     * @param  array<string, string>  $attributes  etykieta kolumny → wartość
     * @return array<string, mixed>
     */
    private static function row(string $code, string $name, string $ean, string $size, bool $available, string $date, string $gross, string $discount, string $net, array $attributes = []): array
    {
        return compact('code', 'name', 'ean', 'size', 'available', 'date', 'gross', 'discount', 'net', 'attributes');
    }

    private static function section(int $id, string $type, string $view, string $inner, string $classes = ''): string
    {
        return '<section id="section_'.$id.'" class="section section_'.$type.' section_'.$id.' '.$classes.'" data-lazysection-routevalues="{&quot;sectionid&quot;:'.$id.'}" data-lazyload="false"  data-action="X" data-sectionid="'.$id.'" data-view="'.$view.'">'
            ."\n".$inner."\n</section>\n";
    }

    private static function description(int $id, string $text): string
    {
        if ($text === '') {
            return self::section($id, 'ProductDetail_Description', 'ProductDetail/_Description', '<input type="hidden" class="section-has-no-data" value="'.$id.'" />');
        }

        return self::section($id, 'ProductDetail_Description', 'ProductDetail/_Description',
            '<div class="row"><div class="col-xs-12"><div id="textshort'.$id.'"><p>'.$text.'</p></div><div style="visibility:hidden;max-height:1px;"  id="textlong'.$id.'" ></div></div></div>'
            ."\n<style>\n    #more-information-link {\n        font-weight: bold;\n    }\n</style>");
    }

    private static function header(int $id, string $text): string
    {
        return self::section($id, 'TranslationText', 'System/_TranslationText_p', "<p>\n        ".$text."\n    </p>", 'pdp-description-header');
    }

    /**
     * @param  list<string>  $titles
     */
    private static function icons(int $id, array $titles): string
    {
        $html = '';
        foreach ($titles as $index => $title) {
            $html .= '<div class="attribute-image-hor pull-left"><img data-toggle="tooltip" data-placement="top" title="" data-original-title="'.$title.'" src="/pl/image/getthumbnail/'.(900 + $index).'?width=50&amp;height=50&amp;version=1&amp;s=013" alt="" /></div>';
        }

        return self::section($id, 'ProductAttributes', 'ProductDetail/_AttributePlaceholder_Images', $html);
    }

    /**
     * @param  list<int>  $ids
     */
    private static function gallery(array $ids): string
    {
        $slides = '';
        foreach ($ids as $id) {
            $slides .= '<div><a href="/pl/image/getthumbnail/'.$id.'?version=1&amp;s=013"><img src="/pl/image/getthumbnail/'.$id.'?width=600&amp;height=600&amp;version=1&amp;s=013" data-id="'.$id.'" alt="" /></a></div>';
        }

        return self::section(430, 'ProductImageLibrary', 'ProductDetail/_ImageLibrary',
            '<section class=" product-slider-section hidden"><div class="product-slider"><div class="slider-for main" data-id="g">'.$slides
            .'</div></div><div class="download-product-image"></div></section>');
    }

    private static function tabPane(string $title, string $inner): string
    {
        return '<div class="tab-pane " id="g'.crc32($title).'" ><h3 class="hidden-lg">'.$title.'</h3><div class="content">'
            .'<input type="hidden" id="CurrentUserName" value="'.self::USER.'" /><div>'.$inner.'</div></div></div>';
    }

    private static function relationSection(int $id, string $code, int $total, string $rows): string
    {
        return self::section($id, 'ProductRelationList', 'Catalog/ProductLists/_ProductRelation',
            '<section class="products section-should-show" data-sectionid="'.$id.'"><div id="productlist">'
            .self::navigation(['productListParentType' => '2', 'sectionId' => (string) $id, 'total' => (string) $total, 'ExactTextSearch' => 'False', 'ErpProductsOnly' => 'False', 'listvalue' => $code, 'RelationType' => '4 Virtual', 'id' => $code])
            .'<div class="product-nav"><input type="hidden" name="SelectedListTypeModel" value="Table" /></div>'.$rows.'</div></section>');
    }

    /**
     * @param  array<string, string>  $fields
     */
    private static function navigation(array $fields): string
    {
        $html = '<div class="js-productlist_navigation_data">';
        foreach ($fields as $name => $value) {
            $html .= "\n".'<input type="hidden" name="'.$name.'" value="'.$value.'" />';
            if ($name === 'total') {
                $html .= "\n".'<input type="hidden" name="UniqueNodeIdentifier" />';
            }
        }

        return $html."\n</div>";
    }

    /**
     * @param  list<array<string, mixed>>  $rows
     */
    private static function table(array $rows): string
    {
        $attributes = [];
        foreach ($rows as $row) {
            foreach (array_keys($row['attributes']) as $label) {
                $attributes[$label] = true;
            }
        }
        $labels = array_keys($attributes);
        $withEan = array_filter($rows, static fn (array $row): bool => $row['ean'] !== '') !== [];
        $withSize = array_filter($rows, static fn (array $row): bool => $row['size'] !== '') !== [];
        $head = '<th class="col-xs-4 field-desc">Produkt</th><th class="field-stockcode hidden-xs print-visible">Kod artykułu</th>'
            .($withEan ? '<th class="hidden-xs attr-field-641">EAN</th>' : '')
            .($withSize ? '<th class="hidden-xs attr-field-2">Rozmiar</th>' : '');
        foreach ($labels as $index => $label) {
            $head .= '<th class="hidden-xs attr-field-'.(300 + $index).'">'.$label.'</th>';
        }
        $head .= '<th class="field-avail">Na stanie</th><th class="hidden-xs field-deldate">Data dostawy</th><th class="header-field-price col-xs-4"><div class="">Cena</div></th><th class="col-xs-3 "></th>';
        $body = '';
        foreach ($rows as $row) {
            $cells = '<td class="field-desc"> '.$row['name'].' </td><td class="field-stockcode hidden-xs print-visible nowrap "> '.$row['code'].' </td>'
                .($withEan ? '<td class="hidden-xs attr-field-641"> '.$row['ean'].' </td>' : '')
                .($withSize ? '<td class="hidden-xs attr-field-2"> '.$row['size'].' </td>' : '');
            foreach ($labels as $index => $label) {
                $cells .= '<td class="hidden-xs attr-field-'.(300 + $index).'"> '.($row['attributes'][$label] ?? '').' </td>';
            }
            $cells .= '<td class="field-avail"><span class="js-avail-wrapper"><span class="fa fa-circle fa-lg '.($row['available'] ? 'text-available' : 'text-notavailable').'" title="'.($row['available'] ? 'Tak' : 'Nie').'"></span></span></td>'
                .'<td class="field-deldate"> '.$row['date'].' </td>'
                .'<td class="field-price"><div><div class="js-price-wrapper"><p class="product-grossprice hover-product-price " style="margin-bottom: 0;" aria-label="Gross price"> '.str_replace(' ', "\u{00A0}", $row['gross']).' </p>'
                .'<p class="product-discount hover-product-price" style="margin-bottom: 0;" aria-label="Gross price discount"><span class="product-discount-lbl">Rabat:</span> <span class="product-discount-percentage">'.$row['discount'].'%</span></p>'
                .'<h4 class="product-netprice hover-product-price " aria-label="Net price"> '.str_replace(' ', "\u{00A0}", $row['net']).' <span class="price-unit-code"></span></h4></div></div></td>'
                .'<td class=" field-addtobasket"><button class="btn js-addToBasket" data-stockcode="'.$row['code'].'" type="button"></button></td>';
            $body .= "\n    <tr \n        class=\"productlistrow pt_product product brandid-1  type-erp\" title=\"\"\n         data-productid=\"1\" data-productstockcode=\"".$row['code']."\">\n".$cells."\n</tr>";
        }

        return '<table class="table"><thead><tr>'.$head.'</tr></thead><tbody>'.$body.'</tbody></table>';
    }

    /**
     * @param  list<string>  $colours
     * @param  array<string, int>  $positions  kod artykułu → kolumna koloru
     */
    private static function matrix(array $colours, array $positions): string
    {
        $head = '<tr class="add-to-basket-matrix-table-header sticky-header"><td><h5>Rozmiar:</h5></td>';
        foreach ($colours as $colour) {
            $head .= '<th style="border-top: none;"><img title="'.$colour.'" alt="'.$colour.'" src="/pl/image/getthumbnail/1?version=1&amp;s=013" /></th>';
        }
        $rows = '';
        $index = 0;
        foreach (array_chunk($positions, count($colours), true) as $chunk) {
            $cells = array_fill(0, count($colours), '<td></td>');
            foreach ($chunk as $code => $column) {
                $cells[$column] = '<td><div class="input-group"><input value="0" name="Products['.$index.'].Quantity" type="text" /></div><input type="hidden" name="Products['.$index.'].StockCode" value="'.$code.'" /><p class="hidden">'.$code.'</p></td>';
                $index++;
            }
            $rows .= '<tr><th><h5>r</h5></th>'.implode('', $cells).'</tr>';
        }

        return self::section(469, 'AddToBasket', 'Basket/_AddToBasket_Matrix', '<div class="js-add-to-basket-by-attribute-matrix "><table class="table add-to-basket-matrix-table"><tbody>'.$head.'</tr>'.$rows.'</tbody></table></div>');
    }

    /**
     * @param  list<array{0: string, 1: string}>  $files  tytuł, adres
     */
    private static function documents(array $files): string
    {
        $rows = '';
        foreach ($files as [$title, $href]) {
            $rows .= '<tr><td class="icon text-center"><i class="fa fa-file-pdf-o fa-lg" aria-hidden="true"></i></td><td>'."\n    ".$title."\n".'</td><td><a class="document btn" target="_blank" rel="pdf" href="'.$href.'">Pobierz</a></td></tr>';
        }

        return self::section(472, 'ProductDocuments', 'ProductDetail/_DocumentsTable', '<table class="document-table"><tbody>'.$rows.'</tbody></table>');
    }

    /**
     * @param  list<array{0: string, 1: string}>  $pairs
     */
    private static function definitions(int $id, array $pairs): string
    {
        $html = '<div class="product-additional-content"><dl class="dl-horizontal">';
        foreach ($pairs as [$term, $definition]) {
            $html .= '<dt>'.$term.'</dt><dd>'.$definition.'</dd>';
        }

        return self::section($id, 'ProductAttributes', 'ProductDetail/_AttributePlaceholders_Single_List', $html.'</dl></div>');
    }

    private function listFragment(int $page, int $pageSize): string
    {
        $total = $this->reportedTotal ?? count($this->tiles);
        $html = self::navigation(['productListParentType' => '1', 'sectionId' => '23', 'total' => (string) $total, 'listvalue' => '303', 'RelationType' => '4 Virtual'])
            .'<input type="hidden" name="TotalPages" value="'.max(1, (int) ceil($total / max(1, $pageSize))).'" /><ul class="product-list">';
        foreach (array_slice($this->tiles, ($page - 1) * $pageSize, $pageSize) as $index => $tile) {
            $link = $tile['url'] !== '' ? '<a href="'.$tile['url'].'" class="js-product-detail"><div class="hover-product-additonal-details"></div></a>' : '';
            $title = $tile['url'] !== '' ? '<a href="'.$tile['url'].'" class="js-product-detail" aria-label="Test product detail page">Nazwa '.$tile['code'].'</a>' : 'Nazwa '.$tile['code'];
            $price = $tile['price'] !== '' ? '<h4 class="product-netprice hover-product-price netprice-lowest"> '.$tile['price'].' </h4>' : '';
            $html .= '<li class="product type-vp"><div class="hover-product brandid-1 pt_product" data-productid="'.(1000 + $index).'" data-productstockcode="'.$tile['code'].'">'
                .'<div class="hover-product-image-wrapper" style="position: relative"><img class="hover-product-image" src="/pl/image/getthumbnail/1?width=300" alt="" />'
                .'<div class="hover-product-additional"><div class="hover-product-hover-bg"></div>'.$link.'</div></div>'
                .'<h5 class="product-brandname product-brandid-1"> '.$tile['brand'].' </h5>'
                .'<h3 class="hover-product-title"><span class="field-stockcode">'.$tile['code'].'</span> '.$title.'</h3>'
                .'<div class="pull-right pricepanel"><div class="js-price-wrapper"><input type="hidden" id="lowestandhighestvirtualproduct" value="'.$tile['range'].'" />'.$price.'</div></div></div></li>';
        }

        return $html.'</ul>';
    }

    private function tableFragment(string $code, int $page, int $pageSize): string
    {
        $rows = $this->tables[$code] ?? [];
        $total = $this->tableTotals[$code] ?? count($rows);

        return self::navigation(['productListParentType' => '2', 'sectionId' => '464', 'total' => (string) $total, 'listvalue' => $code, 'RelationType' => '4 Virtual', 'id' => $code])
            .'<input type="hidden" name="TotalPages" value="'.max(1, (int) ceil($total / max(1, $pageSize))).'" />'
            .self::table(array_slice($rows, ($page - 1) * $pageSize, $pageSize));
    }

    private static function accountPage(string $body): string
    {
        return '<!DOCTYPE html><html><head><title>Index</title></head><body><input type="hidden" name="PAGEID" value="2" />'
            .'<input type="hidden" id="CurrentUserName" value="'.self::USER.'" /><main>'.$body.'</main></body></html>';
    }

    private static function loginPage(): string
    {
        return '<!DOCTYPE html><html><head><title>One Master - Login</title></head><body><input type="hidden" id="CurrentUserName" />'
            .'<form action="/user/login" class="form-horizontal" id="loginform" method="post">'
            .'<input name="__RequestVerificationToken" type="hidden" value="'.self::FORM_TOKEN.'" />'
            .'<input id="ReturnUrl" name="ReturnUrl" type="hidden" value="/" />'
            .'<input class="form-control input-lg" id="User_UserName" name="User.UserName" type="text" value="" />'
            .'<input class="form-control input-lg" id="User_Password" name="User.Password" type="password" value="" /></form>'
            .'<form class="form-horizontal"><input name="__RequestVerificationToken" type="hidden" value="inny-token" /></form></body></html>';
    }

    // ---- atrapa portalu ----

    private function fakeSite(): void
    {
        Http::fake(function (Request $request) {
            $url = $request->url();
            $path = (string) parse_url($url, PHP_URL_PATH);
            $html = ['Content-Type' => 'text/html; charset=utf-8'];
            preg_match('/\.ASPXAUTH=([\w-]+)/', $request->header('Cookie')[0] ?? '', $m);
            $signedIn = ! $this->sessionsRefused && in_array($m[1] ?? '', $this->sessions, true);

            if (strtolower($path) === '/user/login') {
                if ($request->method() === 'POST') {
                    $data = $request->data();
                    $this->loginBodies[] = $data;
                    if (($data['__RequestVerificationToken'] ?? null) === self::FORM_TOKEN
                        && ($data['User.UserName'] ?? null) === self::USER && ($data['User.Password'] ?? null) === self::PASSWORD) {
                        $this->logins++;
                        $this->sessions[] = 's'.$this->logins;
                        $this->calls = 0;

                        // jak w portalu: przekierowanie na stronę główną konta z ciasteczkiem sesji
                        return Http::response(self::accountPage('<p>Zamówienia</p>'), 200, $html + ['Set-Cookie' => '.ASPXAUTH=s'.$this->logins.'; path=/; secure; HttpOnly']);
                    }

                    return Http::response(self::loginPage(), 200, $html);
                }

                return Http::response(self::loginPage(), 200, $html + ['Set-Cookie' => 'ASP.NET_SessionId=guest; path=/; secure; HttpOnly']);
            }

            if (str_starts_with($path, '/pl/image/getthumbnail/')) {
                // zdjęcia są publiczne
                return Http::response(self::jpeg($path), 200, ['Content-Type' => 'image/jpeg']);
            }

            // bez sesji: portal przekierowuje na logowanie — atrapa oddaje od razu stronę logowania
            if (! $signedIn) {
                return Http::response(self::loginPage(), 200, $html);
            }
            $this->calls++;
            if ($this->expireAfter > 0 && $this->calls > $this->expireAfter) {
                $this->expireAfter = 0;
                $this->sessions = [];

                return Http::response(self::loginPage(), 200, $html);
            }

            if (str_starts_with($path, '/Image/GetDocument/')) {
                if (str_contains($path, 'brak')) {
                    return Http::response(self::accountPage('<p>Nie znaleziono</p>'), 200, $html);
                }

                return Http::response('%PDF-1.4 test '.$path, 200, ['Content-Type' => 'application/pdf']);
            }
            if ($path === '/pl/catalog/node/products') {
                return Http::response(self::accountPage(self::navigation(['productListParentType' => '1', 'sectionId' => '23', 'total' => (string) count($this->tiles), 'ExactTextSearch' => 'False', 'ErpProductsOnly' => 'False', 'listvalue' => '303', 'RelationType' => '4 Virtual'])), 200, $html);
            }
            if ($path === '/pl/Catalog/ProductUpdateList' && $request->method() === 'POST') {
                $data = $request->data();
                $page = (int) ($data['page'] ?? 1);
                $pageSize = (int) ($data['PageSize'] ?? 12);
                if (($data['productListParentType'] ?? '') === '1') {
                    return Http::response($this->listFragment($page, $pageSize), 200, $html);
                }

                return Http::response($this->tableFragment((string) ($data['listvalue'] ?? ''), $page, $pageSize), 200, $html);
            }
            if (preg_match('#^/pl/ProductDetail/TabsSection/([\w\-]+)$#', $path, $t) === 1 && isset($this->tabs[$t[1]])) {
                return Http::response($this->tabs[$t[1]], 200, $html);
            }
            if (in_array($path, $this->brokenPages, true)) {
                return Http::response('Server Error', 500, $html);
            }
            if (isset($this->pages[$path])) {
                return Http::response(self::accountPage($this->pages[$path]), 200, $html);
            }

            return Http::response('', 404);
        });
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
