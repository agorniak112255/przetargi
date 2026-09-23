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
use App\Services\B2b\B2bAccountSyncRunner;
use App\Services\B2b\B2bConnectorRegistry;
use App\Services\B2b\B2bFatalException;
use App\Services\B2b\B2bListProgressAware;
use App\Services\B2b\B2bManufacturerSite;
use App\Services\B2b\B2bRemoteIdentifier;
use App\Services\B2b\B2bRemoteProduct;
use App\Services\B2b\B2bRunSummaryAware;
use App\Services\B2b\B2bShopFieldSource;
use App\Services\B2b\MascotB2bClient;
use App\Services\B2b\MascotB2bConnector;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use RuntimeException;
use Tests\TestCase;

/**
 * Łącznik b2b.mascot.dk na atrapie portalu (Http::fake, bez prawdziwego logowania). Kształt odpowiedzi odwzorowuje
 * portal z 22.09.2026 (ASP.NET MVC): strona /Login z ciasteczkiem sesji, JSON /Login/SignIn → {IsSuccess, Action,
 * Message}, /Progress/PreLoad, formularz /Distributor/LoginDone (strona konta z window.LoginToken i /Js/lang.pl.js),
 * /Profile/GetProfile z walutą konta, lista POST /Distributor/GetProducts {Results, TotalCount, Message} bez cen
 * i /Distributor/GetProductDetail z ceną każdego rozmiaru (Currency null, ExpectedDate „dd-mm-rrrr 00:00:00”).
 * Gość dostaje 302 na /Login — atrapa podaje od razu stronę logowania, którą klient widzi po przekierowaniu.
 *
 * Wszystkie dane (numery, EAN, ceny, opisy) są SYNTETYCZNE.
 */
final class MascotConnectorTest extends TestCase
{
    use RefreshDatabase;

    private const ACCOUNT = '99999';

    private const PASSWORD = 'dobre-haslo';

    private const CDN = 'https://productimage-1ccb8.kxcdn.com/';

    /** @var array<string, array<string, mixed>> szczegóły wg numeru, w kolejności listy */
    private array $articles = [];

    private string $currency = 'PLN';

    private string $action = '';

    /** @var list<string> */
    private array $validSessions = [];

    private int $logins = 0;

    private bool $dropSessionOnDetail = false;

    private bool $detailsAlwaysAnonymous = false;

    private bool $counterChangesOnce = false;

    private bool $polish = true;

    /** @var list<string> zdjęcia, których serwer nie ma (404) */
    private array $missingImages = [];

    /** @var list<string> */
    private array $requests = [];

    protected function setUp(): void
    {
        parent::setUp();
        Queue::fake();
    }

    public function test_login_signs_in_with_json_preloads_and_opens_the_account_page(): void
    {
        $this->fakeSite();
        $client = $this->client();

        $client->login();

        $this->assertTrue($client->isLoggedIn());
        $this->assertSame('PLN', $client->currency());
        $signIn = Http::recorded(fn (Request $r): bool => str_ends_with($r->url(), '/Login/SignIn'))->first()[0];
        $this->assertSame(['Username' => self::ACCOUNT, 'Password' => self::PASSWORD, 'LoginToken' => ''], $signIn->data());
        $this->assertStringStartsWith('pl-PL', $signIn->header('Accept-Language')[0]);
        $this->assertSame(
            ['/Login', '/Login/SignIn', '/Progress/PreLoad', '/Distributor/LoginDone', '/Profile/GetProfile'],
            $this->requests,
        );
    }

    public function test_wrong_password_fails_with_the_portal_message_and_is_not_fatal(): void
    {
        $this->fakeSite();
        $client = new MascotB2bClient(self::ACCOUNT, 'zle-haslo', 0, static function (int $ms): void {});

        try {
            $client->login();
            $this->fail('logowanie złym hasłem powinno się nie udać');
        } catch (B2bFatalException $e) {
            $this->fail('złe hasło to nie błąd krytyczny: '.$e->getMessage());
        } catch (RuntimeException $e) {
            $this->assertStringContainsString('Nieprawidłowe hasło', $e->getMessage());
        }
        $this->assertFalse($client->isLoggedIn());
    }

    public function test_portal_demanding_a_password_change_says_so(): void
    {
        $this->fakeSite();
        $this->action = 'NTCP';

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('wymaga zmiany hasła');

        $this->client()->login();
    }

    public function test_session_not_in_polish_fails_the_login(): void
    {
        $this->fakeSite();
        $this->polish = false;

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('język polski');

        $this->client()->login();
    }

    public function test_article_code_is_split_five_three_rest(): void
    {
        $this->assertSame('18001-249-1809', MascotB2bConnector::articleCode('180012491809'));
        $this->assertSame('F0004-910-09', MascotB2bConnector::articleCode('F000491009'));
        $this->assertSame('21203-415-01017', MascotB2bConnector::articleCode('2120341501017'));
        $this->assertSame('ABC', MascotB2bConnector::articleCode('ABC'));
    }

    public function test_availability_codes_read_like_the_portal_with_the_expected_date(): void
    {
        $this->assertSame('Na stanie', MascotB2bConnector::availability('G', '30-06-2026 00:00:00'));
        $this->assertSame('Brak na stanie, możliwość zamówienia, spodziewane od 10.11.2026', MascotB2bConnector::availability('R', '10-11-2026 00:00:00'));
        $this->assertSame('Ograniczona dostępność', MascotB2bConnector::availability('Y', ''));
        $this->assertSame('Nie można określić stanów magazynowych', MascotB2bConnector::availability('B', '02-02-2027 00:00:00'));
    }

    public function test_article_in_one_price_is_one_card_with_the_article_code_and_size_members(): void
    {
        $this->addArticle(self::boots());
        $this->fakeSite();

        $products = $this->products();

        $this->assertCount(1, $products);
        $card = $products[0];
        $this->assertSame('F9999-910-09', $card->sku);
        $this->assertSame('5700000000101', $card->remoteId);
        $this->assertSame('Buty ochronne MASCOT FOOTWEAR TEST F9999-910-09, czerń', $card->name);
        $this->assertSame('Buty ochronne', $card->category);
        $this->assertSame('https://b2b.mascot.dk/Distributor/ProductDetail?productNumber=F999991009', $card->sourceUrl);
        $this->assertSame(
            [
                ['remote_id' => '5700000000101', 'sku' => 'F9999-910-09 0840', 'name' => 'Buty ochronne MASCOT FOOTWEAR TEST F9999-910-09, czerń 0840'],
                ['remote_id' => '5700000000102', 'sku' => 'F9999-910-09 0841', 'name' => 'Buty ochronne MASCOT FOOTWEAR TEST F9999-910-09, czerń 0841'],
            ],
            $card->members,
        );
        $this->assertSame('Na stanie: 0840; Brak na stanie, możliwość zamówienia, spodziewane od 03.11.2026: 0841', $card->availability);
        $this->assertSame('Rozmiary: 0840 (EAN 5700000000101); 0841 (EAN 5700000000102)', $card->variantSummary);
        // numer z portalu dosłownie (karta) i EAN każdego rozmiaru przy jego pozycji
        $this->assertSame(
            [
                ['manufacturer_code', 'F999991009', null, null, 'Number'],
                ['ean', '5700000000101', '5700000000101', '0840', 'EanNumber'],
                ['ean', '5700000000102', '5700000000102', '0841', 'EanNumber'],
            ],
            array_map(static fn (B2bRemoteIdentifier $i): array => [$i->type, $i->value, $i->remoteId, $i->label, $i->field], $card->identifiers ?? []),
        );

        $connector = $this->connector();
        $price = $connector->price($card);
        $this->assertSame(289.95, $price?->net);
        $this->assertNull($price?->base);
        $this->assertSame("Sznurowane. Stalowy podnosek.\nPodeszwa odporna na oleje.", $connector->description($card));
        $this->assertSame('MASCOT', $connector->manufacturer($card));
        $fields = array_map(static fn ($f): array => [$f->name, $f->value], $connector->shopFields($card));
        $this->assertContains(['Kod artykułu', 'F9999-910-09'], $fields);
        $this->assertContains(['Materiał', 'Skóra bydlęca'], $fields);
        $this->assertContains(['EAN', '0840: 5700000000101; 0841: 5700000000102'], $fields);
    }

    public function test_sizes_in_two_prices_are_two_cards_named_with_their_sizes(): void
    {
        $this->addArticle(self::jacket());
        $this->fakeSite();

        $products = $this->products();

        $this->assertSame(
            [
                // przy podziale każda karta ma kod z pierwszym rozmiarem — sam kod artykułu nie przechodzi między kartami
                ['19999-249-1809 S', 'Kurtka membranowa MASCOT ACCELERATE 19999-249-1809, ciemny antracyt/czerń (rozm. S, M, L)', 3],
                ['19999-249-1809 3XL', 'Kurtka membranowa MASCOT ACCELERATE 19999-249-1809, ciemny antracyt/czerń (rozm. 3XL)', 1],
            ],
            array_map(static fn (B2bRemoteProduct $p): array => [$p->sku, $p->name, count($p->members)], $products),
        );
        $connector = $this->connector();
        $this->assertSame([539.0, 599.0], array_map(static fn (B2bRemoteProduct $p): ?float => $connector->price($p)?->net, $products));
    }

    public function test_missing_translation_is_an_empty_field_and_hidden_or_unpriced_sizes_stay_off_the_card(): void
    {
        $this->addArticle(self::untranslated());
        $this->fakeSite();
        $connector = $this->connector();

        $products = iterator_to_array($connector->products(), false);

        $this->assertCount(1, $products);
        $this->assertSame('Bluza MASCOT 17999-319-09', $products[0]->name);
        $this->assertSame('', $connector->description($products[0]));
        // rozmiar bez dostępności portal chowa, rozmiar z ceną 0 nie ma ceny — oba poza kartą
        $this->assertSame(['5700000000301'], array_column($products[0]->members, 'remote_id'));
        $summary = implode("\n", $connector->runSummary());
        $this->assertStringContainsString('rozmiarami bez ceny (te rozmiary poza kartami): 1, np. 1799931909', $summary);
        $this->assertStringContainsString('Bez polskiego opisu w portalu: 1, np. 1799931909', $summary);
    }

    public function test_detail_that_fails_or_names_another_number_is_skipped_with_a_reason(): void
    {
        $this->addArticle(self::boots());
        $broken = self::jacket();
        $broken['IsSuccess'] = false;
        $broken['Message'] = 'Produkt niedostępny!';
        $this->addArticle($broken);
        $this->fakeSite();
        $connector = $this->connector();

        $products = iterator_to_array($connector->products(), false);

        $this->assertCount(2, $products);
        $this->assertSame('skipped', $products[1]->raw['status']);
        try {
            $connector->price($products[1]);
            $this->fail('pominięty artykuł nie ma ceny');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString('Produkt niedostępny!', $e->getMessage());
        }
    }

    public function test_account_in_another_currency_stops_before_the_list(): void
    {
        $this->addArticle(self::boots());
        $this->fakeSite();
        $this->currency = 'EUR';
        $connector = $this->connector();

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('EUR');

        iterator_to_array($connector->products(), false);
    }

    public function test_list_across_pages_changed_once_is_read_again_from_the_start(): void
    {
        $this->addArticle(self::boots());
        $this->addArticle(self::jacket());
        $this->fakeSite();
        $this->counterChangesOnce = true;
        $connector = new MascotB2bConnector($this->client(), 1);
        $connector->login();
        $progress = [];
        $connector->onListProgress(static function (string $m) use (&$progress): void {
            $progress[] = $m;
        });

        $products = iterator_to_array($connector->products(), false);

        $this->assertCount(3, $products);
        $this->assertStringContainsString('pobieram od nowa', implode("\n", $progress));
    }

    public function test_session_lost_on_a_detail_logs_in_again_once(): void
    {
        $this->addArticle(self::boots());
        $this->fakeSite();
        $connector = $this->connector();
        $this->dropSessionOnDetail = true;

        $products = iterator_to_array($connector->products(), false);

        $this->assertSame('ok', $products[0]->raw['status']);
        $this->assertSame(2, $this->logins);
    }

    public function test_details_never_signed_in_are_fatal(): void
    {
        $this->addArticle(self::boots());
        $this->fakeSite();
        $connector = $this->connector();
        $this->detailsAlwaysAnonymous = true;

        $this->expectException(B2bFatalException::class);
        $this->expectExceptionMessage('Utracono sesję konta b2b.mascot.dk');

        iterator_to_array($connector->products(), false);
    }

    public function test_image_prefers_the_1000px_shot_and_falls_back_to_the_list_thumbnail(): void
    {
        $this->addArticle(self::boots());
        $this->addArticle(self::jacket());
        $this->fakeSite();
        $this->missingImages = [self::CDN.'19999-249-1809_P01_1000px.jpg'];
        $connector = $this->connector();
        $products = iterator_to_array($connector->products(), false);

        $this->assertSame(self::CDN.'F9999-910-09_P01_1000px.jpg', $connector->image($products[0])?->sourceUrl);
        $this->assertSame(self::CDN.'19999-249-1809_P01_400px.jpg', $connector->image($products[1])?->sourceUrl);
    }

    public function test_sync_creates_mascot_cards_with_prices_links_shop_card_and_images_and_a_second_run_changes_nothing(): void
    {
        Storage::fake('public');
        Storage::fake('local');
        $this->addArticle(self::boots());
        $this->addArticle(self::jacket());
        $this->fakeSite();

        $result = app(B2bAccountSyncRunner::class)->run($this->account(), delayMs: 0, withImages: true);

        $this->assertSame(3, $result['created'], implode(' | ', $result['errors']));
        $boots = Product::query()->where('sku', 'F9999-910-09')->sole();
        $this->assertSame('MASCOT', $boots->manufacturer);
        $this->assertSame(
            ['5700000000101', '5700000000102'],
            B2bProductLink::query()->where('product_id', $boots->id)->orderBy('remote_id')->pluck('remote_id')->all(),
        );
        $slot = ProductSourcePrice::query()->where('product_id', $boots->id)->sole();
        $this->assertSame('289.95', (string) $slot->purchase_price);
        $this->assertStringContainsString('Stalowy podnosek', (string) $boots->description);
        $this->assertTrue(ProductShopCard::query()->where('product_id', $boots->id)->exists());
        $this->assertSame(
            [self::CDN.'F9999-910-09_P01_1000px.jpg'],
            ProductImage::query()->where('product_id', $boots->id)->pluck('source_url')->all(),
        );
        $this->assertSame('599.00', (string) ProductSourcePrice::query()
            ->where('product_id', Product::query()->where('sku', '19999-249-1809 3XL')->value('id'))->value('purchase_price'));

        $this->assertSame(
            ['0840' => '5700000000101', '0841' => '5700000000102', '' => 'F999991009'],
            ProductIdentifier::query()->where('product_id', $boots->id)->orderBy('value')->pluck('value', 'variant_label')->all(),
        );

        $before = $this->snapshot();
        $identifiers = ProductIdentifier::query()->count();
        $second = app(B2bAccountSyncRunner::class)->run($this->account(), delayMs: 0, withImages: true);
        $this->assertSame($identifiers, ProductIdentifier::query()->count());
        $this->assertSame(0, ProductIdentifier::query()->whereNotNull('removed_at')->count());

        $this->assertSame(0, $second['created'], implode(' | ', $second['errors']));
        $this->assertSame(0, $second['updated'], implode(' | ', $second['errors']));
        $this->assertSame(3, $second['unchanged'], implode(' | ', $second['errors']));
        $this->assertSame($before, $this->snapshot());
    }

    public function test_registry_detects_mascot_by_host_as_the_manufacturer_site(): void
    {
        $registry = app(B2bConnectorRegistry::class);

        $this->assertSame('mascot', $registry->keyForSites(['https://b2b.mascot.dk/Login']));
        $this->assertSame('Mascot', $registry->label('mascot'));
        $this->assertTrue($registry->requiresPassword('mascot'));
        $this->assertNull($registry->discountRulesMode('mascot'));

        $account = B2bAccount::query()->create([
            'username' => self::ACCOUNT, 'password' => 'sekret', 'sites' => ['https://b2b.mascot.dk/'],
        ]);
        $connector = $registry->make($account, 0);

        $this->assertInstanceOf(MascotB2bConnector::class, $connector);
        $this->assertSame('MASCOT', MascotB2bConnector::ownBrand());
        foreach ([B2bManufacturerSite::class, B2bShopFieldSource::class, B2bRunSummaryAware::class, B2bListProgressAware::class] as $interface) {
            $this->assertInstanceOf($interface, $connector);
        }
    }

    // ---- pomocnicze ----

    private function client(): MascotB2bClient
    {
        return new MascotB2bClient(self::ACCOUNT, self::PASSWORD, 0, static function (int $ms): void {});
    }

    private function connector(): MascotB2bConnector
    {
        $connector = new MascotB2bConnector($this->client());
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
            ['username' => self::ACCOUNT],
            ['password' => self::PASSWORD, 'sites' => ['https://b2b.mascot.dk/'], 'connector' => 'mascot', 'sync_images' => true],
        )->fresh();
    }

    /**
     * @param  array<string, mixed>  $article
     */
    private function addArticle(array $article): void
    {
        $this->articles[$article['Number']] = $article;
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
            'price' => ProductSourcePrice::query()->where('product_id', $p->id)->get(['purchase_price', 'catalog_price_net', 'availability'])->toArray(),
        ]])->all();
    }

    /**
     * @return array<string, mixed>
     */
    private static function boots(): array
    {
        return [
            'Number' => 'F999991009',
            'Group' => 'FOOTWEAR TEST',
            'Category' => 'Feet',
            'Name' => 'Buty ochronne',
            'Type' => 'Buty ochronne',
            'Quality' => 'Skóra bydlęca',
            'Color' => 'czerń',
            'Description' => "Sznurowane.  Stalowy podnosek.\r\nPodeszwa odporna na oleje.",
            'Image' => self::CDN.'F9999-910-09_P01_400px.jpg',
            'ProductSizes' => [
                self::sizeRow('0840', '5700000000101', 289.95, 'G', '30-06-2026 00:00:00'),
                self::sizeRow('0841', '5700000000102', 289.95, 'R', '03-11-2026 00:00:00'),
            ],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private static function jacket(): array
    {
        return [
            'Number' => '199992491809',
            'Group' => 'ACCELERATE',
            'Category' => 'Upper body',
            'Name' => 'Kurtka membranowa',
            'Type' => 'Kurtka membranowa',
            'Quality' => '100% poliester',
            'Color' => 'ciemny antracyt/czerń',
            'Description' => 'Lekka tkanina. Oddychający, wiatro- i wodoszczelny.',
            'Image' => self::CDN.'19999-249-1809_P01_400px.jpg',
            'ProductSizes' => [
                self::sizeRow('S', '5700000000201', 539, 'G', '30-06-2026 00:00:00'),
                self::sizeRow('M', '5700000000202', 539, 'G', '30-06-2026 00:00:00'),
                self::sizeRow('L', '5700000000203', 539, 'G', '30-06-2026 00:00:00'),
                self::sizeRow('3XL', '5700000000204', 599, 'G', '30-06-2026 00:00:00'),
            ],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private static function untranslated(): array
    {
        return [
            'Number' => '1799931909',
            'Group' => 'Brak tekstu w tym języku',
            'Category' => 'Upper body',
            'Name' => 'Bluza',
            'Type' => 'Bluza',
            'Quality' => '100% poliester',
            'Color' => 'Brak tekstu w tym języku',
            'Description' => 'Brak tekstu w tym języku',
            'Image' => '',
            'ProductSizes' => [
                self::sizeRow('M', '5700000000301', 199, 'G', '30-06-2026 00:00:00'),
                self::sizeRow('L', '5700000000302', 199, '', ''),
                self::sizeRow('XL', '5700000000303', 0, 'G', '30-06-2026 00:00:00'),
            ],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private static function sizeRow(string $size, string $ean, int|float $price, string $availability, string $expected): array
    {
        return [
            'Id' => 1, 'Size' => $size, 'EanNumber' => $ean, 'Price' => $price, 'Currency' => null,
            'SizeOrder' => '00000001', 'Availability' => $availability, 'ExpectedDate' => $expected, 'OfferContent' => '',
            'E_Cm' => '', 'E1_Cm' => '', 'Size_CH' => $size, 'Size_DK' => $size, 'EUSize' => $size, 'FRSize' => $size,
        ];
    }

    // ---- atrapa portalu ----

    private function fakeSite(): void
    {
        $listCalls = 0;
        Http::fake(function (Request $request) use (&$listCalls) {
            $url = $request->url();
            $path = (string) parse_url($url, PHP_URL_PATH);
            parse_str((string) parse_url($url, PHP_URL_QUERY), $query);
            preg_match('/ASP\.NET_SessionId=(\w+)/', $request->header('Cookie')[0] ?? '', $m);
            $session = $m[1] ?? '';
            $signedIn = in_array($session, $this->validSessions, true);
            // portal odsyła gościa 302 na /Login — klient widzi stronę logowania (HTML, nie JSON)
            $guest = Http::response('<html><form id="loginForm"><input name="username"></form></html>', 200, ['Content-Type' => 'text/html; charset=utf-8']);
            if (parse_url($url, PHP_URL_HOST) === MascotB2bClient::HOST) {
                $this->requests[] = $path;
            }

            if ($path === '/Login' && $request->method() === 'GET') {
                $this->logins++;

                return Http::response('<html><form id="loginForm"><input name="username"></form>'
                    .'<script src="/Js/lang.'.($this->polish ? 'pl' : 'iv').'.js"></script></html>', 200, [
                        'Content-Type' => 'text/html; charset=utf-8',
                        'Set-Cookie' => 'ASP.NET_SessionId=s'.$this->logins.'; path=/; secure; HttpOnly',
                    ]);
            }
            if ($path === '/Login/SignIn') {
                $data = $request->data();
                if (($data['Username'] ?? null) !== self::ACCOUNT || ($data['Password'] ?? null) !== self::PASSWORD) {
                    return Http::response(['IsSuccess' => false, 'Message' => 'Nieprawidłowe hasło.']);
                }
                $this->validSessions[] = $session;

                return Http::response(['IsSuccess' => true, 'Action' => $this->action, 'Message' => '']);
            }
            if ($path === '/Progress/PreLoad') {
                return $signedIn ? Http::response('true') : $guest;
            }
            if ($path === '/Distributor/LoginDone') {
                if (! $signedIn) {
                    return $guest;
                }

                return Http::response('<html><script src="/Js/lang.'.($this->polish ? 'pl' : 'iv').'.js"></script>'
                    .'<script>window.LoginToken = \'abc-123\';</script></html>', 200, ['Content-Type' => 'text/html; charset=utf-8']);
            }
            if ($path === '/Profile/GetProfile') {
                return $signedIn
                    ? Http::response(['IsSuccess' => true, 'Info' => ['Number' => self::ACCOUNT, 'Currency' => $this->currency, 'Language' => 'EN']])
                    : $guest;
            }
            if ($path === '/Distributor/GetProducts') {
                if (! $signedIn) {
                    return $guest;
                }
                $listCalls++;
                $page = max(1, (int) ($request->data()['PageIndex'] ?? 1));
                $size = (int) ($request->data()['PageSize'] ?? 100);
                $total = count($this->articles);
                if ($this->counterChangesOnce && $listCalls === 2) {
                    $total++;
                }
                $items = array_slice(array_values($this->articles), ($page - 1) * $size, $size);

                return Http::response([
                    'Results' => array_map(static fn (array $a): array => [
                        'Number' => $a['Number'], 'Name' => $a['Name'], 'Group' => $a['Group'], 'Image' => $a['Image'],
                        'IsDiscontinued' => false, 'ProductSizes' => [],
                    ], $items),
                    'Alternatives' => null,
                    'Accessories' => null,
                    'TotalCount' => $total,
                    'Message' => '',
                ]);
            }
            if ($path === '/Distributor/GetProductDetail') {
                if ($this->dropSessionOnDetail) {
                    $this->dropSessionOnDetail = false;
                    $this->validSessions = [];
                    $signedIn = false;
                }
                if (! $signedIn || $this->detailsAlwaysAnonymous) {
                    return $guest;
                }
                $article = $this->articles[(string) ($query['productNumber'] ?? '')] ?? null;
                if ($article === null) {
                    return Http::response(['IsSuccess' => false, 'ProductDetail' => null, 'Message' => 'Brak produktu']);
                }
                $success = $article['IsSuccess'] ?? true;
                unset($article['IsSuccess']);
                $message = $article['Message'] ?? '';
                unset($article['Message']);

                return Http::response(['IsSuccess' => $success, 'ProductDetail' => $success ? $article : null, 'Message' => $message]);
            }
            if (str_starts_with($url, self::CDN)) {
                if (in_array($url, $this->missingImages, true) || str_contains($url, '_1000px') && ! str_contains($url, 'F9999')) {
                    return in_array($url, $this->missingImages, true)
                        ? Http::response('Not found', 404, ['Content-Type' => 'text/html'])
                        : Http::response(self::jpeg($url), 200, ['Content-Type' => 'image/jpeg']);
                }

                return Http::response(self::jpeg($url), 200, ['Content-Type' => 'image/jpeg']);
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
