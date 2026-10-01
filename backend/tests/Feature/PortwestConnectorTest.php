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
use App\Services\B2b\PortwestB2bClient;
use App\Services\B2b\PortwestB2bConnector;
use Closure;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use RuntimeException;
use Tests\TestCase;

/**
 * Łącznik portwest.com/market na atrapie portalu (Http::fake). Kształt odpowiedzi odwzorowuje zalogowane konto
 * z 01.10.2026: logowanie formularzem (POST /main/login/ {email, password}) z sesją w ciasteczku ci_session; strona
 * „Linki marketingowe” (/account/marketinglinks) z adresami cenników CSV (exportCustomerPriceLists/{grupa}/…) i plików
 * Daily Data (downloadDailyData?…&file=sohPL.csv / sohPLB.csv); bez sesji każdy adres konta oddaje stronę logowania
 * z HTTP 200 i z wpisanym hasłem odesłanym w polu formularza. Publiczne: opisy i normy na CDN-ie
 * d11ak7fd9ypfb7.cloudfront.net, karty produktu i deklaracje na documents.portwest.com (PDF poprzedzony śmieciowym
 * tekstem portalu), zdjęcia kolorów na CDN-ie.
 *
 * Łącznik odrzuca pliki ucięte: każdy cennik i Daily Data co najmniej 1000 wierszy, razem co najmniej 5000 pozycji;
 * opisy i normy Portwest co najmniej 1000 wierszy, Base 100; najwyżej 8% pozycji Daily Data bez ceny. Wypełniacz:
 * - cennik „99”: model FILL z 5000 różnymi pozycjami, Daily Data sohPL.csv: 5000 wierszy TEJ SAMEJ pozycji FILLBKR1;
 * - cennik Base „12”: model BFILL z 1000 pozycjami, sohPLB.csv: 1000 wierszy pozycji BFILLGOR1;
 * - opisy i normy: wiersze modeli spoza Daily Data (ZD…, ZS…) — łącznik je pomija, ale liczy do progu.
 * Strażnik liczy wiersze, a karta łączy pozycje po kodzie — pełny przebieg zapisuje karty FILL i BFILL z jednym
 * rozmiarem zamiast 6000 wierszy rozmiarów (przebieg w sekundach, nie minutach).
 *
 * Wszystkie dane (kody, ceny, nazwy, opisy, EAN-y, numery certyfikatów) są SYNTETYCZNE.
 */
final class PortwestConnectorTest extends TestCase
{
    use RefreshDatabase;

    private const USER = '123456';

    private const PASSWORD = 'dobre-haslo';

    private const CDN = 'https://d11ak7fd9ypfb7.cloudfront.net';

    private const PRICE_ALL = 'https://portwest.com/account/exportCustomerPriceLists/99/A3/PL/A5';

    private const PRICE_BASE = 'https://portwest.com/account/exportCustomerPriceLists/12/A3/PL/A5';

    private const DAILY = 'https://portwest.com/account/downloadDailyData?name=LOG_DL_NAME_DAILY_DATA&file=sohPL.csv';

    private const DAILY_BASE = 'https://portwest.com/account/downloadDailyData?name=LOG_DL_NAME_DAILY_DATA_BASE&file=sohPLB.csv';

    private const PRICE_HEADER = "\u{FEFF}ProductRange,ProductGroup,Style,ItemCode,Currency,Price";

    private const DAILY_HEADER = "\u{FEFF}Style,Item,Carton_Qty,Price,Colour,Size,Fit,Product_Name,UK_SoH,PL_SoH,Next_Delivery,Length,Width,Height,CC,Weight(Kg),EAN13,DUN14,Commodity_Code,Unit_Of_Sale,Image_Path,Order_Multiple,Description";

    private const DESC_HEADER = "\u{FEFF}Style,ProductType,Range,Collection,Product,Description,Features";

    private const STDS_HEADER = 'Style,"Test House","Cert No.","Standards (1)","Standards (2)","Standards (3)","Standards (4)","Standards (5)","Standards (6)","Standards (7)","Standards (8)","Standards (9)","Standards (10)"';

    /** @var list<string> ważne identyfikatory sesji (ci_session) */
    private array $sessions = [];

    private int $logins = 0;

    /** Pierwsze pobranie cennika trafia na wygasłą sesję (strona logowania), potem portal działa. */
    private bool $expireAtPriceList = false;

    /** Pliki konta (cennik, Daily Data) zawsze wracają jako strona logowania, choć logowanie się udaje. */
    private bool $filesRefused = false;

    /** Strona linków ma cennik Base (grupa 12). */
    private bool $baseLink = true;

    /** Różne pozycje wypełniacza w cenniku „99” (model FILL). */
    private int $priceFiller = 5000;

    /** Różne pozycje wypełniacza w cenniku Base „12” (model BFILL). */
    private int $basePriceFiller = 1000;

    /** Wiersze wypełniacza w sohPL.csv (pozycja FILLBKR1). */
    private int $dailyFiller = 5000;

    /** Ile z wierszy wypełniacza sohPL.csv ma kod spoza cennika (pozycja bez ceny). */
    private int $unpricedFiller = 0;

    /** Wiersze wypełniacza w sohPLB.csv (pozycja BFILLGOR1). */
    private int $baseDailyFiller = 1000;

    /** Wiersze modeli spoza Daily Data w plikach opisów i norm: [opisy Portwest, opisy Base, normy Portwest, normy Base]. */
    private int $descFiller = 1000;

    private int $baseDescFiller = 100;

    private int $stdsFiller = 1000;

    private int $baseStdsFiller = 100;

    /** Waluta jednego wiersza cennika (AP50BKR8). */
    private string $currency = 'PLN';

    /** Nagłówek cennika „99” (null = prawdziwy). */
    private ?string $priceHeader = null;

    /** Nagłówek sohPL.csv (null = prawdziwy). */
    private ?string $dailyHeader = null;

    protected function setUp(): void
    {
        parent::setUp();
        Queue::fake();
    }

    public function test_login_posts_the_form_and_reads_the_account_file_links(): void
    {
        $this->fakePortal();
        $client = $this->client();

        $client->login();

        $this->assertTrue($client->isLoggedIn());
        $post = Http::recorded(static fn (Request $r): bool => $r->method() === 'POST')->first()[0];
        $this->assertSame('https://portwest.com/main/login/', $post->url());
        $this->assertTrue($post->isForm());
        $this->assertSame(['email' => self::USER, 'password' => self::PASSWORD], $post->data());
        $links = Http::recorded(static fn (Request $r): bool => str_ends_with($r->url(), '/account/marketinglinks'))->first()[0];
        $this->assertStringContainsString('ci_session=sess1', $links->header('Cookie')[0] ?? '');
        // linki opisów, innych hostów i stron konta — pominięte; &amp; w adresie odkodowane
        $this->assertSame(['99' => self::PRICE_ALL, '12' => self::PRICE_BASE], $client->priceListUrls());
        $this->assertSame(['sohPL.csv' => self::DAILY, 'sohPLB.csv' => self::DAILY_BASE], $client->dailyDataUrls());
    }

    public function test_wrong_password_is_not_fatal_and_the_echoed_password_never_reaches_the_message(): void
    {
        $this->fakePortal();
        $client = new PortwestB2bClient(self::USER, 'zle-haslo-ECHO', 0, static function (int $ms): void {});

        try {
            $client->login();
            $this->fail('logowanie złym hasłem powinno się nie udać');
        } catch (B2bFatalException $e) {
            $this->fail('złe hasło to nie błąd krytyczny: '.$e->getMessage());
        } catch (RuntimeException $e) {
            $this->assertStringContainsString('sprawdź numer konta i hasło', $e->getMessage());
            $this->assertStringNotContainsString('zle-haslo-ECHO', $e->getMessage());
            $this->assertStringNotContainsString('zle-haslo-ECHO', (string) $e->getPrevious()?->getMessage());
        }
        $this->assertFalse($client->isLoggedIn());
        // portal naprawdę odesłał hasło w stronie — test nie przechodzi tylko dlatego, że atrapa go nie zwróciła
        $page = Http::recorded(static fn (Request $r): bool => $r->method() === 'POST')->first()[1];
        $this->assertStringContainsString('value="zle-haslo-ECHO"', $page->body());
    }

    public function test_helpers_colour_code_norm_rows_and_delivery_date(): void
    {
        $this->assertSame('WHR', PortwestB2bConnector::colourCode('2201', '2201WHRL', 'L'));
        $this->assertSame('BKR', PortwestB2bConnector::colourCode('A001', 'A001BKR', ''));
        $this->assertSame('BKG', PortwestB2bConnector::colourCode('BL100', 'BL100BKG', '100cm'));
        $this->assertSame('NAT', PortwestB2bConnector::colourCode('2201', '2201NATXL', 'XL'));

        $this->assertTrue(PortwestB2bConnector::isEuropeanNorm('EN ISO 21420: 2020 Dexterity 5'));
        $this->assertTrue(PortwestB2bConnector::isEuropeanNorm('EN 388: 2016 + A1: 2018  (4X43D)'));
        $this->assertTrue(PortwestB2bConnector::isEuropeanNorm('EN471'));
        $this->assertTrue(PortwestB2bConnector::isEuropeanNorm('PN-EN ISO 20471'));
        $this->assertTrue(PortwestB2bConnector::isEuropeanNorm('BS EN 13034 Type 6'));
        $this->assertTrue(PortwestB2bConnector::isEuropeanNorm('ISO 13688'));
        $this->assertTrue(PortwestB2bConnector::isEuropeanNorm('IEC 61482-2'));
        $this->assertFalse(PortwestB2bConnector::isEuropeanNorm('ANSI/ISEA 105: 2016 CUT Level (A6)'));
        $this->assertFalse(PortwestB2bConnector::isEuropeanNorm('AS/NZS 4602.1: 2011'));
        $this->assertFalse(PortwestB2bConnector::isEuropeanNorm('CE Cat 1'));
        $this->assertFalse(PortwestB2bConnector::isEuropeanNorm('prEN 17353'));
        $this->assertFalse(PortwestB2bConnector::isEuropeanNorm('ENERGY STAR'));

        $norm = 'Norma';
        $other = 'Inne normy i oznaczenia';
        // dwie normy EN w jednym wpisie — dwa wiersze; przedrostek krajowy normy nie dzieli
        $this->assertSame([[$norm, 'EN 61482-2'], [$norm, 'EN 61482-1-2 APC 1']], PortwestB2bConnector::normRows('EN 61482-2 EN 61482-1-2 APC 1'));
        $this->assertSame([[$norm, 'BS EN 13034 Type 6']], PortwestB2bConnector::normRows('BS EN 13034 Type 6'));
        // numer części oddzielony spacją — złączony
        $this->assertSame([[$norm, 'EN 1149-5']], PortwestB2bConnector::normRows('EN 1149 -5'));
        $this->assertSame([[$norm, 'EN 388: 2016 + A1: 2018 (4X43D)']], PortwestB2bConnector::normRows("EN 388: 2016 + A1: 2018 \u{00A0}(4X43D)"));
        $this->assertSame([[$norm, 'EN ISO 21420: 2020 Dexterity 5']], PortwestB2bConnector::normRows('EN ISO 21420: 2020 Dexterity 5'));
        foreach (['ANSI/ISEA 105: 2016 CUT Level (A6)', 'AS/NZS 4602.1: 2011 Class D/N', 'CE Cat 1', 'Waterproof/Breathability (WP 10,000mm)', 'Fabric Conforms to EN 388: 2016', 'prEN 17353'] as $text) {
            $this->assertSame([[$other, $text]], PortwestB2bConnector::normRows($text), $text);
        }
        $this->assertSame([], PortwestB2bConnector::normRows("  \u{200B} "));

        $this->assertSame('27.11.2026', PortwestB2bConnector::deliveryDate('27/11/2026'));
        $this->assertSame('27.11.2026', PortwestB2bConnector::deliveryDate(' 27/11/2026 '));
        $this->assertSame('', PortwestB2bConnector::deliveryDate(''));
        $this->assertSame('', PortwestB2bConnector::deliveryDate('31/02/2026'));
        $this->assertSame('', PortwestB2bConnector::deliveryDate('2026-11-27'));
    }

    public function test_model_becomes_one_card_with_colours_sizes_account_prices_stock_identifiers_and_polish_description(): void
    {
        $this->fakePortal();
        $connector = $this->connector();

        $products = iterator_to_array($connector->products(), false);

        $this->assertSame(['2201', 'AP50', 'A080', 'S999', 'A147', 'FILL', 'B0105', 'BFILL'], array_map(static fn (B2bRemoteProduct $p): string => $p->sku, $products));
        $card = $products[0];
        $this->assertSame('ok', $card->raw['status']);
        // kolory po kodzie: NAT przed WHR, choć Daily Data zaczyna się od WHR — kolor wiodący stały
        $this->assertSame('2201NATL', $card->remoteId);
        // spacja na końcu „Product” odcięta
        $this->assertSame('Kombinezon dla przemysłu spożywczego Portwest 2201', $card->name);
        $this->assertSame('Odzież > Odzież dla przemysłu spożywczego', $card->category);
        $this->assertSame('https://portwest.com/products/view/2201/NAT', $card->sourceUrl);
        $this->assertSame('Portwest', $connector->manufacturer($card));
        $this->assertSame('Kolory: Navy Tall (NAT), White (WHR); rozmiary: L, XL', $card->variantSummary);
        // cena z cennika konta, nie z kolumny Price Daily Data (NATL: 72.10 w cenniku, 80.00 w Daily Data); 2201NATXL bez ceny
        // w obu plikach — poza kartą
        $this->assertSame(
            [
                ['2201NATL', '2201NATL', 'Navy Tall (NAT) / L', 72.1, 'Brak na stanie', 'Kombinezon dla przemysłu spożywczego Portwest 2201, Navy Tall (NAT) L'],
                ['2201WHRL', '2201WHRL', 'White (WHR) / L', 67.5, 'Na stanie: 354', 'Kombinezon dla przemysłu spożywczego Portwest 2201, White (WHR) L'],
                ['2201WHRXL', '2201WHRXL', 'White (WHR) / XL', 67.5, 'Brak na stanie, dostawa od 27.11.2026', 'Kombinezon dla przemysłu spożywczego Portwest 2201, White (WHR) XL'],
            ],
            array_map(static fn (array $m): array => [$m['remote_id'], $m['sku'], $m['size'], $m['price']->net, $m['availability'], $m['name']], $card->members),
        );
        foreach ($card->members as $member) {
            $this->assertSame('PLN', $member['price']->currency);
            $this->assertNull($member['price']->base);
        }
        $this->assertSame(
            'Brak na stanie: Navy Tall (NAT) / L; Na stanie: White (WHR) / L; Brak na stanie, dostawa od 27.11.2026: White (WHR) / XL',
            $card->availability,
        );
        $price = $connector->price($card);
        $this->assertSame(67.5, $price->net);
        $this->assertNull($price->base);
        $this->assertSame('PLN', $price->currency);
        $this->assertFalse($price->order?->restricts());
        $this->assertSame(
            [
                [ProductIdentifier::TYPE_MODEL_CODE, '2201', null, null, 'Style'],
                [ProductIdentifier::TYPE_SOURCE_CODE, '2201NATL', '2201NATL', 'Navy Tall (NAT) / L', 'Item'],
                // pierwszy wiersz Daily Data ma BOM w kodzie pozycji — usunięty
                [ProductIdentifier::TYPE_SOURCE_CODE, '2201WHRL', '2201WHRL', 'White (WHR) / L', 'Item'],
                [ProductIdentifier::TYPE_EAN, '5036108106691', '2201WHRL', 'White (WHR) / L', 'EAN13'],
                [ProductIdentifier::TYPE_PACK_EAN, '15036108106691', '2201WHRL', 'White (WHR) / L', 'DUN14'],
                // DUN14 bez 14 cyfr — bez kodu opakowania
                [ProductIdentifier::TYPE_SOURCE_CODE, '2201WHRXL', '2201WHRXL', 'White (WHR) / XL', 'Item'],
                [ProductIdentifier::TYPE_EAN, '5036108106708', '2201WHRXL', 'White (WHR) / XL', 'EAN13'],
            ],
            array_map(static fn ($i): array => [$i->type, $i->value, $i->remoteId, $i->label, $i->field], $card->identifiers ?? []),
        );
        // tylko polski opis i cechy z pliku opisów (cecha powtórzona raz); angielski opis z Daily Data nie trafia na kartę
        $description = $connector->description($card);
        $this->assertSame("Kombinezon z poliestru i bawełny do zakładów spożywczych.\nZatrzaski kryte\nBez kieszeni zewnętrznych\nGumka w pasie", $description);
        $this->assertStringNotContainsString('English', $description);
        $this->assertSame(
            [
                'Informacje ze sklepu Portwest | Marka | Portwest',
                'Informacje ze sklepu Portwest | Kod modelu | 2201',
                'Informacje ze sklepu Portwest | Nazwa w danych sklepu | Food Industry Coverall',
                'Informacje ze sklepu Portwest | Rodzaj | Odzież',
                'Informacje ze sklepu Portwest | Linia | Odzież dla przemysłu spożywczego',
                'Informacje ze sklepu Portwest | Kolekcja | Odzież dla przemysłu spożywczego',
                'Informacje ze sklepu Portwest | Grupa w cenniku | Odzież dla przemysłu spożywczego > Kombinezony',
                'Informacje ze sklepu Portwest | Ilość w kartonie | 18',
                'Normy | Norma | EN ISO 13688: 2013',
                'Normy | Inne normy i oznaczenia | CE',
                // „EN 1149 -5” i „EN 1149-5” to ten sam wiersz — raz
                'Normy | Norma | EN 1149-5',
                'Normy | Norma | BS EN 13034 Type 6',
                // znaczniki HTML i encje w zapisie jednostek — sam tekst
                'Normy | Norma | IEC 61482-2 ATPV 8 Cal/CM2',
                'Normy | Inne normy i oznaczenia | Waterproof & Breathable',
            ],
            $this->fieldLines($connector, $card),
        );
        $this->assertSame(
            [
                ['Karta produktu 2201', ProductDocument::KIND_DATASHEET, 'https://documents.portwest.com/datasheet.php?style=2201&lang=PL&itemcol=2201NAT&facility=920'],
                ['Deklaracja zgodności UE 2201', ProductDocument::KIND_CERTIFICATE, 'https://documents.portwest.com/declaration_eu.php?style=2201&lang=PL&itemcol=2201NAT'],
            ],
            array_map(static fn ($d): array => [$d->title, $d->kind, $d->sourceUrl], $connector->documents($card)),
        );
        $this->assertSame(
            [self::CDN.'/styles1100px/2201NAT.jpg', self::CDN.'/styles1100px/2201WHR.jpg'],
            $connector->imageUrls($card),
        );

        $summary = implode("\n", $connector->runSummary());
        // pozycje liczone raz — powtórzone wiersze wypełniacza (ten sam Item) osobno, jak 7 identycznych wierszy Base w portalu
        $this->assertStringContainsString('Lista Portwest: 8 modeli w Daily Data (Portwest 6, Base 2), 15 pozycji; cennik konta: 6010 pozycji', $summary);
        $this->assertStringContainsString('Powtórzone wiersze Daily Data (wzięty pierwszy): 5998', $summary);
        $this->assertStringContainsString('Karty: 7 (2 z rozmiarami albo kolorami w różnych cenach', $summary);
        $this->assertStringContainsString('Bez polskiego opisu w pliku opisów Portwest: 4, np. A080, A147, FILL, BFILL', $summary);
        $this->assertSame(8, $connector->totalProducts());
    }

    public function test_unpriced_positions_stay_off_the_card_and_unpriced_or_price_only_models_get_no_card(): void
    {
        $this->fakePortal();
        $connector = $this->connector();

        $products = iterator_to_array($connector->products(), false);
        $bySku = [];
        foreach ($products as $product) {
            $bySku[$product->sku] = $product;
        }

        // model z cennika bez oferty sklepu — żadnej karty, ani pominiętej
        $this->assertArrayNotHasKey('PO01', $bySku);
        $this->assertNotContains('2201NATXL', array_column($bySku['2201']->members, 'remote_id'));
        $this->assertNotContains('2201NATXL', array_map(static fn ($i): ?string => $i->remoteId, $bySku['2201']->identifiers ?? []));

        $skipped = $bySku['S999'];
        $this->assertSame('skipped', $skipped->raw['status']);
        $this->assertSame('Unpriced Vest Portwest S999', $skipped->name);
        $this->assertSame('https://portwest.com/products/view/S999', $skipped->sourceUrl);
        $this->assertSame([], $connector->shopFields($skipped));
        $this->assertSame([], $skipped->members);

        $summary = implode("\n", $connector->runSummary());
        $this->assertStringContainsString('Modele tylko w cenniku konta, bez oferty sklepu (Daily Data) — bez karty: 1, np. PO01', $summary);
        $this->assertStringContainsString('Modele bez żadnej pozycji z ceną (pominięte): 1, np. S999', $summary);
        // „N/A”, pusta i zerowa cena Daily Data to brak ceny
        $this->assertStringContainsString('Pozycje sklepu bez ceny w cenniku konta i w Daily Data (poza kartą): 3, np. 2201NATXL, S999BKRM, S999BKRL', $summary);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('żadna pozycja modelu z oferty sklepu nie ma ceny konta (ani w cenniku CSV, ani w Daily Data)');
        $connector->price($skipped);
    }

    public function test_daily_data_price_is_the_fallback_without_a_price_list_row_and_a_different_price_is_reported(): void
    {
        $this->fakePortal();
        $connector = $this->connector();

        $products = iterator_to_array($connector->products(), false);

        $cap = $products[4];
        $this->assertSame('A147', $cap->sku);
        $this->assertSame('ok', $cap->raw['status']);
        $this->assertSame([['A147WHR', 'jeden rozmiar', 10.7, 'PLN']], array_map(
            static fn (array $m): array => [$m['remote_id'], $m['size'], $m['price']->net, $m['price']->currency],
            $cap->members,
        ));
        $this->assertSame(10.7, $connector->price($cap)->net);
        $this->assertSame('Disposable Cap Portwest A147', $cap->name);
        // bez opisu i bez wiersza w cenniku — kategorii nie ma skąd wziąć
        $this->assertNull($cap->category);

        // obie ceny są — na karcie cena z cennika CSV, różnica w podsumowaniu
        $this->assertSame(72.1, $products[0]->members[0]['price']->net);
        $summary = implode("\n", $connector->runSummary());
        $this->assertStringContainsString('Pozycje z ceną z Daily Data, bez wiersza w cenniku CSV konta: 1, np. A147WHR', $summary);
        $this->assertStringContainsString('Różna cena w cenniku CSV i w Daily Data (na karcie cena z cennika): 1, np. 2201NATL 72.10 / 80.00', $summary);
    }

    public function test_order_multiple_becomes_min_and_step_and_mixed_multiples_vary(): void
    {
        $this->fakePortal();
        $connector = $this->connector();

        $products = iterator_to_array($connector->products(), false);

        $gloves = $products[1];
        $this->assertSame('AP50', $gloves->sku);
        $this->assertSame(
            ['order_min_qty' => 12.0, 'order_step_qty' => 12.0, 'order_unit' => null, 'order_varies' => false],
            $connector->price($gloves)->order?->slotValues(),
        );
        $this->assertSame(12.4, $connector->price($gloves)->net);
        // jeden kolor — rozmiary bez nazwy koloru; nazwa koloru z pierwszego niepustego wiersza grupy
        $this->assertSame(['8', '9'], array_column($gloves->members, 'size'));
        $this->assertSame('Kolor: Black (BKR); rozmiary: 8, 9', $gloves->variantSummary);
        $this->assertSame('Rękawice antyprzecięciowe Portwest AP50, Black (BKR) 8', $gloves->members[0]['name']);
        $this->assertSame('Na stanie', $gloves->availability);

        $hairnet = $products[2];
        $this->assertSame('A080', $hairnet->sku);
        $this->assertSame(
            ['order_min_qty' => null, 'order_step_qty' => null, 'order_unit' => null, 'order_varies' => true],
            $connector->price($hairnet)->order?->slotValues(),
        );
    }

    public function test_norm_rows_test_house_certificate_and_declaration_only_with_a_european_norm(): void
    {
        $this->fakePortal();
        $connector = $this->connector();

        $products = iterator_to_array($connector->products(), false);

        $gloves = $products[1];
        $this->assertSame(
            [
                'Informacje ze sklepu Portwest | Marka | Portwest',
                'Informacje ze sklepu Portwest | Kod modelu | AP50',
                'Informacje ze sklepu Portwest | Nazwa w danych sklepu | Cut Resistant Glove',
                'Informacje ze sklepu Portwest | Rodzaj | Rękawice',
                'Informacje ze sklepu Portwest | Linia | Ochrona dłoni',
                'Informacje ze sklepu Portwest | Kolekcja | Rękawice antyprzecięciowe',
                'Informacje ze sklepu Portwest | Grupa w cenniku | Ochrona dłoni > Rękawice',
                'Informacje ze sklepu Portwest | Ilość w kartonie | 144',
                'Normy | Norma | EN ISO 21420: 2020 Dexterity 5',
                // dwie spacje ze źródła zwinięte do jednej, poziom dosłownie
                'Normy | Norma | EN 388: 2016 + A1: 2018 (4X43D)',
                'Normy | Inne normy i oznaczenia | ANSI/ISEA 105: 2016 CUT Level (A6)',
                'Normy | Jednostka certyfikująca | CTC',
                'Normy | Numer certyfikatu | 0075/2085',
            ],
            $this->fieldLines($connector, $gloves),
        );
        $this->assertSame(
            [
                ['Karta produktu AP50', 'https://documents.portwest.com/datasheet.php?style=AP50&lang=PL&itemcol=AP50BKR&facility=920'],
                ['Deklaracja zgodności UE AP50', 'https://documents.portwest.com/declaration_eu.php?style=AP50&lang=PL&itemcol=AP50BKR'],
            ],
            array_map(static fn ($d): array => [$d->title, $d->sourceUrl], $connector->documents($gloves)),
        );

        // tylko oznakowanie CE — bez deklaracji (portal wydałby pusty PDF), karta produktu zawsze
        $hairnet = $products[2];
        $this->assertSame(['Normy | Inne normy i oznaczenia | CE Cat 1'], array_values(array_filter(
            $this->fieldLines($connector, $hairnet),
            static fn (string $line): bool => str_starts_with($line, 'Normy |'),
        )));
        $this->assertSame(
            [['Karta produktu A080', ProductDocument::KIND_DATASHEET, 'https://documents.portwest.com/datasheet.php?style=A080&lang=PL&itemcol=A080BKR&facility=920']],
            array_map(static fn ($d): array => [$d->title, $d->kind, $d->sourceUrl], $connector->documents($hairnet)),
        );
        $this->assertSame(['Norma'], PortwestB2bConnector::normShopFieldNames());
    }

    public function test_one_size_items_and_model_without_polish_description(): void
    {
        $this->fakePortal();
        $connector = $this->connector();

        $hairnet = iterator_to_array($connector->products(), false)[2];

        $this->assertSame('A080', $hairnet->sku);
        // bez opisu w descPL.csv — nazwa z Daily Data, kategoria z cennika konta, pusty opis
        $this->assertSame('Hairnet Portwest A080', $hairnet->name);
        $this->assertSame('Odzież jednorazowa > Siatki na włosy', $hairnet->category);
        $this->assertSame('', $connector->description($hairnet));
        $this->assertSame(
            [['A080BKR', 'Black (BKR) / jeden rozmiar'], ['A080WHR', 'White (WHR) / jeden rozmiar']],
            array_map(static fn (array $m): array => [$m['remote_id'], $m['size']], $hairnet->members),
        );
        $this->assertSame('Kolory: Black (BKR), White (WHR); rozmiary: jeden rozmiar', $hairnet->variantSummary);
        $this->assertSame('https://portwest.com/products/view/A080/BKR', $hairnet->sourceUrl);
        $this->assertNotContains('Informacje ze sklepu Portwest | Nazwa w danych sklepu | Hairnet', $this->fieldLines($connector, $hairnet));
    }

    public function test_base_model_from_its_own_daily_file_is_branded_base_but_made_by_portwest(): void
    {
        $this->fakePortal();
        $connector = $this->connector();

        $products = iterator_to_array($connector->products(), false);
        $shoe = $products[6];

        $this->assertSame('B0105', $shoe->sku);
        $this->assertSame('ok', $shoe->raw['status']);
        $this->assertSame('Półbuty ochronne Classic S3 Base B0105', $shoe->name);
        $this->assertSame('Portwest', $connector->manufacturer($shoe));
        $this->assertSame('Obuwie > Base Classic', $shoe->category);
        $this->assertSame('Kolor: Szary/Pomarańcz (GOR); rozmiary: 37, 38', $shoe->variantSummary);
        $this->assertSame([['B0105GOR37', '37', 143.5], ['B0105GOR38', '38', 148.0]], array_map(
            static fn (array $m): array => [$m['remote_id'], $m['size'], $m['price']->net],
            $shoe->members,
        ));
        // Carton_Qty „N/A” — bez wiersza ilości w kartonie
        $this->assertSame(
            [
                'Informacje ze sklepu Portwest | Marka | Base',
                'Informacje ze sklepu Portwest | Kod modelu | B0105',
                'Informacje ze sklepu Portwest | Nazwa w danych sklepu | Półbut Classic',
                'Informacje ze sklepu Portwest | Rodzaj | Obuwie',
                'Informacje ze sklepu Portwest | Linia | Base Classic',
                'Informacje ze sklepu Portwest | Kolekcja | Base Classic',
                'Informacje ze sklepu Portwest | Grupa w cenniku | Classic > Półbuty',
                'Normy | Norma | EN ISO 20345: 2022 S3 SRC',
            ],
            $this->fieldLines($connector, $shoe),
        );
        $this->assertSame("Lekki półbut ochronny S3.\nPodnosek kompozytowy", $connector->description($shoe));
        $this->assertSame('https://documents.portwest.com/declaration_eu.php?style=B0105&lang=PL&itemcol=B0105GOR', $connector->documents($shoe)[1]->sourceUrl ?? null);
        $this->assertSame('BFILL', $products[7]->sku);
        $this->assertContains('Informacje ze sklepu Portwest | Marka | Base', $this->fieldLines($connector, $products[7]));
    }

    public function test_expired_session_is_renewed_once_and_a_file_still_behind_the_login_page_is_fatal(): void
    {
        $this->fakePortal();
        $connector = $this->connector();
        $this->expireAtPriceList = true;

        $this->assertCount(8, iterator_to_array($connector->products(), false));
        $this->assertSame(2, $this->logins);
        $this->assertFalse($this->expireAtPriceList);

        $this->logins = 0;
        $this->filesRefused = true;
        $stuck = $this->connector();

        try {
            iterator_to_array($stuck->products(), false);
            $this->fail('plik konta za stroną logowania po ponownym logowaniu powinien przerwać przebieg');
        } catch (B2bFatalException $e) {
            $this->assertStringContainsString('Utracono sesję konta portwest.com', $e->getMessage());
            $this->assertStringContainsString('po ponownym logowaniu', $e->getMessage());
            $this->assertStringNotContainsString(self::PASSWORD, $e->getMessage());
        }
        $this->assertSame(2, $this->logins);
    }

    public function test_short_price_list_stops_the_sync_before_anything_is_written(): void
    {
        Storage::fake('public');
        Storage::fake('local');
        $this->priceFiller = 100;
        $this->fakePortal();

        try {
            app(B2bAccountSyncRunner::class)->run($this->account(), delayMs: 0, withImages: true);
            $this->fail('cennik krótszy niż próg powinien przerwać przebieg');
        } catch (B2bFatalException $e) {
            $this->assertStringContainsString('Plik „Cennik konta (grupa 99)” z portwest.com ma tylko 109 wierszy (oczekiwane co najmniej 1000)', $e->getMessage());
        }

        $this->assertSame(0, Product::query()->count());
        $this->assertSame(0, B2bProductLink::query()->count());
        $this->assertSame(0, ProductSourcePrice::query()->count());
        $this->assertSame('failed', $this->account()->last_sync_status);
    }

    public function test_truncated_changed_or_foreign_account_files_stop_the_run_before_the_first_model(): void
    {
        $this->fakePortal();
        $cases = [
            'krótki cennik Base' => [fn () => $this->basePriceFiller = 500, 'Plik „Cennik konta (grupa 12)” z portwest.com ma tylko 502 wierszy (oczekiwane co najmniej 1000)'],
            'cenniki razem poniżej 5000 pozycji' => [fn () => $this->priceFiller = 3000, 'Cennik konta portwest.com ma tylko 4010 pozycji z ceną (oczekiwane co najmniej 5000)'],
            'brak linku cennika Base' => [fn () => $this->baseLink = false, 'nie ma cennika CSV grupy 12 (Base)'],
            'cennik w EUR' => [fn () => $this->currency = 'EUR', 'w walucie „EUR”'],
            'cennik bez kolumny Currency' => [fn () => $this->priceHeader = "\u{FEFF}ProductRange,ProductGroup,Style,ItemCode,Waluta,Price", 'nie ma kolumn: Currency'],
            'krótki sohPL.csv' => [fn () => $this->dailyFiller = 100, 'Plik „Daily Data sohPL.csv” z portwest.com ma tylko 111 wierszy (oczekiwane co najmniej 1000)'],
            'Daily Data razem poniżej 5000 pozycji' => [fn () => $this->dailyFiller = 3000, 'Daily Data konta portwest.com ma tylko 4013 pozycji'],
            'Daily Data bez kolumny DUN14' => [fn () => $this->dailyHeader = str_replace(',DUN14,', ',DUN,', self::DAILY_HEADER), 'nie ma kolumn: DUN14'],
            'Daily Data bez kolumny Price' => [fn () => $this->dailyHeader = str_replace(',Price,', ',Cena,', self::DAILY_HEADER), 'nie ma kolumn: Price'],
            // próg liczy pokrycie samym cennikiem CSV (cena zapasowa z Daily Data się nie liczy): 478 + 4 pozycje bez wiersza
            // w cenniku (2201NATXL, S999 ×2, A147) = 482 > 8% z 6013 (481,04)
            'ponad 8% pozycji bez ceny w cenniku CSV' => [fn () => $this->unpricedFiller = 478, '482 z 6013 pozycji Daily Data nie ma ceny w cenniku konta'],
            'krótkie opisy Portwest' => [fn () => $this->descFiller = 900, 'Plik „Opisy Portwest” z portwest.com ma tylko 903 wierszy (oczekiwane co najmniej 1000)'],
            'krótkie opisy Base' => [fn () => $this->baseDescFiller = 50, 'Plik „Opisy Base” z portwest.com ma tylko 51 wierszy (oczekiwane co najmniej 100)'],
            'krótkie normy Portwest' => [fn () => $this->stdsFiller = 900, 'Plik „Normy Portwest” z portwest.com ma tylko 903 wierszy (oczekiwane co najmniej 1000)'],
            'krótkie normy Base' => [fn () => $this->baseStdsFiller = 50, 'Plik „Normy Base” z portwest.com ma tylko 51 wierszy (oczekiwane co najmniej 100)'],
        ];

        foreach ($cases as $case => [$change, $message]) {
            $this->resetFixture();
            $change();
            $connector = $this->connector();
            $yielded = 0;
            try {
                foreach ($connector->products() as $product) {
                    $yielded++;
                }
                $this->fail($case.': przebieg powinien zostać przerwany');
            } catch (B2bFatalException $e) {
                $this->assertStringContainsString($message, $e->getMessage(), $case);
            }
            $this->assertSame(0, $yielded, $case.': żaden model przed sprawdzeniem plików');
        }

        // 477 + 4 = 481 pozycji bez wiersza w cenniku — w granicy 8%
        $this->resetFixture();
        $this->unpricedFiller = 477;
        $this->assertCount(8, iterator_to_array($this->connector()->products(), false));
    }

    public function test_files_are_cut_to_the_pdf_header_and_html_or_foreign_hosts_are_not_files(): void
    {
        $this->fakePortal();
        $connector = $this->connector();
        $card = iterator_to_array($connector->products(), false)[0];

        $sheet = $connector->documentBytes($connector->documents($card)[0]);
        $this->assertSame('application/pdf', $sheet['mime']);
        $this->assertSame('%PDF-1.4 karta 2201 PL', $sheet['bytes']);
        $image = $connector->image($card);
        $this->assertSame('image/jpeg', $image?->mime);
        $this->assertSame(self::CDN.'/styles1100px/2201NAT.jpg', $image?->sourceUrl);

        $client = $this->client();
        foreach ([
            'https://documents.portwest.com/datasheet.php?style=BAD&lang=PL&itemcol=BADBKR&facility=920' => 'nie jest PDF-em',
            'https://documents.portwest.com/datasheet.php?style=HTML&lang=PL&itemcol=HTMLBKR&facility=920' => 'nie wydał pliku',
            'https://example.com/datasheet.php?style=2201' => 'spoza Portwest',
            'http://documents.portwest.com/datasheet.php?style=2201' => 'spoza Portwest',
            'https://documents.portwest.com:8443/datasheet.php?style=2201' => 'spoza Portwest',
            'https://portwest.com/account/marketinglinks' => 'spoza Portwest',
        ] as $url => $message) {
            try {
                $client->fileBytes($url);
                $this->fail('plik '.$url.' nie powinien zostać przyjęty');
            } catch (RuntimeException $e) {
                $this->assertNotInstanceOf(B2bFatalException::class, $e);
                $this->assertStringContainsString($message, $e->getMessage(), $url);
            }
        }
        $this->assertNull($connector->imageAt(self::CDN.'/marketing_files/desc/descPL.csv'));
        $this->assertTrue(Http::recorded(static fn (Request $r): bool => str_contains($r->url(), 'example.com') || str_starts_with($r->url(), 'http://') || str_contains($r->url(), ':8443'))->isEmpty());
        $this->expectExceptionMessage('nie jest PDF-em');
        $connector->documentBytes(new B2bRemoteDocument('x', 'https://documents.portwest.com/declaration_eu.php?style=BAD&lang=PL&itemcol=BADBKR'));
    }

    public function test_sync_creates_the_model_card_with_size_rows_shop_card_documents_images_and_a_second_run_changes_nothing(): void
    {
        Storage::fake('public');
        Storage::fake('local');
        $this->fakePortal();

        $result = app(B2bAccountSyncRunner::class)->run($this->account(), delayMs: 0, withImages: true);

        $this->assertSame(7, $result['created'], implode(' | ', $result['errors']));
        $this->assertSame(0, Product::query()->where('sku', 'S999')->count());
        $this->assertSame(0, Product::query()->where('sku', 'PO01')->count());
        $card = Product::query()->where('sku', '2201')->sole();
        $this->assertSame('Portwest', $card->manufacturer);
        $this->assertSame('Kombinezon dla przemysłu spożywczego Portwest 2201', $card->name);
        $this->assertStringContainsString('Kombinezon z poliestru i bawełny', (string) $card->description);
        $this->assertStringNotContainsString('English', (string) $card->description);
        $this->assertEqualsCanonicalizing(
            ['2201WHRL', '2201WHRXL', '2201NATL'],
            B2bProductLink::query()->where('product_id', $card->id)->pluck('remote_id')->all(),
        );
        $slot = ProductSourcePrice::query()->where('product_id', $card->id)->sole();
        $this->assertSame('67.50', (string) $slot->purchase_price);
        // portal nie podaje ceny katalogowej — slot dostaje cenę konta (zasada B2bCatalogSync: base ?? net)
        $this->assertSame('67.50', (string) $slot->catalog_price_net);
        $this->assertSame('72.10', (string) $slot->size_price_max);
        $this->assertSame(
            ['Navy Tall (NAT) / L' => '72.10', 'White (WHR) / L' => '67.50', 'White (WHR) / XL' => '67.50'],
            ProductVariant::query()->where('product_id', $card->id)->where('kind', ProductVariant::KIND_SIZE)->orderBy('label')->pluck('purchase_price', 'label')->map(static fn ($p): string => (string) $p)->all(),
        );
        $this->assertSame(
            ['Karta produktu 2201', 'Deklaracja zgodności UE 2201'],
            ProductDocument::query()->where('product_id', $card->id)->orderBy('sort_order')->pluck('title')->all(),
        );
        $this->assertSame(2, ProductImage::query()->where('product_id', $card->id)->count());
        $rows = $this->shopCardRows($card);
        $this->assertContains('Normy | Norma | EN ISO 13688: 2013', $rows);
        $this->assertContains('Normy | Inne normy i oznaczenia | CE', $rows);
        $this->assertContains('Informacje ze sklepu Portwest | Grupa w cenniku | Odzież dla przemysłu spożywczego > Kombinezony', $rows);
        $this->assertSame(
            ['5036108106691', '5036108106708'],
            ProductIdentifier::query()->where('product_id', $card->id)->where('type', ProductIdentifier::TYPE_EAN)->orderBy('value')->pluck('value')->all(),
        );
        $this->assertSame(
            ['15036108106691'],
            ProductIdentifier::query()->where('product_id', $card->id)->where('type', ProductIdentifier::TYPE_PACK_EAN)->pluck('value')->all(),
        );
        $this->assertSame(
            ['2201'],
            ProductIdentifier::query()->where('product_id', $card->id)->where('type', ProductIdentifier::TYPE_MODEL_CODE)->pluck('value')->all(),
        );
        $this->assertSame(
            ['2201NATL', '2201WHRL', '2201WHRXL'],
            ProductIdentifier::query()->where('product_id', $card->id)->where('type', ProductIdentifier::TYPE_SOURCE_CODE)->orderBy('value')->pluck('value')->all(),
        );

        // normy producenta: tylko wiersze „Norma” — bez ANSI i oznakowania CE; poziom EN 388 bez nawiasu
        $gloves = Product::query()->where('sku', 'AP50')->sole();
        $this->assertSame('portwest', $gloves->manufacturer_norms['source']['connector'] ?? null);
        $this->assertContains(['label' => 'EN 388: 2016 + A1: 2018', 'value' => '4X43D'], $gloves->manufacturer_norms['rows'] ?? []);
        $this->assertSame('4X43D', $gloves->manufacturer_norms['en388'] ?? null);
        $norms = (string) json_encode($gloves->manufacturer_norms['rows'] ?? [], JSON_UNESCAPED_UNICODE);
        $this->assertStringNotContainsString('ANSI', $norms);
        $gloveSlot = ProductSourcePrice::query()->where('product_id', $gloves->id)->sole();
        $this->assertSame('12.40', (string) $gloveSlot->purchase_price);
        $hairnet = Product::query()->where('sku', 'A080')->sole();
        $this->assertSame([], $hairnet->manufacturer_norms['rows'] ?? []);

        $cap = Product::query()->where('sku', 'A147')->sole();
        $this->assertSame('10.70', (string) ProductSourcePrice::query()->where('product_id', $cap->id)->sole()->purchase_price);

        $shoe = Product::query()->where('sku', 'B0105')->sole();
        $this->assertSame('Portwest', $shoe->manufacturer);
        $this->assertContains('Informacje ze sklepu Portwest | Marka | Base', $this->shopCardRows($shoe));

        $before = $this->snapshot();
        $second = app(B2bAccountSyncRunner::class)->run($this->account(), delayMs: 0, withImages: true);

        $this->assertSame(0, $second['created'], implode(' | ', $second['errors']));
        $this->assertSame(0, $second['updated'], implode(' | ', $second['errors']));
        $this->assertSame(7, $second['unchanged'], implode(' | ', $second['errors']));
        $this->assertSame($before, $this->snapshot());
    }

    public function test_registry_detects_portwest_by_host_as_the_manufacturer_site(): void
    {
        $registry = app(B2bConnectorRegistry::class);

        $this->assertSame('portwest', $registry->keyForSites(['https://portwest.com/market/']));
        $this->assertSame('Portwest', $registry->label('portwest'));
        $this->assertTrue($registry->requiresPassword('portwest'));
        $this->assertTrue($registry->isManufacturerSite('portwest'));
        $this->assertTrue($registry->groupsSizes('portwest'));
        $this->assertTrue($registry->sendsSizePrices('portwest'));
        $this->assertSame(['brand' => 'Portwest', 'names' => ['Norma']], $registry->shopFieldNormSource('portwest'));

        $account = B2bAccount::query()->create([
            'username' => self::USER, 'password' => 'sekret', 'sites' => ['https://portwest.com/market/'],
        ]);
        $connector = $registry->make($account, 0);

        $this->assertInstanceOf(PortwestB2bConnector::class, $connector);
        foreach ([B2bManufacturerSite::class, B2bShopFieldSource::class, B2bShopFieldNormSource::class, B2bDocumentSource::class, B2bImageGallery::class, B2bGroupsSizes::class, B2bSizePriceSource::class] as $interface) {
            $this->assertInstanceOf($interface, $connector);
        }
    }

    // ---- pomocnicze ----

    private function client(): PortwestB2bClient
    {
        return new PortwestB2bClient(self::USER, self::PASSWORD, 0, static function (int $ms): void {});
    }

    private function connector(): PortwestB2bConnector
    {
        $connector = new PortwestB2bConnector($this->client());
        $connector->login();

        return $connector;
    }

    private function account(): B2bAccount
    {
        return B2bAccount::query()->firstOrCreate(
            ['username' => self::USER],
            ['password' => self::PASSWORD, 'sites' => ['https://portwest.com/market/'], 'connector' => 'portwest', 'sync_images' => true],
        )->fresh();
    }

    /** Pliki portalu jak domyślnie (między przypadkami jednego testu). */
    private function resetFixture(): void
    {
        $this->baseLink = true;
        $this->priceFiller = 5000;
        $this->basePriceFiller = 1000;
        $this->dailyFiller = 5000;
        $this->unpricedFiller = 0;
        $this->baseDailyFiller = 1000;
        $this->descFiller = 1000;
        $this->baseDescFiller = 100;
        $this->stdsFiller = 1000;
        $this->baseStdsFiller = 100;
        $this->currency = 'PLN';
        $this->priceHeader = null;
        $this->dailyHeader = null;
    }

    /**
     * @return list<string>
     */
    private function fieldLines(PortwestB2bConnector $connector, B2bRemoteProduct $product): array
    {
        return array_map(static fn ($f): string => $f->section.' | '.$f->name.' | '.$f->value, $connector->shopFields($product));
    }

    /**
     * @return list<string>
     */
    private function shopCardRows(Product $product): array
    {
        return collect(ProductShopCard::query()->where('product_id', $product->id)->sole()->fields)->flatMap(static fn (array $section): array => array_map(
            static fn (array $row): string => ($section['section'] ?? '').' | '.$row['name'].' | '.$row['value'],
            $section['rows'] ?? [],
        ))->values()->all();
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
            'identifiers' => ProductIdentifier::query()->where('product_id', $p->id)->orderBy('type')->orderBy('value')->pluck('value')->all(),
            'price' => ProductSourcePrice::query()->where('product_id', $p->id)->get(['purchase_price', 'catalog_price_net', 'size_price_max', 'availability'])->toArray(),
        ]])->all();
    }

    // ---- pliki portalu ----

    /**
     * Plik CSV jak w portalu: nagłówek dosłownie (z BOM-em), pola w cudzysłowie tylko gdy trzeba.
     *
     * @param  list<list<string>>  $rows
     */
    private static function csv(string $header, array $rows): string
    {
        $stream = fopen('php://temp', 'w+b');
        self::assertNotFalse($stream);
        fwrite($stream, $header."\n");
        foreach ($rows as $row) {
            fputcsv($stream, $row, ',', '"', '', "\n");
        }
        rewind($stream);
        $content = (string) stream_get_contents($stream);
        fclose($stream);

        return $content;
    }

    /**
     * Cennik konta: „99” = wszystkie wyroby Portwest (z wypełniaczem FILL i modelem PO01 bez oferty sklepu), „12” = Base
     * (z wypełniaczem BFILL). Pozycja B0105GOR37 w obu cennikach (jak w portalu: ta sama cena).
     */
    private function priceList(string $group): string
    {
        if ($group === '12') {
            $rows = [
                ['Classic', 'Półbuty', 'B0105', 'B0105GOR37', 'PLN', '143.50'],
                ['Classic', 'Półbuty', 'B0105', 'B0105GOR38', 'PLN', '148.00'],
            ];
            for ($i = 1; $i <= $this->basePriceFiller; $i++) {
                $rows[] = ['Wypełniacz', 'Wypełniacz', 'BFILL', 'BFILLGOR'.$i, 'PLN', '20.00'];
            }

            return self::csv(self::PRICE_HEADER, $rows);
        }

        $rows = [
            ['Odzież dla przemysłu spożywczego', 'Kombinezony', '2201', '2201WHRL', 'PLN', '67.50'],
            ['Odzież dla przemysłu spożywczego', 'Kombinezony', '2201', '2201WHRXL', 'PLN', '67.50'],
            ['Odzież dla przemysłu spożywczego', 'Kombinezony', '2201', '2201NATL', 'PLN', '72.10'],
            ['Ochrona dłoni', 'Rękawice', 'AP50', 'AP50BKR8', $this->currency, '12.40'],
            ['Ochrona dłoni', 'Rękawice', 'AP50', 'AP50BKR9', 'PLN', '12.40'],
            ['Odzież jednorazowa', 'Siatki na włosy', 'A080', 'A080WHR', 'PLN', '3.10'],
            ['Odzież jednorazowa', 'Siatki na włosy', 'A080', 'A080BKR', 'PLN', '3.10'],
            // model tylko w cenniku — sklep go nie sprzedaje
            ['Odzież robocza', 'Spodnie', 'PO01', 'PO01NAR32', 'PLN', '55.00'],
            ['Classic', 'Półbuty', 'B0105', 'B0105GOR37', 'PLN', '143.50'],
        ];
        for ($i = 1; $i <= $this->priceFiller; $i++) {
            $rows[] = ['Wypełniacz', 'Wypełniacz', 'FILL', 'FILLBKR'.$i, 'PLN', '10.00'];
        }

        return self::csv($this->priceHeader ?? self::PRICE_HEADER, $rows);
    }

    /**
     * @return list<string> komórki wiersza Daily Data w kolejności nagłówka
     */
    private static function dailyRow(string $style, string $item, string $carton, string $price, string $colour, string $size, string $fit, string $name, string $plStock, string $delivery, string $ean, string $image, string $multiple, string $description, ?string $dun = null): array
    {
        return [
            $style, $item, $carton, $price, $colour, $size, $fit, $name,
            '120', $plStock, $delivery, '40', '30', '10', '0.012', '0.45', $ean, $dun ?? ($ean !== '' ? '1'.$ean : ''), '62104000', 'EA',
            $image, $multiple, $description,
        ];
    }

    /**
     * Daily Data Portwest: 2201 w dwóch kolorach (WHR zwykły, NAT „Tall” z nazwą koloru „Navy”) — pierwszy wiersz z BOM-em
     * w kodzie pozycji, NATL z inną ceną w Daily Data niż w cenniku, NATXL bez ceny w obu plikach; AP50 po 12 szt., pierwszy
     * rozmiar bez nazwy koloru; A080 jeden rozmiar w dwóch kolorach z różną wielokrotnością; S999 bez żadnej ceny (pusta
     * i zerowa cena Daily Data); A147 z ceną tylko w Daily Data; wypełniacz FILL.
     */
    private function dailyData(): string
    {
        $img = self::CDN.'/styles1100px/';
        $english = 'English description of the food industry coverall';
        $rows = [
            self::dailyRow('2201', "\u{FEFF}2201WHRL", '18', '67.50', 'White', 'L', 'R', 'Food Industry Coverall', '354', '27/11/2026', '5036108106691', $img.'2201WHR.jpg', '1', $english),
            self::dailyRow('2201', '2201NATL', '18', '80.00', 'Navy', 'L', 'T', 'Food Industry Coverall', '', '', '', $img.'2201NAT.jpg', '1', $english),
            self::dailyRow('2201', '2201WHRXL', '18', '67.50', 'White', 'XL', 'R', 'Food Industry Coverall', '0', '27/11/2026', '5036108106708', $img.'2201WHR.jpg', '1', $english, '503610810670'),
            self::dailyRow('2201', '2201NATXL', '18', 'N/A', 'Navy', 'XL', 'T', 'Food Industry Coverall', '5', '', '5036108106722', $img.'2201NAT.jpg', '1', $english),
            self::dailyRow('AP50', 'AP50BKR8', '144', '12.40', '', '8', 'R', 'Cut Resistant Glove', '1200', '', '5036108300013', $img.'AP50BKR.jpg', '12', 'Glove'),
            self::dailyRow('AP50', 'AP50BKR9', '144', '12.40', 'Black', '9', 'R', 'Cut Resistant Glove', '800', '', '5036108300020', $img.'AP50BKR.jpg', '12', 'Glove'),
            self::dailyRow('A080', 'A080WHR', '1000', '3.10', 'White', '', 'R', 'Hairnet', '50', '', '5036108400010', $img.'A080WHR.jpg', '12', 'Hairnet'),
            self::dailyRow('A080', 'A080BKR', '1000', '3.10', 'Black', '', 'R', 'Hairnet', '40', '', '5036108400027', $img.'A080BKR.jpg', '1', 'Hairnet'),
            self::dailyRow('S999', 'S999BKRM', '50', '', 'Black', 'M', 'R', 'Unpriced Vest', '10', '', '5036108500017', $img.'S999BKR.jpg', '1', 'Vest'),
            self::dailyRow('S999', 'S999BKRL', '50', '0.00', 'Black', 'L', 'R', 'Unpriced Vest', '10', '', '5036108500024', $img.'S999BKR.jpg', '1', 'Vest'),
            // sklep sprzedaje, cennika CSV nie ma — cena konta z kolumny Price
            self::dailyRow('A147', 'A147WHR', '500', '10.70', 'White', '', 'R', 'Disposable Cap', '30', '', '5036108600014', $img.'A147WHR.jpg', '1', 'Cap'),
        ];
        for ($i = 0; $i < $this->dailyFiller; $i++) {
            $item = $i < $this->unpricedFiller ? 'NOPRICEBKR1' : 'FILLBKR1';
            $rows[] = self::dailyRow('FILL', $item, '10', '10.00', 'Black', '1', 'R', 'Filler Item', '1', '', '', '', '1', 'Filler');
        }

        return self::csv($this->dailyHeader ?? self::DAILY_HEADER, $rows);
    }

    /** Daily Data Base: polskie nazwy kolorów i wyrobów, Carton_Qty „N/A”; wypełniacz BFILL. */
    private function dailyDataBase(): string
    {
        $img = self::CDN.'/styles1100px/';
        $rows = [
            self::dailyRow('B0105', 'B0105GOR37', 'N/A', '143.50', 'Szary/Pomarańcz', '37', 'R', 'Półbut Classic', '7', '', '8033546000011', $img.'B0105GOR.jpg', '1', 'Półbut'),
            self::dailyRow('B0105', 'B0105GOR38', 'N/A', '148.00', 'Szary/Pomarańcz', '38', 'R', 'Półbut Classic', '3', '', '8033546000028', $img.'B0105GOR.jpg', '1', 'Półbut'),
        ];
        for ($i = 0; $i < $this->baseDailyFiller; $i++) {
            $rows[] = self::dailyRow('BFILL', 'BFILLGOR1', 'N/A', '20.00', 'Szary', '1', 'R', 'Wypełniacz Base', '1', '', '', '', '1', 'Wypełniacz');
        }

        return self::csv(self::DAILY_HEADER, $rows);
    }

    private function descriptions(bool $base): string
    {
        if ($base) {
            $rows = [['B0105', 'Obuwie', 'Base Classic', 'Base Classic', 'Półbuty ochronne Classic S3', 'Lekki półbut ochronny S3.', 'Podnosek kompozytowy']];
            for ($i = 1; $i <= $this->baseDescFiller; $i++) {
                $rows[] = ['ZB'.$i, 'Obuwie', 'Base', 'Base', 'But spoza oferty', 'Opis.', ''];
            }

            return self::csv(self::DESC_HEADER, $rows);
        }

        $rows = [
            // cechy w kolumnach poza nagłówkiem, pusta komórka na końcu, powtórzona cecha raz
            ['2201', 'Odzież', 'Odzież dla przemysłu spożywczego', 'Odzież dla przemysłu spożywczego', 'Kombinezon dla przemysłu spożywczego ', 'Kombinezon z poliestru i bawełny do zakładów spożywczych.', 'Zatrzaski kryte', 'Bez kieszeni zewnętrznych', 'Gumka w pasie', 'Zatrzaski kryte', ''],
            ['AP50', 'Rękawice', 'Ochrona dłoni', 'Rękawice antyprzecięciowe', 'Rękawice antyprzecięciowe', 'Rękawice z poziomem D odporności na przecięcie.', 'Powlekane nitrylem'],
            // model spoza Daily Data — pominięty
            ['ZZ01', 'Odzież', 'Odzież robocza', 'Odzież robocza', 'Bluza', 'Opis bluzy.', ''],
        ];
        for ($i = 1; $i <= $this->descFiller; $i++) {
            $rows[] = ['ZD'.$i, 'Odzież', 'Odzież robocza', 'Odzież robocza', 'Wyrób spoza oferty', 'Opis.', ''];
        }

        return self::csv(self::DESC_HEADER, $rows);
    }

    private function standards(bool $base): string
    {
        $pad = static fn (array $row): array => array_pad($row, 13, '');
        if ($base) {
            $rows = [$pad(['B0105', '', '', 'EN ISO 20345: 2022 S3 SRC'])];
            for ($i = 1; $i <= $this->baseStdsFiller; $i++) {
                $rows[] = $pad(['ZT'.$i, '', '', 'EN ISO 20345: 2022 S1P']);
            }

            return self::csv(self::STDS_HEADER, $rows);
        }

        $rows = [
            $pad(['AP50', 'CTC', '0075/2085', 'EN ISO 21420: 2020 Dexterity 5', 'EN 388: 2016 + A1: 2018  (4X43D)', 'ANSI/ISEA 105: 2016 CUT Level (A6)']),
            $pad(['A080', '', '', 'CE Cat 1']),
            $pad(['2201', '', '', 'EN ISO 13688: 2013', 'CE', 'EN 1149 -5', 'EN 1149-5', 'BS EN 13034 Type 6', 'IEC 61482-2 ATPV 8 Cal/CM<sup>2</sup>', 'Waterproof &amp; Breathable']),
        ];
        for ($i = 1; $i <= $this->stdsFiller; $i++) {
            $rows[] = $pad(['ZS'.$i, '', '', 'EN ISO 13688: 2013']);
        }

        return self::csv(self::STDS_HEADER, $rows);
    }

    private function marketingLinks(): string
    {
        return '<!DOCTYPE html><html><body><h1>Linki marketingowe</h1><ul>'
            .'<li><a href="https://portwest.com/account/exportCustomerPriceLists/99/A3/PL/A5">All Products</a></li>'
            .($this->baseLink ? '<li><a href="https://portwest.com/account/exportCustomerPriceLists/12/A3/PL/A5">Base</a></li>' : '')
            .'<li><a href="https://portwest.com/account/downloadDailyData?name=LOG_DL_NAME_DAILY_DATA&amp;file=sohPL.csv">Daily Data</a></li>'
            .'<li><a href="https://portwest.com/account/downloadDailyData?name=LOG_DL_NAME_DAILY_DATA_BASE&amp;file=sohPLB.csv">Daily Data Base</a></li>'
            .'<li><a href="'.self::CDN.'/marketing_files/desc/descPL.csv">Opisy</a></li>'
            .'<li><a href="https://example.com/account/exportCustomerPriceLists/7/A3/PL/A5">Obcy</a></li>'
            .'<li><a href="/account/contractPrices">Ceny kontraktowe</a></li>'
            .'</ul></body></html>';
    }

    /** Strona logowania portalu — z hasłem wpisanym w formularz odesłanym w value (tak robi prawdziwy portal). */
    private static function loginPage(string $echoedPassword = ''): string
    {
        return '<!DOCTYPE html><html><head><title>Portwest</title></head><body>'
            .'<form action="https://portwest.com/main/login/" class="form-signin" method="post">'
            .'<input type="text" name="email" value=""><input type="password" name="password" value="'.$echoedPassword.'">'
            .'</form></body></html>';
    }

    // ---- atrapa portalu ----

    private function fakePortal(): void
    {
        Http::fake(Closure::fromCallable([$this, 'portalResponse']));
    }

    private function portalResponse(Request $request): mixed
    {
        $url = $request->url();
        $host = (string) parse_url($url, PHP_URL_HOST);
        $path = (string) parse_url($url, PHP_URL_PATH);
        parse_str((string) parse_url($url, PHP_URL_QUERY), $query);
        $html = ['Content-Type' => 'text/html; charset=UTF-8'];
        $csv = ['Content-Type' => 'text/csv; charset=UTF-8'];

        if ($host === 'portwest.com') {
            if ($path === '/main/login/' && $request->method() === 'POST') {
                $data = $request->data();
                if (($data['email'] ?? null) === self::USER && ($data['password'] ?? null) === self::PASSWORD) {
                    $this->logins++;
                    $session = 'sess'.$this->logins;
                    $this->sessions[] = $session;

                    return Http::response('<!DOCTYPE html><html><body>Market</body></html>', 200, $html + ['Set-Cookie' => 'ci_session='.$session.'; path=/; HttpOnly']);
                }

                return Http::response(self::loginPage((string) ($data['password'] ?? '')), 200, $html);
            }

            preg_match('/ci_session=(\w+)/', $request->header('Cookie')[0] ?? '', $m);
            if (! in_array($m[1] ?? '', $this->sessions, true)) {
                return Http::response(self::loginPage(), 200, $html);
            }
            if ($path === '/account/marketinglinks') {
                return Http::response($this->marketingLinks(), 200, $html);
            }
            if (preg_match('~^/account/exportCustomerPriceLists/(\d+)/~', $path, $g) === 1) {
                if ($this->filesRefused) {
                    return Http::response(self::loginPage(), 200, $html);
                }
                if ($this->expireAtPriceList) {
                    $this->expireAtPriceList = false;
                    $this->sessions = [];

                    return Http::response(self::loginPage(), 200, $html);
                }

                return Http::response($this->priceList($g[1]), 200, $csv);
            }
            if ($path === '/account/downloadDailyData') {
                if ($this->filesRefused) {
                    return Http::response(self::loginPage(), 200, $html);
                }

                return match ($query['file'] ?? '') {
                    'sohPL.csv' => Http::response($this->dailyData(), 200, $csv),
                    'sohPLB.csv' => Http::response($this->dailyDataBase(), 200, $csv),
                    default => Http::response('', 404),
                };
            }

            return Http::response('', 404);
        }

        if ($host === 'd11ak7fd9ypfb7.cloudfront.net') {
            return match (true) {
                $path === '/marketing_files/desc/descPL.csv' => Http::response($this->descriptions(false), 200, $csv),
                $path === '/marketing_files/desc/descbPL.csv' => Http::response($this->descriptions(true), 200, $csv),
                $path === '/marketing_files/stds/stds.csv' => Http::response($this->standards(false), 200, $csv),
                $path === '/marketing_files/stds/base_stds.csv' => Http::response($this->standards(true), 200, $csv),
                str_starts_with($path, '/styles1100px/') => Http::response(self::jpeg($path), 200, ['Content-Type' => 'image/jpeg']),
                default => Http::response('', 404),
            };
        }

        if ($host === 'documents.portwest.com' && in_array($path, ['/datasheet.php', '/declaration_eu.php'], true)) {
            $style = (string) ($query['style'] ?? '');
            if (($query['lang'] ?? '') !== 'PL') {
                return Http::response('', 404);
            }

            return match ($style) {
                // portal bez nagłówka PDF — sam śmieciowy tekst
                'BAD' => Http::response('testmysql', 200, ['Content-Type' => 'application/pdf']),
                'HTML' => Http::response('<!DOCTYPE html><html><body>Błąd</body></html>', 200, $html),
                // prawdziwy portal poprzedza PDF tekstem diagnostycznym
                default => Http::response("testmysql\n%PDF-1.4 ".($path === '/datasheet.php' ? 'karta' : 'deklaracja').' '.$style.' PL', 200, ['Content-Type' => 'application/pdf']),
            };
        }

        return Http::response('', 404);
    }

    private static function jpeg(string $seed): string
    {
        $image = imagecreatetruecolor(1, 1);
        imagesetpixel($image, 0, 0, crc32($seed) & 0xFFFFFF);
        ob_start();
        imagejpeg($image);

        return (string) ob_get_clean();
    }
}
