<?php

declare(strict_types=1);

namespace App\Support;

/**
 * Znak „<”, po którym nie zaczyna się znacznik („3950 - <4700”, „rezystancja skrośna <35 megaomów”, „< 1000 V”),
 * zamieniony na encję przed DOMDocument::loadHTML. Przeglądarka pokazuje taki znak jako tekst (HTML5: znacznik
 * zaczyna się od litery, „/”, „!” albo „?”), ale libxml 2.9.10 z serwera produkcyjnego bierze go za początek
 * znacznika i połyka tekst aż do najbliższego „>” — lokalny libxml 2.10 tego nie robi, więc testy błędu nie widziały.
 *
 * Skutki na produkcji (audyt UVEX 08.10.2026): tabele ochrony laserowej sklejone w jeden wiersz („3950 - 4700 -
 * 4765 - 5200 - 14500 (OD10+)” zamiast czterech zakresów z OD4+…OD10+) i opisy obuwia ucięte na „rezystancja
 * skrośna”. Prawdziwe znaczniki i komentarze zostają bez zmian.
 */
final class HtmlBareLessThan
{
    public static function escape(string $html): string
    {
        return (string) (preg_replace('/<(?![A-Za-z\/!?])/', '&lt;', $html) ?? $html);
    }
}
