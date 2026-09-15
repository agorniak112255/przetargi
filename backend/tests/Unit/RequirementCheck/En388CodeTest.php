<?php

declare(strict_types=1);

namespace Tests\Unit\RequirementCheck;

use App\Support\RequirementCheck\En388Code;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class En388CodeTest extends TestCase
{
    /**
     * @return iterable<string, array{string, string, string}>
     */
    public static function codes(): iterable
    {
        yield 'zwarty w nawiasie (karta produkcyjna)' => ['EN 388:2016+A1:2018 (1X42C), EN 407:2020 (X1XXXX)', '1X42C', '1X42C'];
        yield 'ze spacjami (44-304)' => ['Poziomy EN 388: 4 3 4 1 B', '4 3 4 1 B', '4341B'];
        yield 'z kropkami (wymaganie poz. 1)' => ['EN 388 z poziomami min. 2.X.4.2.C (odporność na przecięcie poziom C)', '2.X.4.2.C', '2X42C'];
        yield 'bez spacji przed kodem' => ['EN388 (2.X.4.2.C),', '2.X.4.2.C', '2X42C'];
        yield 'stary kod czterocyfrowy' => ['EN 388:2003 4131', '4131', '4131-'];
        yield 'uderzenie P' => ['EN 388:2016 4X43DP', '4X43DP', '4X43DP'];
        yield 'po myślniku z opisem normy (KRYTECH)' => ['EN 388:2016 – Rękawice chroniące przed zagrożeniami mechanicznymi (4343B)', '4343B', '4343B'];
        yield 'goły kod za normą' => ['EN 388 4X42C', '4X42C', '4X42C'];
        yield 'małe litery' => ['EN 388: 4x43c', '4x43c', '4X43C'];
        yield 'rok w nawiasie przed kodem' => ['EN 388 (2003) 4X43C', '4X43C', '4X43C'];
        yield 'min. przed kodem' => ['EN 388 min. 2121', '2121', '2121-'];
    }

    #[Test]
    #[DataProvider('codes')]
    public function reads_code_right_after_the_norm(string $text, string $literal, string $canonical): void
    {
        $code = En388Code::first($text);

        $this->assertNotNull($code);
        $this->assertSame($literal, $code->text);
        $this->assertSame($canonical, $code->canonical());
        $this->assertFalse($code->worded);
    }

    #[Test]
    public function keeps_x_as_not_tested_and_missing_iso_as_null(): void
    {
        $modern = En388Code::first('EN 388 (1X42C)');
        $old = En388Code::first('EN 388 4131');

        $this->assertSame('X', $modern?->levels['coupe']);
        $this->assertNull($old?->levels['iso'], 'stary kod nie podaje ISO 13997 — to brak, nie X');
    }

    #[Test]
    public function reads_worded_levels_from_tender_requirement(): void
    {
        // poz. 7 opisowy15 — bez kodu, każda pozycja słownie
        $code = En388Code::first('EN 388:2016 – ścieranie 4, przecięcie (Coup Test) 3, rozdzieranie 4, przekłucie 1, przecięcie wg metody ISO – poziom B; EN 407:2004 – ciepło kontaktowe poziom 1');

        $this->assertNotNull($code);
        $this->assertTrue($code->worded);
        $this->assertSame('4341B', $code->canonical());
    }

    #[Test]
    public function reads_partial_worded_levels_without_inventing_the_rest(): void
    {
        $code = En388Code::first('EN 388 – odporność na przecięcie poziom C, na rozdzieranie 4, na przekłucie 2');

        $this->assertSame(['abrasion' => null, 'coupe' => null, 'tear' => '4', 'puncture' => '2', 'iso' => 'C', 'impact' => null], $code?->levels);
    }

    #[Test]
    public function reads_worded_card_description_with_dashes(): void
    {
        $code = En388Code::first('EN 388:2016 - Rękawice ochronne. Odporność na ścieranie - 4 Odporność na przecięcie wg "Coup Test" - 3 Odporność na rozerwanie - 4 Odporność na przekłucie - 1 Odporność na przecięcie wg ISO 13977 - B EN 407:2004 - Odporność na palenie - x');

        $this->assertSame('4341B', $code?->canonical());
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function notEn388(): iterable
    {
        yield 'kod EN 407 tuż za gołym EN 388' => ['Normy: EN 420, EN 388, EN 407 (X.1.X.X.X)'];
        yield 'EN 3880 to inna norma' => ['EN 3880 4131A'];
        yield 'kod bez numeru normy (numer modelu)' => ['Rękawice 4131A'];
        yield 'rok w nawiasie to nie kod 2-0-0-3' => ['EN 388 (2003)'];
        yield 'EN 388 bez poziomów (poz. 2)' => ['ochrona dłoni przed przecięciem i ścieraniem potwierdzona oznakowaniem zgodnie z EN 388; konstrukcja'];
        yield 'poziom przed normą należy do cechy, nie do kodu' => ['Odporność na przecięcie poziom C (EN 388)'];
        // cyfry dalej w zdaniu to nie kod (recenzja): numer artykułu, telefon, liczba sztuk
        yield 'numer artykułu po przecinku' => ['Spełnia EN 388, nr art. 4543, rozmiary 7-10'];
        yield 'telefon w kolejnym zdaniu' => ['Rękawice zgodne z EN 388. Producent: ul. Polna 1, tel. 4444 12'];
        yield 'liczba par w kolejnym zdaniu' => ['Norma EN 388. Karton 1200 par.'];
        yield 'liczba w nowej linii' => ["EN 388\n1200 szt."];
        yield 'rozmiar małymi literami' => ['en 388, rozm. 8x10'];
        yield 'kod dalej w tekście opisu' => ['EN 388 rękawice w rozmiarze 9, kolor 4131'];
    }

    #[Test]
    #[DataProvider('notEn388')]
    public function ignores_text_that_is_not_an_en388_code(string $text): void
    {
        $this->assertNull(En388Code::first($text));
    }
}
