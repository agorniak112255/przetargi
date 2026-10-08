<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Jobs\TranslateB2bProductTextJob;
use App\Models\B2bAccount;
use App\Models\B2bDiscountRule;
use App\Models\B2bProductLink;
use App\Models\B2bSyncRun;
use App\Models\CardRedirect;
use App\Models\PriceList;
use App\Models\Product;
use App\Models\ProductDocument;
use App\Models\ProductIdentifier;
use App\Models\ProductPriceHistory;
use App\Models\ProductSourcePrice;
use App\Models\ProductVariant;
use App\Services\B2b\B2bAccountSyncRunner;
use App\Services\B2b\B2bConnectorRegistry;
use App\Services\B2b\B2bFatalException;
use App\Services\B2b\B2bOrderQuantity;
use App\Services\B2b\B2bRemoteIdentifier;
use App\Services\B2b\B2bRemoteProduct;
use App\Services\B2b\B2bRemoteShopField;
use App\Services\B2b\UvexB2bClient;
use App\Services\B2b\UvexB2bConnector;
use App\Services\Catalog\CardRedirectStore;
use App\Services\Vector\ProductEmbeddingIndexer;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use RuntimeException;
use Tests\TestCase;
use Tests\Unit\UvexBasePriceListTest;

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

    /** Strona startowa atrapy bez odnośnika do cennika bazowego — podsumowanie przebiegu to zgłasza. */
    private const NO_PRICE_LIST_LINE = 'Cennik bazowy UVEX nie wczytany (brak odnośnika „Cennik do pobrania” do sklepu na stronie startowej konta) — ceny bazowe kart bez zmian z poprzedniego przebiegu';

    /**
     * Opis szyby P1D01 w sklepie dosłownie (skrócony o środkowe wiersze, strona z 08.10.2026): wiersze tabeli
     * rozdzielone <br>, kolumny tabulatorem, górne granice zakresów zapisane gołym „<”.
     */
    private const P1D01_TABLE = "<p>T: 6 ±0,3 | W x H: 915 x 610<br>\n\nwavelength (nm) \tOD \tOperating mode  / Tested protection level<br>\n"
        ."180 - 315 \t(OD10+) \tD LB10 + IR LB4 + M LB6Y<br>\n"
        ."3950 - <4700 \t(OD4+) \tDIM LB4 + R LB3Y<br>\n"
        ."4700 - <4765 \t(OD5+) \tDIM LB5 + R LB3Y<br>\n"
        ."4765 - <5200 \t(OD8+) \tDI LB5 + R LB3Y + M LB6Y<br>\n"
        ."5200 - 14500 \t(OD10+) \tDI LB5 + R LB3Y + M LB6Y<br>\n</p>";

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

    /** Adresy stron producenta, po które poszedł łącznik. */
    private array $manufacturerHits = [];

    /** Numery katalogowe wpisane w wyszukiwarkę producenta. */
    private array $searchHits = [];

    /** Czy wyszukiwarka producenta zna kartę o szukanym numerze. */
    private bool $manufacturerKnowsCode = true;

    /** Czy wstęp przy cenie powtarza tekst z zakładki „Description”. */
    private bool $manufacturerIntroRepeatsDescription = false;

    /** Numer katalogowy wypisany na stronie producenta („Order number: …”); null = strona go nie podaje. */
    private ?string $manufacturerOrderNumber = null;

    /** Czy strona producenta ma zakładkę „Description” (część kart ma opis tylko we wstępie przy cenie). */
    private bool $manufacturerHasDescriptionTab = true;

    /** Adresy pobranych zdjęć — po jednym wpisie na pobranie. */
    private array $imageHits = [];

    /** Czy strona producenta jest bez któregokolwiek bloku opisu (zmiana budowy sklepu). */
    private bool $emptyManufacturerPage = false;

    private int $logins = 0;

    private bool $dropSessionOnce = false;

    private bool $pagesWithoutSession = false;

    /** Ile kolejnych pobrań strony 2 listy ma podać inną liczbę produktów (produkt dodany w trakcie). */
    private int $changedTotalOnPage2 = 0;

    /** @var list<int> */
    private array $sleeps = [];

    /** HTML dopisany do strony startowej konta (np. odnośnik „Cennik do pobrania”). */
    private string $startPageExtra = '';

    /** Bajty cennika bazowego spod odnośnika; null = sklep odpowiada błędem 500. */
    private ?string $priceListXlsx = null;

    private int $priceListHits = 0;

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

    /**
     * Rozmiary jednego wyrobu to jedna karta. Do 28.09.2026 (decyzja 15.09.2026) trzewik 6935/2/38 (358,70 PLN) był
     * osobną kartą obok 6935/2/39–40 (360,40 PLN); od decyzji użytkownika 28.09.2026 to jedna karta z ceną każdego
     * rozmiaru przy jego pozycji, a cena karty (price()) = najniższa cena rozmiaru.
     */
    public function test_products_read_all_pages_and_merge_sizes_into_one_card(): void
    {
        $this->fakeSite();
        $connector = $this->connector();
        $messages = [];
        $connector->onListProgress(function (string $message) use (&$messages): void {
            $messages[] = $message;
        });
        $connector->login();

        $products = $this->productsByCode($connector);

        $this->assertSame(['000P1D011003', '6935/2/38', '8430/2/39', '9970.005', 'CENNIKI', 'HA2023(L)', 'HECKEL6273/3/36'], array_keys($products));
        $this->assertSame(7, $connector->totalProducts());
        foreach ([1, 2, 3] as $page) {
            Http::assertSent(static fn (Request $r): bool => $r->url() === 'https://izam.system-b2b.pl/public/product?query=%25&sort=code%2Basc&page='.$page);
        }
        Http::assertNotSent(static fn (Request $r): bool => str_ends_with($r->url(), 'page=4'));
        $this->assertContains('Lista produktów UVEX: 13 pozycji na 3 stronach', $messages);
        $this->assertContains('Lista UVEX: 13 pozycji → 7 kart (4 grup rozmiarów, w tym 1 z rozmiarami w różnych cenach — jedna karta, cena karty = najniższa cena rozmiaru, ceny rozmiarów w tabeli rozmiarów karty)', $messages);

        $shoes = $products['8430/2/39'];
        $this->assertSame('8430/2', $shoes->sku);
        $this->assertSame('Półbuty ochronne uvex 1 business 8430/2', $shoes->name);
        $this->assertSame('Dostępny: 39; Na zamówienie: 40, 41', $shoes->availability);
        $this->assertSame('Rozmiary: 39 (8430/2/39); 40 (8430/2/40); 41 (8430/2/41)', $shoes->variantSummary);
        $this->assertSame([
            ['8430/2/39', '8430/2/39', 'Półbuty ochronne uvex 1 business 8430/2/39', '39', 'Dostępny', 226.8, null, 'PLN'],
            ['8430/2/40', '8430/2/40', 'Półbuty ochronne uvex 1 business 8430/2/40', '40', 'Na zamówienie', 226.8, null, 'PLN'],
            ['8430/2/41', '8430/2/41', 'Półbuty ochronne uvex 1 business 8430/2/41', '41', 'Na zamówienie', 226.8, null, 'PLN'],
        ], self::memberRows($shoes));
        $this->assertStringStartsWith('https://izam.system-b2b.pl/public/product-details/', (string) $shoes->sourceUrl);
        $this->assertSame('UVEX', $connector->manufacturer($shoes));
        $this->assertSame(226.8, $connector->price($shoes)?->net);

        // 38 ma inną cenę niż 39 i 40 — ta sama karta (28.09.2026), cena karty = najtańszy rozmiar, ceny przy pozycjach
        $boots = $products['6935/2/38'];
        $this->assertSame('6935/2', $boots->sku);
        $this->assertSame('6935/2/38', $boots->remoteId);
        $this->assertSame('Trzewik uvex 2 trend 6935/2', $boots->name);
        $this->assertSame('Dostępny: 38, 39; Na zamówienie: 40', $boots->availability);
        $this->assertSame('Rozmiary: 38 (6935/2/38); 39 (6935/2/39); 40 (6935/2/40)', $boots->variantSummary);
        $this->assertSame([
            ['6935/2/38', '6935/2/38', 'Trzewik uvex 2 trend 6935/2/38', '38', 'Dostępny', 358.7, null, 'PLN'],
            ['6935/2/39', '6935/2/39', 'Trzewik uvex 2 trend 6935/2/39', '39', 'Dostępny', 360.4, null, 'PLN'],
            ['6935/2/40', '6935/2/40', 'Trzewik uvex 2 trend 6935/2/40', '40', 'Na zamówienie', 360.4, null, 'PLN'],
        ], self::memberRows($boots));
        $this->assertSame(358.7, $connector->price($boots)?->net);
        // cennik bazowy dopasowujemy tylko do rozmiarów w cenie karty
        $this->assertSame(['6935/2/38'], $boots->raw['card_price_codes']);
        $this->assertSame(['8430/2/39', '8430/2/40', '8430/2/41'], $shoes->raw['card_price_codes']);

        $gloves = $products['HA2023(L)'];
        $this->assertSame('Rękawice HexArmor Rig Lizard Arctic 2023', $gloves->name);
        $this->assertSame('Na zamówienie', $gloves->availability);
        $this->assertSame('Rozmiary: 8 (HA2023(M)); 9 (HA2023(L))', $gloves->variantSummary);
        $this->assertSame('HexArmor', $connector->manufacturer($gloves));

        $heckel = $products['HECKEL6273/3/36'];
        $this->assertSame('HECKEL 6273/3 SUXXED OFFROAD HIGH S3', $heckel->name);
        $this->assertSame('HECKEL', $connector->manufacturer($heckel));

        // identyfikatory: kod panelu każdej pozycji dosłownie; wyrób UVEX — kod producenta, HECKEL i HexArmor
        // (sklep UVEX tylko je sprzedaje) — kod źródła; rozmiar tylko na karcie z rozmiarami
        $this->assertSame([
            ['manufacturer_code', '8430/2/39', '8430/2/39', '39', 'Kod'],
            ['manufacturer_code', '8430/2/40', '8430/2/40', '40', 'Kod'],
            ['manufacturer_code', '8430/2/41', '8430/2/41', '41', 'Kod'],
        ], self::identifierRows($shoes));
        $this->assertSame([
            ['manufacturer_code', '6935/2/38', '6935/2/38', '38', 'Kod'],
            ['manufacturer_code', '6935/2/39', '6935/2/39', '39', 'Kod'],
            ['manufacturer_code', '6935/2/40', '6935/2/40', '40', 'Kod'],
        ], self::identifierRows($boots));
        // pozycja pojedyncza — bez ceny pozycji (karta bez tabeli rozmiarów, jak dotąd)
        $this->assertSame([['remote_id' => '9970.005', 'sku' => '9970.005', 'name' => 'Pojemnik mini na środki czyszczące - pełny 9970.005']], $products['9970.005']->members);
        $this->assertSame([['manufacturer_code', '9970.005', '9970.005', null, 'Kod']], self::identifierRows($products['9970.005']));
        $this->assertSame([
            ['source_code', 'HA2023(L)', 'HA2023(L)', '9', 'Kod'],
            ['source_code', 'HA2023(M)', 'HA2023(M)', '8', 'Kod'],
        ], self::identifierRows($gloves));
        $this->assertSame([
            ['source_code', 'HECKEL6273/3/36', 'HECKEL6273/3/36', '36', 'Kod'],
            ['source_code', 'HECKEL6273/3/37', 'HECKEL6273/3/37', '37', 'Kod'],
        ], self::identifierRows($heckel));
        foreach ($products as $product) {
            $positions = array_column($product->members, 'remote_id');
            foreach ($product->identifiers ?? [] as $identifier) {
                $this->assertContains($identifier->remoteId, $positions, 'identyfikator wskazuje pozycję spoza karty');
            }
        }

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

        $this->assertCount(7, $this->productsByCode($connector));
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

    public function test_description_is_verbatim_text_from_product_page_without_the_shop_table_data(): void
    {
        $this->fakeSite();
        $connector = $this->connector();
        $connector->login();
        $product = $this->productsByCode($connector)['9970.005'];

        $description = $connector->description($product);

        $this->assertSame(
            'Stacja czyszcząca do okularów i gogli. Zawiera: 2x chusteczki czyszczące (700 szt. w opakowaniu) 9971.000, 1x płyn czyszczący 9972.103, 1x pompkę dozującą 9973.101',
            $description,
        );
        // jednostka sprzedaży to dana z tabelki sklepu, nie zdanie opisu — jest na karcie wyrobu u dostawcy
        $this->assertStringNotContainsString('Jednostka', $description);
        $this->assertContains(
            ['Informacje handlowe', 'Jednostka sprzedaży', 'szt.'],
            self::rows($connector->shopFields($product)),
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

        $this->assertSame('', $description, 'karta czeka na opis, a nie dostaje samej jednostki sprzedaży');
    }

    public function test_empty_description_stays_empty_and_other_code_on_page_is_rejected(): void
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

        $this->assertSame('', $connector->description($products['9970.005']));
        $this->assertContains(
            ['Informacje handlowe', 'Jednostka sprzedaży', 'szt.'],
            self::rows($connector->shopFields($products['9970.005'])),
        );
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
        $this->assertStringStartsWith(self::PNG, $image->bytes);
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

    public function test_network_hiccup_is_retried_instead_of_failing_the_run(): void
    {
        Http::fake(['izam.system-b2b.pl/*' => Http::sequence()
            ->pushFailedConnection()
            ->push(self::PNG, 200, ['Content-Type' => 'image/png'])]);
        $client = new UvexB2bClient('K123', 'jan', 'dobre-haslo', 0, function (int $ms): void {
            $this->sleeps[] = $ms;
        });

        $file = $client->fileBytes('https://izam.system-b2b.pl/get-preview/zdjecie.jpg');

        $this->assertSame(self::PNG, $file['bytes'], 'po zerwanym połączeniu próbujemy jeszcze raz');
        $this->assertSame([2000], $this->sleeps, 'i czekamy przed ponowieniem');
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

        // trzewik 6935/2 w dwóch cenach to jedna karta (decyzja użytkownika 28.09.2026; wcześniej 8 pozycji listy i 7 kart)
        $this->assertSame(7, $result['total_remote']);
        $this->assertSame(6, $result['created']);
        $this->assertContains('CENNIKI: brak ceny w B2B', $result['errors']);
        $this->assertSame(6, Product::query()->count());

        $shoes = Product::query()->where('sku', '8430/2')->sole();
        $this->assertSame('Półbuty ochronne uvex 1 business 8430/2', $shoes->name);
        $this->assertSame('UVEX', $shoes->manufacturer);
        $this->assertSame('226.80', $shoes->purchase_price);
        $this->assertSame('PLN', $shoes->currency);
        $this->assertSame('Rozmiary: 39 (8430/2/39); 40 (8430/2/40); 41 (8430/2/41)', $shoes->variant_summary);
        $this->assertFalse(Product::query()->whereIn('sku', ['8430/2/40', '8430/2/41', '6935/2/39', '6935/2/40', 'HA2023(M)', 'HECKEL6273/3/37'])->exists());

        // rozmiary w różnych cenach: cena karty = najtańszy rozmiar, najwyższa przy slocie, każdy rozmiar w tabeli
        $boots = Product::query()->where('sku', '6935/2')->sole();
        $this->assertSame('Trzewik uvex 2 trend 6935/2', $boots->name);
        $this->assertSame('358.70', $boots->purchase_price);
        $bootsSlot = ProductSourcePrice::query()->where('product_id', $boots->id)->sole();
        $this->assertSame('358.70', (string) $bootsSlot->purchase_price);
        $this->assertSame('360.40', (string) $bootsSlot->size_price_max);
        $sizes = static fn (Product $card): array => ProductVariant::query()->where('product_id', $card->id)->where('kind', ProductVariant::KIND_SIZE)
            ->orderBy('sort_order')->get()->map(static fn (ProductVariant $v): array => [$v->label, (string) $v->purchase_price, $v->availability])->all();
        $this->assertSame([['38', '358.70', 'Dostępny'], ['39', '360.40', 'Dostępny'], ['40', '360.40', 'Na zamówienie']], $sizes($boots));
        $this->assertSame(['6935/2/38', '6935/2/39', '6935/2/40'], B2bProductLink::query()->where('product_id', $boots->id)->orderBy('remote_id')->pluck('remote_id')->all());
        // rozmiary w jednej cenie — też w tabeli, bez „do Y”
        $this->assertSame([['39', '226.80', 'Dostępny'], ['40', '226.80', 'Na zamówienie'], ['41', '226.80', 'Na zamówienie']], $sizes($shoes));
        $this->assertNull(ProductSourcePrice::query()->where('product_id', $shoes->id)->value('size_price_max'));
        // pozycja pojedyncza — bez tabeli rozmiarów
        $this->assertSame([], $sizes(Product::query()->where('sku', '9970.005')->sole()));

        $slot = ProductSourcePrice::query()->where('product_id', $shoes->id)->where('source_key', ProductSourcePrice::b2bKey((int) $this->account()->id))->sole();
        $this->assertSame('Dostępny: 39; Na zamówienie: 40, 41', $slot->availability);
        $this->assertSame(
            ['8430/2/39' => 'Półbuty ochronne uvex 1 business 8430/2/39', '8430/2/40' => 'Półbuty ochronne uvex 1 business 8430/2/40', '8430/2/41' => 'Półbuty ochronne uvex 1 business 8430/2/41'],
            B2bProductLink::query()->where('product_id', $shoes->id)->orderBy('remote_id')->pluck('remote_name', 'remote_id')->all(),
        );

        $cleaner = Product::query()->where('sku', '9970.005')->sole();
        $this->assertStringStartsWith('Stacja czyszcząca do okularów i gogli.', (string) $cleaner->description);
        $this->assertSame(str_replace('/public/get-preview/', '/get-preview/', self::IMG_9970), $cleaner->images()->firstOrFail()->source_url);
        $this->assertSame('HexArmor', Product::query()->where('sku', 'HA2023')->value('manufacturer'));
        $this->assertSame('HECKEL', Product::query()->where('sku', 'HECKEL6273/3')->value('manufacturer'));

        $log = array_column((array) B2bSyncRun::query()->latest('id')->firstOrFail()->log, 'text');
        $this->assertContains('Lista UVEX: 13 pozycji → 7 kart (4 grup rozmiarów, w tym 1 z rozmiarami w różnych cenach — jedna karta, cena karty = najniższa cena rozmiaru, ceny rozmiarów w tabeli rozmiarów karty)', $log);
    }

    public function test_order_quantity_is_read_from_the_cart_field_and_sizes_with_different_packs_get_none(): void
    {
        // jak na żywej stronie 24.09.2026: gogle 9307.375 tylko po 10 szt. (min="10.0000" step="10.0000")
        $this->rows[7] += ['min' => '10.0000', 'step' => '10.0000'];
        // półbuty: 39 i 40 po 2 pary, 41 bez ograniczenia — karta z trzech rozmiarów nie ma jednego warunku
        $this->rows[4] += ['min' => '2.0000', 'step' => '2.0000'];
        $this->rows[5] += ['min' => '2.0000', 'step' => '2.0000'];
        $this->fakeSite();
        $connector = $this->connector();
        $connector->login();
        $products = $this->productsByCode($connector);

        $cleaner = $connector->price($products['9970.005'])?->order;
        $this->assertNotNull($cleaner);
        $this->assertSame(['order_min_qty' => 10.0, 'order_step_qty' => 10.0, 'order_unit' => 'szt.', 'order_varies' => false], $cleaner->slotValues());
        $this->assertTrue($cleaner->restricts());
        $connector->description($products['9970.005']);
        $this->assertSame([
            ['Informacje handlowe', 'Kod', '9970.005'],
            ['Informacje handlowe', 'Jednostka sprzedaży', 'szt.'],
            ['Informacje handlowe', 'Zamawianie', 'po 10 szt.'],
        ], self::rows($connector->shopFields($products['9970.005'])));

        // min="1" step="any" — sklep nie ogranicza: minimum 1, bez kroku (na karcie dostawcy bez wiersza)
        $free = $connector->price($products['000P1D011003'])?->order;
        $this->assertSame(['order_min_qty' => 1.0, 'order_step_qty' => null, 'order_unit' => 'szt.', 'order_varies' => false], $free?->slotValues());
        $this->assertFalse($free->restricts());

        // rozmiary z różnymi paczkami: warunku nie przypisujemy karcie, na karcie dostawcy każdy rozmiar osobno
        $shoes = $connector->price($products['8430/2/39'])?->order;
        $this->assertSame(['order_min_qty' => null, 'order_step_qty' => null, 'order_unit' => 'par.', 'order_varies' => true], $shoes?->slotValues());
        $this->assertSame('po 2: 39, 40; bez ograniczeń: 41', $products['8430/2/39']->raw['order']['by_size']);
        // ten sam warunek we wszystkich rozmiarach — jeden warunek karty
        $this->assertSame(['min' => 1.0, 'step' => null, 'by_size' => null], $products['HA2023(L)']->raw['order']);
    }

    public function test_order_quantity_attribute_and_format(): void
    {
        $this->assertSame(10.0, B2bOrderQuantity::attribute('10.0000'));
        $this->assertSame(2.5, B2bOrderQuantity::attribute('2,5'));
        $this->assertNull(B2bOrderQuantity::attribute('any'));
        $this->assertNull(B2bOrderQuantity::attribute(''));
        $this->assertNull(B2bOrderQuantity::attribute('0'));
        $this->assertSame('10', B2bOrderQuantity::format(10.0));
        $this->assertSame('2,5', B2bOrderQuantity::format(2.5));
        $this->assertFalse((new B2bOrderQuantity(1.0, 1.0))->restricts());
        $this->assertTrue((new B2bOrderQuantity(1.0, 5.0))->restricts());
    }

    public function test_sync_stores_the_order_quantity_in_the_account_slot_and_updates_it_when_the_shop_changes(): void
    {
        Storage::fake('public');
        $this->rows[7] += ['min' => '10.0000', 'step' => '10.0000'];
        $this->fakeSite();
        $slotOf = fn (): ProductSourcePrice => ProductSourcePrice::query()
            ->where('product_id', Product::query()->where('sku', '9970.005')->value('id'))
            ->where('source_key', ProductSourcePrice::b2bKey((int) $this->account()->id))
            ->sole();

        app(B2bAccountSyncRunner::class)->run($this->account(), delayMs: 0);

        $slot = $slotOf();
        $this->assertSame(10.0, $slot->order_min_qty);
        $this->assertSame(10.0, $slot->order_step_qty);
        $this->assertSame('szt.', $slot->order_unit);

        // sklep zmienia paczkę na 8 szt. — zmiana warunku to aktualizacja karty, nie „bez zmian”
        $this->rows[7]['min'] = '8.0000';
        $this->rows[7]['step'] = '8.0000';
        app(B2bAccountSyncRunner::class)->run($this->account(), delayMs: 0);

        $this->assertSame(8.0, $slotOf()->order_step_qty);
        $updated = collect(PriceList::query()->latest('id')->firstOrFail()->updated_products)->firstWhere('sku', '9970.005');
        $this->assertNotNull($updated);
        $this->assertContains('warunek zamawiania', $updated['fields']);

        // warunek zniknął ze sklepu (step="any") — zapis czyści krok, nie zostawia starego
        $this->rows[7]['min'] = '1';
        $this->rows[7]['step'] = 'any';
        app(B2bAccountSyncRunner::class)->run($this->account(), delayMs: 0);

        $this->assertSame(1.0, $slotOf()->order_min_qty);
        $this->assertNull($slotOf()->order_step_qty);
    }

    public function test_first_sync_after_the_order_columns_appear_does_not_report_every_card_as_changed(): void
    {
        Storage::fake('public');
        $this->fakeSite();
        app(B2bAccountSyncRunner::class)->run($this->account(), delayMs: 0);
        $baseline = app(B2bAccountSyncRunner::class)->run($this->account(), delayMs: 0)['updated'];

        // sloty sprzed wdrożenia: warunku nie ma; sklep podaje min="1" step="any" — to nie jest zmiana
        ProductSourcePrice::query()->update(['order_min_qty' => null, 'order_step_qty' => null, 'order_unit' => null]);
        $result = app(B2bAccountSyncRunner::class)->run($this->account(), delayMs: 0);

        $this->assertSame($baseline, $result['updated']);
        $this->assertSame(1.0, ProductSourcePrice::query()->whereHas('product', fn ($q) => $q->where('sku', '9970.005'))->firstOrFail()->order_min_qty);
    }

    public function test_sync_stores_panel_codes_as_identifiers_and_the_second_run_neither_duplicates_nor_removes_them(): void
    {
        Storage::fake('public');
        $this->fakeSite();

        $first = app(B2bAccountSyncRunner::class)->run($this->account(), delayMs: 0);

        $this->assertSame(6, $first['created']);
        $shoes = Product::query()->where('sku', '8430/2')->sole();
        $this->assertSame([
            ['8430/2/39', 'manufacturer_code', '8430/2/39', '39', 'Kod', 'UVEX'],
            ['8430/2/40', 'manufacturer_code', '8430/2/40', '40', 'Kod', 'UVEX'],
            ['8430/2/41', 'manufacturer_code', '8430/2/41', '41', 'Kod', 'UVEX'],
        ], self::storedIdentifiers($shoes->id));
        $this->assertSame(
            [['HECKEL6273/3/36', 'source_code', 'HECKEL6273/3/36', '36', 'Kod', 'HECKEL'], ['HECKEL6273/3/37', 'source_code', 'HECKEL6273/3/37', '37', 'Kod', 'HECKEL']],
            self::storedIdentifiers((int) Product::query()->where('sku', 'HECKEL6273/3')->value('id')),
        );
        $this->assertSame(
            [['HA2023(L)', 'source_code', 'HA2023(L)', '9', 'Kod', 'HexArmor'], ['HA2023(M)', 'source_code', 'HA2023(M)', '8', 'Kod', 'HexArmor']],
            self::storedIdentifiers((int) Product::query()->where('sku', 'HA2023')->value('id')),
        );
        $this->assertSame(
            [['9970.005', 'manufacturer_code', '9970.005', null, 'Kod', 'UVEX']],
            self::storedIdentifiers((int) Product::query()->where('sku', '9970.005')->value('id')),
        );
        // 6 kart, 12 pozycji z ceną (CENNIKI bez ceny nie jest zapisywany)
        $identifiers = ProductIdentifier::query()->count();
        $this->assertSame(12, $identifiers);
        $this->assertNoIdentifierWarnings($first);

        $descriptions = Product::query()->orderBy('id')->pluck('description', 'id')->all();
        $second = app(B2bAccountSyncRunner::class)->run($this->account(), delayMs: 0);

        $this->assertSame(0, $second['created']);
        $this->assertSame($identifiers, ProductIdentifier::query()->count());
        $this->assertSame(0, ProductIdentifier::query()->whereNotNull('removed_at')->count());
        $this->assertSame($descriptions, Product::query()->orderBy('id')->pluck('description', 'id')->all());
        $this->assertNoIdentifierWarnings($second);
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

    public function test_datasheet_of_another_model_is_kept_as_another_document(): void
    {
        // audyt 08.10.2026: hełm elektroizolacyjny pronamic E-WR 9730.xxx miał w sklepie tylko kartę wentylowanego
        // B-WR 9731.030 — bez EN 50365; plik zostaje przy karcie, ale nie jako jej karta techniczna
        $this->details['4713'] = str_replace('SST stacja czyszcząca mini 9970.005', 'SST uvex pronamic B-WR 9731.030', $this->details['4713']);
        $this->fakeSite();
        $connector = $this->connector();
        $connector->login();

        $documents = $connector->documents($this->productsByCode($connector)['9970.005']);

        $this->assertSame(['other', 'other'], array_map(static fn ($d): string => $d->kind, $documents));
        $this->assertContains(
            'Plik PDF innego modelu przy karcie — zapisany jako inny dokument, nie karta techniczna: 1 (9970.005 → SST uvex pronamic B-WR 9731.030.pdf (model 9731, karta 9970))',
            $connector->runSummary(),
        );
    }

    public function test_stored_datasheet_of_another_model_becomes_another_document_at_the_next_sync(): void
    {
        // wiersz z produkcji: plik innego modelu zapisany jako karta techniczna, z tekstem w embeddingu
        Storage::fake('public');
        $this->details['4713'] = str_replace('SST stacja czyszcząca mini 9970.005', 'SST uvex 2 trend 6936.8', $this->details['4713']);
        $this->fakeSite();
        app(B2bAccountSyncRunner::class)->run($this->account(), delayMs: 0);
        $cleaner = Product::query()->where('sku', '9970.005')->sole();
        $document = ProductDocument::query()->where('product_id', $cleaner->id)->orderBy('sort_order')->firstOrFail();
        $document->forceFill(['kind' => ProductDocument::KIND_DATASHEET, 'text' => 'EN ISO 20345:2011 S3 SRC 6936.8'])->save();
        $this->assertStringContainsString('6936.8', app(ProductEmbeddingIndexer::class)->documentText($cleaner->fresh()));

        app(B2bAccountSyncRunner::class)->run($this->account(), delayMs: 0);

        $this->assertSame(ProductDocument::KIND_OTHER, $document->fresh()?->kind);
        $this->assertSame('EN ISO 20345:2011 S3 SRC 6936.8', $document->fresh()?->text, 'plik i jego tekst zostają do wglądu');
        // tekst karty innego modelu wypada z dokumentu wyszukiwania (zmiana rodzaju zleca przeliczenie wektora)
        $this->assertStringNotContainsString('6936.8', app(ProductEmbeddingIndexer::class)->documentText($cleaner->fresh()));
    }

    public function test_file_of_another_model_is_recognised_by_the_model_number_only(): void
    {
        $this->assertSame('model 9731, karta 9730', UvexB2bConnector::foreignModelFile('SST uvex pronamic B-WR 9731.030.pdf', ['9730.330', '9730.330']));
        $this->assertSame('model 6936, karta 6937', UvexB2bConnector::foreignModelFile('SST uvex 2 trend 6936.8', ['6937/8', '6937/8/40']));
        // ten sam model w innym wariancie, zakres wariantów, lista wariantów — to nadal plik tego modelu
        $this->assertNull(UvexB2bConnector::foreignModelFile('SST pronamic E-WR 9730.030', ['9730.330']));
        $this->assertNull(UvexB2bConnector::foreignModelFile('SST uvex i-3 2124.017-20', ['2124.021']));
        $this->assertNull(UvexB2bConnector::foreignModelFile('SST uvex hi-com 2112.100/101/120/106', ['2112.103']));
        $this->assertNull(UvexB2bConnector::foreignModelFile('SST 8534.pdf', ['8534/8/52']), 'nazwa bez wariantu nic nie rozstrzyga');
        // nie kody modeli: rozporządzenie, normy, kody rękawic i laserów; karta bez numeru modelu UVEX
        $this->assertNull(UvexB2bConnector::foreignModelFile('Deklaracja UE 2016/425 EN 1149-5 ISO 20345:2011', ['6937/8']));
        $this->assertNull(UvexB2bConnector::foreignModelFile('SST C300 dry 60549.pdf', ['60549']));
        $this->assertNull(UvexB2bConnector::foreignModelFile('lv details F22.P1M02.1001.pdf', ['F22P1M031001']));
        $this->assertNull(UvexB2bConnector::foreignModelFile('SST uvex 2 trend 6936.8', ['HECKEL6273/3/36', 'HA2023(L)']));
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

    public function test_sync_saves_product_files_and_keeps_the_datasheet_text_out_of_the_description(): void
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

        // tekst karty technicznej zostaje przy pliku — opis wyrobu to proza ze sklepu, nie treść PDF-a
        $datasheet = $documents->firstOrFail();
        $this->assertStringContainsString('EN ISO 20345:2011 S1 P SRC', (string) $datasheet->text);
        $this->assertNull($documents->last()->text, 'skan bez warstwy tekstowej nie dostaje tekstu');

        $this->assertStringStartsWith('Stacja czyszcząca do okularów i gogli.', (string) $cleaner->description);
        $this->assertStringNotContainsString('Z karty technicznej (', (string) $cleaner->description);
        $this->assertStringNotContainsString('EN ISO 20345:2011 S1 P SRC', (string) $cleaner->description);
        // wyszukiwanie nic nie traci: karta techniczna wchodzi do dokumentu embeddingu
        $this->assertStringContainsString(
            'EN ISO 20345:2011 S1 P SRC',
            app(ProductEmbeddingIndexer::class)->documentText($cleaner->fresh()),
        );

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

    public function test_description_comes_from_the_manufacturer_page_when_the_shop_only_links_to_it(): void
    {
        $this->linkInsteadOfDescription('https://www.uvex-laservision.de/en/laser-safety-windows/laser-safety-window-p1p10-3mm/9970.005');
        $this->fakeSite();
        $connector = $this->connector();
        $connector->login();
        $product = $this->productsByCode($connector)['9970.005'];

        $description = $connector->description($product);

        $this->assertStringContainsString('Opis ze strony producenta (www.uvex-laservision.de):', $description);
        $this->assertStringContainsString('The laser safety window P1P10 is a new blue absorbing laser protection filter without additional reflective coating.', $description);
        // wstęp przy cenie dokłada fakty, których nie ma w zakładce (maksymalny rozmiar, grubość)
        $this->assertStringContainsString('user specific available up to a size of 1219x915mm', $description);
        // opis to sama proza: tabele strony producenta i jednostka sprzedaży są na karcie wyrobu u dostawcy
        $this->assertStringNotContainsString('Jednostka', $description);
        $this->assertStringNotContainsString('Dane techniczne:', $description);
        $this->assertStringNotContainsString('Filter material', $description);
        $this->assertStringNotContainsString('Protection range', $description);
        $this->assertStringNotContainsString('Parametry:', $description);
        $this->assertStringNotContainsString('180 - 315', $description);
        // sam nagłówek tabeli nie jest zdaniem opisu
        $this->assertStringNotContainsString("\nSpecifications", $description);
        $this->assertTrue($connector->hasForeignDescription($product), 'opis po angielsku idzie do tłumaczenia');

        $rows = self::rows($connector->shopFields($product));

        $this->assertContains(['Informacje handlowe', 'Jednostka sprzedaży', 'szt.'], $rows);
        $this->assertContains(['Dane techniczne', 'Filter material', 'Plastic'], $rows);
        $this->assertContains(
            ['Dane techniczne', 'Protection Class / Norm', 'EN 207 full protection, EN 208 Alignment protection, EN 60825'],
            $rows,
        );
        // boczny blok z granicami widma — bez niego karta ma same nazwy zakresów, bez liczb
        $this->assertContains(
            ['Ochrona', 'ultraviolett', 'Protection within the ultraviolet spectral range between 180 and 400nm'],
            $rows,
        );
        $this->assertContains(
            ['Ochrona', 'visible', 'Protection within the visible spectral range between 400 and 700nm'],
            $rows,
        );
        // poziomy ochrony dosłownie, kolumnami
        $this->assertContains(
            ['Ochrona', 'Poziomy ochrony', 'Długość fali (nm) | OD | Tryb pracy / badany stopień ochrony'],
            $rows,
        );
        $this->assertContains(['Ochrona', 'Poziomy ochrony', '180 - 315 | (OD10+) | D LB10 + IR LB4 + M LB6'], $rows);
        $this->assertContains(['Ochrona', 'Poziomy ochrony', '>315 - 385 | (OD8+) | D LB6 + IRM LB8'], $rows);
        // opis i karta wyrobu u dostawcy czytają tę samą kopię strony
        $this->assertCount(1, $this->manufacturerHits);
    }

    public function test_wrong_link_is_replaced_by_the_page_found_by_product_code(): void
    {
        // sklep odsyła część kart pod adres innego filtra — właściwej karty szukamy po numerze katalogowym
        $this->linkInsteadOfDescription('https://www.uvex-laservision.de/en/laser-safety-windows/laser-safety-window-p1p10-3mm/000P1P102001');
        $this->fakeSite();
        $connector = $this->connector();
        $connector->login();
        $product = $this->productsByCode($connector)['9970.005'];

        $description = $connector->description($product);

        $this->assertSame(['9970.005'], $this->searchHits, 'szukamy po numerze katalogowym karty');
        $this->assertSame(
            ['https://www.uvex-laservision.de/en/laser-safety-windows/cleaning-station/9970.005'],
            $this->manufacturerHits,
            'pobieramy tylko stronę o zgodnym numerze',
        );
        $this->assertStringContainsString('Opis ze strony producenta (www.uvex-laservision.de):', $description);
        $this->assertTrue($connector->hasForeignDescription($product));
        $this->assertSame(
            [self::NO_PRICE_LIST_LINE, 'Odnośnik ze sklepu prowadził do strony innego wyrobu, właściwą znaleziono po numerze katalogowym: 1 kart'],
            $connector->runSummary(),
        );
    }

    public function test_card_stays_without_description_when_the_manufacturer_has_no_such_code(): void
    {
        $this->manufacturerKnowsCode = false;
        $this->linkInsteadOfDescription('https://www.uvex-laservision.de/en/laser-safety-windows/laser-safety-window-p1p10-3mm/000P1P102001');
        $this->fakeSite();
        $connector = $this->connector();
        $connector->login();
        $product = $this->productsByCode($connector)['9970.005'];

        $description = $connector->description($product);

        $this->assertSame('', $description, 'karta zostaje bez opisu producenta');
        $this->assertSame([], $this->manufacturerHits, 'strony innego wyrobu nie pobieramy');
        $this->assertFalse($connector->hasForeignDescription($product));
        $this->assertSame(
            [self::NO_PRICE_LIST_LINE, 'Odnośnik ze sklepu prowadził do strony innego wyrobu — opisu nie pobrano dla 1 kart (9970.005 → 000P1P102001)'],
            $connector->runSummary(),
        );
    }

    public function test_description_from_a_wrong_page_is_removed_at_the_next_sync(): void
    {
        Storage::fake('public');
        $this->linkInsteadOfDescription('https://www.uvex-laservision.de/en/laser-safety-windows/laser-safety-window-p1p10-3mm/9970.005');
        $this->fakeSite();
        app(B2bAccountSyncRunner::class)->run($this->account(), delayMs: 0);

        $cleaner = Product::query()->where('sku', '9970.005')->sole();
        $this->assertStringContainsString('The laser safety window P1P10', (string) $cleaner->description);

        // sklep zmienił odnośnik na stronę innego wyrobu, a producent nie zna tego numeru — cudzy opis ma zniknąć
        $this->manufacturerKnowsCode = false;
        $this->details['4713'] = str_replace(
            '/laser-safety-window-p1p10-3mm/9970.005',
            '/laser-safety-window-p1p10-3mm/000P1P102001',
            $this->details['4713'],
        );

        $result = app(B2bAccountSyncRunner::class)->run($this->account(), delayMs: 0);

        $description = (string) Product::query()->whereKey($cleaner->id)->value('description');
        $this->assertStringNotContainsString('laser safety window', $description);
        $this->assertStringNotContainsString('Opis ze strony producenta', $description);
        // karta zostaje bez opisu: jedynym jej opisem była treść, której producent już nie ma. Tekst karty
        // technicznej nie jest opisem — zostaje przy pliku i w dokumencie embeddingu
        $this->assertSame('', $description);
        $this->assertStringContainsString(
            'EN ISO 20345:2011 S1 P SRC',
            (string) ProductDocument::query()->where('product_id', $cleaner->id)->orderBy('sort_order')->value('text'),
        );

        $log = array_column((array) B2bSyncRun::query()->findOrFail($result['sync_run_id'])->log, 'text');
        $this->assertContains(
            'Odnośnik ze sklepu prowadził do strony innego wyrobu — opisu nie pobrano dla 1 kart (9970.005 → 000P1P102001)',
            $log,
        );
    }

    public function test_product_code_decides_whether_the_manufacturer_page_belongs_to_the_card(): void
    {
        // ten sam wyrób, inny rozmiar albo wariant
        $this->assertTrue(UvexB2bConnector::sameProduct('000P1P102001', '000P1P102004'));
        $this->assertTrue(UvexB2bConnector::sameProduct('000P1P102607', '000P1P102602'));
        $this->assertTrue(UvexB2bConnector::sameProduct('9970.005', '9970.005'));
        // inny filtr: P621 i P1N01 pod adresem P1P10
        $this->assertFalse(UvexB2bConnector::sameProduct('000P1P102001', '000P6P212003'));
        $this->assertFalse(UvexB2bConnector::sameProduct('000P1P102001', '000P1N011005'));
        // krótkie numery muszą zgadzać się w całości
        $this->assertFalse(UvexB2bConnector::sameProduct('9970.005', '9970.006'));
        $this->assertFalse(UvexB2bConnector::sameProduct('', '9970.005'));
    }

    public function test_repeated_intro_is_not_written_twice(): void
    {
        $this->manufacturerIntroRepeatsDescription = true;
        $this->linkInsteadOfDescription('https://www.uvex-laservision.de/en/laser-safety-windows/laser-safety-window-p1p10-3mm/9970.005');
        $this->fakeSite();
        $connector = $this->connector();
        $connector->login();

        $description = $connector->description($this->productsByCode($connector)['9970.005']);

        $this->assertSame(
            1,
            mb_substr_count($description, 'blue absorbing laser protection filter'),
            'ten sam akapit nie może wejść na kartę dwa razy',
        );
    }

    public function test_link_outside_the_manufacturer_domains_is_not_followed(): void
    {
        $this->linkInsteadOfDescription('https://przypadkowa-domena.test/opis');
        $this->fakeSite();
        $connector = $this->connector();
        $connector->login();
        $product = $this->productsByCode($connector)['9970.005'];

        $description = $connector->description($product);

        $this->assertSame('', $description);
        $this->assertFalse($connector->hasForeignDescription($product));
        $this->assertSame([], $this->manufacturerHits);
    }

    public function test_sync_orders_a_translation_only_for_the_card_with_the_english_description(): void
    {
        Storage::fake('public');
        $this->linkInsteadOfDescription('https://www.uvex-laservision.de/en/laser-safety-windows/laser-safety-window-p1p10-3mm/9970.005');
        $this->fakeSite();

        app(B2bAccountSyncRunner::class)->run($this->account(), delayMs: 0);

        $cleaner = Product::query()->where('sku', '9970.005')->sole();
        $this->assertStringContainsString('The laser safety window P1P10', (string) $cleaner->description);

        Queue::assertPushed(
            TranslateB2bProductTextJob::class,
            static fn (TranslateB2bProductTextJob $job): bool => $job->productId === $cleaner->id,
        );
        Queue::assertPushed(TranslateB2bProductTextJob::class, 1);
    }

    public function test_page_reached_by_its_name_belongs_to_the_card_when_its_order_number_matches(): void
    {
        // akcesoria mają w sklepie producenta adres ze skrótu nazwy, bez numeru katalogowego — o przynależności
        // strony mówi wtedy numer wypisany przy cenie
        $this->manufacturerOrderNumber = '9970.005';
        $this->linkInsteadOfDescription('https://www.uvex-laservision.de/en/laser-safety-eyewear/accessories/cushion-frame-with-lip-seal/?number=9970.005');
        $this->fakeSite();
        $connector = $this->connector();
        $connector->login();
        $product = $this->productsByCode($connector)['9970.005'];

        $description = $connector->description($product);

        $this->assertStringContainsString('Opis ze strony producenta (www.uvex-laservision.de):', $description);
        $this->assertSame([], $this->searchHits, 'strona jest tego wyrobu — nie ma czego szukać');
        $this->assertCount(1, $this->manufacturerHits);
        $this->assertTrue($connector->hasForeignDescription($product));
    }

    public function test_page_with_another_order_number_is_rejected_even_when_its_address_fits(): void
    {
        // adres zgodny z kodem karty, ale sklep pokazuje pod nim inny wyrób — cudzy opis nie może wejść na kartę
        $this->manufacturerOrderNumber = '000P1P102001';
        $this->manufacturerKnowsCode = false;
        $this->linkInsteadOfDescription('https://www.uvex-laservision.de/en/laser-safety-windows/cleaning-station/9970.005');
        $this->fakeSite();
        $connector = $this->connector();
        $connector->login();
        $product = $this->productsByCode($connector)['9970.005'];

        $description = $connector->description($product);

        $this->assertSame('', $description);
        $this->assertSame(['9970.005'], $this->searchHits, 'właściwej strony szukamy po numerze katalogowym karty');
        $this->assertFalse($connector->hasForeignDescription($product));
    }

    public function test_description_is_taken_from_above_the_price_when_the_page_has_no_description_tab(): void
    {
        // strona akcesorium ma opis tylko we wstępie przy cenie — zakładki „Description” nie ma wcale
        $this->manufacturerHasDescriptionTab = false;
        $this->manufacturerOrderNumber = '9970.005';
        $this->linkInsteadOfDescription('https://www.uvex-laservision.de/en/laser-safety-eyewear/accessories/cushion-frame-with-lip-seal/');
        $this->fakeSite();
        $connector = $this->connector();
        $connector->login();
        $product = $this->productsByCode($connector)['9970.005'];

        $description = $connector->description($product);

        $this->assertStringContainsString(
            "Opis ze strony producenta (www.uvex-laservision.de):\nThe laservision plastic laser safety window P1P10",
            $description,
        );
        $this->assertStringNotContainsString('Dane techniczne:', $description);
        $this->assertStringNotContainsString('Jednostka', $description);
        $this->assertContains(
            ['Dane techniczne', 'Filter material', 'Plastic'],
            self::rows($connector->shopFields($product)),
        );
        $this->assertTrue($connector->hasForeignDescription($product), 'wstęp też jest po angielsku');
    }

    public function test_page_without_any_description_block_is_reported_instead_of_being_taken_for_the_card(): void
    {
        $this->manufacturerHasDescriptionTab = false;
        $this->manufacturerOrderNumber = '9970.005';
        $this->emptyManufacturerPage = true;
        $this->linkInsteadOfDescription('https://www.uvex-laservision.de/en/laser-safety-eyewear/accessories/cushion-frame-with-lip-seal/');
        $this->fakeSite();
        $connector = $this->connector();
        $connector->login();
        $product = $this->productsByCode($connector)['9970.005'];

        $this->expectExceptionMessage('nie ma opisu w spodziewanym miejscu');
        $connector->description($product);
    }

    public function test_less_than_sign_in_the_shop_text_stays_text_and_does_not_swallow_the_rest(): void
    {
        // audyt 08.10.2026, karta 8534/8: libxml 2.9 z produkcji brał „<35” za znacznik i ucinał opis na
        // „rezystancja skrośna” — tekst do najbliższego „>” znikał (lokalny libxml 2.10 tego nie robi)
        $this->details['4713'] = str_replace(
            '<p>Stacja czyszcząca do okularów i gogli. Zawiera: 2x chusteczki czyszczące (700 szt. w opakowaniu) 9971.000, 1x płyn czyszczący 9972.103, 1x pompkę dozującą 9973.101</p>',
            '<p>Niezwykle lekki, uniwersalny półbut ochronny S2 wykonany z materiałów syntetycznych. Zgodność z wymaganiami względem ESD, rezystancja skrośna <35 megaomów, praktycznie bezszwowa budowa z użyciem zaawansowanego mikroweluru pozwala wyeliminować punkty ucisku.</p>',
            $this->details['4713'],
        );
        $this->fakeSite();
        $connector = $this->connector();
        $connector->login();

        $this->assertSame(
            'Niezwykle lekki, uniwersalny półbut ochronny S2 wykonany z materiałów syntetycznych. Zgodność z wymaganiami względem ESD, rezystancja skrośna <35 megaomów, praktycznie bezszwowa budowa z użyciem zaawansowanego mikroweluru pozwala wyeliminować punkty ucisku.',
            $connector->description($this->productsByCode($connector)['9970.005']),
        );
    }

    public function test_laser_table_keeps_every_wavelength_range_with_its_own_protection_level(): void
    {
        // szyba P1D01 (audyt 08.10.2026): cztery zakresy „3950 - <4700 … OD4+” sklejały się w jeden wiersz
        // „3950 - 4700 - 4765 - 5200 - 14500 (OD10+)” — zawyżony stopień ochrony
        $this->manufacturerOrderNumber = '000P1D011001';
        $this->laserCardText(self::P1D01_TABLE, 'https://www.uvex-laservision.de/en/laser-safety-windows/plastic-laser-safety-windows/laser-safety-window-p1d01/');
        $this->fakeSite();
        $connector = $this->connector();
        $connector->login();

        $this->assertSame(
            "T: 6 ±0,3 | W x H: 915 x 610\n"
            ."wavelength (nm) OD Operating mode / Tested protection level\n"
            ."180 - 315 (OD10+) D LB10 + IR LB4 + M LB6Y\n"
            ."3950 - <4700 (OD4+) DIM LB4 + R LB3Y\n"
            ."4700 - <4765 (OD5+) DIM LB5 + R LB3Y\n"
            ."4765 - <5200 (OD8+) DI LB5 + R LB3Y + M LB6Y\n"
            .'5200 - 14500 (OD10+) DI LB5 + R LB3Y + M LB6Y',
            $connector->description($this->productsByCode($connector)['000P1D011003']),
        );
        // odnośnik prowadzi do strony tego samego filtra — tekst sklepu zostaje, nikt nie szuka po numerze
        $this->assertSame([], $this->searchHits);
        $this->assertSame([self::NO_PRICE_LIST_LINE], $connector->runSummary());
    }

    public function test_shop_text_linking_to_the_page_of_another_filter_is_not_taken_for_the_card(): void
    {
        // szyba P1H09 (audyt 08.10.2026) miała w sklepie tabelę P1D01 i odnośnik do strony P1D01 — strona
        // producenta sama podaje numer innego wyrobu, więc tekst obok odnośnika też jest cudzy
        $this->manufacturerOrderNumber = '000P1H091001';
        $this->laserCardText(self::P1D01_TABLE, 'https://www.uvex-laservision.de/en/laser-safety-windows/plastic-laser-safety-windows/laser-safety-window-p1h09/');
        $this->fakeSite();
        $connector = $this->connector();
        $connector->login();
        $product = $this->productsByCode($connector)['000P1D011003'];

        $this->assertSame('', $connector->description($product), 'karta czeka na opis zamiast nosić tabelę innego filtra');
        $this->assertSame(['000P1D011003'], $this->searchHits, 'właściwej strony szukamy po numerze karty');
        $this->assertContains(
            'Opis w sklepie opisuje inny wyrób — pominięty dla 1 kart (000P1D011003 (odnośnik do strony innego wyrobu: laser-safety-window-p1h09))',
            $connector->runSummary(),
        );
    }

    public function test_shop_text_stays_when_the_linked_page_does_not_say_whose_it_is(): void
    {
        // strona bez numeru katalogowego, adres-skrót nazwy: nic nie mówi, że to inny wyrób — tekst sklepu zostaje
        $this->manufacturerOrderNumber = null;
        $this->laserCardText(self::P1D01_TABLE, 'https://www.uvex-laservision.de/en/laser-safety-windows/plastic-laser-safety-windows/laser-safety-window-p1d01/');
        $this->fakeSite();
        $connector = $this->connector();
        $connector->login();

        $description = $connector->description($this->productsByCode($connector)['000P1D011003']);

        $this->assertStringContainsString('3950 - <4700 (OD4+) DIM LB4 + R LB3Y', $description);
        $this->assertSame([], array_values(array_filter(
            $connector->runSummary(),
            static fn (string $line): bool => str_starts_with($line, 'Opis w sklepie opisuje inny wyrób'),
        )));
    }

    public function test_shop_text_naming_only_another_filter_is_not_taken_for_the_card(): void
    {
        // okulary F22.P1M03 (audyt 08.10.2026) miały w sklepie opis „F22.P1M02.1001” — zakresy fal innego filtra
        $this->laserCardText('<p>Okulary chroniące przed promieniowaniem laserowym F22.P1M02.1001 z standardowymi zausznikami nadają się do pracy z laserami CO2. Filtr P1M02 jest uważany za filtr wąskopasmowy.</p>');
        $this->fakeSite();
        $connector = $this->connector();
        $connector->login();

        $this->assertSame('', $connector->description($this->productsByCode($connector)['000P1D011003']));
        $this->assertSame([], $this->manufacturerHits, 'karta nie odsyła do producenta — nie ma czego pobierać');
        $this->assertContains(
            'Opis w sklepie opisuje inny wyrób — pominięty dla 1 kart (000P1D011003 (tekst podaje filtr P1M02, karta P1D01))',
            $connector->runSummary(),
        );
    }

    public function test_shop_text_naming_its_own_filter_next_to_another_one_stays(): void
    {
        $text = 'Szyba z filtrem P1D01 do laserów CO2; do laserów zielonych producent poleca filtr P1P10.';
        $this->laserCardText('<p>'.$text.'</p>');
        $this->fakeSite();
        $connector = $this->connector();
        $connector->login();

        $this->assertSame($text, $connector->description($this->productsByCode($connector)['000P1D011003']));
    }

    public function test_filter_designations_are_read_also_inside_catalogue_numbers(): void
    {
        $this->assertSame(['P1D01'], UvexB2bConnector::filterCodes('000P1D011003'));
        $this->assertSame(['P1P23'], UvexB2bConnector::filterCodes('FS1P1P231003'));
        $this->assertSame(['P1M02', 'P6P21'], UvexB2bConnector::filterCodes('F22.P1M02.1001, filtr p1m02 i P6P21'));
        $this->assertSame([], UvexB2bConnector::filterCodes('9192.225 HA2023(L) HECKEL6273/3/36 SP1D01'));
    }

    public function test_synced_text_of_another_filter_is_removed_at_the_next_sync_and_kept_in_the_payload(): void
    {
        // wiersz z produkcji sprzed poprawki: synchronizacja zapisała tekst innego filtra i jego odcisk
        Storage::fake('public');
        $this->laserCardText('<p>Szyba z filtrem P1D01 do laserów CO2.</p>');
        $this->fakeSite();
        app(B2bAccountSyncRunner::class)->run($this->account(), delayMs: 0);
        $card = Product::query()->where('sku', '000P1D011003')->sole();
        $this->assertSame('Szyba z filtrem P1D01 do laserów CO2.', $card->description);

        $foreign = 'Okulary chroniące przed promieniowaniem laserowym F22.P1M02.1001 do laserów CO2.';
        $card->forceFill(['description' => $foreign])->save();
        B2bProductLink::query()->where('product_id', $card->id)->update(['description_hash' => sha1($foreign)]);
        $this->laserCardText('<p>'.$foreign.'</p>');

        $result = app(B2bAccountSyncRunner::class)->run($this->account(), delayMs: 0);

        $card->refresh();
        $this->assertFalse($card->hasDescriptionText(), 'cudza tabela laserowa znika z karty');
        $this->assertSame($foreign, $card->enrichment_payload['replaced_description'] ?? null, 'skasowany tekst zostaje do wglądu');
        $log = array_column((array) B2bSyncRun::query()->findOrFail($result['sync_run_id'])->log, 'text');
        $this->assertContains(
            'Opis w sklepie opisuje inny wyrób — pominięty dla 1 kart (000P1D011003 (tekst podaje filtr P1M02, karta P1D01))',
            $log,
        );
    }

    public function test_shop_card_joins_the_list_row_with_the_manufacturer_tables_already_downloaded(): void
    {
        $this->manufacturerOrderNumber = '9970.005';
        $this->linkInsteadOfDescription('https://www.uvex-laservision.de/en/laser-safety-windows/laser-safety-window-p1p10-3mm/9970.005');
        $this->fakeSite();
        $connector = $this->connector();
        $connector->login();
        $product = $this->productsByCode($connector)['9970.005'];

        // opis pobiera stronę producenta — karta dostawcy korzysta z tej samej kopii
        $connector->description($product);
        $before = Http::recorded()->count();
        $fields = $connector->shopFields($product);

        $this->assertSame($before, Http::recorded()->count(), 'karta dostawcy nie dopytuje sklepu ani producenta');
        $this->assertSame([
            ['Informacje handlowe', 'Kod', '9970.005'],
            ['Informacje handlowe', 'Jednostka sprzedaży', 'szt.'],
            ['Informacje handlowe', 'Numer katalogowy producenta', '9970.005'],
            ['Dane techniczne', 'Filter material', 'Plastic'],
            ['Dane techniczne', 'Protection Class / Norm', 'EN 207 full protection, EN 208 Alignment protection, EN 60825'],
            ['Dane techniczne', 'VLT (approx.)', '16%'],
            ['Ochrona', 'ultraviolett', 'Protection within the ultraviolet spectral range between 180 and 400nm'],
            ['Ochrona', 'visible', 'Protection within the visible spectral range between 400 and 700nm'],
            ['Ochrona', 'Poziomy ochrony', 'Długość fali (nm) | OD | Tryb pracy / badany stopień ochrony'],
            ['Ochrona', 'Poziomy ochrony', '180 - 315 | (OD10+) | D LB10 + IR LB4 + M LB6'],
            ['Ochrona', 'Poziomy ochrony', '>315 - 385 | (OD8+) | D LB6 + IRM LB8'],
        ], self::rows($fields));
    }

    public function test_shop_card_sends_no_request_when_the_card_does_not_link_to_the_manufacturer(): void
    {
        // sklep ma własny opis, więc karta nigdzie nie odsyła — tabelki wyrobu po prostu nie ma czego szukać
        $this->fakeSite();
        $connector = $this->connector();
        $connector->login();
        $product = $this->productsByCode($connector)['9970.005'];
        $connector->description($product);

        $before = Http::recorded()->count();
        $fields = $connector->shopFields($product);

        $this->assertSame($before, Http::recorded()->count(), 'karta dostawcy nie wysyła żadnego żądania');
        $this->assertSame([], $this->manufacturerHits);
        $this->assertSame([
            ['Informacje handlowe', 'Kod', '9970.005'],
            ['Informacje handlowe', 'Jednostka sprzedaży', 'szt.'],
        ], self::rows($fields));
    }

    public function test_shop_card_of_a_card_without_the_manufacturer_page_does_not_borrow_rows_from_the_previous_one(): void
    {
        $this->manufacturerOrderNumber = '9970.005';
        $this->linkInsteadOfDescription('https://www.uvex-laservision.de/en/laser-safety-windows/laser-safety-window-p1p10-3mm/9970.005');
        // druga karta ma własną stronę produktu, z opisem w panelu — do producenta nie odsyła
        $this->details['6687'] = str_replace(
            '<td class="codeViewProductDane">9970.005</td>',
            '<td class="codeViewProductDane">8430/2/39</td>',
            $this->fixture('product_9970005.html'),
        );
        $this->fakeSite();
        $connector = $this->connector();
        $connector->login();
        $products = $this->productsByCode($connector);

        $connector->description($products['9970.005']);

        // tabelka producenta należy do karty, dla której pobrano stronę — inna karta dostaje same dane handlowe
        $this->assertSame([
            ['Informacje handlowe', 'Kod', '8430/2/39'],
            ['Informacje handlowe', 'Jednostka sprzedaży', 'par.'],
        ], self::rows($connector->shopFields($products['8430/2/39'])));
    }

    public function test_shop_card_finds_the_manufacturer_page_itself_when_nobody_asked_for_the_description(): void
    {
        // karta z opisem ręcznym albo od AI nie woła description() — tabelka wyrobu i tak ma być pełna
        $this->manufacturerOrderNumber = '9970.005';
        $this->linkInsteadOfDescription('https://www.uvex-laservision.de/en/laser-safety-windows/laser-safety-window-p1p10-3mm/9970.005');
        $this->fakeSite();
        $connector = $this->connector();
        $connector->login();
        $product = $this->productsByCode($connector)['9970.005'];

        $rows = self::rows($connector->shopFields($product));

        $this->assertSame(
            ['https://www.uvex-laservision.de/en/laser-safety-windows/laser-safety-window-p1p10-3mm/9970.005'],
            $this->manufacturerHits,
        );
        $this->assertContains(['Informacje handlowe', 'Kod', '9970.005'], $rows);
        $this->assertContains(['Informacje handlowe', 'Numer katalogowy producenta', '9970.005'], $rows);
        $this->assertContains(['Dane techniczne', 'Filter material', 'Plastic'], $rows);
        $this->assertContains(['Ochrona', 'Poziomy ochrony', '180 - 315 | (OD10+) | D LB10 + IR LB4 + M LB6'], $rows);

        // przy jednym przebiegu strona producenta (i strona produktu) idzie po sieci raz, kto by o nią nie prosił
        $description = $connector->description($product);
        $connector->shopFields($product);

        $this->assertStringContainsString('Opis ze strony producenta (www.uvex-laservision.de):', $description);
        $this->assertCount(1, $this->manufacturerHits);
        $this->assertSame(1, $this->detailHits);
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

    public function test_all_photos_from_the_product_page_land_on_the_card_and_are_not_downloaded_twice(): void
    {
        Storage::fake('public');
        $gallery = [
            self::IMG_9970,
            'https://izam.system-b2b.pl/public/get-preview/product_images/C9/C9231177D6E8BDB6B2798552BD96BE49AFAFE8DD9B853842FFCCA085C6F18E6A.jpg',
            'https://izam.system-b2b.pl/public/get-preview/product_images/F2/F2FA091F35BCCAB225754E2BC551DF97058C2C4B9C3652A2D3F9EAEFD6C4A780.jpg',
        ];
        $this->galleryOnProductPage($gallery);
        $this->fakeSite();

        app(B2bAccountSyncRunner::class)->run($this->account(), delayMs: 0);

        $cleaner = Product::query()->where('sku', '9970.005')->sole();
        $images = $cleaner->images()->orderBy('sort_order')->get();
        $this->assertSame(
            array_map(static fn (string $url): string => str_replace('/public/get-preview/', '/get-preview/', $url), $gallery),
            $images->pluck('source_url')->all(),
        );
        $this->assertSame([true, false, false], $images->pluck('is_primary')->map(static fn ($v): bool => (bool) $v)->all());

        // drugi przebieg: zdjęcia są już przy karcie, więc nie pobieramy ich ponownie
        $this->imageHits = [];
        app(B2bAccountSyncRunner::class)->run($this->account(), delayMs: 0);

        $this->assertSame([], $this->imageHits);
        $this->assertSame(3, $cleaner->images()->count());
    }

    /** Galeria na stronie produktu: sklep trzyma tam pozostałe ujęcia wyrobu. */
    private function galleryOnProductPage(array $urls): void
    {
        $links = '';
        foreach ($urls as $url) {
            $links .= '<a data-img="'.$url.'" data-full="'.$url.'" data-thumb="'.$url.'"></a>';
        }
        $this->details['4713'] = (string) preg_replace(
            '#(<div class="B2B_fotorama widget-user-header" id="B2B_fotorama_details"[^>]*>).*?(</div>)#s',
            '$1'.$links.'$2',
            $this->details['4713'],
        );
    }

    /** Karta bez opisu w panelu: zamiast treści odnośnik „Kliknij i przejdź do pełnego opisu”. */
    /**
     * Strona produktu szyby 000P1D011003 (pozycja 7677) z podanym opisem sklepu i — gdy jest — odnośnikiem
     * „Kliknij i przejdź do pełnego opisu” na końcu opisu, jak na prawdziwych kartach laserowych.
     */
    private function laserCardText(string $html, ?string $link = null): void
    {
        if ($link !== null) {
            $html = (string) preg_replace('#</p>\s*$#', '<br><a href="'.$link.'" target="_blank">Kliknij i przejdź do pełnego opisu</a></p>', $html);
        }
        $this->details['7677'] = str_replace(
            ['<td class="codeViewProductDane">9970.005</td>', '<p>Stacja czyszcząca do okularów i gogli. Zawiera: 2x chusteczki czyszczące (700 szt. w opakowaniu) 9971.000, 1x płyn czyszczący 9972.103, 1x pompkę dozującą 9973.101</p>'],
            ['<td class="codeViewProductDane">000P1D011003</td>', $html],
            $this->fixture('product_9970005.html'),
        );
    }

    private function linkInsteadOfDescription(string $url): void
    {
        $this->details['4713'] = str_replace(
            '<p>Stacja czyszcząca do okularów i gogli. Zawiera: 2x chusteczki czyszczące (700 szt. w opakowaniu) 9971.000, 1x płyn czyszczący 9972.103, 1x pompkę dozującą 9973.101</p>',
            '<p><br><a href="'.$url.'" target="_blank">Kliknij i przejdź do pełnego opisu</a></p>',
            $this->details['4713'],
        );
    }

    /** Lista wyników wyszukiwarki producenta: karta o szukanym numerze albo nic pasującego. */
    private function manufacturerSearchPage(string $code): string
    {
        $hit = $this->manufacturerKnowsCode && $code !== ''
            ? '<a href="https://www.uvex-laservision.de/en/laser-safety-windows/cleaning-station/'.$code.'">'.$code.'</a>'
            : '';

        return '<!DOCTYPE html><html><head><meta charset="utf-8"></head><body><div class="cms-listing">'
            .'<a href="https://www.uvex-laservision.de/en/'.$code.'/details.pdf">SPEC sheet</a>'
            .'<a href="https://www.uvex-laservision.de/en/laser-safety-windows/laser-safety-window-p1p10-3mm/000P1P102001">laser safety window P1P10</a>'
            .$hit
            .'</div></body></html>';
    }

    /**
     * Skrócona strona produktu uvex-laservision.de (Shopware): opis w bloku itemprop="description", tabela
     * „Specifications” i zakładka z poziomami ochrony.
     */
    private function manufacturerPage(): string
    {
        $descriptionTab = $this->manufacturerHasDescriptionTab
            ? '<h2 class="product-detail-description-title">Product information "laser safety window P1P10 (3mm)"</h2>'
                .'<div class="product-detail-description-text" itemprop="description">'
                .'<p>The laser safety window P1P10 is a new blue absorbing laser protection filter without additional reflective coating.</p>'
                .'<p>A broadband laser protection exists from 635nm to 11,500nm.</p>'
                .'<h2>Specifications</h2>'
                .'</div>'
            : '';
        $orderNumber = $this->manufacturerOrderNumber !== null
            ? '<ul class="list-unstyled"><li>Order number: '.$this->manufacturerOrderNumber.'</li><li>GTIN: 4050369019263</li></ul>'
            : '';
        if ($this->emptyManufacturerPage) {
            return '<!DOCTYPE html><html><head><meta charset="utf-8"></head><body>'.$orderNumber.'</body></html>';
        }

        return '<!DOCTYPE html><html><head><meta charset="utf-8"></head><body>'
            .'<div class="product-detail-short-description">'.($this->manufacturerIntroRepeatsDescription
                ? 'The laser safety window P1P10 is a new blue absorbing laser protection filter without additional reflective coating.'
                : 'The laservision plastic laser safety window P1P10 is a window for green laser systems'
                    .' and is user specific available up to a size of 1219x915mm. The thickness is 3mm.').'</div>'
            .$orderNumber
            .$descriptionTab
            .'<table class="product-detail-properties-table">'
            .'<tr><th>Filter material:</th><td>Plastic</td></tr>'
            .'<tr><th>Protection Class / Norm:</th><td>EN 207 full protection, EN 208 Alignment protection, EN 60825</td></tr>'
            .'<tr><th>VLT (approx.):</th><td>16%</td></tr>'
            .'</table>'
            .'<div id="protection-levels-tab-pane"><table>'
            .'<tr><th>WAVELENGTH (NM)</th><th>OD</th><th>OPERATING MODE / TESTED PROTECTION LEVEL</th></tr>'
            .'<tr><td>180 - 315</td><td>(OD10+)</td><td>D LB10 + IR LB4 + M LB6</td></tr>'
            .'<tr><td>&gt;315 - 385</td><td>(OD8+)</td><td>D LB6 + IRM LB8</td></tr>'
            .'</table></div>'
            .'<div class="col-lg-4 product-detail-properties-protectionrange-container">'
            .'<span class="h1 uvex-protectionrange-headline">Protection range</span>'
            .'<div class="row protectionrange-information-container">'
            .'<div class="col-3 range-image-container"><img src="uv.png" alt="UV"></div>'
            .'<div class="col-9"><span class="h2 uvex-protectionrange-title">ultraviolett</span>'
            .'<p>Protection within the ultraviolet spectral range between 180 and 400nm</p></div>'
            .'</div>'
            .'<div class="row protectionrange-information-container">'
            .'<div class="col-9"><span class="h2 uvex-protectionrange-title">visible</span>'
            .'<p>Protection within the visible spectral range between 400 and 700nm</p></div>'
            .'</div>'
            .'</div>'
            .'</body></html>';
    }

    public function test_price_list_link_is_found_by_its_text_and_resolved_against_the_shop(): void
    {
        $html = '<html><head><base href="https://izam.system-b2b.pl/public/"></head><body>'
            .'<a href="https://izam.system-b2b.pl/public/logout">Wyloguj</a>'
            .'<a href="assets/resources/products/2180/cenniki%20USPL%20od%201%20X%2025%20ver.2.xlsx"> Cennik  do pobrania </a>'
            .'</body></html>';

        $this->assertSame(
            'https://izam.system-b2b.pl/public/assets/resources/products/2180/cenniki%20USPL%20od%201%20X%2025%20ver.2.xlsx',
            UvexB2bConnector::basePriceListUrl($html),
        );

        $this->expectException(RuntimeException::class);
        UvexB2bConnector::basePriceListUrl('<a href="https://example.com/cennik.xlsx">Cennik do pobrania</a>');
    }

    public function test_missing_price_list_link_leaves_base_prices_unloaded_and_the_sync_goes_on(): void
    {
        Storage::fake('public');
        $this->fakeSite();

        $result = app(B2bAccountSyncRunner::class)->run($this->account(), delayMs: 0);

        $this->assertSame(6, $result['created']);
        $this->assertSame(0, $this->priceListHits);
        $this->assertFalse(ProductSourcePrice::query()->whereNotNull('base_price_net')->exists());
        $log = array_column((array) B2bSyncRun::query()->latest('id')->firstOrFail()->log, 'text');
        $this->assertContains(self::NO_PRICE_LIST_LINE, $log);
    }

    public function test_sync_stores_the_base_price_and_standard_discount_in_the_account_slot_only(): void
    {
        Storage::fake('public');
        $this->withPriceList($this->priceListSheets());
        $account = $this->account();
        B2bDiscountRule::query()->create([
            'b2b_account_id' => $account->id, 'position' => 1, 'name' => 'Heckel',
            'match_field' => B2bDiscountRule::FIELD_CATEGORY, 'match_type' => B2bDiscountRule::TYPE_EQUALS,
            'pattern' => 'Buty Heckel', 'discount_percent' => 15,
        ]);
        $any = B2bDiscountRule::query()->create([
            'b2b_account_id' => $account->id, 'position' => 2, 'name' => 'Reszta',
            'match_field' => B2bDiscountRule::FIELD_CATALOG_NO, 'match_type' => B2bDiscountRule::TYPE_ANY,
            'pattern' => '', 'discount_percent' => 10,
        ]);

        $result = app(B2bAccountSyncRunner::class)->run($account, delayMs: 0);

        $this->assertSame(6, $result['created']);
        // nowa karta (grupa rozmiarów, reguła butów) — slot z ceną bazową, karta z ceną konta
        $shoes = Product::query()->where('sku', '8430/2')->sole();
        $this->assertSame('226.80', $shoes->catalog_price_net);
        $this->assertSame([
            'base_price_net' => '300.00',
            'base_price_category' => 'Buty Uvex',
            'base_price_code' => '84302',
            'base_price_source' => 'cennik UVEX 2026.xlsx · arkusz Buty Uvex · Półbuty uvex 1 business · pobrano '.now()->format('Y-m-d'),
            'standard_discount_percent' => '10.00',
        ], $this->baseFields($shoes));
        // Heckel: seria + model, cena z długim ułamkiem zaokrąglona, reguła kategorii przed łapanką
        $heckel = $this->baseFields(Product::query()->where('sku', 'HECKEL6273/3')->sole());
        $this->assertSame(['255.31', 'Buty Heckel', '62733', '15.00'], [$heckel['base_price_net'], $heckel['base_price_category'], $heckel['base_price_code'], $heckel['standard_discount_percent']]);
        // HexArmor po modelu w nazwie wiersza
        $this->assertSame('60201', $this->baseFields(Product::query()->where('sku', 'HA2023')->sole())['base_price_code']);
        // arkusz „Odzież” pominięty, choć łapanka pasowałaby do wszystkiego
        $this->assertSame(
            ['base_price_net' => null, 'base_price_category' => null, 'base_price_code' => null, 'base_price_source' => null, 'standard_discount_percent' => null],
            $this->baseFields(Product::query()->where('sku', '000P1D011003')->sole()),
        );
        // trzewik z rozmiarami w dwóch cenach (jedna karta od 28.09.2026) — wiersz „NNNND” cennika, dopasowany kodem
        // rozmiaru w cenie karty
        $this->assertSame('69352', $this->baseFields(Product::query()->where('sku', '6935/2')->sole())['base_price_code']);
        // historia cen: jeden wpis na nową kartę — cena bazowa nie jest ceną karty
        $this->assertSame(6, ProductPriceHistory::query()->count());
        $this->assertFalse(ProductPriceHistory::query()->where('catalog_price_net', '300.00')->exists());

        $log = array_column((array) B2bSyncRun::query()->latest('id')->firstOrFail()->log, 'text');
        $this->assertContains('Cennik bazowy: 5 kart dopasowanych (w tym 0 z częścią rozmiarów spoza cennika), 1 kart spoza cennika, 0 kart z niejednoznacznym wierszem', $log);
        $this->assertContains('Rabat standardowy: 5 kart z pasującą regułą', $log);
        $this->assertSame(4, (int) $any->fresh()->last_matched_count);
    }

    /**
     * Karta z rozmiarami w różnych cenach (decyzja użytkownika 28.09.2026) ma w slocie cenę najtańszego rozmiaru, więc
     * cena bazowa musi być ceną tego samego rozmiaru — inaczej porównanie „cena specjalna” zestawiłoby cenę 38
     * z cennikiem 39. Wiersz tylko dla droższego rozmiaru (kod dokładny 6935239) = brak ceny bazowej karty.
     */
    public function test_base_price_is_matched_only_by_the_sizes_in_the_card_price(): void
    {
        $sheets = $this->priceListSheets();
        $sheets['Buty Uvex'] = [['Kod', 'Nazwa', 'CENA KATALOGOWA'], ['6935239', 'Trzewik uvex 2 trend rozm. 39', 425], [84302, 'Półbuty uvex 1 business', 300]];
        $this->withPriceList($sheets);
        $connector = $this->connector();
        $connector->login();

        $products = $this->productsByCode($connector);

        $this->assertNull($connector->basePrice($products['6935/2/38']));
        $this->assertSame('84302', $connector->basePrice($products['8430/2/39'])?->code);

        // wiersz rozmiaru w cenie karty — ta cena bazowa
        $sheets['Buty Uvex'][] = ['6935238', 'Trzewik uvex 2 trend rozm. 38', 410];
        $this->withPriceList($sheets);
        $connector = $this->connector();
        $connector->login();

        $base = $connector->basePrice($this->productsByCode($connector)['6935/2/38']);
        $this->assertSame(['6935238', 410.0], [$base?->code, $base?->net]);
    }

    public function test_failed_price_list_keeps_previous_base_prices_and_a_card_gone_from_the_list_loses_them(): void
    {
        Storage::fake('public');
        $this->withPriceList($this->priceListSheets());
        $account = $this->account();
        app(B2bAccountSyncRunner::class)->run($account, delayMs: 0);
        $shoes = Product::query()->where('sku', '8430/2')->sole();
        $before = $this->baseFields($shoes);
        $history = ProductPriceHistory::query()->count();

        // plik nie do pobrania — ceny bazowe z poprzedniego przebiegu zostają
        $this->priceListXlsx = null;
        $result = app(B2bAccountSyncRunner::class)->run($account, delayMs: 0);
        $this->assertSame(0, $result['created']);
        $this->assertSame($before, $this->baseFields($shoes->fresh()));
        $log = array_column((array) B2bSyncRun::query()->latest('id')->firstOrFail()->log, 'text');
        $this->assertContains('Cennik bazowy UVEX nie wczytany (izam.system-b2b.pl odpowiedziało HTTP 500) — ceny bazowe kart bez zmian z poprzedniego przebiegu', $log);

        // plik wczytany, ale bez wiersza butów — karta traci cenę bazową, inne ją zachowują
        $sheets = $this->priceListSheets();
        $sheets['Buty Uvex'] = array_values(array_filter($sheets['Buty Uvex'], static fn (array $row): bool => $row[0] !== 84302));
        $this->priceListXlsx = UvexBasePriceListTest::workbook($sheets);
        app(B2bAccountSyncRunner::class)->run($account, delayMs: 0);
        $this->assertNull($this->baseFields($shoes->fresh())['base_price_net']);
        $this->assertSame('62733', $this->baseFields(Product::query()->where('sku', 'HECKEL6273/3')->sole())['base_price_code']);

        // ceny konta bez zmian — zmiana ceny bazowej nie dopisuje historii cen
        $this->assertSame($history, ProductPriceHistory::query()->count());
    }

    /**
     * Karty sprzed 28.09.2026 z dawnego podziału według ceny: „6935/2/38” (358,70) i „6935/2/39” z rozmiarami 39 i 40
     * (360,40). Przebieg po zmianie nie zakłada nowej karty i nie przepina powiązań; każda stara karta dostaje swoje
     * rozmiary i cenę bazową SWOJEGO najtańszego rozmiaru (38 — wiersz dokładny 6935238, 39/40 — wiersz modelu 69352),
     * a nie cenę bazową najtańszego rozmiaru całego wyrobu.
     */
    public function test_legacy_price_split_cards_keep_their_sizes_and_get_the_base_price_of_their_own_cheapest_size(): void
    {
        Storage::fake('public');
        $sheets = $this->priceListSheets();
        $sheets['Buty Uvex'] = [
            ['Kod', 'Nazwa', 'CENA KATALOGOWA'],
            ['6935238', 'Trzewik uvex 2 trend rozm. 38', 410],
            [69352, 'Trzewik uvex 2 trend', 420],
            [84302, 'Półbuty uvex 1 business', 300],
        ];
        $this->withPriceList($sheets);
        $account = $this->account();
        $small = $this->legacyCard($account, '6935/2/38', 358.70, ['6935/2/38']);
        $large = $this->legacyCard($account, '6935/2/39', 360.40, ['6935/2/39', '6935/2/40']);

        $result = app(B2bAccountSyncRunner::class)->run($account, delayMs: 0);

        // nowe tylko karty spoza trzewika (półbuty, pojemnik, szyba, HexArmor, Heckel)
        $this->assertSame(5, $result['created'], implode(' | ', $result['errors']));
        $this->assertSame(2, Product::query()->where('sku', 'like', '6935/2/%')->count());
        $this->assertSame(['6935/2/38'], B2bProductLink::query()->where('product_id', $small->id)->pluck('remote_id')->all());
        $this->assertSame(['6935/2/39', '6935/2/40'], B2bProductLink::query()->where('product_id', $large->id)->orderBy('remote_id')->pluck('remote_id')->all());
        $sizes = static fn (Product $card): array => ProductVariant::query()->where('product_id', $card->id)->where('kind', ProductVariant::KIND_SIZE)
            ->orderBy('sort_order')->get()->map(static fn (ProductVariant $v): array => [$v->label, (string) $v->purchase_price])->all();
        $this->assertSame([['38', '358.70']], $sizes($small));
        $this->assertSame([['39', '360.40'], ['40', '360.40']], $sizes($large));
        $this->assertSame(['358.70', '6935238', '410.00'], [
            (string) ProductSourcePrice::query()->where('product_id', $small->id)->value('purchase_price'),
            $this->baseFields($small)['base_price_code'],
            $this->baseFields($small)['base_price_net'],
        ]);
        $this->assertSame(['360.40', '69352', '420.00'], [
            (string) ProductSourcePrice::query()->where('product_id', $large->id)->value('purchase_price'),
            $this->baseFields($large)['base_price_code'],
            $this->baseFields($large)['base_price_net'],
        ]);
        $spread = B2bSyncRun::query()->findOrFail($result['sync_run_id'])->size_spread;
        $this->assertSame(1, $spread['total']);
        $this->assertSame([$small->id, $large->id], $spread['groups'][0]['cards']);
    }

    /**
     * Mapa połączeń z powodem „split” rozdziela grupę rozmiarów na pozycje (B2bCatalogSync::syncMembersSeparately).
     * Droższy rozmiar 40 na wskazanej karcie ma cenę bazową wiersza SWOJEGO kodu (6935240), a nie kodów najtańszych
     * rozmiarów całego wyrobu.
     */
    public function test_split_redirect_gives_the_separated_dearer_size_the_base_price_of_its_own_code(): void
    {
        Storage::fake('public');
        $sheets = $this->priceListSheets();
        $sheets['Buty Uvex'] = [
            ['Kod', 'Nazwa', 'CENA KATALOGOWA'],
            [69352, 'Trzewik uvex 2 trend', 420],
            ['6935240', 'Trzewik uvex 2 trend rozm. 40', 430],
            [84302, 'Półbuty uvex 1 business', 300],
        ];
        $this->withPriceList($sheets);
        $account = $this->account();
        $target = Product::query()->create([
            'sku' => 'TRZEWIK-40', 'name' => 'Trzewik uvex 2 trend rozmiar 40', 'manufacturer' => 'UVEX', 'description' => '',
            'catalog_price_net' => 0, 'purchase_price' => 0,
        ]);
        CardRedirect::query()->create([
            'source_key' => ProductSourcePrice::b2bKey((int) $account->id), 'position_key' => '6935/2/40',
            'b2b_account_id' => $account->id, 'product_id' => $target->id, 'reason' => CardRedirect::REASON_SPLIT,
            'target_snapshot' => CardRedirectStore::snapshot($target),
        ]);

        $result = app(B2bAccountSyncRunner::class)->run($account, delayMs: 0);

        $this->assertSame(['6935/2/40'], B2bProductLink::query()->where('product_id', $target->id)->pluck('remote_id')->all(), implode(' | ', $result['errors']));
        $slot = ProductSourcePrice::query()->where('product_id', $target->id)->sole();
        $this->assertSame('360.40', (string) $slot->purchase_price);
        $this->assertSame(['6935240', '430.00'], [$this->baseFields($target)['base_price_code'], $this->baseFields($target)['base_price_net']]);
        // pozostałe rozmiary — własne karty z wierszem modelu
        $small = Product::query()->where('sku', '6935/2/38')->sole();
        $this->assertSame(['358.70', '69352'], [(string) ProductSourcePrice::query()->where('product_id', $small->id)->value('purchase_price'), $this->baseFields($small)['base_price_code']]);
    }

    /**
     * Karta zapisana przez dawny podział według ceny: powiązania rozmiarów, slot konta, kod bez zmian.
     *
     * @param  list<string>  $codes
     */
    private function legacyCard(B2bAccount $account, string $sku, float $price, array $codes): Product
    {
        $card = Product::query()->create([
            'sku' => $sku, 'name' => 'Trzewik uvex 2 trend '.$sku, 'manufacturer' => 'UVEX', 'description' => '',
            'catalog_price_net' => $price, 'discount_percent' => 0, 'purchase_price' => $price, 'currency' => 'PLN',
        ]);
        foreach ($codes as $code) {
            B2bProductLink::query()->create([
                'b2b_account_id' => $account->id, 'remote_id' => $code, 'product_id' => $card->id,
                'remote_sku' => $code, 'remote_name' => 'Trzewik uvex 2 trend '.$code, 'manufacturer' => 'UVEX',
                'last_purchase_price' => $price, 'last_currency' => 'PLN',
            ]);
        }
        ProductSourcePrice::query()->create([
            'product_id' => $card->id, 'source_key' => ProductSourcePrice::b2bKey((int) $account->id), 'b2b_account_id' => $account->id,
            'catalog_price_net' => $price, 'purchase_price' => $price, 'discount_percent' => 0, 'currency' => 'PLN',
            'checked_at' => now()->subDay(),
        ]);

        return $card;
    }

    /**
     * @param  array<string, list<list<mixed>>>  $sheets
     */
    private function withPriceList(array $sheets): void
    {
        $this->startPageExtra = '<a href="https://izam.system-b2b.pl/public/assets/resources/products/2180/cennik%20UVEX%202026.xlsx">Cennik do pobrania</a>';
        $this->priceListXlsx = UvexBasePriceListTest::workbook($sheets);
        $this->fakeSite();
    }

    /**
     * Cennik bazowy dla pozycji z setUp(): buty po „NNNN/D”, Heckel, HexArmor po modelu, pojemnik dokładnie po
     * kodzie; szyba lasera tylko w „Odzież” (arkusz pominięty).
     *
     * @return array<string, list<list<mixed>>>
     */
    private function priceListSheets(): array
    {
        $header = ['Kod', 'Nazwa', 'CENA KATALOGOWA'];

        return [
            'Ogólne' => [],
            'Ochrona wzroku' => [$header, ['9970005', 'Stacja czyszcząca uvex', 200]],
            'Rękawice HEXArmor' => [$header, [60201, 'HexArmor Rig Lizard® Arctic 2023', 222]],
            'Buty Heckel' => [['Kod', 'Seria', 'Model', 'KATALOG'], [62733, 'Suxxeed Offroad', 'SUXXEED OFFROAD HIGH S3', 255.30601]],
            'Buty Uvex' => [$header, [84302, 'Półbuty uvex 1 business', 300], [69352, 'Trzewik uvex 2 trend', 420]],
            'Odzież' => [$header, ['000P1D011003', 'Szyba', 999]],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function baseFields(Product $product): array
    {
        $slot = ProductSourcePrice::query()
            ->where('product_id', $product->id)
            ->where('source_key', ProductSourcePrice::b2bKey((int) $this->account()->id))
            ->sole();

        return $slot->only(['base_price_net', 'base_price_category', 'base_price_code', 'base_price_source', 'standard_discount_percent']);
    }

    /**
     * Pozycje karty: kod, SKU, nazwa, rozmiar, dostępność, cena konta, cena bazowa i waluta pozycji.
     *
     * @return list<list<mixed>>
     */
    private static function memberRows(B2bRemoteProduct $product): array
    {
        return array_map(static fn (array $m): array => [
            $m['remote_id'], $m['sku'], $m['name'], $m['size'] ?? null, $m['availability'] ?? null,
            ($m['price'] ?? null)?->net, ($m['price'] ?? null)?->base, ($m['price'] ?? null)?->currency,
        ], $product->members);
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
     * @return list<array{0: string, 1: string, 2: string, 3: string|null, 4: string|null, 5: string|null}>
     */
    private static function storedIdentifiers(int $productId): array
    {
        return ProductIdentifier::query()
            ->where('product_id', $productId)
            ->orderBy('position_key')->orderBy('type')->orderBy('value')
            ->get()
            ->map(static fn (ProductIdentifier $i): array => [
                $i->position_key, $i->type, $i->value, $i->variant_label, $i->source_field, $i->manufacturer,
            ])
            ->all();
    }

    /**
     * @param  array<string, mixed>  $result
     */
    private function assertNoIdentifierWarnings(array $result): void
    {
        $log = array_column((array) B2bSyncRun::query()->latest('id')->firstOrFail()->log, 'text');
        foreach ([...$result['errors'], ...$log] as $line) {
            $this->assertStringNotContainsString('identyfikator', mb_strtolower((string) $line));
        }
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
                // pole ilości koszyka jak na żywej stronie: większość pozycji min="1" step="any" (24.09.2026)
                '{{MIN}}' => $row['min'] ?? '1',
                '{{STEP}}' => $row['step'] ?? 'any',
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
                return $loggedIn ? Http::response(strtr($this->fixture('list_page.html'), ['{{ROWS}}' => '', '{{INFO}}' => $this->startPageExtra])) : $toLogin;
            }
            if (str_starts_with($path, '/public/assets/resources/products/') && str_ends_with($path, '.xlsx')) {
                if (! $loggedIn) {
                    return $toLogin;
                }
                $this->priceListHits++;

                return $this->priceListXlsx === null
                    ? Http::response('błąd serwera', 500)
                    : Http::response($this->priceListXlsx, 200, ['Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet']);
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
                $this->imageHits[] = $url;

                // każde zdjęcie innymi bajtami — inaczej katalog uzna je za to samo (odcisk sha256)
                return Http::response(self::PNG.basename($path), 200, ['Content-Type' => 'image/png']);
            }
            if (str_ends_with((string) parse_url($url, PHP_URL_HOST), 'uvex-laservision.de')) {
                if ($path === '/en/search') {
                    $this->searchHits[] = (string) ($query['search'] ?? '');

                    return Http::response($this->manufacturerSearchPage((string) ($query['search'] ?? '')));
                }
                $this->manufacturerHits[] = $url;

                return Http::response($this->manufacturerPage());
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
