<?php

declare(strict_types=1);

namespace App\Support;

/**
 * Adresy stron w treści zapytania („jak w linku https://protekt.pl/…~p8511~c5356”).
 *
 * Klient wskazuje wyrób linkiem do strony producenta albo sklepu. Taki adres jest
 * identyfikatorem: ten sam adres łącznik B2B zapisuje przy karcie, więc porównujemy
 * adresy, a nie słowa. Słowa z adresu bywają wspólne dla całej rodziny wyrobów —
 * „urzadzenie-samohamowne-do-pracy-w-pionie” we frazie wyszukiwania dawało 99% każdemu
 * ROLEX-owi, a klient wskazał ROLEX 5 (zapytanie #69, 24.09.2026).
 */
final class InquiryLinks
{
    /** Adres http(s) do białego znaku, cudzysłowu albo nawiasu (Outlook pisze „Nazwa<https://…>”). */
    private const URL = '~https?://[^\s<>"\'\[\]{}|\\\\^`]+~iu';

    /** Parametry śledzące — nie zmieniają strony, na którą prowadzi adres. */
    private const TRACKING = '/^(?:utm_[a-z_]+|gclid|fbclid|msclkid|mc_cid|mc_eid|srsltid|_ga)$/i';

    /**
     * Adresy stron w kolejności z tekstu, bez powtórzeń. Znak interpunkcji za adresem
     * („…~c5356.”) kończy zdanie, nie adres.
     *
     * @return list<string>
     */
    public static function extract(string $text): array
    {
        preg_match_all(self::URL, $text, $m);
        $out = [];
        foreach ($m[0] as $raw) {
            $url = rtrim($raw, '.,;:!?)');
            if (self::key($url) !== null && ! in_array($url, $out, true)) {
                $out[] = $url;
            }
        }

        return $out;
    }

    /** Tekst bez adresów, razem z nawiasem ostrym, w którym Outlook podaje adres za nazwą. */
    public static function withoutUrls(string $text): string
    {
        $text = preg_replace('~<\s*https?://[^>\s]*\s*>~iu', ' ', $text) ?? $text;
        $text = preg_replace(self::URL, ' ', $text) ?? $text;

        return trim(preg_replace('/[ \t]{2,}/u', ' ', $text) ?? $text);
    }

    /**
     * Klucz porównania adresów: host bez „www.”, ścieżka bez końcowego ukośnika i parametry
     * zapytania bez śledzących (posortowane) — bez schematu i kotwicy (#…), bez wielkości liter.
     * null = to nie jest adres strony.
     */
    public static function key(string $url): ?string
    {
        $parts = parse_url(trim($url));
        if (! is_array($parts) || ! isset($parts['host'])) {
            return null;
        }
        $host = (string) preg_replace('/^www\./', '', mb_strtolower($parts['host']));
        if (! str_contains($host, '.')) {
            return null;
        }
        $path = rtrim(rawurldecode((string) ($parts['path'] ?? '')), '/');

        $pairs = [];
        foreach (explode('&', (string) ($parts['query'] ?? '')) as $pair) {
            $name = explode('=', $pair, 2)[0];
            if ($pair !== '' && preg_match(self::TRACKING, $name) !== 1) {
                $pairs[] = rawurldecode($pair);
            }
        }
        sort($pairs);

        return mb_strtolower($host.$path.($pairs === [] ? '' : '?'.implode('&', $pairs)));
    }

    /**
     * Host i ścieżka z klucza — bez parametrów zapytania.
     */
    public static function hostPath(string $key): string
    {
        return explode('?', $key, 2)[0];
    }

    /**
     * Parametry zapytania z klucza („id_product=12”).
     *
     * @return list<string>
     */
    public static function queryPairs(string $key): array
    {
        $query = explode('?', $key, 2)[1] ?? '';

        return $query === '' ? [] : explode('&', $query);
    }

    /**
     * Klucz wyrobu w adresie sklepu z numerem wyrobu przed nazwą (PrestaShop):
     * „/bluzy/138-2702-geffer-620-61920.html” — wyrób 138, kombinacja 2702 (kolor, rozmiar),
     * nazwa. Kombinacja i kategoria w ścieżce nie zmieniają wyrobu: łącznik zapisał przy
     * karcie „…/138-2740-geffer-620-61920.html”, a klient przysłał
     * „…/138-2702-geffer-620-61920.html#/3-rozmiar-l/34-kolor-26” (zapytanie #62).
     * null = adres nie ma tej budowy.
     *
     * @return array{host: string, id: string, name: string}|null
     */
    public static function shopProductKey(string $url): ?array
    {
        $key = self::key($url);
        if ($key === null) {
            return null;
        }
        $hostPath = self::hostPath($key);
        $slash = strpos($hostPath, '/');
        if ($slash === false) {
            return null;
        }
        $last = basename(substr($hostPath, $slash));
        if (preg_match('/^(\d+)(?:-\d+)?-([\p{L}\d][\p{L}\d-]*[\p{L}\d])\.html?$/u', $last, $m) !== 1
            || preg_match('/\p{L}/u', $m[2]) !== 1) {
            return null;
        }

        return ['host' => substr($hostPath, 0, $slash), 'id' => $m[1], 'name' => $m[2]];
    }

    /**
     * Wariant wybrany w kotwicy adresu PrestaShop: „#/3-rozmiar-l/34-kolor-26” → rozmiar l,
     * kolor 26. Pusta lista, gdy kotwica nie ma tej budowy.
     *
     * @return list<array{group: string, value: string}>
     */
    public static function fragmentOptions(string $url): array
    {
        $fragment = rawurldecode((string) parse_url(trim($url), PHP_URL_FRAGMENT));
        $out = [];
        foreach (explode('/', $fragment) as $part) {
            if (preg_match('/^\d+-(\p{L}[\p{L}_]*)-([\p{L}\d][\p{L}\d-]*)$/u', $part, $m) === 1) {
                $out[] = [
                    'group' => str_replace('_', ' ', mb_strtolower($m[1])),
                    'value' => mb_strtolower($m[2]),
                ];
            }
        }

        return $out;
    }

    /**
     * Wariant z kotwicy, który podaje nazwa karty („GEFFER 620 61920, kolor 26” przy
     * „34-kolor-26”) — sama grupa z wartością obok siebie; „kolor 26/70” to inny kolor.
     * null = nazwa żadnego nie podaje.
     *
     * @param  list<array{group: string, value: string}>  $options
     */
    public static function variantNamedIn(string $name, array $options): ?string
    {
        foreach ($options as $option) {
            $quote = static fn (string $part): string => preg_quote($part, '/');
            $group = implode('\s*', array_map($quote, preg_split('/\s+/u', $option['group']) ?: []));
            $value = implode('[\s\/-]', array_map($quote, explode('-', $option['value'])));
            if (preg_match('/(?<![\p{L}\d])'.$group.'\W*'.$value.'(?![\p{L}\d\/])/iu', $name) === 1) {
                return $option['group'].' '.str_replace('-', '/', $option['value']);
            }
        }

        return null;
    }

    /**
     * Słowa z adresu, gdy wiersz klienta sam wyrobu nie nazywa: ostatni człon ścieżki bez
     * rozszerzenia, bez numerów przed nazwą („138-2702-geffer-620-61920.html” → „geffer
     * 620-61920”), bez dopisków po tyldzie („…-w-pionie~p8511~c5356”) i bez długich ciągów
     * cyfr (numer oferty Allegro). Łącznik między cyframi zostaje — to kod („23-202”).
     * Pusty tekst, gdy nie zostało żadne słowo.
     */
    public static function slugWords(string $url): string
    {
        $path = rtrim((string) parse_url(trim($url), PHP_URL_PATH), '/');
        $last = rawurldecode(basename($path));
        $last = explode('~', $last)[0];
        $last = preg_replace('/\.(?:html?|php|aspx?|jsp)$/iu', '', $last) ?? $last;
        $last = preg_replace('/^(?:\d+-){1,2}(?=\p{L})/u', '', $last) ?? $last;
        $last = preg_replace('/(?<!\d)-|-(?!\d)|[_+,]/u', ' ', $last) ?? $last;

        $words = array_filter(
            preg_split('/\s+/u', $last) ?: [],
            static fn (string $word): bool => $word !== '' && preg_match('/^\d{7,}$/u', $word) !== 1,
        );
        $text = trim(implode(' ', $words));

        return InquiryQueryText::hasProductWord($text) ? mb_substr($text, 0, 80) : '';
    }
}
