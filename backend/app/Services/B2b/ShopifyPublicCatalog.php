<?php

declare(strict_types=1);

namespace App\Services\B2b;

use DOMElement;
use DOMNode;
use DOMXPath;

/**
 * Publiczny sklep Shopify czytany bez logowania i bez klucza API. Każdy taki sklep wystawia to samo:
 * mapę strony z adresami kart, `<adres karty>.json` z tytułem, opcjami, wariantami i galerią oraz HTML karty
 * z tym, czego w JSON-ie nie ma (u Artry `body_html` bywa puste, więc opis i tabelka są tylko w HTML).
 *
 * Klasa jest bezstanowa i nie wysyła zapytań — dostaje host i gotowe ciała odpowiedzi, a pobieraniem zajmuje
 * się klient łącznika. Nie zna żadnego konkretnego sklepu: nazwy klas CSS, etykiety i reguły rozpoznawania
 * plików podaje łącznik, bo to one zmieniają się razem z szablonem sklepu. Dzięki temu kolejny sklep Shopify
 * potrzebuje tylko własnej, krótkiej klasy łącznika.
 */
final class ShopifyPublicCatalog
{
    public function __construct(private readonly string $host) {}

    public function base(): string
    {
        return 'https://'.$this->host;
    }

    /** Mapa nadrzędna — wskazuje mapy produktów, stron i kolekcji. */
    public function sitemapUrl(): string
    {
        return $this->base().'/sitemap.xml';
    }

    public function productUrl(string $handle): string
    {
        return $this->base().'/products/'.$handle;
    }

    /** Dane produktu w postaci gotowej do odczytu — ten sam adres z końcówką .json. */
    public function productJsonUrl(string $handle): string
    {
        return $this->productUrl($handle).'.json';
    }

    /**
     * Adresy map produktów z mapy nadrzędnej. Shopify dzieli katalog na kilka map i doszywa do adresu zakres
     * identyfikatorów (?from=…&to=…), więc adresu nie da się złożyć samemu — bierzemy go dosłownie z mapy.
     *
     * @return list<string>
     */
    public function productSitemapUrls(string $indexXml): array
    {
        $urls = [];
        foreach (self::locations($indexXml) as $url) {
            if (str_contains((string) parse_url($url, PHP_URL_PATH), '/sitemap_products')) {
                $urls[] = $url;
            }
        }

        return $urls;
    }

    /**
     * Klucze kart (handle) z mapy produktów, w kolejności z mapy i bez powtórzeń. Mapa zawiera też adres
     * strony głównej — liczy się tylko to, co leży pod /products/.
     *
     * @return list<string>
     */
    public function handles(string $xml): array
    {
        $handles = [];
        foreach (self::locations($xml) as $url) {
            $path = (string) parse_url($url, PHP_URL_PATH);
            if (preg_match('#^/products/(.+)$#', $path, $m) !== 1) {
                continue;
            }
            $handle = rawurldecode($m[1]);
            $handles[$handle] = true;
        }

        return array_keys($handles);
    }

    /**
     * Produkt z `<handle>.json`; null gdy odpowiedź nie jest produktem (np. strona błędu podana jako HTML).
     *
     * @return array<string, mixed>|null
     */
    public static function product(string $json): ?array
    {
        $decoded = json_decode($json, true);
        $product = is_array($decoded) ? ($decoded['product'] ?? null) : null;

        return is_array($product) ? $product : null;
    }

    /**
     * Nazwy wariantów w kolejności ze sklepu, bez powtórzeń (u sklepu obuwniczego to rozmiary: „EU 35”…).
     * Bierzemy je z wariantów, a nie z listy opcji — wariant to pozycja, którą sklep naprawdę oferuje.
     *
     * @param  array<string, mixed>  $product
     * @return list<string>
     */
    public static function variantTitles(array $product): array
    {
        $titles = [];
        foreach ((array) ($product['variants'] ?? []) as $variant) {
            if (! is_array($variant)) {
                continue;
            }
            $title = trim((string) ($variant['title'] ?? ''));
            if ($title !== '') {
                $titles[$title] = true;
            }
        }

        return array_keys($titles);
    }

    /**
     * Adresy zdjęć z galerii, w kolejności ze sklepu. To wszystkie obrazy karty — także grafiki technologii,
     * które zdjęciem wyrobu nie są; wybór należy do łącznika, bo reguła zależy od sklepu.
     *
     * @param  array<string, mixed>  $product
     * @return list<string>
     */
    public function imageUrls(array $product): array
    {
        $urls = [];
        foreach ((array) ($product['images'] ?? []) as $image) {
            if (! is_array($image)) {
                continue;
            }
            $src = trim((string) ($image['src'] ?? ''));
            if ($src !== '') {
                $urls[$this->fileUrl($src)] = true;
            }
        }

        return array_keys($urls);
    }

    /**
     * Nazwa pliku bez rozszerzenia z adresu obrazu albo dokumentu — po niej sklepy poznają, czego plik dotyczy.
     */
    public static function fileStem(string $url): string
    {
        $path = (string) parse_url($url, PHP_URL_PATH);

        return pathinfo(rawurldecode($path), PATHINFO_FILENAME);
    }

    /**
     * Wiersze bloku parametrów jako etykieta i wartość rozbita na linie. Linię tworzy wyłącznie <br> ze źródła:
     * u Artry pole „norma” ma tak rozdzielone dwa osobne oznaczenia, a pole „Waga” to jedna wartość zapisana
     * w dwóch wierszach znaczników („580” i „gramów dla rozmiaru 42”) — rozbicie jej byłoby przekłamaniem.
     *
     * Szablony Shopify potrafią wypisać ten sam blok dwa razy (karta i szybki podgląd), więc identyczne pary
     * odpadają — inaczej tabelka u dostawcy miałaby każdy parametr podwójnie.
     *
     * @return list<array{name: string, lines: list<string>}>
     */
    public static function labelledRows(string $html, string $rowClass, string $keyClass, string $valueClass): array
    {
        $xpath = self::dom($html);
        $rows = [];
        $seen = [];
        foreach ($xpath->query('//*['.self::classPredicate($rowClass).']') as $row) {
            $name = self::text($xpath->query('.//*['.self::classPredicate($keyClass).']', $row)->item(0));
            $value = $xpath->query('.//*['.self::classPredicate($valueClass).']', $row)->item(0);
            if ($name === '' || $value === null) {
                continue;
            }
            $lines = self::lines($value);
            if ($lines === []) {
                continue;
            }
            $key = $name."\0".implode("\0", $lines);
            if (isset($seen[$key])) {
                continue;
            }
            $seen[$key] = true;
            $rows[] = ['name' => $name, 'lines' => $lines];
        }

        return $rows;
    }

    /**
     * Tabela o podanej klasie: nagłówki kolumn i wiersze danych, dosłownie ze źródła. Pusta tablica nagłówków
     * i wierszy = strona takiej tabeli nie ma.
     *
     * @return array{headers: list<string>, rows: list<list<string>>}
     */
    public static function table(string $html, string $tableClass): array
    {
        $xpath = self::dom($html);
        $table = $xpath->query('//table['.self::classPredicate($tableClass).']')->item(0);
        if ($table === null) {
            return ['headers' => [], 'rows' => []];
        }

        $headers = [];
        foreach ($xpath->query('.//th', $table) as $cell) {
            $headers[] = self::text($cell);
        }

        $rows = [];
        foreach ($xpath->query('.//tr', $table) as $row) {
            $cells = [];
            foreach ($xpath->query('./td', $row) as $cell) {
                $cells[] = self::text($cell);
            }
            if ($cells !== []) {
                $rows[] = $cells;
            }
        }

        return ['headers' => $headers, 'rows' => $rows];
    }

    /** Tekst pierwszego bloku o podanej klasie, ze zwiniętymi odstępami; '' gdy strona takiego bloku nie ma. */
    public static function blockText(string $html, string $class): string
    {
        return self::text(self::dom($html)->query('//*['.self::classPredicate($class).']')->item(0));
    }

    /**
     * Odnośniki do plików o podanym rozszerzeniu, z nazwą widoczną na stronie. Adres jest sprowadzany do postaci
     * bezwzględnej i bez zapytania (patrz fileUrl), po którym idzie też odsiew powtórzeń — ten sam plik bywa
     * w znacznikach kilka razy.
     *
     * @return list<array{title: string, url: string}>
     */
    public function fileLinks(string $html, string $extension): array
    {
        $xpath = self::dom($html);
        $links = [];
        foreach ($xpath->query('//a[@href]') as $link) {
            if (! $link instanceof DOMElement) {
                continue;
            }
            $href = trim($link->getAttribute('href'));
            $path = mb_strtolower((string) parse_url($href, PHP_URL_PATH));
            if ($href === '' || ! str_ends_with($path, '.'.mb_strtolower($extension))) {
                continue;
            }
            $url = $this->fileUrl($href);
            if (isset($links[$url])) {
                continue;
            }
            $links[$url] = ['title' => self::text($link), 'url' => $url];
        }

        return array_values($links);
    }

    /**
     * Adres pliku w postaci do zapisania jako źródło: bezwzględny (adresy bywają bezprotokołowe — „//host/…”)
     * i bez zapytania. Zapytanie przy plikach Shopify to znacznik wersji (?v=…), a nie tożsamość pliku: gdyby
     * został, każde ponowne wgranie tego samego dokumentu albo zdjęcia doklejałoby kartę drugi egzemplarz,
     * bo zapisane pliki rozpoznajemy po adresie. Sam adres bez ?v= wydaje bieżącą wersję pliku.
     */
    public function fileUrl(string $url): string
    {
        $url = trim($url);
        if (str_starts_with($url, '//')) {
            $url = 'https:'.$url;
        } elseif (str_starts_with($url, '/')) {
            $url = $this->base().$url;
        }

        return explode('#', explode('?', $url)[0])[0];
    }

    /**
     * @return list<string>
     */
    private static function locations(string $xml): array
    {
        preg_match_all('#<loc>\s*([^<]+?)\s*</loc>#i', $xml, $m);

        return array_map(
            static fn (string $loc): string => html_entity_decode($loc, ENT_QUOTES | ENT_XML1, 'UTF-8'),
            $m[1],
        );
    }

    /**
     * Zawartość węzła rozbita na linie po <br>; odstępy w obrębie linii zwinięte, puste linie pominięte.
     *
     * @return list<string>
     */
    private static function lines(DOMNode $node): array
    {
        $lines = [''];
        foreach ($node->childNodes as $child) {
            if ($child instanceof DOMElement && mb_strtolower($child->nodeName) === 'br') {
                $lines[] = '';

                continue;
            }
            $lines[count($lines) - 1] .= $child->textContent;
        }

        $out = [];
        foreach ($lines as $line) {
            $line = trim((string) preg_replace('/\s+/u', ' ', $line));
            if ($line !== '') {
                $out[] = $line;
            }
        }

        return $out;
    }

    private static function text(?DOMNode $node): string
    {
        if ($node === null) {
            return '';
        }

        return trim((string) preg_replace('/\s+/u', ' ', $node->textContent));
    }

    /** Dopasowanie po jednej klasie CSS, niezależnie od pozostałych klas elementu. */
    private static function classPredicate(string $class): string
    {
        return 'contains(concat(" ", normalize-space(@class), " "), " '.$class.' ")';
    }

    private static function dom(string $html): DOMXPath
    {
        $document = new \DOMDocument;
        $previous = libxml_use_internal_errors(true);
        $document->loadHTML('<?xml encoding="UTF-8">'.$html);
        libxml_clear_errors();
        libxml_use_internal_errors($previous);

        return new DOMXPath($document);
    }
}
