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
        $text = self::stripSpecTableRows($text);
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

    /**
     * Zrzut tabeli parametrów wklejony jako opis. Karta 3M oddaje kilkanaście wierszy
     * „Adhesion Strength (Imperial)22 oz/in”, a proza zaczyna się dopiero pod nimi —
     * dla operatora „gotowe” ze zrzutem jest gorsze niż uczciwe „nie udało się”.
     * Wycinamy wiersze tabeli i zostawiamy resztę do oceny; jeśli zostanie za mało,
     * opis odpadnie już jako zbyt ubogi.
     */
    public static function stripSpecTableRows(string $text): string
    {
        $lines = preg_split('/\R/u', $text) ?: [];
        $out = [];
        foreach ($lines as $line) {
            $drop = self::isComparisonDumpRow($line)
                || self::isGluedSpecRow($line)
                || self::isSpacedSpecRow($line);
            $out[] = $drop ? '' : $line;
        }
        $text = implode("\n", $out);
        $text = preg_replace('/\n{3,}/u', "\n\n", $text) ?? $text;

        return trim($text);
    }

    /** Tabela porównawcza kilku produktów spłaszczona do jednej linii — wartości cudzych modeli. */
    private static function isComparisonDumpRow(string $line): bool
    {
        return preg_match('/^\s*(?:-{2,}\s+){2,}/u', $line) === 1;
    }

    /** „Overall Length (Metric)54.9 m” — etykieta sklejona z wartością przy spłaszczaniu <td>. */
    private static function isGluedSpecRow(string $line): bool
    {
        if (self::mentionsStandard($line)) {
            return false;
        }
        if (preg_match('/^\s*(\p{Lu}[^\d\n]{2,80}?[\p{L}\)\]™®])(\d.*)$/u', $line, $m) !== 1) {
            return false;
        }
        $shape = self::measurementShape($m[2]);

        return $shape['ok'] && ($shape['unit'] || self::labelCarriesUnit($m[1]));
    }

    /** „Elongation at Break 4 %” — ten sam wiersz, tyle że ze spacją. */
    private static function isSpacedSpecRow(string $line): bool
    {
        if (mb_strlen(trim($line)) > 80 || self::mentionsStandard($line)) {
            return false;
        }
        if (preg_match('/^\s*(\p{Lu}[\p{L}\s\-\/&()]{2,60}?)\s(\d.*)$/u', $line, $m) !== 1) {
            return false;
        }
        // etykieta tabeli nazywa parametr; zdanie („Care washable in industrial machine up to”) nie
        if (count(preg_split('/\s+/u', trim($m[1])) ?: []) > 4) {
            return false;
        }
        $shape = self::measurementShape($m[2]);

        return $shape['ok'] && ($shape['unit'] || self::labelCarriesUnit($m[1]));
    }

    /**
     * Czy wartość wygląda na pomiar i czy niesie jednostkę.
     *
     * @return array{ok: bool, unit: bool}
     */
    private static function measurementShape(string $value): array
    {
        $value = trim($value);
        if ($value === '' || mb_strlen($value) > 90) {
            return ['ok' => false, 'unit' => false];
        }
        $numbers = 0;
        $unit = false;
        foreach (preg_split('/[\s,]+/u', $value) ?: [] as $token) {
            if ($token === '') {
                continue;
            }
            if (preg_match('/^\d[\d.,]*$/u', $token) === 1) {
                $numbers++;

                continue;
            }
            if (! self::isUnitToken($token)) {
                return ['ok' => false, 'unit' => false];
            }
            $unit = true;
        }

        return ['ok' => $numbers >= 1, 'unit' => $unit];
    }

    /** „mm”, „°C”, „ppm”, „oz/in”, „N/100mm” — ale nie „metra”, „SPORT” ani kod modelu „S3”. */
    private static function isUnitToken(string $token): bool
    {
        if (preg_match('/^[\p{L}\d%°µ"²³.\/]{1,14}$/u', $token) !== 1) {
            return false;
        }
        // dłuższa bywa tylko jednostka złożona („N/100mm”); „oddychająca” to już słowo zdania
        if (mb_strlen($token) > 6 && preg_match('/[\d\/]/u', $token) !== 1) {
            return false;
        }

        return preg_match('/\p{Ll}/u', $token) === 1
            || preg_match('/[%°µ"²³]/u', $token) === 1
            || preg_match('/\/.*\d/u', $token) === 1;
    }

    /** Norma to treść karty, nie wiersz tabeli — „EN ISO 13688” musi zostać. */
    private static function mentionsStandard(string $line): bool
    {
        return preg_match('/\b(?:EN|ISO|PN|DIN|ASTM|ANSI|AS\/NZS)\b/u', $line) === 1;
    }

    /** „Obwód dłoni (mm)” — jednostka siedzi w etykiecie, więc same liczby wystarczą. */
    private static function labelCarriesUnit(string $label): bool
    {
        return preg_match('/\([^()]{1,14}\)\s*$/u', $label) === 1;
    }

    private static function norm(string $text): string
    {
        $text = mb_strtolower($text);
        $text = preg_replace('/\s+/u', ' ', $text) ?? $text;

        return trim($text);
    }
}
