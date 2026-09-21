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
use App\Services\B2b\PolstarB2bClient;
use App\Services\B2b\PolstarB2bConnector;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use RuntimeException;
use Tests\TestCase;

/**
 * Łącznik polstar.com.pl na atrapie sklepu (Http::fake, bez prawdziwego logowania). Układ odpowiedzi jak w sklepie
 * 21.09.2026: formularz /login z tokenem CSRF, odnośnik „/wyloguj” na stronach w sesji, plik XML produktów konta,
 * kafelki z id i ceną konta na stronach kategorii, kafelki bez id w wyszukiwarce, pliki PDF i kategoria ochrony
 * na stronie produktu. Wszystkie dane (produkty, ceny, EAN-y, pliki) są SYNTETYCZNE.
 */
final class PolstarConnectorTest extends TestCase
{
    use RefreshDatabase;

    private const JPEG = "\xFF\xD8\xFF\xE0\x00\x10JFIF\x00\x01syntetyczny\xFF\xD9";

    private const TOKEN = 'token-formularza-1';

    /** @var list<string> */
    private array $validSessions = [];

    private int $logins = 0;

    /** Wszystkie sesje wygasają przy pierwszym pobraniu pliku XML. */
    private bool $dropSessionsOnXml = false;

    /** Sklep nie uznaje żadnej sesji przy pliku XML (np. konto bez zakładki „API”). */
    private bool $xmlAlwaysLoggedOut = false;

    /** Strony kategorii bez kafelków (np. zmieniony wygląd sklepu). */
    private bool $emptyCategories = false;

    private int $pageHits = 0;

    /** @var array<string, int> */
    private array $pageHitsBySlug = [];

    public function test_login_posts_the_form_with_the_csrf_token_and_confirms_the_session(): void
    {
        $this->fakeSite();
        $client = $this->client();

        $client->login();

        $this->assertTrue($client->isLoggedIn());
        $post = Http::recorded(fn (Request $r): bool => $r->method() === 'POST')->first()[0];
        $this->assertSame('https://polstar.com.pl/login', $post->url());
        $this->assertSame(
            ['_method' => 'POST', '_csrfToken' => self::TOKEN, 'username' => 'PHT TEST', 'password' => 'dobre-haslo'],
            $post->data(),
        );
        $this->assertStringContainsString('csrfToken=csrf1', $post->header('Cookie')[0] ?? '');
    }

    public function test_wrong_password_fails_with_clear_message_although_the_shop_answers_http_200(): void
    {
        $this->fakeSite();
        $client = new PolstarB2bClient('PHT TEST', 'zle-haslo', 0, static function (int $ms): void {});

        try {
            $client->login();
            $this->fail('Logowanie powinno się nie udać');
        } catch (RuntimeException $e) {
            $this->assertNotInstanceOf(B2bFatalException::class, $e);
            $this->assertSame('Logowanie do polstar.com.pl nieudane: sklep nie potwierdził zalogowania — sprawdź login i hasło', $e->getMessage());
        }
        $this->assertFalse($client->isLoggedIn());
    }

    public function test_every_product_of_the_xml_is_one_card_with_a_unique_sku_and_the_shop_name(): void
    {
        $this->fakeSite();
        $connector = $this->connector();

        $products = $this->productsBySku($connector);

        // kod ABOG mają dwa różne produkty w różnych cenach — SKU karty to kod i id
        $this->assertSame(['RCCS-64', 'ABOG-9', 'ABOG-667', 'MP01-2628', 'SOG1-2583', 'RUFL-2551', 'ZZZZ-777'], array_keys($products));
        $covent = $products['RCCS-64'];
        $this->assertSame('64', $covent->remoteId);
        $this->assertSame('COVENT S kat. II', $covent->name);
        $this->assertSame('POWLEKANE', $covent->category);
        $this->assertSame('https://polstar.com.pl/produkt/covent_s', $covent->sourceUrl);
        $this->assertSame('Rozmiary: 8; 9', $covent->variantSummary);
        $this->assertSame([], $covent->members);

        $this->assertSame('BRIXTON CLASSIC SPODNIE OGRODNICZKI', $products['ABOG-9']->name);
        $this->assertSame('Kolory: Zielony (_z); Niebieski (_n) | Rozmiary: 44; 46; 2XL.', $products['ABOG-9']->variantSummary);
        // nazwa, która już zawiera kolekcję, nie dostaje jej drugi raz
        $this->assertSame('CHAPLIN OCHRONNIKI SŁUCHU GUARD 1 SNR-27', $products['SOG1-2583']->name);
        // wyrób bez rozmiaru („_______a”) nie ma listy rozmiarów
        $this->assertNull($products['SOG1-2583']->variantSummary);

        $this->assertSame([
            'Lista Polstar (plik XML konta): 7 produktów',
            'Produkty spoza kategorii menu: 2, wyszukiwarka sklepu: 2',
            'Produkty bez ceny konta na stronach sklepu: 1 — pominięte',
        ], $connector->runSummary());
    }

    public function test_price_is_the_account_price_from_the_tile_with_the_retail_price_and_no_invented_discount(): void
    {
        $this->fakeSite();
        $connector = $this->connector();
        $products = $this->productsBySku($connector);

        $price = $connector->price($products['RCCS-64']);
        $this->assertNotNull($price);
        $this->assertSame(1.59, $price->net);
        $this->assertSame(2.3, $price->base);
        $this->assertSame(0.0, $price->discountPercent);
        $this->assertSame('PLN', $price->currency);

        // kafelek „DETAL”: konto płaci cenę detaliczną (strona produktu podaje ją jako „Cena po rabacie”)
        $mynts = $connector->price($products['MP01-2628']);
        $this->assertNotNull($mynts);
        $this->assertSame(1152.84, $mynts->net);
        $this->assertSame(1152.84, $mynts->base);

        $this->assertSame(51.66, $connector->price($products['ABOG-667'])?->net);
        $this->assertNull($connector->price($products['ZZZZ-777']));
    }

    public function test_product_outside_the_menu_is_found_by_search_and_confirmed_by_the_id_on_its_page(): void
    {
        $this->fakeSite();
        $connector = $this->connector();

        $products = $this->productsBySku($connector);

        $guard = $products['SOG1-2583'];
        $this->assertSame('https://polstar.com.pl/produkt/chaplin-ochronniki-sluchu-guard-1-snr-27', $guard->sourceUrl);
        $this->assertSame(13.03, $connector->price($guard)?->net);
        // kafelek innego produktu z tym samym kodem odrzucony po id ze strony, a kafelek z innym kodem nieotwierany
        $this->assertSame(1, $this->pageHitsBySlug['chaplin-guard-1-stara-wersja'] ?? 0);
        $this->assertArrayNotHasKey('chaplin-guard-2-snr-31', $this->pageHitsBySlug);
    }

    public function test_description_is_the_detailed_description_of_the_xml_line_by_line(): void
    {
        $this->fakeSite();
        $connector = $this->connector();
        $products = $this->productsBySku($connector);

        $this->assertSame(
            "Rodzaj rękawicy: rękawica powlekana,\n"
            ."Część dłoniowa: powleczona szorstkowanym lateksem, w kolorze szarym,\n"
            .'EN 388 - Odporność na uszkodzenia mechaniczne: 2: odporność na ścieranie, 1: odporność na przecięcie,'."\n"
            .'Oddychalność: 5000 g/m2/24h',
            $connector->description($products['RCCS-64']),
        );
        $this->assertSame('', $connector->description($products['MP01-2628']));
        $this->assertSame('Polstar', $connector->manufacturer($products['RCCS-64']));
        $this->assertSame('SUMIRUBBER MALAYSIA SDN BHD', $connector->manufacturer($products['RUFL-2551']));
    }

    public function test_shop_card_has_xml_data_and_the_protection_category_from_the_page(): void
    {
        $this->fakeSite();
        $connector = $this->connector();
        $products = $this->productsBySku($connector);

        $rows = array_map(
            static fn (B2bRemoteShopField $f): string => $f->section.' | '.$f->name.' | '.$f->value,
            $connector->shopFields($products['RCCS-64']),
        );

        $this->assertSame([
            'Parametry produktu | Kod produktu | RCCS',
            'Parametry produktu | Kolekcja | COVENT',
            'Parametry produktu | Powleczenie | szorstkowany lateks',
            'Parametry produktu | Gramatura | 290 g/m2',
            'Parametry produktu | Norma | EN 420:2003 + A1:2009',
            'Parametry produktu | Norma | EN388 : 2016',
            'Parametry produktu | Kategoria ochrony | II',
            'Parametry produktu | Ilość w kartonie | 120',
            'Parametry produktu | Producent | Polstar Holding Wołoszczuk sp.k.',
            'Informacje handlowe | Kategoria | RĘKAWICE->POWLEKANE',
            'Informacje handlowe | Rozmiary | 8; 9',
        ], $rows);
        // tabelka i pliki czytają tę samą stronę
        $connector->documents($products['RCCS-64']);
        $this->assertSame(1, $this->pageHitsBySlug['covent_s']);
    }

    public function test_documents_skip_packaging_declarations_and_put_the_manual_before_the_declaration(): void
    {
        $this->fakeSite();
        $connector = $this->connector();
        $products = $this->productsBySku($connector);

        $documents = $connector->documents($products['RCCS-64']);

        $this->assertSame([
            ['COVENT S instrukcja 2023.pdf', 'https://polstar.com.pl/media/medias/download/2473', ProductDocument::KIND_MANUAL],
            ['Covent S deklaracja 2023.pdf', 'https://polstar.com.pl/media/medias/download/2475', ProductDocument::KIND_CERTIFICATE],
            ['Covent S.pdf', 'https://polstar.com.pl/media/medias/download/3889', ProductDocument::KIND_OTHER],
        ], array_map(static fn (B2bRemoteDocument $d): array => [$d->title, $d->sourceUrl, $d->kind], $documents));

        $this->expectException(RuntimeException::class);
        $connector->documentBytes(new B2bRemoteDocument('obcy.pdf', 'https://example.test/obcy.pdf'));
    }

    public function test_image_is_the_first_photo_of_the_xml(): void
    {
        $this->fakeSite();
        $connector = $this->connector();
        $products = $this->productsBySku($connector);

        $image = $connector->image($products['RCCS-64']);

        $this->assertNotNull($image);
        $this->assertSame('https://polstar.com.pl/product_media/25463/rekawice_covent_s_(2).jpg', $image->sourceUrl);
        $this->assertSame('image/jpeg', $image->mime);
        $this->assertSame(self::JPEG, $image->bytes);
    }

    public function test_expired_session_logs_in_again_once(): void
    {
        $this->dropSessionsOnXml = true;
        $this->fakeSite();

        $products = $this->productsBySku($this->connector());

        $this->assertCount(7, $products);
        $this->assertSame(2, $this->logins);
    }

    public function test_xml_refused_to_a_fresh_session_is_fatal(): void
    {
        $this->xmlAlwaysLoggedOut = true;
        $this->fakeSite();

        $this->expectException(B2bFatalException::class);
        $this->expectExceptionMessage('Utracono sesję konta polstar.com.pl');
        iterator_to_array($this->connector()->products(), false);
    }

    public function test_categories_without_any_tile_are_an_error_not_a_list_without_prices(): void
    {
        $this->emptyCategories = true;
        $this->fakeSite();

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Strony kategorii polstar.com.pl nie podały żadnego produktu z ceną konta');
        iterator_to_array($this->connector()->products(), false);
    }

    public function test_sync_through_runner_creates_cards_prices_shop_cards_and_files(): void
    {
        Storage::fake('public');
        $this->fakeSite();

        $result = app(B2bAccountSyncRunner::class)->run($this->account(), delayMs: 0);

        $this->assertSame(7, $result['total_remote']);
        $this->assertSame(6, $result['created']);
        $this->assertContains('ZZZZ-777: brak ceny w B2B', $result['errors']);

        $covent = Product::query()->where('sku', 'RCCS-64')->sole();
        $this->assertSame('COVENT S kat. II', $covent->name);
        $this->assertSame('Polstar', $covent->manufacturer);
        $this->assertSame('Rozmiary: 8; 9', $covent->variant_summary);
        $this->assertStringStartsWith('Rodzaj rękawicy: rękawica powlekana,', (string) $covent->description);
        $this->assertSame(['64'], B2bProductLink::query()->where('product_id', $covent->id)->pluck('remote_id')->all());
        $slot = ProductSourcePrice::query()->where('product_id', $covent->id)->where('source_key', ProductSourcePrice::b2bKey((int) $this->account()->id))->sole();
        $this->assertSame('1.59', (string) $slot->purchase_price);

        $this->assertSame('SUMIRUBBER MALAYSIA SDN BHD', Product::query()->where('sku', 'RUFL-2551')->value('manufacturer'));
        $this->assertSame(2, Product::query()->whereIn('sku', ['ABOG-9', 'ABOG-667'])->count());

        $this->assertTrue(ProductShopCard::query()->where('product_id', $covent->id)->exists());
        $this->assertStringContainsString('EN388 : 2016', (string) $covent->fresh()?->shop_fields_summary);
        $this->assertSame(
            ['COVENT S instrukcja 2023.pdf', 'Covent S deklaracja 2023.pdf', 'Covent S.pdf'],
            ProductDocument::query()->where('product_id', $covent->id)->orderBy('sort_order')->pluck('title')->all(),
        );

        $log = array_column((array) B2bSyncRun::query()->latest('id')->firstOrFail()->log, 'text');
        $this->assertContains('Lista Polstar (plik XML konta): 7 produktów', $log);
    }

    public function test_second_run_on_the_same_cards_creates_nothing_and_keeps_description_and_files(): void
    {
        Storage::fake('public');
        $this->fakeSite();
        app(B2bAccountSyncRunner::class)->run($this->account(), delayMs: 0);
        $covent = Product::query()->where('sku', 'RCCS-64')->sole();
        $description = (string) $covent->description;
        $documents = ProductDocument::query()->where('product_id', $covent->id)->count();
        $pdfDownloads = count(Http::recorded(fn (Request $r): bool => str_starts_with((string) parse_url($r->url(), PHP_URL_PATH), '/media/medias/download/')));

        $second = app(B2bAccountSyncRunner::class)->run($this->account(), delayMs: 0);

        $this->assertSame(0, $second['created']);
        $this->assertSame(6, Product::query()->count());
        $this->assertNotSame('', $description);
        $this->assertSame($description, (string) $covent->fresh()?->description);
        $this->assertSame($documents, ProductDocument::query()->where('product_id', $covent->id)->count());
        // pliki, które karta już ma, nie są pobierane drugi raz
        $this->assertSame($pdfDownloads, count(Http::recorded(fn (Request $r): bool => str_starts_with((string) parse_url($r->url(), PHP_URL_PATH), '/media/medias/download/'))));
    }

    public function test_registry_detects_polstar_by_host_and_it_is_the_manufacturer_site_of_polstar(): void
    {
        $registry = app(B2bConnectorRegistry::class);

        $this->assertSame('polstar', $registry->keyForSites(['https://polstar.com.pl/users/users/login?redirect=%2Fmoje_konto']));
        $this->assertSame('polstar', $registry->keyForSites(['polstar.com.pl']));
        $this->assertSame('Polstar', $registry->label('polstar'));
        $this->assertTrue($registry->requiresPassword('polstar'));
        $account = B2bAccount::query()->create(['username' => 'PHT TEST', 'password' => 'sekret', 'sites' => ['polstar.com.pl']]);
        $connector = $registry->make($account, 0);
        $this->assertInstanceOf(PolstarB2bConnector::class, $connector);
        $this->assertInstanceOf(B2bManufacturerSite::class, $connector);
        $this->assertSame('Polstar', PolstarB2bConnector::ownBrand());
        $this->assertSame('Polstar', $connector->manufacturer(new B2bRemoteProduct('1', 'X', 'X')));
    }

    private function client(): PolstarB2bClient
    {
        return new PolstarB2bClient('PHT TEST', 'dobre-haslo', 0, static function (int $ms): void {});
    }

    private function connector(): PolstarB2bConnector
    {
        $connector = new PolstarB2bConnector($this->client());
        $connector->login();

        return $connector;
    }

    private function account(): B2bAccount
    {
        return B2bAccount::query()->firstOrCreate(
            ['username' => 'PHT TEST'],
            ['password' => 'dobre-haslo', 'sites' => ['polstar.com.pl'], 'connector' => 'polstar', 'sync_images' => false],
        )->fresh();
    }

    /**
     * @return array<string, B2bRemoteProduct>
     */
    private function productsBySku(PolstarB2bConnector $connector): array
    {
        $out = [];
        foreach ($connector->products() as $product) {
            $out[$product->sku] = $product;
        }

        return $out;
    }

    /** Plik XML produktów konta w kształcie ze sklepu (downloadProductsList/xml). */
    private static function productsXml(): string
    {
        $variant = static fn (string $id, string $color, string $size, string $ean): string => '<wariant><kolor>'.$color.'</kolor><kod_koloru>_x</kod_koloru><kolor_id>1</kolor_id>'
            .'<rozmiar>'.$size.'</rozmiar><kod_rozmiaru>_'.$size.'</kod_rozmiaru><rozmiar_id>2</rozmiar_id><id_wariantu>'.$id.'</id_wariantu>'
            .'<kod_produktu>X-'.$id.'</kod_produktu><stan>Duża ilość</stan><stan2>20</stan2><ean13>'.$ean.'</ean13></wariant>';
        $product = static fn (string $id, string $name, string $code, string $collection, string $categories, string $retail, string $inner, string $producer = 'Polstar Holding Wołoszczuk sp.k.'): string => '<produkt>'
            .'<id>'.$id.'</id><nazwa>'.$name.'</nazwa><symbol>t'.$code.'</symbol><kod_produktu>'.$code.'</kod_produktu><category_id>32</category_id>'
            .'<kategorie>'.$categories.'</kategorie><kategorie_ids>5-X,32-Y</kategorie_ids><kolekcja>'.$collection.'</kolekcja>'
            .'<producent>'.$producer.'</producent><cena_detaliczna>'.$retail.'</cena_detaliczna><cena_detaliczna_eur>1</cena_detaliczna_eur><opis/>'
            .$inner.'<kolor/><kod_koloru/><rozmiar/><kod_rozmiaru/><stan/><stan2/><wyprzedaz/><bestseller/></produkt>';

        return '<?xml version="1.0" encoding="utf-8"?>'."\n<produkty>"
            .$product('64', 'S kat. II', 'RCCS', 'COVENT', 'RĘKAWICE-&gt;POWLEKANE', '2.3',
                '<zdjecia><zdjecie><url>https://polstar.com.pl/product_media/25463/rekawice_covent_s_(2).jpg</url><warianty/></zdjecie>'
                .'<zdjecie><url>https://polstar.com.pl/product_media/25462/rekawice_covent_s_(3).jpg</url><warianty><id_wariantu>4853</id_wariantu></warianty></zdjecie></zdjecia>'
                .'<opakowanie_zbiorcze>120</opakowanie_zbiorcze><normy><norma>EN 420:2003 + A1:2009</norma><norma>EN388 : 2016</norma></normy>'
                .'<warianty>'.$variant('4853', '__', '8', '5900000000148').$variant('4854', '__', '9', '5900000000155').'</warianty>'
                .'<cechy><cecha><nazwa>Powleczenie</nazwa><wartosc>szorstkowany lateks</wartosc><jednostka/></cecha>'
                .'<cecha><nazwa>Gramatura</nazwa><wartosc>290</wartosc><jednostka>g/m2</jednostka></cecha></cechy>'
                .'<dane_szczegolowe>'
                .'<element><nazwa>..Rodzaj rękawicy:</nazwa><wartosci><wartosc>rękawica powlekana,</wartosc></wartosci></element>'
                .'<element><nazwa>..Część dłoniowa:</nazwa><wartosci><wartosc>powleczona szorstkowanym lateksem,</wartosc><wartosc>w kolorze szarym,</wartosc></wartosci></element>'
                .'<element><nazwa>..EN 388 - Odporność na uszkodzenia mechaniczne:</nazwa><wartosci><wartosc>2: odporność na ścieranie,</wartosc><wartosc>1: odporność na przecięcie,</wartosc></wartosci></element>'
                .'<element><nazwa>Oddychalność</nazwa><wartosci><wartosc>5000 g/m2/24h</wartosc></wartosci></element>'
                .'</dane_szczegolowe>')
            .$product('9', 'SPODNIE OGRODNICZKI', 'ABOG', 'BRIXTON CLASSIC', 'ODZIEŻ OCHRONNA-&gt;SPODNIE OGRODNICZKI', '53.78',
                '<zdjecia><zdjecie><url>https://polstar.com.pl/product_media/23552/abog.jpg</url><warianty/></zdjecie></zdjecia><opakowanie_zbiorcze>10</opakowanie_zbiorcze>'
                .'<normy><norma>EN 13688</norma></normy><warianty>'.$variant('3336', 'Zielony (_z)', '46', '5900000000001')
                .$variant('3337', 'Zielony (_z)', '2XL.', '5900000000002').$variant('3338', 'Niebieski (_n)', '44', '5900000000003').'</warianty><cechy/><dane_szczegolowe/>')
            .$product('667', 'SPODNIE OGRODNICZKI / ODBLASK', 'ABOG', 'BRIXTON CLASSIC', 'ODZIEŻ OCHRONNA-&gt;SPODNIE OGRODNICZKI', '67.97',
                '<zdjecia/><opakowanie_zbiorcze>10</opakowanie_zbiorcze><normy/><warianty>'.$variant('3400', 'Stalowy', '48', '5900000000004').'</warianty><cechy/><dane_szczegolowe/>')
            .$product('2628', 'MYNTS Jogger Pants Women WMP001 Lavender', 'MP01', 'MYNTS', 'ODZIEŻ MEDYCZNA-&gt;Ona-&gt;SPODNIE DO PASA', '1152.84',
                '<zdjecia/><opakowanie_zbiorcze>10</opakowanie_zbiorcze><normy/><warianty>'.$variant('5000', 'Lavender', 'M', '5900000000005').'</warianty><cechy/><dane_szczegolowe/>')
            .$product('2583', 'CHAPLIN OCHRONNIKI SŁUCHU GUARD 1 SNR-27', 'SOG1', 'CHAPLIN.', 'OCHRONA SŁUCHU', '17.15',
                '<zdjecia/><opakowanie_zbiorcze/><normy><norma>EN 352-1:2020</norma></normy><warianty>'.$variant('6000', '__', '_______a', '5900000000006').'</warianty><cechy/><dane_szczegolowe/>')
            .$product('2551', 'RĘKAWICE POLSTARITTA GUMOWE FLOKOWANE ŻÓŁTA', 'RUFL', 'POLSTARITTA', 'RĘKAWICE-&gt;CHEMICZNE', '4.10',
                '<zdjecia/><opakowanie_zbiorcze>144</opakowanie_zbiorcze><normy/><warianty>'.$variant('7000', 'Żółty', '9', '5900000000007').'</warianty><cechy/><dane_szczegolowe/>',
                'SUMIRUBBER MALAYSIA SDN BHD')
            .$product('777', 'PRODUKT BEZ KAFELKA', 'ZZZZ', 'POLSTAR', 'RĘKAWICE-&gt;POWLEKANE', '9.99',
                '<zdjecia/><opakowanie_zbiorcze/><normy/><warianty>'.$variant('8000', '__', '10', '5900000000008').'</warianty><cechy/><dane_szczegolowe/>')
            ."</produkty>\n";
    }

    /**
     * Kafelek produktu jak w sklepie: przycisk porównania z id tylko na stronach kategorii, cena z nbsp przed „zł”.
     */
    private static function tile(?string $id, string $slug, string $code, string $price, string $label = 'TWOJA CENA'): string
    {
        return '<div class="col-md-4"><div class="product-card"><div class="product-card-labels"></div>'
            .'<div class="product-card-toolbar">'.($id !== null ? '<a href="#" data-id="'.$id.'" class="product-compare"><span class="icon-compare"></span></a>' : '').'</div>'
            .'<a href="/produkt/'.$slug.'" class="product-card-photos"><div class="product-card-photo lazy" data-bg="url(\'/product_media/1/thumb_x.jpg\')"></div></a>'
            .'<div class="d-flex"><div class="mr-3"><div class="product-card-symbol">'.$code.'</div>'
            .'<div class="product-card-price">'."\n                ".$price."&nbsp;zł                  <span>\n                    ".$label."\n                  </span>\n              </div></div>"
            .'<div><h4 class="product-card-name">X<br><span>Y</span></h4></div></div>'
            .'<div class="text-right"><a href="#" class="add-to" data-slug="'.$slug.'" title="Dodaj do koszyka / schowka"><div class="multi-img"></div></a></div>'
            .'</div></div>';
    }

    private static function layout(string $content, bool $loggedIn): string
    {
        $menu = '<nav><a href="/katalog/rekawice">RĘKAWICE</a><a href="/katalog/rekawice_powlekane">POWLEKANE</a>'
            .'<a href="/katalog/odziez_ochronna">ODZIEŻ OCHRONNA</a><a href="/katalog/rekawice">RĘKAWICE</a></nav>';
        $account = $loggedIn ? '<a href="/moje_konto">Moje konto</a><a href="/wyloguj">Wyloguj się</a>' : '<a href="/login">Zaloguj się</a>';

        return '<!DOCTYPE html><html><head><meta charset="utf-8"/></head><body><header>'.$account.$menu.'</header>'
            .'<div id="product-list"><div class="row">'.$content.'</div></div><footer>Polstar</footer></body></html>';
    }

    private static function loginPage(): string
    {
        return self::layout('<form method="post" accept-charset="utf-8" id="loginForm" role="form" action="/login">'
            .'<input type="hidden" name="_method" class="form-control" value="POST" />'
            .'<input type="hidden" name="_csrfToken" class="form-control"  autocomplete="off" value="'.self::TOKEN.'" />'
            .'<input type="text" name="username" /><input type="password" name="password" /></form>', false);
    }

    private function listing(string $path): string
    {
        if ($this->emptyCategories) {
            return self::layout('<p>Brak produktów</p>', true);
        }

        return self::layout(match ($path) {
            '/katalog/rekawice' => self::tile('64', 'covent_s', 'RCCS', '1,59')
                .self::tile('2551', 'polstaritta-flokowane', 'RUFL', '3,12'),
            // ten sam produkt w podkategorii — ta sama cena
            '/katalog/rekawice_powlekane' => self::tile('64', 'covent_s', 'RCCS', '1,59'),
            '/katalog/odziez_ochronna' => self::tile('9', 'brixton_classic_spodnie', 'ABOG', '40,87')
                .self::tile('667', 'brixton_classic_spodnie_odblask', 'ABOG', '51,66')
                .self::tile('2628', 'mynts-jogger-pants-women-mp001-lavender', 'MP01', '1&nbsp;152,84', 'DETAL')
                // produkt spoza pliku XML (wyprzedaż) — pomijany
                .self::tile('682', 'fusion_polbut', 'OF01', '45,59'),
            default => '',
        }, true);
    }

    private static function productPage(string $id, string $files, string $protection = ''): string
    {
        return '<!DOCTYPE html><html><head><meta charset="utf-8"/></head><body><a href="/wyloguj">Wyloguj się</a>'
            ."<script>\nvar product = {\n  id: '".$id."',\n  reference: 'X-',\n  qw: '1'\n};\nvar logged = '1';\n</script>"
            .'<dl class="product-info"><dt>Kod produktu:</dt><dd><p class="product-symbol">X</p></dd>'
            .($protection !== '' ? '<dt>Kategoria ochrony:</dt>'."\n".'<dd>'."\n   ".$protection."   \n</dd>" : '')
            .'<dt>Pliki:</dt><dd>'.$files.'</dd><dt>Producent:</dt><dd><a href="#">Polstar Holding Wołoszczuk sp.k.</a></dd></dl>'
            .'<div class="product-price">2,30&nbsp;zł<div class="price-description">Cena detaliczna</div></div></body></html>';
    }

    private function page(string $slug): ?string
    {
        return match ($slug) {
            'covent_s' => self::productPage('64',
                '<a href="/media/medias/download/4616" class="product-file">PPWR folia - Covent S.pdf</a>'
                .'<a href="/media/medias/download/3889" class="product-file">Covent S.pdf</a>'
                .'<a href="/media/medias/download/2475" class="product-file">Covent S deklaracja 2023.pdf</a>'
                .'<a href="/media/medias/download/2473" class="product-file">COVENT S instrukcja 2023.pdf</a>'
                .'<a href="https://example.test/obcy.pdf" class="product-file">obcy.pdf</a>', 'II'),
            'chaplin-ochronniki-sluchu-guard-1-snr-27' => self::productPage('2583',
                '<a href="/media/medias/download/5001" class="product-file">Instrukcja użytkowania Chaplin Guard 1.pdf</a>', 'III'),
            'chaplin-guard-1-stara-wersja' => self::productPage('2999', ''),
            'brixton_classic_spodnie', 'brixton_classic_spodnie_odblask', 'mynts-jogger-pants-women-mp001-lavender', 'polstaritta-flokowane' => self::productPage('1', ''),
            default => null,
        };
    }

    private function search(string $keyword): string
    {
        if ($keyword !== 'CHAPLIN OCHRONNIKI SŁUCHU GUARD 1 SNR-27') {
            return self::layout('<p>Brak wyników</p>', true);
        }

        // kafelki wyszukiwarki nie mają id: inny kod i ten sam kod innego produktu, dopiero trzeci to szukany
        return self::layout(
            self::tile(null, 'chaplin-guard-2-snr-31', 'SOG2', '15,10')
            .self::tile(null, 'chaplin-guard-1-stara-wersja', 'SOG1', '9,99')
            .self::tile(null, 'chaplin-ochronniki-sluchu-guard-1-snr-27', 'SOG1', '13,03'),
            true,
        );
    }

    private function fakeSite(): void
    {
        Http::fake(function (Request $request) {
            $url = $request->url();
            $path = (string) parse_url($url, PHP_URL_PATH);
            preg_match('/CAKEPHP=(sess\d+)/', $request->header('Cookie')[0] ?? '', $m);
            $loggedIn = isset($m[1]) && in_array($m[1], $this->validSessions, true);

            if ($path === '/users/users/login') {
                return Http::response(self::loginPage(), 200, ['Set-Cookie' => 'csrfToken=csrf1; path=/']);
            }
            if ($path === '/login' && $request->method() === 'POST') {
                $data = $request->data();
                // jak sklep: złe dane = strona logowania z HTTP 200, bez sesji konta
                if (($data['_csrfToken'] ?? null) !== self::TOKEN || ($data['username'] ?? null) !== 'PHT TEST' || ($data['password'] ?? null) !== 'dobre-haslo') {
                    return Http::response(self::loginPage(), 200);
                }
                $this->logins++;
                $this->validSessions[] = 'sess'.$this->logins;

                return Http::response(self::layout('<p>Moje konto</p>', true), 200, ['Set-Cookie' => 'CAKEPHP=sess'.$this->logins.'; path=/; HttpOnly']);
            }
            if ($path === '/customers/customers/downloadProductsList/xml') {
                if ($this->dropSessionsOnXml && $this->logins === 1) {
                    $this->validSessions = [];
                    $loggedIn = false;
                }
                if (! $loggedIn || $this->xmlAlwaysLoggedOut) {
                    return Http::response(self::loginPage(), 200, ['Content-Type' => 'text/html; charset=UTF-8']);
                }

                return Http::response(self::productsXml(), 200, ['Content-Type' => 'application/xml; charset=UTF-8']);
            }
            if (str_starts_with($path, '/katalog/')) {
                return Http::response($loggedIn ? $this->listing($path) : self::loginPage(), 200, ['Content-Type' => 'text/html; charset=UTF-8']);
            }
            if ($path === '/szukaj') {
                parse_str((string) parse_url($url, PHP_URL_QUERY), $query);

                return Http::response($loggedIn ? $this->search((string) ($query['keyword'] ?? '')) : self::loginPage());
            }
            if (str_starts_with($path, '/produkt/')) {
                $slug = rawurldecode(substr($path, strlen('/produkt/')));
                $page = $this->page($slug);
                if ($page === null) {
                    return Http::response('nieznana karta', 404);
                }
                $this->pageHits++;
                $this->pageHitsBySlug[$slug] = ($this->pageHitsBySlug[$slug] ?? 0) + 1;

                return Http::response($loggedIn ? $page : self::loginPage(), 200, ['Content-Type' => 'text/html; charset=UTF-8']);
            }
            if (str_starts_with($path, '/product_media/')) {
                return Http::response(self::JPEG, 200, ['Content-Type' => 'image/jpeg']);
            }
            if (str_starts_with($path, '/media/medias/download/')) {
                // każdy plik z inną treścią — zapis plików rozpoznaje powtórzony plik po sumie kontrolnej
                return Http::response("%PDF-1.4\n".$path."\n%%EOF", 200, [
                    'Content-Type' => 'application/pdf',
                    'Content-Disposition' => 'attachment; filename="plik.pdf"',
                ]);
            }

            return Http::response('nieznany adres w teście: '.$url, 404);
        });
    }
}
