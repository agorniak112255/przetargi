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
use App\Services\B2b\B2bForeignLanguageSource;
use App\Services\B2b\B2bGroupsSizes;
use App\Services\B2b\B2bImageGallery;
use App\Services\B2b\B2bKeepsExistingNames;
use App\Services\B2b\B2bManufacturerSite;
use App\Services\B2b\B2bRemoteDocument;
use App\Services\B2b\B2bRemoteProduct;
use App\Services\B2b\B2bShopFieldNormSource;
use App\Services\B2b\B2bShopFieldSource;
use App\Services\B2b\B2bSizePriceSource;
use App\Services\B2b\SirB2bClient;
use App\Services\B2b\SirB2bConnector;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use RuntimeException;
use Tests\TestCase;

/**
 * Łącznik b2b.sirsafety.com na atrapie API (Http::fake). Kształt odpowiedzi odwzorowuje zalogowane konto z 01.10.2026:
 * logowanie JSON-em (login/login/ → {net_token, uidata: {bp}}), nagłówki Authorization i sir-b2b-bp na każdym
 * zapytaniu, lista z wyszukiwania zaawansowanego (searchProduct POST → {TOTAL, OUT_DATA}), wyrób z wariantami
 * (product/product/ → data: wiersz wyrobu z ColorList i PriceList + wiersze wariantów ATTYP 02), teksty, normy
 * z PerformanceList, dostępność tygodniami (DAT00, MNG02), pliki jako JSON {file: base64}, zdjęcia z GetImage.ashx
 * z jednym obrazkiem zastępczym dla wyrobów bez zdjęcia. Bez sesji API odpowiada 401.
 *
 * Wszystkie dane (kody, ceny, nazwy, opisy, EAN-y) są SYNTETYCZNE.
 */
final class SirConnectorTest extends TestCase
{
    use RefreshDatabase;

    private const USER = '121000001';

    private const PASSWORD = 'dobre-haslo';

    private const BP = '0012100000';

    private const API = 'https://app.sirsafety.com/sirapi/api/';

    /** @var list<array{id: string, name: string}> wyroby listy */
    private array $list = [];

    /** @var array<string, list<array<string, mixed>>> kod wyrobu → wiersze product/product */
    private array $products = [];

    /** @var array<string, array<string, string>> kod wyrobu → teksty */
    private array $texts = [];

    /** @var array<string, list<array<string, mixed>>> kod wyrobu → normy */
    private array $norms = [];

    /** @var array<string, list<array<string, mixed>>> kod wyrobu z kolorem → dostępność */
    private array $availability = [];

    /** @var list<string> zdjęcia, które sklep ma (reszta = obrazek zastępczy) */
    private array $images = [];

    /** @var list<string> kody wyrobów, których teksty kończą się błędem */
    private array $brokenTexts = [];

    /** @var list<string> ważne tokeny */
    private array $tokens = [];

    private int $logins = 0;

    /** @var list<array<string, mixed>> */
    private array $loginBodies = [];

    /** Sesja wygasa po tylu zapytaniach API (0 = nigdy). */
    private int $expireAfterCalls = 0;

    private int $calls = 0;

    /** API odmawia każdej sesji (logowanie się udaje) — sesja nie do odzyskania. */
    private bool $apiRefused = false;

    /** Liczba wyrobów zgłaszana w TOTAL (null = prawdziwa). */
    private ?int $reportedTotal = null;

    protected function setUp(): void
    {
        parent::setUp();
        Queue::fake();
        // 01.10.2026 12:00 w Polsce — dostępność liczy się od dziś
        $this->travelTo(Carbon::create(2026, 10, 1, 10, 0, 0, 'UTC'));
    }

    public function test_login_posts_json_and_api_calls_carry_the_token_and_customer_headers(): void
    {
        $this->addGloves();
        $this->fakeSite();
        $connector = $this->connector();

        iterator_to_array($connector->products(), false);

        $this->assertSame(
            [
                'language' => ['code' => 'EN', 'desc' => 'English', 'languageID' => 2, 'sapRawValue' => 'E'],
                'remember' => false,
                'username' => self::USER,
                'password' => self::PASSWORD,
                'appCode' => 'B2B',
                'loginAs' => '',
            ],
            $this->loginBodies[0],
        );
        $list = Http::recorded(static fn (Request $r): bool => str_contains($r->url(), 'product/searchProduct/'))->first()[0];
        $this->assertSame('POST', $list->method());
        $this->assertSame('Bearer tok-1', $list->header('Authorization')[0] ?? null);
        $this->assertSame(base64_encode(self::BP), $list->header('sir-b2b-bp')[0] ?? null);
        $this->assertSame(self::BP, $list->data()['IN_BP'] ?? null);
        $this->assertSame('X', $list->data()['IN_MAT_AVAILABLE'] ?? null);
    }

    public function test_wrong_password_is_not_fatal(): void
    {
        $this->fakeSite();
        $client = new SirB2bClient(self::USER, 'zle-haslo', 0, static function (int $ms): void {});

        try {
            $client->login();
            $this->fail('logowanie złym hasłem powinno się nie udać');
        } catch (B2bFatalException $e) {
            $this->fail('złe hasło to nie błąd krytyczny: '.$e->getMessage());
        } catch (RuntimeException $e) {
            $this->assertStringContainsString('sprawdź login (numer klienta) i hasło', $e->getMessage());
        }
        $this->assertFalse($client->isLoggedIn());
    }

    public function test_norm_row_and_plain_text_helpers(): void
    {
        $level = static fn (string $name, string $value): array => ['name' => $name, 'value' => $value];
        $this->assertSame(
            ['norm' => 'EN 388 3143X', 'levels' => ''],
            SirB2bConnector::normRow('EN 388', [$level('Abrasione', '3'), $level('Taglio', '1'), $level('Strappo', '4'), $level('Perforazione', '3'), $level('Taglio ISO', 'X')]),
        );
        $this->assertSame(['norm' => 'EN 407 X2XXXX', 'levels' => ''], SirB2bConnector::normRow('EN  407', array_map(static fn (string $v): array => $level('x', $v), ['X', '2', 'X', 'X', 'X', 'X'])));
        $this->assertSame(['norm' => 'EN ISO 20345 S2 FO SR', 'levels' => ''], SirB2bConnector::normRow(' EN ISO 20345 ', [$level('Valori', 'S2 FO SR')]));
        // ta sama wartość dwa razy — jedna
        $this->assertSame(['norm' => 'EN ISO 20345 S5 SRA', 'levels' => ''], SirB2bConnector::normRow('EN ISO 20345', [$level('Valori', ' S5 SRA'), $level('Valori', 'S5 SRA')]));
        $this->assertSame(['norm' => 'EN ISO 21420', 'levels' => ''], SirB2bConnector::normRow('EN ISO 21420', []));
        // poziomy jednoznakowe innej normy nie są kodem: „EN 166 F1” zmieniłoby znaczenie
        $this->assertSame(
            ['norm' => 'EN 166', 'levels' => "Resistenza all'impatto: F; Classe ottica: 1"],
            SirB2bConnector::normRow('EN 166', [$level("Resistenza all'impatto", 'F'), $level('Classe ottica ', '1')]),
        );
        $this->assertSame(
            ['norm' => 'EN ISO 11612', 'levels' => 'Comportamento alla Fiamma: A1+A2; Calore convettivo: B1'],
            SirB2bConnector::normRow('EN ISO 11612', [$level('Comportamento alla Fiamma', 'A1+A2'), $level('Calore convettivo', 'B1'), $level('Spruzzi di Alluminio fuso', '')]),
        );
        // EN 388 z poziomem wieloznakowym — nie kod
        $this->assertSame(['norm' => 'EN 388', 'levels' => 'Abrasione: 4; Taglio: 10'], SirB2bConnector::normRow('EN 388', [$level('Abrasione', '4'), $level('Taglio', '10')]));

        $this->assertSame("Linia 1\nLinia 2\n\n- punkt", SirB2bConnector::plainText("  Linia   1\nLinia 2\n\n\n\n- punkt\n\n"));
    }

    public function test_product_becomes_one_card_with_colour_size_rows_prices_norms_files_and_images(): void
    {
        $this->addGloves();
        $this->addTrousers();
        $this->addMask();
        $this->addKit();
        $this->fakeSite();
        $connector = $this->connector();

        $products = iterator_to_array($connector->products(), false);

        $this->assertSame(['MA9001', 'MC9002', 'FA9003', 'MC9004'], array_map(static fn (B2bRemoteProduct $p): string => $p->sku, $products));
        [$gloves, $trousers, $mask, $kit] = $products;

        $this->assertSame('ok', $gloves->raw['status']);
        $this->assertSame('MA9001B009', $gloves->remoteId);
        $this->assertSame('TESTVIC glove MA9001', $gloves->name);
        $this->assertSame('GLOVES > GLOVES LEATHER', $gloves->category);
        $this->assertSame('https://b2b.sirsafety.com/b2b/product/MA9001', $gloves->sourceUrl);
        $this->assertSame('SIR Safety System', $connector->manufacturer($gloves));
        $this->assertSame('Kolor: GREY (B0); rozmiary: 9, 10', $gloves->variantSummary);
        $this->assertSame(
            [
                ['MA9001B009', 'TESTVIC glove MA9001, GREY (B0) 9', '9', 2.0, 5.0, 'EUR', 'Dostępne od 08.10.2026'],
                ['MA9001B010', 'TESTVIC glove MA9001, GREY (B0) 10', '10', 2.0, 5.0, 'EUR', 'Na stanie: 100'],
            ],
            array_map(static fn (array $m): array => [$m['remote_id'], $m['name'], $m['size'], $m['price']->net, $m['price']->base, $m['price']->currency, $m['availability']], $gloves->members),
        );
        $this->assertSame('Dostępne od 08.10.2026: 9; Na stanie: 10', $gloves->availability);
        $price = $connector->price($gloves);
        $this->assertSame([2.0, 5.0, 'EUR'], [$price->net, $price->base, $price->currency]);
        $this->assertSame(['order_min_qty' => 12.0, 'order_step_qty' => 12.0, 'order_unit' => null, 'order_varies' => false], $price->order->slotValues());
        $this->assertSame(
            [
                [ProductIdentifier::TYPE_SOURCE_CODE, 'MA9001', null, null],
                [ProductIdentifier::TYPE_EAN, '8054528000009', 'MA9001B009', '9'],
                [ProductIdentifier::TYPE_EAN, '8054528000016', 'MA9001B010', '10'],
                [ProductIdentifier::TYPE_LEGACY_CODE, '11104', null, null],
            ],
            array_map(static fn ($i): array => [$i->type, $i->value, $i->remoteId, $i->label], $gloves->identifiers ?? []),
        );
        // długi opis, krótki opis i uwagi — dosłownie, po angielsku (tłumaczy synchronizacja)
        $this->assertSame(
            "Glove made of split leather.\nCuff: 7 cm.\n\n- High resistance.\n\nEN 388 3143X\nDEXTERITY: 5\n\nConstruction, Logistics.",
            $connector->description($gloves),
        );
        $this->assertSame(
            [
                'Informacje ze sklepu SIR | Kod wyrobu | MA9001',
                'Informacje ze sklepu SIR | Dawne kody SIR | 11104',
                'Informacje ze sklepu SIR | Dział | GLOVES',
                'Informacje ze sklepu SIR | Grupa towarowa | GLOVES LEATHER',
                'Informacje ze sklepu SIR | Kategoria ŚOI | II',
                'Informacje ze sklepu SIR | Kolory | GREY (B0)',
                'Informacje ze sklepu SIR | Rozmiary | 9, 10',
                'Informacje ze sklepu SIR | Jednostka | Pair',
                'Informacje ze sklepu SIR | Ilość w opakowaniu | 12',
                'Informacje ze sklepu SIR | Ilość w kartonie | 60',
                'Informacje ze sklepu SIR | Minimalne zamówienie | 12',
                'Informacje ze sklepu SIR | Kraj pochodzenia | IN',
                'Normy | Norma | EN 388 3143X',
                'Normy | Norma | EN ISO 21420',
            ],
            array_map(static fn ($f): string => $f->section.' | '.$f->name.' | '.$f->value, $connector->shopFields($gloves)),
        );
        $this->assertSame(
            [
                ['stpMA9001_EN.pdf', ProductDocument::KIND_DATASHEET, self::API.'export/getDS?productID=MA9001&colorID=B0&langID=2&norm_manager=false'],
                ['MA9001B0_DoC_PL.pdf', ProductDocument::KIND_CERTIFICATE, self::API.'export/getCD?prodID=MA9001B0&langID=60'],
            ],
            array_map(static fn ($d): array => [$d->title, $d->kind, $d->sourceUrl], $connector->documents($gloves)),
        );
        $this->assertSame(['https://sirweb.sirsafety.com/PortaleBridge_PRD/GetImage.ashx?id=MA9001B0'], $connector->imageUrls($gloves));

        // spodnie: tylko pozycje, które przyjmuje koszyk; pozycja bez rabatu w cenie cennikowej
        $this->assertSame('TESTSYM trousers MC9002', $trousers->name);
        $this->assertSame('Kolory: MOUSEY GREY (B4), ANTHRACITE (C2); rozmiary: 38', $trousers->variantSummary);
        $this->assertSame(
            [
                ['MC9002B438', 'MOUSEY GREY (B4) / 38', 5.0, 5.0, 'Na stanie: 3'],
                ['MC9002C238', 'ANTHRACITE (C2) / 38', 2.0, 5.0, null],
            ],
            array_map(static fn (array $m): array => [$m['remote_id'], $m['size'], $m['price']->net, $m['price']->base, $m['availability'] ?? null], $trousers->members),
        );
        $this->assertSame('Na stanie', $trousers->availability);
        $this->assertSame(2.0, $connector->price($trousers)->net);
        $this->assertFalse($connector->price($trousers)->order->restricts());
        // minimum 0 i 1 w SAP to w koszyku ten sam warunek — nie „różny dla rozmiarów”
        $this->assertFalse($connector->price($trousers)->order->varies);
        $this->assertSame('', $connector->description($trousers));
        $this->assertSame(
            ['Normy | Norma | EN ISO 13688', 'Normy | Norma | EN 343', "Normy | Poziomy EN 343 | Resistenza alla penetrazione dell'acqua: 3; Resistenza al vapore acqueo: 1"],
            array_values(array_filter(
                array_map(static fn ($f): string => $f->section.' | '.$f->name.' | '.$f->value, $connector->shopFields($trousers)),
                static fn (string $r): bool => str_starts_with($r, 'Normy'),
            )),
        );
        $this->assertContains([ProductIdentifier::TYPE_LEGACY_CODE, '30844A'], array_map(static fn ($i): array => [$i->type, $i->value], $trousers->identifiers ?? []));
        $this->assertSame(
            ['https://sirweb.sirsafety.com/PortaleBridge_PRD/GetImage.ashx?id=MC9002B4', 'https://sirweb.sirsafety.com/PortaleBridge_PRD/GetImage.ashx?id=MC9002C2'],
            $connector->imageUrls($trousers),
        );

        // maska: wyrób bez wariantów (ATTYP 00) sam jest pozycją; drugi wiersz w odpowiedzi pomijany jak w sklepie
        $this->assertSame('Mesh respirator FFP2 NR D w/v FA9003', $mask->name);
        $this->assertSame(
            [['FA9003', 'Mesh respirator FFP2 NR D w/v FA9003', 'FA9003', 1.0, 2.5, 'Brak na stanie']],
            array_map(static fn (array $m): array => [$m['remote_id'], $m['name'], $m['size'], $m['price']->net, $m['price']->base, $m['availability']], $mask->members),
        );
        $this->assertSame('', $mask->variantSummary);
        $this->assertSame(['order_min_qty' => 10.0, 'order_step_qty' => 10.0, 'order_unit' => null, 'order_varies' => false], $connector->price($mask)->order->slotValues());
        $this->assertContains('Normy | Norma | EN 149 FFP2 NR D', array_map(static fn ($f): string => $f->section.' | '.$f->name.' | '.$f->value, $connector->shopFields($mask)));
        $this->assertSame(
            [self::API.'export/getDS?productID=FA9003&colorID=null&langID=2&norm_manager=false', self::API.'export/getCD?prodID=FA9003&langID=60'],
            array_map(static fn ($d): string => $d->sourceUrl, $connector->documents($mask)),
        );
        // obrazek zastępczy sklepu to nie zdjęcie wyrobu
        $this->assertNull($connector->image($mask));

        $this->assertSame('skipped', $kit->raw['status']);
        $this->assertSame('Kit vestiario MC9004', $kit->name);

        $summary = implode("\n", $connector->runSummary());
        $this->assertStringContainsString('Lista SIR: 4 wyrobów', $summary);
        $this->assertStringContainsString('Karty: 3 (1 z rozmiarami albo kolorami w różnych cenach', $summary);
        $this->assertStringContainsString('Bez ceny konta albo bez pozycji do zamówienia (pominięte): 1, np. MC9004', $summary);
        $this->assertStringContainsString(
            'Pozycje, których koszyk sklepu nie przyjmuje (poza kartą): 5, np. MC9002B440 (czas dostawy 30 dni), MC9002C240 (niedostępna), MC9002Q738 (bez ceny), MC9002Q740 (nieprowadzona w sklepie), MC9004K1U (bez ceny)',
            $summary,
        );
        $this->assertStringContainsString('Pozycje do wyczerpania zapasu (Z1) poza kartą, bo wyrób ma inne: 1, np. MC9002P838', $summary);
        $this->assertStringContainsString('Pozycje bez rabatu konta (cena cennikowa, jak w koszyku): 1, np. MC9002B438', $summary);
        $this->assertStringContainsString('Pozycje z ceną promocyjną (na karcie cena konta bez promocji): 2, np. MA9001B010 (1,35 EUR, GL26), FA9003 (0,80 EUR, 26M2, od 20)', $summary);
        $this->assertStringContainsString('Dostępność nieodczytana (pozycje bez stanu w tym przebiegu): 1, np. MC9002C2', $summary);
        $this->assertStringContainsString('Bez opisu w sklepie: 1, np. MC9002', $summary);
    }

    public function test_texts_that_cannot_be_read_skip_the_product_instead_of_emptying_its_description(): void
    {
        $this->addGloves();
        $this->brokenTexts = ['MA9001'];
        $this->fakeSite();
        $connector = $this->connector();

        $product = iterator_to_array($connector->products(), false)[0];

        $this->assertSame('skipped', $product->raw['status']);
        $this->assertStringContainsString('opis albo normy nieodczytane', (string) $product->raw['reason']);
        $this->expectException(RuntimeException::class);
        $connector->price($product);
    }

    public function test_many_products_without_any_account_price_stop_the_run(): void
    {
        for ($i = 0; $i < 21; $i++) {
            $this->addKit('MC80'.str_pad((string) $i, 2, '0', STR_PAD_LEFT));
        }
        $this->fakeSite();

        $this->expectException(B2bFatalException::class);
        $this->expectExceptionMessage('bez ceny konta');

        iterator_to_array($this->connector()->products(), false);
    }

    public function test_list_across_pages_gives_every_product_once_and_a_changed_total_is_read_again_once(): void
    {
        for ($i = 0; $i < 5; $i++) {
            $this->addKit('MC70'.$i);
        }
        $this->fakeSite();
        $connector = new SirB2bConnector($this->client(), pageSize: 2);
        $connector->login();

        $this->assertSame(['MC700', 'MC701', 'MC702', 'MC703', 'MC704'], array_map(static fn (B2bRemoteProduct $p): string => $p->sku, iterator_to_array($connector->products(), false)));
        $this->assertCount(3, Http::recorded(static fn (Request $r): bool => str_contains($r->url(), 'product/searchProduct/')));

        $again = 0;
        $connector->onListProgress(function (string $message) use (&$again): void {
            if (str_contains($message, 'pobieram od nowa')) {
                $again++;
                $this->reportedTotal = null;
            }
        });
        $this->reportedTotal = 6;
        $this->assertCount(5, iterator_to_array($connector->products(), false));
        $this->assertSame(1, $again);

        $stuck = new SirB2bConnector($this->client(), pageSize: 2);
        $stuck->login();
        $this->reportedTotal = 6;
        $this->expectExceptionMessage('niespójna także po ponownym pobraniu');
        iterator_to_array($stuck->products(), false);
    }

    public function test_expired_session_is_renewed_once_and_a_session_never_restored_is_fatal(): void
    {
        $this->addGloves();
        $this->addMask();
        $this->fakeSite();
        $connector = $this->connector();
        $this->expireAfterCalls = 4;

        $this->assertSame(['ok', 'ok'], array_map(static fn (B2bRemoteProduct $p): string => $p->raw['status'], iterator_to_array($connector->products(), false)));
        $this->assertSame(2, $this->logins);

        $client = $this->client();
        $client->login();
        $this->apiRefused = true;

        $this->expectException(B2bFatalException::class);
        $client->listPage(0, 10);
    }

    public function test_generated_files_are_decoded_once_per_card_and_foreign_addresses_are_refused(): void
    {
        $this->addGloves();
        $this->fakeSite();
        $connector = $this->connector();
        $card = iterator_to_array($connector->products(), false)[0];
        $sheet = $connector->documents($card)[0];

        $first = $connector->documentBytes($sheet);
        $second = $connector->documentBytes($sheet);
        $image = $connector->image($card);

        $this->assertSame('application/pdf', $first['mime']);
        $this->assertStringStartsWith('%PDF-', $first['bytes']);
        $this->assertSame($first, $second);
        // synchronizacja pyta o nowy plik dwa razy — sklep generuje go raz
        $this->assertCount(1, Http::recorded(static fn (Request $r): bool => str_contains($r->url(), 'export/getDS')));
        $this->assertSame('image/png', $image?->mime);

        $this->expectExceptionMessage('adres pliku spoza');
        $connector->documentBytes(new B2bRemoteDocument('x', 'https://example.test/export/getDS?productID=MA9001'));
    }

    public function test_sync_creates_cards_in_eur_with_size_rows_norms_documents_and_images_and_a_second_run_changes_nothing(): void
    {
        Storage::fake('public');
        Storage::fake('local');
        $this->addGloves();
        $this->addTrousers();
        $this->addMask();
        $this->fakeSite();

        $result = app(B2bAccountSyncRunner::class)->run($this->account(), delayMs: 0, withImages: true);

        $this->assertSame(3, $result['created'], implode(' | ', $result['errors']));
        $gloves = Product::query()->where('sku', 'MA9001')->sole();
        $this->assertSame('SIR Safety System', $gloves->manufacturer);
        $this->assertSame('TESTVIC glove MA9001', $gloves->name);
        $this->assertStringContainsString('Glove made of split leather.', (string) $gloves->description);
        $this->assertEqualsCanonicalizing(['MA9001B009', 'MA9001B010'], B2bProductLink::query()->where('product_id', $gloves->id)->pluck('remote_id')->all());
        $slot = ProductSourcePrice::query()->where('product_id', $gloves->id)->sole();
        $this->assertSame(['2.00', '5.00', 'EUR', 12.0, 12.0], [(string) $slot->purchase_price, (string) $slot->catalog_price_net, $slot->currency, $slot->order_min_qty, $slot->order_step_qty]);
        $this->assertContains(['label' => 'EN 388', 'value' => '3143X'], $gloves->manufacturer_norms['rows'] ?? []);
        $this->assertSame('sir', $gloves->manufacturer_norms['source']['connector'] ?? null);
        $this->assertSame(
            ['stpMA9001_EN.pdf', 'MA9001B0_DoC_PL.pdf'],
            ProductDocument::query()->where('product_id', $gloves->id)->orderBy('sort_order')->pluck('title')->all(),
        );
        $this->assertSame(1, ProductImage::query()->where('product_id', $gloves->id)->count());
        $this->assertSame(
            ['8054528000009', '8054528000016'],
            ProductIdentifier::query()->where('product_id', $gloves->id)->where('type', ProductIdentifier::TYPE_EAN)->orderBy('value')->pluck('value')->all(),
        );
        $this->assertSame(['11104'], ProductIdentifier::query()->where('product_id', $gloves->id)->where('type', ProductIdentifier::TYPE_LEGACY_CODE)->pluck('value')->all());

        $trousers = Product::query()->where('sku', 'MC9002')->sole();
        $this->assertSame(
            ['ANTHRACITE (C2) / 38' => '2.00', 'MOUSEY GREY (B4) / 38' => '5.00'],
            ProductVariant::query()->where('product_id', $trousers->id)->where('kind', ProductVariant::KIND_SIZE)->orderBy('label')->pluck('purchase_price', 'label')->map(static fn ($p): string => (string) $p)->all(),
        );
        $this->assertSame('5.00', (string) ProductSourcePrice::query()->where('product_id', $trousers->id)->sole()->size_price_max);
        $mask = Product::query()->where('sku', 'FA9003')->sole();
        $this->assertSame(0, ProductImage::query()->where('product_id', $mask->id)->count());
        $rows = collect(ProductShopCard::query()->where('product_id', $mask->id)->sole()->fields)->flatMap(static fn (array $section): array => array_map(
            static fn (array $row): string => ($section['section'] ?? '').' | '.$row['name'].' | '.$row['value'],
            $section['rows'] ?? [],
        ))->all();
        $this->assertContains('Normy | Norma | EN 149 FFP2 NR D', $rows);
        // teksty po angielsku idą do tłumaczenia po zapisie
        Queue::assertPushed(TranslateB2bProductTextJob::class);
        // nazwa karty już w katalogu też czeka na tłumaczenie, dopóki jest nazwą ze źródła — odrzucone przy pierwszym
        // przebiegu zostawały po angielsku na zawsze (05.10.2026: ANACONDA CPS low shoe MB1636)
        $glovesLink = B2bProductLink::query()->where('product_id', $gloves->id)->orderBy('id')->firstOrFail();
        $this->assertTrue(TranslateB2bProductTextJob::pending($gloves->fresh(), $glovesLink, $this->connector() instanceof B2bKeepsExistingNames)['name']);

        $before = $this->snapshot();
        $second = app(B2bAccountSyncRunner::class)->run($this->account(), delayMs: 0, withImages: true);

        $this->assertSame(0, $second['created'], implode(' | ', $second['errors']));
        $this->assertSame(0, $second['updated'], implode(' | ', $second['errors']));
        $this->assertSame(3, $second['unchanged'], implode(' | ', $second['errors']));
        $this->assertSame($before, $this->snapshot());
    }

    public function test_registry_detects_sir_by_host_as_the_manufacturer_site_with_foreign_texts(): void
    {
        $registry = app(B2bConnectorRegistry::class);

        $this->assertSame('sir', $registry->keyForSites(['https://b2b.sirsafety.com/']));
        $this->assertSame('SIR Safety System', $registry->label('sir'));
        $this->assertTrue($registry->requiresPassword('sir'));
        $this->assertTrue($registry->isManufacturerSite('sir'));
        $this->assertTrue($registry->groupsSizes('sir'));
        $this->assertTrue($registry->sendsSizePrices('sir'));
        $this->assertSame(['brand' => 'SIR Safety System', 'names' => ['Norma']], $registry->shopFieldNormSource('sir'));

        $account = B2bAccount::query()->create([
            'username' => self::USER, 'password' => 'sekret', 'sites' => ['https://b2b.sirsafety.com/'],
        ]);
        $connector = $registry->make($account, 0);

        $this->assertInstanceOf(SirB2bConnector::class, $connector);
        foreach ([B2bManufacturerSite::class, B2bForeignLanguageSource::class, B2bKeepsExistingNames::class, B2bShopFieldSource::class, B2bShopFieldNormSource::class, B2bDocumentSource::class, B2bImageGallery::class, B2bGroupsSizes::class, B2bSizePriceSource::class] as $interface) {
            $this->assertInstanceOf($interface, $connector);
        }
    }

    // ---- pomocnicze ----

    private function client(): SirB2bClient
    {
        return new SirB2bClient(self::USER, self::PASSWORD, 0, static function (int $ms): void {});
    }

    private function connector(): SirB2bConnector
    {
        $connector = new SirB2bConnector($this->client());
        $connector->login();

        return $connector;
    }

    private function account(): B2bAccount
    {
        return B2bAccount::query()->firstOrCreate(
            ['username' => self::USER],
            ['password' => self::PASSWORD, 'sites' => ['https://b2b.sirsafety.com/'], 'connector' => 'sir', 'sync_images' => true],
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
            'price' => ProductSourcePrice::query()->where('product_id', $p->id)->get(['purchase_price', 'catalog_price_net', 'currency', 'size_price_max', 'availability'])->toArray(),
        ]])->all();
    }

    /**
     * Wiersz wyrobu albo wariantu z product/product (pola, których łącznik używa, i kilka innych jak w sklepie).
     *
     * @param  array<string, mixed>  $fields
     * @return array<string, mixed>
     */
    private static function row(string $father, string $id, array $fields = []): array
    {
        return array_merge([
            'MANDT' => '001', 'SATNR' => $father, 'MATNR' => $id, 'MAKTX' => '', 'COLOR' => '', 'SIZE1' => '', 'SIZE_DESC' => null,
            'SIZE_ORDER' => null, 'MEINS' => 'ST', 'MSEH3' => 'PC', 'MSEHT' => 'Piece', 'EAN11' => '', 'MTPOS_MARA' => 'Z001', 'ATTYP' => '02',
            'FSH_MG_AT1' => 'S', 'FSH_MG_AT2' => 'S', 'FSH_MG_AT3' => 'S', 'MATKL' => '', 'BISMT' => '', 'MSTAE' => 'AA', 'CLASS' => '',
            'CLASS_NAME' => '', 'PLIFZ' => '0', 'AUMNG' => '1.000', 'PRAT4' => 'X', 'PRAT5' => 'X', 'PRAT6' => '', 'PACK_QTY' => '1',
            'CART_QTY' => '10', 'DPI_CATEG' => 'II', 'WHERL' => 'CN', 'MAKTX_ITA' => '', 'PROD_SOST' => null,
        ], $fields);
    }

    /**
     * @return array<string, mixed>
     */
    private static function price(string $father, string $id, string $colour, float $list, float $account, float $promo = 0.0, string $promoCode = '', int $promoMinimum = 0): array
    {
        return [
            'WGHIER_D' => substr($father, 0, 2), 'MATNR' => $id, 'SATNR' => $father, 'COLOR' => $colour, 'KBETR' => $list,
            'B2B_KBETR' => $account, 'B2B_PM_KBETR' => $promo, 'B2B_PM_CODE' => $promoCode, 'KSCHL' => 'ZPR0', 'DATAB' => '2025-03-28',
            'DATBI' => '9999-12-31', 'B2B_AV_FOR_SALE' => true, 'B2B_PM_MIN_ORD_QTY' => $promoMinimum, 'VERPR' => 0.5,
        ];
    }

    /**
     * @return array{MATNR: string, ZID_PROD: string, DAT00: string, MNG02: int}
     */
    private static function stock(string $id, string $date, int $quantity): array
    {
        return ['MATNR' => $id, 'ZID_PROD' => substr($id, 0, 8), 'DAT00' => $date, 'MNG02' => $quantity, 'DISPO_STR' => $id.' - '.$quantity];
    }

    /** Rękawice w jednym kolorze, dwa rozmiary, sprzedaż po 12 par, EN 388 z poziomami, promocja na jednym rozmiarze. */
    private function addGloves(): void
    {
        $common = ['MAKTX' => 'TESTVIC glove', 'CLASS' => 'MA', 'CLASS_NAME' => 'GLOVES', 'MATKL' => 'MA11', 'MEINS' => 'PAA', 'MSEHT' => 'Pair',
            'AUMNG' => '12.000', 'PACK_QTY' => '12', 'CART_QTY' => '60', 'WHERL' => 'IN', 'BISMT' => '11104'];
        $this->list[] = ['MATNR' => 'MA9001', 'BISMT' => '', 'MAKTX' => 'TESTVIC glove', 'MATKL' => 'MA11', 'KDMAT' => '', 'WGHIER_D' => 'MA', 'COLOR' => ''];
        $this->products['MA9001'] = [
            self::row('MA9001', 'MA9001', $common + [
                'ATTYP' => '01', 'MAKTX_ITA' => 'Guanto TESTVIC', 'ColorString' => 'GREY',
                'ColorList' => [['Name' => 'GREY', 'ColorID' => 'B0', 'ValoreHEX' => '#626160', 'GestitoAgente' => true, 'FullDescription' => 'B0 - GREY']],
                'PriceList' => [
                    self::price('MA9001', 'MA9001B009', 'B0', 5.0, 2.0),
                    self::price('MA9001', 'MA9001B010', 'B0', 5.0, 2.0, 1.35, 'GL26'),
                ],
                'MOQGrouped' => [['AUMNG' => '12.000', 'SATNR' => 'MA9001', 'COLOR' => 'B0']],
            ]),
            self::row('MA9001', 'MA9001B009', $common + ['COLOR' => 'B0', 'SIZE1' => '9', 'SIZE_DESC' => '9', 'SIZE_ORDER' => '0004', 'EAN11' => '8054528000009']),
            self::row('MA9001', 'MA9001B010', $common + ['COLOR' => 'B0', 'SIZE1' => '10', 'SIZE_DESC' => '10', 'SIZE_ORDER' => '0005', 'EAN11' => '8054528000016']),
        ];
        $this->texts['MA9001'] = [
            'shortDescription' => "EN 388 3143X\nDEXTERITY: 5\n",
            'longDescription' => "Glove made of split leather.\nCuff:  7 cm.\n\n\n- High resistance.\n",
            'warningText' => "Construction, Logistics.\n",
        ];
        $this->norms['MA9001'] = [
            ['IDProdottoPadre' => 'MA9001', 'IDNorma' => 47, 'TestoNorma' => 'EN 388', 'PerformanceList' => [
                ['Name' => 'Abrasione', 'NormID' => 47, 'PerformanceValue' => '3'],
                ['Name' => 'Taglio', 'NormID' => 47, 'PerformanceValue' => '1'],
                ['Name' => 'Strappo', 'NormID' => 47, 'PerformanceValue' => '4'],
                ['Name' => 'Perforazione', 'NormID' => 47, 'PerformanceValue' => '3'],
                ['Name' => 'Taglio ISO', 'NormID' => 47, 'PerformanceValue' => 'X'],
            ], 'PerformanceCstTxt' => ''],
            ['IDProdottoPadre' => 'MA9001', 'IDNorma' => 196, 'TestoNorma' => 'EN ISO 21420', 'PerformanceList' => [], 'PerformanceCstTxt' => ''],
        ];
        $this->availability['MA9001B0'] = [
            // wczoraj — nie liczy się
            self::stock('MA9001B010', '20260930', 999),
            self::stock('MA9001B010', '20261001', 100),
            self::stock('MA9001B010', '20261008', 100),
            self::stock('MA9001B009', '20261001', 0),
            self::stock('MA9001B009', '20261008', 50),
            self::stock('MA9001B009', '99991231', 7),
        ];
        $this->images[] = 'MA9001B0';
    }

    /**
     * Spodnie w czterech kolorach × dwa rozmiary: do zamówienia tylko B4/38 (bez rabatu — cena cennikowa) i C2/38 (minimum
     * 0 w SAP = ten sam warunek co 1); B4/40 z czasem dostawy, C2/40 wycofany (Z2), Q7/38 bez ceny, Q7/40 nieprowadzony,
     * P8/38 do wyczerpania zapasu (Z1) w cenie resztek — poza kartą, bo są inne pozycje. Dostępność C2 kończy się błędem.
     */
    private function addTrousers(): void
    {
        $common = ['MAKTX' => 'TESTSYM trousers', 'CLASS' => 'MC', 'CLASS_NAME' => 'CLOTHING', 'MATKL' => 'MC11', 'BISMT' => '30844,30844A, 30844'];
        $variant = static fn (string $id, string $colour, string $size, array $extra = []): array => self::row('MC9002', $id, $common + $extra + ['COLOR' => $colour, 'SIZE1' => $size, 'SIZE_DESC' => $size, 'SIZE_ORDER' => '000'.($size === '38' ? 3 : 4)]);
        $this->list[] = ['MATNR' => 'MC9002', 'BISMT' => '30844', 'MAKTX' => 'TESTSYM trousers', 'MATKL' => 'MC11', 'KDMAT' => '', 'WGHIER_D' => 'MC', 'COLOR' => ''];
        $this->products['MC9002'] = [
            self::row('MC9002', 'MC9002', $common + [
                'ATTYP' => '01',
                'ColorList' => [
                    ['Name' => 'MOUSEY GREY', 'ColorID' => 'B4'],
                    ['Name' => 'ANTHRACITE', 'ColorID' => 'C2'],
                    ['Name' => 'NAVY', 'ColorID' => 'Q7'],
                    ['Name' => 'ROYAL', 'ColorID' => 'P8'],
                ],
                'PriceList' => [
                    self::price('MC9002', 'MC9002B438', 'B4', 5.0, 2.0),
                    self::price('MC9002', 'MC9002B440', 'B4', 5.0, 2.0),
                    self::price('MC9002', 'MC9002C238', 'C2', 5.0, 2.0),
                    self::price('MC9002', 'MC9002C240', 'C2', 5.0, 2.0),
                    self::price('MC9002', 'MC9002Q740', 'Q7', 5.0, 2.0),
                    self::price('MC9002', 'MC9002P838', 'P8', 1.0, 0.4),
                ],
            ]),
            $variant('MC9002B438', 'B4', '38', ['PRAT6' => 'X', 'EAN11' => '8054528120841']),
            $variant('MC9002B440', 'B4', '40', ['PLIFZ' => '30']),
            $variant('MC9002C238', 'C2', '38', ['EAN11' => '8054528120858', 'AUMNG' => '0.000']),
            $variant('MC9002C240', 'C2', '40', ['MSTAE' => 'Z2']),
            $variant('MC9002Q738', 'Q7', '38'),
            $variant('MC9002Q740', 'Q7', '40', ['FSH_MG_AT2' => 'N']),
            $variant('MC9002P838', 'P8', '38', ['MSTAE' => 'Z1', 'PRAT6' => 'X', 'PROD_SOST' => 'MC9002C238']),
        ];
        $this->texts['MC9002'] = ['shortDescription' => '', 'longDescription' => '', 'warningText' => ''];
        $this->norms['MC9002'] = [
            ['IDProdottoPadre' => 'MC9002', 'IDNorma' => 133, 'TestoNorma' => 'EN ISO 13688', 'PerformanceList' => [], 'PerformanceCstTxt' => ''],
            ['IDProdottoPadre' => 'MC9002', 'IDNorma' => 30, 'TestoNorma' => 'EN 343', 'PerformanceList' => [
                ['Name' => "Resistenza alla penetrazione dell'acqua", 'PerformanceValue' => '3'],
                ['Name' => 'Resistenza al vapore acqueo', 'PerformanceValue' => '1'],
            ]],
        ];
        $this->availability['MC9002B4'] = [self::stock('MC9002B438', '20261001', 3)];
        $this->images[] = 'MC9002B4';
        $this->images[] = 'MC9002C2';
    }

    /** Maska bez wariantów (ATTYP 00) po 10 sztuk, do wyczerpania zapasu (jedyna pozycja — zostaje), promocja z minimum, bez zdjęcia i bez stanu. */
    private function addMask(): void
    {
        $this->list[] = ['MATNR' => 'FA9003', 'BISMT' => '', 'MAKTX' => 'Mesh respirator FFP2 NR D w/v', 'MATKL' => 'FA13', 'KDMAT' => '', 'WGHIER_D' => 'FA', 'COLOR' => ''];
        $fields = ['MAKTX' => 'Mesh respirator FFP2 NR D w/v', 'CLASS' => 'FA', 'CLASS_NAME' => 'RESPIRATORY PROTECTION', 'MATKL' => 'FA13',
            'AUMNG' => '10.000', 'PACK_QTY' => '10', 'CART_QTY' => '120', 'DPI_CATEG' => 'III', 'EAN11' => '8054528292418', 'ATTYP' => '00', 'MSTAE' => 'Z1'];
        $this->products['FA9003'] = [
            self::row('FA9003', 'FA9003', $fields + [
                'ColorList' => [],
                'PriceList' => [self::price('FA9003', 'FA9003', '', 2.5, 1.0, 0.8, '26M2', 20)],
            ]),
            // sklep bierze tylko pierwszy wiersz wyrobu bez wariantów
            self::row('FA9003', 'FA9003X', $fields),
        ];
        $this->texts['FA9003'] = ['shortDescription' => "EN 149 FFP2 NR D\nMesh disposable respirator.\n", 'longDescription' => "Mesh disposable respirator.\n", 'warningText' => ''];
        $this->norms['FA9003'] = [['IDProdottoPadre' => 'FA9003', 'IDNorma' => 87, 'TestoNorma' => 'EN 149', 'PerformanceList' => [
            ['Name' => 'Valori', 'NormID' => 87, 'PerformanceValue' => 'FFP2 NR D'],
        ]]];
        $this->availability['FA9003'] = [self::stock('FA9003', '20261001', 0)];
    }

    /** Zestaw bez ceny konta (pusty angielski opis, nazwa tylko po włosku). */
    private function addKit(string $id = 'MC9004'): void
    {
        $this->list[] = ['MATNR' => $id, 'BISMT' => '', 'MAKTX' => '', 'MATKL' => 'MC01', 'KDMAT' => '', 'WGHIER_D' => 'MC', 'COLOR' => ''];
        $this->products[$id] = [
            self::row($id, $id, ['ATTYP' => '01', 'MAKTX_ITA' => 'Kit vestiario', 'CLASS' => 'MC', 'ColorList' => [], 'PriceList' => []]),
            self::row($id, $id.'K1U', ['COLOR' => 'K1', 'SIZE_DESC' => 'U']),
        ];
        $this->texts[$id] = ['shortDescription' => '', 'longDescription' => '', 'warningText' => ''];
        $this->norms[$id] = [];
    }

    // ---- atrapa sklepu ----

    private function fakeSite(): void
    {
        Http::fake(function (Request $request) {
            $url = $request->url();
            $host = (string) parse_url($url, PHP_URL_HOST);
            $path = (string) parse_url($url, PHP_URL_PATH);
            parse_str((string) parse_url($url, PHP_URL_QUERY), $query);

            if ($host === 'sirweb.sirsafety.com' && $path === '/PortaleBridge_PRD/GetImage.ashx') {
                $id = (string) ($query['id'] ?? '');

                return Http::response(self::png(in_array($id, $this->images, true) ? $id : 'zaslepka'), 200, ['Content-Type' => 'image/png']);
            }
            if ($host !== 'app.sirsafety.com' || ! str_starts_with($path, '/sirapi/api/')) {
                return Http::response('', 404);
            }
            $endpoint = substr($path, strlen('/sirapi/api/'));

            if ($endpoint === 'login/login/') {
                $data = json_decode($request->body(), true) ?: [];
                $this->loginBodies[] = $data;
                if (($data['username'] ?? null) === self::USER && ($data['password'] ?? null) === self::PASSWORD && ($data['appCode'] ?? null) === 'B2B') {
                    $this->logins++;
                    $token = 'tok-'.$this->logins;
                    $this->tokens[] = $token;
                    $this->calls = 0;

                    return Http::response(['net_token' => $token, 'uidata' => [
                        'email' => 'zakupy@example.test', 'username' => self::USER, 'language' => $data['language'] ?? null,
                        'allowedFunctions' => ['DISPONIBILITA', 'STORICO', 'ORDINI'], 'bp' => self::BP, 'appCode' => 'B2B',
                    ]]);
                }

                return Http::response(['message' => 'Invalid credentials'], 400);
            }

            $token = substr((string) ($request->header('Authorization')[0] ?? ''), strlen('Bearer '));
            if ($this->apiRefused || ! in_array($token, $this->tokens, true) || ($request->header('sir-b2b-bp')[0] ?? '') !== base64_encode(self::BP)) {
                return Http::response(['message' => 'Unauthorized'], 401);
            }
            $this->calls++;
            if ($this->expireAfterCalls > 0 && $this->calls > $this->expireAfterCalls) {
                $this->expireAfterCalls = 0;
                $this->tokens = [];

                return Http::response(['message' => 'Unauthorized'], 401);
            }

            $filter = static function (string $name) use ($query): string {
                foreach (json_decode((string) ($query['filters'] ?? '[]'), true) ?: [] as $f) {
                    if (($f['field'] ?? null) === $name) {
                        return (string) $f['value'];
                    }
                }

                return '';
            };

            return match ($endpoint) {
                'product/searchProduct/' => (function () use ($request): mixed {
                    $data = json_decode($request->body(), true) ?: [];

                    return Http::response([
                        'TOTAL' => $this->reportedTotal ?? count($this->list),
                        'OUT_DATA' => array_slice($this->list, (int) $data['IN_SKIP'], (int) $data['IN_TAKE']),
                    ]);
                })(),
                'product/merchandiseGroups' => Http::response(['data' => [
                    ['F4Set' => ['results' => [['Atwrt' => 'MA', 'Atwtb' => 'GLOVES'], ['Atwrt' => 'MC', 'Atwtb' => 'CLOTHING'], ['Atwrt' => 'FA', 'Atwtb' => 'RESPIRATORY PROTECTION']]]],
                    ['F4Set' => ['results' => [['Atwrt' => 'MA11', 'Atwtb' => 'GLOVES LEATHER'], ['Atwrt' => 'FA13', 'Atwtb' => 'RESPIRATORY PROTECTION DISPOSABLE MASKS FFP2']]]],
                ]]),
                'product/product/' => Http::response(['total' => 0, 'last_page' => null, 'data' => $this->products[$filter('SATNR')] ?? []]),
                'product/productTexts/' => in_array($query['fatherID'] ?? '', $this->brokenTexts, true)
                    ? Http::response(['message' => 'error'], 500)
                    : Http::response($this->texts[$query['fatherID'] ?? ''] ?? ['shortDescription' => '', 'longDescription' => '', 'warningText' => '']),
                'product/productNorms' => Http::response(['total' => 0, 'last_page' => null, 'data' => $this->norms[$filter('IDProdottoPadre')] ?? []]),
                'product/availability/' => isset($this->availability[$query['fatherColorID'] ?? ''])
                    ? Http::response(['total' => null, 'last_page' => null, 'data' => $this->availability[$query['fatherColorID']]])
                    : Http::response(['message' => 'error'], 500),
                'export/getDS', 'export/getCD' => Http::response(['file' => base64_encode('%PDF-1.4 test '.$endpoint.' '.http_build_query($query))]),
                default => Http::response('', 404),
            };
        });
    }

    private static function png(string $seed): string
    {
        $image = imagecreatetruecolor(1, 1);
        imagesetpixel($image, 0, 0, crc32($seed) & 0xFFFFFF);
        ob_start();
        imagepng($image);

        return (string) ob_get_clean();
    }
}
