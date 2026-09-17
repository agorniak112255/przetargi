<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\B2bAccount;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\User;
use App\Services\B2b\B2bAccountSyncRunner;
use App\Services\B2b\B2bConnectorRegistry;
use App\Services\B2b\B2bFatalException;
use App\Services\B2b\B2bRemoteProduct;
use App\Services\B2b\B2bRemoteShopField;
use App\Services\B2b\B2bRemoteVariant;
use App\Services\B2b\SignProjectB2bClient;
use App\Services\B2b\SignProjectB2bConnector;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use RuntimeException;
use Tests\TestCase;

/**
 * Łącznik signproject.pl na atrapie sklepu (Http::fake). Cena konta zależy od ciasteczka sesji RSSID
 * wysłanego przez CookieJar klienta — test sprawdza więc także, że ciasteczka logowania idą z zapytaniami.
 */
final class SignProjectConnectorTest extends TestCase
{
    use RefreshDatabase;

    /** 1×1 PNG */
    private const PNG = "\x89PNG\r\n\x1a\n\x00\x00\x00\rIHDR\x00\x00\x00\x01\x00\x00\x00\x01\x08\x06\x00\x00\x00\x1f\x15\xc4\x89\x00\x00\x00\rIDATx\x9cc\xf8\x0f\x00\x00\x01\x01\x00\x05\x18\xd8N\x00\x00\x00\x00IEND\xaeB`\x82";

    private const LOGOUT_LINK = '<a href="/signin.php?operation=logout">Wyloguj</a>';

    private const OG_BB014 = 'https://signproject.pl/hpeciai/8ed772e1fbac9f91611fcf5a86ea2717/pol_pl_BB014-Drzwi-przeciwpozarowe-Zamykac-Kierunek-drogi-ewakuacyjnej-w-prawo-7109_1.png';

    /** @var array<int, array<string, mixed>> wersja → dane atrapy */
    private array $catalog = [];

    /** @var list<string> adresy stron produktów w mapie (kolejność) */
    private array $sitemapUrls = [];

    private bool $secondSitemapFails = false;

    /** @var array<string, string> adres strony → HTML */
    private array $pages = [];

    /** @var list<string> */
    private array $validSessions = [];

    private bool $newSessionsInvalid = false;

    private int $logins = 0;

    private bool $ogImageMissing = false;

    /** Strona produktu bez „Wyloguj” tylko przy pierwszym pobraniu. */
    private bool $pageDropsMarkerOnce = false;

    /** @var (callable(int, string): void)|null wołane przy każdym zapytaniu projector.php (id, get) */
    private $onProjector = null;

    /** @var array<int, string|null> wersja → Retry-After pierwszej odpowiedzi 429 (null = bez nagłówka) */
    private array $throttleOnce = [];

    /** @var array<int, true> wersje, dla których zapytanie o cenę (sizes,sizeprices) kończy się HTTP 500 */
    private array $failingPrices = [];

    /** @var list<int> */
    private array $sleeps = [];

    protected function setUp(): void
    {
        parent::setUp();
        Queue::fake();

        $this->sign('BB014', 'BB014 Drzwi przeciwpożarowe Zamykać! Kierunek drogi ewakuacyjnej w prawo', [
            7109 => ['10 x 14,8 cm \ FN - folia samoprzylepna', 0.97, 3.23],
            7110 => ['15 x 22,2 cm \ FN - folia samoprzylepna', 1.50, 4.10],
        ]);
        $this->sign('GL031', 'GL031 Przeczytaj instrukcję', [
            32059 => ['35 x 52,5 cm \ KN - folia podłogowa', 20.10, 30.00],
            32067 => ['5 x 7,4 cm \ FS - folia fotoluminescencyjna', 1.20, 2.00],
        ]);
        $this->sitemapUrls = [
            $this->link(7109), 'https://signproject.pl/en/products/bb014-fire-door-7109', $this->link(7110),
            'https://signproject.pl/pl/menu/ochrona-i-higiena-pracy-299', $this->link(32059), $this->link(32067),
        ];
        $this->pages[$this->link(7109)] = $this->fixture('product_bb014.html');
        $this->pages[$this->link(32059)] = $this->fixture('product_gl031.html');
    }

    public function test_login_posts_form_and_sends_session_cookie_with_later_requests(): void
    {
        $this->fakeShop();
        $client = $this->client();

        $client->login();
        $json = $client->projector(7109, 'sizes,sizeprices');

        $this->assertTrue($client->isLoggedIn());
        $this->assertSame(0.97, $json['sizes']['items']['00000-uniw']['prices']['price_net']);
        $signin = Http::recorded(fn (Request $r): bool => str_ends_with($r->url(), '/signin.php'))->first()[0];
        $this->assertSame('POST', $signin->method());
        $this->assertSame(['operation' => 'login', 'login' => 'jan', 'password' => 'dobre-haslo'], $signin->data());
        $this->assertStringContainsString('client=c0ffee', $signin->header('Cookie')[0] ?? '');
        $projector = Http::recorded(fn (Request $r): bool => str_contains($r->url(), 'projector.php'))->first()[0];
        $this->assertStringContainsString('RSSID=sess1', $projector->header('Cookie')[0] ?? '');
    }

    public function test_login_without_logout_marker_fails_with_clear_message(): void
    {
        $this->fakeShop();
        $client = new SignProjectB2bClient('jan', 'zle-haslo', 0, fn (int $ms) => $this->sleeps[] = $ms);

        try {
            $client->login();
            $this->fail('Logowanie powinno się nie udać');
        } catch (RuntimeException $e) {
            $this->assertNotInstanceOf(B2bFatalException::class, $e);
            $this->assertStringStartsWith('Logowanie do signproject.pl nieudane', $e->getMessage());
        }
        $this->assertFalse($client->isLoggedIn());
    }

    public function test_sitemap_index_reads_gzipped_and_plain_children_and_keeps_only_polish_products(): void
    {
        $this->fakeShop();

        $list = $this->client()->sitemapProductIds();

        $this->assertSame([7109, 7110, 32059, 32067], $list['ids']);
        $this->assertTrue($list['complete']);
        $this->assertSame($this->link(7110), $list['urls'][7110]);
    }

    public function test_incomplete_sitemap_gives_no_listed_ids(): void
    {
        $this->secondSitemapFails = true;
        $this->fakeShop();
        $connector = $this->connector();
        $connector->login();

        $codes = array_map(static fn (B2bRemoteProduct $p): string => $p->sku, iterator_to_array($connector->products(), false));

        $this->assertSame(['BB014'], $codes);
        $this->assertSame(2, $connector->totalVariants());
        $this->assertNull($connector->listedVariantIds());
    }

    public function test_products_group_versions_into_one_sign_and_skip_sibling_ids(): void
    {
        $this->fakeShop();
        $connector = $this->connector();
        $connector->login();

        $signs = iterator_to_array($connector->products(), false);

        $this->assertCount(2, $signs);
        [$bb014, $gl031] = $signs;
        $this->assertSame('BB014', $bb014->remoteId);
        $this->assertSame('BB014', $bb014->sku);
        $this->assertSame('BB014 Drzwi przeciwpożarowe Zamykać! Kierunek drogi ewakuacyjnej w prawo', $bb014->name);
        $this->assertSame($this->link(7109), $bb014->sourceUrl);
        $this->assertSame('Znaki bezpieczeństwa - Ochrona Przeciwpożarowa', $bb014->category);
        $this->assertSame('Format \ Podłoże', $bb014->raw['version_header']);
        $this->assertSame([
            ['id' => 7109, 'name' => '10 x 14,8 cm \ FN - folia samoprzylepna', 'link' => $this->link(7109)],
            ['id' => 7110, 'name' => '15 x 22,2 cm \ FN - folia samoprzylepna', 'link' => $this->link(7110)],
        ], $bb014->raw['versions']);
        $this->assertSame('SIGNPROJECT', $connector->manufacturer($bb014));
        $this->assertNull($connector->price($bb014));
        $this->assertSame('GL031', $gl031->sku);
        $this->assertSame('Ochrona i higiena pracy › Znaki nakazu z opisem', $gl031->category);

        // tylko po jednym pełnym zapytaniu na znak — ID wersji 7110 i 32067 nie są pobierane osobno
        $signFetches = Http::recorded(fn (Request $r): bool => str_contains($r->url(), 'projector.php') && ($r['get'] ?? '') === 'sizes,sizeprices,versions')
            ->map(fn (array $pair): int => (int) $pair[0]['product'])->values()->all();
        $this->assertSame([7109, 32059], $signFetches);
        $this->assertSame(2, $connector->totalProducts());
        $this->assertSame(4, $connector->totalVariants());
        $this->assertSame(['7109', '7110', '32059', '32067'], $connector->listedVariantIds());
        $this->assertSame(240, $connector->runBudgetMinutes());
    }

    public function test_products_start_with_unknown_ids_then_signs_with_oldest_price_check(): void
    {
        $this->sign('GX001', 'GX001 Nowy znak', [40000 => ['', 5.00, 6.00]]);
        $this->sitemapUrls[] = $this->link(40000);
        $this->fakeShop();
        $bb014 = $this->product('BB014');
        $gl031 = $this->product('GL031');
        $this->variant($bb014, '7109', '2026-09-12 10:00:00');
        $this->variant($bb014, '7110', '2026-09-13 10:00:00');
        $this->variant($gl031, '32059', '2026-09-11 10:00:00');
        $this->variant($gl031, '32067:00000-uniw', '2026-09-10 10:00:00');
        $connector = $this->connector();
        $connector->login();

        $signs = iterator_to_array($connector->products(), false);

        $this->assertSame(['GX001', 'GL031', 'BB014'], array_map(static fn (B2bRemoteProduct $p): string => $p->sku, $signs));
        // produkt bez wersji jest swoją jedyną wersją; strona niedostępna = kategoria pusta, bez zgadywania
        $this->assertSame([['id' => 40000, 'name' => '', 'link' => $this->link(40000)]], $signs[0]->raw['versions']);
        $this->assertNull($signs[0]->category);
    }

    public function test_variants_carry_account_prices_attributes_vat_unit_and_currency_from_shop_text(): void
    {
        $this->catalog[7110]['name'] = '15 x 22,2 cm';
        $this->fakeShop();
        $connector = $this->connector();
        $connector->login();
        $bb014 = $this->firstSign($connector);

        $variants = $connector->variants($bb014);

        $this->assertCount(2, $variants);
        $this->assertSame('7109', $variants[0]->remoteId);
        $this->assertSame('10 x 14,8 cm \ FN - folia samoprzylepna', $variants[0]->label);
        $this->assertSame(['Format' => '10 x 14,8 cm', 'Podłoże' => 'FN - folia samoprzylepna'], $variants[0]->attributes);
        $this->assertSame(0.97, $variants[0]->price?->net);
        $this->assertNull($variants[0]->price?->base);
        $this->assertSame('PLN', $variants[0]->price?->currency);
        $this->assertNull($variants[0]->priceError);
        $this->assertSame(23.0, $variants[0]->vatRate);
        $this->assertSame('szt.', $variants[0]->unit);
        $this->assertSame($this->link(7109), $variants[0]->sourceUrl);
        $this->assertSame(0, $variants[0]->sortOrder);
        // etykieta z innej liczby członów niż nagłówek — atrybuty puste, etykieta dosłownie
        $this->assertSame('15 x 22,2 cm', $variants[1]->label);
        $this->assertSame([], $variants[1]->attributes);
        $this->assertSame(1.50, $variants[1]->price?->net);
        $this->assertSame(1, $variants[1]->sortOrder);
    }

    public function test_shop_card_describes_the_sign_as_a_whole_without_repeating_the_version_table(): void
    {
        $this->fakeShop();
        $connector = $this->connector();
        $connector->login();
        $bb014 = $this->firstSign($connector);
        // jednostka i stawka VAT są w odpowiedziach projector.php, które i tak czyta zbieranie cen wersji
        $connector->variants($bb014);

        $before = Http::recorded()->count();
        $fields = $connector->shopFields($bb014);

        $this->assertSame($before, Http::recorded()->count(), 'karta dostawcy nie dopytuje sklepu');
        $this->assertSame([
            ['Informacje handlowe', 'Producent', 'SIGNPROJECT'],
            ['Informacje handlowe', 'Kategoria', 'Znaki bezpieczeństwa - Ochrona Przeciwpożarowa'],
            ['Informacje handlowe', 'Jednostka sprzedaży', 'szt.'],
            ['Informacje handlowe', 'Stawka VAT (%)', '23.0'],
            ['Dostępne wersje', 'Format', '10 x 14,8 cm; 15 x 22,2 cm'],
            ['Dostępne wersje', 'Podłoże', 'FN - folia samoprzylepna'],
        ], self::rows($fields));
    }

    public function test_shop_card_has_no_unit_or_vat_before_the_versions_were_priced(): void
    {
        // etykieta wersji o innej liczbie członów niż nagłówek nie trafia do zestawu wartości (bez zgadywania)
        $this->catalog[7110]['name'] = '15 x 22,2 cm';
        $this->fakeShop();
        $connector = $this->connector();
        $connector->login();
        $bb014 = $this->firstSign($connector);

        $this->assertSame([
            ['Informacje handlowe', 'Producent', 'SIGNPROJECT'],
            ['Informacje handlowe', 'Kategoria', 'Znaki bezpieczeństwa - Ochrona Przeciwpożarowa'],
            ['Dostępne wersje', 'Format', '10 x 14,8 cm'],
            ['Dostępne wersje', 'Podłoże', 'FN - folia samoprzylepna'],
        ], self::rows($connector->shopFields($bb014)));
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

    public function test_multi_key_items_become_separate_variants_and_missing_currency_is_a_price_error(): void
    {
        $this->catalog[7110]['items'] = [
            '00001-a' => ['name' => 'A', 'prices' => ['price_net' => 1.11]],
            '00002-b' => ['name' => 'B', 'prices' => ['price_net' => 2.22]],
        ];
        $this->catalog[32067]['formatted'] = '1,20';
        $this->fakeShop();
        $connector = $this->connector();
        $connector->login();
        [$bb014, $gl031] = iterator_to_array($connector->products(), false);

        $bbVariants = $connector->variants($bb014);
        $glVariants = $connector->variants($gl031);

        $this->assertSame(['7109', '7110:00001-a', '7110:00002-b'], array_map(static fn (B2bRemoteVariant $v): string => $v->remoteId, $bbVariants));
        $this->assertSame(2.22, $bbVariants[2]->price?->net);
        $this->assertSame('15 x 22,2 cm \ FN - folia samoprzylepna (B)', $bbVariants[2]->label);
        $this->assertSame([0, 1, 2], array_map(static fn (B2bRemoteVariant $v): int => $v->sortOrder, $bbVariants));
        $this->assertNull($glVariants[1]->price);
        $this->assertSame('sklep nie podał waluty ceny', $glVariants[1]->priceError);
        $this->assertContains('7110:00002-b', $connector->listedVariantIds() ?? []);
    }

    public function test_rate_limit_honours_retry_after_then_backoff_and_succeeds(): void
    {
        Http::fake(['signproject.pl/ajax/*' => Http::sequence()
            ->push('', 429, ['Retry-After' => '7'])
            ->push('', 503)
            ->push(['sizes' => ['id' => 7109]])]);

        $json = $this->client()->projector(7109, 'sizes');

        $this->assertSame(7109, $json['sizes']['id']);
        $this->assertSame([7000, 10000], $this->sleeps);
    }

    public function test_twenty_consecutive_http_failures_are_fatal(): void
    {
        Http::fake(['*' => Http::response('', 500)]);
        $client = $this->client();

        for ($i = 1; $i < 20; $i++) {
            try {
                $client->projector(7109, 'sizes');
                $this->fail('Oczekiwano błędu HTTP');
            } catch (RuntimeException $e) {
                $this->assertNotInstanceOf(B2bFatalException::class, $e);
            }
        }
        $this->expectException(B2bFatalException::class);
        $client->projector(7109, 'sizes');
    }

    public function test_silent_session_loss_is_detected_by_control_price_and_prices_are_collected_again(): void
    {
        $this->fakeShop();
        $connector = $this->connector();
        $connector->login();
        $bb014 = $this->firstSign($connector);
        $dropped = false;
        $this->onProjector = function (int $id, string $get) use (&$dropped): void {
            if ($id === 7110 && ! $dropped) {
                $dropped = true;
                $this->validSessions = [];
            }
        };

        $variants = $connector->variants($bb014);

        $this->assertSame(2, $this->logins);
        $this->assertSame([0.97, 1.50], array_map(static fn (B2bRemoteVariant $v): ?float => $v->price?->net, $variants));
    }

    public function test_session_loss_that_survives_relogin_is_fatal(): void
    {
        $this->fakeShop();
        $connector = $this->connector();
        $connector->login();
        $bb014 = $this->firstSign($connector);
        $this->onProjector = function (int $id): void {
            if ($id === 7110) {
                // sklep dalej pokazuje „Wyloguj”, ale ceny są już anonimowe
                $this->validSessions = [];
                $this->newSessionsInvalid = true;
            }
        };

        $this->expectException(B2bFatalException::class);
        $this->expectExceptionMessage('Utracono sesję konta signproject.pl — ceny konta niedostępne');
        $connector->variants($bb014);
    }

    public function test_version_prices_are_fetched_in_batches_with_one_pause_per_batch_and_account_cookie(): void
    {
        $this->manyVersionSign('ZZ100', 50001, 14);
        $this->sitemapUrls = [$this->link(50001)];
        $this->fakeShop();
        $connector = new SignProjectB2bConnector($this->client(150));
        $connector->login();
        $sign = $this->firstSign($connector);
        $this->sleeps = [];

        $variants = $connector->variants($sign);

        $this->assertSame(array_map('strval', range(50001, 50014)), array_map(static fn (B2bRemoteVariant $v): string => $v->remoteId, $variants));
        $this->assertSame(range(0, 13), array_map(static fn (B2bRemoteVariant $v): int => $v->sortOrder, $variants));
        $this->assertSame(
            array_map(static fn (int $i): float => round(1 + $i / 100, 2), range(0, 13)),
            array_map(static fn (B2bRemoteVariant $v): ?float => $v->price?->net, $variants),
        );
        $this->assertSame([], array_values(array_filter(array_map(static fn (B2bRemoteVariant $v): ?string => $v->priceError, $variants))));

        // 50001 z pamięci products(); pozostałe 13 zapytań w kolejności wersji, każde z ciasteczkiem sesji konta
        $priceRequests = Http::recorded(fn (Request $r): bool => str_contains($r->url(), 'projector.php')
            && ($r['get'] ?? '') === 'sizes,sizeprices' && (int) $r['product'] !== 7109)
            ->map(fn (array $pair): Request => $pair[0]);
        $this->assertSame(range(50002, 50014), $priceRequests->map(fn (Request $r): int => (int) $r['product'])->values()->all());
        foreach ($priceRequests as $request) {
            $this->assertStringContainsString('RSSID=sess1', $request->header('Cookie')[0] ?? '');
        }
        // przerwa przed każdą z 3 serii (13 = 6 + 6 + 1) i przed pojedynczym sprawdzeniem ceny kontrolnej
        $this->assertSame([150, 150, 150, 150], $this->sleeps);
    }

    public function test_rate_limited_versions_in_a_batch_honour_retry_after_then_backoff(): void
    {
        $this->manyVersionSign('TT001', 60001, 3);
        $this->sitemapUrls = [$this->link(60001)];
        $this->throttleOnce = [60002 => '3', 60003 => null];
        $this->fakeShop();
        $connector = $this->connector();
        $connector->login();
        $sign = $this->firstSign($connector);
        $this->sleeps = [];

        $variants = $connector->variants($sign);

        $this->assertSame([1.0, 1.01, 1.02], array_map(static fn (B2bRemoteVariant $v): ?float => $v->price?->net, $variants));
        // 60002: Retry-After 3 s; 60003: bez nagłówka — pierwszy krok backoffu (2 s); potem ponowienie z ceną
        $this->assertSame([3000, 2000], $this->sleeps);
        $this->assertCount(2, Http::recorded(fn (Request $r): bool => str_contains($r->url(), 'projector.php') && (int) $r['product'] === 60002));
        $this->assertSame(1, $this->logins);
    }

    public function test_failures_inside_batches_count_in_version_order_and_twenty_in_a_row_are_fatal(): void
    {
        $this->manyVersionSign('FA001', 70001, 26);
        $this->manyVersionSign('FA002', 71001, 21);
        $this->sitemapUrls = [$this->link(70001), $this->link(71001)];
        $this->pages[$this->link(70001)] = $this->fixture('product_gl031.html');
        $this->pages[$this->link(71001)] = $this->fixture('product_gl031.html');
        // FA001: 19 błędów, sukces (70021, w tej samej serii co 70020), 5 błędów — ciąg błędów przerwany
        $this->failingPrices = array_fill_keys([...range(70002, 70020), ...range(70022, 70026)], true);
        // FA002: 20 błędów z rzędu
        $this->failingPrices += array_fill_keys(range(71002, 71021), true);
        $this->fakeShop();
        $connector = $this->connector();
        $connector->login();

        $outcomes = [];
        foreach ($connector->products() as $sign) {
            try {
                $outcomes[$sign->sku] = $connector->variants($sign);
            } catch (B2bFatalException $e) {
                $outcomes[$sign->sku] = $e->getMessage();
                break;
            }
        }

        $this->assertIsArray($outcomes['FA001']);
        $errors = array_values(array_filter(array_map(static fn (B2bRemoteVariant $v): ?string => $v->priceError, $outcomes['FA001'])));
        $this->assertCount(24, $errors);
        $this->assertSame('nie udało się pobrać ceny: signproject.pl odpowiedziało HTTP 500', $errors[0]);
        $this->assertSame(1.2, $outcomes['FA001'][20]->price?->net);
        $this->assertSame(
            '20 kolejnych błędów zapytań do signproject.pl (ostatni: signproject.pl odpowiedziało HTTP 500) — pobieranie przerwane',
            $outcomes['FA002'],
        );
    }

    public function test_session_loss_during_batched_sign_logs_in_again_and_collects_all_prices_in_batches(): void
    {
        $this->manyVersionSign('SL001', 80001, 8);
        $this->sitemapUrls = [$this->link(80001)];
        $this->fakeShop();
        $connector = new SignProjectB2bConnector($this->client(150));
        $connector->login();
        $sign = $this->firstSign($connector);
        $this->sleeps = [];
        $dropped = false;
        $this->onProjector = function (int $id) use (&$dropped): void {
            if ($id === 80004 && ! $dropped) {
                $dropped = true;
                $this->validSessions = [];
            }
        };

        $variants = $connector->variants($sign);

        $this->assertSame(2, $this->logins);
        $this->assertSame(
            array_map(static fn (int $i): float => round(1 + $i / 100, 2), range(0, 7)),
            array_map(static fn (B2bRemoteVariant $v): ?float => $v->price?->net, $variants),
        );
        // po ponownym logowaniu wszystkie 8 wersji (także ta z pamięci products()) jeszcze raz, z nową sesją
        $recollected = Http::recorded(fn (Request $r): bool => str_contains($r->url(), 'projector.php')
            && ($r['get'] ?? '') === 'sizes,sizeprices' && (int) $r['product'] >= 80001
            && str_contains($r->header('Cookie')[0] ?? '', 'RSSID=sess2'));
        $this->assertSame(range(80001, 80008), $recollected->map(fn (array $pair): int => (int) $pair[0]['product'])->values()->all());
        // przerwy: 2 serie (7 wersji), kontrola, logowanie (2), cena kontrolna konta i anonimowa (2),
        // ponowne zebranie w 2 seriach (8 wersji — z przerwą przed każdym zapytaniem byłoby 8), kontrola
        $this->assertSame(array_fill(0, 10, 150), $this->sleeps);
    }

    public function test_second_sync_skips_product_page_of_known_sign_whose_card_has_category(): void
    {
        $this->seed(RolesAndPermissionsSeeder::class);
        $user = User::factory()->withRole('admin')->create();
        $account = B2bAccount::query()->create([
            'username' => 'jan', 'password' => 'dobre-haslo', 'sites' => ['signproject.pl'], 'connector' => 'signproject',
            'created_by' => $user->id, 'updated_by' => $user->id,
        ]);
        $this->fakeShop();
        $pageRequests = fn (int $id): int => Http::recorded(fn (Request $r): bool => $r->url() === $this->link($id))->count();

        $first = $this->syncAccount($account);

        $this->assertSame(2, $first['created']);
        $this->assertSame(1, $pageRequests(7109));
        $this->assertSame(1, $pageRequests(32059));
        $bb014 = Product::query()->where('sku', 'BB014')->sole();
        $this->assertSame('Znaki bezpieczeństwa - Ochrona Przeciwpożarowa', $bb014->category);
        // opis poprawiony ręcznie — synchronizacja go nie nadpisze, więc description() nie jest wołane
        Product::query()->whereKey($bb014->id)->update(['description' => 'Opis poprawiony ręcznie na karcie produktu.']);
        $this->sign('GX001', 'GX001 Nowy znak', [40000 => ['', 5.00, 6.00]]);
        $this->sitemapUrls[] = $this->link(40000);
        $this->pages[$this->link(40000)] = $this->fixture('product_gl031.html');

        $second = $this->syncAccount($account);

        $this->assertSame(1, $second['created']);
        // znany znak z kategorią na karcie — bez strony
        $this->assertSame(1, $pageRequests(7109));
        // GL031: opis zapisany wcześniej przez synchronizację jest odświeżany — description() pobiera stronę sama
        $this->assertSame(2, $pageRequests(32059));
        // nowy znak — strona dla kategorii (i ta sama kopia dla opisu)
        $this->assertSame(1, $pageRequests(40000));
        $this->assertSame('Ochrona i higiena pracy › Znaki nakazu z opisem', Product::query()->where('sku', 'GX001')->value('category'));
        $this->assertSame('Znaki bezpieczeństwa - Ochrona Przeciwpożarowa', $bb014->fresh()->category);
        $this->assertSame('Opis poprawiony ręcznie na karcie produktu.', $bb014->fresh()->description);
    }

    public function test_product_page_without_logout_marker_triggers_one_relogin(): void
    {
        $this->pageDropsMarkerOnce = true;
        $this->fakeShop();
        $client = $this->client();
        $client->login();

        $html = $client->productPage($this->link(7109));

        $this->assertSame(2, $this->logins);
        $this->assertTrue(SignProjectB2bClient::hasLoggedInMarker($html));
    }

    public function test_description_uses_shop_long_description_as_text(): void
    {
        $this->fakeShop();
        $connector = $this->connector();
        $connector->login();

        $description = $connector->description($this->firstSign($connector));

        $this->assertSame(implode("\n", [
            'Zamykanie drzwi przeciwpożarowych ma kluczowe znaczenie w przypadku pożaru i jest jedną z procedur bezpieczeństwa. Głównym celem drzwi przeciwpożarowych jest zapobieganie rozpowszechnieniu się ognia z jednego obiektu na inne. Dzięki zastosowaniu konstrukcji i materiału, drzwi te barierę, która powoduje rozprzestrzenianie się płomienia i ciepła. To daje strażakom więcej czasu na skuteczne działania.',
            'Znak „Drzwi przeciwpożarowe Zamykać! Kierunek drogi ewakuacyjnej w prawo” o rozmiarze 10 x 14,8 cm, wydrukowany na folii samoprzylepnej to jedna z nalepek, którą znajdziecie Państwo w sklepie internetowym www.signproject.pl.',
            'Informacja zawarta na znaku (zamykać drzwi ppoż. i droga ewakuacyjna w prawo) to jedne z najważniejszych informacji, które w sposób łatwy powinna znaleźć osoba znajdująca się w stanie zagrożenia.',
            'Dzięki zastosowaniu odpowiednich oznaczeń w budynku (szczególnie ważne miejsca, to):',
            '1. Wejście główne.',
            '2. Korytarze i hale.',
            '3. Klatki schodowe.',
            '4. Miejsce wspólne.',
            '5. Miejsca pracy.',
            '6. Obiekty publiczne.',
            '7. Obiekty przemysłowe.',
            '8. Blisko środków transportu.',
            '9. Punkt zbiórki. Zapewnicie Państwo bezpieczeństwo i zachowacie standardy wyznaczane przez prawo.',
        ]), $description);
        // strona pobrana raz na znak (kategoria w products(), opis z tej samej kopii)
        $this->assertCount(1, Http::recorded(fn (Request $r): bool => $r->url() === $this->link(7109)));
    }

    public function test_description_is_empty_without_shop_text_and_catalog_facts_stay_on_the_shop_card(): void
    {
        $this->fakeShop();
        $connector = $this->connector();
        $connector->login();
        $gl031 = iterator_to_array($connector->products(), false)[1];

        $description = $connector->description($gl031);

        // znak bez opisu w sklepie czeka na opis — sklejka danych katalogowych opisem wyrobu nie jest
        $this->assertSame('', $description);
        $this->assertStringNotContainsString('Kategoria:', $description);
        $this->assertStringNotContainsString('Dostępne formaty:', $description);
        $this->assertStringNotContainsString('Podłoża:', $description);
        $this->assertStringNotContainsString('katalogu SignProject', $description);

        // te same dane są na karcie wyrobu u dostawcy
        $rows = self::rows($connector->shopFields($gl031));

        $this->assertContains(['Informacje handlowe', 'Kategoria', 'Ochrona i higiena pracy › Znaki nakazu z opisem'], $rows);
        $this->assertContains(['Dostępne wersje', 'Format', '35 x 52,5 cm; 5 x 7,4 cm'], $rows);
        $this->assertContains(['Dostępne wersje', 'Podłoże', 'KN - folia podłogowa; FS - folia fotoluminescencyjna'], $rows);
    }

    public function test_category_falls_back_to_meta_description_template_when_breadcrumbs_have_one_level(): void
    {
        $this->pages[$this->link(32059)] = (string) preg_replace(
            '#<li class="category bc-item-2 bc-active">.*?</li>#s', '', $this->pages[$this->link(32059)]
        );
        $this->catalog[32059]['header'] = 'Rozmiar';
        $this->catalog[32067]['header'] = 'Rozmiar';
        $this->fakeShop();
        $connector = $this->connector();
        $connector->login();
        $gl031 = iterator_to_array($connector->products(), false)[1];

        $this->assertSame('Ochrona i higiena pracy › Znaki nakazu z opisem', $gl031->category);
        $this->assertSame('', $connector->description($gl031), 'sklep nie opisuje tego znaku');
        // kategoria idzie na kartę wyrobu u dostawcy; etykiety wersji o innej liczbie członów niż nagłówek
        // zostają poza nią (bez zgadywania, co jest czym) — opisu też nie udają
        $this->assertSame([
            ['Informacje handlowe', 'Producent', 'SIGNPROJECT'],
            ['Informacje handlowe', 'Kategoria', 'Ochrona i higiena pracy › Znaki nakazu z opisem'],
        ], self::rows($connector->shopFields($gl031)));
    }

    public function test_image_comes_from_og_image_with_icon_fallback(): void
    {
        $this->fakeShop();
        $connector = $this->connector();
        $connector->login();
        $bb014 = $this->firstSign($connector);

        $image = $connector->image($bb014);

        $this->assertNotNull($image);
        $this->assertSame(self::PNG, $image->bytes);
        $this->assertSame('image/png', $image->mime);
        $this->assertSame(self::OG_BB014, $image->sourceUrl);

        $this->ogImageMissing = true;
        $fallback = $connector->image($bb014);
        $this->assertSame(
            'https://signproject.pl/hpeciai/5d9f2210a6af4efbb3b33eae93e023a9/pol_il_BB014-Drzwi-przeciwpozarowe-Zamykac-Kierunek-drogi-ewakuacyjnej-w-prawo-7109.png',
            $fallback?->sourceUrl,
        );
    }

    public function test_registry_detects_signproject_by_host(): void
    {
        $registry = app(B2bConnectorRegistry::class);

        $this->assertSame('signproject', $registry->keyForSites(['https://signproject.pl/pl/login.html']));
        $this->assertSame('anro', $registry->keyForSites(['b2b.anro.net.pl']));
        $this->assertSame('SignProject', $registry->label('signproject'));
        $account = B2bAccount::query()->create(['username' => 'jan', 'password' => 'sekret', 'sites' => ['signproject.pl']]);
        $this->assertInstanceOf(SignProjectB2bConnector::class, $registry->make($account, 0));
    }

    private function client(int $delayMs = 0): SignProjectB2bClient
    {
        return new SignProjectB2bClient('jan', 'dobre-haslo', $delayMs, function (int $ms): void {
            $this->sleeps[] = $ms;
        });
    }

    /**
     * @return array<string, mixed>
     */
    private function syncAccount(B2bAccount $account): array
    {
        return app(B2bAccountSyncRunner::class)->run($account->fresh(), withImages: false, delayMs: 0, connector: $this->connector());
    }

    /** Znak z $count wersjami od $firstId; cena konta 1,00 + i/100, anonimowa 5,00 + i/100. */
    private function manyVersionSign(string $code, int $firstId, int $count): void
    {
        $versions = [];
        for ($i = 0; $i < $count; $i++) {
            $versions[$firstId + $i] = [(10 + $i).' x 10 cm \ FN - folia samoprzylepna', round(1 + $i / 100, 2), round(5 + $i / 100, 2)];
        }
        $this->sign($code, $code.' Znak testowy', $versions);
    }

    private function connector(): SignProjectB2bConnector
    {
        return new SignProjectB2bConnector($this->client());
    }

    private function firstSign(SignProjectB2bConnector $connector): B2bRemoteProduct
    {
        foreach ($connector->products() as $sign) {
            return $sign;
        }
        $this->fail('Brak znaków');
    }

    /**
     * @param  array<int, array{0: string, 1: float, 2: float}>  $versions  id → [nazwa wersji, cena konta, cena anonimowa]
     */
    private function sign(string $code, string $name, array $versions): void
    {
        foreach ($versions as $id => [$versionName, $logged, $anonymous]) {
            $this->catalog[$id] = [
                'code' => $code, 'name' => $versionName, 'product_name' => $name, 'header' => 'Format \ Podłoże',
                'logged' => $logged, 'anonymous' => $anonymous, 'siblings' => array_keys($versions),
            ];
        }
    }

    private function link(int $id): string
    {
        return 'https://signproject.pl/pl/products/'.strtolower((string) ($this->catalog[$id]['code'] ?? 'x')).'-znak-'.$id;
    }

    private function fixture(string $name): string
    {
        return (string) file_get_contents(base_path('tests/Fixtures/signproject/'.$name));
    }

    private function product(string $sku): Product
    {
        return Product::query()->create([
            'sku' => $sku, 'name' => $sku, 'manufacturer' => 'SIGNPROJECT',
            'catalog_price_net' => 0, 'purchase_price' => 0, 'discount_percent' => 0, 'currency' => 'PLN',
        ]);
    }

    private function variant(Product $product, string $remoteId, string $checkedAt): void
    {
        ProductVariant::query()->create([
            'product_id' => $product->id, 'source' => 'b2b:signproject', 'remote_id' => $remoteId,
            'label' => $remoteId, 'price_checked_at' => $checkedAt,
        ]);
    }

    private function fakeShop(): void
    {
        Http::fake(function (Request $request) {
            $url = $request->url();
            $path = (string) parse_url($url, PHP_URL_PATH);
            preg_match('/RSSID=([^;\s]+)/', $request->header('Cookie')[0] ?? '', $m);
            $loggedIn = isset($m[1]) && in_array($m[1], $this->validSessions, true);

            if ($path === '/pl/login.html') {
                return Http::response($this->fixture('login.html'), 200, ['Set-Cookie' => 'client=c0ffee; path=/; secure; HttpOnly']);
            }
            if ($path === '/signin.php') {
                if (($request['password'] ?? '') !== 'dobre-haslo') {
                    return Http::response($this->fixture('login.html'));
                }
                $this->logins++;
                $session = 'sess'.$this->logins;
                if (! $this->newSessionsInvalid) {
                    $this->validSessions[] = $session;
                }

                return Http::response('<html><body>'.self::LOGOUT_LINK.'</body></html>', 200, ['Set-Cookie' => 'RSSID='.$session.'; path=/; secure']);
            }
            if ($path === '/') {
                return Http::response('<html><body>'.($loggedIn ? self::LOGOUT_LINK : 'Zaloguj się').'</body></html>');
            }
            if ($path === '/sitemap.xml.gz') {
                return Http::response(gzencode($this->sitemapIndex()));
            }
            if ($path === '/sitemap-https-1-1.xml.gz') {
                return Http::response(gzencode($this->urlset(array_slice($this->sitemapUrls, 0, 3))));
            }
            if ($path === '/sitemap-https-1-2.xml.gz') {
                // już rozpakowana, jak bywa na serwerze
                return $this->secondSitemapFails
                    ? Http::response('', 500)
                    : Http::response($this->urlset(array_slice($this->sitemapUrls, 3)));
            }
            if ($path === '/ajax/projector.php') {
                $id = (int) $request['product'];
                $get = (string) $request['get'];
                if ($this->onProjector !== null) {
                    ($this->onProjector)($id, $get);
                    preg_match('/RSSID=([^;\s]+)/', $request->header('Cookie')[0] ?? '', $m);
                    $loggedIn = isset($m[1]) && in_array($m[1], $this->validSessions, true);
                }
                if ($get === 'sizes,sizeprices' && isset($this->failingPrices[$id])) {
                    return Http::response('', 500);
                }
                if (array_key_exists($id, $this->throttleOnce)) {
                    $retryAfter = $this->throttleOnce[$id];
                    unset($this->throttleOnce[$id]);

                    return Http::response('', 429, $retryAfter !== null ? ['Retry-After' => $retryAfter] : []);
                }

                return isset($this->catalog[$id])
                    ? Http::response($this->projectorJson($id, $get, $loggedIn))
                    : Http::response(['error' => 'brak'], 404);
            }
            if (str_ends_with($path, '.png')) {
                return $this->ogImageMissing && str_contains($path, 'pol_pl_')
                    ? Http::response('', 404)
                    : Http::response(self::PNG, 200, ['Content-Type' => 'image/png']);
            }
            if (isset($this->pages[$url])) {
                $marker = $loggedIn && ! $this->pageDropsMarkerOnce;
                $this->pageDropsMarkerOnce = false;

                return Http::response(str_replace('</body>', ($marker ? self::LOGOUT_LINK : '').'</body>', $this->pages[$url]));
            }

            return Http::response('nieznany adres w teście: '.$url, 404);
        });
    }

    private function sitemapIndex(): string
    {
        return '<?xml version="1.0" encoding="UTF-8"?><sitemapindex xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">'
            .'<sitemap><loc>https://signproject.pl/sitemap-https-1-1.xml.gz</loc></sitemap>'
            .'<sitemap><loc>https://signproject.pl/sitemap-https-1-2.xml.gz</loc></sitemap></sitemapindex>';
    }

    /**
     * @param  list<string>  $urls
     */
    private function urlset(array $urls): string
    {
        return '<?xml version="1.0" encoding="UTF-8"?><urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">'
            .implode('', array_map(static fn (string $u): string => '<url><loc>'.$u.'</loc></url>', $urls)).'</urlset>';
    }

    /**
     * Odpowiedź projector.php na wzór zapisanej ze sklepu (7109, anonimowo) z cenami zależnymi od sesji.
     *
     * @return array<string, mixed>
     */
    private function projectorJson(int $id, string $get, bool $loggedIn): array
    {
        $entry = $this->catalog[$id];
        $json = json_decode($this->fixture('projector_7109_anonymous.json'), true);
        $price = $loggedIn ? $entry['logged'] : $entry['anonymous'];

        $json['sizes']['id'] = $id;
        $json['sizes']['code'] = $entry['code'];
        $json['sizes']['name'] = $entry['product_name'];
        $json['sizes']['link'] = (string) parse_url($this->link($id), PHP_URL_PATH);
        $json['sizes']['items'] = $entry['items'] ?? $json['sizes']['items'];
        if (! isset($entry['items'])) {
            $json['sizes']['items']['00000-uniw']['prices']['price_net'] = $price;
        }
        $json['sizeprices']['price_net'] = number_format($price, 2, '.', '');
        $formatted = $entry['formatted'] ?? number_format($price, 2, ',', '').' zł';
        $json['sizeprices']['price_net_formatted'] = $formatted;
        $json['sizeprices']['price_formatted'] = $formatted;

        if (str_contains($get, 'versions') && count($entry['siblings']) > 1) {
            $json['versions'] = [
                'name' => $entry['header'],
                'items' => array_map(fn (int $sibling): array => [
                    'id' => $sibling,
                    'name' => $this->catalog[$sibling]['name'],
                    'link' => (string) parse_url($this->link($sibling), PHP_URL_PATH),
                    'gfx' => '',
                    'product_name' => $entry['product_name'],
                    'product_icon' => '',
                ], $entry['siblings']),
            ];
        }

        return $json;
    }
}
