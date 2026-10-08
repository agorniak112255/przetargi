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
use App\Services\B2b\B2bShopFieldNormSource;
use App\Services\B2b\B2bShopFieldSource;
use App\Services\B2b\B2bSizePriceSource;
use App\Services\B2b\ProfixB2bClient;
use App\Services\B2b\ProfixB2bConnector;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use RuntimeException;
use Tests\TestCase;

/**
 * Łącznik partners.profix.com.pl na atrapie API (Http::fake). Kształt odpowiedzi odwzorowuje portal z 08.10.2026:
 * POST /api/auth/login → {token, refresh_token}, bez tokenu albo ze złym — HTTP 401 {code, message} (LexikJWT);
 * /api/catalog/grid z filtrem parametru tylko przy cats=true (bez niego portal zwraca wszystkie marki), listą
 * categories/sub_categories z licznikami i params z „Marką”; /api/catalog/product/{symbol} z content (lista <li>,
 * piktogramy <img title>), params (Rozmiar, Marka z isProducer), gallery (media/cache/square/…) i documents
 * (pim.doc.*). Zdjęcia i pliki publiczne.
 *
 * Wszystkie dane (kody, ceny, nazwy, opisy, EAN-y) są SYNTETYCZNE.
 */
final class ProfixConnectorTest extends TestCase
{
    use RefreshDatabase;

    private const USER = 'zakupy@example.test';

    private const PASSWORD = 'dobre-haslo';

    private const BRAND_PARAM = 19;

    private const LAHTI = 4497;

    /** @var array<string, array<string, mixed>> symbol → pozycja listy (z kluczami pomocniczymi _brand, _category) */
    private array $items = [];

    /** @var array<string, array<string, mixed>> symbol → szczegóły */
    private array $details = [];

    /** @var list<string> ważne tokeny */
    private array $tokens = [];

    private int $logins = 0;

    /** @var list<array<string, mixed>> */
    private array $loginBodies = [];

    /** Token traci ważność po tylu zapytaniach o API (0 = nigdy). */
    private int $expireAfterCalls = 0;

    private int $calls = 0;

    /** Portal nie uznaje żadnego tokenu (logowanie się udaje) — sesja nie do odzyskania. */
    private bool $apiRefused = false;

    /** Portal ignoruje filtr marki (zwraca wszystko). */
    private bool $filterIgnored = false;

    /** Strona 2 listy powtarza pierwszą pozycję (tyle razy). */
    private int $duplicateOnPage2 = 0;

    protected function setUp(): void
    {
        parent::setUp();
        Queue::fake();
    }

    public function test_login_posts_json_and_list_requests_carry_the_token_and_the_brand_filter_with_cats(): void
    {
        $this->addCatalog();
        $this->fakeSite();

        iterator_to_array($this->connector()->products(), false);

        $this->assertSame(['username' => self::USER, 'password' => self::PASSWORD], $this->loginBodies[0]);
        $list = Http::recorded(static fn (Request $r): bool => str_contains($r->url(), '/api/catalog/grid') && str_contains($r->url(), 'sort_field=symbol'))->first()[0];
        $this->assertSame('Bearer tok-1', $list->header('Authorization')[0] ?? null);
        parse_str((string) parse_url($list->url(), PHP_URL_QUERY), $query);
        $this->assertSame((string) self::LAHTI, $query['param_'.self::BRAND_PARAM] ?? null);
        $this->assertSame('true', $query['cats'] ?? null);
        $this->assertSame(1, $this->logins);
    }

    public function test_wrong_password_is_not_fatal_and_says_what_the_portal_said(): void
    {
        $this->fakeSite();
        $client = new ProfixB2bClient(self::USER, 'zle-haslo', 0, static function (int $ms): void {});

        try {
            $client->login();
            $this->fail('logowanie złym hasłem powinno się nie udać');
        } catch (B2bFatalException $e) {
            $this->fail('złe hasło to nie błąd krytyczny: '.$e->getMessage());
        } catch (RuntimeException $e) {
            $this->assertStringContainsString('HTTP 401', $e->getMessage());
            $this->assertStringContainsString('Invalid credentials.', $e->getMessage());
            $this->assertStringNotContainsString('zle-haslo', $e->getMessage());
        }
        $this->assertFalse($client->isLoggedIn());
    }

    public function test_name_analysis_separates_size_colour_and_model(): void
    {
        $pants = ProfixB2bConnector::analyse('L8000104', 'SPODNIE TESTOWE ZIELONO-CZARNE, "XL", CE, LAHTI');
        $this->assertSame(['L80001', true, 'XL', 'ZIELONO-CZARNE'], [$pants['model'], $pants['standard'], $pants['size'], $pants['colour']]);
        $this->assertSame('SPODNIE TESTOWE ZIELONO-CZARNE, CE, LAHTI', $pants['name_without_size']);

        // druga część rozmiaru bez cudzysłowu i kropka bez spacji
        $gloves = ProfixB2bConnector::analyse('L800809B', 'RĘKAWICE NITR. NIEB., 100 SZT., PUDEŁKO, "9", L, CE, LAHTI');
        $this->assertSame('9 / L', $gloves['size']);
        $this->assertSame("L80080\nRĘKAWICE NITR.,100 SZT.,PUDEŁKO,CE,LAHTI", $gloves['key']);

        // ucięta nazwa: cudzysłów bez pary, kropka bez spacji przed kolorem („JASNONIEB.STRETCH”)
        $a = ProfixB2bConnector::analyse('L8005404', 'SPODNIE JEANSOWE JASNONIEB. STRETCH ZE WZMOC. ,XL",CE, LAHTI');
        $b = ProfixB2bConnector::analyse('L8005406', 'SPODNIE JEANSOWE JASNONIEB.STRETCH ZE WZMOC.,"3XL",CE, LAHTI');
        $this->assertSame($a['key'], $b['key']);
        $this->assertSame(['XL', '3XL'], [$a['size'], $b['size']]);

        // wartość w cudzysłowie, która nie jest rozmiarem, zostaje w nazwie
        $bomber = ProfixB2bConnector::analyse('L8093704', 'BLUZA OCIEPLANA "BOMBER" CZARNA,"XL", CE, LAHTI');
        $this->assertSame('XL', $bomber['size']);
        $this->assertStringContainsString('"BOMBER"', $bomber['key']);

        // kod pary w nazwie (L800807P) nie rozdziela rozmiarów; opakowanie rozdziela
        $seven = ProfixB2bConnector::analyse('L800807K', 'RĘKAWICE TEST L800807P, KARTA, "7", CE, LAHTI');
        $eight = ProfixB2bConnector::analyse('L800808K', 'RĘKAWICE TEST L800808P, KARTA, "8", CE, LAHTI');
        $pack = ProfixB2bConnector::analyse('L800808W', 'RĘKAWICE TEST L800808P, 12 PAR, "8", CE, LAHTI');
        $this->assertSame($seven['key'], $eight['key']);
        $this->assertNotSame($eight['key'], $pack['key']);

        // kod spoza wzorca: model bez końcówki rozmiaru; „ROZM.” i wiek dziecka to rozmiar
        $shoe = ProfixB2bConnector::analyse('LPTEST39', 'PÓŁBUTY TESTOWE CZARNE, S1 FO SR, "39", CE, LAHTI');
        $this->assertSame(['LPTEST', false, '39'], [$shoe['model'], $shoe['standard'], $shoe['size']]);
        $overalls = ProfixB2bConnector::analyse('LPTO882XL', 'OGRODNICZKI, ROZM. 2XL(188/106-110), CE, ALLTON LAHTI');
        $this->assertSame(['LPTO88', '2XL(188/106-110)'], [$overalls['model'], $overalls['size']]);
        $vest = ProfixB2bConnector::analyse('L8130101', 'KAMIZELKA OSTRZEG. ŻÓŁTA DLA DZIECI 4-6 LAT, "XS", CE, LAHTI');
        $this->assertSame('XS / 4-6 LAT', $vest['size']);
        $this->assertSame(['46001', false], array_values(array_intersect_key(ProfixB2bConnector::analyse('46001', 'GOGLE TESTOWE, CE, LAHTI'), ['model' => 1, 'standard' => 1])));

        // kolor ze skrótem z kropką i bez — ten sam kolor
        $this->assertSame(
            ProfixB2bConnector::analyse('L8018110K', 'RĘKAWICE WARSZT. CZAR.-POM, L818110P, KARTA, "10",CE,LAHTI')['colour_key'],
            ProfixB2bConnector::analyse('L8018111K', 'RĘKAWICE WARSZT. CZAR.-POM., L818111P, KARTA, "11",CE,LAHTI')['colour_key'],
        );
        $this->assertFalse(ProfixB2bConnector::isColour('POMPA'));
        $this->assertFalse(ProfixB2bConnector::isSize('BOMBER'));
        $this->assertTrue(ProfixB2bConnector::isSize('S (48)'));
    }

    public function test_helpers_read_html_pictograms_labelled_lines_and_original_images(): void
    {
        $html = '<ul><li>MATERIAŁ: Wierzch: sk&amp;oacute;ra</li><li>NORMA: EN ISO 21420, EN 388</li><li>Wygłuszanie: SNR: 30 dB</li></ul>'
            .'<div class="picto"><img src="/photo/pim/x.svg" alt="" /><img src="/photo/pim/k2.svg" alt="Kategoria II" title="Środek ochrony indywidualnej kategorii II" /><img src="/a.svg" alt="Wykonane ze sk&amp;oacute;ry" /></div>';
        $text = ProfixB2bConnector::htmlText($html);

        $this->assertSame("MATERIAŁ: Wierzch: skóra\nNORMA: EN ISO 21420, EN 388\nWygłuszanie: SNR: 30 dB", $text);
        $this->assertSame(['Środek ochrony indywidualnej kategorii II', 'Wykonane ze skóry'], ProfixB2bConnector::pictograms($html));
        $this->assertSame(
            [['name' => 'MATERIAŁ', 'value' => 'Wierzch: skóra'], ['name' => 'NORMA', 'value' => 'EN ISO 21420, EN 388']],
            ProfixB2bConnector::labelledLines($text),
        );
        $this->assertSame('https://partners.profix.com.pl/media/cache/full/photo/pim/l80001/glowne.jpg', ProfixB2bConnector::imageUrl('https://partners.profix.com.pl/media/cache/square/photo/pim/l80001/glowne.jpg'));
        $this->assertSame('https://partners.profix.com.pl/media/cache/full/photo/pim/l80001/a.jpg', ProfixB2bConnector::imageUrl('https://partners.profix.com.pl/photo/pim/l80001/a.jpg'));
        $this->assertNull(ProfixB2bConnector::imageUrl('https://example.test/media/cache/square/photo/x.jpg'));
        $this->assertNull(ProfixB2bConnector::imageUrl('https://partners.profix.com.pl/documents/pim/x.pdf'));
    }

    public function test_footwear_norm_joins_the_safety_category_only_for_one_matching_footwear_norm(): void
    {
        $rows = static fn (string $norm, string $class): array => [
            ['name' => 'MATERIAŁ', 'value' => 'skóra'],
            ['name' => 'KAT. BEZPIECZEŃSTWA', 'value' => $class],
            ['name' => 'NORMA', 'value' => $norm],
        ];

        $this->assertSame(
            ['name' => 'NORMA (z kategorią bezpieczeństwa)', 'value' => 'EN ISO 20345:2022 S1 FO SR'],
            ProfixB2bConnector::footwearNorm($rows('EN ISO 20345:2022', 'S1 FO SR'))[2],
        );
        $this->assertSame('EN ISO 20347:2012 O1 FO SRC', ProfixB2bConnector::footwearNorm($rows('EN ISO 20347:2012', 'O1 FO SRC'))[2]['value']);
        $this->assertSame('EN ISO 20345 SB P HRO FO SR', ProfixB2bConnector::footwearNorm($rows('EN ISO 20345', 'SB P HRO FO SR'))[2]['value']);
        // klasa zawodowa przy normie bezpiecznej, dwie normy, norma nie obuwia — bez zmian
        foreach ([['EN ISO 20345:2022', 'O1 SRC'], ['EN ISO 20345:2022, EN ISO 20344', 'S1 SRC'], ['EN 388', 'S1 SRC'], ['EN ISO 20345:2022', 'kat. II']] as [$norm, $class]) {
            $this->assertSame($rows($norm, $class), ProfixB2bConnector::footwearNorm($rows($norm, $class)), $norm.' / '.$class);
        }
    }

    public function test_cards_group_sizes_and_colours_and_keep_other_products_and_packaging_apart(): void
    {
        $this->addCatalog();
        $this->fakeSite();

        $connector = $this->connector();
        $products = [];
        foreach ($connector->products() as $product) {
            $products[$product->sku] = $product;
        }

        $this->assertSame(['L80001', 'L80002', 'L8000300', 'L8000301', 'L80009', 'L800408W', 'L800407K'], array_keys($products));
        $pants = $products['L80001'];
        $this->assertSame('SPODNIE TESTOWE ZIELONO-CZARNE, CE, LAHTI', $pants->cardName);
        $this->assertSame(['L8000101', 'L8000102', 'L8000104'], array_column($pants->members, 'remote_id'));
        $this->assertSame(['S', 'M', 'XL'], array_column($pants->members, 'size'));
        $this->assertSame([65.0, 65.0, 71.5], array_map(static fn (array $m): float => $m['price']->net, $pants->members));
        $this->assertSame(65.0, $connector->price($pants)->net);
        $this->assertSame(100.0, $connector->price($pants)->base);
        $this->assertSame('Artykuły BHP > Odzież robocza', $pants->category);
        $this->assertSame('Kolor: ZIELONO-CZARNE; rozmiary: S, M, XL', $pants->variantSummary);
        $this->assertSame('Na stanie (dużo): S, M; Brak na stanie: XL', $pants->availability);

        // kolory jednego modelu — jedna karta, kolor w etykiecie wiersza, nazwa bez koloru
        $helmet = $products['L80002'];
        $this->assertSame(['BIAŁY', 'CZERWONY'], array_column($helmet->members, 'size'));
        $this->assertSame('HEŁM TESTOWY, KAT. III, CE, LAHTI', $helmet->cardName);
        $this->assertSame(
            ['https://partners.profix.com.pl/media/cache/full/photo/pim/l8000201/glowne.jpg', 'https://partners.profix.com.pl/media/cache/full/photo/pim/l8000202/glowne.jpg'],
            $connector->imageUrls($helmet),
        );

        // przyłbica i szybki pod jednym kodem modelu — dwie karty jednopozycyjne z nazwą dosłownie
        $this->assertSame([], $products['L8000301']->members);
        $this->assertNull($products['L8000301']->cardName);
        $this->assertSame('SZYBKI WYMIEN. DO PRZYŁBICY L8000300, CE, LAHTI', $products['L8000301']->name);

        // opakowanie rozdziela: „12 PAR” (rozmiary 8 i 9, po 12 par) i „KARTA” (jeden rozmiar — wiersz rozmiaru);
        // w modelu najpierw większa karta, choć „KARTA” ma niższy symbol (kolejność kluczy $products sprawdzona wyżej)
        $box = $products['L800408W'];
        $this->assertSame(['8', '9'], array_column($box->members, 'size'));
        $this->assertSame('RĘKAWICE TESTOWE, 12 PAR, CE, LAHTI', $box->cardName);
        $this->assertSame(12.0, $connector->price($box)->order?->min);
        $this->assertSame('para', $connector->price($box)->order?->unit);
        $this->assertSame(['7'], array_column($products['L800407K']->members, 'size'));

        // obca marka w szczegółach — pominięta z powodem; PROLINE w nazwie — poza kartami
        $this->assertSame('skipped', $products['L80009']->raw['status']);
        $this->assertStringContainsString('DEWALT', (string) $products['L80009']->raw['reason']);
        // pominięta karta niesie swoje pozycje — ich rozmiary nie mogą wyglądać na usunięte
        $this->assertSame(['L8000901', 'L8000902'], array_column($products['L80009']->members, 'remote_id'));
        $summary = implode("\n", $connector->runSummary());
        $this->assertStringContainsString('Lista Profix (Marka LAHTI PRO): 13 pozycji, 5 modeli → 7 kart', $summary);
        $this->assertStringContainsString('Pozycje z inną marką w nazwie (pominięte — konto zamawia tylko Lahti Pro): 1, np. 98001', $summary);
        $this->assertStringContainsString('Kod modelu w kilku kartach (inny wyrób, opakowanie albo oznaczenie pod tym samym kodem): 2, np. L80003, L80040', $summary);
        $this->assertStringContainsString('Pozycje z promocją niższą od ceny konta (cena zakupu = cena konta, bez promocji): 1, np. L8000102', $summary);
    }

    public function test_account_without_any_discount_on_the_first_cards_stops_the_run(): void
    {
        for ($i = 10; $i < 31; $i++) {
            $this->add('L9'.$i.'000', 'WYRÓB TESTOWY NR '.$i.', CE, LAHTI', 20.0, 20.0, 45);
        }
        $this->add('DCX124', 'WKRĘTARKA TESTOWA 18V', 500.0, 400.0, 76, brand: 1);
        $this->fakeSite();

        $this->expectException(B2bFatalException::class);
        $this->expectExceptionMessage('20 pierwszych kart partners.profix.com.pl bez ceny konta niższej od katalogowej');
        iterator_to_array($this->connector()->products(), false);
    }

    public function test_lost_session_is_renewed_once_and_a_refused_one_is_fatal(): void
    {
        $this->addCatalog();
        $this->fakeSite();
        $connector = new ProfixB2bConnector($this->client(), pageSize: 3);
        $connector->login();
        $this->expireAfterCalls = 3;

        $products = iterator_to_array($connector->products(), false);

        $this->assertSame(2, $this->logins);
        $this->assertCount(7, $products);

        $client = $this->client();
        $client->login();
        $this->apiRefused = true;

        $this->expectException(B2bFatalException::class);
        $this->expectExceptionMessage('Utracono sesję konta partners.profix.com.pl');
        $client->product('L8000101');
    }

    public function test_token_close_to_expiry_is_renewed_before_the_request(): void
    {
        $this->addCatalog();
        $this->fakeSite(jwtExpiresAt: 1_000_100);
        $now = 1_000_000;
        $client = new ProfixB2bClient(self::USER, self::PASSWORD, 0, static function (int $ms): void {}, static function () use (&$now): int {
            return $now;
        });
        $client->login();

        $client->product('L8000101');
        $this->assertSame(2, $this->logins, 'token ważny jeszcze 100 s — odnowiony przed zapytaniem');
    }

    public function test_brand_filter_that_returns_the_whole_catalog_or_is_missing_stops_the_run(): void
    {
        $this->addCatalog();
        $this->fakeSite();
        $this->filterIgnored = true;

        try {
            iterator_to_array($this->connector()->products(), false);
            $this->fail('lista bez działającego filtra marki nie może iść dalej');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString('Filtr marki LAHTI PRO nie zadziałał', $e->getMessage());
        }

        $this->filterIgnored = false;
        foreach ($this->items as $symbol => $item) {
            if ($item['_brand'] === self::LAHTI) {
                unset($this->items[$symbol]);
            }
        }
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('nie ma filtra „Marka: LAHTI PRO”');
        iterator_to_array($this->connector()->products(), false);
    }

    public function test_list_with_a_repeated_item_is_read_again_once_then_fails(): void
    {
        $this->addCatalog();
        $this->fakeSite();
        $this->duplicateOnPage2 = 1;
        $connector = new ProfixB2bConnector($this->client(), pageSize: 4);
        $connector->login();
        $messages = [];
        $connector->onListProgress(static function (string $m) use (&$messages): void {
            $messages[] = $m;
        });

        $this->assertCount(7, iterator_to_array($connector->products(), false));
        $this->assertStringContainsString('dwa razy na liście', implode("\n", $messages));

        $this->duplicateOnPage2 = 2;
        $connector = new ProfixB2bConnector($this->client(), pageSize: 4);
        $connector->login();
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('niespójna także po ponownym pobraniu');
        iterator_to_array($connector->products(), false);
    }

    public function test_sync_creates_cards_with_size_rows_norms_documents_and_images_and_a_second_run_changes_nothing(): void
    {
        Storage::fake('public');
        Storage::fake('local');
        $this->addCatalog();
        $this->fakeSite();

        $result = app(B2bAccountSyncRunner::class)->run($this->account(), delayMs: 0, withImages: true);

        $this->assertSame(6, $result['created'], implode(' | ', $result['errors']));
        $card = Product::query()->where('sku', 'L80001')->sole();
        $this->assertSame('Lahti Pro', $card->manufacturer);
        $this->assertSame('SPODNIE TESTOWE ZIELONO-CZARNE, CE, LAHTI', $card->name);
        $this->assertStringContainsString('MATERIAŁ: 100% bawełna', (string) $card->description);
        $this->assertEqualsCanonicalizing(['L8000101', 'L8000102', 'L8000104'], B2bProductLink::query()->where('product_id', $card->id)->pluck('remote_id')->all());
        $slot = ProductSourcePrice::query()->where('product_id', $card->id)->sole();
        $this->assertSame('65.00', (string) $slot->purchase_price);
        $this->assertSame('100.00', (string) $slot->catalog_price_net);
        $this->assertSame(
            ['M' => '65.00', 'S' => '65.00', 'XL' => '71.50'],
            ProductVariant::query()->where('product_id', $card->id)->where('kind', ProductVariant::KIND_SIZE)->orderBy('label')->pluck('purchase_price', 'label')->map(static fn ($p): string => (string) $p)->all(),
        );
        $this->assertSame(
            ['Karta produktu', 'Deklaracja zgodności', 'Instrukcja użytkowania'],
            ProductDocument::query()->where('product_id', $card->id)->orderBy('sort_order')->pluck('title')->all(),
        );
        $this->assertSame(2, ProductImage::query()->where('product_id', $card->id)->count());
        $this->assertSame(['5900000000101', '5900000000102', '5900000000104'], ProductIdentifier::query()->where('product_id', $card->id)->where('type', ProductIdentifier::TYPE_EAN)->orderBy('value')->pluck('value')->all());
        $this->assertSame(['L80001'], ProductIdentifier::query()->where('product_id', $card->id)->where('type', ProductIdentifier::TYPE_MODEL_CODE)->pluck('value')->all());
        $rows = collect(ProductShopCard::query()->where('product_id', $card->id)->sole()->fields)->flatMap(static fn (array $section): array => array_map(
            static fn (array $row): string => $row['name'].' | '.$row['value'],
            $section['rows'] ?? [],
        ))->all();
        $this->assertContains('Kod modelu | L80001', $rows);
        $this->assertContains('MATERIAŁ | 100% bawełna', $rows);
        $this->assertContains('Kraj pochodzenia | CN', $rows);
        $this->assertContains('Opakowanie zbiorcze | 20 SZT', $rows);
        $this->assertContains('Piktogramy | Środek ochrony indywidualnej kategorii pierwszej; Oznaczony znakiem CE', $rows);

        $gloves = Product::query()->where('sku', 'L800408W')->sole();
        $this->assertSame('profix', $gloves->manufacturer_norms['source']['connector'] ?? null);
        $this->assertEqualsCanonicalizing(['EN ISO 21420', 'EN 388'], array_column($gloves->manufacturer_norms['rows'] ?? [], 'label'));
        $gloveSlot = ProductSourcePrice::query()->where('product_id', $gloves->id)->sole();
        $this->assertSame(12.0, $gloveSlot->order_min_qty);
        $this->assertSame(12.0, $gloveSlot->order_step_qty);
        $this->assertSame('Artykuły BHP > Rękawice', $gloves->category);

        $this->assertSame(0, Product::query()->where('sku', 'like', '98001%')->count());
        $this->assertSame(0, Product::query()->where('sku', 'L80009')->count());

        $before = $this->snapshot();
        $second = app(B2bAccountSyncRunner::class)->run($this->account(), delayMs: 0, withImages: true);

        $this->assertSame(0, $second['created'], implode(' | ', $second['errors']));
        $this->assertSame(0, $second['updated'], implode(' | ', $second['errors']));
        $this->assertSame(6, $second['unchanged'], implode(' | ', $second['errors']));
        $this->assertSame($before, $this->snapshot());
    }

    public function test_registry_detects_profix_by_host_as_the_lahti_pro_manufacturer_site(): void
    {
        $registry = app(B2bConnectorRegistry::class);

        $this->assertSame('profix', $registry->keyForSites(['https://partners.profix.com.pl/app/login']));
        $this->assertSame('Profix', $registry->label('profix'));
        $this->assertTrue($registry->requiresPassword('profix'));
        $this->assertTrue($registry->isManufacturerSite('profix'));
        $this->assertTrue($registry->groupsSizes('profix'));
        $this->assertTrue($registry->sendsSizePrices('profix'));
        $this->assertSame(['Profix', 'Lahti Pro'], $registry->brandsForKey('profix'));
        $this->assertSame(['brand' => 'Lahti Pro', 'names' => ['NORMA', 'NORMA (z kategorią bezpieczeństwa)']], $registry->shopFieldNormSource('profix'));

        $account = B2bAccount::query()->create([
            'username' => self::USER, 'password' => 'sekret', 'sites' => ['https://partners.profix.com.pl/app/login'],
        ]);
        $connector = $registry->make($account, 0);

        $this->assertInstanceOf(ProfixB2bConnector::class, $connector);
        foreach ([B2bManufacturerSite::class, B2bShopFieldSource::class, B2bShopFieldNormSource::class, B2bDocumentSource::class, B2bImageGallery::class, B2bGroupsSizes::class, B2bSizePriceSource::class] as $interface) {
            $this->assertInstanceOf($interface, $connector);
        }
    }

    // ---- pomocnicze ----

    private function client(): ProfixB2bClient
    {
        return new ProfixB2bClient(self::USER, self::PASSWORD, 0, static function (int $ms): void {});
    }

    private function connector(): ProfixB2bConnector
    {
        $connector = new ProfixB2bConnector($this->client());
        $connector->login();

        return $connector;
    }

    private function account(): B2bAccount
    {
        return B2bAccount::query()->firstOrCreate(
            ['username' => self::USER],
            ['password' => self::PASSWORD, 'sites' => ['https://partners.profix.com.pl/app/login'], 'sync_images' => true],
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
            'category' => $p->category,
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
     * Katalog marki (13 pozycji) i obca pozycja spoza filtra:
     * - spodnie L80001 w trzech rozmiarach (XL droższy, bez stanu; M z promocją niższą od ceny konta),
     * - hełm L80002 w dwóch kolorach,
     * - przyłbica L8000300 i szybki do niej L8000301 pod jednym kodem modelu,
     * - rękawice L80040: „12 PAR” w rozmiarach 8 i 9 (po 12 par) oraz „KARTA” w rozmiarze 7,
     * - żyłka PROLINE z „Marką” LAHTI PRO, wyrób L80009 (dwa rozmiary) z obcą marką w szczegółach,
     * - poza marką: wkrętarka DEWALT.
     */
    private function addCatalog(): void
    {
        $this->add('L8000104', 'SPODNIE TESTOWE ZIELONO-CZARNE, "XL", CE, LAHTI', 110.0, 71.5, 48, stock: false);
        $this->add('L8000101', 'SPODNIE TESTOWE ZIELONO-CZARNE, "S", CE, LAHTI', 100.0, 65.0, 48);
        $this->add('L8000102', 'SPODNIE TESTOWE ZIELONO-CZARNE, "M", CE, LAHTI', 100.0, 65.0, 48, promotion: 50.0);
        $pants = [
            'name' => 'SPODNIE, TESTOWE',
            'content' => '<ul><li>MATERIAŁ: 100% bawełna</li><li>Podwójne szwy</li></ul><div class="picto"><img src="/photo/pim/1.svg" alt="Środek ochrony indywidualnej kategorii pierwszej" title="Środek ochrony indywidualnej kategorii pierwszej" /><img src="/photo/pim/ce.svg" alt="Oznaczony znakiem CE" title="Oznaczony znakiem CE" /></div>',
            'gallery' => ['/media/cache/square/photo/pim/l80001/glowne.jpg', '/media/cache/square/photo/pim/l80001/dod/01.jpg'],
            'documents' => [
                ['path' => '/documents/pim/kp/l8000101.pdf', 'name' => 'pim.doc.product_card'],
                ['path' => '/documents/pim/0001/xx/l80001/v1.pdf', 'name' => 'pim.doc.declaration_conformity'],
                ['path' => '/documents/pim/l80001/v5/curves.pdf', 'name' => 'pim.doc.instruction'],
            ],
        ];
        foreach (['L8000101' => 'S', 'L8000102' => 'M', 'L8000104' => 'XL'] as $symbol => $size) {
            $this->details[$symbol] = [...$pants, 'params' => [self::param(21, 'Rozmiar', [$size]), self::brand('LAHTI PRO')]];
        }

        $this->add('L8000201', 'HEŁM TESTOWY, BIAŁY, KAT. III, CE, LAHTI', 30.0, 19.5, 47);
        $this->add('L8000202', 'HEŁM TESTOWY, CZERWONY, KAT. III, CE, LAHTI', 30.0, 19.5, 47);
        foreach (['L8000201', 'L8000202'] as $symbol) {
            $this->details[$symbol] = [
                'name' => 'HEŁM PRZEMYSŁOWY', 'content' => '<ul><li>MATERIAŁ: HDPE</li><li>NORMA: EN 397</li></ul>',
                'params' => [self::brand('LAHTI PRO')], 'gallery' => ['/media/cache/square/photo/pim/'.strtolower($symbol).'/glowne.jpg'], 'documents' => [],
            ];
        }

        $this->add('L8000300', 'PRZYŁBICA SPAWAL. TESTOWA, CE, LAHTI', 300.0, 195.0, 47);
        $this->add('L8000301', 'SZYBKI WYMIEN. DO PRZYŁBICY L8000300, CE, LAHTI', 6.0, 3.9, 47);
        foreach (['L8000300', 'L8000301'] as $symbol) {
            $this->details[$symbol] = ['name' => 'PRZYŁBICA', 'content' => '<ul><li>NORMA: EN 175</li></ul>', 'params' => [self::brand('LAHTI PRO')], 'gallery' => [], 'documents' => []];
        }

        $this->add('L800408W', 'RĘKAWICE TESTOWE L800408P, 12 PAR, "8", CE, LAHTI', 50.0, 32.5, 49, unit: 'PR', multiple: 12);
        $this->add('L800409W', 'RĘKAWICE TESTOWE L800409P, 12 PAR, "9", CE, LAHTI', 50.0, 32.5, 49, unit: 'PR', multiple: 12);
        // „KARTA” ma niższy symbol niż „12 PAR” — a i tak idzie po większej karcie modelu
        $this->add('L800407K', 'RĘKAWICE TESTOWE L800407P, KARTA, "7", CE, LAHTI', 5.0, 3.25, 49, unit: 'PR');
        foreach (['L800408W', 'L800409W', 'L800407K'] as $symbol) {
            $this->details[$symbol] = [
                'name' => 'RĘKAWICE OCHRONNE', 'content' => '<ul><li>MATERIAŁ: poliester, lateks</li><li>NORMA: EN ISO 21420, EN 388</li></ul>',
                'params' => [self::param(21, 'Rozmiar', ['8 - M']), self::brand('LAHTI PRO')], 'gallery' => [], 'documents' => [],
            ];
        }

        $this->add('98001', 'ŻYŁKA TNĄCA TESTOWA 2.4MM X 15M, PROLINE', 10.0, 6.5, 4);
        $this->add('L8000901', 'WYRÓB TESTOWY, "S", CE, LAHTI', 10.0, 6.5, 45);
        $this->add('L8000902', 'WYRÓB TESTOWY, "M", CE, LAHTI', 10.0, 6.5, 45);
        $this->details['L8000901'] = ['name' => 'WYRÓB', 'content' => '', 'params' => [self::brand('DEWALT')], 'gallery' => [], 'documents' => []];

        $this->add('DCX124', 'WKRĘTARKA TESTOWA 18V', 500.0, 400.0, 76, brand: 1);
    }

    private function add(string $symbol, string $name, float $list, float $account, int $category, bool $stock = true, ?float $promotion = null, string $unit = 'SZT', int $multiple = 1, int $brand = self::LAHTI): void
    {
        $this->items[$symbol] = [
            'symbol' => $symbol,
            'ean' => '59000000'.str_pad((string) (crc32($symbol) % 100000), 5, '0', STR_PAD_LEFT),
            'provider' => '',
            'name' => $name,
            'countryOrigin' => 'CN',
            'customsCode' => '62034311',
            'stock' => $stock,
            'price' => [$list, 'PLN'],
            'unit' => $unit,
            'pricePromo' => [$account, 'PLN'],
            'multiple' => $multiple,
            'quantity' => 0,
            'gallery' => ['https://partners.profix.com.pl/media/cache/square/photo/pim/'.strtolower($symbol).'/glowne.jpg'],
            'allPromotions' => $promotion === null ? [] : [['price' => [number_format($promotion, 4, '.', ''), 'PLN'], 'name' => 'PROMOCJA TESTOWA', 'kind' => 2, 'set' => false, 'id' => 1]],
            'box' => 20,
            'stockLevel' => $stock ? 'high' : 'low',
            '_brand' => $brand,
            '_category' => $category,
        ];
        // EAN-y spodni sprawdzane w teście synchronizacji
        if (str_starts_with($symbol, 'L80001')) {
            $this->items[$symbol]['ean'] = '5900000000'.substr($symbol, -3);
        }
    }

    /**
     * @param  list<string>  $values
     * @return array<string, mixed>
     */
    private static function param(int $id, string $name, array $values): array
    {
        return ['id' => $id, 'name' => $name, 'image' => null, 'position' => 0, 'isProducer' => false, 'values' => $values];
    }

    /**
     * @return array<string, mixed>
     */
    private static function brand(string $value): array
    {
        return ['id' => self::BRAND_PARAM, 'name' => 'Marka', 'image' => null, 'position' => 1, 'isProducer' => true, 'values' => [$value]];
    }

    private function fakeSite(?int $jwtExpiresAt = null): void
    {
        $categories = [43 => 'Artykuły BHP', 4 => 'Dom i ogród', 76 => 'Elektronarzędzia'];
        $subs = [43 => [45 => 'Inne', 47 => 'Ochrona głowy i twarzy', 48 => 'Odzież robocza', 49 => 'Rękawice']];
        $parentOf = [45 => 43, 47 => 43, 48 => 43, 49 => 43];

        Http::fake(function (Request $request) use ($categories, $subs, $parentOf, $jwtExpiresAt) {
            $url = $request->url();
            $path = (string) parse_url($url, PHP_URL_PATH);

            if (str_starts_with($path, '/media/cache/full/photo/')) {
                return Http::response(self::png($path), 200, ['Content-Type' => 'image/jpeg']);
            }
            if (str_starts_with($path, '/documents/')) {
                return Http::response('%PDF-1.4 test '.$path, 200, ['Content-Type' => 'application/pdf']);
            }
            if ($path === '/api/auth/login') {
                $body = json_decode($request->body(), true) ?: [];
                $this->loginBodies[] = $body;
                if (($body['username'] ?? null) === self::USER && ($body['password'] ?? null) === self::PASSWORD) {
                    $this->logins++;
                    $token = $jwtExpiresAt !== null
                        ? 'eyJ0eXAiOiJKV1QifQ.'.rtrim(strtr(base64_encode((string) json_encode(['exp' => $jwtExpiresAt, 'n' => $this->logins])), '+/', '-_'), '=').'.sig'
                        : 'tok-'.$this->logins;
                    $this->tokens[] = $token;
                    $this->calls = 0;

                    return Http::response(['token' => $token, 'refresh_token' => 'r-'.$this->logins, 'refresh_token_expiration' => 1999999999], 200, ['Authorization' => 'Bearer '.$token]);
                }

                return Http::response(['code' => 401, 'message' => 'Invalid credentials.'], 401);
            }
            if (! str_starts_with($path, '/api/')) {
                return Http::response('', 404);
            }

            $token = (string) preg_replace('/^Bearer\s+/', '', $request->header('Authorization')[0] ?? '');
            $valid = ! $this->apiRefused && in_array($token, $this->tokens, true);
            if ($valid) {
                $this->calls++;
                if ($this->expireAfterCalls > 0 && $this->calls > $this->expireAfterCalls) {
                    $this->expireAfterCalls = 0;
                    $this->tokens = [];
                    $valid = false;
                }
            }
            if (! $valid) {
                return Http::response(['code' => 401, 'message' => $token === '' ? 'JWT Token not found' : 'Invalid JWT Token'], 401);
            }

            if (preg_match('#^/api/catalog/product/(.+)$#', $path, $m) === 1) {
                $symbol = rawurldecode($m[1]);
                $item = $this->items[$symbol] ?? null;
                if ($item === null) {
                    return Http::response(['status' => 404, 'detail' => 'Not Found'], 404);
                }
                $detail = $this->details[$symbol] ?? ['name' => $item['name'], 'content' => '', 'params' => [self::brand('LAHTI PRO')], 'gallery' => [], 'documents' => []];

                return Http::response([
                    ...$detail,
                    'gallery' => array_map(static fn (string $p): string => 'https://partners.profix.com.pl'.$p, $detail['gallery']),
                    'thumbnail' => [],
                    'ean' => $item['ean'],
                    'symbol' => $symbol,
                    'multiple' => $item['multiple'],
                    'countryOrigin' => 'CN',
                    'promotions' => [],
                ]);
            }
            if ($path !== '/api/catalog/grid') {
                return Http::response(['status' => 404], 404);
            }

            parse_str((string) parse_url($url, PHP_URL_QUERY), $query);
            $cats = ($query['cats'] ?? null) === 'true';
            $brand = $cats && ! $this->filterIgnored && isset($query['param_'.self::BRAND_PARAM]) ? (int) $query['param_'.self::BRAND_PARAM] : null;
            $category = isset($query['category']) ? (int) $query['category'] : null;
            $items = array_values(array_filter($this->items, static function (array $item) use ($brand, $category, $parentOf): bool {
                if ($brand !== null && $item['_brand'] !== $brand) {
                    return false;
                }

                return $category === null || $item['_category'] === $category || ($parentOf[$item['_category']] ?? null) === $category;
            }));
            usort($items, static fn (array $a, array $b): int => strcmp($a['symbol'], $b['symbol']));
            $page = max(1, (int) ($query['page'] ?? 1));
            $onpage = max(1, (int) ($query['onpage'] ?? 100));
            $slice = array_slice($items, ($page - 1) * $onpage, $onpage);
            if ($page === 2 && $this->duplicateOnPage2 > 0 && $slice !== []) {
                $this->duplicateOnPage2--;
                $slice[0] = $items[0];
            }
            $count = static function (int $id) use ($items, $parentOf): int {
                return count(array_filter($items, static fn (array $i): bool => $i['_category'] === $id || ($parentOf[$i['_category']] ?? null) === $id));
            };
            $brands = [];
            foreach ($this->items as $item) {
                $brands[$item['_brand']] = true;
            }
            $values = [];
            if (isset($brands[1])) {
                $values[] = ['id' => 1, 'name' => 'DEWALT'];
            }
            if (isset($brands[self::LAHTI])) {
                $values[] = ['id' => self::LAHTI, 'name' => 'LAHTI PRO'];
            }

            return Http::response([
                'total' => count($items),
                'data' => array_map(static fn (array $i): array => array_diff_key($i, ['_brand' => 1, '_category' => 1]), $slice),
                'categories' => array_map(static fn (int $id, string $title): array => ['title' => $title, 'translations' => [], 'id' => $id, 'cnt' => $count($id)], array_keys($categories), $categories),
                'current_category' => $category !== null ? ['title' => $categories[$category] ?? '', 'id' => $category] : false,
                'sub_categories' => $category !== null && isset($subs[$category])
                    ? array_map(static fn (int $id, string $title): array => ['title' => $title, 'id' => $id, 'cnt' => $count($id)], array_keys($subs[$category]), $subs[$category])
                    : [],
                'current_sub_category' => [],
                'params' => [
                    ['title' => 'Rozmiar', 'position' => 0, 'id' => 21, 'values' => [['id' => 1, 'name' => 'S']]],
                    ['title' => 'Marka', 'position' => 1, 'id' => self::BRAND_PARAM, 'values' => $values],
                ],
                'tid' => 0,
                'pages' => (int) ceil(count($items) / $onpage),
            ]);
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
