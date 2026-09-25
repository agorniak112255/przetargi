<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Models\Product;
use App\Support\ProductModelFuzzy;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

final class ProductModelFuzzyTest extends TestCase
{
    private ProductModelFuzzy $fuzzy;

    protected function setUp(): void
    {
        parent::setUp();
        $this->fuzzy = new ProductModelFuzzy;
    }

    /** Pamięć igieł (wydajność: score() woła needles() dla każdej karty) nie miesza wymagań. */
    #[Test]
    public function needles_cache_does_not_mix_requirements(): void
    {
        $first = 'Rękawice MAPA TEPM-ICE 700 · EN 388 EN 511 EN ISO 21420';
        $second = 'Okulary ochronne MSA PERSPECTA 010';
        $expectedFirst = (new ProductModelFuzzy)->needles($first);
        $expectedSecond = (new ProductModelFuzzy)->needles($second);

        $this->assertSame($expectedFirst, $this->fuzzy->needles($first));
        $this->assertSame($expectedSecond, $this->fuzzy->needles($second));
        $this->assertSame($expectedFirst, $this->fuzzy->needles($first));
        $this->assertNotSame($expectedFirst, $expectedSecond);
    }

    /** 23.09.2026: „CEDERROTH 6036” — marka stoi w polu producenta, kod w SKU, nazwa nie ma ani jednego z nich razem. */
    #[Test]
    public function brand_in_manufacturer_and_code_in_sku_match_named_model(): void
    {
        $req = 'Zestaw plastrów plastikowych CEDERROTH 6036';

        $this->assertTrue($this->fuzzy->matches($req, $this->product(
            '6036',
            'Plastry plastikowe Cederroth Salvequick, 45 szt.',
            'CEDERROTH',
        )));
        // Ten sam kod z literą przed nim, inna marka — to nie jest nazwany wyrób.
        $this->assertFalse($this->fuzzy->matches($req, $this->product(
            'A6036',
            'Rękawice termoizolacyjne CRUSADER FLEX 42-474',
            'ANSELL HEALTHCARE EUROPE N.V.',
        )));
        // Dokładny kod, ale inna marka.
        $this->assertFalse($this->fuzzy->matches($req, $this->product('6036', 'Plastry opatrunkowe', 'REF')));
        // SKU karty to tylko ogon dłuższego numeru z zapytania.
        $this->assertFalse($this->fuzzy->matches('Plastry CEDERROTH 16036', $this->product(
            '6036',
            'Plastry plastikowe Cederroth Salvequick, 45 szt.',
            'CEDERROTH 1',
        )));
        // Marka zgodna, kod o jedną cyfrę inny — inny wyrób.
        $this->assertFalse($this->fuzzy->matches($req, $this->product(
            '6035',
            'Plastry tekstylne Cederroth Salvequick',
            'CEDERROTH',
        )));
    }

    #[Test]
    public function tepm_ice_matches_temp_ice_and_not_other_gloves(): void
    {
        $req = 'Rękawice MAPA TEPM-ICE 700 · EN 388 EN 511 EN ISO 21420';

        $this->assertTrue($this->fuzzy->hasNamedModel($req));
        $this->assertContains('tepmice', $this->fuzzy->needles($req));
        $this->assertContains('tepmice700', $this->fuzzy->needles($req));
        $this->assertSame(['mapa'], $this->fuzzy->manufacturerHints($req));
        $this->assertGreaterThanOrEqual(80, $this->fuzzy->score($req, $this->product(
            '34700018',
            'TEMP-ICE 700',
            'MAPA',
        )));
        $this->assertGreaterThanOrEqual(80, $this->fuzzy->score($req, $this->product(
            'TEMP-ICE-700-5',
            'TEMP-CE 700',
            'MAPA',
        )));
        $this->assertLessThan(80, $this->fuzzy->score($req, $this->product(
            '60592',
            'Rękawice zimowe Unilite Thermo Plus',
            'uvex',
        )));
        $this->assertLessThan(80, $this->fuzzy->score($req, $this->product(
            '34720039',
            'TEMP DEX 720',
            'MAPA',
        )));
    }

    #[Test]
    public function fastening_words_are_not_a_model_for_rain_jacket(): void
    {
        $req = 'Ubranie ochronne dla elektryków (bluza + spodnie) długa bluza zapinana na zatrzaski EN 1149-5 IEC 61482';

        $this->assertNotContains('zapinanana', $this->fuzzy->needles($req));
        $this->assertNotContains('dlaelektrykow', $this->fuzzy->needles($req));
        $this->assertSame(0, $this->fuzzy->score($req, $this->product(
            '103',
            '103 - Kurtka wodoochronna zapinana na zamek + stójka',
            'PROS / AJ Group',
        )));
    }

    #[Test]
    public function catalog_brand_msa_is_detected_not_common_nouns(): void
    {
        $req = 'Ochronniki słuchu na hełm MSA - niski poziom tłumienia';

        $this->assertContains('msa', $this->fuzzy->catalogBrands($req));
        $this->assertNotContains('ochronniki', $this->fuzzy->catalogBrands($req));
        $this->assertTrue($this->fuzzy->matchesCatalogBrand($this->product('X', 'Nauszniki', 'MSA'), ['msa']));
        $this->assertFalse($this->fuzzy->matchesCatalogBrand($this->product('PW75', 'PW75', 'Portwest'), ['msa']));
    }

    #[Test]
    public function short_alnum_code_p3e_is_a_named_model(): void
    {
        $req = 'Adapter P3E do hełmu 3M';

        $this->assertTrue($this->fuzzy->hasNamedModel($req));
        $this->assertContains('p3e', $this->fuzzy->needles($req));
        $this->assertContains('p3e', $this->fuzzy->shortCodes($req));
        $this->assertGreaterThanOrEqual(80, $this->fuzzy->score($req, $this->product(
            'P3E',
            '3M Adapter P3E do mocowania osłony twarzy',
            '3M',
        )));
        $this->assertSame(0, $this->fuzzy->score($req, $this->product(
            'FH-934',
            'Adapter kaptura ochronnego 3M systemu z wymuszonym przepływem',
            '3M',
        )));
    }

    #[Test]
    public function polar_fabric_is_not_sku_pola(): void
    {
        $req = 'KURTKA DAMSKA - POLAR granatowy rozm. S - XXXXL';
        $this->assertSame(0, $this->fuzzy->score($req, $this->product(
            'POLA',
            'POLA - EN 420 KAT. II, EN 388 - 3131',
            'X',
        )));
    }

    #[Test]
    public function perspecta_and_numeric_suffix_form_model_needle(): void
    {
        $req = 'OKULARY OCHRONNE MSA PERSPECTA 010';

        $this->assertTrue($this->fuzzy->hasNamedModel($req));
        $this->assertContains('perspecta010', $this->fuzzy->needles($req));
        $this->assertContains('perspecta010', $this->fuzzy->catalogModelNeedles($req));
        $this->assertGreaterThanOrEqual(80, $this->fuzzy->score($req, $this->product(
            '10061279',
            'MSA PERSPECTA 010 - Okulary ochronne',
            'MSA',
        )));
        $this->assertLessThan(80, $this->fuzzy->score($req, $this->product(
            '10045516',
            'Okulary PERSPECTA 9000 (12szt), bezbarwne',
            'MSA',
        )));
        $this->assertLessThan(80, $this->fuzzy->score($req, $this->product(
            '10081939',
            'Sztywne etui na okulary Perspecta (6szt)',
            'MSA',
        )));
        $this->assertNotContains('perspecta', $this->fuzzy->needles($req));
    }

    #[Test]
    public function perspecta_2047w_does_not_match_9000_or_1070(): void
    {
        $req = 'Okulary ochronne MSA PERSPECTA 2047W';

        $this->assertContains('perspecta2047w', $this->fuzzy->needles($req));
        $this->assertContains('perspecta2047w', $this->fuzzy->catalogModelNeedles($req));
        $this->assertNotContains('perspecta', $this->fuzzy->needles($req));
        $this->assertGreaterThanOrEqual(80, $this->fuzzy->score($req, $this->product(
            '10064800',
            'Okulary PERSPECTA 2047W (12szt), bezbarwne',
            'MSA',
        )));
        $this->assertLessThan(80, $this->fuzzy->score($req, $this->product(
            '10045516',
            'Okulary PERSPECTA 9000 (12szt), bezbarwne',
            'MSA',
        )));
        $this->assertLessThan(80, $this->fuzzy->score($req, $this->product(
            '10064797',
            'Okulary PERSPECTA 1070 (12szt), bezbarwne',
            'MSA',
        )));
    }

    #[Test]
    public function uvex_phynomic_is_a_line_needle(): void
    {
        $req = 'Rękawice montażowe powlekane uvex phynomic z funkcją ESD';

        $this->assertContains('phynomic', $this->fuzzy->needles($req));
        $this->assertContains('phynomic', $this->fuzzy->catalogModelNeedles($req));
        $this->assertGreaterThanOrEqual(80, $this->fuzzy->score($req, $this->product(
            '60078',
            'uvex phynomic airLite B ESD 6,7,8,9,10,11,12 10 4',
            'SUNGBOO',
        )));
    }

    #[Test]
    public function urgent_urg_a_is_a_named_model_and_not_urg_b(): void
    {
        $req = 'Spodnie ogrodniczki robocze URGENT URG-A';

        $this->assertTrue($this->fuzzy->hasNamedModel($req));
        $this->assertContains('urga', $this->fuzzy->needles($req));
        $this->assertContains('urga', $this->fuzzy->catalogModelNeedles($req));
        $this->assertNotContains('urgent', $this->fuzzy->catalogModelNeedles($req));
        $this->assertTrue($this->fuzzy->usesModelAnchoredCatalogSearch($req));
        $this->assertGreaterThanOrEqual(80, $this->fuzzy->score($req, $this->product(
            'PROS-URG-A-OGROD',
            'URG-A (ogrodniczki)',
            'URGENT',
        )));
        $this->assertLessThan(80, $this->fuzzy->score($req, $this->product(
            'PROS-URG-B-OGROD',
            'URG-B (ogrodniczki)',
            'URGENT',
        )));
    }

    #[Test]
    public function peltor_x2_is_a_named_model_and_not_x1(): void
    {
        $req = 'Nauszniki przeciwhałasowe 3M Peltor X2 wersja nagłowna';

        $this->assertTrue($this->fuzzy->hasNamedModel($req));
        $this->assertContains('peltorx2', $this->fuzzy->needles($req));
        $this->assertContains('peltorx2', $this->fuzzy->catalogModelNeedles($req));
        $this->assertTrue($this->fuzzy->usesModelAnchoredCatalogSearch($req));
        $this->assertGreaterThanOrEqual(80, $this->fuzzy->score($req, $this->product(
            'X2A-EU',
            '3M Nauszniki przeciwhałasowe PELTOR X2 - wersja nagłowna (SNR 31 dB)',
            '3M',
        )));
        $this->assertLessThan(80, $this->fuzzy->score($req, $this->product(
            'X1A-EU',
            '3M Nauszniki przeciwhałasowe PELTOR X1 - wersja nagłowna (SNR 27 dB)',
            '3M',
        )));
        $this->assertNotContains('klasas3', $this->fuzzy->needles(
            'Trzewiki robocze w klasie ochrony S3 SRC z podnoskiem'
        ));
    }

    /**
     * Karta 3M pisze linię i kod osobno („3M™ PELTOR™ Nauszniki …, nagłowne, X2A”), a igła „peltorx2” wymagała
     * ciągłego zapisu — 25.09.2026 wzorcowa 7000103989 nie była nazwanym modelem pod „Peltor X2”.
     */
    #[Test]
    public function peltor_x2_matches_line_and_short_code_written_apart(): void
    {
        $req = 'Nauszniki przeciwhałasowe 3M Peltor X2 wersja nagłowna';

        $x2a = $this->product('7000103989', '3M™ PELTOR™ Nauszniki przeciwhałasowe, żółte, nagłowne, X2A', '3M');
        $this->assertTrue($this->fuzzy->matches($req, $x2a));
        $this->assertGreaterThanOrEqual(80, $this->fuzzy->strongSkuScore($req, $x2a));
        // Wersję nahełmową model przepuszcza — odrzuca ją sprzeczność mocowania (PpeAssortment::hearingVariantConflict).
        $this->assertTrue($this->fuzzy->matches($req, $this->product(
            '7000103990',
            '3M™ PELTOR™ Nauszniki przeciwhałasowe, żółte, nahełmowe, X2P3',
            '3M',
        )));

        foreach ([
            ['7000103987', '3M™ PELTOR™ Nauszniki przeciwhałasowe, żółte, nagłowne, X1A'],
            ['7000104046', '3M™ PELTOR™ Zestaw higieniczny, HYX2'],
            ['FLX2-200', '3M™ PELTOR™ FLX2 Kabel J11 standardowy, Ex, FLX2-200'],
            ['X200', '3M™ PELTOR™ Nauszniki przeciwhałasowe X200'],
            // linia bez kodu i kod bez linii
            ['7100000001', '3M™ PELTOR™ Nauszniki przeciwhałasowe, żółte, nagłowne'],
            ['7100000002', 'Nauszniki przeciwhałasowe X2A'],
        ] as [$sku, $name]) {
            $this->assertFalse($this->fuzzy->matches($req, $this->product($sku, $name, '3M')), $name);
        }
    }

    #[Test]
    public function tychem_4000_s_is_a_named_model_and_not_tychem_c(): void
    {
        $req = 'Kombinezon chemoodporny Tychem 4000 S biały';

        $this->assertTrue($this->fuzzy->hasNamedModel($req));
        $this->assertContains('tychem4000', $this->fuzzy->needles($req));
        $this->assertContains('tychem4000', $this->fuzzy->catalogModelNeedles($req));
        $this->assertContains(['tychem', '4000'], $this->fuzzy->catalogModelWordDigitPairs($req));
        $this->assertNotContains('tychem', $this->fuzzy->manufacturerHints($req));
        $this->assertGreaterThanOrEqual(80, $this->fuzzy->score($req, $this->product(
            'SL CHZ5 T WH 00',
            'TYCHEM® 4000 S - white.',
            'DuPont',
        )));
        $this->assertGreaterThanOrEqual(80, $this->fuzzy->score($req, $this->product(
            'SL CHZ6 T WH 16',
            'TYCHEM® 4000 S - white with socks.',
            'DuPont',
        )));
        $this->assertLessThan(80, $this->fuzzy->score($req, $this->product(
            'TC CHA5 T YL 00',
            'TYCHEM C - yellow.',
            'DuPont',
        )));
    }

    #[Test]
    public function disposable_cap_pack_is_not_a_named_model(): void
    {
        $req = 'CZEPEK JEDNORAZOWY -1 OP.-100 szt.';

        $this->assertFalse($this->fuzzy->usesModelAnchoredCatalogSearch($req));
        $this->assertSame([], $this->fuzzy->catalogModelNeedles($req));
        $this->assertNotContains('czepek', $this->fuzzy->needles($req));
        $this->assertNotContains('jednorazowy', $this->fuzzy->needles($req));
        $this->assertNotContains('op100', $this->fuzzy->needles($req));
    }

    #[Test]
    public function shoe_size_range_is_not_a_catalog_model(): void
    {
        $req = 'BUTY gumowe DAMSKIE antyelektrostatyczne rozm. 35-41 TRONCHETTO OB. SRA prod.CERVA · EN ISO 20347';

        $this->assertNotContains('rozm3541', $this->fuzzy->catalogModelNeedles($req));
        $this->assertContains('tronchetto', $this->fuzzy->catalogModelNeedles($req));
        $this->assertContains('cerva', $this->fuzzy->catalogBrands($req));
        $this->assertLessThan(80, $this->fuzzy->score($req, $this->product(
            '28-0001.00/858.0_3400',
            'Getry żaroodp. metalizowane 858.0 wys. 34 cm, taśma spręż., rozm. 41-42',
            'ALWIT POLAND',
        )));
    }

    #[Test]
    public function hygiene_kit_code_is_the_model_not_the_earmuff_line(): void
    {
        $req = 'Zestaw higieniczny do nauszników 3M OPTIME I HY51';

        $this->assertContains('hy51', $this->fuzzy->needles($req));
        $this->assertNotContains('optime', $this->fuzzy->needles($req));
        $this->assertGreaterThanOrEqual(80, $this->fuzzy->score($req, $this->product(
            'HY51',
            '3M Zestaw higieniczny do nauszników Optime I',
            '3M',
        )));
        $this->assertLessThan(80, $this->fuzzy->score($req, $this->product(
            'HY52',
            '3M Zestaw higieniczny do nauszników PELTOR Optime II',
            '3M',
        )));
    }

    #[Test]
    public function letter_digit_model_does_not_match_digits_inside_other_sku(): void
    {
        $req = 'Pasek podbródkowy 3 punktowy do hełmu G3000';

        $this->assertGreaterThanOrEqual(80, $this->fuzzy->score($req, $this->product(
            'D1229',
            'Podbradní pásek GH1 k přilbě Peltor G3000',
            'ARDON SAFETY',
        )));
        $this->assertLessThan(80, $this->fuzzy->score($req, $this->product(
            'GA3130000000-RE000',
            'F2 X-TREM WildLand Rescue, CE, EN12492, wentylacja, pasek podbródkowy, czerwony',
            'MSA',
        )));
    }

    /** Oznaczenia klas/poziomów, lata norm, temperatury i miary nie są kodami modeli (W1: poz. 1, 3, 5, 8, 10–14). */
    #[Test]
    public function class_markings_norm_years_temperatures_and_measures_are_not_needles(): void
    {
        $cases = [
            'Półmaska filtrująca klasy FFP1 z zaworem' => 'klasa FFP1 (klasyffp1, ffp1)',
            'Sandały S1P ESD kategorii S1 P' => 'klasa obuwia (sandalys1p, s1p, kategoriis1)',
            'Pochłaniacz klasy A2 EN 14387' => 'klasa filtra (klasya2)',
            'Rękawice EN 388:2016 4X42C' => 'poziomy EN 388 (4x42c)',
            'Trzewiki S3 SRC EN ISO 20345:2011' => 'rok normy (src2011)',
            'zgodność z normą EN 20347:2012 dla obuwia kategorii OB' => 'rok normy (norma2012)',
            'Wymagane: ŚOI kategorii III; EN 420:2003+A1:2009' => 'poprawka normy (kategoriiiii2003)',
            'materiał odporny na zginanie w temperaturze do -50°C' => 'temperatura (50c)',
            'ciepło kontaktowe do 100°C przez 15 s' => 'temperatura (100c)',
            'Opakowanie: 10 szt., karton 100 szt.' => 'karton + liczba (karton100)',
            'butelka o pojemności 500 ml' => 'rzeczownik miary (pojemnosci500)',
            'Płukanka do oczu butelka 500 ml' => 'liczba z jednostką (butelka500)',
            'Fartuch wodoochronny, wymiary 120 × 75 cm' => 'wymiary (wymiary120)',
            'soczewka o polu widzenia 180° bez zniekształceń' => 'pole widzenia (poluwidzenia180)',
            'Gogle o polu widzenia 180 stopni' => 'pole widzenia bez symbolu stopnia',
            'chwytność w układzie rombowym; długość 300 mm' => 'długość (rombowymdlugosc300)',
            'zestaw opatrunkowy 4-w-1 do tamowania krwi; masa 1,981 kg' => 'N-w-1 (4w1)',
            'z akredytacją dermatologiczną. EN 388:2016' => 'akredytacja + rok (akredytacjadermatologiczna2016)',
            'dzianina z przędzy UHMWPE, włókna szklanego' => 'akronim materiału (uhmwpe)',
            'KALESONY bawełniane (100% bawełny) męskie rozmiar od S do XXXXL' => 'procent (bawelniane100)',
        ];
        foreach ($cases as $req => $why) {
            $this->assertSame([], $this->fuzzy->needles($req), $why.': '.implode(', ', $this->fuzzy->needles($req)));
            $this->assertFalse($this->fuzzy->hasNamedModel($req), $why);
        }
    }

    /** Klasa obok numeru modelu: zostaje wyłącznie igła z numerem (Aura 9322), nie „ffp2”. */
    #[Test]
    public function model_number_next_to_class_marking_keeps_only_the_model_needle(): void
    {
        $req = 'Półmaska FFP2 z zaworem 3M Aura 9322+';

        $this->assertSame(['aura9322'], $this->fuzzy->needles($req));
        $this->assertSame(['aura9322'], $this->fuzzy->catalogModelNeedles($req));
        $this->assertNotContains('ffp2', $this->fuzzy->needles('Półmaska FFP2 z zaworem 3M 9322+'));
        $this->assertNotContains('ffp2', $this->fuzzy->shortCodes('Półmaska FFP2 z zaworem 3M 9322+'));
        $this->assertGreaterThanOrEqual(80, $this->fuzzy->score($req, $this->product(
            '9322+',
            '3M™ Aura 9322+ półmaska filtrująca FFP2 z zaworem',
            '3M',
        )));
        $this->assertLessThan(80, $this->fuzzy->score($req, $this->product(
            '9320+',
            '3M™ Aura 9320+ półmaska filtrująca FFP2 bez zaworu',
            '3M',
        )));
    }

    /** „owocowo-warzywne”, „czerwono-czarnym”, „bi-materiałowe” to przymiotniki złożone, nie TEPM-ICE (poz. 4, 6, 7). */
    #[Test]
    public function compound_adjectives_with_hyphen_are_not_hyphen_models(): void
    {
        foreach ([
            'przetwórstwo spożywcze (owocowo-warzywne, mięsne, rybne)',
            'bi-materiałowe zauszniki PC+TPR w kolorze czerwono-czarnym',
            'powłoka ze spienionej gumy nitrylowo-butadienowej (NBR)',
        ] as $req) {
            $this->assertSame([], $this->fuzzy->needles($req), implode(', ', $this->fuzzy->needles($req)));
        }

        // skład tkaniny w nazwie karty zostaje igłą (pinowane w ProductAiSearchApiTest::test_lab_coat_*)
        $this->assertContains('elanobawelna', $this->fuzzy->needles('FARTUCH LAB. ELANO-BAWEŁNA prosty, biały. EN ISO 13688'));
        $this->assertContains('tepmice700', $this->fuzzy->needles('Rękawice MAPA TEPM-ICE 700'));
        $this->assertContains('coolflow', $this->fuzzy->needles('Zawór Cool-Flow do półmaski'));
    }

    /** Zapytanie #50 z 23.09.2026: symbol REIS przepisany z katalogu, doklejony myślnikiem do poprzedniego słowa. */
    #[Test]
    public function code_declared_by_symbol_label_is_the_named_model(): void
    {
        $req = 'Rękawice ochronne tkaninowe pięciopalcowe, powlekane nitrylem żółtym, zakończone ściągaczem-symbol RNITz  - 432 pary';

        $this->assertSame(['rnitz'], $this->fuzzy->needles($req));
        $this->assertTrue($this->fuzzy->matches($req, $this->product('RNITZ', 'Rękawice ochronne NITZ.', 'REIS')));
        // Kod przepisany z katalogu nie ma literówki — sąsiednie symbole to inne wyroby.
        $this->assertFalse($this->fuzzy->matches($req, $this->product('RNITNL', 'Rękawice ochronne NITNL.', 'REIS')));
        $this->assertFalse($this->fuzzy->matches($req, $this->product('RNITRIO', 'Rękawice ochronne NITRIO.', 'REIS')));

        $this->assertSame(['ab15021'], $this->fuzzy->needles('Szelki bezpieczeństwa, kod: AB15021'));
        $this->assertSame(['rnitz'], $this->fuzzy->needles('Rękawice nitrylowe o symbolu RNITZ'));
    }

    /**
     * Zapytanie #53 z 23.09.2026: opis wyrobu pisany KAPITALIKAMI. „DIAGNOSTYCZNE” i „BEZPUDROWE”
     * były igłami modelu, więc rękawice lateksowe z tymi słowami w nazwie dostawały 94% jak nazwany model.
     */
    #[Test]
    public function caps_words_next_to_the_product_noun_are_description_not_model(): void
    {
        $req = 'RĘKAWICZKI DIAGNOSTYCZNE BEZPUDROWE,(wyposażenie apteczek) -10 OPAKOWAŃ PO 50 PAR = 500par';
        $this->assertSame([], $this->fuzzy->needles($req));
        $this->assertFalse($this->fuzzy->matches($req, $this->product('RZ-LATEX', 'Rękawice diagnostyczne lateksowe bezpudrowe RZ-LATEX', 'REIS')));

        $boots = $this->fuzzy->needles('TRZEWIKI BEZPIECZNE PPO STRZELCE OPOLSKIE MODEL 705, KAT. S3,HI,CI,SRC');
        $this->assertNotContains('bezpieczne', $boots);

        // KAPITALIKI wyróżnione w zwykłym tekście dalej są modelem
        $this->assertContains('tronchetto', $this->fuzzy->needles('Obuwie TRONCHETTO'));
        // (sąsiednie słowa KAPITALIKAMI to jedna nazwa — zob. caps_model_words_form_one_name…)
        $this->assertSame(['easygrippurple'], $this->fuzzy->needles('Rękawiczki nitrylowe "MedaSept" EASYGRIP PURPLE - 50 opk'));
        // linia po znanej marce i numerowany model nie zależą od wielkości liter
        $this->assertContains('ultrane', $this->fuzzy->needles('RĘKAWICE MAPA ULTRANE'));
        $this->assertContains('perspecta010', $this->fuzzy->needles('OKULARY OCHRONNE MSA PERSPECTA 010'));
    }

    /** Zapytanie #54 z 23.09.2026, poz. 6 i 7: fałszywe trafienia „Marka i model z SIWZ” oznaczone jako pewne. */
    #[Test]
    public function caps_model_words_form_one_name_and_brackets_split_tokens(): void
    {
        // poz. 6: sam kolor z nazwy modelu trafiał w Kleenguard G60 Purple
        $medasept = 'Rękawiczki nitrylowe "MedaSept" EASYGRIP PURPLE - 50 opk';
        $this->assertSame(['easygrippurple'], $this->fuzzy->needles($medasept));
        $this->assertFalse($this->fuzzy->matches($medasept, $this->product('97434', 'KLNGD G60 Glove Lvl 3 Purple Nitrile 11', 'Ansell')));
        $this->assertTrue($this->fuzzy->matches($medasept, $this->product('MS-EGP', 'Rękawiczki nitrylowe MedaSept Easygrip Purple', 'Medasept')));

        // poz. 7: „EN420(2)(brak rozmiaru)” sklejał „2brak” — kod o literę od ABRAK
        $drill = 'Rękawice drelichowe pięciopalcowe EN374,EN420(2)(brak rozmiaru)';
        $this->assertSame([], $this->fuzzy->needles($drill));
        $this->assertFalse($this->fuzzy->matches($drill, $this->product('3410-064-000-00', 'Gloves ABRAK, nitrile coated, white-grey, blister', 'Canis')));
    }

    /** Etykieta stoi też przed zwykłymi słowami — te nie są kodem. */
    #[Test]
    public function code_label_before_ordinary_words_adds_no_needle(): void
    {
        foreach ([
            'Rękawice oznaczone symbolem CE, EN 388',
            'Kask z naklejką, kod kreskowy na opakowaniu',
            'Okulary ochronne, symbol graficzny na zauszniku',
            'Obuwie zgodne z kodeksem pracy',
            'rękawice ochronne tkaninowe nitryl żółty ściągacz-symbol',
        ] as $req) {
            $this->assertSame([], $this->fuzzy->needles($req), $req.': '.implode(', ', $this->fuzzy->needles($req)));
        }
    }

    #[Test]
    public function measure_nouns_and_units_do_not_form_word_digit_pairs(): void
    {
        $this->assertSame([], $this->fuzzy->catalogModelWordDigitPairs('butelka o pojemności 500 ml z końcówką'));
        $this->assertSame([], $this->fuzzy->catalogModelWordDigitPairs('soczewka o polu widzenia 180° bez zniekształceń'));
        $this->assertSame([], $this->fuzzy->catalogModelWordDigitPairs('Opakowanie: 10 szt., karton 100 szt.'));
        $this->assertSame([], $this->fuzzy->catalogModelWordDigitPairs('Płukanka do oczu butelka 500 ml'));
        $this->assertContains(['tychem', '4000'], $this->fuzzy->catalogModelWordDigitPairs('Kombinezon chemoodporny Tychem 4000 S biały'));
        $this->assertContains(['perspecta', '010'], $this->fuzzy->catalogModelWordDigitPairs('OKULARY OCHRONNE MSA PERSPECTA 010'));
    }

    /** Mocne igły dla heurystyki „mocny SKU”: z cyfrą, model z myślnikiem, linia po znanej marce — nie goły wyraz. */
    #[Test]
    public function strong_sku_needles_need_digit_hyphen_model_or_brand_line(): void
    {
        $this->assertContains('tepmice700', $this->fuzzy->strongSkuNeedles('Rękawice MAPA TEPM-ICE 700 · EN 388 EN 511 EN ISO 21420'));
        $this->assertContains('perspecta010', $this->fuzzy->strongSkuNeedles('OKULARY OCHRONNE MSA PERSPECTA 010'));
        $this->assertContains('perspecta2047w', $this->fuzzy->strongSkuNeedles('Okulary ochronne MSA PERSPECTA 2047W'));
        $this->assertContains('p3e', $this->fuzzy->strongSkuNeedles('Adapter P3E do hełmu 3M'));
        $this->assertContains('hy51', $this->fuzzy->strongSkuNeedles('Zestaw higieniczny do nauszników 3M OPTIME I HY51'));
        $this->assertContains('peltorx2', $this->fuzzy->strongSkuNeedles('Nauszniki 3M Peltor X2 nahełmowe'));
        $this->assertContains('tychem4000', $this->fuzzy->strongSkuNeedles('Kombinezon chemoodporny Tychem 4000 S biały'));
        $this->assertContains('g3000', $this->fuzzy->strongSkuNeedles('Pasek podbródkowy 3 punktowy do hełmu G3000'));
        $this->assertContains('urga', $this->fuzzy->strongSkuNeedles('Półmaska URG-A z filtrami'));
        $this->assertContains('phynomic', $this->fuzzy->strongSkuNeedles('Rękawice uvex phynomic lite'));

        $cerva = 'BUTY gumowe DAMSKIE antyelektrostatyczne rozm. 35-41 TRONCHETTO OB. SRA prod.CERVA · EN ISO 20347';
        $this->assertContains('tronchetto', $this->fuzzy->needles($cerva));
        $this->assertSame([], $this->fuzzy->strongSkuNeedles($cerva));
        $tronchetto = $this->product('0202001060', 'TRONCHETTO OB SRA buty gumowe damskie', 'CERVA');
        $this->assertGreaterThanOrEqual(80, $this->fuzzy->score($cerva, $tronchetto));
        $this->assertSame(0, $this->fuzzy->strongSkuScore($cerva, $tronchetto));
    }

    public function test_code_written_with_another_separator_still_points_at_the_card(): void
    {
        $uvex = $this->product('8543/8/35', 'Polbut Uvex 1 8543/8', 'UVEX');
        $artra = $this->product('AROX 733 641460 S1 ESD', 'AROX 733 641460 S1 ESD', 'ARTRA');
        $ajGroup = $this->product('101/001/A', 'Ubranie wodoochronne antystatyczne', 'AJ GROUP');

        // klient pisze kod z kropka, katalog trzyma go z ukosnikami
        $uvexQuery = 'BUTY UVEX BUSINESS CASUAL 8543.8 S1 SRC ROZMIAR 44';
        $this->assertTrue($this->fuzzy->separatedCodeMatches($uvexQuery, $uvex));
        $this->assertFalse($this->fuzzy->separatedCodeMatches($uvexQuery, $artra));
        $this->assertTrue($this->fuzzy->separatedCodeMatches('UBRANIE WODOOCHRONNE AJ GROUP 101.001.A', $ajGroup));

        // klasa ochrony, ulamek i model bez separatora kodem nie sa
        $this->assertFalse($this->fuzzy->separatedCodeMatches('Trzewiki S1/SRC rozmiar 44', $artra));
        $this->assertFalse($this->fuzzy->separatedCodeMatches('Kurtka 3/4 ocieplana', $ajGroup));
        $this->assertFalse($this->fuzzy->separatedCodeMatches(
            'Kombinezon Tychem 4000 bialy',
            $this->product('AB-40/00', 'Kombinezon zwykly', 'X')
        ));
    }

    private function product(string $sku, string $name, string $manufacturer): Product
    {
        $p = new Product;
        $p->forceFill([
            'sku' => $sku,
            'name' => $name,
            'manufacturer' => $manufacturer,
        ]);

        return $p;
    }
}
