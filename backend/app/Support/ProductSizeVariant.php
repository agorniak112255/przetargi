<?php

declare(strict_types=1);

namespace App\Support;

use App\Models\Product;

/**
 * Grupuje warianty, które różnią się tylko rozmiarem
 * (np. AlphaTec Size 7.0 / 10.0 albo 1st Winter Dry 9 / 10 / 11).
 * Wyciąga też zakresy z opisu: rękawice, obuwie, odzież, spodnie.
 */
final class ProductSizeVariant
{
    /** Kod 3-cyfrowy na końcu SKU (Ansell VP100 = 10.0). */
    private const DIGIT_CODE_SIZES = [
        '050' => '5',
        '055' => '5.5',
        '060' => '6',
        '065' => '6.5',
        '070' => '7',
        '075' => '7.5',
        '080' => '8',
        '085' => '8.5',
        '090' => '9',
        '095' => '9.5',
        '100' => '10',
        '105' => '10.5',
        '110' => '11',
        '115' => '11.5',
        '120' => '12',
        '130' => '13',
    ];

    /** @var list<string> */
    private const ALPHA_ORDER = [
        'xxs', 'xs', 's', 'm', 'l', 'xl', 'xxl', 'xxxl', 'xxxxl', '5xl', '6xl',
    ];

    private const KEYWORD = '(?:dost[eę]pne\\s+rozmiary|available\\s+sizes?|rozmiary|rozmiar(?:ów|y)?|sizes?|tailles?|pointures?|taglie|misure|numeri|gr(?:o|ö)sse?n?)';

    private const NUM = '(\\d{1,2}(?:[.,]\\d)?)';

    private const ALPHA = '(xxxxl|xxxl|xxl|xl|xxs|xs|[2-6]\\s*xl|[sml])';

    private const RANGE_SEP = '(?:do|to|à|au|bis|[-–—])';

    /**
     * Lista rozmiarów z pola opakowania (np. „7, 8, 9, 10”) albo jeden rozmiar z nazwy/SKU.
     *
     * @return list<string>
     */
    public function parseSizeList(?string $packaging, ?string $name = null, ?string $sku = null): array
    {
        $raw = trim((string) $packaging);
        $range = $this->expandIfSingleRange($raw);
        if ($range !== []) {
            return $range;
        }

        $found = [];
        if ($raw !== '' && preg_match('/[,;]/', $raw) === 1) {
            foreach (preg_split('/[,;]+/', $raw) ?: [] as $part) {
                $part = trim((string) $part);
                if ($part === '') {
                    continue;
                }
                $norm = $this->normalizeSizeToken($part);
                if ($norm === null && preg_match('/^\d{1,2}(?:[.,]\d)?\s*-\s*\d{1,2}(?:[.,]\d)?$/', $part) === 1) {
                    $norm = str_replace(',', '.', preg_replace('/\s+/', '', $part) ?? $part);
                }
                if ($norm !== null && ! in_array($norm, $found, true)) {
                    $found[] = $norm;
                }
            }
        }
        if ($found !== []) {
            return $found;
        }
        $one = $this->extractSize($name, $sku, $packaging);
        if ($one === null) {
            return [];
        }

        return [$one];
    }

    /**
     * Rozmiary do Presty / opakowania: lista z cennika, potem zakres z opisu.
     *
     * @return list<string>
     */
    public function sizesForProduct(Product $product): array
    {
        $fromPack = $this->parseSizeList($product->packaging, $product->name, $product->sku);
        if (count($fromPack) >= 2) {
            return $fromPack;
        }

        $payload = is_array($product->enrichment_payload) ? $product->enrichment_payload : [];
        $attrs = is_array($payload['attributes'] ?? null) ? $payload['attributes'] : [];
        $chunks = [
            (string) ($attrs['rozmiar'] ?? ''),
        ];
        foreach ($payload['specs'] ?? [] as $spec) {
            if (is_string($spec) && trim($spec) !== '') {
                $chunks[] = $spec;
            }
        }
        $chunks[] = (string) ($product->description ?? '');

        $fromText = [];
        foreach ($chunks as $chunk) {
            $parsed = $this->parseSizesFromText($chunk);
            if (count($parsed) > count($fromText)) {
                $fromText = $parsed;
            }
        }
        if (count($fromText) >= 2) {
            return $fromText;
        }
        if ($fromText !== [] && $fromPack === []) {
            return $fromText;
        }

        return $fromPack;
    }

    /**
     * @return list<string>
     */
    public function parseSizesFromText(string $text): array
    {
        $text = html_entity_decode(strip_tags($text), ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $text = str_replace("\xc2\xa0", ' ', $text);
        $text = trim($text);
        if ($text === '') {
            return [];
        }

        $asWhole = $this->expandIfSingleRange($text);
        if ($asWhole !== []) {
            return $asWhole;
        }

        $keyword = self::KEYWORD;
        $num = self::NUM;
        $alpha = self::ALPHA;
        $sep = self::RANGE_SEP;

        if (preg_match(
            '/\b'.$keyword.'\b.{0,48}?(?:od|from|de|du|von)?\s*'.$num.'\s*'.$sep.'\s*'.$num.'\b/iu',
            $text,
            $m
        ) === 1) {
            $expanded = $this->expandNumericRange($m[1], $m[2]);
            if ($expanded !== []) {
                return $expanded;
            }
        }

        if (preg_match(
            '/\b'.$keyword.'\b.{0,48}?(?:od|from|de|du|von)?\s*'.$alpha.'\s*'.$sep.'\s*'.$alpha.'\b/iu',
            $text,
            $m
        ) === 1) {
            $expanded = $this->expandAlphaRange($m[1], $m[2]);
            if ($expanded !== []) {
                return $expanded;
            }
        }

        if (preg_match(
            '/\b'.$keyword.'\b(?:\s+\p{L}+){0,4}\s*[:.\-—]?\s*\b((?:'.$num.'|'.$alpha.')(?:\s*[,\/;]\s*(?:'.$num.'|'.$alpha.')){1,24})/iu',
            $text,
            $m
        ) === 1) {
            $list = $this->parseCommaList($m[1]);
            if ($list !== []) {
                return $list;
            }
        }

        // Siatka sklepu: „Rozmiar: 36 37 38 39…” — spacje, nie przecinki.
        if (preg_match(
            '/\b(?:rozmiar(?:y|ów)?|sizes?|tailles?|pointures?)\s*[:=]?\s*((?:'.$num.'|'.$alpha.')(?:\s+(?:'.$num.'|'.$alpha.')){2,24})\b/iu',
            $text,
            $m
        ) === 1) {
            $list = $this->parseSpaceList($m[1]);
            if (count($list) >= 3) {
                return $list;
            }
        }

        $fromCodes = $this->sizesFromManufacturerCodes($text);
        if (count($fromCodes) >= 4) {
            return $fromCodes;
        }

        if (preg_match(
            '/\b(?:rozmiar|size|taille|rozm\.?)\s*[:=]?\s*('.$num.'|'.$alpha.')\b/iu',
            $text,
            $m
        ) === 1) {
            $one = $this->normalizeSizeToken($m[1]);
            if ($one !== null) {
                return [$one];
            }
        }

        if (preg_match('/\b(?:rozmiar\s+uniwersaln\w*|one\s*size|onesize|taille\s+unique)\b/iu', $text) === 1) {
            return ['onesize'];
        }

        return $this->parseBareFootwearRange($text);
    }

    /**
     * Opcje zakupu ze sklepu: select/przyciski „36…47”, nie opis.
     *
     * @return list<string>
     */
    public function parseShopOptionSizes(string $html): array
    {
        if (trim($html) === '') {
            return [];
        }
        $html = html_entity_decode($html, ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $best = [];
        foreach ($this->shopOptionBlocks($html) as $block) {
            $tokens = $this->tokensFromShopBlock($block);
            if (count($tokens) > count($best)) {
                $best = $tokens;
            }
        }
        // Opcje zakupu mają pierwszeństwo przed tabelą / opisem (np. 36-48 w stopce).
        if ($best !== []) {
            return $this->uniqueSortedSizes($best);
        }
        $fromCodes = $this->sizesFromManufacturerCodes($html);
        if ($fromCodes === []) {
            $fromCodes = $this->sizesFromVariantSkuSuffixes($html);
        }
        if ($fromCodes !== []) {
            return $fromCodes;
        }
        $plain = trim(html_entity_decode(strip_tags($html), ENT_QUOTES | ENT_HTML5, 'UTF-8'));

        return $this->uniqueSortedSizes($this->parseSizesFromText($plain));
    }

    /**
     * Najdłuższa lista pasująca do kategorii (rękawice ≠ 35-49 z tabeli butów).
     *
     * @param  list<list<string>>  $lists
     * @return list<string>
     */
    public function pickBestSizeList(array $lists, ?string $category): array
    {
        $bestFiltered = [];
        $bestRaw = [];
        foreach ($lists as $list) {
            if (! is_array($list) || count($list) < 2) {
                continue;
            }
            $tokens = [];
            foreach ($list as $size) {
                if (is_string($size) && trim($size) !== '') {
                    $tokens[] = $size;
                }
            }
            if (count($tokens) < 2) {
                continue;
            }
            $filtered = $this->filterByCategory($tokens, $category);
            if (count($filtered) > count($bestFiltered)) {
                $bestFiltered = $filtered;
            }
            if ($bestFiltered === [] && count($tokens) > count($bestRaw)) {
                $bestRaw = $tokens;
            }
        }
        if ($bestFiltered !== []) {
            return $this->uniqueSortedSizes($bestFiltered);
        }
        if ($category !== null && trim($category) !== '') {
            return [];
        }

        return $this->uniqueSortedSizes($bestRaw);
    }

    /**
     * Etykieta z AI / opisu: odrzuca śmieci w stylu „1-5XL” przy obuwiu.
     */
    public function labelFromTexts(?string $claimed, string $text, ?string $category = null): ?string
    {
        $best = [];
        foreach ([$claimed ?? '', $text] as $chunk) {
            if (trim($chunk) === '') {
                continue;
            }
            foreach ([$this->parseSizesFromText($chunk), $this->parseBareFootwearRange($chunk)] as $parsed) {
                $parsed = $this->filterByCategory($parsed, $category);
                if (count($parsed) > count($best)) {
                    $best = $parsed;
                }
            }
        }

        return $this->formatPackaging($best);
    }

    /**
     * @param  list<string>  $sizes
     */
    public function formatPackaging(array $sizes): ?string
    {
        $sizes = array_values(array_filter(
            $sizes,
            static fn (string $s): bool => trim($s) !== ''
        ));
        if ($sizes === []) {
            return null;
        }
        if (count($sizes) === 1) {
            return $sizes[0];
        }
        $compact = $this->compactContiguous($sizes);

        return $compact ?? implode(', ', $sizes);
    }

    /**
     * @param  list<string>  $sizes
     */
    public function shouldFillPackaging(?string $current, array $sizes): bool
    {
        if ($sizes === []) {
            return false;
        }
        $existing = $this->parseSizeList($current);
        if ($existing === []) {
            return true;
        }
        if (count($existing) >= 2) {
            return false;
        }

        return count($sizes) >= 2;
    }

    public function extractSize(?string $name, ?string $sku = null, ?string $packaging = null): ?string
    {
        $fromPack = $this->normalizeSizeToken((string) $packaging);
        if ($fromPack !== null) {
            return $fromPack;
        }
        $fromName = $this->sizeFromName((string) $name);
        if ($fromName !== null) {
            return $fromName;
        }

        return $this->sizeFromSku((string) $sku);
    }

    public function stripSizeFromName(string $name): string
    {
        $t = trim($name);
        $t = preg_replace(
            '/[,\s]+(?:size|rozmiar|taille|rozm\.?)\s*:?\s*(?:\d{1,2}(?:[.,]\d)?|[2-6]\s*xl|xxxxl|xxxl|xxl|xl|xxs|xs|s|m|l)\s*$/iu',
            '',
            $t
        ) ?? $t;
        if (preg_match('/^(.*?)[\s,\/]+(\d{1,2}(?:[.,]\d)?)\s*$/u', $t, $m) === 1
            && $this->normalizeSizeToken($m[2]) !== null) {
            $t = $m[1];
        }

        return trim(preg_replace('/\s+/u', ' ', $t) ?? $t);
    }

    /**
     * Ostatni człon po / albo - to rozmiar (40, 09, XL) — sklepy trzymają sam model.
     */
    public function stripWearSizeSuffix(string $sku): ?string
    {
        $sku = trim($sku);
        if ($sku === '' || preg_match('/[\/\-_]/u', $sku) !== 1) {
            return null;
        }
        $parts = preg_split('/[\/\-_]+/u', $sku) ?: [];
        if (count($parts) < 2 || count($parts) > 3) {
            return null;
        }
        if (preg_match('/^(.+?)[\/\-_](xxxxl|xxxl|xxl|xxs|xs|xl|[2-6]xl|[sml])$/iu', $sku, $m) === 1
            && $this->isUsableCore($m[1])) {
            return rtrim($m[1], "-/_ \t");
        }
        if (preg_match('/^(.+?)[\/\-_](\d{1,2}(?:[.,]\d)?)$/u', $sku, $m) === 1
            && $this->looksLikeWearSize($m[2])
            && $this->isUsableCore($m[1])) {
            return rtrim($m[1], "-/_ \t");
        }

        return null;
    }

    /** Rękawice 5–13, obuwie 32–50, odzież 44–78 albo litera (S–XXXL). */
    public function looksLikeWearSize(string $raw): bool
    {
        return $this->normalizeSizeToken($raw) !== null;
    }

    public function skuCore(?string $sku, ?string $name = null): ?string
    {
        $sku = trim((string) $sku);
        if ($sku === '') {
            return null;
        }
        $stripped = $this->stripWearSizeSuffix($sku);
        if ($stripped !== null) {
            return $stripped;
        }
        $size = $this->extractSize($name, $sku);
        if ($size === null) {
            return null;
        }
        $code = $this->sizeToSkuSuffix($size);
        if ($code !== null && preg_match('/^(.+)'.$code.'$/i', $sku, $m) === 1 && $this->isUsableCore($m[1])) {
            return rtrim($m[1], "-/_ \t");
        }
        if (preg_match('/^\d+(?:\.\d)?$/', $size) === 1 && preg_match('/[\/\-_]/u', $sku) !== 1) {
            $two = (string) (int) $size;
            if (preg_match('/^(.+)'.$two.'$/i', $sku, $m) === 1 && $this->isUsableCore($m[1])) {
                return rtrim($m[1], "-/_ \t");
            }
        }

        return null;
    }

    /**
     * Null = nie jest wariantem rozmiaru, nie scalać.
     */
    public function groupKey(string $manufacturer, string $name, string $sku, ?string $packaging = null): ?string
    {
        $size = $this->extractSize($name, $sku, $packaging);
        if ($size === null) {
            return null;
        }
        $stripped = mb_strtolower($this->stripSizeFromName($name));
        $original = mb_strtolower(trim($name));
        $mfr = mb_strtolower(trim($manufacturer));
        if ($stripped !== '' && $stripped !== $original && mb_strlen($stripped) >= 6) {
            return 'name:'.$mfr.'|'.$stripped;
        }
        $core = $this->skuCore($sku, $name);
        if ($core !== null) {
            return 'sku:'.$mfr.'|'.mb_strtolower($core);
        }

        return null;
    }

    public function priceBucket(float|string|null $catalog, float|string|null $purchase): string
    {
        return number_format(round((float) $catalog, 2), 2, '.', '')
            .'|'.number_format(round((float) $purchase, 2), 2, '.', '');
    }

    /**
     * @return list<string>
     */
    public function parseBareFootwearRange(string $text): array
    {
        if (preg_match(
            '/(?<![\d.:])\b(3[2-9]|4[0-9]|5[0-2])\s*[-–—]\s*(3[2-9]|4[0-9]|5[0-2])\b(?!\d)/',
            $text,
            $m
        ) !== 1) {
            return [];
        }

        return $this->expandNumericRange($m[1], $m[2]);
    }

    /**
     * @param  list<string>  $sizes
     * @return list<string>
     */
    public function filterByCategory(array $sizes, ?string $category): array
    {
        $cat = mb_strtolower(trim((string) $category));
        $footwear = $cat === 'obuwie' || str_contains($cat, 'obuw') || str_contains($cat, 'but');
        $gloves = $cat === 'rekawice' || str_contains($cat, 'rękaw') || str_contains($cat, 'rekaw');
        $out = [];
        foreach ($sizes as $size) {
            if ($footwear && ! $this->isFootwearToken($size)) {
                continue;
            }
            if ($gloves && ! $this->isGloveToken($size)) {
                continue;
            }
            $out[] = $size;
        }

        return $out;
    }

    private function isFootwearToken(string $size): bool
    {
        if ($size === 'onesize') {
            return true;
        }

        return preg_match('/^\d{2}$/', $size) === 1
            && (int) $size >= 32
            && (int) $size <= 52;
    }

    private function isGloveToken(string $size): bool
    {
        if (preg_match('/^(xxxxl|xxxl|xxl|xl|xxs|xs|s|m|l|[2-6]xl|onesize)$/', $size) === 1) {
            return true;
        }
        if (preg_match('/^\d{1,2}(?:\.\d)?$/', $size) !== 1) {
            return false;
        }
        $n = (float) $size;

        return $n >= 4 && $n <= 16;
    }

    /**
     * @return list<string>
     */
    private function expandIfSingleRange(string $raw): array
    {
        $t = trim(preg_replace('/\s+/u', ' ', $raw) ?? $raw);
        if ($t === '' || preg_match('/[,;\/]/', $t) === 1) {
            return [];
        }

        $num = self::NUM;
        $alpha = self::ALPHA;
        $sep = self::RANGE_SEP;

        if (preg_match('/^(?:od|from|de|du|von)\s+'.$num.'\s+'.$sep.'\s+'.$num.'$/iu', $t, $m) === 1
            || preg_match('/^'.$num.'\s*'.$sep.'\s*'.$num.'$/iu', $t, $m) === 1) {
            return $this->expandNumericRange($m[1], $m[2]);
        }

        if (preg_match('/^(?:od|from|de|du|von)\s+'.$alpha.'\s+'.$sep.'\s+'.$alpha.'$/iu', $t, $m) === 1
            || preg_match('/^'.$alpha.'\s*'.$sep.'\s*'.$alpha.'$/iu', $t, $m) === 1) {
            return $this->expandAlphaRange($m[1], $m[2]);
        }

        return [];
    }

    /**
     * @return list<string>
     */
    private function expandNumericRange(string $fromRaw, string $toRaw): array
    {
        $from = (float) str_replace(',', '.', $fromRaw);
        $to = (float) str_replace(',', '.', $toRaw);
        if ($from > $to) {
            [$from, $to] = [$to, $from];
        }
        if (! $this->isPlausibleNumericRange($from, $to)) {
            return [];
        }
        if ($this->isHalfPair($from, $to)) {
            return [$this->formatNumeric($from).'-'.$this->formatNumeric($to)];
        }

        $step = $this->numericStep($from, $to);
        $out = [];
        for ($n = $from; $n <= $to + 0.001; $n += $step) {
            $out[] = $this->formatNumeric(round($n * 2) / 2);
            if (count($out) > 25) {
                return [];
            }
        }

        return $out;
    }

    /**
     * @return list<string>
     */
    private function expandAlphaRange(string $fromRaw, string $toRaw): array
    {
        $from = $this->normalizeSizeToken($fromRaw);
        $to = $this->normalizeSizeToken($toRaw);
        if ($from === null || $to === null) {
            return [];
        }
        $i = array_search($from, self::ALPHA_ORDER, true);
        $j = array_search($to, self::ALPHA_ORDER, true);
        if (! is_int($i) || ! is_int($j)) {
            return [];
        }
        if ($i > $j) {
            [$i, $j] = [$j, $i];
        }

        return array_values(array_slice(self::ALPHA_ORDER, $i, $j - $i + 1));
    }

    /**
     * @return list<string>
     */
    private function parseCommaList(string $raw): array
    {
        $found = [];
        foreach (preg_split('/[,;\/]+/', $raw) ?: [] as $part) {
            $norm = $this->normalizeSizeToken(trim((string) $part));
            if ($norm !== null && ! in_array($norm, $found, true)) {
                $found[] = $norm;
            }
        }

        return $found;
    }

    /**
     * @return list<string>
     */
    private function parseSpaceList(string $raw): array
    {
        $found = [];
        foreach (preg_split('/\s+/u', trim($raw)) ?: [] as $part) {
            $norm = $this->normalizeSizeToken(trim((string) $part));
            if ($norm !== null && ! in_array($norm, $found, true)) {
                $found[] = $norm;
            }
        }

        return $found;
    }

    /**
     * @return list<string>
     */
    private function shopOptionBlocks(string $html): array
    {
        $blocks = [];
        if (preg_match_all(
            '#<(?:select|ul|ol|div|fieldset|table)[^>]*(?:name|id|class|aria-label|data-name|data-attribute)=["\'][^"\']*(?:rozmiar|size|taille|pointure|taglia|groesse|größe|attribute)[^"\']*["\'][^>]*>(.*?)</(?:select|ul|ol|div|fieldset|table)>#is',
            $html,
            $m
        )) {
            foreach ($m[1] as $block) {
                $blocks[] = (string) $block;
            }
        }
        if (preg_match_all(
            '#(?:rozmiar|size|taille|pointure)[^<]{0,40}</[^>]+>\s*(?:<br\s*/?>\s*)*<(?:select|ul|div|fieldset)[^>]*>(.*?)</(?:select|ul|div|fieldset)>#iu',
            $html,
            $m
        )) {
            foreach ($m[1] as $block) {
                $blocks[] = (string) $block;
            }
        }
        // IdoSell / Sote: select2 bez „rozmiar” w class/id (etykieta w sąsiedniej komórce).
        if (preg_match_all(
            '#<select[^>]*(?:select-field-select2|core_parseOption|select2-hidden-accessible)[^>]*>(.*?)</select>#is',
            $html,
            $m
        )) {
            foreach ($m[1] as $block) {
                $blocks[] = (string) $block;
            }
        }
        if (preg_match_all('#<select[^>]*>(.*?)</select>#is', $html, $m)) {
            foreach ($m[1] as $block) {
                $blocks[] = (string) $block;
            }
        }
        // IdoSell: <div id="opcja_22243_20_0"> + <label for="atrybuty_…">36</label>
        if (preg_match_all('#<div[^>]+id=["\']opcja_\d[^"\']*["\'][^>]*>(.*?)</div>#is', $html, $m)) {
            foreach ($m[1] as $block) {
                $blocks[] = (string) $block;
            }
        }
        if (preg_match_all(
            '#<label[^>]+(?:for|id)=["\']atrybuty_[^"\']+["\'][^>]*>\s*([^<]{1,16})\s*</label>#iu',
            $html,
            $m
        )) {
            $labels = [];
            foreach ($m[1] as $raw) {
                $labels[] = '<label>'.trim((string) $raw).'</label>';
            }
            if ($labels !== []) {
                $blocks[] = implode('', $labels);
            }
        }

        return $blocks;
    }

    /**
     * @return list<string>
     */
    private function tokensFromShopBlock(string $block): array
    {
        $found = [];
        if (preg_match_all(
            '#<(?:option|label|button|a|span|li|td)[^>]*>\s*([^<]{1,16})\s*</#iu',
            $block,
            $m
        )) {
            foreach ($m[1] as $raw) {
                $norm = $this->normalizeSizeToken(trim((string) $raw));
                if ($norm !== null && ! in_array($norm, $found, true)) {
                    $found[] = $norm;
                }
            }
        }
        if (preg_match_all('/\b(?:data-size|data-value)=["\']([^"\']+)["\']/iu', $block, $m)) {
            foreach ($m[1] as $raw) {
                $norm = $this->normalizeSizeToken(trim((string) $raw));
                if ($norm !== null && ! in_array($norm, $found, true)) {
                    $found[] = $norm;
                }
            }
        }

        return count($found) >= 2 ? $found : [];
    }

    /**
     * JET3SPNO36 / JET3SPNO47 — wariant rozmiaru w kodzie producenta.
     *
     * @return list<string>
     */
    private function sizesFromManufacturerCodes(string $text): array
    {
        if (preg_match_all('/\b[A-Z][A-Z0-9]{2,28}[A-Z](3[2-9]|4[0-9]|5[0-2])\b/', $text, $m) < 1) {
            return [];
        }
        $found = [];
        foreach ($m[1] as $size) {
            if (! in_array($size, $found, true)) {
                $found[] = $size;
            }
        }
        if (count($found) < 4) {
            return [];
        }
        $nums = array_map(static fn (string $s): int => (int) $s, $found);
        if (max($nums) - min($nums) > 20) {
            return [];
        }

        return $this->uniqueSortedSizes($found);
    }

    /**
     * A5016/06 … A5016/11 — wariant rozmiaru po ukośniku, nie w opisie.
     *
     * @return list<string>
     */
    private function sizesFromVariantSkuSuffixes(string $text): array
    {
        $plain = html_entity_decode(strip_tags($text), ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $hay = $text."\n".$plain;
        if (preg_match_all('/\b([A-Z][A-Z0-9]{2,20})[\/\-]0?(\d{1,2})(?![0-9])/', $hay, $m) < 1) {
            return [];
        }
        $byPrefix = [];
        foreach ($m[1] as $i => $prefix) {
            $norm = $this->normalizeSizeToken((string) $m[2][$i]);
            if ($norm === null) {
                continue;
            }
            if (! isset($byPrefix[$prefix])) {
                $byPrefix[$prefix] = [];
            }
            if (! in_array($norm, $byPrefix[$prefix], true)) {
                $byPrefix[$prefix][] = $norm;
            }
        }
        $best = [];
        foreach ($byPrefix as $list) {
            if (count($list) >= 3 && count($list) > count($best)) {
                $best = $list;
            }
        }

        return $this->uniqueSortedSizes($best);
    }

    /**
     * @param  list<string>  $sizes
     * @return list<string>
     */
    private function uniqueSortedSizes(array $sizes): array
    {
        $sizes = array_values(array_unique(array_filter(
            $sizes,
            static fn (string $s): bool => trim($s) !== ''
        )));
        usort($sizes, function (string $a, string $b): int {
            $na = is_numeric($a);
            $nb = is_numeric($b);
            if ($na && $nb) {
                return ((float) $a <=> (float) $b);
            }
            if ($na !== $nb) {
                return $na ? -1 : 1;
            }
            $ia = array_search($a, self::ALPHA_ORDER, true);
            $ib = array_search($b, self::ALPHA_ORDER, true);
            if (is_int($ia) && is_int($ib)) {
                return $ia <=> $ib;
            }

            return $a <=> $b;
        });

        return $sizes;
    }

    /**
     * @param  list<string>  $sizes
     */
    private function compactContiguous(array $sizes): ?string
    {
        if (count($sizes) < 2) {
            return null;
        }
        if ($this->areNumericContiguous($sizes)) {
            return $sizes[0].'-'.$sizes[array_key_last($sizes)];
        }
        if ($this->areAlphaContiguous($sizes)) {
            return $sizes[0].'-'.$sizes[array_key_last($sizes)];
        }

        return null;
    }

    /**
     * @param  list<string>  $sizes
     */
    private function areNumericContiguous(array $sizes): bool
    {
        $nums = [];
        foreach ($sizes as $size) {
            if (preg_match('/^\d{1,2}(?:\.\d)?$/', $size) !== 1) {
                return false;
            }
            $nums[] = (float) $size;
        }
        $step = $this->numericStep($nums[0], $nums[array_key_last($nums)]);
        for ($i = 1, $n = count($nums); $i < $n; $i++) {
            if (abs(($nums[$i] - $nums[$i - 1]) - $step) > 0.001) {
                return false;
            }
        }

        return true;
    }

    /**
     * @param  list<string>  $sizes
     */
    private function areAlphaContiguous(array $sizes): bool
    {
        $idx = [];
        foreach ($sizes as $size) {
            $i = array_search($size, self::ALPHA_ORDER, true);
            if (! is_int($i)) {
                return false;
            }
            $idx[] = $i;
        }
        for ($i = 1, $n = count($idx); $i < $n; $i++) {
            if ($idx[$i] !== $idx[$i - 1] + 1) {
                return false;
            }
        }

        return true;
    }

    private function isPlausibleNumericRange(float $from, float $to): bool
    {
        $glove = $from >= 4 && $to <= 16;
        $shoe = $from >= 32 && $to <= 52;
        $cloth = $from >= 40 && $to <= 78;
        if (! $glove && ! $shoe && ! $cloth) {
            return false;
        }
        if ($glove && ($to - $from) > 12) {
            return false;
        }
        if (! $glove && ($to - $from) > 28) {
            return false;
        }

        return true;
    }

    private function numericStep(float $from, float $to): float
    {
        if (fmod($from, 1.0) !== 0.0 || fmod($to, 1.0) !== 0.0) {
            return 0.5;
        }
        $a = (int) $from;
        $b = (int) $to;
        if ($a >= 44 && $b >= 54 && $a % 2 === 0 && $b % 2 === 0) {
            return 2.0;
        }

        return 1.0;
    }

    private function isHalfPair(float $from, float $to): bool
    {
        if ($from < 4 || $to > 16 || $from === $to) {
            return false;
        }

        return ($to - $from) <= 1.0001
            && (fmod($from, 1.0) !== 0.0 || fmod($to, 1.0) !== 0.0);
    }

    private function formatNumeric(float $n): string
    {
        return fmod($n, 1.0) === 0.0 ? (string) (int) $n : (string) $n;
    }

    private function sizeFromName(string $name): ?string
    {
        if (preg_match(
            '/(?:size|rozmiar|taille|rozm\.?)\s*:?\s*(\d{1,2}(?:[.,]\d)?|[2-6]\s*xl|xxxxl|xxxl|xxl|xl|xxs|xs|s|m|l)\b/iu',
            $name,
            $m
        ) === 1) {
            return $this->normalizeSizeToken($m[1]);
        }
        if (preg_match('/(?:^|[\s,\/])(\d{1,2}(?:[.,]\d)?)\s*$/u', $name, $m) === 1) {
            return $this->normalizeSizeToken($m[1]);
        }

        return null;
    }

    private function sizeFromSku(string $sku): ?string
    {
        if (preg_match('/[A-Za-z](\d{3})$/', $sku, $m) === 1) {
            return $this->sizeForDigitCode($m[1]);
        }
        if (preg_match('/^\d{5,}(\d{3})$/', $sku, $m) === 1) {
            return $this->sizeForDigitCode($m[1]);
        }
        if (preg_match('/[\/\-_]([A-Za-z0-9]+(?:[.,]\d)?)$/', $sku, $m) === 1) {
            return $this->looksLikeWearSize($m[1]) ? $this->normalizeSizeToken($m[1]) : null;
        }

        return null;
    }

    private function normalizeSizeToken(string $raw): ?string
    {
        $t = mb_strtolower(trim(str_replace(',', '.', $raw)));
        $t = preg_replace('/\s+/', '', $t) ?? $t;
        if ($t === '') {
            return null;
        }
        $t = match ($t) {
            '2xl' => 'xxl',
            '3xl' => 'xxxl',
            '4xl' => 'xxxxl',
            default => $t,
        };
        if (preg_match('/^(xxxxl|xxxl|xxl|xl|xxs|xs|s|m|l|[2-6]xl|onesize)$/', $t) === 1) {
            return $t;
        }
        if (preg_match('/^\d{1,2}(?:\.\d)?$/', $t) === 1) {
            $n = (float) $t;
            if ($n >= 4 && $n <= 16) {
                return fmod($n, 1.0) === 0.0 ? (string) (int) $n : (string) $n;
            }
            if (fmod($n, 1.0) === 0.0 && $n >= 32 && $n <= 78) {
                return (string) (int) $n;
            }
        }

        return null;
    }

    private function sizeForDigitCode(string $code): ?string
    {
        foreach (self::DIGIT_CODE_SIZES as $digits => $label) {
            if (sprintf('%03d', $digits) === $code || (string) $digits === $code) {
                return $label;
            }
        }

        return null;
    }

    private function sizeToSkuSuffix(string $size): ?string
    {
        foreach (self::DIGIT_CODE_SIZES as $digits => $label) {
            if ($label === $size) {
                return sprintf('%03d', $digits);
            }
        }

        return null;
    }

    private function isUsableCore(string $core): bool
    {
        $core = rtrim($core, "-/_ \t");

        return mb_strlen($core) >= 3;
    }
}
