<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Models\Product;
use App\Support\ProductModelFuzzy;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Klient pisze „ARTRA ARMEN 9007 1010 S1”, a igła modelu niesie tylko „armen9007” — wszystkie
 * warianty rodziny (6660, 9360) dostawały przez to identyczne 99% i najtańszy wchodził do oferty
 * zamiast żądanego. Oznaczenie wariantu musi się liczyć, ale nie wolno brać za nie wymiarów,
 * rozmiarów ani numerów norm.
 */
final class ProductModelFuzzyVariantCodeTest extends TestCase
{
    use RefreshDatabase;

    private const QUERY = 'buty firmy ARTRA model ARMEN 9007 1010 S1';

    private function fuzzy(): ProductModelFuzzy
    {
        return $this->app->make(ProductModelFuzzy::class);
    }

    public function test_variant_code_next_to_the_model_is_picked_up(): void
    {
        // 9007 siedzi już w igle „armen9007”, więc zostaje samo 1010.
        $this->assertSame(['1010'], $this->fuzzy()->variantCodes(self::QUERY));
    }

    public function test_card_with_other_variant_reports_the_missing_code(): void
    {
        $this->assertSame(
            ['1010'],
            $this->fuzzy()->missingVariantCodes(self::QUERY, $this->makeProduct('ARMEN 9007 6660 S1'))
        );
    }

    public function test_card_with_requested_variant_has_nothing_missing(): void
    {
        foreach (['ARMEN 9007 1010 S1', 'ARMEN 9007 1010 S1 P ESD', 'ARMEN 9007 Clip 1010 S1'] as $sku) {
            $this->assertSame(
                [],
                $this->fuzzy()->missingVariantCodes(self::QUERY, $this->makeProduct($sku)),
                $sku
            );
        }
    }

    /** Wiersz propozycji nazywa wariant karty (kolor), nie tylko brak oznaczenia z zapytania. */
    public function test_card_variant_codes_name_what_the_card_has_instead(): void
    {
        $fuzzy = $this->fuzzy();

        $this->assertSame(['6660'], $fuzzy->otherVariantCodes(self::QUERY, $this->makeProduct('ARMEN 9007 6660 S1')));
        $this->assertSame(['9360'], $fuzzy->otherVariantCodes(self::QUERY, $this->makeProduct('ARMEN 9007 9360 S1 ESD')));
        // Numer modelu z igły („9007”) i żądany wariant nie są „innym wariantem”.
        $this->assertSame([], $fuzzy->otherVariantCodes(self::QUERY, $this->makeProduct('ARMEN 9007 1010 S1 P ESD')));
        // Rok i numer normy w nazwie karty to nie wariant (recenzja 25.09.2026: „na karcie 6660, 2011”).
        $this->assertSame(['6660'], $fuzzy->otherVariantCodes(
            'Półbuty ARMEN 9007 1010 S1 SRC EN ISO 20345:2011',
            $this->makeProduct('Półbuty ARMEN 9007 6660 S1 SRC EN ISO 20345:2011 EN 1149-5')
        ));
        // Wymaganie bez oznaczenia wariantu — nie ma czego porównywać.
        $this->assertSame([], $fuzzy->otherVariantCodes('Kalosze chemoodporne antyelektrostatyczne rozmiar 43', $this->makeProduct('ARMEN 9007 6660 S1')));
    }

    public function test_norms_dimensions_and_sizes_are_not_variant_codes(): void
    {
        $fuzzy = $this->fuzzy();

        // Norma z rokiem, wymiary w centymetrach i rozmiar buta nie są oznaczeniem wariantu.
        $this->assertSame([], $fuzzy->variantCodes('Fartuch wodoochronny 120 x 75 cm EN 343'));
        $this->assertSame([], $fuzzy->variantCodes('Rękawice chemoodporne EN ISO 374-1 rozmiar 10'));
        $this->assertSame([], $fuzzy->variantCodes('Półmaska wielokrotnego użytku PN-EN 140:2004'));
        // Kod z kropką: 8543 wchodzi już w igłę modelu, więc nie staje się dodatkowym warunkiem.
        $this->assertSame([], $fuzzy->variantCodes('BUTY UVEX BUSINESS CASUAL 8543.8 S1 SRC ROZMIAR 44'));
    }

    /** Ilość z zamówienia to nie oznaczenie wariantu: przy „…X2 nagłowna 1500 szt” wszystkie karty modelu miały 60%. */
    public function test_ordered_quantity_is_not_a_variant_code(): void
    {
        $fuzzy = $this->fuzzy();

        $this->assertSame([], $fuzzy->variantCodes('Nauszniki przeciwhałasowe 3M Peltor X2 wersja nagłowna 1500 szt'));
        $this->assertSame([], $fuzzy->variantCodes('Nauszniki przeciwhałasowe 3M Peltor X2 wersja nagłowna 2000 szt.'));
        $this->assertSame(['1010'], $fuzzy->variantCodes('Półbuty ARTRA ARMEN 9007 1010 S1 SRC, ilość 1200 par'));
        $this->assertSame(['6060'], $fuzzy->variantCodes('Trzewiki ARTRA ARAUKAN 940 6060 S3'));
        // Litera rozmiaru po kolorze to nie jednostka („L” to nie litry, „M” to nie metry).
        $this->assertSame(['1010'], $fuzzy->variantCodes('Półbuty ARTRA ARMEN 9007 1010 L'));
        $this->assertSame(['1010'], $fuzzy->variantCodes('Półbuty ARTRA ARMEN 9007 1010 M'));
    }

    public function test_query_without_named_model_has_no_variant_codes(): void
    {
        $this->assertSame([], $this->fuzzy()->variantCodes('Kalosze chemoodporne antyelektrostatyczne rozmiar 43'));
    }

    /**
     * Automat przetargu odrzuca karty innego wariantu, więc liczba spoza miejsca oznaczenia wariantu — rozporządzenie,
     * norma IEC, zmiana normy, napięcie, data — nie może być kodem: robiła z każdej karty modelu „inny wariant”.
     */
    public function test_numbers_away_from_the_model_are_not_variant_codes(): void
    {
        $fuzzy = $this->fuzzy();

        foreach ([
            'buty firmy ARTRA model ARMEN 9007 1010 S1 zgodne z rozporządzeniem (UE) 2016/425',
            'Półbuty ARTRA ARMEN 9007 1010 S1 zgodne z rozporządzeniem nr 2016/425',
            'Półbuty ARTRA ARMEN 9007 1010 S1 REACH (WE) 1907/2006',
            'Półbuty ARTRA ARMEN 9007 1010 S1 P ESD wg EN IEC 61340-4-3:2018',
            'Półbuty ARTRA ARMEN 9007 1010 S1 EN ISO 20471:2013/A1:2016',
            'Półbuty ARTRA ARMEN 9007 1010 S1, izolacja do 1000 V',
            'Półbuty ARTRA ARMEN 9007 1010 S1, data produkcji 09.03.2025, dostawa w 2026 r.',
        ] as $query) {
            $this->assertSame(['1010'], $fuzzy->variantCodes($query), $query);
        }
        $this->assertSame([], $fuzzy->variantCodes('Półbuty ARTRA ARMEN 9007 S1 zgodne z rozporządzeniem (UE) 2016/425'));
    }

    /** Klasy obuwia między numerem modelu a kolorem nie zajmują miejsca w oknie; „kolor” też zapowiada kod. */
    public function test_class_markers_and_colour_word_lead_to_the_variant_code(): void
    {
        $fuzzy = $this->fuzzy();

        $this->assertSame(['1010'], $fuzzy->variantCodes('Półbuty ARTRA ARMEN 9007 S1 P kolor 1010'));
        // „SRC 1010” składa też igłę „src1010” — to nie inny model, który zabierałby kod
        $this->assertSame(['1010'], $fuzzy->variantCodes('Półbuty ARTRA ARMEN 9007 S1 SRC 1010'));
        $this->assertSame(['1010'], $fuzzy->variantCodes('Półbuty ARTRA ARMEN 9007 w kolorze 1010'));
    }

    /**
     * Sonda automatu przetargu z 26.09.2026: „2016” z rozporządzenia robiło z „HYCRON 27-600” zapytanie z oznaczeniem
     * wariantu, a wtedy remis rozstrzygał kod dystrybutora „27-600” złożony z nazwy modelu.
     */
    public function test_regulation_number_does_not_make_a_variant_query(): void
    {
        $query = 'Rękawice powlekane nitrylem Ansell HYCRON 27-600 · EN ISO 21420 EN 388 zgodne z (UE) 2016/425';

        $this->assertSame([], $this->fuzzy()->variantCodes($query));
        $this->assertFalse($this->fuzzy()->variantSkuWrittenInQuery($query, $this->makeProduct('27-600')));
    }

    public function test_other_variant_card_and_card_code_written_by_the_client(): void
    {
        $fuzzy = $this->fuzzy();

        $this->assertTrue($fuzzy->isOtherVariant(self::QUERY, $this->makeProduct('ARMEN 9007 6660 S1')));
        $this->assertFalse($fuzzy->isOtherVariant(self::QUERY, $this->makeProduct('ARMEN 9007 Clip 1010 S1')));

        $this->assertTrue($fuzzy->variantSkuWrittenInQuery(self::QUERY, $this->makeProduct('ARMEN 9007 1010 S1')));
        $this->assertFalse($fuzzy->variantSkuWrittenInQuery(self::QUERY, $this->makeProduct('ARMEN 9007 Clip 1010 S1')));
        $this->assertFalse($fuzzy->variantSkuWrittenInQuery(self::QUERY, $this->makeProduct('ARMEN 9007 6660 S1')));
    }

    /**
     * Numer katalogowy z kreską tuż za nazwą modelu jest za długi na parę słowo + numer, a igła niesie samą nazwę
     * („maxiflex”) — jego człony odróżniają modele i kolory linii. Data w tym miejscu numerem nie jest.
     */
    public function test_article_number_right_after_the_model_name_gives_variant_codes(): void
    {
        $fuzzy = $this->fuzzy();

        $this->assertSame(['8743'], $fuzzy->variantCodes('Rękawice antyprzecięciowe ATG MaxiFlex Cut 34-8743 do prac precyzyjnych'));
        $this->assertSame(['1809'], $fuzzy->variantCodes('Kurtka membranowa MASCOT ACCELERATE 19999-249-1809, ciemny antracyt/czerń'));
        $this->assertSame(['9007', '1010'], $fuzzy->variantCodes('Półbuty ARTRA ARMEN 9007-1010 S1'));
        $this->assertSame([], $fuzzy->variantCodes('Rękawice ATG MaxiFlex 09.03.2025'));
    }

    /** Karta nazywa swój kolor, a nie rok normy IEC, numer rozporządzenia czy napięcie z nazwy. */
    public function test_card_variant_codes_skip_regulations_iec_norms_and_quantities(): void
    {
        $this->assertSame(['6660'], $this->fuzzy()->otherVariantCodes(
            self::QUERY,
            $this->makeProduct('ARMEN 9007 6660 S1 P ESD wg EN IEC 61340-4-3:2018 (UE) 2016/425, 1000 V')
        ));
    }

    /** Pamięć oznaczeń wariantu (karta po karcie, także przy sortowaniu) nie miesza wymagań. */
    public function test_variant_codes_cache_does_not_mix_requirements(): void
    {
        $fuzzy = new ProductModelFuzzy;
        $araukan = 'Trzewiki ARTRA ARAUKAN 940 6060 S3';

        $this->assertSame(['1010'], $fuzzy->variantCodes(self::QUERY));
        $this->assertSame(['6060'], $fuzzy->variantCodes($araukan));
        $this->assertSame([], $fuzzy->variantCodes('Kalosze chemoodporne antyelektrostatyczne rozmiar 43'));
        $this->assertSame(['1010'], $fuzzy->variantCodes(self::QUERY));
        $this->assertSame(['6060'], $fuzzy->variantCodes($araukan));
    }

    /**
     * Przegląd 26.09.2026: numery za słowem „serii”, przyimkiem („do”, „dla”) i spójnikiem („i”, „oraz”) to inne
     * wyroby albo liczby, a nie kolor modelu — pochłaniacz 3M 6059 „do półmasek serii 6000 i 7500” dostawał kod 7500.
     */
    public function test_series_lists_prepositions_and_conjunctions_end_the_variant_window(): void
    {
        $fuzzy = $this->fuzzy();

        foreach ([
            'Pochłaniacz 3M 6059 ABEK1 do półmasek 3M serii 6000 i 7500',
            'Pochłaniacz 3M 6059 ABEK1 do półmasek 3M serii 6000, 7500',
            'Pochłaniacz 3M 6059 ABEK1 do półmasek 3M serii 6000/7500',
            'Pochłaniacz 3M 6059 ABEK1 do półmasek 3M serii 6000, 6500 i 7500',
            'Filtr Moldex 9430 do półmasek Moldex seria 7000 i 9000',
            'Filtr Moldex 9430 do 7000',
            'Moldex 9200 do 7002 i 7003',
            'Peltor Optime 1000 dla 3000 pracowników',
            // „serii 6000” to też para słowo + numer — numer za nią to kolejny model z listy, nie kolor
            'Pochłaniacz 3M 6059 ABEK1 do półmasek serii 6000 7500',
        ] as $query) {
            $this->assertSame([], $fuzzy->variantCodes($query), $query);
        }
    }

    /**
     * Myślnik nie zamyka okna, więc liczba tuż za nim bywa w miejscu koloru — ilość, miara i rok to jednak nie kolor.
     * Okno ma dwa słowa: dalsza liczba to już inna część opisu.
     */
    public function test_quantity_unit_or_year_in_the_window_and_numbers_past_it_are_not_variant_codes(): void
    {
        $fuzzy = $this->fuzzy();

        foreach ([
            'Półbuty ARTRA ARMEN 9007 S1 – 1500 par',
            'Półbuty ARTRA ARMEN 9007 S1 – 1000 V',
            'Półbuty ARTRA ARMEN 9007 S1 – 2026 r.',
            'Półbuty ARTRA ARMEN 9007 S1 – 2026r.',
            'Półbuty ARTRA ARMEN 9007 S1 – 2025 roku',
            'Półbuty ARTRA ARMEN 9007 S1 obuwie robocze męskie 2000',
        ] as $query) {
            $this->assertSame([], $fuzzy->variantCodes($query), $query);
        }
        $this->assertSame(['1010'], $fuzzy->variantCodes('Półbuty ARTRA ARMEN 9007 S1 – 1010'));
    }

    /** Ukośnik kończy okno — kosztem zapisu „9007/1010”: wolimy nie zgadnąć koloru niż wziąć numer innego wyrobu. */
    public function test_slash_after_the_model_number_ends_the_window(): void
    {
        $this->assertSame([], $this->fuzzy()->variantCodes('Półbuty ARTRA ARMEN 9007/1010 S1'));
    }

    /** Inny wariant to karta tego samego modelu bez żądanego kodu — także z literówką i numerem zapisanym inaczej. */
    public function test_other_variant_is_a_card_of_the_same_model(): void
    {
        $fuzzy = $this->fuzzy();

        foreach (['ARMEM 9007 6660 S1', 'Armen9007 6660', 'ARMEN 9007-6660 S1', 'Półbuty ARTRA ARMEN czarne S1 (9007/6660)'] as $card) {
            $this->assertTrue($fuzzy->isOtherVariant(self::QUERY, $this->makeProduct($card)), $card);
        }
        // Karta innego wyrobu nie jest innym wariantem nazwanego modelu — nie ma czego brakować.
        $reis = $this->makeProduct('REIS BRS S1');
        $this->assertFalse($fuzzy->isOtherVariant(self::QUERY, $reis));
        $this->assertSame([], $fuzzy->missingVariantCodes(self::QUERY, $reis));
        $this->assertSame([], $fuzzy->otherVariantCodes(self::QUERY, $this->makeProduct('REIS BRS 6660 S1')));
    }

    /** Kod z numeru katalogowego należy do modelu, za którym stoi: HyFlex pod MaxiFlex to inny wyrób, nie wariant. */
    public function test_catalogue_number_code_belongs_to_its_model(): void
    {
        $query = 'Rękawice antyprzecięciowe ATG MaxiFlex Cut 34-8743 lub równoważne, EN 388';

        $this->assertFalse($this->fuzzy()->isOtherVariant($query, $this->makeProduct('Ansell HyFlex 11-541')));
        $this->assertTrue($this->fuzzy()->isOtherVariant($query, $this->makeProduct('ATG MaxiFlex Cut 34-8443')));
        $this->assertTrue($this->fuzzy()->isRequestedVariant($query, $this->makeProduct('ATG MaxiFlex Cut 34-8743')));
    }

    public function test_requested_variant_is_a_card_of_the_model_with_every_requested_code(): void
    {
        $fuzzy = $this->fuzzy();

        $this->assertTrue($fuzzy->isRequestedVariant(self::QUERY, $this->makeProduct('ARMEN 9007 1010 S1')));
        $this->assertFalse($fuzzy->isRequestedVariant(self::QUERY, $this->makeProduct('ARMEN 9007 6660 S1')));
        $this->assertFalse($fuzzy->isRequestedVariant(self::QUERY, $this->makeProduct('REIS BRS S1')));
        // Bez oznaczenia wariantu w wymaganiu nie ma czego potwierdzać.
        $this->assertFalse($fuzzy->isRequestedVariant('Półbuty ARTRA ARMEN 9007 S1', $this->makeProduct('ARMEN 9007 6660 S1')));
    }

    /** Dwa modele z kolorami w jednej pozycji: każdy kod liczy się tylko dla swojego modelu. */
    public function test_two_models_with_colours_in_one_line(): void
    {
        $fuzzy = $this->fuzzy();
        $query = 'Półbuty ARTRA ARMEN 9007 1010 S1 oraz ARICA 6207 6660 S2';

        $this->assertSame(['1010', '6660'], $fuzzy->variantCodes($query));
        $this->assertFalse($fuzzy->isOtherVariant($query, $this->makeProduct('ARICA 6207 6660 S2')));
        $this->assertTrue($fuzzy->isRequestedVariant($query, $this->makeProduct('ARICA 6207 6660 S2')));
        $this->assertSame(['6660'], $fuzzy->missingVariantCodes($query, $this->makeProduct('ARICA 6207 1010 S2')));
        $this->assertSame(['1010'], $fuzzy->otherVariantCodes($query, $this->makeProduct('ARICA 6207 1010 S2')));
        $this->assertSame(['1010'], $fuzzy->missingVariantCodes($query, $this->makeProduct('ARMEN 9007 6660 S1')));
        $this->assertTrue($fuzzy->isRequestedVariant($query, $this->makeProduct('ARMEN 9007 1010 S1')));
    }

    /**
     * Ten sam model w dwóch kolorach do wyboru: kotwice jednego modelu są alternatywami — karta spełniająca którąkolwiek
     * jest żądanym wariantem, a brakuje dopiero karcie, która nie spełnia żadnej. W jednej kotwicy dalej potrzeba
     * wszystkich kodów („9007-1010”).
     */
    public function test_alternative_colours_of_one_model(): void
    {
        $fuzzy = $this->fuzzy();

        foreach (['Półbuty ARTRA ARMEN 9007 1010 S1 lub ARMEN 9007 6660 S1', 'Półbuty ARTRA ARMEN 9007 1010 S1, ARMEN 9007 6660 S1'] as $query) {
            $this->assertSame(['1010', '6660'], $fuzzy->variantCodes($query), $query);
            foreach (['ARMEN 9007 1010 S1', 'ARMEN 9007 6660 S1'] as $sku) {
                $card = $this->makeProduct($sku);
                $this->assertSame([], $fuzzy->missingVariantCodes($query, $card), $query.' / '.$sku);
                $this->assertTrue($fuzzy->isRequestedVariant($query, $card), $query.' / '.$sku);
            }
            $other = $this->makeProduct('ARMEN 9007 9360 S1');
            $this->assertSame(['1010', '6660'], $fuzzy->missingVariantCodes($query, $other), $query);
            $this->assertFalse($fuzzy->isRequestedVariant($query, $other), $query);
        }
        $this->assertFalse($fuzzy->isRequestedVariant('Półbuty ARTRA ARMEN 9007-1010 S1', $this->makeProduct('ARMEN 9008 1010 S1')));
        $this->assertSame(['9007'], $fuzzy->missingVariantCodes('Półbuty ARTRA ARMEN 9007-1010 S1', $this->makeProduct('ARMEN 9008 1010 S1')));
        // „w kolorze” rozstrzyga tylko o karcie bez kodu przy modelu: kolor sznurówek nie jest alternatywą dla „1010”.
        $laces = 'Półbuty ARTRA ARMEN 9007 1010 S1, sznurówki zapasowe w kolorze 6660';
        $this->assertSame(['1010', '6660'], $fuzzy->variantCodes($laces));
        $this->assertSame(['1010'], $fuzzy->missingVariantCodes($laces, $this->makeProduct('ARMEN 9007 6660 S1')));
        $this->assertSame(['6660'], $fuzzy->otherVariantCodes($laces, $this->makeProduct('ARMEN 9007 6660 S1')));
        $this->assertFalse($fuzzy->isRequestedVariant($laces, $this->makeProduct('ARMEN 9007 6660 S1')));
        $this->assertTrue($fuzzy->isRequestedVariant($laces, $this->makeProduct('ARMEN 9007 1010 S1')));
        $this->assertSame([], $fuzzy->missingVariantCodes($laces, $this->makeProduct('ARMEN 9007 1010 S1')));
        // bez kodu przy modelu kolor dalej wskazuje wariant
        $colourOnly = 'Półbuty ARTRA ARMEN 9007 S1 w kolorze 1010';
        $this->assertTrue($fuzzy->isRequestedVariant($colourOnly, $this->makeProduct('ARMEN 9007 1010 S1')));
        $this->assertSame(['1010'], $fuzzy->missingVariantCodes($colourOnly, $this->makeProduct('ARMEN 9007 6660 S1')));
        // Spełniona kotwica innego modelu nie znosi braku: „ARICA 6207 1010” ma kod ARMEN-a, ale nie swój 6660.
        $twoModels = 'Półbuty ARTRA ARMEN 9007 1010 S1 oraz ARICA 6207 6660 S2';
        $this->assertSame(['6660'], $fuzzy->missingVariantCodes($twoModels, $this->makeProduct('ARICA 6207 1010 S2')));
        $this->assertFalse($fuzzy->isRequestedVariant($twoModels, $this->makeProduct('ARICA 6207 1010 S2')));
    }

    /**
     * Słowo, numer i kod koloru nazwanego modelu są dowodem kodu tylko dla kart tego modelu — SKU „1010” albo „9007”
     * innego wyrobu to przypadek (ProductMatchService::strongSkuMatches).
     */
    public function test_model_designation_parts_and_cards_of_the_named_model(): void
    {
        $fuzzy = $this->fuzzy();

        $this->assertSame(['armen', '9007', '1010'], $fuzzy->variantAnchorParts(self::QUERY));
        $this->assertSame([], $fuzzy->variantAnchorParts('Półbuty ARTRA ARMEN 9007 S1'));
        $this->assertSame(['maxiflex', '8743'], $fuzzy->variantAnchorParts('Rękawice antyprzecięciowe ATG MaxiFlex Cut 34-8743'));
        $this->assertTrue($fuzzy->isVariantModelCard(self::QUERY, $this->makeProduct('ARMEN 9007 6660 S1')));
        $this->assertTrue($fuzzy->isVariantModelCard(self::QUERY, $this->makeProduct('ARMEN 9007 1010 S1')));
        $this->assertFalse($fuzzy->isVariantModelCard(self::QUERY, $this->makeProduct('1010')));
        $this->assertFalse($fuzzy->isVariantModelCard(self::QUERY, $this->makeProduct('REIS BRS S1')));
    }

    /** Tekst igieł i par słowo + numer bez aktów prawnych, norm IEC i zmian norm po ukośniku. */
    public function test_strip_norms_removes_regulations_iec_norms_and_slash_amendments(): void
    {
        $fuzzy = $this->fuzzy();

        $this->assertSame(
            'polbuty armen 9007 1010 s1',
            $fuzzy->stripNorms('Półbuty ARMEN 9007 1010 S1 EN ISO 20471:2013/A1:2016 EN IEC 61340-4-3:2018 (UE) 2016/425')
        );
        // „IEC 61482” był igłą nazwanego modelu
        $this->assertNotContains('iec61482', $fuzzy->needles('Ubranie ochronne dla elektryków EN 1149-5 IEC 61482'));
        // dwie serie półmasek to nie akt prawny — także po słowie „rozporządzenia”, gdy między nimi stoi zwykłe słowo
        $this->assertSame('pochlaniacz do polmasek secura 2000/3000', $fuzzy->stripNorms('Pochłaniacz do półmasek SECURA 2000/3000'));
        $this->assertContains('secura2000', $fuzzy->needles('Półmaska zgodna z wymaganiami rozporządzenia np. SECURA 2000/3000'));
    }

    private function makeProduct(string $sku): Product
    {
        return new Product([
            'sku' => $sku,
            'name' => $sku,
            'manufacturer' => 'ARTRA',
        ]);
    }
}
