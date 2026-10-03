<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Services\Bzp\NoticeSections;
use PHPUnit\Framework\TestCase;

/**
 * Numeracja w tekście zamawiającego („2.1) Wadium…” w opisie wadium, „1.1) …” w opisie kryterium) nie jest punktem
 * ogłoszenia — punkt ogłoszenia ma numer swojej sekcji (SEKCJA VI → 6.x).
 */
final class NoticeSectionsTest extends TestCase
{
    public function test_numbering_inside_buyer_text_does_not_cut_the_point(): void
    {
        $html = implode('', [
            '<h2>SEKCJA IV - PRZEDMIOT ZAMÓWIENIA</h2>',
            '<p>4.1.) Informacje ogólne odnoszące się do przedmiotu zamówienia.</p>',
            '<p>4.3.) Kryteria oceny ofert:</p>',
            '<p>4.3.1.) Sposób oceny ofert: Cena 60%</p>',
            '<p>1.1) cena brutto oferty</p>',
            '<p>4.3.2.) Termin gwarancji 40%</p>',
            '<h2>SEKCJA VI - WARUNKI ZAMÓWIENIA</h2>',
            '<p>6.1.) Zamawiający przewiduje udzielenie zamówień, o których mowa w art. 214: Nie</p>',
            '<p>6.4.) Zamawiający wymaga wadium: Tak</p>',
            '<p>6.4.1) Informacje dotyczące wadium:</p>',
            '<p>2.1) Wadium wnosi się w wysokości 1 000,00 zł.</p>',
            '<p>3.1) Zwrot wadium następuje na zasadach art. 98.</p>',
            '<p>6.5.) Zamawiający wymaga zabezpieczenia należytego wykonania umowy: Nie</p>',
            '<p>6.6.) Wymagania dotyczące składania oferty przez wykonawców wspólnie ubiegających się</p>',
        ]);

        $sections = collect((new NoticeSections)->extract($html))->keyBy('key');

        $this->assertSame(implode("\n", [
            '6.4.) Zamawiający wymaga wadium: Tak',
            '6.4.1) Informacje dotyczące wadium:',
            '2.1) Wadium wnosi się w wysokości 1 000,00 zł.',
            '3.1) Zwrot wadium następuje na zasadach art. 98.',
            '6.5.) Zamawiający wymaga zabezpieczenia należytego wykonania umowy: Nie',
        ]), $sections['deposit']['text']);
        $this->assertSame(implode("\n", [
            '4.3.) Kryteria oceny ofert:',
            '4.3.1.) Sposób oceny ofert: Cena 60%',
            '1.1) cena brutto oferty',
            '4.3.2.) Termin gwarancji 40%',
        ]), $sections['criteria']['text']);
        $this->assertStringNotContainsString('cena brutto', $sections['subject']['text']);
    }
}
