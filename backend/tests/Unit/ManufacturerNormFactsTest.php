<?php

declare(strict_types=1);

namespace Tests\Unit;

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

    public function test_karta_bez_ani_jednej_pary_nie_zapisuje_kolumny(): void
    {
        $this->assertNull(ManufacturerNormFacts::build([], 'atg', 'ATG', 'https://example.test/karta'));
        $this->assertNull(ManufacturerNormFacts::build([['label' => '  ', 'value' => '4331B']], 'atg', 'ATG', 'https://example.test/karta'));
        $this->assertSame([], ManufacturerNormFacts::norms(null));
        $this->assertSame([], ManufacturerNormFacts::rows('nie tablica'));
        $this->assertSame(['normy' => []], ManufacturerNormFacts::context(null));
    }
}
