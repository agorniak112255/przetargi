<?php

declare(strict_types=1);

namespace App\Services\B2b;

use DOMDocument;
use DOMElement;
use DOMNode;
use DOMXPath;

/**
 * Odczyt stron portalu Canis (K2 „eshop5”, wersja /pl) — znaczniki ze stron zalogowanego konta 04.10.2026. Wszystko
 * dosłownie ze strony; czego strona nie podaje, zostaje puste (żadnych wartości domyślnych).
 *
 * Strona wyrobu bywa stroną modelu (data-is-master=1, kod „MMMM-MMM-000-00”), koloru (kod „…-KKK-00”), rozmiaru
 * (data-master = numer strony koloru) albo wyrobu bez wariantów (3M). Tabela wariantów (wiersze data-k2="variantItem")
 * to pozycje do zamówienia z ceną konta; strona bez tabeli to sama pozycja z ceną w nagłówku (schema.org Offer).
 */
final class CanisPageParser
{
    /** Kod pozycji Canis: model (4+3 cyfry), kolor, rozmiar. */
    public const CODE = '/^\d{4}-\d{3}-[0-9A-Z]{3}-[0-9A-Z]{2}$/';

    /**
     * Kategorie-liście z menu strony głównej: adres „/pl/…_c{id}/…” i ścieżka etykiet menu („Rękawice robocze >
     * Skórzane”). Kategoria nadrzędna nie jest liściem — jej lista to suma liści (pobieranie jej podwoiłoby pracę).
     *
     * @return list<array{path: string, label: string}>
     */
    public static function categories(string $homeHtml): array
    {
        $titles = [];
        preg_match_all('#<a\b[^>]*\bhref="(/pl/[^"?\#]*_c\d+)"[^>]*>#u', $homeHtml, $anchors, PREG_SET_ORDER);
        foreach ($anchors as [$tag, $path]) {
            if (! isset($titles[$path]) || $titles[$path] === '') {
                $titles[$path] = preg_match('/\btitle="([^"]*)"/u', $tag, $t) === 1 ? self::clean(self::decode($t[1])) : '';
            }
        }
        $paths = array_keys($titles);
        sort($paths, SORT_STRING);

        $out = [];
        foreach ($paths as $path) {
            foreach ($paths as $other) {
                if (str_starts_with($other, $path.'/')) {
                    continue 2;
                }
            }
            $labels = [];
            $prefix = '';
            foreach (array_values(array_filter(explode('/', substr($path, 4)), static fn (string $s): bool => $s !== '')) as $segment) {
                $prefix .= '/'.$segment;
                $label = $titles['/pl'.$prefix] ?? '';
                $labels[] = $label !== '' ? $label : $segment;
            }
            $out[] = ['path' => $path, 'label' => implode(' > ', $labels)];
        }

        return $out;
    }

    /**
     * Kafle strony listy kategorii i numer ostatniej strony (odnośnik „pagLast”; bez niego = 1).
     *
     * @return array{tiles: list<array{id: int, url: string, name: string}>, last_page: int}
     */
    public static function categoryPage(string $main): array
    {
        $xpath = self::xpath($main);
        $tiles = [];
        foreach ($xpath->query('//div[@data-product-id]') ?: [] as $node) {
            if (! $node instanceof DOMElement) {
                continue;
            }
            $id = (int) $node->getAttribute('data-product-id');
            // kafle listy wyrobów; „Powiązane”/„Wybierz wariant” to inne sekcje (swiper-slide)
            $item = $node->parentNode;
            if ($id <= 0 || ! $item instanceof DOMElement || $item->getAttribute('data-k2') !== 'item'
                || str_contains(' '.$item->getAttribute('class').' ', ' swiper-slide ')) {
                continue;
            }
            $link = $xpath->query('.//a[contains(@class,"product_item_title")]', $node)?->item(0);
            $href = $link instanceof DOMElement ? trim($link->getAttribute('href')) : '';
            if ($href === '' || isset($tiles[$id])) {
                continue;
            }
            $tiles[$id] = ['id' => $id, 'url' => $href, 'name' => self::clean($link?->textContent ?? '')];
        }
        $last = $xpath->query('//a[@data-k2="pagLast"]')?->item(0);
        $lastPage = 1;
        if ($last instanceof DOMElement && preg_match('/[?&]p=(\d+)/', $last->getAttribute('href'), $m) === 1) {
            $lastPage = max(1, (int) $m[1]);
        }

        return ['tiles' => array_values($tiles), 'last_page' => $lastPage];
    }

    /**
     * Strona wyrobu (treść <main>).
     *
     * @return array{
     *     id: int, master: int, is_master: bool, name: string, code: string, eans: list<string>, alt_units: string,
     *     price: float|null, currency: string, stock: string, step: float|null, description: string,
     *     params: list<array{section: string, name: string, value: string}>,
     *     rows: list<array{id: int, master: int, code: string, name: string, pack: string, kind: string, size: string, stock: string, price: float|null, currency: string, step: float|null}>,
     *     documents: list<array{title: string, url: string}>, gallery: list<string>, colour_images: array<int, string>
     * }
     */
    public static function productPage(string $main): array
    {
        $xpath = self::xpath($main);

        $config = $xpath->query('//*[@data-k2="ifItem" and contains(@class,"configuration_wrap")]')?->item(0);
        $detail = $xpath->query('//*[@data-master and contains(concat(" ",@class," ")," k2tx4detail ")]')?->item(0);
        $id = $config instanceof DOMElement ? (int) $config->getAttribute('data-id') : 0;
        if ($id === 0 && $detail instanceof DOMElement && preg_match('/\bk2item(\d+)\b/', $detail->getAttribute('class'), $m) === 1) {
            $id = (int) $m[1];
        }

        $header = self::headerTable($xpath);
        $offer = $xpath->query('//span[@itemprop="price" and contains(@class,"primary_vat")]')?->item(0);
        $currency = $xpath->query('//meta[@itemprop="priceCurrency"]')?->item(0);
        $stock = $xpath->query('//form[contains(@class,"buy_wrap_item_detail")]//div[contains(@class,"item_p_stock")]')?->item(0);
        $step = $xpath->query('//form[contains(@class,"buy_wrap_item_detail")]//input[contains(concat(" ",@class," ")," k2productBuyCount ")]')?->item(0);

        return [
            'id' => $id,
            'master' => $detail instanceof DOMElement ? (int) $detail->getAttribute('data-master') : 0,
            'is_master' => $config instanceof DOMElement && $config->getAttribute('data-is-master') === '1',
            'name' => self::title($xpath),
            'code' => $header['code'],
            'eans' => $header['eans'],
            'alt_units' => $header['alt_units'],
            'price' => $offer instanceof DOMElement ? self::number($offer->getAttribute('content')) : null,
            'currency' => $currency instanceof DOMElement ? strtoupper(trim($currency->getAttribute('content'))) : '',
            'stock' => self::clean($stock?->textContent ?? ''),
            'step' => $step instanceof DOMElement ? B2bOrderQuantity::attribute($step->getAttribute('step')) : null,
            'description' => self::description($xpath),
            'params' => self::params($xpath),
            'rows' => self::rows($xpath),
            'documents' => self::documents($xpath),
            'gallery' => self::gallery($xpath),
            'colour_images' => self::colourImages($xpath),
        ];
    }

    /** Liczba ze strony: „92,73”, „9.164”, „1 234,50”; pusta albo nieliczbowa = null, zero i mniej = null. */
    public static function number(string $value): ?float
    {
        $value = str_replace(["\u{00A0}", ' '], '', trim($value));
        if (preg_match('/^\d+(?:[.,]\d+)?$/', $value) !== 1) {
            return null;
        }
        $number = (float) str_replace(',', '.', $value);

        return $number > 0 ? $number : null;
    }

    /** Poprawny GTIN (EAN-8/12/13/14) z sumą kontrolną; pseudo-EAN Canis (kod bez kresek, 12 cyfr) jej zwykle nie ma. */
    public static function validGtin(string $value): bool
    {
        if (preg_match('/^(?:\d{8}|\d{12,14})$/', $value) !== 1) {
            return false;
        }
        $digits = array_map('intval', str_split($value));
        $check = array_pop($digits);
        $sum = 0;
        foreach (array_reverse($digits) as $i => $digit) {
            $sum += $digit * ($i % 2 === 0 ? 3 : 1);
        }

        return (10 - $sum % 10) % 10 === $check;
    }

    /** h1 wyrobu bez przecinka na końcu („Bluza CXS SIRIUS LUCIUS, męska,”). */
    private static function title(DOMXPath $xpath): string
    {
        $h1 = $xpath->query('//h1[@itemprop="name"]')?->item(0);

        return rtrim(self::clean($h1?->textContent ?? ''), ' ,;');
    }

    /**
     * Tabelka nagłówka: Kod, EAN (kilka), Jednostki alternatywne.
     *
     * @return array{code: string, eans: list<string>, alt_units: string}
     */
    private static function headerTable(DOMXPath $xpath): array
    {
        $out = ['code' => '', 'eans' => [], 'alt_units' => ''];
        foreach ($xpath->query('//div[contains(@class,"table_product_data")]/div') ?: [] as $cell) {
            $parts = [];
            foreach ($cell->childNodes as $child) {
                if ($child instanceof DOMElement) {
                    $parts[] = $child;
                }
            }
            if (count($parts) < 2) {
                continue;
            }
            $label = self::clean($parts[0]->textContent);
            if ($label === 'Kod:' && $out['code'] === '') {
                $out['code'] = self::clean($parts[1]->textContent);
            } elseif ($label === 'EAN:') {
                foreach ($xpath->query('.//div[@data-k2="EAN"]/div', $parts[1]) ?: [] as $ean) {
                    $value = self::clean($ean->textContent);
                    if ($value !== '' && ! in_array($value, $out['eans'], true)) {
                        $out['eans'][] = $value;
                    }
                }
            } elseif ($label === 'Jednostki alternatywne:') {
                $units = [];
                foreach ($xpath->query('.//div[@data-k2="unitItem"]', $parts[1]) ?: [] as $unit) {
                    $units[] = self::clean($unit->textContent);
                }
                $out['alt_units'] = implode('; ', array_filter($units, static fn (string $u): bool => $u !== ''));
            }
        }

        return $out;
    }

    /** Pełny opis z zakładki „Opis” (nad ceną jest skrót z „Pokaż więcej…”). Akapity w osobnych liniach. */
    private static function description(DOMXPath $xpath): string
    {
        $node = $xpath->query('//div[@id="description"]//div[contains(@class,"html_wrap")]')?->item(0);

        return $node === null ? '' : implode("\n", self::blockLines($node));
    }

    /**
     * Parametry towaru dosłownie: sekcja (nagłówek grupy, np. „Parametry towaru”, „Ostatní”), nazwa, wartość.
     *
     * @return list<array{section: string, name: string, value: string}>
     */
    private static function params(DOMXPath $xpath): array
    {
        $out = [];
        $seen = [];
        foreach ($xpath->query('//div[@data-k2="parametersGroupItem"]') ?: [] as $group) {
            $section = self::clean($xpath->query('./h3', $group)?->item(0)?->textContent ?? '');
            foreach ($xpath->query('.//tr[@data-k2="parametersItemItem"]', $group) ?: [] as $row) {
                $cells = $xpath->query('./td', $row);
                if ($cells === false || $cells->length < 2) {
                    continue;
                }
                $name = self::clean($cells->item(0)?->textContent ?? '');
                $valueNode = $xpath->query('./span', $cells->item(1))?->item(0) ?? $cells->item(1);
                $value = self::clean($valueNode?->textContent ?? '');
                $key = $section."\n".$name."\n".$value;
                if ($name === '' || $value === '' || isset($seen[$key])) {
                    continue;
                }
                $seen[$key] = true;
                $out[] = ['section' => $section, 'name' => $name, 'value' => $value];
            }
        }

        return $out;
    }

    /**
     * Wiersze tabeli wariantów (pozycje do zamówienia).
     *
     * @return list<array{id: int, master: int, code: string, name: string, pack: string, kind: string, size: string, stock: string, price: float|null, currency: string, step: float|null}>
     */
    private static function rows(DOMXPath $xpath): array
    {
        $out = [];
        foreach ($xpath->query('//tr[@data-k2="variantItem"]') ?: [] as $tr) {
            if (! $tr instanceof DOMElement || preg_match('/\bvariant_item_id_(\d+)\b/', $tr->getAttribute('class'), $m) !== 1) {
                continue;
            }
            $id = (int) $m[1];
            if (isset($out[$id])) {
                continue;
            }
            $cells = [];
            foreach ($xpath->query('./td[@data-title]', $tr) ?: [] as $td) {
                if ($td instanceof DOMElement) {
                    $cells[$td->getAttribute('data-title')] ??= $td;
                }
            }
            $nameCell = $cells['Nazwa produktu'] ?? null;
            $price = $xpath->query('.//*[@data-price and @data-currency]', $cells['Cena całkowita bez VAT'] ?? $tr)?->item(0);
            $step = $xpath->query('.//input[contains(concat(" ",@class," ")," k2productBuyCountBulk ")]', $tr)?->item(0);
            $out[$id] = [
                'id' => $id,
                'master' => (int) $tr->getAttribute('data-k2-master-id'),
                'code' => self::clean($cells['Kod produktu']->textContent ?? ''),
                'name' => $nameCell instanceof DOMElement && $nameCell->getAttribute('data-name') !== ''
                    ? self::clean($nameCell->getAttribute('data-name'))
                    : self::clean($nameCell?->textContent ?? ''),
                'pack' => self::clean($cells['Jednostka opakowania']->textContent ?? ''),
                'kind' => self::clean($cells['Rodzaj towaru']->textContent ?? ''),
                'size' => self::clean($cells['Rozmiar']->textContent ?? ''),
                'stock' => self::clean($cells['Na stanie']->textContent ?? ''),
                'price' => $price instanceof DOMElement ? self::number($price->getAttribute('data-price')) : null,
                'currency' => $price instanceof DOMElement ? self::clean(self::decode($price->getAttribute('data-currency'))) : '',
                'step' => $step instanceof DOMElement ? B2bOrderQuantity::attribute($step->getAttribute('step')) : null,
            ];
        }

        return array_values($out);
    }

    /**
     * Pliki z zakładki „Dokumenty” (imgserver portalu); tytuł z atrybutu odnośnika.
     *
     * @return list<array{title: string, url: string}>
     */
    private static function documents(DOMXPath $xpath): array
    {
        $out = [];
        foreach ($xpath->query('//div[@data-k2="fileItem"]//a[@href]') ?: [] as $link) {
            if (! $link instanceof DOMElement) {
                continue;
            }
            $url = trim($link->getAttribute('href'));
            if (! CanisB2bClient::isFileUrl($url) || isset($out[$url])) {
                continue;
            }
            $title = self::clean($link->getAttribute('title'));
            $out[$url] = ['title' => $title !== '' ? $title : self::clean($link->textContent), 'url' => $url];
        }

        return array_values($out);
    }

    /**
     * Galeria strony (data-k2="galleryItem") — adresy bez parametru rozmiaru „?w=1920” (oryginał portalu).
     *
     * @return list<string>
     */
    private static function gallery(DOMXPath $xpath): array
    {
        $out = [];
        foreach ($xpath->query('//*[@data-k2="galleryItem"]/a[@href]') ?: [] as $link) {
            if (! $link instanceof DOMElement) {
                continue;
            }
            $url = self::withoutQuery($link->getAttribute('href'));
            if ($url !== null && ! in_array($url, $out, true)) {
                $out[] = $url;
            }
        }

        return $out;
    }

    /**
     * Miniatury kafli „Wybierz wariant” (inne kolory modelu) — numer kafla → adres zdjęcia. Łącznik bierze tylko numery
     * kolorów z tabeli wariantów (sekcja „Powiązane produkty” ma te same kafle cudzych wyrobów).
     *
     * @return array<int, string>
     */
    private static function colourImages(DOMXPath $xpath): array
    {
        $out = [];
        foreach ($xpath->query('//div[@data-product-id]') ?: [] as $node) {
            if (! $node instanceof DOMElement) {
                continue;
            }
            $id = (int) $node->getAttribute('data-product-id');
            $img = $xpath->query('.//img[@data-src]', $node)?->item(0);
            $url = $img instanceof DOMElement ? self::withoutQuery($img->getAttribute('data-src')) : null;
            if ($id > 0 && $url !== null && ! isset($out[$id])) {
                $out[$id] = $url;
            }
        }

        return $out;
    }

    private static function withoutQuery(string $url): ?string
    {
        $url = trim(explode('?', trim($url), 2)[0]);

        return CanisB2bClient::isFileUrl($url) ? $url : null;
    }

    /**
     * Tekst węzła w liniach: bloki (p, div, li, br, tr, h1–h6) kończą linię.
     *
     * @return list<string>
     */
    private static function blockLines(DOMNode $node): array
    {
        $lines = [];
        $current = '';
        $flush = static function () use (&$lines, &$current): void {
            $line = self::clean($current);
            if ($line !== '') {
                $lines[] = $line;
            }
            $current = '';
        };
        $walk = static function (DOMNode $node) use (&$walk, &$current, $flush): void {
            foreach ($node->childNodes as $child) {
                if ($child->nodeType === XML_TEXT_NODE) {
                    $current .= $child->textContent;

                    continue;
                }
                if (! $child instanceof DOMElement) {
                    continue;
                }
                $tag = strtolower($child->tagName);
                if (in_array($tag, ['script', 'style'], true)) {
                    continue;
                }
                $block = in_array($tag, ['p', 'div', 'li', 'tr', 'ul', 'ol', 'table', 'h1', 'h2', 'h3', 'h4', 'h5', 'h6'], true);
                if ($tag === 'br' || $block) {
                    $flush();
                }
                $walk($child);
                if ($block) {
                    $flush();
                }
            }
        };
        $walk($node);
        $flush();

        return $lines;
    }

    private static function xpath(string $html): DOMXPath
    {
        $doc = new DOMDocument;
        $previous = libxml_use_internal_errors(true);
        $doc->loadHTML('<?xml encoding="utf-8"?>'.$html, LIBXML_NONET | LIBXML_COMPACT);
        libxml_clear_errors();
        libxml_use_internal_errors($previous);

        return new DOMXPath($doc);
    }

    private static function decode(string $text): string
    {
        return html_entity_decode($text, ENT_QUOTES | ENT_HTML5, 'UTF-8');
    }

    /** Tekst ze strony: bez znaków zerowej szerokości i miękkich łączników, twarde spacje jako zwykłe, odstępy zwinięte. */
    public static function clean(string $text): string
    {
        $text = str_replace(["\u{200B}", "\u{200C}", "\u{200D}", "\u{200E}", "\u{200F}", "\u{FEFF}", "\u{00AD}"], '', $text);

        return trim((string) preg_replace('/\s+/u', ' ', str_replace("\u{00A0}", ' ', $text)));
    }
}
