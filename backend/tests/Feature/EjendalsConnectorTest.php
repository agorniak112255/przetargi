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
use App\Services\B2b\B2bNormFactSource;
use App\Services\B2b\B2bRemoteProduct;
use App\Services\B2b\B2bShopFieldSource;
use App\Services\B2b\B2bSizePriceSource;
use App\Services\B2b\EjendalsB2bClient;
use App\Services\B2b\EjendalsB2bConnector;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use RuntimeException;
use Tests\TestCase;

/**
 * Łącznik www.ejendals.com na atrapie witryny (Http::fake). Kształt odpowiedzi odwzorowuje witrynę i konto #20
 * z 30.09.2026: /api/v2/connects/token (formularz grant_type=password, client_id=web) odpowiada JSON-em bez
 * access_token i ustawia ciasteczko sesji EjendalsAuthToken (expires_in 900), uprawnienia konta
 * (/api/v2/membership/user/current/get/permissions — lista napisów), lista /api/v2/search/products {totalMatching,
 * products} — z sesją konta z cenami (model, articles: cena za parę w EUR, opakowania bundle/carton/minUnit, jednostka
 * tylko przy opakowaniach), kategorie po angielsku; publiczna karta /api/v2/products/get/{kod}/card z kategoriami po
 * polsku, rozmiary
 * /api/v2/products/get/pdp/{kod}/variants (specyfikacja „Rozmiar (EU)”, „Numer artykułu”, „EAN-13”) i strona wyrobu
 * z modelem w window.contentDataModel (HTML bez treści — strona to aplikacja Vue).
 *
 * Wszystkie dane (kody, EAN, ceny, opisy) są SYNTETYCZNE.
 */
final class EjendalsConnectorTest extends TestCase
{
    use RefreshDatabase;

    private const LOGIN = 'zakupy@example.test';

    private const PASSWORD = 'dobre-haslo';

    private const MEDIA = 'https://www.ejendals.com/4910bc/globalassets/pim/media/9/';

    /** @var array<string, array<string, mixed>> wyroby wg kodu, w kolejności listy */
    private array $products = [];

    /** @var list<string> ważne tokeny */
    private array $tokens = [];

    private int $logins = 0;

    /** @var list<string> */
    private array $permissions = ['CartPermissions.Prices', 'CartPermissions.Modify'];

    private string $currency = 'EUR';

    private int $expiresIn = 900;

    private bool $listWithoutPrices = false;

    private bool $pageFails = false;

    private bool $counterChangesOnce = false;

    /** @var list<string> */
    private array $requests = [];

    protected function setUp(): void
    {
        parent::setUp();
        Queue::fake();
    }

    public function test_login_takes_a_token_by_password_grant_and_checks_the_price_permission(): void
    {
        $this->fakeSite();
        $client = $this->client();

        $client->login();

        $this->assertTrue($client->isLoggedIn());
        $token = Http::recorded(fn (Request $r): bool => str_ends_with($r->url(), '/api/v2/connects/token'))->first()[0];
        $this->assertSame(
            ['username' => self::LOGIN, 'password' => self::PASSWORD, 'client_id' => 'web', 'grant_type' => 'password', 'scope' => 'offline_access'],
            $token->data(),
        );
        $permissions = Http::recorded(fn (Request $r): bool => str_contains($r->url(), '/get/permissions'))->first()[0];
        // sesja w ciasteczku — witryna nie oddaje access_token w JSON-ie
        $this->assertStringContainsString('EjendalsAuthToken=tok-1', $permissions->header('Cookie')[0]);
        $this->assertSame('pl', $permissions->header('Ejendals-User-Market')[0]);
        $this->assertSame('pl', $permissions->header('Accept-Language')[0]);
    }

    public function test_wrong_password_fails_with_the_site_message_and_is_not_fatal(): void
    {
        $this->fakeSite();
        $client = new EjendalsB2bClient(self::LOGIN, 'zle-haslo', 0, static function (int $ms): void {});

        try {
            $client->login();
            $this->fail('logowanie złym hasłem powinno się nie udać');
        } catch (B2bFatalException $e) {
            $this->fail('złe hasło to nie błąd krytyczny: '.$e->getMessage());
        } catch (RuntimeException $e) {
            $this->assertStringContainsString('invalid_username_or_password', $e->getMessage());
            $this->assertStringContainsString('sprawdź login', $e->getMessage());
        }
        $this->assertFalse($client->isLoggedIn());
    }

    public function test_account_without_the_price_permission_cannot_log_in(): void
    {
        $this->fakeSite();
        $this->permissions = ['CartPermissions.Modify'];

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('CartPermissions.Prices');

        $this->client()->login();
    }

    public function test_product_page_model_gives_description_norms_marks_documents_and_images(): void
    {
        $page = EjendalsB2bConnector::parseProductPage(self::pageHtml(self::gloves()));

        $this->assertNotNull($page);
        $this->assertSame('TEGERA Testowa 9999', $page['name']);
        $this->assertSame("Cienka rękawica, poziom D\n\nErgonomiczna rękawica z CRF®. Powłoka z pianki nitrylowej.", $page['description']);
        $this->assertSame(
            [['label' => 'EN ISO 21420:2020', 'value' => ''], ['label' => 'EN 388:2016+A1:2018', 'value' => '4X43D'], ['label' => 'EN 407:2020', 'value' => 'X1XXXX']],
            $page['norms'],
        );
        // znaki i oznaczenia bez normy — do tabelki, nie do faktów o normach
        $this->assertSame(
            [['label' => 'CE', 'value' => 'Cat. II'], ['label' => 'Odpowiednie do kontaktu z żywnością', 'value' => '']],
            $page['marks'],
        );
        $this->assertSame(
            [['name' => 'Type', 'value' => 'Cut resistant gloves'], ['name' => 'Collection', 'value' => 'TEGERA® Testowa Collection'], ['name' => 'Prevents risk of', 'value' => 'Cut injuries']],
            $page['specs'],
        );
        // karta produktu tylko po polsku, bez deklaracji UKCA; karty techniczne pierwsze
        $this->assertSame(
            [
                ['Product sheet pl', ProductDocument::KIND_DATASHEET],
                ['Declaration of conformity EU', ProductDocument::KIND_CERTIFICATE],
                ['User instructions', ProductDocument::KIND_MANUAL],
            ],
            array_map(static fn (array $d): array => [$d['title'], $d['kind']], $page['documents']),
        );
        // obrót 360° spoza witryny odpada
        $this->assertSame([self::MEDIA.'pi_9999.webp', self::MEDIA.'di_9999-1.webp'], $page['images']);
        $this->assertSame(['Characteristics', 'Functions', 'Features'], array_column($page['lists'], 'title'));
    }

    public function test_product_sheet_falls_back_to_english_and_page_without_model_is_not_a_product(): void
    {
        $model = self::gloves()['page'];
        $model['documents'] = [self::document('ProductSheet', 'Product sheet', 'en', '9999_productsheet_en.pdf'), self::document('ProductSheet', 'Product sheet', 'de', '9999_productsheet_de.pdf')];

        $page = EjendalsB2bConnector::parseProductPage(self::pageHtml(['page' => $model]));

        $this->assertSame(['Product sheet en'], array_column($page['documents'] ?? [], 'title'));
        $this->assertNull(EjendalsB2bConnector::parseProductPage('<html><body>Nie znaleziono</body></html>'));
    }

    public function test_product_with_sizes_is_one_card_with_size_members_prices_and_identifiers(): void
    {
        $this->addProduct(self::gloves());
        $this->fakeSite();

        $products = $this->products();

        $this->assertCount(1, $products);
        $card = $products[0];
        $this->assertSame('9999', $card->sku);
        $this->assertSame('Rękawice TEGERA Testowa 9999', $card->name);
        $this->assertSame('Rękawice > Rękawice ochronne zabezpieczające przed przecięciem, Ochrona przed wysoką temperaturą', $card->category);
        $this->assertSame('https://www.ejendals.com/pl/products/gloves/cut-protection-gloves/9999/', $card->sourceUrl);
        $this->assertSame('9999-8', $card->remoteId);
        $this->assertSame(
            [['9999-8', '8', '11.24', '18.74', 'EUR', 'W magazynie'], ['9999-9', '9', '11.24', '18.74', 'EUR', 'W magazynie'], ['9999-10', '10', '12.00', '19.50', 'EUR', 'Brak w magazynie']],
            array_map(static fn (array $m): array => [$m['remote_id'], $m['size'], sprintf('%.2F', $m['price']->net), sprintf('%.2F', $m['price']->base), $m['price']->currency, $m['availability']], $card->members),
        );
        $this->assertSame('W magazynie: 8, 9; Brak w magazynie: 10', $card->availability);
        $connector = $this->connector();
        $price = $connector->price($card);
        $this->assertSame(11.24, $price->net);
        $this->assertSame(18.74, $price->base);
        $this->assertSame('EUR', $price->currency);
        // cena za parę, koszyk sprzedaje całe wiązki po 6 par
        $this->assertSame([6.0, 6.0, 'Pary', false], [$price->order?->min, $price->order?->step, $price->order?->unit, $price->order?->varies]);
        $this->assertTrue($price->order->restricts());
        $this->assertSame('EJENDALS', mb_strtoupper($connector->manufacturer($card)));
        $this->assertSame(
            [
                [ProductIdentifier::TYPE_MODEL_CODE, '9999', null],
                [ProductIdentifier::TYPE_MANUFACTURER_CODE, '9999-8', '9999-8'],
                [ProductIdentifier::TYPE_EAN, '7340000000081', '9999-8'],
                [ProductIdentifier::TYPE_MANUFACTURER_CODE, '9999-9', '9999-9'],
                [ProductIdentifier::TYPE_EAN, '7340000000091', '9999-9'],
                [ProductIdentifier::TYPE_MANUFACTURER_CODE, '9999-10', '9999-10'],
                [ProductIdentifier::TYPE_EAN, '7340000000101', '9999-10'],
            ],
            array_map(static fn ($i): array => [$i->type, $i->value, $i->remoteId], $card->identifiers ?? []),
        );
    }

    public function test_single_size_product_has_no_members_and_product_without_account_price_is_left_out(): void
    {
        $this->addProduct(self::indicator());
        $this->addProduct(self::withoutPrice());
        $this->fakeSite();
        $connector = $this->connector();

        $products = iterator_to_array($connector->products(), false);

        $this->assertSame(['9186T'], array_map(static fn (B2bRemoteProduct $p): string => $p->sku, $products));
        $this->assertSame([], $products[0]->members);
        $this->assertSame('9186T-1', $products[0]->remoteId);
        // akcesoria — bez rodzaju przed nazwą; kategorie po polsku z karty wyrobu
        $this->assertSame('TEGERA 9186T', $products[0]->name);
        $this->assertSame('Akcesoria > Inne akcesoria', $products[0]->category);
        $indicator = $connector->price($products[0]);
        $this->assertSame(170.18, $indicator->net);
        // bez ceny podstawowej wyższej od ceny konta; sprzedawany od jednej pary — bez ograniczenia
        $this->assertNull($indicator->base);
        $this->assertFalse($indicator->order?->restricts() ?? true);
        $this->assertStringContainsString('Bez ceny konta (pominięte): 1, np. 8888', implode("\n", $connector->runSummary()));
    }

    public function test_sizes_without_an_account_price_stay_off_the_card_and_are_reported(): void
    {
        $gloves = self::gloves();
        array_pop($gloves['articles']);
        $this->addProduct($gloves);
        $this->fakeSite();
        $connector = $this->connector();

        $products = iterator_to_array($connector->products(), false);

        $this->assertSame(['9999-8', '9999-9'], array_column($products[0]->members, 'remote_id'));
        $this->assertStringContainsString('rozmiarami bez ceny konta (te rozmiary poza kartami): 1, np. 9999', implode("\n", $connector->runSummary()));
    }

    public function test_sizes_in_two_currencies_skip_the_product_with_a_reason(): void
    {
        $gloves = self::gloves();
        $gloves['articles'][2]['price']['currency'] = 'SEK';
        $this->addProduct($gloves);
        $this->fakeSite();
        $connector = $this->connector();

        $products = iterator_to_array($connector->products(), false);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('jednej waluty');
        $connector->price($products[0]);
    }

    public function test_list_without_any_account_price_stops_the_run(): void
    {
        $this->addProduct(self::gloves());
        $this->listWithoutPrices = true;
        $this->fakeSite();

        $this->expectException(B2bFatalException::class);
        $this->expectExceptionMessage('bez żadnej ceny konta');

        $this->products();
    }

    public function test_list_across_pages_changed_once_is_read_again_from_the_start(): void
    {
        $this->addProduct(self::gloves());
        $this->addProduct(self::indicator());
        $this->counterChangesOnce = true;
        $this->fakeSite();
        $connector = new EjendalsB2bConnector($this->client(), pageSize: 1);
        $connector->login();

        $products = iterator_to_array($connector->products(), false);

        $this->assertSame(['9999', '9186T'], array_map(static fn (B2bRemoteProduct $p): string => $p->sku, $products));
    }

    public function test_session_near_its_end_is_renewed_before_a_list_page(): void
    {
        $this->addProduct(self::gloves());
        $this->addProduct(self::indicator());
        $this->expiresIn = 30;
        $this->fakeSite();
        $connector = new EjendalsB2bConnector($this->client(), pageSize: 1);
        $connector->login();

        $products = iterator_to_array($connector->products(), false);

        $this->assertCount(2, $products);
        // logowanie + nowa sesja przed każdą z dwóch stron listy (ważność 30 s < zapas 60 s)
        $this->assertSame(3, $this->logins);
    }

    public function test_sync_creates_the_ejendals_card_with_sizes_norms_documents_images_and_a_second_run_changes_nothing(): void
    {
        Storage::fake('public');
        Storage::fake('local');
        $this->addProduct(self::gloves());
        $this->addProduct(self::withoutPrice());
        $this->fakeSite();

        $result = app(B2bAccountSyncRunner::class)->run($this->account(), delayMs: 0, withImages: true);

        $this->assertSame(1, $result['created'], implode(' | ', $result['errors']));
        $card = Product::query()->where('sku', '9999')->sole();
        $this->assertSame('Ejendals', $card->manufacturer);
        $this->assertSame('Rękawice TEGERA Testowa 9999', $card->name);
        $this->assertStringContainsString('Ergonomiczna rękawica z CRF®', (string) $card->description);
        $this->assertSame(
            ['9999-10', '9999-8', '9999-9'],
            B2bProductLink::query()->where('product_id', $card->id)->orderBy('remote_id')->pluck('remote_id')->all(),
        );
        $slot = ProductSourcePrice::query()->where('product_id', $card->id)->sole();
        $this->assertSame('11.24', (string) $slot->purchase_price);
        $this->assertSame('18.74', (string) $slot->catalog_price_net);
        $this->assertSame('12.00', (string) $slot->size_price_max);
        $this->assertSame('EUR', $slot->currency);
        $this->assertEquals([6, 6, 'Pary'], [(float) $slot->order_min_qty, (float) $slot->order_step_qty, $slot->order_unit]);
        $this->assertSame(
            [['8', '11.24'], ['9', '11.24'], ['10', '12.00']],
            ProductVariant::query()->where('product_id', $card->id)->where('kind', ProductVariant::KIND_SIZE)->orderBy('sort_order')->get()
                ->map(static fn (ProductVariant $v): array => [$v->label, (string) $v->purchase_price])->all(),
        );
        $norms = $card->manufacturer_norms;
        $this->assertSame('4X43D', $norms['en388'] ?? null);
        $this->assertSame('ejendals', $norms['source']['connector'] ?? null);
        $this->assertSame(
            ['Product sheet pl', 'Declaration of conformity EU', 'User instructions'],
            ProductDocument::query()->where('product_id', $card->id)->orderBy('sort_order')->pluck('title')->all(),
        );
        $this->assertSame(
            [self::MEDIA.'pi_9999.webp', self::MEDIA.'di_9999-1.webp'],
            ProductImage::query()->where('product_id', $card->id)->orderBy('sort_order')->pluck('source_url')->all(),
        );
        $shopCard = ProductShopCard::query()->where('product_id', $card->id)->sole();
        $rows = collect($shopCard->fields)->flatMap(static fn (array $section): array => array_map(
            static fn (array $row): string => ($section['section'] ?? '').' | '.$row['name'].' | '.$row['value'],
            $section['rows'] ?? [],
        ))->all();
        $this->assertContains('Informacje ze sklepu Ejendals | Marka | TEGERA®', $rows);
        $this->assertContains('Informacje ze sklepu Ejendals | Cena za | Pary', $rows);
        $this->assertContains('Informacje ze sklepu Ejendals | Opakowania | Bundle 6 Pairs; Carton 120 Pairs', $rows);
        $this->assertContains('Compliance | EN 388:2016+A1:2018 | 4X43D', $rows);
        $this->assertContains('Compliance | CE | Cat. II', $rows);
        $this->assertContains('Specifications | Type | Cut resistant gloves', $rows);
        $this->assertEquals(
            ['' => '9999', '8' => '7340000000081'],
            array_intersect_key(
                ProductIdentifier::query()->where('product_id', $card->id)->where('type', '!=', ProductIdentifier::TYPE_MANUFACTURER_CODE)
                    ->orderBy('value')->pluck('value', 'variant_label')->all(),
                ['' => true, '8' => true],
            ),
        );

        $before = $this->snapshot();
        $second = app(B2bAccountSyncRunner::class)->run($this->account(), delayMs: 0, withImages: true);

        $this->assertSame(0, $second['created'], implode(' | ', $second['errors']));
        $this->assertSame(0, $second['updated'], implode(' | ', $second['errors']));
        $this->assertSame(1, $second['unchanged'], implode(' | ', $second['errors']));
        $this->assertSame($before, $this->snapshot());
    }

    public function test_product_page_failing_on_a_later_run_leaves_the_card_sizes_and_identifiers_untouched(): void
    {
        Storage::fake('public');
        Storage::fake('local');
        $this->addProduct(self::gloves());
        $this->fakeSite();
        $first = app(B2bAccountSyncRunner::class)->run($this->account(), delayMs: 0, withImages: false);
        $this->assertSame(1, $first['created'], implode(' | ', $first['errors']));
        $card = Product::query()->where('sku', '9999')->sole();
        $before = $this->snapshot();
        $identifiers = ProductIdentifier::query()->where('product_id', $card->id)->count();

        $this->pageFails = true;
        $second = app(B2bAccountSyncRunner::class)->run($this->account(), delayMs: 0, withImages: false);

        $this->assertSame(1, $second['skipped'], implode(' | ', $second['errors']));
        $this->assertStringContainsString('strona wyrobu', implode(' | ', $second['errors']));
        $this->assertSame($before, $this->snapshot());
        $this->assertSame(0, ProductVariant::query()->where('product_id', $card->id)->whereNotNull('removed_at')->count());
        $this->assertSame($identifiers, ProductIdentifier::query()->where('product_id', $card->id)->whereNull('removed_at')->count());
    }

    public function test_registry_detects_ejendals_by_host_as_the_manufacturer_site(): void
    {
        $registry = app(B2bConnectorRegistry::class);

        $this->assertSame('ejendals', $registry->keyForSites(['https://www.ejendals.com/pl/']));
        $this->assertSame('Ejendals', $registry->label('ejendals'));
        $this->assertTrue($registry->requiresPassword('ejendals'));
        $this->assertTrue($registry->isManufacturerSite('ejendals'));
        $this->assertTrue($registry->sendsSizePrices('ejendals'));

        $account = B2bAccount::query()->create([
            'username' => self::LOGIN, 'password' => 'sekret', 'sites' => ['https://www.ejendals.com'],
        ]);
        $connector = $registry->make($account, 0);

        $this->assertInstanceOf(EjendalsB2bConnector::class, $connector);
        foreach ([B2bManufacturerSite::class, B2bShopFieldSource::class, B2bNormFactSource::class, B2bDocumentSource::class, B2bImageGallery::class, B2bSizePriceSource::class] as $interface) {
            $this->assertInstanceOf($interface, $connector);
        }
    }

    // ---- pomocnicze ----

    private function client(): EjendalsB2bClient
    {
        return new EjendalsB2bClient(self::LOGIN, self::PASSWORD, 0, static function (int $ms): void {});
    }

    private function connector(): EjendalsB2bConnector
    {
        $connector = new EjendalsB2bConnector($this->client());
        $connector->login();

        return $connector;
    }

    /**
     * @return list<B2bRemoteProduct>
     */
    private function products(): array
    {
        return iterator_to_array($this->connector()->products(), false);
    }

    private function account(): B2bAccount
    {
        return B2bAccount::query()->firstOrCreate(
            ['username' => self::LOGIN],
            ['password' => self::PASSWORD, 'sites' => ['https://www.ejendals.com'], 'connector' => 'ejendals', 'sync_images' => true],
        )->fresh();
    }

    /**
     * @param  array<string, mixed>  $product
     */
    private function addProduct(array $product): void
    {
        $this->products[$product['list']['code']] = $product;
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
     * Rękawice w trzech rozmiarach: 8 i 9 w jednej cenie, 10 droższy i brak w magazynie.
     *
     * @return array<string, mixed>
     */
    private static function gloves(): array
    {
        return [
            'list' => [
                'code' => '9999',
                'title' => 'TEGERA® TESTOWA 9999',
                'typeOfProduct' => 1,
                'brand' => ['cvl' => ['key' => '60', 'value' => 'TEGERA®']],
                'text' => 'Cienka rękawica antyprzecięciowa.',
                'categories' => ['Cut protection gloves', 'Heat protection gloves', 'Gloves'],
                'variants' => ['9999-8', '9999-9', '9999-10'],
                'href' => '/pl/products/gloves/cut-protection-gloves/9999/',
                'isValid' => true,
                'isSupported' => true,
            ],
            'variants' => [
                self::variant('9999-8', '8', '7340000000081'),
                self::variant('9999-9', '9', '7340000000091'),
                self::variant('9999-10', '10', '7340000000101'),
            ],
            'cardCategories' => ['Rękawice ochronne zabezpieczające przed przecięciem', 'Ochrona przed wysoką temperaturą', 'Rękawice'],
            'model' => ['basePrice' => 18.74, 'price' => 11.24, 'currency' => 'EUR'],
            'articles' => [
                self::gloveArticle('9999-8', '8', 11.24, 18.74, true),
                self::gloveArticle('9999-9', '9', 11.24, 18.74, true),
                self::gloveArticle('9999-10', '10', 12.0, 19.5, false),
            ],
            'page' => [
                'code' => '9999',
                'title' => 'TEGERA Testowa 9999',
                'valueProposition' => 'Cienka rękawica, poziom D',
                'description' => 'Ergonomiczna rękawica z CRF®. Powłoka z pianki nitrylowej.',
                'characteristics' => [['key' => '07', 'value' => 'Wysoki poziom ochrony', 'language' => 'pl']],
                'functions' => [['key' => '71', 'value' => 'Odporność na ciepło kontaktowe do 100°C', 'language' => 'pl']],
                'features' => [['type' => 1, 'cvl' => ['key' => 'CutProtection', 'value' => 'Ochrona przed przecięciem', 'language' => 'pl']]],
                'compliances' => [
                    self::compliance('CE', [], 1, 'Cat. II'),
                    self::compliance('EN ISO 21420:2020', [], 0),
                    self::compliance('EN 388:2016+A1:2018 ', ['4X43D'], 4),
                    self::compliance('EN 407:2020', ['X1XXXX'], 4),
                    self::compliance('Odpowiednie do kontaktu z żywnością', [], 0),
                ],
                'specification' => [
                    ['label' => 'Type', 'value' => 'Cut resistant gloves', 'isFallback' => false],
                    ['label' => 'Collection', 'value' => '<a href=/tegera-safety-gloves/collections/testowa/>TEGERA® Testowa Collection</a>', 'isFallback' => false],
                ],
                'generalInformation' => [['label' => 'Prevents risk of', 'value' => 'Cut injuries', 'isFallback' => false]],
                'documents' => [
                    self::document('UserInstructions', 'User instructions', null, 'uis_9999_a4.pdf'),
                    self::document('DoC-EU', 'Declaration of conformity EU', null, 'doc_9999_a4.pdf'),
                    self::document('DoC-UKCA', 'Declaration of conformity UKCA', null, 'doc-ukca_9999_a4.pdf'),
                    self::document('ProductSheet', 'Product sheet', 'de', '9999_productsheet_de.pdf'),
                    self::document('ProductSheet', 'Product sheet', 'pl', '9999_productsheet_pl.pdf'),
                    self::document('ProductSheet', 'Product sheet', 'en', '9999_productsheet_en.pdf'),
                ],
                'images' => [
                    ['type' => 'MAINIMAGE', 'orginalUrl' => self::MEDIA.'pi_9999.webp'],
                    ['type' => '360', 'orginalUrl' => 'https://orbitvu.co/share/abc/1/360/view'],
                    ['type' => 'DI', 'orginalUrl' => self::MEDIA.'di_9999-1.webp'],
                ],
            ],
        ];
    }

    /**
     * Akcesorium w jednym rozmiarze, bez ceny podstawowej.
     *
     * @return array<string, mixed>
     */
    private static function indicator(): array
    {
        return [
            'list' => [
                'code' => '9186T',
                'title' => 'TEGERA® 9186T',
                'typeOfProduct' => 5,
                'brand' => ['cvl' => ['key' => '60', 'value' => 'TEGERA®']],
                'text' => 'Wskaźnik drgań.',
                'categories' => ['Other accessories', 'Accessories'],
                'variants' => ['9186T-1'],
                'href' => '/pl/products/accessories/other-accessories/9186t/',
                'isValid' => true,
                'isSupported' => true,
            ],
            'variants' => [self::variant('9186T-1', '1', '7333500000001')],
            'cardCategories' => ['Inne akcesoria', 'Akcesoria'],
            'model' => ['basePrice' => 0, 'price' => 170.18, 'currency' => 'EUR'],
            'articles' => [[
                'code' => '9186T-1',
                'size' => '1',
                'price' => ['basePrice' => 0, 'price' => 170.18, 'currency' => 'EUR'],
                'inStock' => true,
                'carton' => ['text' => '40 Pairs', 'fullText' => 'Carton 40 Pairs', 'quantity' => 40, 'min' => 1, 'baseUnit' => self::pairs()],
                'minUnit' => ['text' => '1 Pair', 'quantity' => 1, 'min' => 1, 'baseUnit' => self::pairs()],
            ]],
            'page' => ['code' => '9186T', 'title' => 'TEGERA 9186T', 'description' => 'Wskaźnik do monitorowania drgań.'],
        ];
    }

    /**
     * Wyrób, którego konto nie może kupić — karta bez ceny.
     *
     * @return array<string, mixed>
     */
    private static function withoutPrice(): array
    {
        return [
            'list' => [
                'code' => '8888',
                'title' => 'JALAS® 8888',
                'typeOfProduct' => 2,
                'brand' => ['cvl' => ['key' => '61', 'value' => 'JALAS®']],
                'categories' => ['Low shoes', 'Shoes'],
                'variants' => ['8888-40'],
                'href' => '/pl/products/shoes/low-shoes/8888/',
                'isValid' => true,
                'isSupported' => true,
            ],
            'variants' => [self::variant('8888-40', '40', '6408480000401')],
            'cardCategories' => ['Półbuty', 'Obuwie'],
            'model' => ['basePrice' => 0, 'price' => 0, 'currency' => 'EUR'],
            'articles' => [],
            'page' => ['code' => '8888', 'title' => 'JALAS 8888', 'description' => 'Półbut.'],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private static function variant(string $code, string $size, string $ean): array
    {
        return [
            'code' => $code,
            'specification' => [
                ['label' => 'Rozmiar (EU)', 'value' => $size, 'isFallback' => false],
                ['label' => 'Numer artykułu', 'value' => $code, 'isFallback' => false],
                ['label' => 'EAN-13', 'value' => $ean, 'isFallback' => false],
            ],
            'sizes' => ['eu' => $size],
        ];
    }

    /**
     * Rozmiar rękawic jak w zalogowanej liście: cena za parę, wiązka 6 par i karton 120 par; jednostka bazowa tylko
     * przy opakowaniach (karta wyrobu ma ją też przy rozmiarze, lista — nie).
     *
     * @return array<string, mixed>
     */
    private static function gloveArticle(string $code, string $size, float $price, float $base, bool $inStock): array
    {
        return [
            'code' => $code,
            'size' => $size,
            'price' => ['basePrice' => $base, 'price' => $price, 'currency' => 'EUR'],
            'inStock' => $inStock,
            'carton' => ['text' => '120 Pairs', 'fullText' => 'Carton 120 Pairs', 'quantity' => 120, 'min' => 6, 'baseUnit' => self::pairs()],
            'bundle' => ['text' => '6 Pairs', 'fullText' => 'Bundle 6 Pairs', 'quantity' => 6, 'min' => 6, 'baseUnit' => self::pairs()],
        ];
    }

    /**
     * @return array<string, string>
     */
    private static function pairs(): array
    {
        return ['contentId' => '862968', 'key' => 'PR', 'value' => 'Pary', 'language' => 'pl'];
    }

    /**
     * @param  list<string>  $values
     * @return array<string, mixed>
     */
    private static function compliance(string $label, array $values, int $special, ?string $category = null): array
    {
        return [
            'cvl' => ['key' => str_replace(' ', '', $label), 'value' => $label, 'language' => 'en'],
            'renderValues' => $values,
            'specialComplianceType' => $special,
            'protectionCategory' => $category !== null ? ['key' => '2', 'value' => $category] : null,
            'showValue' => $special !== 1,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private static function document(string $type, string $text, ?string $language, string $file): array
    {
        return [
            'type' => $type,
            'name' => strtoupper($file),
            'url' => self::MEDIA.$file,
            'languages' => $language !== null ? [$language] : [],
            'text' => $text,
        ];
    }

    /**
     * Strona wyrobu jak z witryny: HTML bez treści, model w skrypcie (z „}” i cudzysłowem w napisie).
     *
     * @param  array<string, mixed>  $product
     */
    private static function pageHtml(array $product): string
    {
        $model = [...$product['page'], 'seo' => ['title' => 'Rękawice "TEGERA" {test}']];

        return '<!DOCTYPE html><html lang="en" data-market="pl"><head></head><body><div id="app"></div>'
            .'<script>window.contentDataModel = '.json_encode($model)."\n    window.settings = {\"mapsKey\":\"x\"};\n</script>"
            .'</body></html>';
    }

    // ---- atrapa witryny ----

    private function fakeSite(): void
    {
        $listCalls = 0;
        Http::fake(function (Request $request) use (&$listCalls) {
            $url = $request->url();
            $path = (string) parse_url($url, PHP_URL_PATH);
            parse_str((string) parse_url($url, PHP_URL_QUERY), $query);
            preg_match('/EjendalsAuthToken=([\w-]+)/', $request->header('Cookie')[0] ?? '', $m);
            $signedIn = in_array($m[1] ?? '', $this->tokens, true);
            $this->requests[] = $path;

            if ($path === '/api/v2/connects/token') {
                $data = $request->data();
                if (($data['username'] ?? null) !== self::LOGIN || ($data['password'] ?? null) !== self::PASSWORD
                    || ($data['grant_type'] ?? null) !== 'password' || ($data['client_id'] ?? null) !== 'web') {
                    return Http::response(['error' => 'invalid_grant', 'error_description' => 'invalid_username_or_password'], 400);
                }
                $this->logins++;
                $this->tokens[] = 'tok-'.$this->logins;

                return Http::response(
                    ['token_type' => 'Bearer', 'expires_in' => $this->expiresIn, 'refresh_token' => 'r-'.$this->logins],
                    200,
                    ['Set-Cookie' => 'EjendalsAuthToken=tok-'.$this->logins.'; path=/; secure; HttpOnly'],
                );
            }
            if ($path === '/api/v2/membership/user/current/get/permissions') {
                return $signedIn ? Http::response($this->permissions) : Http::response(['message' => 'Authorization has been denied'], 401);
            }
            if ($path === '/api/v2/search/products') {
                $listCalls++;
                $page = max(1, (int) ($query['page'] ?? 1));
                $size = (int) ($query['pageSize'] ?? 10);
                $total = count($this->products);
                if ($this->counterChangesOnce && $listCalls === 2) {
                    $total++;
                }

                return Http::response([
                    'totalMatching' => $total,
                    'totalMatchWithFilter' => $total,
                    'products' => array_map(fn (array $p): array => $signedIn && ! $this->listWithoutPrices
                        ? [...$p['list'], 'model' => [...$p['model'], 'currency' => $this->currency], 'articles' => $p['articles']]
                        : $p['list'], array_slice(array_values($this->products), ($page - 1) * $size, $size)),
                    'filters' => [],
                ]);
            }
            if (preg_match('#^/api/v2/products/get/pdp/(.+)/variants$#', $path, $m) === 1) {
                $product = $this->products[rawurldecode($m[1])] ?? null;

                return Http::response($product['variants'] ?? []);
            }
            if (preg_match('#^/api/v2/products/get/(.+)/card$#', $path, $m) === 1) {
                $product = $this->products[rawurldecode($m[1])] ?? null;

                return $product !== null
                    ? Http::response([...$product['list'], 'categories' => $product['cardCategories']])
                    : Http::response(['message' => 'Not found'], 404);
            }
            foreach ($this->products as $product) {
                if ($path === $product['list']['href'] && $this->pageFails) {
                    return Http::response('Server Error', 500, ['Content-Type' => 'text/html']);
                }
                if ($path === $product['list']['href']) {
                    return Http::response(self::pageHtml($product), 200, ['Content-Type' => 'text/html; charset=utf-8']);
                }
            }
            if (str_starts_with($url, self::MEDIA)) {
                return str_ends_with($path, '.pdf')
                    ? Http::response('%PDF-1.4 test '.$path, 200, ['Content-Type' => 'application/pdf'])
                    : Http::response(self::jpeg($url), 200, ['Content-Type' => 'image/webp']);
            }

            return Http::response('Nie znaleziono '.$url, 404, ['Content-Type' => 'text/html']);
        });
    }

    private static function jpeg(string $path): string
    {
        $image = imagecreatetruecolor(1, 1);
        imagesetpixel($image, 0, 0, crc32($path) & 0xFFFFFF);
        ob_start();
        imagejpeg($image);

        return (string) ob_get_clean();
    }
}
