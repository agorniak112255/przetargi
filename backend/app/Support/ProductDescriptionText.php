<?php

declare(strict_types=1);

namespace App\Support;

/**
 * Opis produktu jako zwykły tekst — bez HTML/CSS, który rozsadza kartę i Prestę.
 */
final class ProductDescriptionText
{
    public static function plain(?string $text): string
    {
        $text = trim((string) $text);
        if ($text === '') {
            return '';
        }
        $text = preg_replace('#<(script|style|noscript)[^>]*>.*?</\1>#is', ' ', $text) ?? $text;
        $text = preg_replace('#<\s*br\s*/?\s*>#i', "\n", $text) ?? $text;
        $text = preg_replace('#</(?:p|div|li|h[1-6]|tr|section|article|table)>#i', "\n", $text) ?? $text;
        $text = html_entity_decode(strip_tags($text), ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $text = preg_replace('/\{[^{}]{0,240}\}/u', ' ', $text) ?? $text;
        $text = self::stripShopUi($text);
        $text = self::cutGenericCatalogAppendix($text);
        $text = self::unglueBrandWords($text);
        $text = preg_replace('/[ \t]+/u', ' ', $text) ?? $text;
        $text = preg_replace('/\n{3,}/u', "\n\n", $text) ?? $text;
        $text = preg_replace('/([^\n])\s+(\d{1,2})\)\s+/u', "$1\n$2) ", $text) ?? $text;
        $text = preg_replace('/(\S{72})(?=\S)/u', '$1 ', $text) ?? $text;

        return trim($text);
    }

    /** Ikony Presty, wysyłka, wycena — nie fakty o modelu. */
    public static function stripShopUi(string $text): string
    {
        $text = preg_replace(
            '/\b(?:zoom_out_map|zoom_in_map|chevron_left|chevron_right|shopping_cart|expand_more|expand_less)\b/u',
            ' ',
            $text
        ) ?? $text;
        $text = preg_replace(
            '/\b(?:czas wysyłki(?: od \d+ do \d+ dni roboczych)?|indywidualna wycena(?: dla firm)?|zapytaj o wycenę|polityka bezpieczeństwa|zasady dostawy|zasady zwrotu|tabela rozmiarów(?: \w+)?|najniższa cena w okresie 30 dni[^.]{0,48})\b\.?/iu',
            ' ',
            $text
        ) ?? $text;
        $text = preg_replace('/\bcheck\s+czas wysyłki[^.]{0,40}\.?/iu', ' ', $text) ?? $text;
        $text = preg_replace('/\b[-−]?\d{1,2}%\b/u', ' ', $text) ?? $text;
        $text = preg_replace(
            '/(?:(?:\s*EU\s*)?(?:3[2-9]|4[0-9]|5[0-2])\s*[-–]\s*\d{1,5}(?:[.,]\d{2})?\s*(?:zł|eur|€))+/iu',
            ' ',
            $text
        ) ?? $text;
        $text = preg_replace('/\bWariant\b/iu', ' ', $text) ?? $text;
        $text = preg_replace('/(?:EU\s*(?:3[2-9]|4[0-9]|5[0-2])\s*){8,}/iu', ' ', $text) ?? $text;
        $text = preg_replace(
            '/(?:Rozmiar\s+EU\s+Długość stopy|#+\s*Jak dobrać rozmiar\?).{0,400}/isu',
            ' ',
            $text
        ) ?? $text;
        $text = preg_replace('/\*\*(?:3[5-9]|4[0-9])\*\*\s*[\d,]+/u', ' ', $text) ?? $text;
        $text = preg_replace('/Jeśli obuwie nie będzie Państwu odpowiadać.{0,280}/isu', ' ', $text) ?? $text;
        $text = preg_replace('/Na każde obuwie .{0,120}gwarancja.{0,160}/isu', ' ', $text) ?? $text;
        $text = preg_replace(
            '/\bNatychmiast do wysyłki\b(?:\s*[•·]\s*Darmowa dostawa)?/iu',
            ' ',
            $text
        ) ?? $text;

        return trim($text);
    }

    /**
     * Ogólna legenda piktogramów / tabela wszystkich klas ARTRA — nie karta tego modelu.
     * Specyfikacja i Cechy zostają.
     */
    public static function cutGenericCatalogAppendix(string $text): string
    {
        // Tylko nagłówek od nowej linii — nie zakładka „Tabela rozmiarów i Piktogramy”.
        if (preg_match(
            '/^(.*?)(?:\n+\s*)(?:Piktogramy|Tabela zamiany rozmiaru|Klasyfikacja obuwia według kategorii)\b/us',
            $text,
            $m
        ) === 1) {
            return trim($m[1]);
        }

        return $text;
    }

    /** „ARTRABiałe półbuty” → „ARTRA Białe półbuty”. */
    public static function unglueBrandWords(string $text): string
    {
        $text = (string) preg_replace('/\b([A-Z]{3,})(?=[A-Z][a-ząćęłńóśźż])/u', '$1 ', $text);

        return (string) preg_replace('/\b([A-Z]{3,})(?=[a-ząćęłńóśźż])/u', '$1 ', $text);
    }

    /**
     * @return list<string>
     */
    public static function paragraphs(string $plain): array
    {
        $plain = trim($plain);
        if ($plain === '') {
            return [];
        }
        $parts = preg_split('/\n\s*\n/u', $plain) ?: [$plain];
        $parts = array_values(array_filter(array_map('trim', $parts), static fn (string $p): bool => $p !== ''));
        if (count($parts) >= 2) {
            return $parts;
        }
        $one = $parts[0] ?? $plain;
        if (mb_strlen($one) < 280 || substr_count($one, '. ') < 3) {
            return [$one];
        }
        $sentences = preg_split('/(?<=[.!?])\s+/u', $one) ?: [$one];
        $out = [];
        $buf = '';
        $n = 0;
        foreach ($sentences as $sentence) {
            $sentence = trim((string) $sentence);
            if ($sentence === '') {
                continue;
            }
            $buf = $buf === '' ? $sentence : $buf.' '.$sentence;
            $n++;
            if ($n >= 3) {
                $out[] = $buf;
                $buf = '';
                $n = 0;
            }
        }
        if ($buf !== '') {
            $out[] = $buf;
        }

        return $out !== [] ? $out : [$one];
    }

    /**
     * @param  list<string>  $items
     * @return list<string>
     */
    public static function dropDuplicatedListItems(array $items, string $prose): array
    {
        $normProse = self::norm($prose);
        if ($normProse === '') {
            return array_values(array_filter($items, static fn (string $item): bool => trim($item) !== ''));
        }
        $out = [];
        foreach ($items as $item) {
            $t = trim($item);
            if ($t === '') {
                continue;
            }
            $norm = self::norm($t);
            if (mb_strlen($norm) >= 40 && str_contains($normProse, $norm)) {
                continue;
            }
            $out[] = $t;
        }

        return $out;
    }

    private static function norm(string $text): string
    {
        $text = mb_strtolower($text);
        $text = preg_replace('/\s+/u', ' ', $text) ?? $text;

        return trim($text);
    }
}
