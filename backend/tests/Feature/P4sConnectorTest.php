<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\B2bAccount;
use App\Models\B2bProductLink;
use App\Models\Product;
use App\Models\ProductDocument;
use App\Models\ProductImage;
use App\Models\ProductShopCard;
use App\Models\ProductSourcePrice;
use App\Services\B2b\B2bAccountSyncRunner;
use App\Services\B2b\B2bConnectorRegistry;
use App\Services\B2b\B2bDocumentSource;
use App\Services\B2b\B2bFatalException;
use App\Services\B2b\B2bImageGallery;
use App\Services\B2b\B2bListProgressAware;
use App\Services\B2b\B2bManufacturerSite;
use App\Services\B2b\B2bRemoteProduct;
use App\Services\B2b\B2bRunSummaryAware;
use App\Services\B2b\B2bShopFieldSource;
use App\Services\B2b\P4sB2bClient;
use App\Services\B2b\P4sB2bConnector;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use RuntimeException;
use Tests\TestCase;

/**
 * Łącznik b2b.p4s.pl na atrapie platformy (Http::fake, bez prawdziwego logowania). Kształt odpowiedzi odwzorowuje
 * platformę z 23.09.2026: jedno API POST /scripts/b2bPortal.jsp {function: …} z JSON-em poprzedzonym pustymi liniami,
 * login → {errorMessage} przy odmowie, checkSession z danymi konta, getProducts (mothers 1 = matki M i pojedyncze T,
 * mothers 0 = pojedyncze i rozmiary R z parentProductId), getProduct z opisem (<br />), normami, oznaczeniami
 * i plikami (karta techniczna P4S bez numeru pliku). Gość dostaje {sessionExpired: true}, a zamiast pliku pustą
 * odpowiedź.
 *
 * Wszystkie dane (id, kody, ceny, opisy) są SYNTETYCZNE.
 */
final class P4sConnectorTest extends TestCase
{
    use RefreshDatabase;

    private const EMAIL = 'zakupy@example.pl';

    private const PASSWORD = 'dobre-haslo';

    private const API = 'https://b2b.p4s.pl/scripts/b2bPortal.jsp';

    /** @var array<int, array<string, mixed>> matki i pojedyncze oferty ogólnej wg id */
    private array $general = [];

    /** @var array<int, array<string, mixed>> matki i pojedyncze „Mojej oferty” wg id */
    private array $own = [];

    /** @var array<int, list<array<string, mixed>>> rozmiary wg id matki */
    private array $sizes = [];

    /** @var array<int, array<string, mixed>> karty wyrobów wg id */
    private array $details = [];

    /** @var array<string, string> pliki: "id/numer" → treść */
    private array $files = [];

    private bool $regulations = false;

    private string $currency = 'PLN';

    private int $logins = 0;

    private bool $signedIn = false;

    private bool $dropSessionOnProduct = false;

    private bool $productsAlwaysExpired = false;

    private bool $dropSessionOnFile = false;

    private bool $counterChangesOnce = false;

    /** @var list<string> */
    private array $functions = [];

    protected function setUp(): void
    {
        parent::setUp();
        Queue::fake();
    }

    public function test_login_posts_json_to_the_portal_and_confirms_the_account_session(): void
    {
        $this->fakeSite();
        $client = $this->client();

        $client->login();

        $this->assertTrue($client->isLoggedIn());
        $this->assertSame('PLN', $client->currency());
        $login = Http::recorded(fn (Request $r): bool => ($r->data()['function'] ?? '') === 'login')->first()[0];
        $this->assertSame(self::API, $login->url());
        $this->assertSame(['function' => 'login', 'userEmail' => self::EMAIL, 'password' => self::PASSWORD, 'language' => 'pl'], $login->data());
        $this->assertSame(['checkSession', 'login', 'checkSession'], $this->functions);
    }

    public function test_wrong_password_fails_with_the_portal_message_and_is_not_fatal(): void
    {
        $this->fakeSite();
        $client = new P4sB2bClient(self::EMAIL, 'zle-haslo', 0, static function (int $ms): void {});

        try {
            $client->login();
            $this->fail('logowanie złym hasłem powinno się nie udać');
        } catch (B2bFatalException $e) {
            $this->fail('złe hasło to nie błąd krytyczny: '.$e->getMessage());
        } catch (RuntimeException $e) {
            $this->assertStringContainsString('Nieprawidłowy login lub hasło', $e->getMessage());
        }
        $this->assertFalse($client->isLoggedIn());
    }

    public function test_portal_demanding_regulations_approval_says_so(): void
    {
        $this->fakeSite();
        $this->regulations = true;

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('akceptacji regulaminu');

        $this->client()->login();
    }

    public function test_price_and_description_read_the_portal_notation(): void
    {
        $this->assertSame(8346, P4sB2bConnector::priceCents('83,46 PLN'));
        $this->assertSame(113305, P4sB2bConnector::priceCents('1 133,05 PLN'));
        $this->assertSame(1234500, P4sB2bConnector::priceCents("12\u{00A0}345,00 PLN"));
        $this->assertNull(P4sB2bConnector::priceCents('83,46 EUR'));
        $this->assertNull(P4sB2bConnector::priceCents('83.46'));
        $this->assertSame(
            "Lekkie okulary.\nCechy:\nSzybka z poliwęglanu & powłoka",
            P4sB2bConnector::descriptionText("Lekkie  okulary.\r<br />\r<br />Cechy:\r<br /><b>Szybka</b> z poliwęglanu &amp; powłoka "),
        );
    }

    public function test_single_product_is_one_card_with_the_p4s_code_shop_fields_documents_and_images(): void
    {
        $this->addProduct(self::goggles());
        $this->fakeSite();

        $products = $this->products();

        $this->assertCount(1, $products);
        $card = $products[0];
        $this->assertSame('900001', $card->remoteId);
        $this->assertSame('000999', $card->sku);
        $this->assertSame('Okulary testowe polaryzacyjne UV400', $card->name);
        $this->assertSame('Ochrona wzroku, twarzy, głowy', $card->category);
        $this->assertSame('https://b2b.p4s.pl/#/product/900001', $card->sourceUrl);
        $this->assertSame([], $card->members);
        $this->assertSame('', $card->variantSummary);
        $this->assertSame('Produkt dostępny', $card->availability);

        $connector = $this->connector();
        $price = $connector->price($card);
        $this->assertSame(83.46, $price?->net);
        $this->assertNull($price?->base);
        $this->assertSame("Sportowe okulary z podwójną szybką.\nCechy:\nOchrona UV 99%", $connector->description($card));
        $this->assertSame('PORTWEST', $connector->manufacturer($card));
        $fields = array_map(static fn ($f): array => [$f->section, $f->name, $f->value], $connector->shopFields($card));
        $this->assertContains(['Informacje handlowe', 'Kod producenta', 'PS99'], $fields);
        $this->assertContains(['Informacje handlowe', 'Opakowanie zbiorcze', 'kart = 60 szt.'], $fields);
        $this->assertContains(['Certyfikacja', 'Normy', 'EN 166:2001, EN 172:1994'], $fields);
        $this->assertContains(['Certyfikacja', 'Kategoria UE', 'Ś.O.I. II'], $fields);
        // karta techniczna P4S (bez numeru pliku) to zestawienie pól karty — pominięta
        $this->assertSame(
            [
                ['Deklaracja zgodności', self::API.'?function=getProductDocument&productId=900001&ordinalNumber=2', ProductDocument::KIND_CERTIFICATE],
                ['Tabela rozmiarów', self::API.'?function=getProductDocument&productId=900001&ordinalNumber=5', ProductDocument::KIND_SIZE_CHART],
            ],
            array_map(static fn ($d): array => [$d->title, $d->sourceUrl, $d->kind], $connector->documents($card)),
        );
        $this->assertSame(
            ['https://b2b.p4s.pl/productDocuments/900001/1_PS99.jpg', 'https://b2b.p4s.pl/productDocuments/900001/2_PS99%20bok.jpg'],
            $connector->imageUrls($card),
        );
    }

    public function test_sizes_in_one_price_are_one_card_with_the_mother_code_and_size_members(): void
    {
        $this->addProduct(self::gloves());
        $this->fakeSite();

        $products = $this->products();

        $this->assertCount(1, $products);
        $card = $products[0];
        $this->assertSame('11.999', $card->sku);
        $this->assertSame('900111', $card->remoteId);
        $this->assertSame('Rękawice testowe powlekane nitrylem', $card->name);
        $this->assertSame(
            [
                ['remote_id' => '900111', 'sku' => '11.999/F07,0', 'name' => 'Rękawice testowe powlekane nitrylem, rozmiar 7'],
                ['remote_id' => '900112', 'sku' => '11.999/F08,0', 'name' => 'Rękawice testowe powlekane nitrylem, rozmiar 8'],
                // rozmiar bez oznaczenia — reszta nazwy rozmiaru po nazwie wyrobu
                ['remote_id' => '900113', 'sku' => '11.999/F09,0', 'name' => 'Rękawice testowe powlekane nitrylem, rozmiar 9'],
            ],
            $card->members,
        );
        $this->assertSame('Warianty: rozmiar 7 (11.999/F07,0); rozmiar 8 (11.999/F08,0); rozmiar 9 (11.999/F09,0)', $card->variantSummary);
        $this->assertSame('Produkt dostępny: rozmiar 7, rozmiar 8; Produkt niedostępny: rozmiar 9', $card->availability);

        $connector = $this->connector();
        $this->assertSame(45.02, $connector->price($card)?->net);
        $fields = array_map(static fn ($f): array => [$f->section, $f->name, $f->value], $connector->shopFields($card));
        $this->assertContains(['Oznaczenia', 'EN 388:2016', '4 X 4 3 D'], $fields);
        $this->assertContains(['Opis techniczny', 'Rozmiary', '7-9'], $fields);
        $this->assertContains(['Informacje handlowe', 'Minimalna ilość zamówienia', '120 para'], $fields);
    }

    public function test_sizes_in_two_prices_are_two_cards_coded_with_their_first_size(): void
    {
        $this->addProduct(self::trousers());
        $this->fakeSite();
        $connector = $this->connector();

        $products = iterator_to_array($connector->products(), false);

        $this->assertSame(
            [
                ['05 99 360 04 056', 'Spodnie testowe FR360 (kolor niebieski, rozmiar 56; kolor szary, rozmiar 56)', 2],
                ['05 99 360 08 058', 'Spodnie testowe FR360 (kolor szary, rozmiar 58)', 1],
            ],
            array_map(static fn (B2bRemoteProduct $p): array => [$p->sku, $p->name, count($p->members)], $products),
        );
        $this->assertSame([134.56, 148.09], array_map(static fn (B2bRemoteProduct $p): ?float => $connector->price($p)?->net, $products));
        $this->assertStringContainsString('1 wyrobów w kilku cenach', implode("\n", $connector->runSummary()));
    }

    public function test_offers_are_merged_without_duplicates_and_non_products_are_skipped(): void
    {
        $this->addProduct(self::goggles());
        $this->addProduct(self::gloves(), own: true, general: false);
        $this->own[900001] = $this->general[900001];
        $service = ['id' => 900900, 'code' => 'TRANSPORT', 'name' => 'Koszty transportu', 'type' => 'U', 'netPrice' => '12,00 PLN', 'available' => '1', 'parentProductId' => 0, 'sizeName' => ''];
        $this->general[900900] = $service;
        $this->fakeSite();
        $connector = $this->connector();

        $products = iterator_to_array($connector->products(), false);

        $this->assertSame(['000999', '11.999'], array_map(static fn (B2bRemoteProduct $p): string => $p->sku, $products));
        $summary = implode("\n", $connector->runSummary());
        $this->assertStringContainsString('Lista P4S (Moja oferta): 2 wyrobów', $summary);
        $this->assertStringContainsString('Lista P4S razem: 2 wyrobów bez powtórzeń', $summary);
        $this->assertStringContainsString('nie są wyrobem (usługi) — pominięte: 1, np. TRANSPORT (typ U)', $summary);
    }

    public function test_mother_without_sizes_missing_detail_or_foreign_code_is_skipped_with_a_reason(): void
    {
        $gloves = self::gloves();
        $gloves['sizes'] = [];
        $this->addProduct($gloves);
        $goggles = self::goggles();
        $goggles['detail']['code'] = 'INNY';
        $this->addProduct($goggles);
        $gone = self::trousers();
        $gone['detail'] = null;
        $this->addProduct($gone);
        $this->fakeSite();
        $connector = $this->connector();

        $products = iterator_to_array($connector->products(), false);

        $this->assertSame(['skipped', 'skipped', 'skipped'], array_map(static fn (B2bRemoteProduct $p): string => $p->raw['status'], $products));
        $reasons = [];
        foreach ($products as $product) {
            try {
                $connector->price($product);
            } catch (RuntimeException $e) {
                $reasons[$product->sku] = $e->getMessage();
            }
        }
        $this->assertSame('wyrób bez rozmiarów w ofercie konta', $reasons['11.999']);
        $this->assertSame('karta dotyczy innego kodu (INNY)', $reasons['000999']);
        $this->assertSame('platforma: wyrobu nie ma w ofercie konta', $reasons['05 99 360 00 000']);
    }

    public function test_account_in_another_currency_stops_before_the_list(): void
    {
        $this->addProduct(self::goggles());
        $this->fakeSite();
        $this->currency = 'EUR';
        $connector = $this->connector();

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('EUR');

        iterator_to_array($connector->products(), false);
    }

    public function test_list_across_pages_changed_once_is_read_again_from_the_start(): void
    {
        $this->addProduct(self::goggles());
        $this->addProduct(self::gloves());
        $this->addProduct(self::trousers());
        $this->fakeSite();
        $this->counterChangesOnce = true;
        $connector = new P4sB2bConnector($this->client(), 1);
        $connector->login();
        $progress = [];
        $connector->onListProgress(static function (string $m) use (&$progress): void {
            $progress[] = $m;
        });

        $products = iterator_to_array($connector->products(), false);

        $this->assertCount(4, $products);
        $this->assertStringContainsString('pobieram od nowa', implode("\n", $progress));
    }

    public function test_session_lost_on_a_product_logs_in_again_once(): void
    {
        $this->addProduct(self::goggles());
        $this->fakeSite();
        $connector = $this->connector();
        $this->dropSessionOnProduct = true;

        $products = iterator_to_array($connector->products(), false);

        $this->assertSame('ok', $products[0]->raw['status']);
        $this->assertSame(2, $this->logins);
    }

    public function test_products_never_signed_in_are_fatal(): void
    {
        $this->addProduct(self::goggles());
        $this->fakeSite();
        $connector = $this->connector();
        $this->productsAlwaysExpired = true;

        $this->expectException(B2bFatalException::class);
        $this->expectExceptionMessage('Utracono sesję konta b2b.p4s.pl');

        iterator_to_array($connector->products(), false);
    }

    public function test_empty_file_means_a_lost_session_and_the_file_is_fetched_after_logging_in_again(): void
    {
        $this->addProduct(self::goggles());
        $this->fakeSite();
        $connector = $this->connector();
        $card = iterator_to_array($connector->products(), false)[0];
        $this->dropSessionOnFile = true;

        $file = $connector->documentBytes($connector->documents($card)[0]);

        $this->assertStringStartsWith('%PDF-', $file['bytes']);
        $this->assertSame('application/pdf', $file['mime']);
        $this->assertSame(2, $this->logins);
    }

    public function test_file_listed_on_the_card_but_missing_is_skipped_without_stopping_the_run(): void
    {
        Storage::fake('public');
        Storage::fake('local');
        $goggles = self::goggles();
        // platforma wypisuje plik, a przy aktywnej sesji oddaje pustą odpowiedź (09-430/10,0L, 23.09.2026)
        $goggles['detail']['documents'][] = ['ordinalNumber' => 9, 'productId' => 900001, 'description' => 'Instrukcja użytkowania', 'type' => 'document'];
        $this->addProduct($goggles);
        unset($this->files['900001/9']);
        $this->addProduct(self::gloves());
        $this->fakeSite();

        $connector = $this->connector();
        $card = iterator_to_array($connector->products(), false)[0];
        try {
            $connector->documentBytes($connector->documents($card)[2]);
            $this->fail('brakujący plik powinien dać błąd pliku');
        } catch (B2bFatalException $e) {
            $this->fail('brakujący plik to nie utrata sesji: '.$e->getMessage());
        } catch (RuntimeException $e) {
            $this->assertStringContainsString('nie wydała pliku', $e->getMessage());
        }
        $this->assertSame(1, $this->logins);

        $result = app(B2bAccountSyncRunner::class)->run($this->account(), delayMs: 0, withImages: false);

        $this->assertSame(2, $result['created'], implode(' | ', $result['errors']));
        $this->assertSame(
            ['Deklaracja zgodności', 'Tabela rozmiarów'],
            ProductDocument::query()->where('product_id', Product::query()->where('sku', '000999')->value('id'))->orderBy('sort_order')->pluck('title')->all(),
        );
    }

    public function test_sync_creates_p4s_cards_with_prices_links_shop_card_documents_and_images_and_a_second_run_changes_nothing(): void
    {
        Storage::fake('public');
        Storage::fake('local');
        $this->addProduct(self::goggles());
        $this->addProduct(self::gloves());
        $this->addProduct(self::trousers());
        $this->fakeSite();

        $result = app(B2bAccountSyncRunner::class)->run($this->account(), delayMs: 0, withImages: true);

        $this->assertSame(4, $result['created'], implode(' | ', $result['errors']));
        $gloves = Product::query()->where('sku', '11.999')->sole();
        $this->assertSame('LEBON', $gloves->manufacturer);
        $this->assertSame(
            ['900111', '900112', '900113'],
            B2bProductLink::query()->where('product_id', $gloves->id)->orderBy('remote_id')->pluck('remote_id')->all(),
        );
        $slot = ProductSourcePrice::query()->where('product_id', $gloves->id)->sole();
        $this->assertSame('45.02', (string) $slot->purchase_price);
        $this->assertStringContainsString('Pierwsza warstwa powleczenia', (string) $gloves->description);
        $this->assertTrue(ProductShopCard::query()->where('product_id', $gloves->id)->exists());
        $goggles = Product::query()->where('sku', '000999')->sole();
        $this->assertSame(
            [['Deklaracja zgodności', ProductDocument::KIND_CERTIFICATE], ['Tabela rozmiarów', ProductDocument::KIND_SIZE_CHART]],
            ProductDocument::query()->where('product_id', $goggles->id)->orderBy('sort_order')->get()
                ->map(static fn (ProductDocument $d): array => [(string) $d->title, (string) $d->kind])->all(),
        );
        $this->assertSame(
            ['https://b2b.p4s.pl/productDocuments/900001/1_PS99.jpg', 'https://b2b.p4s.pl/productDocuments/900001/2_PS99%20bok.jpg'],
            ProductImage::query()->where('product_id', $goggles->id)->orderBy('sort_order')->pluck('source_url')->all(),
        );
        $this->assertSame('148.09', (string) ProductSourcePrice::query()
            ->where('product_id', Product::query()->where('sku', '05 99 360 08 058')->value('id'))->value('purchase_price'));

        $before = $this->snapshot();
        $second = app(B2bAccountSyncRunner::class)->run($this->account(), delayMs: 0, withImages: true);

        $this->assertSame(0, $second['created'], implode(' | ', $second['errors']));
        $this->assertSame(0, $second['updated'], implode(' | ', $second['errors']));
        $this->assertSame(4, $second['unchanged'], implode(' | ', $second['errors']));
        $this->assertSame($before, $this->snapshot());
    }

    public function test_a_file_added_later_under_the_same_script_address_is_stored_next_to_the_existing_ones(): void
    {
        Storage::fake('public');
        Storage::fake('local');
        $this->addProduct(self::goggles());
        $this->fakeSite();
        app(B2bAccountSyncRunner::class)->run($this->account(), delayMs: 0, withImages: false);

        // pliki P4S różnią się tylko zapytaniem (ordinalNumber) — nowy plik nie może uchodzić za już zapisany
        $this->details[900001]['documents'][] = ['ordinalNumber' => 7, 'productId' => 900001, 'description' => 'Instrukcja użytkowania', 'type' => 'document'];
        $this->files['900001/7'] = "%PDF-1.4\ninstrukcja\n%%EOF";
        $result = app(B2bAccountSyncRunner::class)->run($this->account(), delayMs: 0, withImages: false);

        $this->assertSame([], $result['errors']);
        $goggles = Product::query()->where('sku', '000999')->sole();
        $this->assertSame(
            ['Deklaracja zgodności', 'Tabela rozmiarów', 'Instrukcja użytkowania'],
            ProductDocument::query()->where('product_id', $goggles->id)->orderBy('sort_order')->pluck('title')->all(),
        );
    }

    public function test_registry_detects_p4s_by_host_as_a_distributor(): void
    {
        $registry = app(B2bConnectorRegistry::class);

        $this->assertSame('p4s', $registry->keyForSites(['https://b2b.p4s.pl/#/login']));
        $this->assertSame('P4S', $registry->label('p4s'));
        $this->assertTrue($registry->requiresPassword('p4s'));
        $this->assertNull($registry->discountRulesMode('p4s'));

        $account = B2bAccount::query()->create([
            'username' => self::EMAIL, 'password' => 'sekret', 'sites' => ['https://b2b.p4s.pl/'],
        ]);
        $connector = $registry->make($account, 0);

        $this->assertInstanceOf(P4sB2bConnector::class, $connector);
        $this->assertNotInstanceOf(B2bManufacturerSite::class, $connector);
        foreach ([B2bShopFieldSource::class, B2bDocumentSource::class, B2bImageGallery::class, B2bRunSummaryAware::class, B2bListProgressAware::class] as $interface) {
            $this->assertInstanceOf($interface, $connector);
        }
    }

    // ---- pomocnicze ----

    private function client(): P4sB2bClient
    {
        return new P4sB2bClient(self::EMAIL, self::PASSWORD, 0, static function (int $ms): void {});
    }

    private function connector(): P4sB2bConnector
    {
        $connector = new P4sB2bConnector($this->client());
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

    private function account(): B2bAccount
    {
        return B2bAccount::query()->firstOrCreate(
            ['username' => self::EMAIL],
            ['password' => self::PASSWORD, 'sites' => ['https://b2b.p4s.pl/'], 'connector' => 'p4s', 'sync_images' => true],
        )->fresh();
    }

    /**
     * @param  array{row: array<string, mixed>, sizes: list<array<string, mixed>>, detail: array<string, mixed>|null}  $product
     */
    private function addProduct(array $product, bool $own = false, bool $general = true): void
    {
        $id = $product['row']['id'];
        if ($general) {
            $this->general[$id] = $product['row'];
        }
        if ($own) {
            $this->own[$id] = $product['row'];
        }
        $this->sizes[$id] = $product['sizes'];
        if ($product['detail'] !== null) {
            $this->details[$id] = $product['detail'];
            foreach ($product['detail']['documents'] as $document) {
                if (isset($document['ordinalNumber'])) {
                    $this->files[$id.'/'.$document['ordinalNumber']] = "%PDF-1.4\n".$document['description']."\n%%EOF";
                }
            }
        }
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
            'documents' => ProductDocument::query()->where('product_id', $p->id)->orderBy('sort_order')->pluck('source_url')->all(),
            'images' => ProductImage::query()->where('product_id', $p->id)->orderBy('sort_order')->pluck('source_url')->all(),
            'price' => ProductSourcePrice::query()->where('product_id', $p->id)->get(['purchase_price', 'catalog_price_net', 'availability'])->toArray(),
        ]])->all();
    }

    /**
     * @return array<string, mixed>
     */
    private static function row(int $id, string $code, string $name, string $type, string $price, string $available = '0', int $parent = 0, string $sizeName = ''): array
    {
        return [
            'sizing' => '0', 'image' => '', 'parentProductId' => $parent, 'code' => $code, 'quantity' => '', 'available' => $available,
            'netPrice' => $price, 'measureUnit' => 'szt.', 'manufacturerCode' => '', 'type' => $type, 'favourite' => '0',
            'sizeName' => $sizeName, 'name' => $name, 'servicedNetPrice' => '0,00 PLN', 'newProductId' => 0, 'serviced' => '0',
            'id' => $id, 'servicedQuantity' => '', 'outlet' => false, 'minimumQuantity' => 1, 'expirationDate' => '23.12.2026',
        ];
    }

    /**
     * @param  array<string, mixed>  $fields
     * @return array<string, mixed>
     */
    private static function detail(int $id, string $code, string $name, array $fields): array
    {
        return [
            'sizing' => '0', 'code' => $code, 'documents' => [['productId' => $id, 'description' => 'Karta techniczna P4S', 'type' => 'technicalCard']],
            'available' => '0', 'customerCode' => '', 'netPrice' => '', 'type' => 'M', 'characteristic' => '', 'servicedNetPrice' => '0,00 PLN',
            'newProductId' => 0, 'id' => $id, 'minimumQuantity' => 1, 'brand' => '', 'expirationDate' => '23.12.2026', 'group' => '',
            'europeanNorms' => '', 'images' => [], 'quantity' => '', 'technicalDescriptions' => [], 'categoryCE' => '', 'measureUnit' => 'szt.',
            'manufacturerCode' => '', 'marks' => [], 'favourite' => 0, 'packings' => [], 'taxRate' => '23%', 'application' => '',
            'sizings' => [], 'name' => $name, 'serviced' => '0', 'modifiedProduct' => false, 'servicedQuantity' => '', 'sizingParameters' => [],
            ...$fields,
        ];
    }

    /**
     * @return array{row: array<string, mixed>, sizes: list<array<string, mixed>>, detail: array<string, mixed>}
     */
    private static function goggles(): array
    {
        return [
            'row' => self::row(900001, '000999', 'Okulary testowe polaryzacyjne UV400', 'T', '83,46 PLN', '1'),
            'sizes' => [],
            'detail' => self::detail(900001, '000999', 'Okulary testowe polaryzacyjne UV400', [
                'type' => 'T',
                'characteristic' => "Sportowe okulary z podwójną szybką.\r<br />\r<br />Cechy:\r<br />Ochrona UV 99%",
                'brand' => 'PORTWEST',
                'group' => 'Ochrona wzroku, twarzy, głowy',
                'europeanNorms' => 'EN 166:2001, EN 172:1994',
                'categoryCE' => 'Ś.O.I. II',
                'manufacturerCode' => 'PS99',
                'packings' => [['measureUnit' => 'kart', 'baseMeasureUnit' => 'szt.', 'ratio' => 60]],
                'images' => ['productDocuments/900001/1_PS99.jpg', 'productDocuments/900001/2_PS99 bok.jpg'],
                'documents' => [
                    ['productId' => 900001, 'description' => 'Karta techniczna P4S', 'type' => 'technicalCard'],
                    ['ordinalNumber' => 2, 'productId' => 900001, 'description' => 'Deklaracja zgodności', 'type' => 'document'],
                    ['ordinalNumber' => 5, 'productId' => 900001, 'description' => 'Product.sizesTable', 'type' => 'document'],
                ],
            ]),
        ];
    }

    /**
     * @return array{row: array<string, mixed>, sizes: list<array<string, mixed>>, detail: array<string, mixed>}
     */
    private static function gloves(): array
    {
        $name = 'Rękawice testowe powlekane nitrylem';

        return [
            'row' => self::row(900110, '11.999', $name, 'M', '45,02 PLN'),
            'sizes' => [
                self::row(900111, '11.999/F07,0', $name.', rozmiar 7', 'R', '45,02 PLN', '1', 900110, 'rozmiar 7'),
                self::row(900112, '11.999/F08,0', $name.', rozmiar 8', 'R', '45,02 PLN', '1', 900110, 'rozmiar 8'),
                self::row(900113, '11.999/F09,0', $name.', rozmiar 9', 'R', '45,02 PLN', '0', 900110),
            ],
            'detail' => self::detail(900110, '11.999', $name, [
                'characteristic' => "Rękawice dziane bezszwowo.\r<br />Pierwsza warstwa powleczenia 3/4 z nitrylu.",
                'brand' => 'LEBON',
                'group' => 'Ochrona rąk',
                'europeanNorms' => 'EN 388:2016, EN 420:2010',
                'categoryCE' => 'Ś.O.I. II',
                'measureUnit' => 'para',
                'minimumQuantity' => 120,
                'marks' => [['discriminant' => 'EN 388:2016', 'value' => '4 X 4 3 D']],
                'technicalDescriptions' => [['discriminant' => 'Rozmiary', 'value' => '7-9']],
                'images' => ['productDocuments/900110/1_11.999.jpg'],
            ]),
        ];
    }

    /**
     * @return array{row: array<string, mixed>, sizes: list<array<string, mixed>>, detail: array<string, mixed>}
     */
    private static function trousers(): array
    {
        $name = 'Spodnie testowe FR360';

        return [
            'row' => self::row(900200, '05 99 360 00 000', $name, 'M', '134,56 PLN'),
            'sizes' => [
                self::row(900201, '05 99 360 04 056', $name.', kolor niebieski, rozmiar 56', 'R', '134,56 PLN', '0', 900200, 'kolor niebieski, rozmiar 56'),
                self::row(900202, '05 99 360 08 056', $name.', kolor szary, rozmiar 56', 'R', '134,56 PLN', '0', 900200, 'kolor szary, rozmiar 56'),
                self::row(900203, '05 99 360 08 058', $name.', kolor szary, rozmiar 58', 'R', '148,09 PLN', '0', 900200, 'kolor szary, rozmiar 58'),
            ],
            'detail' => self::detail(900200, '05 99 360 00 000', $name, [
                'characteristic' => 'Spodnie spawalnicze z bawełny.',
                'brand' => 'PLANAM',
                'group' => 'Odzież robocza i ochronna',
                'europeanNorms' => 'EN ISO 11611:2015',
            ]),
        ];
    }

    // ---- atrapa platformy ----

    private function fakeSite(): void
    {
        $listCalls = 0;
        Http::fake(function (Request $request) use (&$listCalls) {
            $url = $request->url();
            // JSP poprzedza JSON pustymi liniami
            $json = static fn (array $data) => Http::response(str_repeat("\r\n", 20).json_encode($data, JSON_UNESCAPED_UNICODE)."\r\n", 200, ['Content-Type' => 'text/html;charset=UTF-8']);
            $expired = $json(['sessionExpired' => true, 'recordsTotal' => 0, 'products' => []]);

            if (str_starts_with($url, 'https://b2b.p4s.pl/productDocuments/')) {
                return Http::response(self::jpeg($url), 200, ['Content-Type' => 'image/jpeg']);
            }
            if (str_starts_with($url, self::API.'?')) {
                parse_str((string) parse_url($url, PHP_URL_QUERY), $query);
                if ($this->dropSessionOnFile) {
                    $this->dropSessionOnFile = false;
                    $this->signedIn = false;
                }
                $file = $this->files[($query['productId'] ?? '').'/'.($query['ordinalNumber'] ?? '')] ?? null;
                if (! $this->signedIn || $file === null) {
                    return Http::response('', 200, ['Content-Type' => 'text/html;charset=UTF-8']);
                }

                return Http::response($file, 200, ['Content-Type' => 'application/pdf;charset=UTF-8']);
            }
            if ($url !== self::API) {
                return Http::response('Nie znaleziono '.$url, 404, ['Content-Type' => 'text/html']);
            }

            $data = $request->data();
            $function = (string) ($data['function'] ?? '');
            $this->functions[] = $function;

            if ($function === 'checkSession') {
                return $json($this->signedIn
                    ? ['isSessionActive' => true, 'loggedIn' => true, 'anonymousSession' => false, 'showPrices' => true, 'orderCurrency' => $this->currency, 'requiredRegulationsApproval' => $this->regulations, 'language' => 'pl']
                    : ['isSessionActive' => false]);
            }
            if ($function === 'login') {
                $this->logins++;
                if (($data['userEmail'] ?? null) !== self::EMAIL || ($data['password'] ?? null) !== self::PASSWORD) {
                    return $json(['errorMessage' => 'Nieprawidłowy login lub hasło.']);
                }
                $this->signedIn = true;

                return $json(['redirectPath' => '/products', 'cardLogin' => false]);
            }
            if ($function === 'getProducts') {
                if (! $this->signedIn) {
                    return $expired;
                }
                $listCalls++;
                $offer = $data['productGroupType'] === 'productGroups' ? $this->own : $this->general;
                $items = [];
                foreach ($offer as $id => $row) {
                    if ($data['mothers'] === 1) {
                        $items[] = $row;
                    } elseif ($row['type'] === 'T') {
                        $items[] = $row;
                    } else {
                        array_push($items, ...($this->sizes[$id] ?? []));
                    }
                }
                usort($items, static fn (array $a, array $b): int => strcmp($a['code'], $b['code']));
                $total = count($items);
                if ($this->counterChangesOnce && $listCalls === 2) {
                    $total++;
                }

                return $json(['recordsFiltered' => $total, 'manufacturerCode' => true, 'recordsTotal' => $total, 'products' => array_slice($items, $data['start'], $data['length'])]);
            }
            if ($function === 'getProduct') {
                if ($this->dropSessionOnProduct) {
                    $this->dropSessionOnProduct = false;
                    $this->signedIn = false;
                }
                if (! $this->signedIn || $this->productsAlwaysExpired) {
                    return $json(['sessionExpired' => true]);
                }
                $detail = $this->details[(int) $data['productId']] ?? null;

                return $json($detail === null ? ['productNotInOffer' => true] : ['product' => $detail, 'productNotInOffer' => false]);
            }

            return $json(['errorMessage' => 'Nieznana funkcja '.$function]);
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
