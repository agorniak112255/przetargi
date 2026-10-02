<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\B2bAccount;
use App\Models\B2bProductLink;
use App\Models\Product;
use App\Models\ProductDocument;
use App\Models\ProductIdentifier;
use App\Models\ProductImage;
use App\Models\ProductShopCard;
use App\Models\ProductSourcePrice;
use App\Models\ProductVariant;
use App\Services\B2b\B2bAccountSyncRunner;
use App\Services\B2b\B2bConnectorRegistry;
use App\Services\B2b\B2bDocumentSource;
use App\Services\B2b\B2bFatalException;
use App\Services\B2b\B2bGroupsSizes;
use App\Services\B2b\B2bImageGallery;
use App\Services\B2b\B2bManufacturerSite;
use App\Services\B2b\B2bRemoteDocument;
use App\Services\B2b\B2bRemoteProduct;
use App\Services\B2b\B2bShopFieldNormSource;
use App\Services\B2b\B2bShopFieldSource;
use App\Services\B2b\B2bSizePriceSource;
use App\Services\B2b\SaraB2bClient;
use App\Services\B2b\SaraB2bConnector;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use RuntimeException;
use Tests\TestCase;

/**
 * Łącznik b2b.saraworkwear.com na atrapie API GraphQL (Http::fake). Kształt odpowiedzi odwzorowuje API sklepu
 * z 02.10.2026: logowanie mutacją createCustomerToken (zły login = błąd z extensions.errors.credentials, data null),
 * categoryList, products(from_category, page, limit) z pagination i pozycjami (jeden rozmiar jednego koloru: properties
 * shortCode/ID/size/color/standard…, prices sellPrice/listPrice z walutą, wariant z indeksem, EAN-em i stanem, pliki
 * /product/attachment/{pozycja}/{plik}_{wariant}, zdjęcia „{imageSafeUri}/…png”). Pole customer bez ważnego żetonu =
 * null z błędem „This action requires authentication”, a lista idzie dalej z cenami gościa — jak w sklepie.
 *
 * Wszystkie dane (kody, ceny, nazwy, opisy, EAN-y) są SYNTETYCZNE.
 */
final class SaraConnectorTest extends TestCase
{
    use RefreshDatabase;

    private const USER = 'zakupy@example.test';

    private const PASSWORD = 'dobre-haslo';

    /** Cena gościa — atrapa podaje ją, gdy żeton nie jest ważny (pułapka sklepu). */
    private const GUEST_PRICE = 999.0;

    /** @var list<array{id: string, name: string}> */
    private array $categories = [['id' => '10', 'name' => 'Bluzy'], ['id' => '20', 'name' => 'Rękawice'], ['id' => '30', 'name' => 'Nowości']];

    /** @var array<string, list<array<string, mixed>>> id kategorii → pozycje */
    private array $catalog = ['10' => [], '20' => [], '30' => []];

    /** @var list<string> ważne żetony */
    private array $tokens = [];

    private int $logins = 0;

    /** @var list<array<string, mixed>> */
    private array $loginVariables = [];

    /** Żeton traci ważność po tylu zapytaniach o listę (0 = nigdy). */
    private int $expireAfterCalls = 0;

    private int $calls = 0;

    /** Sklep nie uznaje żadnego żetonu (logowanie się udaje) — sesja nie do odzyskania. */
    private bool $apiRefused = false;

    /** Licznik pozycji kategorii zgłaszany od drugiej strony (null = prawdziwy). */
    private ?int $reportedTotal = null;

    private string $expirationDate = '2099-01-01T00:00:00+00:00';

    protected function setUp(): void
    {
        parent::setUp();
        Queue::fake();
    }

    public function test_login_sends_the_mutation_and_list_requests_carry_the_token_and_the_customer_check(): void
    {
        $this->addJacket();
        $this->fakeSite();

        iterator_to_array($this->connector()->products(), false);

        $this->assertSame(self::USER, $this->loginVariables[0]['login']);
        $this->assertSame(self::PASSWORD, $this->loginVariables[0]['password']);
        $this->assertSame(SaraB2bClient::fingerprint(self::USER), $this->loginVariables[0]['fingerprint']);
        $this->assertSame(32, strlen($this->loginVariables[0]['fingerprint']));
        $list = Http::recorded(static fn (Request $r): bool => str_contains($r->body(), 'products('))->first()[0];
        $this->assertSame('Bearer tok-1', $list->header('Authorization')[0] ?? null);
        $this->assertStringContainsString('customer { user { email } }', $list->body());
        $this->assertSame(1, $this->logins);
    }

    public function test_wrong_password_is_not_fatal_and_says_what_the_shop_said(): void
    {
        $this->fakeSite();
        $client = new SaraB2bClient(self::USER, 'zle-haslo', 0, static function (int $ms): void {});

        try {
            $client->login();
            $this->fail('logowanie złym hasłem powinno się nie udać');
        } catch (B2bFatalException $e) {
            $this->fail('złe hasło to nie błąd krytyczny: '.$e->getMessage());
        } catch (RuntimeException $e) {
            $this->assertStringContainsString('Nieprawidłowy login lub hasło.', $e->getMessage());
            $this->assertStringContainsString('sprawdź e-mail i hasło', $e->getMessage());
            $this->assertStringNotContainsString('zle-haslo', $e->getMessage());
        }
        $this->assertFalse($client->isLoggedIn());
    }

    public function test_helpers_order_sizes_build_image_urls_and_read_html(): void
    {
        $sizes = ['XXXL', '102', 'S', 'XXLB', 'L', 'LS', '44', 'M', '1SIZE', 'XL', 'XLS', '8', 'XS'];
        usort($sizes, static fn (string $a, string $b): int => SaraB2bConnector::sizeOrder($a) <=> SaraB2bConnector::sizeOrder($b));
        $this->assertSame(['XS', 'S', 'M', 'L', 'LS', 'XL', 'XLS', 'XXLB', 'XXXL', '8', '44', '102', '1SIZE'], $sizes);

        $this->assertSame(
            'https://b2b.saraworkwear.com/picture/fit-in/1600x1600/smart/filters:fill(white)/44e47a150467dfdab55138ea52251994.png',
            SaraB2bConnector::imageUrl('{imageSafeUri}/44e47a150467dfdab55138ea52251994.png'),
        );
        $this->assertNull(SaraB2bConnector::imageUrl('files/inny/zapis.png'));
        $this->assertNull(SaraB2bConnector::imageUrl('{imageSafeUri}/../x.png'));

        $this->assertSame(
            "Lekka bluza na chłodne dni.\nOpis techniczny produktu:\n- dwie kieszenie\n- kaptur & ściągacz",
            SaraB2bConnector::htmlText("<p>Lekka bluza na <span class=\"caps\">chłodne</span> dni.   </p>\n\n<p>Opis techniczny produktu:</p>\n<p>- dwie kieszenie<br />\n- kaptur &amp; ściągacz</p>"),
        );
    }

    public function test_model_is_one_card_with_colour_size_rows_and_a_differently_named_product_under_the_same_code_is_separate(): void
    {
        $this->addJacket();
        $this->addHiVisJacket();
        $this->fakeSite();
        $connector = $this->connector();

        $products = iterator_to_array($connector->products(), false);

        // dwie karty pod jednym Skrótem — SKU każdej to Identyfikator jej pierwszego koloru (Skrót byłby wspólny)
        $this->assertSame(['TEST-01-100-22-00', 'TEST-01-100-27-22'], array_map(static fn (B2bRemoteProduct $p): string => $p->sku, $products));
        $card = $products[0];
        $this->assertSame('ok', $card->raw['status']);
        $this->assertSame('Bluza robocza TESTOWA', $card->name);
        $this->assertSame('Bluzy męskie > Bluzy robocze', $card->category);
        $this->assertSame('TEST-01-100-22-00-S', $card->remoteId);
        $this->assertSame('https://b2b.saraworkwear.com/bluza-robocza-testowa-1', $card->sourceUrl);
        $this->assertSame('Sara Workwear', $connector->manufacturer($card));
        // rozmiary po kolei (S, M, XXLB), pozycja wyprzedaży bez stanu poza kartą, kolor bez wyprzedaży zostaje
        $this->assertSame('Kolory: Granatowy, Czarny + Szary; rozmiary: S, M, XXLB, L', $card->variantSummary);
        $this->assertSame(
            [
                ['TEST-01-100-22-00-S', 'Granatowy / S', 80.5, 100.0, 'Na stanie: 27 szt'],
                ['TEST-01-100-22-00-M', 'Granatowy / M', 80.5, 100.0, 'Brak na stanie'],
                ['TEST-01-100-22-00-XXLB', 'Granatowy / XXLB', 88.0, 110.0, 'Na stanie: 3 szt'],
                ['TEST-01-100-25-71-L', 'Czarny + Szary / L', 70.0, 100.0, 'Na stanie: 5 szt'],
            ],
            array_map(static fn (array $m): array => [$m['remote_id'], $m['size'], $m['price']->net, $m['price']->base, $m['availability']], $card->members),
        );
        $this->assertSame('Bluza robocza TESTOWA, Granatowy S', $card->members[0]['name']);
        $this->assertSame(
            'Na stanie: Granatowy / S, Granatowy / XXLB, Czarny + Szary / L; Brak na stanie: Granatowy / M',
            $card->availability,
        );
        $price = $connector->price($card);
        $this->assertSame(70.0, $price->net);
        $this->assertSame(100.0, $price->base);
        $this->assertFalse($price->order->restricts());
        $this->assertSame(
            [
                [ProductIdentifier::TYPE_SOURCE_CODE, 'TEST-01-100', null, null],
                [ProductIdentifier::TYPE_EAN, '5900000000011', 'TEST-01-100-22-00-S', 'Granatowy / S'],
                [ProductIdentifier::TYPE_EAN, '5900000000028', 'TEST-01-100-22-00-M', 'Granatowy / M'],
                [ProductIdentifier::TYPE_EAN, '5900000000035', 'TEST-01-100-22-00-XXLB', 'Granatowy / XXLB'],
                [ProductIdentifier::TYPE_ALT_CODE, 'TEST-01-100-22-00', 'TEST-01-100-22-00-S', 'Granatowy'],
                [ProductIdentifier::TYPE_EAN, '5900000000042', 'TEST-01-100-25-71-L', 'Czarny + Szary / L'],
                [ProductIdentifier::TYPE_ALT_CODE, 'TEST-01-100-25-71', 'TEST-01-100-25-71-L', 'Czarny + Szary'],
            ],
            array_map(static fn ($i): array => [$i->type, $i->value, $i->remoteId, $i->label], $card->identifiers ?? []),
        );
        $this->assertSame("Bluza robocza na chłodne dni.\n- dwie kieszenie", $connector->description($card));
        $this->assertSame(
            [
                'Informacje ze sklepu Sara Workwear | Skrót (model) | TEST-01-100',
                'Informacje ze sklepu Sara Workwear | Kolekcja | Testowa',
                'Informacje ze sklepu Sara Workwear | Norma | EN 1149-5:2018',
                'Informacje ze sklepu Sara Workwear | Norma | EN ISO 11612:2015 poziom A1+A2, B(1), C(1)',
                // skład różny w kolorach — przy każdym kolorze
                'Informacje ze sklepu Sara Workwear | Skład | Granatowy: 65% poliester / 35% bawełna; Czarny + Szary: 100% bawełna',
                'Informacje ze sklepu Sara Workwear | Cechy szczególne | Kaptur; Elementy odblaskowe',
            ],
            array_map(static fn ($f): string => $f->section.' | '.$f->name.' | '.$f->value, $connector->shopFields($card)),
        );
        // polskie pliki raz (ten sam plik przy każdej pozycji ma inny adres — pierwszy z listy), angielskie bliźniaki pominięte
        $this->assertSame(
            [
                ['Deklaracja TESTOWA PL.pdf', ProductDocument::KIND_CERTIFICATE, 'https://b2b.saraworkwear.com/product/attachment/1003/5001_7003'],
                ['Karta produktu - bluza Testowa.pdf', ProductDocument::KIND_DATASHEET, 'https://b2b.saraworkwear.com/product/attachment/1003/5003_7003'],
                ['Instrukcja użytkowania.pdf', ProductDocument::KIND_MANUAL, 'https://b2b.saraworkwear.com/product/attachment/1004/5005_7004'],
            ],
            array_map(static fn ($d): array => [$d->title, $d->kind, $d->sourceUrl], $connector->documents($card)),
        );
        // po jednym zdjęciu na kolor
        $this->assertSame(
            [
                'https://b2b.saraworkwear.com/picture/fit-in/1600x1600/smart/filters:fill(white)/aaa1.png',
                'https://b2b.saraworkwear.com/picture/fit-in/1600x1600/smart/filters:fill(white)/bbb1.png',
            ],
            $connector->imageUrls($card),
        );

        $hiVis = $products[1];
        $this->assertSame('Bluza robocza TESTOWA HV', $hiVis->name);
        $this->assertSame('Kolor: Żółty odblaskowy + Granatowy; rozmiary: M', $hiVis->variantSummary);
        $this->assertSame(['M'], array_column($hiVis->members, 'size'));
        $this->assertContains('Informacje ze sklepu Sara Workwear | Norma | EN ISO 20471:2013 Klasa 3', array_map(static fn ($f): string => $f->section.' | '.$f->name.' | '.$f->value, $connector->shopFields($hiVis)));
        // karta jednego koloru — cała galeria pozycji wiodącej
        $this->assertSame(
            [
                'https://b2b.saraworkwear.com/picture/fit-in/1600x1600/smart/filters:fill(white)/ccc1.png',
                'https://b2b.saraworkwear.com/picture/fit-in/1600x1600/smart/filters:fill(white)/ccc2.png',
            ],
            $connector->imageUrls($hiVis),
        );

        $summary = implode("\n", $connector->runSummary());
        $this->assertStringContainsString('Lista Sara Workwear: 6 pozycji (rozmiarów), 1 modeli, 3 kolorów → 2 kart', $summary);
        $this->assertStringContainsString('Karty: 2 (1 z rozmiarami albo kolorami w różnych cenach', $summary);
        $this->assertStringContainsString('Pozycje poza kartą (wyprzedane do zera albo bez ceny w PLN): 1, np. TEST-01-100-25-71-XL (wyprzedaż, stan 0)', $summary);
        $this->assertStringContainsString('Pozycje z flagą „Wyprzedaż” (na karcie, do wyczerpania stanu): 1, np. TEST-01-100-25-71-L', $summary);
    }

    public function test_norm_of_only_some_colours_is_not_a_norm_of_the_whole_card(): void
    {
        $this->addJacket();
        // kolor Czarny + Szary bez normy i bez cech szczególnych
        foreach ($this->catalog['10'] as &$item) {
            if (str_starts_with($item['variants'][0]['warehouseSymbol'], 'TEST-01-100-25-71')) {
                $item['properties'] = array_values(array_filter($item['properties'], static fn (array $p): bool => ! in_array($p['symbol'], ['standard', 'specialFeatures'], true)));
            }
        }
        unset($item);
        $this->fakeSite();
        $connector = $this->connector();

        $card = iterator_to_array($connector->products(), false)[0];
        $rows = array_map(static fn ($f): string => $f->name.' | '.$f->value, $connector->shopFields($card));

        $this->assertNotContains('Norma | EN 1149-5:2018', $rows);
        $this->assertContains('Norma (zależnie od koloru) | Granatowy: EN 1149-5:2018, EN ISO 11612:2015 poziom A1+A2, B(1), C(1)', $rows);
        $this->assertContains('Cechy szczególne | Granatowy: Kaptur, Elementy odblaskowe', $rows);
    }

    public function test_gloves_sold_in_packs_of_pairs_get_an_order_condition_and_a_duplicated_size_keeps_its_code(): void
    {
        $this->addGloves();
        $this->fakeSite();
        $connector = $this->connector();

        $gloves = iterator_to_array($connector->products(), false)[0];

        $this->assertSame('TEST-19-RS1', $gloves->sku);
        $this->assertSame('Rękawice > Skórzane', $gloves->category);
        $this->assertSame(['8', '8 (TEST-19-RS1-20-32-9)', '10'], array_column($gloves->members, 'size'));
        $this->assertSame(['order_min_qty' => 6.0, 'order_step_qty' => 6.0, 'order_unit' => 'pa', 'order_varies' => false], $connector->price($gloves)->order->slotValues());
        $this->assertSame('Na stanie: 120 pa', $gloves->members[0]['availability']);
        $this->assertContains('Informacje ze sklepu Sara Workwear | Norma | EN388 3121X', array_map(static fn ($f): string => $f->name === 'Norma' ? $f->section.' | '.$f->name.' | '.$f->value : '', $connector->shopFields($gloves)));
        $this->assertSame('', $connector->description($gloves));
        $summary = implode("\n", $connector->runSummary());
        $this->assertStringContainsString('Ten sam kolor i rozmiar w dwóch pozycjach (rozmiar z indeksem): 1, np. TEST-19-RS1-20-32-9', $summary);
        $this->assertStringContainsString('Bez opisu w sklepie: 1, np. TEST-19-RS1', $summary);
    }

    public function test_item_listed_in_two_categories_counts_once_and_incomplete_items_are_reported(): void
    {
        $this->addJacket();
        // ta sama pozycja także w „Nowościach”
        $this->catalog['30'][] = $this->catalog['10'][0];
        $broken = $this->catalog['10'][0];
        $broken['id'] = '1999';
        $broken['properties'] = array_values(array_filter($broken['properties'], static fn (array $p): bool => $p['symbol'] !== 'shortCode'));
        $broken['variants'][0]['warehouseSymbol'] = 'BEZ-SKROTU-S';
        $this->catalog['30'][] = $broken;
        $this->fakeSite();
        $connector = $this->connector();

        $card = iterator_to_array($connector->products(), false)[0];

        $this->assertCount(4, $card->members);
        $this->assertStringContainsString('Pozycje bez Skrótu, Identyfikatora, rozmiaru albo indeksu (pominięte): 1, np. BEZ-SKROTU-S (Bluza robocza TESTOWA)', implode("\n", $connector->runSummary()));
    }

    public function test_card_without_any_priced_position_is_skipped_with_a_reason(): void
    {
        $this->addJacket();
        $this->addGloves(currency: 'EUR');
        $this->fakeSite();
        $connector = $this->connector();

        $products = iterator_to_array($connector->products(), false);

        $this->assertSame(['TEST-01-100', 'TEST-19-RS1'], array_map(static fn (B2bRemoteProduct $p): string => $p->sku, $products));
        $this->assertSame('skipped', $products[1]->raw['status']);
        $this->assertSame([], $connector->shopFields($products[1]));
        $this->assertStringContainsString('Bez ceny konta albo bez pozycji do zamówienia (pominięte): 1, np. TEST-19-RS1', implode("\n", $connector->runSummary()));
        $this->expectExceptionMessage('ceny konta');
        $connector->price($products[1]);
    }

    public function test_many_cards_without_any_account_price_stop_the_run(): void
    {
        for ($i = 0; $i < 21; $i++) {
            $this->addGloves(currency: 'EUR', code: 'TEST-19-E'.str_pad((string) $i, 2, '0', STR_PAD_LEFT), firstId: 3000 + 10 * $i);
        }
        $this->fakeSite();

        $this->expectException(B2bFatalException::class);
        $this->expectExceptionMessage('bez ceny konta');

        iterator_to_array($this->connector()->products(), false);
    }

    public function test_guest_answer_after_a_lost_session_is_never_used_the_session_is_renewed_once_and_a_lost_one_is_fatal(): void
    {
        $this->addJacket();
        $this->addGloves();
        $this->fakeSite();
        $connector = new SaraB2bConnector($this->client(), pageSize: 2);
        $connector->login();
        $this->expireAfterCalls = 2;

        $products = iterator_to_array($connector->products(), false);

        $this->assertSame(2, $this->logins);
        foreach ($products as $product) {
            foreach ($product->members as $member) {
                $this->assertNotSame(self::GUEST_PRICE, $member['price']->net, 'cena gościa po utracie sesji');
            }
        }

        $client = $this->client();
        $client->login();
        $this->apiRefused = true;

        $this->expectException(B2bFatalException::class);
        $this->expectExceptionMessage('Utracono sesję konta');
        $client->categoryPage('10', 1, 10);
    }

    public function test_token_close_to_expiry_is_renewed_before_the_request(): void
    {
        $this->addJacket();
        $this->expirationDate = '2026-10-02T12:03:00+00:00';
        $this->fakeSite();
        $now = strtotime('2026-10-02T12:00:00+00:00');
        $client = new SaraB2bClient(self::USER, self::PASSWORD, 0, static function (int $ms): void {}, static fn (): int => $now);
        $client->login();

        $client->categoryPage('10', 1, 10);

        $this->assertSame(2, $this->logins);
    }

    public function test_list_with_a_changed_total_is_read_again_once_and_stays_inconsistent_only_then_fails(): void
    {
        $this->addJacket();
        $this->fakeSite();
        $connector = new SaraB2bConnector($this->client(), pageSize: 2);
        $connector->login();
        $this->reportedTotal = 9;
        $again = 0;
        $connector->onListProgress(function (string $message) use (&$again): void {
            if (str_contains($message, 'pobieram od nowa')) {
                $again++;
                $this->reportedTotal = null;
            }
        });

        $this->assertCount(1, iterator_to_array($connector->products(), false));
        $this->assertSame(1, $again);

        $stuck = new SaraB2bConnector($this->client(), pageSize: 2);
        $stuck->login();
        $this->reportedTotal = 9;
        $this->expectExceptionMessage('niespójna także po ponownym pobraniu');
        iterator_to_array($stuck->products(), false);
    }

    public function test_list_pages_through_every_category(): void
    {
        $this->addJacket();
        $this->addGloves();
        $this->fakeSite();
        $connector = new SaraB2bConnector($this->client(), pageSize: 2);
        $connector->login();

        $products = iterator_to_array($connector->products(), false);

        $this->assertSame(['TEST-01-100', 'TEST-19-RS1'], array_map(static fn (B2bRemoteProduct $p): string => $p->sku, $products));
        // Bluzy: 5 pozycji = 3 strony, Rękawice: 3 = 2 strony, Nowości: pusta = 1 strona
        $this->assertCount(6, Http::recorded(static fn (Request $r): bool => str_contains($r->body(), 'products(')));
    }

    public function test_files_and_images_are_fetched_and_an_html_page_is_not_a_file(): void
    {
        $this->addJacket();
        $this->fakeSite();
        $connector = $this->connector();
        $card = iterator_to_array($connector->products(), false)[0];

        $image = $connector->image($card);
        $file = $connector->documentBytes($connector->documents($card)[0]);
        $octet = $connector->documentBytes($connector->documents($card)[1]);

        $this->assertSame('image/png', $image?->mime);
        $this->assertSame('application/pdf', $file['mime']);
        $this->assertStringStartsWith('%PDF-', $file['bytes']);
        // PDF podany jako octet-stream — typ z treści
        $this->assertSame('application/pdf', $octet['mime']);

        $this->expectExceptionMessage('nie wydał pliku');
        $connector->documentBytes(new B2bRemoteDocument('x', 'https://b2b.saraworkwear.com/product/attachment/1/404_1'));
    }

    public function test_sync_creates_cards_with_size_rows_norms_documents_and_images_and_a_second_run_changes_nothing(): void
    {
        Storage::fake('public');
        Storage::fake('local');
        $this->addJacket();
        $this->addGloves();
        $this->fakeSite();

        $result = app(B2bAccountSyncRunner::class)->run($this->account(), delayMs: 0, withImages: true);

        $this->assertSame(2, $result['created'], implode(' | ', $result['errors']));
        $card = Product::query()->where('sku', 'TEST-01-100')->sole();
        $this->assertSame('Sara Workwear', $card->manufacturer);
        $this->assertSame('Bluza robocza TESTOWA', $card->name);
        $this->assertStringContainsString('Bluza robocza na chłodne dni.', (string) $card->description);
        $this->assertEqualsCanonicalizing(
            ['TEST-01-100-22-00-S', 'TEST-01-100-22-00-M', 'TEST-01-100-22-00-XXLB', 'TEST-01-100-25-71-L'],
            B2bProductLink::query()->where('product_id', $card->id)->pluck('remote_id')->all(),
        );
        $slot = ProductSourcePrice::query()->where('product_id', $card->id)->sole();
        $this->assertSame('70.00', (string) $slot->purchase_price);
        $this->assertSame('100.00', (string) $slot->catalog_price_net);
        $this->assertSame('88.00', (string) $slot->size_price_max);
        $this->assertSame(
            ['Czarny + Szary / L' => '70.00', 'Granatowy / M' => '80.50', 'Granatowy / S' => '80.50', 'Granatowy / XXLB' => '88.00'],
            ProductVariant::query()->where('product_id', $card->id)->where('kind', ProductVariant::KIND_SIZE)->orderBy('label')->pluck('purchase_price', 'label')->map(static fn ($p): string => (string) $p)->all(),
        );
        $this->assertSame('sara', $card->manufacturer_norms['source']['connector'] ?? null);
        $this->assertContains('EN 1149-5:2018', array_column($card->manufacturer_norms['rows'] ?? [], 'label'));
        $this->assertSame(
            ['Deklaracja TESTOWA PL.pdf', 'Karta produktu - bluza Testowa.pdf', 'Instrukcja użytkowania.pdf'],
            ProductDocument::query()->where('product_id', $card->id)->orderBy('sort_order')->pluck('title')->all(),
        );
        $this->assertSame(2, ProductImage::query()->where('product_id', $card->id)->count());
        $rows = collect(ProductShopCard::query()->where('product_id', $card->id)->sole()->fields)->flatMap(static fn (array $section): array => array_map(
            static fn (array $row): string => ($section['section'] ?? '').' | '.$row['name'].' | '.$row['value'],
            $section['rows'] ?? [],
        ))->all();
        $this->assertContains('Informacje ze sklepu Sara Workwear | Kolekcja | Testowa', $rows);
        $this->assertSame(
            ['5900000000011', '5900000000028', '5900000000035', '5900000000042'],
            ProductIdentifier::query()->where('product_id', $card->id)->where('type', ProductIdentifier::TYPE_EAN)->orderBy('value')->pluck('value')->all(),
        );
        $gloves = Product::query()->where('sku', 'TEST-19-RS1')->sole();
        $gloveSlot = ProductSourcePrice::query()->where('product_id', $gloves->id)->sole();
        $this->assertSame(6.0, $gloveSlot->order_min_qty);
        $this->assertSame(6.0, $gloveSlot->order_step_qty);

        $before = $this->snapshot();
        $second = app(B2bAccountSyncRunner::class)->run($this->account(), delayMs: 0, withImages: true);

        $this->assertSame(0, $second['created'], implode(' | ', $second['errors']));
        $this->assertSame(0, $second['updated'], implode(' | ', $second['errors']));
        $this->assertSame(2, $second['unchanged'], implode(' | ', $second['errors']));
        $this->assertSame($before, $this->snapshot());
    }

    public function test_registry_detects_sara_by_host_as_the_manufacturer_site(): void
    {
        $registry = app(B2bConnectorRegistry::class);

        $this->assertSame('sara', $registry->keyForSites(['https://b2b.saraworkwear.com/customer/login?redirect=%2Fcustomer']));
        $this->assertSame('Sara Workwear', $registry->label('sara'));
        $this->assertTrue($registry->requiresPassword('sara'));
        $this->assertTrue($registry->isManufacturerSite('sara'));
        $this->assertTrue($registry->groupsSizes('sara'));
        $this->assertTrue($registry->sendsSizePrices('sara'));
        $this->assertSame(['brand' => 'Sara Workwear', 'names' => ['Norma']], $registry->shopFieldNormSource('sara'));

        $account = B2bAccount::query()->create([
            'username' => self::USER, 'password' => 'sekret', 'sites' => ['https://b2b.saraworkwear.com/customer/login'],
        ]);
        $connector = $registry->make($account, 0);

        $this->assertInstanceOf(SaraB2bConnector::class, $connector);
        foreach ([B2bManufacturerSite::class, B2bShopFieldSource::class, B2bShopFieldNormSource::class, B2bDocumentSource::class, B2bImageGallery::class, B2bGroupsSizes::class, B2bSizePriceSource::class] as $interface) {
            $this->assertInstanceOf($interface, $connector);
        }
    }

    // ---- pomocnicze ----

    private function client(): SaraB2bClient
    {
        return new SaraB2bClient(self::USER, self::PASSWORD, 0, static function (int $ms): void {});
    }

    private function connector(): SaraB2bConnector
    {
        $connector = new SaraB2bConnector($this->client());
        $connector->login();

        return $connector;
    }

    private function account(): B2bAccount
    {
        return B2bAccount::query()->firstOrCreate(
            ['username' => self::USER],
            ['password' => self::PASSWORD, 'sites' => ['https://b2b.saraworkwear.com/customer/login'], 'connector' => 'sara', 'sync_images' => true],
        )->fresh();
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
            'manufacturer_norms' => collect($p->manufacturer_norms ?? [])->except('source')->all(),
            'links' => B2bProductLink::query()->where('product_id', $p->id)->orderBy('remote_id')->pluck('remote_id')->all(),
            'variants' => ProductVariant::query()->where('product_id', $p->id)->orderBy('label')->get(['label', 'purchase_price', 'availability'])->toArray(),
            'shop_card' => ProductShopCard::query()->where('product_id', $p->id)->value('fields'),
            'images' => ProductImage::query()->where('product_id', $p->id)->orderBy('sort_order')->pluck('source_url')->all(),
            'documents' => ProductDocument::query()->where('product_id', $p->id)->orderBy('sort_order')->pluck('source_url')->all(),
            'price' => ProductSourcePrice::query()->where('product_id', $p->id)->get(['purchase_price', 'catalog_price_net', 'size_price_max', 'availability'])->toArray(),
        ]])->all();
    }

    /**
     * Bluza w dwóch kolorach: Granatowy (S, M bez stanu, XXLB droższy; pozycje w pomieszanej kolejności),
     * Czarny + Szary (L w wyprzedaży ze stanem, XL w wyprzedaży bez stanu — poza kartą). Skład różny w kolorach.
     */
    private function addJacket(): void
    {
        $navy = ['TEST-01-100-22-00', 'Granatowy', '65% poliester / 35% bawełna', ['aaa1', 'aaa2']];
        $black = ['TEST-01-100-25-71', 'Czarny + Szary', '100% bawełna', ['bbb1']];
        $first = $this->item('1003', 'Bluza robocza TESTOWA', 'TEST-01-100', $navy, 'XXLB', 88.0, 110.0, '5900000000035', stock: 3);
        // sklep podaje przy części pozycji samą kolekcję — kategorią karty jest najgłębsza ścieżka
        $first['categoryPath'] = [['name' => 'TESTOWA']];
        $this->catalog['10'][] = $first;
        $this->catalog['10'][] = $this->item('1001', 'Bluza robocza TESTOWA', 'TEST-01-100', $navy, 'S', 80.5, 100.0, '5900000000011', stock: 27);
        $this->catalog['10'][] = $this->item('1002', 'Bluza robocza TESTOWA', 'TEST-01-100', $navy, 'M', 80.5, 100.0, '5900000000028', stock: 0);
        $this->catalog['10'][] = $this->item('1004', 'Bluza robocza TESTOWA', 'TEST-01-100', $black, 'L', 70.0, 100.0, '5900000000042', stock: 5, sale: true);
        $this->catalog['10'][] = $this->item('1005', 'Bluza robocza TESTOWA', 'TEST-01-100', $black, 'XL', 70.0, 100.0, '5900000000059', stock: 0, sale: true);
    }

    /** Ten sam Skrót, inny wyrób: wersja odblaskowa z normą EN ISO 20471. */
    private function addHiVisJacket(): void
    {
        $hiVis = ['TEST-01-100-27-22', 'Żółty odblaskowy + Granatowy', '80% poliester / 20% bawełna', ['ccc1', 'ccc2']];
        $item = $this->item('1101', 'Bluza robocza TESTOWA HV', 'TEST-01-100', $hiVis, 'M', 120.0, 150.0, '5900000000066', stock: 9);
        foreach ($item['properties'] as &$property) {
            if ($property['symbol'] === 'standard') {
                $property['value'] = ['other' => ['EN ISO 20471:2013 Klasa 3']];
            }
        }
        unset($property);
        $this->catalog['10'][] = $item;
    }

    /** Rękawice po 6 par: rozmiary 8, 8 (błąd sklepu: indeks …-9), 10; bez opisu. */
    private function addGloves(string $currency = 'PLN', string $code = 'TEST-19-RS1', int $firstId = 2001): void
    {
        $white = [$code.'-20-32', 'Biały', 'Kozia skóra / poliester', ['ddd1']];
        foreach ([['8', '8'], ['9', '8'], ['10', '10']] as $i => [$suffix, $size]) {
            $item = $this->item((string) ($firstId + $i), 'Rękawice ze skóry TESTOWE '.$code, $code, $white, $size, 7.2, 7.2, '590000000'.str_pad((string) ($firstId + $i), 4, '0', STR_PAD_LEFT), stock: 120, currency: $currency);
            $item['variants'][0]['warehouseSymbol'] = $code.'-20-32-'.$suffix;
            $item['unit'] = ['name' => 'pa', 'interval' => 6];
            $item['individualUnitInterval'] = 6;
            $item['description'] = '';
            $item['categoryPath'] = [['name' => 'Rękawice'], ['name' => 'Skórzane']];
            foreach ($item['properties'] as &$property) {
                if ($property['symbol'] === 'standard') {
                    $property['value'] = ['other' => ['EN ISO 21420', 'EN388 3121X']];
                }
            }
            unset($property);
            $this->catalog['20'][] = $item;
        }
    }

    /**
     * Pozycja API: jeden rozmiar jednego koloru.
     *
     * @param  array{0: string, 1: string, 2: string, 3: list<string>}  $colour  Identyfikator, kolor, skład, zdjęcia
     * @return array<string, mixed>
     */
    private function item(string $id, string $name, string $shortCode, array $colour, string $size, float $net, float $list, string $ean, float $stock, bool $sale = false, string $currency = 'PLN'): array
    {
        [$colourId, $colourName, $composition, $pictures] = $colour;
        $variantId = (string) (6000 + (int) $id);
        $attachments = [
            ['name' => 'Deklaracja TESTOWA PL.pdf', 'url' => 'https://b2b.saraworkwear.com/product/attachment/'.$id.'/5001_'.$variantId],
            ['name' => 'Declaration TESTOWA EN.pdf', 'url' => 'https://b2b.saraworkwear.com/product/attachment/'.$id.'/5002_'.$variantId],
            ['name' => 'Karta produktu - bluza Testowa.pdf', 'url' => 'https://b2b.saraworkwear.com/product/attachment/'.$id.'/5003_'.$variantId],
            ['name' => 'Product data sheet jacket Testowa.pdf', 'url' => 'https://b2b.saraworkwear.com/product/attachment/'.$id.'/5004_'.$variantId],
        ];
        if ($colourName === 'Czarny + Szary') {
            $attachments[] = ['name' => 'Instrukcja użytkowania.pdf', 'url' => 'https://b2b.saraworkwear.com/product/attachment/'.$id.'/5005_'.$variantId];
        }

        return [
            'id' => $id,
            'name' => $name,
            'niceUrl' => 'bluza-robocza-testowa-'.($id === '1001' ? '1' : $id),
            'description' => "<p>Bluza robocza na <span class=\"caps\">chłodne</span> dni.</p>\n\n<p>- dwie kieszenie</p>",
            'flags' => $sale ? [['name' => 'Wyprzedaż']] : [['name' => 'Nowość']],
            'unit' => ['name' => 'szt', 'interval' => 1],
            'individualUnitInterval' => 1,
            'categoryPath' => [['name' => 'Bluzy męskie'], ['name' => 'Bluzy robocze']],
            'pictures' => array_map(static fn (string $p): string => '{imageSafeUri}/'.$p.'.png', $pictures),
            'attachments' => $attachments,
            'properties' => [
                ['name' => 'Kolekcja', 'symbol' => 'collection', 'value' => ['other' => ['Testowa']]],
                ['name' => 'Norma', 'symbol' => 'standard', 'value' => ['other' => ['EN 1149-5:2018', 'EN ISO 11612:2015 poziom A1+A2, B(1), C(1)']]],
                ['name' => 'Skład', 'symbol' => 'composition', 'value' => ['other' => [$composition]]],
                ['name' => 'Rozmiar', 'symbol' => 'size', 'value' => ['other' => [$size]]],
                ['name' => 'Rozmiar A klatka', 'symbol' => 'size-a', 'value' => ['other' => ['88-92']]],
                ['name' => 'Kolor', 'symbol' => 'color', 'value' => ['other' => [$colourName]]],
                ['name' => 'Cechy szczególne ', 'symbol' => 'specialFeatures', 'value' => ['other' => ['Kaptur', 'Elementy odblaskowe']]],
                ['name' => 'Skrót', 'symbol' => 'shortCode', 'value' => ['other' => [$shortCode]]],
                ['name' => 'Identyfikator', 'symbol' => 'ID', 'value' => ['other' => [$colourId]]],
                ['name' => 'Materiał / powłoka', 'symbol' => null, 'value' => ['other' => null]],
                ['name' => 'Podnosek', 'symbol' => 'toeCap', 'value' => ['other' => null]],
            ],
            'prices' => [
                'sellPrice' => ['nett' => $net, 'currency' => $currency],
                'listPrice' => ['nett' => $list, 'currency' => $currency],
            ],
            'variants' => [[
                'id' => $variantId,
                'warehouseSymbol' => $colourId.'-'.$size,
                'ean' => $ean,
                'availability' => ['buyable' => $stock > 0, 'stock' => ['amount' => $stock, 'unit' => 'szt']],
            ]],
        ];
    }

    // ---- atrapa API ----

    private function fakeSite(): void
    {
        Http::fake(function (Request $request) {
            $url = $request->url();
            $path = (string) parse_url($url, PHP_URL_PATH);

            if (str_starts_with($path, '/picture/')) {
                return Http::response(self::png($path), 200, ['Content-Type' => 'image/png']);
            }
            if (str_starts_with($path, '/product/attachment/')) {
                if (str_contains($path, '/404_')) {
                    return Http::response('<!DOCTYPE html><html><body>Nie znaleziono</body></html>', 200, ['Content-Type' => 'text/html; charset=UTF-8']);
                }

                // sklep wydaje PDF jako application/pdf (sonda 02.10.2026); octet-stream tylko dla karty produktu (5003),
                // żeby rozpoznanie typu po treści było sprawdzane
                return Http::response('%PDF-1.3 test '.$path, 200, ['Content-Type' => str_contains($path, '/5003_') ? 'application/octet-stream' : 'application/pdf']);
            }
            if ($path !== '/api/graphql/frontend') {
                return Http::response('', 404);
            }

            $body = json_decode($request->body(), true) ?: [];
            $query = (string) ($body['query'] ?? '');
            $variables = is_array($body['variables'] ?? null) ? $body['variables'] : [];

            if (str_contains($query, 'createCustomerToken')) {
                $this->loginVariables[] = $variables;
                if (($variables['login'] ?? null) === self::USER && ($variables['password'] ?? null) === self::PASSWORD && strlen((string) ($variables['fingerprint'] ?? '')) > 0) {
                    $this->logins++;
                    $token = 'tok-'.$this->logins;
                    $this->tokens[] = $token;
                    $this->calls = 0;

                    return Http::response(['data' => ['createCustomerToken' => ['accessToken' => ['token' => $token, 'expirationDate' => $this->expirationDate]]]]);
                }

                return Http::response([
                    'errors' => [['message' => '401 Unauthorized', 'path' => ['createCustomerToken'], 'extensions' => ['errors' => ['credentials' => 'Nieprawidłowy login lub hasło.'], 'code' => 'INTERNAL_SERVER_ERROR']]],
                    'data' => null,
                ]);
            }
            if (str_contains($query, 'categoryList')) {
                return Http::response(['data' => ['categoryList' => $this->categories]]);
            }
            if (str_contains($query, 'products(')) {
                $token = (string) preg_replace('/^Bearer\s+/', '', $request->header('Authorization')[0] ?? '');
                $valid = ! $this->apiRefused && in_array($token, $this->tokens, true);
                if ($valid) {
                    $this->calls++;
                    if ($this->expireAfterCalls > 0 && $this->calls > $this->expireAfterCalls) {
                        $this->expireAfterCalls = 0;
                        $this->tokens = [];
                        $valid = false;
                    }
                }
                $items = $this->catalog[(string) ($variables['id'] ?? '')] ?? [];
                $page = (int) ($variables['page'] ?? 1);
                $limit = (int) ($variables['limit'] ?? 40);
                $slice = array_slice($items, ($page - 1) * $limit, $limit);
                if (! $valid) {
                    // gość: ceny cennikowe, bez błędu poza polem customer
                    $slice = array_map(static function (array $item): array {
                        $item['prices']['sellPrice']['nett'] = self::GUEST_PRICE;

                        return $item;
                    }, $slice);
                }
                $count = $page > 1 && $this->reportedTotal !== null ? $this->reportedTotal : count($items);
                $products = [
                    'pagination' => ['itemsCount' => $count, 'lastPage' => max(1, (int) ceil($count / $limit)), 'currentPage' => $page],
                    'items' => $slice,
                ];

                return $valid
                    ? Http::response(['data' => ['customer' => ['user' => ['email' => self::USER]], 'products' => $products]])
                    : Http::response([
                        'errors' => [['message' => 'This action requires authentication', 'path' => ['customer'], 'extensions' => ['code' => 'INTERNAL_SERVER_ERROR']]],
                        'data' => ['customer' => null, 'products' => $products],
                    ]);
            }

            return Http::response(['errors' => [['message' => 'Cannot query field', 'extensions' => ['code' => 'GRAPHQL_VALIDATION_FAILED']]]], 400);
        });
    }

    private static function png(string $seed): string
    {
        $image = imagecreatetruecolor(1, 1);
        imagesetpixel($image, 0, 0, crc32($seed) & 0xFFFFFF);
        ob_start();
        imagepng($image);

        return (string) ob_get_clean();
    }
}
