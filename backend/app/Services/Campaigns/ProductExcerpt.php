<?php

declare(strict_types=1);

namespace App\Services\Campaigns;

/**
 * Krótki opis produktu do maila kampanii — WYCINEK opisu karty, nigdy tekst dopisany: pierwsze pełne zdania opisu
 * (do MAX znaków), a gdy opis to same wypunktowania — pierwsze cechy rozdzielone „ · ”. Pomija nagłówki źródła
 * („Z karty technicznej (…pdf):”), linie wersalikami z PDF-ów i powtórzoną nazwę produktu. Brak sensownego tekstu =
 * null (handlowiec może wpisać opis przy pozycji).
 */
final class ProductExcerpt
{
    public const MAX = 240;

    /** Tyle pierwszych niepustych linii opisu przeglądamy — dalej są zwykle tabele i szczegóły techniczne. */
    private const SCAN_LINES = 15;

    private const MAX_FEATURES = 3;

    /** Linia cechy krótsza niż to to zwykle resztka łamania PDF-a („Właściwości”, „produktu”). */
    private const MIN_FEATURE = 15;

    private const MAX_FEATURE = 110;

    public static function fromDescription(?string $description, string $name = ''): ?string
    {
        $text = trim(strip_tags(html_entity_decode((string) $description, ENT_QUOTES | ENT_HTML5, 'UTF-8')));
        if ($text === '') {
            return null;
        }
        $nameKey = self::key($name);
        $features = [];
        $seen = 0;
        foreach (preg_split('/\R/u', $text) ?: [] as $raw) {
            $line = trim((string) preg_replace('/\s+/u', ' ', $raw));
            if ($line === '') {
                continue;
            }
            if (++$seen > self::SCAN_LINES) {
                break;
            }
            $bullet = preg_match('/^(?:[•\-–—*·▪►]|\d{1,2}[.)])\s*/u', $line) === 1;
            $clean = trim((string) preg_replace('/^(?:[•\-–—*·▪►]|\d{1,2}[.)])\s*/u', '', $line));
            if ($clean === '' || self::isHeading($clean) || ($nameKey !== '' && self::key($clean) === $nameKey)) {
                continue;
            }
            // pierwszy akapit prozy wygrywa: pełne zdania, do MAX znaków
            if (! $bullet && mb_strlen($clean) >= 60 && preg_match('/[.!?](\s|$)/u', $clean) === 1) {
                // linia wprowadzająca bez kropki przed akapitem („Rękawice mechaniczne idealne do…”) — jako pierwsze zdanie
                $lead = $features !== [] ? $features[0].'.' : '';
                $withLead = $lead !== '' && mb_strlen($lead) < self::MAX / 2 ? $lead.' '.$clean : $clean;

                return self::sentences($withLead);
            }
            $len = mb_strlen($clean);
            if ($len >= self::MIN_FEATURE && $len <= self::MAX_FEATURE && count($features) < self::MAX_FEATURES) {
                $features[] = rtrim($clean, ' ,;.');
            }
        }
        if ($features === []) {
            return null;
        }
        $out = '';
        foreach ($features as $feature) {
            $next = $out === '' ? $feature : $out.' · '.$feature;
            if (mb_strlen($next) > self::MAX) {
                break;
            }
            $out = $next;
        }

        return $out === '' ? self::cut($features[0]) : self::upperFirst($out);
    }

    /**
     * Normy z pola karty („EN ISO 20345:2011 S3, SRC; EN 388”) jako krótka lista do maila — najwyżej 4 pozycje.
     *
     * @return list<string>
     */
    public static function norms(?string $norms): array
    {
        $out = [];
        foreach (preg_split('/[;,\n]+/u', (string) $norms) ?: [] as $part) {
            $part = trim((string) preg_replace('/\s+/u', ' ', $part));
            if ($part !== '' && mb_strlen($part) <= 40 && ! in_array($part, $out, true)) {
                $out[] = $part;
            }
            if (count($out) === 4) {
                break;
            }
        }

        return $out;
    }

    /** Całe zdania do MAX znaków; pierwsze zdanie dłuższe niż MAX ucięte na słowie z „…”. */
    private static function sentences(string $paragraph): string
    {
        $out = '';
        foreach (preg_split('/(?<=[.!?])\s+(?=[\p{Lu}\d„"])/u', $paragraph) ?: [] as $sentence) {
            $next = $out === '' ? $sentence : $out.' '.$sentence;
            if (mb_strlen($next) > self::MAX) {
                break;
            }
            $out = $next;
        }

        return $out === '' ? self::cut($paragraph) : self::upperFirst($out);
    }

    private static function cut(string $text): string
    {
        if (mb_strlen($text) <= self::MAX) {
            return self::upperFirst($text);
        }
        $cut = mb_substr($text, 0, self::MAX - 1);
        $space = mb_strrpos($cut, ' ');
        if ($space !== false && $space > self::MAX / 2) {
            $cut = mb_substr($cut, 0, $space);
        }

        return self::upperFirst(rtrim($cut, ' ,;:.-–').'…');
    }

    /** Nagłówek sekcji albo źródła („Cechy szczególne:”, „Z karty technicznej (…):”) lub linia wersalikami z PDF-a. */
    private static function isHeading(string $line): bool
    {
        if (str_ends_with($line, ':') && mb_strlen($line) <= 120) {
            return true;
        }

        return mb_strlen($line) <= 60 && preg_match('/\p{Lu}/u', $line) === 1 && mb_strtoupper($line) === $line;
    }

    private static function key(string $text): string
    {
        return mb_strtolower(trim((string) preg_replace('/[^\p{L}\p{N}]+/u', ' ', $text)));
    }

    private static function upperFirst(string $text): string
    {
        return mb_strtoupper(mb_substr($text, 0, 1)).mb_substr($text, 1);
    }
}
