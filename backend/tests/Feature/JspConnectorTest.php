<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\B2bAccount;
use App\Models\B2bProductLink;
use App\Models\PriceList;
use App\Models\Product;
use App\Models\ProductPriceHistory;
use App\Services\B2b\B2bAccountSyncRunner;
use App\Services\B2b\B2bConnectorRegistry;
use App\Services\B2b\B2bFatalException;
use App\Services\B2b\B2bRemoteProduct;
use App\Services\B2b\JspB2bClient;
use App\Services\B2b\JspB2bConnector;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use RuntimeException;
use Tests\TestCase;

/**
 * Łącznik www.jspsafety.com na atrapie sklepu (Http::fake, bez prawdziwego logowania). Sesja konta = ciasteczko
 * .ASPXAUTH ustawione przy logowaniu i wysyłane przez CookieJar klienta; strony produktów mają „Logout Now”
 * tylko dla ważnej sesji. Kod spoza katalogu konta przekierowuje na stronę główną (jak w sklepie).
 */
final class JspConnectorTest extends TestCase
{
    use RefreshDatabase;

    /** 1×1 PNG */
    private const PNG = "\x89PNG\r\n\x1a\n\x00\x00\x00\rIHDR\x00\x00\x00\x01\x00\x00\x00\x01\x08\x06\x00\x00\x00\x1f\x15\xc4\x89\x00\x00\x00\rIDATx\x9cc\xf8\x0f\x00\x00\x01\x01\x00\x05\x18\xd8N\x00\x00\x00\x00IEND\xaeB`\x82";

    private const LOGGED_IN_BAR = '<div class="TopLinks"><a class="logout">Logout Now</a><div class="CreditBar">Overall Credit: (kwota usunięta)</div></div>';

    private const FAR_OG = 'https://www.jspsafety.com/netalogue/photos/far0701-main.jpg';

    private const FAR_URL = 'https://www.jspsafety.com/products/kw/a/FAR0701_2m-Webbing-Self-Retractable-lifeline-Vertical-Horizontal-FF2';

    /** @var array<string, string> kod bez myślników → HTML strony produktu */
    private array $pages = [];

    /** @var list<string> */
    private array $validSessions = [];

    private int $logins = 0;

    /** Pierwsze pobranie strony produktu unieważnia sesję (np. wygasła w nocy). */
    private bool $dropSessionOnce = false;

    /** Strony produktów nigdy nie mają „Logout Now”, choć logowanie się udaje. */
    private bool $pagesWithoutMarker = false;

    /** @var list<int> */
    private array $sleeps = [];

    protected function setUp(): void
    {
        parent::setUp();
        Queue::fake();

        $far = $this->fixture('product_far0701.html');
        $this->pages = [
            'FAR0701' => $far,
            '1LEOCARB23S' => $this->fixture('product_1leocarb23s.html'),
            // strona grupy wariantów: tytuł jest, kodu produktu i ceny brak
            'VARAKS270000100' => str_replace(
                '<div id="ctl00_ContentPlaceHolder1_ctl16_dvPartNo" class="ProductTitleBar_PartNo"> FAR0701 </div>', '', $far
            ),
            // AJF030000100 nie ma strony dla konta → przekierowanie na stronę główną
        ];
    }

    public function test_login_echoes_hidden_form_fields_and_sends_session_cookies_with_later_requests(): void
    {
        $this->fakeSite();
        $client = $this->client();

        $client->login();
        $page = $client->productPage('FAR0701');

        $this->assertTrue($client->isLoggedIn());
        $this->assertSame('ok', $page['status']);
        $post = Http::recorded(fn (Request $r): bool => $r->method() === 'POST')->first()[0];
        $this->assertSame('https://www.jspsafety.com/login.aspx', $post->url());
        $data = $post->data();
        $this->assertSame('VS-SYNTETYCZNY', $data['__VIEWSTATE']);
        $this->assertSame('C2EE9ABB', $data['__VIEWSTATEGENERATOR']);
        $this->assertSame('EV-SYNTETYCZNY', $data['__EVENTVALIDATION']);
        $this->assertSame('true', $data['ctl00$ContentPlaceHolder1$hdnDefaultLoginButtonEnabled']);
        $this->assertSame('jan', $data['ctl00$ContentPlaceHolder1$tbusername']);
        $this->assertSame('dobre-haslo', $data['ctl00$ContentPlaceHolder1$tbpassword']);
        $this->assertSame('Login to distributor account', $data['ctl00$ContentPlaceHolder1$blogin']);
        // inne przyciski formularza i pola z szablonów w skryptach nie są wysyłane
        $this->assertArrayNotHasKey('ctl00$ContentPlaceHolder1$bGoogleLogin', $data);
        $this->assertArrayNotHasKey('zeSkryptu', $data);
        $this->assertStringContainsString('ASP.NET_SessionId=anon1', $post->header('Cookie')[0] ?? '');

        $product = Http::recorded(fn (Request $r): bool => str_contains($r->url(), '/products/kw/a/FAR0701_'))->first()[0];
        $cookie = $product->header('Cookie')[0] ?? '';
        $this->assertStringContainsString('ASP.NET_SessionId=anon1', $cookie);
        $this->assertStringContainsString('__AntiXsrfToken=xsrf1', $cookie);
        $this->assertStringContainsString('.ASPXAUTH=auth1', $cookie);
    }

    public function test_login_without_logout_now_fails_with_clear_message(): void
    {
        $this->fakeSite();
        $client = new JspB2bClient('jan', 'zle-haslo', 0, fn (int $ms) => $this->sleeps[] = $ms);

        try {
            $client->login();
            $this->fail('Logowanie powinno się nie udać');
        } catch (RuntimeException $e) {
            $this->assertNotInstanceOf(B2bFatalException::class, $e);
            $this->assertStringStartsWith('Logowanie do jspsafety.com nieudane', $e->getMessage());
        }
        $this->assertFalse($client->isLoggedIn());
    }

    public function test_sitemap_keeps_standard_codes_without_bespoke_and_duplicates_and_url_drops_dashes(): void
    {
        $this->fakeSite();
        $client = $this->client();

        $codes = $client->sitemapProductCodes();

        $this->assertSame(['FAR0701', '1LEOCARB23S', 'AJF030-000-100', 'VAR-AKS270-000-100'], $codes);
        $this->assertSame('https://www.jspsafety.com/products/kw/a/AJF030000100_EVO3-Safety-Helmet', $client->productUrl('AJF030-000-100'));
        $this->assertSame(self::FAR_URL, $client->productUrl('FAR0701'));
    }

    public function test_price_is_account_price_in_euro_from_price_or_from_label_and_ignores_tiers(): void
    {
        $this->fakeSite();
        $connector = $this->connector();
        $connector->login();
        $products = $this->productsByCode($connector);

        $far = $connector->price($products['FAR0701']);
        $this->assertSame('From:', $products['FAR0701']->raw['price_prefix']);
        $this->assertSame(155.83, $far?->net);
        $this->assertNull($far?->base);
        $this->assertSame(0.0, $far?->discountPercent);
        $this->assertSame('EUR', $far?->currency);

        $leone = $connector->price($products['1LEOCARB23S']);
        $this->assertSame('Price:', $products['1LEOCARB23S']->raw['price_prefix']);
        $this->assertSame(7.74, $leone?->net);
        $this->assertNull($leone?->base);
        $this->assertSame('EUR', $leone?->currency);
        $this->assertSame(4, $connector->totalProducts());
    }

    public function test_non_empty_mrrp_becomes_catalog_price(): void
    {
        $this->pages['1LEOCARB23S'] = str_replace(
            '<div class="Prices_PriceMRRPText Prices_PriceMRRPText_NoStrike"></div>',
            '<div class="Prices_PriceMRRPText Prices_PriceMRRPText_NoStrike"> €9.99 </div>',
            $this->pages['1LEOCARB23S'],
        );
        $this->fakeSite();
        $connector = $this->connector();
        $connector->login();

        $price = $connector->price($this->productsByCode($connector)['1LEOCARB23S']);

        $this->assertSame(7.74, $price?->net);
        $this->assertSame(9.99, $price?->base);
    }

    public function test_code_redirected_to_home_page_is_unavailable_and_skipped_with_reason(): void
    {
        $this->fakeSite();
        $connector = $this->connector();
        $connector->login();
        $products = $this->productsByCode($connector);

        $unavailable = $products['AJF030-000-100'];
        $this->assertSame('skipped', $unavailable->raw['status']);
        Http::assertSent(static fn (Request $r): bool => $r->url() === 'https://www.jspsafety.com/products/kw/a/AJF030000100_EVO3-Safety-Helmet');
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('niedostępny w katalogu konta (sklep przekierował na stronę główną)');
        $connector->price($unavailable);
    }

    public function test_client_reports_redirect_to_home_page_as_unavailable(): void
    {
        $this->fakeSite();
        $client = $this->client();
        $client->login();

        $this->assertSame('unavailable', $client->productPage('AJF030-000-100')['status']);
        $this->assertSame('ok', $client->productPage('FAR0701')['status']);
    }

    public function test_page_showing_other_code_or_no_code_is_skipped(): void
    {
        $this->pages['1LEOCARB23S'] = $this->pages['FAR0701'];
        $this->fakeSite();
        $connector = $this->connector();
        $connector->login();
        $products = $this->productsByCode($connector);

        $this->assertSame('kod na stronie (FAR0701) inny niż kod z mapy strony (1LEOCARB23S)', $products['1LEOCARB23S']->raw['reason']);
        $this->assertSame('brak kodu produktu na stronie (np. strona grupy wariantów)', $products['VAR-AKS270-000-100']->raw['reason']);
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('kod na stronie (FAR0701) inny niż kod z mapy strony (1LEOCARB23S)');
        $connector->price($products['1LEOCARB23S']);
    }

    public function test_logged_in_page_without_price_block_is_skipped_as_no_price_without_stopping_sync(): void
    {
        Storage::fake('public');
        $this->pages['1LEOCARB23S'] = (string) preg_replace('#<!-- cena -->.*?<!-- /cena -->#s', '', $this->pages['1LEOCARB23S']);
        $this->fakeSite();

        $result = app(B2bAccountSyncRunner::class)->run($this->account(), delayMs: 0);

        $this->assertSame(1, $result['created']);
        $this->assertContains('1LEOCARB23S: brak ceny w B2B', $result['errors']);
        $this->assertFalse(Product::query()->where('sku', '1LEOCARB23S')->exists());
        $this->assertSame('ok', $this->account()->last_sync_status);
    }

    public function test_product_page_without_logout_now_logs_in_again_and_continues(): void
    {
        $this->dropSessionOnce = true;
        $this->fakeSite();
        $client = $this->client();
        $client->login();

        $page = $client->productPage('FAR0701');

        $this->assertSame(2, $this->logins);
        $this->assertSame('ok', $page['status']);
        $this->assertTrue(JspB2bClient::hasLoggedInMarker($page['html'] ?? ''));
    }

    public function test_product_page_still_without_logout_now_after_relogin_is_fatal(): void
    {
        $this->pagesWithoutMarker = true;
        $this->fakeSite();
        $client = $this->client();
        $client->login();

        try {
            $client->productPage('FAR0701');
            $this->fail('Oczekiwano B2bFatalException');
        } catch (B2bFatalException $e) {
            $this->assertStringStartsWith('Utracono sesję konta jspsafety.com', $e->getMessage());
        }
        $this->assertSame(2, $this->logins);
    }

    public function test_description_is_polish_feature_list_without_duplicates_plus_unit_and_category_from_breadcrumbs(): void
    {
        $this->fakeSite();
        $connector = $this->connector();
        $connector->login();
        $products = $this->productsByCode($connector);

        $far = $products['FAR0701'];
        $this->assertSame('FAR0701', $far->sku);
        $this->assertSame('FAR0701', $far->remoteId);
        $this->assertSame('Urządzenie samohamowne 2m - pionowe + poziome + FF2', $far->name);
        $this->assertSame('JSP', $connector->manufacturer($far));
        $this->assertSame(self::FAR_URL, $far->sourceUrl);
        // adres kw/a ma w okruszkach tylko „Search : a” — to nie kategoria
        $this->assertNull($far->category);
        $this->assertSame(implode("\n", [
            '- Wytrzymała i trwała obudowa z tworzywa sztucznego.',
            '- Taśma o szerokości 21 mm z amortyzatorem.',
            '- Zgodne z normą EN 360:2002, do pracy w poziomie (ostra krawędź CNB/P/11.060:2014) i przy współczynniku upadku 2 w granicy 100 kg.',
            '- 2 zatrzaśniki aluminiowe z blokadą ćwierćobrotową na obu końcach. Otwarcie zatrzaśnika: 21 mm. Zgodne z normą EN 362:2004 klasa B.',
            '',
            'Jednostka: sztuka',
        ]), $connector->description($far));

        $leone = $products['1LEOCARB23S'];
        $this->assertSame('1LEOCARB23S', $leone->sku);
        $this->assertSame('Okulary ochronne Leone™ Premium - soczewki przyciemniane K&N, oprawki w stylu karbonu', $leone->name);
        $this->assertSame('ŚOI › Ostatnia szansa na zakup › Ochrona oczu ostatniej szansy', $leone->category);
        // dokumenty (karty techniczne, deklaracje) nie są pobierane
        Http::assertNotSent(static fn (Request $r): bool => str_contains($r->url(), 'showfile.aspx'));
    }

    public function test_image_comes_from_og_image_as_public_url(): void
    {
        $this->fakeSite();
        $connector = $this->connector();
        $connector->login();
        $far = $this->productsByCode($connector)['FAR0701'];

        $image = $connector->image($far);

        $this->assertNotNull($image);
        $this->assertSame(self::PNG, $image->bytes);
        $this->assertSame('image/png', $image->mime);
        $this->assertSame(self::FAR_OG, $image->sourceUrl);
        $this->assertNull($connector->image(new B2bRemoteProduct('X', 'X', 'X', raw: ['status' => 'ok'])));
    }

    public function test_rate_limit_honours_retry_after_then_backoff_and_succeeds(): void
    {
        Http::fake(['www.jspsafety.com/*' => Http::sequence()
            ->push('', 429, ['Retry-After' => '7'])
            ->push('', 503)
            ->push($this->fixture('sitemap.xml'))]);

        $this->assertCount(4, $this->client()->sitemapProductCodes());
        $this->assertSame([7000, 10000], $this->sleeps);
    }

    public function test_twenty_consecutive_http_failures_are_fatal(): void
    {
        Http::fake(['*' => Http::response('', 500)]);
        $client = $this->client();
        for ($i = 1; $i < 20; $i++) {
            try {
                $client->sitemapProductCodes();
                $this->fail('Oczekiwano błędu HTTP');
            } catch (RuntimeException $e) {
                $this->assertNotInstanceOf(B2bFatalException::class, $e);
            }
        }
        $this->expectException(B2bFatalException::class);
        $client->sitemapProductCodes();
    }

    public function test_registry_detects_jsp_by_host(): void
    {
        $registry = app(B2bConnectorRegistry::class);

        $this->assertSame('jsp', $registry->keyForSites(['https://www.jspsafety.com/login.aspx']));
        $this->assertSame('JSP', $registry->label('jsp'));
        $account = B2bAccount::query()->create(['username' => 'jan', 'password' => 'sekret', 'sites' => ['www.jspsafety.com']]);
        $this->assertInstanceOf(JspB2bConnector::class, $registry->make($account, 0));
    }

    public function test_sync_through_runner_creates_jsp_card_with_account_price_description_link_image_and_history(): void
    {
        Storage::fake('public');
        $this->fakeSite();

        $result = app(B2bAccountSyncRunner::class)->run($this->account(), delayMs: 0);

        $this->assertSame(4, $result['total_remote']);
        $this->assertSame(4, $result['seen']);
        $this->assertSame(2, $result['created']);
        $this->assertSame(2, $result['skipped']);
        $this->assertSame(2, $result['images']);
        $this->assertContains('AJF030-000-100: niedostępny w katalogu konta (sklep przekierował na stronę główną)', $result['errors']);
        $this->assertContains('VAR-AKS270-000-100: brak kodu produktu na stronie (np. strona grupy wariantów)', $result['errors']);
        $this->assertFalse(Product::query()->whereIn('sku', ['AJF030-000-100', 'VAR-AKS270-000-100'])->exists());

        $product = Product::query()->where('sku', 'FAR0701')->sole();
        $this->assertSame('JSP', $product->manufacturer);
        $this->assertSame('Urządzenie samohamowne 2m - pionowe + poziome + FF2', $product->name);
        $this->assertSame('EUR', $product->currency);
        $this->assertSame('155.83', $product->purchase_price);
        $this->assertSame('155.83', $product->catalog_price_net);
        $this->assertSame('0.00', $product->discount_percent);
        $this->assertSame(self::FAR_URL, $product->shop_source_url);
        $this->assertStringContainsString('- Zgodne z normą EN 360:2002', (string) $product->description);
        $this->assertSame(self::FAR_OG, $product->images()->firstOrFail()->source_url);
        $this->assertSame('ŚOI › Ostatnia szansa na zakup › Ochrona oczu ostatniej szansy', Product::query()->where('sku', '1LEOCARB23S')->value('category'));

        $link = B2bProductLink::query()->where('remote_id', 'FAR0701')->sole();
        $this->assertSame($product->id, $link->product_id);
        $this->assertSame(sha1((string) $product->description), $link->description_hash);
        $this->assertTrue(ProductPriceHistory::query()
            ->where('product_id', $product->id)
            ->where('price_list_id', $result['price_list_id'])
            ->where('b2b_sync_run_id', $result['sync_run_id'])
            ->where('source', 'b2b:jsp')
            ->exists());
        $this->assertSame(1, PriceList::query()->count());
        $this->assertStringStartsWith('W B2B: 4 · sprawdzone: 4 · nowe: 2', (string) $this->account()->last_sync_message);
    }

    public function test_sync_command_prints_summary_for_product_connector(): void
    {
        $this->fakeSite();
        $account = $this->account();

        $this->artisan('b2b:sync', ['account' => $account->id, '--delay' => 0, '--no-images' => true, '--dry-run' => true])
            ->expectsOutputToContain('W B2B: 4 · sprawdzone: 4 · nowe: 2')
            ->assertSuccessful();
    }

    private function client(): JspB2bClient
    {
        return new JspB2bClient('jan', 'dobre-haslo', 0, function (int $ms): void {
            $this->sleeps[] = $ms;
        });
    }

    private function connector(): JspB2bConnector
    {
        return new JspB2bConnector($this->client());
    }

    private function account(): B2bAccount
    {
        return B2bAccount::query()->firstOrCreate(
            ['username' => 'jan'],
            ['password' => 'dobre-haslo', 'sites' => ['www.jspsafety.com'], 'connector' => 'jsp'],
        )->fresh();
    }

    /**
     * @return array<string, B2bRemoteProduct>
     */
    private function productsByCode(JspB2bConnector $connector): array
    {
        $out = [];
        foreach ($connector->products() as $product) {
            $out[$product->remoteId] = $product;
        }

        return $out;
    }

    private function fixture(string $name): string
    {
        return (string) file_get_contents(base_path('tests/Fixtures/jsp/'.$name));
    }

    private function fakeSite(): void
    {
        Http::fake(function (Request $request) {
            $url = $request->url();
            $path = (string) parse_url($url, PHP_URL_PATH);
            preg_match('/\.ASPXAUTH=([^;\s]+)/', $request->header('Cookie')[0] ?? '', $m);
            $loggedIn = isset($m[1]) && in_array($m[1], $this->validSessions, true);

            if ($path === '/login.aspx' && $request->method() === 'GET') {
                return Http::response($this->fixture('login.html'), 200, ['Set-Cookie' => [
                    'ASP.NET_SessionId=anon1; path=/; HttpOnly; SameSite=None; Secure',
                    '__AntiXsrfToken=xsrf1; path=/; HttpOnly; SameSite=None; Secure',
                ]]);
            }
            if ($path === '/login.aspx' && $request->method() === 'POST') {
                if (($request['ctl00$ContentPlaceHolder1$tbpassword'] ?? '') !== 'dobre-haslo'
                    || ($request['__VIEWSTATE'] ?? '') !== 'VS-SYNTETYCZNY'
                    || ($request['__EVENTVALIDATION'] ?? '') !== 'EV-SYNTETYCZNY') {
                    return Http::response($this->fixture('login.html'));
                }
                $this->logins++;
                $session = 'auth'.$this->logins;
                $this->validSessions[] = $session;

                return Http::response('', 302, [
                    'Location' => 'https://www.jspsafety.com/',
                    'Set-Cookie' => '.ASPXAUTH='.$session.'; path=/; HttpOnly; Secure',
                ]);
            }
            if ($path === '/') {
                return Http::response($this->withMarker($this->fixture('home.html'), $loggedIn));
            }
            if ($path === '/netalogue/sitemap.xml') {
                return Http::response($this->fixture('sitemap.xml'), 200, ['Content-Type' => 'text/xml']);
            }
            if (str_starts_with($path, '/products/kw/a/')) {
                if ($this->dropSessionOnce) {
                    $this->dropSessionOnce = false;
                    $this->validSessions = [];
                    $loggedIn = false;
                }
                $last = basename($path);
                $key = strtoupper(substr($last, 0, (int) strpos($last, '_')));
                if (! isset($this->pages[$key])) {
                    return Http::response('', 302, ['Location' => 'https://www.jspsafety.com/']);
                }

                return Http::response($this->withMarker($this->pages[$key], $loggedIn && ! $this->pagesWithoutMarker));
            }
            if (str_starts_with($path, '/netalogue/photos/')) {
                return Http::response(self::PNG, 200, ['Content-Type' => 'image/png']);
            }

            return Http::response('nieznany adres w teście: '.$url, 404);
        });
    }

    private function withMarker(string $html, bool $loggedIn): string
    {
        return $loggedIn ? str_replace('<body>', '<body>'.self::LOGGED_IN_BAR, $html) : $html;
    }
}
