<?php

declare(strict_types=1);

namespace App\Services\Enrichment\PartsTable;

use App\Models\ManufacturerPart;
use App\Models\Product;
use App\Services\Enrichment\DescriptionVersionStore;
use App\Services\Enrichment\ManufacturerProfile;
use App\Support\ColourWords;

/**
 * Tabela części coba.com (`<table id="parts-table">` na https://www.coba.com/pl/produkt/<slug>) i przypięcie karty
 * cennika Coby do jej wiersza (decyzja właściciela 09.10.2026: przypisanie deterministyczne zamiast heurystyk
 * wyszukiwarki). Reguły przeniesione 1:1 ze skryptów pomiaru (measure.py, resolve_short.py) i reguła kontraktu dla
 * kodu na kilku stronach — pomiar na 221 stronach i 869 kartach z 09.10.2026: 680 dokładnych + 70 skrótów, 10 kodów
 * na stronach z różnymi tabelami (Fatigue-Step krawędź B1, Ringmat) czeka na page_overrides, 109 bez strony:
 * - dokładny kod karty (wielkie litery i cyfry) w tabeli którejś strony;
 * - inaczej skrót cennika („AF0107”, „DP0100-4”): litery rodziny + 4 cyfry (albo 2) po literach; prefiks = rodzina +
 *   cyfry, a gdy cyfry 3–4 to „00” — rodzina + 2 cyfry bez kodów, których 2 cyfry po prefiksie to „07”/„17”
 *   (krawędzie żółte); spośród nich wiersze z rozmiarem z nazwy karty (tolerancja 0,02 m, cm i mm przeliczone,
 *   „~0.59m/0.6m” = druga liczba, „mb”/„metr bieżący”) — przypięcie tylko przy dokładnie jednym kodzie;
 * - kod na kilku stronach: ten sam zbiór kodów stron (hygimat / hygimat-2) → strona kanoniczna (ma style > slug bez
 *   „-N” > krótszy > alfabetycznie); różne tabele → bez przypięcia, chyba że parts_table.page_overrides[kod] wskazuje
 *   slug;
 * - adres wskazany przez człowieka (Product::trustedShopUrl) wygrywa — bez przypięcia; strona przypięcia odrzucona
 *   przez handlowca (DescriptionVersionStore::rejectedUrls) — bez przypięcia;
 * - zdjęcie: styl („Dostępne style”) w kolorze wiersza, gdy dokładnie jeden pasuje (ta sama etykieta albo ten sam
 *   zbiór kolorów — „czarny i żółty” = „Czarny/Żółty”); inaczej zdjęcie modelu z wiersza.
 */
final class CobaPartsTable implements PartsTableResolver
{
    public const REASON_MANUAL_URL = 'adres ręczny';

    public const REASON_REJECTED_PAGE = 'strona odrzucona przez handlowca';

    public const REASON_SEVERAL = 'kilku kandydatów';

    public const REASON_SIZE = 'rozmiar się nie zgadza';

    public const REASON_NO_FAMILY = 'brak rodziny na stronach coba.com';

    public const REASON_NO_PATTERN = 'kod bez wzoru Coby';

    public const REASON_PAGES_DIFFER = 'kod na kilku stronach z różnymi tabelami';

    /** Okno po nagłówku „Dostępne style” (znaki), w którym stoją kafelki stylów — jak w measure.py. */
    private const STYLES_WINDOW = 8000;

    /** Tolerancja wymiaru w metrach (resolve_short.py: same_size). */
    private const SIZE_TOLERANCE = 0.02;

    /** @var array{key: string, byCode: array<string, list<ManufacturerPart>>, pageCodes: array<string, string>}|null */
    private ?array $index = null;

    public function __construct(private readonly DescriptionVersionStore $versions) {}

    public function parse(string $html, string $url): array
    {
        $rows = [];
        $seen = [];
        $styles = $this->styles($html);
        if (preg_match_all('#<tr>(.*?)</tr>#s', $html, $trs) > 0) {
            foreach ($trs[1] as $tr) {
                $cells = [];
                preg_match_all('#data-label="([^"]+)">(.*?)</td>#s', $tr, $found, PREG_SET_ORDER);
                foreach ($found as $cell) {
                    $cells[$cell[1]] = $cell[2];
                }
                $label = self::cell($cells, 'Numer');
                $code = ManufacturerPart::codeKey($label);
                if ($code === '' || strlen($code) > 64 || isset($seen[$code])) {
                    continue;
                }
                $seen[$code] = true;
                $colour = self::nullable(self::cell($cells, 'Kolor'));
                $image = preg_match('/productimage="([^"]+)"/', $tr, $m) === 1 ? self::text($m[1]) : '';
                $rows[] = [
                    'part' => $label,
                    'label' => $label,
                    'size' => self::nullable(self::cell($cells, 'Rozmiar')),
                    'colour' => $colour,
                    'weight_kg' => self::weight(self::cell($cells, 'Waga')),
                    'model_image' => self::nullable($image),
                    'style_image' => $this->styleFor($colour, $styles),
                ];
            }
        }

        return ['title' => $this->title($html), 'rows' => $rows, 'has_styles' => $styles !== []];
    }

    public function pinFor(Product $product, ManufacturerProfile $profile, array $rows): PinResult
    {
        if ($product->trustedShopUrl() !== null) {
            return new PinResult(null, self::REASON_MANUAL_URL);
        }
        $index = $this->index($rows);
        $sku = ManufacturerPart::codeKey((string) $product->sku);
        $viaShort = false;
        if (! isset($index['byCode'][$sku])) {
            $short = $this->shortCode($sku, (string) $product->name, $index);
            if ($short instanceof PinResult) {
                return $short;
            }
            $sku = $short;
            $viaShort = true;
        }

        $byPage = [];
        foreach ($index['byCode'][$sku] as $row) {
            $byPage[$row->page_url_hash] ??= $row;
        }
        $row = $this->pageRow($sku, array_values($byPage), $profile, $index);
        if ($row === null) {
            return new PinResult(null, self::REASON_PAGES_DIFFER, array_map(
                static fn (ManufacturerPart $r): string => ManufacturerPart::pageKeyFor($r->page_url),
                array_values($byPage),
            ));
        }
        $pageKey = DescriptionVersionStore::sourceUrlKey($row->page_url);
        foreach ($this->versions->rejectedUrls($product) as $rejected) {
            if (DescriptionVersionStore::sourceUrlKey($rejected) === $pageKey) {
                return new PinResult(null, self::REASON_REJECTED_PAGE, [ManufacturerPart::pageKeyFor($row->page_url)]);
            }
        }

        $style = trim((string) $row->style_image_url);

        return new PinResult(new PartsTablePin(
            brandKey: (string) $row->brand_key,
            pageUrl: (string) $row->page_url,
            pageKey: ManufacturerPart::pageKeyFor((string) $row->page_url),
            pageTitle: $row->page_title,
            part: (string) $row->part_label,
            size: $row->size_label,
            colour: $row->colour_label,
            weightKg: $row->weight_kg !== null ? (float) $row->weight_kg : null,
            imageUrl: $style !== '' ? $style : (trim((string) $row->model_image_url) !== '' ? (string) $row->model_image_url : null),
            imageReason: $style !== '' ? 'style' : 'model',
            viaShortCode: $viaShort,
            cardCode: (string) $product->sku,
        ));
    }

    /**
     * Wymiar z nazwy karty albo komórki „Rozmiar” w metrach: [szerokość, długość] albo [szerokość, 'mb'] (na metry
     * bieżące); null = bez wymiaru. resolve_short.py: size_key — zaokrąglenie jak Python round(x, 2).
     *
     * @return array{0: float, 1: float|string}|null
     */
    public static function sizeKey(?string $text): ?array
    {
        $t = str_replace(',', '.', mb_strtolower((string) $text, 'UTF-8'));
        // „~0.59m/0.6m x …” — liczy się druga liczba
        $t = (string) preg_replace('~\~?\d+(?:\.\d+)?m?/(?=\d)~u', '', $t);
        if (preg_match('/(\d+(?:\.\d+)?)\s*(mm|cm|m)?\s*x\s*(?:(\d+(?:\.\d+)?)\s*(mm|cm|m)?|(mb|metr))/u', $t, $m) !== 1) {
            return null;
        }
        $firstUnit = $m[2] ?? '';
        $secondUnit = $m[4] ?? '';
        $width = (float) $m[1] / self::divisor($firstUnit !== '' ? $firstUnit : $secondUnit);
        if (($m[5] ?? '') !== '') {
            return [self::pyRound($width), 'mb'];
        }

        return [self::pyRound($width), self::pyRound((float) $m[3] / self::divisor($secondUnit !== '' ? $secondUnit : $firstUnit))];
    }

    /**
     * Ten sam wymiar (resolve_short.py: same_size): szerokość ±0,02 m, długość ±max(0,02 m, 1%); metry bieżące tylko
     * z metrami bieżącymi.
     *
     * @param  array{0: float, 1: float|string}|null  $a
     * @param  array{0: float, 1: float|string}|null  $b
     */
    public static function sameSize(?array $a, ?array $b): bool
    {
        if ($a === null || $b === null || ($a[1] === 'mb') !== ($b[1] === 'mb')) {
            return false;
        }
        if (abs($a[0] - $b[0]) > self::SIZE_TOLERANCE) {
            return false;
        }

        return $a[1] === 'mb' || abs((float) $a[1] - (float) $b[1]) <= max(self::SIZE_TOLERANCE, 0.01 * (float) $b[1]);
    }

    /**
     * Skrót cennika → jeden kod z tabeli albo wynik bez przypięcia z powodem (i kandydatami).
     *
     * @param  array{key: string, byCode: array<string, list<ManufacturerPart>>, pageCodes: array<string, string>}  $index
     */
    private function shortCode(string $sku, string $name, array $index): string|PinResult
    {
        if (preg_match('/^([A-Z]+)(\d{4}|\d{2})/', $sku, $m) !== 1) {
            return new PinResult(null, self::REASON_NO_PATTERN);
        }
        $digits = $m[2];
        $blankEdge = strlen($digits) === 4 && substr($digits, 2) === '00';
        $prefix = $m[1].($blankEdge ? substr($digits, 0, 2) : $digits);
        $want = self::sizeKey($name);
        $family = [];
        $hits = [];
        foreach ($index['byCode'] as $code => $rows) {
            $code = (string) $code;
            if (! str_starts_with($code, $prefix)
                || ($blankEdge && in_array(substr($code, strlen($prefix), 2), ['07', '17'], true))) {
                continue;
            }
            $family[$code] = true;
            foreach ($rows as $row) {
                if (self::sameSize($want, self::sizeKey($row->size_label))) {
                    $hits[$code] = true;
                    break;
                }
            }
        }
        $codes = array_keys($hits);
        sort($codes, SORT_STRING);
        if (count($codes) === 1) {
            return (string) $codes[0];
        }
        if ($codes !== []) {
            return new PinResult(null, self::REASON_SEVERAL, array_map('strval', $codes));
        }
        if ($family !== []) {
            $all = array_map('strval', array_keys($family));
            sort($all, SORT_STRING);

            return new PinResult(null, self::REASON_SIZE, $all);
        }

        return new PinResult(null, self::REASON_NO_FAMILY);
    }

    /**
     * Wiersz kodu na stronie przypięcia: jedna strona — ta; kilka stron z tym samym zbiorem kodów — kanoniczna;
     * strona wskazana w page_overrides; inaczej null (różne tabele).
     *
     * @param  list<ManufacturerPart>  $rows  po jednym wierszu kodu na stronę
     * @param  array{key: string, byCode: array<string, list<ManufacturerPart>>, pageCodes: array<string, string>}  $index
     */
    private function pageRow(string $code, array $rows, ManufacturerProfile $profile, array $index): ?ManufacturerPart
    {
        if (count($rows) === 1) {
            return $rows[0];
        }
        foreach ((array) ($profile->partsTable['page_overrides'] ?? []) as $overrideCode => $slug) {
            if (ManufacturerPart::codeKey((string) $overrideCode) !== $code) {
                continue;
            }
            foreach ($rows as $row) {
                if (ManufacturerPart::pageKeyFor($row->page_url) === trim((string) $slug, '/ ')) {
                    return $row;
                }
            }
        }
        $sets = array_unique(array_map(static fn (ManufacturerPart $r): string => $index['pageCodes'][$r->page_url_hash] ?? '', $rows));
        if (count($sets) !== 1) {
            return null;
        }
        usort($rows, static function (ManufacturerPart $a, ManufacturerPart $b): int {
            return self::canonicalKey($a) <=> self::canonicalKey($b);
        });

        return $rows[0];
    }

    /** @return array{0: int, 1: int, 2: int, 3: string} ma style > slug bez „-N” > krótszy > alfabetycznie */
    private static function canonicalKey(ManufacturerPart $row): array
    {
        $slug = ManufacturerPart::pageKeyFor($row->page_url);

        return [$row->has_styles ? 0 : 1, preg_match('/-\d+$/', $slug) === 1 ? 1 : 0, strlen($slug), $slug];
    }

    /**
     * Indeks wierszy marki: kod => wiersze (po stronach), strona => posortowany zbiór kodów. Liczony raz na zestaw
     * wierszy (PartsTables trzyma te same obiekty przez cały przebieg).
     *
     * @param  list<ManufacturerPart>  $rows
     * @return array{key: string, byCode: array<string, list<ManufacturerPart>>, pageCodes: array<string, string>}
     */
    private function index(array $rows): array
    {
        $first = $rows[0] ?? null;
        $last = $rows !== [] ? $rows[count($rows) - 1] : null;
        $key = count($rows).':'.($first !== null ? spl_object_id($first).':'.$first->id : '').':'.($last !== null ? spl_object_id($last).':'.$last->id : '');
        if ($this->index !== null && $this->index['key'] === $key) {
            return $this->index;
        }
        $byCode = [];
        $codesByPage = [];
        foreach ($rows as $row) {
            $code = (string) $row->part_code;
            $byCode[$code][] = $row;
            $codesByPage[(string) $row->page_url_hash][$code] = true;
        }
        $pageCodes = [];
        foreach ($codesByPage as $hash => $codes) {
            $codes = array_map('strval', array_keys($codes));
            sort($codes, SORT_STRING);
            $pageCodes[$hash] = implode(',', $codes);
        }

        return $this->index = ['key' => $key, 'byCode' => $byCode, 'pageCodes' => $pageCodes];
    }

    /**
     * Kafelki sekcji „Dostępne style”: etykieta (dosłownie), klucz (małe litery bez białych znaków) i adres zdjęcia;
     * ta sama etykieta drugi raz — wygrywa późniejsza (jak słownik w measure.py).
     *
     * @return array<string, array{label: string, url: string}>
     */
    private function styles(string $html): array
    {
        $start = mb_strpos($html, 'Dostępne style', 0, 'UTF-8');
        if ($start === false) {
            return [];
        }
        $window = mb_substr($html, $start, self::STYLES_WINDOW, 'UTF-8');
        $styles = [];
        preg_match_all(
            '#<div>\s*<a href="([^"]+)"><img[^>]*>\s*</a>\s*(?:<p[^>]*>(.*?)</p>|<span[^>]*>(.*?)</span>)?#s',
            $window,
            $found,
            PREG_SET_ORDER,
        );
        foreach ($found as $tile) {
            $raw = ($tile[2] ?? '') !== '' ? $tile[2] : ($tile[3] ?? '');
            $label = trim((string) preg_replace('/\s+/u', ' ', self::text((string) preg_replace('/<[^>]+>/', '', $raw))));
            $key = self::colourKey($label);
            if ($key !== '') {
                $styles[$key] = ['label' => $label, 'url' => self::text($tile[1])];
            }
        }

        return $styles;
    }

    /**
     * Zdjęcie stylu w kolorze wiersza: ta sama etykieta (bez wielkości liter i białych znaków), a bez niej dokładnie
     * jeden styl z tym samym zbiorem kolorów (ColourWords: „czarny i żółty” = „Czarny/Żółty”); null = zdjęcie modelu.
     *
     * @param  array<string, array{label: string, url: string}>  $styles
     */
    private function styleFor(?string $colour, array $styles): ?string
    {
        $key = self::colourKey((string) $colour);
        if ($key === '' || $styles === []) {
            return null;
        }
        if (isset($styles[$key])) {
            return $styles[$key]['url'];
        }
        $wanted = ColourWords::allInName((string) $colour);
        if ($wanted === []) {
            return null;
        }
        $matches = array_values(array_filter(
            $styles,
            static fn (array $style): bool => ColourWords::sameSet(ColourWords::allInName($style['label']), $wanted),
        ));

        return count($matches) === 1 ? $matches[0]['url'] : null;
    }

    private function title(string $html): ?string
    {
        foreach (['#<h1[^>]*>(.*?)</h1>#s', '#<title>(.*?)</title>#s'] as $pattern) {
            if (preg_match($pattern, $html, $m) === 1) {
                $title = trim((string) preg_replace('/\s+/u', ' ', self::text((string) preg_replace('/<[^>]+>/', ' ', $m[1]))));
                $title = trim((string) preg_replace('/\s+-\s+COBA\s+PL$/u', '', $title));
                if ($title !== '') {
                    return mb_substr($title, 0, 300, 'UTF-8');
                }
            }
        }

        return null;
    }

    /**
     * Pierwsza komórka, której etykieta (data-label) zawiera słowo — bez znaczników, bez encji (measure.py: cell).
     *
     * @param  array<string, string>  $cells
     */
    private static function cell(array $cells, string $word): string
    {
        foreach ($cells as $label => $value) {
            if (str_contains(self::text((string) $label), $word)) {
                return trim(self::text(trim((string) preg_replace('/<[^>]+>/', '', $value))));
            }
        }

        return '';
    }

    /** Waga z komórki („5.4”, „58,55”) albo null. */
    private static function weight(string $text): ?float
    {
        return preg_match('/\d+(?:\.\d+)?/', str_replace(',', '.', $text), $m) === 1 ? (float) $m[0] : null;
    }

    private static function nullable(string $text): ?string
    {
        $text = trim($text);

        return $text !== '' ? $text : null;
    }

    private static function text(string $html): string
    {
        return html_entity_decode($html, ENT_QUOTES | ENT_HTML5, 'UTF-8');
    }

    /** Klucz etykiety koloru: małe litery bez białych znaków (measure.py: ncol). */
    private static function colourKey(string $label): string
    {
        return (string) preg_replace('/\s+/u', '', mb_strtolower(trim(self::text($label)), 'UTF-8'));
    }

    private static function divisor(string $unit): float
    {
        return match ($unit) {
            'mm' => 1000.0,
            'cm' => 100.0,
            default => 1.0,
        };
    }

    /** Jak Python round(x, 2): połówki po dokładnej wartości binarnej („%.2F” zaokrągla tak samo). */
    private static function pyRound(float $value): float
    {
        return (float) sprintf('%.2F', $value);
    }
}
