<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Services\Enrichment\NormListSanity;
use PHPUnit\Framework\TestCase;

final class NormListSanityTest extends TestCase
{
    /** Przypadek z testu A (§4, pkt 8): jedna norma zostaje, kategoria idzie do certyfikatów, reszta z powodem. */
    public function test_mixed_list_from_stage_three_contract(): void
    {
        $result = NormListSanity::clean(['EN ISO 13999 D', 'EN 388 15 gauge', 'Kategoria 2', 'EN ISO 374-1 Type B JKLOPT']);

        $this->assertSame(['EN 388'], $result['norms']);
        $this->assertSame(['Kategoria 2'], $result['certificates']);
        $this->assertCount(2, $result['dropped']);
        $this->assertStringContainsString('EN ISO 13999 D', $result['dropped'][0]);
        $this->assertStringContainsString('EN ISO 374-1 Type B JKLOPT', $result['dropped'][1]);
        $this->assertStringContainsString('typ B', $result['dropped'][1]);
        $this->assertStringContainsString('EN 388 15 gauge → EN 388', $result['fixed'][0]);
    }

    /** Prawdziwe oznaczenia z poziomami, typami i klasami — lista wraca bez zmian. */
    public function test_valid_designations_stay_untouched(): void
    {
        $norms = [
            'DIN 13157', 'ASTM F2675', 'PN-EN 50321-1:2018', 'EN 343 (4. klasa)', 'EN 166 2C-1.2 1 FT', 'EN 388 4X43D',
            'EN ISO 374-1:2016/Type A AJKLPT', 'EN 407 X1XXXX', 'EN ISO 20471 klasa 2', 'EN 61340-5-1', 'BS EN 61111 Class 0',
            'EN 13034 Type 6-B', 'EN ISO 13982-1 Type 5-B', 'EN 388:2016 +A1:2018 3121B', 'EN 388:2016 (poziom 2)',
            'EN 388 3X21XP', 'EN ISO 21420:2020', 'EN 420 (zręczność 5)', 'EN ISO 374-1:2016 AKLMPT', 'EN ISO 13999-1:2006',
            'ANSI/ISEA 105-2024 (klasa A4)', 'FDA 21 CFR 177.1520', 'BS EN 13501-1 (klasa Cfl - s1)', 'Rozporządzenie (UE) 2016/425',
            'DGUV 112-191', 'Oznaczenie soczewki: 5-2.5 1 FT KN', 'SRC – odporność na poślizg', 'Typ 5', 'STANAG 2920',
            'OEKO-TEX® STANDARD 100', 'EN ISO D', 'EN 166 (oznaczenie: 1 F)', 'EN ISO 374-1:2016 Typ C K',
        ];

        $this->assertSame(['norms' => $norms, 'certificates' => [], 'dropped' => [], 'fixed' => []], NormListSanity::clean($norms));
    }

    public function test_levels_that_belong_to_another_standard_are_stripped(): void
    {
        $result = NormListSanity::clean([
            'EN 388 (klasa 15)',
            'EN 388 (Klasa odporności na przecięcia: 10)',
            'EN 388 (poziom A2)',
            'EN 388 4131X, 13 gauge',
            'EN 388:2016 +A1:2018 4X42C 15 gauge',
            'EN 420:2003 + A1:2009 (klasa ochrony 4121B)',
            'EN ISO 21420 (przecięcia poziom E)',
        ]);

        $this->assertSame(['EN 388 4131X', 'EN 388:2016 +A1:2018 4X42C', 'EN 420:2003 + A1:2009', 'EN ISO 21420'], $result['norms']);
        $this->assertCount(7, $result['fixed']);
        $this->assertStringContainsString('gauge', $result['fixed'][3]);
        $this->assertStringContainsString('poziom ANSI', $result['fixed'][2]);
        $this->assertSame([], $result['dropped']);
    }

    public function test_iso_388_is_written_as_en_388(): void
    {
        $result = NormListSanity::clean(['EN ISO 388', 'EN ISO 388 (odporność na ścieranie)']);

        $this->assertSame(['EN 388', 'EN 388 (odporność na ścieranie)'], $result['norms']);
        $this->assertCount(2, $result['fixed']);
    }

    public function test_items_without_a_norm_are_dropped_and_categories_moved(): void
    {
        $result = NormListSanity::clean([
            'ATEX HAZARDOUS AREA / ATMOSPHERE GROUP',
            'SCS (Scientific Certification Systems)',
            'Klasa 2 (napięcie przemienne 17 000 V, stałe 25 500 V)',
            'EN (brak szczegółowych poziomów w źródle)',
            'ANSI/EN (odporność na ścieranie)',
            'CSA',
            'Kat. III',
            'Kategoria 3: 0334',
            'ATEX II 2G Ex h IIB T4 Gb',
        ]);

        $this->assertSame(['ATEX II 2G Ex h IIB T4 Gb'], $result['norms']);
        $this->assertSame(['Kat. III', 'Kategoria 3: 0334'], $result['certificates']);
        $this->assertCount(6, $result['dropped']);
        foreach ($result['dropped'] as $reason) {
            $this->assertStringStartsWith('pozycja bez oznaczenia normy', $reason);
        }
    }

    public function test_cut_level_under_13999_and_conflicting_chemical_type_are_dropped(): void
    {
        $result = NormListSanity::clean([
            'EN ISO 13999-1 (klasa D)',
            'EN ISO 13999 (poziom A2)',
            'ANSI/ISEA 105-2024 poziom A6, EN ISO 13999-1 poziom F',
            'EN ISO 374-1:2016 (klasa ochrony 3, typ B, kod przenikania AKLMPT)',
            'EN ISO 374-1:2016 Type A JKL',
        ]);

        $this->assertSame([], $result['norms']);
        $this->assertCount(5, $result['dropped']);
    }

    public function test_problu_en166_template_without_symbols_is_dropped(): void
    {
        $result = NormListSanity::clean([
            'EN 166 (oznaczenie na oprawce: Crown CE UKCA)',
            'EN 166 (oznaczenie na soczewce: PrB420)',
            'EN 166 (oznaczenie PrB420 na soczewkach)',
            'EN 166:2001 (oznaczenie soczewki: 2C-1.2 1 FT)',
        ]);

        $this->assertSame(['EN 166:2001 (oznaczenie soczewki: 2C-1.2 1 FT)'], $result['norms']);
        $this->assertCount(3, $result['dropped']);
    }

    public function test_prompt_echo_item_is_dropped(): void
    {
        $result = NormListSanity::clean([
            "EN ISO 21420 (wymagania ogólne - implied by context of PPE, but specific cut level is EN ISO 13999 or similar, source says 'EN ISO D / A4' for cut resistance)",
            'EN 388 4X43D',
        ]);

        $this->assertSame(['EN 388 4X43D'], $result['norms']);
        $this->assertStringStartsWith('powtórzenie polecenia', $result['dropped'][0]);
    }

    public function test_sentence_problems(): void
    {
        $this->assertCount(1, NormListSanity::sentenceProblems(
            'Produkt spełnia normy EN ISO 13999-1 (klasa odporności na przecięcie D) oraz ANSI/ISEA 105-2024 (klasa odporności na przecięcie A4).'
        ));
        $this->assertCount(1, NormListSanity::sentenceProblems(
            'Produkt spełnia normy EN 388:2016 +A1:2018 (klasa ochrony 3121B), EN ISO 21420:2020, EN ISO 374-1:2016 (klasa ochrony 3, typ B, kod przenikania AKLMPT) oraz EN ISO 374-5:2016.'
        ));
        $this->assertSame([], NormListSanity::sentenceProblems('Rękawice spełniają normy EN 388 4X43D i EN ISO 374-1:2016/Type A AJKLPT.'));
        $this->assertSame([], NormListSanity::sentenceProblems('Rękawice kolcze zgodne z EN ISO 13999-1:2006 do pracy z nożem w temperaturze do 40°C.'));
    }

    /** chipdip.ru przy ProBlu: szablonowe pole „EN166 Lens Marking” z wartością bez symboli EN 166 nie potwierdza normy. */
    public function test_template_attribute_rows_without_symbols_are_removed_from_source_text(): void
    {
        $source = "Frame Material Acetate\nEN166 Lens Marking PrB420\nLens Colour Clear\nEN166 Frame Marking\nCrown CE UKCA\n"
            ."EN 166 Lens Marking 2C-1.2 1 FT\nEN166 Lens Marking: 1 F\nEN 166:2001\nNorma EN 166 dla okularów.";

        $clean = NormListSanity::withoutTemplateAttributeRows($source);

        $this->assertStringNotContainsString('PrB420', $clean);
        $this->assertStringNotContainsString('EN166 Frame Marking', $clean);
        $this->assertStringContainsString('EN 166 Lens Marking 2C-1.2 1 FT', $clean);
        $this->assertStringContainsString('EN166 Lens Marking: 1 F', $clean);
        $this->assertStringContainsString('EN 166:2001', $clean);
        $this->assertStringContainsString('Norma EN 166 dla okularów.', $clean);
        $this->assertStringContainsString('Lens Colour Clear', $clean);
        $this->assertSame('Tekst bez pól.', NormListSanity::withoutTemplateAttributeRows('Tekst bez pól.'));
    }
}
