<?php

declare(strict_types=1);

namespace App\Support;

/**
 * Tekst wymagania bez liczb, które nie są kodem produktu: numery norm z rokiem, poprawką i częścią
 * („PN-EN 140:2004 (EN 140:1998)”, „EN 388:2016+A1:2018”, „EN 50321-1”), numery rozporządzeń
 * („(UE) 2016/425”, „(WE) 1907/2006”) i liczby z jednostką („5000 ppm”, „500 ml”, „17 kV”).
 *
 * Wspólne dla kodów z SIWZ w dopasowaniu przetargu (ProductMatchService::codeCandidates) i dla
 * wyszukiwania po kodzie modelu (ProductAiSearchService::modelCodePhrases). Dwie osobne kopie
 * rozjechały się: wyszukiwarka brała „2004” i „1998” z normy za kod i wciągała do puli półmaski
 * klej 3M 7100200484 i materiał odblaskowy 1998467 (AUDYT_4 §15, przetarg 1 poz. 13).
 * Zwraca tekst małymi literami.
 */
final class RequirementCodeNoise
{
    public static function strip(string $text): string
    {
        $t = mb_strtolower($text);
        // normy z rokiem, poprawką i częścią (PN-EN / EN / EN ISO)
        $t = preg_replace(
            '/\b(?:pn[\s-]*)?en(?:[\s-]*iso)?[\s-]*\d+(?:[\s\-:]+\d+\b)*(?:\s*\+\s*a\d+(?::\s*\d+\b)?)*/u',
            ' ',
            $t
        ) ?? $t;
        $t = preg_replace('/\b(?:pn[\s-]*)?iso[\s-]*\d+(?:[\s\-:]+\d+\b)*/u', ' ', $t) ?? $t;
        // rozporządzenia i dyrektywy: (UE) 2016/425, WE 1907/2006, 89/686/EWG
        $t = preg_replace('/\(?\b(?:ue|we|eu|ewg)\)?\s*(?:nr\s*)?\d{2,4}\s*\/\s*\d{2,4}\b/u', ' ', $t) ?? $t;
        $t = preg_replace('/\b\d{2,4}\s*\/\s*\d{2,4}\s*\/\s*(?:ewg|we|ue|eu)\b/u', ' ', $t) ?? $t;
        // liczby z jednostką (miara, stężenie, napięcie, czas)
        $t = preg_replace(
            '/\b\d+(?:[.,]\d+)?\s*(?:ppm|ml|mm|cm|m|g|kg|l|szt|par|kv|v|db|min|mies\w*|lat|°c|%)(?![a-z0-9])/u',
            ' ',
            $t
        ) ?? $t;

        return trim(preg_replace('/\s+/u', ' ', $t) ?? $t);
    }
}
