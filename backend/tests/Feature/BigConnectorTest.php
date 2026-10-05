<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Jobs\TranslateB2bProductTextJob;
use App\Models\B2bAccount;
use App\Models\B2bProductLink;
use App\Models\Product;
use App\Models\ProductDocument;
use App\Models\ProductIdentifier;
use App\Models\ProductImage;
use App\Models\ProductShopCard;
use App\Models\ProductSourcePrice;
use App\Models\ProductVariant;
use App\Models\User;
use App\Services\B2b\B2bAccountSyncRunner;
use App\Services\B2b\B2bConnectorRegistry;
use App\Services\B2b\B2bDocumentSource;
use App\Services\B2b\B2bFatalException;
use App\Services\B2b\B2bForeignLanguageSource;
use App\Services\B2b\B2bGroupsSizes;
use App\Services\B2b\B2bImageGallery;
use App\Services\B2b\B2bKeepsExistingNames;
use App\Services\B2b\B2bManufacturerSite;
use App\Services\B2b\B2bRemoteDocument;
use App\Services\B2b\B2bRemotePrice;
use App\Services\B2b\B2bRemoteProduct;
use App\Services\B2b\B2bShopFieldNormSource;
use App\Services\B2b\B2bShopFieldSource;
use App\Services\B2b\B2bSizePriceSource;
use App\Services\B2b\BigB2bClient;
use App\Services\B2b\BigB2bConnector;
use App\Support\XlsxStreamReader;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;
use PhpOffice\PhpSpreadsheet\Cell\DataType;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use RuntimeException;
use Tests\TestCase;

/**
 * Łącznik www.big-arbeitsschutz.de (DSISoft/SOG ERP, BIG Arbeitsschutz GmbH) na atrapie sklepu (Http::fake). Znaczniki
 * odwzorowują strony zalogowanego konta #34 z 01.10.2026: formularz logowania (performAction=processLogin, personlogin,
 * personpwd) wysłany POST-em na /account.html, odnośnik wylogowania „/logout-performAction-processLogout.html” tylko na
 * stronach konta; „Ihre Preislisten” (/kundenDokumente.html) z tabelą dokumentów (PDF i XLSX „Preisliste_RRRRMMDD”);
 * cennik XLSX z kolumnami sklepu (Artikel liczbą, ceny napisem „1,15 €”, teksty ucięte na 255 znakach); strona wyrobu
 * /item-1-{numer}.html (h1.product-title, div.product-description, span.sales_unit „Abnahmeeinheit: 12 Paar”, bloki norm
 * plg_big_normen-norm z oznaczeniem i dopiskiem, pary stammdaten-name/-value w zakładkach, product-domain, pliki
 * a.artikelDokumente-link „1102_PL_Arkusz_danych_technicznych (814,8 kB)”); artykuł bez strony = 404.
 *
 * Wszystkie dane (numery, ceny, EAN-y, opisy) są SYNTETYCZNE.
 */
final class BigConnectorTest extends TestCase
{
    use RefreshDatabase;

    private const USER = '10001';

    private const PASSWORD = 'dobre-haslo';

    private const COLUMNS = [
        'Artikel', 'Größe', 'Marke', 'Bezeichnung', 'Farbe', 'Status', 'VE_Menge', 'Preis', 'Anbruchpreis', 'Basispreis',
        'Preiseinheit', 'EAN_Artikel', 'EAN_UVP', 'EAN_VE', 'Kleinste_Einheit_mit_EAN', 'Mindestmenge', 'UVP_Menge',
        'Palettenmenge', 'Herstellerartikelnummer', 'Warentarifnummer', 'Ursprungsland', 'Artikelbeschreibung',
        'Marketingtext', 'Material', 'Einsatzgebiete', 'PSA_Kategorie', 'Normen', 'Bild_1_URL', 'Bild_2_URL', 'Bild_3_URL',
        'Bild_4_URL', 'Bild_5_URL', 'Bild_6_URL', 'Hersteller',
    ];

    /** @var list<array<string, string|int>> wiersze cennika */
    private array $rows = [];

    /** @var array<string, string|int> numer artykułu → HTML strony albo kod HTTP */
    private array $pages = [];

    /** @var list<string> nagłówek cennika (testy usuwają kolumnę) */
    private array $columns = self::COLUMNS;

    private bool $session = false;

    private int $logins = 0;

    /** @var list<array<string, mixed>> */
    private array $loginBodies = [];

    /** Sesja wygasa po tylu stronach wyrobów (0 = nigdy). */
    private int $expireAfterPages = 0;

    private int $pageHits = 0;

    /** Sklep po wygaśnięciu sesji odpowiada 404 na strony wyrobów (zamiast strony gościa). */
    private bool $notFoundForGuests = false;

    protected function setUp(): void
    {
        parent::setUp();
        Queue::fake();
    }

    public function test_xlsx_stream_reader_reads_shared_and_inline_strings_numbers_and_gaps(): void
    {
        $sheet = new Spreadsheet;
        $active = $sheet->getActiveSheet();
        $active->setCellValue('A1', 'Artikel');
        $active->setCellValue('C1', 'Größe');
        $active->setCellValue('A2', 1102);
        $active->setCellValueExplicit('B2', 'Leder natur, Drell grün/rot', DataType::TYPE_INLINE);
        $active->setCellValueExplicit('C2', '10', DataType::TYPE_STRING);
        $active->setCellValue('D2', 0.12);
        $path = $this->tempPath();
        IOFactory::createWriter($sheet, 'Xlsx')->save($path);

        $rows = iterator_to_array(XlsxStreamReader::rows($path));
        @unlink($path);

        $this->assertSame(['Artikel', '', 'Größe'], $rows[1]);
        $this->assertSame(['1102', 'Leder natur, Drell grün/rot', '10', '0.12'], $rows[2]);
        $this->assertSame(47, XlsxStreamReader::columnIndex('AV12'));
    }

    public function test_helpers_parse_prices_cut_texts_brands_files_and_price_list_links(): void
    {
        $this->assertSame(1.15, BigB2bConnector::euro('1,15 €'));
        $this->assertSame(1234.5, BigB2bConnector::euro("1.234,50\u{00A0}€"));
        $this->assertNull(BigB2bConnector::euro('1,15 CHF'));
        $this->assertNull(BigB2bConnector::euro('1.15'));

        // eksport liczy nową linię jako CRLF i obcina odstępy z końca uciętego pola
        $this->assertTrue(BigB2bConnector::looksCut(str_repeat('a', 255)));
        $this->assertTrue(BigB2bConnector::looksCut("Zeile eins.\n".str_repeat('b', 243)));
        $this->assertTrue(BigB2bConnector::looksCut(str_repeat('c', 251).' Die'));
        $this->assertFalse(BigB2bConnector::looksCut(str_repeat('d', 251).'.'));
        $this->assertFalse(BigB2bConnector::looksCut('Kurz.'));
        $this->assertSame(['text' => 'Erster Satz ist vollständig und lang genug hier.', 'cut' => true], BigB2bConnector::uncut('Erster Satz ist vollständig und lang genug hier. Zweiter Sat', true));
        $this->assertSame(['text' => "Obermaterial: Nubukleder\nFußbett: Einlegesohle", 'cut' => true], BigB2bConnector::uncut("Obermaterial: Nubukleder\nFußbett: Einlegesohle\nPassende Fußbetten:", true));
        $this->assertSame(['text' => 'Bau, Straßenbau, Behörden/Organisationen, Entsorgungsbetriebe', 'cut' => true], BigB2bConnector::uncut('Bau, Straßenbau, Behörden/Organisationen, Entsorgungsbetriebe, Handw', true));
        $this->assertSame(['text' => '', 'cut' => true], BigB2bConnector::uncut('Ohne Grenze und ohne Ende hier', true));
        $this->assertSame(['text' => 'Ohne Grenze', 'cut' => false], BigB2bConnector::uncut('Ohne Grenze', false));

        $this->assertSame('teXXor', BigB2bConnector::manufacturerOf('teXXor®', 'teXXor® Handschuhe TEST'));
        $this->assertSame('4PROTECT', BigB2bConnector::manufacturerOf('4PROTECT®', '4PROTECT 3D Kniepolster'));
        $this->assertSame('Klever', BigB2bConnector::manufacturerOf('SPG®', 'Klever® Sicherheitsmesser KLEVER TEST'));
        $this->assertSame('Pacific Handy Cutter', BigB2bConnector::manufacturerOf('SPG®', 'Pacific Handy Cutter® Messer S5R'));
        $this->assertSame('SPG', BigB2bConnector::manufacturerOf('SPG®', 'Sicherheitsmesser ohne Marke'));

        $files = BigB2bConnector::chooseDocuments([
            ['title' => '1102_DE_EU_Konformitätserklärung', 'url' => 'https://www.big-arbeitsschutz.de/artikeldokumente/a.pdf'],
            ['title' => '1102_DE_Technisches_Datenblatt', 'url' => 'https://www.big-arbeitsschutz.de/artikeldokumente/b.pdf'],
            ['title' => '1102_PL_Arkusz_danych_technicznych', 'url' => 'https://www.big-arbeitsschutz.de/artikeldokumente/c.pdf'],
            ['title' => '1102_DK_Teknisk_datablad', 'url' => 'https://www.big-arbeitsschutz.de/artikeldokumente/d.pdf'],
            ['title' => '1102_Carelabel_Artwork', 'url' => 'https://www.big-arbeitsschutz.de/artikeldokumente/e.pdf'],
            ['title' => '1102_DE_Groeßenhilfe', 'url' => 'https://www.big-arbeitsschutz.de/artikeldokumente/f.pdf'],
        ]);
        $this->assertSame(
            [['1102_PL_Arkusz_danych_technicznych', ProductDocument::KIND_DATASHEET], ['1102_DE_EU_Konformitätserklärung', ProductDocument::KIND_CERTIFICATE], ['1102_DE_Groeßenhilfe', ProductDocument::KIND_SIZE_CHART]],
            array_map(static fn (array $f): array => [$f['title'], $f['kind']], $files),
        );

        $links = BigB2bClient::parsePriceListLinks($this->documentsPage([
            ['Preisliste_20260901', '01.09.26', 'old.xlsx'],
            ['Preisliste_20260930', '01.10.26', 'new.pdf'],
            ['Preisliste_20260930', '01.10.26', 'new.xlsx'],
        ]));
        $this->assertSame(['Preisliste_20260930', 'Preisliste_20260901'], array_column($links, 'name'));
        $this->assertStringEndsWith('/kundendokumente/new.xlsx', $links[0]['url']);
    }

    public function test_login_posts_the_form_and_wrong_password_is_not_fatal(): void
    {
        $this->addGloves();
        $this->fakeSite();
        $client = $this->client();

        $client->login();

        $this->assertTrue($client->isLoggedIn());
        $this->assertSame(['performAction' => 'processLogin', 'personlogin' => self::USER, 'personpwd' => self::PASSWORD], $this->loginBodies[0]);

        $wrong = new BigB2bClient(self::USER, 'zle-haslo', 0, static function (int $ms): void {});
        try {
            $wrong->login();
            $this->fail('logowanie złym hasłem powinno się nie udać');
        } catch (B2bFatalException $e) {
            $this->fail('złe hasło to nie błąd krytyczny: '.$e->getMessage());
        } catch (RuntimeException $e) {
            $this->assertStringContainsString('sprawdź numer klienta i hasło', $e->getMessage());
        }
    }

    public function test_price_list_and_item_pages_become_cards_with_priced_sizes(): void
    {
        $this->addGloves();
        $this->addShorts();
        $this->addPagelessGloves();
        $this->addKnife();
        $this->addArticle('2000', '', 'teXXor®', 'teXXor® Handschuhe FEHLER', price: '2,00 €');
        $this->pages['2000'] = 500;
        $this->addArticle('9999', 'XL', 'teXXor®', 'teXXor® Jacke ANFRAGE', price: '0,00 €', status: 'auf Anfrage');
        $this->fakeSite();

        $cards = $this->cards();

        // rękawice: rozmiar 11 powtórzony identycznie — raz; minimum ze strony (12), nie z cennika (6)
        $gloves = $cards['BIG-1102'];
        $this->assertSame('1102/9', $gloves->remoteId);
        $this->assertSame('teXXor® Rindkernspaltleder-Handschuhe TEST', $gloves->name);
        $this->assertSame('https://www.big-arbeitsschutz.de/item-1-1102.html', $gloves->sourceUrl);
        $this->assertSame(['1102/9', '1102/10', '1102/11'], array_column($gloves->members, 'remote_id'));
        $this->assertSame([1.15, 1.15, 1.25], array_map(static fn (array $m): float => $m['price']->net, $gloves->members));
        $connector = new BigB2bConnector($this->client());
        $price = $this->priceOf($gloves);
        $this->assertSame(1.15, $price->net);
        $this->assertNull($price->base);
        $this->assertSame('EUR', $price->currency);
        $this->assertSame([12.0, 12.0, 'Paar', false], [$price->order?->min, $price->order?->step, $price->order?->unit, $price->order?->varies]);
        $this->assertSame('Rozmiary: 9, 10, 11', $gloves->variantSummary);
        $this->assertSame(
            "Angenehmes Tragegefühl durch eine leichte Fütterung.\n\nRindkernspaltleder, gefüttert\nTOP-Qualität\n\nEinsatzgebiete: Grobe Arbeiten, z.B. Handwerk",
            $gloves->raw['description'],
        );
        $fields = array_map(static fn (array $f): string => $f[0].' = '.$f[1], array_filter($gloves->raw['fields'], static fn (array $f): bool => $f[1] !== ''));
        $this->assertContains('Norma = EN 388:2016+A1:2018 3143XX', $fields);
        $this->assertContains('Norma = EN ISO 21420:2020', $fields);
        $this->assertContains('Kategoria ŚOI = II', $fields);
        $this->assertContains('Inne oznaczenia = CE-Kennzeichnung', $fields);
        $this->assertContains('Material = Trägermaterial: Rindkernspaltleder; Drell: Polyester', $fields);
        $this->assertContains('Minimum i wielokrotność zamówienia = 12 Paar', $fields);
        // druga cena: przy pełnym kartonie (Preis), osobno dla rozmiaru
        $this->assertSame([0.92, 0.92, 1.0], array_map(static fn (array $m): ?float => $m['price']->carton?->net, $gloves->members));
        $this->assertSame(96.0, $gloves->members[0]['price']->carton?->qty);
        $this->assertSame([0.92, 96.0], [$price->carton?->net, $price->carton?->qty]);
        $this->assertNotContains('Zolltarif = 42032910000', $fields);
        $this->assertSame(['1102_PL_Arkusz_danych_technicznych', '1102_DE_EU_Konformitätserklärung'], array_map(static fn ($d) => $d->title, $this->connectorDocuments($gloves)));
        $this->assertSame(['https://www.big-arbeitsschutz-static.de/webshop/1102_s.jpg', 'https://www.big-arbeitsschutz-static.de/webshop/1102_Handr%C3%BCcken_s.jpg', 'https://www.big-arbeitsschutz-static.de/webshop/1102_brak_s.jpg'], $gloves->raw['images']);
        // PDF wydany jako application/octet-stream — typ z treści (zapis plików przyjmuje tylko znane typy)
        $this->assertSame('application/pdf', $connector->documentBytes($this->connectorDocuments($gloves)[0])['mime']);
        // zdjęcie z cennika, którego serwer nie ma (404) — pominięte bez błędu
        $this->assertNull($connector->imageAt('https://www.big-arbeitsschutz-static.de/webshop/1102_brak_s.jpg'));
        $this->assertSame('image/jpeg', $connector->imageAt('https://www.big-arbeitsschutz-static.de/webshop/1102_s.jpg')?->mime);
        $codes = array_map(static fn ($i): string => $i->type.'='.$i->value.($i->label !== null ? '/'.$i->label : ''), $gloves->identifiers ?? []);
        $this->assertContains(ProductIdentifier::TYPE_EAN.'=4000000000010/10', $codes);
        $this->assertContains(ProductIdentifier::TYPE_PACK_EAN.'=4000000000910/10', $codes);
        $this->assertContains(ProductIdentifier::TYPE_MANUFACTURER_CODE.'=1102', $codes);
        $this->assertSame('teXXor', $connector->manufacturer($gloves));

        // szorty: cena cennikowa i rabat, rozmiar „auf Anfrage” za 0,00 € poza kartą, strona bez „Abnahmeeinheit” = po sztuce
        $shorts = $cards['BIG-3048'];
        $this->assertSame(['3048/S', '3048/M'], array_column($shorts->members, 'remote_id'));
        $price = $this->priceOf($shorts);
        $this->assertSame([50.4, 63.0, 20.0], [$price->net, $price->base, $price->discountPercent]);
        $this->assertSame(1.0, $price->order?->min);
        // ta sama cena przy pełnym kartonie — drugiej ceny nie ma (pole czyszczone)
        $this->assertNotNull($price->carton);
        $this->assertNull($price->carton->net);
        $this->assertSame('4PROTECT', $shorts->raw['manufacturer']);
        $this->assertSame('Auslauf: M', $shorts->availability);

        // bez strony w sklepie (404): opis z cennika przycięty do pełnego zdania, normy bez poziomów, minimum z cennika
        $pageless = $cards['BIG-1113'];
        $this->assertNull($pageless->sourceUrl);
        $this->assertSame([], $pageless->members);
        $this->assertSame('1113/10', $pageless->remoteId);
        $this->assertStringStartsWith('Angenehmes Tragegefühl. Guter Schutz durch die verstärkte Innenhand.', $pageless->raw['description']);
        $this->assertStringNotContainsString('Abgeschnitt', $pageless->raw['description']);
        $fields = array_map(static fn (array $f): string => $f[0].' = '.$f[1], $pageless->raw['fields']);
        $this->assertContains('Norma = EN 420:2003+A1:2009', $fields);
        $this->assertSame(12.0, $this->priceOf($pageless)->order?->min);

        // nóż: producent z nazwy (SPG to marka handlowa), kod producenta pozycji, numer artykułu jako kod sklepu
        $knife = $cards['BIG-7603'];
        $this->assertSame('Klever', $knife->raw['manufacturer']);
        $codes = array_map(static fn ($i): string => $i->type.'='.$i->value.($i->label !== null ? '/'.$i->label : ''), $knife->identifiers ?? []);
        $this->assertContains(ProductIdentifier::TYPE_MANUFACTURER_CODE.'=PLS-20Y/gelb', $codes);
        $this->assertContains(ProductIdentifier::TYPE_SOURCE_CODE.'=7603', $codes);

        // strona z błędem: artykuł wstrzymany; same zera: pominięty
        $this->assertSame('skipped', $cards['2000']->raw['status']);
        $this->assertStringContainsString('strona artykułu nieodczytana', $cards['2000']->raw['reason']);
        $this->assertSame('skipped', $cards['9999']->raw['status']);

        $summary = implode("\n", $this->lastConnector->runSummary());
        $this->assertStringContainsString('Lista BIG: cennik Preisliste_20260930 z 01.10.26', $summary);
        $this->assertStringContainsString('Powtórzone identyczne wiersze cennika (pominięte): 1', $summary);
        $this->assertStringContainsString('3048/7XL (auf Anfrage)', $summary);
        $this->assertStringContainsString('Artykuły bez strony w sklepie (karta z samego cennika, normy bez poziomów): 1, np. 1113', $summary);
        $this->assertStringContainsString('Strony nieodczytane (artykuł wstrzymany do następnego przebiegu): 1, np. 2000', $summary);
    }

    public function test_different_order_minimum_between_sizes_varies(): void
    {
        $this->addArticle('1200', '9', 'teXXor®', 'teXXor® Handschuhe MIX', minimum: 12);
        $this->addArticle('1200', '10', 'teXXor®', 'teXXor® Handschuhe MIX', minimum: 6);
        $this->fakeSite();

        $card = $this->cards()['BIG-1200'];

        $this->assertTrue($this->priceOf($card)->order?->varies);
    }

    public function test_broken_price_list_stops_the_run(): void
    {
        $this->addGloves();
        $this->columns = array_values(array_diff(self::COLUMNS, ['Anbruchpreis']));
        $this->fakeSite();
        try {
            $this->cards();
            $this->fail('cennik bez kolumny Anbruchpreis powinien przerwać przebieg');
        } catch (B2bFatalException $e) {
            $this->assertStringContainsString('bez kolumn: Anbruchpreis', $e->getMessage());
        }

        $this->columns = self::COLUMNS;
        $this->rows = [];
        $this->addArticle('1300', '9', 'teXXor®', 'teXXor® Handschuhe CHF', price: '1,15 CHF');
        try {
            $this->cards();
            $this->fail('cena w innej walucie powinna przerwać przebieg');
        } catch (B2bFatalException $e) {
            $this->assertStringContainsString('cena nie w euro', $e->getMessage());
        }

        // domyślny strażnik długości cennika
        $this->rows = [];
        $this->addGloves();
        $connector = new BigB2bConnector($this->client());
        $this->expectException(B2bFatalException::class);
        $this->expectExceptionMessage('ucięty plik albo zmiana konta');
        iterator_to_array($connector->products(), false);
    }

    public function test_many_articles_without_a_page_stop_the_run(): void
    {
        for ($i = 0; $i < 25; $i++) {
            $this->addArticle((string) (5000 + $i), '42', 'RUNNEX®', 'RUNNEX® Schuh '.$i);
        }
        $this->fakeSite();

        $this->expectException(B2bFatalException::class);
        $this->expectExceptionMessage('20 pierwszych artykułów bez strony');
        $this->cards();
    }

    public function test_expired_session_is_renewed_once(): void
    {
        $this->addGloves();
        $this->addShorts();
        $this->expireAfterPages = 1;
        $this->fakeSite();

        $cards = $this->cards();

        $this->assertSame('ok', $cards['BIG-3048']->raw['status']);
        $this->assertGreaterThanOrEqual(2, $this->logins);
    }

    public function test_not_found_page_without_session_is_an_expired_session_not_a_missing_page(): void
    {
        $this->addGloves();
        $this->addShorts();
        $this->expireAfterPages = 1;
        $this->notFoundForGuests = true;
        $this->fakeSite();

        $cards = $this->cards();

        $this->assertSame('https://www.big-arbeitsschutz.de/item-1-3048.html', $cards['BIG-3048']->sourceUrl);
        $this->assertSame('Hervorragender Tragekomfort.', $cards['BIG-3048']->raw['description']);
        $this->assertGreaterThanOrEqual(2, $this->logins);
    }

    public function test_xlsx_stream_reader_skips_phonetic_runs_and_decodes_escaped_characters(): void
    {
        $path = $this->tempPath();
        $zip = new \ZipArchive;
        $zip->open($path, \ZipArchive::CREATE);
        $zip->addFromString('xl/sharedStrings.xml', '<?xml version="1.0" encoding="UTF-8"?><sst xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main">'
            .'<si><t>A</t><rPh sb="0" eb="1"><t>x</t></rPh></si>'
            .'<si><r><t>B</t></r><rPh><t>y</t></rPh><rPh><t>z</t></rPh><phoneticPr fontId="1"/></si>'
            .'<si><t>Leder_x000D_</t></si><si><t>a_x005F_x000D_b</t></si></sst>');
        $zip->addFromString('xl/worksheets/sheet1.xml', '<?xml version="1.0" encoding="UTF-8"?><worksheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main"><sheetData>'
            .'<row r="1"><c r="A1" t="s"><v>0</v></c><c r="B1" t="s"><v>1</v></c><c r="C1" t="s"><v>2</v></c><c r="D1" t="s"><v>3</v></c>'
            .'<c r="E1" t="inlineStr"><is><t>C_x000A_D</t><rPh><t>q</t></rPh></is></c></row></sheetData></worksheet>');
        $zip->close();

        $rows = iterator_to_array(XlsxStreamReader::rows($path));
        @unlink($path);

        $this->assertSame(['A', 'B', "Leder\r", 'a_x000D_b', "C\nD"], $rows[1]);
    }

    public function test_sync_creates_cards_with_size_rows_norms_documents_images_translation_and_a_second_run_changes_nothing(): void
    {
        Storage::fake('public');
        Storage::fake('local');
        $this->addGloves();
        $this->addPagelessGloves();
        $this->fakeSite();

        $result = app(B2bAccountSyncRunner::class)->run($this->account(), delayMs: 0, withImages: true, connector: $this->connector());

        $this->assertSame(2, $result['created'], implode(' | ', $result['errors']));
        $card = Product::query()->where('sku', 'BIG-1102')->sole();
        $this->assertSame('teXXor', $card->manufacturer);
        $this->assertSame('EUR', $card->currency);
        $this->assertEqualsCanonicalizing(['1102/9', '1102/10', '1102/11'], B2bProductLink::query()->where('product_id', $card->id)->pluck('remote_id')->all());
        $slot = ProductSourcePrice::query()->where('product_id', $card->id)->sole();
        $this->assertSame('1.15', (string) $slot->purchase_price);
        $this->assertSame('1.25', (string) $slot->size_price_max);
        $this->assertSame('12.00', number_format((float) $slot->order_min_qty, 2, '.', ''));
        $this->assertSame('Paar', $slot->order_unit);
        $this->assertSame(['0.92', 96.0], [(string) $slot->carton_price_net, $slot->carton_qty]);
        $this->assertSame(
            ['10' => '1.15', '11' => '1.25', '9' => '1.15'],
            ProductVariant::query()->where('product_id', $card->id)->where('kind', ProductVariant::KIND_SIZE)->orderBy('label')->pluck('purchase_price', 'label')->map(static fn ($p): string => (string) $p)->all(),
        );
        $this->assertSame(
            ['10' => '0.92', '11' => '1.00', '9' => '0.92'],
            ProductVariant::query()->where('product_id', $card->id)->where('kind', ProductVariant::KIND_SIZE)->orderBy('label')->pluck('carton_price_net', 'label')->map(static fn ($p): string => (string) $p)->all(),
        );
        $this->assertSame('big', $card->manufacturer_norms['source']['connector'] ?? null);
        $this->assertContains(['label' => 'EN 388:2016+A1:2018', 'value' => '3143XX'], $card->manufacturer_norms['rows'] ?? []);
        $this->assertSame(['1102_PL_Arkusz_danych_technicznych', '1102_DE_EU_Konformitätserklärung'], ProductDocument::query()->where('product_id', $card->id)->orderBy('sort_order')->pluck('title')->all());
        $this->assertSame(2, ProductImage::query()->where('product_id', $card->id)->count());
        $this->assertSame([], $result['errors']);
        $this->assertContains('1102', ProductIdentifier::query()->where('product_id', $card->id)->where('type', ProductIdentifier::TYPE_MANUFACTURER_CODE)->pluck('value')->all());
        $rows = collect(ProductShopCard::query()->where('product_id', $card->id)->sole()->fields)->flatMap(static fn (array $section): array => array_map(
            static fn (array $row): string => ($section['section'] ?? '').' | '.$row['name'].' | '.$row['value'],
            $section['rows'] ?? [],
        ))->all();
        $this->assertContains('Informacje ze sklepu BIG | Norma | EN 388:2016+A1:2018 3143XX', $rows);
        // sklep po niemiecku: opis i nazwa nowej karty idą do tłumaczenia
        Queue::assertPushed(TranslateB2bProductTextJob::class, static fn (TranslateB2bProductTextJob $job): bool => $job->productId === (int) $card->id && $job->translateName);
        $this->assertSame('BIG-1113', Product::query()->where('sku', 'BIG-1113')->value('sku'));

        $before = $this->snapshot();
        $second = app(B2bAccountSyncRunner::class)->run($this->account(), delayMs: 0, withImages: true, connector: $this->connector());

        $this->assertSame(0, $second['created'], implode(' | ', $second['errors']));
        $this->assertSame(0, $second['updated'], implode(' | ', $second['errors']));
        $this->assertSame(2, $second['unchanged'], implode(' | ', $second['errors']));
        $this->assertSame($before, $this->snapshot());
    }

    public function test_product_card_api_shows_both_prices_for_the_source_and_each_size(): void
    {
        Storage::fake('public');
        Storage::fake('local');
        $this->seed(RolesAndPermissionsSeeder::class);
        $this->addGloves();
        $this->fakeSite();
        app(B2bAccountSyncRunner::class)->run($this->account(), delayMs: 0, withImages: false, connector: $this->connector());
        $card = Product::query()->where('sku', 'BIG-1102')->sole();
        Sanctum::actingAs(User::factory()->withRole('admin')->create());

        $response = $this->getJson('/api/products/'.$card->id)->assertOk();

        $slot = collect($response->json('source_prices'))->firstWhere('source_key', ProductSourcePrice::b2bKey((int) $this->account()->id));
        $this->assertSame(['1.15', '0.92', 96], [$slot['purchase_price'], $slot['carton_price_net'], $slot['carton_qty']]);
        $this->assertSame(
            ['9' => '0.92', '10' => '0.92', '11' => '1.00'],
            collect($response->json('variants.items'))->mapWithKeys(static fn (array $v): array => [$v['label'] => $v['carton_price_net']])->all(),
        );
    }

    public function test_registry_detects_big_by_host_as_a_site_of_its_own_brands(): void
    {
        $registry = app(B2bConnectorRegistry::class);

        $this->assertSame('big', $registry->keyForSites(['https://www.big-arbeitsschutz.de/account-performAction-login.html']));
        $this->assertSame('BIG Arbeitsschutz', $registry->label('big'));
        $this->assertTrue($registry->requiresPassword('big'));
        $this->assertTrue($registry->isManufacturerSite('big'));
        $this->assertTrue($registry->groupsSizes('big'));
        $this->assertTrue($registry->sendsSizePrices('big'));
        $this->assertSame(['brand' => 'teXXor', 'names' => ['Norma'], 'brands' => ['teXXor', '4PROTECT', 'RUNNEX']], $registry->shopFieldNormSource('big'));

        $account = B2bAccount::query()->create([
            'username' => self::USER, 'password' => 'sekret', 'sites' => ['https://www.big-arbeitsschutz.de/account-performAction-login.html'],
        ]);
        $connector = $registry->make($account, 150);

        $this->assertInstanceOf(BigB2bConnector::class, $connector);
        foreach ([B2bManufacturerSite::class, B2bShopFieldSource::class, B2bShopFieldNormSource::class, B2bDocumentSource::class, B2bImageGallery::class, B2bGroupsSizes::class, B2bSizePriceSource::class, B2bForeignLanguageSource::class, B2bKeepsExistingNames::class] as $interface) {
            $this->assertInstanceOf($interface, $connector);
        }
        $client = (new \ReflectionProperty($connector, 'client'))->getValue($connector);
        $this->assertSame(BigB2bConnector::MIN_DELAY_MS, (new \ReflectionProperty($client, 'delayMs'))->getValue($client));
    }

    // ---- pomocnicze ----

    private ?BigB2bConnector $lastConnector = null;

    private function client(): BigB2bClient
    {
        return new BigB2bClient(self::USER, self::PASSWORD, 0, static function (int $ms): void {});
    }

    private function connector(): BigB2bConnector
    {
        return $this->lastConnector = new BigB2bConnector($this->client(), minArticles: 1, minRows: 1);
    }

    /**
     * @return array<string, B2bRemoteProduct> SKU → karta
     */
    private function cards(): array
    {
        $connector = $this->connector();
        $connector->login();
        $out = [];
        foreach ($connector->products() as $product) {
            $out[$product->sku] = $product;
        }

        return $out;
    }

    private function priceOf(B2bRemoteProduct $product): B2bRemotePrice
    {
        $price = (new BigB2bConnector($this->client()))->price($product);
        $this->assertNotNull($price);

        return $price;
    }

    /**
     * @return list<B2bRemoteDocument>
     */
    private function connectorDocuments(B2bRemoteProduct $product): array
    {
        return (new BigB2bConnector($this->client()))->documents($product);
    }

    private function account(): B2bAccount
    {
        return B2bAccount::query()->firstOrCreate(
            ['username' => self::USER],
            ['password' => self::PASSWORD, 'sites' => ['https://www.big-arbeitsschutz.de/account-performAction-login.html'], 'connector' => 'big', 'sync_images' => true],
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
            'variants' => ProductVariant::query()->where('product_id', $p->id)->orderBy('label')->get(['label', 'purchase_price', 'carton_price_net', 'availability'])->toArray(),
            'shop_card' => ProductShopCard::query()->where('product_id', $p->id)->value('fields'),
            'images' => ProductImage::query()->where('product_id', $p->id)->orderBy('sort_order')->pluck('source_url')->all(),
            'documents' => ProductDocument::query()->where('product_id', $p->id)->orderBy('sort_order')->pluck('source_url')->all(),
            'price' => ProductSourcePrice::query()->where('product_id', $p->id)->get(['purchase_price', 'catalog_price_net', 'size_price_max', 'availability', 'order_min_qty', 'carton_price_net', 'carton_qty'])->toArray(),
        ]])->all();
    }

    private function addGloves(): void
    {
        foreach ([['9', '1,15 €', '0,92 €'], ['10', '1,15 €', '0,92 €'], ['11', '1,25 €', '1,00 €'], ['11', '1,25 €', '1,00 €']] as $i => [$size, $anbruch, $carton]) {
            $this->addArticle('1102', $size, 'teXXor®', 'teXXor® Rindkernspaltleder-Handschuhe TEST', price: $anbruch, carton: $carton, ve: '96 Paar', unit: 'Paar', minimum: 6,
                ean: '40000000000'.str_pad($size, 2, '0', STR_PAD_LEFT), packEan: '40000000009'.str_pad($size, 2, '0', STR_PAD_LEFT),
                images: ['https://www.big-arbeitsschutz-static.de/webshop/1102_s.jpg', 'https://www.big-arbeitsschutz-static.de/webshop/1102_Handrücken_s.jpg', 'https://www.big-arbeitsschutz-static.de/webshop/1102_brak_s.jpg'],
                marketing: 'Angenehmes Tragegefühl durch eine leichte Fütterung.', norms: 'EN ISO 21420:2020, EN 388:2016+A1:2018');
        }
        $this->pages['1102'] = $this->itemPage(
            title: "teXXor® Rindkernspaltleder-Handschuhe\nTEST",
            description: 'Angenehmes Tragegefühl durch eine leichte Fütterung.',
            salesUnit: '12 Paar',
            psa: 'II',
            norms: [['EN&nbsp;388:2016+A1:2018', '<span style="white-space:nowrap">3143XX</span>'], ['EN&nbsp;ISO&nbsp;21420:2020', ''], ['CE-Kennzeichnung', '']],
            material: [['Material', 'Trägermaterial: Rindkernspaltleder<br />Drell: <a href="/document-1-wp607.html">Polyester</a>']],
            features: 'Rindkernspaltleder, gefüttert<br />TOP-Qualität',
            stammdaten: [['Zolltarif', '42032910000'], ['Ursprungsland', 'Indien']],
            domain: "Grobe Arbeiten,\nz.B. Handwerk",
            documents: ['1102_DE_EU_Konformitätserklärung (760,8 kB)', '1102_PL_Arkusz_danych_technicznych (814,8 kB)', '1102_DK_Teknisk_datablad (812,9 kB)', '1102_EN_Technical_Data_Sheet (812,7 kB)'],
        );
    }

    private function addShorts(): void
    {
        foreach ([['S', '50,40 €', ''], ['M', '50,40 €', 'Auslauf'], ['7XL', '0,00 €', 'auf Anfrage']] as [$size, $price, $status]) {
            $this->addArticle('3048', $size, '4PROTECT®', '4PROTECT® Workwear Shorts TEST', price: $price, carton: $price, base: '63', ve: '10 Stück', unit: 'Stück', minimum: 1, status: $status);
        }
        $this->pages['3048'] = $this->itemPage(title: '4PROTECT® Workwear Shorts', description: 'Hervorragender Tragekomfort.', salesUnit: null, features: 'Hervorragender Tragekomfort.');
    }

    private function addPagelessGloves(): void
    {
        $marketing = 'Angenehmes Tragegefühl. Guter Schutz durch die verstärkte Innenhand. '.str_repeat('Weitere Eigenschaften des Handschuhs werden hier beschrieben ', 3).'Abgeschnitt';
        $this->addArticle('1113', '10', 'teXXor®', 'teXXor® Möbelleder-Handschuhe TEST', price: '1,31 €', carton: '1,05 €', ve: '120 Paar', unit: 'Paar', minimum: 12,
            marketing: mb_substr($marketing, 0, 255), norms: 'EN 420:2003+A1:2009', images: ['https://www.big-arbeitsschutz-static.de/webshop/1113_s.jpg']);
        $this->pages['1113'] = 404;
    }

    private function addKnife(): void
    {
        foreach ([['gelb', 'PLS-20Y'], ['orange', 'PLS-20G']] as [$colour, $code]) {
            $this->addArticle('7603', $colour, 'SPG®', 'Klever® Sicherheitsmesser KLEVER TEST', price: '2,33 €', carton: '2,08 €', ve: '500 Stück', unit: 'Stück', minimum: 10, maker: $code, colour: $colour, status: '-');
        }
        $this->pages['7603'] = $this->itemPage(title: 'Klever® Sicherheitsmesser', description: 'Sicherheitsmesser mit verdeckter Klinge.', salesUnit: '10 Stück');
    }

    /**
     * @param  list<string>  $images
     */
    private function addArticle(
        string $number,
        string $size,
        string $brand,
        string $name,
        string $price = '10,00 €',
        string $carton = '',
        string $base = '0',
        string $ve = '10 Paar',
        string $unit = 'Paar',
        int $minimum = 1,
        string $status = '',
        string $ean = '',
        string $packEan = '',
        string $maker = '',
        string $colour = 'schwarz',
        string $marketing = 'Text.',
        string $norms = '',
        array $images = [],
    ): void {
        $this->rows[] = [
            'Artikel' => (int) $number, 'Größe' => $size, 'Marke' => $brand, 'Bezeichnung' => $name, 'Farbe' => $colour,
            'Status' => $status, 'VE_Menge' => $ve, 'Preis' => $carton !== '' ? $carton : $price, 'Anbruchpreis' => $price,
            'Basispreis' => (int) $base, 'Preiseinheit' => $unit, 'EAN_Artikel' => $ean, 'EAN_UVP' => '', 'EAN_VE' => $packEan,
            'Kleinste_Einheit_mit_EAN' => 'Artikel', 'Mindestmenge' => $minimum, 'UVP_Menge' => $minimum, 'Palettenmenge' => 1000,
            'Herstellerartikelnummer' => $maker, 'Warentarifnummer' => 42032910, 'Ursprungsland' => 'China',
            'Artikelbeschreibung' => '', 'Marketingtext' => $marketing, 'Material' => 'Leder', 'Einsatzgebiete' => 'Bau',
            'PSA_Kategorie' => 'II', 'Normen' => $norms,
            'Bild_1_URL' => $images[0] ?? '', 'Bild_2_URL' => $images[1] ?? '', 'Bild_3_URL' => $images[2] ?? '', 'Bild_4_URL' => '',
            'Bild_5_URL' => '', 'Bild_6_URL' => '', 'Hersteller' => 'BIG Arbeitsschutz GmbH',
        ];
    }

    private function xlsx(): string
    {
        $sheet = new Spreadsheet;
        $active = $sheet->getActiveSheet();
        $active->setTitle('Preisliste');
        foreach ($this->columns as $col => $name) {
            $active->setCellValue([$col + 1, 1], $name);
        }
        foreach ($this->rows as $r => $row) {
            foreach ($this->columns as $col => $name) {
                $value = $row[$name];
                if (is_int($value)) {
                    $active->setCellValue([$col + 1, $r + 2], $value);
                } elseif ($value !== '') {
                    $active->setCellValueExplicit([$col + 1, $r + 2], $value, DataType::TYPE_STRING);
                }
            }
        }
        $path = $this->tempPath();
        IOFactory::createWriter($sheet, 'Xlsx')->save($path);
        $bytes = (string) file_get_contents($path);
        @unlink($path);

        return $bytes;
    }

    private function tempPath(): string
    {
        return sys_get_temp_dir().DIRECTORY_SEPARATOR.'big-test-'.bin2hex(random_bytes(6)).'.xlsx';
    }

    /**
     * @param  list<array{0: string, 1: string, 2: string}>  $rows  nazwa, data, plik
     */
    private function documentsPage(array $rows): string
    {
        $html = '<table><tr><th>Dokument / Nr.</th><th>Datum</th><th>Download</th></tr>';
        foreach ($rows as [$name, $date, $file]) {
            $html .= '<tr class="tableRow"><td class="tableCell column-key">'."\n".$name."\n".'</td><td class="tableCell column-datum">'.$date.'</td>'
                .'<td class="tableCell column-download"><a href="https://www.big-arbeitsschutz.de/kundendokumente/'.$file.'" target="_blank">'.strtoupper(pathinfo($file, PATHINFO_EXTENSION)).'</a></td></tr>';
        }

        return $this->layout($html.'</table>', true);
    }

    private function layout(string $content, bool $account): string
    {
        $menu = $account
            ? '<a rel="nofollow" href="/logout-performAction-processLogout.html" class="button logoutButton">Abmelden</a>'
            : '<form action="/account.html" method="post" class="loginForm"><input type="hidden" name="performAction" value="processLogin" /></form>';

        return '<!DOCTYPE html><html lang="de"><head><meta charset="utf-8"><title>BIG</title></head><body><div class="header">'.$menu.'</div>'
            .'<div class="pageContentMiddle">'.$content.'</div></body></html>';
    }

    /**
     * @param  list<array{0: string, 1: string}>  $norms  oznaczenie, dopisek (HTML)
     * @param  list<array{0: string, 1: string}>  $material
     * @param  list<array{0: string, 1: string}>  $stammdaten
     * @param  list<string>  $documents
     */
    private function itemPage(
        string $title,
        string $description,
        ?string $salesUnit,
        string $psa = '',
        array $norms = [],
        array $material = [],
        string $features = '',
        array $stammdaten = [],
        string $domain = '',
        array $documents = [],
    ): string {
        $pairs = static fn (array $rows): string => implode('', array_map(
            static fn (array $r): string => '<div class="stammdaten-row"><div class="stammdaten-name">'.$r[0].'</div><div class="stammdaten-value">'.$r[1].'</div></div>',
            $rows,
        ));
        $normHtml = implode('', array_map(
            static fn (array $n): string => '<div class="plg_big_normen-norm plg_big_normen-norm-x"><table><tr><td class="plg_big_normen-bezeichnung">'."\n".$n[0]."\n".'</td></tr>'
                .'<tr><td class="plg_big_normen-icon-cell"><div class="plg_big_normen-icon"><img src="https://www.big-arbeitsschutz-static.de/webshop/EN.png" /></div></td></tr>'
                .'<tr><td class="plg_big_normen-zusatz">'."\n".$n[1]."\n".'</td></tr></table></div></span>',
            $norms,
        ));
        $docs = implode('', array_map(
            static fn (string $d, int $i): string => '<li><a href="https://www.big-arbeitsschutz.de/artikeldokumente/01100001102++++0000'.$i.'.pdf" class="artikelDokumente-link" target="_blank"><svg class="pdfIcon"><use xlink:href="/sprite.svg#file-pdf"></use></svg>'."\n".$d."\n".'</a></li>',
            $documents,
            array_keys($documents),
        ));

        return $this->layout(
            '<div class="product-container"><div class="product-detail"><h1 class="product-title">'."\n".$title."\n".'</h1>'
            .'<div class="product-itemnumber">Artikel-Nr.: 1</div><div class="product-description">'.$description.'</div>'
            .'<div class="additionalLines">'.($salesUnit !== null ? '<span class="sales_unit additionalLine" title="Die Bestellmenge muss ein Vielfaches von '.$salesUnit.' sein">'."\nAbnahmeeinheit: ".$salesUnit.'<br /></span>' : '')
            .'<span class="qty_per_unit additionalLine">Karton &agrave; 96 Paar<br /></span></div></div></div>'
            .'<div id="Normen" class="tabcontent"><div class="plg_big_normen"><div class="heading">Normen</div>'
            .($psa !== '' ? '<div class="plg_big_normen-psa">PSA-Kategorie: '.$psa.'</div>' : '')
            .'<div class="plg_big_normen-list">'.$normHtml.'</div></div></div>'
            .'<div id="Material" class="tabcontent">'.$pairs($material).'</div>'
            .'<div id="Eigenschaften" class="tabcontent">'.$pairs([['Verpackungseinheit', '96 Paar<br />']]).($features !== '' ? $pairs([['Eigenschaften', $features]]) : '').'</div>'
            .'<div id="Einsatzgebiete" class="tabcontent"><div class="product-domain">'."\n".$domain."\n".'</div></div>'
            .'<div id="Stammdaten" class="tabcontent">'.$pairs($stammdaten).'</div>'
            .'<div id="Downloads" class="tabcontent"><ul class="artikelDokumente-list">'.$docs.'</ul></div>',
            true,
        );
    }

    private function fakeSite(): void
    {
        Http::fake(function (Request $request) {
            $url = $request->url();
            $path = (string) parse_url($url, PHP_URL_PATH);
            $host = (string) parse_url($url, PHP_URL_HOST);

            if ($host === BigB2bClient::STATIC_HOST) {
                // cennik wymienia zdjęcia, których serwer nie ma
                if (str_contains($path, '_brak_')) {
                    return Http::response('<html><body>Not Found</body></html>', 404, ['Content-Type' => 'text/html; charset=iso-8859-1']);
                }

                return Http::response("\xFF\xD8\xFF\xE0".'jpeg-'.$path, 200, ['Content-Type' => 'image/jpeg']);
            }
            if ($path === '/') {
                return Http::response($this->layout('<p>Startseite</p>', $this->session), 200);
            }
            if ($path === '/account.html' && $request->method() === 'POST') {
                $this->loginBodies[] = $request->data();
                $data = $request->data();
                $this->session = ($data['personlogin'] ?? '') === self::USER && ($data['personpwd'] ?? '') === self::PASSWORD;
                if ($this->session) {
                    $this->logins++;
                    $this->pageHits = 0;
                }

                return Http::response($this->layout('<h1>Mein Konto</h1>', $this->session), 200);
            }
            if ($path === '/kundenDokumente.html') {
                return Http::response($this->session ? $this->documentsPage([['Preisliste_20260930', '01.10.26', 'preise.pdf'], ['Preisliste_20260930', '01.10.26', 'preise.xlsx']]) : $this->layout('', false), 200);
            }
            if ($path === '/kundendokumente/preise.xlsx') {
                return $this->session
                    ? Http::response($this->xlsx(), 200, ['Content-Type' => 'application/octet-stream;charset=utf-8'])
                    : Http::response($this->layout('', false), 200);
            }
            if (preg_match('#^/item-1-(\d+)\.html$#', $path, $m) === 1) {
                $page = $this->pages[$m[1]] ?? 404;
                if ($page === 500) {
                    return Http::response('<html><body>Fehler</body></html>', 500);
                }
                $this->pageHits++;
                if ($this->expireAfterPages > 0 && $this->pageHits > $this->expireAfterPages) {
                    $this->session = false;
                }
                // sklep bez sesji: strona gościa; strona 404 ma menu konta jak każda strona (z „Abmelden” tylko w sesji)
                if ($page === 404 || $this->notFoundForGuests && ! $this->session) {
                    return Http::response($this->layout('<h1>Seite nicht gefunden</h1>', $this->session), 404);
                }
                if (! $this->session) {
                    return Http::response($this->layout('<p>Gast</p>', false), 200);
                }

                return Http::response($page, 200);
            }
            if (str_starts_with($path, '/artikeldokumente/')) {
                // sklep wydaje PDF-y jako application/octet-stream (sprawdzone na żywo 01.10.2026)
                return Http::response('%PDF-1.4 big '.$path, 200, ['Content-Type' => 'application/octet-stream']);
            }

            return Http::response('nie ma', 404);
        });
    }
}
