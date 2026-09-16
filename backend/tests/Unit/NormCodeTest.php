<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Support\NormCode;
use Tests\TestCase;

/**
 * Normy scalane z kilku źródeł trafiały na kartę po kilka razy („EN 388”, „EN 388:2016”,
 * „EN388:2016+A1:2018”). Klucz ma sklejać te same normy i NIE sklejać różnych.
 */
final class NormCodeTest extends TestCase
{
    /** Zapis krajowy, spacja, rok, poprawka, wielkość liter, myślnik i kropka nie zmieniają normy. */
    public function test_key_ignores_national_prefix_spacing_year_amendment_and_case(): void
    {
        $oczekiwany = NormCode::key('EN 388');
        $this->assertSame('EN 388', $oczekiwany);

        foreach ([
            'EN388',
            'en 388',
            'EN 388.',
            'EN 388:2016',
            'EN 388: 2016',
            'EN 388:2016+A1:2018',
            'EN388:2016+A1:2018',
            'EN 388:2016 + A1:2018',
            'EN 388 +A1',
            'PN-EN 388',
            'PN–EN 388',
            'PN-EN 388:2017-02',
            'DIN EN 388',
            'BS EN 388',
            '- EN 388',
            '  EN   388  ',
        ] as $zapis) {
            $this->assertSame($oczekiwany, NormCode::key($zapis), $zapis);
        }
    }

    /** Część normy to inne wymaganie — EN 374-1 nie spełnia EN 374-5. */
    public function test_key_keeps_norm_parts_apart(): void
    {
        $this->assertSame('EN 374', NormCode::key('EN 374'));
        $this->assertSame('EN 374-1', NormCode::key('EN 374-1'));
        $this->assertSame('EN 374-5', NormCode::key('EN 374-5'));
        $this->assertNotSame(NormCode::key('EN 374-1'), NormCode::key('EN 374-5'));
        $this->assertNotSame(NormCode::key('EN 374-1'), NormCode::key('EN 374'));
        $this->assertSame('EN 61340-5-1', NormCode::key('EN 61340-5-1'));
        $this->assertNotSame(NormCode::key('EN 61340-5-1'), NormCode::key('EN 61340-5'));
    }

    /** Człon ISO jest częścią oznaczenia, a nie ozdobnikiem. */
    public function test_key_keeps_iso_member(): void
    {
        $this->assertSame('EN ISO 20345', NormCode::key('EN ISO 20345'));
        $this->assertSame('EN 20345', NormCode::key('EN 20345'));
        $this->assertNotSame(NormCode::key('EN ISO 20345'), NormCode::key('EN 20345'));
        $this->assertSame('EN ISO 13688', NormCode::key('EN ISO 13688:2013'));
        $this->assertSame('EN ISO 13688', NormCode::key('PN-EN ISO 13688'));
        $this->assertSame('ISO 9001', NormCode::key('ISO 9001'));
    }

    /** Typ niesie treść — rękawica typu A chroni dłużej niż typu B. */
    public function test_key_keeps_type_but_ignores_protection_levels(): void
    {
        $this->assertSame('EN 374-1 TYP A', NormCode::key('EN 374-1 Typ A'));
        $this->assertNotSame(NormCode::key('EN 374-1 Typ A'), NormCode::key('EN 374-1 Typ B'));
        $this->assertSame(NormCode::key('EN 374-1 Typ A'), NormCode::key('PN-EN 374-1:2016 TYPE A'));
        $this->assertSame(NormCode::key('EN ISO 374-1 Typ B'), NormCode::key('EN ISO 374-1:2016/Typ B'));

        // poziomy odporności to wciąż ta sama norma
        $this->assertSame(NormCode::key('EN 388'), NormCode::key('EN 388 4X42C'));
        $this->assertSame(NormCode::key('EN 388'), NormCode::key('EN 388:2016+A1:2018 3121X'));
        $this->assertSame(NormCode::key('EN 166'), NormCode::key('EN 166 1F'));
    }

    /** Prawdziwe zapisy z kart BHP muszą być rozpoznane, a nie odrzucone jako tekst. */
    public function test_real_bhp_designations_are_recognised(): void
    {
        $przypadki = [
            'EN 166' => 'EN 166',
            'EN 166 1F' => 'EN 166',
            'EN ISO 20345:2011 S1 SRC' => 'EN ISO 20345',
            'EN 149:2001+A1:2009 FFP2 NR D' => 'EN 149',
            'PN-EN 388:2017-02' => 'EN 388',
            'EN 511' => 'EN 511',
            'EN 12477 Typ A' => 'EN 12477 TYP A',
            'EN 61340-5-1' => 'EN 61340-5-1',
            'EN ISO 374-1:2016/Typ B' => 'EN ISO 374-1 TYP B',
            'EN 60903' => 'EN 60903',
            'EN 13034 Typ 6' => 'EN 13034 TYP 6',
            'EN 407' => 'EN 407',
            'EN ISO 21420:2020' => 'EN ISO 21420',
        ];
        foreach ($przypadki as $zapis => $klucz) {
            $this->assertTrue(NormCode::looksLikeNorm((string) $zapis), (string) $zapis);
            $this->assertSame($klucz, NormCode::key((string) $zapis), (string) $zapis);
        }
    }

    /** Zdanie o normie to tekst — klucz z niego byłby mylący, a druga norma w wierszu by zginęła. */
    public function test_sentences_and_junk_are_not_norms(): void
    {
        foreach ([
            '',
            '   ',
            '2016',
            ':2016',
            'Rękawice spełniają wymagania normy EN 388:2016',
            'Produkt zgodny z EN 388 oraz EN 420',
            'EN 388, EN 374',
            'EN 388 dla monterów',
            'Kategoria II',
            'Certyfikat CE',
            '1 sztuka',
            'EN',
        ] as $tekst) {
            $this->assertFalse(NormCode::looksLikeNorm($tekst), $tekst);
            $this->assertSame('', NormCode::key($tekst), $tekst);
        }
    }

    /** Zwijamy do jednej pozycji i zostawiamy wariant najbogatszy w informacje. */
    public function test_dedupe_keeps_the_richest_variant(): void
    {
        // zostaje zapis źródła zwycięskiego wariantu, bez sklejania go z pozostałych
        $this->assertSame(
            ['EN388:2016+A1:2018'],
            NormCode::dedupe(['EN 388', 'EN 388:2016', 'EN388:2016+A1:2018'])
        );
        $this->assertSame(
            ['PN-EN 388:2016+A1:2018'],
            NormCode::dedupe(['EN 388', 'PN-EN 388:2016+A1:2018', 'EN 388:2016'])
        );
        $this->assertSame(
            ['EN 388 4X42C'],
            NormCode::dedupe(['EN 388', 'EN 388 4X42C'])
        );
        // poziomy biją rok i poprawkę
        $this->assertSame(
            ['EN 388 4X42C'],
            NormCode::dedupe(['EN 388:2016+A1:2018', 'EN 388 4X42C'])
        );
        // bogatszy wariant na drugim miejscu też wygrywa
        $this->assertSame(
            ['EN ISO 20345:2011 S1 SRC'],
            NormCode::dedupe(['EN ISO 20345', 'PN-EN ISO 20345:2011 S1', 'EN ISO 20345:2011 S1 SRC'])
        );
    }

    /** Sprzeczne poziomy przy tej samej normie zostają obydwa — nie zgadujemy, który jest prawdziwy. */
    public function test_dedupe_keeps_conflicting_levels(): void
    {
        $this->assertSame(
            ['EN 388 4X42C', 'EN 388 3121X'],
            NormCode::dedupe(['EN 388 4X42C', 'EN 388 3121X'])
        );
        $this->assertSame(
            ['EN 388 4X42C', 'EN 388 3121X'],
            NormCode::dedupe(['EN 388', 'EN 388 4X42C', 'EN 388 3121X', 'EN 388:2016'])
        );
    }

    /** Różne normy przeżywają deduplikację w kolejności pierwszego wystąpienia. */
    public function test_dedupe_keeps_first_occurrence_order_and_distinct_norms(): void
    {
        $this->assertSame(
            ['EN ISO 374-1:2016 Typ A', 'EN 374-5', 'EN 388 4X42C', 'PN-EN ISO 21420'],
            NormCode::dedupe([
                'EN ISO 374-1 Typ A',
                'EN 374-5',
                'EN 388',
                'PN-EN ISO 21420',
                'EN ISO 374-1:2016 Typ A',
                'EN 388 4X42C',
                'EN ISO 21420',
            ])
        );
    }

    /** Pozycje niebędące normami przechodzą bez zmian — tylko dosłowne powtórki znikają. */
    public function test_dedupe_leaves_non_norm_items_alone(): void
    {
        $this->assertSame(
            ['Kategoria II', 'EN 388:2016', 'Certyfikat CE nr 2777', 'Rękawice spełniają wymagania normy EN 388'],
            NormCode::dedupe([
                'Kategoria II',
                'EN 388',
                'Certyfikat CE nr 2777',
                'Kategoria II',
                '',
                'EN 388:2016',
                'Rękawice spełniają wymagania normy EN 388',
                '   ',
                'Certyfikat CE nr 2777',
            ])
        );
        $this->assertSame([], NormCode::dedupe([]));
    }
}
