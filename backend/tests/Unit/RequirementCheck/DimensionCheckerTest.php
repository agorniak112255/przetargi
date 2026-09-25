<?php

declare(strict_types=1);

namespace Tests\Unit\RequirementCheck;

use App\Support\RequirementCheck\CardSource;
use App\Support\RequirementCheck\CardSources;
use App\Support\RequirementCheck\CheckRow;
use App\Support\RequirementCheck\DimensionChecker;
use App\Support\RequirementCheck\DimensionParser;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Support\Opisowy15Fixture;
use Tests\TestCase;

/**
 * Wymiary w oknie „Weryfikacja karty”: pewne ✓/✗ tylko z nazwy wymiaru, liczby i jednostki;
 * liczba bez jednostki i wartość z samej nazwy karty to „do sprawdzenia”.
 */
final class DimensionCheckerTest extends TestCase
{
    public function test_poz1_dlugosc_w_dwoch_jednostkach_to_jeden_wiersz_ok_z_karty_fixture(): void
    {
        $rows = $this->rows(Opisowy15Fixture::requirement(1), $this->fixtureCard('11202000'));

        $this->assertSame(['length'], array_keys($rows));
        $row = $rows['length'];
        $this->assertSame('ok', $row['status']);
        $this->assertSame('Długość', $row['label']);
        $this->assertSame('ok. 475 mm', $row['required']['text']);
        $this->assertSame('approx', $row['required']['op']);
        $this->assertSame(475, $row['required']['value_mm']);
        $this->assertSame(5, $row['required']['tolerance_pct']);
        $this->assertStringContainsString("długość ok. 475 mm (19'')", $row['required']['quote']);

        $this->assertCount(1, $row['card']);
        $finding = $row['card'][0];
        // Dosłowny fragment karty, nie przeliczone 475 mm — ma dać się znaleźć w źródle.
        $this->assertSame('47,5 cm', $finding['text']);
        $this->assertSame('specs', $finding['source']);
        $this->assertSame('47,5 cm', $finding['find']);
        $this->assertSame(475, $finding['value_mm']);
    }

    public function test_poz1_karta_produkcyjna_wartosc_z_nazwy_nie_psuje_potwierdzonej_dlugosci(): void
    {
        $rows = $this->rows(Opisowy15Fixture::requirement(1), [
            new CardSource(CardSource::NAME, "HyFlex 11202 SIZE 19''/47,5 cm"),
            new CardSource(CardSource::SPECS, 'Długość: 19 cali (47,5 cm)'),
            new CardSource(CardSource::DESCRIPTION, 'Model o długości 19 cali (47,5 cm) w kolorze wysokiej widoczności'),
        ]);

        $row = $rows['length'];
        $this->assertSame('ok', $row['status']);
        $this->assertSame(['specs', 'description'], array_column($row['card'], 'source'));
        $this->assertSame(['47,5 cm', '47,5 cm'], array_column($row['card'], 'text'));
    }

    public function test_wartosc_w_samej_nazwie_bez_nazwy_wymiaru_to_najwyzej_do_sprawdzenia(): void
    {
        $matching = $this->rows('Rękaw ochronny, długość ok. 475 mm', [new CardSource(CardSource::NAME, "HyFlex 11202 SIZE 19''/47,5 cm")]);
        $other = $this->rows('Rękaw ochronny, długość ok. 475 mm', [new CardSource(CardSource::NAME, 'Rękaw HyFlex 11202 30 cm')]);

        foreach ([$matching, $other] as $rows) {
            $this->assertSame('unclear', $rows['length']['status']);
            $this->assertSame(['unclear'], array_column($rows['length']['card'], 'verdict'));
            $this->assertSame([null], array_column($rows['length']['card'], 'find'), 'nazwy nie ma w tekście opisu okna');
        }
    }

    public function test_poz4_fartuch_120_na_75_nazwa_bez_jednostki_i_inne_warianty_to_do_sprawdzenia(): void
    {
        $rows = $this->rows(Opisowy15Fixture::requirement(4), $this->fixtureCard('202'));

        $row = $rows['dimensions'];
        $this->assertSame('unclear', $row['status']);
        $this->assertSame([1200, 750], $row['required']['values_mm']);
        $this->assertSame(['120/75', '75x75 cm', '100x75 cm'], array_column($row['card'], 'text'));
        $this->assertSame(['unclear'], array_values(array_unique(array_column($row['card'], 'verdict'))));
    }

    public function test_poz6_grubosc_soczewki_z_opisu_okularow(): void
    {
        $rows = $this->rows(Opisowy15Fixture::requirement(6), $this->fixtureCard('RUSHPTWI'));

        $this->assertSame(['thickness'], array_keys($rows));
        $this->assertSame('ok', $rows['thickness']['status']);
        $this->assertSame(2.3, $rows['thickness']['required']['value_mm']);
        $this->assertSame([['description', '2,3 mm']], array_map(static fn (array $f): array => [$f['source'], $f['text']], $rows['thickness']['card']));
    }

    public function test_poz15_rekawice_wartosci_bez_jednostki_na_karcie_to_do_sprawdzenia(): void
    {
        $rows = $this->rows(Opisowy15Fixture::requirement(15), $this->fixtureCard('87320100-BULK'));

        $this->assertSame(['length', 'thickness_palm'], array_keys($rows));
        $this->assertSame('unclear', $rows['length']['status']);
        $this->assertSame('300 / 11.8', $rows['length']['card'][0]['text']);
        $this->assertSame('Grubość w części dłoniowej', $rows['thickness_palm']['label']);
        $this->assertSame('unclear', $rows['thickness_palm']['status']);
        $this->assertSame('0.45 / 17.7', $rows['thickness_palm']['card'][0]['text']);
    }

    public function test_bez_wymiaru_w_wymaganiu_nie_ma_wierszy(): void
    {
        // Poz. 2: sam EN 388; poz. 3 i 12: „Rozmiary: 35–48”, „Rozmiary 41–45” bez jednostki.
        foreach ([2 => '34837018', 3 => 'ARMEN 9007 6660 S1 P', 12 => 'T5912100'] as $line => $sku) {
            $this->assertSame([], $this->rows(Opisowy15Fixture::requirement($line), $this->fixtureCard($sku)), "poz. {$line}");
        }
    }

    /**
     * @return array<string, array{0: string, 1: string, 2: string, 3: string, 4: list<float>}>
     */
    public static function parsedValues(): array
    {
        return [
            'cale znakiem' => ["długość 19''", 'length', 'single', 'exact', [482.6]],
            'cale słowem' => ['długość 19 cali', 'length', 'single', 'exact', [482.6]],
            'przecinek dziesiętny' => ['Długość: 47,5 cm', 'length', 'single', 'exact', [475.0]],
            'około' => ['długość ok. 475 mm', 'length', 'single', 'approx', [475.0]],
            'minimum' => ['szerokość min. 30 cm', 'width', 'single', 'min', [300.0]],
            'maksimum' => ['wysokość max 50 mm', 'height', 'single', 'max', [50.0]],
            'zakres z myślnikiem' => ['długość 45–50 cm', 'length', 'range', 'range', [450.0, 500.0]],
            'zakres od do' => ['długość od 45 do 50 cm', 'length', 'range', 'range', [450.0, 500.0]],
            'średnica i wysokość' => ['wymiary ok. Ø 6,6 x 23,5 cm', 'dimensions', 'dims', 'approx', [66.0, 235.0]],
            'średnica' => ['średnica 6 mm', 'diameter', 'single', 'exact', [6.0]],
            'grubość w mm' => ['grubość 0,12 mm', 'thickness', 'single', 'exact', [0.12]],
            'grubość w µm' => ['grubość 120 µm', 'thickness', 'single', 'exact', [0.12]],
        ];
    }

    /**
     * @param  list<float>  $valuesMm
     */
    #[DataProvider('parsedValues')]
    public function test_parser_odczytuje_wymiar_operator_i_jednostke(string $text, string $label, string $kind, string $op, array $valuesMm): void
    {
        $measures = (new DimensionParser)->parse($text);

        $this->assertCount(1, $measures);
        $this->assertSame($label, $measures[0]['label']);
        $this->assertSame($kind, $measures[0]['kind']);
        $this->assertSame($op, $measures[0]['op']);
        $this->assertEqualsWithDelta($valuesMm, $measures[0]['values_mm'], 0.0001);
    }

    public function test_wykluczenia_nie_daja_wierszy(): void
    {
        foreach ([
            'Rozmiary: 35–48',
            'Tkanina o gramaturze 400 g/m², długość rękawa',
            'ochrona zgodnie z EN 388, długość fali nieistotna',
            'soczewka o odporności na uderzenia do 120 m/s',
            'powłoka o długości fali 120 µm',
            'masa 1,981 kg; długość przewodu nieokreślona',
        ] as $requirement) {
            $this->assertSame([], $this->rows($requirement, []), $requirement);
        }
    }

    public function test_wymiary_porownywane_bez_kolejnosci(): void
    {
        $rows = $this->rows('Fartuch 120 × 75 cm', [new CardSource(CardSource::SPECS, 'Wymiary: 75 x 120 cm')]);

        $this->assertSame('ok', $rows['dimensions']['status']);
    }

    public function test_tolerancja_liczby_i_okolo(): void
    {
        $card = static fn (string $value): array => [new CardSource(CardSource::SPECS, "Długość: {$value}")];

        // Sama liczba ±2%: 490 mm to +3,2%.
        $this->assertSame('fail', $this->rows('długość 475 mm', $card('49 cm'))['length']['status']);
        // „ok.” ±5%: 500 mm to +5,26%, 490 mm to +3,2%.
        $this->assertSame('fail', $this->rows('długość ok. 475 mm', $card('50 cm'))['length']['status']);
        $this->assertSame('ok', $this->rows('długość ok. 475 mm', $card('49 cm'))['length']['status']);
    }

    public function test_minimum_maksimum_i_zakres(): void
    {
        $card = static fn (string $value): array => [new CardSource(CardSource::SPECS, "Długość: {$value}")];

        $this->assertSame('ok', $this->rows('długość min. 30 cm', $card('32 cm'))['length']['status']);
        $this->assertSame('fail', $this->rows('długość min. 30 cm', $card('29 cm'))['length']['status']);
        $this->assertSame('ok', $this->rows('długość max. 50 mm', $card('4,5 cm'))['length']['status']);
        $this->assertSame('ok', $this->rows('długość 45–50 cm', $card('47,5 cm'))['length']['status']);
        $this->assertSame(['op' => 'range', 'min_mm' => 450, 'max_mm' => 500], array_intersect_key(
            $this->rows('długość 45–50 cm', $card('47,5 cm'))['length']['required'],
            ['op' => 1, 'min_mm' => 1, 'max_mm' => 1],
        ));
        // Karta „do 60 cm” może mieć 30 albo 55 cm — nie wiemy, czy mieści się w 45–50 cm.
        $this->assertSame('unclear', $this->rows('długość 45–50 cm', $card('do 60 cm'))['length']['status']);
    }

    public function test_dookreslenie_wymiaru_to_osobny_parametr(): void
    {
        // Wymaganie „długość 30 cm” wobec karty z samą długością mankietu — nie wiadomo, czy to ta miara.
        $rows = $this->rows('Rękawice, długość 30 cm', [new CardSource(CardSource::SPECS, 'Długość mankietu: 30 cm')]);
        $this->assertSame('unclear', $rows['length']['status']);

        // Wymaganie o mankiecie nie bierze całkowitej długości rękawicy.
        $rows = $this->rows('Rękawice, długość mankietu 10 cm', [new CardSource(CardSource::SPECS, 'Długość: 30 cm')]);
        $this->assertSame('Długość mankietu', $rows['length_cuff']['label']);
        $this->assertSame('missing', $rows['length_cuff']['status']);
        $this->assertSame([], $rows['length_cuff']['card']);
    }

    public function test_miara_czesci_w_skladni_rzeczownik_o_wymiarze_to_do_sprawdzenia(): void
    {
        // „mankiet o długości 30 cm” to długość mankietu, nie rękawicy — jak „długość mankietu 30 cm”.
        $rows = $this->rows('Rękawice o długości min. 30 cm', [new CardSource(CardSource::DESCRIPTION, 'Rękawica nitrylowa, mankiet o długości 30 cm')]);
        $this->assertSame('unclear', $rows['length']['status']);
        $this->assertStringContainsString('Długość mankietu', (string) $rows['length']['note']);

        $rows = $this->rows('Obuwie o wysokości min. 15 cm', [new CardSource(CardSource::DESCRIPTION, 'Cholewka o wysokości 16 cm')]);
        $this->assertSame('unclear', $rows['height']['status']);

        // Przymiotnik między częścią a „o” nie gubi dookreślenia.
        $rows = $this->rows('Rękawice, długość 30 cm', [new CardSource(CardSource::DESCRIPTION, 'Mankiet ściągaczowy o długości 30 cm')]);
        $this->assertSame('unclear', $rows['length']['status']);

        // Ta sama część po obu stronach — ten sam klucz i pewny wynik.
        $rows = $this->rows('Rękawice, mankiet o długości min. 10 cm', [new CardSource(CardSource::SPECS, 'Długość mankietu: 12 cm')]);
        $this->assertSame('ok', $rows['length_cuff']['status']);
    }

    public function test_rzeczownik_wyrobu_przed_o_wymiarze_nie_jest_czescia(): void
    {
        foreach (['Model', 'Rękaw', 'Produkt', 'Rękawica', 'Wyrób'] as $noun) {
            $rows = $this->rows('długość ok. 475 mm', [new CardSource(CardSource::DESCRIPTION, "{$noun} o długości 19 cali (47,5 cm)")]);
            $this->assertSame(['length'], array_keys($rows), $noun);
            $this->assertSame('ok', $rows['length']['status'], $noun);
        }
        // „Soczewki wykonane z poliwęglanu o grubości” — grubość okularów, jak w wymaganiu poz. 6.
        $rows = $this->rows('soczewki z poliwęglanu o grubości 2,3 mm', [new CardSource(CardSource::DESCRIPTION, 'Soczewki o grubości 2,3 mm')]);
        $this->assertSame('ok', $rows['thickness']['status']);
    }

    public function test_okolo_na_karcie_to_przedzial_piec_procent(): void
    {
        $card = static fn (string $value): array => [new CardSource(CardSource::SPECS, "Długość: {$value}")];

        // „ok. 30 cm” to 28,5–31,5 cm — częściowo poniżej minimum.
        $row = $this->rows('długość min. 30 cm', $card('ok. 30 cm'))['length'];
        $this->assertSame('unclear', $row['status']);
        $this->assertSame('approx', $row['card'][0]['op']);
        $this->assertSame('unclear', $this->rows('długość max. 30 cm', $card('ok. 30 cm'))['length']['status']);
        // Cały przedział w wymaganiu albo całkiem poza.
        $this->assertSame('ok', $this->rows('długość min. 30 cm', $card('ok. 35 cm'))['length']['status']);
        $this->assertSame('fail', $this->rows('długość min. 30 cm', $card('ok. 25 cm'))['length']['status']);
        $this->assertSame('ok', $this->rows('długość ok. 475 mm', $card('ok. 47,5 cm'))['length']['status']);
        $this->assertSame('unclear', $this->rows('Wymiary: 120 x 75 cm', [new CardSource(CardSource::SPECS, 'Wymiary: ok. 120 x 75 cm')])['dimensions']['status']);
    }

    public function test_kilka_wartosci_tej_samej_miary_to_warianty(): void
    {
        $card = [new CardSource(CardSource::SPECS, 'Długość: 28 cm, 30 cm, 32 cm')];

        $row = $this->rows('długość 34 cm', $card)['length'];
        $this->assertSame('unclear', $row['status']);
        $this->assertSame(['unclear'], array_values(array_unique(array_column($row['card'], 'verdict'))));

        $row = $this->rows('długość 30 cm', $card)['length'];
        $this->assertSame('ok', $row['status']);
        $this->assertSame(['30 cm'], array_column($row['card'], 'text'));

        // Pojedyncza wartość dalej daje pewny „nie spełnia”.
        $this->assertSame('fail', $this->rows('długość 34 cm', [new CardSource(CardSource::SPECS, 'Długość: 30 cm')])['length']['status']);
    }

    public function test_cudzyslow_i_in_to_cale_tylko_przy_liczbie(): void
    {
        // Zamykający cytat to nie cal — zostaje liczba bez jednostki, jak „DŁUGOŚĆ 300 / 11.8”.
        $row = $this->rows('długość 30 cm', [new CardSource(CardSource::DESCRIPTION, 'Model "Długość 30" rozm. 9')])['length'];
        $this->assertSame('unclear', $row['status']);
        $this->assertSame(['30'], array_column($row['card'], 'text'));
        $this->assertNotSame('ok', $this->rows('szerokość 5 in', [new CardSource(CardSource::DESCRIPTION, 'Szerokość 5 in 1 zestaw')])['width']['status'] ?? 'missing');
        $this->assertSame([], (new DimensionParser)->parse('Szerokość 5 in 1 zestaw')[0]['values_mm'] ?? []);

        foreach (["długość 19''", 'długość 19"', 'długość 19 "', 'długość 19″', 'długość 19 cali', 'długość 19 in'] as $text) {
            $measures = (new DimensionParser)->parse($text);
            $this->assertCount(1, $measures, $text);
            $this->assertEqualsWithDelta([482.6], $measures[0]['values_mm'], 0.0001, $text);
        }
        // Dwa rozmiary w calach w jednym zdaniu — pierwszy znak cala nie otwiera cytatu.
        $this->assertEqualsWithDelta([482.6, 533.4], array_merge(...array_column((new DimensionParser)->parse('Długość: 19" lub 21"'), 'values_mm')), 0.0001);
    }

    /**
     * Fałszywe „spełnia” to najgorszy błąd — te pary nigdy nie mogą dać ok.
     *
     * @return array<string, array{0: string, 1: string}>
     */
    public static function neverOk(): array
    {
        return [
            'mankiet o długości' => ['rękawice o długości min. 30 cm', 'Rękawica nitrylowa, mankiet o długości 30 cm'],
            'cholewka o wysokości' => ['obuwie o wysokości min. 15 cm', 'Cholewka o wysokości 16 cm'],
            'mankiet w drugim zdaniu' => ['długość min. 30 cm', 'Długość rękawicy: 24 cm. Mankiet o długości 30 cm.'],
            'podeszwa o grubości' => ['grubość min. 5 mm', 'Podeszwa o grubości 8 mm'],
            'ok. na karcie przy minimum' => ['długość min. 30 cm', 'Długość ok. 30 cm'],
            'ok. na karcie przy maksimum' => ['długość max. 30 cm', 'Długość ok. 30 cm'],
            'ok. na karcie przy liczbie' => ['długość 30 cm', 'Długość ok. 30 cm'],
            'cytat z liczbą' => ['długość 30 cm', 'Model "Długość 30" rozm. 9'],
        ];
    }

    #[DataProvider('neverOk')]
    public function test_nigdy_ok(string $requirement, string $card): void
    {
        foreach ((new DimensionChecker)->check($requirement, [new CardSource(CardSource::DESCRIPTION, $card)]) as $row) {
            $this->assertNotSame('ok', $row->toArray()['status'], $row->key);
        }
    }

    /**
     * Tryb sprzeczności dla limitu oceny w wyszukiwarce (decyzja właściciela 25.09.2026): wymiar bez „min./max.” to minimum,
     * więc karta większa nie przeczy wymaganiu, a mniejsza o więcej niż 5% — tak.
     *
     * @return array<string, array{0: string, 1: string, 2: string}>
     */
    public static function contradictionVerdicts(): array
    {
        return [
            'liczba: większa karta' => ['długość 300 mm', 'Długość: 320 mm', 'ok'],
            'liczba: mniejsza w 5%' => ['długość 300 mm', 'Długość: 290 mm', 'ok'],
            'liczba: mniejsza o więcej niż 5%' => ['długość 300 mm', 'Długość: 280 mm', 'fail'],
            'ok.: dłuższy rękaw' => ['Rękaw ochronny, długość ok. 475 mm', 'Długość: 60 cm', 'ok'],
            'ok.: krótszy rękaw' => ['Rękaw ochronny, długość ok. 475 mm', 'Długość: 30 cm', 'fail'],
            'min.: mniejsza w 5%' => ['długość min. 30 cm', 'Długość: 29 cm', 'ok'],
            'min.: mniejsza o więcej niż 5%' => ['długość min. 30 cm', 'Długość: 28 cm', 'fail'],
            'max.: mniejsza' => ['długość max. 30 cm', 'Długość: 20 cm', 'ok'],
            'max.: większa w 5%' => ['długość max. 30 cm', 'Długość: 31 cm', 'ok'],
            'max.: większa o więcej niż 5%' => ['długość max. 30 cm', 'Długość: 32 cm', 'fail'],
            'wymiary: większy fartuch' => ['Fartuch wodoochronny 120 × 75 cm', 'Wymiary: 130 x 80 cm', 'ok'],
            'wymiary: krótszy fartuch' => ['Fartuch wodoochronny 120 × 75 cm', 'Wymiary: 110 x 75 cm', 'fail'],
        ];
    }

    #[DataProvider('contradictionVerdicts')]
    public function test_tryb_sprzecznosci_wymiar_bez_min_max_to_minimum(string $requirement, string $card, string $expected): void
    {
        $rows = DimensionChecker::forContradictions()->check($requirement, [new CardSource(CardSource::SPECS, $card)]);

        $this->assertCount(1, $rows);
        $this->assertSame($expected, $rows[0]->status->value, $rows[0]->key);
    }

    public function test_okno_weryfikacji_karty_bez_zmian_po_trybie_sprzecznosci(): void
    {
        // Okno „Weryfikacja karty” porównuje dokładnie (±2%, ok. ±5%) w obie strony — większy wymiar to tam dalej „nie spełnia”.
        $this->assertSame('fail', $this->rows('długość 300 mm', [new CardSource(CardSource::SPECS, 'Długość: 320 mm')])['length']['status']);
        $this->assertSame('fail', $this->rows('długość 300 mm', [new CardSource(CardSource::SPECS, 'Długość: 290 mm')])['length']['status']);
        $this->assertSame('fail', $this->rows('Rękaw ochronny, długość ok. 475 mm', [new CardSource(CardSource::SPECS, 'Długość: 60 cm')])['length']['status']);
        $this->assertSame(2, $this->rows('długość 300 mm', [new CardSource(CardSource::SPECS, 'Długość: 300 mm')])['length']['required']['tolerance_pct']);
    }

    /**
     * @param  list<CardSource>  $sources
     * @return array<string, array<string, mixed>>
     */
    private function rows(string $requirement, array $sources): array
    {
        $out = [];
        foreach ((new DimensionChecker)->check($requirement, $sources) as $row) {
            $this->assertInstanceOf(CheckRow::class, $row);
            $out[$row->key] = $row->toArray();
        }

        return $out;
    }

    /**
     * @return list<CardSource>
     */
    private function fixtureCard(string $sku): array
    {
        return CardSources::fromProduct(Opisowy15Fixture::product($sku));
    }
}
