<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\B2bAccount;
use App\Models\B2bProductLink;
use App\Models\Product;
use App\Models\ProductIdentifier;
use App\Models\ProductImage;
use App\Models\ProductShopCard;
use App\Models\ProductSourcePrice;
use App\Models\ProductVariant;
use App\Services\B2b\B2bAccountSyncRunner;
use App\Services\B2b\B2bConnectorRegistry;
use App\Services\B2b\B2bFatalException;
use App\Services\B2b\B2bImageGallery;
use App\Services\B2b\B2bManufacturerSite;
use App\Services\B2b\B2bRemoteProduct;
use App\Services\B2b\B2bShopFieldSource;
use App\Services\B2b\B2bSizePriceSource;
use App\Services\B2b\BrubeckB2bClient;
use App\Services\B2b\BrubeckB2bConnector;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use RuntimeException;
use Tests\TestCase;

/**
 * Łącznik sklepu Brubeck (Comarch B2B, ERP XL — ta sama platforma co Fagum) na atrapie witryny (Http::fake). Kształt
 * odpowiedzi odwzorowuje konto z 01.10.2026: konto ma witrynę https://46.45.74.25, a zapytania idą pod
 * https://b2b.brubeck.pl (certyfikat *.brubeck.pl). Logowanie JSON-em pod /account/login (customerName = kod klienta,
 * userName = pracownik, LoginConfirmation), sesja w ciasteczku, /account/isloggedin → true; bez sesji API odpowiada
 * 401, zdjęcie — HTTP 200 z pustą treścią. Lista /api/items/articleListXl/ podaje każdy towar (kolor i rozmiar) osobno,
 * kod „P1BRU-{kolekcja}-{model}-{kolor}-{kod rozmiaru}-{rozmiar}”, bez symbolu; dane towaru getArticleGeneralInfoXl
 * (opis = skład, marka BRUBECK, spółka FILATI), attributesXl z jednym zdjęciem i bez atrybutów, cena articleFromListXl
 * za „szt.” (netPrice = cena konta, baseNetPrice = cennikowa, unitNetPrice pusty, EAN towaru).
 *
 * Wszystkie dane (kody, EAN, ceny, nazwy, kod klienta) są SYNTETYCZNE.
 */
final class BrubeckConnectorTest extends TestCase
{
    use RefreshDatabase;

    private const CUSTOMER = 'FIRMA_TESTOWA';

    private const USER = 'Jan Testowy';

    private const PASSWORD = 'dobre-haslo';

    private const FILATI = 'FILATI MIROSŁAW KUBIAK SPÓŁKA KOMANDYTOWO-AKCYJNA';

    /** @var array<int, array<string, mixed>> towary wg id, w kolejności listy */
    private array $articles = [];

    /** @var array<int, list<int>> grupa drzewa → id towarów */
    private array $groupArticles = [];

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

    public function test_login_goes_to_the_certificate_name_and_confirms_the_session(): void
    {
        $this->fakeSite();
        $client = $this->client();

        $client->login();

        $this->assertTrue($client->isLoggedIn());
        $this->assertSame(
            ['customerName' => self::CUSTOMER, 'userName' => self::USER, 'password' => self::PASSWORD, 'rememberMe' => false, 'companyGroupId' => 0, 'LoginConfirmation' => true],
            $this->loginBodies[0],
        );
        // żadne zapytanie nie idzie na sam adres IP (certyfikat *.brubeck.pl do niego nie pasuje)
        $this->assertSame([], Http::recorded(fn (Request $r): bool => parse_url($r->url(), PHP_URL_HOST) !== 'b2b.brubeck.pl')->all());
        $check = Http::recorded(fn (Request $r): bool => str_ends_with($r->url(), '/account/isloggedin'))->first()[0];
        $this->assertStringContainsString('ASP.NET_SessionId=s-1', $check->header('Cookie')[0]);
        $this->assertStringContainsString('_culture=pl-PL', $check->header('Cookie')[0]);
    }

    public function test_wrong_password_is_not_fatal_and_does_not_ask_for_a_nip(): void
    {
        $this->fakeSite();
        $client = new BrubeckB2bClient(self::CUSTOMER, self::USER, 'zle-haslo', 0, static function (int $ms): void {});

        try {
            $client->login();
            $this->fail('logowanie złym hasłem powinno się nie udać');
        } catch (B2bFatalException $e) {
            $this->fail('złe hasło to nie błąd krytyczny: '.$e->getMessage());
        } catch (RuntimeException $e) {
            $this->assertStringContainsString('HTTP 401', $e->getMessage());
            $this->assertStringContainsString('46.45.74.25', $e->getMessage());
            $this->assertStringContainsString('sprawdź kod kontrahenta, pracownika i hasło', $e->getMessage());
        }
        $this->assertFalse($client->isLoggedIn());
    }

    public function test_price_is_the_net_price_per_piece_with_the_list_price_only_when_higher(): void
    {
        $price = BrubeckB2bConnector::parsePrice(self::priceJson(24.14, 36.58, '5900000000017'));

        $this->assertNotNull($price);
        $this->assertSame([24.14, 36.58, 'PLN', 'szt.', '5900000000017', false], [$price['net'], $price['base'], $price['currency'], $price['unit'], $price['ean'], $price['thresholds']]);
        // cennikowa równa cenie konta — brak ceny cennikowej wyższej
        $this->assertNull(BrubeckB2bConnector::parsePrice(self::priceJson(8.13, 8.13, '5900000000017'))['base'] ?? null);
        // towar poza cennikiem konta — brak ceny
        $outside = self::priceJson(24.14, 36.58, '5900000000017');
        $outside['itemExistsInCurrentPriceList'] = false;
        $this->assertNull(BrubeckB2bConnector::parsePrice($outside));
        // cena w jednostce pomocniczej — błąd zamiast zgadywania przelicznika
        $carton = self::priceJson(24.14, 36.58, '5900000000017');
        $carton['unit']['auxiliaryUnit'] = ['unit' => 'krt', 'representsExistingValue' => true];
        $this->expectException(RuntimeException::class);
        BrubeckB2bConnector::parsePrice($carton);
    }

    public function test_names_split_into_colour_part_and_size_and_the_card_name_uses_the_model_code(): void
    {
        $this->assertSame(['LS9001M Koszulka męska PRO czarny - WILK', 'M'], BrubeckB2bConnector::splitName('LS9001M Koszulka męska PRO czarny M - WILK'));
        $this->assertSame(['SC9001W Skarpety damskie czarny/różowy', 'S/36-38'], BrubeckB2bConnector::splitName('SC9001W Skarpety damskie czarny/różowy  S/36-38 '));
        $this->assertSame(
            ['LE9002J Spodnie dziecięce THERMO JUNIOR', ['jeansowy/fioletowy', 'czarny/grafitowy']],
            BrubeckB2bConnector::cardNameAndColours('LE9002J', ['LE90020 Spodnie dziecięce THERMO JUNIOR jeansowy/fioletowy', 'LE9002J Spodnie dziecięce THERMO JUNIOR czarny/grafitowy']),
        );
        // ten sam kolor z innym nadrukiem: wspólny początek obejmuje kolor, etykieta = nadruk
        $this->assertSame(
            ['LS9001M Koszulka męska PRO czarny', ['GÓRY 14', 'WILK']],
            BrubeckB2bConnector::cardNameAndColours('LS9001M', ['LS9001M Koszulka męska PRO czarny - GÓRY 14', 'LS9001M Koszulka męska PRO czarny - WILK']),
        );
        $this->assertSame(['LE9002J Spodnie czarny', ['']], BrubeckB2bConnector::cardNameAndColours('LE9002J', ['LE90020 Spodnie czarny']));
        $this->assertSame('Brubeck', BrubeckB2bConnector::manufacturerName('BRUBECK', self::FILATI));
        $this->assertSame('Inna Marka', BrubeckB2bConnector::manufacturerName('Inna Marka', self::FILATI));
        $this->assertSame('Brubeck', BrubeckB2bConnector::manufacturerName('', self::FILATI));
        $this->assertSame('ACME SPÓŁKA', BrubeckB2bConnector::manufacturerName('', 'ACME SPÓŁKA'));
    }

    public function test_model_with_colours_and_sizes_is_one_card_with_member_prices_identifiers_and_images(): void
    {
        $this->addLeggings();
        $this->fakeSite();
        $connector = $this->connector();

        $products = iterator_to_array($connector->products(), false);

        $this->assertCount(1, $products);
        $card = $products[0];
        $this->assertSame('LE9001W', $card->sku);
        $this->assertSame('LE9001W Legginsy damskie TEST WOOL', $card->cardName);
        $this->assertSame('LE9001W Legginsy damskie TEST WOOL czarny S', $card->name);
        $this->assertSame('https://46.45.74.25/itemdetails/101', $card->sourceUrl);
        $this->assertSame('02 COMFORT, 02 WYPRZEDAŻ', $card->category);
        $this->assertSame('101', $card->remoteId);
        // rozmiar XL koloru czarnego poza cennikiem konta — poza kartą; „bezowy” to literówka jednego rozmiaru
        $this->assertSame(
            [
                ['101', 'P1BRU-CWOA-LE9001W-99XXXXXXX-24-S', 'czarny / S', '40.00', '60.00', 'Dostępny'],
                ['102', 'P1BRU-CWOA-LE9001W-99XXXXXXX-25-M', 'czarny / M', '40.00', '60.00', 'Dostępny'],
                ['201', 'P1BRU-CWOF-LE9001W-06XXXXXXX-24-S', 'beżowy / S', '35.50', '60.00', 'Dostępny'],
                ['202', 'P1BRU-CWOF-LE9001W-06XXXXXXX-25-M', 'beżowy / M', '35.50', '60.00', 'Niedostępny'],
                ['203', 'P1BRU-CWOI-LE9001W-06XXXXXXX-26-L', 'beżowy / L', '35.50', '60.00', 'Dostępny'],
            ],
            array_map(static fn (array $m): array => [$m['remote_id'], $m['sku'], $m['size'], sprintf('%.2F', $m['price']->net), sprintf('%.2F', $m['price']->base), $m['availability']], $card->members),
        );
        $this->assertSame('Dostępny: czarny / S, czarny / M, beżowy / S, beżowy / L; Niedostępny: beżowy / M', $card->availability);
        $this->assertSame('Kolory: czarny, beżowy; rozmiary: S, M, L', $card->variantSummary);
        $price = $connector->price($card);
        $this->assertSame([35.5, 60.0, 'PLN'], [$price->net, $price->base, $price->currency]);
        $this->assertSame([null, null, 'szt.', false], [$price->order?->min, $price->order?->step, $price->order?->unit, $price->order?->restricts()]);
        $this->assertSame('Brubeck', $connector->manufacturer($card));
        $this->assertSame("80% wełna 20% poliamid\nWyrób testowy", $connector->description($card));
        $this->assertSame(
            [
                [ProductIdentifier::TYPE_MODEL_CODE, 'LE9001W', null, null],
                [ProductIdentifier::TYPE_MANUFACTURER_CODE, 'P1BRU-CWOA-LE9001W-99XXXXXXX-24-S', '101', 'czarny / S'],
                [ProductIdentifier::TYPE_EAN, '5900000001011', '101', 'czarny / S'],
            ],
            array_map(static fn ($i): array => [$i->type, $i->value, $i->remoteId, $i->label], array_slice($card->identifiers ?? [], 0, 3)),
        );
        $this->assertSame(
            [
                'Informacje ze sklepu Brubeck | Producent | '.self::FILATI,
                'Informacje ze sklepu Brubeck | Marka | BRUBECK',
                'Informacje ze sklepu Brubeck | Model | LE9001W',
                'Informacje ze sklepu Brubeck | Cena za | szt.',
            ],
            array_map(static fn ($f): string => $f->section.' | '.$f->name.' | '.$f->value, $connector->shopFields($card)),
        );
        // jedno zdjęcie na kolor (towar wiodący koloru), na hoście konta
        $this->assertSame(
            ['https://46.45.74.25/imagehandler.ashx?id=1101&width=2000&height=2000', 'https://46.45.74.25/imagehandler.ashx?id=1201&width=2000&height=2000'],
            $connector->imageUrls($card),
        );
        $summary = implode("\n", $connector->runSummary());
        $this->assertStringContainsString('Lista Brubeck: 6 towarów (kolor i rozmiar), 1 modeli', $summary);
        $this->assertStringContainsString('Towary bez ceny konta (poza kartami): 1, np. P1BRU-CWOA-LE9001W-99XXXXXXX-27-XL', $summary);
        $this->assertStringContainsString('Karty: 1 (1 z pozycjami w różnych cenach', $summary);
    }

    public function test_same_model_and_colour_code_with_another_product_name_is_a_separate_card_and_a_missing_description_is_read_from_the_next_size(): void
    {
        // HM9001U jak HM1008U na koncie: czapka ACTIVE WOOL w kolekcjach ACSA i ACSB (ten sam kolor i nazwa — jeden kolor)
        // i czapka „Protect” w PTHG (ten sam kod koloru i skład, inna nazwa i cena — inny wyrób); S/M w ACSA bez opisu
        $this->addArticle(301, 'P1BRU-ACSA-HM9001U-99XXXXXXX-13-S_M', 'HM9001U Czapka wełniana unisex ACTIVE WOOL czarny S/M', 20.0, 30.0, '', group: 9286, image: 3001);
        $this->addArticle(304, 'P1BRU-ACSA-HM9001U-99XXXXXXX-23-XS', 'HM9001U Czapka wełniana unisex ACTIVE WOOL czarny XS', 20.0, 30.0, '80% bawełna 20% poliamid', group: 9286, image: 3004);
        $this->addArticle(302, 'P1BRU-ACSB-HM9001U-99XXXXXXX-13-S_M', 'HM9001U Czapka wełniana unisex ACTIVE WOOL czarny S/M', 21.0, 30.0, '80% bawełna 20% poliamid', group: 9286, image: 3002);
        // towar wiodący Protect bez zdjęcia w atrybutach — zdjęcie z miniatury listy
        $this->addArticle(303, 'P1BRU-PTHG-HM9001U-99XXXXXXX-13-S_M', 'HM9001U Czapka Wełniana Protect czarny S/M', 50.0, 70.0, '80% bawełna 20% poliamid', group: 9289, image: 3003, attributeImage: false);
        $this->addArticle(401, 'P1BRU-ACSE-SC9001W-990145XXX-44-36_38', 'SC9001W Skarpety damskie Ski Force czarny/różowy S/36-38', 30.0, 45.0, '', group: 9286, brand: 'Inna Marka');
        $this->fakeSite();
        $connector = $this->connector();

        $products = iterator_to_array($connector->products(), false);

        $this->assertSame(['HM9001U-ACSA-99XXXXXXX', 'HM9001U-PTHG-99XXXXXXX', 'SC9001W'], array_map(static fn (B2bRemoteProduct $p): string => $p->sku, $products));
        [$wool, $protect, $socks] = $products;
        $this->assertSame('HM9001U Czapka wełniana unisex ACTIVE WOOL czarny', $wool->cardName);
        // ten sam rozmiar z dwóch kolekcji — z kodem kolekcji; rozmiary w kolejności kodu rozmiaru
        $this->assertSame(['S/M (ACSA)', 'S/M (ACSB)', 'XS'], array_column($wool->members, 'size'));
        $this->assertSame('Rozmiary: S/M, XS', $wool->variantSummary);
        $this->assertSame('80% bawełna 20% poliamid', $connector->description($wool));
        $this->assertNotEmpty(Http::recorded(fn (Request $r): bool => str_contains($r->url(), '/api/items/304/getArticleGeneralInfoXl'))->all());
        $this->assertSame(['https://46.45.74.25/imagehandler.ashx?id=3001&width=2000&height=2000'], $connector->imageUrls($wool));
        $this->assertSame([], $protect->members);
        $this->assertSame('303', $protect->remoteId);
        $this->assertSame(50.0, $connector->price($protect)->net);
        $this->assertSame(['https://46.45.74.25/imagehandler.ashx?id=3003&width=2000&height=2000'], $connector->imageUrls($protect));
        $this->assertSame([], $socks->members);
        $this->assertSame('401', $socks->remoteId);
        $this->assertSame('Rozmiary: S/36-38', $socks->variantSummary);
        $this->assertSame('Inna Marka', $connector->manufacturer($socks));
        $summary = implode("\n", $connector->runSummary());
        $this->assertStringNotContainsString('innym opisem', $summary);
        $this->assertStringContainsString('Ten sam kod modelu i koloru z inną nazwą wyrobu — osobne karty: 1, np. HM9001U', $summary);
        $this->assertStringContainsString('Bez opisu w sklepie: 1, np. SC9001W', $summary);
        $this->assertStringContainsString('Producent spoza marki Brubeck (zapisany dosłownie): Inna Marka — 1 kart', $summary);
    }

    public function test_colours_with_another_description_are_separate_cards_with_the_colour_code_in_the_sku(): void
    {
        // jak LS1414M na koncie: skład skrótami w jednej kolekcji, słownie w drugiej — opisu nie łączymy ani nie wybieramy
        $this->addArticle(501, 'P1BRU-AWOL-LS9001M-99XXXXA85-24-S', 'LS9001M Koszulka męska PRO czarny S - GÓRY 14', 90.0, 130.0, '73% PO 27% WOOL<br/>');
        $this->addArticle(502, 'P1BRU-AWOL-LS9001M-99XXXXA85-25-M', 'LS9001M Koszulka męska PRO czarny M - GÓRY 14', 90.0, 130.0, '73% PO 27% WOOL<br/>');
        $this->addArticle(601, 'P1BRU-AWOH-LS9001M-5986XXA18-25-M', 'LS9001M Koszulka męska PRO czarny M - WILK', 95.0, 140.0, '73% poliamid 27% wełna');
        $this->fakeSite();
        $connector = $this->connector();

        $products = iterator_to_array($connector->products(), false);

        $this->assertSame(['LS9001M-99XXXXA85', 'LS9001M-5986XXA18'], array_map(static fn (B2bRemoteProduct $p): string => $p->sku, $products));
        $this->assertSame(['73% PO 27% WOOL', '73% poliamid 27% wełna'], array_map(static fn (B2bRemoteProduct $p): string => $connector->description($p), $products));
        $this->assertSame('LS9001M Koszulka męska PRO czarny - GÓRY 14', $products[0]->cardName);
        $this->assertSame(['S', 'M'], array_column($products[0]->members, 'size'));
        $this->assertSame([], $products[1]->members);
        $this->assertStringContainsString('Kolory jednego modelu z innym opisem (składem) — osobne karty: 1, np. LS9001M (2 opisy)', implode("\n", $connector->runSummary()));
    }

    public function test_expired_session_is_renewed_once(): void
    {
        $this->addLeggings();
        $this->fakeSite();
        $connector = $this->connector();
        $this->expireAfterApiCalls = 3;

        $products = iterator_to_array($connector->products(), false);

        $this->assertCount(1, $products);
        $this->assertSame(2, $this->logins);
    }

    public function test_image_without_a_session_logs_in_again_and_is_fetched_from_the_certificate_name(): void
    {
        $this->addLeggings();
        $this->fakeSite();
        $connector = $this->connector();
        $card = iterator_to_array($connector->products(), false)[0];

        $this->sessions = [];
        $image = $connector->imageAt($connector->imageUrls($card)[0]);

        $this->assertNotNull($image);
        $this->assertSame('image/jpeg', $image->mime);
        $this->assertNotSame('', $image->bytes);
        $this->assertSame('https://46.45.74.25/imagehandler.ashx?id=1101&width=2000&height=2000', $image->sourceUrl);
        $this->assertSame(2, $this->logins);
        $this->assertNotEmpty(Http::recorded(fn (Request $r): bool => str_starts_with($r->url(), 'https://b2b.brubeck.pl/imagehandler.ashx?id=1101'))->all());
        // adres spoza sklepu — odmowa
        $this->expectException(RuntimeException::class);
        $connector->imageAt('https://example.test/imagehandler.ashx?id=1');
    }

    public function test_many_models_without_any_account_price_stop_the_run(): void
    {
        for ($i = 0; $i < 21; $i++) {
            $this->addArticle(900 + $i, 'P1BRU-ACSE-BEZ'.$i.'U-99XXXXXXX-24-S', 'BEZ'.$i.'U Skarpety czarny S', 10.0, 20.0, 'bawełna', inPriceList: false);
        }
        $this->fakeSite();

        $this->expectException(B2bFatalException::class);
        $this->expectExceptionMessage('bez żadnej ceny konta');

        iterator_to_array($this->connector()->products(), false);
    }

    public function test_sync_creates_the_card_with_colour_size_rows_and_a_second_run_changes_nothing(): void
    {
        Storage::fake('public');
        Storage::fake('local');
        $this->addLeggings();
        $this->fakeSite();

        $result = app(B2bAccountSyncRunner::class)->run($this->account(), delayMs: 0, withImages: true);

        $this->assertSame(1, $result['created'], implode(' | ', $result['errors']));
        $card = Product::query()->where('sku', 'LE9001W')->sole();
        $this->assertSame('Brubeck', $card->manufacturer);
        $this->assertSame('LE9001W Legginsy damskie TEST WOOL', $card->name);
        $this->assertStringContainsString('80% wełna 20% poliamid', (string) $card->description);
        $this->assertSame(['101', '102', '201', '202', '203'], B2bProductLink::query()->where('product_id', $card->id)->orderBy('remote_id')->pluck('remote_id')->all());
        $slot = ProductSourcePrice::query()->where('product_id', $card->id)->sole();
        $this->assertSame('35.50', (string) $slot->purchase_price);
        $this->assertSame('60.00', (string) $slot->catalog_price_net);
        $this->assertSame('40.00', (string) $slot->size_price_max);
        $this->assertSame('szt.', $slot->order_unit);
        $this->assertSame(
            [['czarny / S', '40.00'], ['czarny / M', '40.00'], ['beżowy / S', '35.50'], ['beżowy / M', '35.50'], ['beżowy / L', '35.50']],
            ProductVariant::query()->where('product_id', $card->id)->where('kind', ProductVariant::KIND_SIZE)->orderBy('sort_order')->get()
                ->map(static fn (ProductVariant $v): array => [$v->label, (string) $v->purchase_price])->all(),
        );
        $this->assertSame(2, ProductImage::query()->where('product_id', $card->id)->count());
        $rows = collect(ProductShopCard::query()->where('product_id', $card->id)->sole()->fields)->flatMap(static fn (array $section): array => array_map(
            static fn (array $row): string => ($section['section'] ?? '').' | '.$row['name'].' | '.$row['value'],
            $section['rows'] ?? [],
        ))->all();
        $this->assertContains('Informacje ze sklepu Brubeck | Marka | BRUBECK', $rows);
        $this->assertSame(
            '5900000002021',
            ProductIdentifier::query()->where('product_id', $card->id)->where('type', ProductIdentifier::TYPE_EAN)->where('variant_label', 'beżowy / M')->value('value'),
        );

        $before = $this->snapshot();
        $second = app(B2bAccountSyncRunner::class)->run($this->account(), delayMs: 0, withImages: true);

        $this->assertSame(0, $second['created'], implode(' | ', $second['errors']));
        $this->assertSame(0, $second['updated'], implode(' | ', $second['errors']));
        $this->assertSame(1, $second['unchanged'], implode(' | ', $second['errors']));
        $this->assertSame($before, $this->snapshot());
    }

    public function test_registry_detects_brubeck_by_the_ip_host_as_the_manufacturer_site(): void
    {
        $registry = app(B2bConnectorRegistry::class);

        $this->assertSame('brubeck', $registry->keyForSites(['https://46.45.74.25/login']));
        $this->assertSame('Brubeck', $registry->label('brubeck'));
        $this->assertTrue($registry->requiresPassword('brubeck'));
        $this->assertTrue($registry->isManufacturerSite('brubeck'));
        $this->assertTrue($registry->sendsSizePrices('brubeck'));
        $this->assertTrue(B2bConnectorRegistry::isConnectorUrl('https://46.45.74.25/itemdetails/101'));

        $account = B2bAccount::query()->create([
            'username' => self::USER, 'contractor_code' => self::CUSTOMER, 'password' => 'sekret', 'sites' => ['https://46.45.74.25/login'],
        ]);
        $connector = $registry->make($account, 0);

        $this->assertInstanceOf(BrubeckB2bConnector::class, $connector);
        foreach ([B2bManufacturerSite::class, B2bShopFieldSource::class, B2bImageGallery::class, B2bSizePriceSource::class] as $interface) {
            $this->assertInstanceOf($interface, $connector);
        }
    }

    // ---- pomocnicze ----

    private function client(): BrubeckB2bClient
    {
        return new BrubeckB2bClient(self::CUSTOMER, self::USER, self::PASSWORD, 0, static function (int $ms): void {});
    }

    private function connector(): BrubeckB2bConnector
    {
        $connector = new BrubeckB2bConnector($this->client());
        $connector->login();

        return $connector;
    }

    private function account(): B2bAccount
    {
        return B2bAccount::query()->firstOrCreate(
            ['username' => self::USER],
            ['contractor_code' => self::CUSTOMER, 'password' => self::PASSWORD, 'sites' => ['https://46.45.74.25/login'], 'sync_images' => true],
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
            'links' => B2bProductLink::query()->where('product_id', $p->id)->orderBy('remote_id')->pluck('remote_id')->all(),
            'shop_card' => ProductShopCard::query()->where('product_id', $p->id)->value('fields'),
            'images' => ProductImage::query()->where('product_id', $p->id)->orderBy('sort_order')->pluck('source_url')->all(),
            'price' => ProductSourcePrice::query()->where('product_id', $p->id)->get(['purchase_price', 'catalog_price_net', 'size_price_max', 'availability'])->toArray(),
            'sizes' => ProductVariant::query()->where('product_id', $p->id)->orderBy('id')
                ->get(['remote_id', 'label', 'purchase_price', 'availability', 'removed_at', 'updated_at'])->toArray(),
        ]])->all();
    }

    /**
     * Legginsy w dwóch kolorach: czarny (kolekcja CWOA: S, M, XL poza cennikiem) i beżowy (kolekcje CWOF i CWOI — ten
     * sam kolor z dwóch sezonów; jeden rozmiar z literówką w nazwie, M niedostępny). Czarny w dziale „02 COMFORT”,
     * beżowy L w „02 WYPRZEDAŻ”.
     */
    private function addLeggings(): void
    {
        $description = '80% wełna 20% poliamid<br/>Wyrób testowy';
        $this->addArticle(101, 'P1BRU-CWOA-LE9001W-99XXXXXXX-24-S', 'LE9001W Legginsy damskie TEST WOOL czarny S', 40.0, 60.0, $description, image: 1101);
        $this->addArticle(102, 'P1BRU-CWOA-LE9001W-99XXXXXXX-25-M', 'LE9001W Legginsy damskie TEST WOOL czarny M', 40.0, 60.0, $description, image: 1102);
        $this->addArticle(104, 'P1BRU-CWOA-LE9001W-99XXXXXXX-27-XL', 'LE9001W Legginsy damskie TEST WOOL czarny XL', 40.0, 60.0, $description, inPriceList: false);
        $this->addArticle(202, 'P1BRU-CWOF-LE9001W-06XXXXXXX-25-M', 'LE9001W Legginsy damskie TEST WOOL beżowy M', 35.5, 60.0, $description, status: 'NotAvailable', image: 1202);
        $this->addArticle(201, 'P1BRU-CWOF-LE9001W-06XXXXXXX-24-S', 'LE9001W Legginsy damskie TEST WOOL bezowy S  ', 35.5, 60.0, $description, image: 1201);
        $this->addArticle(203, 'P1BRU-CWOI-LE9001W-06XXXXXXX-26-L', 'LE9001W Legginsy damskie TEST WOOL beżowy L', 35.5, 60.0, $description, group: 9920, image: 1203);
    }

    private function addArticle(int $id, string $code, string $name, float $net, float $base, string $description, int $group = 9287, string $status = 'Available', bool $inPriceList = true, string $brand = 'BRUBECK', ?int $image = null, bool $attributeImage = true): void
    {
        $ean = '590000000'.str_pad((string) $id, 3, '0', STR_PAD_LEFT).'1';
        $price = self::priceJson($net, $base, $ean);
        $price['itemExistsInCurrentPriceList'] = $inPriceList;
        $this->articles[$id] = [
            'list' => [
                'article' => ['id' => $id, 'image' => ['imageType' => 1, 'imageId' => $image ?? 5000 + $id, 'imageUrl' => null], 'name' => $name, 'code' => ['value' => $code, 'representsExistingValue' => true], 'symbol' => null, 'type' => 1, 'discountPermission' => true],
                'flag' => 0,
                'status' => $status,
                'rowNumber' => count($this->articles) + 1,
            ],
            'info' => [
                'article' => ['id' => $id, 'image' => ['imageType' => 0, 'imageId' => null, 'imageUrl' => null], 'name' => $name, 'code' => ['value' => $code, 'representsExistingValue' => true], 'symbol' => '', 'type' => 1, 'discountPermission' => true],
                'permissions' => ['showLastOrder' => true, 'showPlannedDeliveries' => true, 'showArticleThresholdPrices' => true],
                'articleBasicDetails' => [
                    'description' => $description,
                    'manufacturerUrl' => 'www.filati.pl',
                    'manager' => '',
                    'managerMail' => '',
                    'articleGroupId' => 9290,
                    'ean' => $ean,
                    'status' => 'Available',
                    'flag' => 0,
                    'availabilityStatus' => 'Unknown',
                    'availableFrom' => null,
                    'manufacturer' => ['id' => 208, 'name' => self::FILATI, 'imageId' => null, 'extensions' => null],
                    'brand' => ['id' => 1593, 'name' => $brand, 'imageId' => null, 'extensions' => null],
                ],
            ],
            'attributes' => ['articleAttributes' => [], 'articleAttachments' => [], 'articleImages' => $attributeImage ? [['imageType' => 1, 'imageId' => $image ?? 5000 + $id, 'imageUrl' => null]] : [], 'articleMovies' => []],
            'price' => $price,
        ];
        $this->groupArticles[$group][] = $id;
    }

    /**
     * Cena jak w articleFromListXl konta Brubeck: za „szt.”, bez jednostki pomocniczej, unitNetPrice pusty.
     *
     * @return array<string, mixed>
     */
    private static function priceJson(float $net, float $base, string $ean): array
    {
        return [
            'unit' => [
                'unitLockChange' => false,
                'auxiliaryUnit' => ['unit' => null, 'representsExistingValue' => false],
                'numerator' => ['value' => 1, 'representsExistingValue' => true],
                'denominator' => ['value' => 1, 'representsExistingValue' => true],
                'basicUnit' => 'szt.',
                'defaultUnitNo' => 0,
                'isUnitTotal' => true,
            ],
            'price' => [
                'currency' => 'PLN',
                'netPrice' => $net,
                'grossPrice' => round($net * 1.23, 4),
                'baseNetPrice' => $base,
                'baseGrossPrice' => round($base * 1.23, 4),
                'unitNetPrice' => ['value' => 0, 'representsExistingValue' => false],
                'unitGrossPrice' => ['value' => 0, 'representsExistingValue' => false],
                'vatValue' => 23,
            ],
            'stockLevel' => ['value' => '632', 'representsExistingValue' => true],
            'itemExistsInCurrentPriceList' => true,
            'thresholdPriceLists' => ['constPriceThresholdPriceList' => [], 'valuableThresholdPriceList' => [], 'percentageThresholdPriceList' => [], 'hasAnyThresholdPriceList' => false],
            'articleDetailsType' => 'NotContainVariants',
            'ean' => $ean,
            'articleInCart' => ['quantity' => null],
        ];
    }

    // ---- atrapa witryny ----

    private function fakeSite(): void
    {
        Http::fake(function (Request $request) {
            $url = $request->url();
            if (parse_url($url, PHP_URL_HOST) !== 'b2b.brubeck.pl') {
                return Http::response('certyfikat nie pasuje do '.$url, 495);
            }
            $path = (string) parse_url($url, PHP_URL_PATH);
            parse_str((string) parse_url($url, PHP_URL_QUERY), $query);
            preg_match('/ASP\.NET_SessionId=([\w-]+)/', $request->header('Cookie')[0] ?? '', $m);
            $signedIn = in_array($m[1] ?? '', $this->sessions, true);

            if ($path === '/login') {
                return Http::response('<html><head><meta name="CompilationVersion" content="2025.4.0.5"></head><body><app-root></app-root></body></html>', 200, ['Content-Type' => 'text/html']);
            }
            if ($path === '/account/login') {
                $data = $request->data();
                $this->loginBodies[] = $data;
                if (($data['customerName'] ?? null) !== self::CUSTOMER || ($data['userName'] ?? null) !== self::USER || ($data['password'] ?? null) !== self::PASSWORD
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
            // bez sesji jak w sklepie: zdjęcie — HTTP 200 z pustą treścią
            if ($path === '/imagehandler.ashx') {
                return Http::response($signedIn ? self::jpeg((string) ($query['id'] ?? '')) : '', 200, $signedIn ? ['Content-Type' => 'image/jpeg'] : []);
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
                $ids = $groupId === 0 ? array_keys($this->articles) : ($this->groupArticles[$groupId] ?? []);
                $page = max(1, (int) ($query['pageNumber'] ?? 1));
                $pages = max(1, (int) ceil(count($ids) / 50));

                return Http::response([
                    'articleList' => array_map(fn (int $id): array => $this->articles[$id]['list'], array_slice($ids, ($page - 1) * 50, 50)),
                    'paging' => ['currentPage' => $page, 'totalPages' => $pages, 'buildPager' => $pages > 1],
                ]);
            }
            if ($path === '/api/items/treeXl') {
                return Http::response(['groups' => array_map(
                    static fn (int $id): array => ['id' => $id, 'isExpand' => $id >= 9919 ? 0 : 1, 'name' => self::groupName($id)],
                    [9286, 9287, 9289, 9919, 9920],
                ), 'parentGroups' => [['id' => 9285, 'name' => '02 B2B']]]);
            }
            if ($path === '/api/items/articleFromListXl/') {
                $article = $this->articles[(int) ($query['articleId'] ?? 0)] ?? null;

                return $article !== null ? Http::response($article['price']) : Http::response(['message' => 'brak'], 403);
            }
            if (preg_match('#^/api/items/(\d+)/(getArticleGeneralInfoXl|attributesXl|getArticleVariantsDetailsXl|articleDetailsXl)$#', $path, $m) === 1) {
                $article = $this->articles[(int) $m[1]] ?? null;
                if ($article === null) {
                    return Http::response(['message' => 'brak'], 403);
                }

                return match ($m[2]) {
                    'articleDetailsXl' => Http::response(['articleDetailsType' => 'NotContainVariants', 'articleVariantProperties' => []]),
                    // jak w sklepie: o warianty towaru bez wariantów — HTTP 500
                    'getArticleVariantsDetailsXl' => Http::response('', 500),
                    'getArticleGeneralInfoXl' => Http::response(['articleGeneralInfo' => $article['info'], 'substituteList' => [], 'accessoryList' => []]),
                    'attributesXl' => Http::response($article['attributes']),
                };
            }

            return Http::response('Nie znaleziono '.$url, 404, ['Content-Type' => 'text/html']);
        });
    }

    private static function groupName(int $id): string
    {
        return match ($id) {
            9286 => '02 ACTIVE',
            9287 => '02 COMFORT',
            9289 => '02 PROTECT',
            9919 => '02 SUPER CENA',
            9920 => '02 WYPRZEDAŻ',
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
