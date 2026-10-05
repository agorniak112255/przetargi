<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\B2bAccount;
use App\Models\B2bSyncRun;
use App\Models\Product;
use App\Models\ProductIdentifier;
use App\Models\ProductImage;
use App\Models\ProductSourcePrice;
use App\Services\B2b\B2bAccountSyncRunner;
use App\Services\B2b\B2bConnectorRegistry;
use App\Services\B2b\B2bFatalException;
use App\Services\B2b\B2bForeignLanguageSource;
use App\Services\B2b\B2bKeepsExistingNames;
use App\Services\B2b\B2bRemoteIdentifier;
use App\Services\B2b\B2bRemoteNormFact;
use App\Services\B2b\B2bRemoteProduct;
use App\Services\B2b\B2bRemoteShopField;
use App\Services\B2b\BolleB2bClient;
use App\Services\B2b\BolleB2bConnector;
use App\Support\ManufacturerNormFacts;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
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

    private const SHEET_EN = '/core/media/media.nl?id=777&c=5230881&h=baxterEN0123&_xt=.pdf';

    private const SHEET_FR = '/core/media/media.nl?id=776&c=5230881&h=baxterFR0123&_xt=.pdf';

    private const SHEET_DOC = '/core/media/media.nl?id=775&c=5230881&h=baxterDOC012&_xt=.pdf';

    private const SHEET_US = '/core/media/media.nl?id=774&c=5230881&h=baxterUS0123&_xt=.pdf';

    /** @var array<string, list<array<string, mixed>>> fullurl liścia → pozycje */
    private array $leaves = [];

    /** @var array<string, int> fullurl liścia → total podawany przez sklep (domyślnie liczba pozycji) */
    private array $totals = [];

    private ?string $environment = null;

    private bool $profileWithoutCurrency = false;

    /** Konto zwolnione z minimum zamówienia Bolle (custentity_c25_allowlowquantity „T”). */
    private bool $profileAllowsLowQuantity = false;

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

    /** @var array<string, array{0: string, 1: int, 2: string}> id pliku media.nl → [treść, status, Content-Type] */
    private array $media = [];

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

        // kod towaru (itemid) = kod producenta pozycji karty (internalid); internalid identyfikatorem nie jest
        $this->assertSame(
            [[ProductIdentifier::TYPE_MANUFACTURER_CODE, 'PSSTRYOC13B', '101', null, 'itemid']],
            self::identifierRows($products[0]),
        );
        $this->assertSame(
            [[ProductIdentifier::TYPE_MANUFACTURER_CODE, 'PSSRUSH0002', '102', null, 'itemid']],
            self::identifierRows($products[1]),
        );
        // pozycja z wariantami jest pomijana — nie podaje identyfikatorów
        $this->assertNull($products[3]->identifiers);
    }

    public function test_sync_stores_item_codes_once_and_second_run_neither_duplicates_nor_removes_them(): void
    {
        Queue::fake();
        Storage::fake('public');
        $this->fakeSite();
        $account = B2bAccount::query()->create([
            'username' => 'jan@example.com',
            'password' => 'dobre-haslo',
            'sites' => ['https://b2b.bolle-safety.com/'],
            'connector' => 'bolle',
        ]);
        $rows = static fn (): array => ProductIdentifier::query()->orderBy('id')->get()
            ->map(static fn (ProductIdentifier $i): array => [
                (string) $i->product_id, $i->position_key, $i->type, $i->value, $i->source_field, $i->manufacturer,
            ])
            ->all();

        $first = app(B2bAccountSyncRunner::class)->run($account->fresh(), delayMs: 0, withImages: false);

        $this->assertSame(2, $first['created'], implode(' | ', $first['errors']));
        $tryon = (string) Product::query()->where('sku', 'PSSTRYOC13B')->value('id');
        $clear = (string) Product::query()->where('sku', 'PSSCLEAR03')->value('id');
        // tylko zapisane pozycje: bez ceny konta (102) i macierz (104) są pominięte
        $this->assertSame([
            [$tryon, '101', ProductIdentifier::TYPE_MANUFACTURER_CODE, 'PSSTRYOC13B', 'itemid', 'Bolle'],
            [$clear, '103', ProductIdentifier::TYPE_MANUFACTURER_CODE, 'PSSCLEAR03', 'itemid', 'Bolle'],
        ], $rows());
        $stored = $rows();
        $slot = ProductSourcePrice::query()->where('product_id', (int) $tryon)->where('b2b_account_id', $account->id)->sole();
        $this->assertSame(10.0, (float) $slot->order_min_qty);
        $this->assertSame(10.0, (float) $slot->order_step_qty);
        $this->assertNull($slot->order_unit);

        $second = app(B2bAccountSyncRunner::class)->run($account->fresh(), delayMs: 0, withImages: false);

        $this->assertSame(0, $second['created'], implode(' | ', $second['errors']));
        $this->assertSame($stored, $rows());
        $this->assertSame(0, ProductIdentifier::query()->whereNotNull('removed_at')->count());
        foreach ([$first, $second] as $result) {
            $texts = implode("\n", array_column(B2bSyncRun::query()->findOrFail($result['sync_run_id'])->log, 'text'));
            $this->assertStringNotContainsString('identyfikator', $texts);
        }
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

    public function test_order_quantity_is_the_item_minimum_and_its_multiples_like_the_shop_cart(): void
    {
        $this->fakeSite();
        $connector = $this->connector();
        $connector->login();
        $products = $this->productsById($connector);

        // STKS 420 w sklepie: „Quantity - Multiples of 10” — minimum 10, krok 10, bez jednostki
        $order = $connector->price($products['101'])?->order;
        $this->assertSame(10.0, $order?->min);
        $this->assertSame(10.0, $order?->step);
        $this->assertNull($order?->unit);
        $this->assertFalse($order->varies);

        $withMinimum = static fn (mixed $minimum): B2bRemoteProduct => new B2bRemoteProduct('107', 'Z', 'Z', raw: [
            'status' => 'ok', 'onlinecustomerprice' => 10, 'pricelevel1' => 20, 'custitem_b2bminimum' => $minimum,
        ]);
        // skrypt sklepu: puste albo 0 = 1, czyli bez ograniczenia
        foreach ([null, '', 0, 1, '1'] as $free) {
            $order = $connector->price($withMinimum($free))?->order;
            $this->assertSame(1.0, $order?->min, var_export($free, true));
            $this->assertNull($order?->step, var_export($free, true));
        }
        $withoutField = new B2bRemoteProduct('108', 'Z', 'Z', raw: ['status' => 'ok', 'onlinecustomerprice' => 10, 'pricelevel1' => 20]);
        $this->assertSame(1.0, $connector->price($withoutField)?->order?->min);
        $this->assertSame(6.0, $connector->price($withMinimum('6'))?->order?->step);
        // wartość nieznanego kształtu = warunku nie znamy (zapisany zostaje), cena bez zmian
        $odd = $connector->price($withMinimum('10 pcs'));
        $this->assertSame(10.0, $odd?->net);
        $this->assertNull($odd?->order);
    }

    public function test_account_exempt_from_the_minimum_gives_no_order_quantity(): void
    {
        $this->profileAllowsLowQuantity = true;
        $this->fakeSite();
        $connector = $this->connector();
        $connector->login();
        $products = $this->productsById($connector);

        $price = $connector->price($products['101']);
        $this->assertSame(30.15, $price?->net);
        $this->assertNull($price?->order);
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

    public function test_description_is_verbatim_sections_only_and_parameters_live_in_the_shop_card(): void
    {
        $this->fakeSite();
        $connector = $this->connector();
        $connector->login();

        $product = $this->productsById($connector)['101'];
        $description = $connector->description($product);

        $this->assertSame(implode("\n", [
            'Copper safety glasses',
            '',
            'The TRYON BSSI offers a wraparound design.',
            '- EN 166 1FT',
            '- Anti-scratch & anti-fog',
        ]), $description);
        // Cechy z etykietami filtrów sklepu są tylko w tabelce karty wyrobu u dostawcy — opis ich nie powtarza.
        foreach (['Parametry:', 'Materiał oprawki', 'Powłoka soczewki', 'Kolor soczewki', 'Nylon', 'Platinum®'] as $absent) {
            $this->assertStringNotContainsString($absent, $description);
        }
        $this->assertSame([
            ['Parametry', 'Materiał oprawki', 'Nylon'],
            ['Parametry', 'Powłoka soczewki', 'Platinum®'],
            ['Parametry', 'Kolor soczewki', 'Copper'],
        ], array_values(array_filter(
            self::rows($connector->shopFields($product)),
            static fn (array $row): bool => $row[0] === 'Parametry',
        )));

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

    public function test_raw_keeps_only_the_file_list_and_ean_from_the_item(): void
    {
        $this->leaves['/goggles'][] = $this->baxterItem(105, 'BAXCSP', '3660740007768');
        $this->fakeSite();
        $connector = $this->connector();
        $connector->login();
        $products = $this->productsById($connector);

        $raw = $products['105']->raw;
        $this->assertSame('3660740007768', $raw['upccode']);
        $this->assertSame([
            ['name' => 'BAXTER-INDUSTRIAL-FT-EMEA_FR.pdf', 'displayname' => 'Technical Sheet', 'url' => BolleB2bClient::BASE.self::SHEET_FR],
            ['name' => 'BAXTER-INDUSTRIAL-FT-EMEA_EN.pdf', 'displayname' => 'Technical Sheet', 'url' => BolleB2bClient::BASE.self::SHEET_EN],
            ['name' => 'BAXTER-DOC-EMEA_EN.pdf', 'displayname' => 'Declaration of Conformity', 'url' => BolleB2bClient::BASE.self::SHEET_DOC],
            ['name' => 'BAXTER-INDUSTRIAL-FT-NAM_EN-US.pdf', 'displayname' => 'Technical Sheet', 'url' => BolleB2bClient::BASE.self::SHEET_US],
        ], $raw['downloads']);
        // stany, zamówienia i odznaki z tego samego pola nie trafiają do raw
        $this->assertArrayNotHasKey('custitem_c25_web_nextdelivery', $raw);
        $encoded = (string) json_encode($raw);
        foreach (['POFS', 'allocated', 'badges'] as $absent) {
            $this->assertStringNotContainsString($absent, $encoded);
        }
        // pole w starym kształcie (sama data dostawy) = brak plików
        $this->assertArrayNotHasKey('downloads', $products['101']->raw);
    }

    public function test_norm_facts_come_from_the_english_technical_sheet_and_the_parsed_table_is_cached_per_file(): void
    {
        $this->leaves['/goggles'][] = $this->baxterItem(105, 'BAXCSP', '3660740007768');
        $this->media['777'] = [$this->fixture('datasheet-baxter.pdf'), 200, 'application/pdf'];
        $this->fakeSite();
        $connector = $this->connector();
        $connector->login();
        $product = $this->productsById($connector)['105'];

        $facts = $connector->normFacts($product);

        $this->assertSame([
            ['EN166', null],
            ['EN172', null],
            ['Oznaczenie soczewki', '5-1.4 1 BT K N'],
        ], array_map(static fn (B2bRemoteNormFact $f): array => [$f->label, $f->value], $facts));
        // tylko karta EN (EMEA): nie wersja FR, nie deklaracja zgodności, nie karta rynku USA
        $media = Http::recorded(static fn (Request $r): bool => str_contains($r->url(), '/core/media/media.nl'));
        $this->assertCount(1, $media);
        $this->assertSame(BolleB2bClient::BASE.self::SHEET_EN, $media->first()[0]->url());

        $provenance = $connector->normFactProvenance($product);
        $this->assertSame('datasheet', $provenance['kind']);
        $this->assertSame(BolleB2bClient::BASE.self::SHEET_EN, $provenance['document_url']);
        $this->assertSame('BAXTER-INDUSTRIAL-FT-EMEA_EN.pdf', $provenance['document_name']);
        $this->assertSame(2, $provenance['page']);
        $this->assertSame(['by' => 'reference', 'value' => 'BAXCSP', 'ean' => '3660740007768'], $provenance['identity']);
        $this->assertStringContainsString('BAXCSP', $provenance['block']);
        $this->assertStringContainsString('EN166 - EN172', $provenance['block']);
        $this->assertSame(hash('sha256', $provenance['block']), $provenance['block_sha256']);
        $this->assertSame([], $connector->normFactProvenance(new B2bRemoteProduct('999', 'X', 'X')));

        // Kolumna producenta: normy na liście, oznaczenie soczewki tylko jako para (to nie kod EN 388)
        $column = ManufacturerNormFacts::build(
            array_map(static fn (B2bRemoteNormFact $f): array => ['label' => $f->label, 'value' => $f->value], $facts),
            'bolle',
            'Bolle',
            'https://b2b.bolle-safety.com/x',
        );
        $this->assertSame(['EN166', 'EN172'], $column['normy_en'] ?? null);
        $this->assertArrayNotHasKey('en388', $column ?? []);

        // Inna pozycja tej rodziny w nowym przebiegu (nowy łącznik): odczyt z pamięci podręcznej, bez pobrania
        $sent = Http::recorded()->count();
        $sibling = $this->connector()->normFacts($this->remoteWithSheets('BAXPSI', '3660740007744'));
        $this->assertSame(['EN166', 'EN170', 'Oznaczenie soczewki'], array_map(static fn (B2bRemoteNormFact $f): string => $f->label, $sibling));
        Http::assertSentCount($sent);
    }

    public function test_norm_facts_are_empty_without_english_sheet_own_row_or_matching_ean(): void
    {
        $this->media['777'] = [$this->fixture('datasheet-baxter.pdf'), 200, 'application/pdf'];
        $this->media['778'] = [$this->fixture('datasheet-tryon-rx.pdf'), 200, 'application/pdf'];
        $this->fakeSite();
        $connector = $this->connector();

        // pozycja bez plików (SPICMX11U-F), z samą kartą FR i z plikiem, który nie jest kartą techniczną — bez pobrania
        $this->assertSame([], $connector->normFacts(new B2bRemoteProduct('1', 'SPICMX11U-F', 'X', raw: ['status' => 'ok', 'itemid' => 'SPICMX11U-F'])));
        $this->assertSame([], $connector->normFacts(new B2bRemoteProduct('2', 'BAXCSP', 'X', raw: [
            'status' => 'ok', 'itemid' => 'BAXCSP', 'upccode' => '3660740007768', 'downloads' => [
                ['name' => 'BAXTER-INDUSTRIAL-FT-EMEA_FR.pdf', 'displayname' => 'Technical Sheet', 'url' => BolleB2bClient::BASE.self::SHEET_EN],
                ['name' => 'BAXTER-DOC-EMEA_EN.pdf', 'displayname' => '', 'url' => BolleB2bClient::BASE.self::SHEET_EN],
            ],
        ])));
        Http::assertNothingSent();

        // bez karty europejskiej: karta rynku USA, a plik bez opisu z nazwą karty technicznej też się liczy
        foreach ([
            ['BAXTER-INDUSTRIAL-FT-NAM_EN-US.pdf', 'Technical Sheet'],
            ['BAXTER-DATASHEET-EN.pdf', ''],
        ] as [$name, $label]) {
            $facts = $connector->normFacts(new B2bRemoteProduct('5', 'BAXCSP', 'X', raw: [
                'status' => 'ok', 'itemid' => 'BAXCSP', 'upccode' => '3660740007768', 'downloads' => [
                    ['name' => 'BAXTER-INDUSTRIAL-FT-EMEA_FR.pdf', 'displayname' => 'Technical Sheet', 'url' => BolleB2bClient::BASE.self::SHEET_FR],
                    ['name' => $name, 'displayname' => $label, 'url' => BolleB2bClient::BASE.self::SHEET_EN],
                ],
            ]));
            $this->assertSame(['EN166', 'EN172'], array_values(array_filter(
                array_map(static fn (B2bRemoteNormFact $f): string => $f->label, $facts),
                static fn (string $label): bool => str_starts_with($label, 'EN'),
            )), $name);
        }

        // TRYON RX: w kolumnie REFERENCE nazwa rodziny, nie kod pozycji
        $tryon = new B2bRemoteProduct('3', 'TRYONN10E', 'X', raw: ['status' => 'ok', 'itemid' => 'TRYONN10E', 'upccode' => '3660740020132', 'downloads' => [
            ['name' => 'TRYON-PRESCRIPTION-RX-FT-EMEA_EN.pdf', 'displayname' => 'Technical Sheet', 'url' => BolleB2bClient::BASE.'/core/media/media.nl?id=778&c=5230881&h=tryon&_xt=.pdf'],
        ]]);
        $this->assertSame([], $connector->normFacts($tryon));
        $this->assertSame([], $connector->normFactProvenance($tryon));

        // kod się zgadza, EAN wiersza nie — to nie ten wyrób
        $this->assertSame([], $connector->normFacts($this->remoteWithSheets('BAXCSP', '3660740007751')));
        // pozycja pominięta (macierz) niczego nie czyta
        $this->assertSame([], $connector->normFacts(new B2bRemoteProduct('4', 'BAXCSP', 'X', raw: ['status' => 'skipped', 'itemid' => 'BAXCSP'])));
    }

    public function test_datasheet_outside_the_shop_host_is_rejected_without_a_request(): void
    {
        $this->fakeSite();
        $connector = $this->connector();

        foreach (['https://obcy.example.com/core/media/media.nl?id=1&_xt=.pdf', '//obcy.example.com/karta_EN.pdf'] as $url) {
            try {
                $connector->normFacts(new B2bRemoteProduct('1', 'BAXCSP', 'X', raw: ['status' => 'ok', 'itemid' => 'BAXCSP', 'downloads' => [
                    ['name' => 'BAXTER-INDUSTRIAL-FT-EMEA_EN.pdf', 'displayname' => 'Technical Sheet', 'url' => $url],
                ]]));
                $this->fail('Obcy host powinien być odrzucony: '.$url);
            } catch (RuntimeException $e) {
                $this->assertStringContainsString('odrzucony', $e->getMessage());
            }
        }
        Http::assertNothingSent();
    }

    public function test_failed_download_is_an_error_not_repeated_in_the_same_run_and_not_cached(): void
    {
        $this->media['777'] = ['blad serwera', 500, 'text/html'];
        $this->fakeSite();
        $connector = $this->connector();

        foreach (['BAXCSP', 'BAXPSI'] as $code) {
            try {
                $connector->normFacts($this->remoteWithSheets($code, null));
                $this->fail('Błąd pobrania karty technicznej powinien być wyjątkiem');
            } catch (RuntimeException $e) {
                $this->assertStringContainsString('karta techniczna', $e->getMessage());
            }
        }
        Http::assertSentCount(1);

        // następny przebieg próbuje znowu — błąd nie trafił do pamięci podręcznej
        $this->media['777'] = [$this->fixture('datasheet-baxter.pdf'), 200, 'application/pdf'];
        $this->assertCount(3, $this->connector()->normFacts($this->remoteWithSheets('BAXCSP', null)));
        Http::assertSentCount(2);
    }

    public function test_gallery_lists_main_and_extra_shop_images_and_ean_becomes_an_identifier(): void
    {
        $connector = $this->connector();
        $product = new B2bRemoteProduct('7', 'BAXCSP', 'X', raw: [
            'status' => 'ok',
            'custitem_atlas_item_image' => '/core/media/media.nl?id=900&c=5230881&h=main',
            'custitem_bb_item_image_2' => '/core/media/media.nl?id=901&c=5230881&h=second',
            'custitem_bb_item_image_3' => '',
            'custitem_bb_item_image_4' => '/core/media/media.nl?id=900&c=5230881&h=main',
        ]);

        $this->assertSame([
            BolleB2bClient::BASE.'/core/media/media.nl?id=900&c=5230881&h=main',
            BolleB2bClient::BASE.'/core/media/media.nl?id=901&c=5230881&h=second',
        ], $connector->imageUrls($product));
        $this->assertSame([], $connector->imageUrls(new B2bRemoteProduct('8', 'X', 'X', raw: ['status' => 'ok'])));

        $this->leaves['/goggles'][] = $this->baxterItem(105, 'BAXCSP', '3660740007768');
        $this->leaves['/goggles'][] = $this->baxterItem(106, 'BAXPSI', '3660740007745');
        $this->fakeSite();
        $connector->login();
        $products = $this->productsById($connector);
        $identifiers = static fn (B2bRemoteProduct $p): array => array_map(
            static fn (B2bRemoteIdentifier $i): array => [$i->type, $i->value, $i->field],
            $p->identifiers ?? [],
        );
        $this->assertSame([
            [ProductIdentifier::TYPE_MANUFACTURER_CODE, 'BAXCSP', 'itemid'],
            [ProductIdentifier::TYPE_EAN, '3660740007768', 'upccode'],
        ], $identifiers($products['105']));
        // zła suma kontrolna — to nie jest EAN, zostaje sam kod
        $this->assertSame([[ProductIdentifier::TYPE_MANUFACTURER_CODE, 'BAXPSI', 'itemid']], $identifiers($products['106']));
    }

    public function test_sync_puts_bolle_images_before_images_found_on_other_shops(): void
    {
        Queue::fake();
        Storage::fake('public');
        $this->leaves = ['/goggles' => [$this->item(105, [
            'itemid' => 'BAXCSP',
            'urlcomponent' => 'BAXTER_BAXCSP',
            'custitem_atlas_item_image' => '/core/media/media.nl?id=900&c=5230881&h=main',
            'custitem_bb_item_image_2' => '/core/media/media.nl?id=901&c=5230881&h=second',
        ])]];
        $this->media['900'] = ["\xFF\xD8\xFF\xE0zdjecie-glowne\xFF\xD9", 200, 'image/jpeg'];
        $this->media['901'] = ["\xFF\xD8\xFF\xE0zdjecie-drugie\xFF\xD9", 200, 'image/jpeg'];
        $this->fakeSite();
        $account = B2bAccount::query()->create([
            'username' => 'jan@example.com',
            'password' => 'dobre-haslo',
            'sites' => ['https://b2b.bolle-safety.com/'],
            'connector' => 'bolle',
        ]);

        $first = app(B2bAccountSyncRunner::class)->run($account->fresh(), delayMs: 0, withImages: false);
        $this->assertSame(1, $first['created'], implode(' | ', $first['errors']));
        $card = Product::query()->where('sku', 'BAXCSP')->firstOrFail();
        // zdjęcie wyłowione wcześniej z obcego sklepu (import pliku z 12.09 i wzbogacanie)
        ProductImage::query()->create([
            'product_id' => $card->id, 'path' => 'products/obce.jpg', 'source_url' => 'https://www.specshop.pl/baxter.jpg',
            'is_primary' => true, 'sort_order' => 0, 'checksum' => 'obce',
        ]);

        $this->travel(2)->days();
        $second = app(B2bAccountSyncRunner::class)->run($account->fresh(), delayMs: 0, withImages: true);

        $this->assertSame([], $second['errors']);
        $images = ProductImage::query()->where('product_id', $card->id)->orderBy('sort_order')->get();
        $this->assertSame([
            [BolleB2bClient::BASE.'/core/media/media.nl?id=900&c=5230881&h=main', $account->id, true],
            [BolleB2bClient::BASE.'/core/media/media.nl?id=901&c=5230881&h=second', $account->id, false],
            ['https://www.specshop.pl/baxter.jpg', null, false],
        ], $images->map(static fn (ProductImage $i): array => [$i->source_url, $i->b2b_account_id, (bool) $i->is_primary])->all());
    }

    public function test_sync_saves_datasheet_norms_with_provenance_and_identical_second_run_does_not_rewrite_them(): void
    {
        Queue::fake();
        Storage::fake('public');
        $this->leaves['/goggles'][] = $this->baxterItem(105, 'BAXCSP', '3660740007768');
        $this->media['777'] = [$this->fixture('datasheet-baxter.pdf'), 200, 'application/pdf'];
        $this->fakeSite();
        $account = B2bAccount::query()->create([
            'username' => 'jan@example.com',
            'password' => 'dobre-haslo',
            'sites' => ['https://b2b.bolle-safety.com/'],
            'connector' => 'bolle',
        ]);

        $first = app(B2bAccountSyncRunner::class)->run($account->fresh(), delayMs: 0, withImages: false);

        $this->assertSame(3, $first['created'], implode(' | ', $first['errors']));
        $column = Product::query()->where('sku', 'BAXCSP')->firstOrFail()->manufacturer_norms;
        $this->assertSame([['label' => 'EN166'], ['label' => 'EN172'], ['label' => 'Oznaczenie soczewki', 'value' => '5-1.4 1 BT K N']], $column['rows']);
        $this->assertSame(['EN166', 'EN172'], $column['normy_en']);
        $this->assertSame('bolle', $column['source']['connector']);
        $this->assertSame('Bolle', $column['source']['brand']);
        $this->assertSame('datasheet', $column['source']['kind']);
        $this->assertSame(BolleB2bClient::BASE.self::SHEET_EN, $column['source']['document_url']);
        $this->assertSame('BAXTER-INDUSTRIAL-FT-EMEA_EN.pdf', $column['source']['document_name']);
        $this->assertSame(2, $column['source']['page']);
        $this->assertSame(['by' => 'reference', 'value' => 'BAXCSP', 'ean' => '3660740007768'], $column['source']['identity']);
        $this->assertSame(hash('sha256', $column['source']['block']), $column['source']['block_sha256']);
        // pozycje bez karty technicznej nie dostają norm producenta
        $this->assertNull(Product::query()->where('sku', 'PSSTRYOC13B')->firstOrFail()->manufacturer_norms);

        $this->travel(2)->days();
        $second = app(B2bAccountSyncRunner::class)->run($account->fresh(), delayMs: 0, withImages: false);

        $this->assertSame(0, $second['created'], implode(' | ', $second['errors']));
        foreach ([$first, $second] as $result) {
            $texts = implode("\n", array_column(B2bSyncRun::query()->findOrFail($result['sync_run_id'])->log, 'text'));
            $this->assertStringNotContainsString('normy z karty producenta nie zostały odczytane', $texts);
        }
        $again = Product::query()->where('sku', 'BAXCSP')->firstOrFail()->manufacturer_norms;
        // te same fakty i to samo źródło — bez ponownego zapisu (data odczytu z pierwszego przebiegu)
        $this->assertSame($column['source']['synced_at'], $again['source']['synced_at']);
        $this->assertEquals($column, $again);
        // karta techniczna rodziny pobrana raz na oba przebiegi
        $this->assertCount(1, Http::recorded(static fn (Request $r): bool => str_contains($r->url(), 'id=777')));
    }

    /**
     * Pozycja rodziny BAXTER z plikami w polu custitem_c25_web_nextdelivery (kształt żywego API z 28.09.2026: JSON
     * z item.downloads obok stanów i zamówień). Adresy i identyfikatory plików są syntetyczne.
     *
     * @return array<string, mixed>
     */
    private function baxterItem(int $id, string $itemId, string $upc): array
    {
        $downloads = [];
        foreach ([
            [self::SHEET_FR, 'BAXTER-INDUSTRIAL-FT-EMEA_FR.pdf', 'Technical Sheet'],
            [self::SHEET_EN, 'BAXTER-INDUSTRIAL-FT-EMEA_EN.pdf', 'Technical Sheet'],
            [self::SHEET_DOC, 'BAXTER-DOC-EMEA_EN.pdf', 'Declaration of Conformity'],
            [self::SHEET_US, 'BAXTER-INDUSTRIAL-FT-NAM_EN-US.pdf', 'Technical Sheet'],
        ] as $i => [$url, $name, $label]) {
            $downloads[(string) (413 + $i)] = ['file' => (string) (12131900 + $i), 'url' => $url, 'name' => $name, 'displayname' => $label];
        }

        return $this->item($id, [
            'itemid' => $itemId,
            'upccode' => $upc,
            'urlcomponent' => 'BAXTER_'.$itemId,
            'custitem_c25_web_nextdelivery' => (string) json_encode([
                'v' => 5,
                'item' => ['downloads' => $downloads, 'badges' => ['NEW'], 'allocated' => ['qty' => 12]],
                'POFS0079223' => ['qty' => 300, 'date' => '2026-10-01'],
            ]),
        ]);
    }

    private function remoteWithSheets(string $itemId, ?string $upc): B2bRemoteProduct
    {
        return new B2bRemoteProduct('50'.$itemId, $itemId, $itemId, raw: array_filter([
            'status' => 'ok',
            'itemid' => $itemId,
            'upccode' => $upc,
            'downloads' => [
                ['name' => 'BAXTER-INDUSTRIAL-FT-EMEA_EN.pdf', 'displayname' => 'Technical Sheet', 'url' => BolleB2bClient::BASE.self::SHEET_EN],
            ],
        ], static fn (mixed $value): bool => $value !== null));
    }

    /**
     * @return list<array{0: string, 1: string, 2: string|null, 3: string|null, 4: string|null}>
     */
    private static function identifierRows(B2bRemoteProduct $product): array
    {
        return array_map(
            static fn (B2bRemoteIdentifier $i): array => [$i->type, $i->value, $i->remoteId, $i->label, $i->field],
            $product->identifiers ?? [],
        );
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
                if ($this->profileAllowsLowQuantity) {
                    foreach ($profile['customfields'] as $i => $field) {
                        if ($field['name'] === 'custentity_c25_allowlowquantity') {
                            $profile['customfields'][$i]['value'] = 'T';
                        }
                    }
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
                if (isset($this->media[(string) ($query['id'] ?? '')])) {
                    [$body, $status, $type] = $this->media[(string) $query['id']];

                    return Http::response($body, $status, ['Content-Type' => $type]);
                }

                return isset($query['h'])
                    ? Http::response(self::JPEG, 200, ['Content-Type' => 'image/jpeg'])
                    : Http::response('', 403);
            }

            return Http::response('nieznany adres w teście: '.$url, 404);
        });
    }
}
