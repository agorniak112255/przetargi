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
use App\Services\B2b\B2bRemoteProduct;
use App\Services\B2b\B2bShopFieldNormSource;
use App\Services\B2b\B2bShopFieldSource;
use App\Services\B2b\VmFootwearB2bClient;
use App\Services\B2b\VmFootwearB2bConnector;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use RuntimeException;
use Tests\TestCase;

/**
 * Łącznik pl.b2b.vmfootwear.cz (AB Solutions, ERP Cézar) na atrapie sklepu (Http::fake). Znaczniki HTML odwzorowują
 * strony zalogowanego konta z 01.10.2026: formularz logowania (user, password, login=1) pod /Login_uzytkownika/,
 * gość przekierowany na stronę logowania, strona konta z odnośnikiem href="/uzytkownik/" w nagłówku; menu działów
 * (ul.navbar-nav), lista działu (div.product-card, paginacja „/obuv/2”), strona wyrobu (okruszki, cena „307,92 PLN”,
 * #colorOption, tabela „Opis produktu”, „Pliki do pobrania” z kartą techniczną ?d=pdf i plikami /file/{hash}/…,
 * galeria z data-zoom, rozmiary ze stanem i ciężarówką dostawy, zakładki #param_1 i #param_3). Zdjęcia sklep wydaje
 * bez Content-Type.
 *
 * Wszystkie dane (kody, ceny, nazwy, opisy) są SYNTETYCZNE.
 */
final class VmFootwearConnectorTest extends TestCase
{
    use RefreshDatabase;

    private const USER = 'zakupy@example.test';

    private const PASSWORD = 'dobre-haslo';

    /** @var array<string, list<array<string, mixed>>> dział → wyroby */
    private array $sections = [];

    /** @var array<string, array<string, mixed>> ścieżka strony wyrobu → wyrób */
    private array $products = [];

    /** @var list<string> ważne sesje */
    private array $sessions = [];

    private int $logins = 0;

    /** @var list<array<string, mixed>> */
    private array $loginBodies = [];

    /** Sesja wygasa po tylu stronach konta (0 = nigdy). */
    private int $expireAfterPages = 0;

    private int $pages = 0;

    private int $perPage = 2;

    protected function setUp(): void
    {
        parent::setUp();
        Queue::fake();
    }

    public function test_login_posts_the_form_and_confirms_the_account_page(): void
    {
        $this->fakeSite();
        $client = $this->client();

        $client->login();

        $this->assertTrue($client->isLoggedIn());
        $this->assertSame(['user' => self::USER, 'password' => self::PASSWORD, 'login' => '1'], $this->loginBodies[0]);
    }

    public function test_wrong_password_is_not_fatal(): void
    {
        $this->fakeSite();
        $client = new VmFootwearB2bClient(self::USER, 'zle-haslo', 0, static function (int $ms): void {});

        try {
            $client->login();
            $this->fail('logowanie złym hasłem powinno się nie udać');
        } catch (B2bFatalException $e) {
            $this->fail('złe hasło to nie błąd krytyczny: '.$e->getMessage());
        } catch (RuntimeException $e) {
            $this->assertStringContainsString('sprawdź e-mail i hasło', $e->getMessage());
        }
        $this->assertFalse($client->isLoggedIn());
    }

    public function test_price_text_is_parsed_with_thousands_and_hard_spaces(): void
    {
        $this->assertSame(['net' => 307.92, 'currency' => 'PLN'], VmFootwearB2bConnector::parsePrice("  307,92 PLN \n"));
        $this->assertSame(['net' => 1234.5, 'currency' => 'PLN'], VmFootwearB2bConnector::parsePrice("1\u{00A0}234,50 PLN"));
        $this->assertNull(VmFootwearB2bConnector::parsePrice('Cena:'));
        $this->assertNull(VmFootwearB2bConnector::parsePrice('0,00 PLN'));
    }

    public function test_document_kind_comes_from_the_button_and_the_file_name(): void
    {
        $this->assertSame(ProductDocument::KIND_CERTIFICATE, VmFootwearB2bConnector::documentKind('Declaration_of_conformity', 'https://pl.b2b.vmfootwear.cz/file/ab/EU_9100-O6_FO_SR.pdf'));
        $this->assertSame(ProductDocument::KIND_CERTIFICATE, VmFootwearB2bConnector::documentKind('EU_9100-S3.pdf', 'https://pl.b2b.vmfootwear.cz/file/ab/EU_9100-S3.pdf'));
        $this->assertSame(ProductDocument::KIND_MANUAL, VmFootwearB2bConnector::documentKind('Manual for Users', 'https://pl.b2b.vmfootwear.cz/file/ab/Manual_for_users.pdf'));
        $this->assertSame(ProductDocument::KIND_DATASHEET, VmFootwearB2bConnector::documentKind('Karta techniczna', 'https://pl.b2b.vmfootwear.cz/europa-polbuty-testowe/?d=pdf'));
    }

    public function test_model_page_becomes_one_card_with_sizes_stock_description_fields_files_and_image(): void
    {
        $this->addShoe();
        $this->addSocks();
        $this->fakeSite();
        $connector = $this->connector();

        $products = iterator_to_array($connector->products(), false);

        $this->assertSame(['9100-O6', '8800'], array_map(static fn (B2bRemoteProduct $p): string => $p->sku, $products));
        $card = $products[0];
        $this->assertSame('9100-O6', $card->remoteId);
        $this->assertSame('TESTOWO półbuty robocze', $card->name);
        $this->assertSame('Obuwie > Ouwie robocze > Półbuty', $card->category);
        $this->assertSame('https://pl.b2b.vmfootwear.cz/testowo-polbuty-robocze/', $card->sourceUrl);
        $this->assertSame('Rozmiary: 39, 40, 41', $card->variantSummary);
        $this->assertSame('Stan: 39: 24; 40: >50; 41: 0 (dostawa 9.10.2026)', $card->availability);
        $this->assertSame([], $card->members);
        $price = $connector->price($card);
        $this->assertSame(207.92, $price->net);
        $this->assertNull($price->base);
        $this->assertSame('PLN', $price->currency);
        $this->assertSame('VM Footwear', $connector->manufacturer($card));
        $this->assertSame(
            [[ProductIdentifier::TYPE_MANUFACTURER_CODE, '9100-O6', 'Kod produktu']],
            array_map(static fn ($i): array => [$i->type, $i->value, $i->field], $card->identifiers ?? []),
        );
        $this->assertSame(
            "cholewka: oddychająca tkanina\npodeszwa: EVA/GUMA – odporna na olej napędowy, antypoślizgowa\nnormy: EN ISO 20347:2022\nwersja: O6 FO SR – bez podnoska\nuwaga: odcienie mogą się różnić; nie do całodziennego stosowania!\nTestowa podeszwa®",
            $connector->description($card),
        );
        $fields = array_map(static fn ($f): string => $f->section.' | '.$f->name.' | '.$f->value, $connector->shopFields($card));
        $this->assertSame(
            [
                'Informacje ze sklepu VM Footwear | Kod produktu | 9100-O6',
                'Informacje ze sklepu VM Footwear | Rozmiary | 39, 40, 41',
                'Informacje ze sklepu VM Footwear | Oznaczenia | Promocja, Nowość',
                'Informacje ze sklepu VM Footwear | Promocja | TEST special price - 207,92 PLN',
                // właściwość ✗ (ESD) nie trafia do tabelki — tylko ✓
                'Właściwości | SR — Podeszwa antypoślizg. (posadzka ceramiczna) | tak',
                'Właściwości | WR — Wodoodporność | tak',
                'Właściwości | Normą | EN ISO 20347:2022',
                'Właściwości | Kategoria obuwia | O6',
                'Parametry biznesowe | Kod nomenklatury | 64041990',
                'Parametry biznesowe | Waga towaru | 1,60 kg',
            ],
            $fields,
        );
        $this->assertSame(
            [
                ['Karta techniczna', ProductDocument::KIND_DATASHEET, 'https://pl.b2b.vmfootwear.cz/testowo-polbuty-robocze/?d=pdf'],
                ['Declaration_of_conformity', ProductDocument::KIND_CERTIFICATE, 'https://pl.b2b.vmfootwear.cz/file/c0ffee02/EU_9100-O6_FO_SR.pdf'],
                ['Manual_for_users.pdf', ProductDocument::KIND_MANUAL, 'https://pl.b2b.vmfootwear.cz/file/c0ffee01/Manual_for_users.pdf'],
            ],
            array_map(static fn ($d): array => [$d->title, $d->kind, $d->sourceUrl], $connector->documents($card)),
        );
        $this->assertSame(['https://pl.b2b.vmfootwear.cz/image/aa11'], $connector->imageUrls($card));

        // akcesorium bez rozmiarów: stan z nagłówka strony, bez opisu wariantów
        $socks = $products[1];
        $this->assertNull($socks->variantSummary);
        $this->assertSame('Stan: >50', $socks->availability);
        $this->assertSame('Obuwie > Akcesoria', $socks->category);
        $this->assertSame(17.67, $connector->price($socks)->net);
        $this->assertNotContains('Właściwości | Normą | ', array_map(static fn ($f): string => $f->section.' | '.$f->name.' | '.$f->value, $connector->shopFields($socks)));

        $summary = implode("\n", $connector->runSummary());
        $this->assertStringContainsString('Lista VM Footwear: 2 modeli', $summary);
        $this->assertStringContainsString('Karty: 2', $summary);
    }

    public function test_all_list_pages_of_all_menu_sections_are_read(): void
    {
        for ($i = 1; $i <= 5; $i++) {
            $this->addShoe('/model-'.$i.'/', '91'.$i.'0-S3', 'obuv');
        }
        $this->addSocks();
        $this->fakeSite();

        $products = iterator_to_array($this->connector()->products(), false);

        $this->assertSame(['9110-S3', '9120-S3', '9130-S3', '9140-S3', '9150-S3', '8800'], array_map(static fn (B2bRemoteProduct $p): string => $p->sku, $products));
        $this->assertTrue(Http::recorded(fn (Request $r): bool => str_ends_with($r->url(), '/obuv/3'))->isNotEmpty());
    }

    public function test_expired_session_is_renewed_once(): void
    {
        $this->addShoe();
        $this->addSocks();
        $this->fakeSite();
        $connector = $this->connector();
        $this->expireAfterPages = 2;

        $products = iterator_to_array($connector->products(), false);

        $this->assertCount(2, $products);
        $this->assertSame(2, $this->logins);
    }

    public function test_image_without_content_type_is_recognised_and_a_login_page_is_not_saved_as_a_file(): void
    {
        $this->addShoe();
        $this->fakeSite();
        $connector = $this->connector();
        $card = iterator_to_array($connector->products(), false)[0];

        $image = $connector->imageAt($connector->imageUrls($card)[0]);
        $this->sessions = [];
        $file = $connector->documentBytes($connector->documents($card)[0]);

        $this->assertNotNull($image);
        $this->assertSame('image/jpeg', $image->mime);
        $this->assertSame('application/pdf', $file['mime']);
        $this->assertStringStartsWith('%PDF-', $file['bytes']);
        $this->assertSame(2, $this->logins);
    }

    public function test_model_without_a_price_is_skipped_with_a_reason_not_dropped(): void
    {
        $this->addShoe('/bez-ceny/', 'BEZ-1', 'obuv', price: null);
        $this->addSocks();
        $this->fakeSite();
        $connector = $this->connector();

        $products = iterator_to_array($connector->products(), false);

        $this->assertSame(['BEZ-1', '8800'], array_map(static fn (B2bRemoteProduct $p): string => $p->sku, $products));
        $this->assertSame('skipped', $products[0]->raw['status']);
        $this->assertSame([], $connector->shopFields($products[0]));
        $this->assertStringContainsString('Bez ceny konta (pominięte): 1, np. BEZ-1', implode("\n", $connector->runSummary()));
        $this->expectExceptionMessage('bez ceny konta');
        $connector->price($products[0]);
    }

    public function test_many_models_without_any_account_price_stop_the_run(): void
    {
        for ($i = 0; $i < 21; $i++) {
            $this->addShoe('/bez-ceny-'.$i.'/', 'BEZ-'.$i, 'obuv', price: null);
        }
        $this->perPage = 50;
        $this->fakeSite();

        $this->expectException(B2bFatalException::class);
        $this->expectExceptionMessage('bez ceny konta');

        iterator_to_array($this->connector()->products(), false);
    }

    public function test_sync_creates_the_card_with_norms_documents_and_image_and_a_second_run_changes_nothing(): void
    {
        Storage::fake('public');
        Storage::fake('local');
        $this->addShoe();
        $this->fakeSite();

        $result = app(B2bAccountSyncRunner::class)->run($this->account(), delayMs: 0, withImages: true);

        $this->assertSame(1, $result['created'], implode(' | ', $result['errors']));
        $card = Product::query()->where('sku', '9100-O6')->sole();
        $this->assertSame('VM Footwear', $card->manufacturer);
        $this->assertStringContainsString('cholewka: oddychająca tkanina', (string) $card->description);
        $this->assertSame(['9100-O6'], B2bProductLink::query()->where('product_id', $card->id)->pluck('remote_id')->all());
        $slot = ProductSourcePrice::query()->where('product_id', $card->id)->sole();
        $this->assertSame('207.92', (string) $slot->purchase_price);
        // sklep nie podaje ceny katalogowej — synchronizacja przyjmuje cenę konta (rabat 0), jak przy innych kontach
        $this->assertSame('207.92', (string) $slot->catalog_price_net);
        $this->assertSame('vmfootwear', $card->manufacturer_norms['source']['connector'] ?? null);
        $this->assertSame([['label' => 'EN ISO 20347:2022']], $card->manufacturer_norms['rows'] ?? null);
        $this->assertSame(
            ['Karta techniczna', 'Declaration_of_conformity', 'Manual_for_users.pdf'],
            ProductDocument::query()->where('product_id', $card->id)->orderBy('sort_order')->pluck('title')->all(),
        );
        $this->assertSame(1, ProductImage::query()->where('product_id', $card->id)->count());
        $rows = collect(ProductShopCard::query()->where('product_id', $card->id)->sole()->fields)->flatMap(static fn (array $section): array => array_map(
            static fn (array $row): string => ($section['section'] ?? '').' | '.$row['name'].' | '.$row['value'],
            $section['rows'] ?? [],
        ))->all();
        $this->assertContains('Właściwości | Normą | EN ISO 20347:2022', $rows);

        $before = $this->snapshot();
        $second = app(B2bAccountSyncRunner::class)->run($this->account(), delayMs: 0, withImages: true);

        $this->assertSame(0, $second['created'], implode(' | ', $second['errors']));
        $this->assertSame(0, $second['updated'], implode(' | ', $second['errors']));
        $this->assertSame(1, $second['unchanged'], implode(' | ', $second['errors']));
        $this->assertSame($before, $this->snapshot());
    }

    public function test_registry_detects_vm_footwear_by_host_as_the_manufacturer_site(): void
    {
        $registry = app(B2bConnectorRegistry::class);

        $this->assertSame('vmfootwear', $registry->keyForSites(['https://pl.b2b.vmfootwear.cz/Login_uzytkownika/']));
        $this->assertSame('VM Footwear', $registry->label('vmfootwear'));
        $this->assertTrue($registry->requiresPassword('vmfootwear'));
        $this->assertTrue($registry->isManufacturerSite('vmfootwear'));
        $this->assertSame(['brand' => 'VM Footwear', 'names' => ['Normą']], $registry->shopFieldNormSource('vmfootwear'));

        $account = B2bAccount::query()->create([
            'username' => self::USER, 'password' => 'sekret', 'sites' => ['https://pl.b2b.vmfootwear.cz/Login_uzytkownika/'],
        ]);
        $connector = $registry->make($account, 0);

        $this->assertInstanceOf(VmFootwearB2bConnector::class, $connector);
        foreach ([B2bManufacturerSite::class, B2bShopFieldSource::class, B2bShopFieldNormSource::class, B2bDocumentSource::class, B2bImageGallery::class] as $interface) {
            $this->assertInstanceOf($interface, $connector);
        }
    }

    // ---- pomocnicze ----

    private function client(): VmFootwearB2bClient
    {
        return new VmFootwearB2bClient(self::USER, self::PASSWORD, 0, static function (int $ms): void {});
    }

    private function connector(): VmFootwearB2bConnector
    {
        $connector = new VmFootwearB2bConnector($this->client());
        $connector->login();

        return $connector;
    }

    private function account(): B2bAccount
    {
        return B2bAccount::query()->firstOrCreate(
            ['username' => self::USER],
            ['password' => self::PASSWORD, 'sites' => ['https://pl.b2b.vmfootwear.cz'], 'connector' => 'vmfootwear', 'sync_images' => true],
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
            'shop_card' => ProductShopCard::query()->where('product_id', $p->id)->value('fields'),
            'images' => ProductImage::query()->where('product_id', $p->id)->orderBy('sort_order')->pluck('source_url')->all(),
            'documents' => ProductDocument::query()->where('product_id', $p->id)->orderBy('sort_order')->pluck('source_url')->all(),
            'price' => ProductSourcePrice::query()->where('product_id', $p->id)->get(['purchase_price', 'catalog_price_net', 'availability'])->toArray(),
        ]])->all();
    }

    /**
     * Półbut z trzema rozmiarami (41 brak w magazynie, dostawa w drodze), w promocji, z opisem w tabeli (wiersz
     * „uwaga” z dwiema komórkami wartości), plikami, zdjęciem i właściwościami ✓/✗.
     */
    private function addShoe(string $path = '/testowo-polbuty-robocze/', string $code = '9100-O6', string $section = 'obuv', ?string $price = '207,92 PLN'): void
    {
        $this->sections[$section][] = ['path' => $path, 'code' => $code, 'name' => 'TESTOWO półbuty robocze'];
        $this->products[$path] = [
            'name' => 'TESTOWO półbuty robocze',
            'code' => $code,
            'price' => $price,
            'crumbs' => [['/obuv/', 'Obuwie'], ['/pracovni-obuv/', 'Ouwie robocze'], ['/polobotka/', 'Półbuty']],
            'badges' => '<span class="badge bg-primary"><i class="fas me-1 fa-percent"></i>Promocja</span><span class="badge bg-success"><i class="fas me-1 fa-star"></i>Nowość</span>',
            'action' => $price !== null ? '<div class="product-badge fw-bold product-action-price mt-n1"><i class="fas fa-tag"></i>&nbsp;TEST special price - '.$price.'</div>' : '',
            'stock' => '&gt;50',
            'sizes' => [['39', '24', ''], ['40', '&gt;50', ''], ['41', '0', '9.10.2026']],
            'description' => '<table><tbody>'
                .'<tr><td><strong>cholewka</strong></td><td>oddychająca tkanina</td></tr>'
                ."<tr><td><strong>podeszwa</strong></td><td>EVA/GUMA&nbsp;– odporna na olej napędowy,\nantypoślizgowa</td></tr>"
                .'<tr><td><strong>normy</strong></td><td>EN ISO 20347:2022</td></tr>'
                .'<tr><td><strong>wersja</strong></td><td>O6 FO SR&nbsp;– bez podnoska</td></tr>'
                ."<tr><td><strong>uwaga</strong></td><td>odcienie mogą się różnić</td><td>nie do całodziennego\nstosowania!</td><td>&nbsp;</td></tr>"
                .'</tbody></table><p>Testowa podeszwa®</p>',
            'files' => '<a target="_blank" href="'.$path.'?d=pdf" class="btn btn-md my-1 btn-primary"><i class="fas fa-file-pdf"></i>&nbsp;Karta techniczna</a>'
                ."\n".'<a target="_blank" href="/file/c0ffee01/Manual_for_users.pdf" class="btn btn-md my-1 btn-primary"><i class="fas fa-file-download"></i>&nbsp;Manual_for_users.pdf <small class="text-white">(3,08MB)</small></a>'
                ."\n".'<a target="_blank" href="/file/c0ffee02/EU_9100-O6_FO_SR.pdf" class="btn btn-md my-1 btn-primary"><i class="fas fa-file-download"></i>&nbsp;Declaration_of_conformity <small class="text-white">(734,63kB)</small></a>',
            'image' => 'aa11',
            'properties' => [['SR', 'Podeszwa antypoślizg. (posadzka ceramiczna)', true], ['WR', 'Wodoodporność', true], ['ESD', 'antyelektrostatyczne właściwości ESD', false]],
            'values' => [['Normą', 'EN ISO 20347:2022'], ['Kategoria obuwia', 'O6']],
            'business' => [['Kod nomenklatury', '64041990'], ['Waga towaru', '1,60&nbsp;kg']],
        ];
    }

    /** Skarpety bez rozmiarów (jedna pozycja), stan tylko w nagłówku, bez plików i norm. */
    private function addSocks(): void
    {
        $path = '/testowe-skarpety/';
        $this->sections['prislusenstvi'][] = ['path' => $path, 'code' => '8800', 'name' => 'TEST skarpety funkcjonalne'];
        $this->products[$path] = [
            'name' => 'TEST skarpety funkcjonalne',
            'code' => '8800',
            'price' => '17,67 PLN',
            'crumbs' => [['/obuv/', 'Obuwie'], ['/prislusenstvi/', 'Akcesoria']],
            'badges' => '',
            'action' => '',
            'stock' => '&gt;50',
            'sizes' => [],
            'description' => '<table><tbody><tr><td><strong>skład materiału</strong></td><td>80% bawełna</td></tr></tbody></table>',
            'files' => '',
            'image' => 'bb22',
            'properties' => [['SR', 'Podeszwa antypoślizg. (posadzka ceramiczna)', false]],
            'values' => [['Normą', '&nbsp;'], ['Kategoria obuwia', '&nbsp;']],
            'business' => [['Kod nomenklatury', '61159500']],
        ];
    }

    // ---- atrapa sklepu ----

    private function fakeSite(): void
    {
        Http::fake(function (Request $request) {
            $url = $request->url();
            $path = (string) parse_url($url, PHP_URL_PATH);
            $query = (string) parse_url($url, PHP_URL_QUERY);
            preg_match('/PHPSESSID=([\w-]+)/', $request->header('Cookie')[0] ?? '', $m);
            $signedIn = in_array($m[1] ?? '', $this->sessions, true);

            if ($path === '/Login_uzytkownika/') {
                if ($request->method() === 'POST') {
                    $data = $request->data();
                    $this->loginBodies[] = $data;
                    if (($data['user'] ?? null) === self::USER && ($data['password'] ?? null) === self::PASSWORD && ($data['login'] ?? null) === '1') {
                        $this->logins++;
                        $this->sessions[] = 's'.$this->logins;
                        $this->pages = 0;

                        // jak w sklepie: przekierowanie na stronę konta z nowym ciasteczkiem sesji
                        return Http::response(self::page('<p>Zamówienia</p>', true), 200, ['Set-Cookie' => 'PHPSESSID=s'.$this->logins.'; path=/; secure; HttpOnly', 'Content-Type' => 'text/html; charset=utf-8']);
                    }

                    return Http::response(self::loginPage('Nieprawidłowe dane logowania'), 200, ['Content-Type' => 'text/html; charset=utf-8']);
                }

                return Http::response(self::loginPage(''), 200, ['Set-Cookie' => 'PHPSESSID=guest; path=/', 'Content-Type' => 'text/html; charset=utf-8']);
            }

            // bez sesji: sklep przekierowuje na logowanie — atrapa oddaje od razu stronę logowania
            if (! $signedIn) {
                return Http::response(self::loginPage(''), 200, ['Content-Type' => 'text/html; charset=utf-8']);
            }

            if (str_starts_with($path, '/image/')) {
                // jak w sklepie: bez Content-Type
                return Http::response(self::jpeg($path), 200, ['Content-Type' => '']);
            }
            if (str_starts_with($path, '/file/') || $query === 'd=pdf') {
                return Http::response('%PDF-1.4 test '.$path, 200, ['Content-Type' => 'application/pdf']);
            }

            $this->pages++;
            if ($this->expireAfterPages > 0 && $this->pages > $this->expireAfterPages) {
                $this->expireAfterPages = 0;
                $this->sessions = [];

                return Http::response(self::loginPage(''), 200, ['Content-Type' => 'text/html; charset=utf-8']);
            }

            if ($path === '/') {
                return Http::response(self::page(self::menu(array_keys($this->sections)), true), 200, ['Content-Type' => 'text/html; charset=utf-8']);
            }
            if (preg_match('#^/([a-z]+)/(\d*)$#', $path, $s) === 1 && isset($this->sections[$s[1]])) {
                return Http::response(self::page($this->listPage($s[1], $s[2] === '' ? 1 : (int) $s[2]), true), 200, ['Content-Type' => 'text/html; charset=utf-8']);
            }
            if (isset($this->products[$path])) {
                return Http::response(self::page(self::productPage($path, $this->products[$path]), true), 200, ['Content-Type' => 'text/html; charset=utf-8']);
            }

            return Http::response(self::page('<h1>Nie znaleziono</h1>', true), 404, ['Content-Type' => 'text/html; charset=utf-8']);
        });
    }

    private static function page(string $body, bool $account): string
    {
        $header = $account
            ? '<a class="navbar-tool ms-1 ms-lg-0 me-n1 me-lg-2" href="/uzytkownik/" ><div class="navbar-tool-text ms-n3"><small class="text-accent">Firma Testowa</small>Jan Test</div></a>'
            : '<a class="navbar-tool ms-1 ms-lg-0 me-n1 me-lg-2" href="/Login_uzytkownika/" ></a>';

        return '<!DOCTYPE html><html lang="pl"><head><meta charset="utf-8"><title>VM Footwear s.r.o.</title></head><body><header>'.$header.'</header>'.$body.'</body></html>';
    }

    private static function loginPage(string $error): string
    {
        return self::page(($error !== '' ? '<div class="alert alert-danger">'.$error.'</div>' : '')
            .'<form method="post" action="https://pl.b2b.vmfootwear.cz/Login_uzytkownika/"><input type="email" name="user"><input type="password" name="password"><input type="hidden" name="login" value="1"><button type="submit">Zaloguj się</button></form>', false);
    }

    /**
     * @param  list<string>  $sections
     */
    private static function menu(array $sections): string
    {
        $items = array_map(static fn (string $s): string => '<li class="nav-item dropdown "><a class="nav-link " href="/'.$s.'/" >'.$s.'</a></li>', $sections);

        // stopka z działem spoza menu (materiały marketingowe) — nie jest działem wyrobów
        return '<ul class="navbar-nav">'.implode("\n", $items).'</ul><footer><a href="/marketingove-materialy/">Materiały marketingowe</a></footer>';
    }

    private function listPage(string $section, int $page): string
    {
        $items = $this->sections[$section];
        $pages = max(1, (int) ceil(count($items) / $this->perPage));
        $cards = '';
        foreach (array_slice($items, ($page - 1) * $this->perPage, $this->perPage) as $item) {
            $cards .= '<div class="col-12"><div class="card product-card overflow-hidden product-list my-1   text-white bg-darker"><div class="row">'
                .'<div class="col-1 pe-0"><a class="product-list-thumb w-100 d-block" href="'.$item['path'].'"><img src="/image/thumb/x" alt=""></a></div>'
                .'<div class="col-11"><div class="card-body ps-0 py-2 pe-2 w-100"><div class="d-flex gap-2">'
                .'<a class="product-meta align-self-end fs-xs  text-accent" href="'.$item['path'].'">'."\n".$item['code']."\n".'</a>'
                .'<h3 class="product-title fs-base"><a class="text-white" href="'.$item['path'].'">'.$item['name'].'</a></h3></div>'
                .'<div class="product-price  flex-grow-1"><span class="text-primary">1,00 PLN</span></div></div></div></div></div></div>';
        }
        $pager = '';
        for ($p = 1; $p <= $pages; $p++) {
            $pager .= '<li class="page-item"><a class="page-link" href="/'.$section.'/'.($p > 1 ? $p : '').'">'."\n".$p."\n".'</a></li>';
        }
        if ($pages > 1) {
            $pager .= '<li class="page-item"><a class="page-link" href="/'.$section.'/2"><i class="fad fa-chevron-right"></i></a></li>';
        }

        return '<ol class="breadcrumb"><li class="breadcrumb-item"><a href="/">Powrót do strony głównej</a></li></ol>'
            .'<ul class="pagination pagination-top">'.$pager.'</ul><div class="row prod-aj">'.$cards.'</div>';
    }

    /**
     * @param  array<string, mixed>  $p
     */
    private static function productPage(string $path, array $p): string
    {
        $crumbs = '<li class="breadcrumb-item text-nowrap"><a href="/">Powrót do strony głównej</a></li>';
        foreach ($p['crumbs'] as [$href, $text]) {
            $crumbs .= '<li class="ps-3 pe-2 breadcrumb-item active"><i class="fad fa-chevron-right"></i></li><li class="breadcrumb-item text-nowrap"><a href="'.$href.'">'.$text.'</a></li>';
        }
        $crumbs .= '<li class="breadcrumb-item text-nowrap active">'.$p['name'].'</li>';

        $options = '<option data-url="'.$path.'" value="">Wybierz rozmiar</option>';
        $sizes = '';
        foreach ($p['sizes'] as [$size, $stock, $delivery]) {
            $slug = rtrim($path, '/').'-'.$size.'/';
            $options .= '<option data-url="'.$slug.'"  value="'.$size.'">'.$size.'</option>';
            $icon = $delivery !== ''
                ? '<i class="fas fa-truck text-muted" title="Przewidywana data dostawy: '.$delivery.'" style="padding:1px 0px 0px 1px;"></i>'
                : '<i class="fas fa- text-muted" title="Przewidywana data dostawy: 1.1.1970" style="padding:1px 0px 0px 1px;"></i>';
            $sizes .= '<div class="btn px-2 py-1 btn-md d-flex align-items-center position-relative btn-dark"><i class="fal fa-shopping-cart"></i>&nbsp;'
                .'<input tabindex="2" class=" bg-secondary form-control qty-detail-variant  d-inline-block input-sm" data-add-to-cart-link="/koszyk'.$slug.'1" name="variant_count" type="number" value="">'
                ."\n".'<span class="mx-1 d-inline-block">roz.'.$size."\n".'<small class="text-muted align-items-center d-flex  justify-content-between mt-n1">'.$icon.'<div>'.$stock.'</div></small></span>'
                .'<a href="'.$slug.'"><i class="fal fa-info-circle"></i></a></div>';
        }
        $select = $p['sizes'] !== []
            ? '<div class="mb-3"><select class="form-select bg-secondary" required="" id="product-size">'.$options.'</select></div>'
            : '<div class="mb-3"></div><div class="mb-3 d-flex align-items-start"><input type="number" name="qty" class="form-control" min="1" value="1"><a href="/koszyk'.$path.'1" class="btn btn-success add2cart">Dodaj do koszyka</a></div>';

        $price = $p['price'] !== null ? $p['price'] : '';
        $files = $p['files'] !== ''
            ? '<div class="accordion-item bg-dark"><h3 class="accordion-header"><a class="accordion-button text-border collapsed" href="#fileDownload">Pliki do pobrania</a></h3>'
                .'<div class="accordion-collapse bg-dark-light collapse" id="fileDownload"><div class="accordion-body">'.$p['files'].'</div></div></div>'
            : '';

        $properties = '';
        foreach ($p['properties'] as [$code, $title, $checked]) {
            $properties .= '<li class="d-flex justify-content-between pb-2 border-light border-bottom"><span class="text-accent flex-fill d-flex"><div>'."\n"
                .$code.'<small class="d-block text-truncate text-muted" title="'.$title.'">'.$title.'</small></div></span>'
                .'<span><i class="fas fa-2x '.($checked ? 'mt-1 text-success fa-check-circle' : 'text-muted opacity-50 mt-1 fa-times-circle').'"></i></span></li>';
        }
        $valueRow = static fn (string $name, string $value): string => '<li class="d-flex justify-content-between pb-2 border-light border-bottom"><span class="text-accent flex-fill d-flex"><div>'."\n"
            .$name.'<small class="d-block text-truncate text-muted" title="&nbsp;">&nbsp;</small></div></span>'
            .'<span><div class="text-white fs-6">'.$value.'&nbsp;</div></span></li>';
        foreach ($p['values'] as [$name, $value]) {
            $properties .= $valueRow($name, $value);
        }
        $business = '';
        foreach ($p['business'] as [$name, $value]) {
            $business .= $valueRow($name, $value);
        }

        return '<ol class="breadcrumb breadcrumb-light flex-lg-nowrap">'.$crumbs.'</ol>'
            .'<h1 class="h3 text-white mb-2">'.$p['name'].'</h1>'
            .'<div class="product-gallery"><div class="product-gallery-preview order-sm-2"><div class="product-gallery-preview-item active" id="image-main">'
            .'<img class="image-zoom" src="/image/detail/'.$p['image'].'" data-zoom="/image/'.$p['image'].'" alt=""><div class="image-zoom-pane"></div></div></div>'
            .'<div class="product-gallery-thumblist order-sm-1"><a class="product-gallery-thumblist-item active" href="#image-main"><img src="/image/thumb/'.$p['image'].'" alt=""></a></div></div>'
            .'<div class="product-details ms-auto pt-5 pb-3 position-relative">'
            .'<div class="badge-list position-absolute justify-content-end flex-wrap ms-auto me-2 pe-1 d-flex gap-2 ">'.$p['badges'].'</div>'
            .'<div class="mb-3"><span class="h3 fw-normal text-accent me-1">'."\nCena:\n".'</span><span class="h3 fw-normal text-accent me-1">'."\n".$price.'                            </span></div>'
            .'<div class="fs-sm mb-4 me-n4 position-relative"><span class="text-border fw-medium me-1">'."\nKod produktu:\n".'</span><span class="text-light" id="colorOption">'."\n".$p['code'].'                            </span>'
            .'<div class="item-availible fs-sm mb-2 me-n4 position-relative text-start"><span class="fw-medium text-accent me-1">Dostupnost:</span><span>'.$p['stock'].'</span></div>'
            .$p['action'].'</div>'
            .'<form class="mb-grid-gutter" method="post">'.$select.'</form>'
            .'<div class="accordion  mb-4 bg-dark" id="productPanels"><div class="accordion-item "><h3 class="accordion-header "><a class="accordion-button text-border" href="#productInfo">&nbsp;Opis produktu</a></h3>'
            .'<div class="accordion-collapse bg-dark-light collapse show" id="productInfo"><div class="accordion-body">'."\n".$p['description']."\n".'</div></div></div>'.$files.'</div>'
            .'</div>'
            .'<div class="col-12 col-sm-11">'.($sizes !== '' ? '<div>Dostępne rozmiary:</div><div class="cart-load-variants mt-3 mb-5 d-flex flex-wrap">'.$sizes.'</div>' : '').'</div>'
            .'<ul class="nav nav-tabs border-light" role="tablist"><li class="nav-item"><a class="nav-link" href="#param_1">Właściwości</a></li><li class="nav-item"><a class="nav-link" href="#param_3">Parametry biznesowe</a></li></ul>'
            .'<div class="tab-content"><div class="tab-pane fade active show" id="param_1" role="tabpanel"><h3 class="h6 mb-3">Właściwości</h3><div class="row"><div class="col-lg-3 col-sm-6"><ul class="list-unstyled fs-sm mb-0">'.$properties.'</ul></div></div></div>'
            .'<div class="tab-pane fade " id="param_3" role="tabpanel"><h3 class="h6 mb-3">Parametry biznesowe</h3><div class="row"><div class="col-lg-3 col-sm-6"><ul class="list-unstyled fs-sm mb-0">'.$business.'</ul></div></div></div></div>';
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
