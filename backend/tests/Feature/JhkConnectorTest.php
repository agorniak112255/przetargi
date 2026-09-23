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
use App\Services\B2b\B2bAccountSyncRunner;
use App\Services\B2b\B2bConnectorRegistry;
use App\Services\B2b\B2bDocumentSource;
use App\Services\B2b\B2bFatalException;
use App\Services\B2b\B2bImageGallery;
use App\Services\B2b\B2bListProgressAware;
use App\Services\B2b\B2bManufacturerSite;
use App\Services\B2b\B2bRemoteIdentifier;
use App\Services\B2b\B2bRemoteProduct;
use App\Services\B2b\B2bRunSummaryAware;
use App\Services\B2b\B2bShopFieldSource;
use App\Services\B2b\JhkB2bClient;
use App\Services\B2b\JhkB2bConnector;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use RuntimeException;
use Tests\TestCase;

/**
 * Łącznik jhkpolska.pl na atrapie sklepu (Http::fake, bez prawdziwego logowania). Znaczniki odwzorowują sklep
 * SolEx B2B z 22.09.2026: JSON ustawień s-a-p z „isLoggedIn”, lista /pl/p?strona=N w obu układach sklepu — kafle
 * (article.kaf) i tabela rodzin (td.naglowek-rodzina, tr.lista-produktow-produkt) — z polem stronicowania
 * (data-max), strona wyrobu z blokami cen (ceny-twoja, ceny-przed-rabatem, ceny-detaliczna),
 * tabelą wariantów (tr.rodzina-dziecko, td.CenaPoRabacie albo td.CenaProduktu), polami karty (kontrolka-PoleProduktu),
 * cechami (lista-atrybuty), plikami (pliki-do-produktu) i galerią w JSON (fancyboxUrl).
 *
 * Wszystkie dane (symbole, EAN, ceny, opisy) są SYNTETYCZNE.
 */
final class JhkConnectorTest extends TestCase
{
    use RefreshDatabase;

    private const LOGIN = 'konto@example.test';

    private const PASSWORD = 'dobre-haslo';

    private const BASE = 'https://jhkpolska.pl';

    /** @var array<string, array<string, mixed>> wyroby atrapy w kolejności listy, wg adresu kafla */
    private array $products = [];

    /** @var list<string> */
    private array $validSessions = [];

    private int $logins = 0;

    private int $pageSize = 50;

    /** Układ listy w sklepie: kafle (tak widzi ją gość) albo tabela rodzin (widok konta). */
    private string $listView = 'tiles';

    private bool $dropSessionOnProduct = false;

    private bool $productsAlwaysAnonymous = false;

    private bool $guestPagesAreAccountPages = false;

    private bool $pageCountChangesOnce = false;

    /** @var list<string> */
    private array $requests = [];

    protected function setUp(): void
    {
        parent::setUp();
        Queue::fake();
    }

    public function test_login_posts_the_form_and_confirms_the_account_session(): void
    {
        $this->fakeShop();
        $client = $this->client();

        $client->login();

        $this->assertTrue($client->isLoggedIn());
        $post = Http::recorded(fn (Request $r): bool => $r->method() === 'POST')->first()[0];
        $this->assertSame(
            ['Uzytkownik' => self::LOGIN, 'Haslo' => self::PASSWORD, 'logowanie' => 'Zaloguj'],
            $post->data(),
        );
        $this->assertSame(['gość:/logowanie', 'gość:/logowanie'], $this->requests);
    }

    public function test_wrong_password_fails_without_being_fatal(): void
    {
        $this->fakeShop();
        $client = new JhkB2bClient(self::LOGIN, 'zle-haslo', 0, static function (int $ms): void {});

        try {
            $client->login();
            $this->fail('logowanie złym hasłem powinno się nie udać');
        } catch (B2bFatalException $e) {
            $this->fail('złe hasło to nie błąd krytyczny: '.$e->getMessage());
        } catch (RuntimeException $e) {
            $this->assertStringContainsString('sprawdź e-mail i hasło', $e->getMessage());
        }
        $this->assertFalse($client->isLoggedIn());
    }

    public function test_price_is_read_only_with_the_currency(): void
    {
        $this->assertSame(4600, JhkB2bConnector::priceCents('46,00 PLN'));
        $this->assertSame(4700, JhkB2bConnector::priceCents(' 47,00 PLN /szt. '));
        $this->assertSame(123405, JhkB2bConnector::priceCents('1 234,05 PLN'));
        $this->assertNull(JhkB2bConnector::priceCents('46,00 EUR'));
        $this->assertNull(JhkB2bConnector::priceCents('46,00'));
        $this->assertNull(JhkB2bConnector::priceCents(''));
    }

    public function test_product_code_is_the_symbol_without_the_size(): void
    {
        $rows = [
            ['symbol' => 'JT SWCR BK XS', 'size' => 'XS'],
            ['symbol' => 'JT SWCR BK 3XL', 'size' => '3XL'],
        ];
        $this->assertSame('JT SWCR BK', JhkB2bConnector::productCode($rows));

        // odzież dziecięca: ten sam rozmiar raz z ukośnikiem (symbol), raz z myślnikiem (tabela)
        $this->assertSame('TSRK 150 BC', JhkB2bConnector::productCode([
            ['symbol' => 'TSRK 150 BC 3/4', 'size' => '3-4'],
            ['symbol' => 'TSRK 150 BC 5/6', 'size' => '5-6'],
        ]));

        // symbol, w którym rozmiaru nie ma na końcu, nie daje kodu — nie zgadujemy rdzenia
        $this->assertNull(JhkB2bConnector::productCode([
            ['symbol' => 'JT SWCR BK XS', 'size' => 'XS'],
            ['symbol' => 'JT SWCR GM S', 'size' => 'S'],
        ]));
        $this->assertNull(JhkB2bConnector::productCode([['symbol' => 'KOC CMF BK', 'size' => '']]));
    }

    public function test_sizes_in_one_price_are_one_card_with_the_product_code_and_size_members(): void
    {
        $this->addProduct(self::sweatshirt());
        $this->fakeShop();
        $connector = $this->connector();

        $products = iterator_to_array($connector->products(), false);

        $this->assertCount(1, $products);
        $card = $products[0];
        $this->assertSame('JT TEST BK', $card->sku);
        $this->assertSame('JT TEST BK XS', $card->remoteId);
        $this->assertSame('JHK Bluza JT TEST BK, BK - Black', $card->name);
        $this->assertSame('Bluzy Dresowe > Męskie > JHK JT TEST', $card->category);
        $this->assertSame(self::BASE.'/pl/bluza-jt-test-bk-xxl', $card->sourceUrl);
        $this->assertSame(
            [
                ['remote_id' => 'JT TEST BK XS', 'sku' => 'JT TEST BK XS', 'name' => 'JHK Bluza JT TEST BK, BK - Black XS'],
                ['remote_id' => 'JT TEST BK XXL', 'sku' => 'JT TEST BK XXL', 'name' => 'JHK Bluza JT TEST BK, BK - Black XXL'],
            ],
            $card->members,
        );
        $this->assertSame(
            'Magazyn w Polsce - dostępne 24 h: 45 szt.; Magazyn producenta - dostępne 14 dni: 216 szt.: XS;'
            .' Magazyn w Polsce - dostępne 24 h: 200 szt.: XXL',
            $card->availability,
        );
        $this->assertSame(
            'Rozmiary: XS (JT TEST BK XS, EAN 5900000000101); XXL (JT TEST BK XXL, EAN 5900000000102)',
            $card->variantSummary,
        );

        $price = $connector->price($card);
        $this->assertSame(25.76, $price?->net);
        $this->assertSame(46.0, $price?->base);
        $this->assertSame(44.0, $price?->discountPercent);
        $this->assertSame('JHK', $connector->manufacturer($card));
        $this->assertSame(
            "Bluza unisex z okrągłym dekoltem.\n- Gramatura: 260 g/m²\n- Pakowanie: 25 szt. (karton)",
            $connector->description($card),
        );

        $fields = array_map(static fn ($f): array => [$f->section, $f->name, $f->value], $connector->shopFields($card));
        $this->assertContains(['Oznaczenia', 'Symbol', 'JT TEST BK XS; JT TEST BK XXL'], $fields);
        $this->assertContains(['Oznaczenia', 'EAN', 'XS: 5900000000101; XXL: 5900000000102'], $fields);
        $this->assertContains(['Oznaczenia', 'Kod HS', '61109000'], $fields);
        $this->assertContains(['Cechy', 'Kolor', 'BK - Black'], $fields);
        $this->assertContains(['Informacje handlowe', 'VAT', '23,00 %'], $fields);
        $this->assertContains([
            'Informacje handlowe',
            'Stan magazynowy',
            'XS: Magazyn w Polsce - dostępne 24 h: 45 szt.; Magazyn producenta - dostępne 14 dni: 216 szt.;'
            .' XXL: Magazyn w Polsce - dostępne 24 h: 200 szt.',
        ], $fields);
        // rozmiar otwartej karty dotyczy jednego rozmiaru, a historia zakupów konta nie jest cechą wyrobu
        $names = array_column($fields, 1);
        $this->assertNotContains('Rozmiar', $names);
        $this->assertNotContains('Produkt niekupiony', $names);

        // symbol i EAN każdego rozmiaru na jego pozycji; kod bez rozmiaru („JT TEST BK”) składamy sami — nie jest
        // identyfikatorem ze źródła
        $this->assertSame(
            [
                ['manufacturer_code', 'JT TEST BK XS', 'JT TEST BK XS', 'XS', 'Symbol'],
                ['ean', '5900000000101', 'JT TEST BK XS', 'XS', 'EAN'],
                ['manufacturer_code', 'JT TEST BK XXL', 'JT TEST BK XXL', 'XXL', 'Symbol'],
                ['ean', '5900000000102', 'JT TEST BK XXL', 'XXL', 'EAN'],
            ],
            self::identifierRows($card),
        );
    }

    public function test_sizes_in_two_prices_are_two_cards_named_with_their_sizes(): void
    {
        $this->addProduct(self::sweatshirtSplit());
        $this->fakeShop();
        $connector = $this->connector();

        $products = iterator_to_array($connector->products(), false);

        $this->assertSame(
            [
                ['JT TEST BK XS', 'JHK Bluza JT TEST BK, BK - Black (rozm. XS, XXL)', 25.76, 46.0],
                ['JT TEST BK 3XL', 'JHK Bluza JT TEST BK, BK - Black (rozm. 3XL)', 26.32, 47.0],
            ],
            array_map(
                static fn (B2bRemoteProduct $p): array => [$p->sku, $p->name, $connector->price($p)?->net, $connector->price($p)?->base],
                $products,
            ),
        );
    }

    public function test_product_without_sizes_takes_both_prices_from_the_account_page(): void
    {
        $this->addProduct(self::beanie());
        $this->fakeShop();
        $connector = $this->connector();

        $products = iterator_to_array($connector->products(), false);

        $this->assertCount(1, $products);
        $card = $products[0];
        $this->assertSame('CZZIM TEST BK', $card->sku);
        $this->assertSame([], $card->members);
        $this->assertSame('', $card->variantSummary);
        $this->assertSame(6.16, $connector->price($card)?->net);
        $this->assertSame(11.0, $connector->price($card)?->base);
        $this->assertSame('Magazyn w Polsce - dostępne 24 h: 6476 szt.', $card->availability);
        // strona wyrobu bez rozmiarów nie jest pobierana drugi raz — cenę katalogową ma na stronie konta
        $this->assertNotContains('gość:/pl/czapka-czzim-test-bk', $this->requests);
        $fields = array_map(static fn ($f): array => [$f->name, $f->value], $connector->shopFields($card));
        $this->assertContains(['Kod kreskowy EAN', '5900000000201'], $fields);
        $this->assertContains(['Rozmiar', 'Uni'], $fields);
        // jedna pozycja = symbol karty (remoteId), EAN z pola karty
        $this->assertSame(
            [
                ['manufacturer_code', 'CZZIM TEST BK', 'CZZIM TEST BK', null, 'Symbol'],
                ['ean', '5900000000201', 'CZZIM TEST BK', null, 'Kod kreskowy EAN'],
            ],
            self::identifierRows($card),
        );
    }

    public function test_product_sold_in_quantity_tiers_takes_the_current_tier_as_the_account_price(): void
    {
        $this->addProduct(self::vest());
        $this->fakeShop();
        $connector = $this->connector();

        $products = iterator_to_array($connector->products(), false);

        $this->assertCount(1, $products);
        $card = $products[0];
        $this->assertSame('MOONTEX KO TEST SYF M', $card->sku);
        // cena konta z progu „Twoja cena”, katalogowa ze strony gościa (konto jej nie widzi)
        $this->assertSame(4.48, $connector->price($card)?->net);
        $this->assertSame(8.0, $connector->price($card)?->base);
        $this->assertSame('MOONTEX', $connector->manufacturer($card));
        $fields = array_map(static fn ($f): array => [$f->name, $f->value], $connector->shopFields($card));
        $this->assertContains([
            'Progi cenowe konta',
            '1 - 99 szt.: 4,48 PLN; 100 - 499 szt.: 3,99 PLN; 500 - 999 szt.: 3,67 PLN; 1000 + szt.: 3,40 PLN',
        ], $fields);
        // MOONTEX to inna marka niż właściciel sklepu (JHK) — symbol sklepu jest kodem źródła, nie kodem producenta
        $this->assertSame(
            [
                ['source_code', 'MOONTEX KO TEST SYF M', 'MOONTEX KO TEST SYF M', null, 'Symbol'],
                ['ean', '5900000000201', 'MOONTEX KO TEST SYF M', null, 'Kod kreskowy EAN'],
            ],
            self::identifierRows($card),
        );
    }

    public function test_page_without_a_product_name_or_with_another_price_than_the_tile_is_skipped(): void
    {
        $this->addProduct(self::catalogPage());
        $this->addProduct(self::sweatshirt(tilePriceText: '99,00 PLN'));
        $this->fakeShop();
        $connector = $this->connector();

        $products = iterator_to_array($connector->products(), false);

        $this->assertCount(2, $products);
        $this->assertSame('skipped', $products[0]->raw['status']);
        $this->assertStringContainsString('bez nazwy wyrobu', $products[0]->raw['reason']);
        $this->assertSame('skipped', $products[1]->raw['status']);
        $this->assertStringContainsString('cena z listy', $products[1]->raw['reason']);
        $this->assertStringContainsString('2 pozycji pominiętych', implode("\n", $connector->runSummary()));

        $this->expectException(RuntimeException::class);
        $connector->price($products[0]);
    }

    public function test_list_in_the_account_table_view_gives_the_same_cards_as_the_tiles(): void
    {
        $this->addProduct(self::sweatshirt());
        $this->addProduct(self::beanie());
        $this->fakeShop();
        // widok konta: wyrób z rozmiarami jest nagłówkiem rodziny (bez ceny), wyrób bez rozmiarów wierszem z ceną
        $this->listView = 'rows';
        $connector = $this->connector();

        $products = iterator_to_array($connector->products(), false);

        $this->assertSame(
            [['JT TEST BK', 25.76, 46.0], ['CZZIM TEST BK', 6.16, 11.0]],
            array_map(
                static fn (B2bRemoteProduct $p): array => [$p->sku, $connector->price($p)?->net, $connector->price($p)?->base],
                $products,
            ),
        );
    }

    public function test_second_tile_of_the_same_size_family_is_skipped_instead_of_doubling_the_card(): void
    {
        $product = self::sweatshirt();
        $twin = $product;
        // drugi kafel tej samej rodziny (sklep prowadzi z niego do tej samej strony rozmiarów)
        $twin['tile_path'] = '/pl/bluza-jt-test-bk-xs';
        $this->addProduct($product);
        $this->addProduct($twin);
        $this->fakeShop();
        $connector = $this->connector();

        $products = iterator_to_array($connector->products(), false);

        $this->assertCount(2, $products);
        $this->assertSame('ok', $products[0]->raw['status']);
        $this->assertSame('skipped', $products[1]->raw['status']);
        $this->assertStringContainsString('ta sama karta co kafel /pl/bluza-jt-test-bk-xxl', $products[1]->raw['reason']);
    }

    public function test_guest_page_that_is_an_account_page_leaves_the_card_without_the_catalog_price(): void
    {
        $this->addProduct(self::sweatshirt());
        $this->fakeShop();
        $this->guestPagesAreAccountPages = true;
        $connector = $this->connector();

        $products = iterator_to_array($connector->products(), false);

        $this->assertNull($connector->price($products[0])?->base);
        $summary = implode("\n", $connector->runSummary());
        $this->assertStringContainsString('Bez ceny katalogowej', $summary);
        $this->assertStringContainsString('nie jest stroną gościa', $summary);
    }

    public function test_catalog_price_below_the_account_price_is_not_used(): void
    {
        $product = self::sweatshirt();
        $product['sizes'][0]['catalog'] = 2000;
        $product['sizes'][1]['catalog'] = 2000;
        $this->addProduct($product);
        $this->fakeShop();
        $connector = $this->connector();

        $products = iterator_to_array($connector->products(), false);

        $this->assertCount(1, $products);
        $this->assertNull($connector->price($products[0])?->base);
        $this->assertSame(0.0, $connector->price($products[0])?->discountPercent);
    }

    public function test_size_without_an_account_price_stays_off_the_card(): void
    {
        $product = self::sweatshirt();
        $product['sizes'][1]['account'] = null;
        $this->addProduct($product);
        $this->fakeShop();
        $connector = $this->connector();

        $products = iterator_to_array($connector->products(), false);

        $this->assertSame(['JT TEST BK XS'], array_column($products[0]->members, 'remote_id'));
        $this->assertStringContainsString('JT TEST BK XXL', implode("\n", $connector->runSummary()));
    }

    public function test_files_keep_the_polish_version_and_stay_out_of_the_description(): void
    {
        $this->addProduct(self::jacket());
        $this->fakeShop();
        $connector = $this->connector();

        $products = iterator_to_array($connector->products(), false);

        $this->assertSame(
            [
                // najpierw blok „Karty do pobrania” (z kompletu językowego zostaje polski), potem pliki z opisu
                ['hv-test-karta-produktu-pl', self::BASE.'/zasoby/import/h/hv-test-karta-produktu-pl.pdf', ProductDocument::KIND_DATASHEET],
                ['Karta produktu dostępna tutaj!', self::BASE.'/zasoby/obrazki/HV%20TEST_PL_EN_DE.pdf', ProductDocument::KIND_DATASHEET],
                ['Sprawdź certyfikaty produktu', self::BASE.'/zasoby/obrazki/HV%20TEST_certificates_en.pdf', ProductDocument::KIND_CERTIFICATE],
            ],
            array_map(
                static fn ($d): array => [$d->title, $d->sourceUrl, $d->kind],
                $connector->documents($products[0]),
            ),
        );
        // opis kończy się przed nagłówkiem listy załączników, a odnośnik do strony sklepu w nim zostaje
        $this->assertSame('Kurtka softshell z zapięciem na zamek. Tabela rozmiarów', $connector->description($products[0]));
        $this->assertSame('MOONTEX', $connector->manufacturer($products[0]));
    }

    public function test_gallery_falls_back_to_the_main_photo_without_preview_parameters(): void
    {
        $this->addProduct(self::sweatshirt());
        $withoutGallery = self::beanie();
        $withoutGallery['gallery'] = [];
        $this->addProduct($withoutGallery);
        $this->fakeShop();
        $connector = $this->connector();

        $products = iterator_to_array($connector->products(), false);

        $this->assertSame(
            [self::BASE.'/zasoby/import/j/jt-test-bk-xs_01.jpg', self::BASE.'/zasoby/import/j/jt-test-bk-xs_02.jpg'],
            $connector->imageUrls($products[0]),
        );
        $this->assertSame([self::BASE.'/zasoby/import/c/czzim-test-bk.jpg'], $connector->imageUrls($products[1]));
    }

    public function test_list_across_pages_changed_once_is_read_again_from_the_start(): void
    {
        $this->addProduct(self::sweatshirt());
        $this->addProduct(self::beanie());
        $this->fakeShop();
        $this->pageSize = 1;
        $this->pageCountChangesOnce = true;
        $connector = $this->connector();
        $progress = [];
        $connector->onListProgress(static function (string $m) use (&$progress): void {
            $progress[] = $m;
        });

        $products = iterator_to_array($connector->products(), false);

        $this->assertCount(2, $products);
        $this->assertStringContainsString('pobieram od nowa', implode("\n", $progress));
    }

    public function test_session_lost_on_a_product_page_logs_in_again_once(): void
    {
        $this->addProduct(self::sweatshirt());
        $this->fakeShop();
        $connector = $this->connector();
        $this->dropSessionOnProduct = true;

        $products = iterator_to_array($connector->products(), false);

        $this->assertSame('ok', $products[0]->raw['status']);
        $this->assertSame(2, $this->logins);
    }

    public function test_product_pages_never_signed_in_are_fatal(): void
    {
        $this->addProduct(self::sweatshirt());
        $this->fakeShop();
        $connector = $this->connector();
        $this->productsAlwaysAnonymous = true;

        $this->expectException(B2bFatalException::class);
        $this->expectExceptionMessage('Utracono sesję konta jhkpolska.pl');

        iterator_to_array($connector->products(), false);
    }

    public function test_sync_creates_jhk_cards_with_prices_files_images_and_a_second_run_changes_nothing(): void
    {
        Storage::fake('public');
        Storage::fake('local');
        $this->addProduct(self::sweatshirtSplit());
        $this->addProduct(self::beanie());
        $this->fakeShop();

        $result = app(B2bAccountSyncRunner::class)->run($this->account(), delayMs: 0, withImages: true);

        $this->assertSame(3, $result['created'], implode(' | ', $result['errors']));
        $card = Product::query()->where('sku', 'JT TEST BK XS')->sole();
        $this->assertSame('JHK', $card->manufacturer);
        $this->assertSame(
            ['JT TEST BK XS', 'JT TEST BK XXL'],
            B2bProductLink::query()->where('product_id', $card->id)->orderBy('remote_id')->pluck('remote_id')->all(),
        );
        $slot = ProductSourcePrice::query()->where('product_id', $card->id)->sole();
        $this->assertSame('25.76', (string) $slot->purchase_price);
        $this->assertSame('46.00', (string) $slot->catalog_price_net);
        $this->assertStringContainsString('Bluza unisex', (string) $card->description);
        $this->assertTrue(ProductShopCard::query()->where('product_id', $card->id)->exists());
        $this->assertSame(
            [self::BASE.'/zasoby/import/j/jt-test-bk-xs_01.jpg', self::BASE.'/zasoby/import/j/jt-test-bk-xs_02.jpg'],
            ProductImage::query()->where('product_id', $card->id)->orderBy('sort_order')->pluck('source_url')->all(),
        );
        $beanie = Product::query()->where('sku', 'CZZIM TEST BK')->sole();
        $this->assertSame('6.16', (string) ProductSourcePrice::query()->where('product_id', $beanie->id)->value('purchase_price'));

        // identyfikatory na pozycjach kart: rozmiar 3XL (inna cena) na swojej karcie, symbol i EAN każdego rozmiaru
        $this->assertSame(
            [
                ['JT TEST BK XS', 'ean', '5900000000101', 'XS', 'EAN', 'JHK'],
                ['JT TEST BK XS', 'manufacturer_code', 'JT TEST BK XS', 'XS', 'Symbol', 'JHK'],
                ['JT TEST BK XXL', 'ean', '5900000000102', 'XXL', 'EAN', 'JHK'],
                ['JT TEST BK XXL', 'manufacturer_code', 'JT TEST BK XXL', 'XXL', 'Symbol', 'JHK'],
            ],
            self::storedIdentifiers($card->id),
        );
        $split = Product::query()->where('sku', 'JT TEST BK 3XL')->sole();
        $this->assertSame(
            [
                ['JT TEST BK 3XL', 'ean', '5900000000103', '3XL', 'EAN', 'JHK'],
                ['JT TEST BK 3XL', 'manufacturer_code', 'JT TEST BK 3XL', '3XL', 'Symbol', 'JHK'],
            ],
            self::storedIdentifiers($split->id),
        );
        $this->assertSame(
            [
                ['CZZIM TEST BK', 'ean', '5900000000201', null, 'Kod kreskowy EAN', 'JHK'],
                ['CZZIM TEST BK', 'manufacturer_code', 'CZZIM TEST BK', null, 'Symbol', 'JHK'],
            ],
            self::storedIdentifiers($beanie->id),
        );
        $identifiers = ProductIdentifier::query()->count();
        $this->assertSame(8, $identifiers);

        $before = $this->snapshot();
        $second = app(B2bAccountSyncRunner::class)->run($this->account(), delayMs: 0, withImages: true);

        $this->assertSame(0, $second['created'], implode(' | ', $second['errors']));
        $this->assertSame(0, $second['updated'], implode(' | ', $second['errors']));
        $this->assertSame(3, $second['unchanged'], implode(' | ', $second['errors']));
        $this->assertSame($before, $this->snapshot());
        // drugi przebieg nie dubluje identyfikatorów i żadnego nie uznaje za usunięty
        $this->assertSame($identifiers, ProductIdentifier::query()->count());
        $this->assertSame(0, ProductIdentifier::query()->whereNotNull('removed_at')->count());
    }

    public function test_sync_reports_a_skipped_tile_and_saves_the_rest(): void
    {
        Storage::fake('public');
        Storage::fake('local');
        $this->addProduct(self::catalogPage());
        $this->addProduct(self::beanie());
        $this->fakeShop();

        $result = app(B2bAccountSyncRunner::class)->run($this->account(), delayMs: 0, withImages: false);

        $this->assertSame(1, $result['created'], implode(' | ', $result['errors']));
        $this->assertStringContainsString('bez nazwy wyrobu', implode(' | ', $result['errors']));
        $this->assertSame(['CZZIM TEST BK'], Product::query()->pluck('sku')->all());
    }

    public function test_registry_detects_jhk_by_host_as_the_manufacturer_site(): void
    {
        $registry = app(B2bConnectorRegistry::class);

        $this->assertSame('jhk', $registry->keyForSites(['https://jhkpolska.pl/logowanie']));
        $this->assertSame('JHK Polska', $registry->label('jhk'));
        $this->assertTrue($registry->requiresPassword('jhk'));
        $this->assertFalse($registry->requiresLoginCode('jhk'));
        $this->assertNull($registry->discountRulesMode('jhk'));

        $account = B2bAccount::query()->create([
            'username' => self::LOGIN, 'password' => 'sekret', 'sites' => ['https://jhkpolska.pl/'],
        ]);
        $connector = $registry->make($account, 0);

        $this->assertInstanceOf(JhkB2bConnector::class, $connector);
        $this->assertSame('JHK', JhkB2bConnector::ownBrand());
        foreach ([
            B2bManufacturerSite::class, B2bShopFieldSource::class, B2bRunSummaryAware::class,
            B2bListProgressAware::class, B2bDocumentSource::class, B2bImageGallery::class,
        ] as $interface) {
            $this->assertInstanceOf($interface, $connector);
        }
    }

    // ---- pomocnicze ----

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

    private function client(): JhkB2bClient
    {
        return new JhkB2bClient(self::LOGIN, self::PASSWORD, 0, static function (int $ms): void {});
    }

    private function connector(): JhkB2bConnector
    {
        $connector = new JhkB2bConnector($this->client());
        $connector->login();

        return $connector;
    }

    private function account(): B2bAccount
    {
        return B2bAccount::query()->firstOrCreate(
            ['username' => self::LOGIN],
            ['password' => self::PASSWORD, 'sites' => ['https://jhkpolska.pl/'], 'connector' => 'jhk', 'sync_images' => true],
        )->fresh();
    }

    /**
     * @param  array<string, mixed>  $product
     */
    private function addProduct(array $product): void
    {
        $this->products[$product['tile_path']] = $product;
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
            'price' => ProductSourcePrice::query()->where('product_id', $p->id)->get(['purchase_price', 'catalog_price_net', 'availability'])->toArray(),
        ]])->all();
    }

    // ---- wyroby atrapy ----

    /**
     * @return array<string, mixed>
     */
    private static function sweatshirt(?string $tilePriceText = null): array
    {
        return [
            'tile_path' => '/pl/bluza-jt-test-bk-xxl',
            'tile_name' => 'Bluza JT TEST BK',
            'tile_price_text' => $tilePriceText,
            'color' => 'BK - Black',
            'name' => 'Bluza JT TEST BK XXL',
            'categories' => [['Bluzy Dresowe', 'Męskie', 'JHK JT TEST']],
            'fields' => [['Kod kreskowy EAN', '5900000000102'], ['Kod HS', '61109000'], ['Waga:', '0.49 kg']],
            'attributes' => [['Produkt niekupiony:', 'Produkt nie kupiony przez klienta'], ['Kolor:', 'BK - Black'], ['Rozmiar:', 'XXL']],
            'description' => '<p>Bluza unisex z okrągłym dekoltem.</p><ul><li>Gramatura: 260 g/m²</li><li>Pakowanie: 25 szt. (karton)</li></ul>',
            'documents' => [],
            'gallery' => ['/zasoby/import/j/jt-test-bk-xs_01.jpg', '/zasoby/import/j/jt-test-bk-xs_02.jpg'],
            'main_image' => '/zasoby/import/j/jt-test-bk-xs_01.jpg',
            'stock' => [155, 66],
            'sizes' => [
                ['size' => 'XS', 'symbol' => 'JT TEST BK XS', 'ean' => '5900000000101', 'account' => 2576, 'catalog' => 4600, 'path' => '/pl/bluza-jt-test-bk-xs', 'stock' => [45, 216]],
                ['size' => 'XXL', 'symbol' => 'JT TEST BK XXL', 'ean' => '5900000000102', 'account' => 2576, 'catalog' => 4600, 'path' => '/pl/bluza-jt-test-bk-xxl', 'stock' => [200, 0]],
            ],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private static function sweatshirtSplit(): array
    {
        $product = self::sweatshirt();
        $product['sizes'][] = [
            'size' => '3XL', 'symbol' => 'JT TEST BK 3XL', 'ean' => '5900000000103', 'account' => 2632, 'catalog' => 4700,
            'path' => '/pl/bluza-jt-test-bk-3xl', 'stock' => [12, 0],
        ];

        return $product;
    }

    /**
     * @return array<string, mixed>
     */
    private static function beanie(): array
    {
        return [
            'tile_path' => '/pl/czapka-czzim-test-bk',
            'tile_name' => 'CZZIM TEST BK',
            'tile_price_text' => null,
            'color' => 'BK - Black',
            'name' => 'CZZIM TEST BK',
            'categories' => [['Czapki', 'Czapka zimowa Beanie']],
            'fields' => [['Kod kreskowy EAN', '5900000000201'], ['Waga:', '0.08 kg']],
            'attributes' => [['Kolor:', 'BK - Black'], ['Rozmiar:', 'Uni']],
            'description' => '<p>Czapka wykonana w 100% z akrylu.</p>',
            'documents' => [],
            'gallery' => ['/zasoby/import/c/czzim-test-bk.jpg'],
            'main_image' => '/zasoby/import/c/czzim-test-bk.jpg',
            'stock' => [6476, 0],
            'symbol' => 'CZZIM TEST BK',
            'account' => 616,
            'catalog' => 1100,
            'sizes' => [],
        ];
    }

    /**
     * Kamizelka ostrzegawcza sprzedawana progami ilościowymi: strona konta ma tabelę progów zamiast bloku
     * „Twoja cena”, ceny przed rabatem nie ma wcale — cenę detaliczną widzi tylko gość.
     *
     * @return array<string, mixed>
     */
    private static function vest(): array
    {
        $product = self::beanie();
        $product['tile_path'] = '/pl/moontex-kamizelka-ost-test-syf-m';
        $product['tile_name'] = 'MOONTEX Kamizelka ost TEST SYF M';
        $product['tile_price_text'] = '';
        $product['name'] = 'MOONTEX Kamizelka ost TEST SYF M';
        $product['symbol'] = 'MOONTEX KO TEST SYF M';
        $product['categories'] = [['Kamizelki Ostrzegawcze'], ['Wysoka Widoczność']];
        $product['account'] = null;
        $product['catalog'] = 800;
        $product['tiers'] = [['1 - 99', 448], ['100 - 499', 399], ['500 - 999', 367], ['1000 +', 340]];

        return $product;
    }

    /**
     * Kurtka linii Professional: pliki w opisie (karta produktu i certyfikaty) i komplet językowy w blokach plików.
     *
     * @return array<string, mixed>
     */
    private static function jacket(): array
    {
        $product = self::sweatshirt();
        $product['tile_path'] = '/pl/kurtka-moontex-hv-test-orf-xxl';
        $product['tile_name'] = 'MOONTEX HV TEST Kurtka softshell ORF';
        $product['name'] = 'MOONTEX HV TEST Kurtka softshell ORF XXL';
        $product['description'] = '<p>Kurtka softshell z zapięciem na zamek. <a href="/pl/tabela-rozmiarow">Tabela rozmiarów</a></p>'
            .'<p><strong>Załączniki:</strong></p>'
            .'<p><a href="https://jhkpolska.pl/zasoby/obrazki/HV TEST_PL_EN_DE.pdf"><span>📥 </span><strong>Karta produktu dostępna tutaj!</strong></a></p>'
            .'<p><a href="https://jhkpolska.pl/zasoby/obrazki/HV TEST_certificates_en.pdf"><strong>📄 Sprawdź certyfikaty produktu</strong></a></p>';
        $product['documents'] = [
            '/zasoby/import/h/hv-test-karta-produktu-de.pdf',
            '/zasoby/import/h/hv-test-karta-produktu-en.pdf',
            '/zasoby/import/h/hv-test-karta-produktu-pl.pdf',
        ];
        $product['sizes'] = [
            ['size' => 'XS', 'symbol' => 'HV TEST ORF XS', 'ean' => '5900000000301', 'account' => 8960, 'catalog' => 16000, 'path' => '/pl/kurtka-moontex-hv-test-orf-xs', 'stock' => [0, 14]],
            ['size' => 'XXL', 'symbol' => 'HV TEST ORF XXL', 'ean' => '5900000000302', 'account' => 8960, 'catalog' => 16000, 'path' => '/pl/kurtka-moontex-hv-test-orf-xxl', 'stock' => [0, 20]],
        ];

        return $product;
    }

    /**
     * Kafel prowadzący poza kartę wyrobu (strona katalogu) — bez nazwy wyrobu i bez symbolu.
     *
     * @return array<string, mixed>
     */
    private static function catalogPage(): array
    {
        return [
            'tile_path' => '/pl/katalog-test',
            'tile_name' => 'KATALOG TEST',
            'tile_price_text' => null,
            'color' => '',
            'name' => '',
            'categories' => [],
            'fields' => [],
            'attributes' => [],
            'description' => '',
            'documents' => [],
            'gallery' => [],
            'main_image' => '',
            'stock' => [0, 0],
            'symbol' => '',
            'account' => null,
            'catalog' => null,
            'sizes' => [],
        ];
    }

    // ---- atrapa sklepu ----

    private function fakeShop(): void
    {
        $listCalls = 0;
        Http::fake(function (Request $request) use (&$listCalls) {
            $url = $request->url();
            $path = (string) parse_url($url, PHP_URL_PATH);
            parse_str((string) parse_url($url, PHP_URL_QUERY), $query);
            preg_match('/SolExSession=(\w+)/', $request->header('Cookie')[0] ?? '', $m);
            $session = $m[1] ?? '';
            $signedIn = in_array($session, $this->validSessions, true);
            $this->requests[] = ($signedIn ? '' : 'gość:').$path;

            if ($path === '/logowanie') {
                if ($request->method() === 'GET') {
                    $this->logins++;

                    return Http::response(self::loginPage(), 200, [
                        'Content-Type' => 'text/html; charset=utf-8',
                        'Set-Cookie' => 'SolExSession=s'.$this->logins.'; path=/; secure; HttpOnly',
                    ]);
                }
                $data = $request->data();
                if (($data['Uzytkownik'] ?? null) !== self::LOGIN || ($data['Haslo'] ?? null) !== self::PASSWORD) {
                    return Http::response(self::loginPage(), 200, ['Content-Type' => 'text/html; charset=utf-8']);
                }
                $this->validSessions[] = $session;

                return Http::response(self::page('<div>Witamy</div>', account: true), 200, ['Content-Type' => 'text/html; charset=utf-8']);
            }

            if ($path === '/pl/home') {
                return Http::response(self::page('<div>Witamy</div>', account: $signedIn), 200, ['Content-Type' => 'text/html; charset=utf-8']);
            }

            if ($path === '/pl/p') {
                if (! $signedIn) {
                    return Http::response(self::loginPage(), 200, ['Content-Type' => 'text/html; charset=utf-8']);
                }
                $listCalls++;
                $page = max(1, (int) ($query['strona'] ?? 1));
                $pages = (int) ceil(count($this->products) / $this->pageSize);
                if ($this->pageCountChangesOnce && $listCalls === 2) {
                    $pages++;
                }
                $items = array_slice(array_values($this->products), ($page - 1) * $this->pageSize, $this->pageSize);

                return Http::response($this->listPage($items, max(1, $pages)), 200, ['Content-Type' => 'text/html; charset=utf-8']);
            }

            if (str_starts_with($path, '/zasoby/')) {
                return str_ends_with($path, '.pdf')
                    ? Http::response('%PDF-1.4 atrapa', 200, ['Content-Type' => 'application/pdf'])
                    : Http::response(self::jpeg($path), 200, ['Content-Type' => 'image/jpeg']);
            }

            $product = $this->productAt($path);
            if ($product === null) {
                return Http::response('Nie znaleziono '.$url, 404, ['Content-Type' => 'text/html']);
            }
            if ($this->dropSessionOnProduct) {
                $this->dropSessionOnProduct = false;
                $this->validSessions = [];
                $signedIn = false;
            }
            if ($this->productsAlwaysAnonymous) {
                $signedIn = false;
            }
            $account = $signedIn || $this->guestPagesAreAccountPages;

            return Http::response(self::productPage($product, $account), 200, ['Content-Type' => 'text/html; charset=utf-8']);
        });
    }

    /**
     * @return array<string, mixed>|null
     */
    private function productAt(string $path): ?array
    {
        foreach ($this->products as $product) {
            if ($product['tile_path'] === $path) {
                return $product;
            }
            foreach ($product['sizes'] as $size) {
                if ($size['path'] === $path) {
                    return $product;
                }
            }
        }

        return null;
    }

    private static function loginPage(): string
    {
        return self::page(
            '<form method="POST" action="https://jhkpolska.pl/logowanie">'
            .'<input name="Uzytkownik" type="email"><input name="Haslo" type="password">'
            .'<button type="submit" name="logowanie">Zaloguj</button></form>',
            account: false,
        );
    }

    /**
     * @param  list<array<string, mixed>>  $items
     */
    private function listPage(array $items, int $pages): string
    {
        $tiles = '';
        foreach ($items as $item) {
            $price = $item['tile_price_text'] ?? self::priceText(self::tileCents($item));
            if ($this->listView === 'rows') {
                $tiles .= $item['sizes'] !== []
                    // wyrób z rozmiarami: nagłówek rodziny, rozmiary sklep dociąga po rozwinięciu
                    ? '<tr><td class="naglowek-rodzina"><div class="produkt-zdjecie metki-WidokRodzinowy">'
                        .'<a href="'.$item['tile_path'].'" class="foto link-szczegoly"><img class="zdjecie-produktu"></a></div></td>'
                        .'<td class="naglowek-rodzina" colspan="7"><h5><a href="'.$item['tile_path'].'">'.$item['tile_name'].'</a></h5>'
                        .'<span class="text-muted produkt-opis-2linia">'.$item['color'].'</span></td></tr>'
                    : '<tr class="lista-produktow-produkt"><td class="zdjecie"><a href="'.$item['tile_path'].'" class="foto link-szczegoly">'
                        .'<img class="zdjecie-produktu"></a></td>'
                        .'<td class="text-left nazwa"><span class="produkt-nazwa-naglowek">'
                        .'<a class="d-print-none ulubione dodaj" data-produkt-id="1"><i class="far fa-star"></i></a>'
                        .'<a href="'.$item['tile_path'].'" class="produkt-nazwa link-szczegoly"> '.$item['tile_name'].' </a></span>'
                        .'<div class="text-muted kody-pod-nazwa"><div class="text-muted produkt-opis-2linia">'.$item['color'].'</div>'
                        .'<div class="kod"><span class="small">Symbol:</span> '.$item['symbol'].'</div></div></td>'
                        .'<td class="text-right CenaProduktu"><div class="cena"><div class="font-weight-bold netto">'
                        .self::priceText($item['catalog']).'</div></div></td>'
                        .'<td class="text-right CenaPoRabacie"><div class="cena"><div class="font-weight-bold netto">'.$price.'</div></div></td></tr>';

                continue;
            }
            $tiles .= '<div class="col-12 col-sm-6 kafle-produktow-ukryj-przedrostki-cen"><article class="kaf dwie-linie">'
                .'<div class="kaf-opis"><div class="nazwa-produktu"><span class="produkt-nazwa-naglowek">'
                .'<a class="d-print-none ulubione dodaj"><i class="far fa-star"></i></a>'
                .'<a href="'.$item['tile_path'].'" data-s-modal="False" class="clickable produkt-nazwa link-szczegoly">'.$item['tile_name'].'</a>'
                .'</span></div><div class="text-muted kody-pod-nazwa"><div class="text-muted produkt-opis-2linia">'.$item['color'].'</div></div></div>'
                .'<div class="kaf-waluta"><div class="cena"><div class="font-weight-bold netto">'.$price.'</div></div></div>'
                .'</article></div>';
        }
        // karuzela „polecane” z innym wyrobem — nie jest kaflem listy
        $slick = '<div class="kaf-slick" data-produkt-id="1"><article class="kaf"><div class="kaf-opis"><div class="nazwa-produktu">'
            .'<span class="produkt-nazwa-naglowek"><a href="/pl/polecany-test" class="clickable produkt-nazwa link-szczegoly">POLECANY TEST</a>'
            .'</span></div></div></article></div>';
        $pagination = '<div class="stronicowanie"><div class="input-group input-group-sm">'
            .'<input type="text" class="form-control" value="1" title="Wpisz stronę" data-max="'.$pages.'">'
            .'<span class="input-group-text">z '.$pages.'</span></div></div>';
        $list = $this->listView === 'rows'
            ? '<table class="table lista-produktow"><tbody>'.$tiles.'</tbody></table>'
            : '<div class="row kafle-produktow">'.$tiles.'</div>';

        return self::page($slick.$list.$pagination, account: true);
    }

    /**
     * @param  array<string, mixed>  $product
     */
    private static function productPage(array $product, bool $account): string
    {
        if ($product['name'] === '') {
            return self::page('<div class="kontrolka-Tekst">Katalog do pobrania</div>', $account);
        }

        $opened = $product['sizes'] === []
            ? ['symbol' => $product['symbol'], 'account' => $product['account'], 'catalog' => $product['catalog']]
            : self::sizeAt($product, $product['tile_path']);

        $body = '<div class="kontrolka-NaglowekNazwaProduktu col-12"><h1 class="h2 naglowek">'.$product['name'].'</h1></div>';

        $paths = '';
        foreach ($product['categories'] as $path) {
            $links = array_map(static fn (string $part): string => '<a href="/pl/kategoria">'.$part.'</a>', $path);
            $paths .= '<span class="atrybut-opis">'.implode(' \\ ', $links).'</span>';
        }
        if ($paths !== '') {
            $body .= '<div class="kontrolka-KategorieProduktu col-12"><div class="produkt-atrybuty-lista"><div class="atrybut-produktu">'
                .'<span class="atrybut-produktu"><span class="atrybut-nazwa text-muted">Kategorie:</span>'
                .'<span class="atrybut-cechy">'.$paths.'</span></span></div></div></div>';
        }

        // wyrób sprzedawany progami ilościowymi: strona konta ma tabelę progów zamiast bloków „Twoja cena”
        // i „Cena przed rabatem”, a cenę detaliczną pokazuje tylko gościowi
        $tiers = $product['tiers'] ?? [];
        if ($account && $tiers !== []) {
            $rows = '<tr><td colspan="4" class="text-center">Zakupiłeś już <b>0 szt.</b> tego produktu</td></tr>'
                .'<tr class="text-center"><td rowspan="2" class="progi">Progi cenowe</td>'
                .'<td rowspan="2" class="zamow-jeszcze">Zamów jeszcze</td><td colspan="2">Twoja cena wyniesie</td></tr>';
            foreach ($tiers as $i => [$label, $cents]) {
                $rows .= '<tr class="niespalniony '.($i === 0 ? 'aktualna-cena ' : '').'">'
                    .'<td class="text-center"> '.$label.' </td>'
                    .'<td class="text-center brakujace">'.($i === 0 ? ' Twoja cena ' : ' '.$label.' ').'</td>'
                    .'<td class="text-right">'.self::priceText($cents).' </td>'
                    .'<td class="text-right">'.self::priceText((int) round($cents * 1.23)).'</td></tr>';
            }
            $body .= '<div class="kontrolka-GradacjaProduktu col-12"><table class="gradacje table table-sm"><tbody>'
                .$rows.'</tbody></table></div>';
        }

        if (! $account || $tiers === []) {
            if ($account && $opened['catalog'] !== null) {
                $body .= '<div class="kontrolka-CenaProduktu col-12"><div class="cena ceny-przed-rabatem row karta">'
                    .'<div class="opis-cena-netto col-6">Cena przed rabatem netto</div>'
                    .'<div class="netto col-6">'.self::priceText($opened['catalog']).' <small>/szt.</small></div></div></div>';
            }
            $mainPrice = $account ? $opened['account'] : $opened['catalog'];
            if ($mainPrice !== null) {
                $body .= '<div class="kontrolka-CenaProduktu col-12"><div class="cena '.($account ? 'ceny-twoja' : 'ceny-detaliczna').' row karta">'
                    .'<div class="opis-cena-netto col-6">'.($account ? 'Twoja cena netto' : 'Cena detaliczna netto').'</div>'
                    .'<div class="netto col-6">'.self::priceText($mainPrice).' <small>/szt.</small></div></div></div>';
            }
        }

        $body .= '<div class="kontrolka-StanyProduktuNaKarcie col-6"><div class="naglowek-kontrolki">Stan mag.:</div>'
            .'<div class="produkt-karta-stany">'.self::stockCell($product['stock']).'</div></div>';

        $body .= self::fieldControl('Symbol', $opened['symbol']);
        foreach ($product['fields'] as [$label, $value]) {
            $body .= self::fieldControl($label, $value);
        }

        $rows = '';
        foreach ($product['attributes'] as [$label, $value]) {
            $rows .= '<div class="col-6 atrybut-nazwa text-muted">'.$label.'</div>'
                .'<div class="col-6 atrybut-cechy"><a href="/pl/p?filtry=x">'.$value.'</a></div>';
        }
        if ($rows !== '') {
            $body .= '<div class="kontrolka-WszystkieAtrybutyProduktu col-12"><div class="produkt-wszystkie-atrybuty">'
                .'<div class="collapse show lista-atrybuty"><div class="row">'.$rows.'</div></div></div></div>';
        }

        if ($product['documents'] !== []) {
            $files = '';
            foreach ($product['documents'] as $file) {
                $name = pathinfo($file, PATHINFO_FILENAME);
                $files .= '<div class="'.$name.'"><a href="'.$file.'" download="'.$name.'">'.$name.'</a></div>';
            }
            $body .= '<div class="kontrolka-ZalacznikiDoPropduktu col-12"><div class="naglowek-kontrolki">KARTY DO POBRANIA</div>'
                .'<div class="pliki-do-produktu">'.$files.'</div></div>';
        }

        if ($product['gallery'] !== []) {
            $elements = array_map(
                static fn (string $url): array => [
                    'fullImageUrl' => $url.'?preset=ico570x630p',
                    'thumbnailUrl' => $url.'?preset=ikona225',
                    'fancyboxUrl' => $url,
                    'elementType' => 1,
                ],
                $product['gallery'],
            );
            $gallery = '<div class="row product--gallery--thumbs"><script type="application/json">'
                .json_encode(['elements' => $elements, 'fancyboxGallerySelector' => 'p--g--178']).'</script></div>';
        } else {
            $gallery = '';
        }
        $body .= '<div class="kontrolka-GaleriaZdjecProduktuMiniatury col-12"><div class="product-gallery--main--element">'
            .'<div class="produkt-zdjecie metki-SzczegolyProduku"><a class="product--gallery--main--image">'
            .'<img class="zdjecie-produktu" src="'.$product['main_image'].'?quality=30&amp;preset=ico570x630p"></a></div></div>'
            .$gallery.'</div>';

        if ($product['description'] !== '') {
            $body .= '<div class="kontrolka-PoleProduktu col-12"><div class="naglowek-kontrolki">OPIS</div>'
                .'<div class="pole-opis-wartosc"><span class="wartosc">'.$product['description'].'</span></div></div>';
        }

        if ($product['sizes'] !== []) {
            $variants = '';
            foreach ($product['sizes'] as $size) {
                $cents = $account ? $size['account'] : $size['catalog'];
                $price = $cents === null ? '' : '<div class="cena"><div class="opis-cena-netto">netto</div>'
                    .'<div class="font-weight-bold netto">'.self::priceText($cents).'</div></div>'
                    .($account ? '<span class="text-muted produkt-cena-klienta-vat"><span>VAT</span> <span>23,00 %</span></span>' : '');
                $variants .= '<tr class="rodzina-dziecko odstepy-rowne">'
                    .'<td class="warianty-kol-zdjecia zdjecie"><a href="'.$size['path'].'" class="foto link-szczegoly"><img class="zdjecie-produktu"></a></td>'
                    .'<td class="warianty-kol-nazwa nazwa"><a href="'.$size['path'].'" class="clickable"><div class="rodzina-dziecko-cechy">'
                    .'<div class="wieksze-litery-100"><span class="atrybut-produktu"><span class="atrybut-cechy">'
                    .'<span class="atrybut-opis">'.$size['size'].'</span></span></span></div></div></a>'
                    .'<span class="text-muted warianty-dane-produktu"><a href="'.$size['path'].'" class="clickable">'
                    .'<div class="kod"><span class="small text-muted">Symbol:</span> '.$size['symbol'].'</div>'
                    .'<div class="kod-kreskowy"><span class="small text-muted">EAN:</span> '.$size['ean'].'</div></a></span></td>'
                    .'<td class="text-right '.($account ? 'CenaPoRabacie' : 'CenaProduktu').'">'.$price.'</td>'
                    .'<td class="text-center kolumna-stany-produktow">'.self::stockCell($size['stock']).'</td>'
                    .'<td class="text-right kolumna-dodawanie-do-koszyka"><div class="add-cart" data-produkt-id="1"></div></td>'
                    .'</tr>';
            }
            $body .= '<div class="kontrolka-ListaWybranychProduktow col-12"><div class="lista-produktow-wybrane-produkty">'
                .'<table class="table table-sm lista-produktow lista-wariantow"><thead><tr><th class="NaglowekNazwa">NAZWA</th>'
                .'<th class="NaglowekTwojaCena">TWOJA CENA</th><th>STAN MAG.</th></tr></thead><tbody>'.$variants.'</tbody></table></div></div>';
        }

        return self::page($body, $account);
    }

    /**
     * @param  array<string, mixed>  $product
     * @return array{symbol: string, account: int|null, catalog: int|null}
     */
    private static function sizeAt(array $product, string $path): array
    {
        foreach ($product['sizes'] as $size) {
            if ($size['path'] === $path) {
                return ['symbol' => $size['symbol'], 'account' => $size['account'], 'catalog' => $size['catalog']];
            }
        }

        $first = $product['sizes'][0];

        return ['symbol' => $first['symbol'], 'account' => $first['account'], 'catalog' => $first['catalog']];
    }

    /**
     * @param  array<string, mixed>  $product
     */
    private static function tileCents(array $product): ?int
    {
        return $product['sizes'] === []
            ? $product['account']
            : self::sizeAt($product, $product['tile_path'])['account'];
    }

    /**
     * Stan magazynowy jak w sklepie: pokazywane są tylko magazyny ze sztukami.
     *
     * @param  array{0: int, 1: int}  $stock
     */
    private static function stockCell(array $stock): string
    {
        $html = '';
        if ($stock[0] > 0) {
            $html .= '<div class="wlacz-tooltip stany główny mag" title="Magazyn w Polsce - dostępne 24 h">'
                .'<span>24H </span><img src="/zasoby/obrazki/grafika/truck-fast-solid.svg"><strong><span>'.$stock[0].'</span></strong></div>';
        }
        if ($stock[1] > 0) {
            $html .= '<div class="wlacz-tooltip stany centrala" title="Magazyn producenta - dostępne 14 dni">'
                .'<img src="/zasoby/obrazki/grafika/warehouse-solid.svg"><strong><span>  '.$stock[1].'</span></strong></div>';
        }

        return $html;
    }

    private static function fieldControl(string $label, string $value): string
    {
        return '<div class="kontrolka-PoleProduktu col-12"><div class="row"><div class="col-6"><div>'.$label.'</div></div>'
            .'<div class="col-6"> '.$value.' </div></div></div>';
    }

    private static function priceText(?int $cents): string
    {
        return $cents === null ? '' : number_format($cents / 100, 2, ',', ' ').' PLN';
    }

    private static function page(string $body, bool $account): string
    {
        $settings = json_encode([
            'langSymbol' => 'pl',
            'shouldHidePrices' => false,
            'isLoggedIn' => $account,
            'urlPrefix' => '/pl',
        ]);

        return '<!DOCTYPE HTML><html lang="pl"><head><script id="s-a-p" type="application/json">'.$settings.'</script></head>'
            .'<body>'.$body.'</body></html>';
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
