<?php

declare(strict_types=1);

namespace App\Support;

/**
 * Ta sama miejscowość zapisana różnie w ERP XL („DĄBROWA GÓRNICZA”, „Dąbrowa Górnicza”, „Dabrowa Gornicza”) — klucz
 * scalania i pisownia do wyświetlenia (raport Klienci, filtr miejscowości na liście klientów).
 */
final class CityName
{
    private const POLISH_UPPER = ['Ą' => 'A', 'Ć' => 'C', 'Ę' => 'E', 'Ł' => 'L', 'Ń' => 'N', 'Ó' => 'O', 'Ś' => 'S', 'Ź' => 'Z', 'Ż' => 'Z'];

    /**
     * Klucz scalania: wielkie litery bez polskich znaków („Kraków”, „Krakow”, „KRAKÓW ” → KRAKOW).
     * Skrótów („GŁOGÓW MŁP.”) nie rozwijamy — nie zgadujemy.
     */
    public static function key(string $city): string
    {
        return strtr(mb_strtoupper(trim($city), 'UTF-8'), self::POLISH_UPPER);
    }

    /**
     * Wyświetlana pisownia: najczęstsza; remis — wolimy zapis z polskimi znakami, potem nie w całości wielkimi
     * literami, potem alfabet.
     *
     * @param  array<string, int>  $spellings
     */
    public static function displaySpelling(array $spellings): string
    {
        $best = null;
        foreach ($spellings as $spelling => $count) {
            $spelling = (string) $spelling;
            $upper = mb_strtoupper($spelling, 'UTF-8');
            $rank = [$count, strtr($upper, self::POLISH_UPPER) !== $upper ? 1 : 0, $spelling !== $upper ? 1 : 0];
            if ($best === null || $rank > $best[1] || ($rank === $best[1] && strcmp($spelling, $best[0]) < 0)) {
                $best = [$spelling, $rank];
            }
        }

        return $best[0] ?? '';
    }
}
