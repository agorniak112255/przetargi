<?php

declare(strict_types=1);

namespace App\Support;

/**
 * Tekst wymagania bez liczb, które nie są kodem produktu: numery norm z rokiem, poprawką i częścią
 * („PN-EN 140:2004 (EN 140:1998)”, „EN 388:2016+A1:2018”, „EN 50321-1”, „EN IEC 61340-4-3:2018”),
 * numery rozporządzeń („(UE) 2016/425”, „(WE) 1907/2006”, „rozporządzeniem nr 2016/425”) i liczby
 * z jednostką („5000 ppm”, „500 ml”, „17 kV”).
 *
 * Wspólne dla kodów z SIWZ w dopasowaniu przetargu (ProductMatchService::codeCandidates) i dla
 * wyszukiwania po kodzie modelu (ProductAiSearchService::modelCodePhrases). Dwie osobne kopie
 * rozjechały się: wyszukiwarka brała „2004” i „1998” z normy za kod i wciągała do puli półmaski
 * klej 3M 7100200484 i materiał odblaskowy 1998467 (AUDYT_4 §15, przetarg 1 poz. 13).
 * Zwraca tekst małymi literami.
 */
final class RequirementCodeNoise
{
    /**
     * Słowo z nazwy aktu między „rozporządzeniem” a numerem („Parlamentu Europejskiego i Rady”, „PE i Rady”,
     * „wykonawczym Komisji”). Inne słowo („np. SECURA 2000/3000”) znaczy, że numer nie należy do aktu.
     */
    private const LEGAL_ACT_NAME_WORD = '(?:parlament\p{L}*|europejsk\p{L}*|pe|rad[ayz]?|komisj\p{L}*|wykonawcz\p{L}*'
        .'|delegowan\p{L}*|nr\.?|\((?:ue|we)\)|ue|we|eu|z|dnia|i)';

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
        $t = self::stripRegulations($t);
        // liczby z jednostką (miara, stężenie, napięcie, czas)
        $t = preg_replace(
            '/\b\d+(?:[.,]\d+)?\s*(?:ppm|ml|mm|cm|m|g|kg|l|szt|par|kv|v|db|min|mies\w*|lat|°c|%)(?![a-z0-9])/u',
            ' ',
            $t
        ) ?? $t;

        return trim(preg_replace('/\s+/u', ' ', $t) ?? $t);
    }

    /**
     * Numery aktów prawnych, norm IEC i zmian norm po ukośniku — wspólne z ProductModelFuzzy::stripNorms, z którego
     * powstają igły modelu i oznaczenia wariantu („2016” z „(UE) 2016/425” wyglądało jak kolor „1010”). Wołane po
     * wycięciu norm EN/ISO. Tekst wejściowy małymi literami, polskie znaki mogą być już zdjęte.
     */
    public static function stripRegulations(string $lowercaseText): string
    {
        $t = $lowercaseText;
        // rozporządzenia i dyrektywy: (UE) 2016/425, WE 1907/2006, 89/686/EWG
        $t = preg_replace('/\(?\b(?:ue|we|eu|ewg)\)?\s*(?:nr\s*)?\d{2,4}\s*\/\s*\d{2,4}\b/u', ' ', $t) ?? $t;
        $t = preg_replace('/\b\d{2,4}\s*\/\s*\d{2,4}\s*\/\s*(?:ewg|we|ue|eu)\b/u', ' ', $t) ?? $t;
        // Akt bez skrótu UE/WE: „rozporządzeniem nr 2016/425”, „rozporządzeniem Parlamentu Europejskiego i Rady
        // 2016/425”, „rozporządzeniem wykonawczym Komisji 2019/1020”, „REACH 1907/2006”. Tylko po „nr” albo po słowie
        // aktu, a między nim a numerem wyłącznie słowa z nazwy aktu — goły zapis rok/numer bywa wyrobem:
        // „rozporządzenia np. SECURA 2000/3000” to dwie serie półmasek.
        $t = preg_replace('/\bnr\.?\s*(?:19|20)\d{2}\s*\/\s*\d{1,4}\b/u', ' ', $t) ?? $t;
        $t = preg_replace(
            '/(\b(?:(?:rozporz|dyrektyw)\p{L}*|reach)(?:\s+'.self::LEGAL_ACT_NAME_WORD.')*)[\s:]+(?:19|20)\d{2}\s*\/\s*\d{1,4}\b/u',
            '$1 ',
            $t
        ) ?? $t;
        // normy IEC: „EN IEC 61340-4-3:2018”, „IEC 61482” — wzorzec EN nie przechodzi przez słowo „iec”
        $t = preg_replace(
            '/\b(?:pn[\s-]*)?(?:en[\s-]*)?iec[\s-]*\d+(?:[\s\-:]+\d+\b)*(?:\s*[+\/]\s*a\d+(?::\s*\d+\b)?)*/u',
            ' ',
            $t
        ) ?? $t;

        // zmiana normy po ukośniku: z „EN ISO 20471:2013/A1:2016” wzorzec normy zostawia „/a1:2016”
        return preg_replace('/\/\s*a(?:c|\d{1,2})\s*:\s*(?:19|20)\d{2}\b/u', ' ', $t) ?? $t;
    }
}
