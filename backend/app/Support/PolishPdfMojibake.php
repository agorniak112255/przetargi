<?php

declare(strict_types=1);

namespace App\Support;

/**
 * Polski tekst z PDF-a, którego czcionki nie mają tabeli ToUnicode i deklarują kodowanie Windows-1252, choć glify
 * ułożono w Windows-1250. pdftotext (i każdy inny odczyt) daje wtedy „Rêkawica antyprzeciêciowa”, „œci¹gacz”,
 * „zagro¿enie” zamiast „Rękawica antyprzecięciowa”, „ściągacz”, „zagrożenie” (21.09.2026: karty katalogowe
 * TK GLOVES z Tegro — 17 z 8107 tekstów dokumentów w bazie). Bywa to tylko część czcionek pliku: w tym samym tekście
 * stoi poprawne „DOSTĘPNE” i zepsute „Rêkawica”. Taki tekst trafia do indeksu wyszukiwania i do źródeł opisu karty
 * — słowa z ogonkami w nim nie istnieją.
 *
 * Zamiana jest odwracalna znak w znak (bajt Windows-1250 odczytany jako Windows-1252), ale te same znaki bywają
 * prawdziwe: „ê” i „œ” po francusku, „ñ” i „¿” po hiszpańsku, „³” w „m³”, „£” przed kwotą. Dlatego:
 * - dokument poprawiamy tylko wtedy, gdy ma dowód zepsucia: znak ¹ ³ ¿ ¥ £ ¯ WEWNĄTRZ słowa, między literami
 *   („w³ókno”, „WI¥ZKA”) — tak nie pisze się w żadnym języku;
 * - w takim dokumencie zmieniamy tylko słowa bez prawdziwych polskich liter (słowo z „ę” pochodzi z dobrej czcionki).
 */
final class PolishPdfMojibake
{
    /** Polska litera zapisana w Windows-1250 i odczytana jako Windows-1252 → właściwa litera. */
    private const MAP = [
        '¹' => 'ą', '¥' => 'Ą',
        'æ' => 'ć', 'Æ' => 'Ć',
        'ê' => 'ę', 'Ê' => 'Ę',
        '³' => 'ł', '£' => 'Ł',
        'ñ' => 'ń', 'Ñ' => 'Ń',
        'œ' => 'ś', 'Œ' => 'Ś',
        'Ÿ' => 'ź',
        '¿' => 'ż', '¯' => 'Ż',
    ];

    /** Dowód zepsucia: znak, który w słowie nigdy nie stoi między literami. */
    private const MARKER_INSIDE_WORD = '/(?<=\p{L})[¹³¿¥£¯](?=\p{L})/u';

    /** Słowo: od litery, dalej litery i znaki zepsutych liter (także na końcu: „by³” = „był”). */
    private const WORD = '/\p{L}[\p{L}¹³¿¥£¯]*/u';

    private const POLISH_LETTERS = '/[ąćęłńśźżĄĆĘŁŃŚŹŻ]/u';

    public static function looksBroken(string $text): bool
    {
        return preg_match(self::MARKER_INSIDE_WORD, $text) === 1;
    }

    /** Tekst z polskimi literami w miejsce zepsutych; tekst bez dowodu zepsucia — bez zmian. */
    public static function repair(string $text): string
    {
        if (! self::looksBroken($text)) {
            return $text;
        }

        return (string) preg_replace_callback(
            self::WORD,
            static fn (array $m): string => preg_match(self::POLISH_LETTERS, $m[0]) === 1 ? $m[0] : strtr($m[0], self::MAP),
            $text,
        );
    }
}
