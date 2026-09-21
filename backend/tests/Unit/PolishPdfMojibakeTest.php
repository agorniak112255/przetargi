<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Support\PolishPdfMojibake;
use PHPUnit\Framework\TestCase;

/**
 * Tekst karty katalogowej TK GLOVES z Tegro (21.09.2026) tak, jak zwraca go pdftotext: polskie litery w Windows-1250
 * odczytane jako Windows-1252. Fragmenty dosłownie z pliku; przykłady obcojęzyczne — syntetyczne.
 */
final class PolishPdfMojibakeTest extends TestCase
{
    public function test_repairs_a_catalogue_card_read_in_the_wrong_code_page(): void
    {
        $broken = "SHARK\nRêkawica antyprzeciêciowa\nDOSTÊPNE OPAKOWANIA\npokryta poliuretanem\nœci¹gacz\n"
            ."do prac, podczas, których\nwystêpuje zagro¿enie przeciêciem\nWI¥ZKA\n"
            ."Rêkawica dziana ze splotu w³ókien polietylenowych o\nultra wysokiej wytrzyma³oœci (UHPPE)";

        $this->assertSame(
            "SHARK\nRękawica antyprzecięciowa\nDOSTĘPNE OPAKOWANIA\npokryta poliuretanem\nściągacz\n"
            ."do prac, podczas, których\nwystępuje zagrożenie przecięciem\nWIĄZKA\n"
            ."Rękawica dziana ze splotu włókien polietylenowych o\nultra wysokiej wytrzymałości (UHPPE)",
            PolishPdfMojibake::repair($broken),
        );
    }

    public function test_in_a_file_with_some_good_fonts_only_the_broken_words_change(): void
    {
        $mixed = 'BUDGIE Rêkawica monterska DOSTĘPNE OPAKOWANIA Pokryta elastyczną pianką, w³ókno nylonowe';

        $this->assertSame(
            'BUDGIE Rękawica monterska DOSTĘPNE OPAKOWANIA Pokryta elastyczną pianką, włókno nylonowe',
            PolishPdfMojibake::repair($mixed),
        );
    }

    public function test_real_foreign_letters_and_symbols_stay_without_evidence_of_the_broken_code_page(): void
    {
        foreach ([
            'Déclaration de conformité : être conforme, fenêtre, cœur de l’œuvre',
            '¿Qué es? El señor compró guantes por £25 y un depósito de 2 m³.',
            'Rękawica antyprzecięciowa z włókna HPPE, ściągacz, zagrożenie przecięciem.',
            'Footnote¹ and volume 3 m³ in an English data sheet',
        ] as $text) {
            $this->assertFalse(PolishPdfMojibake::looksBroken($text), $text);
            $this->assertSame($text, PolishPdfMojibake::repair($text));
        }
    }
}
