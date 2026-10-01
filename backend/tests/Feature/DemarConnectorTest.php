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
use App\Services\B2b\B2bGroupsSizes;
use App\Services\B2b\B2bImageGallery;
use App\Services\B2b\B2bManufacturerSite;
use App\Services\B2b\B2bRemoteProduct;
use App\Services\B2b\B2bShopFieldSource;
use App\Services\B2b\B2bSizePriceSource;
use App\Services\B2b\DemarB2bClient;
use App\Services\B2b\DemarB2bConnector;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use RuntimeException;
use Tests\TestCase;

/**
 * Łącznik hurt.demar24.pl (IdoSell) na atrapie sklepu (Http::fake). Znaczniki odwzorowują strony zalogowanego konta
 * z 01.10.2026: strona powitalna z formularzem (POST /signin.php: operation, login, password), odnośnik
 * „/login.php?operation=logout” tylko na stronach konta, strona produktu gościa = strona powitalna, mapa strony
 * (indeks .xml.gz z podmapami), ajax/projector.php z rozmiarami (klucz „00036-18”, price_net konta, amount) i cenami
 * produktu (beforerebate_net) — a bez sesji z cenami detalicznymi zamiast błędu. Strona produktu: window.product_data
 * z rozmiarami w kolejności przycisków, okruszki z podmenu, galeria z data-img_high_res, #projector_dictionary
 * (nazwa parametru z odnośnikiem „Więcej”, „Kod producenta” = EAN rozmiaru) i #projector_longdescription.
 *
 * Wszystkie dane (kody, ceny, EAN-y, nazwy, opisy) są SYNTETYCZNE.
 */
final class DemarConnectorTest extends TestCase
{
    use RefreshDatabase;

    private const USER = 'hurt_test';

    private const PASSWORD = 'dobre-haslo';

    /** @var array<int, array<string, mixed>> id produktu → produkt */
    private array $products = [];

    /** @var list<string> ważne sesje */
    private array $sessions = [];

    private int $logins = 0;

    /** @var list<array<string, mixed>> */
    private array $loginBodies = [];

    /** Przy pierwszym projector.php tego produktu sesja wygasa (odpowiedź jak dla gościa). */
    private ?int $expireAtProjectorOf = null;

    /** Strony produktów zawsze bez sesji (sklep nie przyjmuje logowania do stron). */
    private bool $pagesWithoutSession = false;

    protected function setUp(): void
    {
        parent::setUp();
        Queue::fake();
    }

    public function test_login_posts_the_form_and_confirms_the_logout_link(): void
    {
        $this->fakeSite();
        $client = $this->client();

        $client->login();

        $this->assertTrue($client->isLoggedIn());
        $this->assertSame(1, $client->logins());
        $this->assertSame(['operation' => 'login', 'login' => self::USER, 'password' => self::PASSWORD], $this->loginBodies[0]);
    }

    public function test_wrong_password_is_not_fatal(): void
    {
        $this->fakeSite();
        $client = new DemarB2bClient(self::USER, 'zle-haslo', 0, static function (int $ms): void {});

        try {
            $client->login();
            $this->fail('logowanie złym hasłem powinno się nie udać');
        } catch (B2bFatalException $e) {
            $this->fail('złe hasło to nie błąd krytyczny: '.$e->getMessage());
        } catch (RuntimeException $e) {
            $this->assertStringContainsString('sprawdź login i hasło', $e->getMessage());
        }
        $this->assertFalse($client->isLoggedIn());
    }

    public function test_sitemap_lists_products_from_all_submaps(): void
    {
        $this->addBoots();
        $this->addSocks();
        $this->fakeSite();

        $list = $this->client()->sitemapProducts();

        $this->assertSame([
            501 => 'https://hurt.demar24.pl/product-pol-501-Trzewiki-ochronne-TEST-S3.html',
            502 => 'https://hurt.demar24.pl/product-pol-502-Skarpety-TEST.html',
        ], $list);
    }

    public function test_description_html_is_turned_into_lines_without_more_links(): void
    {
        $this->assertSame(
            "Trzewiki ochronne z podnoskiem.\nPodeszwa SRC\n- wkładka wymienna\n- cholewka skórzana",
            DemarB2bConnector::descriptionText('<p><span>Trzewiki <a href="/x.html">ochronne</a> z&nbsp;podnoskiem.</span></p>'
                .'<p>Podeszwa SRC <a href="/blog-pol-1.html">Więcej informacji</a></p><ul><li>wkładka wymienna</li><li>cholewka skórzana</li></ul>'),
        );
    }

    public function test_product_is_one_card_with_size_prices_ean_stock_description_fields_and_images(): void
    {
        $this->addBoots();
        $this->addSocks();
        $this->fakeSite();
        $connector = $this->connector();

        $products = iterator_to_array($connector->products(), false);

        $this->assertSame(['9001-TEST', '5754-TEST'], array_map(static fn (B2bRemoteProduct $p): string => $p->sku, $products));
        $card = $products[0];
        $this->assertSame('501-18', $card->remoteId);
        $this->assertSame('Trzewiki ochronne TEST S3', $card->name);
        $this->assertSame('Ochronne > Trzewiki ochronne', $card->category);
        $this->assertSame('https://hurt.demar24.pl/product-pol-501-Trzewiki-ochronne-TEST-S3.html', $card->sourceUrl);
        // kolejność przycisków strony, nie „priority” z projector.php; rozmiar 39 bez ceny konta — poza kartą
        $this->assertSame('Rozmiary: 40, 41, 48.', $card->variantSummary);
        $this->assertSame('Stan: 40: 4; 41: 0; 48.: 2', $card->availability);
        $this->assertSame(
            [['501-18', '5900000000400', '40', '100.00', '120.00', 'Stan: 4'], ['501-19', '5900000000417', '41', '100.00', '120.00', 'Stan: 0'], ['501-31', '5900000000486', '48.', '110.00', null, 'Stan: 2']],
            array_map(static fn (array $m): array => [$m['remote_id'], $m['sku'], $m['size'], sprintf('%.2F', $m['price']->net), $m['price']->base !== null ? sprintf('%.2F', $m['price']->base) : null, $m['availability']], $card->members),
        );
        $price = $connector->price($card);
        $this->assertSame([100.0, 120.0, 16.67, 'PLN'], [$price->net, $price->base, $price->discountPercent, $price->currency]);
        $this->assertSame([null, null, 'para'], [$price->order?->min, $price->order?->step, $price->order?->unit]);
        $this->assertSame('Demar', $connector->manufacturer($card));
        $this->assertSame(
            [
                [ProductIdentifier::TYPE_MODEL_CODE, '9001-TEST', null, null],
                [ProductIdentifier::TYPE_EAN, '5900000000400', '501-18', '40'],
                [ProductIdentifier::TYPE_EAN, '5900000000417', '501-19', '41'],
                [ProductIdentifier::TYPE_EAN, '5900000000486', '501-31', '48.'],
            ],
            array_map(static fn ($i): array => [$i->type, $i->value, $i->remoteId, $i->label], $card->identifiers ?? []),
        );
        $this->assertSame(
            "Trzewiki ochronne TEST z podnoskiem stalowym.\nPodeszwa odporna na poślizg SRC.\n- wkładka wymienna",
            $connector->description($card),
        );
        $this->assertSame(
            [
                'Informacje ze sklepu Demar | Marka | Demar',
                'Informacje ze sklepu Demar | Symbol | 9001-TEST',
                'Informacje ze sklepu Demar | Podnosek | Stalowy S',
                'Informacje ze sklepu Demar | Normy bezpieczeństwa | S3',
                'Informacje ze sklepu Demar | Parametry: obuwie ochronne i robocze | Właściwości antyelektrostatyczne; Odporność na poślizg (SRC)',
                'Informacje ze sklepu Demar | Jednostka sprzedaży | para',
                'Informacje ze sklepu Demar | Rozmiary | 40, 41, 48.',
            ],
            array_map(static fn ($f): string => $f->section.' | '.$f->name.' | '.$f->value, $connector->shopFields($card)),
        );
        $this->assertSame(
            ['https://hurt.demar24.pl/hpeciai/aa01/pol_pl_Trzewiki-TEST-501_1.jpg', 'https://hurt.demar24.pl/hpeciai/aa02/pol_pm_Trzewiki-TEST-501_2.jpg'],
            $connector->imageUrls($card),
        );

        // jeden rozmiar — pozycja pojedyncza; etykieta ze strony; bez marki = bez producenta
        $socks = $products[1];
        $this->assertSame('502-uniw', $socks->remoteId);
        $this->assertSame([], $socks->members);
        $this->assertSame('Rozmiary: Uniwersalny', $socks->variantSummary);
        $this->assertSame('', $connector->manufacturer($socks));
        $this->assertSame([20.0, 24.0], [$connector->price($socks)->net, $connector->price($socks)->base]);
        $this->assertSame(
            [[ProductIdentifier::TYPE_MODEL_CODE, '5754-TEST', null], [ProductIdentifier::TYPE_EAN, '5900000000509', '502-uniw']],
            array_map(static fn ($i): array => [$i->type, $i->value, $i->remoteId], $socks->identifiers ?? []),
        );

        $summary = implode("\n", $connector->runSummary());
        $this->assertStringContainsString('Lista Demar: 2 produktów', $summary);
        $this->assertStringContainsString('Karty: 2 (1 z rozmiarami w różnych cenach)', $summary);
        $this->assertStringContainsString('Rozmiary bez ceny konta (te rozmiary poza kartami): 1, np. 9001-TEST 39', $summary);
        $this->assertStringContainsString('bez ceny przed rabatem: 1, np. 9001-TEST 48.', $summary);
        $this->assertStringContainsString('Bez marki w sklepie (karty bez producenta): 1, np. 5754-TEST', $summary);
        $this->assertStringNotContainsString('Kod producenta bez pasującego rozmiaru', $summary);
    }

    public function test_product_without_price_and_a_repeated_symbol_are_skipped_with_a_reason(): void
    {
        $this->addBoots();
        $this->addSocks(id: 503, symbol: 'BEZ-CENY', net: 0.0);
        $this->addBoots(id: 504, slug: 'Trzewiki-ochronne-TEST-S3-kopia');
        $this->fakeSite();
        $connector = $this->connector();

        $products = iterator_to_array($connector->products(), false);

        $this->assertSame(['9001-TEST', 'BEZ-CENY', '9001-TEST'], array_map(static fn (B2bRemoteProduct $p): string => $p->sku, $products));
        $this->assertSame(['ok', 'skipped', 'skipped'], array_map(static fn (B2bRemoteProduct $p): string => $p->raw['status'], $products));
        $this->assertSame([], $connector->shopFields($products[1]));
        $summary = implode("\n", $connector->runSummary());
        $this->assertStringContainsString('Bez ceny konta (pominięte): 1, np. BEZ-CENY', $summary);
        $this->assertStringContainsString('Ten sam Symbol na kilku produktach (kolejne pominięte): 1, np. 9001-TEST (produkt 504)', $summary);
        $this->expectExceptionMessage('ten sam Symbol');
        $connector->price($products[2]);
    }

    public function test_symbol_of_a_product_without_price_does_not_block_a_priced_one_and_negative_stock_is_not_a_quantity(): void
    {
        $this->addSocks(id: 701, symbol: 'DUBEL', net: 0.0);
        $this->addSocks(id: 702, symbol: 'DUBEL', amount: -1);
        $this->fakeSite();
        $connector = $this->connector();

        $products = iterator_to_array($connector->products(), false);

        $this->assertSame(['skipped', 'ok'], array_map(static fn (B2bRemoteProduct $p): string => $p->raw['status'], $products));
        $this->assertSame('702-uniw', $products[1]->remoteId);
        $this->assertNull($products[1]->availability);
        $this->assertStringNotContainsString('Ten sam Symbol', implode("\n", $connector->runSummary()));
    }

    public function test_guest_prices_after_a_lost_session_are_fetched_again_with_the_account(): void
    {
        $this->addBoots();
        $this->fakeSite();
        $connector = $this->connector();
        $this->expireAtProjectorOf = 501;

        $card = iterator_to_array($connector->products(), false)[0];

        // projector.php bez sesji oddał cenę detaliczną 150 zł — strona produktu wykryła brak sesji
        $this->assertSame(100.0, $connector->price($card)->net);
        $this->assertSame(120.0, $connector->price($card)->base);
        $this->assertSame(2, $this->logins);
    }

    public function test_product_page_still_without_a_session_after_logging_in_again_stops_the_run(): void
    {
        $this->addBoots();
        $this->fakeSite();
        $connector = $this->connector();
        $this->pagesWithoutSession = true;

        $this->expectException(B2bFatalException::class);
        $this->expectExceptionMessage('Utracono sesję konta hurt.demar24.pl');

        iterator_to_array($connector->products(), false);
    }

    public function test_many_products_without_any_account_price_stop_the_run(): void
    {
        for ($i = 0; $i < 21; $i++) {
            $this->addSocks(id: 600 + $i, symbol: 'BEZ-'.$i, net: 0.0);
        }
        $this->fakeSite();

        $this->expectException(B2bFatalException::class);
        $this->expectExceptionMessage('bez żadnej ceny konta');

        iterator_to_array($this->connector()->products(), false);
    }

    public function test_sync_creates_the_card_with_sizes_identifiers_images_and_a_second_run_changes_nothing(): void
    {
        Storage::fake('public');
        Storage::fake('local');
        $this->addBoots();
        $this->fakeSite();

        $result = app(B2bAccountSyncRunner::class)->run($this->account(), delayMs: 0, withImages: true);

        $this->assertSame(1, $result['created'], implode(' | ', $result['errors']));
        $card = Product::query()->where('sku', '9001-TEST')->sole();
        $this->assertSame('Demar', $card->manufacturer);
        $this->assertStringContainsString('podnoskiem stalowym', (string) $card->description);
        $this->assertSame(['501-18', '501-19', '501-31'], B2bProductLink::query()->where('product_id', $card->id)->orderBy('remote_id')->pluck('remote_id')->all());
        $slot = ProductSourcePrice::query()->where('product_id', $card->id)->sole();
        $this->assertSame('100.00', (string) $slot->purchase_price);
        $this->assertSame('120.00', (string) $slot->catalog_price_net);
        $this->assertSame('110.00', (string) $slot->size_price_max);
        $this->assertSame(
            [['40', '100.00'], ['41', '100.00'], ['48.', '110.00']],
            ProductVariant::query()->where('product_id', $card->id)->where('kind', ProductVariant::KIND_SIZE)->orderBy('sort_order')->get()
                ->map(static fn (ProductVariant $v): array => [$v->label, (string) $v->purchase_price])->all(),
        );
        $this->assertSame(
            '5900000000417',
            ProductIdentifier::query()->where('product_id', $card->id)->where('type', ProductIdentifier::TYPE_EAN)->where('variant_label', '41')->value('value'),
        );
        $this->assertSame(2, ProductImage::query()->where('product_id', $card->id)->count());
        $rows = collect(ProductShopCard::query()->where('product_id', $card->id)->sole()->fields)->flatMap(static fn (array $section): array => array_map(
            static fn (array $row): string => ($section['section'] ?? '').' | '.$row['name'].' | '.$row['value'],
            $section['rows'] ?? [],
        ))->all();
        $this->assertContains('Informacje ze sklepu Demar | Normy bezpieczeństwa | S3', $rows);

        $before = $this->snapshot();
        $second = app(B2bAccountSyncRunner::class)->run($this->account(), delayMs: 0, withImages: true);

        $this->assertSame(0, $second['created'], implode(' | ', $second['errors']));
        $this->assertSame(0, $second['updated'], implode(' | ', $second['errors']));
        $this->assertSame(1, $second['unchanged'], implode(' | ', $second['errors']));
        $this->assertSame($before, $this->snapshot());
    }

    public function test_registry_detects_demar_by_host_as_the_manufacturer_site(): void
    {
        $registry = app(B2bConnectorRegistry::class);

        $this->assertSame('demar', $registry->keyForSites(['hurt.demar24.pl']));
        $this->assertSame('Demar', $registry->label('demar'));
        $this->assertTrue($registry->requiresPassword('demar'));
        $this->assertTrue($registry->isManufacturerSite('demar'));
        $this->assertTrue($registry->sendsSizePrices('demar'));
        $this->assertTrue($registry->groupsSizes('demar'));
        $this->assertNull($registry->shopFieldNormSource('demar'));
        $this->assertTrue(B2bConnectorRegistry::isConnectorUrl('https://hurt.demar24.pl/product-pol-501-x.html'));

        // konto zapisane przed łącznikiem: witryna bez https, bez klucza łącznika
        $account = B2bAccount::query()->create(['username' => self::USER, 'password' => 'sekret', 'sites' => ['hurt.demar24.pl']]);
        $connector = $registry->make($account, 0);

        $this->assertInstanceOf(DemarB2bConnector::class, $connector);
        foreach ([B2bManufacturerSite::class, B2bShopFieldSource::class, B2bImageGallery::class, B2bGroupsSizes::class, B2bSizePriceSource::class] as $interface) {
            $this->assertInstanceOf($interface, $connector);
        }
    }

    // ---- pomocnicze ----

    private function client(): DemarB2bClient
    {
        return new DemarB2bClient(self::USER, self::PASSWORD, 0, static function (int $ms): void {});
    }

    private function connector(): DemarB2bConnector
    {
        $connector = new DemarB2bConnector($this->client());
        $connector->login();

        return $connector;
    }

    private function account(): B2bAccount
    {
        return B2bAccount::query()->firstOrCreate(
            ['username' => self::USER],
            ['password' => self::PASSWORD, 'sites' => ['hurt.demar24.pl'], 'connector' => 'demar', 'sync_images' => true],
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
            'identifiers' => ProductIdentifier::query()->where('product_id', $p->id)->orderBy('id')->get(['type', 'value', 'variant_label', 'removed_at'])->toArray(),
        ]])->all();
    }

    /**
     * Trzewiki: rozmiar „48.” pierwszy w projector.php (priority 0), ale ostatni na stronie; 48. droższy (bez ceny
     * przed rabatem), 39 bez ceny konta, EAN-y w „Kod producenta”.
     */
    private function addBoots(int $id = 501, string $slug = 'Trzewiki-ochronne-TEST-S3'): void
    {
        $this->products[$id] = [
            'slug' => $slug,
            'symbol' => '9001-TEST',
            'name' => 'Trzewiki ochronne TEST S3',
            'firm' => 'Demar',
            'unit' => 'para',
            // [id rozmiaru, priority, etykieta w JSON, etykieta na stronie, cena konta, stan]
            'sizes' => [['31', 0, '48.', '48.', 110.0, 2], ['26', 26, '39', '39', 0.0, 0], ['18', 36, '40', '40', 100.0, 4], ['19', 38, '41', '41', 100.0, 0]],
            'page_order' => ['26', '18', '19', '31'],
            'headline' => 100.0,
            'before_rebate' => 120.0,
            'crumbs' => [['/pol_m_Ochronne-240.html', 'Ochronne'], ['/pol_m_Ochronne_Trzewiki-ochronne-249.html', 'Trzewiki ochronne']],
            'dictionary' => '<div class="dictionary__param row mb-3" data-producer="true"><div class="dictionary__name col-6"><span class="dictionary__name_txt">Marka</span></div><div class="dictionary__values col-6"><div class="dictionary__value"><a class="dictionary__value_txt" href="/firm-pol-1-Demar.html">Demar</a></div></div></div>'
                .'<div class="dictionary__param row mb-3" data-code="true"><div class="dictionary__name col-6"><span class="dictionary__name_txt">Symbol</span></div><div class="dictionary__values col-6"><div class="dictionary__value"><span class="dictionary__value_txt">9001-TEST</span></div></div></div>'
                .'<div class="dictionary__param row mb-3" data-producer_code="true"><div class="dictionary__name col-6"><span class="dictionary__name_txt">Kod producenta</span></div><div class="dictionary__values col-6">'
                .self::producerCode('39', '5900000000394').self::producerCode('40', '5900000000400').self::producerCode('41', '5900000000417').self::producerCode('48.', '5900000000486').'</div></div>'
                .'<div class="dictionary__param row mb-3"><div class="dictionary__name col-6"><span class="dictionary__name_txt">Podnosek</span></div><div class="dictionary__values col-6"><div class="dictionary__value"><span class="dictionary__value_txt">Stalowy S</span></div></div></div>'
                .'<div class="dictionary__param row mb-3"><div class="dictionary__name col-6"><span class="dictionary__name_txt">Normy bezpieczeństwa</span></div><div class="dictionary__values col-6"><div class="dictionary__value"><span class="dictionary__value_txt">S3</span></div></div></div>'
                .'<div class="dictionary__param row mb-3" data-gfx_value="true"><div class="dictionary__name col-6" data-desc="true"><span class="dictionary__name_txt">Parametry: obuwie ochronne i robocze<a href="#showDescription" class="dictionary__more">Więcej</a></span><div class="dictionary__description --name"><p>Edycja opisu Cechy:</p></div></div><div class="dictionary__values col-6">'
                .'<div class="dictionary__value" data-gfx="true"><span class="dictionary__value_txt">Właściwości antyelektrostatyczne</span><picture class="dictionary__picture --value"><img src="/gfx/standards/loader.gif" data-src="/data/lang/pol/traits/gfx/projector/237_4.png" alt=""></picture></div>'
                .'<div class="dictionary__value" data-gfx="true"><span class="dictionary__value_txt">Odporność na poślizg (SRC)</span></div></div></div>',
            'description' => '<p><span style="font-size: 12pt;">Trzewiki ochronne TEST z <a href="/pol_m_Obuwie-ochronne-172.html ">podnoskiem stalowym</a>.</span></p>'
                .'<p>Podeszwa odporna na poślizg SRC.</p><p><a href="/blog-pol-1.html">Więcej informacji</a></p><ul><li>wkładka wymienna</li></ul>',
            'photos' => [['/hpeciai/aa01/pol_pm_Trzewiki-TEST-501_1.jpg', '/hpeciai/aa01/pol_pl_Trzewiki-TEST-501_1.jpg'], ['/hpeciai/aa02/pol_pm_Trzewiki-TEST-501_2.jpg', null]],
        ];
    }

    /** Skarpety: jeden rozmiar „uniw” (w JSON „uniwersalny”, na stronie „Uniwersalny”), bez marki. */
    private function addSocks(int $id = 502, string $symbol = '5754-TEST', float $net = 20.0, int $amount = 5): void
    {
        $this->products[$id] = [
            'slug' => 'Skarpety-TEST',
            'symbol' => $symbol,
            'name' => 'Skarpety TEST',
            'firm' => null,
            'unit' => 'para',
            'sizes' => [['uniw', 0, 'uniwersalny', 'Uniwersalny', $net, $amount]],
            'page_order' => ['uniw'],
            'headline' => $net,
            'before_rebate' => $net > 0 ? 24.0 : null,
            'crumbs' => [['/pol_m_Akcesoria-297.html', 'Akcesoria']],
            'dictionary' => '<div class="dictionary__param row mb-3" data-code="true"><div class="dictionary__name col-6"><span class="dictionary__name_txt">Symbol</span></div><div class="dictionary__values col-6"><div class="dictionary__value"><span class="dictionary__value_txt">'.$symbol.'</span></div></div></div>'
                .'<div class="dictionary__param row mb-3" data-producer_code="true"><div class="dictionary__name col-6"><span class="dictionary__name_txt">Kod producenta</span></div><div class="dictionary__values col-6">'.self::producerCode('Uniwersalny', '5900000000509').'</div></div>',
            'description' => '<p>Skarpety z wełną.</p>',
            'photos' => [['/hpeciai/bb01/pol_pm_Skarpety-TEST-502_1.png', '/hpeciai/bb01/pol_pl_Skarpety-TEST-502_1.png']],
        ];
    }

    private static function producerCode(string $size, string $code): string
    {
        return '<div class="dictionary__value"><span class="dictionary__value_txt"><span class="dictionary__producer_code --name">'.$size.'</span><span class="dictionary__producer_code --value">'.$code.'</span></span></div>';
    }

    // ---- atrapa sklepu ----

    private function fakeSite(): void
    {
        Http::fake(function (Request $request) {
            $url = $request->url();
            $path = (string) parse_url($url, PHP_URL_PATH);
            preg_match('/(?:^|;\s*)client=([\w-]+)/', $request->header('Cookie')[0] ?? '', $m);
            $signedIn = in_array($m[1] ?? '', $this->sessions, true);
            $html = ['Content-Type' => 'text/html; charset=utf-8'];

            if ($path === '/signin.php' && $request->method() === 'POST') {
                $data = $request->data();
                $this->loginBodies[] = $data;
                if (($data['operation'] ?? null) === 'login' && ($data['login'] ?? null) === self::USER && ($data['password'] ?? null) === self::PASSWORD) {
                    $this->logins++;
                    $this->sessions[] = 's'.$this->logins;

                    return Http::response(self::page('<p>Moje konto</p>', true), 200, ['Set-Cookie' => 'client=s'.$this->logins.'; path=/; secure; HttpOnly'] + $html);
                }

                return Http::response(self::welcomePage(), 200, $html);
            }
            if ($path === '/') {
                return $signedIn
                    ? Http::response(self::page('<p>Strona główna</p>', true), 200, $html)
                    : Http::response(self::welcomePage(), 200, ['Set-Cookie' => 'client=guest; path=/'] + $html);
            }
            if ($path === '/sitemap.xml.gz') {
                return Http::response(gzencode('<?xml version="1.0" encoding="UTF-8"?><sitemapindex xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">'
                    .'<sitemap><loc>https://hurt.demar24.pl/sitemap-https-4-1.xml.gz</loc></sitemap><sitemap><loc>https://hurt.demar24.pl/sitemap-https-4-2.xml.gz</loc></sitemap></sitemapindex>'), 200, ['Content-Type' => 'application/xml']);
            }
            if ($path === '/sitemap-https-4-1.xml.gz') {
                // pierwsza podmapa: strony sklepu bez produktów, podana już rozpakowana
                return Http::response('<?xml version="1.0" encoding="UTF-8"?><urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9"><url><loc>https://hurt.demar24.pl/</loc></url><url><loc>https://hurt.demar24.pl/firm-pol-1-Demar.html</loc></url></urlset>', 200, ['Content-Type' => 'application/x-gzip']);
            }
            if ($path === '/sitemap-https-4-2.xml.gz') {
                $locs = '';
                foreach ($this->products as $id => $p) {
                    $locs .= '<url><loc>https://hurt.demar24.pl/product-pol-'.$id.'-'.$p['slug'].'.html</loc></url>';
                }

                return Http::response(gzencode('<?xml version="1.0" encoding="UTF-8"?><urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">'.$locs.'</urlset>'), 200, ['Content-Type' => 'application/x-gzip']);
            }
            if ($path === '/ajax/projector.php') {
                parse_str((string) parse_url($url, PHP_URL_QUERY), $query);
                $id = (int) ($query['product'] ?? 0);
                if (! isset($this->products[$id]) || ($query['get'] ?? '') !== 'sizes,sizeprices') {
                    return Http::response('null', 200, $html);
                }
                if ($this->expireAtProjectorOf === $id) {
                    $this->expireAtProjectorOf = null;
                    $this->sessions = [];
                    $signedIn = false;
                }

                // jak w sklepie: gość dostaje ceny detaliczne, nie błąd
                return Http::response(json_encode($this->projectorJson($id, $signedIn)), 200, $html);
            }
            if (preg_match('#^/product-pol-(\d+)-#', $path, $p) === 1 && isset($this->products[(int) $p[1]])) {
                if (! $signedIn || $this->pagesWithoutSession) {
                    // jak w sklepie: przekierowanie gościa na stronę powitalną
                    return Http::response(self::welcomePage(), 200, $html);
                }

                return Http::response(self::page($this->productPage((int) $p[1]), true), 200, $html);
            }
            if (str_starts_with($path, '/hpeciai/')) {
                return Http::response(self::jpeg($path), 200, ['Content-Type' => 'image/jpeg']);
            }

            return Http::response(self::page('<h1>Nie znaleziono</h1>', $signedIn), 404, $html);
        });
    }

    /**
     * @return array<string, mixed>
     */
    private function projectorJson(int $id, bool $signedIn): array
    {
        $p = $this->products[$id];
        $items = [];
        foreach ($p['sizes'] as [$sizeId, $priority, $jsonName, , $net, $amount]) {
            $value = $signedIn ? $net : 150.0;
            $items[sprintf('%05d', $priority).'-'.$sizeId] = [
                'type' => $sizeId, 'priority' => (string) $priority, 'name' => $jsonName, 'description' => $jsonName, 'amount' => $amount,
                'prices' => ['price_retail' => round($value * 1.23, 2), 'price' => round($value * 1.23, 2), 'price_net' => $value],
            ];
        }
        $headline = $signedIn ? $p['headline'] : 150.0;
        $sizeprices = ['value' => sprintf('%.2F', $headline * 1.23), 'price_net' => sprintf('%.2F', $headline), 'price_net_formatted' => number_format($headline, 2, ',', ' ').' zł'];
        if ($signedIn && $p['before_rebate'] !== null) {
            $sizeprices += ['detalprice_net' => '150.00', 'beforerebate_net' => sprintf('%.2F', $p['before_rebate']), 'beforerebate_yousave_percent' => '17'];
        }
        $sizes = [
            'id' => $id, 'name' => $p['name'], 'code' => $p['symbol'], 'link' => '/product-pol-'.$id.'-'.$p['slug'].'.html',
            'taxes' => ['vat' => '23.0'], 'unit' => $p['unit'], 'unit_sellby' => 1, 'items' => $items,
        ];
        if ($p['firm'] !== null) {
            $sizes['firm'] = ['name' => $p['firm'], 'productsLink' => '/firm-pol-1-'.$p['firm'].'.html'];
        }

        return ['sizes' => $sizes, 'sizeprices' => $sizeprices];
    }

    private function productPage(int $id): string
    {
        $p = $this->products[$id];
        $byId = [];
        foreach ($p['sizes'] as [$sizeId, , , $pageName, , $amount]) {
            $byId[$sizeId] = [$pageName, $amount];
        }
        $sizes = '';
        foreach ($p['page_order'] as $sizeId) {
            [$pageName, $amount] = $byId[$sizeId];
            $sizes .= "\t\t\t{\n\t\t\t\t\n\t\t\t\tname: \"".$pageName."\",\n\t\t\t\tid: \"".$sizeId."\",\n\t\t\t\tproduct_id: ".$id.",\n\t\t\t\tamount: ".$amount.",\n"
                ."\t\t\t\tavailability: {\n\t\t\t\t\tvisible: true,\n\t\t\t\t\tdescription: \"Produkt dostępny\",\n\t\t\t\t},\n\t\t\t},\n";
        }
        $crumbs = '<li><span>Jesteś tutaj:  </span></li><li class="bc-main"><span><a href="/">Strona główna</a></span></li>';
        foreach ($p['crumbs'] as $i => [$href, $text]) {
            $crumbs .= '<li class="category bc-item-'.($i + 1).'"><a class="category" href="'.$href.'">'.$text.'</a>'
                .($i === 0 ? '<ul class="breadcrumbs__sub"><li class="breadcrumbs__item"><a class="breadcrumbs__link --link" href="/pol_m_Inne-999.html">Inna kategoria</a></li></ul>' : '')
                .'</li>';
        }
        $crumbs .= '<li class="bc-active bc-product-name" aria-current="page"><span>'.$p['name'].'</span></li>';
        $photos = '';
        foreach ($p['photos'] as $i => [$src, $high]) {
            $photos .= '<figure class="photos__figure swiper-slide " data-slide-index="'.$i.'"><picture><source type="image/webp" srcset="'.str_replace('.jpg', '.webp', $src).'"></source>'
                .'<img class="photos__photo" src="'.$src.'" alt="'.$p['name'].'"'.($high !== null ? ' data-img_high_res="'.$high.'"' : '').'></picture></figure>';
        }

        return '<div id="breadcrumbs" class="breadcrumbs"><nav class="list_wrapper"><ol>'.$crumbs.'</ol></nav></div>'
            .'<section id="projector_photos" class="photos"><div id="photos_nav"><figure class="photos__figure --nav"><img src="/hpeciai/nav/pol_ps_miniatura.jpg"></figure></div>'
            .'<div id="photos_slider" class="photos__slider swiper"><div class="photos___slider_wrapper swiper-wrapper">'.$photos.'</div></div></section>'
            .'<section id="projector_productname"><h1 class="product_name__name">'.$p['name'].'</h1></section>'
            .'<script class="ajaxLoad">'."\n\tclient_login = 'true';\n\twindow.product_data = [{\n\t\tid: ".$id.",\n\t\tunit: {\n\t\t\tname: \"".$p['unit']."\",\n\t\t},\n\t\tsizes: [\n".$sizes."\t\t],\n\t}];\n</script>"
            .'<section id="projector_longdescription" class="section longdescription cm">'.$p['description'].'</section>'
            .'<section id="projector_dictionary" class="section dictionary"><div class="dictionary__group --first --no-group">'.$p['dictionary'].'</div></section>';
    }

    private static function page(string $body, bool $account): string
    {
        $header = $account
            ? '<a href="https://hurt.demar24.pl/client-orders.php">Moje zamówienia</a><a href="/login.php?operation=logout">Wyloguj się</a>'
            : '<a href="/login.php">Zaloguj się</a>';

        return '<!DOCTYPE html><html><head><meta charset="utf-8"><title>Demar24</title></head><body><header>'.$header.'</header><main>'.$body.'</main></body></html>';
    }

    private static function welcomePage(): string
    {
        return '<!DOCTYPE html><html><head><title>Strona powitalna dla hurtowni IAI-Shop.com</title></head><body><div id="b2b_welcome">'
            .'<form id="spb2b_loginform" action="/signin.php" method="post"><input type="hidden" name="operation" value="login">'
            .'<input id="signin_login_input" type="text" name="login"><input id="signin_pass_input" type="password" name="password"><button class="btn">Zaloguj się</button></form>'
            .'</div></body></html>';
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
