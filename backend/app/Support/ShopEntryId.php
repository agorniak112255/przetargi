<?php

declare(strict_types=1);

namespace App\Support;

/**
 * Numer wpisu sklepu w adresie to nie kod wyrobu (etap 3 opisów z cenników, §1.3). Sklepy wstawiają do adresu własny
 * numer karty, który bywa równy krótkiemu kodowi wyrobu innej marki albo innego modelu: pros.pl
 * „/102-plaszcz-model-1102.html” (102 = wpis PrestaShop, model 1102), robartbhp „…-51011006-p-1893.html” (1893 =
 * wpis osCommerce, nie REF 1893 Cederroth), bhp-gabi „/p4240,kurtka….html”. Zdejmowane wzorce:
 * - PrestaShop: nazwa strony „{id}[-{kombinacja}]-slug.html” (.htm) — tylko segment kończący się na .html/.htm
 *   (securabc.com „20-41-secura-3000.html” = wpis 20, kombinacja 41); bez rozszerzenia to nie PrestaShop
 *   („/produkt/4255-polmaska-3m/” — 3M 4255, „/1011-r” — AJ 1011 R). Druga liczba to kombinacja tylko przy 1–2
 *   cyfrach: dłuższa bywa kodem wyrobu (produkcja 08.10.2026, catalog_pages: securabc „34-200010-p3-…” — filtr
 *   200010, facom „341-2201-…”, „251-1031-oc-…”), a kombinacja zostawiona w ścieżce i tak nie jest kluczem karty
 *   (krótki numer innej karty liczy się w CardCodeArbiter tylko z etykietą). Katalog zdjęcia „{id}-*_default/”,
 *   parametry id_product, product_id, id i id_product_attribute;
 * - osCommerce: „-p-{id}” na końcu segmentu;
 * - IdoSell: „product-{język}-{id}-” na początku segmentu;
 * - IAI (bhp-gabi.pl): „p{id},” na początku segmentu.
 * Numer na początku nazwy pliku (zdjęcie, PDF: cederroth.com „6943-sensitive-plasters.jpg”) zostaje — to bywa kod
 * wyrobu, nie numer wpisu.
 */
final class ShopEntryId
{
    /** Parametry zapytania z numerem wpisu sklepu (PrestaShop, x13producttopdf, ogólne „?id=”). */
    private const QUERY_PARAMS = ['id_product', 'product_id', 'id', 'id_product_attribute'];

    /**
     * Numer wpisu PrestaShop — do 7 cyfr, jak w SourceIdentity::pathCarries; kombinacja za nim tylko 1–2 cyfry (dłuższa
     * liczba bywa kodem wyrobu — opis klasy).
     */
    private const PRESTA_ENTRY = '/^(\d{1,7})(?:-\d{1,2})?-(?=[^\/]*\p{L})/u';

    /**
     * Adres bez numerów wpisów sklepu: schemat, host i ścieżka z segmentami bez numerów, zapytanie bez parametrów
     * numeru wpisu, bez kotwicy. Ciąg, który nie jest adresem z hostem, traktowany jak sama ścieżka.
     */
    public static function strip(string $url): string
    {
        $url = trim($url);
        if ($url === '') {
            return '';
        }
        $parts = parse_url($url);
        if (! is_array($parts)) {
            return self::stripPath($url);
        }
        $path = self::stripPath((string) ($parts['path'] ?? ''));
        $query = self::stripQuery((string) ($parts['query'] ?? ''));
        $out = '';
        if (isset($parts['host'])) {
            $out = (isset($parts['scheme']) ? $parts['scheme'].'://' : '//').$parts['host']
                .(isset($parts['port']) ? ':'.$parts['port'] : '');
        }

        return $out.$path.($query !== '' ? '?'.$query : '');
    }

    /**
     * Numer wpisu sklepu: z parametru (id_product, product_id, id — w tej kolejności) albo z pierwszego członu nazwy
     * strony PrestaShop („/240-kombinezon-model-1041.html” = 240). Nazwa pliku (zdjęcie, PDF) nie daje numeru.
     */
    public static function entryId(string $url): ?int
    {
        $url = trim($url);
        if ($url === '') {
            return null;
        }
        $parts = parse_url($url);
        if (! is_array($parts)) {
            return null;
        }
        parse_str((string) ($parts['query'] ?? ''), $params);
        foreach (['id_product', 'product_id', 'id'] as $name) {
            $value = $params[$name] ?? null;
            if (is_string($value) && preg_match('/^\d{1,9}$/', trim($value)) === 1) {
                return (int) trim($value);
            }
        }
        $segments = array_values(array_filter(explode('/', (string) ($parts['path'] ?? '')), static fn (string $s): bool => $s !== ''));
        $last = $segments === [] ? '' : rawurldecode($segments[count($segments) - 1]);
        if ($last !== '' && self::isPageSegment($last) && preg_match(self::PRESTA_ENTRY, $last, $m) === 1) {
            return (int) $m[1];
        }

        return null;
    }

    private static function stripPath(string $path): string
    {
        if ($path === '') {
            return '';
        }
        $segments = explode('/', $path);
        $out = [];
        foreach ($segments as $segment) {
            $decoded = rawurldecode($segment);
            // katalog zdjęcia PrestaShop: „3879-thickbox_default”, „7328-large_default”
            if (preg_match('/^\d{1,9}(?:-[\w\-]*)?_default$/u', $decoded) === 1) {
                continue;
            }
            $out[] = self::stripSegment($segment);
        }

        return implode('/', $out);
    }

    private static function stripSegment(string $segment): string
    {
        if ($segment === '') {
            return '';
        }
        // IAI (bhp-gabi.pl): „p4240,kurtka….html”
        $segment = (string) preg_replace('/^p\d{1,9},/u', '', $segment);
        // IdoSell: „product-pol-12345-nazwa.html”
        $segment = (string) preg_replace('/^product-[a-z]{2,4}-\d{1,9}-/iu', '', $segment);
        // osCommerce: „…-p-1893.html”
        $segment = (string) preg_replace('/-p-\d{1,9}(?=\.html?$|$)/iu', '', $segment);
        // PrestaShop: „102-plaszcz-model-1102.html”, „20-41-secura-3000.html” — tylko strona, nie plik
        if (self::isPageSegment($segment)) {
            $segment = (string) preg_replace(self::PRESTA_ENTRY, '', $segment);
        }

        return $segment;
    }

    /**
     * Nazwa strony PrestaShop: segment kończący się na .html albo .htm. Segment bez rozszerzenia („/4255-polmaska-3m/”,
     * „/1011-r”) to inny sklep albo katalog — liczba na początku bywa tam kodem wyrobu.
     */
    private static function isPageSegment(string $segment): bool
    {
        return preg_match('/\.html?$/i', $segment) === 1;
    }

    private static function stripQuery(string $query): string
    {
        if ($query === '') {
            return '';
        }
        $kept = [];
        foreach (explode('&', $query) as $pair) {
            if ($pair === '') {
                continue;
            }
            $name = mb_strtolower(rawurldecode(explode('=', $pair, 2)[0]));
            if (! in_array($name, self::QUERY_PARAMS, true)) {
                $kept[] = $pair;
            }
        }

        return implode('&', $kept);
    }
}
