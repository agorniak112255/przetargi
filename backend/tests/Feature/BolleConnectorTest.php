<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\B2bAccount;
use App\Services\B2b\B2bConnectorRegistry;
use App\Services\B2b\B2bFatalException;
use App\Services\B2b\B2bForeignLanguageSource;
use App\Services\B2b\B2bKeepsExistingNames;
use App\Services\B2b\B2bRemoteProduct;
use App\Services\B2b\B2bRemoteShopField;
use App\Services\B2b\BolleB2bClient;
use App\Services\B2b\BolleB2bConnector;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use RuntimeException;
use Tests\TestCase;

/**
 * Łącznik b2b.bolle-safety.com na atrapie sklepu (Http::fake, bez prawdziwego logowania). Sesja konta = ciasteczko
 * JSESSIONID ustawione przy logowaniu i wysyłane przez CookieJar klienta. Profil gościa = HTTP 401; /api/items
 * odpowiada także gościowi, ale z ceną katalogową (pricelevel1) w miejscu ceny konta — jak w sklepie.
 * Wszystkie dane (drzewo, pozycje, profil) są SYNTETYCZNE.
 */
final class BolleConnectorTest extends TestCase
{
    use RefreshDatabase;

    private const JPEG = "\xFF\xD8\xFF\xE0\x00\x10JFIF\x00\x01syntetyczny\xFF\xD9";

    private const IMAGE_URL = 'https://b2b.bolle-safety.com/core/media/media.nl?id=123456&c=5230881&h=0123456789abcdef0123';

    private const CLEAR = 'SAFETY GLASSES › Clear [lens] "K"';

    /** @var array<string, list<array<string, mixed>>> fullurl liścia → pozycje */
    private array $leaves = [];

    /** @var array<string, int> fullurl liścia → total podawany przez sklep (domyślnie liczba pozycji) */
    private array $totals = [];

    private ?string $environment = null;

    private bool $profileWithoutCurrency = false;

    /** Profil zawsze gościa, choć logowanie przyjmuje dane. */
    private bool $profileAlwaysGuest = false;

    /** @var list<string> */
    private array $validSessions = [];

    private int $logins = 0;

    /** Sesje o numerze większym dostają ceny gościa mimo profilu konta (null = wszystkie ceny konta). */
    private ?int $pricedSessions = null;

    /** Po tylu pobraniach listy kategorii wszystkie sesje wygasają (null = nigdy). */
    private ?int $dropSessionsAfterListings = null;

    private int $listings = 0;

    protected function setUp(): void
    {
        parent::setUp();

        $this->leaves = [
            '/safety-glasses/clear' => [
                $this->item(101),
                // bez ceny konta; rodzina tylko w displayname, krótki opis = rodzina
                $this->item(102, [
                    'itemid' => ' PSSRUSH0002 ', 'storedisplayname2' => '', 'displayname' => 'RUSH+', 'storedescription' => 'rush+',
                    'onlinecustomerprice' => 0, 'onlinecustomerprice_detail' => ['onlinecustomerprice' => 0], 'pricelevel1' => 20,
                ]),
            ],
            '/safety-glasses/tinted' => [
                // ta sama pozycja w drugim liściu
                $this->item(101),
                // bez rodziny i bez adresu karty
                $this->item(103, [
                    'itemid' => 'PSSCLEAR03', 'storedisplayname2' => '', 'displayname' => '', 'storedescription' => '<b>Clear</b> lens',
                    'onlinecustomerprice' => 12.5, 'onlinecustomerprice_detail' => ['onlinecustomerprice' => 12.5], 'pricelevel1' => 12.5,
                    'urlcomponent' => '',
                ]),
            ],
            '/goggles' => [
                $this->item(104, ['itemid' => 'GOGMATRIX', 'matrixchilditems_detail' => [['internalid' => 1041, 'itemid' => 'GOGMATRIX-S']]]),
            ],
            '/marketing-items/displays' => [
                $this->item(900, ['itemid' => 'DISPLAY900']),
            ],
        ];
    }

    public function test_login_posts_json_credentials_and_sends_session_cookie_with_later_requests(): void
    {
        $this->fakeSite();
        $client = $this->client();

        $client->login();
        $this->assertTrue($client->sessionAlive());

        $this->assertTrue($client->isLoggedIn());
        $this->assertSame('EUR', $client->currency());
        $post = Http::recorded(fn (Request $r): bool => $r->method() === 'POST')->first()[0];
        $this->assertSame('https://b2b.bolle-safety.com/safety/services/Account.Login.Service.ss?n=3&c=5230881', $post->url());
        $this->assertStringContainsString('application/json', $post->header('Content-Type')[0] ?? '');
        $this->assertSame(['email' => 'jan@example.com', 'password' => 'dobre-haslo', 'redirect' => 'true'], $post->data());
        $this->assertSame('checkout', $post->header('X-SC-Touchpoint')[0] ?? null);
        $this->assertSame('XMLHttpRequest', $post->header('X-Requested-With')[0] ?? null);
        $this->assertSame('https://b2b.bolle-safety.com', $post->header('Origin')[0] ?? null);

        $profiles = Http::recorded(fn (Request $r): bool => str_contains($r->url(), 'Profile.Service.ss'));
        $this->assertCount(2, $profiles);
        foreach ($profiles as [$request]) {
            $this->assertStringContainsString('JSESSIONID=sess1', $request->header('Cookie')[0] ?? '');
        }
    }

    public function test_login_with_guest_profile_fails_with_clear_message(): void
    {
        $this->profileAlwaysGuest = true;
        $this->fakeSite();
        $client = $this->client();

        try {
            $client->login();
            $this->fail('Logowanie powinno się nie udać');
        } catch (RuntimeException $e) {
            $this->assertNotInstanceOf(B2bFatalException::class, $e);
            $this->assertStringStartsWith('Logowanie do bolle-safety.com nieudane', $e->getMessage());
        }
        $this->assertFalse($client->isLoggedIn());
    }

    public function test_rejected_password_fails_with_shop_message(): void
    {
        $this->fakeSite();
        $client = new BolleB2bClient('jan@example.com', 'zle-haslo', 0, static function (int $ms): void {});

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Logowanie do bolle-safety.com nieudane: sklep odrzucił logowanie (HTTP 401: ERR_WS_INVALID_LOGIN Invalid login)');
        $client->login();
    }

    /**
     * Sklep odmawia logowania w HTTP 200 z kodem błędu w treści (15.09.2026: ERR_INVALID_ORIGIN bez nagłówków
     * przeglądarki). Komunikat ma podać powód sklepu, a nie sugerować złe hasło.
     */
    public function test_refusal_in_http_200_body_reports_shop_error_code(): void
    {
        Http::fake([
            'b2b.bolle-safety.com/safety/services/Account.Login.Service.ss*' => Http::response(
                ['errorStatusCode' => '400', 'errorCode' => 'ERR_INVALID_ORIGIN', 'errorMessage' => 'Invalid request origin'],
                200,
            ),
            '*' => Http::response(['errorCode' => 'NIE_POWINNO_BYC_WYWOLANE'], 500),
        ]);
        $client = $this->client();

        try {
            $client->login();
            $this->fail('Logowanie powinno się nie udać');
        } catch (RuntimeException $e) {
            $this->assertSame(
                'Logowanie do bolle-safety.com nieudane: sklep odrzucił logowanie (HTTP 200: ERR_INVALID_ORIGIN Invalid request origin)',
                $e->getMessage(),
            );
        }
        $this->assertFalse($client->isLoggedIn());
        Http::assertSentCount(1);
    }

    public function test_profile_without_currency_fails_login_instead_of_default_currency(): void
    {
        $this->profileWithoutCurrency = true;
        $this->fakeSite();
        $client = $this->client();

        try {
            $client->login();
            $this->fail('Logowanie bez waluty powinno się nie udać');
        } catch (RuntimeException $e) {
            $this->assertStringStartsWith('Logowanie do bolle-safety.com nieudane', $e->getMessage());
            $this->assertStringContainsString('currency.code', $e->getMessage());
        }
        $this->assertFalse($client->isLoggedIn());
        $this->expectException(RuntimeException::class);
        $client->currency();
    }

    public function test_leaf_categories_keep_tree_order_and_paths_without_marketing_items(): void
    {
        $this->fakeSite();

        $this->assertSame([
            ['url' => '/safety-glasses/clear', 'path' => self::CLEAR],
            ['url' => '/safety-glasses/tinted', 'path' => 'SAFETY GLASSES › Tinted'],
            ['url' => '/goggles', 'path' => 'GOGGLES'],
        ], $this->client()->leafCategories());
    }

    public function test_script_without_category_tree_is_an_error(): void
    {
        $this->environment = 'var SC = window.SC = {}; SC.ENVIRONMENT = {"x": "SC.CATEGORIES"};';
        $this->fakeSite();

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('nie zawiera drzewa kategorii (SC.CATEGORIES)');
        $this->client()->leafCategories();
    }

    public function test_listing_query_has_page_limit_and_no_parameters_rejected_by_shop_and_pages_by_offset(): void
    {
        $this->leaves['/goggles'] = array_map(fn (int $id): array => $this->item($id, ['itemid' => 'G'.$id]), range(2001, 2105));
        $this->fakeSite();
        $connector = $this->connector();
        $connector->login();

        $products = $this->productsById($connector);

        $this->assertCount(108, $products);
        $listings = Http::recorded(fn (Request $r): bool => str_contains($r->url(), 'commercecategoryurl='));
        $this->assertNotEmpty($listings);
        $offsets = [];
        foreach ($listings as [$request]) {
            parse_str((string) parse_url($request->url(), PHP_URL_QUERY), $query);
            $this->assertSame('5230881', $query['c']);
            $this->assertSame('3', $query['n']);
            $this->assertSame('details', $query['fieldset']);
            $this->assertSame('100', $query['limit']);
            foreach (['currency', 'language', 'pricelevel', 'sort'] as $rejected) {
                $this->assertArrayNotHasKey($rejected, $query);
            }
            $this->assertNotSame('/marketing-items/displays', $query['commercecategoryurl']);
            if ($query['commercecategoryurl'] === '/goggles') {
                $offsets[] = $query['offset'];
            }
        }
        $this->assertSame(['0', '100'], $offsets);
    }

    public function test_items_from_several_leaves_are_listed_once_with_first_leaf_category_and_total_known_upfront(): void
    {
        $this->fakeSite();
        $connector = $this->connector();
        $connector->login();

        $products = [];
        foreach ($connector->products() as $product) {
            $this->assertSame(4, $connector->totalProducts());
            $products[] = $product;
        }

        $this->assertSame(['101', '102', '103', '104'], array_map(static fn (B2bRemoteProduct $p): string => $p->remoteId, $products));
        $this->assertSame(self::CLEAR, $products[0]->category);
        $this->assertSame('SAFETY GLASSES › Tinted', $products[2]->category);
        $this->assertSame('PSSTRYOC13B', $products[0]->sku);
        $this->assertSame('PSSRUSH0002', $products[1]->sku);
    }

    public function test_incomplete_leaf_is_fatal(): void
    {
        $this->totals['/safety-glasses/tinted'] = 3;
        $this->fakeSite();
        $connector = $this->connector();
        $connector->login();

        $this->expectException(B2bFatalException::class);
        $this->expectExceptionMessage('Lista kategorii SAFETY GLASSES › Tinted niepełna (2 z 3)');
        $this->productsById($connector);
    }

    public function test_control_price_lost_after_leaf_logs_in_again_and_collects_leaf_again(): void
    {
        $this->dropSessionsAfterListings = 1;
        $this->fakeSite();
        $connector = $this->connector();
        $connector->login();

        $products = $this->productsById($connector);

        $this->assertSame(2, $this->logins);
        $this->assertCount(4, $products);
        $this->assertSame(30.15, $connector->price($products['101'])?->net);
        $clearListings = Http::recorded(fn (Request $r): bool => str_contains($r->url(), 'commercecategoryurl=%2Fsafety-glasses%2Fclear'));
        $this->assertCount(2, $clearListings);
        Http::assertSent(static fn (Request $r): bool => str_contains($r->url(), '/api/items') && str_contains($r->url(), 'id=101'));
    }

    public function test_control_price_still_catalog_after_relogin_is_fatal(): void
    {
        $this->dropSessionsAfterListings = 1;
        $this->pricedSessions = 1;
        $this->fakeSite();
        $connector = $this->connector();
        $connector->login();

        try {
            $this->productsById($connector);
            $this->fail('Oczekiwano B2bFatalException');
        } catch (B2bFatalException $e) {
            $this->assertStringStartsWith('Utracono sesję konta bolle-safety.com — ceny konta niedostępne', $e->getMessage());
        }
        $this->assertSame(2, $this->logins);
    }

    public function test_guest_session_at_start_logs_in_before_listing(): void
    {
        $this->fakeSite();
        $connector = $this->connector();

        $this->assertCount(4, $this->productsById($connector));
        $this->assertSame(1, $this->logins);
    }

    public function test_range_without_account_price_below_catalog_is_fatal(): void
    {
        $this->leaves['/safety-glasses/clear'][0] = $this->item(101, ['pricelevel1' => 30.15]);
        $this->leaves['/safety-glasses/tinted'][0] = $this->item(101, ['pricelevel1' => 30.15]);
        $this->leaves['/goggles'][0]['pricelevel1'] = 30.15;
        $this->fakeSite();
        $connector = $this->connector();
        $connector->login();

        $this->expectException(B2bFatalException::class);
        $this->expectExceptionMessage('nie ma żadnej pozycji z ceną konta niższą od katalogowej');
        $this->productsById($connector);
    }

    public function test_price_is_account_price_with_catalog_level_and_account_currency(): void
    {
        $this->fakeSite();
        $connector = $this->connector();
        $connector->login();
        $products = $this->productsById($connector);

        $price = $connector->price($products['101']);
        $this->assertSame(30.15, $price?->net);
        $this->assertSame(67.0, $price?->base);
        $this->assertSame(0.0, $price?->discountPercent);
        $this->assertSame('EUR', $price?->currency);

        // cena 0 = brak ceny
        $this->assertNull($connector->price($products['102']));
        $this->assertSame(12.5, $connector->price($products['103'])?->base);

        $hidden = new B2bRemoteProduct('105', 'X', 'X', raw: ['status' => 'ok', 'dontshowprice' => true, 'onlinecustomerprice' => 10, 'pricelevel1' => 20]);
        $this->assertNull($connector->price($hidden));

        $belowNet = new B2bRemoteProduct('106', 'Y', 'Y', raw: ['status' => 'ok', 'onlinecustomerprice' => 25.004, 'pricelevel1' => 20]);
        $this->assertSame(25.0, $connector->price($belowNet)?->net);
        $this->assertNull($connector->price($belowNet)?->base);
    }

    public function test_name_joins_family_with_distinct_short_description(): void
    {
        $this->fakeSite();
        $connector = $this->connector();
        $connector->login();
        $products = $this->productsById($connector);

        $this->assertSame('TRYON BSSI – Copper safety glasses', $products['101']->name);
        // krótki opis równy rodzinie (bez wielkości liter) nie jest dopisywany
        $this->assertSame('RUSH+', $products['102']->name);
        // bez rodziny — sam krótki opis
        $this->assertSame('Clear lens', $products['103']->name);
    }

    public function test_description_is_verbatim_sections_and_labelled_parameters_only(): void
    {
        $this->fakeSite();
        $connector = $this->connector();
        $connector->login();

        $description = $connector->description($this->productsById($connector)['101']);

        $this->assertSame(implode("\n", [
            'Copper safety glasses',
            '',
            'The TRYON BSSI offers a wraparound design.',
            '- EN 166 1FT',
            '- Anti-scratch & anti-fog',
            '',
            'Parametry:',
            '- Materiał oprawki: Nylon',
            '- Powłoka soczewki: Platinum®',
            '- Kolor soczewki: Copper',
        ]), $description);
        foreach (['&nbsp;', 'Technologia oprawki', 'Frame Material', 'SEGMENT-WEWNETRZNY', '2026-10-01', '<p>'] as $absent) {
            $this->assertStringNotContainsString($absent, $description);
        }
    }

    public function test_shop_card_has_the_item_code_and_the_labelled_parameters_without_extra_requests(): void
    {
        $this->fakeSite();
        $connector = $this->connector();
        $connector->login();
        $products = $this->productsById($connector);

        $before = Http::recorded()->count();
        $fields = $connector->shopFields($products['101']);

        $this->assertSame($before, Http::recorded()->count(), 'karta dostawcy nie dopytuje sklepu');
        $this->assertSame([
            ['Informacje handlowe', 'Kod towaru', 'PSSTRYOC13B'],
            // cechy dosłownie ze źródła (encje zdekodowane), puste „&nbsp;” pomijamy
            ['Parametry', 'Materiał oprawki', 'Nylon'],
            ['Parametry', 'Powłoka soczewki', 'Platinum®'],
            ['Parametry', 'Kolor soczewki', 'Copper'],
        ], self::rows($fields));

        // pozycja z wariantami (macierz) nie ma czego pokazać
        $this->assertSame([], $connector->shopFields($products['104']));
    }

    /**
     * @param  list<B2bRemoteShopField>  $fields
     * @return list<array{0: string, 1: string, 2: string}>
     */
    private static function rows(array $fields): array
    {
        return array_map(
            static fn ($field): array => [$field->section, $field->name, $field->value],
            $fields,
        );
    }

    public function test_image_uses_full_media_url_with_hash_and_rejects_foreign_host(): void
    {
        $this->fakeSite();
        $connector = $this->connector();
        $connector->login();

        $image = $connector->image($this->productsById($connector)['101']);

        $this->assertNotNull($image);
        $this->assertSame(self::JPEG, $image->bytes);
        $this->assertSame('image/jpeg', $image->mime);
        // API podaje ścieżkę bez domeny (fixture jak żywy sklep 15.09.2026) — zapisany adres jest pełny
        $this->assertSame(self::IMAGE_URL, $image->sourceUrl);
        $this->assertNull($connector->image(new B2bRemoteProduct('1', 'X', 'X', raw: ['status' => 'ok', 'custitem_atlas_item_image' => ''])));

        // pełny adres sklepu też działa
        $full = $connector->image(new B2bRemoteProduct('3', 'Z', 'Z', raw: ['status' => 'ok', 'custitem_atlas_item_image' => self::IMAGE_URL]));
        $this->assertSame(self::IMAGE_URL, $full?->sourceUrl);
        // ścieżka protokołu względnego („//host/…”) to obcy host, nie ścieżka sklepu
        try {
            $connector->image(new B2bRemoteProduct('4', 'W', 'W', raw: ['status' => 'ok', 'custitem_atlas_item_image' => '//obcy.example.com/zdjecie.jpg']));
            $this->fail('Adres protokołu względnego powinien być odrzucony');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString('odrzucony', $e->getMessage());
        }

        try {
            $connector->image(new B2bRemoteProduct('2', 'Y', 'Y', raw: ['status' => 'ok', 'custitem_atlas_item_image' => 'https://obcy.example.com/zdjecie.jpg']));
            $this->fail('Obcy host powinien być odrzucony');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString('odrzucony', $e->getMessage());
        }
        Http::assertNotSent(static fn (Request $r): bool => str_contains($r->url(), 'obcy.example.com'));
    }

    public function test_source_url_from_urlcomponent_or_null_when_empty(): void
    {
        $this->fakeSite();
        $connector = $this->connector();
        $connector->login();
        $products = $this->productsById($connector);

        $this->assertSame('https://b2b.bolle-safety.com/TRYON_BSSI_PSSTRYOC13B', $products['101']->sourceUrl);
        $this->assertNull($products['103']->sourceUrl);
    }

    public function test_matrix_item_is_skipped_with_reason(): void
    {
        $this->fakeSite();
        $connector = $this->connector();
        $connector->login();
        $matrix = $this->productsById($connector)['104'];

        $this->assertSame('skipped', $matrix->raw['status']);
        $this->assertSame('GOGMATRIX', $matrix->sku);
        $this->assertSame('GOGMATRIX', $matrix->name);
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('pozycja z wariantami (macierz) — importer ich nie obsługuje');
        $connector->price($matrix);
    }

    public function test_registry_detects_bolle_by_host_and_connector_keeps_existing_names(): void
    {
        $registry = app(B2bConnectorRegistry::class);

        $this->assertSame('bolle', $registry->keyForSites(['https://b2b.bolle-safety.com/']));
        $this->assertSame('Bolle', $registry->label('bolle'));
        $account = B2bAccount::query()->create(['username' => 'jan@example.com', 'password' => 'sekret', 'sites' => ['https://b2b.bolle-safety.com/']]);
        $connector = $registry->make($account, 0);
        $this->assertInstanceOf(BolleB2bConnector::class, $connector);
        $this->assertInstanceOf(B2bKeepsExistingNames::class, $connector);
        $this->assertInstanceOf(B2bForeignLanguageSource::class, $connector);
        $this->assertSame('Bolle', $connector->manufacturer(new B2bRemoteProduct('1', 'X', 'X')));
    }

    private function client(): BolleB2bClient
    {
        return new BolleB2bClient('jan@example.com', 'dobre-haslo', 0, static function (int $ms): void {});
    }

    private function connector(): BolleB2bConnector
    {
        return new BolleB2bConnector($this->client());
    }

    /**
     * @return array<string, B2bRemoteProduct>
     */
    private function productsById(BolleB2bConnector $connector): array
    {
        $out = [];
        foreach ($connector->products() as $product) {
            $out[$product->remoteId] = $product;
        }

        return $out;
    }

    /**
     * Pozycja z tests/Fixtures/bolle/item.json z nadpisanymi polami.
     *
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    private function item(int $id, array $overrides = []): array
    {
        $item = json_decode($this->fixture('item.json'), true);

        return ['internalid' => $id, ...$overrides] + $item;
    }

    private function fixture(string $name): string
    {
        return (string) file_get_contents(base_path('tests/Fixtures/bolle/'.$name));
    }

    /**
     * Pozycja tak, jak widzi ją gość: cena „konta” = katalogowa.
     *
     * @param  array<string, mixed>  $item
     * @return array<string, mixed>
     */
    private static function asGuest(array $item): array
    {
        $item['onlinecustomerprice'] = $item['pricelevel1'];
        $item['onlinecustomerprice_detail'] = ['onlinecustomerprice' => $item['pricelevel1']];

        return $item;
    }

    private function fakeSite(): void
    {
        Http::fake(function (Request $request) {
            $url = $request->url();
            $path = (string) parse_url($url, PHP_URL_PATH);
            parse_str((string) parse_url($url, PHP_URL_QUERY), $query);
            preg_match('/JSESSIONID=sess(\d+)/', $request->header('Cookie')[0] ?? '', $m);
            $session = isset($m[1]) ? 'sess'.$m[1] : null;
            $loggedIn = $session !== null && in_array($session, $this->validSessions, true);
            $accountPrices = $loggedIn && ($this->pricedSessions === null || (int) $m[1] <= $this->pricedSessions);

            if ($path === '/safety/services/Account.Login.Service.ss') {
                if ($request->method() !== 'POST') {
                    return Http::response(['errorCode' => 'ERR_METHOD_NOT_ALLOWED'], 405);
                }
                // jak sklep 15.09.2026: bez nagłówków przeglądarki odmowa w HTTP 200, zanim sprawdzi hasło
                if (($request->header('X-SC-Touchpoint')[0] ?? null) !== 'checkout' || ($request->header('X-Requested-With')[0] ?? null) !== 'XMLHttpRequest') {
                    return Http::response(['errorStatusCode' => '400', 'errorCode' => 'ERR_INVALID_ORIGIN', 'errorMessage' => 'Invalid request origin'], 200);
                }
                if (($request->data()['password'] ?? null) !== 'dobre-haslo' || ($request->data()['email'] ?? null) !== 'jan@example.com') {
                    return Http::response(['errorStatusCode' => '401', 'errorCode' => 'ERR_WS_INVALID_LOGIN', 'errorMessage' => 'Invalid login'], 401);
                }
                $this->logins++;
                $this->validSessions[] = 'sess'.$this->logins;

                return Http::response(['touchpoints' => []], 200, [
                    'Set-Cookie' => 'JSESSIONID=sess'.$this->logins.'; path=/; HttpOnly; Secure',
                ]);
            }
            if ($path === '/safety/services/Profile.Service.ss') {
                if (! $loggedIn || $this->profileAlwaysGuest) {
                    return Http::response($this->fixture('profile_guest.json'), 401, ['Content-Type' => 'application/json']);
                }
                $profile = json_decode($this->fixture('profile.json'), true);
                if ($this->profileWithoutCurrency) {
                    unset($profile['currency']);
                }

                return Http::response($profile);
            }
            if ($path === '/safety/public/shopping.environment.shortcache.ssp') {
                return Http::response($this->environment ?? $this->fixture('environment.js'), 200, ['Content-Type' => 'application/javascript']);
            }
            if ($path === '/api/items') {
                if (isset($query['id'])) {
                    $found = [];
                    foreach ($this->leaves as $items) {
                        foreach ($items as $item) {
                            if ((string) $item['internalid'] === $query['id']) {
                                $found = [$accountPrices ? $item : self::asGuest($item)];
                                break 2;
                            }
                        }
                    }

                    return Http::response(['total' => count($found), 'items' => $found, 'links' => []]);
                }
                foreach (['currency', 'language', 'pricelevel', 'sort'] as $rejected) {
                    if (isset($query[$rejected])) {
                        return Http::response(['errorCode' => 'ERR_BAD_REQUEST'], 400);
                    }
                }
                if ((int) ($query['limit'] ?? 0) > 100) {
                    return Http::response(['errorCode' => 'ERR_BAD_REQUEST'], 400);
                }
                $leafUrl = (string) ($query['commercecategoryurl'] ?? '');
                $items = $this->leaves[$leafUrl] ?? [];
                $page = array_slice($items, (int) ($query['offset'] ?? 0), (int) $query['limit']);
                $response = [
                    'total' => $this->totals[$leafUrl] ?? count($items),
                    'items' => array_map(static fn (array $item): array => $accountPrices ? $item : self::asGuest($item), $page),
                    'links' => [],
                ];
                $this->listings++;
                if ($this->dropSessionsAfterListings !== null && $this->listings === $this->dropSessionsAfterListings) {
                    $this->validSessions = [];
                }

                return Http::response($response, 200, ['Cache-Control' => 'private, max-age=300']);
            }
            if ($path === '/core/media/media.nl') {
                return isset($query['h'])
                    ? Http::response(self::JPEG, 200, ['Content-Type' => 'image/jpeg'])
                    : Http::response('', 403);
            }

            return Http::response('nieznany adres w teście: '.$url, 404);
        });
    }
}
