<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\B2bAccount;
use App\Models\B2bProductLink;
use App\Models\B2bSyncRun;
use App\Models\Product;
use App\Models\ProductDocument;
use App\Models\ProductShopCard;
use App\Models\ProductSourcePrice;
use App\Services\B2b\B2bAccountSyncRunner;
use App\Services\B2b\B2bConnectorRegistry;
use App\Services\B2b\B2bFatalException;
use App\Services\B2b\B2bManufacturerSite;
use App\Services\B2b\B2bRemoteDocument;
use App\Services\B2b\B2bRemoteProduct;
use App\Services\B2b\B2bRemoteShopField;
use App\Services\B2b\TegroB2bClient;
use App\Services\B2b\TegroB2bConnector;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use RuntimeException;
use Tests\TestCase;

/**
 * Łącznik b2b.tegro.pl (SolEx B2B) na atrapie sklepu (Http::fake, bez prawdziwego logowania). Układ odpowiedzi
 * jak w sklepie 21.09.2026: formularz /logowanie, stan sesji w JSON strony głównej („isLoggedIn”), API /api3
 * z HTTP 401 bez sesji, plik XML oferty z adresami stron, tabelka „Parametry produktu” i „Załączniki” na stronie.
 * Wszystkie dane (pozycje, ceny, EAN-y, strony) są SYNTETYCZNE.
 */
final class TegroConnectorTest extends TestCase
{
    use RefreshDatabase;

    private const JPEG = "\xFF\xD8\xFF\xE0\x00\x10JFIF\x00\x01syntetyczny\xFF\xD9";

    private const F09 = 'RĘKAWICE G-REX F09 PLUS';

    private const F09_PAGE = 'https://b2b.tegro.pl/pl/rekawice-g-rex-f09-plus-6';

    /** @var list<array<string, mixed>> */
    private array $items = [];

    /** @var list<string> */
    private array $validSessions = [];

    private int $logins = 0;

    /** Wszystkie sesje wygasają przy pierwszym zapytaniu listy (null = nigdy). */
    private bool $dropSessionsOnList = false;

    /** API odmawia każdej sesji (konto bez dostępu do API). */
    private bool $apiAlwaysUnauthorized = false;

    private bool $offerXmlFails = false;

    private ?int $countOverride = null;

    private int $pageHits = 0;

    protected function setUp(): void
    {
        parent::setUp();

        $this->items = [
            // lista sklepu nie jest ułożona wg rozmiaru
            $this->item(4635, 'F09 PLUS 7', self::F09.' 7', self::F09, 5.1, 6.0, '5900000000007'),
            // jak w sklepie 21.09.2026: najmniejszy rozmiar bez opisu, pozostałe z opisem
            $this->item(4634, 'F09 PLUS 6', self::F09.' 6', self::F09, 5.1, 6.0, '5900000000006', ['Description' => '']),
            $this->item(4638, 'F09 PLUS 10', self::F09.' 10', self::F09, 5.1, 6.0, '5900000000010'),
            // ten sam model, rozmiar w innej cenie — osobne karty z pełną nazwą
            $this->item(2001, 'HEAVY 9', 'RĘKAWICE RS ARBEITSSCHUTZ HEAVY 9', 'RĘKAWICE RS ARBEITSSCHUTZ HEAVY', 5.9, 6.24, '5900000000109'),
            $this->item(2000, 'HEAVY 8', 'RĘKAWICE RS ARBEITSSCHUTZ HEAVY 8', 'RĘKAWICE RS ARBEITSSCHUTZ HEAVY', 6.24, 6.24, '5900000000108'),
            // bez modelu; normy także w atrybutach API
            $this->item(1921, 'ALASKA 10', 'RĘKAWICE RS ARBEITSSCHUTZ ALASKA 10', null, 7.5, 9.0, '5900000000210', [
                'Attributes' => [['Id' => 135, 'Name' => 'Normy', 'Features' => [['Id' => 1, 'Name' => 'EN 388:2016(3122X), EN 511:2006(X1X)']]]],
                'Brand' => 'RS',
            ]),
            // nazwa nie jest „Model rozmiar” — bez zgadywania, osobna karta
            $this->item(3001, 'ODD 8', 'RĘKAWICE INNA NAZWA 8', 'RĘKAWICE ODD', 2.0, 2.5, ''),
            // bez ceny konta
            $this->item(3002, 'NOPRICE 9', 'RĘKAWICE NOPRICE 9', 'RĘKAWICE NOPRICE', 0.0, 3.0, ''),
        ];
    }

    public function test_login_posts_the_shop_form_and_confirms_the_session_on_the_home_page(): void
    {
        $this->fakeSite();
        $client = $this->client();

        $client->login();

        $this->assertTrue($client->isLoggedIn());
        $post = Http::recorded(fn (Request $r): bool => $r->method() === 'POST')->first()[0];
        $this->assertSame('https://b2b.tegro.pl/logowanie', $post->url());
        $this->assertSame(['Uzytkownik' => 'jan@example.com', 'Haslo' => 'dobre-haslo', 'logowanie' => ''], $post->data());
        $home = Http::recorded(fn (Request $r): bool => $r->url() === 'https://b2b.tegro.pl/pl/home')->first()[0];
        $this->assertStringContainsString('.ASPXAUTH=auth1', $home->header('Cookie')[0] ?? '');
    }

    public function test_wrong_password_fails_with_clear_message_although_the_shop_answers_http_200(): void
    {
        $this->fakeSite();
        $client = new TegroB2bClient('jan@example.com', 'zle-haslo', 0, static function (int $ms): void {});

        try {
            $client->login();
            $this->fail('Logowanie powinno się nie udać');
        } catch (RuntimeException $e) {
            $this->assertNotInstanceOf(B2bFatalException::class, $e);
            $this->assertSame('Logowanie do b2b.tegro.pl nieudane: sklep nie potwierdził zalogowania — sprawdź login i hasło', $e->getMessage());
        }
        $this->assertFalse($client->isLoggedIn());
    }

    public function test_sizes_of_one_model_in_one_price_are_one_card_and_a_size_in_another_price_is_its_own_card(): void
    {
        $this->fakeSite();

        $products = $this->productsBySku($this->connector());

        $this->assertSame(['F09 PLUS 6', 'HEAVY 9', 'HEAVY 8', 'ALASKA 10', 'ODD 8', 'NOPRICE 9'], array_keys($products));
        $f09 = $products['F09 PLUS 6'];
        $this->assertSame('4634', $f09->remoteId);
        $this->assertSame(self::F09, $f09->name);
        $this->assertSame('RĘKAWICE DZIANE POWLEKANE PIANĄ', $f09->category);
        $this->assertSame(self::F09_PAGE, $f09->sourceUrl);
        $this->assertSame('Rozmiary: 6 (F09 PLUS 6); 7 (F09 PLUS 7); 10 (F09 PLUS 10)', $f09->variantSummary);
        $this->assertSame(
            [
                ['remote_id' => '4634', 'sku' => 'F09 PLUS 6', 'name' => self::F09.' 6'],
                ['remote_id' => '4635', 'sku' => 'F09 PLUS 7', 'name' => self::F09.' 7'],
                ['remote_id' => '4638', 'sku' => 'F09 PLUS 10', 'name' => self::F09.' 10'],
            ],
            $f09->members,
        );

        // pojedyncza pozycja: pełna nazwa ze sklepu, bez listy rozmiarów i bez members
        $this->assertSame('RĘKAWICE RS ARBEITSSCHUTZ HEAVY 8', $products['HEAVY 8']->name);
        $this->assertSame([], $products['HEAVY 8']->members);
        $this->assertNull($products['HEAVY 8']->variantSummary);
        $this->assertSame('RĘKAWICE INNA NAZWA 8', $products['ODD 8']->name);
        $this->assertSame([], $products['ODD 8']->members);
    }

    public function test_price_is_the_account_price_with_catalog_price_and_no_invented_discount(): void
    {
        $this->fakeSite();
        $connector = $this->connector();
        $products = $this->productsBySku($connector);

        $price = $connector->price($products['F09 PLUS 6']);
        $this->assertNotNull($price);
        $this->assertSame(5.1, $price->net);
        $this->assertSame(6.0, $price->base);
        $this->assertSame(0.0, $price->discountPercent);
        $this->assertSame('PLN', $price->currency);

        $this->assertNull($connector->price($products['NOPRICE 9']));
        $this->assertSame('Rękawica ochronna kat. II, nitryl.'."\n".'Druga linia opisu.', $connector->description($products['F09 PLUS 6']));
        $this->assertSame('G-REX', $connector->manufacturer($products['F09 PLUS 6']));
        $this->assertSame('Rękawica ochronna kat. II, nitryl.'."\n".'Druga linia opisu.', $connector->description($products['F09 PLUS 6']));
    }

    public function test_shop_card_has_page_parameters_norms_and_trade_data_of_every_size(): void
    {
        $this->fakeSite();
        $connector = $this->connector();
        $products = $this->productsBySku($connector);

        $rows = array_map(
            static fn (B2bRemoteShopField $f): string => $f->section.' | '.$f->name.' | '.$f->value,
            $connector->shopFields($products['F09 PLUS 6']),
        );

        $this->assertSame([
            'Parametry produktu | Nazwa produktu | RĘKAWICE G-REX F09 PLUS',
            'Parametry produktu | Pakowanie (wiązka/karton) | 12/144',
            'Parametry produktu | Rozmiar | 6-11',
            'Parametry produktu | KodCN | 61161020',
            'Parametry produktu | Normy | EN ISO 21420:2020;EN 388:2016+A1:2018;EN 407:2020',
            'Informacje handlowe | Marka | G-REX',
            'Informacje handlowe | Kategoria | RĘKAWICE DZIANE POWLEKANE PIANĄ',
            'Informacje handlowe | Jednostka | para',
            'Informacje handlowe | Kod towaru | F09 PLUS 6',
            'Informacje handlowe | EAN | 5900000000006 (F09 PLUS 6)',
            'Informacje handlowe | Kod towaru | F09 PLUS 7',
            'Informacje handlowe | EAN | 5900000000007 (F09 PLUS 7)',
            'Informacje handlowe | Kod towaru | F09 PLUS 10',
            'Informacje handlowe | EAN | 5900000000010 (F09 PLUS 10)',
        ], $rows);
        // tabelka i pliki czytają tę samą stronę
        $connector->documents($products['F09 PLUS 6']);
        $this->assertSame(1, $this->pageHits);
    }

    public function test_norms_from_the_api_are_kept_once_when_the_page_repeats_them(): void
    {
        $this->fakeSite();
        $connector = $this->connector();
        $products = $this->productsBySku($connector);

        $norms = array_values(array_filter(
            $connector->shopFields($products['ALASKA 10']),
            static fn (B2bRemoteShopField $f): bool => $f->name === 'Normy',
        ));

        $this->assertCount(1, $norms);
        $this->assertSame('EN 388:2016(3122X), EN 511:2006(X1X)', $norms[0]->value);
    }

    public function test_documents_put_the_polish_datasheet_first_and_ignore_files_outside_the_shop(): void
    {
        $this->fakeSite();
        $connector = $this->connector();
        $products = $this->productsBySku($connector);

        $documents = $connector->documents($products['F09 PLUS 6']);

        $this->assertSame([
            ['g-rex-f09-plus-karta-katalogowa-pl.pdf', 'https://b2b.tegro.pl/zasoby/import/g/g-rex-f09-plus-karta-katalogowa-pl.pdf', ProductDocument::KIND_DATASHEET],
            ['f09-plus-instrukcja.pdf', 'https://b2b.tegro.pl/zasoby/import/f/f09-plus-instrukcja.pdf', ProductDocument::KIND_MANUAL],
            ['g-rex-f09-plus-product-card.pdf', 'https://b2b.tegro.pl/zasoby/import/g/g-rex-f09-plus-product-card.pdf', ProductDocument::KIND_DATASHEET],
            ['g-rex-f09-plus-deklaracja.pdf', 'https://b2b.tegro.pl/zasoby/import/g/g-rex-f09-plus-deklaracja.pdf', ProductDocument::KIND_CERTIFICATE],
            ['v03.pdf', 'https://b2b.tegro.pl/zasoby/import/v/v03.pdf', ProductDocument::KIND_OTHER],
        ], array_map(static fn (B2bRemoteDocument $d): array => [$d->title, $d->sourceUrl, $d->kind], $documents));

        $this->expectException(RuntimeException::class);
        $connector->documentBytes(new B2bRemoteDocument('obcy.pdf', 'https://example.test/obcy.pdf'));
    }

    public function test_image_is_the_full_size_photo_without_the_thumbnail_preset(): void
    {
        $this->fakeSite();
        $connector = $this->connector();
        $products = $this->productsBySku($connector);

        $image = $connector->image($products['F09 PLUS 6']);

        $this->assertNotNull($image);
        $this->assertSame('https://b2b.tegro.pl/zasoby/import/f/f09-plus.jpg', $image->sourceUrl);
        $this->assertSame('image/jpeg', $image->mime);
        $this->assertSame(self::JPEG, $image->bytes);
    }

    public function test_expired_session_logs_in_again_once(): void
    {
        $this->dropSessionsOnList = true;
        $this->fakeSite();

        $products = $this->productsBySku($this->connector());

        $this->assertCount(6, $products);
        $this->assertSame(2, $this->logins);
    }

    public function test_api_refusing_a_fresh_session_is_fatal(): void
    {
        $this->apiAlwaysUnauthorized = true;
        $this->fakeSite();

        $this->expectException(B2bFatalException::class);
        $this->expectExceptionMessage('Utracono sesję konta b2b.tegro.pl');
        iterator_to_array($this->connector()->products(), false);
    }

    public function test_incomplete_list_is_an_error_not_a_partial_price_list(): void
    {
        $this->countOverride = 500;
        $this->fakeSite();

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('API b2b.tegro.pl zwróciło niepełną listę produktów (8 z 500)');
        iterator_to_array($this->connector()->products(), false);
    }

    public function test_without_the_offer_file_prices_still_come_and_the_run_summary_says_what_is_missing(): void
    {
        $this->offerXmlFails = true;
        $this->fakeSite();
        $connector = $this->connector();

        $products = $this->productsBySku($connector);

        $this->assertNull($products['F09 PLUS 6']->sourceUrl);
        $this->assertSame([], $connector->documents($products['F09 PLUS 6']));
        $this->assertSame(0, $this->pageHits);
        $this->assertSame([
            'Lista Tegro: 8 pozycji → 6 kart (1 grup rozmiarów o tej samej cenie)',
            'Plik oferty XML nie został pobrany (b2b.tegro.pl odpowiedziało HTTP 500) — karty bez tabelki parametrów i plików PDF',
        ], $connector->runSummary());
    }

    public function test_sync_through_runner_creates_cards_links_shop_cards_and_files(): void
    {
        Storage::fake('public');
        $this->fakeSite();

        $result = app(B2bAccountSyncRunner::class)->run($this->account(), delayMs: 0);

        $this->assertSame(6, $result['total_remote']);
        $this->assertSame(5, $result['created']);
        $this->assertContains('NOPRICE 9: brak ceny w B2B', $result['errors']);

        $f09 = Product::query()->where('sku', 'F09 PLUS 6')->sole();
        $this->assertSame(self::F09, $f09->name);
        $this->assertSame('G-REX', $f09->manufacturer);
        $this->assertSame('Rozmiary: 6 (F09 PLUS 6); 7 (F09 PLUS 7); 10 (F09 PLUS 10)', $f09->variant_summary);
        $this->assertFalse(Product::query()->whereIn('sku', ['F09 PLUS 7', 'F09 PLUS 10'])->exists());
        $this->assertSame(
            ['4634' => self::F09.' 6', '4635' => self::F09.' 7', '4638' => self::F09.' 10'],
            B2bProductLink::query()->where('product_id', $f09->id)->orderBy('remote_id')->pluck('remote_name', 'remote_id')->all(),
        );
        $slot = ProductSourcePrice::query()->where('product_id', $f09->id)->where('source_key', ProductSourcePrice::b2bKey((int) $this->account()->id))->sole();
        $this->assertSame('5.10', (string) $slot->purchase_price);

        $this->assertSame('RĘKAWICE RS ARBEITSSCHUTZ HEAVY 8', Product::query()->where('sku', 'HEAVY 8')->value('name'));
        $this->assertSame('RĘKAWICE RS ARBEITSSCHUTZ HEAVY 9', Product::query()->where('sku', 'HEAVY 9')->value('name'));

        $this->assertTrue(ProductShopCard::query()->where('product_id', $f09->id)->exists());
        $this->assertStringContainsString('EN 388:2016+A1:2018', (string) $f09->fresh()?->shop_fields_summary);
        $this->assertSame(
            ['g-rex-f09-plus-karta-katalogowa-pl.pdf', 'f09-plus-instrukcja.pdf', 'g-rex-f09-plus-product-card.pdf', 'g-rex-f09-plus-deklaracja.pdf', 'v03.pdf'],
            ProductDocument::query()->where('product_id', $f09->id)->orderBy('sort_order')->pluck('title')->all(),
        );

        $log = array_column((array) B2bSyncRun::query()->latest('id')->firstOrFail()->log, 'text');
        $this->assertContains('Lista Tegro: 8 pozycji → 6 kart (1 grup rozmiarów o tej samej cenie)', $log);
    }

    public function test_second_run_on_the_same_cards_creates_nothing_and_keeps_description_and_files(): void
    {
        Storage::fake('public');
        $this->fakeSite();
        app(B2bAccountSyncRunner::class)->run($this->account(), delayMs: 0);
        $f09 = Product::query()->where('sku', 'F09 PLUS 6')->sole();
        $description = (string) $f09->description;
        $documents = ProductDocument::query()->where('product_id', $f09->id)->count();
        $pdfDownloads = count(Http::recorded(fn (Request $r): bool => str_ends_with((string) parse_url($r->url(), PHP_URL_PATH), '.pdf')));

        $second = app(B2bAccountSyncRunner::class)->run($this->account(), delayMs: 0);

        $this->assertSame(0, $second['created']);
        $this->assertSame(5, Product::query()->count());
        $this->assertSame('Rękawica ochronna kat. II, nitryl.'."\n".'Druga linia opisu.', $description);
        $this->assertSame($description, (string) $f09->fresh()?->description);
        $this->assertSame($documents, ProductDocument::query()->where('product_id', $f09->id)->count());
        $this->assertSame(3, B2bProductLink::query()->where('product_id', $f09->id)->count());
        // pliki, które karta już ma, nie są pobierane drugi raz
        $this->assertSame($pdfDownloads, count(Http::recorded(fn (Request $r): bool => str_ends_with((string) parse_url($r->url(), PHP_URL_PATH), '.pdf'))));
    }

    public function test_registry_detects_tegro_by_host_and_it_is_not_a_manufacturer_site(): void
    {
        $registry = app(B2bConnectorRegistry::class);

        $this->assertSame('tegro', $registry->keyForSites(['https://b2b.tegro.pl/logowanie']));
        $this->assertSame('Tegro', $registry->label('tegro'));
        $this->assertTrue($registry->requiresPassword('tegro'));
        $account = B2bAccount::query()->create(['username' => 'jan@example.com', 'password' => 'sekret', 'sites' => ['https://b2b.tegro.pl/']]);
        $connector = $registry->make($account, 0);
        $this->assertInstanceOf(TegroB2bConnector::class, $connector);
        $this->assertNotInstanceOf(B2bManufacturerSite::class, $connector);
        $this->assertSame('Tegro', $connector->manufacturer(new B2bRemoteProduct('1', 'X', 'X')));
    }

    private function client(): TegroB2bClient
    {
        return new TegroB2bClient('jan@example.com', 'dobre-haslo', 0, static function (int $ms): void {});
    }

    private function connector(): TegroB2bConnector
    {
        $connector = new TegroB2bConnector($this->client());
        $connector->login();

        return $connector;
    }

    private function account(): B2bAccount
    {
        return B2bAccount::query()->firstOrCreate(
            ['username' => 'jan@example.com'],
            ['password' => 'dobre-haslo', 'sites' => ['https://b2b.tegro.pl/'], 'connector' => 'tegro', 'sync_images' => false],
        )->fresh();
    }

    /**
     * @return array<string, B2bRemoteProduct>
     */
    private function productsBySku(TegroB2bConnector $connector): array
    {
        $out = [];
        foreach ($connector->products() as $product) {
            $out[$product->sku] = $product;
        }

        return $out;
    }

    /**
     * Pozycja listy API (ApiProduct) w kształcie ze sklepu.
     *
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    private function item(int $id, string $sku, string $name, ?string $model, float $net, float $retail, string $ean, array $overrides = []): array
    {
        return [
            'Id' => $id,
            'Name' => $name,
            'Ean' => $ean,
            'Sku' => $sku,
            'Description' => "Rękawica ochronna kat. II, nitryl.\r\n  Druga linia   opisu. ",
            'Model' => $model,
            'Brand' => 'G-REX',
            'Unit' => 'para',
            'Vat' => 23,
            'InStock' => true,
            'RetailPriceNet' => ['Value' => $retail, 'Currency' => 'PLN'],
            'PriceAfterDiscountNet' => ['Value' => $net, 'Currency' => 'PLN'],
            'Attributes' => [],
            'Categories' => [['Id' => '8562462340266848137', 'Name' => 'RĘKAWICE DZIANE POWLEKANE PIANĄ', 'ParentId' => null, 'GroupId' => 1]],
            'Photo' => '/zasoby/import/f/f09-plus.jpg?preset=ico80x80wp&quality=30',
            ...$overrides,
        ];
    }

    private function offerXml(): string
    {
        $products = '';
        foreach ($this->items as $item) {
            $slug = 'rekawice-'.strtolower(str_replace(' ', '-', (string) $item['Sku']));
            if ($item['Id'] === 4634) {
                $slug = 'rekawice-g-rex-f09-plus-6';
            }
            $products .= '<product addedDate="19.10.2023 12:34:25"><ean><![CDATA['.$item['Ean'].']]></ean><id>'.$item['Id'].'</id>'
                .'<sku><![CDATA['.$item['Sku'].']]></sku><url><![CDATA[https://b2b.tegro.pl/pl/'.$slug.']]></url></product>';
        }

        return "<?xml version='1.0' encoding='utf-8' standalone='yes' ?>\r\n<products elments=\"".count($this->items).'" lang="pl" version="3">'.$products.'</products>';
    }

    private static function productPage(): string
    {
        return <<<'HTML'
            <!DOCTYPE html><html lang="pl"><head><meta charset="utf-8"><title>RĘKAWICE G-REX F09 PLUS - Platforma SolexB2B</title></head><body>
            <div id="kontrolka-167" class="kontrolka-PoleProduktu col-12"><div class="pole-opis-wartosc"><span class="wartosc"><a href="/pl/g-rex"><img src="/zasoby/obrazki/marki/G-rex.png?preset=producent" alt="G-REX/"></a></span></div></div>
            <div id="kontrolka-174" class="kontrolka-PoleProduktu col-12"><div class="naglowek-kontrolki"><div><h4 class="naglowek">Parametry produktu</h4></div></div>
              <div class="row"><div class="col-6"> Nazwa produktu </div><div class="col-6"> RĘKAWICE G-REX F09 PLUS </div></div></div>
            <div id="kontrolka-153" class="kontrolka-PoleProduktu col-12">
              <div class="row"><div class="col-6"> Pakowanie (wiązka/karton) </div><div class="col-6"><a>12/144</a></div></div>
              <div class="row"><div class="col-6"> Rozmiar </div><div class="col-6"><a>6-11</a></div></div></div>
            <div id="kontrolka-232" class="kontrolka-PoleProduktu col-12"><div class="row"><div class="col-6"> KodCN </div><div class="col-6"><a>61161020</a></div></div></div>
            <div id="kontrolka-223" class="kontrolka-PoleProduktu col-12 mt-2 mb-3"><div class="pole-opis-wartosc"><span class="wartosc"> Normy:EN ISO 21420:2020;EN 388:2016+A1:2018;EN 407:2020 </span></div></div>
            <div id="kontrolka-112" class="kontrolka-PoleProduktu col-12"><div class="naglowek-kontrolki"><h4 class="naglowek">Opis</h4></div>
              <div class="pole-opis-wartosc"><span class="wartosc"> Uwaga: opis nie jest wierszem tabelki. </span></div></div>
            <div id="kontrolka-155" class="kontrolka-ZalacznikiDoPropduktu col-12"><h4 class="naglowek">Załączniki</h4><div class="pliki-do-produktu">
              <div><a href="/zasoby/import/f/f09-plus-instrukcja.pdf" download><img src="/zasoby/obrazki/ikony_plikow/pdf.png"/> f09-plus-instrukcja.pdf </a></div>
              <div><a href="/zasoby/import/g/g-rex-f09-plus-deklaracja.pdf" download> g-rex-f09-plus-deklaracja.pdf </a></div>
              <div><a href="/zasoby/import/g/g-rex-f09-plus-karta-katalogowa-pl.pdf" download> g-rex-f09-plus-karta-katalogowa-pl.pdf </a></div>
              <div><a href="/zasoby/import/g/g-rex-f09-plus-product-card.pdf" download> g-rex-f09-plus-product-card.pdf </a></div>
              <div><a href="/zasoby/import/v/v03.pdf" download> v03.pdf </a></div>
              <div><a href="https://example.test/obcy.pdf" download> obcy.pdf </a></div>
            </div></div>
            </body></html>
            HTML;
    }

    private static function homePage(bool $loggedIn): string
    {
        return '<html><body><script id="s-a-p" type="application/json">{"recaptchaSiteKey":null,"langSymbol":"pl","isLoggedIn":'
            .($loggedIn ? 'true' : 'false').',"urlToLoginPage":"https://b2b.tegro.pl/pl/logowanie"}</script></body></html>';
    }

    private function fakeSite(): void
    {
        Http::fake(function (Request $request) {
            $url = $request->url();
            $path = (string) parse_url($url, PHP_URL_PATH);
            preg_match('/\.ASPXAUTH=(auth\d+)/', $request->header('Cookie')[0] ?? '', $m);
            $loggedIn = isset($m[1]) && in_array($m[1], $this->validSessions, true);

            if ($path === '/pl/logowanie') {
                return Http::response('<form method="POST" action="https://b2b.tegro.pl/logowanie"></form>', 200, [
                    'Set-Cookie' => 'ASP.NET_SessionId=anon; path=/; HttpOnly',
                ]);
            }
            if ($path === '/logowanie' && $request->method() === 'POST') {
                // jak sklep: złe dane = ta sama strona logowania z HTTP 200, bez ciasteczka konta
                if (($request->data()['Uzytkownik'] ?? null) !== 'jan@example.com' || ($request->data()['Haslo'] ?? null) !== 'dobre-haslo') {
                    return Http::response('<form>Nieprawidłowy login lub hasło</form>', 200);
                }
                $this->logins++;
                $this->validSessions[] = 'auth'.$this->logins;

                return Http::response(self::homePage(true), 200, ['Set-Cookie' => '.ASPXAUTH=auth'.$this->logins.'; path=/; HttpOnly']);
            }
            if ($path === '/pl/home') {
                return Http::response(self::homePage($loggedIn));
            }
            if ($path === '/api3/product/findProduct') {
                if ($this->dropSessionsOnList && $this->logins === 1) {
                    $this->validSessions = [];
                    $loggedIn = false;
                }
                if (! $loggedIn || $this->apiAlwaysUnauthorized) {
                    return Http::response('"Użytkownik niezalogowany nie ma dostępu do wywołania akcji"', 401, ['Content-Type' => 'application/json']);
                }
                parse_str((string) parse_url($url, PHP_URL_QUERY), $query);
                if (($query['field'] ?? '') !== TegroB2bClient::PRODUCT_FIELDS) {
                    return Http::response(['Message' => 'nieoczekiwane pola'], 400);
                }

                return Http::response([
                    'Count' => $this->countOverride ?? count($this->items),
                    'PageSize' => 2147483647,
                    'PageNumber' => 1,
                    'HasMore' => false,
                    'CollectionType' => 'Product',
                    'Items' => $this->items,
                ]);
            }
            if ($path === '/pl/xmlapi/1/3/UTF8') {
                if ($this->offerXmlFails) {
                    return Http::response('błąd', 500);
                }

                return $loggedIn
                    ? Http::response($this->offerXml(), 200, ['Content-Type' => 'text/xml'])
                    : Http::response('"Użytkownik niezalogowany"', 401);
            }
            if (str_starts_with($path, '/pl/rekawice-')) {
                $this->pageHits++;
                $page = self::productPage();
                if ($path === '/pl/rekawice-alaska-10') {
                    // jak w sklepie: wiersz „Normy” strony ma to samo brzmienie co atrybut API
                    $page = str_replace('EN ISO 21420:2020;EN 388:2016+A1:2018;EN 407:2020', 'EN 388:2016(3122X), EN 511:2006(X1X)', $page);
                }

                return Http::response($page, 200, ['Content-Type' => 'text/html; charset=utf-8']);
            }
            if (str_starts_with($path, '/zasoby/import/') && str_ends_with($path, '.jpg')) {
                return Http::response(self::JPEG, 200, ['Content-Type' => 'image/jpeg']);
            }
            if (str_starts_with($path, '/zasoby/import/') && str_ends_with($path, '.pdf')) {
                // każdy plik z inną treścią — zapis plików rozpoznaje powtórzony plik po sumie kontrolnej
                return Http::response("%PDF-1.4\n".$path."\n%%EOF", 200, ['Content-Type' => 'application/pdf']);
            }

            return Http::response('nieznany adres w teście: '.$url, 404);
        });
    }
}
