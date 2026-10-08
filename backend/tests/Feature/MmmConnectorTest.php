<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\B2bAccount;
use App\Models\B2bSyncRun;
use App\Models\Product;
use App\Models\ProductDocument;
use App\Models\ProductIdentifier;
use App\Models\ProductImage;
use App\Models\ProductImageRejection;
use App\Models\ProductShopCard;
use App\Models\ProductSourcePrice;
use App\Services\B2b\B2bAccountSyncRunner;
use App\Services\B2b\B2bCodeLoginSite;
use App\Services\B2b\B2bDocumentSource;
use App\Services\B2b\B2bFatalException;
use App\Services\B2b\B2bGroupsSizes;
use App\Services\B2b\B2bImageGallery;
use App\Services\B2b\B2bListProgressAware;
use App\Services\B2b\B2bManufacturerSite;
use App\Services\B2b\B2bRemoteIdentifier;
use App\Services\B2b\B2bRemoteProduct;
use App\Services\B2b\B2bRunSummaryAware;
use App\Services\B2b\B2bShopFieldSource;
use App\Services\B2b\B2bSizePriceSource;
use App\Services\B2b\MmmB2bClient;
use App\Services\B2b\MmmB2bConnector;
use GuzzleHttp\Exception\ConnectException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use RuntimeException;
use Tests\Support\OversizedFileDownload;
use Tests\TestCase;
use Throwable;

/**
 * Łącznik order.3m.com na atrapie sklepu i B2C (Http::fake). Układ odpowiedzi odwzorowuje witrynę z 22.09.2026:
 * strona logowania sklepu z formularzem /mmmsinglesignon/saml2/authenticate/login, formularz SAMLRequest do
 * ciam.iamext.3m.com (polityka B2C_1A_3MSAMLB), strony B2C z „var SETTINGS = {...};”, odpowiedzi SelfAsserted
 * {"status":"200"}, ekran weryfikacji e-mail (readOnlyEmail), formularz SAMLResponse do /mmmsinglesignon/saml/SSO,
 * strona katalogu z currentUserJWT, wyszukiwarka searchapi.3m.com (search, pdp) i ceny /bcomv2/…/productPrice.
 *
 * Wyroby, numery, EAN i ceny są SYNTETYCZNE (wzorowane na zapisie sklepu).
 */
final class MmmConnectorTest extends TestCase
{
    use RefreshDatabase;

    private const EMAIL = 'handel@example.com';

    private const PASSWORD = 'dobre-haslo-3m';

    private const CODE = '482913';

    private const USER_ID = 'tpodolak45803';

    private const TENANT = '/mmmciam.onmicrosoft.com/B2C_1A_3MSAMLB';

    private const TX = 'StateProperties=eyJUSUQiOiJ0ZXN0In0';

    private const SAML_REQUEST = 'PHNhbWxwOkF1dGhuUmVxdWVzdD4=';

    private const SAML_RESPONSE = 'PHNhbWxwOlJlc3BvbnNlPg==';

    private const MEDIA = 'https://multimedia.3m.com/mws/media/';

    /** Początek strony, którą multimedia.3m.com oddaje pod nagłówkiem image/jpeg (zapisana jako .jpg na 46491). */
    private const RESOURCE_404 = "<!DOCTYPE HTML PUBLIC \"-//IETF//DTD HTML 2.0//EN\">\n<html><head><title>404 Not Found</title></head><body><h1>Resource not found</h1></body></html>";

    /** @var list<string> ważne sesje sklepu (JSESSIONID) */
    private array $storeSessions = ['live'];

    /** @var list<string> ważne sesje SSO B2C (x-ms-cpim-sso) */
    private array $ssoSessions = ['alive'];

    private int $storeLogins = 0;

    /** Etap logowania B2C: email → password → verify → done. */
    private string $stage = 'email';

    private bool $mfaRequired = true;

    private bool $codeSent = false;

    /** Ceny zawsze 401 (sesja sklepu utracona w trakcie przebiegu). */
    private bool $pricesUnauthorized = false;

    /** @var list<string> numery, z którymi każda paczka cen kończy się błędem */
    private array $brokenPriceIds = [];

    /** @var list<array<string, mixed>> pozycje listy wyszukiwarki */
    private array $items = [];

    private ?int $listTotal = null;

    /** @var (\Closure(string, int, int, list<array<string, mixed>>): list<array<string, mixed>>)|null  (grupa, start, numer przejścia grupy od 1, pozycje grupy) → pozycje strony */
    private ?\Closure $listPage = null;

    /** @var array<string, int> grupa („ścieżka|marka”) → liczba przejść */
    private array $listPasses = [];

    /** @var array<string, array<string, int>> ścieżka → podkategorie z licznikiem nadpisanym (niespójne drzewo) */
    private array $facetOverrides = [];

    /** @var array<string, array<string, mixed>> numer magazynowy → „value” odpowiedzi ceny */
    private array $prices = [];

    /** @var array<string, array<string, mixed>> numer magazynowy → karta pdp */
    private array $pdps = [];

    private ?string $jwt = null;

    protected function setUp(): void
    {
        parent::setUp();
        Queue::fake();
    }

    public function test_login_with_a_live_session_from_the_account_posts_nothing_and_saves_the_session(): void
    {
        $this->fakeSite();
        $account = $this->account(['live' => true]);

        $connector = MmmB2bConnector::forAccount($account, 0);
        $connector->login();

        $this->assertCount(0, Http::recorded(fn (Request $r): bool => $r->method() === 'POST'));
        $this->assertNotNull($account->fresh()?->connector_session_saved_at);
        $this->assertSame('live', self::cookieValue((array) $account->fresh()?->connector_session, 'JSESSIONID'));
    }

    public function test_expired_store_session_renews_through_silent_sso_without_sending_email_or_password(): void
    {
        $this->fakeSite();
        $account = $this->account(['live' => false, 'sso' => true]);

        MmmB2bConnector::forAccount($account, 0)->login();

        $this->assertCount(0, $this->selfAssertedPosts());
        $this->assertSame(1, $this->storeLogins);
        $saml = Http::recorded(fn (Request $r): bool => str_ends_with(parse_url($r->url(), PHP_URL_PATH) ?: '', '/mmmsinglesignon/saml/SSO'))->first()[0];
        $this->assertSame(self::SAML_RESPONSE, $saml->data()['SAMLResponse']);
        $this->assertSame('live1', self::cookieValue((array) $account->fresh()?->connector_session, 'JSESSIONID'));
    }

    public function test_without_a_session_login_is_fatal_and_sends_no_request_at_all(): void
    {
        $this->fakeSite();
        $account = $this->account([]);

        try {
            MmmB2bConnector::forAccount($account, 0)->login();
            $this->fail('logowanie bez sesji powinno prosić o kod');
        } catch (B2bFatalException $e) {
            $this->assertStringContainsString('Zaloguj kodem', $e->getMessage());
        }
        $this->assertCount(0, Http::recorded());
    }

    public function test_session_that_b2c_does_not_renew_is_fatal_without_posting_credentials(): void
    {
        $this->fakeSite();
        $account = $this->account(['live' => false, 'sso' => false]);

        try {
            MmmB2bConnector::forAccount($account, 0)->login();
            $this->fail('B2C pytający o e-mail = prośba o kod');
        } catch (B2bFatalException $e) {
            $this->assertStringContainsString('Zaloguj kodem', $e->getMessage());
        }
        $this->assertCount(0, $this->selfAssertedPosts());
        $this->assertFalse($this->codeSent);
    }

    public function test_undecryptable_session_on_the_account_counts_as_no_session(): void
    {
        $this->fakeSite();
        $account = $this->account([]);
        DB::table('b2b_accounts')->where('id', $account->id)->update(['connector_session' => 'nie-zaszyfrowane']);

        $this->expectException(B2bFatalException::class);
        MmmB2bConnector::forAccount($account->fresh() ?? $account, 0)->login();
    }

    public function test_start_code_login_sends_email_password_and_verification_request_and_keeps_no_password(): void
    {
        $this->fakeSite();

        $result = $this->client()->startCodeLogin();

        $posts = $this->selfAssertedPosts();
        $this->assertCount(3, $posts);
        $this->assertSame(['email' => self::EMAIL, 'request_type' => 'RESPONSE'], $posts[0]->data());
        $this->assertSame(['password' => self::PASSWORD, 'request_type' => 'RESPONSE'], $posts[1]->data());
        $this->assertSame(
            ['request_type' => 'VERIFICATION_REQUEST', 'claim_id' => 'readOnlyEmail', 'claim_value' => self::EMAIL],
            $posts[2]->data(),
        );
        $this->assertSame('CSRF-2', $posts[1]->header('X-CSRF-TOKEN')[0]);
        $this->assertSame('XMLHttpRequest', $posts[0]->header('X-Requested-With')[0]);
        $this->assertTrue($this->codeSent);

        $state = $result['state'];
        $this->assertStringNotContainsString(self::PASSWORD, (string) json_encode($state));
        $this->assertSame(self::EMAIL, $state['claim_value']);
        $this->assertSame('CSRF-3', $state['csrf']);
        $this->assertSame(self::TX, $state['tx']);
        $this->assertSame(self::TENANT, $state['tenant']);
        $this->assertSame('B2C_1A_3MSAMLB', $state['policy']);
        $this->assertSame('SelfAsserted', $state['api']);
        $this->assertNotEmpty($state['cookies']);
        $this->assertStringContainsString(self::EMAIL, $result['message']);
        // każde zapytanie z nagłówkiem przeglądarki (Akamai)
        foreach (Http::recorded() as [$request]) {
            $this->assertStringContainsString('Mozilla/5.0', $request->header('User-Agent')[0] ?? '');
        }
    }

    public function test_wrong_password_is_a_plain_runtime_exception_with_the_b2c_message(): void
    {
        $this->fakeSite();
        $client = new MmmB2bClient(self::EMAIL, 'zle-haslo', [], 0, static function (int $ms): void {});

        try {
            $client->startCodeLogin();
            $this->fail('złe hasło');
        } catch (B2bFatalException $e) {
            $this->fail('złe hasło to nie błąd krytyczny: '.$e->getMessage());
        } catch (RuntimeException $e) {
            $this->assertStringContainsString('Nieprawidłowe hasło', $e->getMessage());
        }
        $this->assertFalse($this->codeSent);
    }

    public function test_finish_code_login_with_the_right_code_returns_a_session_the_next_run_uses(): void
    {
        $this->fakeSite();
        $state = json_decode((string) json_encode($this->client()->startCodeLogin()['state']), true);

        $session = $this->client()->finishCodeLogin($state, ' '.self::CODE.' ');

        $validation = $this->selfAssertedPosts()[3]->data();
        $this->assertSame('VALIDATION_REQUEST', $validation['request_type']);
        $this->assertSame(self::CODE, $validation['user_input']);
        $this->assertSame(
            ['readOnlyEmail' => self::EMAIL, 'readOnlyEmail_ver_input' => self::CODE, 'request_type' => 'RESPONSE'],
            $this->selfAssertedPosts()[4]->data(),
        );
        $this->assertSame('live1', self::cookieValue($session, 'JSESSIONID'));
        // ciasteczko SSO B2C ma dwukropek w nazwie — jar musi je zachować (inaczej ciche SSO nigdy nie zadziała)
        $this->assertSame('alive', self::cookieValue($session, 'x-ms-cpim-sso:mmmciam.onmicrosoft.com_0'));

        // przebieg z zapisaną sesją — bez żadnego POST
        $account = $this->account([]);
        $account->forceFill(['connector_session' => json_decode((string) json_encode($session), true)])->save();
        $posts = count(Http::recorded(fn (Request $r): bool => $r->method() === 'POST'));
        MmmB2bConnector::forAccount($account->fresh() ?? $account, 0)->login();
        $this->assertCount($posts, Http::recorded(fn (Request $r): bool => $r->method() === 'POST'));
    }

    public function test_finish_code_login_with_a_wrong_code_fails_and_logs_nobody_in(): void
    {
        $this->fakeSite();
        $state = $this->client()->startCodeLogin()['state'];
        $client = $this->client();

        try {
            $client->finishCodeLogin($state, '111111');
            $this->fail('zły kod');
        } catch (B2bFatalException $e) {
            $this->fail('zły kod to nie błąd krytyczny');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString('Kod nieprawidłowy albo wygasł', $e->getMessage());
            $this->assertStringContainsString('Kod weryfikacyjny jest nieprawidłowy', $e->getMessage());
        }
        $this->assertFalse($client->isLoggedIn());
        $this->assertSame(0, $this->storeLogins);

        $this->expectException(RuntimeException::class);
        $client->finishCodeLogin($state, '48a913');
    }

    public function test_price_parser_reads_polish_amounts_with_thousand_spaces_and_units_only(): void
    {
        $this->assertSame(['amount' => 3141.12, 'unit' => 'szt'], MmmB2bConnector::parsePrice('3 141,12 PLN / szt'));
        $this->assertSame(['amount' => 3141.12, 'unit' => 'karton'], MmmB2bConnector::parsePrice("3\u{00A0}141,12\u{00A0}PLN / karton"));
        $this->assertSame(['amount' => 0.4196, 'unit' => null], MmmB2bConnector::parsePrice('0,4196PLN'));
        $this->assertSame(['amount' => 714.205, 'unit' => null], MmmB2bConnector::parsePrice('714,205PLN'));
        $this->assertSame(['amount' => 1570.56, 'unit' => null], MmmB2bConnector::parsePrice('1 570,56 PLN'));
        $this->assertNull(MmmB2bConnector::parsePrice('132.80 EUR'));
        $this->assertNull(MmmB2bConnector::parsePrice(''));
        $this->assertNull(MmmB2bConnector::parsePrice('12 34,00 PLN'));
    }

    public function test_prices_are_asked_per_base_unit_and_a_different_price_unit_is_no_price_with_a_reason(): void
    {
        $this->fakeSite();
        $this->catalog();
        $connector = $this->connector();

        $products = self::byId(iterator_to_array($connector->products(), false));

        $request = Http::recorded(fn (Request $r): bool => str_contains($r->url(), 'productPrice'))->first()[0];
        parse_str((string) parse_url($request->url(), PHP_URL_QUERY), $query);
        $this->assertSame('7000009701,7100100637,7000034747,7100066103', $query['materialIDs']);
        $this->assertSame('EA,PR,EA,EA', $query['materialUnits']);
        $this->assertStringContainsString('/users/'.self::USER_ID.'/', $request->url());

        $mask = $connector->price($products['7000009701']);
        $this->assertSame(24.54, $mask?->net);
        $this->assertSame(49.08, $mask?->base);
        $this->assertSame(50.0, $mask?->discountPercent);
        $plugs = $connector->price($products['7100100637']);
        $this->assertSame(0.42, $plugs?->net);
        $this->assertSame(0.84, $plugs?->base);
        $this->assertNull($connector->price($products['7000034747']));
        $this->assertNull($connector->price($products['7100066103']));

        // numery 3M z pozycji listy dosłownie (GTIN-14 z zerem), pozycja = numer magazynowy; kody kreskowe opakowań
        // są dopiero na karcie pdp, więc nie ma ich tutaj (i karta pdp nie jest pobierana przy liście)
        $this->assertSame([
            [ProductIdentifier::TYPE_MANUFACTURER_CODE, '6200', '7000009701', null, 'mmm_catalog_number'],
            [ProductIdentifier::TYPE_ALT_CODE, '7000009701', '7000009701', null, 'mmm_id'],
            [ProductIdentifier::TYPE_LEGACY_CODE, '70-0710-2845-5', '7000009701', null, 'legacy_mmm_id'],
            [ProductIdentifier::TYPE_EAN, '04046719303420', '7000009701', null, 'gtin_display'],
        ], self::identifierRows($products['7000009701']));
        // bez poprzedniego numeru — nic nie jest wymyślane
        $this->assertSame([
            [ProductIdentifier::TYPE_MANUFACTURER_CODE, '1100', '7100100637', null, 'mmm_catalog_number'],
            [ProductIdentifier::TYPE_ALT_CODE, '7100100637', '7100100637', null, 'mmm_id'],
            [ProductIdentifier::TYPE_EAN, '07100100637', '7100100637', null, 'gtin_display'],
        ], self::identifierRows($products['7100100637']));
        $this->assertCount(0, Http::recorded(fn (Request $r): bool => str_contains($r->url(), '/pdp')));

        $summary = implode("\n", $connector->runSummary());
        $this->assertStringContainsString('cena za inną jednostkę niż bazowa (szt): 1, np. 7000034747 („1 karton”)', $summary);
        $this->assertStringContainsString('sklep nie podaje ceny: 1, np. 7100066103', $summary);
        // pozycja powtórzona na liście liczy się raz
        $this->assertStringContainsString('Lista 3M (ŚOI, aktywne): 4 wyrobów z 1 grup kategorii', $summary);
    }

    public function test_order_condition_comes_from_the_base_unit_row_of_the_list(): void
    {
        $this->fakeSite();
        $this->catalog();
        // jak na żywo (7100265270): sprzedaż w kartonach po 24, w jednostce bazowej moq 24 i moi 24; karton moq 1
        foreach ([0, 3] as $i) {
            $this->items[$i]['orderUnits'][1]['moq'] = '24.0';
            $this->items[$i]['orderUnits'][1]['moi'] = '24.0';
        }
        $connector = $this->connector();

        $products = self::byId(iterator_to_array($connector->products(), false));

        $this->assertSame(
            ['order_min_qty' => 24.0, 'order_step_qty' => 24.0, 'order_unit' => 'szt', 'order_varies' => false],
            $connector->price($products['7000009701'])?->order?->slotValues(),
        );
        // moq 1 / moi 1 w jednostce bazowej — bez ograniczenia
        $plugs = $connector->price($products['7100100637'])?->order;
        $this->assertSame(['order_min_qty' => 1.0, 'order_step_qty' => 1.0, 'order_unit' => 'para', 'order_varies' => false], $plugs?->slotValues());
        $this->assertFalse($plugs->restricts());
    }

    public function test_list_goes_by_category_groups_splits_a_large_leaf_by_brand_and_rereads_an_unstable_group(): void
    {
        $this->fakeSite();
        // 60 w podkategorii A (jedna strona), 150 w liściu B bez podkategorii: 70 PELTOR + 80 Scott
        for ($i = 0; $i < 210; $i++) {
            $item = self::item((string) (7000000000 + $i), 'K'.$i, 'Wyrób testowy '.$i, 'EA', 'szt', 'CS', 'karton', '10');
            $item['_path'] = $i < 60 ? ['GPH10008', 'GPHA'] : ['GPH10008', 'GPHB'];
            $item['_brand'] = $i < 60 ? '3M' : ($i < 130 ? 'PELTOR' : 'Scott');
            $this->items[] = $item;
        }
        // jak na żywo: pierwsze przejście grupy Scott gubi pozycję i powtarza inną — drugie przejście ją dobiera
        $this->listPage = static fn (string $group, int $start, int $pass, array $inGroup): array => $group === 'GPH10008/GPHB|Scott' && $pass === 1
            ? [...array_slice($inGroup, 0, 79), $inGroup[0]]
            : array_slice($inGroup, $start, 100);
        $messages = [];
        $connector = $this->connector();
        $connector->onListProgress(static function (string $m) use (&$messages): void {
            $messages[] = $m;
        });

        $products = iterator_to_array($connector->products(), false);

        $this->assertCount(210, $products);
        $this->assertSame(210, $connector->totalProducts());
        $this->assertSame(210, count(array_unique(array_map(static fn (B2bRemoteProduct $p): string => $p->sku, $products))));
        $this->assertSame(['GPH10008/GPHA' => 1, 'GPH10008/GPHB|PELTOR' => 1, 'GPH10008/GPHB|Scott' => 2], $this->listPasses);
        $this->assertStringContainsString('210 pozycji w 3 grupach kategorii', implode("\n", $messages));
        $this->assertStringContainsString('grup pobranych więcej niż raz (zmienna kolejność stron): 1', implode("\n", $connector->runSummary()));
        $packs = Http::recorded(fn (Request $r): bool => str_contains($r->url(), 'productPrice'))->values();
        // paczki po 50: 210 = 4 × 50 + 10
        $this->assertCount(5, $packs);
        parse_str((string) parse_url($packs[4][0]->url(), PHP_URL_QUERY), $query);
        $this->assertCount(10, explode(',', $query['materialIDs']));
    }

    public function test_price_pack_failing_on_one_item_is_halved_so_only_that_item_has_no_price(): void
    {
        $this->fakeSite();
        for ($i = 0; $i < 50; $i++) {
            $id = (string) (7000000000 + $i);
            $this->items[] = self::item($id, 'K'.$i, 'Wyrób testowy '.$i, 'EA', 'szt', 'CS', 'karton', '10');
            $this->prices[$id] = self::price('10,00 PLN / szt', '20,00 PLN / szt', '1 szt');
        }
        $this->brokenPriceIds = ['7000000017'];
        $connector = $this->connector();

        $products = iterator_to_array($connector->products(), false);

        $this->assertCount(50, $products);
        $failed = [];
        foreach ($products as $product) {
            try {
                $this->assertSame(10.0, $connector->price($product)?->net);
            } catch (RuntimeException $e) {
                $failed[$product->remoteId] = $e->getMessage();
            }
        }
        $this->assertSame(['7000000017'], array_map('strval', array_keys($failed)));
        $this->assertStringStartsWith('ceny 3M nie zostały pobrane (', $failed['7000000017']);
        $this->assertStringNotContainsString('materialIDs=', $failed['7000000017']);
    }

    public function test_group_incomplete_after_every_pass_stops_before_the_first_product(): void
    {
        $this->fakeSite();
        for ($i = 0; $i < 150; $i++) {
            $this->items[] = self::item((string) (7000000000 + $i), 'K'.$i, 'Wyrób testowy '.$i, 'EA', 'szt', 'CS', 'karton', '10');
        }
        // jedna marka bez podkategorii — grupy nie da się podzielić, a ostatniej pozycji nie ma nigdy
        $this->listPage = static fn (string $group, int $start, int $pass, array $inGroup): array => $start === 100
            ? array_slice($inGroup, 99, 50)
            : array_slice($inGroup, $start, 100);
        $connector = $this->connector();

        try {
            iterator_to_array($connector->products(), false);
            $this->fail('grupa niepełna po każdym przejściu');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString('po 4 przejściach ma 149 różnych pozycji przy liczniku 150', $e->getMessage());
        }
        $this->assertSame(4, $this->listPasses['GPH10008|3M']);
        $this->assertCount(0, Http::recorded(fn (Request $r): bool => str_contains($r->url(), 'productPrice')));
    }

    public function test_subcategories_not_adding_up_to_the_branch_count_stop_the_list(): void
    {
        $this->fakeSite();
        for ($i = 0; $i < 150; $i++) {
            $item = self::item((string) (7000000000 + $i), 'K'.$i, 'Wyrób testowy '.$i, 'EA', 'szt', 'CS', 'karton', '10');
            $item['_path'] = ['GPH10008', $i < 75 ? 'GPHA' : 'GPHB'];
            $this->items[] = $item;
        }
        $this->facetOverrides = ['GPH10008' => ['GPHB' => 70]];

        try {
            iterator_to_array($this->connector()->products(), false);
            $this->fail('drzewo kategorii niespójne');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString('podkategorie GPH10008 dają 145 pozycji przy liczniku 150', $e->getMessage());
        }
    }

    public function test_session_lost_on_prices_logs_in_once_and_without_sso_the_run_stops(): void
    {
        $this->fakeSite();
        $this->catalog();
        $this->pricesUnauthorized = true;
        $this->ssoSessions = [];
        $connector = $this->connector();

        try {
            iterator_to_array($connector->products(), false);
            $this->fail('utrata sesji bez SSO');
        } catch (B2bFatalException $e) {
            $this->assertStringContainsString('Zaloguj kodem', $e->getMessage());
        }
        $this->assertCount(0, $this->selfAssertedPosts());
    }

    public function test_description_shop_fields_documents_and_images_come_from_one_product_card(): void
    {
        $this->fakeSite();
        $this->catalog();
        $connector = $this->connector();
        $products = self::byId(iterator_to_array($connector->products(), false));
        $mask = $products['7000009701'];

        $this->assertSame(
            "Półmaska wielokrotnego użytku.\n\n"
            ."Lekka konstrukcja.\n\nSpełnia wymagania normy EN 140:1998.\n\n"
            ."- Niska waga\n- Prosty montaż filtrów",
            $connector->description($mask),
        );
        $this->assertSame([
            ['Informacje handlowe', 'Marka', '3M™'],
            ['Informacje handlowe', 'Numer katalogowy 3M', '6200'],
            ['Informacje handlowe', 'Numer magazynowy 3M', '7000009701'],
            ['Informacje handlowe', 'EAN', '04046719303420'],
            ['Informacje handlowe', 'Poprzedni numer 3M', '70-0710-2845-5'],
            ['Informacje handlowe', 'Cena za', '1 szt'],
            ['Informacje handlowe', 'Minimalne zamówienie', '1 karton'],
            ['Informacje handlowe', 'Jednostka sprzedaży', 'karton = 24 szt'],
            ['Dane techniczne', 'Kolor produktu', 'Szary, Niebieski'],
            ['Dane techniczne', 'Rozmiar', 'M'],
            ['Dane techniczne', 'Waga', '82 g'],
            ['Dane techniczne', 'Liczba w opakowaniu', '24'],
            ['Opakowanie', 'Kod kreskowy - Opakowanie detaliczne (szt)', '04046719303420'],
            ['Opakowanie', 'Kraj pochodzenia', 'Wielka Brytania'],
            ['Opakowanie', 'za karton', '24 szt'],
        ], array_map(static fn ($f): array => [$f->section, $f->name, $f->value], $connector->shopFields($mask)));
        $this->assertSame([
            ['Karta danych 6200.pdf', self::MEDIA.'1287001O/karta-6200.pdf', ProductDocument::KIND_DATASHEET],
            ['Ulotka półmasek.pdf', self::MEDIA.'1287002O/ulotka.pdf', ProductDocument::KIND_OTHER],
        ], array_map(static fn ($d): array => [$d->title, $d->sourceUrl, $d->kind], $connector->documents($mask)));
        $this->assertSame(
            [self::MEDIA.'1287824Z/polmaska-front.jpg', self::MEDIA.'1287825Z/polmaska-bok.jpg'],
            $connector->imageUrls($mask),
        );
        $this->assertSame('application/pdf', $connector->documentBytes($connector->documents($mask)[0])['mime']);
        $this->assertSame('image/jpeg', $connector->imageAt(self::MEDIA.'1287824Z/polmaska-front.jpg')?->mime);
        $this->assertSame('3M', $connector->manufacturer($mask));
        // karta pdp pobrana raz na produkt
        $this->assertCount(1, Http::recorded(fn (Request $r): bool => str_contains($r->url(), '/pdp')));

        // część zamienna bez opisu — pusty opis, liczony w podsumowaniu
        $this->assertSame('', $connector->description($products['7100100637']));
        $this->assertStringContainsString('bez opisu', implode("\n", $connector->runSummary()));
    }

    public function test_sync_creates_3m_cards_with_the_stock_number_as_sku_and_the_price_per_base_unit(): void
    {
        Storage::fake('public');
        Storage::fake('local');
        $this->fakeSite();
        $this->catalog();
        $account = $this->account(['live' => true]);

        $result = app(B2bAccountSyncRunner::class)->run(
            $account, delayMs: 0, withImages: true, connector: MmmB2bConnector::forAccount($account, 0),
        );

        $this->assertSame(2, $result['created'], implode(' | ', $result['errors']));
        $this->assertSame(2, $result['skipped'], implode(' | ', $result['errors']));

        $card = Product::query()->where('sku', '7000009701')->sole();
        $this->assertSame('3M', $card->manufacturer);
        $this->assertSame('Półmaska 3M 6200, rozmiar M', $card->name);
        $slot = ProductSourcePrice::query()->where('product_id', $card->id)->sole();
        $this->assertSame('24.54', (string) $slot->purchase_price);
        $this->assertSame('49.08', (string) $slot->catalog_price_net);
        $this->assertStringContainsString('EN 140:1998', (string) $card->description);
        $shop = ProductShopCard::query()->where('product_id', $card->id)->sole();
        $this->assertStringContainsString('karton = 24 szt', (string) json_encode($shop->fields, JSON_UNESCAPED_UNICODE));
        $this->assertSame(
            [self::MEDIA.'1287001O/karta-6200.pdf', self::MEDIA.'1287002O/ulotka.pdf'],
            ProductDocument::query()->where('product_id', $card->id)->orderBy('sort_order')->pluck('source_url')->all(),
        );
        $this->assertSame(
            [self::MEDIA.'1287824Z/polmaska-front.jpg', self::MEDIA.'1287825Z/polmaska-bok.jpg'],
            ProductImage::query()->where('product_id', $card->id)->orderBy('sort_order')->pluck('source_url')->all(),
        );

        $plugs = Product::query()->where('sku', '7100100637')->sole();
        $this->assertSame('0.42', (string) ProductSourcePrice::query()->where('product_id', $plugs->id)->value('purchase_price'));
        $this->assertFalse(Product::query()->where('sku', '7000034747')->exists());

        $log = implode("\n", array_column((array) B2bSyncRun::query()->latest('id')->firstOrFail()->log, 'text'));
        $this->assertStringContainsString('cena za inną jednostkę niż bazowa', $log);
        $this->assertNotNull($account->fresh()?->connector_session_saved_at);

        $identifiers = static fn (int $productId): array => ProductIdentifier::query()->where('product_id', $productId)->orderBy('id')
            ->get()->map(static fn (ProductIdentifier $i): array => [$i->type, $i->value, $i->position_key, $i->source_field, $i->manufacturer])->all();
        $expected = [
            [ProductIdentifier::TYPE_MANUFACTURER_CODE, '6200', '7000009701', 'mmm_catalog_number', '3M'],
            [ProductIdentifier::TYPE_ALT_CODE, '7000009701', '7000009701', 'mmm_id', '3M'],
            [ProductIdentifier::TYPE_LEGACY_CODE, '70-0710-2845-5', '7000009701', 'legacy_mmm_id', '3M'],
            [ProductIdentifier::TYPE_EAN, '04046719303420', '7000009701', 'gtin_display', '3M'],
        ];
        $this->assertSame($expected, $identifiers((int) $card->id));
        $this->assertSame(3, ProductIdentifier::query()->where('product_id', $plugs->id)->count());
        $this->assertSame(7, ProductIdentifier::query()->count());

        // drugi przebieg: nic nowego, identyfikatory nie dublują się i nie są oznaczane jako zniknięte
        $second = app(B2bAccountSyncRunner::class)->run(
            $account->fresh() ?? $account, delayMs: 0, withImages: true, connector: MmmB2bConnector::forAccount($account->fresh() ?? $account, 0),
        );
        $this->assertSame(0, $second['created'], implode(' | ', $second['errors']));
        $this->assertSame($expected, $identifiers((int) $card->id));
        $this->assertSame(7, ProductIdentifier::query()->count());
        $this->assertSame(0, ProductIdentifier::query()->whereNotNull('removed_at')->count());
    }

    public function test_connector_declares_its_capabilities(): void
    {
        $connector = new MmmB2bConnector($this->client());

        $this->assertSame('3m', MmmB2bConnector::key());
        $this->assertSame('3M', MmmB2bConnector::label());
        $this->assertSame('order.3m.com', MmmB2bConnector::host());
        $this->assertSame('3M', MmmB2bConnector::ownBrand());
        foreach ([B2bCodeLoginSite::class, B2bManufacturerSite::class, B2bShopFieldSource::class, B2bDocumentSource::class, B2bImageGallery::class, B2bListProgressAware::class, B2bRunSummaryAware::class, B2bSizePriceSource::class] as $interface) {
            $this->assertInstanceOf($interface, $connector);
        }
        // łączy tylko kolory — rozmiary 3M (6200 S/M/L) zostają osobnymi kartami, więc „Łączenie kart” musi móc je
        // zaproponować jako rozmiary (CardMatchFinder, warunek 3 sygnału)
        $this->assertNotInstanceOf(B2bGroupsSizes::class, $connector);
    }

    public function test_file_over_the_limit_is_skipped_and_never_counts_as_a_connection_error(): void
    {
        // ten sam błąd co u Honeywella 08.10.2026: wyjątek limitu z on_headers dochodził jako „brak połączenia
        // (An error was encountered during the on_headers event)” i liczył się do serii, po której przebieg staje
        $withLength = self::MEDIA.'9000001O/synth-user-instruction.pdf';
        $withoutLength = self::MEDIA.'9000002O/synth-user-instruction-chunked.pdf';
        $requests = 0;
        Http::fake(static function (Request $request, array $options) use ($withLength, $withoutLength, &$requests) {
            $requests++;
            if (in_array($request->url(), [$withLength, $withoutLength], true)) {
                return OversizedFileDownload::abortLikeCurl($request, $options, $request->url() === $withLength);
            }

            return Http::response('%PDF-1.4 synth', 200, ['Content-Type' => 'application/pdf']);
        });
        $client = $this->client();

        // więcej niż próg serii błędów (20)
        foreach (array_merge(array_fill(0, 22, $withLength), [$withoutLength]) as $url) {
            $thrown = null;
            try {
                $client->fileBytes($url);
            } catch (Throwable $e) {
                $thrown = $e;
            }
            $this->assertNotNull($thrown, $url);
            $this->assertNotInstanceOf(B2bFatalException::class, $thrown, $thrown->getMessage());
            $this->assertSame('plik ponad 15 MB pominięty: '.$url, $thrown->getMessage());
        }

        $this->assertSame(23, $requests);
        $this->assertSame('application/pdf', $client->fileBytes(self::MEDIA.'9000003O/synth-datasheet.pdf')['mime']);
    }

    public function test_missing_file_is_skipped_and_never_counts_as_a_server_failure(): void
    {
        $gone = self::MEDIA.'9000004O/synth-brochure-404.pdf';
        Http::fake(static function (Request $request) use ($gone) {
            if ($request->url() === $gone) {
                return Http::response('<html>Not Found</html>', 404, ['Content-Type' => 'text/html']);
            }

            return Http::response('%PDF-1.4 synth', 200, ['Content-Type' => 'application/pdf']);
        });
        $client = $this->client();

        // więcej niż próg serii błędów (20)
        for ($i = 0; $i < 22; $i++) {
            $thrown = null;
            try {
                $client->fileBytes($gone);
            } catch (Throwable $e) {
                $thrown = $e;
            }
            $this->assertNotNull($thrown);
            $this->assertNotInstanceOf(B2bFatalException::class, $thrown, $thrown->getMessage());
            $this->assertSame('brak pliku na multimedia.3m.com (HTTP 404): '.$gone, $thrown->getMessage());
        }

        $this->assertSame('application/pdf', $client->fileBytes(self::MEDIA.'9000005O/synth-datasheet.pdf')['mime']);
    }

    public function test_colour_variant_key_reads_the_colour_and_the_code_suffix_from_the_list_name(): void
    {
        $helmet = 'Hełm ochronny 3M™, wskaźnik Uvicator, pinlock, wentylowany, opaska przeciwpotna';
        $this->assertSame([
            'key' => mb_strtolower($helmet).'|G3000CUV',
            'colour' => 'biały',
            'code' => 'G3000CUV-VI',
            'prefix' => $helmet,
            'stem' => 'G3000CUV',
        ], MmmB2bConnector::colourVariantKey($helmet.', biały, G3000CUV-VI'));
        // ta sama grupa: inny kolor i końcówka kodu, także „o zwiększonej widzialności”
        $key = MmmB2bConnector::colourVariantKey($helmet.', biały, G3000CUV-VI')['key'] ?? null;
        $this->assertSame($key, MmmB2bConnector::colourVariantKey($helmet.', czerwony, G3000CUV-RD')['key'] ?? null);
        $this->assertSame($key, MmmB2bConnector::colourVariantKey($helmet.', o zwiększonej widzialności, G3000CUV-GB')['key'] ?? null);
        // kod z członem modelu przed kolorem (G3000NUV-10-GB) — rdzeń zachowuje człon modelu
        $nuv = MmmB2bConnector::colourVariantKey('Hełm ochronny 3M™, wentylowany, pomarańczowy, G3000NUV-10-GB');
        $this->assertSame(['pomarańczowy', 'G3000NUV-10-GB', 'G3000NUV-10'], [$nuv['colour'] ?? null, $nuv['code'] ?? null, $nuv['stem'] ?? null]);
        $this->assertSame('jasnoniebieskie', MmmB2bConnector::colourVariantKey('Nauszniki 3M™, jasnoniebieskie, H510A-401-GU')['colour'] ?? null);

        // kod bez końcówki koloru — inne modele (100V / 1000V), nie kolory
        $this->assertNull(MmmB2bConnector::colourVariantKey('Hełm ochronny 3M™, biały, G3001MUV100V'));
        $this->assertNull(MmmB2bConnector::colourVariantKey('Hełm ochronny 3M™, biały, G3001MUV1000V'));
        // pokrywy SportTac to jedna grupa, czasze SportTac (inny początek nazwy) — inna
        $green = MmmB2bConnector::colourVariantKey('3M™ PELTOR™ SportTac™ pokrywy wymienne, zielone, 210100-478-GN')['key'] ?? null;
        $this->assertSame($green, MmmB2bConnector::colourVariantKey('3M™ PELTOR™ SportTac™ pokrywy wymienne, pomarańczowe, 210100-478-VI')['key'] ?? null);
        $this->assertNotSame($green, MmmB2bConnector::colourVariantKey('Wymienne czasze 3M™ PELTOR™ SportTac™, czarne, 210100-478-SV')['key'] ?? null);
        // inny początek przy tym samym rdzeniu kodu = inny wyrób
        $this->assertNotSame($key, MmmB2bConnector::colourVariantKey('Hełm ochronny 3M™ ze skóry, wskaźnik Uvicator, biały, G3000CUV-VI')['key'] ?? null);
        // bez członu koloru, kolor tylko w początku nazwy, kolor z dopiskiem, kod bez cyfry
        $this->assertNull(MmmB2bConnector::colourVariantKey('Półmaska 3M 6200, rozmiar M'));
        $this->assertNull(MmmB2bConnector::colourVariantKey('Filtr 3M™, P3, 6035-AB'));
        $this->assertNull(MmmB2bConnector::colourVariantKey('Hełm ochronny 3M™ biały, wentylowany, G3000CUV-VI'));
        $this->assertNull(MmmB2bConnector::colourVariantKey('Hełm ochronny 3M™, biały z logo, G3000CUV-VI'));
        $this->assertNull(MmmB2bConnector::colourVariantKey('Hełm ochronny 3M™, biały, GCUV-VI'));
        $this->assertNull(MmmB2bConnector::colourVariantKey('biały, G3000CUV-VI'));
    }

    public function test_colour_variants_of_one_product_become_one_card_with_a_price_per_colour(): void
    {
        $this->fakeSite();
        $helmet = 'Hełm ochronny 3M™, wskaźnik Uvicator, pinlock, wentylowany, opaska przeciwpotna';
        $this->colourCatalog([
            ['7100000001', 'G3000CUV-VI', $helmet.', biały, G3000CUV-VI', '60,00'],
            ['7000009701', '6200', 'Półmaska 3M 6200, rozmiar M', '24,54'],
            ['7100000010', 'G3001MUV100V', 'Hełm ochronny 3M™, biały, G3001MUV100V', '70,00'],
            ['7100000003', 'G3000CUV-RD', $helmet.', czerwony, G3000CUV-RD', '55,00'],
            ['7100000011', 'G3001MUV1000V', 'Hełm ochronny 3M™, biały, G3001MUV1000V', '71,00'],
            ['7100000020', '210100-478-SV', 'Wymienne czasze 3M™ PELTOR™ SportTac™, czarne, 210100-478-SV', '90,00'],
            // G3000CUV-GB: numer katalogowy 3M bywa błędny — kod pozycji bierzemy z nazwy
            ['7100000002', 'G3000CUV-XX', $helmet.', o zwiększonej widzialności, G3000CUV-GB', '55,00'],
        ]);
        $this->pdps['7100000002'] = ['mmm_id' => '7100000002', 'name' => 'Hełm G3000', 'description' => 'Hełm ze wskaźnikiem UV.', 'classified' => [
            ['label' => 'Kolor produktu', 'type' => 'enum', 'value' => [['value' => 'Żółty']]],
            ['label' => 'Normy', 'type' => 'text', 'value' => 'EN 397'],
        ], 'packagingIdentificationDetails' => [['label' => 'EAN karton', 'value' => '04054596000000']]];
        $connector = $this->connector();

        $products = iterator_to_array($connector->products(), false);

        // pozycje spoza grup kolorów — jak dotąd i w kolejności listy; karta z kolorami po nich
        $this->assertSame(
            ['7000009701', '7100000010', '7100000011', '7100000020', '7100000002'],
            array_map(static fn (B2bRemoteProduct $p): string => $p->remoteId, $products),
        );
        $this->assertSame(5, $connector->totalProducts());
        $single = $products[0];
        $this->assertSame(['7000009701', 'Półmaska 3M 6200, rozmiar M', null, [], null], [$single->sku, $single->name, $single->cardName, $single->members, $single->variantSummary]);
        $this->assertSame(24.54, $connector->price($single)?->net);

        $card = $products[4];
        // najtańsza pozycja (remis 55,00 — niższy numer magazynowy) daje numer, nazwę ze źródła i cenę karty
        $this->assertSame('7100000002', $card->sku);
        $this->assertSame($helmet.', o zwiększonej widzialności, G3000CUV-GB', $card->name);
        $this->assertSame($helmet.', G3000CUV', $card->cardName);
        $this->assertNull($card->category);
        $this->assertSame(MmmB2bClient::productUrl('7100000002'), $card->sourceUrl);
        $this->assertSame('Kolory: biały (G3000CUV-VI); czerwony (G3000CUV-RD); o zwiększonej widzialności (G3000CUV-GB)', $card->variantSummary);
        $this->assertSame(
            [
                ['7100000001', 'G3000CUV-VI', $helmet.', biały, G3000CUV-VI', 'biały', 60.0, 80.0],
                ['7100000003', 'G3000CUV-RD', $helmet.', czerwony, G3000CUV-RD', 'czerwony', 55.0, 80.0],
                ['7100000002', 'G3000CUV-GB', $helmet.', o zwiększonej widzialności, G3000CUV-GB', 'o zwiększonej widzialności', 55.0, 80.0],
            ],
            array_map(static fn (array $m): array => [$m['remote_id'], $m['sku'], $m['name'], $m['size'], $m['price']->net, $m['price']->base], $card->members),
        );
        $this->assertSame('PLN', $card->members[0]['price']->currency);
        $this->assertSame(25.0, $card->members[0]['price']->discountPercent);
        $this->assertSame(['order_min_qty' => 1.0, 'order_step_qty' => 1.0, 'order_unit' => 'szt', 'order_varies' => false], $card->members[1]['price']->order?->slotValues());
        $this->assertSame(55.0, $connector->price($card)?->net);
        // numery 3M każdej pozycji z jej kolorem
        $this->assertContains([ProductIdentifier::TYPE_MANUFACTURER_CODE, 'G3000CUV-VI', '7100000001', 'biały', 'mmm_catalog_number'], self::identifierRows($card));
        $this->assertContains([ProductIdentifier::TYPE_ALT_CODE, '7100000003', '7100000003', 'czerwony', 'mmm_id'], self::identifierRows($card));
        $this->assertContains([ProductIdentifier::TYPE_MANUFACTURER_CODE, 'G3000CUV-XX', '7100000002', 'o zwiększonej widzialności', 'mmm_catalog_number'], self::identifierRows($card));
        $this->assertCount(9, self::identifierRows($card));
        // opis z karty pdp pozycji prowadzącej
        $this->assertSame('Hełm ze wskaźnikiem UV.', $connector->description($card));
        // tabelka bez numerów, kodów opakowań i koloru jednej pozycji — każdy kolor ma je w wierszu wariantu
        $names = array_map(static fn ($f): string => $f->name, $connector->shopFields($card));
        $this->assertContains('Normy', $names);
        foreach (['Kolor produktu', 'Numer katalogowy 3M', 'Numer magazynowy 3M', 'EAN', 'EAN karton'] as $perPosition) {
            $this->assertNotContains($perPosition, $names);
        }
        $this->assertStringContainsString('Warianty kolorystyczne 3M: 1 wyrobów z 3 pozycji', implode("\n", $connector->runSummary()));
    }

    public function test_colour_group_member_without_price_stays_a_single_card_and_the_rest_still_groups(): void
    {
        $this->fakeSite();
        $helmet = 'Hełm ochronny 3M™, wentylowany';
        $covers = '3M™ PELTOR™ SportTac™ pokrywy wymienne';
        $this->colourCatalog([
            ['7100000001', 'G3000CUV-VI', $helmet.', biały, G3000CUV-VI', '60,00'],
            ['7100000002', 'G3000CUV-RD', $helmet.', czerwony, G3000CUV-RD', '61,00'],
            ['7100000003', 'G3000CUV-GU', $helmet.', żółty, G3000CUV-GU', null],
            ['7100000030', '210100-478-GN', $covers.', zielone, 210100-478-GN', '20,00'],
            ['7100000031', '210100-478-VI', $covers.', pomarańczowe, 210100-478-VI', '20,00'],
        ]);
        // pokrywa pomarańczowa bez ceny z błędem paczki — zostaje jedna pozycja z ceną, więc bez karty z kolorami
        $this->brokenPriceIds = ['7100000031'];
        $connector = $this->connector();

        $products = self::byId(iterator_to_array($connector->products(), false));

        $this->assertSame(['7100000001', '7100000003', '7100000030', '7100000031'], array_map(static fn (B2bRemoteProduct $p): string => $p->remoteId, array_values($products)));
        $this->assertSame(4, $connector->totalProducts());
        $card = $products['7100000001'];
        $this->assertSame(['7100000001', '7100000002'], array_column($card->members, 'remote_id'));
        $this->assertSame('Kolory: biały (G3000CUV-VI); czerwony (G3000CUV-RD)', $card->variantSummary);
        $this->assertSame(60.0, $connector->price($card)?->net);
        // żółty bez ceny — osobna karta jak dotąd (bez ceny z powodem)
        $yellow = $products['7100000003'];
        $this->assertSame([[], null, null], [$yellow->members, $yellow->cardName, $connector->price($yellow)]);
        $this->assertSame($helmet.', żółty, G3000CUV-GU', $yellow->name);
        $this->assertSame([], $products['7100000030']->members);
        $this->assertSame(20.0, $connector->price($products['7100000030'])?->net);
        try {
            $connector->price($products['7100000031']);
            $this->fail('błąd ceny pozycji powinien wrócić z price()');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString('ceny 3M nie zostały pobrane', $e->getMessage());
        }
        $summary = implode("\n", $connector->runSummary());
        $this->assertStringContainsString('Warianty kolorystyczne 3M: 1 wyrobów z 2 pozycji', $summary);
        $this->assertStringContainsString('sklep nie podaje ceny: 1, np. 7100000003', $summary);
    }

    public function test_repeated_colour_in_a_group_keeps_every_position_a_separate_card(): void
    {
        $this->fakeSite();
        $helmet = 'Hełm ochronny 3M™, wentylowany';
        // jak na żywo (G3000NUV-10-GB trzy razy): ten sam kolor dwa razy — nie wiadomo, który wiersz jest którym wyrobem
        $this->colourCatalog([
            ['7100000001', 'G3000NUV-10-BB', $helmet.', niebieski, G3000NUV-10-BB', '60,00'],
            ['7100000002', 'G3000NUV-10-VI', $helmet.', biały, G3000NUV-10-VI', '60,00'],
            ['7100000003', 'G3000NUV-10-GB', $helmet.', Biały, G3000NUV-10-GB', '60,00'],
        ]);
        $connector = $this->connector();

        $products = iterator_to_array($connector->products(), false);

        $this->assertSame(['7100000001', '7100000002', '7100000003'], array_map(static fn (B2bRemoteProduct $p): string => $p->remoteId, $products));
        $this->assertSame(3, $connector->totalProducts());
        foreach ($products as $product) {
            $this->assertSame([[], null, null], [$product->members, $product->cardName, $product->variantSummary]);
            $this->assertSame($product->remoteId, $product->sku);
            $this->assertSame(60.0, $connector->price($product)?->net);
        }
        $this->assertStringNotContainsString('Warianty kolorystyczne', implode("\n", $connector->runSummary()));
    }

    // ---- pomocnicze ----

    public function test_gallery_skips_3m_placeholders_file_icons_and_pages_served_as_images(): void
    {
        $this->fakeSite();
        $this->mediaCatalog();
        $connector = $this->connector();
        $mask = self::byId(iterator_to_array($connector->products(), false))['7000001001'];

        // zaślepki „Scott Spares and Accessories” i „Spare Part” odpadają po adresie, bez pobierania
        $this->assertSame([
            self::MEDIA.'2593907Z/brak-zasobu-6200.jpg',
            self::MEDIA.'2593908Z/ikona-pliku-6200.jpg',
            self::MEDIA.'1287824Z/polmaska-6200.jpg',
        ], $connector->imageUrls($mask));
        // strona 404 i ikona pliku 240×175 mają nagłówek obrazu — liczą się bajty
        $this->assertNull($connector->imageAt(self::MEDIA.'2593907Z/brak-zasobu-6200.jpg'));
        $this->assertNull($connector->imageAt(self::MEDIA.'2593908Z/ikona-pliku-6200.jpg'));
        $this->assertSame('image/jpeg', $connector->imageAt(self::MEDIA.'1287824Z/polmaska-6200.jpg')?->mime);
        $this->assertTrue(MmmB2bConnector::isPlaceholderImageUrl(self::MEDIA.'2100067J/inna-nazwa.jpg'));
        $this->assertFalse(MmmB2bConnector::isPlaceholderImageUrl(self::MEDIA.'21000671Z/hełm.jpg'));
        $this->assertStringContainsString('Zdjęcia z karty 3M pominięte (zaślepka, ikona pliku albo strona zamiast obrazu): 4', implode("\n", $connector->runSummary()));
    }

    public function test_documents_skip_marketing_and_files_of_other_3m_positions_but_keep_family_files(): void
    {
        $this->fakeSite();
        $this->mediaCatalog();
        $connector = $this->connector();
        $products = self::byId(iterator_to_array($connector->products(), false));

        $this->assertSame([
            'Karta danych 6200.pdf',
            'Broszura półmasek wielokrotnego użytku.pdf',
            'Instrukcja 3M 6000 – półmaski 6100, 6200, 6300',
        ], array_map(static fn ($d): string => $d->title, $connector->documents($products['7000001001'])));
        // ta sama karta 9936 przy własnej pozycji zostaje
        $this->assertSame(['Karta techniczna 3M Aura 9936'], array_map(static fn ($d): string => $d->title, $connector->documents($products['7000001002'])));

        $summary = implode("\n", $connector->runSummary());
        $this->assertStringContainsString('Pliki z karty 3M pominięte — materiał marketingowy 3M: 2', $summary);
        $this->assertStringContainsString('Pliki z karty 3M pominięte — plik innego wyrobu 3M: 2', $summary);
    }

    public function test_description_drops_3m_editorial_markup_and_keeps_the_rest_verbatim(): void
    {
        $this->fakeSite();
        $this->mediaCatalog();
        $connector = $this->connector();
        $products = self::byId(iterator_to_array($connector->products(), false));

        $this->assertSame(
            "Przewód do radiotelefonu.\n- Współpracuje z PELTOR\n- Umożliwia podłączenie\n\n"
            .'Hełmy mogą spełniać wymagania normy EN 12492 lub wymagania normy EN 397, przy pojawieniu się hałasu (opis w instrukcji).',
            $connector->description($products['7000001001']),
        );
    }

    public function test_media_audit_removes_legacy_placeholders_pages_and_foreign_files_and_the_next_sync_adds_none_back(): void
    {
        Storage::fake('public');
        Storage::fake('local');
        $this->fakeSite();
        $this->mediaCatalog();
        $account = $this->account(['live' => true]);
        $sync = fn (): array => app(B2bAccountSyncRunner::class)->run(
            $account->fresh() ?? $account, delayMs: 0, withImages: true, connector: MmmB2bConnector::forAccount($account->fresh() ?? $account, 0),
        );

        $first = $sync();
        $this->assertSame(2, $first['created'], implode(' | ', $first['errors']));
        $card = Product::query()->where('sku', '7000001001')->sole();
        $real = self::MEDIA.'1287824Z/polmaska-6200.jpg';
        $this->assertSame([$real], ProductImage::query()->where('product_id', $card->id)->pluck('source_url')->all());
        $this->assertSame(
            ['Karta danych 6200.pdf', 'Broszura półmasek wielokrotnego użytku.pdf', 'Instrukcja 3M 6000 – półmaski 6100, 6200, 6300'],
            ProductDocument::query()->where('product_id', $card->id)->orderBy('sort_order')->pluck('title')->all(),
        );

        // stan karty z produkcji sprzed poprawki: zaślepka jako zdjęcie główne, strona 404 jako .jpg, ikona pliku,
        // karta techniczna innej pozycji i baner — wszystko z kontem 3M; obok zdjęcie z sieci i plik bez konta
        $legacy = [
            ['zaslepka.jpg', self::jpeg('z', 300, 300), self::MEDIA.'2100067Z/3m-scott-spare-and-accessories-icon-for-b-com.jpg', $account->id],
            ['strona.jpg', self::RESOURCE_404, self::MEDIA.'2593907Z/brak-zasobu-6200.jpg', $account->id],
            ['ikona.jpg', self::jpeg('i', 240, 175), self::MEDIA.'2593909Z/inny-zasob.jpg', $account->id],
            ['z-sieci.jpg', self::jpeg('w', 300, 300), 'https://sklep.example.com/polmaska-6200.jpg', null],
        ];
        foreach ($legacy as $i => [$file, $bytes, $url, $owner]) {
            Storage::disk('public')->put('products/'.$card->id.'/'.$file, $bytes);
            ProductImage::query()->forceCreate([
                'product_id' => $card->id, 'b2b_account_id' => $owner, 'path' => 'products/'.$card->id.'/'.$file,
                'source_url' => $url, 'is_primary' => $i === 0, 'sort_order' => $i === 0 ? 0 : 10 + $i, 'checksum' => hash('sha256', $bytes),
            ]);
        }
        ProductImage::query()->where('source_url', $real)->update(['is_primary' => false, 'sort_order' => 5]);
        foreach ([
            ['Karta techniczna 3M Aura 9936', self::MEDIA.'1400001O/karta-9936.pdf', $account->id],
            ['Peltor Comtac IX Facebook banner', self::MEDIA.'2648535O/3m-peltor-comtac-ix-facebook-banner-ce-version.pdf', $account->id],
            ['Karta techniczna 3M Aura 9936', 'https://sklep.example.com/karta-9936.pdf', null],
        ] as $i => [$title, $url, $owner]) {
            Storage::disk('public')->put('products/'.$card->id.'/docs/legacy'.$i.'.pdf', '%PDF-1.4 '.$title);
            ProductDocument::query()->forceCreate([
                'product_id' => $card->id, 'b2b_account_id' => $owner, 'path' => 'products/'.$card->id.'/docs/legacy'.$i.'.pdf',
                'source_url' => $url, 'title' => $title, 'kind' => ProductDocument::KIND_DATASHEET, 'sort_order' => 20 + $i,
                'checksum' => hash('sha256', $title.$i), 'size_bytes' => 10,
            ]);
        }
        $imagesBefore = ProductImage::query()->count();
        $documentsBefore = ProductDocument::query()->count();

        // sam przebieg zastanych wierszy nie usuwa (synchronizacja tylko dokłada)
        $this->assertSame(0, $sync()['created']);
        $this->assertSame($imagesBefore, ProductImage::query()->count());
        $this->assertSame($documentsBefore, ProductDocument::query()->count());

        // raport niczego nie usuwa
        $this->artisan('b2b:mmm-media-audit')
            ->expectsOutputToContain('zaślepki i ikony pliku: 2; strony zamiast obrazu: 1')
            ->expectsOutputToContain('do usunięcia: 2')
            ->assertSuccessful();
        $this->assertSame($imagesBefore, ProductImage::query()->count());
        $this->assertSame($documentsBefore, ProductDocument::query()->count());

        $this->artisan('b2b:mmm-media-audit --apply')->assertSuccessful();
        $this->assertSame(
            [$real, 'https://sklep.example.com/polmaska-6200.jpg'],
            ProductImage::query()->where('product_id', $card->id)->orderBy('sort_order')->pluck('source_url')->all(),
        );
        $this->assertTrue(ProductImageRejection::query()->where('product_id', $card->id)->where('source_url', 'like', '%2100067Z%')->exists());
        $this->assertTrue(ProductImageRejection::query()->where('product_id', $card->id)->where('source_url', 'like', '%2593909Z%')->exists());
        // strona 404 bez śladu odrzucenia — adres może kiedyś oddać prawdziwe zdjęcie
        $this->assertFalse(ProductImageRejection::query()->where('source_url', 'like', '%2593907Z%')->exists());
        $this->assertSame(
            ['Karta danych 6200.pdf', 'Broszura półmasek wielokrotnego użytku.pdf', 'Instrukcja 3M 6000 – półmaski 6100, 6200, 6300', 'Karta techniczna 3M Aura 9936'],
            ProductDocument::query()->where('product_id', $card->id)->orderBy('sort_order')->pluck('title')->all(),
        );
        $this->assertNull(ProductDocument::query()->where('product_id', $card->id)->where('title', 'Karta techniczna 3M Aura 9936')->value('b2b_account_id'));
        // karta 9936 przy swojej pozycji zostaje
        $this->assertTrue(ProductDocument::query()->where('product_id', Product::query()->where('sku', '7000001002')->value('id'))->where('title', 'Karta techniczna 3M Aura 9936')->exists());

        // kolejny przebieg nie dokłada niczego z powrotem, a zdjęcie wyrobu jest główne
        $sync();
        $this->assertSame(
            [$real, 'https://sklep.example.com/polmaska-6200.jpg'],
            ProductImage::query()->where('product_id', $card->id)->orderBy('sort_order')->pluck('source_url')->all(),
        );
        $this->assertSame($real, ProductImage::query()->where('product_id', $card->id)->where('is_primary', true)->value('source_url'));
        $this->assertSame(4, ProductDocument::query()->where('product_id', $card->id)->count());
    }

    /**
     * Dwie pozycje: półmaska 6200 z galerią i plikami jak na karcie 3M po audycie 08.10.2026 (zaślepki, strona 404
     * i ikona pliku pod nagłówkiem obrazu, baner, karta innej pozycji) i półmaska 9936 — jej kod czyni plik
     * „Karta techniczna 3M Aura 9936” plikiem innej pozycji przy 6200.
     */
    private function mediaCatalog(): void
    {
        $this->items = [
            self::item('7000001001', '6200', 'Półmaska 3M 6200, rozmiar M', 'EA', 'szt', 'CS', 'karton', '24'),
            self::item('7000001002', '9936', 'Półmaska filtrująca 3M Aura, FFP3, 9936', 'EA', 'szt', 'CS', 'karton', '10'),
        ];
        $this->prices = [
            '7000001001' => self::price('24,54 PLN / szt', '49,08 PLN / szt', '1 szt'),
            '7000001002' => self::price('12,00 PLN / szt', '20,00 PLN / szt', '1 szt'),
        ];
        $pdf = static fn (string $asset, string $title, string $type = 'Arkusze danych'): array => [
            'url' => self::MEDIA.$asset.'J/'.$asset.'.jpg', 'title' => $title, 'content_type' => $type, 'mime_type' => 'application/pdf',
        ];
        $image = static fn (string $asset, string $file, bool $main = false): array => [
            'url' => self::MEDIA.$asset.'J/'.$file, 'url_pattern' => self::MEDIA.$asset.'<R>/'.$file, 'is_main_image' => $main, 'mime_type' => 'image/jpeg',
        ];
        $this->pdps = [
            '7000001001' => [
                'mmm_id' => '7000001001',
                'name' => 'Półmaska 3M 6200',
                'description' => 'Przewód do radiotelefonu. (bul) Współpracuje z PELTOR (bul) Umożliwia podłączenie',
                'long_description' => 'Hełmy mogą spełniać wymagania normy EN 12492 (dodać normę) lub wymagania normy EN 397 (opis), '
                    .'przy poja¬wieniu się hałasu (opis w instrukcji).',
                'classified' => [],
                'media_links_images' => [
                    $image('2100067', '3m-scott-spare-and-accessories-icon-for-b-com.jpg', true),
                    $image('2549891', 'emea-psd-spare-part-picto-with-white-mesh.jpg'),
                    $image('2593907', 'brak-zasobu-6200.jpg'),
                    $image('2593908', 'ikona-pliku-6200.jpg'),
                    $image('1287824', 'polmaska-6200.jpg'),
                ],
                'media_links_documents' => [
                    $pdf('1400010', 'Karta danych 6200.pdf'),
                    $pdf('1400001', 'Karta techniczna 3M Aura 9936'),
                    $pdf('2648535', 'Peltor Comtac IX Facebook banner', 'Grafika'),
                    $pdf('2390788', 'Infografika 3M: Testowanie słuchu i kompatybilność', 'Ulotki'),
                    $pdf('1400002', 'Broszura półmasek wielokrotnego użytku.pdf', 'Broszury'),
                    // plik z kodem tej karty zostaje, choć wymienia też inne pozycje
                    $pdf('1400003', 'Instrukcja 3M 6000 – półmaski 6100, 6200, 6300', 'Instrukcje'),
                    $pdf('1400004', 'tds-vflex-9936-pl.pdf'),
                ],
            ],
            '7000001002' => [
                'mmm_id' => '7000001002',
                'name' => 'Aura 9936',
                'description' => 'Półmaska filtrująca FFP3.',
                'classified' => [],
                'media_links_images' => [],
                'media_links_documents' => [$pdf('1400001', 'Karta techniczna 3M Aura 9936')],
            ],
        ];
    }

    private function client(): MmmB2bClient
    {
        return new MmmB2bClient(self::EMAIL, self::PASSWORD, [], 0, static function (int $ms): void {});
    }

    private function connector(): MmmB2bConnector
    {
        $connector = new MmmB2bConnector(new MmmB2bClient(
            self::EMAIL, self::PASSWORD, ['cookies' => [self::cookie('JSESSIONID', 'live', 'order.3m.com')]], 0, static function (int $ms): void {},
        ));
        $connector->login();

        return $connector;
    }

    /**
     * @param  array{live?: bool, sso?: bool}  $session  [] = konto bez sesji
     */
    private function account(array $session): B2bAccount
    {
        $cookies = [];
        if (array_key_exists('live', $session)) {
            $cookies[] = self::cookie('JSESSIONID', $session['live'] ? 'live' : 'expired', 'order.3m.com');
        }
        if ($session['sso'] ?? false) {
            $cookies[] = self::cookie('x-ms-cpim-sso:mmmciam.onmicrosoft.com_0', 'alive', 'ciam.iamext.3m.com');
        }

        return B2bAccount::query()->create([
            'username' => self::EMAIL,
            'password' => self::PASSWORD,
            'sites' => ['https://order.3m.com/'],
            'connector' => '3m',
            'sync_images' => true,
            'connector_session' => $cookies === [] ? null : ['cookies' => $cookies, 'saved_at' => '2026-09-22T08:00:00+02:00'],
        ])->fresh() ?? throw new RuntimeException('konto');
    }

    /**
     * @return array<string, mixed>
     */
    private static function cookie(string $name, string $value, string $domain): array
    {
        return ['Name' => $name, 'Value' => $value, 'Domain' => $domain, 'Path' => '/', 'Max-Age' => null, 'Expires' => null, 'Secure' => true, 'Discard' => false, 'HttpOnly' => true, 'HostOnly' => true];
    }

    /**
     * @param  array<string, mixed>  $session
     */
    private static function cookieValue(array $session, string $name): ?string
    {
        foreach ((array) ($session['cookies'] ?? []) as $cookie) {
            if (($cookie['Name'] ?? null) === $name) {
                return (string) $cookie['Value'];
            }
        }

        return null;
    }

    /**
     * @return list<Request>
     */
    private function selfAssertedPosts(): array
    {
        return Http::recorded(fn (Request $r): bool => $r->method() === 'POST' && str_contains($r->url(), '/SelfAsserted'))
            ->map(static fn (array $pair): Request => $pair[0])->values()->all();
    }

    /**
     * @param  list<B2bRemoteProduct>  $products
     * @return array<string, B2bRemoteProduct>
     */
    private static function byId(array $products): array
    {
        $byId = [];
        foreach ($products as $product) {
            $byId[$product->remoteId] = $product;
        }

        return $byId;
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

    /**
     * Token wyszukiwarki wydany raz na test (ważny godzinę, jak na żywo). Liczony od nowa przy każdym zapytaniu
     * zmieniał się z sekundą zegara (exp), więc atrapa wyszukiwarki odrzucała token wydany sekundę wcześniej —
     * pod obciążeniem pakietu równoległego klient odświeżał token i pytał o kartę pdp drugi raz.
     */
    private function jwt(): string
    {
        $encode = static fn (array $data): string => rtrim(strtr(base64_encode((string) json_encode($data)), '+/', '-_'), '=');

        return $this->jwt ??= $encode(['alg' => 'HS256', 'typ' => 'JWT']).'.'
            .$encode(['exp' => time() + 3600, 'entitlementsPayload' => ['userId' => self::USER_ID, 'soldTo' => '16102804', 'salesDistrict' => '410027']])
            .'.c2lnbmF0dXJh';
    }

    /**
     * @return array<string, mixed>
     */
    private static function item(string $id, string $catalog, string $name, string $base, string $baseName, string $sales, string $salesName, string $conversion): array
    {
        return [
            'mmm_id' => ['value' => $id],
            'mmm_catalog_number' => ['value' => $catalog],
            'fml_mkpl_name' => ['value' => $name],
            'gtin_display' => ['value' => '0'.substr($id, 0, 13)],
            'legacy_mmm_id' => ['value' => ''],
            'main_image' => ['url' => self::MEDIA.'1000'.substr($id, -3).'J/lista.jpg'],
            'product_status' => ['id' => '10', 'value' => 'Aktywny'],
            'product_type' => ['id' => 'product.standard'],
            'salesUnit' => $sales,
            'baseUomCode' => $base,
            'orderUnits' => [
                ['orderUnitCode' => $sales, 'orderUnitName' => $salesName, 'defaultOrderUnit' => 'true', 'uomData' => ['conversion' => $conversion], 'moq' => '1', 'moi' => '1'],
                ['orderUnitCode' => $base, 'orderUnitName' => $baseName, 'defaultOrderUnit' => 'false', 'uomData' => ['conversion' => '1'], 'moq' => '1', 'moi' => '1'],
            ],
            'discontinued' => false,
            'isCanBuy' => true,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private static function price(string $value, string $list, string $per, string $minUnit = 'karton'): array
    {
        return [
            'isCanBuy' => true,
            'price' => ['listPrice' => $list, 'netPriceWithoutPromotion' => $value, 'pricePer' => $per, 'value' => $value, 'currency' => '', 'message' => ''],
            'minOrderQuantity' => ['unit' => $minUnit, 'value' => '1'],
        ];
    }

    /**
     * Wyroby w kolejności listy, każdy za szt: cena konta za szt i katalogowa 80,00 zł; cena null = sklep nie podaje ceny.
     *
     * @param  list<array{0: string, 1: string, 2: string, 3: string|null}>  $rows  numer magazynowy, numer katalogowy, nazwa, cena
     */
    private function colourCatalog(array $rows): void
    {
        foreach ($rows as [$id, $catalog, $name, $net]) {
            $this->items[] = self::item($id, $catalog, $name, 'EA', 'szt', 'CS', 'karton', '20');
            $this->prices[$id] = $net !== null
                ? self::price($net.' PLN / szt', '80,00 PLN / szt', '1 szt')
                : ['isCanBuy' => false, 'price' => ['value' => '', 'listPrice' => '', 'pricePer' => '', 'message' => 'Brak ceny']];
        }
    }

    /** Cztery wyroby (i jedna powtórka na liście): półmaska za szt, wkładki za parę, rozjazd jednostki, brak ceny. */
    private function catalog(): void
    {
        $mask = self::item('7000009701', '6200', 'Półmaska 3M 6200, rozmiar M', 'EA', 'szt', 'CS', 'karton', '24');
        $mask['gtin_display'] = ['value' => '04046719303420'];
        $mask['legacy_mmm_id'] = ['value' => '70-0710-2845-5'];
        $this->items = [
            $mask,
            self::item('7100100637', '1100', 'Wkładki przeciwhałasowe 3M 1100', 'PR', 'para', 'CS', 'karton', '1000'),
            self::item('7000034747', '501', 'Pokrywa filtra 3M 501', 'EA', 'szt', 'CS', 'karton', '64'),
            $mask,
            self::item('7100066103', '6035', 'Filtr 3M 6035', 'EA', 'szt', 'CS', 'karton', '32'),
        ];
        $this->listTotal = 4;
        $this->prices = [
            '7000009701' => self::price('24,54 PLN / szt', '49,08 PLN / szt', '1 szt'),
            '7100100637' => self::price('0,4196PLN', '0,8392 PLN / para', '1 para'),
            '7000034747' => self::price('1 570,56 PLN / karton', "3\u{00A0}141,12 PLN / karton", '1 karton'),
            '7100066103' => ['isCanBuy' => false, 'price' => ['value' => '', 'listPrice' => '', 'pricePer' => '', 'message' => 'Brak ceny']],
        ];
        $this->pdps = [
            '7000009701' => [
                'mmm_id' => '7000009701',
                'name' => 'Półmaska 3M 6200',
                'description' => 'Półmaska wielokrotnego użytku.',
                'long_description' => "Lekka konstrukcja.\nSpełnia wymagania normy EN 140:1998.",
                'benefits' => ['Niska waga', 'Prosty montaż filtrów'],
                'classified' => [
                    ['label' => 'Kolor produktu', 'type' => 'enum', 'value' => [['value' => 'Szary'], ['value' => 'Niebieski']]],
                    ['label' => 'Rozmiar', 'type' => 'text', 'value' => ['M']],
                    ['label' => 'Waga', 'type' => 'numeric', 'value' => [['uom_id' => 'g', 'value' => '82']]],
                    ['label' => 'Liczba w opakowaniu', 'type' => 'numeric', 'value' => ['24']],
                ],
                'common' => [['label' => 'Marka', 'value' => '3M™']],
                'breadcrumbs' => [['gph_id' => 'GPH10008', 'gph_name' => 'Środki ochrony indywidualnej']],
                'packagingIdentificationDetails' => [
                    ['label' => 'Kod kreskowy - Opakowanie detaliczne (szt)', 'value' => '04046719303420'],
                    ['label' => 'Kraj pochodzenia', 'value' => 'Wielka Brytania'],
                    ['label' => 'za karton', 'value' => '24 szt'],
                ],
                'media_links_images' => [
                    ['url' => self::MEDIA.'1287825J/polmaska-bok.jpg', 'url_pattern' => self::MEDIA.'1287825<R>/polmaska-bok.jpg', 'is_main_image' => false, 'mime_type' => 'image/tiff'],
                    ['url' => self::MEDIA.'1287824J/polmaska-front.jpg', 'url_pattern' => self::MEDIA.'1287824<R>/polmaska-front.jpg', 'is_main_image' => true, 'mime_type' => 'image/tiff'],
                    ['url' => 'https://example.com/obce.jpg', 'url_pattern' => 'https://example.com/<R>/obce.jpg', 'is_main_image' => false],
                    // jak na żywo: galeria 3M miesza zdjęcia z filmami — ani z typem wideo, ani z adresem .mp4 bez typu
                    ['url' => self::MEDIA.'1657267O/3m-davit-final-en-master-hd-720p.mp4?&fn=DAVIT2020_EN_MASTER_R1.mp4', 'url_pattern' => self::MEDIA.'1657267<R>/3m-davit-final-en-master-hd-720p.mp4', 'is_main_image' => false, 'mime_type' => 'video/mp4'],
                    ['url' => self::MEDIA.'1657268O/film-bez-typu.mp4', 'url_pattern' => self::MEDIA.'1657268<R>/film-bez-typu.mp4', 'is_main_image' => false],
                ],
                'media_links_documents' => [
                    ['url' => self::MEDIA.'1287001J/karta-6200.jpg', 'title' => 'Karta danych 6200.pdf', 'content_type' => 'Arkusze danych', 'mime_type' => 'application/pdf'],
                    ['url' => self::MEDIA.'1287009J/katalog-emea.jpg', 'title' => 'Katalog EMEA.pdf', 'content_type' => 'Katalogi', 'mime_type' => 'application/pdf'],
                    ['url' => self::MEDIA.'1287010J/przewodnik.jpg', 'title' => 'Przewodnik doboru.pdf', 'content_type' => 'Przewodniki\\wytyczne wyboru produktów', 'mime_type' => 'application/pdf'],
                    ['url' => self::MEDIA.'1287002J/ulotka.jpg', 'title' => 'Ulotka półmasek.pdf', 'content_type' => 'Ulotki', 'mime_type' => 'application/pdf'],
                    ['url' => self::MEDIA.'1287012J/fall-catalogue.jpg', 'title' => '3M-Fall-Protection-Product-Catalogue-EMEA-EN_R2.pdf', 'content_type' => 'Broszury', 'mime_type' => 'application/pdf'],
                    ['url' => self::MEDIA.'1287011J/film.jpg', 'title' => 'Film', 'content_type' => 'Wideo', 'mime_type' => 'video/mp4'],
                ],
            ],
            '7100100637' => [
                'mmm_id' => '7100100637',
                'name' => 'Wkładki 3M 1100',
                'description' => '',
                'long_description' => '',
                'classified' => [],
                'media_links_images' => [],
                'media_links_documents' => [],
            ],
        ];
    }

    /**
     * Wyszukiwarka jak na żywo: filtr pełnej ścieżki kategorii (pozycja w gałęzi, gdy jej _path zaczyna się od ścieżki)
     * i marki, licznik grupy, podkategorie następnego poziomu i marki w aggregations.sticky. Pozycje testowe niosą
     * _path (domyślnie ["GPH10008"]) i _brand (domyślnie „3M”).
     *
     * @param  array<string, mixed>  $body
     * @return array<string, mixed>
     */
    private function searchResponse(array $body): array
    {
        $filters = $body['sticky_filters'];
        $want = $filters['categories_path'];
        $brand = $filters['brand']['values'][0] ?? null;
        $inGroup = array_values(array_filter($this->items, static function (array $item) use ($want, $brand): bool {
            $path = $item['_path'] ?? ['GPH10008'];

            return array_slice($path, 0, count($want)) === $want && ($brand === null || ($item['_brand'] ?? '3M') === $brand);
        }));
        $unique = [];
        foreach ($inGroup as $item) {
            $unique[$item['mmm_id']['value']] = $item;
        }
        $categories = [];
        $brands = [];
        foreach ($unique as $item) {
            $next = ($item['_path'] ?? ['GPH10008'])[count($want)] ?? null;
            if ($next !== null) {
                $categories[$next] = ($categories[$next] ?? 0) + 1;
            }
            $b = $item['_brand'] ?? '3M';
            $brands[$b] = ($brands[$b] ?? 0) + 1;
        }
        foreach ($this->facetOverrides[implode('/', $want)] ?? [] as $id => $count) {
            $categories[$id] = $count;
        }

        $key = implode('/', $want).($brand !== null ? '|'.$brand : '');
        $start = (int) $body['start'];
        if ($start === 0 && (int) $body['size'] > 1) {
            $this->listPasses[$key] = ($this->listPasses[$key] ?? 0) + 1;
        }
        $page = $this->listPage !== null
            ? ($this->listPage)($key, $start, $this->listPasses[$key] ?? 1, $inGroup)
            : array_slice($inGroup, $start, (int) $body['size']);
        $total = $want === ['GPH10008'] && $brand === null && $this->listTotal !== null ? $this->listTotal : count($unique);

        return [
            'items' => $page,
            'total' => $total,
            'queryId' => 'q1',
            'aggregations' => ['sticky' => [
                'categories' => ['facets' => array_map(static fn (string $id, int $c): array => ['id' => $id, 'value' => 'Kategoria '.$id, 'count' => $c], array_keys($categories), $categories)],
                'brand' => ['facets' => array_map(static fn (string $v, int $c): array => ['value' => $v, 'count' => $c], array_keys($brands), $brands)],
            ]],
        ];
    }

    private function fakeSite(): void
    {
        $html = ['Content-Type' => 'text/html; charset=UTF-8'];
        $json = ['Content-Type' => 'application/json'];

        Http::fake(function (Request $request) use ($html, $json) {
            $host = (string) parse_url($request->url(), PHP_URL_HOST);
            $path = (string) parse_url($request->url(), PHP_URL_PATH);
            parse_str((string) parse_url($request->url(), PHP_URL_QUERY), $query);
            $cookies = self::requestCookies($request);

            // jak na żywo (22.09.2026): Akamai przytrzymuje zapytanie bez przeglądarkowego User-Agent — 0 bajtów
            // do przekroczenia czasu; ceny bez UA szły tak przez cały pierwszy przebieg
            if (str_ends_with($host, '3m.com') && ! str_contains($request->header('User-Agent')[0] ?? '', 'Mozilla/5.0')) {
                throw new ConnectException('Operation timed out after 30001 milliseconds with 0 bytes received', $request->toPsrRequest());
            }

            if ($host === 'order.3m.com') {
                $signedIn = in_array($cookies['JSESSIONID'] ?? '', $this->storeSessions, true);
                if ($path === '/store/user/login') {
                    return Http::response(self::storeLoginPage(), 200, [...$html, 'Set-Cookie' => 'JSESSIONID=guest; Path=/; Secure; HttpOnly']);
                }
                if ($path === '/mmmsinglesignon/saml2/authenticate/login' && $request->method() === 'POST') {
                    return Http::response(
                        '<html><body onload="document.forms[0].submit()"><form action="https://ciam.iamext.3m.com'.self::TENANT.'/samlp/sso/login" method="post">'
                        .'<input type="hidden" name="SAMLRequest" value="'.self::SAML_REQUEST.'"/><input type="hidden" name="RelayState" value="rs-1"/>'
                        .'<noscript><input type="submit" value="Continue"/></noscript></form></body></html>',
                        200,
                        $html,
                    );
                }
                if ($path === '/mmmsinglesignon/saml/SSO' && $request->method() === 'POST') {
                    if (($request->data()['SAMLResponse'] ?? '') !== self::SAML_RESPONSE || ($request->data()['RelayState'] ?? '') !== 'rs-1') {
                        return Http::response('bad saml', 400);
                    }
                    $this->storeLogins++;
                    $session = 'live'.$this->storeLogins;
                    $this->storeSessions[] = $session;

                    return Http::response('', 302, ['Location' => 'https://order.3m.com/store/', 'Set-Cookie' => 'JSESSIONID='.$session.'; Path=/; Secure; HttpOnly']);
                }
                if ($path === '/store/') {
                    return Http::response('<html><body>Sklep</body></html>', 200, $html);
                }
                if ($path === '/store/escatalog/GPH10008') {
                    return $signedIn
                        ? Http::response("<html><head><script>var currentUserJWT='".$this->jwt()."';</script></head><body><a href=\"/store/logout\">Wyloguj</a></body></html>", 200, $html)
                        : Http::response(self::storeLoginPage(), 200, $html);
                }
                if (str_ends_with($path, '/productdetails/productPrice')) {
                    if ($this->pricesUnauthorized) {
                        // sesja sklepu wygasła w trakcie przebiegu
                        $this->storeSessions = [];
                    }
                    if (! $signedIn || $this->pricesUnauthorized || ! str_contains($path, '/users/'.self::USER_ID.'/')) {
                        return Http::response(['errors' => [['type' => 'InvalidTokenError']]], 401, $json);
                    }
                    $asked = explode(',', (string) ($query['materialIDs'] ?? ''));
                    if (array_intersect($asked, $this->brokenPriceIds) !== []) {
                        // SAP 3M nie wycenia tej pozycji — cała paczka z nią kończy się błędem
                        return Http::response(['errors' => [['type' => 'SapError']]], 500, $json);
                    }
                    $products = [];
                    foreach ($asked as $id) {
                        if (isset($this->prices[$id])) {
                            $products[] = ['key' => $id, 'value' => $this->prices[$id]];
                        }
                    }

                    return Http::response(['products' => $products], 200, $json);
                }
            }

            if ($host === 'ciam.iamext.3m.com') {
                if ($path === self::TENANT.'/samlp/sso/login' && $request->method() === 'POST') {
                    if (($request->data()['SAMLRequest'] ?? '') !== self::SAML_REQUEST) {
                        return Http::response('bad request', 400);
                    }
                    if (in_array($cookies['x-ms-cpim-sso:mmmciam.onmicrosoft.com_0'] ?? '', $this->ssoSessions, true)) {
                        return Http::response(self::samlResponsePage(), 200, $html);
                    }
                    $this->stage = 'email';

                    return Http::response(self::b2cPage('CSRF-1', ['email' => '']), 200, [
                        ...$html, 'Set-Cookie' => 'x-ms-cpim-trans=T1; Path=/; Secure; HttpOnly',
                    ]);
                }
                if ($path === self::TENANT.'/SelfAsserted' && $request->method() === 'POST') {
                    return $this->selfAsserted($request, $query, $cookies, $json);
                }
                if ($path === self::TENANT.'/api/SelfAsserted/confirmed') {
                    if (($query['tx'] ?? '') !== self::TX || ($query['p'] ?? '') !== 'B2C_1A_3MSAMLB') {
                        return Http::response('bad tx', 400);
                    }

                    return match (true) {
                        $this->stage === 'password' => Http::response(self::b2cPage('CSRF-2', ['password' => '']), 200, $html),
                        $this->stage === 'verify' && $this->mfaRequired => Http::response(self::b2cPage(
                            'CSRF-3',
                            ['readOnlyEmail' => self::EMAIL],
                        ), 200, $html),
                        $this->stage === 'done' || ($this->stage === 'verify' && ! $this->mfaRequired) => Http::response(self::samlResponsePage(), 200, [
                            ...$html, 'Set-Cookie' => 'x-ms-cpim-sso:mmmciam.onmicrosoft.com_0=alive; Path=/; Secure; HttpOnly',
                        ]),
                        default => Http::response('unexpected', 400),
                    };
                }
            }

            if ($host === 'searchapi.3m.com') {
                if (($request->header('Authorization')[0] ?? '') !== 'Bearer '.$this->jwt()) {
                    return Http::response(['error' => 'Internal Server Error'], 500, $json);
                }
                $body = $request->data();
                if ($path === '/search/bcom/v1/search') {
                    return Http::response($this->searchResponse($body), 200, $json);
                }
                if ($path === '/search/bcom/v1/pdp') {
                    $pdp = $this->pdps[(string) $body['mmm_id']] ?? ['mmm_id' => (string) $body['mmm_id'], 'name' => 'x'];

                    return Http::response($pdp, 200, $json);
                }
            }

            if ($host === 'multimedia.3m.com') {
                if (str_ends_with($path, '.pdf')) {
                    return Http::response("%PDF-1.4\n".basename($path)."\n%%EOF", 200, ['Content-Type' => 'application/pdf']);
                }
                // jak na żywo (audyt 08.10.2026): zasób bez wersji graficznej — strona 404 albo szara ikona pliku
                // 240×175, obie z nagłówkiem obrazu i kodem 200
                if (str_contains($path, 'brak-zasobu')) {
                    return Http::response(self::RESOURCE_404, 200, ['Content-Type' => 'image/jpeg']);
                }
                if (str_contains($path, 'ikona-pliku')) {
                    return Http::response(self::jpeg($path, 240, 175), 200, ['Content-Type' => 'image/jpeg']);
                }

                return Http::response(self::jpeg($path), 200, ['Content-Type' => 'image/jpeg']);
            }

            return Http::response('not found', 404);
        });
    }

    /**
     * @param  array<string, mixed>  $query
     * @param  array<string, string>  $cookies
     * @param  array<string, string>  $json
     */
    private function selfAsserted(Request $request, array $query, array $cookies, array $json): mixed
    {
        if (($query['tx'] ?? '') !== self::TX || ($query['p'] ?? '') !== 'B2C_1A_3MSAMLB' || ($cookies['x-ms-cpim-trans'] ?? '') !== 'T1') {
            return Http::response(['status' => '400', 'message' => 'Sesja logowania wygasła.'], 400, $json);
        }
        $csrf = $request->header('X-CSRF-TOKEN')[0] ?? '';
        $data = $request->data();
        $type = $data['request_type'] ?? '';

        if ($type === 'RESPONSE' && isset($data['email']) && $this->stage === 'email' && $csrf === 'CSRF-1') {
            $this->stage = 'password';

            return Http::response(['status' => '200'], 200, $json);
        }
        if ($type === 'RESPONSE' && isset($data['password']) && $this->stage === 'password' && $csrf === 'CSRF-2') {
            if ($data['password'] !== self::PASSWORD) {
                return Http::response(['status' => '400', 'message' => 'Nieprawidłowe hasło.'], 200, $json);
            }
            $this->stage = 'verify';

            return Http::response(['status' => '200'], 200, $json);
        }
        if ($type === 'VERIFICATION_REQUEST' && $this->stage === 'verify' && $csrf === 'CSRF-3' && ($data['claim_value'] ?? '') === self::EMAIL) {
            $this->codeSent = true;

            return Http::response(['status' => '200', 'result' => 0], 200, $json);
        }
        if ($type === 'VALIDATION_REQUEST' && $this->stage === 'verify' && $csrf === 'CSRF-3') {
            return ($data['user_input'] ?? '') === self::CODE
                ? Http::response(['status' => '200', 'result' => 0], 200, $json)
                : Http::response(['status' => '400', 'result' => 3, 'message' => 'Kod weryfikacyjny jest nieprawidłowy.'], 200, $json);
        }
        if ($type === 'RESPONSE' && ($data['readOnlyEmail_ver_input'] ?? '') === self::CODE && $this->stage === 'verify') {
            $this->stage = 'done';

            return Http::response(['status' => '200'], 200, $json);
        }

        return Http::response(['status' => '400', 'message' => 'Nieoczekiwane zapytanie.'], 400, $json);
    }

    /**
     * @return array<string, string>
     */
    private static function requestCookies(Request $request): array
    {
        $cookies = [];
        foreach (explode(';', $request->header('Cookie')[0] ?? '') as $pair) {
            $parts = explode('=', trim($pair), 2);
            if (count($parts) === 2) {
                $cookies[$parts[0]] = $parts[1];
            }
        }

        return $cookies;
    }

    private static function storeLoginPage(): string
    {
        return '<html><body><form action="/mmmsinglesignon/saml2/authenticate/login" method="POST">'
            .'<button type="submit" class="btn btn-primary">Zaloguj się</button></form></body></html>';
    }

    /**
     * Strona B2C jak na żywo (22.09.2026): pól nie ma w HTML — opisuje je „var SA_FIELDS = {"AttributeFields":[…]}”,
     * a formularz składa skrypt w przeglądarce.
     *
     * @param  array<string, string>  $fields  ID pola → wartość wstępna (PRE)
     */
    private static function b2cPage(string $csrf, array $fields): string
    {
        $attributeFields = [];
        foreach ($fields as $id => $pre) {
            $attributeFields[] = ['UX_INPUT_TYPE' => 'TextBox', 'DN' => $id, 'ID' => $id, 'PRE' => $pre, 'VERIFY' => $id === 'readOnlyEmail'];
        }
        $saFields = json_encode(['AttributeFields' => $attributeFields], JSON_UNESCAPED_SLASHES);
        $settings = [
            'remoteResource' => 'https://ciam.iamext.3m.com/static/unified.html',
            'retryLimit' => 7,
            'api' => 'SelfAsserted',
            'csrf' => $csrf,
            'transId' => self::TX,
            'pageViewId' => 'pv-1',
            'hosts' => ['tenant' => self::TENANT, 'policy' => 'B2C_1A_3MSAMLB', 'static' => 'https://ciam.iamext.3m.com/static/'],
            'locale' => ['lang' => 'pl'],
        ];

        return "<!DOCTYPE html><html><head><title>Logowanie</title>\n"
            .'<script data-container="true" nonce="n1">var SA_FIELDS = '.$saFields.";\nvar CONTENT = {\"ver_sent\":\"Kod weryfikacyjny został wysłany do:\"};\n"
            .'var SETTINGS = '.json_encode($settings, JSON_UNESCAPED_SLASHES).";\n</script>\n"
            .'<script>var t=\'<input/>\';</script></head><body><div id="api"></div></body></html>';
    }

    private static function samlResponsePage(): string
    {
        return '<html><body onload="document.forms[0].submit()"><form method="POST" action="https://order.3m.com/mmmsinglesignon/saml/SSO">'
            .'<input type="hidden" name="SAMLResponse" value="'.self::SAML_RESPONSE.'"/><input type="hidden" name="RelayState" value="rs-1"/>'
            .'</form></body></html>';
    }

    private static function jpeg(string $path, int $width = 1, int $height = 1): string
    {
        $image = imagecreatetruecolor($width, $height);
        imagesetpixel($image, 0, 0, crc32($path) & 0xFFFFFF);
        ob_start();
        imagejpeg($image);

        return (string) ob_get_clean();
    }
}
