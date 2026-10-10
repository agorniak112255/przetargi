<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Models\PriceList;
use App\Models\Product;
use App\Services\Enrichment\PartsTable\PinResult;
use App\Services\PriceLists\Importers\ImportedRow;
use App\Services\PriceLists\Importers\Mapa\MapaPriceListImporter;
use App\Services\PriceLists\Importers\MapContext;
use App\Services\PriceLists\Importers\PriceListFormatChanged;
use App\Services\PriceLists\Importers\PriceListImporterRegistry;
use App\Services\PriceLists\Importers\ReadContext;
use App\Services\PriceLists\Importers\ReadResult;
use App\Support\ProductCodeMatch;
use PhpOffice\PhpSpreadsheet\Cell\DataType;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use Tests\TestCase;

/**
 * Pilot importera MAPA (10.10.2026). Plik: arkusze i nagłówki jak w „MAPA SUPON - cennik bazowy & ceny specjalne_2025.xlsx”
 * (kody raz liczbą, raz tekstem, znaczniki „Wycofane”/„Nowość” w kolumnie D, „SIZE 8” w nazwie), budowany w teście.
 * Mapa: indeks stron mapa-pro z produkcji (tests/Fixtures/price-lists/mapa/catalog-pages-2026-10-10.json — odczyt
 * catalog_pages 10.10.2026) i karty cennika #1 z obecnym źródłem opisu (cards-price-list-1-2026-10-10.json).
 */
final class MapaPriceListImporterTest extends TestCase
{
    private const FIXTURES = 'tests/Fixtures/price-lists/mapa/';

    private const PL = 'https://www.mapa-pro.pl/produkty/';

    private const COM = 'https://www.mapa-pro.com/our-gloves/';

    /** @var list<string> */
    private array $files = [];

    protected function tearDown(): void
    {
        foreach ($this->files as $file) {
            @unlink($file);
        }
        parent::tearDown();
    }

    public function test_reads_base_sheet_rows_skips_withdrawn_and_unpriced_with_reason(): void
    {
        $result = $this->read($this->workbook());

        $this->assertSame(12, $result->rowsTotal);
        $this->assertCount(8, $result->rows);
        $this->assertSame([
            ['ref' => 'Cennik bazowy 2025!12', 'sku' => '34181148', 'reason' => 'wycofane (kolumna D „Wycofane”)'],
            ['ref' => 'Cennik bazowy 2025!15', 'sku' => '34985028', 'reason' => 'brak ceny'],
            ['ref' => 'Cennik bazowy 2025!16', 'sku' => '3499', 'reason' => 'kod spoza wzoru MAPA (8 cyfr): „3499”'],
            ['ref' => 'Cennik bazowy 2025!17', 'sku' => '34115038', 'reason' => 'kod powtórzony (pierwszy w wierszu 8)'],
        ], $result->skipped);

        $first = $result->rows[0];
        $this->assertSame('34115038', $first->sku);
        $this->assertSame('VITAL 115', $first->name);
        $this->assertSame(0.5, $first->catalogPriceNet);
        $this->assertSame('EUR', $first->currency);
        $this->assertSame('Cennik bazowy 2025!8', $first->ref);
        $this->assertSame('VITAL 115', $first->modelName);
        $this->assertNull($first->purchasePrice);
        $this->assertNull($first->discountPercent);

        // kod zapisany w pliku jako tekst
        $this->assertSame('34175138', $result->rows[1]->sku);
        $this->assertSame(0.73, $result->rows[1]->catalogPriceNet);

        $temptec = $this->rowBySku($result, '34332028');
        $this->assertSame('TEMP-TEC 332', $temptec->name);
        $this->assertSame(['rozmiar' => '8'], $temptec->attributes);
        $this->assertSame(28.01, $temptec->catalogPriceNet);

        $last = $result->rows[7];
        $this->assertSame('34999028', $last->sku);
        $this->assertSame('SOLO 999', $last->name);
        $this->assertSame(6.78, $last->catalogPriceNet);
        $this->assertSame('ULTRANITRIL 492 UNIT', $this->rowBySku($result, '34492208')->name);
        $this->assertSame('ULTRANITRIL 492', $this->rowBySku($result, '34492208')->modelName);

        // wiersz pliku = pozycja persistImport: cena zakupu z rabatu wspólnego (bez znacznika zakupu z pliku)
        $payload = $temptec->toPayload('MAPA');
        $this->assertSame(28.01, $payload['catalog_price_net']);
        $this->assertSame(28.01, $payload['purchase_price']);
        $this->assertSame('EUR', $payload['currency']);
        $this->assertArrayNotHasKey('_purchase_from_file', $payload);
        $this->assertSame(['rozmiar' => '8'], $payload['price_list_attributes']);
    }

    public function test_notes_describe_rounding_news_and_special_prices_without_importing_them(): void
    {
        $notes = implode("\n", $this->read($this->workbook())->notes);

        $this->assertStringContainsString('Ceny katalogowe netto w EUR z kolumny „2025”', $notes);
        $this->assertStringContainsString('6 zaokrąglono do 2', $notes);
        $this->assertStringContainsString('„Nowość”: 1', $notes);
        $this->assertStringContainsString('3 cen projektowych dla klientów (PROJEKT A, PROJEKT B)', $notes);
        $this->assertStringContainsString('spoza cennika bazowego: VITAL 117 FSC 100%', $notes);
        $this->assertStringContainsString('Nieznany znacznik w kolumnie D „Promocja” (zaimportowano jak zwykłą pozycję): Cennik bazowy 2025!18', $notes);
    }

    public function test_changed_header_throws_format_changed(): void
    {
        $this->expectException(PriceListFormatChanged::class);
        $this->expectExceptionMessage('Kod produktu | Nazwa produktu');

        $this->read($this->workbook(header: ['Kod produktu', 'Nazwa', 2025]));
    }

    public function test_new_header_column_throws_format_changed(): void
    {
        $this->expectException(PriceListFormatChanged::class);
        $this->expectExceptionMessage('nowa kolumna „Cena specjalna” (D)');

        $this->read($this->workbook(header: ['Kod produktu', 'Nazwa produktu', 2025, 'Cena specjalna']));
    }

    public function test_price_header_without_year_throws_format_changed(): void
    {
        $this->expectException(PriceListFormatChanged::class);
        $this->expectExceptionMessage('zamiast roku cennika');

        $this->read($this->workbook(header: ['Kod produktu', 'Nazwa produktu', 'Cena netto']));
    }

    public function test_missing_currency_and_missing_sheet_throw_format_changed(): void
    {
        try {
            $this->read($this->workbook(intro: 'Cennik bazowy'));
            $this->fail('Brak waluty powinien przerwać odczyt.');
        } catch (PriceListFormatChanged $e) {
            $this->assertStringContainsString('nie ma waluty', $e->getMessage());
        }

        $this->expectException(PriceListFormatChanged::class);
        $this->expectExceptionMessage('brak arkusza „Cennik bazowy …”');
        $this->read($this->workbook(sheet: 'Price list 2026'));
    }

    public function test_single_product_page_with_exact_number_is_pinned_from_index_without_fetch_in_preview(): void
    {
        $ctx = $this->context([
            self::PL.'chemioodporne/strona-produktu/ultranitril-492',
            self::PL.'chemioodporne/strona-produktu/ultranitril-4920',
            self::COM.'chemical-protection/product-page/ultranitril-492',
            'https://www.mapa-pro.pl/nowosci/nowa-ultranitril-492',
        ]);

        $decision = $this->importer()->mapSource($this->card('34492208', 'ULTRANITRIL 492 UNIT'), [$this->row('34492208', 'ULTRANITRIL 492 UNIT')], $ctx);

        $this->assertTrue($decision->isPinned());
        $this->assertSame(self::PL.'chemioodporne/strona-produktu/ultranitril-492', $decision->url);
        $this->assertSame('manufacturer', $decision->sourceKind);
        $this->assertSame('model', $decision->matchKind);
        $this->assertSame('ULTRANITRIL 492', $decision->matchKey);
        $this->assertSame(['Kod: 34492208', 'Nazwa w cenniku: ULTRANITRIL 492 UNIT', 'Opakowanie: UNIT'], $decision->spec);
        $this->assertSame('linia', $decision->evidence['matched_by']);
        $this->assertSame('mapa-pro.pl', $decision->evidence['host']);
        $this->assertTrue($decision->evidence['sku_carries_number']);
        $this->assertFalse($decision->evidence['page_checked']);
        $this->assertSame([], $decision->evidence['other_pages_with_number']);
        $this->assertSame([], $ctx->fetched);
        $this->assertNull($decision->toPinAttributes()['unresolved_reason']);
    }

    public function test_falls_back_to_com_when_pl_has_no_product_page(): void
    {
        $decision = $this->importer()->mapSource(
            $this->card('34285018', 'ALTO 285'),
            [],
            $this->context([self::COM.'chemical-protection/product-page/alto-285']),
        );

        $this->assertSame(self::COM.'chemical-protection/product-page/alto-285', $decision->url);
        $this->assertSame('mapa-pro.com', $decision->evidence['host']);
        $this->assertSame(['Kod: 34285018', 'Nazwa w cenniku: ALTO 285'], $decision->spec);
    }

    public function test_several_pages_are_narrowed_by_full_name_then_line(): void
    {
        $ctx = $this->context([
            self::PL.'jednorazowe/strona-produktu/solo-967',
            self::PL.'jednorazowe/strona-produktu/solo-box-967',
            self::PL.'precyzyjne/rekawice-do-prac-precyzyjnych/strona-produktu/ultrane-541',
            self::PL.'precyzyjne/rekawice-do-prac-precyzyjnych/strona-produktu/ultrane-541-no-marking',
        ]);

        $box = $this->importer()->mapSource($this->card('34967118', 'SOLO 967 BOX'), [], $ctx);
        $this->assertSame(self::PL.'jednorazowe/strona-produktu/solo-box-967', $box->url);
        $this->assertSame('pełna nazwa', $box->evidence['matched_by']);
        $this->assertSame([self::PL.'jednorazowe/strona-produktu/solo-967'], $box->evidence['other_pages_with_number']);
        $this->assertContains('Opakowanie: BOX', $box->spec);

        $solo = $this->importer()->mapSource($this->card('34967008', 'SOLO 967'), [], $ctx);
        $this->assertSame(self::PL.'jednorazowe/strona-produktu/solo-967', $solo->url);

        $ultrane = $this->importer()->mapSource($this->card('34541008', 'ULTRANE 541'), [], $ctx);
        $this->assertSame(self::PL.'precyzyjne/rekawice-do-prac-precyzyjnych/strona-produktu/ultrane-541', $ultrane->url);

        $vm = $this->importer()->mapSource($this->card('34541018', 'ULTRANE 541 VM'), [], $ctx);
        $this->assertSame(self::PL.'precyzyjne/rekawice-do-prac-precyzyjnych/strona-produktu/ultrane-541', $vm->url);
        $this->assertSame('linia', $vm->evidence['matched_by']);
        $this->assertContains('Opakowanie: VM (opakowanie vendingowe)', $vm->spec);
    }

    public function test_several_pages_with_same_name_and_number_stay_unresolved_with_candidates(): void
    {
        $decision = $this->importer()->mapSource($this->card('34580008', 'KRYTECH 580 RF'), [], $this->context([
            self::PL.'odpornosc-na-przeciecie/prace-precyzyjne/strona-produktu/krytech-580',
            self::PL.'odpornosc-na-przeciecie/ciezkie-prace-manipulacyjne/strona-produktu/krytech-580',
        ]));

        $this->assertFalse($decision->isPinned());
        $this->assertSame('kilka stron producenta', $decision->unresolvedReason);
        $this->assertCount(2, $decision->candidates);
        $this->assertSame(self::PL.'odpornosc-na-przeciecie/prace-precyzyjne/strona-produktu/krytech-580', $decision->candidates[0]['url']);
    }

    public function test_no_page_or_number_inside_another_number_is_unresolved(): void
    {
        $none = $this->importer()->mapSource($this->card('34987038', 'SOLO 987'), [], $this->context([
            self::PL.'jednorazowe/strona-produktu/solo-997',
        ]));
        $this->assertSame('brak strony producenta z tym numerem', $none->unresolvedReason);
        $this->assertSame([], $none->candidates);

        $longer = $this->importer()->mapSource($this->card('34492208', 'ULTRANITRIL 492'), [], $this->context([
            self::PL.'chemioodporne/strona-produktu/ultranitril-4920',
            self::PL.'chemioodporne/strona-produktu/ultranitril-1492',
            self::PL.'chemioodporne/strona-produktu/ultranitril-492-plus-493',
        ]));
        $this->assertFalse($longer->isPinned());
        $this->assertSame('brak strony producenta z tym numerem', $longer->unresolvedReason);

        // aktualność z numerem w adresie to nie strona produktu
        $news = $this->importer()->mapSource($this->card('34935048', 'DARK SOLO 935'), [], $this->context([
            'https://www.mapa-pro.pl/nowosci/nowy-solo-black-935',
        ]));
        $this->assertSame('brak strony producenta z tym numerem', $news->unresolvedReason);
    }

    public function test_page_with_number_but_other_product_line_is_not_pinned(): void
    {
        $decision = $this->importer()->mapSource($this->card('34547008', 'KRYTECH 547'), [], $this->context([
            self::COM.'cut-protection/heavy-duty-handling/product-page/exonit-547',
        ]));

        $this->assertFalse($decision->isPinned());
        $this->assertSame('strona producenta z numerem 547 ma inną nazwę wyrobu', $decision->unresolvedReason);
        $this->assertSame(self::COM.'cut-protection/heavy-duty-handling/product-page/exonit-547', $decision->candidates[0]['url']);
    }

    public function test_name_and_code_must_agree_on_model_number(): void
    {
        $ctx = $this->context([self::PL.'chemioodporne/strona-produktu/ultranitril-492']);

        $mismatch = $this->importer()->mapSource($this->card('34493008', 'ULTRANITRIL 492'), [], $ctx);
        $this->assertSame('numer w nazwie (492) nie zgadza się z kodem 34493008 (493)', $mismatch->unresolvedReason);

        $noNumber = $this->importer()->mapSource($this->card('34492208', 'ULTRANITRIL'), [], $ctx);
        $this->assertStringStartsWith('nazwa bez jednego numeru modelu MAPA', (string) $noNumber->unresolvedReason);
    }

    public function test_live_fetch_confirms_number_on_page_and_takes_title(): void
    {
        $url = self::PL.'chemioodporne/strona-produktu/butoflex-650';
        $html = (string) file_get_contents(base_path('tests/Fixtures/enrichment/mapa/butoflex-650-pl.html'));
        $ctx = $this->context([$url], live: true, responses: [$url => [
            'url' => $url,
            'final_url' => $url,
            'title' => 'Ochrona chemiczna : Butoflex 650',
            'text' => trim(strip_tags($html)),
            'html' => $html,
        ]]);

        $decision = $this->importer()->mapSource($this->card('34650008', 'BUTOFLEX 650'), [], $ctx);

        $this->assertSame($url, $decision->url);
        $this->assertSame('Ochrona chemiczna : Butoflex 650', $decision->pageTitle);
        $this->assertTrue($decision->evidence['page_checked']);
        $this->assertSame([$url], $ctx->fetched);
    }

    public function test_live_fetch_rejects_page_without_number_or_redirected(): void
    {
        $url = self::PL.'chemioodporne/strona-produktu/butoflex-650';
        $withoutNumber = $this->importer()->mapSource($this->card('34650008', 'BUTOFLEX 650'), [], $this->context([$url], live: true, responses: [
            $url => ['url' => $url, 'final_url' => $url, 'title' => 'Ochrona chemiczna', 'text' => 'Butoflex 651 i Butoflex 6500', 'html' => ''],
        ]));
        $this->assertSame('strona producenta bez numeru 650 w treści', $withoutNumber->unresolvedReason);

        $redirected = $this->importer()->mapSource($this->card('34650008', 'BUTOFLEX 650'), [], $this->context([$url], live: true, responses: [
            $url => ['url' => $url, 'final_url' => 'https://www.mapa-pro.pl/produkty/chemioodporne', 'title' => 'Chemioodporne', 'text' => 'Butoflex 650', 'html' => ''],
        ]));
        $this->assertSame('strona producenta przekierowuje na inny adres', $redirected->unresolvedReason);

        // strona nie odpowiedziała — przypięcie z indeksu, bez potwierdzenia
        $silent = $this->importer()->mapSource($this->card('34650008', 'BUTOFLEX 650'), [], $this->context([$url], live: true));
        $this->assertSame($url, $silent->url);
        $this->assertFalse($silent->evidence['page_checked']);
        $this->assertStringContainsString('nie odpowiedziała', $silent->evidence['page_check']);
    }

    public function test_production_index_pins_146_of_147_cards_and_agrees_with_current_sources(): void
    {
        $pages = json_decode((string) file_get_contents(base_path(self::FIXTURES.'catalog-pages-2026-10-10.json')), true);
        $cards = json_decode((string) file_get_contents(base_path(self::FIXTURES.'cards-price-list-1-2026-10-10.json')), true);
        $ctx = $this->context(array_column($pages, 'url'));
        $norm = static fn (?string $url): string => rtrim((string) preg_replace('#^https?://(www\.)?#', '', (string) $url), '/');

        $pinned = 0;
        $unresolved = [];
        $different = [];
        foreach ($cards as $card) {
            $decision = $this->importer()->mapSource($this->card($card['sku'], $card['name']), [], $ctx);
            if (! $decision->isPinned()) {
                $unresolved[$card['sku']] = $decision->unresolvedReason;

                continue;
            }
            $pinned++;
            if ($norm($decision->url) !== $norm($card['primary_source_url'])) {
                $different[$card['sku']] = $decision->url;
            }
        }

        $this->assertCount(147, $cards);
        $this->assertSame(146, $pinned);
        // SOLO 987: karta bez opisu (manual), mapa-pro nie ma strony z numerem 987 w indeksie
        $this->assertSame(['34987038' => 'brak strony producenta z tym numerem'], $unresolved);
        $this->assertSame([
            // obecne źródło: ta sama strona w wersji .com — mapa woli .pl
            '34285018' => self::PL.'chemioodporne/strona-produktu/alto-285',
            // obecne źródło: solo-967 (.com) — producent ma osobną stronę „Solo Box 967”
            '34967118' => self::PL.'jednorazowe/strona-produktu/solo-box-967',
        ], $different);
        $this->assertSame([], $ctx->fetched);
    }

    public function test_registry_knows_mapa_importer(): void
    {
        $registry = new PriceListImporterRegistry;

        $this->assertSame(MapaPriceListImporter::class, $registry->classFor('mapa-2025'));
        $this->assertInstanceOf(MapaPriceListImporter::class, $registry->make('mapa-2025'));
        $this->assertContains([
            'key' => 'mapa-2025',
            'label' => 'MAPA — cennik bazowy i ceny specjalne 2025',
            'version' => 1,
            'manufacturer_keys' => ['mapa'],
        ], $registry->options());
    }

    private function importer(): MapaPriceListImporter
    {
        return new MapaPriceListImporter;
    }

    private function read(string $path): ReadResult
    {
        return $this->importer()->read($path, 'MAPA SUPON  - cennik bazowy & ceny specjalne_2025.xlsx', new ReadContext(new PriceList(['manufacturer' => 'MAPA'])));
    }

    private function rowBySku(ReadResult $result, string $sku): ImportedRow
    {
        foreach ($result->rows as $row) {
            if ($row->sku === $sku) {
                return $row;
            }
        }
        $this->fail('Brak wiersza '.$sku);
    }

    private function card(string $sku, string $name): Product
    {
        return new Product(['sku' => $sku, 'name' => $name, 'manufacturer' => 'MAPA']);
    }

    private function row(string $sku, string $name): ImportedRow
    {
        return new ImportedRow(sku: $sku, name: $name, catalogPriceNet: 1.0, ref: 'Cennik bazowy 2025!1', currency: 'EUR');
    }

    /**
     * Plik w układzie prawdziwego cennika MAPA 2025 (fragment: te same nagłówki, typy komórek i znaczniki).
     *
     * @param  list<string|int>  $header
     */
    private function workbook(array $header = ['Kod produktu', 'Nazwa produktu', 2025], string $intro = 'Cennik bazowy w walucie €', string $sheet = 'Cennik bazowy 2025'): string
    {
        $book = new Spreadsheet;
        $base = $book->getActiveSheet();
        $base->setTitle($sheet);
        $base->setCellValue('B2', $intro);
        $base->setCellValue('B3', 'Obowiązuje od 01/01/2025');
        $base->setCellValue('B4', 'VM* - opakowanie vendingowe');
        foreach (array_values($header) as $i => $value) {
            $base->setCellValue(chr(ord('A') + $i).'7', $value);
        }
        $rows = [
            8 => [34115038, 'VITAL 115', 0.495, null],
            9 => ['34175138', 'VITAL 175', 0.731, null],
            10 => [34117108, 'VITAL 117', 0.495, null],
            11 => [34332028, 'TEMP-TEC 332 SIZE 8', 28.01, null],
            12 => ['34181148', 'VITAL 181', null, 'Wycofane'],
            13 => [34492208, 'ULTRANITRIL 492 UNIT', 1.362, null],
            14 => [34980458, 'SOLO 980', 8, 'Nowość'],
            15 => [34985028, 'TRILITES 985', null, null],
            16 => [3499, 'SOLO 99', 1.5, null],
            17 => [34115038, 'VITAL 115', 0.495, null],
            18 => [34997168, 'SOLO 997', 5.421, 'Promocja'],
            19 => [34999028, 'SOLO 999', 6.778, null],
        ];
        foreach ($rows as $r => [$code, $name, $price, $flag]) {
            if (is_string($code)) {
                $base->setCellValueExplicit('A'.$r, $code, DataType::TYPE_STRING);
            } else {
                $base->setCellValue('A'.$r, $code);
            }
            $base->setCellValue('B'.$r, $name);
            if ($price !== null) {
                $base->setCellValue('C'.$r, $price);
            }
            if ($flag !== null) {
                $base->setCellValue('D'.$r, $flag);
            }
        }

        $special = $book->createSheet();
        $special->setTitle('Supon - ceny specjalne');
        $special->fromArray([
            ['Klient', 'Projekt', 'Nazwa produktu', 'Cena od 01.01.2025'],
            ['KLIENT', 'PROJEKT A', 'VITAL 117', 0.4],
            ['KLIENT', 'PROJEKT A', 'VITAL 117 FSC 100%', 0.4],
            ['KLIENT', 'PROJEKT B', 'SOLO 997', 5.0],
        ]);

        $path = tempnam(sys_get_temp_dir(), 'mapa').'.xlsx';
        (new Xlsx($book))->save($path);
        $this->files[] = $path;

        return $path;
    }

    /**
     * Atrapa kontekstu mapy: indeks z adresów (tokeny jak catalog_page_tokens — człony adresu), carriesCode jak
     * DefaultMapContext (ProductCodeMatch), pobrania z przygotowanych odpowiedzi.
     *
     * @param  list<string>  $urls
     * @param  array<string, array{url: string, final_url: string, title: ?string, text: string, html: string}>  $responses
     */
    private function context(array $urls, bool $live = false, array $responses = []): MapContext
    {
        return new class($urls, $live, $responses) implements MapContext
        {
            /** @var list<string> */
            public array $fetched = [];

            public function __construct(private array $urls, private bool $live, private array $responses) {}

            public function priceList(): PriceList
            {
                return new PriceList(['manufacturer' => 'MAPA']);
            }

            public function manufacturerHosts(Product $card): array
            {
                return ['mapa-pro.pl', 'mapa-pro.com'];
            }

            public function listHosts(): array
            {
                return [];
            }

            public function indexHits(Product $card, array $hosts): array
            {
                return [];
            }

            public function pagesWithCode(string $code, array $hosts): array
            {
                $out = [];
                foreach ($this->urls as $url) {
                    $host = (string) parse_url($url, PHP_URL_HOST);
                    $tokens = preg_split('/[^a-z0-9]+/', mb_strtolower((string) parse_url($url, PHP_URL_PATH)), -1, PREG_SPLIT_NO_EMPTY) ?: [];
                    if (in_array($host, $hosts, true) && in_array(mb_strtolower($code), $tokens, true)) {
                        $out[] = ['url' => $url, 'title' => ''];
                    }
                }

                return $out;
            }

            public function carriesCode(string $text, array $codes): bool
            {
                foreach ($codes as $code) {
                    if (ProductCodeMatch::textCarries($text, $code)) {
                        return true;
                    }
                }

                return false;
            }

            public function fetch(string $url): ?array
            {
                $this->fetched[] = $url;
                if (! $this->live) {
                    return null;
                }

                return $this->responses[$url] ?? null;
            }

            public function liveFetch(): bool
            {
                return $this->live;
            }

            public function partsPin(Product $card): ?PinResult
            {
                return null;
            }
        };
    }
}
