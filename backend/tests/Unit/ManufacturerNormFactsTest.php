<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Support\BhpAttributeNormalizer;
use App\Support\ManufacturerNormFacts;
use Carbon\CarbonImmutable;
use PHPUnit\Framework\TestCase;

/**
 * Normy z karty producenta: co wolno zapisać jako poziom EN 388, a co zostaje samym cytatem.
 * Pary pochodzą z danych strukturalnych kart ATG (schema.org additionalProperty, odczyt 20.09.2026).
 */
final class ManufacturerNormFactsTest extends TestCase
{
    public function test_pary_z_karty_zostaja_doslownie_razem_ze_zrodlem(): void
    {
        $column = ManufacturerNormFacts::build(
            [
                ['label' => 'EN ISO 21420:2020+A1:2024', 'value' => null],
                ['label' => 'EN 388:2016 + A1:2018', 'value' => '4331B'],
                ['label' => 'ANSI/ISEA 105 (2016)', 'value' => 'A2'],
            ],
            'atg',
            'ATG',
            'https://www.atg-glovesolutions.com/pl/products/maxiflex/maxiflex-cut/34-8753',
            CarbonImmutable::parse('2026-09-20T12:00:00+00:00'),
        );

        $this->assertNotNull($column);
        $this->assertSame([
            ['label' => 'EN ISO 21420:2020+A1:2024', 'value' => ''],
            ['label' => 'EN 388:2016 + A1:2018', 'value' => '4331B'],
            ['label' => 'ANSI/ISEA 105 (2016)', 'value' => 'A2'],
        ], ManufacturerNormFacts::rows($column));
        $this->assertSame(
            'https://www.atg-glovesolutions.com/pl/products/maxiflex/maxiflex-cut/34-8753',
            ManufacturerNormFacts::sourceUrl($column),
        );
        $this->assertSame('atg', $column['source']['connector']);
        $this->assertSame('ATG', $column['source']['brand']);
    }

    public function test_poziom_en388_przechodzi_tylko_przy_normie_en388(): void
    {
        $column = ManufacturerNormFacts::build([
            ['label' => 'ANSI/ISEA 105 (2016)', 'value' => 'A6'],
            ['label' => 'EN 407:2020', 'value' => 'X1XXXX'],
            ['label' => 'EN 388:2016 + A1:2018', 'value' => '4243FP'],
        ], 'atg', 'ATG', 'https://example.test/karta');

        // Poziom EN 407 nie może wejść do pola EN 388, mimo że stoi na liście wyżej.
        $this->assertSame('4243FP', ManufacturerNormFacts::context($column)['en388'] ?? null);
    }

    public function test_nieczytelny_kod_nie_zostaje_zapisany_jako_poziom(): void
    {
        // Tak wyglądały kody wyciągnięte przez model z opisów sklepowych: sam stopień przecięcia
        // („3”) albo kod z myślnikami („4-1-3-1-A”). Karta ma zostać bez poziomów producenta.
        foreach (['3', '5', '4-1-3-1-A', 'poziom 3'] as $value) {
            $column = ManufacturerNormFacts::build(
                [['label' => 'EN 388:2016 + A1:2018', 'value' => $value]],
                'atg',
                'ATG',
                'https://example.test/karta',
            );

            $this->assertNotNull($column);
            $this->assertArrayNotHasKey('en388', $column, 'kod „'.$value.'” nie powinien przejść');
        }
    }

    public function test_norma_bez_poziomu_trafia_na_liste_bez_dopisywania_czegokolwiek(): void
    {
        $column = ManufacturerNormFacts::build([
            ['label' => 'EN ISO 21420:2020+A1:2024', 'value' => null],
        ], 'atg', 'ATG', 'https://example.test/karta');

        $this->assertSame(['EN ISO 21420:2020+A1:2024'], ManufacturerNormFacts::norms($column));
        $this->assertNotNull($column);
        $this->assertArrayNotHasKey('en388', $column);
    }

    public function test_ansi_isea_nie_udaje_normy_en_ale_zostaje_cytatem(): void
    {
        $column = ManufacturerNormFacts::build([
            ['label' => 'ANSI/ISEA 105-2024', 'value' => 'ABR X - CUT X - PUN 4'],
            ['label' => 'ANSI-ISEA 138 (2019)', 'value' => 'ASTM F2992 Impact protection'],
            ['label' => 'EN 388:2016 + A1:2018', 'value' => '4331B'],
        ], 'atg', 'ATG', 'https://example.test/karta');

        $this->assertSame(['EN 388:2016 + A1:2018 4331B'], ManufacturerNormFacts::norms($column));
        $this->assertCount(3, ManufacturerNormFacts::rows($column));
    }

    public function test_rekawica_chemiczna_zachowuje_litery_przy_normie_a_nie_w_nazwie(): void
    {
        // Karta ATG MaxiChem Cut 76-733: przy EN ISO 374-1 stoją klasy odporności chemicznej „KLMNOP”,
        // które oznaczeniem normy nie są. Na liście norm zostaje samo oznaczenie, a litery — w cytacie.
        $column = ManufacturerNormFacts::build([
            ['label' => 'EN ISO 21420:2020+A1:2024', 'value' => null],
            ['label' => 'EN ISO 374-1:2016 + A1:2018', 'value' => 'KLMNOP'],
            ['label' => 'EN ISO 374-5:2016', 'value' => null],
            ['label' => 'EN 407:2020', 'value' => 'X1XXXX'],
            ['label' => 'EN 388:2016 + A1:2018', 'value' => '3X31B'],
        ], 'atg', 'ATG', 'https://example.test/karta');

        $this->assertSame([
            'EN ISO 21420:2020+A1:2024',
            'EN ISO 374-1:2016 + A1:2018',
            'EN ISO 374-5:2016',
            'EN 407:2020 X1XXXX',
            'EN 388:2016 + A1:2018 3X31B',
        ], ManufacturerNormFacts::norms($column));
        $this->assertSame('3X31B', ManufacturerNormFacts::context($column)['en388'] ?? null);
        $this->assertContains(
            ['label' => 'EN ISO 374-1:2016 + A1:2018', 'value' => 'KLMNOP'],
            ManufacturerNormFacts::rows($column),
        );
    }

    public function test_kod_rozstrzelony_spacjami_zapisany_zwarcie_a_cytat_doslownie(): void
    {
        // Witryna Delta Plus: etykieta „EN 388”, wartość „2 1 2 1 X”. Dopasowanie szuka kodu w wymaganiu
        // przetargu bez spacji, więc poziom ma być zwarty; cytat i lista norm — dosłownie z karty.
        $column = ManufacturerNormFacts::build(
            [['label' => 'EN 388', 'value' => '2 1 2 1 X']],
            'deltaplus',
            'Delta Plus',
            'https://example.test/karta',
        );

        $this->assertNotNull($column);
        $this->assertSame('2121X', $column['en388'] ?? null);
        $this->assertSame('2121X', ManufacturerNormFacts::context($column)['en388'] ?? null);
        $this->assertSame([['label' => 'EN 388', 'value' => '2 1 2 1 X']], ManufacturerNormFacts::rows($column));
        $this->assertSame(['EN 388 2 1 2 1 X'], ManufacturerNormFacts::norms($column));
    }

    public function test_kod_z_kropkami_i_z_uderzeniem_zapisany_zwarcie(): void
    {
        foreach (['3.1.2.1.X' => '3121X', '4 3 3 1 B' => '4331B', '4 5 4 3 F P' => '4543FP'] as $value => $expected) {
            $column = ManufacturerNormFacts::build(
                [['label' => 'EN 388:2016 + A1:2018', 'value' => $value]],
                'deltaplus',
                'Delta Plus',
                'https://example.test/karta',
            );

            $this->assertNotNull($column);
            $this->assertSame($expected, $column['en388'] ?? null, 'kod „'.$value.'”');
            $this->assertSame($value, ManufacturerNormFacts::rows($column)[0]['value']);
        }
    }

    public function test_kod_zwarty_i_kod_z_dopiskiem_zostaja_doslownie(): void
    {
        // Zwieramy wyłącznie wartość, która w całości jest kodem — dopisek zostaje taki, jak na karcie.
        foreach (['4331B', '2 1 2 1 X (2016)', '4331B (2016)'] as $value) {
            $column = ManufacturerNormFacts::build(
                [['label' => 'EN 388', 'value' => $value]],
                'deltaplus',
                'Delta Plus',
                'https://example.test/karta',
            );

            $this->assertNotNull($column);
            $this->assertSame($value, $column['en388'] ?? null, 'wartość „'.$value.'”');
        }
    }

    public function test_mieszane_separatory_nie_sa_kodem(): void
    {
        $column = ManufacturerNormFacts::build(
            [['label' => 'EN 388', 'value' => '2.1 2.1X']],
            'deltaplus',
            'Delta Plus',
            'https://example.test/karta',
        );

        $this->assertNotNull($column);
        $this->assertArrayNotHasKey('en388', $column);
    }

    public function test_zapis_slowny_nie_jest_zapisywany_jako_kod_en388(): void
    {
        // Canis/CXS podaje poziomy słowami. En388Code je odczyta, ale `en388` ma być kodem karty, nie zdaniem —
        // para zostaje tylko jako cytat w `rows`.
        $value = 'odporność na przetarcie 2, odporność na przecięcie 1, odporność na rozerwanie 1, odporność na przekłucie 2';
        $column = ManufacturerNormFacts::build(
            [['label' => 'EN 388', 'value' => $value]],
            'strona-producenta',
            'Canis',
            'https://example.test/karta',
        );

        $this->assertNotNull($column);
        $this->assertArrayNotHasKey('en388', $column);
        $this->assertArrayNotHasKey('en388', ManufacturerNormFacts::context($column));
        $this->assertSame([['label' => 'EN 388', 'value' => $value]], ManufacturerNormFacts::rows($column));
    }

    public function test_normalizer_dostaje_kod_zwarty_z_karty_producenta(): void
    {
        $column = ManufacturerNormFacts::build(
            [['label' => 'EN 388', 'value' => '2 1 2 1 X']],
            'deltaplus',
            'Delta Plus',
            'https://example.test/karta',
        );

        $attrs = (new BhpAttributeNormalizer)->normalize(
            ['poziomy_en388' => '4131X'],
            ['manufacturer' => ManufacturerNormFacts::context($column)],
        );

        $this->assertSame('2121X', $attrs['poziomy_en388']);
    }

    public function test_lista_norm_ze_sklepu_ustepuje_producentowi_tej_samej_normy(): void
    {
        $column = ManufacturerNormFacts::build(
            [
                ['label' => 'Kategoria 3', 'value' => '0075'],
                ['label' => 'EN 388', 'value' => '1121X'],
                ['label' => 'EN 374-1', 'value' => 'Type A ABCILMNOS'],
                ['label' => 'EN 374-5', 'value' => null],
            ],
            ManufacturerNormFacts::WEB_PAGE_CONNECTOR,
            'MAPA',
            'https://www.mapa-pro.pl/produkty/chemioodporne/strona-produktu/butoflex-650',
        );

        $norms = ManufacturerNormFacts::preferOver(
            ['EN 388 (1.1.2.2)', 'EN 374 (A.B.C.I.K.L)', 'EN ISO 374-1 Typ B KPT', 'EN 374-5 (ALMNST)', 'EN 407 X1XXXX'],
            $column,
        );

        $this->assertSame('EN 388 1121X', $norms[0]);
        $this->assertContains('EN 374-1 Type A ABCILMNOS', $norms);
        $this->assertContains('EN 407 X1XXXX', $norms, 'normy, której producent nie podaje, nie ruszamy');
        $this->assertContains('EN 374-5 (ALMNST)', $norms, 'producent podał EN 374-5 bez oznaczenia — poziom ze sklepu zostaje');
        $joined = implode(' | ', $norms);
        $this->assertStringNotContainsString('1.1.2.2', $joined);
        $this->assertStringNotContainsString('A.B.C.I.K.L', $joined, 'EN 374 to stary zapis tej samej normy co EN 374-1');
        $this->assertStringNotContainsString('KPT', $joined, 'EN ISO 374-1 i EN 374-1 to ta sama norma');
        $this->assertStringNotContainsString('Kategoria', $joined, 'kategoria ŚOI nie jest normą');
    }

    public function test_inne_podane_wydanie_zostaje_a_zapis_bez_roku_ustepuje_producentowi(): void
    {
        $rows = [['label' => 'EN 388:2016 + A1:2018', 'value' => '4X42C']];

        $norms = ManufacturerNormFacts::resolveAgainstRows(['EN 388:2003 (4542)', 'EN 388 (1.1.2.2)', 'EN 388:2016 4131X'], $rows);

        $this->assertSame('EN 388:2016 + A1:2018 4X42C', $norms[0]);
        $this->assertContains('EN 388:2003 (4542)', $norms, 'EN 388:2003 to inna, prawdziwa wartość wyrobu');
        $this->assertNotContains('EN 388 (1.1.2.2)', $norms, 'rok tylko po stronie producenta — rozstrzyga producent');
        $this->assertNotContains('EN 388:2016 4131X', $norms, 'to samo wydanie, inny kod — sklep ustępuje');
    }

    public function test_para_producenta_bez_wartosci_nie_wypiera_poziomu_ze_sklepu(): void
    {
        $norms = ManufacturerNormFacts::resolveAgainstRows(
            ['EN 388 4121X', 'EN 374 (A.B.C.I.K.L)'],
            [['label' => 'EN 388', 'value' => ''], ['label' => 'EN ISO 374-1', 'value' => 'Typ A AJKOPT']],
        );

        $this->assertContains('EN 388 4121X', $norms);
        $this->assertContains('EN ISO 374-1 Typ A AJKOPT', $norms);
        $this->assertNotContains('EN 374 (A.B.C.I.K.L)', $norms, 'EN 374 to stary zapis tej samej normy co EN ISO 374-1');
    }

    public function test_wydanie_czytamy_tylko_z_roku_podanego_wprost(): void
    {
        $this->assertSame('2016', ManufacturerNormFacts::statedEdition('EN 388:2016 + A1:2018 4X42C'));
        $this->assertSame('2003', ManufacturerNormFacts::statedEdition('EN 388 (2003) 4542'));
        $this->assertSame('2016', ManufacturerNormFacts::statedEdition('EN ISO 374-1:2016 Typ B'));
        $this->assertNull(ManufacturerNormFacts::statedEdition('EN 388 4542'), 'format kodu nie mówi o wydaniu');
        $this->assertNull(ManufacturerNormFacts::statedEdition('EN 388 (1.1.2.2)'));
    }

    public function test_normalizator_rozstrzyga_normy_tak_samo_jak_lista_przy_zapisie(): void
    {
        $column = ManufacturerNormFacts::build(
            [['label' => 'EN 388', 'value' => ''], ['label' => 'EN ISO 374-1', 'value' => 'Typ A AJKOPT']],
            ManufacturerNormFacts::WEB_PAGE_CONNECTOR,
            'MAPA',
            'https://example.test/karta',
        );
        $others = ['EN 388 4121X', 'EN 374 (A.B.C.I.K.L)'];

        $attrs = (new BhpAttributeNormalizer)->normalize(null, [
            'norms' => $others,
            'manufacturer' => ManufacturerNormFacts::context($column),
        ]);

        $this->assertSame(ManufacturerNormFacts::preferOver($others, $column), $attrs['normy_en']);
    }

    public function test_bez_norm_producenta_lista_zostaje_bez_zmian(): void
    {
        $this->assertSame(['EN 388 (1.1.2.2)'], ManufacturerNormFacts::preferOver(['EN 388 (1.1.2.2)'], null));
    }

    public function test_strona_producenta_nie_nadpisuje_par_lacznika(): void
    {
        $atg = ManufacturerNormFacts::build([['label' => 'EN 388', 'value' => '4331B']], 'atg', 'ATG', 'https://example.test/karta');
        $web = ManufacturerNormFacts::build([['label' => 'EN 388', 'value' => '4331B']], ManufacturerNormFacts::WEB_PAGE_CONNECTOR, 'ATG', 'https://example.test/karta');

        $this->assertTrue(ManufacturerNormFacts::replaceableFromWebPage(null));
        $this->assertTrue(ManufacturerNormFacts::replaceableFromWebPage($web));
        $this->assertFalse(ManufacturerNormFacts::replaceableFromWebPage($atg));
    }

    public function test_karta_bez_ani_jednej_pary_nie_zapisuje_kolumny(): void
    {
        $this->assertNull(ManufacturerNormFacts::build([], 'atg', 'ATG', 'https://example.test/karta'));
        $this->assertNull(ManufacturerNormFacts::build([['label' => '  ', 'value' => '4331B']], 'atg', 'ATG', 'https://example.test/karta'));
        $this->assertSame([], ManufacturerNormFacts::norms(null));
        $this->assertSame([], ManufacturerNormFacts::rows('nie tablica'));
        $this->assertSame(['normy' => []], ManufacturerNormFacts::context(null));
    }
}
