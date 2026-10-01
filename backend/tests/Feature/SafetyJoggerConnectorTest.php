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
use App\Services\B2b\SafetyJoggerB2bClient;
use App\Services\B2b\SafetyJoggerB2bConnector;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use RuntimeException;
use Tests\TestCase;

/**
 * Łącznik order.safetyjogger.com na atrapie sklepu (Http::fake). Kształt odpowiedzi odwzorowuje zalogowane konto
 * z 01.10.2026: logowanie JSON-em (/action/login → {success, user: {applicationId, authenticationToken,
 * authorityGroup…}}), nagłówki aplikacji na każdym zapytaniu, lista /action/product (numberOfItems, products z atrybutami
 * katalogu jako kluczami tłumaczeń, kolory z images), pozycje koloru /action/shoppingcart/itemsForProductColor/{id}
 * (sku z EAN-em, ceną konta i cennikową, skuStocks z regionem), pliki /action/document/{id} (ścieżki ze spacjami),
 * tłumaczenia /i18n/SAFETY/{pl,en}.json. Bez sesji API odpowiada 401.
 *
 * Wszystkie dane (kody, ceny, nazwy, opisy, EAN-y) są SYNTETYCZNE.
 */
final class SafetyJoggerConnectorTest extends TestCase
{
    use RefreshDatabase;

    private const USER = 'zakupy@example.test';

    private const PASSWORD = 'dobre-haslo';

    private const TOKEN = 'tok-1';

    /** @var list<array<string, mixed>> wyroby listy */
    private array $products = [];

    /** @var array<int, list<array<string, mixed>>> id koloru → pozycje */
    private array $items = [];

    /** @var array<int, list<array<string, mixed>>> id wyrobu → pliki */
    private array $documents = [];

    /** @var list<string> ważne tokeny */
    private array $tokens = [];

    private int $logins = 0;

    /** @var list<array<string, mixed>> */
    private array $loginBodies = [];

    /** Sesja wygasa po tylu zapytaniach API (0 = nigdy). */
    private int $expireAfterCalls = 0;

    private int $calls = 0;

    private string $currency = 'PLN';

    /** API odmawia każdej sesji (logowanie się udaje) — sesja nie do odzyskania. */
    private bool $apiRefused = false;

    /** Liczba wyrobów zgłaszana w numberOfItems (null = prawdziwa). */
    private ?int $reportedTotal = null;

    protected function setUp(): void
    {
        parent::setUp();
        Queue::fake();
    }

    public function test_login_posts_json_and_api_calls_carry_the_application_headers(): void
    {
        $this->addBoot();
        $this->fakeSite();
        $connector = $this->connector();

        iterator_to_array($connector->products(), false);

        $this->assertSame(['username' => self::USER, 'password' => self::PASSWORD, 'rememberMe' => false], $this->loginBodies[0]);
        $api = Http::recorded(static fn (Request $r): bool => str_contains($r->url(), '/action/product?'))->first()[0];
        $this->assertSame('7', $api->header('X-Application-Id')[0] ?? null);
        $this->assertSame(self::USER, $api->header('X-Auth-User')[0] ?? null);
        $this->assertSame(self::TOKEN, $api->header('X-Auth-Token')[0] ?? null);
        $this->assertSame('SAFETY', $api->header('X-application-Name')[0] ?? null);
        $this->assertSame('pl', $api->header('X-Language-Code')[0] ?? null);
    }

    public function test_wrong_password_is_not_fatal(): void
    {
        $this->fakeSite();
        $client = new SafetyJoggerB2bClient(self::USER, 'zle-haslo', 0, static function (int $ms): void {});

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

    public function test_norm_designation_level_html_and_arrival_date_helpers(): void
    {
        $this->assertSame('EN ISO 20347:2022', SafetyJoggerB2bConnector::normDesignation('EN ISO 20347:2022(Europe)'));
        $this->assertSame('EN 360: 2023', SafetyJoggerB2bConnector::normDesignation('EN 360: 2023 - Personal fall protection equipment - Retractable'));
        $this->assertSame('EN343:2019', SafetyJoggerB2bConnector::normDesignation('Rain Protection - EN343:2019'));
        $this->assertSame('ASTM F2413:2024', SafetyJoggerB2bConnector::normDesignation('ASTM F2413:2024'));
        $this->assertSame('BS EN ISO 1186 - FOOD CONTACT', SafetyJoggerB2bConnector::normDesignation('BS EN ISO 1186 - FOOD CONTACT'));

        $this->assertSame('4X42F', SafetyJoggerB2bConnector::performanceLevel(' 4 X 4 2 F '));
        $this->assertSame('A5 4 4', SafetyJoggerB2bConnector::performanceLevel('A5 4 4'));
        $this->assertSame('X2X', SafetyJoggerB2bConnector::performanceLevel('X 2 X'));

        $this->assertSame("Lekka koszulka z siatką.\nSzybko schnie & oddycha.", SafetyJoggerB2bConnector::htmlText('<p>Lekka <strong>koszulka</strong> z&nbsp;siatką.</p><p>Szybko schnie &amp; oddycha.</p>'));

        // 2026-11-15 23:30 UTC = 16.11.2026 w Polsce
        $this->assertSame('16.11.2026', SafetyJoggerB2bConnector::arrivalDate(1794785400000));
    }

    public function test_model_becomes_one_card_with_colour_size_rows_prices_norms_files_and_images(): void
    {
        $this->addBoot();
        $this->addGloves();
        $this->fakeSite();
        $connector = $this->connector();

        $products = iterator_to_array($connector->products(), false);

        $this->assertSame(['TESTBOOT', 'TESTGLOVE'], array_map(static fn (B2bRemoteProduct $p): string => $p->sku, $products));
        $boot = $products[0];
        $this->assertSame('ok', $boot->raw['status']);
        $this->assertSame('5400000000011', $boot->remoteId);
        $this->assertSame('Półbuty ochronne Safety Jogger TEST RUNNER S3 TESTBOOT', $boot->name);
        $this->assertSame('Buty > Obuwie ochronne > Półbuty ochronne', $boot->category);
        $this->assertSame('https://order.safetyjogger.com/pl/product/TESTBOOT/BLK', $boot->sourceUrl);
        $this->assertSame('Safety Jogger', $connector->manufacturer($boot));
        // kolor wyprzedaży (L) poza kartą, bo wyrób ma inne kolory; pozycja bez ceny poza kartą
        $this->assertSame('Kolory: black (BLK), grey (GRY); rozmiary: 41, 42', $boot->variantSummary);
        $this->assertSame(
            [
                ['5400000000011', 'TESTBOOT-BLK 41', 'black (BLK) / 41', 120.5, 130.0, 'Na stanie: 14'],
                ['5400000000028', 'TESTBOOT-BLK 42', 'black (BLK) / 42', 120.5, 130.0, 'Brak na stanie, dostawa od 16.11.2026'],
                ['sku-903', 'TESTBOOT-GRY 41', 'grey (GRY) / 41', 99.9, 130.0, 'Brak na stanie, na zamówienie'],
            ],
            array_map(static fn (array $m): array => [$m['remote_id'], $m['sku'], $m['size'], $m['price']->net, $m['price']->base, $m['availability']], $boot->members),
        );
        $this->assertSame(
            'Na stanie: black (BLK) / 41; Brak na stanie, dostawa od 16.11.2026: black (BLK) / 42; Brak na stanie, na zamówienie: grey (GRY) / 41',
            $boot->availability,
        );
        $price = $connector->price($boot);
        $this->assertSame(99.9, $price->net);
        $this->assertSame(130.0, $price->base);
        $this->assertSame('PLN', $price->currency);
        $this->assertFalse($price->order->restricts());
        $this->assertSame(
            [
                [ProductIdentifier::TYPE_SOURCE_CODE, 'TESTBOOT', null, null],
                [ProductIdentifier::TYPE_EAN, '5400000000011', '5400000000011', 'black (BLK) / 41'],
                [ProductIdentifier::TYPE_EAN, '5400000000028', '5400000000028', 'black (BLK) / 42'],
                [ProductIdentifier::TYPE_ALT_CODE, '000111', '5400000000011', 'black (BLK)'],
                [ProductIdentifier::TYPE_ALT_CODE, '000222', 'sku-903', 'grey (GRY)'],
            ],
            array_map(static fn ($i): array => [$i->type, $i->value, $i->remoteId, $i->label], $boot->identifiers ?? []),
        );
        // tylko polski długi opis (krótki to jego streszczenie); angielskiego tekstu nie bierzemy
        $this->assertSame("Lekki półbut S3 na co dzień.\nPodnosek kompozytowy & podeszwa SRC.", $connector->description($boot));
        $fields = array_map(static fn ($f): string => $f->section.' | '.$f->name.' | '.$f->value, $connector->shopFields($boot));
        $this->assertSame(
            [
                'Informacje ze sklepu Safety Jogger | Marka | Safety Jogger',
                'Informacje ze sklepu Safety Jogger | Kod modelu | TESTBOOT',
                'Informacje ze sklepu Safety Jogger | Nazwa handlowa | TEST RUNNER S3',
                'Informacje ze sklepu Safety Jogger | Rodzaj produktu | Buty',
                'Informacje ze sklepu Safety Jogger | Kategoria | Obuwie ochronne',
                'Informacje ze sklepu Safety Jogger | Podkategoria | Półbuty ochronne',
                'Informacje ze sklepu Safety Jogger | Zakres rozmiarów EU | 38-47',
                'Informacje ze sklepu Safety Jogger | Kraj pochodzenia | CHINA',
                // tłumaczenia norm są tylko angielskie — dopisek regionu odpada
                'Normy | Norma | EN ISO 20345:2022+A1:2024',
                'Normy | Norma | ASTM F2413:2024',
                'Normy | Kategoria | S3',
                'Normy | Certyfikowane funkcje | SR - odporność na poślizg; FO',
                'Materiały | Materiał cholewki | Mikrofibra',
                'Materiały | Zewnętrzna podeszwa | PU/GUMA',
                // brak polskiego tłumaczenia — angielskie, jak w sklepie
                'Specyfikacja produktu | Korzyści | ESD-certified footwear; Nie zawiera metalu',
                // „N/A” = nie dotyczy, bez wiersza
                'Wyniki testu | Wartość ESD | 20',
            ],
            $fields,
        );
        $this->assertSame(
            [
                ['TESTBOOT_pl.pdf', ProductDocument::KIND_DATASHEET, 'https://order.safetyjogger.com/document/PRODUCT_SHEET/TESTBOOT/pl/TESTBOOT_pl.pdf'],
                ['DOC_TESTBOOT_PL.PDF', ProductDocument::KIND_CERTIFICATE, 'https://order.safetyjogger.com/document/DeclarationofConformity/TESTBOOT/DOC_TESTBOOT_PL.PDF'],
                ['TEST RUNNER S3_CE.pdf', ProductDocument::KIND_CERTIFICATE, 'https://order.safetyjogger.com/document/CERTIFICATE/TESTBOOT/C_2011/TEST%20RUNNER%20S3_CE.pdf'],
            ],
            array_map(static fn ($d): array => [$d->title, $d->kind, $d->sourceUrl], $connector->documents($boot)),
        );
        $this->assertSame(
            ['https://order.safetyjogger.com/picture/big/SAFETY/TESTBOOT-BLK-CTLG.JPG', 'https://order.safetyjogger.com/picture/big/SAFETY/TESTBOOT-GRY-CTLG.JPG'],
            $connector->imageUrls($boot),
        );

        $gloves = $products[1];
        $this->assertSame('Rękawice odporne na przecięcia Safety Jogger TESTGLOVE', $gloves->name);
        $this->assertSame('Rękawice', $gloves->category);
        $this->assertSame('Kolor: grey (GRY); rozmiary: 8, 9', $gloves->variantSummary);
        $this->assertSame(['8', '9'], array_column($gloves->members, 'size'));
        $glovePrice = $connector->price($gloves);
        $this->assertSame(['order_min_qty' => 12.0, 'order_step_qty' => 12.0, 'order_unit' => null, 'order_varies' => false], $glovePrice->order->slotValues());
        $this->assertSame(14.35, $glovePrice->net);
        $this->assertContains('Normy | Norma | EN 388:2016 4X42F', array_map(static fn ($f): string => $f->section.' | '.$f->name.' | '.$f->value, $connector->shopFields($gloves)));
        // dwa certyfikaty o tej samej nazwie pliku = jeden; certyfikat krajowy (UKCA) poza kartą; bez polskiej karty — angielska
        $this->assertSame(
            ['TESTGLOVE_GRY_en.pdf', 'DOC_TESTGLOVE_EN.PDF', 'TESTGLOVE CE CERT.pdf'],
            array_map(static fn ($d): string => $d->title, $connector->documents($gloves)),
        );
        $this->assertSame('', $connector->description($gloves));

        $summary = implode("\n", $connector->runSummary());
        $this->assertStringContainsString('Lista Safety Jogger: 2 wyrobów', $summary);
        $this->assertStringContainsString('Karty: 2 (1 z rozmiarami albo kolorami w różnych cenach', $summary);
        $this->assertStringContainsString('Kolory wyprzedaży (Outlet) poza kartą, bo wyrób ma inne kolory: 1, np. TESTBOOT-ORA', $summary);
        $this->assertStringContainsString('Pozycje bez ceny konta albo wyprzedane do zera (poza kartą): 1, np. TESTBOOT-GRY 42 (bez ceny)', $summary);
        $this->assertStringContainsString('Bez polskiego opisu w sklepie: 1, np. TESTGLOVE', $summary);
    }

    public function test_outlet_only_model_keeps_its_sale_colours_but_not_sold_out_sizes(): void
    {
        $this->addOutletCap();
        $this->fakeSite();

        $products = iterator_to_array($this->connector()->products(), false);

        $this->assertSame('ok', $products[0]->raw['status']);
        $this->assertSame([['TESTCAP-RED SR', 'Do wyczerpania zapasu: 5']], array_map(static fn (array $m): array => [$m['sku'], $m['availability']], $products[0]->members));
        $this->assertSame('Do wyczerpania zapasu', $products[0]->availability);
    }

    public function test_lead_colour_is_the_first_colour_with_positions_on_the_card(): void
    {
        $this->addGloves();
        // pierwszy kolor rękawic bez żadnej ceny — kartę prowadzi drugi
        $this->products[0]['colors'] = [
            ['id' => 2009, 'ref' => '', 'code' => 'BLU', 'description' => 'blue', 'sequence' => 1, 'stockType' => 'N', 'alternatives' => [], 'images' => ['CTLG' => 1]],
            ['id' => 2000, 'ref' => '', 'code' => 'GRY', 'description' => 'grey', 'sequence' => 2, 'stockType' => 'N', 'alternatives' => [], 'images' => ['CTLG' => 1]],
        ];
        $this->items[2009] = [self::item(2091, '5400000009010', '8', null, 14.35, ['stock' => 1, 'stockToSell' => 1])];
        $this->fakeSite();
        $connector = $this->connector();

        $card = iterator_to_array($connector->products(), false)[0];

        $this->assertSame('https://order.safetyjogger.com/pl/product/TESTGLOVE/GRY', $card->sourceUrl);
        // kolor bez pozycji nie robi z karty wyrobu wielokolorowego — rozmiary bez nazwy koloru
        $this->assertSame('Kolor: grey (GRY); rozmiary: 8, 9', $card->variantSummary);
        $this->assertSame(['8', '9'], array_column($card->members, 'size'));
        $this->assertSame(['https://order.safetyjogger.com/picture/big/SAFETY/TESTGLOVE-GRY-CTLG.JPG'], $connector->imageUrls($card));
        $this->assertSame('TESTGLOVE_GRY_en.pdf', $connector->documents($card)[0]->title);
    }

    public function test_model_without_any_priced_position_is_skipped_with_a_reason_not_dropped(): void
    {
        $this->addBoot();
        $this->addGloves(priced: false);
        $this->fakeSite();
        $connector = $this->connector();

        $products = iterator_to_array($connector->products(), false);

        $this->assertSame(['TESTBOOT', 'TESTGLOVE'], array_map(static fn (B2bRemoteProduct $p): string => $p->sku, $products));
        $this->assertSame('skipped', $products[1]->raw['status']);
        $this->assertSame([], $connector->shopFields($products[1]));
        $this->assertStringContainsString('Bez ceny konta albo bez pozycji do zamówienia (pominięte): 1, np. TESTGLOVE', implode("\n", $connector->runSummary()));
        $this->expectExceptionMessage('ceny konta');
        $connector->price($products[1]);
    }

    public function test_many_models_without_any_account_price_stop_the_run(): void
    {
        for ($i = 0; $i < 21; $i++) {
            $this->addGloves(priced: false, id: 500 + $i, code: 'BEZ'.$i);
        }
        $this->fakeSite();

        $this->expectException(B2bFatalException::class);
        $this->expectExceptionMessage('bez ceny konta');

        iterator_to_array($this->connector()->products(), false);
    }

    public function test_list_with_a_changed_total_is_read_again_once_and_stays_inconsistent_only_then_fails(): void
    {
        for ($i = 0; $i < 5; $i++) {
            $this->addGloves(id: 600 + $i, code: 'GL'.$i);
        }
        $this->fakeSite();
        $connector = new SafetyJoggerB2bConnector($this->client(), pageSize: 2);
        $connector->login();
        $this->reportedTotal = 6;
        $again = 0;
        $connector->onListProgress(function (string $message) use (&$again): void {
            if (str_contains($message, 'pobieram od nowa')) {
                $again++;
                $this->reportedTotal = null;
            }
        });

        $this->assertCount(5, iterator_to_array($connector->products(), false));
        $this->assertSame(1, $again);

        $stuck = new SafetyJoggerB2bConnector($this->client(), pageSize: 2);
        $stuck->login();
        $this->reportedTotal = 6;
        $this->expectExceptionMessage('niespójna także po ponownym pobraniu');
        iterator_to_array($stuck->products(), false);
    }

    public function test_list_across_pages_gives_every_model_once(): void
    {
        for ($i = 0; $i < 5; $i++) {
            $this->addGloves(id: 600 + $i, code: 'GL'.$i);
        }
        $this->fakeSite();
        $connector = new SafetyJoggerB2bConnector($this->client(), pageSize: 2);
        $connector->login();

        $products = iterator_to_array($connector->products(), false);

        $this->assertSame(['GL0', 'GL1', 'GL2', 'GL3', 'GL4'], array_map(static fn (B2bRemoteProduct $p): string => $p->sku, $products));
        $this->assertCount(3, Http::recorded(static fn (Request $r): bool => str_contains($r->url(), '/action/product?')));
    }

    public function test_expired_session_is_renewed_once_and_a_session_never_restored_is_fatal(): void
    {
        $this->addBoot();
        $this->addGloves();
        $this->fakeSite();
        $connector = $this->connector();
        $this->expireAfterCalls = 3;

        $this->assertCount(2, iterator_to_array($connector->products(), false));
        $this->assertSame(2, $this->logins);

        $client = $this->client();
        $client->login();
        $this->apiRefused = true;

        $this->expectException(B2bFatalException::class);
        $client->listPage(1, 10);
    }

    public function test_account_in_another_currency_stops_before_the_list(): void
    {
        $this->addBoot();
        $this->currency = 'EUR';
        $this->fakeSite();

        $this->expectExceptionMessage('w walucie „EUR”');

        iterator_to_array($this->connector()->products(), false);
    }

    public function test_files_and_images_are_fetched_and_an_html_page_is_not_a_file(): void
    {
        $this->addBoot();
        $this->fakeSite();
        $connector = $this->connector();
        $card = iterator_to_array($connector->products(), false)[0];

        $image = $connector->image($card);
        $file = $connector->documentBytes($connector->documents($card)[2]);

        $this->assertSame('image/jpeg', $image?->mime);
        $this->assertSame('application/pdf', $file['mime']);
        $this->assertStringStartsWith('%PDF-', $file['bytes']);
        $this->assertTrue(Http::recorded(static fn (Request $r): bool => str_ends_with($r->url(), '/C_2011/TEST%20RUNNER%20S3_CE.pdf'))->isNotEmpty());

        $this->expectExceptionMessage('nie wydał pliku');
        $connector->documentBytes(new B2bRemoteDocument('x', 'https://order.safetyjogger.com/document/brak.pdf'));
    }

    public function test_sync_creates_the_model_card_with_size_rows_norms_documents_and_images_and_a_second_run_changes_nothing(): void
    {
        Storage::fake('public');
        Storage::fake('local');
        $this->addBoot();
        $this->addGloves();
        $this->fakeSite();

        $result = app(B2bAccountSyncRunner::class)->run($this->account(), delayMs: 0, withImages: true);

        $this->assertSame(2, $result['created'], implode(' | ', $result['errors']));
        $card = Product::query()->where('sku', 'TESTBOOT')->sole();
        $this->assertSame('Safety Jogger', $card->manufacturer);
        $this->assertSame('Półbuty ochronne Safety Jogger TEST RUNNER S3 TESTBOOT', $card->name);
        $this->assertStringContainsString('Lekki półbut S3', (string) $card->description);
        $this->assertEqualsCanonicalizing(
            ['5400000000011', '5400000000028', 'sku-903'],
            B2bProductLink::query()->where('product_id', $card->id)->pluck('remote_id')->all(),
        );
        $slot = ProductSourcePrice::query()->where('product_id', $card->id)->sole();
        $this->assertSame('99.90', (string) $slot->purchase_price);
        $this->assertSame('130.00', (string) $slot->catalog_price_net);
        $this->assertSame('120.50', (string) $slot->size_price_max);
        $this->assertSame(
            ['black (BLK) / 41' => '120.50', 'black (BLK) / 42' => '120.50', 'grey (GRY) / 41' => '99.90'],
            ProductVariant::query()->where('product_id', $card->id)->where('kind', ProductVariant::KIND_SIZE)->orderBy('label')->pluck('purchase_price', 'label')->map(static fn ($p): string => (string) $p)->all(),
        );
        $this->assertSame('safetyjogger', $card->manufacturer_norms['source']['connector'] ?? null);
        $this->assertContains('EN ISO 20345:2022+A1:2024', array_column($card->manufacturer_norms['rows'] ?? [], 'label'));
        $gloves = Product::query()->where('sku', 'TESTGLOVE')->sole();
        $this->assertContains(['label' => 'EN 388:2016', 'value' => '4X42F'], $gloves->manufacturer_norms['rows'] ?? []);
        $this->assertSame(
            ['TESTBOOT_pl.pdf', 'DOC_TESTBOOT_PL.PDF', 'TEST RUNNER S3_CE.pdf'],
            ProductDocument::query()->where('product_id', $card->id)->orderBy('sort_order')->pluck('title')->all(),
        );
        $this->assertSame(2, ProductImage::query()->where('product_id', $card->id)->count());
        $rows = collect(ProductShopCard::query()->where('product_id', $card->id)->sole()->fields)->flatMap(static fn (array $section): array => array_map(
            static fn (array $row): string => ($section['section'] ?? '').' | '.$row['name'].' | '.$row['value'],
            $section['rows'] ?? [],
        ))->all();
        $this->assertContains('Normy | Kategoria | S3', $rows);
        $this->assertSame(
            ['5400000000011', '5400000000028'],
            ProductIdentifier::query()->where('product_id', $card->id)->where('type', ProductIdentifier::TYPE_EAN)->orderBy('value')->pluck('value')->all(),
        );

        $before = $this->snapshot();
        $second = app(B2bAccountSyncRunner::class)->run($this->account(), delayMs: 0, withImages: true);

        $this->assertSame(0, $second['created'], implode(' | ', $second['errors']));
        $this->assertSame(0, $second['updated'], implode(' | ', $second['errors']));
        $this->assertSame(2, $second['unchanged'], implode(' | ', $second['errors']));
        $this->assertSame($before, $this->snapshot());
    }

    public function test_registry_detects_safety_jogger_by_host_as_the_manufacturer_site(): void
    {
        $registry = app(B2bConnectorRegistry::class);

        $this->assertSame('safetyjogger', $registry->keyForSites(['https://order.safetyjogger.com/en/catalog']));
        $this->assertSame('Safety Jogger', $registry->label('safetyjogger'));
        $this->assertTrue($registry->requiresPassword('safetyjogger'));
        $this->assertTrue($registry->isManufacturerSite('safetyjogger'));
        $this->assertTrue($registry->groupsSizes('safetyjogger'));
        $this->assertTrue($registry->sendsSizePrices('safetyjogger'));
        $this->assertSame(['brand' => 'Safety Jogger', 'names' => ['Norma']], $registry->shopFieldNormSource('safetyjogger'));

        $account = B2bAccount::query()->create([
            'username' => self::USER, 'password' => 'sekret', 'sites' => ['https://order.safetyjogger.com/en/catalog'],
        ]);
        $connector = $registry->make($account, 0);

        $this->assertInstanceOf(SafetyJoggerB2bConnector::class, $connector);
        foreach ([B2bManufacturerSite::class, B2bShopFieldSource::class, B2bShopFieldNormSource::class, B2bDocumentSource::class, B2bImageGallery::class, B2bGroupsSizes::class, B2bSizePriceSource::class] as $interface) {
            $this->assertInstanceOf($interface, $connector);
        }
    }

    // ---- pomocnicze ----

    private function client(): SafetyJoggerB2bClient
    {
        return new SafetyJoggerB2bClient(self::USER, self::PASSWORD, 0, static function (int $ms): void {});
    }

    private function connector(): SafetyJoggerB2bConnector
    {
        $connector = new SafetyJoggerB2bConnector($this->client());
        $connector->login();

        return $connector;
    }

    private function account(): B2bAccount
    {
        return B2bAccount::query()->firstOrCreate(
            ['username' => self::USER],
            ['password' => self::PASSWORD, 'sites' => ['https://order.safetyjogger.com/en/catalog'], 'connector' => 'safetyjogger', 'sync_images' => true],
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
     * @return array{id: int, name?: string, translationKey: string, priority: int, active: bool}
     */
    private static function member(int $id, string $name, string $key): array
    {
        return ['id' => $id, 'name' => $name, 'translationKey' => $key, 'priority' => 0, 'active' => true];
    }

    /**
     * @return array{id: int, text: string, translationKey: string}
     */
    private static function text(int $id, string $text, string $key = ''): array
    {
        return ['id' => $id, 'text' => $text, 'translationKey' => $key];
    }

    /**
     * Półbut S3 w trzech kolorach: BLK (dwa rozmiary, drugi z dostawą), GRY (rozmiar bez EAN-u w innej cenie
     * i rozmiar bez ceny), ORA wyprzedaż (L) — poza kartą. Opisy po polsku, normy i korzyści tylko po angielsku.
     */
    private function addBoot(): void
    {
        $this->products[] = [
            'id' => 100, 'name' => 'TESTBOOT', 'commercialName' => 'TEST RUNNER S3', 'description' => '', 'stockType' => 'N',
            'brand' => 'SAFETY', 'brandDescription' => 'Safety Jogger', 'category' => 'S', 'priority' => 1,
            'colors' => [
                ['id' => 11, 'ref' => '000111', 'code' => 'BLK', 'description' => 'black', 'sequence' => 1, 'stockType' => 'N', 'alternatives' => [], 'images' => ['CTLG' => 1, 'HIRES' => 1]],
                ['id' => 12, 'ref' => '000222', 'code' => 'GRY', 'description' => 'grey', 'sequence' => 9999999, 'stockType' => 'N', 'alternatives' => [], 'images' => ['CTLG' => 1]],
                ['id' => 13, 'ref' => '000333', 'code' => 'ORA', 'description' => 'orange', 'sequence' => 9999999, 'stockType' => 'L', 'alternatives' => [], 'images' => ['CTLG' => 1]],
            ],
            'countryOfOrigin' => ['id' => 36, 'name' => 'CHINA', 'code' => 'CHN'],
            'norms' => [self::member(1, 'N_2011', 'ca-norms-N_2011'), self::member(2, 'N_2018', 'ca-norms-N_2018'), self::member(3, 'N_0026', 'ca-norms-N_0026')],
            'simplified-brand' => [self::member(4, 'Safety Jogger', 'ca-brand-Safety Jogger')],
            'standards' => [self::member(5, 'S3', 'ca-standards-S3')],
            'features' => [self::member(6, '25026', 'ca-features-25026'), self::member(7, '15093', 'ca-features-15093')],
            'benefits' => [self::member(8, 'ESD', 'ca-benefits-esd'), self::member(9, 'NOMETAL', 'ca-benefits-NOMETAL')],
            'articletype' => [self::member(10, 'shoes', 'ca-articletype-shoes')],
            'shoes_product_category' => [self::member(11, 'safety', 'ca-shoes_product_category-safety')],
            'shoes_product_subcategory' => [self::member(12, 'low', 'ca-shoes_product_subcategory-low')],
            'upper' => [self::member(13, '77', 'ca-commercialName-77')],
            'outsole' => [self::member(14, '78', 'ca-commercialName-78')],
            'industries' => [self::member(15, 'food', 'ca-industries-food')],
            'available-size-range_eu' => [self::text(16, '38-47')],
            'outsole_ESD' => [self::text(17, '20', 'cav_outsole_esd_testboot')],
            'toecap_impact_resistance_100J' => [self::text(18, 'N/A', 'cav_toecap_100_testboot')],
            'product-title' => [self::text(19, 'Light Safety Shoe', 'cav_product-title_testboot')],
            'short-description' => [self::text(20, '<p>Light S3 shoe.</p>', 'cav_short-description_testboot')],
            'product-long-description' => [self::text(21, '<p>Light S3 shoe for every day.</p>', 'cav_product-long-description_testboot')],
            'images' => [], 'videos' => [],
        ];
        $this->items[11] = [
            self::item(901, '5400000000011', '41', 120.5, 130.0, ['stock' => 14, 'stockToSell' => 14]),
            self::item(902, '5400000000028', '42', 120.5, 130.0, ['stock' => 0, 'stockToSell' => 40, 'firstArrival' => 1794785400000]),
        ];
        $this->items[12] = [
            self::item(903, '', '41', 99.9, 130.0, ['stock' => 0, 'stockToSell' => 0]),
            self::item(904, '5400000000035', '42', null, 130.0, ['stock' => 3, 'stockToSell' => 3]),
        ];
        $this->items[13] = [self::item(905, '5400000000042', '41', 50.0, 130.0, ['stock' => 2, 'stockToSell' => 2, 'stockType' => 'L'])];
        $this->documents[100] = [
            self::document(100, 'HI_RES_IMAGE', 'BLK', 'TESTBOOT-BLK-HIRES.PNG', '/picture/Brands/SAFETY/HIRES/TESTBOOT-BLK-HIRES.PNG', ''),
            self::document(100, 'CERTIFICATE', null, 'TEST RUNNER S3_CE.pdf', '/document/CERTIFICATE/TESTBOOT/C_2011/TEST RUNNER S3_CE.pdf', 'ca-certificates-C_2011'),
            self::document(100, 'CERTIFICATE', null, 'TEST RUNNER JSAA.pdf', '/document/CERTIFICATE/TESTBOOT/C_0070/TEST RUNNER JSAA.pdf', 'ca-certificates-C_0070'),
            self::document(100, 'DeclarationofConformity', null, 'DOC_TESTBOOT_EN.PDF', '/document/DeclarationofConformity/TESTBOOT/DOC_TESTBOOT_EN.PDF', 'English'),
            self::document(100, 'DeclarationofConformity', null, 'DOC_TESTBOOT_PL.PDF', '/document/DeclarationofConformity/TESTBOOT/DOC_TESTBOOT_PL.PDF', 'Polski'),
            self::document(100, 'PRODUCT_SHEET', null, 'TESTBOOT_en.pdf', '/document/PRODUCT_SHEET/TESTBOOT/en/TESTBOOT_en.pdf', 'English'),
            self::document(100, 'PRODUCT_SHEET', 'BLK', 'TESTBOOT_BLK_pl.pdf', '/document/PRODUCT_SHEET/TESTBOOT/pl/TESTBOOT_BLK_pl.pdf', 'Polski'),
            self::document(100, 'PRODUCT_SHEET', null, 'TESTBOOT_pl.pdf', '/document/PRODUCT_SHEET/TESTBOOT/pl/TESTBOOT_pl.pdf', 'Polski'),
            // plik innego wyrobu w odpowiedzi — pominięty
            self::document(999, 'PRODUCT_SHEET', null, 'OBCY_pl.pdf', '/document/PRODUCT_SHEET/OBCY/pl/OBCY_pl.pdf', 'Polski'),
        ];
    }

    /** Rękawice w wielokrotnościach 12, EN 388 z poziomem, bez polskiego opisu i polskiej karty. */
    private function addGloves(bool $priced = true, int $id = 200, string $code = 'TESTGLOVE'): void
    {
        $colourId = $id * 10;
        $this->products[] = [
            'id' => $id, 'name' => $code, 'commercialName' => $code, 'description' => '', 'stockType' => 'N',
            'brand' => 'SAFETY', 'brandDescription' => 'Safety Jogger', 'category' => 'T', 'priority' => 1,
            'colors' => [['id' => $colourId, 'ref' => '', 'code' => 'GRY', 'description' => 'grey', 'sequence' => 1, 'stockType' => 'N', 'alternatives' => [], 'images' => ['HIRES' => 1]]],
            'countryOfOrigin' => ['id' => 36, 'name' => 'CHINA', 'code' => 'CHN'],
            'norms' => [self::member(30, 'N_0009', 'ca-norms-N_0009')],
            'PERFORMANCELEVEL_N_0009' => [self::text(31, '4 X 4 2 F', 'ca-performancelevel-N_0009-4 X 4 2 F')],
            'simplified-brand' => [self::member(4, 'Safety Jogger', 'ca-brand-Safety Jogger')],
            'articletype' => [self::member(32, 'gloves', 'ca-articletype-gloves')],
            'product-title' => [self::text(33, 'Cut resistant gloves', 'cav_product-title_testglove')],
            'short-description' => [self::text(34, '<p>Only English.</p>', 'cav_short-description_none')],
            'images' => [], 'videos' => [],
        ];
        $this->items[$colourId] = [
            self::item($colourId + 1, '5400000001018', '8', $priced ? 14.35 : null, 14.35, ['stock' => 600, 'stockToSell' => 600, 'buyPer' => 12]),
            self::item($colourId + 2, '5400000001025', '9', $priced ? 14.35 : null, 14.35, ['stock' => 800, 'stockToSell' => 800, 'buyPer' => 12]),
        ];
        $this->documents[$id] = [
            self::document($id, 'CERTIFICATE', null, $code.' CE CERT.pdf', '/document/CERTIFICATE/'.$code.'/C_0010/'.$code.' CE CERT.pdf', 'ca-certificates-C_0010'),
            self::document($id, 'CERTIFICATE', null, $code.' CE CERT.pdf', '/document/CERTIFICATE/'.$code.'/C_1009/'.$code.' CE CERT.pdf', 'ca-certificates-C_1009'),
            self::document($id, 'CERTIFICATE', null, $code.' UKCA.pdf', '/document/CERTIFICATE/'.$code.'/C_0032/'.$code.' UKCA.pdf', 'ca-certificates-C_0032'),
            self::document($id, 'DeclarationofConformity', null, 'DOC_'.$code.'_EN.PDF', '/document/DeclarationofConformity/'.$code.'/DOC_'.$code.'_EN.PDF', 'English'),
            self::document($id, 'PRODUCT_SHEET', 'GRY', $code.'_GRY_en.pdf', '/document/PRODUCT_SHEET/'.$code.'/en/'.$code.'_GRY_en.pdf', 'English'),
        ];
    }

    /** Czapka tylko w kolorze wyprzedaży: jeden rozmiar do sprzedaży, drugi wyprzedany do zera. */
    private function addOutletCap(): void
    {
        $this->products[] = [
            'id' => 300, 'name' => 'TESTCAP', 'commercialName' => 'TESTCAP', 'description' => '', 'stockType' => 'L',
            'brand' => 'SAFETY', 'brandDescription' => 'Safety Jogger', 'category' => 'T', 'priority' => 1,
            'colors' => [['id' => 31, 'ref' => '000444', 'code' => 'RED', 'description' => 'red', 'sequence' => 1, 'stockType' => 'L', 'alternatives' => [], 'images' => []]],
            'simplified-brand' => [self::member(4, 'Safety Jogger', 'ca-brand-Safety Jogger')],
            'images' => [], 'videos' => [],
        ];
        $this->items[31] = [
            self::item(3101, '5400000003012', 'SR', 9.9, 19.9, ['stock' => 5, 'stockToSell' => 5, 'stockType' => 'L']),
            self::item(3102, '5400000003029', 'XL', 9.9, 19.9, ['stock' => 0, 'stockToSell' => 0, 'stockType' => 'E']),
        ];
        $this->documents[300] = [];
    }

    /**
     * @param  array<string, mixed>  $stock
     * @return array<string, mixed>
     */
    private static function item(int $skuId, string $ean, string $size, ?float $customer, ?float $default, array $stock): array
    {
        $money = static fn (?float $v): ?array => $v === null ? null : ['currency' => 'PLN', 'value' => $v];

        return [
            'id' => null,
            'sku' => [
                'id' => $skuId,
                'eanCode' => $ean,
                'skuStocks' => [
                    // inny region magazynu — nie ten konta
                    ['skuId' => 0, 'stockRegion' => ['id' => 2, 'name' => 'USA'], 'stock' => 999, 'stockToSell' => 999, 'stockType' => 'N', 'buyPer' => 1, 'firstArrival' => null],
                    array_merge(['skuId' => 0, 'stockRegion' => ['id' => 1, 'name' => 'EUROPE', 'erpStockRegionId' => 'BELGIUM'], 'stock' => 0, 'stockToSell' => 0, 'firstArrival' => null, 'stockType' => 'N', 'buyPer' => 1, 'fullCartonQuantity' => 8], $stock),
                ],
                'assortment' => ['id' => 1, 'code' => 'S'.$size, 'sizeRange' => $size, 'sizeDivision' => $size.'(1)', 'pieces' => 1, 'packageType' => null],
                'customerPrice' => $money($customer),
                'defaultPrice' => $money($default),
                'promoPrice' => null,
                'rrpPrice' => ['currency' => 'PLN', 'value' => 299.0],
            ],
            'quantity' => null,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private static function document(int $productId, string $type, ?string $colour, string $fileName, string $path, string $label): array
    {
        return ['productId' => $productId, 'color' => $colour, 'type' => $type, 'fileName' => $fileName, 'path' => $path, 'label' => $label];
    }

    /**
     * @return array<string, string>
     */
    private static function translations(string $language): array
    {
        $common = [
            'ca-norms-N_2011' => 'EN ISO 20345:2022+A1:2024',
            'ca-norms-N_2018' => 'ASTM F2413:2024',
            'ca-norms-N_0009' => 'EN 388:2016',
            'ca-norms-N_0026' => 'N_0026',
            'ca-standards-S3' => 'S3',
            'ca-benefits-esd' => 'ESD-certified footwear',
            'ca-certificates-C_2011' => 'EN ISO 20345:2022+A1:2024 (Europe)',
            'ca-certificates-C_0070' => 'JSAA (Japan)',
            'ca-certificates-C_0010' => 'EN 21420:2020 (Europe)',
            'ca-certificates-C_1009' => 'EN 388:2016 (Europe)',
            'ca-certificates-C_0032' => 'UKCA Certificate(UK Conformity Assessed) For Gloves',
            'path-product' => 'product',
        ];
        if ($language === 'en') {
            return $common + [
                'ca-benefits-NOMETAL' => 'Metal free',
                'ca-articletype-shoes' => 'Shoes',
                'ca-features' => 'Certified features',
                'cav_short-description_none' => '<p>Only English.</p>',
            ];
        }

        return [
            'ca-benefits-NOMETAL' => 'Nie zawiera metalu',
            'ca-articletype' => 'Rodzaj produktu',
            'ca-articletype-shoes' => 'Buty',
            'ca-articletype-gloves' => 'Rękawice',
            'ca-shoes_product_category' => 'Kategoria',
            'ca-shoes_product_category-safety' => 'Obuwie ochronne',
            'ca-shoes_product_subcategory' => 'Podkategoria',
            'ca-shoes_product_subcategory-low' => 'Półbuty ochronne',
            'ca-available-size-range_eu' => 'Zakres rozmiarów EU',
            'ca-standards' => 'Kategoria',
            'ca-features' => 'Certyfikowane funkcje',
            'ca-features-25026' => 'SR - odporność na poślizg',
            'ca-features-15093' => 'FO',
            'ca-benefits' => 'Korzyści',
            'ca-upper' => 'Materiał cholewki',
            'ca-sole' => 'Zewnętrzna podeszwa',
            'ca-commercialName-77' => 'Mikrofibra',
            'ca-commercialName-78' => 'PU/GUMA',
            'ca-outsole_esd' => 'Wartość ESD',
            'ca-toecap_impact_resistance_100j' => 'Podnosek odporny na uderzenia (100J)',
            'ca-industries-food' => 'Spożywcza',
            'ca-performancelevel-N_0009' => 'EN 388:2016',
            'cav_product-title_testboot' => 'Lekki but ochronny',
            'cav_short-description_testboot' => '<p>Lekki półbut S3.</p>',
            'cav_product-long-description_testboot' => '<p>Lekki półbut S3 na co dzień.</p><p>Podnosek kompozytowy &amp; podeszwa SRC.</p>',
            'cav_product-title_testglove' => 'Rękawice odporne na przecięcia',
            // pusty polski tekst = brak tłumaczenia
            'cav_short-description_none' => '',
            'path-product' => 'product',
        ];
    }

    // ---- atrapa sklepu ----

    private function fakeSite(): void
    {
        Http::fake(function (Request $request) {
            $url = $request->url();
            $path = (string) parse_url($url, PHP_URL_PATH);
            parse_str((string) parse_url($url, PHP_URL_QUERY), $query);

            if ($path === '/action/login') {
                $data = json_decode($request->body(), true) ?: [];
                $this->loginBodies[] = $data;
                if (($data['username'] ?? null) === self::USER && ($data['password'] ?? null) === self::PASSWORD) {
                    $this->logins++;
                    $token = $this->logins === 1 ? self::TOKEN : 'tok-'.$this->logins;
                    $this->tokens[] = $token;
                    $this->calls = 0;

                    return Http::response([
                        'success' => true,
                        'user' => [
                            'applicationId' => 7, 'authenticationToken' => $token, 'username' => self::USER,
                            'authorities' => ['SEE_PRICES' => 1, 'PLACE_ORDERS' => 1],
                            'authorityGroup' => ['defaultCurrency' => $this->currency, 'stockRegionId' => 1],
                        ],
                    ], 200, ['Set-Cookie' => 'JSESSIONID=s'.$this->logins.'; Path=/; HttpOnly']);
                }

                return Http::response(['type' => 'ApplicationError', 'key' => 'err-login', 'message' => 'Invalid username or password'], 401);
            }
            if (preg_match('#^/i18n/SAFETY/(pl|en)\.json$#', $path, $m) === 1) {
                return Http::response(self::translations($m[1]), 200, ['Content-Type' => 'application/json']);
            }
            if (str_starts_with($path, '/picture/')) {
                return Http::response(self::jpeg($path), 200, ['Content-Type' => 'image/jpeg']);
            }
            if (str_starts_with($path, '/document/')) {
                if (str_contains($path, 'brak')) {
                    return Http::response('<!DOCTYPE html><html><body>404</body></html>', 200, ['Content-Type' => 'text/html']);
                }

                return Http::response('%PDF-1.4 test '.$path, 200, ['Content-Type' => 'application/pdf']);
            }

            if (str_starts_with($path, '/action/')) {
                $token = $request->header('X-Auth-Token')[0] ?? '';
                if ($this->apiRefused || ! in_array($token, $this->tokens, true) || ($request->header('X-Application-Id')[0] ?? '') !== '7') {
                    return Http::response(['type' => 'ApplicationError', 'key' => 'err-not-logged-in'], 401);
                }
                $this->calls++;
                if ($this->expireAfterCalls > 0 && $this->calls > $this->expireAfterCalls) {
                    $this->expireAfterCalls = 0;
                    $this->tokens = [];

                    return Http::response(['type' => 'ApplicationError', 'key' => 'err-not-logged-in'], 401);
                }
            }

            if ($path === '/action/product') {
                $page = (int) ($query['page'] ?? 1);
                $per = (int) ($query['itemsPerPage'] ?? 100);

                return Http::response([
                    'page' => $page,
                    'itemsPerPage' => $per,
                    'numberOfItems' => $this->reportedTotal ?? count($this->products),
                    'products' => array_slice($this->products, ($page - 1) * $per, $per),
                    'ids' => array_column($this->products, 'id'),
                ], 200, ['Content-Type' => 'application/json;charset=UTF-8']);
            }
            if (preg_match('#^/action/shoppingcart/itemsForProductColor/(\d+)$#', $path, $m) === 1) {
                return Http::response($this->items[(int) $m[1]] ?? [], 200, ['Content-Type' => 'application/json;charset=UTF-8']);
            }
            if (preg_match('#^/action/document/(\d+)$#', $path, $m) === 1) {
                return Http::response($this->documents[(int) $m[1]] ?? [], 200, ['Content-Type' => 'application/json;charset=UTF-8']);
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
