<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\B2bAccount;
use App\Models\B2bProductLink;
use App\Models\Product;
use App\Models\ProductDocument;
use App\Models\ProductIdentifier;
use App\Models\ProductSourcePrice;
use App\Models\ProductVariant;
use App\Services\B2b\B2bAccountSyncRunner;
use App\Services\B2b\B2bCodeLoginSite;
use App\Services\B2b\B2bConnectorRegistry;
use App\Services\B2b\B2bFatalException;
use App\Services\B2b\B2bRemoteProduct;
use App\Services\B2b\MsaB2bClient;
use App\Services\B2b\MsaB2bConnector;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use RuntimeException;
use Tests\TestCase;

/**
 * Łącznik pl.msasafety.com (SAP Commerce) na atrapie sklepu (Http::fake). Sprawdzone na żywej witrynie 01.10.2026:
 * formularz /login z _requestConfirmationToken, POST /j_spring_security_check → /choose-b2b-unit →
 * /additionalStepLogin (formularz #otpForm z zamaskowanym e-mailem; nagłówek strony już z „Wyloguj”), mapa strony
 * (indeks /sitemap.xml → podmapa /medias/Product-pl-…xml), znaczniki strony wyrobu (okruszki, h1.product-name, opis,
 * „Cechy” z blokiem filmu, „Specyfikacje”, lista „Part Number(s)” z „ILOŚĆ min”, „Przyrosty ILOŚCI”, „/szt”,
 * „Wstrzymane”, #pricing-config-variables), lista plików /bynder/search (JSON) i /bynder/asset_download (adres
 * podpisany na assetlibrary). Odpowiedź na kod (JSON „verified”) i ceny /sap/retrieveAllProductPricing.json
 * (value, formattedValue, formattedListValue, currencyIso, kluczem numer części) — wg skryptu strony msa-r22.js;
 * do potwierdzenia na koncie po pierwszym „Zaloguj kodem”.
 *
 * Wszystkie dane (numery, ceny, nazwy, e-maile) są SYNTETYCZNE.
 */
final class MsaConnectorTest extends TestCase
{
    use RefreshDatabase;

    private const USER = 'zakupy@example.test';

    private const PASSWORD = 'dobre-haslo';

    /** Kod z e-maila MSA ma litery i cyfry. */
    private const CODE = 'aB3dE9';

    private const DEVICE = 'msaDeviceTest';

    private const PAGE_HELMET = 'https://pl.msasafety.com/Ochrona-g%C5%82owy/He%C5%82my/Kask-TEST-500/p/000060009900001000?locale=pl';

    private const PAGE_EMPTY = 'https://pl.msasafety.com/Analiza-spalania/Analizatory/Analizator-TEST/p/PCTEST?locale=pl';

    /** @var list<string> ważne sesje sklepu (JSESSIONID) */
    private array $sessions = [];

    /** Sesje po haśle, przed kodem — sklep przekierowuje je na prośbę o kod. */
    private array $pendingOtp = [];

    private int $logins = 0;

    private int $codesSent = 0;

    /** Czy logowanie hasłem z ciasteczkiem urządzenia omija kod. */
    private bool $deviceSkipsOtp = true;

    /** @var array<string, array<string, mixed>> numer części → wpis ceny; brak = sklep nie zwraca wpisu */
    private array $prices = [];

    /** Strona wyrobu jak dla gościa (data-permission="-1") niezależnie od sesji. */
    private bool $pagesAsGuest = false;

    /** @var list<array<string, mixed>> */
    private array $priceRequests = [];

    /** @var list<array<string, mixed>>|null numery części strony kasku (null = domyślne) */
    private ?array $helmetParts = null;

    protected function setUp(): void
    {
        parent::setUp();
        Queue::fake();
        $this->prices = [
            'GV449-0000000-TST' => self::price(51.2, 64.0),
            'GV429-0000000-TST' => self::price(49.9, 64.0),
            'GV412-0000000-TST' => self::price(55.0, 55.0),
        ];
    }

    public function test_without_a_session_login_is_fatal_and_sends_no_request(): void
    {
        $this->fakeSite();
        $account = B2bAccount::query()->create(['username' => self::USER, 'password' => self::PASSWORD, 'sites' => ['https://pl.msasafety.com/login']]);

        try {
            MsaB2bConnector::forAccount($account, 0)->login();
            $this->fail('bez sesji logowanie powinno prosić o kod');
        } catch (B2bFatalException $e) {
            $this->assertStringContainsString('Zaloguj kodem', $e->getMessage());
        }
        $this->assertCount(0, Http::recorded());
    }

    public function test_code_login_posts_the_form_then_the_code_and_remembers_the_device_cookie(): void
    {
        $this->fakeSite();
        $client = $this->client([]);

        $started = $client->startCodeLogin();

        $this->assertSame(1, $this->codesSent);
        $this->assertStringContainsString('za***@example.test', $started['message']);
        $this->assertSame('za***@example.test', $started['state']['email']);
        $this->assertSame('otp-token', $started['state']['token']);
        $this->assertArrayNotHasKey('password', $started['state']);
        $login = Http::recorded(fn (Request $r): bool => str_ends_with($r->url(), '/j_spring_security_check'))->first()[0];
        $this->assertSame(['j_username' => self::USER, 'j_password' => self::PASSWORD, '_requestConfirmationToken' => 'login-token'], $login->data());

        $session = $this->client([])->finishCodeLogin(json_decode((string) json_encode($started['state']), true), ' '.self::CODE.' ');

        $verify = Http::recorded(fn (Request $r): bool => str_ends_with($r->url(), '/additionalStepLogin/verify'))->first()[0];
        $this->assertSame(
            ['otpCode' => self::CODE, 'email' => 'za***@example.test', 'rememberme' => 'true', '_rememberme' => 'on', '_requestConfirmationToken' => 'otp-token'],
            $verify->data(),
        );
        $this->assertSame([self::DEVICE], $session['device_cookies']);
        $this->assertNotNull(self::cookieValue($session, 'JSESSIONID'));
    }

    public function test_wrong_code_is_an_error_for_the_user_not_a_fatal_one(): void
    {
        $this->fakeSite();
        $started = $this->client([])->startCodeLogin();

        try {
            $this->client([])->finishCodeLogin($started['state'], '999999');
            $this->fail('zły kod powinien być błędem');
        } catch (B2bFatalException $e) {
            $this->fail('zły kod to nie błąd krytyczny: '.$e->getMessage());
        } catch (RuntimeException $e) {
            $this->assertStringContainsString('Kod nieprawidłowy', $e->getMessage());
        }
    }

    public function test_code_with_characters_other_than_letters_and_digits_is_rejected_without_a_request(): void
    {
        $this->fakeSite();
        $started = $this->client([])->startCodeLogin();
        $before = count(Http::recorded());

        foreach (['', 'aB3-E9', 'aB3 E9', 'kód123'] as $code) {
            try {
                $this->client([])->finishCodeLogin($started['state'], $code);
                $this->fail('kod „'.$code.'” powinien być odrzucony');
            } catch (RuntimeException $e) {
                $this->assertStringContainsString('litery i cyfry', $e->getMessage());
            }
        }
        $this->assertCount($before, Http::recorded());
    }

    public function test_live_session_needs_no_password(): void
    {
        $this->fakeSite();
        $client = $this->client($this->sessionFor(live: true, device: false));

        $client->login();

        $this->assertTrue($client->isLoggedIn());
        $this->assertCount(0, Http::recorded(fn (Request $r): bool => $r->method() === 'POST'));
    }

    public function test_expired_session_with_a_remembered_device_logs_in_with_the_password_without_a_code(): void
    {
        $this->fakeSite();
        $client = $this->client($this->sessionFor(live: false, device: true));

        $client->login();

        $this->assertTrue($client->isLoggedIn());
        $this->assertSame(1, $client->logins());
        $this->assertSame(0, $this->codesSent);
    }

    public function test_expired_session_without_a_device_cookie_never_posts_the_password(): void
    {
        $this->fakeSite();

        try {
            $this->client($this->sessionFor(live: false, device: false))->login();
            $this->fail('wygasła sesja bez ciasteczka urządzenia = prośba o kod');
        } catch (B2bFatalException $e) {
            $this->assertStringContainsString('Zaloguj kodem', $e->getMessage());
        }
        $this->assertCount(0, Http::recorded(fn (Request $r): bool => $r->method() === 'POST'));
        $this->assertSame(0, $this->codesSent);
    }

    public function test_code_asked_despite_the_device_cookie_is_fatal(): void
    {
        $this->fakeSite();
        $this->deviceSkipsOtp = false;

        try {
            $this->client($this->sessionFor(live: false, device: true))->login();
            $this->fail('prośba o kod w przebiegu = błąd krytyczny');
        } catch (B2bFatalException $e) {
            $this->assertStringContainsString('Zaloguj kodem', $e->getMessage());
        }
    }

    public function test_colour_variant_key_needs_exactly_one_colour_segment(): void
    {
        $this->assertSame(
            ['key' => 'v-gard 500, helmet, vented, *, fas-trac iii foam', 'colour' => 'yellow hi-viz', 'stem' => 'V-Gard 500, Helmet, vented, Fas-Trac III Foam'],
            MsaB2bConnector::colourVariantKey('V-Gard 500, Helmet, vented, yellow hi-viz,, Fas-Trac III Foam'),
        );
        $this->assertSame('green', MsaB2bConnector::colourVariantKey('V-Gard 500, Helmet, vented,green,Fas-Trac III Foam')['colour'] ?? null);
        // kolor wewnątrz członu („…500 orange”) i opisy bez koloru — bez łączenia
        $this->assertNull(MsaB2bConnector::colourVariantKey('Forestry Kit, V-Gard 500 orange'));
        $this->assertNull(MsaB2bConnector::colourVariantKey('ALTAIR CO 100/300 ppm'));
        $this->assertNull(MsaB2bConnector::colourVariantKey('Helmet, white, red stripes, red'));
    }

    public function test_untranslated_unit_key_is_no_unit(): void
    {
        $page = MsaB2bConnector::parsePage('<div id="pdp-redesign-flyout-partnums"><div class="pdp-redesign-partnums--partnum "> <div class="pdp-redesign-partnum--desc--mobile"> <a class="pdp-redesign-partnum--partnum" href="/x/pn/10000001"> 10000001 </a> <div> Cartridge TEST </div> </div>'
            .' <div class="pdp-redesign-partnum--price"> <div class="hidden"> <span class="pdp-redesign-partnum--unit"> &nbsp;/code.uom. </span> </div> </div> </div></div>');

        $this->assertSame([['10000001', 'Cartridge TEST', '']], array_map(static fn (array $p): array => [$p['part'], $p['description'], $p['unit']], $page['parts']));
    }

    public function test_amount_from_price_text(): void
    {
        $this->assertSame(1234.56, MsaB2bConnector::amountFromText('1 234,56 zł'));
        $this->assertSame(1234.56, MsaB2bConnector::amountFromText('PLN 1,234.56'));
        $this->assertSame(12.5, MsaB2bConnector::amountFromText("12,50\u{00A0}zł"));
        $this->assertSame(1000.0, MsaB2bConnector::amountFromText('1.000 zł'));
        $this->assertNull(MsaB2bConnector::amountFromText('brak'));
    }

    public function test_products_are_part_numbers_with_colours_on_one_card_prices_order_quantity_and_files(): void
    {
        $this->fakeSite();
        $connector = $this->connector($this->sessionFor(live: true, device: true));

        $products = iterator_to_array($connector->products(), false);

        // numery bez ceny idą przed kartami strony (pominięte z powodem)
        $this->assertSame(['GV499-0000000-TST', 'GV429-0000000-TST', 'GV412-0000000-TST'], array_map(static fn (B2bRemoteProduct $p): string => $p->remoteId, $products));
        $colours = $products[1];
        $this->assertSame('Kask TEST 500 – V-Gard 500, Helmet, vented,yellow, Fas-Trac III Foam', $colours->name);
        $this->assertSame('Kask TEST 500 – V-Gard 500, Helmet, vented, Fas-Trac III Foam', $colours->cardName);
        $this->assertSame('Ochrona głowy > Hełmy', $colours->category);
        $this->assertSame(self::PAGE_HELMET, $colours->sourceUrl);
        $this->assertSame('Kolory: green (GV449-0000000-TST); yellow (GV429-0000000-TST)', $colours->variantSummary);
        $this->assertSame(
            [['GV449-0000000-TST', 'green', '51.20', '64.00'], ['GV429-0000000-TST', 'yellow', '49.90', '64.00']],
            array_map(static fn (array $m): array => [$m['remote_id'], $m['size'], sprintf('%.2F', $m['price']->net), sprintf('%.2F', $m['price']->base)], $colours->members),
        );
        $price = $connector->price($colours);
        $this->assertSame([49.9, 64.0, 22.03, 'PLN'], [$price->net, $price->base, $price->discountPercent, $price->currency]);
        $this->assertSame([20.0, 20.0, 'szt'], [$price->order?->min, $price->order?->step, $price->order?->unit]);
        $this->assertSame('MSA', $connector->manufacturer($colours));
        $this->assertSame(
            [[ProductIdentifier::TYPE_MANUFACTURER_CODE, 'GV449-0000000-TST', 'GV449-0000000-TST', 'green'], [ProductIdentifier::TYPE_MANUFACTURER_CODE, 'GV429-0000000-TST', 'GV429-0000000-TST', 'yellow']],
            array_map(static fn ($i): array => [$i->type, $i->value, $i->remoteId, $i->label], $colours->identifiers ?? []),
        );
        // opis: wstęp i „Cechy” bez bloku filmu
        $this->assertSame(
            "Hełm TEST z tworzywa ABS.\nCechy:\n- Skorupa ABS\n- Opcjonalna wentylacja",
            $connector->description($colours),
        );
        $this->assertSame(
            [
                'Wyrób | Kask TEST 500',
                'Numer części | GV429-0000000-TST: V-Gard 500, Helmet, vented,yellow, Fas-Trac III Foam',
                'Numer części | GV449-0000000-TST: V-Gard 500, Helmet, vented, green, Fas-Trac III Foam',
                'Materiał | ABS',
                'Rynki | Budowa; Przemysł ogólny',
                'Jednostka | szt',
                'ILOŚĆ min | 20',
                'Przyrosty ILOŚCI | 20',
            ],
            array_map(static fn ($f): string => $f->name.' | '.$f->value, $connector->shopFields($colours)),
        );
        $this->assertSame(
            [
                'https://assetlibrary.msasafety.com/transform/0000000b-0000-0000-0000-000000000000/gv429-0000000-tst',
                'https://assetlibrary.msasafety.com/transform/0000000a-0000-0000-0000-000000000000/gv449-0000000-tst',
                'https://assetlibrary.msasafety.com/transform/00000001-0000-0000-0000-000000000000/KaskTEST500_000060009900001000_PL',
            ],
            $connector->imageUrls($colours),
        );
        $this->assertSame(
            [
                ['EU (DoC) — 06_Kask-TEST_DoC_EU_Rev01.pdf', MsaB2bClient::ASSET_DOWNLOAD.'AAAA0001-TEST', ProductDocument::KIND_CERTIFICATE],
                ['Datasheet: Kask TEST', MsaB2bClient::ASSET_DOWNLOAD.'AAAA0006-TEST', ProductDocument::KIND_DATASHEET],
            ],
            array_map(static fn ($d): array => [$d->title, $d->sourceUrl, $d->kind], $connector->documents($colours)),
        );

        // cena katalogowa = cena konta; numer bez koloru w parze — osobna karta
        $single = $products[2];
        $this->assertSame([], $single->members);
        $this->assertNull($single->variantSummary);
        $this->assertSame([55.0, 55.0, 0.0], [$connector->price($single)->net, $connector->price($single)->base, $connector->price($single)->discountPercent]);
        $this->assertSame(
            [[ProductIdentifier::TYPE_MANUFACTURER_CODE, 'GV412-0000000-TST', null, null]],
            array_map(static fn ($i): array => [$i->type, $i->value, $i->remoteId, $i->label], $single->identifiers ?? []),
        );

        // numer bez ceny konta — pominięty z powodem
        try {
            $connector->price($products[0]);
            $this->fail('numer bez ceny powinien być pominięty');
        } catch (RuntimeException $e) {
            $this->assertSame('sklep nie zwrócił ceny', $e->getMessage());
        }

        // jedna porcja cen (data-batchsize 10), bez numeru „Wstrzymane”
        $this->assertCount(1, $this->priceRequests);
        $this->assertSame('ptoken', $this->priceRequests[0]['_requestConfirmationToken']);
        $this->assertSame(
            [
                ['productCode' => '000060009900001000', 'partNumber' => 'GV449-0000000-TST', 'quantityRequested' => 20],
                ['productCode' => '000060009900001000', 'partNumber' => 'GV429-0000000-TST', 'quantityRequested' => 20],
                ['productCode' => '000060009900001000', 'partNumber' => 'GV412-0000000-TST', 'quantityRequested' => 20],
                ['productCode' => '000060009900001000', 'partNumber' => 'GV499-0000000-TST', 'quantityRequested' => 20],
            ],
            json_decode((string) $this->priceRequests[0]['priceRequestJson'], true)['priceForms'],
        );

        $summary = implode("\n", $connector->runSummary());
        $this->assertStringContainsString('Lista MSA (mapa strony): 2 stron wyrobów', $summary);
        $this->assertStringContainsString('Karty: 2 (w tym 1 z kolorami z 2 numerów części)', $summary);
        $this->assertStringContainsString('Bez ceny konta — sklep nie zwrócił ceny: 1, np. GV499-0000000-TST', $summary);
        $this->assertStringContainsString('Numery „Wstrzymane” (pominięte): 1, np. GV469-0017004-TST', $summary);
        $this->assertStringContainsString('Strony bez numerów części (bez kart): 1, np. PCTEST Analizator TEST', $summary);
        $this->assertSame(3, $connector->totalProducts());
    }

    public function test_page_without_account_prices_after_logging_in_again_stops_the_run(): void
    {
        $this->fakeSite();
        $this->pagesAsGuest = true;
        $connector = $this->connector($this->sessionFor(live: true, device: true));

        $this->expectException(B2bFatalException::class);
        $this->expectExceptionMessage('bez cen konta');
        iterator_to_array($connector->products(), false);
    }

    public function test_many_part_numbers_without_any_account_price_stop_the_run(): void
    {
        $this->fakeSite();
        $this->prices = [];
        $connector = $this->connector($this->sessionFor(live: true, device: true));
        $many = [];
        for ($i = 0; $i < 45; $i++) {
            $many[] = ['part' => sprintf('10%06d', $i), 'description' => 'ALTAIR TEST '.$i, 'min' => null];
        }
        $this->helmetParts = $many;

        $this->expectException(B2bFatalException::class);
        $this->expectExceptionMessage('bez żadnej ceny konta');
        iterator_to_array($connector->products(), false);
    }

    public function test_bynder_file_is_downloaded_through_the_signed_address(): void
    {
        $this->fakeSite();

        $file = $this->client([])->fileBytes(MsaB2bClient::ASSET_DOWNLOAD.'AAAA0001-TEST');

        $this->assertSame('application/pdf', $file['mime']);
        $this->assertStringStartsWith('%PDF-', $file['bytes']);
    }

    public function test_sync_creates_cards_with_colours_prices_files_and_a_second_run_changes_nothing(): void
    {
        Storage::fake('public');
        Storage::fake('local');
        $this->fakeSite();
        $account = $this->account();

        $result = app(B2bAccountSyncRunner::class)->run($account, delayMs: 0, withImages: true, connector: $this->connector($this->sessionFor(live: true, device: true), $account));

        $this->assertSame(2, $result['created'], implode(' | ', $result['errors']));
        $card = Product::query()->where('sku', 'GV429-0000000-TST')->sole();
        $this->assertSame('MSA', $card->manufacturer);
        $this->assertSame('Kask TEST 500 – V-Gard 500, Helmet, vented, Fas-Trac III Foam', $card->name);
        $this->assertStringContainsString('Skorupa ABS', (string) $card->description);
        $this->assertSame(['GV429-0000000-TST', 'GV449-0000000-TST'], B2bProductLink::query()->where('product_id', $card->id)->orderBy('remote_id')->pluck('remote_id')->all());
        $slot = ProductSourcePrice::query()->where('product_id', $card->id)->sole();
        $this->assertSame(['49.90', '64.00', '51.20'], [(string) $slot->purchase_price, (string) $slot->catalog_price_net, (string) $slot->size_price_max]);
        $this->assertSame(['20', '20', 'szt'], [(string) $slot->order_min_qty, (string) $slot->order_step_qty, $slot->order_unit]);
        $this->assertSame(
            [['green', '51.20'], ['yellow', '49.90']],
            ProductVariant::query()->where('product_id', $card->id)->where('kind', ProductVariant::KIND_SIZE)->orderBy('label')->get()
                ->map(static fn (ProductVariant $v): array => [$v->label, (string) $v->purchase_price])->all(),
        );
        $this->assertSame(2, ProductDocument::query()->where('product_id', $card->id)->count());
        $this->assertNotNull($account->fresh()?->connector_session_saved_at);

        $second = app(B2bAccountSyncRunner::class)->run($account->fresh(), delayMs: 0, withImages: true, connector: $this->connector($this->sessionFor(live: true, device: true), $account->fresh()));

        $this->assertSame(0, $second['created'], implode(' | ', $second['errors']));
        $this->assertSame(0, $second['updated'], implode(' | ', $second['errors']));
    }

    public function test_registry_detects_msa_by_the_login_page_as_a_manufacturer_site_with_code_login(): void
    {
        $registry = app(B2bConnectorRegistry::class);

        $this->assertSame('msa', $registry->keyForSites(['https://pl.msasafety.com/login']));
        $this->assertSame('MSA', $registry->label('msa'));
        $this->assertTrue($registry->isManufacturerSite('msa'));
        $this->assertTrue($registry->requiresLoginCode('msa'));
        $this->assertTrue($registry->sendsSizePrices('msa'));
        $this->assertFalse($registry->groupsSizes('msa'));
        $this->assertTrue(B2bConnectorRegistry::isConnectorUrl(self::PAGE_HELMET));
        $this->assertFalse(B2bConnectorRegistry::isConnectorUrl('https://us.msasafety.com/p/000060002100001000'));
        $account = B2bAccount::query()->create(['username' => self::USER, 'password' => 'sekret', 'sites' => ['https://pl.msasafety.com/login']]);
        $this->assertInstanceOf(B2bCodeLoginSite::class, $registry->make($account, 0));
    }

    private function client(array $session): MsaB2bClient
    {
        return new MsaB2bClient(self::USER, self::PASSWORD, $session, 0, static function (int $ms): void {});
    }

    private function connector(array $session, ?B2bAccount $account = null): MsaB2bConnector
    {
        return new MsaB2bConnector($this->client($session), $account);
    }

    private function account(): B2bAccount
    {
        return B2bAccount::query()->firstOrCreate(
            ['username' => self::USER],
            ['password' => self::PASSWORD, 'sites' => ['https://pl.msasafety.com/login'], 'connector' => 'msa', 'sync_images' => true],
        )->fresh();
    }

    /**
     * Sesja jak z konta: ciasteczko sklepu (żywe albo wygasłe po stronie sklepu) i ewentualnie ciasteczko urządzenia.
     *
     * @return array<string, mixed>
     */
    private function sessionFor(bool $live, bool $device): array
    {
        $id = $live ? 'live-session' : 'dead-session';
        if ($live) {
            $this->sessions[] = $id;
        }
        $cookies = [['Name' => 'JSESSIONID', 'Value' => $id, 'Domain' => 'pl.msasafety.com', 'Path' => '/', 'Max-Age' => null, 'Expires' => null, 'Secure' => true, 'Discard' => false, 'HttpOnly' => true, 'HostOnly' => true]];
        if ($device) {
            $cookies[] = ['Name' => self::DEVICE, 'Value' => 'dev1', 'Domain' => 'pl.msasafety.com', 'Path' => '/', 'Max-Age' => null, 'Expires' => time() + 20 * 86400, 'Secure' => true, 'Discard' => false, 'HttpOnly' => true, 'HostOnly' => true];
        }

        return ['cookies' => $cookies, 'device_cookies' => $device ? [self::DEVICE] : [], 'saved_at' => date(DATE_ATOM)];
    }

    private function fakeSite(): void
    {
        Http::fake(function (Request $request) {
            $url = $request->url();
            $path = (string) parse_url($url, PHP_URL_PATH);
            $cookies = implode('; ', $request->header('Cookie'));
            preg_match('/(?:^|;\s*)JSESSIONID=([\w-]+)/', $cookies, $m);
            $session = $m[1] ?? '';
            $signedIn = in_array($session, $this->sessions, true);
            $otpPending = in_array($session, $this->pendingOtp, true);
            $hasDevice = str_contains($cookies, self::DEVICE.'=');
            $html = ['Content-Type' => 'text/html;charset=UTF-8'];

            if ($path === '/login') {
                return Http::response(self::shell('<form id="loginForm" action="/j_spring_security_check" method="post"><input id="j_username" name="j_username" type="text" value=""/><input id="j_password" name="j_password" type="password" value=""/><input type="hidden" name="_requestConfirmationToken" value="login-token"/></form>', false), 200, $html + ['Set-Cookie' => ['JSESSIONID=guest; Path=/; Secure; HttpOnly', 'wcid=w1; Path=/; Max-Age=31536000; HttpOnly; Secure']]);
            }
            if ($path === '/j_spring_security_check' && $request->method() === 'POST') {
                $data = $request->data();
                if (($data['j_username'] ?? null) !== self::USER || ($data['j_password'] ?? null) !== self::PASSWORD || ($data['_requestConfirmationToken'] ?? null) !== 'login-token') {
                    return Http::response('', 302, ['Location' => 'https://pl.msasafety.com/login?error=true']);
                }
                $this->logins++;
                $id = 's'.$this->logins;
                if ($hasDevice && $this->deviceSkipsOtp) {
                    $this->sessions[] = $id;

                    return Http::response('', 302, ['Location' => 'https://pl.msasafety.com/my-account/home', 'Set-Cookie' => 'JSESSIONID='.$id.'; Path=/; Secure; HttpOnly']);
                }
                $this->pendingOtp[] = $id;

                return Http::response('', 302, ['Location' => 'https://pl.msasafety.com/choose-b2b-unit', 'Set-Cookie' => 'JSESSIONID='.$id.'; Path=/; Secure; HttpOnly']);
            }
            if ($path === '/choose-b2b-unit') {
                return Http::response('', 302, ['Location' => 'https://pl.msasafety.com/additionalStepLogin']);
            }
            if ($path === '/additionalStepLogin') {
                $this->codesSent++;

                return Http::response(self::shell('<form id="otpForm" action="/additionalStepLogin/verify" method="post"><input id="otpCode" name="otpCode" type="password" value=""/> <input id="email" name="email" value="za***@example.test" type="hidden" value=""/><input id="j_rememberme" name="rememberme" type="checkbox" value="true"/><input type="hidden" name="_rememberme" value="on"/> <input type="hidden" name="_requestConfirmationToken" value="otp-token"/></form>', true), 200, $html);
            }
            if ($path === '/additionalStepLogin/verify' && $request->method() === 'POST') {
                $data = $request->data();
                if (! $otpPending || ($data['otpCode'] ?? null) !== self::CODE) {
                    return Http::response(['verified' => '0', 'errorMessage' => 'Nieprawidłowy kod'], 200);
                }
                $this->sessions[] = $session;
                $expires = gmdate('D, d M Y H:i:s', time() + 30 * 86400).' GMT';

                // „wcid” (rok) sklep odnawia przy każdej odpowiedzi — to nie ciasteczko urządzenia
                return Http::response(['verified' => '1'], 200, ['Set-Cookie' => [self::DEVICE.'=dev-new; Path=/; Expires='.$expires.'; Secure; HttpOnly', 'wcid=w2; Path=/; Max-Age=31536000; HttpOnly; Secure']]);
            }
            if ($path === '/my-account/home') {
                if ($signedIn) {
                    return Http::response(self::shell('<h1>Moje konto</h1>', true), 200, $html);
                }

                return $otpPending
                    ? Http::response('', 302, ['Location' => 'https://pl.msasafety.com/additionalStepLogin'])
                    : Http::response('', 302, ['Location' => 'https://pl.msasafety.com/login']);
            }
            if ($path === '/sitemap.xml') {
                return Http::response('<?xml version="1.0" encoding="UTF-8"?> <sitemapindex xmlns="http://www.sitemaps.org/schemas/sitemap/0.9"> <sitemap> <loc>https://pl.msasafety.com/medias/Product-en-USD-111.xml?context=bWFzdGVy</loc> </sitemap> <sitemap> <loc>https://pl.msasafety.com/medias/Product-pl-USD-222.xml?context=bWFzdGVy</loc> </sitemap> </sitemapindex>', 200, ['Content-Type' => 'text/xml']);
            }
            if ($path === '/medias/Product-pl-USD-222.xml') {
                return Http::response('<?xml version="1.0" encoding="UTF-8"?><urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9"><url><loc>'.htmlspecialchars(self::PAGE_HELMET).'</loc></url><url><loc>'.htmlspecialchars(self::PAGE_EMPTY).'</loc></url></urlset>', 200, ['Content-Type' => 'text/xml']);
            }
            if (str_ends_with($path, '/p/000060009900001000') || str_ends_with($path, '/p/PCTEST')) {
                $guest = ! $signedIn || $this->pagesAsGuest;
                if ($otpPending && ! $signedIn) {
                    return Http::response('', 302, ['Location' => 'https://pl.msasafety.com/additionalStepLogin']);
                }

                return Http::response(str_ends_with($path, '/p/PCTEST') ? $this->emptyPage($guest) : $this->helmetPage($guest), 200, $html);
            }
            if ($path === '/sap/retrieveAllProductPricing.json' && $request->method() === 'POST') {
                $data = $request->data();
                $this->priceRequests[] = $data;
                $out = [];
                foreach (json_decode((string) ($data['priceRequestJson'] ?? '[]'), true)['priceForms'] ?? [] as $form) {
                    if ($signedIn && isset($this->prices[$form['partNumber']])) {
                        $out[$form['partNumber']] = $this->prices[$form['partNumber']] + ['materialNumber' => $form['partNumber']];
                    }
                }

                return Http::response($out === [] ? '{}' : json_encode($out), 200, ['Content-Type' => 'application/json;charset=UTF-8']);
            }
            if ($path === '/bynder/search/000060009900001000') {
                return Http::response(json_encode(self::assets()), 200, ['Content-Type' => 'application/json;charset=UTF-8']);
            }
            if (str_starts_with($path, '/bynder/asset_download/')) {
                // jak na żywo: tekst z podpisanym adresem pliku (ważnym ograniczony czas)
                return Http::response('https://assetlibrary.msasafety.com/files/'.basename($path).'?account_id=X&expiry=1790925791094&signature=abc%3D%3D&version=a0b1', 200, ['Content-Type' => 'text/plain;charset=ISO-8859-1']);
            }
            if (str_starts_with($url, 'https://assetlibrary.msasafety.com/files/')) {
                return Http::response('%PDF-1.6 '.basename($path), 200, ['Content-Type' => 'application/pdf']);
            }
            if (str_starts_with($url, 'https://assetlibrary.msasafety.com/transform/')) {
                return Http::response(base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mP8z8BQDwAEhQGAhKmMIQAAAABJRU5ErkJggg=='), 200, ['Content-Type' => 'image/jpeg']);
            }

            return Http::response('nie ma', 404, $html);
        });
    }

    /** Strona sklepu: nagłówek z „Wyloguj” dla sesji konta (także przed kodem — jak w sklepie). */
    private static function shell(string $main, bool $signedIn): string
    {
        $menu = $signedIn ? '<a class="dropdown-item" href="/my-account/quickOrder">Formularz zamówienia</a> <a class="dropdown-item" href="/logout"> Wyloguj </a>' : '<a href="/login">Zaloguj</a>';

        return '<!doctype html><html lang="pl" class="no-js lang-pl"><head><meta charset="utf-8"><title>MSA Safety | Poland</title></head><body class="page-productDetails">'
            .'<header class="main-header"><nav>'.$menu.'</nav></header><main id="main" tabindex="-1">'.$main.'</main><footer>MSA</footer></body></html>';
    }

    private function helmetPage(bool $guest): string
    {
        $parts = $this->helmetParts ?? [
            ['part' => 'GV449-0000000-TST', 'description' => 'V-Gard 500, Helmet, vented, green, Fas-Trac III Foam', 'min' => 20, 'image' => 'partnumthumb/0000000a-0000-0000-0000-000000000000/gv449-0000000-tst'],
            ['part' => 'GV429-0000000-TST', 'description' => 'V-Gard 500, Helmet, vented,yellow, Fas-Trac III Foam', 'min' => 20, 'image' => 'partnumthumb/0000000b-0000-0000-0000-000000000000/gv429-0000000-tst'],
            ['part' => 'GV412-0000000-TST', 'description' => 'V-Gard 500, Helmet, vented, white, Fas-Trac III PVC', 'min' => 20, 'image' => null],
            ['part' => 'GV469-0017004-TST', 'description' => 'Forestry Kit, V-Gard 500 orange', 'min' => 20, 'discontinued' => true],
            ['part' => 'GV499-0000000-TST', 'description' => 'V-Gard 500, Helmet, vented, orange hi-viz, Fas-Trac III Foam', 'min' => 20],
        ];
        $rows = '';
        foreach ($parts as $p) {
            $link = '/Ochrona-g%C5%82owy/He%C5%82my/Kask-TEST-500/pn/'.$p['part'];
            $image = ($p['image'] ?? null) !== null
                ? 'https://assetlibrary.msasafety.com/transform/'.$p['image']
                : 'https://s7d9.scene7.com/is/image/minesafetyappliances/'.$p['part'].'?$Part%20Number%20Thumbnail$';
            $discontinued = ($p['discontinued'] ?? false) ? ' <div class="discontinued-product"> <span>Wstrzymane</span> </div>' : '';
            $minimum = $p['min'] !== null ? '<div class="pdp-redesign-partnum--qty--minimum pdp-redesign-partnum--qty--minimum--mobile"> <div>ILOŚĆ min:&nbsp;'.$p['min'].'</div> <div>Przyrosty ILOŚCI:&nbsp;'.$p['min'].'</div> </div>' : '';
            $rows .= '<div class="pdp-redesign-partnums--partnum redesign-price-loading " data-searchtext="'.$p['part'].' '.htmlspecialchars($p['description']).'">'
                .' <div class="pdp-redesign-partnum--image "> <img class="variantImage" src="'.htmlspecialchars($image).'" data-fallback="/images/no-image-placeholder.jpg" alt="'.$p['part'].'"/> </div>'
                .' <div class="pdp-redesign-partnum--desc--mobile"> <a class="pdp-redesign-partnum--partnum" href="'.$link.'"> '.$p['part'].' </a> <div> '.htmlspecialchars($p['description']).' </div>'.$discontinued.' </div>'
                .' <div class="pdp-redesign-partnum--info"> <div class="pdp-redesign-partnum--desc--desktop"> <a class="pdp-redesign-partnum--partnum" href="'.$link.'"> '.$p['part'].' </a> <div> '.htmlspecialchars($p['description']).' </div> </div>'
                .' <div class="pdp-redesign-partnum--price"> <div class="pdp-redesign-partnum--price--ipi hidden"> <span></span> </div> <div class="hidden"> <span class="pdp-redesign-partnum--unit"> &nbsp;/szt </span> </div> </div> </div>'
                .' '.$minimum.' </div>';
        }

        return self::shell(
            '<div class="pdp-page pdp-redesign-page container"> <section id="breadcrumb" class="breadcrumb"> <a href="/">Strona główna&nbsp;</a> /&nbsp;<a href="/Ochrona-g%C5%82owy/c/112"><span class="">Ochrona głowy</span></a> /&nbsp;<a href="/Ochrona-g%C5%82owy/He%C5%82my/c/11204"><span class="">Hełmy</span></a> /&nbsp;<a href="/Ochrona-g%C5%82owy/He%C5%82my/Kask-TEST-500/p/000060009900001000"><span class="active">Kask TEST 500</span></a> </section>'
            .' <div class="product-name mt-4 h1"> Kask TEST 500</div> <h1 class="product-name mt-0 d-lg-block d-none"> Kask TEST 500</h1>'
            .' <div class="description-body"> <div class="pdp-redesign-description--body"> <p>Hełm TEST z tworzywa ABS.</p> </div> </div>'
            .' <div class="pdp-redesign-info-left"> <div> <img class="safe-image" src="https://assetlibrary.msasafety.com/transform/catalogthumb/00000001-0000-0000-0000-000000000000/KaskTEST500_000060009900001000_PL" alt="Kask TEST 500"/> </div> </div>'
            .' <div class="pdp-redesign--section pdp-redesign--section--highlights"> <div class="pdp-redesign--section-heading"> Cechy</div> <div class="pdp-redesign--section-content"> <section class="container py-5 r22-bg-light-grey"> <p class="text-center mt-0">The TEST Series is proudly manufactured in Europe.</p> <div bvd-manifest-url="https://assetlibrary.msasafety.com/vod-stream/x/play-dash.mpd"></div> </section></div> <div class="pdp-redesign--section-content"> <ul> <li>Skorupa ABS</li> <li>Opcjonalna wentylacja</li></ul></div> </div>'
            .' <div class="pdp-redesign--section pdp-redesign--section--specifications"> <div class="pdp-redesign--section-heading"> Specyfikacje </div> <div> <div class="pdp-redesign-table">'
            .' <div class="pdp-redesign-table-column"> <div class="pdp-redesign-table-row"> <div> <strong> Materiał </strong> </div> <div> <div> </div> <div> ABS </div> </div> </div> </div>'
            .' <div class="pdp-redesign-table-column"> <div class="pdp-redesign-table-row"> <div> <strong> Rynki </strong> </div> <div> <div> </div> <div> Budowa </div> <div> Przemysł ogólny </div> </div> </div> </div>'
            .' </div> </div> </div>'
            .' <div class="generic-flyout" id="pdp-redesign-flyout-partnums"> <div class="generic-flyout--body"> <div class="pdp-redesign-partnums--head"> <div class="pdp-redesign-partnums--title"> Part Number(s) </div> </div>'
            .' <div class="pdp-redesign-partnums--partnums"> <div class="pdp-redesign-partnums--group-header"> Kask TEST 500 </div> '.$rows.' </div>'
            .' <div id="pricing-config-variables" class="hidden" data-batchsize="10" data-batchmode="true" data-csrftoken="ptoken" data-permission="'.($guest ? '-1' : '0').'" data-showlistprice="'.($guest ? 'false' : 'true').'" data-showyourprice="'.($guest ? 'false' : 'true').'" data-pricingurl="/sap/retrieveProductPricing.json" data-batchpricingurl="/sap/retrieveAllProductPricing.json" data-productcode="000060009900001000"></div> </div> </div> </div>',
            ! $guest || $this->pagesAsGuest,
        );
    }

    private function emptyPage(bool $guest): string
    {
        return self::shell(
            '<div class="pdp-page"> <section id="breadcrumb" class="breadcrumb"> <a href="/">Strona główna&nbsp;</a> /&nbsp;<a href="/Analiza-spalania/c/1"><span>Analiza spalania</span></a> /&nbsp;<a href="/x/p/PCTEST"><span class="active">Analizator TEST</span></a> </section>'
            .' <h1 class="product-name mt-0 d-lg-block d-none"> Analizator TEST</h1> <div class="pdp-redesign-description--body"><p>Analizator TEST.</p></div>'
            .' <div class="generic-flyout" id="pdp-redesign-flyout-partnums"> <div class="pdp-redesign-partnums--partnums"> </div>'
            .' <div id="pricing-config-variables" class="hidden" data-batchsize="10" data-csrftoken="ptoken" data-permission="'.($guest ? '-1' : '0').'" data-productcode="PCTEST"></div> </div> </div>',
            ! $guest || $this->pagesAsGuest,
        );
    }

    /**
     * Lista Bynder jak na żywo (01.10.2026): aprobaty z zakresem i regionem, literatura i instrukcje z listą języków.
     *
     * @return list<array<string, mixed>>
     */
    private static function assets(): array
    {
        $asset = static fn (string $id, bool $approval, string $name, string $file, string $type, string $lang, string $region, string $scope = '', string $size = '300.5'): array => [
            'documentMetadataObject' => ['region' => $region, 'language' => $lang, 'document_type' => $type, 'objectname' => $name, 'standards' => '', 'productname' => '', 'baseproducts' => '000060009900001000', 'scope' => $scope],
            'productCode' => '000060009900001000', 'id' => $id, 'filename' => $file, 'name' => $name, 'filetype' => 'Document', 'description' => '',
            'filesize' => $size, 'hiResURLRaw' => null, 'status' => 'active', 'approvalDocument' => $approval,
        ];

        return [
            $asset('AAAA0002-TEST', false, 'Flyer: Kask TEST', '06_Kask-TEST_Flyer_EN.pdf', 'Literature', 'EN', 'Europe'),
            $asset('AAAA0003-TEST', false, 'Manual: Kask TEST', '06_Kask-TEST_Manual.pdf', 'Manuals', 'BG, CZ, DE, EN, PL, SK', 'Europe, Middle_East_and_India'),
            $asset('AAAA0001-TEST', true, '06_Kask-TEST_DoC_EU_Rev01.pdf', '06_Kask-TEST_DoC_EU_Rev01.pdf', 'Approvals', 'EN', 'Europe', 'EU (DoC)'),
            $asset('AAAA0004-TEST', true, 'ANSI: Kask TEST', '06_Kask-TEST_CERT_ANSI_EN.pdf', 'Approvals', 'EN', 'North_America', 'US (ANSI)'),
            $asset('AAAA0006-TEST', false, 'Datasheet: Kask TEST', '06_Kask-TEST_Datasheet_PL.pdf', 'Literature', 'EN, PL', 'Europe'),
            $asset('AAAA0005-TEST', false, 'Datasheet: Kask TEST duża', '06_Kask-TEST_Book.pdf', 'Literature', 'EN, PL', 'Europe', '', '13471.94'),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private static function price(float $net, float $list): array
    {
        $format = static fn (float $v): string => number_format($v, 2, ',', ' ')."\u{00A0}zł";

        return ['value' => $net, 'formattedValue' => $format($net), 'formattedListValue' => $format($list), 'currencyIso' => 'PLN'];
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
}
