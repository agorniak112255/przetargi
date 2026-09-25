<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Models\Product;
use App\Support\ProductSizeVariant;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

final class ProductSizeVariantTest extends TestCase
{
    #[Test]
    public function groups_ansell_names_that_differ_only_by_size(): void
    {
        $svc = new ProductSizeVariant;

        $a = $svc->groupKey('Ansell', 'AlphaTec 37695VP Size 7.0', '37695VP070');
        $b = $svc->groupKey('Ansell', 'AlphaTec 37695VP Size 10.0', '37695VP100');

        $this->assertNotNull($a);
        $this->assertSame($a, $b);
        $this->assertSame('37695VP', $svc->skuCore('37695VP100', 'AlphaTec 37695VP Size 10.0'));
        $this->assertSame('AlphaTec 37695VP', $svc->stripSizeFromName('AlphaTec 37695VP Size 10.0'));
        $this->assertSame('AlphaTec 09430', $svc->stripSizeLabelFromName('AlphaTec 09430 Size 10,0'));
        $this->assertSame('HyFlex 11919VP VEND', $svc->stripSizeLabelFromName('HyFlex 11919VP Size 10,0 VEND'));
        $this->assertSame('KG G10 Comfort Plus Ntrl Glv Lt Blue XL', $svc->stripSizeLabelFromName('KG G10 Comfort Plus Ntrl Glv Lt Blue XL'));
        $this->assertSame('1st Winter Dry 10', $svc->stripSizeLabelFromName('1st Winter Dry 10'));
        $this->assertSame('Men´s jacket CXS SOLIS FLEX, blue-black', $svc->stripSizeLabelFromName('Men´s jacket CXS SOLIS FLEX, blue-black, size 46 - 68'));
        $this->assertSame('Men´s jacket CXS SOLIS FLEX, redblack', $svc->stripSizeLabelFromName('Men´s jacket CXS SOLIS FLEX, redblack, size 46  64'));
        $this->assertSame(
            'Kaptur 3M Versaflo ze zintegrowaną więźbą, rozmiar L, S-133L',
            $svc->stripSizeLabelFromName('Kaptur 3M Versaflo ze zintegrowaną więźbą, rozmiar L, S-133L')
        );
        $this->assertSame('Spodnie wodoochronne  ogrodniczki', $svc->stripSizeLabelFromName('Spodnie wodoochronne  ogrodniczki'));
        // cudzysłów na początku nazwy dzieli bajty z półpauzą z listy przycinania
        $this->assertSame('„Bartek” półbuty robocze', $svc->stripSizeLabelFromName('„Bartek” półbuty robocze rozmiar 42'));
        $this->assertSame(
            'Scotchlite 8725 N, srebrny,rozmiar 25,4 mm x 100 m',
            $svc->stripSizeLabelFromName('Scotchlite 8725 N, srebrny,rozmiar 25,4 mm x 100 m')
        );
        $this->assertSame(
            'Szelki ExoFit XE50 1112702, rozmiar 1, 1 szt./opakowanie',
            $svc->stripSizeLabelFromName('Szelki ExoFit XE50 1112702, rozmiar 1, 1 szt./opakowanie')
        );
    }

    #[Test]
    public function groups_names_with_trailing_numeric_size(): void
    {
        $svc = new ProductSizeVariant;

        $a = $svc->groupKey('Showa', '1st Winter Dry 9', '34703090');
        $b = $svc->groupKey('Showa', '1st Winter Dry 10', '34703100');
        $c = $svc->groupKey('Showa', '1st Winter Dry 11', '34703110');
        $other = $svc->groupKey('Showa', '1st Winter 9', '34704090');

        $this->assertNotNull($a);
        $this->assertSame($a, $b);
        $this->assertSame($a, $c);
        $this->assertNotSame($a, $other);
        $this->assertSame('9', $svc->extractSize('1st Winter Dry 9', '34703090'));
        $this->assertSame('10', $svc->extractSize('1st Winter Dry 10', '34703100'));
        $this->assertSame('1st Winter Dry', $svc->stripSizeFromName('1st Winter Dry 10'));
        $this->assertSame('34703', $svc->skuCore('34703100', '1st Winter Dry 10'));
        $this->assertSame('34704', $svc->skuCore('34704090', '1st Winter 9'));
    }

    #[Test]
    public function strips_short_roz_label_and_the_separator_it_leaves(): void
    {
        $svc = new ProductSizeVariant;

        // Karty UVEX #25211 i PROTEKT #26176 zostały po łączeniu rozmiarów z nazwą „… roz.” — obcinana była sama liczba.
        $this->assertSame('Rękawice C500 Dry - nakrapiane', $svc->stripSizeFromName('Rękawice C500 Dry - nakrapiane roz. 7'));
        $this->assertSame(
            'PB 66 - Pas bojowy strażacki z linką bezpieczeństwa',
            $svc->stripSizeFromName('PB 66 - Pas bojowy strażacki z linką bezpieczeństwa - roz. S'),
        );
        $this->assertSame('PB 31 - Pas do pracy w podparciu', $svc->stripSizeFromName('PB 31 - Pas do pracy w podparciu - roz. M-XL'));
        // rozmiar kończący nazwę nie zostawia myślnika ani przecinka — rozmiar pojedynczy i zakres dają ten sam klucz
        $this->assertSame('P-50mX - Szelki bezpieczeństwa', $svc->stripSizeFromName('P-50mX - Szelki bezpieczeństwa - rozmiar S'));
        $range = $svc->groupKey('Canis', 'Fleece jacket, navy blue colour, size XS-5XL', '1');
        $this->assertNotNull($range);
        $this->assertSame($range, $svc->groupKey('Canis', 'Fleece jacket, navy blue colour, size 6XL', '2'));
        // separator przed resztą nazwy zostaje — obcinany jest tylko koniec nazwy
        $this->assertSame('Rękawice nitrylowe', $svc->stripSizeFromName('Rękawice roz. 7 nitrylowe'));
        $this->assertSame('Szelki -', $svc->stripSizeFromName('Szelki -'));
    }

    #[Test]
    public function groups_letter_size_suffix_on_sku_and_name(): void
    {
        $svc = new ProductSizeVariant;

        $s = $svc->groupKey('PIP', 'HM5500 BAYONET HALF-MASK ELASTOMERIC S', 'HM5500BS');
        $m = $svc->groupKey('PIP', 'HM5500 BAYONET HALF-MASK ELASTOMERIC M', 'HM5500BM');
        $l = $svc->groupKey('PIP', 'HM5500 BAYONET HALF-MASK ELASTOMERIC L', 'HM5500BL');

        $this->assertNotNull($s);
        $this->assertSame($s, $m);
        $this->assertSame($s, $l);
        $this->assertSame('s', $svc->extractSize('HM5500 BAYONET HALF-MASK ELASTOMERIC S', 'HM5500BS'));
        $this->assertSame('HM5500B', $svc->skuCore('HM5500BS', 'HM5500 BAYONET HALF-MASK ELASTOMERIC S'));
        $this->assertSame('HM5500 BAYONET HALF-MASK ELASTOMERIC', $svc->stripSizeFromName('HM5500 BAYONET HALF-MASK ELASTOMERIC L'));
    }

    #[Test]
    public function groups_padded_and_plain_numeric_sku_suffix(): void
    {
        $svc = new ProductSizeVariant;

        $padded = $svc->groupKey('PIP', 'Rękawice montażowe 9', 'A501609');
        $plain = $svc->groupKey('PIP', 'Rękawice montażowe 9', 'A50169');
        $slashNine = $svc->groupKey('PIP', 'Rękawice montażowe', 'A5016/09');
        $slashShort = $svc->groupKey('PIP', 'Rękawice montażowe', 'A5016/9');

        $this->assertNotNull($padded);
        $this->assertSame($padded, $plain);
        $this->assertSame('A5016', $svc->skuCore('A501609', 'Rękawice montażowe 9'));
        $this->assertSame('A5016', $svc->skuCore('A50169', 'Rękawice montażowe 9'));
        $this->assertSame($slashNine, $slashShort);
        $this->assertSame('A5016', $svc->skuCore('A5016/09'));
        $this->assertSame('A5016', $svc->skuCore('A5016/9'));
    }

    #[Test]
    public function tail_stem_strips_last_two_size_digits(): void
    {
        $svc = new ProductSizeVariant;

        $this->assertSame('ALUWELD-DRT', $svc->skuTailStem('ALUWELD-DRT07'));
        $this->assertSame('ALUWELD-DRT', $svc->skuTailStem('ALUWELD-DRT11'));
        $this->assertSame('ATTACK6PEOM-BT', $svc->skuTailStem('ATTACK6PEOM-BT09'));
        $this->assertSame('ATTACK6PEOMTEX-BSCT', $svc->skuTailStem('ATTACK6PEOMTEX-BSCT06'));
        $this->assertSame('BLACKSTICK+-SCT', $svc->skuTailStem('BLACKSTICK+-SCT06'));
        $this->assertSame('BLACKTACTIL/0T', $svc->skuTailStem('BLACKTACTIL/0T07'));
        $this->assertSame('BLACKTACTIL', $svc->skuTailStem('BLACKTACTILT07'));
        $this->assertSame('BLACKTACTIL', $svc->skuTailStem('BLACKTACTILT11'));
        $this->assertSame('BLACKTACTIL', $svc->skuCore('BLACKTACTILT07', 'T7 GLOVES, CUT RESISTANCE LEVEL F, PU, BLACK'));
        $this->assertSame('BLACKNIT', $svc->skuTailStem('BLACKNITT11'));
        $this->assertNull($svc->skuTailStem('BLACKSTICK+T'));
        $this->assertNull($svc->skuTailStem('558911'));
        $this->assertNull($svc->skuTailStem('37695VP070'));
        $this->assertNull($svc->skuTailStem('37695VP110'));
        $this->assertSame('MASTERTSHIRT-B03', $svc->skuTailStem('MASTERTSHIRT-B03TS'));
        $this->assertSame('MASTERTSHIRT-B03', $svc->skuTailStem('MASTERTSHIRT-B03TXXXL'));
        $this->assertSame('MASTERTSHIRT-B03', $svc->skuTailStem('MASTERTSHIRT-B03T6XL'));
        $this->assertSame('MASTERTSHIRT-B', $svc->skuTailStem('MASTERTSHIRT-BTS'));
        $this->assertSame('MASTERTSHIRT-B', $svc->skuTailStem('MASTERTSHIRT-BTXL'));
        $this->assertSame('SCANFORCE-BRTL', $svc->skuTailStem('SCANFORCE-BRTL-XL'));
        $this->assertNull($svc->skuTailStem('SCANFORCE-BRTL'));
        $this->assertNull($svc->skuTailStem('ROOTS'));
        $this->assertNull($svc->skuTailStem('CANADA-IT'));
        $this->assertSame('CANADA-IT', $svc->skuTailStem('CANADA-IT08'));
        $this->assertSame('CANADA-IT', $svc->resolveMergeStem('CANADA-IT', ['canada-it' => 'CANADA-IT']));
        $this->assertSame('CRIOT', $svc->skuTailStem('CRIOT08'));
        $this->assertSame('CRIOT', $svc->skuTailStem('CRIOT09'));
        $this->assertSame('PROSOUD/1DRT', $svc->skuTailStem('PROSOUD/1DRT08'));
        $this->assertSame('PROSOUD/1DRT', $svc->skuTailStem('PROSOUD/1DRT10'));
        $this->assertSame(['BLACKTACTIL', 'BLACKTACTI'], $svc->skuSearchFallbacks('BLACKTACTILT'));
        $this->assertSame([], $svc->skuSearchFallbacks('ALUWELD-DRT'));
        $this->assertSame([], $svc->skuSearchFallbacks('ERGOPRIMA'));
        $this->assertSame([], $svc->skuSearchFallbacks('37695VP'));
        $this->assertSame([], $svc->skuSearchFallbacks('BLACKSTICK+T'));
    }

    #[Test]
    public function names_compatible_after_t_size_and_word_order(): void
    {
        $svc = new ProductSizeVariant;

        $this->assertTrue($svc->namesCompatibleForMerge([
            '1 RIGHT HAND GLOVE T7 WELDER LEATHER BACK ALUMINIUM',
            '1 RIGHT HAND GLOVE SIZE 8 WELDER LEATHER BACK ALUMINIUM',
            '1 RIGHT HAND GLOVE T11 WELDER LEATHER ALUMINIUM BACK',
        ]));
        $this->assertTrue($svc->namesCompatibleForMerge([
            'T9 FIREFIGHTING GLOVES - LEATHER - T LONG TEXTILE',
            'T12 FIREFIGHTING GLOVES LEATHER - TEXTILE LONG',
        ]));
        $this->assertTrue($svc->namesCompatibleForMerge([
            'T6 PU CUT RESISTANCE GLOVES WITH LEATHER REINFORCEMENT',
            'T11 GLOVES, F CUT RESISTANCE, PU, LEATHER REINFORCEMENT, SC',
        ]));
        $this->assertFalse($svc->namesCompatibleForMerge([
            'T6 PU CUT RESISTANCE GLOVES WITH LEATHER REINFORCEMENT',
            'T6 GLOVES, CUT RESISTANCE E, BLACK KNITTED',
        ]));
    }

    #[Test]
    public function does_not_group_different_models(): void
    {
        $svc = new ProductSizeVariant;

        $a = $svc->groupKey('Ansell', 'AlphaTec 37695VP Size 10.0', '37695VP100');
        $b = $svc->groupKey('Ansell', 'AlphaTec 37900VP Size 10.0', '37900VP100');

        $this->assertNotSame($a, $b);
    }

    #[Test]
    public function same_price_bucket_only_when_catalog_and_purchase_match(): void
    {
        $svc = new ProductSizeVariant;

        $this->assertSame($svc->priceBucket(2.85, 2.85), $svc->priceBucket('2.85', 2.85));
        $this->assertNotSame($svc->priceBucket(2.85, 2.85), $svc->priceBucket(3.96, 3.96));
    }

    #[Test]
    public function ignores_sku_without_size_in_name_or_known_suffix(): void
    {
        $svc = new ProductSizeVariant;

        $this->assertNull($svc->groupKey('X', 'Rękawice test', 'MAXIFLEX34874'));
    }

    #[Test]
    public function parse_size_list_from_packaging(): void
    {
        $svc = new ProductSizeVariant;

        $this->assertSame(['7', '8', '9', '10', '11'], $svc->parseSizeList('7, 8, 9, 10, 11'));
        $this->assertSame(['6.5-7', '7.5-8'], $svc->parseSizeList('6.5-7, 7.5-8'));
        $this->assertSame(['10'], $svc->parseSizeList(null, 'AlphaTec Size 10.0', '37695VP100'));
        $this->assertSame(
            ['36', '37', '38', '39', '40', '41', '42', '43', '44', '45', '46', '47', '48'],
            $svc->parseSizeList('36-48')
        );
        $this->assertSame(
            ['46', '48', '50', '52', '54', '56', '58', '60', '62'],
            $svc->parseSizeList('od 46 do 62')
        );
        $this->assertSame(['s', 'm', 'l', 'xl', 'xxl'], $svc->parseSizeList('S-XXL'));
    }

    #[Test]
    public function extracts_footwear_range_from_description(): void
    {
        $svc = new ProductSizeVariant;
        $text = "Normy i certyfikaty\n— EN ISO 20345:2011 S1 P SRC\n"
            ."Rozmiary obuwia\n— Rozmiary unisex od 36 do 48. Aby dobrać odpowiedni rozmiar, "
            .'sprawdź tabelę rozmiarów producenta.';

        $this->assertSame(
            ['36', '37', '38', '39', '40', '41', '42', '43', '44', '45', '46', '47', '48'],
            $svc->parseSizesFromText($text)
        );
        $this->assertSame('36-48', $svc->formatPackaging($svc->parseSizesFromText($text)));
    }

    #[Test]
    public function extracts_glove_and_clothing_ranges(): void
    {
        $svc = new ProductSizeVariant;

        $this->assertSame(
            ['7', '8', '9', '10', '11'],
            $svc->parseSizesFromText('Dostępne rozmiary: 7, 8, 9, 10, 11')
        );
        $this->assertSame(
            ['s', 'm', 'l', 'xl', 'xxl', 'xxxl'],
            $svc->parseSizesFromText('Rozmiary odzieży od S do XXXL.')
        );
        $this->assertSame(
            ['46', '48', '50', '52', '54', '56', '58', '60', '62'],
            $svc->parseSizesFromText('Rozmiary spodni: 46-62')
        );
        $this->assertSame(['9'], $svc->parseSizesFromText('Rozmiar: 9'));
        $this->assertSame([], $svc->parseSizesFromText(
            'EN ISO 20345:2011 S1 P SRC — pełna ochrona. ESD wg EN IEC 61340-4-3:2018.'
        ));
    }

    /**
     * Ansell zapisuje rozmiarówkę odzieży listą po przecinku albo ukośniku. Lista urywała się
     * na pierwszym „NXL” (zostawało samo „3”), więc karta S–5XL dostawała rozmiar „S-XL”.
     */
    #[Test]
    public function keeps_sizes_above_xl_in_comma_and_slash_lists(): void
    {
        $svc = new ProductSizeVariant;

        $this->assertSame(
            ['s', 'm', 'l', 'xl', 'xxl', 'xxxl'],
            $svc->parseSizesFromText('Dostępne rozmiary: S, M, L, XL, 2XL, 3XL')
        );
        $this->assertSame(
            ['s', 'm', 'l', 'xl', 'xxl', 'xxxl', 'xxxxl', '5xl'],
            $svc->parseSizesFromText('Available sizes: S, M, L, XL, 2XL, 3XL, 4XL, 5XL')
        );
        $this->assertSame(
            ['s', 'm', 'l', 'xl', 'xxl', 'xxxl', 'xxxxl', '5xl'],
            $svc->parseSizesFromText('Rozmiary: S/M/L/XL/XXL/3XL/4XL/5XL')
        );
        $this->assertSame(
            's-5xl',
            $svc->formatPackaging($svc->parseSizesFromText('Rozmiary: S, M, L, XL, XXL, 3XL, 4XL, 5XL'))
        );
    }

    /**
     * Cennik Ansella oddziela rozmiar kropką („103.5XL”, „126-G02.3XL”). Kropki nie było wśród
     * separatorów, więc nazwa nie dawała rozmiaru, a system schodził do ogona SKU i czytał
     * „-07” jako rozmiar rękawicy 7 przy kombinezonie 3XL.
     */
    #[Test]
    public function reads_size_written_after_a_dot_in_the_name(): void
    {
        $svc = new ProductSizeVariant;

        $this->assertSame('xxxl', $svc->extractSize('4000-GR APOL ENCAP SCBA 126-G02.3XL', 'GR40T-00126-07'));
        $this->assertSame('5xl', $svc->extractSize('3000-YE CVRL COLLAR 103.5XL', 'YE30T-00103-09'));
        $this->assertSame('5xl', $svc->extractSize('3000-YE SLEEVED APRON 243-G01.5XL', 'YE30T-00243-09-G01'));
        // rozpiętość numerów butów w nazwie to nie jeden rozmiar — zostaje w opakowaniu
        $this->assertNull($svc->extractSize('3000-YE OVERBOOTS 406.42-46', 'YE30T-00406-00'));

        // gdy nazwa rozmiaru nie niesie, kod odzieży Ansella milczy zamiast zgadywać:
        // „-07” to kod wariantu producenta, a nie rozmiar rękawicy 7
        $this->assertNull($svc->extractSize('4000-GR CVRL HOOD 121-G02', 'GR40T-00121-07'));
        $this->assertNull($svc->extractSize('3000-YE CVRL HOOD 121', 'YE30T-00121-09-G02'));

        // kody innych dostawców i rękawice Ansella czytają się jak dotąd
        $this->assertSame('11', $svc->extractSize('VersaTouch 92205', '92205110'));
        $this->assertSame('42', $svc->extractSize('Półbuty ARMEN 9003', 'ARMEN-9003-42'));
        $this->assertSame('11', $svc->extractSize('Rękawice BOE 471', 'BOE-471-11'));
        // wzorzec jest wąski: obcy kod o podobnym kształcie zachowuje swój rozmiar
        $this->assertSame('8', $svc->extractSize('Wyrób testowy', 'AB12-34567-08'));
    }

    /**
     * Karta podająca sam token („XXL”) nie ma słowa kluczowego, więc parser list nic nie znajdzie
     * i dotąd ten pusty wynik nadpisywał wartość z karty — 144 karty w katalogu miały pusty
     * rozmiar mimo znanego opakowania. Kategoria nadal odsiewa oznaczenia nie z tej skali.
     */
    #[Test]
    public function bare_size_token_is_kept_when_it_fits_the_category(): void
    {
        $svc = new ProductSizeVariant;

        $this->assertSame('xxl', $svc->bareLabelForCategory('XXL', 'rekawice'));
        $this->assertSame('10', $svc->bareLabelForCategory('10', 'rekawice'));
        $this->assertSame('s-xxl', $svc->bareLabelForCategory('S-XXL', 'odziez'));
        $this->assertSame('36-48', $svc->bareLabelForCategory('36-48', 'obuwie'));

        // etykieta odzieżowa przy obuwiu to nadal śmieć, tak samo jak dotąd
        $this->assertNull($svc->bareLabelForCategory('1-5XL', 'obuwie'));
        $this->assertNull($svc->bareLabelForCategory('s-xxl', 'obuwie'));
        $this->assertNull($svc->bareLabelForCategory('brak danych', 'rekawice'));
        $this->assertNull($svc->bareLabelForCategory('', null));
    }

    /** Tabela rozmiarów z odpowiednikami liczbowymi: rozmiarem jest litera, nie liczby z nawiasu. */
    #[Test]
    public function letter_size_table_keeps_every_letter(): void
    {
        $svc = new ProductSizeVariant;

        $this->assertSame(['s', 'm', 'l'], $svc->parseSizesFromText('Rozmiary: S (36-38), M (40-42), L (44-46)'));
        $this->assertSame(
            ['s', 'm', 'l', 'xl'],
            $svc->parseSizesFromText('Rozmiary: S (36-38) / M (40-42) / L (44-46) / XL (48-50)')
        );
        // nawias bez litery rozmiaru nie uruchamia tej gałęzi
        $this->assertSame(
            ['38', '39', '40', '41', '42', '43', '44', '45', '46'],
            $svc->parseSizesFromText('Rozmiary (wg tabeli): 38-46')
        );
    }

    /**
     * „Rozmiar: XXL (10.5-11.0)” to jeden rozmiar z metrycznym odpowiednikiem, nie zakres.
     * Wygrywał zakres z nawiasu, token „10.5-11” nie przechodził kontroli rozmiaru rękawic
     * i atrybut rozmiaru zostawał pusty (VersaTouch 92205 z listy testerki).
     */
    #[Test]
    public function letter_size_with_metric_equivalent_in_brackets_is_one_size(): void
    {
        $svc = new ProductSizeVariant;

        $this->assertSame(['xxl'], $svc->parseSizesFromText('Rozmiar: XXL (10.5-11.0)'));
        $this->assertSame(['xl'], $svc->parseSizesFromText('Rozmiar: XL (54-56)'));
        $this->assertSame('xxl', $svc->labelFromTexts(null, 'Rozmiar: XXL (10.5-11.0)', 'rekawice'));
        // zwykła lista i zakres czytają się tak samo jak dotąd
        $this->assertSame(['7', '8', '9', '10', '11'], $svc->parseSizesFromText('Rozmiary: 7, 8, 9, 10, 11'));
        $this->assertSame(['9'], $svc->parseSizesFromText('Rozmiar: 9'));
    }

    #[Test]
    public function fills_empty_packaging_from_description_range(): void
    {
        $svc = new ProductSizeVariant;
        $sizes = $svc->parseSizesFromText('Rozmiary unisex od 36 do 48.');

        $this->assertTrue($svc->shouldFillPackaging(null, $sizes));
        $this->assertTrue($svc->shouldFillPackaging('para', $sizes));
        $this->assertTrue($svc->shouldFillPackaging('42', $sizes));
        $this->assertFalse($svc->shouldFillPackaging('7, 8, 9, 10, 11', $sizes));
    }

    #[Test]
    public function rejects_clothing_label_for_footwear_and_reads_bare_eu_range(): void
    {
        $svc = new ProductSizeVariant;

        $this->assertSame([], $svc->parseSizesFromText('1-5XL'));
        $this->assertNull($svc->labelFromTexts('1-5XL', 'EN 20347, EN 13688', 'obuwie'));
        $this->assertSame('39-47', $svc->labelFromTexts('1-5XL', 'Taglie disponibili 39-47', 'obuwie'));
        $this->assertSame(
            ['38', '39', '40', '41', '42', '43', '44', '45', '46', '47'],
            $svc->parseBareFootwearRange('EN 20347 SRC. 38-47. ESD.')
        );
        $this->assertSame([], $svc->parseBareFootwearRange('EN ISO 20345:2011 S1 P SRC'));
    }

    #[Test]
    public function extracts_shop_buy_options_and_spaced_size_grid(): void
    {
        $svc = new ProductSizeVariant;
        $this->assertSame(
            ['36', '37', '38', '39', '40', '41', '42', '43', '44', '45', '46', '47'],
            $svc->parseSizesFromText('Rozmiar: 36 37 38 39 40 41 42 43 44 45 46 47')
        );
        $this->assertSame(
            ['36', '37', '38', '39', '40', '41', '42', '43', '44', '45', '46', '47'],
            $svc->parseShopOptionSizes(
                '<label>Rozmiar:</label><div class="product-sizes">'
                .'<button>36</button><button>37</button><button>38</button>'
                .'<button>39</button><button>40</button><button>41</button>'
                .'<button>42</button><button>43</button><button>44</button>'
                .'<button>45</button><button>46</button><button>47</button></div>'
            )
        );
        $this->assertSame(
            ['36', '37', '38', '39', '40', '41', '42', '43', '44', '45', '46', '47'],
            $svc->parseShopOptionSizes(
                'Kody producenta: JET3SPNO36, JET3SPNO47, JET3SPNO46, JET3SPNO45, '
                .'JET3SPNO44, JET3SPNO43, JET3SPNO42, JET3SPNO41, JET3SPNO40, '
                .'JET3SPNO39, JET3SPNO38, JET3SPNO37'
            )
        );
        $this->assertSame(
            ['36', '37', '38', '39', '40', '41', '42', '43', '44', '45', '46', '47'],
            $svc->parseShopOptionSizes(
                '<select id="group_1" class="form-control" name="group[1]" aria-label="Rozmiar">'
                .'<option>36</option><option>37</option><option>38</option><option>39</option>'
                .'<option>40</option><option>41</option><option>42</option><option>43</option>'
                .'<option>44</option><option>45</option><option>46</option><option>47</option>'
                .'</select>'
            )
        );
        $this->assertSame(
            [],
            $svc->parseShopOptionSizes('EN ISO 20345:2011 S1 P SRC. ID produktu 22243.')
        );
        $this->assertSame(
            ['36', '37', '38', '39', '40', '41', '42', '43', '44', '45', '46', '47'],
            $svc->parseShopOptionSizes(
                '<div class="attributes-box"><div class="attribute-a">'
                .'<div class="name"><p>Rozmiar:</p><div class="selected"><p>Wybrano:</p></div></div>'
                .'<div class="list"><div id="opcja_22243_20_0">'
                .'<p><input type="radio" name="atrybuty_22243[20]" id="atrybuty_22243_20_0">'
                .' <label for="atrybuty_22243_20_0">36</label></p>'
                .'<p><input type="radio" name="atrybuty_22243[20]" id="atrybuty_22243_20_0_2">'
                .' <label for="atrybuty_22243_20_0_2">37</label></p>'
                .'<p><input type="radio" name="atrybuty_22243[20]" id="atrybuty_22243_20_0_3">'
                .' <label for="atrybuty_22243_20_0_3">38</label></p>'
                .'<p><input type="radio" name="atrybuty_22243[20]" id="atrybuty_22243_20_0_4">'
                .' <label for="atrybuty_22243_20_0_4">39</label></p>'
                .'<p><input type="radio" name="atrybuty_22243[20]" id="atrybuty_22243_20_0_5">'
                .' <label for="atrybuty_22243_20_0_5">40</label></p>'
                .'<p><input type="radio" name="atrybuty_22243[20]" id="atrybuty_22243_20_0_6">'
                .' <label for="atrybuty_22243_20_0_6">41</label></p>'
                .'<p><input type="radio" name="atrybuty_22243[20]" id="atrybuty_22243_20_0_7">'
                .' <label for="atrybuty_22243_20_0_7">42</label></p>'
                .'<p><input type="radio" name="atrybuty_22243[20]" id="atrybuty_22243_20_0_8">'
                .' <label for="atrybuty_22243_20_0_8">43</label></p>'
                .'<p><input type="radio" name="atrybuty_22243[20]" id="atrybuty_22243_20_0_9">'
                .' <label for="atrybuty_22243_20_0_9">44</label></p>'
                .'<p><input type="radio" name="atrybuty_22243[20]" id="atrybuty_22243_20_0_10">'
                .' <label for="atrybuty_22243_20_0_10">45</label></p>'
                .'<p><input type="radio" name="atrybuty_22243[20]" id="atrybuty_22243_20_0_11">'
                .' <label for="atrybuty_22243_20_0_11">46</label></p>'
                .'<p><input type="radio" name="atrybuty_22243[20]" id="atrybuty_22243_20_0_12">'
                .' <label for="atrybuty_22243_20_0_12">47</label></p>'
                .'</div></div></div></div>'
            )
        );
        $this->assertSame(
            '36-47',
            $svc->labelFromTexts(null, 'Rozmiar: 36 37 38 39 40 41 42 43 44 45 46 47', 'obuwie')
        );
    }

    #[Test]
    public function reads_idosell_select2_glove_sizes_not_footwear_chart(): void
    {
        $svc = new ProductSizeVariant;
        $html = '<table class="product-parameters"><tr><td>'
            .'<span class="parameter-name">Rozmiary rękawic</span> <br></td><td>'
            .'<select class="select-field-select2 core_parseOption" data-placeholder="Wybierz">'
            .'<option></option>'
            .'<option value="14220" name="option_15-134792">6</option>'
            .'<option value="14221" name="option_15-134792">7</option>'
            .'<option value="14222" name="option_15-134792">8</option>'
            .'<option value="14223" name="option_15-134792">9</option>'
            .'<option value="14224" name="option_15-134792">10</option>'
            .'<option value="14225" name="option_15-134792">11</option>'
            .'</select></td></tr></table>'
            .'<footer>Rozmiary unisex od 35 do 49. Tabela obuwia.</footer>';

        $this->assertSame(
            ['6', '7', '8', '9', '10', '11'],
            $svc->parseShopOptionSizes($html)
        );
        $this->assertSame(
            ['6', '7', '8', '9', '10', '11'],
            $svc->pickBestSizeList(
                [$svc->parseShopOptionSizes($html), ['35', '36', '37', '38', '39', '40', '41', '42', '43', '44', '45', '46', '47', '48', '49']],
                'rekawice'
            )
        );
    }

    #[Test]
    public function reads_glove_sizes_from_sku_slash_table(): void
    {
        $svc = new ProductSizeVariant;
        $html = '<table><tr><td>A5016/06</td><td>A5016/07</td><td>A5016/08</td>'
            .'<td>A5016/09</td><td>A5016/10</td><td>A5016/11</td></tr></table>';
        $this->assertSame(
            ['6', '7', '8', '9', '10', '11'],
            $svc->parseShopOptionSizes('A5016/06 A5016/07 A5016/08 A5016/09 A5016/10 A5016/11')
        );

        $this->assertSame(
            ['6', '7', '8', '9', '10', '11'],
            $svc->parseShopOptionSizes($html)
        );
    }

    #[Test]
    public function strips_eu_size_suffix_from_catalog_sku(): void
    {
        $svc = new ProductSizeVariant;

        $this->assertSame('40', $svc->extractSize(null, 'G3175/40'));
        $this->assertSame('G3175', $svc->skuCore('G3175/40'));
        $this->assertSame('CADIZ', $svc->skuCore('CADIZ-42'));
        $this->assertSame('A5016', $svc->skuCore('A5016/09'));
        $this->assertSame('A5016', $svc->stripWearSizeSuffix('A5016/9'));
        $this->assertSame('A5016', $svc->stripWearSizeSuffix('A5016/09'));
        $this->assertSame('KRYTECH-563', $svc->stripWearSizeSuffix('KRYTECH-563-11'));
        $this->assertSame('PARKA', $svc->stripWearSizeSuffix('PARKA/XL'));
        $this->assertSame('JACKET', $svc->stripWearSizeSuffix('JACKET-XXXL'));
        $this->assertSame('TROUSERS', $svc->stripWearSizeSuffix('TROUSERS/56'));
        $this->assertSame('TX39', $svc->stripWearSizeSuffix('TX39-L'));
        $this->assertNull($svc->skuCore('MT-212-2'));
        $this->assertNull($svc->skuCore('04-322-100'));
        $this->assertNull($svc->skuCore('00500-016'));
        $this->assertNull($svc->stripWearSizeSuffix('URG-914'));
        $this->assertNull($svc->stripWearSizeSuffix('CADIZ-S1PS'));
        $this->assertNull($svc->stripWearSizeSuffix('BALTIK-BLACK-CZARNY-NYLON-PO-70'));
        $this->assertNull($svc->skuCore('BALTIK-BLACK-CZARNY-NYLON-PO-70'));
    }

    #[Test]
    public function all_digit_3m_sku_is_not_a_shoe_size(): void
    {
        $svc = new ProductSizeVariant;
        $name = 'Elastyczna linka z amortyzatorem 3M Protecta, 1,50 m, 1260348';

        $this->assertNull($svc->extractSize($name, '1260348'));
        $this->assertNull($svc->extractSize(null, '1260348'));
        $this->assertNull($svc->extractSize(null, '7100336246'));

        $product = new Product([
            'sku' => '1260348',
            'name' => $name,
            'packaging' => null,
            'description' => 'Podwójna lonża z amortyzatorem.',
        ]);
        $this->assertSame([], $svc->sizesForProduct($product));
    }

    #[Test]
    public function groups_size_letter_hidden_inside_supplier_code(): void
    {
        $svc = new ProductSizeVariant;
        $name = 'Półmaska SECURA 3000 (nagłowie jednoczęściowe)';

        $groups = $svc->midCodeSizeVariantGroups([
            ['id' => 1, 'manufacturer' => 'SECURA', 'name' => $name, 'sku' => 'S56T0SS0'],
            ['id' => 2, 'manufacturer' => 'SECURA', 'name' => ' półmaska  secura 3000 (NAGŁOWIE jednoczęściowe) ', 'sku' => 'S56T0SM0'],
            ['id' => 3, 'manufacturer' => 'SECURA', 'name' => $name, 'sku' => 'S56T0SL0'],
        ]);

        $this->assertCount(3, $groups);
        $this->assertSame('S', $groups[1]['size']);
        $this->assertSame('M', $groups[2]['size']);
        $this->assertSame('L', $groups[3]['size']);
        $this->assertSame($groups[1]['key'], $groups[2]['key']);
        $this->assertSame($groups[1]['key'], $groups[3]['key']);
        $this->assertStringStartsWith('mid:', $groups[1]['key']);
        $this->assertSame('S, M, L', $svc->midCodeSizeLabel(['M', 'S', 'L', 'M']));
    }

    #[Test]
    public function does_not_group_codes_that_differ_outside_a_size_letter(): void
    {
        $svc = new ProductSizeVariant;

        // Wymiary chodnika nie są ani w nazwie, ani w kodzie — cyfra to nie rozmiar.
        $this->assertSame([], $svc->midCodeSizeVariantGroups([
            ['id' => 1, 'manufacturer' => 'Secura', 'name' => 'Chodnik elektroizolacyjny 20 KV', 'sku' => 'T5921002'],
            ['id' => 2, 'manufacturer' => 'Secura', 'name' => 'Chodnik elektroizolacyjny 20 KV', 'sku' => 'T5921003'],
        ]));

        // Dwie różniące się pozycje to już nie jeden rozmiar.
        $this->assertSame([], $svc->midCodeSizeVariantGroups([
            ['id' => 1, 'manufacturer' => 'SECURA', 'name' => 'Półmaska SECURA 3000', 'sku' => 'S56T0SS1'],
            ['id' => 2, 'manufacturer' => 'SECURA', 'name' => 'Półmaska SECURA 3000', 'sku' => 'S56T0SM0'],
        ]));

        // Różne nazwy = brak dowodu z rodzeństwa.
        $this->assertSame([], $svc->midCodeSizeVariantGroups([
            ['id' => 1, 'manufacturer' => 'SECURA', 'name' => 'Półmaska SECURA 3000', 'sku' => 'S56T0SS0'],
            ['id' => 2, 'manufacturer' => 'SECURA', 'name' => 'Półmaska SECURA 4000', 'sku' => 'S56T0SM0'],
        ]));

        // Różni producenci.
        $this->assertSame([], $svc->midCodeSizeVariantGroups([
            ['id' => 1, 'manufacturer' => 'SECURA', 'name' => 'Półmaska SECURA 3000', 'sku' => 'S56T0SS0'],
            ['id' => 2, 'manufacturer' => 'Ansell', 'name' => 'Półmaska SECURA 3000', 'sku' => 'S56T0SM0'],
        ]));

        // Litera na końcu kodu i na jego początku zostaje przy dotychczasowych ścieżkach.
        $this->assertSame([], $svc->midCodeSizeVariantGroups([
            ['id' => 1, 'manufacturer' => 'Bolle', 'name' => 'B713 – Prescription safety glasses', 'sku' => 'B713L'],
            ['id' => 2, 'manufacturer' => 'Bolle', 'name' => 'B713 – Prescription safety glasses', 'sku' => 'B713S'],
        ]));
        $this->assertSame([], $svc->midCodeSizeVariantGroups([
            ['id' => 1, 'manufacturer' => 'Anro', 'name' => 'Znak ewakuacyjny Pchać push', 'sku' => 'S35E/G/P1'],
            ['id' => 2, 'manufacturer' => 'Anro', 'name' => 'Znak ewakuacyjny Pchać push', 'sku' => 'L35E/G/P1'],
        ]));

        // Nazwa sama mówi o rozmiarze — to ścieżka nazwy, nie kodu.
        $this->assertSame([], $svc->midCodeSizeVariantGroups([
            ['id' => 1, 'manufacturer' => 'Bolle', 'name' => 'Soczewki PC PLATINUM - mały rozmiar', 'sku' => 'RUSPSN11E'],
            ['id' => 2, 'manufacturer' => 'Bolle', 'name' => 'Soczewki PC PLATINUM - mały rozmiar', 'sku' => 'RUSPMN11E'],
        ]));
    }

    #[Test]
    public function assigns_each_card_to_a_single_mid_code_group(): void
    {
        $svc = new ProductSizeVariant;
        $name = 'Rękawice montażowe ASTRA';

        // ASMA1 pasuje do maski AS*A1 (grupa 3-elementowa) i do A*MA1 — wygrywa liczniejsza.
        $groups = $svc->midCodeSizeVariantGroups([
            ['id' => 1, 'manufacturer' => 'Astra', 'name' => $name, 'sku' => 'ASMA1'],
            ['id' => 2, 'manufacturer' => 'Astra', 'name' => $name, 'sku' => 'ASLA1'],
            ['id' => 3, 'manufacturer' => 'Astra', 'name' => $name, 'sku' => 'ASSA1'],
            ['id' => 4, 'manufacturer' => 'Astra', 'name' => $name, 'sku' => 'ALMA1'],
        ]);

        $this->assertCount(3, $groups);
        $this->assertSame([1, 2, 3], array_keys($groups));
        $this->assertSame($groups[1]['key'], $groups[2]['key']);
        $this->assertSame($groups[1]['key'], $groups[3]['key']);
    }
}
