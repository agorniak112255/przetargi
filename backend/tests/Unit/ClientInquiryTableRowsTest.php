<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Services\Ai\AiSettingsService;
use App\Services\Ai\OpenAiCompatibleClient;
use App\Services\ClientInquiryService;
use App\Services\NbpExchangeRateService;
use App\Services\ProductInquirySearch;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Mockery;
use Tests\TestCase;

/**
 * Tabele z plików klienta (InquiryFileText: wiersz = linia, komórki po „ | ”). Zapytanie #86 (30.09.2026): dwa pliki OPZ
 * dały ilości 1, 2, 1, 2 (Lp.) zamiast 275 szt., 362 par, 4 i 4 szt., frazy z całych wierszy i pozycję z adresu
 * „35-232 Rzeszów” z ilością 35.
 */
final class ClientInquiryTableRowsTest extends TestCase
{
    use RefreshDatabase;

    private const MAIL = "Dzień dobry, w załączeniu zapytania.\n\nJan Nowak\nSupon\n35-232 Rzeszów, ul. Miłocińska 17\n\n";

    private const ROW_SUIT = '1 | Kombinezon rybacki z podnoskiem (nr pozycji magazynowej u Zamawiającego M056649) | Kombinezon stanowi '
        .'połączenie butów gumowych ze spodniami z podgumowanej dzianiny. Materiał: PVC. Podeszwa antypoślizgowa SRC. '
        .'Rozmiar: od 40 do 47. Gatunek l. | PN-EN ISO 20345 Klasa ochrony obuwia: S5 SR lub S5 SRC oraz EN 343 | szt. | 275';

    private const ROW_WADERS = '2 | Buty gumowe rybackie (wodery) z podnoskiem (nr pozycji magazynowej u Zamawiającego M056650) | Materiał: '
        .'PVC . Podszewka: materiał tekstylny. Podeszwa antypoślizgowa SRC. Rozmiary standardowe od 39 do 48. | PN-EN ISO '
        .'20345 Klasa ochrony obuwia: S5 SR lub S5 SRC | para | 362';

    private const ROW_RUP = '1 | RUP 502-U - Ewakuacyjne urządzenie podnosząco-opuszczające PROTEKT: DOR (kg) 140 MBS: 20 kN '
        .'Długość korby: 300 mm Kolor: pomarańczowy Długość liny: 20m, materiał: stal, średnica liny ø 6,3 mm | 4';

    private const ROW_TRIPOD = '2 | TM 9-N aluminiowy statyw bezpieczeństwa PROTEKT: Wysokość: 209 cm Waga statywu: 15,45 kg '
        .'Maks. liczba użytkowników przy podnoszeniu i opuszczaniu: 3 Maks. waga ładunku: 500kg | 4';

    private function service(): ClientInquiryService
    {
        return new ClientInquiryService(
            Mockery::mock(OpenAiCompatibleClient::class),
            Mockery::mock(ProductInquirySearch::class),
            $this->app->make(NbpExchangeRateService::class),
            $this->app->make(AiSettingsService::class),
        );
    }

    private function inquiry86(): string
    {
        return self::MAIL
            ."=== Plik klienta: opz(65).docx ===\nOPIS PRZEDMIOTU ZAMÓWIENIA\nDostawa kombinezonów rybackich\n\n"
            ."Wymagania do asortymentu\n"
            ."l.p. | Przedmiot zamówienia | Parametry użytkowe | Wymagania spełnienia norm | Jednostka miary | Razem\n"
            .self::ROW_SUIT."\n".self::ROW_WADERS."\n\n"
            ."Wymagania do asortymentu:\n"
            ."1. Obuwie musi posiadać oznaczenie CE oraz deklarację zgodności UE.\n"
            ."2. Każda para obuwia musi posiadać instrukcję użytkowania w języku polskim.\n"
            ."3. Okres gwarancji nie krótszy niż 12 miesięcy.\n\n"
            ."=== Plik klienta: opz_10.08(4).docx ===\nOPIS PRZEDMIOTU ZAMÓWIENIA\nZakres dostawy:\n"
            ."Lp. | Asortyment - opis parametrów | Ilość (szt.)\n"
            .self::ROW_RUP."\n".self::ROW_TRIPOD."\n\n"
            .'Termin dostawy: do 3 tygodni od dnia wysłania zamówienia zakupu';
    }

    /**
     * @param  list<array<string, mixed>>  $items
     * @return list<array<string, mixed>>
     */
    private function pick(array $items, array $keys): array
    {
        return array_map(static fn (array $item): array => array_intersect_key($item, array_flip($keys)), $items);
    }

    public function test_file_tables_give_quantity_unit_and_name_and_no_address_or_requirements(): void
    {
        $items = $this->service()->parseLineItemsFromBody(ClientInquiryService::analysisText($this->inquiry86(), 'thunderbird'));

        $this->assertSame([
            ['qty' => '275', 'unit' => 'szt.', 'query' => 'Kombinezon rybacki z podnoskiem', 'size' => null],
            ['qty' => '362', 'unit' => 'para', 'query' => 'Buty gumowe rybackie (wodery) z podnoskiem', 'size' => null],
            ['qty' => '4', 'unit' => 'szt.', 'query' => 'RUP 502-U - Ewakuacyjne urządzenie podnosząco-opuszczające PROTEKT', 'size' => null],
            ['qty' => '4', 'unit' => 'szt.', 'query' => 'TM 9-N aluminiowy statyw bezpieczeństwa PROTEKT', 'size' => null],
        ], $this->pick($items, ['qty', 'unit', 'query', 'size']));
    }

    /**
     * Model czyta tabelę jak tekst i bierze Lp. za ilość — cytując cały wiersz, wiersz bez „1 | ”, samą nazwę albo
     * urywek. Ilość zawsze z kolumny ilości, cytat to cały wiersz, fraza bez „|” i bez numeru magazynowego klienta.
     */
    public function test_model_quantity_taken_from_lp_is_replaced_by_the_table_column(): void
    {
        $ai = [
            ['id' => 'item_1', 'quote' => self::ROW_SUIT, 'qty' => '1', 'unit' => null, 'query' => 'kombinezon rybacki z podnoskiem PVC', 'size' => null],
            ['id' => 'item_2', 'quote' => mb_substr(self::ROW_WADERS, 4), 'qty' => '2', 'unit' => null, 'query' => 'wodery rybackie z podnoskiem', 'size' => null],
            ['id' => 'item_3', 'quote' => 'RUP 502-U - Ewakuacyjne urządzenie podnosząco-opuszczające PROTEKT', 'qty' => '1', 'unit' => null, 'query' => 'RUP 502-U urządzenie ewakuacyjne PROTEKT', 'size' => null],
            ['id' => 'item_4', 'quote' => 'TM 9-N aluminiowy statyw bezpieczeństwa PROTEKT: Wysokość: 209 cm Waga', 'qty' => '2', 'unit' => null, 'query' => 'statyw bezpieczeństwa aluminiowy', 'size' => null],
        ];

        $items = $this->service()->resolveLineItemsWithOmitted(ClientInquiryService::analysisText($this->inquiry86(), 'thunderbird'), $ai)['items'];

        $this->assertSame(
            [['275', 'szt.'], ['362', 'para'], ['4', 'szt.'], ['4', 'szt.']],
            array_map(static fn (array $item): array => [$item['qty'], $item['unit']], $items),
        );
        $this->assertSame([self::ROW_SUIT, self::ROW_WADERS, self::ROW_RUP, self::ROW_TRIPOD], array_column($items, 'quote'));
        $this->assertSame('kombinezon rybacki z podnoskiem PVC', $items[0]['search_query']);
        // „9-N” z nazwy wyrobu nie może zniknąć z frazy
        $this->assertSame('TM 9-N aluminiowy statyw bezpieczeństwa PROTEKT', $items[3]['search_query']);
        foreach ($items as $item) {
            $this->assertStringNotContainsString('|', (string) $item['search_query']);
            $this->assertStringNotContainsString('M0566', (string) $item['search_query']);
            $this->assertArrayNotHasKey('table_lp', $item);
            $this->assertArrayNotHasKey('table_name', $item);
        }
    }

    /** Numerowane wymagania pod tabelą i adres ze stopki nie przegłosowują modelu samą liczbą wierszy. */
    public function test_requirements_and_address_do_not_outvote_the_model(): void
    {
        $ai = [
            ['id' => 'item_1', 'quote' => self::ROW_SUIT, 'qty' => '275', 'unit' => 'szt.', 'query' => 'kombinezon rybacki z podnoskiem PVC', 'size' => null],
            ['id' => 'item_2', 'quote' => self::ROW_WADERS, 'qty' => '362', 'unit' => 'para', 'query' => 'wodery rybackie z podnoskiem PVC', 'size' => null],
            ['id' => 'item_3', 'quote' => self::ROW_RUP, 'qty' => '4', 'unit' => 'szt.', 'query' => 'RUP 502-U urządzenie ewakuacyjne PROTEKT', 'size' => null],
            ['id' => 'item_4', 'quote' => self::ROW_TRIPOD, 'qty' => '4', 'unit' => 'szt.', 'query' => 'TM 9-N statyw bezpieczeństwa PROTEKT', 'size' => null],
        ];

        $items = $this->service()->resolveLineItemsWithOmitted(ClientInquiryService::analysisText($this->inquiry86(), 'thunderbird'), $ai)['items'];

        $this->assertCount(4, $items);
        $this->assertSame('wodery rybackie z podnoskiem PVC', $items[1]['search_query']);
    }

    public function test_column_number_row_and_total_row_are_not_items(): void
    {
        $body = "=== Plik klienta: formularz.xlsx ===\n"
            ."Lp. | Nazwa | Ilość | j.m.\n"
            ."1 | 2 | 3 | 4\n"
            ."1 | Rękawice nitrylowe rozm. 9 | 20 | op.\n"
            .'Razem |  | 20 |';

        $items = $this->service()->parseLineItemsFromBody($body);

        $this->assertSame([['qty' => '20', 'unit' => 'op.', 'size' => '9']], $this->pick($items, ['qty', 'unit', 'size']));
    }

    /** Kolumny opakowania, ceny i wartości nie są ilością; puste komórki ceny nie przesuwają kolumn. */
    public function test_pack_and_price_columns_are_not_the_quantity(): void
    {
        $body = "=== Plik klienta: formularz.xlsx ===\n"
            ."Lp. | Nazwa | Ilość w opakowaniu | Ilość | j.m. | Cena jedn. netto | Wartość razem\n"
            .'1 | Rękawice nitrylowe | 100 | 20 | op. |  |';

        $items = $this->service()->parseLineItemsFromBody($body);

        $this->assertSame([['qty' => '20', 'unit' => 'op.']], $this->pick($items, ['qty', 'unit']));
    }

    /** Zamówienie podstawowe i opcja bez kolumny „Razem” — ilość nieznana, a nie liczba z jednej z kolumn. */
    public function test_two_quantity_columns_without_total_leave_quantity_empty(): void
    {
        $parser = $this->service();
        $withoutTotal = $parser->parseLineItemsFromBody(
            "=== Plik klienta: a.xlsx ===\nLp. | Nazwa | Ilość 2026 | Ilość 2027\n1 | Rękawice nitrylowe | 100 | 120"
        );
        $withTotal = $parser->parseLineItemsFromBody(
            "=== Plik klienta: a.xlsx ===\nLp. | Nazwa | Ilość podstawowa | Ilość w opcji | Razem\n1 | Rękawice nitrylowe | 100 | 20 | 120"
        );

        $this->assertNull($withoutTotal[0]['qty']);
        $this->assertSame('120', $withTotal[0]['qty']);
    }

    public function test_catalogue_number_column_goes_to_the_search_phrase(): void
    {
        $items = $this->service()->parseLineItemsFromBody(
            "=== Plik klienta: a.xlsx ===\nLp. | Nr katalogowy | Nazwa | Ilość\n1 | 11-800 | Rękawice Ansell HyFlex | 10"
        );

        $this->assertSame('10', $items[0]['qty']);
        $this->assertSame('Rękawice Ansell HyFlex 11-800', $items[0]['query']);
    }

    /** Zakres rozmiarów w parametrach to oferta klienta, nie zamówiony rozmiar. */
    public function test_size_range_in_parameters_is_not_the_ordered_size(): void
    {
        $items = $this->service()->parseLineItemsFromBody(
            "=== Plik klienta: a.docx ===\nLp. | Nazwa | Parametry | Ilość\n1 | Kalosze PCV S5 | Rozmiar: 40-47, podeszwa SRC | 30"
        );

        $this->assertNull($items[0]['size']);
        $this->assertSame('30', $items[0]['qty']);
    }

    /** Wiersz z inną liczbą komórek niż nagłówek: kolumn nie znamy, więc ilości nie zgadujemy. */
    public function test_row_with_other_cell_count_than_header_has_no_quantity(): void
    {
        $items = $this->service()->parseLineItemsFromBody(
            "=== Plik klienta: a.docx ===\nLp. | Nazwa | Cena | Ilość\n1 | Rękawice nitrylowe | 20"
        );

        $this->assertCount(1, $items);
        $this->assertNull($items[0]['qty']);
    }

    /** Tabela bez nagłówka: pierwsza liczba to Lp., ilość tylko z komórki z jednostką. */
    public function test_table_without_header_takes_quantity_only_from_a_cell_with_unit(): void
    {
        $items = $this->service()->parseLineItemsFromBody(
            "=== Plik klienta: a.xlsx ===\n1 | Rękawice nitrylowe | 10 par\n2 | Kalosze PCV S5 | 4"
        );

        $this->assertSame([['qty' => '10', 'unit' => 'par'], ['qty' => null, 'unit' => null]], $this->pick($items, ['qty', 'unit']));
    }

    public function test_postal_address_is_not_an_item_but_product_codes_in_that_form_are(): void
    {
        $items = $this->service()->parseLineItemsFromBody(
            "Proszę o ofertę:\n11-800 Rękawice nitrylowe 10 par\n92-605 rozm. 9 - 20 par\n\nPozdrawiam\n35-232 Rzeszów\n35-232 Rzeszów, ul. Miłocińska 17"
        );

        $this->assertSame(['11-800 Rękawice nitrylowe 10 par', '92-605 rozm. 9 - 20 par'], array_column($items, 'quote'));
    }

    /** Numeracja listy w mailu nad plikiem nie zeruje ilości z kolumny tabeli. */
    public function test_mail_enumeration_above_the_file_keeps_table_quantities(): void
    {
        $items = $this->service()->parseLineItemsFromBody(
            "Proszę o ofertę na:\n1. Rękawice MAPA 332\n2. Kalosze PCV S5\n\n"
            ."=== Plik klienta: a.xlsx ===\nLp. | Nazwa | Ilość\n1 | Okulary UVEX i-3 | 15\n2 | Kask ochronny biały | 3"
        );

        $this->assertSame([null, null, '15', '3'], array_column($items, 'qty'));
    }

    /** Nagłówek przetrwa wiersz grupy („ODZIEŻ OCHRONNA”) w środku tabeli arkusza. */
    public function test_group_row_inside_a_spreadsheet_table_keeps_the_header(): void
    {
        $items = $this->service()->parseLineItemsFromBody(
            "=== Plik klienta: a.xlsx ===\nLp. | Nazwa | Ilość | j.m.\nODZIEŻ OCHRONNA\n1 | Kurtka ocieplana | 12 | szt."
        );

        $this->assertSame([['qty' => '12', 'unit' => 'szt.']], $this->pick($items, ['qty', 'unit']));
    }
}
