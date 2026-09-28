<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\B2bAccount;
use App\Models\B2bProductLink;
use App\Models\B2bSyncRun;
use App\Models\Product;
use App\Models\ProductIdentifier;
use App\Models\ProductImage;
use App\Models\ProductShopCard;
use App\Models\ProductSourcePrice;
use App\Models\ProductVariant;
use App\Models\ProductVariantPriceHistory;
use App\Services\B2b\B2bAccountSyncRunner;
use App\Services\B2b\B2bConnectorRegistry;
use App\Services\B2b\B2bFatalException;
use App\Services\B2b\B2bListProgressAware;
use App\Services\B2b\B2bManufacturerSite;
use App\Services\B2b\B2bRemoteIdentifier;
use App\Services\B2b\B2bRemoteProduct;
use App\Services\B2b\B2bRunSummaryAware;
use App\Services\B2b\B2bShopFieldSource;
use App\Services\B2b\B2bSizePriceSource;
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
                ['5700000000101', 'F9999-910-09 0840', 'Buty ochronne MASCOT FOOTWEAR TEST F9999-910-09, czerń 0840', '0840', 'Na stanie', 289.95, null, 'PLN'],
                ['5700000000102', 'F9999-910-09 0841', 'Buty ochronne MASCOT FOOTWEAR TEST F9999-910-09, czerń 0841', '0841', 'Brak na stanie, możliwość zamówienia, spodziewane od 03.11.2026', 289.95, null, 'PLN'],
            ],
            array_map(static fn (array $m): array => [
                $m['remote_id'], $m['sku'], $m['name'], $m['size'] ?? null, $m['availability'] ?? null,
                $m['price']?->net, $m['price']?->base, $m['price']?->currency,
            ], $card->members),
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

    /**
     * Do 28.09.2026 (decyzja 15.09.2026) rozmiary w dwóch cenach były dwiema kartami („19999-249-1809 S” z S, M, L
     * i „19999-249-1809 3XL”). Od decyzji użytkownika 28.09.2026 to jedna karta z kodem artykułu i nazwą bez rozmiarów,
     * a cena każdego rozmiaru jedzie przy jego pozycji; cena karty (price()) = najniższa cena rozmiaru.
     */
    public function test_sizes_in_two_prices_are_one_card_with_size_prices_and_the_lowest_card_price(): void
    {
        $this->addArticle(self::jacket());
        $this->fakeSite();

        $connector = $this->connector();
        $products = iterator_to_array($connector->products(), false);

        $this->assertSame(
            [['19999-249-1809', 'Kurtka membranowa MASCOT ACCELERATE 19999-249-1809, ciemny antracyt/czerń', 4]],
            array_map(static fn (B2bRemoteProduct $p): array => [$p->sku, $p->name, count($p->members)], $products),
        );
        $this->assertSame('5700000000201', $products[0]->remoteId);
        $this->assertSame(
            [['S', 539.0], ['M', 539.0], ['L', 539.0], ['3XL', 599.0]],
            array_map(static fn (array $m): array => [$m['size'], $m['price']->net], $products[0]->members),
        );
        $this->assertSame(['S', 'M', 'L', '3XL'], array_column($products[0]->raw['sizes'], 'size'));
        $this->assertSame(539.0, $connector->price($products[0])?->net);
        $this->assertSame(1, $connector->totalProducts());
        $this->assertStringContainsString('Karty: 1 (1 artykułów z rozmiarami w różnych cenach', implode("\n", $connector->runSummary()));
    }

    /**
     * Decyzja użytkownika 28.09.2026 (wieczór): kolory jednego modelu to jedna karta z tabelą kolor × rozmiar. Kolor
     * wiodący = najniższy numer (granat 010), SKU = kod modelu, nazwa nowej karty bez koloru, pozycja z nazwą koloru
     * w etykiecie i kodem koloru w kodzie; kolor bez tłumaczenia nazwy — „kolor {kod}”.
     */
    public function test_colours_of_one_model_are_one_card_with_colour_size_rows_and_the_model_code(): void
    {
        $this->addArticle(self::jacket());
        $this->addArticle(self::jacketNavy());
        $this->addArticle(self::colourOf(self::jacket(), '199992490909', 'Brak tekstu w tym języku', [
            self::sizeRow('M', '5700000000222', 549, 'Y', ''),
        ]));
        $this->fakeSite();
        $connector = $this->connector();

        $products = iterator_to_array($connector->products(), false);

        $this->assertCount(1, $products);
        $card = $products[0];
        $this->assertSame('19999-249', $card->sku);
        $this->assertSame('5700000000211', $card->remoteId);
        $this->assertSame('Kurtka membranowa MASCOT ACCELERATE 19999-249-010, granat', $card->name);
        $this->assertSame('Kurtka membranowa MASCOT ACCELERATE 19999-249', $card->cardName);
        $this->assertSame('Kurtka membranowa', $card->category);
        $this->assertSame('https://b2b.mascot.dk/Distributor/ProductDetail?productNumber=19999249010', $card->sourceUrl);
        $this->assertSame(
            [
                ['5700000000211', '19999-249-010 S', 'granat / S', 529.0],
                ['5700000000212', '19999-249-010 M', 'granat / M', 529.0],
                ['5700000000214', '19999-249-010 3XL', 'granat / 3XL', 609.0],
                ['5700000000222', '19999-249-0909 M', 'kolor 0909 / M', 549.0],
                ['5700000000201', '19999-249-1809 S', 'ciemny antracyt/czerń / S', 539.0],
                ['5700000000202', '19999-249-1809 M', 'ciemny antracyt/czerń / M', 539.0],
                ['5700000000203', '19999-249-1809 L', 'ciemny antracyt/czerń / L', 539.0],
                ['5700000000204', '19999-249-1809 3XL', 'ciemny antracyt/czerń / 3XL', 599.0],
            ],
            array_map(static fn (array $m): array => [$m['remote_id'], $m['sku'], $m['size'], $m['price']->net], $card->members),
        );
        $this->assertSame('Kurtka membranowa MASCOT ACCELERATE 19999-249-1809, ciemny antracyt/czerń S', $card->members[4]['name']);
        $this->assertSame('Kolory: granat (010), kolor 0909 (0909), ciemny antracyt/czerń (1809); rozmiary: S, M, 3XL, L', $card->variantSummary);
        $this->assertStringContainsString('Ograniczona dostępność: kolor 0909 / M', (string) $card->availability);
        // numer koloru z portalu przy pierwszym rozmiarze koloru (tam leżał na karcie koloru), EAN-y z etykietą wiersza
        $identifiers = array_map(static fn (B2bRemoteIdentifier $i): array => [$i->type, $i->value, $i->remoteId, $i->label], $card->identifiers ?? []);
        $this->assertContains(['manufacturer_code', '19999249010', '5700000000211', 'granat'], $identifiers);
        $this->assertContains(['manufacturer_code', '199992491809', '5700000000201', 'ciemny antracyt/czerń'], $identifiers);
        $this->assertContains(['ean', '5700000000204', '5700000000204', 'ciemny antracyt/czerń / 3XL'], $identifiers);
        $this->assertCount(11, $identifiers);

        $this->assertSame(529.0, $connector->price($card)?->net);
        $fields = array_map(static fn ($f): array => [$f->name, $f->value], $connector->shopFields($card));
        $this->assertContains(['Kod artykułu', '19999-249'], $fields);
        $this->assertContains(['Numer w portalu', '19999249010; 199992490909; 199992491809'], $fields);
        $this->assertNotContains('Kolor', array_column($fields, 0));
        // jedno zdjęcie jak dotąd — koloru wiodącego
        $this->assertSame(self::CDN.'19999-249-010_P01_1000px.jpg', $connector->image($card)?->sourceUrl);
        $this->assertSame(1, $connector->totalProducts());
        $this->assertStringContainsString('Modele w kilku kolorach: 1 kart z 3 artykułów', implode("\n", $connector->runSummary()));
    }

    /**
     * Łączymy ostrożnie: inny materiał przy tym samym kodzie modelu to dwie karty jak dotąd; kolor bez żadnej ceny
     * zostaje pozycją pominiętą, a jedyny kolor z ceną — kartą jak przed 28.09.2026 (te same kod, nazwa, pozycje).
     */
    public function test_colours_that_differ_beyond_colour_or_lack_prices_stay_separate_cards_as_before(): void
    {
        $this->addArticle(self::jacket());
        $other = self::colourOf(self::jacket(), '199992490909', 'czerń', [self::sizeRow('M', '5700000000222', 549, 'G', '')]);
        $other['Quality'] = '65% poliester, 35% bawełna';
        $this->addArticle($other);
        $this->addArticle(self::boots());
        $this->addArticle(self::colourOf(self::boots(), 'F999991010', 'granat', [self::sizeRow('0840', '5700000000111', 0, 'G', '')]));
        $this->fakeSite();
        $connector = $this->connector();

        $products = iterator_to_array($connector->products(), false);

        $this->assertSame(
            [
                ['19999-249-1809', 'Kurtka membranowa MASCOT ACCELERATE 19999-249-1809, ciemny antracyt/czerń', ['S', 'M', 'L', '3XL'], null],
                ['19999-249-0909', 'Kurtka membranowa MASCOT ACCELERATE 19999-249-0909, czerń', ['M'], null],
                ['F9999-910-09', 'Buty ochronne MASCOT FOOTWEAR TEST F9999-910-09, czerń', ['0840', '0841'], null],
                ['F9999-910-10', 'Buty ochronne F9999-910-10', [], null],
            ],
            array_map(static fn (B2bRemoteProduct $p): array => [$p->sku, $p->name, array_column($p->members, 'size'), $p->cardName], $products),
        );
        $this->assertSame('skipped', $products[3]->raw['status']);
        $this->assertSame('Rozmiary: 0840 (EAN 5700000000101); 0841 (EAN 5700000000102)', $products[2]->variantSummary);
        $this->assertSame(self::CDN.'F9999-910-09_P01_1000px.jpg', $connector->image($products[2])?->sourceUrl);
        $this->assertSame(4, $connector->totalProducts());
        $summary = implode("\n", $connector->runSummary());
        $this->assertStringNotContainsString('Modele w kilku kolorach', $summary);
        // buty: jedyny kolor z ceną to nie rozdzielenie modelu
        $this->assertStringContainsString('Modele z kolorami na osobnych kartach (nazwa, rodzaj, kategoria, materiał, opis albo nazwy kolorów się nie zgadzają): 1, np. 19999-249', $summary);
    }

    /**
     * Błąd odczytu szczegółów jednego koloru (nie treść) — model stoi w tym przebiegu: zamiast karty pozycja pominięta
     * z EAN-ami odczytanych kolorów jako pozycjami (synchronizacja uzna je za nieudane), bez przełączenia na układ
     * jednego koloru.
     */
    public function test_colour_detail_read_error_skips_the_whole_model_with_its_known_positions(): void
    {
        $this->addArticle(self::jacket());
        $navy = self::jacketNavy();
        $navy['IsSuccess'] = false;
        $navy['Message'] = 'Błąd serwera';
        $this->addArticle($navy);
        $this->fakeSite();
        $connector = $this->connector();

        $products = iterator_to_array($connector->products(), false);

        $this->assertSame(
            [
                ['5700000000201', '19999-249', 'skipped', ['5700000000201', '5700000000202', '5700000000203', '5700000000204']],
                ['19999249010', '19999-249-010', 'skipped', []],
            ],
            array_map(static fn (B2bRemoteProduct $p): array => [$p->remoteId, $p->sku, $p->raw['status'], array_column($p->members, 'remote_id')], $products),
        );
        $this->assertStringContainsString('19999249010', (string) $products[0]->raw['reason']);
        $this->assertSame(2, $connector->totalProducts());
        $this->expectException(RuntimeException::class);
        $connector->price($products[0]);
    }

    /** Karta modelu w wielu kolorach: lista EAN-ów dłuższa niż pole tabelki kończy się jawną informacją, nie ucięciem. */
    public function test_long_ean_list_of_a_many_colour_card_ends_with_a_note_not_a_cut(): void
    {
        foreach (['09' => 'czerń', '010' => 'granat', '1809' => 'ciemny antracyt/czerń', '0918' => 'czerń/ciemny antracyt'] as $code => $color) {
            $sizes = [];
            foreach (['XS', 'S', 'M', 'L', 'XL', '2XL', '3XL', '4XL', '5XL', '6XL'] as $i => $size) {
                $sizes[] = self::sizeRow($size, '57000000'.str_pad((string) $code, 4, '0', STR_PAD_LEFT).$i, 539, 'G', '');
            }
            $this->addArticle(self::colourOf(self::jacket(), '19999249'.$code, $color, $sizes));
        }
        $this->fakeSite();
        $connector = $this->connector();

        $products = iterator_to_array($connector->products(), false);

        $this->assertCount(1, $products);
        $this->assertCount(40, $products[0]->members);
        $ean = array_values(array_filter($connector->shopFields($products[0]), static fn ($f): bool => $f->name === 'EAN'))[0]->value;
        $this->assertLessThanOrEqual(ProductShopCard::MAX_VALUE_CHARS, mb_strlen($ean));
        $this->assertStringEndsWith('; … (pełna lista w tabeli wariantów)', $ean);
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

        // dwa artykuły = dwie karty (rozmiary w różnych cenach to od 28.09.2026 jedna karta)
        $this->assertCount(2, $products);
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

        // buty i kurtka — kurtka z rozmiarami w dwóch cenach to jedna karta (decyzja użytkownika 28.09.2026)
        $this->assertSame(2, $result['created'], implode(' | ', $result['errors']));
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
        $jacket = Product::query()->where('sku', '19999-249-1809')->sole();
        $jacketSlot = ProductSourcePrice::query()->where('product_id', $jacket->id)->sole();
        // cena karty = najtańszy rozmiar, najwyższa cena rozmiaru przy slocie
        $this->assertSame('539.00', (string) $jacketSlot->purchase_price);
        $this->assertSame('599.00', (string) $jacketSlot->size_price_max);
        $this->assertSame('539.00', (string) $jacket->purchase_price);
        $this->assertSame(
            [['S', '539.00'], ['M', '539.00'], ['L', '539.00'], ['3XL', '599.00']],
            ProductVariant::query()->where('product_id', $jacket->id)->where('kind', ProductVariant::KIND_SIZE)->orderBy('sort_order')->get()
                ->map(static fn (ProductVariant $v): array => [$v->label, (string) $v->purchase_price])->all(),
        );
        $this->assertSame('599.00', (string) B2bProductLink::query()->where('remote_id', '5700000000204')->value('last_purchase_price'));
        $this->assertSame('539.00', (string) B2bProductLink::query()->where('remote_id', '5700000000201')->value('last_purchase_price'));
        // buty w jednej cenie — rozmiary też w tabeli, bez „do Y”
        $this->assertNull(ProductSourcePrice::query()->where('product_id', $boots->id)->value('size_price_max'));

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
        $this->assertSame(2, $second['unchanged'], implode(' | ', $second['errors']));
        $this->assertSame($before, $this->snapshot());
    }

    /**
     * Karty sprzed 28.09.2026 (kurtka rozbita według ceny na „19999-249-1809 S” i „19999-249-1809 3XL”) — pierwszy
     * i drugi przebieg po zmianie: bez nowej karty, bez przepinania powiązań, bez zmian cen i opisów; każda karta
     * dostaje swoje rozmiary, a wyrób trafia do size_spread przebiegu (scalenie — etap 2).
     */
    public function test_legacy_price_split_cards_stay_and_get_their_own_sizes_over_two_runs(): void
    {
        Storage::fake('public');
        Storage::fake('local');
        $this->addArticle(self::jacket());
        $this->fakeSite();
        $account = $this->account();
        $description = 'Lekka tkanina. Oddychający, wiatro- i wodoszczelny.';
        $small = $this->legacyCard($account, '19999-249-1809 S', 'Kurtka membranowa MASCOT ACCELERATE 19999-249-1809, ciemny antracyt/czerń (rozm. S, M, L)', $description, 539.0, ['5700000000201' => 'S', '5700000000202' => 'M', '5700000000203' => 'L']);
        $large = $this->legacyCard($account, '19999-249-1809 3XL', 'Kurtka membranowa MASCOT ACCELERATE 19999-249-1809, ciemny antracyt/czerń (rozm. 3XL)', $description, 599.0, ['5700000000204' => '3XL']);
        $cards = fn (): array => Product::query()->orderBy('id')->get()->map(fn (Product $p): array => [
            $p->sku, $p->name, $p->description, (string) $p->purchase_price,
            B2bProductLink::query()->where('product_id', $p->id)->orderBy('remote_id')->pluck('remote_id')->all(),
            (string) ProductSourcePrice::query()->where('product_id', $p->id)->value('purchase_price'),
            ProductSourcePrice::query()->where('product_id', $p->id)->value('size_price_max'),
        ])->all();
        $before = $cards();

        $first = app(B2bAccountSyncRunner::class)->run($account, delayMs: 0, withImages: false);

        $this->assertSame(0, $first['created'], implode(' | ', $first['errors']));
        $this->assertSame(0, $first['skipped'], implode(' | ', $first['errors']));
        $this->assertSame(0, $first['prices_changed']);
        $this->assertSame($before, $cards());
        $sizes = static fn (Product $card): array => ProductVariant::query()->where('product_id', $card->id)->orderBy('sort_order')->get()
            ->map(static fn (ProductVariant $v): array => [$v->label, (string) $v->purchase_price])->all();
        $this->assertSame([['S', '539.00'], ['M', '539.00'], ['L', '539.00']], $sizes($small));
        $this->assertSame([['3XL', '599.00']], $sizes($large));
        $spread = B2bSyncRun::query()->findOrFail($first['sync_run_id'])->size_spread;
        $this->assertSame(1, $spread['total']);
        $this->assertSame([$small->id, $large->id], $spread['groups'][0]['cards']);

        $second = app(B2bAccountSyncRunner::class)->run($account, delayMs: 0, withImages: false);

        $this->assertSame(0, $second['updated'], implode(' | ', $second['errors']));
        $this->assertSame(1, $second['unchanged']);
        $this->assertSame($before, $cards());
        $this->assertSame(0, ProductVariant::query()->whereNotNull('removed_at')->count());
    }

    public function test_sync_creates_one_model_card_with_colour_rows_and_the_lead_colour_image_and_a_second_run_changes_nothing(): void
    {
        Storage::fake('public');
        Storage::fake('local');
        $this->addArticle(self::jacket());
        $this->addArticle(self::jacketNavy());
        $this->fakeSite();

        $result = app(B2bAccountSyncRunner::class)->run($this->account(), delayMs: 0, withImages: true);

        $this->assertSame(1, $result['created'], implode(' | ', $result['errors']));
        $card = Product::query()->sole();
        $this->assertSame('19999-249', $card->sku);
        $this->assertSame('Kurtka membranowa MASCOT ACCELERATE 19999-249', $card->name);
        $this->assertSame('Kolory: granat (010), ciemny antracyt/czerń (1809); rozmiary: S, M, 3XL, L', $card->variant_summary);
        $this->assertSame('529.00', (string) $card->purchase_price);
        $this->assertSame('609.00', (string) ProductSourcePrice::query()->where('product_id', $card->id)->value('size_price_max'));
        $this->assertSame(
            [
                ['granat / S', '19999-249-010 S', '529.00'], ['granat / M', '19999-249-010 M', '529.00'], ['granat / 3XL', '19999-249-010 3XL', '609.00'],
                ['ciemny antracyt/czerń / S', '19999-249-1809 S', '539.00'], ['ciemny antracyt/czerń / M', '19999-249-1809 M', '539.00'],
                ['ciemny antracyt/czerń / L', '19999-249-1809 L', '539.00'], ['ciemny antracyt/czerń / 3XL', '19999-249-1809 3XL', '599.00'],
            ],
            ProductVariant::query()->where('product_id', $card->id)->where('kind', ProductVariant::KIND_SIZE)->orderBy('sort_order')->get()
                ->map(static fn (ProductVariant $v): array => [$v->label, $v->sku, (string) $v->purchase_price])->all(),
        );
        $this->assertSame(7, B2bProductLink::query()->where('product_id', $card->id)->count());
        $this->assertSame(
            [self::CDN.'19999-249-010_P01_1000px.jpg'],
            ProductImage::query()->where('product_id', $card->id)->orderBy('sort_order')->pluck('source_url')->all(),
        );

        $before = $this->snapshot();
        $second = app(B2bAccountSyncRunner::class)->run($this->account(), delayMs: 0, withImages: true);

        $this->assertSame(0, $second['created'], implode(' | ', $second['errors']));
        $this->assertSame(0, $second['updated'], implode(' | ', $second['errors']));
        $this->assertSame(1, $second['unchanged'], implode(' | ', $second['errors']));
        $this->assertSame($before, $this->snapshot());
    }

    /**
     * Pełny przebieg z błędem odczytu jednego koloru: karta modelu bez zapisu (nazwa, lista wariantów, cena bez zmian),
     * żaden wiersz nie jest oznaczony jako wycofany, bez nowej historii ceny; następny przebieg — bez zmian.
     */
    public function test_sync_with_one_colour_unreadable_leaves_the_model_card_untouched_and_the_next_run_is_clean(): void
    {
        Storage::fake('public');
        Storage::fake('local');
        $this->addArticle(self::jacket());
        $this->addArticle(self::jacketNavy());
        $this->fakeSite();
        $first = app(B2bAccountSyncRunner::class)->run($this->account(), delayMs: 0, withImages: false);
        $this->assertSame(1, $first['created'], implode(' | ', $first['errors']));
        $before = $this->snapshot();
        $history = ProductVariantPriceHistory::query()->count();

        $this->articles['19999249010']['IsSuccess'] = false;
        $this->articles['19999249010']['Message'] = 'Błąd serwera';
        $broken = app(B2bAccountSyncRunner::class)->run($this->account(), delayMs: 0, withImages: false);

        $this->assertSame(0, $broken['created'], implode(' | ', $broken['errors']));
        $this->assertSame(0, $broken['updated'], implode(' | ', $broken['errors']));
        $this->assertSame(2, $broken['skipped'], implode(' | ', $broken['errors']));
        $this->assertSame(0, ProductVariant::query()->whereNotNull('removed_at')->count());
        $this->assertSame($history, ProductVariantPriceHistory::query()->count());
        $this->assertSame($before, $this->snapshot());

        unset($this->articles['19999249010']['IsSuccess'], $this->articles['19999249010']['Message']);
        $clean = app(B2bAccountSyncRunner::class)->run($this->account(), delayMs: 0, withImages: false);

        $this->assertSame(0, $clean['updated'], implode(' | ', $clean['errors']));
        $this->assertSame(1, $clean['unchanged'], implode(' | ', $clean['errors']));
        $this->assertSame(0, ProductVariant::query()->whereNotNull('removed_at')->count());
        $this->assertSame($before, $this->snapshot());
    }

    /**
     * Karty kolorów sprzed decyzji 28.09.2026 (wieczór) zostają do „Scal rozmiary”: bez nowej karty, bez zmiany kodu
     * i nazwy; każda dostaje wiersze i zdjęcie tylko swojego koloru i zachowuje numer koloru z portalu, a model trafia
     * do size_spread z nazwą bez koloru i listą kolorów.
     */
    public function test_legacy_colour_cards_stay_and_get_only_their_own_colour_rows_and_image(): void
    {
        Storage::fake('public');
        Storage::fake('local');
        $this->addArticle(self::jacket());
        $this->addArticle(self::jacketNavy());
        $this->fakeSite();
        $account = $this->account();
        $description = 'Lekka tkanina. Oddychający, wiatro- i wodoszczelny.';
        $black = $this->legacyCard($account, '19999-249-1809', 'Kurtka membranowa MASCOT ACCELERATE 19999-249-1809, ciemny antracyt/czerń', $description, 539.0, ['5700000000201' => 'S', '5700000000202' => 'M', '5700000000203' => 'L', '5700000000204' => '3XL']);
        $navy = $this->legacyCard($account, '19999-249-010', 'Kurtka membranowa MASCOT ACCELERATE 19999-249-010, granat', $description, 529.0, ['5700000000211' => 'S', '5700000000212' => 'M', '5700000000214' => '3XL']);

        $first = app(B2bAccountSyncRunner::class)->run($account, delayMs: 0, withImages: true);

        $this->assertSame(0, $first['created'], implode(' | ', $first['errors']));
        $this->assertSame(0, $first['skipped'], implode(' | ', $first['errors']));
        $this->assertSame(
            [['19999-249-1809', 'Kurtka membranowa MASCOT ACCELERATE 19999-249-1809, ciemny antracyt/czerń'], ['19999-249-010', 'Kurtka membranowa MASCOT ACCELERATE 19999-249-010, granat']],
            Product::query()->orderBy('id')->get()->map(static fn (Product $p): array => [$p->sku, $p->name])->all(),
        );
        $rows = static fn (Product $card): array => ProductVariant::query()->where('product_id', $card->id)->orderBy('sort_order')->pluck('label')->all();
        $this->assertSame(['ciemny antracyt/czerń / S', 'ciemny antracyt/czerń / M', 'ciemny antracyt/czerń / L', 'ciemny antracyt/czerń / 3XL'], $rows($black));
        $this->assertSame(['granat / S', 'granat / M', 'granat / 3XL'], $rows($navy));
        $images = static fn (Product $card): array => ProductImage::query()->where('product_id', $card->id)->pluck('source_url')->all();
        $this->assertSame([self::CDN.'19999-249-1809_P01_1000px.jpg'], $images($black));
        $this->assertSame([self::CDN.'19999-249-010_P01_1000px.jpg'], $images($navy));
        $codes = static fn (Product $card): array => ProductIdentifier::query()->where('product_id', $card->id)
            ->where('type', ProductIdentifier::TYPE_MANUFACTURER_CODE)->pluck('value')->all();
        $this->assertSame(['199992491809'], $codes($black));
        $this->assertSame(['19999249010'], $codes($navy));
        $spread = B2bSyncRun::query()->findOrFail($first['sync_run_id'])->size_spread;
        $this->assertSame([$black->id, $navy->id], $spread['groups'][0]['cards']);
        $this->assertSame('Kurtka membranowa MASCOT ACCELERATE 19999-249', $spread['groups'][0]['name']);
        $this->assertSame('Kolory: granat (010), ciemny antracyt/czerń (1809); rozmiary: S, M, 3XL, L', $spread['groups'][0]['variant_summary']);

        $second = app(B2bAccountSyncRunner::class)->run($account, delayMs: 0, withImages: true);

        $this->assertSame(0, $second['created'], implode(' | ', $second['errors']));
        $this->assertSame(0, $second['updated'], implode(' | ', $second['errors']));
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
        foreach ([B2bManufacturerSite::class, B2bShopFieldSource::class, B2bRunSummaryAware::class, B2bListProgressAware::class, B2bSizePriceSource::class] as $interface) {
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

    /**
     * Karta zapisana przez dawny podział według ceny: opis ze źródła z odciskiem w powiązaniach, slot konta, kod
     * i nazwa z rozmiarami.
     *
     * @param  array<string, string>  $sizes  EAN => rozmiar
     */
    private function legacyCard(B2bAccount $account, string $sku, string $name, string $description, float $price, array $sizes): Product
    {
        $card = Product::query()->create([
            'sku' => $sku, 'name' => $name, 'manufacturer' => 'MASCOT', 'description' => $description,
            'catalog_price_net' => $price, 'discount_percent' => 0, 'purchase_price' => $price, 'currency' => 'PLN',
        ]);
        foreach ($sizes as $ean => $size) {
            B2bProductLink::query()->create([
                'b2b_account_id' => $account->id, 'remote_id' => (string) $ean, 'product_id' => $card->id,
                'remote_sku' => explode(' ', $sku)[0].' '.$size, 'remote_name' => $name.' '.$size, 'manufacturer' => 'MASCOT',
                'description_hash' => sha1($description), 'last_purchase_price' => $price, 'last_currency' => 'PLN',
            ]);
        }
        ProductSourcePrice::query()->create([
            'product_id' => $card->id, 'source_key' => ProductSourcePrice::b2bKey((int) $account->id), 'b2b_account_id' => $account->id,
            'catalog_price_net' => $price, 'purchase_price' => $price, 'discount_percent' => 0, 'currency' => 'PLN',
            'availability' => 'Na stanie', 'checked_at' => now()->subDay(),
        ]);

        return $card;
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
            'price' => ProductSourcePrice::query()->where('product_id', $p->id)->get(['purchase_price', 'catalog_price_net', 'size_price_max', 'availability'])->toArray(),
            'sizes' => ProductVariant::query()->where('product_id', $p->id)->orderBy('id')
                ->get(['remote_id', 'label', 'purchase_price', 'availability', 'removed_at', 'updated_at'])->toArray(),
            'size_history' => ProductVariantPriceHistory::query()->count(),
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
     * Ta sama kurtka w granacie (kolor 010) — inne EAN-y i ceny.
     *
     * @return array<string, mixed>
     */
    private static function jacketNavy(): array
    {
        return self::colourOf(self::jacket(), '19999249010', 'granat', [
            self::sizeRow('S', '5700000000211', 529, 'G', '30-06-2026 00:00:00'),
            self::sizeRow('M', '5700000000212', 529, 'G', '30-06-2026 00:00:00'),
            self::sizeRow('3XL', '5700000000214', 609, 'G', '30-06-2026 00:00:00'),
        ]);
    }

    /**
     * Artykuł w innym kolorze: numer, kolor, zdjęcie z kodem koloru i rozmiary; reszta pól jak w $article.
     *
     * @param  array<string, mixed>  $article
     * @param  list<array<string, mixed>>  $sizes
     * @return array<string, mixed>
     */
    private static function colourOf(array $article, string $number, string $color, array $sizes): array
    {
        return [
            ...$article,
            'Number' => $number,
            'Color' => $color,
            'Image' => self::CDN.MascotB2bConnector::articleCode($number).'_P01_400px.jpg',
            'ProductSizes' => $sizes,
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
