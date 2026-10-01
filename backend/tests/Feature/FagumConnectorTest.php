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
use App\Services\B2b\B2bImageGallery;
use App\Services\B2b\B2bManufacturerSite;
use App\Services\B2b\B2bRemoteProduct;
use App\Services\B2b\B2bShopFieldNormSource;
use App\Services\B2b\B2bShopFieldSource;
use App\Services\B2b\B2bSizePriceSource;
use App\Services\B2b\FagumB2bClient;
use App\Services\B2b\FagumB2bConnector;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use RuntimeException;
use Tests\TestCase;

/**
 * Łącznik b2b.fagum.pl (Comarch B2B, ERP XL) na atrapie witryny (Http::fake). Kształt odpowiedzi odwzorowuje konto
 * z 01.10.2026: logowanie JSON-em pod /account/login (customerName = NIP, userName = pracownik, LoginConfirmation),
 * sesja w ciasteczku, /account/isloggedin → true; bez sesji API odpowiada 401. Lista /api/items/articleListXl/
 * (jeden towar na model, paging.totalPages), drzewo grup /api/items/treeXl (POST), dane towaru
 * getArticleGeneralInfoXl (symbol, kod z rozmiarem, EAN pary, opis HTML z <br/>, producent = nazwa spółki),
 * atrybuty z plikami i zdjęciami (attributesXl), warianty rozmiarów (getArticleVariantsDetailsXl) i cena
 * (articleFromListXl: cena za karton „krt”, unitNetPrice za parę, baseNetPrice za karton).
 *
 * Wszystkie dane (kody, EAN, ceny, opisy, NIP) są SYNTETYCZNE.
 */
final class FagumConnectorTest extends TestCase
{
    use RefreshDatabase;

    private const NIP = '1234567890';

    private const USER = 'Jan Testowy';

    private const PASSWORD = 'dobre-haslo';

    /** @var array<int, array<string, mixed>> modele wg id towaru z listy, w kolejności listy */
    private array $models = [];

    /** @var array<int, array<string, mixed>> towary (rozmiary) wg id: info, price */
    private array $articles = [];

    /** @var list<string> ważne sesje */
    private array $sessions = [];

    private int $logins = 0;

    /** Sesja wygasa po tylu zapytaniach do API (0 = nigdy). */
    private int $expireAfterApiCalls = 0;

    private int $apiCalls = 0;

    /** @var list<array<string, mixed>> */
    private array $loginBodies = [];

    protected function setUp(): void
    {
        parent::setUp();
        Queue::fake();
    }

    public function test_login_posts_the_form_as_json_and_confirms_the_session(): void
    {
        $this->fakeSite();
        $client = $this->client();

        $client->login();

        $this->assertTrue($client->isLoggedIn());
        $this->assertSame(
            ['customerName' => self::NIP, 'userName' => self::USER, 'password' => self::PASSWORD, 'rememberMe' => false, 'companyGroupId' => 0, 'LoginConfirmation' => true],
            $this->loginBodies[0],
        );
        $check = Http::recorded(fn (Request $r): bool => str_ends_with($r->url(), '/account/isloggedin'))->first()[0];
        $this->assertStringContainsString('ASP.NET_SessionId=s-1', $check->header('Cookie')[0]);
        $this->assertStringContainsString('_culture=pl-PL', $check->header('Cookie')[0]);
    }

    public function test_wrong_password_is_not_fatal_and_names_the_fields_to_check(): void
    {
        $this->fakeSite();
        $client = new FagumB2bClient(self::NIP, self::USER, 'zle-haslo', 0, static function (int $ms): void {});

        try {
            $client->login();
            $this->fail('logowanie złym hasłem powinno się nie udać');
        } catch (B2bFatalException $e) {
            $this->fail('złe hasło to nie błąd krytyczny: '.$e->getMessage());
        } catch (RuntimeException $e) {
            $this->assertStringContainsString('HTTP 401', $e->getMessage());
            $this->assertStringContainsString('NIP', $e->getMessage());
        }
        $this->assertFalse($client->isLoggedIn());
    }

    public function test_account_without_contractor_code_cannot_log_in(): void
    {
        $this->fakeSite();

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Kod kontrahenta');

        (new FagumB2bClient('', self::USER, self::PASSWORD, 0, static function (int $ms): void {}))->login();
    }

    public function test_price_is_per_pair_with_the_base_price_converted_from_the_carton(): void
    {
        $price = FagumB2bConnector::parsePrice(self::priceJson(47.47, 69.81, 6, locked: true));

        $this->assertNotNull($price);
        $this->assertSame(47.47, $price['net']);
        $this->assertSame(69.81, $price['base']);
        $this->assertSame('PLN', $price['currency']);
        $this->assertSame('para', $price['unit']);
        $this->assertSame('krt = 6 para', $price['pack']);
        $this->assertSame(6.0, $price['pack_qty']);
        $this->assertTrue($price['locked']);
        // towar poza cennikiem konta — brak ceny
        $outside = self::priceJson(47.47, 69.81, 6);
        $outside['itemExistsInCurrentPriceList'] = false;
        $this->assertNull(FagumB2bConnector::parsePrice($outside));
    }

    public function test_description_html_is_turned_into_lines_verbatim(): void
    {
        $this->assertSame(
            "Kalosze ocieplane.\nWykonane z tworzywa EVA & futra.\n- SRC: odporność na poślizg",
            FagumB2bConnector::descriptionText('Kalosze  ocieplane. <br/>Wykonane z tworzywa EVA &amp; futra.<br/><br/>- SRC: odporność na poślizg<br/>'),
        );
    }

    public function test_model_with_sizes_is_one_card_with_size_members_prices_identifiers_and_order_condition(): void
    {
        $this->addBoots();
        $this->fakeSite();
        $connector = $this->connector();

        $products = iterator_to_array($connector->products(), false);

        $this->assertCount(1, $products);
        $card = $products[0];
        $this->assertSame('9001-TEST-CZA', $card->sku);
        $this->assertSame('9001 Trzewik bezpieczny S3 testowy czarny', $card->name);
        $this->assertSame('Obuwie Zawodowe i Bezpieczne', $card->category);
        $this->assertSame('https://b2b.fagum.pl/itemdetails/501', $card->sourceUrl);
        $this->assertSame('501', $card->remoteId);
        // rozmiar 43 poza cennikiem konta — poza kartą
        $this->assertSame(
            [['501', 'F-9001-TEST-41-BLA-1', '41', '100.00', '147.06', 'Dostępny'], ['502', 'F-9001-TEST-42-BLA-1', '42', '100.00', '147.06', 'Dostępny'], ['503', 'F-9001-TEST-44-BLA-1', '44', '110.00', '161.76', 'Na zamówienie']],
            array_map(static fn (array $m): array => [$m['remote_id'], $m['sku'], $m['size'], sprintf('%.2F', $m['price']->net), sprintf('%.2F', $m['price']->base), $m['availability']], $card->members),
        );
        $this->assertSame('Dostępny: 41, 42; Na zamówienie: 44', $card->availability);
        $this->assertSame('Rozmiary: 41 (F-9001-TEST-41-BLA-1); 42 (F-9001-TEST-42-BLA-1); 44 (F-9001-TEST-44-BLA-1)', $card->variantSummary);
        $price = $connector->price($card);
        $this->assertSame(100.0, $price->net);
        $this->assertSame(147.06, $price->base);
        // sprzedawany tylko w kartonach po 5 par
        $this->assertSame([5.0, 5.0, 'para', false], [$price->order?->min, $price->order?->step, $price->order?->unit, $price->order?->varies]);
        $this->assertSame('Fagum-Stomil', $connector->manufacturer($card));
        $this->assertSame(
            [
                [ProductIdentifier::TYPE_MODEL_CODE, '9001-TEST-CZA', null],
                [ProductIdentifier::TYPE_MANUFACTURER_CODE, 'F-9001-TEST-41-BLA-1', '501'],
                [ProductIdentifier::TYPE_EAN, '5900000000411', '501'],
                [ProductIdentifier::TYPE_MANUFACTURER_CODE, 'F-9001-TEST-42-BLA-1', '502'],
                [ProductIdentifier::TYPE_EAN, '5900000000421', '502'],
                [ProductIdentifier::TYPE_MANUFACTURER_CODE, 'F-9001-TEST-44-BLA-1', '503'],
                [ProductIdentifier::TYPE_EAN, '5900000000441', '503'],
            ],
            array_map(static fn ($i): array => [$i->type, $i->value, $i->remoteId], $card->identifiers ?? []),
        );
        $this->assertSame(
            "Obuwie bezpieczne z podnoskiem kompozytowym.\nPodeszwa odporna na poślizg SRC.",
            $connector->description($card),
        );
        $fields = array_map(static fn ($f): string => $f->section.' | '.$f->name.' | '.$f->value, $connector->shopFields($card));
        $this->assertSame(
            [
                'Informacje ze sklepu Fagum | Producent | "FAGUM - STOMIL" SPÓŁKA Z OGRANICZONĄ ODPOWIEDZIALNOŚCIĄ',
                'Informacje ze sklepu Fagum | Symbol | 9001-TEST-CZA',
                'Informacje ze sklepu Fagum | Cena za | para',
                'Informacje ze sklepu Fagum | Opakowanie | krt = 5 para',
                'Atrybuty | Wierzch | Skóra licowa',
                'Atrybuty | Spełnia normy | EN ISO 20345:2022 S3 SRC',
                'Atrybuty | Właściwości użytkowe | Antypoślizgowa podeszwa; Podnosek kompozytowy',
            ],
            $fields,
        );
        // pliki: karta produktu pierwsza, odnośnik zewnętrzny pominięty
        $this->assertSame(
            [['9001 TEST.pdf', ProductDocument::KIND_DATASHEET], ['Deklaracja Zgodności UE 9001.pdf', ProductDocument::KIND_CERTIFICATE], ['Instrukcja użytkowania.pdf', ProductDocument::KIND_MANUAL]],
            array_map(static fn ($d): array => [$d->title, $d->kind], $connector->documents($card)),
        );
        $this->assertSame('https://b2b.fagum.pl/filehandler.ashx?id=71&fileName=9001%20TEST.pdf&customerData=h%2F71%2B', $connector->documents($card)[0]->sourceUrl);
        $this->assertSame(
            ['https://b2b.fagum.pl/imagehandler.ashx?id=81&width=2000&height=2000', 'https://b2b.fagum.pl/imagehandler.ashx?id=82&width=2000&height=2000'],
            $connector->imageUrls($card),
        );
        $summary = implode("\n", $connector->runSummary());
        $this->assertStringContainsString('Lista Fagum: 1 modeli', $summary);
        $this->assertStringContainsString('rozmiarami bez ceny konta (te rozmiary poza kartami): 1, np. F-9001-TEST-41-BLA-1', $summary);
        $this->assertStringContainsString('Karty: 1 (1 z rozmiarami w różnych cenach', $summary);
    }

    public function test_shared_symbol_uses_the_article_code_and_lukpol_and_single_articles_are_cards(): void
    {
        $this->addSandal(601, 'L-1102-36-BLA-1', 'PRZEDSIĘBIORSTWO PRODUKCYJNO HANDLOWO USŁUGOWE ŁUKPOL SPÓŁKA Z OGRANICZONĄ ODPOWIEDZIALNOŚCIĄ', 'Techwork');
        $this->addSandal(701, 'FL-1102-36-BLA-1', '"FAGUM - STOMIL" SPÓŁKA Z OGRANICZONĄ ODPOWIEDZIALNOŚCIĄ', null);
        $this->addAssortment();
        $this->fakeSite();
        $connector = $this->connector();

        $products = iterator_to_array($connector->products(), false);

        $this->assertSame(['L-1102-36-BLA-1', 'FL-1102-36-BLA-1', '062-TEST-MIX'], array_map(static fn (B2bRemoteProduct $p): string => $p->sku, $products));
        $this->assertSame(['Łukpol', 'Fagum-Stomil'], [$connector->manufacturer($products[0]), $connector->manufacturer($products[1])]);
        $this->assertContains('Informacje ze sklepu Fagum | Marka | Techwork', array_map(static fn ($f): string => $f->section.' | '.$f->name.' | '.$f->value, $connector->shopFields($products[0])));
        // asortyment w kartonie bez wariantów: jedna pozycja, bez ograniczenia zamawiania (jednostka nie jest zablokowana)
        $assortment = $products[2];
        $this->assertSame([], $assortment->members);
        $this->assertSame('801', $assortment->remoteId);
        $price = $connector->price($assortment);
        $this->assertSame(37.38, $price->net);
        $this->assertFalse($price->order?->restricts() ?? true);
        $this->assertNull($assortment->category);
        $this->assertStringContainsString('Ten sam symbol w kilku modelach — SKU karty = kod towaru wiodącego: 2', implode("\n", $connector->runSummary()));
    }

    public function test_expired_session_is_renewed_once(): void
    {
        $this->addBoots();
        $this->fakeSite();
        $connector = $this->connector();
        $this->expireAfterApiCalls = 3;

        $products = iterator_to_array($connector->products(), false);

        $this->assertCount(1, $products);
        $this->assertSame(2, $this->logins);
    }

    public function test_image_and_file_without_a_session_log_in_again_instead_of_saving_an_empty_or_login_page(): void
    {
        $this->addBoots();
        $this->fakeSite();
        $connector = $this->connector();
        $card = iterator_to_array($connector->products(), false)[0];

        $this->sessions = [];
        $image = $connector->imageAt($connector->imageUrls($card)[0]);
        $this->sessions = [];
        $file = $connector->documentBytes($connector->documents($card)[0]);

        $this->assertNotNull($image);
        $this->assertSame('image/jpeg', $image->mime);
        $this->assertNotSame('', $image->bytes);
        $this->assertSame('application/pdf', $file['mime']);
        $this->assertStringStartsWith('%PDF-', $file['bytes']);
        $this->assertSame(3, $this->logins);
    }

    public function test_many_models_without_any_account_price_stop_the_run(): void
    {
        for ($i = 0; $i < 21; $i++) {
            $this->addSandal(900 + $i, 'F-BEZ-'.$i, '"FAGUM - STOMIL" SPÓŁKA Z OGRANICZONĄ ODPOWIEDZIALNOŚCIĄ', null, symbol: 'BEZ-'.$i, inPriceList: false);
        }
        $this->fakeSite();

        $this->expectException(B2bFatalException::class);
        $this->expectExceptionMessage('bez żadnej ceny konta');

        iterator_to_array($this->connector()->products(), false);
    }

    public function test_sync_creates_the_card_with_sizes_norms_documents_images_and_a_second_run_changes_nothing(): void
    {
        Storage::fake('public');
        Storage::fake('local');
        $this->addBoots();
        $this->fakeSite();

        $result = app(B2bAccountSyncRunner::class)->run($this->account(), delayMs: 0, withImages: true);

        $this->assertSame(1, $result['created'], implode(' | ', $result['errors']));
        $card = Product::query()->where('sku', '9001-TEST-CZA')->sole();
        $this->assertSame('Fagum-Stomil', $card->manufacturer);
        $this->assertStringContainsString('podnoskiem kompozytowym', (string) $card->description);
        $this->assertSame(['501', '502', '503'], B2bProductLink::query()->where('product_id', $card->id)->orderBy('remote_id')->pluck('remote_id')->all());
        $slot = ProductSourcePrice::query()->where('product_id', $card->id)->sole();
        $this->assertSame('100.00', (string) $slot->purchase_price);
        $this->assertSame('147.06', (string) $slot->catalog_price_net);
        $this->assertSame('110.00', (string) $slot->size_price_max);
        $this->assertEquals([5, 5, 'para'], [(float) $slot->order_min_qty, (float) $slot->order_step_qty, $slot->order_unit]);
        $this->assertSame(
            [['41', '100.00'], ['42', '100.00'], ['44', '110.00']],
            ProductVariant::query()->where('product_id', $card->id)->where('kind', ProductVariant::KIND_SIZE)->orderBy('sort_order')->get()
                ->map(static fn (ProductVariant $v): array => [$v->label, (string) $v->purchase_price])->all(),
        );
        $this->assertSame('fagum', $card->manufacturer_norms['source']['connector'] ?? null);
        $this->assertSame(
            ['9001 TEST.pdf', 'Deklaracja Zgodności UE 9001.pdf', 'Instrukcja użytkowania.pdf'],
            ProductDocument::query()->where('product_id', $card->id)->orderBy('sort_order')->pluck('title')->all(),
        );
        $this->assertSame(2, ProductImage::query()->where('product_id', $card->id)->count());
        $rows = collect(ProductShopCard::query()->where('product_id', $card->id)->sole()->fields)->flatMap(static fn (array $section): array => array_map(
            static fn (array $row): string => ($section['section'] ?? '').' | '.$row['name'].' | '.$row['value'],
            $section['rows'] ?? [],
        ))->all();
        $this->assertContains('Atrybuty | Spełnia normy | EN ISO 20345:2022 S3 SRC', $rows);
        $this->assertSame(
            '5900000000421',
            ProductIdentifier::query()->where('product_id', $card->id)->where('type', ProductIdentifier::TYPE_EAN)->where('variant_label', '42')->value('value'),
        );

        $before = $this->snapshot();
        $second = app(B2bAccountSyncRunner::class)->run($this->account(), delayMs: 0, withImages: true);

        $this->assertSame(0, $second['created'], implode(' | ', $second['errors']));
        $this->assertSame(0, $second['updated'], implode(' | ', $second['errors']));
        $this->assertSame(1, $second['unchanged'], implode(' | ', $second['errors']));
        $this->assertSame($before, $this->snapshot());
    }

    public function test_registry_detects_fagum_by_host_as_the_manufacturer_site(): void
    {
        $registry = app(B2bConnectorRegistry::class);

        $this->assertSame('fagum', $registry->keyForSites(['https://b2b.fagum.pl/login']));
        $this->assertSame('Fagum-Stomil', $registry->label('fagum'));
        $this->assertTrue($registry->requiresPassword('fagum'));
        $this->assertTrue($registry->isManufacturerSite('fagum'));
        $this->assertTrue($registry->sendsSizePrices('fagum'));
        $this->assertSame(['brand' => 'Fagum-Stomil', 'names' => ['Spełnia normy']], $registry->shopFieldNormSource('fagum'));

        $account = B2bAccount::query()->create([
            'username' => self::USER, 'contractor_code' => self::NIP, 'password' => 'sekret', 'sites' => ['https://b2b.fagum.pl'],
        ]);
        $connector = $registry->make($account, 0);

        $this->assertInstanceOf(FagumB2bConnector::class, $connector);
        foreach ([B2bManufacturerSite::class, B2bShopFieldSource::class, B2bShopFieldNormSource::class, B2bDocumentSource::class, B2bImageGallery::class, B2bSizePriceSource::class] as $interface) {
            $this->assertInstanceOf($interface, $connector);
        }
    }

    // ---- pomocnicze ----

    private function client(): FagumB2bClient
    {
        return new FagumB2bClient(self::NIP, self::USER, self::PASSWORD, 0, static function (int $ms): void {});
    }

    private function connector(): FagumB2bConnector
    {
        $connector = new FagumB2bConnector($this->client());
        $connector->login();

        return $connector;
    }

    private function account(): B2bAccount
    {
        return B2bAccount::query()->firstOrCreate(
            ['username' => self::USER],
            ['contractor_code' => self::NIP, 'password' => self::PASSWORD, 'sites' => ['https://b2b.fagum.pl'], 'connector' => 'fagum', 'sync_images' => true],
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
            'price' => ProductSourcePrice::query()->where('product_id', $p->id)->get(['purchase_price', 'catalog_price_net', 'size_price_max', 'availability'])->toArray(),
            'sizes' => ProductVariant::query()->where('product_id', $p->id)->orderBy('id')
                ->get(['remote_id', 'label', 'purchase_price', 'availability', 'removed_at', 'updated_at'])->toArray(),
        ]])->all();
    }

    /**
     * Trzewik w czterech rozmiarach: 41 i 42 w jednej cenie, 44 droższy i na zamówienie, 43 poza cennikiem konta;
     * sprzedawany tylko w kartonach po 5 par. Na liście towar wiodący = rozmiar 41.
     */
    private function addBoots(): void
    {
        $fagum = '"FAGUM - STOMIL" SPÓŁKA Z OGRANICZONĄ ODPOWIEDZIALNOŚCIĄ';
        $sizes = [501 => ['41', 100.0, 147.06, true], 502 => ['42', 100.0, 147.06, true], 504 => ['43', 100.0, 147.06, false], 503 => ['44', 110.0, 161.76, true]];
        foreach ($sizes as $id => [$size, $net, $base, $inList]) {
            $price = self::priceJson($net, $base, 5, locked: true);
            $price['itemExistsInCurrentPriceList'] = $inList;
            $this->articles[$id] = [
                'info' => self::info($id, '9001 Trzewik bezpieczny S3 testowy czarny', 'F-9001-TEST-'.$size.'-BLA-1', '9001-TEST-CZA', '5900000000'.$size.'1', $fagum, null,
                    'Obuwie bezpieczne z podnoskiem kompozytowym.  <br/>Podeszwa odporna na poślizg SRC.<br/>'),
                'price' => $price,
            ];
        }
        $this->models[501] = [
            'list' => ['id' => 501, 'name' => '9001 Trzewik bezpieczny S3 testowy czarny', 'code' => 'F-9001-TEST-41-BLA-1', 'status' => 'Available'],
            'groups' => ['Obuwie Zawodowe i Bezpieczne', 'Polecane'],
            'variants' => [
                'headerVariants' => [],
                'expandedVariant' => [
                    'header' => ['propertyId' => 7, 'translatedName' => 'Rozmiar'],
                    'expandedValues' => [
                        self::value(501, '41', 'Available', 'Checked'),
                        self::value(502, '42', 'Available'),
                        self::value(504, '43', 'Available'),
                        self::value(503, '44', 'AvailableOnDemand'),
                    ],
                ],
            ],
            'attributes' => [
                'articleAttributes' => [
                    self::attribute('Wierzch', 'Skóra licowa'),
                    self::attribute('Wyściółka', ''),
                    self::attribute('Spełnia normy', 'EN ISO 20345:2022 S3 SRC'),
                    self::attribute('Rozmiar', '41'),
                    self::attribute('Dostępność', 'Dostępny'),
                    self::attribute('Właściwości użytkowe', 'Antypoślizgowa podeszwa'),
                    self::attribute('Właściwości użytkowe', 'Podnosek kompozytowy'),
                ],
                'articleAttachments' => [
                    self::attachment(72, 'Instrukcja użytkowania.pdf'),
                    self::attachment(73, 'Deklaracja Zgodności UE 9001.pdf'),
                    self::attachment(71, '9001 TEST.pdf'),
                    ['id' => 74, 'fullName' => 'Film', 'extension' => '', 'type' => 1, 'hash' => null, 'url' => 'https://example.test/film', 'size' => 0],
                ],
                'articleImages' => [
                    ['imageType' => 1, 'imageId' => 81, 'imageUrl' => null],
                    ['imageType' => 1, 'imageId' => 82, 'imageUrl' => null],
                    ['imageType' => 1, 'imageId' => 81, 'imageUrl' => null],
                ],
                'articleMovies' => [],
            ],
        ];
    }

    /**
     * Sandał w jednym rozmiarze (warianty z jedną wartością) — dwa modele mogą mieć ten sam symbol (L-/FL-).
     */
    private function addSandal(int $id, string $code, string $company, ?string $brand, string $symbol = '1102-DWG-CZA', bool $inPriceList = true): void
    {
        $price = self::priceJson(85.39, 125.57, 5);
        $price['itemExistsInCurrentPriceList'] = $inPriceList;
        $this->articles[$id] = [
            'info' => self::info($id, '1102 Półbut zawodowy O2 FO SRC DWG czarny', $code, $symbol, '590000000'.$id.'0', $company, $brand, 'Półbut zawodowy.'),
            'price' => $price,
        ];
        $this->models[$id] = [
            'list' => ['id' => $id, 'name' => '1102 Półbut zawodowy O2 FO SRC DWG czarny', 'code' => $code, 'status' => 'AvailableOnDemand'],
            'groups' => ['Basic Obuwie Robocze'],
            'variants' => ['headerVariants' => [], 'expandedVariant' => ['header' => ['propertyId' => 7, 'translatedName' => 'Rozmiar'], 'expandedValues' => [self::value($id, '36', 'AvailableOnDemand', 'Checked')]]],
            'attributes' => ['articleAttributes' => [], 'articleAttachments' => [], 'articleImages' => [], 'articleMovies' => []],
        ];
    }

    /** Asortyment w kartonie (mieszane rozmiary) — towar bez wariantów, poza działami drzewa. */
    private function addAssortment(): void
    {
        $this->articles[801] = [
            'info' => self::info(801, '062 Kozak damski czarny (38-42) (22222+2) 12p', 'FL-062-MIX-1', '062-TEST-MIX', '5900000008010', '"FAGUM - STOMIL" SPÓŁKA Z OGRANICZONĄ ODPOWIEDZIALNOŚCIĄ', null, 'Kozak damski.'),
            'price' => self::priceJson(37.38, 54.97, 12),
        ];
        $this->models[801] = [
            'list' => ['id' => 801, 'name' => '062 Kozak damski czarny (38-42) (22222+2) 12p', 'code' => 'FL-062-MIX-1', 'status' => 'AvailableOnDemand'],
            'groups' => ['Polecane'],
            'variants' => [],
            'attributes' => ['articleAttributes' => [], 'articleAttachments' => [], 'articleImages' => [], 'articleMovies' => []],
        ];
    }

    /**
     * Cena jak w articleFromListXl: za karton ($pairs par), unitNetPrice za parę.
     *
     * @return array<string, mixed>
     */
    private static function priceJson(float $pairNet, float $pairBase, int $pairs, bool $locked = false): array
    {
        return [
            'unit' => [
                'unitLockChange' => $locked,
                'auxiliaryUnit' => ['unit' => 'krt', 'representsExistingValue' => true],
                'numerator' => ['value' => (float) $pairs, 'representsExistingValue' => true],
                'denominator' => ['value' => 1.0, 'representsExistingValue' => true],
                'basicUnit' => 'para',
                'defaultUnitNo' => 1,
                'isUnitTotal' => false,
            ],
            'price' => [
                'currency' => 'PLN',
                'netPrice' => round($pairNet * $pairs, 2),
                'grossPrice' => round($pairNet * $pairs * 1.23, 4),
                'baseNetPrice' => round($pairBase * $pairs, 2),
                'baseGrossPrice' => round($pairBase * $pairs * 1.23, 4),
                'unitNetPrice' => ['value' => $pairNet, 'representsExistingValue' => true],
                'unitGrossPrice' => ['value' => round($pairNet * 1.23, 4), 'representsExistingValue' => true],
                'vatValue' => 23.0,
            ],
            'stockLevel' => ['value' => '71,6667', 'representsExistingValue' => true],
            'itemExistsInCurrentPriceList' => true,
            'articleDetailsType' => 'ContainsVariants',
            // EAN kartonu (jednostki pokazanej), nie pary — łącznik go nie bierze
            'ean' => '5900000099999',
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private static function info(int $id, string $name, string $code, string $symbol, string $ean, string $company, ?string $brand, string $description): array
    {
        return [
            'article' => ['id' => $id, 'image' => ['imageType' => 0, 'imageId' => null, 'imageUrl' => null], 'name' => $name, 'code' => ['value' => $code, 'representsExistingValue' => true], 'symbol' => $symbol, 'type' => 2, 'discountPermission' => true],
            'permissions' => ['showLastOrder' => false],
            'articleBasicDetails' => [
                'description' => $description,
                'manufacturerUrl' => 'https://fagum.pl/',
                'ean' => $ean,
                'status' => 'Available',
                'manufacturer' => ['id' => 5669, 'name' => $company, 'imageId' => null],
                'brand' => $brand !== null ? ['id' => 3, 'name' => $brand] : null,
            ],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private static function value(int $id, string $size, string $status, string $selection = 'Available'): array
    {
        return ['articleId' => $id, 'articleStatus' => $status, 'articleType' => 2, 'value' => ['valueId' => 100 + $id, 'translatedName' => $size, 'status' => $selection]];
    }

    /**
     * @return array<string, mixed>
     */
    private static function attribute(string $name, string $value): array
    {
        return ['type' => 4, 'name' => $name, 'value' => $value, 'objectExtension' => ['extendedItemsList' => []]];
    }

    /**
     * @return array<string, mixed>
     */
    private static function attachment(int $id, string $name): array
    {
        return ['id' => $id, 'fullName' => $name, 'extension' => 'pdf', 'type' => 0, 'hash' => 'h/'.$id.'+', 'url' => null, 'size' => 1000];
    }

    // ---- atrapa witryny ----

    private function fakeSite(): void
    {
        Http::fake(function (Request $request) {
            $url = $request->url();
            $path = (string) parse_url($url, PHP_URL_PATH);
            parse_str((string) parse_url($url, PHP_URL_QUERY), $query);
            preg_match('/ASP\.NET_SessionId=([\w-]+)/', $request->header('Cookie')[0] ?? '', $m);
            $signedIn = in_array($m[1] ?? '', $this->sessions, true);

            if ($path === '/login') {
                return Http::response('<html><body><app-root></app-root></body></html>', 200, ['Content-Type' => 'text/html']);
            }
            if ($path === '/account/login') {
                $data = $request->data();
                $this->loginBodies[] = $data;
                if (($data['customerName'] ?? null) !== self::NIP || ($data['userName'] ?? null) !== self::USER || ($data['password'] ?? null) !== self::PASSWORD
                    || ($data['LoginConfirmation'] ?? null) !== true) {
                    return Http::response('', 401);
                }
                $this->logins++;
                $this->sessions[] = 's-'.$this->logins;
                $this->apiCalls = 0;

                return Http::response(['IsNeededReloadCacheAndTranslations' => false], 200, ['Set-Cookie' => 'ASP.NET_SessionId=s-'.$this->logins.'; path=/; secure; HttpOnly']);
            }
            if ($path === '/account/isloggedin') {
                return Http::response($signedIn ? 'true' : 'false', 200, ['Content-Type' => 'application/json']);
            }

            // bez sesji jak w sklepie: zdjęcie — HTTP 200 z pustą treścią, plik — przekierowanie na stronę logowania
            if ($path === '/imagehandler.ashx') {
                return Http::response($signedIn ? self::jpeg((string) ($query['id'] ?? '')) : '', 200, ['Content-Type' => 'image/jpeg']);
            }
            if ($path === '/filehandler.ashx') {
                return $signedIn
                    ? Http::response('%PDF-1.4 test '.($query['id'] ?? ''), 200, ['Content-Type' => 'Application/pdf'])
                    : Http::response('', 302, ['Location' => 'https://b2b.fagum.pl/login']);
            }
            if (! $signedIn) {
                return Http::response(['message' => 'Authorization has been denied for this request.'], 401);
            }
            $this->apiCalls++;
            if ($this->expireAfterApiCalls > 0 && $this->apiCalls > $this->expireAfterApiCalls) {
                $this->expireAfterApiCalls = 0;
                $this->sessions = [];

                return Http::response(['message' => 'Authorization has been denied for this request.'], 401);
            }

            if ($path === '/api/items/articleListXl/') {
                $groupId = (int) ($query['groupId'] ?? 0);
                $models = $groupId === 0
                    ? array_values($this->models)
                    : array_values(array_filter($this->models, fn (array $model): bool => in_array(self::groupName($groupId), $model['groups'], true)));
                $page = max(1, (int) ($query['pageNumber'] ?? 1));
                $pages = max(1, (int) ceil(count($models) / 50));

                return Http::response([
                    'articleList' => array_map(static fn (array $model): array => [
                        'article' => ['id' => $model['list']['id'], 'image' => ['imageType' => 1, 'imageId' => 1, 'imageUrl' => null], 'name' => $model['list']['name'], 'code' => ['value' => $model['list']['code'], 'representsExistingValue' => true], 'symbol' => null, 'type' => 2],
                        'flag' => 0,
                        'status' => $model['list']['status'],
                    ], array_slice($models, ($page - 1) * 50, 50)),
                    'paging' => ['currentPage' => $page, 'totalPages' => $pages, 'buildPager' => $pages > 1],
                ]);
            }
            if ($path === '/api/items/treeXl') {
                return Http::response(['groups' => array_map(
                    static fn (int $id): array => ['id' => $id, 'isExpand' => $id === 20158 ? 0 : 1, 'name' => self::groupName($id)],
                    [80, 81, 20158],
                ), 'parentGroups' => [['id' => 16, 'name' => 'GLOBALNE.B2B']]]);
            }
            if ($path === '/api/items/articleFromListXl/') {
                $article = $this->articles[(int) ($query['articleId'] ?? 0)] ?? null;

                return $article !== null ? Http::response($article['price']) : Http::response(['message' => 'brak'], 403);
            }
            if (preg_match('#^/api/items/(\d+)/(getArticleGeneralInfoXl|attributesXl|getArticleVariantsDetailsXl|articleDetailsXl)$#', $path, $m) === 1) {
                $id = (int) $m[1];
                $withVariants = isset($this->models[$id]['variants']['expandedVariant']);

                return match ($m[2]) {
                    'articleDetailsXl' => Http::response(['articleDetailsType' => $withVariants ? 'ContainsVariants' : 'NotContainVariants', 'articleVariantProperties' => []]),
                    // jak w sklepie: o warianty towaru bez wariantów — HTTP 500 z pustą treścią
                    'getArticleVariantsDetailsXl' => $withVariants ? Http::response($this->models[$id]['variants']) : Http::response('', 500),
                    'getArticleGeneralInfoXl' => isset($this->articles[$id])
                        ? Http::response(['articleGeneralInfo' => $this->articles[$id]['info'], 'substituteList' => [], 'accessoryList' => []])
                        : Http::response(['message' => 'brak'], 403),
                    'attributesXl' => Http::response($this->models[$id]['attributes'] ?? ['articleAttributes' => [], 'articleAttachments' => [], 'articleImages' => []]),
                };
            }

            return Http::response('Nie znaleziono '.$url, 404, ['Content-Type' => 'text/html']);
        });
    }

    private static function groupName(int $id): string
    {
        return match ($id) {
            80 => 'Basic Obuwie Robocze',
            81 => 'Obuwie Zawodowe i Bezpieczne',
            20158 => 'Polecane',
            default => 'Inna',
        };
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
