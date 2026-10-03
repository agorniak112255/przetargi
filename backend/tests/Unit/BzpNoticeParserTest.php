<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Services\Bzp\BzpNoticeParser;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Odczyt ogłoszeń Biuletynu Zamówień Publicznych na prawdziwych próbkach z API (tests/Fixtures/bzp, pobrane
 * 03.10.2026) i na krótkich ogłoszeniach zbudowanych w teście (przypadki brzegowe).
 */
final class BzpNoticeParserTest extends TestCase
{
    private BzpNoticeParser $parser;

    protected function setUp(): void
    {
        parent::setUp();
        $this->parser = new BzpNoticeParser;
    }

    public function test_result_notice_with_cancelled_and_awarded_lot(): void
    {
        $record = $this->parser->parse(self::fixture('result-lots-cancelled-and-awarded.json'));

        $this->assertSame('TenderResultNotice', $record['notice_type']);
        $this->assertSame('2026/BZP 00416394/01', $record['notice_number']);
        $this->assertSame('2026/BZP 00416394', $record['bzp_number']);
        // „2.14.) Numer ogłoszenia: 2026/BZP 00361360” — ogłoszenie o zamówieniu, które poprzedziło wynik
        $this->assertSame('2026/BZP 00361360', $record['preceding_bzp_number']);
        $this->assertSame('ocds-148610-c15d46d8-6996-4880-b284-2ae0025ed8c4', $record['ocds_id']);
        $this->assertSame('2026-09-01 11:08:55', $record['published_at']->format('Y-m-d H:i:s'));
        $this->assertSame('UTC', $record['published_at']->getTimezone()->getName());
        $this->assertNull($record['submitting_offers_at']);
        $this->assertSame(BzpNoticeParser::VERSION, $record['parser_version']);
        $this->assertSame(BzpNoticeParser::VERSION, $record['parsed']['version']);
        $this->assertSame('8762449139', $record['organization_nip']);
        $this->assertSame(['code' => '35113400-3', 'name' => 'Odzież ochronna i zabezpieczająca'], $record['cpv_codes'][0]);
        $this->assertStringContainsString('SEKCJA V', (string) $record['html_body']);

        [$lot1, $lot2] = $record['parsed']['lots'];
        $this->assertSame(1, $lot1['lot_no']);
        $this->assertSame(BzpNoticeParser::RESULT_CANCELLED, $lot1['result']);
        $this->assertSame('html', $lot1['result_source']);
        $this->assertSame('35113400-3', $lot1['cpv_main']);
        $this->assertSame(['amount' => '495372.29', 'currency' => 'PLN'], $lot1['estimated_value']);
        $this->assertSame(2, $lot1['offers_count']);
        $this->assertSame(['amount' => '1365178.85', 'currency' => 'PLN'], $lot1['lowest_price']);
        $this->assertNull($lot1['winner_price']);
        // część unieważniona — bez wykonawcy (API podaje dla niej pustego wykonawcę)
        $this->assertSame([], $lot1['contractors']);

        $this->assertSame(2, $lot2['lot_no']);
        $this->assertSame(BzpNoticeParser::RESULT_AWARDED, $lot2['result']);
        $this->assertSame('Dostawa obuwia roboczego i ochronnego (zakres podstawowy) tj.:', $lot2['name']);
        $this->assertSame('18830000-6', $lot2['cpv_main']);
        $this->assertSame(3, $lot2['offers_count']);
        $this->assertSame(['amount' => '143320.83', 'currency' => 'PLN'], $lot2['winner_price']);
        $this->assertCount(1, $lot2['contractors']);
        $this->assertSame('Przedsiębiorstwo Wielobranżowe "MADA" Kosiec i Wspólnicy Sp. k.', $lot2['contractors'][0]['name']);
        // „NIP: 118-16-25-269” → same cyfry z poprawną sumą kontrolną; surowa wartość zostaje
        $this->assertSame('NIP: 118-16-25-269', $lot2['contractors'][0]['national_id_raw']);
        $this->assertSame('1181625269', $lot2['contractors'][0]['nip']);
        $this->assertSame([], $record['parsed']['warnings']);
    }

    public function test_unresolved_lot_has_no_result_data(): void
    {
        $record = $this->parser->parse(self::fixture('result-lots-unresolved.json'));
        [$lot1, $lot2] = $record['parsed']['lots'];

        $this->assertSame('2026/BZP 00379403', $record['preceding_bzp_number']);
        $this->assertSame(['amount' => '98752.91', 'currency' => 'PLN'], $lot1['lowest_price']);
        $this->assertSame(['amount' => '103024.03', 'currency' => 'PLN'], $lot1['highest_price']);
        // zwycięzca nie był najtańszy — cena zwycięzcy z własnego pola, nie z najniższej
        $this->assertSame(['amount' => '103024.03', 'currency' => 'PLN'], $lot1['winner_price']);
        $this->assertSame('9720249933', $lot1['contractors'][0]['nip']);

        $this->assertSame(BzpNoticeParser::RESULT_UNRESOLVED, $lot2['result']);
        $this->assertNull($lot2['offers_count']);
        $this->assertNull($lot2['lowest_price']);
        $this->assertSame([], $lot2['contractors']);
    }

    public function test_notice_without_lots_is_one_lot(): void
    {
        $record = $this->parser->parse(self::fixture('result-single-awarded.json'));

        $this->assertFalse($record['parsed']['has_lots']);
        $this->assertCount(1, $record['parsed']['lots']);
        $lot = $record['parsed']['lots'][0];
        $this->assertSame(1, $lot['lot_no']);
        $this->assertSame(BzpNoticeParser::RESULT_AWARDED, $lot['result']);
        // „Wartość zamówienia stanowiącego przedmiot tego postępowania (bez VAT): 25805,00 PLN”
        $this->assertSame(['amount' => '25805.00', 'currency' => 'PLN'], $lot['estimated_value']);
        $this->assertSame('18143000-3', $lot['cpv_main']);
        $this->assertSame('1181625269', $lot['contractors'][0]['national_id_raw']);
        $this->assertSame('1181625269', $lot['contractors'][0]['nip']);
        $this->assertSame('2026/BZP 00376786', $record['preceding_bzp_number']);
    }

    public function test_cancelled_notice_without_lots(): void
    {
        $record = $this->parser->parse(self::fixture('result-single-cancelled.json'));
        $lot = $record['parsed']['lots'][0];

        $this->assertSame('2026/BZP 00439099', $record['preceding_bzp_number']);
        $this->assertSame(BzpNoticeParser::RESULT_CANCELLED, $lot['result']);
        $this->assertSame(5, $lot['offers_count']);
        $this->assertSame(['amount' => '203922.70', 'currency' => 'PLN'], $lot['lowest_price']);
        $this->assertSame(['amount' => '312220.74', 'currency' => 'PLN'], $lot['highest_price']);
        $this->assertNull($lot['winner_price']);
        $this->assertSame(['amount' => '161727.68', 'currency' => 'PLN'], $lot['estimated_value']);
    }

    public function test_notice_with_result_for_one_part_only(): void
    {
        // procedureResult „;;;;;uniewaznienie” — ogłoszenie podaje wynik tylko części 6
        $lots = $this->parser->parse(self::fixture('result-one-part-of-six.json'))['parsed']['lots'];

        $this->assertSame([1, 2, 3, 4, 5, 6], array_column($lots, 'lot_no'));
        $this->assertSame([null, null, null, null, null, BzpNoticeParser::RESULT_CANCELLED], array_column($lots, 'result'));
    }

    public function test_long_procedure_result_is_kept_whole_for_reparse(): void
    {
        // 40 części — pozycja wyniku = numer części; obcięcie do 255 znaków zgubiłoby wyniki dalszych części
        $procedureResult = implode(';', array_fill(0, 39, 'zawarcieumowy')).';uniewaznienie';
        $this->assertGreaterThan(255, strlen($procedureResult));

        $record = $this->parser->parse(self::item(['Ogłoszenie o wyniku postępowania'], $procedureResult));

        $this->assertSame($procedureResult, $record['procedure_result']);
    }

    public function test_identifier_without_nip_stays_raw(): void
    {
        $lots = $this->parser->parse(self::fixture('result-regon-only.json'))['parsed']['lots'];

        $this->assertSame('Nowa Szkoła Sp. z o.o.', $lots[0]['contractors'][0]['name']);
        $this->assertSame('REGON 471014170', $lots[0]['contractors'][0]['national_id_raw']);
        $this->assertNull($lots[0]['contractors'][0]['nip']);
        // część 4: w API pusta pozycja wyniku i brak sekcji wyniku w treści → bez wyniku, nie zgadujemy
        $this->assertNull($lots[3]['result']);
    }

    public function test_contract_notice_with_lots(): void
    {
        $record = $this->parser->parse(self::fixture('contract-lots.json'));

        $this->assertSame('ContractNotice', $record['notice_type']);
        $this->assertNull($record['preceding_bzp_number']);
        $this->assertSame('2026-09-30 06:00:00', $record['submitting_offers_at']->format('Y-m-d H:i:s'));
        $this->assertSame('2026-09-30 08:00', $record['parsed']['submission_deadline_local']);
        $this->assertSame('https://platformazakupowa.pl/pn/szi', $record['parsed']['procedure_url']);
        $this->assertSame(['amount' => '796747.97', 'currency' => 'PLN'], $record['parsed']['total_value']);
        $lots = $record['parsed']['lots'];
        $this->assertSame([1, 2, 3, 4, 5], array_column($lots, 'lot_no'));
        $this->assertSame('Część 2 - Dostawa Bielizny trudnopalnej termoaktywna - opis przedmiotu zamówienia określa Załącznik nr 13b do SWZ', $lots[1]['name']);
        $this->assertSame('18100000-0', $lots[1]['cpv_main']);
        $this->assertSame(['amount' => '101626.02', 'currency' => 'PLN'], $lots[1]['estimated_value']);
        $this->assertSame([null, null, null, null, null], array_column($lots, 'result'));
    }

    public function test_labels_are_recognised_by_text_not_numbering(): void
    {
        $record = $this->parser->parse(self::item([
            'SEKCJA II – INFORMACJE PODSTAWOWE',
            '9.1.) Numer ogłoszenia: <span>2026/BZP 00000001</span>',
            '9.2.) Numer ogłoszenia: <span>2026/BZP 00000777</span>',
            'SEKCJA V ZAKOŃCZENIE POSTĘPOWANIA',
            '7.7.) Postępowanie zakończyło się zawarciem umowy albo unieważnieniem postępowania: <span>Postępowanie/cześć postępowania zakończyła się zawarciem umowy</span>',
            'SEKCJA VI OFERTY',
            '1.2.3.) Liczba otrzymanych ofert lub wniosków:   <span>  7 </span>',
            '1.2.4.) Cena lub koszt oferty z najniższą ceną lub kosztem: <span>1&nbsp;234,50 PLN</span>',
            '1.2.5.) Cena lub koszt oferty z najwyższą ceną lub kosztem: <span>1.234 PLN</span>',
        ]));
        $lot = $record['parsed']['lots'][0];

        $this->assertSame('2026/BZP 00000777', $record['preceding_bzp_number']);
        $this->assertSame(BzpNoticeParser::RESULT_AWARDED, $lot['result']);
        $this->assertSame(7, $lot['offers_count']);
        $this->assertSame(['amount' => '1234.50', 'currency' => 'PLN'], $lot['lowest_price']);
        // „1.234” — tysiąc czy jeden i 234 tysięczne? Niejednoznaczne → null, nie zgadujemy
        $this->assertNull($lot['highest_price']);
        // brak pola = null
        $this->assertNull($lot['winner_price']);
        $this->assertNull($lot['cpv_main']);
        $this->assertSame([], $lot['contractors']);
    }

    public function test_lot_without_contractor_in_text_takes_contractor_from_api_by_position(): void
    {
        $item = self::item([
            'SEKCJA V ZAKOŃCZENIE POSTĘPOWANIA',
            'Część 1',
            'SEKCJA V ZAKOŃCZENIE POSTĘPOWANIA (dla części 1)',
            '5.1.) Postępowanie zakończyło się zawarciem umowy albo unieważnieniem postępowania: Postępowanie/cześć postępowania zakończyła się unieważnieniem',
            'Część 2',
            'SEKCJA V ZAKOŃCZENIE POSTĘPOWANIA (dla części 2)',
            '5.1.) Postępowanie zakończyło się zawarciem umowy albo unieważnieniem postępowania: Postępowanie/cześć postępowania zakończyła się zawarciem umowy',
            'SEKCJA VII WYKONAWCA, KTÓREMU UDZIELONO ZAMÓWIENIA (dla części 2)',
            '7.3.2) Krajowy Numer Identyfikacyjny: 118-16-25-269',
        ], 'uniewaznienie;zawarcieUmowy', [
            ['contractorName' => null, 'contractorNationalId' => null],
            ['contractorName' => 'Firma Testowa sp. z o.o.', 'contractorNationalId' => '1181625269'],
        ]);
        $lots = $this->parser->parse($item)['parsed']['lots'];

        $this->assertSame([], $lots[0]['contractors']);
        $this->assertSame('Firma Testowa sp. z o.o.', $lots[1]['contractors'][0]['name']);
        $this->assertSame('118-16-25-269', $lots[1]['contractors'][0]['national_id_raw']);
        $this->assertSame('html+api', $lots[1]['contractors'][0]['source']);

        // pozycje API nie odpowiadają częściom (inna liczba) → API nie uzupełnia nazwy
        $item['contractors'] = [['contractorName' => 'Firma Testowa sp. z o.o.', 'contractorNationalId' => '1181625269']];
        $lots = $this->parser->parse($item)['parsed']['lots'];
        $this->assertNull($lots[1]['contractors'][0]['name']);
    }

    public function test_result_from_api_when_text_has_none_and_text_wins_on_conflict(): void
    {
        $lots = $this->parser->parse(self::item(['SEKCJA IV – PRZEDMIOT ZAMÓWIENIA'], 'uniewaznienie'))['parsed']['lots'];
        $this->assertSame(BzpNoticeParser::RESULT_CANCELLED, $lots[0]['result']);
        $this->assertSame('api', $lots[0]['result_source']);

        $record = $this->parser->parse(self::item([
            'SEKCJA V ZAKOŃCZENIE POSTĘPOWANIA',
            '5.1.) Postępowanie zakończyło się zawarciem umowy albo unieważnieniem postępowania: zakończyła się zawarciem umowy',
        ], 'uniewaznienie'));
        $this->assertSame(BzpNoticeParser::RESULT_AWARDED, $record['parsed']['lots'][0]['result']);
        $this->assertCount(1, $record['parsed']['warnings']);
    }

    public function test_notice_without_number_is_rejected(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->parser->parse(['noticeNumber' => 'ABC', 'publicationDate' => '2026-09-01T10:00:00Z']);
    }

    /**
     * @param  array{amount: string, currency: ?string}|null  $expected
     */
    #[DataProvider('amounts')]
    public function test_amounts(string $value, ?array $expected): void
    {
        $this->assertSame($expected, BzpNoticeParser::amount($value));
    }

    /**
     * @return iterable<string, array{0: string, 1: array{amount: string, currency: ?string}|null}>
     */
    public static function amounts(): iterable
    {
        yield 'przecinek i waluta' => ['1365178,85 PLN', ['amount' => '1365178.85', 'currency' => 'PLN']];
        yield 'spacje tysięcy i zł' => ['1 365 178,85 zł', ['amount' => '1365178.85', 'currency' => 'PLN']];
        yield 'twarde spacje' => ["98\u{00A0}752,91\u{00A0}PLN", ['amount' => '98752.91', 'currency' => 'PLN']];
        yield 'kropki tysięcy' => ['1.365.178,85', ['amount' => '1365178.85', 'currency' => null]];
        yield 'kropka dziesiętna' => ['143320.83', ['amount' => '143320.83', 'currency' => null]];
        yield 'przecinki tysięcy i euro' => ['1,365,178.85 EUR', ['amount' => '1365178.85', 'currency' => 'EUR']];
        yield 'jedna cyfra po przecinku' => ['0,5 PLN', ['amount' => '0.50', 'currency' => 'PLN']];
        yield 'bez groszy' => ['12 PLN', ['amount' => '12.00', 'currency' => 'PLN']];
        yield 'niejednoznaczna kropka' => ['1.234', null];
        yield 'niejednoznaczny przecinek' => ['1,234 PLN', null];
        yield 'trzy miejsca po przecinku' => ['12,345 PLN', null];
        yield 'tekst' => ['brak danych', null];
    }

    /**
     * @return array<string, mixed>
     */
    private static function fixture(string $name): array
    {
        $data = json_decode((string) file_get_contents(__DIR__.'/../Fixtures/bzp/'.$name), true);
        self::assertIsArray($data);

        return $data;
    }

    /**
     * Krótkie ogłoszenie o wyniku: każdy element listy = osobny nagłówek (jak <h3> w Biuletynie).
     *
     * @param  list<string>  $lines
     * @param  list<array<string, ?string>>|null  $contractors
     * @return array<string, mixed>
     */
    private static function item(array $lines, ?string $procedureResult = null, ?array $contractors = null): array
    {
        $html = '<html><body><main>'.implode('', array_map(
            static fn (string $line): string => '<h3 class="mb-0">'.$line.'</h3>',
            $lines,
        )).'</main></body></html>';

        return [
            'noticeType' => 'TenderResultNotice',
            'noticeNumber' => '2026/BZP 00000001/01',
            'bzpNumber' => '2026/BZP 00000001',
            'publicationDate' => '2026-09-01T10:00:00Z',
            'orderObject' => 'Dostawa rękawic',
            'cpvCode' => '18141000-9 (Rękawice robocze)',
            'procedureResult' => $procedureResult,
            'organizationName' => 'Zamawiający',
            'tenderId' => 'ocds-148610-test',
            'contractors' => $contractors,
            'htmlBody' => $html,
        ];
    }
}
