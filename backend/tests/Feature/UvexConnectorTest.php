<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\B2bAccount;
use App\Models\B2bProductLink;
use App\Models\B2bSyncRun;
use App\Models\Product;
use App\Models\ProductDocument;
use App\Models\ProductSourcePrice;
use App\Services\B2b\B2bAccountSyncRunner;
use App\Services\B2b\B2bConnectorRegistry;
use App\Services\B2b\B2bFatalException;
use App\Services\B2b\B2bRemoteProduct;
use App\Services\B2b\UvexB2bClient;
use App\Services\B2b\UvexB2bConnector;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use RuntimeException;
use Tests\TestCase;

/**
 * Łącznik izam.system-b2b.pl (UVEX) na atrapie sklepu (Http::fake, bez prawdziwego logowania). Znaczniki listy, wiersza
 * i strony produktu z tests/Fixtures/uvex (skrócone strony konta z 15.09.2026, bez danych osobowych). Sesja =
 * ciasteczko b2b_session ustawione przy logowaniu; bez ważnej sesji sklep przekierowuje na /public/login.
 * Pozycje listy to prawdziwe kody, nazwy i ceny; dostępność części pozycji zmieniona na potrzeby testów.
 */
final class UvexConnectorTest extends TestCase
{
    use RefreshDatabase;

    /** 1×1 PNG */
    private const PNG = "\x89PNG\r\n\x1a\n\x00\x00\x00\rIHDR\x00\x00\x00\x01\x00\x00\x00\x01\x08\x06\x00\x00\x00\x1f\x15\xc4\x89\x00\x00\x00\rIDATx\x9cc\xf8\x0f\x00\x00\x01\x01\x00\x05\x18\xd8N\x00\x00\x00\x00IEND\xaeB`\x82";

    private const IMG_9970 = 'https://izam.system-b2b.pl/public/get-preview/product_images/40/408ACDA208BDCAB5C69A726ED5D5F989AECA8874FF618C9D87E81DE857F63480.jpg';

    /** @var list<array{id: string, code: string, name: string, price: string|null, avail: string, unit: string, img: string}> */
    private array $rows = [];

    private int $pageSize = 5;

    /** @var array<string, string> id pozycji → HTML strony produktu */
    private array $details = [];

    /** @var list<string> */
    private array $validSessions = [];

    /** Adresy pobranych plików produktu (karty techniczne, instrukcje) — po jednym wpisie na pobranie. */
    private array $fileHits = [];

    /** Ile razy atrapa wydala stronę produktu (opis i pliki mają czytać jedno pobranie). */
    private int $detailHits = 0;

    private int $logins = 0;

    private bool $dropSessionOnce = false;

    private bool $pagesWithoutSession = false;

    /** Ile kolejnych pobrań strony 2 listy ma podać inną liczbę produktów (produkt dodany w trakcie). */
    private int $changedTotalOnPage2 = 0;

    /** @var list<int> */
    private array $sleeps = [];

    protected function setUp(): void
    {
        parent::setUp();
        Queue::fake();

        $img = static fn (string $hash): string => 'https://izam.system-b2b.pl/public/get-preview/product_images/'.substr($hash, 0, 2).'/'.$hash.'.jpg';
        $this->rows = [
            ['id' => '7677', 'code' => '000P1D011003', 'name' => 'Szyba chroniąca przed laserem 000P1D011003 wymiary: 6 x 915 x 610 mm', 'price' => '1 133,05 PLN', 'avail' => 'Na zamówienie', 'unit' => 'szt.', 'img' => $img('C6E146C233C39E64')],
            ['id' => '5496', 'code' => '6935/2/38', 'name' => 'Trzewik uvex 2 trend 6935/2/38', 'price' => '358,70 PLN', 'avail' => 'Dostępny', 'unit' => 'par.', 'img' => $img('FD2AAC9F35323FC4')],
            ['id' => '5497', 'code' => '6935/2/39', 'name' => 'Trzewik uvex 2 trend 6935/2/39', 'price' => '360,40 PLN', 'avail' => 'Dostępny', 'unit' => 'par.', 'img' => $img('FD2AAC9F35323FC4')],
            ['id' => '5498', 'code' => '6935/2/40', 'name' => 'Trzewik uvex 2 trend 6935/2/40', 'price' => '360,40 PLN', 'avail' => 'Na zamówienie', 'unit' => 'par.', 'img' => $img('FD2AAC9F35323FC4')],
            ['id' => '6687', 'code' => '8430/2/39', 'name' => 'Półbuty ochronne uvex 1 business 8430/2/39', 'price' => '226,80 PLN', 'avail' => 'Dostępny', 'unit' => 'par.', 'img' => $img('39CB311772DB67F5')],
            ['id' => '6679', 'code' => '8430/2/40', 'name' => 'Półbuty ochronne uvex 1 business 8430/2/40', 'price' => '226,80 PLN', 'avail' => 'Na zamówienie', 'unit' => 'par.', 'img' => $img('39CB311772DB67F5')],
            ['id' => '6688', 'code' => '8430/2/41', 'name' => 'Półbuty ochronne uvex 1 business 8430/2/41', 'price' => '226,80 PLN', 'avail' => 'Na zamówienie', 'unit' => 'par.', 'img' => $img('39CB311772DB67F5')],
            ['id' => '4713', 'code' => '9970.005', 'name' => 'Pojemnik mini na środki czyszczące - pełny 9970.005', 'price' => '182,00 PLN', 'avail' => 'Dostępny', 'unit' => 'szt.', 'img' => self::IMG_9970],
            ['id' => '2180', 'code' => 'CENNIKI', 'name' => 'Cenniki', 'price' => '0,00 PLN', 'avail' => 'Na zamówienie', 'unit' => 'szt.', 'img' => 'https://izam.system-b2b.pl/public/assets/images/blank.jpg'],
            ['id' => '2401', 'code' => 'HA2023(L)', 'name' => 'Rękawice HexArmor Rig Lizard Arctic 2023 rozmiar 9 (L)', 'price' => '189,00 PLN', 'avail' => 'Na zamówienie', 'unit' => 'par.', 'img' => $img('4EDCED7C43AB9C31')],
            ['id' => '3123', 'code' => 'HA2023(M)', 'name' => 'Rękawice HexArmor Rig Lizard Arctic 2023 rozmiar 8 (M)', 'price' => '189,00 PLN', 'avail' => 'Na zamówienie', 'unit' => 'par.', 'img' => $img('4EDCED7C43AB9C31')],
            ['id' => '3337', 'code' => 'HECKEL6273/3/36', 'name' => 'HECKEL 6273/3/36 SUXXED OFFROAD HIGH S3', 'price' => '216,75 PLN', 'avail' => 'Na zamówienie', 'unit' => 'par.', 'img' => $img('710E2E36CA86EFFE')],
            ['id' => '3338', 'code' => 'HECKEL6273/3/37', 'name' => 'HECKEL 6273/3/37 SUXXED OFFROAD HIGH S3', 'price' => '216,75 PLN', 'avail' => 'Na zamówienie', 'unit' => 'par.', 'img' => $img('710E2E36CA86EFFE')],
        ];
        $this->details = ['4713' => $this->fixture('product_9970005.html')];
    }

    public function test_login_posts_contractor_code_person_password_and_token_and_keeps_session_cookie(): void
    {
        $this->fakeSite();
        $client = $this->client();

        $client->login();
        $client->listPage(1);

        $this->assertTrue($client->isLoggedIn());
        $post = Http::recorded(fn (Request $r): bool => $r->method() === 'POST')->first()[0];
        $this->assertSame('https://izam.system-b2b.pl/public/login', $post->url());
        $this->assertSame([
            '_token' => 'TOKEN-LOGOWANIA-SYNTETYCZNY',
            'contractor_code' => 'K123',
            'name' => 'jan',
            'password' => 'dobre-haslo',
        ], $post->data());
        $this->assertStringContainsString('b2b_session=guest', $post->header('Cookie')[0] ?? '');
        $list = Http::recorded(fn (Request $r): bool => str_contains($r->url(), '/public/product?'))->first()[0];
        $this->assertSame('https://izam.system-b2b.pl/public/product?query=%25&sort=code%2Basc&page=1', $list->url());
        $this->assertStringContainsString('b2b_session=sess1', $list->header('Cookie')[0] ?? '');
    }

    public function test_login_without_contractor_code_fails_before_any_request(): void
    {
        Http::fake();
        $client = new UvexB2bClient('  ', 'jan', 'dobre-haslo', 0);

        try {
            $client->login();
            $this->fail('Logowanie powinno się nie udać');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString('konto nie ma kodu kontrahenta', $e->getMessage());
        }
        Http::assertNothingSent();
    }

    public function test_wrong_password_fails_with_clear_message_and_is_not_fatal(): void
    {
        $this->fakeSite();
        $client = new UvexB2bClient('K123', 'jan', 'zle-haslo', 0, fn (int $ms) => $this->sleeps[] = $ms);

        try {
            $client->login();
            $this->fail('Logowanie powinno się nie udać');
        } catch (RuntimeException $e) {
            $this->assertNotInstanceOf(B2bFatalException::class, $e);
            $this->assertStringStartsWith('Logowanie do izam.system-b2b.pl nieudane', $e->getMessage());
        }
        $this->assertFalse($client->isLoggedIn());
    }

    public function test_products_read_all_pages_and_merge_same_price_sizes_into_one_card(): void
    {
        $this->fakeSite();
        $connector = $this->connector();
        $messages = [];
        $connector->onListProgress(function (string $message) use (&$messages): void {
            $messages[] = $message;
        });
        $connector->login();

        $products = $this->productsByCode($connector);

        $this->assertSame(['000P1D011003', '6935/2/38', '6935/2/39', '8430/2/39', '9970.005', 'CENNIKI', 'HA2023(L)', 'HECKEL6273/3/36'], array_keys($products));
        $this->assertSame(8, $connector->totalProducts());
        foreach ([1, 2, 3] as $page) {
            Http::assertSent(static fn (Request $r): bool => $r->url() === 'https://izam.system-b2b.pl/public/product?query=%25&sort=code%2Basc&page='.$page);
        }
        Http::assertNotSent(static fn (Request $r): bool => str_ends_with($r->url(), 'page=4'));
        $this->assertContains('Lista produktów UVEX: 13 pozycji na 3 stronach', $messages);
        $this->assertContains('Lista UVEX: 13 pozycji → 8 kart (4 grup rozmiarów o tej samej cenie)', $messages);

        $shoes = $products['8430/2/39'];
        $this->assertSame('8430/2/39', $shoes->sku);
        $this->assertSame('Półbuty ochronne uvex 1 business 8430/2', $shoes->name);
        $this->assertSame('Dostępny: 39; Na zamówienie: 40, 41', $shoes->availability);
        $this->assertSame('Rozmiary: 39 (8430/2/39); 40 (8430/2/40); 41 (8430/2/41)', $shoes->variantSummary);
        $this->assertSame([
            ['remote_id' => '8430/2/39', 'sku' => '8430/2/39', 'name' => 'Półbuty ochronne uvex 1 business 8430/2/39'],
            ['remote_id' => '8430/2/40', 'sku' => '8430/2/40', 'name' => 'Półbuty ochronne uvex 1 business 8430/2/40'],
            ['remote_id' => '8430/2/41', 'sku' => '8430/2/41', 'name' => 'Półbuty ochronne uvex 1 business 8430/2/41'],
        ], $shoes->members);
        $this->assertStringStartsWith('https://izam.system-b2b.pl/public/product-details/', (string) $shoes->sourceUrl);
        $this->assertSame('UVEX', $connector->manufacturer($shoes));
        $this->assertSame(226.8, $connector->price($shoes)?->net);

        // 38 ma inną cenę niż 39 i 40 — osobna karta
        $this->assertSame('Trzewik uvex 2 trend 6935/2/38', $products['6935/2/38']->name);
        $this->assertSame('Dostępny', $products['6935/2/38']->availability);
        $this->assertSame('', $products['6935/2/38']->variantSummary);
        $this->assertSame([['remote_id' => '6935/2/38', 'sku' => '6935/2/38', 'name' => 'Trzewik uvex 2 trend 6935/2/38']], $products['6935/2/38']->members);
        $this->assertSame(358.7, $connector->price($products['6935/2/38'])?->net);
        $this->assertSame('Dostępny: 39; Na zamówienie: 40', $products['6935/2/39']->availability);

        $gloves = $products['HA2023(L)'];
        $this->assertSame('Rękawice HexArmor Rig Lizard Arctic 2023', $gloves->name);
        $this->assertSame('Na zamówienie', $gloves->availability);
        $this->assertSame('Rozmiary: 8 (HA2023(M)); 9 (HA2023(L))', $gloves->variantSummary);
        $this->assertSame('HexArmor', $connector->manufacturer($gloves));

        $heckel = $products['HECKEL6273/3/36'];
        $this->assertSame('HECKEL 6273/3 SUXXED OFFROAD HIGH S3', $heckel->name);
        $this->assertSame('HECKEL', $connector->manufacturer($heckel));

        $laser = $products['000P1D011003'];
        $this->assertSame('Szyba chroniąca przed laserem 000P1D011003 wymiary: 6 x 915 x 610 mm', $laser->name);
        $this->assertSame(1133.05, $connector->price($laser)?->net);
        $this->assertSame('PLN', $connector->price($laser)?->currency);
        $this->assertNull($connector->price($laser)?->base);
        $this->assertNull($connector->price($products['CENNIKI']));
    }

    public function test_price_text_is_parsed_strictly(): void
    {
        $this->assertSame(113305, UvexB2bConnector::priceCents('1 133,05 PLN'));
        $this->assertSame(2028342, UvexB2bConnector::priceCents(" 20\u{00A0}283,42 PLN "));
        $this->assertSame(18200, UvexB2bConnector::priceCents('182,00 PLN'));
        $this->assertSame(0, UvexB2bConnector::priceCents('0,00 PLN'));
        $this->assertNull(UvexB2bConnector::priceCents('182.00 PLN'));
        $this->assertNull(UvexB2bConnector::priceCents('182,00 EUR'));
        $this->assertNull(UvexB2bConnector::priceCents('182,00'));
        $this->assertNull(UvexB2bConnector::priceCents('1133,05 PLN'));
    }

    public function test_unreadable_price_skips_product_with_reason(): void
    {
        $this->rows[7]['price'] = 'cena na zapytanie';
        $this->fakeSite();
        $connector = $this->connector();
        $connector->login();

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('nie udało się odczytać ceny „cena na zapytanie”');
        $connector->price($this->productsByCode($connector)['9970.005']);
    }

    public function test_list_changed_during_download_is_read_again_once(): void
    {
        $this->changedTotalOnPage2 = 1;
        $this->fakeSite();
        $connector = $this->connector();
        $messages = [];
        $connector->onListProgress(function (string $message) use (&$messages): void {
            $messages[] = $message;
        });
        $connector->login();

        $this->assertCount(8, $this->productsByCode($connector));
        $this->assertNotEmpty(array_filter($messages, static fn (string $m): bool => str_starts_with($m, 'Lista zmieniła się w trakcie pobierania (liczba produktów zmieniła się z 13 na 14')));
    }

    public function test_list_still_inconsistent_after_second_download_fails_without_products(): void
    {
        $this->changedTotalOnPage2 = 2;
        $this->fakeSite();
        $connector = $this->connector();
        $connector->login();

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Lista produktów izam.system-b2b.pl niespójna także po ponownym pobraniu');
        iterator_to_array($connector->products());
    }

    public function test_row_without_account_price_stops_the_list_instead_of_mass_no_price(): void
    {
        $this->rows[4]['price'] = null;
        $this->fakeSite();
        $connector = $this->connector();
        $connector->login();

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('pozycja 8430/2/39 bez ceny konta');
        iterator_to_array($connector->products());
    }

    public function test_same_code_with_different_prices_is_skipped_with_reason(): void
    {
        $this->rows[] = ['id' => '9999', 'code' => '9970.005', 'name' => 'Pojemnik mini na środki czyszczące - pełny 9970.005', 'price' => '190,00 PLN', 'avail' => 'Dostępny', 'unit' => 'szt.', 'img' => self::IMG_9970];
        $this->fakeSite();
        $connector = $this->connector();
        $connector->login();
        $products = iterator_to_array($connector->products(), false);

        $duplicates = array_values(array_filter($products, static fn (B2bRemoteProduct $p): bool => $p->remoteId === '9970.005'));
        $this->assertCount(1, $duplicates);
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('kod występuje na liście 2 razy z różnymi cenami (182,00 PLN, 190,00 PLN)');
        $connector->price($duplicates[0]);
    }

    public function test_description_is_verbatim_text_from_product_page_plus_unit(): void
    {
        $this->fakeSite();
        $connector = $this->connector();
        $connector->login();

        $description = $connector->description($this->productsByCode($connector)['9970.005']);

        $this->assertSame(
            "Stacja czyszcząca do okularów i gogli. Zawiera: 2x chusteczki czyszczące (700 szt. w opakowaniu) 9971.000, 1x płyn czyszczący 9972.103, 1x pompkę dozującą 9973.101\n\nJednostka: szt.",
            $description,
        );
        foreach (['Pobierz', 'SST', 'brutto', 'Marża', 'Dodaj do koszyka'] as $chrome) {
            $this->assertStringNotContainsString($chrome, $description);
        }
    }

    public function test_shop_invitation_is_not_taken_as_the_description(): void
    {
        // część kart ma w miejscu opisu zachętę do kliknięcia — to nie jest opis wyrobu
        $this->details['4713'] = str_replace(
            '<p>Stacja czyszcząca do okularów i gogli. Zawiera: 2x chusteczki czyszczące (700 szt. w opakowaniu) 9971.000, 1x płyn czyszczący 9972.103, 1x pompkę dozującą 9973.101</p>',
            '<p>Kliknij i przejdź do pełnego opisu</p>',
            $this->details['4713'],
        );
        $this->fakeSite();
        $connector = $this->connector();
        $connector->login();

        $description = $connector->description($this->productsByCode($connector)['9970.005']);

        $this->assertSame('Jednostka: szt.', $description);
    }

    public function test_empty_description_gives_only_unit_and_other_code_on_page_is_rejected(): void
    {
        $this->details['4713'] = str_replace(
            '<p>Stacja czyszcząca do okularów i gogli. Zawiera: 2x chusteczki czyszczące (700 szt. w opakowaniu) 9971.000, 1x płyn czyszczący 9972.103, 1x pompkę dozującą 9973.101</p>',
            '<p></p>',
            $this->details['4713'],
        );
        $this->details['6687'] = str_replace('<td class="codeViewProductDane">9970.005</td>', '<td class="codeViewProductDane">8430/2/40</td>', $this->details['4713']);
        $this->fakeSite();
        $connector = $this->connector();
        $connector->login();
        $products = $this->productsByCode($connector);

        $this->assertSame('Jednostka: szt.', $connector->description($products['9970.005']));
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('kod na stronie produktu (8430/2/40) inny niż kod z listy (8430/2/39)');
        $connector->description($products['8430/2/39']);
    }

    public function test_image_uses_preview_path_without_public_and_blank_placeholder_is_no_image(): void
    {
        $this->fakeSite();
        $connector = $this->connector();
        $connector->login();
        $products = $this->productsByCode($connector);

        $image = $connector->image($products['9970.005']);

        $this->assertNotNull($image);
        $this->assertSame(self::PNG, $image->bytes);
        $this->assertSame('image/png', $image->mime);
        $this->assertSame(str_replace('/public/get-preview/', '/get-preview/', self::IMG_9970), $image->sourceUrl);
        $this->assertNull($connector->image($products['CENNIKI']));
        $this->assertNull(UvexB2bConnector::imageUrl('https://inny.example.com/public/get-preview/a.jpg'));
    }

    public function test_list_page_without_session_logs_in_again_and_continues(): void
    {
        $this->dropSessionOnce = true;
        $this->fakeSite();
        $client = $this->client();
        $client->login();

        $html = $client->listPage(2);

        $this->assertSame(2, $this->logins);
        $this->assertTrue(UvexB2bClient::hasLoggedInMarker($html));
    }

    public function test_page_still_without_session_after_relogin_is_fatal(): void
    {
        $this->pagesWithoutSession = true;
        $this->fakeSite();
        $client = $this->client();
        $client->login();

        try {
            $client->listPage(1);
            $this->fail('Oczekiwano B2bFatalException');
        } catch (B2bFatalException $e) {
            $this->assertStringStartsWith('Utracono sesję konta izam.system-b2b.pl', $e->getMessage());
        }
        $this->assertSame(2, $this->logins);
    }

    public function test_login_page_counts_as_logged_out_even_with_logout_link(): void
    {
        $this->assertFalse(UvexB2bClient::hasLoggedInMarker('<a href="/public/logout">x</a><input name="contractor_code">'));
        $this->assertTrue(UvexB2bClient::hasLoggedInMarker('<a href="https://izam.system-b2b.pl/public/logout">Wyloguj</a>'));
        $this->assertFalse(UvexB2bClient::hasLoggedInMarker($this->fixture('login.html')));
    }

    public function test_rate_limit_retry_after_is_capped_at_two_minutes(): void
    {
        Http::fake(['izam.system-b2b.pl/*' => Http::sequence()
            ->push('', 429, ['Retry-After' => '3600'])
            ->push(self::PNG, 200, ['Content-Type' => 'image/png'])]);

        $file = $this->client()->imageBytes('https://izam.system-b2b.pl/get-preview/product_images/40/A.jpg');

        $this->assertSame(self::PNG, $file['bytes']);
        $this->assertSame([120000], $this->sleeps);
    }

    public function test_registry_detects_uvex_by_host_and_builds_connector_from_account(): void
    {
        $registry = app(B2bConnectorRegistry::class);

        $this->assertSame('uvex', $registry->keyForSites(['https://izam.system-b2b.pl/public/start']));
        $this->assertSame('UVEX', $registry->label('uvex'));
        $this->assertInstanceOf(UvexB2bConnector::class, $registry->make($this->account(), 0));
    }

    public function test_sync_through_runner_creates_one_card_per_size_group_with_links_availability_and_image(): void
    {
        Storage::fake('public');
        $this->fakeSite();

        $result = app(B2bAccountSyncRunner::class)->run($this->account(), delayMs: 0);

        $this->assertSame(8, $result['total_remote']);
        $this->assertSame(7, $result['created']);
        $this->assertContains('CENNIKI: brak ceny w B2B', $result['errors']);
        $this->assertSame(7, Product::query()->count());

        $shoes = Product::query()->where('sku', '8430/2/39')->sole();
        $this->assertSame('Półbuty ochronne uvex 1 business 8430/2', $shoes->name);
        $this->assertSame('UVEX', $shoes->manufacturer);
        $this->assertSame('226.80', $shoes->purchase_price);
        $this->assertSame('PLN', $shoes->currency);
        $this->assertSame('Rozmiary: 39 (8430/2/39); 40 (8430/2/40); 41 (8430/2/41)', $shoes->variant_summary);
        $this->assertFalse(Product::query()->whereIn('sku', ['8430/2/40', '8430/2/41', '6935/2/40', 'HA2023(M)', 'HECKEL6273/3/37'])->exists());

        $slot = ProductSourcePrice::query()->where('product_id', $shoes->id)->where('source_key', ProductSourcePrice::b2bKey((int) $this->account()->id))->sole();
        $this->assertSame('Dostępny: 39; Na zamówienie: 40, 41', $slot->availability);
        $this->assertSame(
            ['8430/2/39' => 'Półbuty ochronne uvex 1 business 8430/2/39', '8430/2/40' => 'Półbuty ochronne uvex 1 business 8430/2/40', '8430/2/41' => 'Półbuty ochronne uvex 1 business 8430/2/41'],
            B2bProductLink::query()->where('product_id', $shoes->id)->orderBy('remote_id')->pluck('remote_name', 'remote_id')->all(),
        );

        $cleaner = Product::query()->where('sku', '9970.005')->sole();
        $this->assertStringStartsWith('Stacja czyszcząca do okularów i gogli.', (string) $cleaner->description);
        $this->assertSame(str_replace('/public/get-preview/', '/get-preview/', self::IMG_9970), $cleaner->images()->firstOrFail()->source_url);
        $this->assertSame('HexArmor', Product::query()->where('sku', 'HA2023(L)')->value('manufacturer'));
        $this->assertSame('HECKEL', Product::query()->where('sku', 'HECKEL6273/3/36')->value('manufacturer'));

        $log = array_column((array) B2bSyncRun::query()->latest('id')->firstOrFail()->log, 'text');
        $this->assertContains('Lista UVEX: 13 pozycji → 8 kart (4 grup rozmiarów o tej samej cenie)', $log);
    }

    public function test_product_files_are_listed_with_absolute_addresses_and_kinds(): void
    {
        $this->fakeSite();
        $connector = $this->connector();
        $connector->login();
        $cleaner = null;
        foreach ($connector->products() as $product) {
            if ($product->sku === '9970.005') {
                $cleaner = $product;
            }
        }
        $this->assertNotNull($cleaner);

        $documents = $connector->documents($cleaner);

        $this->assertSame(
            ['SST stacja czyszcząca mini 9970.005.pdf', 'instrukcja obuwia uvex.JPG'],
            array_map(static fn ($d): string => $d->title, $documents),
        );
        $this->assertSame(
            [
                'https://izam.system-b2b.pl/public/assets/resources/products/4713/SST%20stacja%20czyszcz%C4%85ca%20mini%209970.005.pdf',
                'https://izam.system-b2b.pl/public/assets/resources/products/4713/instrukcja%20obuwia%20uvex.JPG',
            ],
            array_map(static fn ($d): string => $d->sourceUrl, $documents),
        );
        $this->assertSame(['datasheet', 'other'], array_map(static fn ($d): string => $d->kind, $documents));
        // strona produktu pobrana raz — lista plików korzysta z tego samego HTML co opis
        $this->assertSame(1, $this->detailHits);
    }

    public function test_file_address_outside_the_shop_is_rejected(): void
    {
        $this->assertNull(UvexB2bConnector::fileUrl('https://izam.system-b2b.pl/public/', 'https://example.test/karta.pdf'));
        $this->assertNull(UvexB2bConnector::fileUrl('https://izam.system-b2b.pl/public/', ''));
        $this->assertSame(
            'https://izam.system-b2b.pl/public/assets/karta%20techniczna.pdf',
            UvexB2bConnector::fileUrl('https://izam.system-b2b.pl/public/', 'assets/karta techniczna.pdf'),
        );
        // adres już zakodowany nie jest kodowany drugi raz
        $this->assertSame(
            'https://izam.system-b2b.pl/public/assets/karta%20techniczna.pdf',
            UvexB2bConnector::fileUrl('https://izam.system-b2b.pl/public/', 'assets/karta%20techniczna.pdf'),
        );
    }

    public function test_sync_saves_product_files_and_reads_description_from_the_datasheet(): void
    {
        Storage::fake('public');
        $this->fakeSite();

        app(B2bAccountSyncRunner::class)->run($this->account(), delayMs: 0);

        $cleaner = Product::query()->where('sku', '9970.005')->sole();
        $documents = ProductDocument::query()->where('product_id', $cleaner->id)->orderBy('sort_order')->get();
        $this->assertSame(
            ['SST stacja czyszcząca mini 9970.005.pdf', 'instrukcja obuwia uvex.JPG'],
            $documents->pluck('title')->all(),
        );
        $this->assertSame(['datasheet', 'other'], $documents->pluck('kind')->all());
        $this->assertSame(
            [(int) $this->account()->id, (int) $this->account()->id],
            $documents->pluck('b2b_account_id')->all(),
            'plik z panelu dostawcy ma wskazywać konto B2B — uzupełnianie AI takich nie kasuje',
        );
        foreach ($documents as $document) {
            $this->assertTrue(Storage::disk('public')->exists((string) $document->path));
            $this->assertStringStartsWith('https://izam.system-b2b.pl/public/assets/resources/products/4713/', (string) $document->source_url);
        }

        // tekst karty technicznej: zapisany przy pliku i dopisany do opisu ze wskazaniem źródła
        $datasheet = $documents->firstOrFail();
        $this->assertStringContainsString('EN ISO 20345:2011 S1 P SRC', (string) $datasheet->text);
        $this->assertNull($documents->last()->text, 'skan bez warstwy tekstowej nie dostaje tekstu');

        $this->assertStringStartsWith('Stacja czyszcząca do okularów i gogli.', (string) $cleaner->description);
        $this->assertStringContainsString('Z karty technicznej (SST stacja czyszcząca mini 9970.005.pdf):', (string) $cleaner->description);
        $this->assertStringContainsString('EN ISO 20345:2011 S1 P SRC', (string) $cleaner->description);

        // opis z synchronizacji jest rozpoznawany jako opis z B2B (kolejny przebieg może go poprawić)
        $link = B2bProductLink::query()->where('remote_id', '9970.005')->sole();
        $this->assertSame(sha1((string) $cleaner->description), (string) $link->description_hash);

        $downloads = count($this->fileHits);
        $this->assertSame(2, $downloads);

        // drugi przebieg: pliki są już przy karcie, więc nie pobieramy ich ponownie
        $this->fileHits = [];
        $second = app(B2bAccountSyncRunner::class)->run($this->account(), delayMs: 0);

        $this->assertSame([], $this->fileHits);
        $this->assertSame(2, ProductDocument::query()->where('product_id', $cleaner->id)->count());
        $this->assertSame(0, $second['created']);
        $this->assertSame($cleaner->description, (string) $cleaner->fresh()?->description);
    }

    private function client(): UvexB2bClient
    {
        return new UvexB2bClient('K123', 'jan', 'dobre-haslo', 0, function (int $ms): void {
            $this->sleeps[] = $ms;
        });
    }

    private function connector(): UvexB2bConnector
    {
        return new UvexB2bConnector($this->client());
    }

    private function account(): B2bAccount
    {
        return B2bAccount::query()->firstOrCreate(
            ['username' => 'jan'],
            ['contractor_code' => 'K123', 'password' => 'dobre-haslo', 'sites' => ['izam.system-b2b.pl'], 'connector' => 'uvex'],
        )->fresh();
    }

    /**
     * @return array<string, B2bRemoteProduct>
     */
    private function productsByCode(UvexB2bConnector $connector): array
    {
        $out = [];
        foreach ($connector->products() as $product) {
            $out[$product->remoteId] = $product;
        }

        return $out;
    }

    private function fixture(string $name): string
    {
        return (string) file_get_contents(base_path('tests/Fixtures/uvex/'.$name));
    }

    private function listHtml(int $page): string
    {
        $total = count($this->rows);
        $slice = array_slice($this->rows, ($page - 1) * $this->pageSize, $this->pageSize);
        $template = $this->fixture('list_row.html');
        $rows = '';
        foreach ($slice as $row) {
            $html = strtr($template, [
                '{{ID}}' => $row['id'],
                '{{ID12}}' => str_pad($row['id'], 12, '0', STR_PAD_LEFT),
                '{{SLUG}}' => 'produkt-'.$row['id'],
                '{{CODE}}' => htmlspecialchars($row['code']),
                '{{NAME}}' => htmlspecialchars($row['name']),
                '{{PRICE}}' => (string) $row['price'],
                '{{AVAIL}}' => $row['avail'],
                '{{UNIT}}' => $row['unit'],
                '{{IMAGE}}' => '<a data-img="'.$row['img'].'" data-full="'.$row['img'].'"></a>',
            ]);
            if ($row['price'] === null) {
                $html = (string) preg_replace('#<span[^>]*class="twojaCenaNetto_\d+">\s*</span>#', '', $html);
            }
            $rows .= $html."\n";
        }
        if ($page === 2 && $this->changedTotalOnPage2 > 0) {
            $this->changedTotalOnPage2--;
            $total++;
        }
        $from = ($page - 1) * $this->pageSize + 1;
        $to = min($page * $this->pageSize, count($this->rows));

        return strtr($this->fixture('list_page.html'), [
            '{{ROWS}}' => $rows,
            '{{INFO}}' => 'Wyświetlanie rekordów od '.$from.' do '.$to.' z '.$total,
        ]);
    }

    private function fakeSite(): void
    {
        Http::fake(function (Request $request) {
            $url = $request->url();
            $path = (string) parse_url($url, PHP_URL_PATH);
            parse_str((string) parse_url($url, PHP_URL_QUERY), $query);
            preg_match('/b2b_session=([^;\s]+)/', $request->header('Cookie')[0] ?? '', $m);
            $loggedIn = isset($m[1]) && in_array($m[1], $this->validSessions, true);
            $toLogin = Http::response('', 302, ['Location' => 'https://izam.system-b2b.pl/public/login']);

            if ($path === '/public/login' && $request->method() === 'GET') {
                return Http::response($this->fixture('login.html'), 200, ['Set-Cookie' => 'b2b_session=guest; path=/; httponly']);
            }
            if ($path === '/public/login' && $request->method() === 'POST') {
                if (($request['_token'] ?? '') !== 'TOKEN-LOGOWANIA-SYNTETYCZNY' || ($request['contractor_code'] ?? '') !== 'K123'
                    || ($request['name'] ?? '') !== 'jan' || ($request['password'] ?? '') !== 'dobre-haslo') {
                    return Http::response($this->fixture('login.html'));
                }
                $this->logins++;
                $session = 'sess'.$this->logins;
                $this->validSessions[] = $session;

                return Http::response('', 302, [
                    'Location' => 'https://izam.system-b2b.pl/public/start',
                    'Set-Cookie' => 'b2b_session='.$session.'; path=/; httponly',
                ]);
            }
            if ($path === '/public/start') {
                return $loggedIn ? Http::response(strtr($this->fixture('list_page.html'), ['{{ROWS}}' => '', '{{INFO}}' => ''])) : $toLogin;
            }
            if ($path === '/public/product' || str_starts_with($path, '/public/product-details/')) {
                if ($this->dropSessionOnce) {
                    $this->dropSessionOnce = false;
                    $this->validSessions = [];
                    $loggedIn = false;
                }
                if (! $loggedIn || $this->pagesWithoutSession) {
                    return $toLogin;
                }
                if ($path === '/public/product') {
                    return Http::response($this->listHtml((int) ($query['page'] ?? 1)));
                }
                $id = (string) ($query['first_id'] ?? '');
                $this->detailHits++;

                return isset($this->details[$id]) ? Http::response($this->details[$id]) : Http::response('brak strony', 404);
            }
            if (str_starts_with($path, '/get-preview/')) {
                return Http::response(self::PNG, 200, ['Content-Type' => 'image/png']);
            }
            if (str_starts_with($path, '/public/assets/resources/products/')) {
                if (! $loggedIn) {
                    return $toLogin;
                }
                $this->fileHits[] = $path;
                $name = rawurldecode(basename($path));

                return str_ends_with(mb_strtolower($name), '.pdf')
                    ? Http::response($this->fixture('sst_9970005.pdf'), 200, ['Content-Type' => 'application/pdf'])
                    : Http::response(self::PNG, 200, ['Content-Type' => 'image/jpeg']);
            }

            return Http::response('nieznany adres w teście: '.$url, 404);
        });
    }
}
