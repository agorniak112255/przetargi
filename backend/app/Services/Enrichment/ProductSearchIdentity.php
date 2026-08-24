<?php

declare(strict_types=1);

namespace App\Services\Enrichment;

use App\Models\Product;

/**
 * Jedna tożsamość produktu do wyszukiwania i filtrowania wyników.
 * SKU w cenniku bywa „PROS-1001”, a w sieci „1001” / „101/001” / „model 1001”.
 */
final class ProductSearchIdentity
{
    /**
     * Tokeny do dopasowania w URL/tytule/snippecie (lowercase, unikalne).
     *
     * @return list<string>
     */
    public function matchTokens(Product $product): array
    {
        $sku = trim((string) $product->sku);
        $name = trim((string) $product->name);
        $brand = $this->shortBrand((string) $product->manufacturer);

        $raw = [];
        if ($sku !== '') {
            $raw[] = $sku;
            $raw[] = $this->stripBrandPrefix($sku, $brand);
        }
        if ($name !== '' && mb_strtolower($name) !== mb_strtolower($sku)) {
            $raw[] = $name;
            $raw[] = $this->stripBrandPrefix($name, $brand);
        }

        $out = [];
        foreach ($raw as $value) {
            $value = trim($value);
            if ($value === '') {
                continue;
            }
            $low = mb_strtolower($value);
            $out[] = $low;
            $compact = preg_replace('/[^a-z0-9]+/iu', '', $low) ?? $low;
            if ($compact !== '' && $compact !== $low) {
                $out[] = $compact;
            }
            // 101/001 → 101-001
            $slashToDash = str_replace('/', '-', $low);
            if ($slashToDash !== $low) {
                $out[] = $slashToDash;
            }
            // sam rdzeń cyfrowy (≥3) z kodu typu PROS-1001 / 101/001
            if (preg_match_all('/\d{3,}/u', $low, $m)) {
                foreach ($m[0] as $digits) {
                    $out[] = $digits;
                }
            }
        }

        foreach ($this->modelAliases($product) as $alias) {
            $out[] = $alias;
        }

        $out = array_values(array_unique(array_filter(
            $out,
            static fn (string $t): bool => $t !== '' && mb_strlen($t) >= 3
        )));

        // za krótkie same „100” itd. — zostaw tylko gdy nie ma lepszych
        return $out !== [] ? $out : array_values(array_filter([mb_strtolower($sku), mb_strtolower($name)]));
    }

    /**
     * Zdjęcie z HTML karty — SKU/model w URL ALBO zaufana galeria producenta (uvex shop-media).
     * Nigdy: Tavily, LLM, lego.com/product/… z przypadkowym „c500”.
     */
    public function isTrustedPageImageUrl(string $url, Product $product): bool
    {
        if (! ProductImageDownloader::looksLikeImageUrl($url)) {
            return false;
        }
        $hay = mb_strtolower(urldecode($url).' '.$this->decodeEmbeddedUrls($url));
        if ($this->looksLikeJunkMediaPath($hay)) {
            return false;
        }
        // twarde: SKU cyfrowe / alias modelu w URL
        if ($this->imageUrlMentionsProduct($url, $product)) {
            return true;
        }
        // uvex/Ansell CDN galerii — tylko typowe ścieżki mediów produktu (bez słowa „product” w dowolnym sklepie)
        return $this->looksLikeManufacturerGalleryUrl($url, $product);
    }

    /**
     * URL zdjęcia musi wskazywać produkt (SKU/model) — bez samego słowa „product” (LEGO!).
     */
    public function imageUrlMentionsProduct(string $url, Product $product): bool
    {
        $hay = mb_strtolower(urldecode($url));
        $hay .= ' '.$this->decodeEmbeddedUrls($hay);
        $hayCompact = preg_replace('/[^a-z0-9]+/iu', '', $hay) ?? $hay;

        if ($this->looksLikeJunkMediaPath($hay)) {
            return false;
        }

        $sku = mb_strtolower(trim((string) $product->sku));
        $skuCompact = preg_replace('/[^a-z0-9]+/iu', '', $sku) ?? $sku;
        // NB27 ≠ NB27B / NB27S — dłuższy wariant w URL = inny produkt (nawet gdy w nazwie jest „rubiflex”)
        if ($this->urlContainsLongerAlphanumericSkuVariant($hay, $skuCompact)) {
            return false;
        }
        // pełny SKU w nazwie pliku (glove-ABC123.jpg) — bez wymogu „product” w hoście
        if ($sku !== '' && mb_strlen($sku) >= 4 && $this->skuTokenInImageHay($hay, $hayCompact, $sku, $skuCompact)) {
            return true;
        }

        foreach ($this->strongImageTokens($product) as $token) {
            if ($token === '' || mb_strlen($token) < 3) {
                continue;
            }
            // same cyfry SKU: 60544 OK w …6054407…
            if (preg_match('/^\d{4,}$/', $token) === 1) {
                if (preg_match('/(?<![0-9])'.preg_quote($token, '/').'/u', $hay) === 1
                    || preg_match('/(?<![0-9])'.preg_quote($token, '/').'/u', $hayCompact) === 1) {
                    return true;
                }

                continue;
            }
            if (preg_match('/^r-?\d{2,4}g?$/i', $token) === 1
                && (str_contains($hay, $token) || str_contains($hayCompact, preg_replace('/[^a-z0-9]/i', '', $token) ?? $token))) {
                return true;
            }
            // alfanumeryczny kod (NB27, C300): granica tokenu, nie substring
            if ($this->isAlphanumericProductCode($token)) {
                $tokenCompact = preg_replace('/[^a-z0-9]+/iu', '', $token) ?? $token;
                if ($this->skuTokenInImageHay($hay, $hayCompact, $token, $tokenCompact)) {
                    return true;
                }

                continue;
            }
            // model (ringers, rubiflex) — TYLKO ze ścieżką BHP/CDN, nigdy sam „…/product/…” (LEGO)
            if (mb_strlen($token) >= 4 && (str_contains($hay, $token) || str_contains($hayCompact, $token))) {
                if (preg_match('#(glove|handschuh|rekaw|shop-media|product-assets|fileadmin/.+products|pim/products|media/catalog/product)#i', $hay) === 1) {
                    return true;
                }
            }
        }

        $brand = mb_strtolower($this->shortBrand((string) $product->manufacturer));
        if ($brand !== '' && str_contains($hay, $brand) && str_contains($hay, 'product-assets')) {
            foreach ($this->modelAliases($product) as $alias) {
                if (str_contains($hay, $alias) || str_contains($hayCompact, preg_replace('/[^a-z0-9]/i', '', $alias) ?? $alias)) {
                    return true;
                }
            }
        }

        return false;
    }

    /** Galeria z CDN producenta (hash bez SKU) — tylko znane hosty/marki. */
    public function looksLikeManufacturerGalleryUrl(string $url, Product $product): bool
    {
        $u = mb_strtolower(urldecode($url).' '.$this->decodeEmbeddedUrls($url));
        if ($this->looksLikeJunkMediaPath($u)) {
            return false;
        }
        $host = mb_strtolower((string) (parse_url($url, PHP_URL_HOST) ?? ''));
        $brand = mb_strtolower($this->shortBrand((string) $product->manufacturer));

        // uvex CloudFront shop-media / fileadmin produktów
        if (str_contains($host, 'cloudfront.net') || str_contains($host, 'uvex')) {
            return str_contains($u, 'shop-media')
                || (str_contains($u, 'fileadmin') && str_contains($u, 'product') && ! str_contains($u, 'menue'));
        }
        // Ansell PIM
        if (str_contains($host, 'ansell') || ($brand === 'ansell' && str_contains($u, 'product-assets'))) {
            return str_contains($u, 'product-assets') || str_contains($u, '/-/media/');
        }
        if (str_contains($host, 'urgent.com.pl') || str_contains($host, 'urgent.pl')) {
            return str_contains($u, 'wp-content')
                || str_contains($u, 'upload')
                || str_contains($u, 'product')
                || preg_match('/(?<![0-9])\d{3,4}(?![0-9])/u', $u) === 1;
        }
        // Magento / Presta typowe galerie
        if (str_contains($u, 'media/catalog/product') || str_contains($u, 'large_default') || str_contains($u, 'pim/products')) {
            return true;
        }

        return false;
    }

    /** @deprecated użyj looksLikeManufacturerGalleryUrl */
    public function looksLikeProductGalleryUrl(string $url): bool
    {
        $u = mb_strtolower(urldecode($url).' '.$this->decodeEmbeddedUrls($url));
        if ($this->looksLikeJunkMediaPath($u)) {
            return false;
        }

        return str_contains($u, 'shop-media')
            || str_contains($u, 'media/catalog/product')
            || str_contains($u, 'pim/products')
            || str_contains($u, 'product-assets')
            || str_contains($u, 'large_default');
    }


    /**
     * NB27 w URL z NB27B/NB27S — to inny wariant, nie substring match.
     */
    private function urlContainsLongerAlphanumericSkuVariant(string $hay, string $skuCompact): bool
    {
        if ($skuCompact === '' || mb_strlen($skuCompact) < 3) {
            return false;
        }
        // tylko kody mieszane (litery+cyfry); czyste cyfry mają sufiksy rozmiaru (60544→6054407)
        if (! $this->isAlphanumericProductCode($skuCompact)) {
            return false;
        }

        return preg_match(
            '/(?<![a-z0-9])'.preg_quote($skuCompact, '/').'[a-z0-9]+/iu',
            $hay
        ) === 1;
    }

    /**
     * Dopasowanie SKU w URL zdjęcia: alfanumeryczne jako cały token; cyfrowe z dozwolonym sufiksem rozmiaru.
     */
    private function skuTokenInImageHay(string $hay, string $hayCompact, string $sku, string $skuCompact): bool
    {
        if ($sku === '' && $skuCompact === '') {
            return false;
        }

        if ($skuCompact !== '' && preg_match('/^\d+$/', $skuCompact) === 1) {
            return preg_match('/(?<![0-9])'.preg_quote($skuCompact, '/').'/u', $hay) === 1
                || preg_match('/(?<![0-9])'.preg_quote($skuCompact, '/').'/u', $hayCompact) === 1;
        }

        foreach (array_unique(array_filter([$sku, $skuCompact])) as $token) {
            if (preg_match('/(?<![a-z0-9])'.preg_quote($token, '/').'(?![a-z0-9])/iu', $hay) === 1) {
                return true;
            }
        }

        if ($skuCompact !== ''
            && preg_match('/(?<![a-z0-9])'.preg_quote($skuCompact, '/').'(?![a-z0-9])/iu', $hayCompact) === 1) {
            return true;
        }

        return false;
    }

    private function isAlphanumericProductCode(string $token): bool
    {
        $compact = preg_replace('/[^a-z0-9]+/iu', '', mb_strtolower($token)) ?? '';
        if ($compact === '' || mb_strlen($compact) < 3) {
            return false;
        }
        // litery i cyfry (NB27, C300) — nie same litery (rubiflex) ani same cyfry (60544)
        return preg_match('/[a-z]/u', $compact) === 1
            && preg_match('/\d/u', $compact) === 1;
    }

    private function looksLikeJunkMediaPath(string $hay): bool
    {
        return str_contains($hay, 'menue')
            || str_contains($hay, 'menu-neuheit')
            || str_contains($hay, 'menukachel')
            || str_contains($hay, '01_menue')
            || str_contains($hay, 'lego')
            || str_contains($hay, 'beer')
            || str_contains($hay, 'world-map');
    }

    private function decodeEmbeddedUrls(string $hay): string
    {
        $out = [];
        if (preg_match_all('#(?:^|/)([A-Za-z0-9_\-+/=]{24,})(?:\?|$)#', $hay, $m)) {
            foreach ($m[1] as $chunk) {
                $raw = strtr((string) $chunk, '-_', '+/');
                $pad = strlen($raw) % 4;
                if ($pad > 0) {
                    $raw .= str_repeat('=', 4 - $pad);
                }
                $decoded = base64_decode($raw, true);
                if (is_string($decoded) && $decoded !== '' && (str_contains($decoded, 'http') || str_contains($decoded, 'media') || str_contains($decoded, 'fileadmin'))) {
                    $out[] = mb_strtolower($decoded);
                }
            }
        }

        return implode(' ', $out);
    }

    /**
     * @return list<string>
     */
    public function strongImageTokens(Product $product): array
    {
        $tokens = [];
        $sku = mb_strtolower(trim((string) $product->sku));
        $name = mb_strtolower(trim((string) $product->name));

        if ($sku !== '') {
            $tokens[] = $sku;
            $tokens[] = preg_replace('/[^a-z0-9]+/iu', '', $sku) ?? $sku;
        }
        foreach ($this->modelAliases($product) as $alias) {
            $tokens[] = $alias;
        }
        // nazwa handlowa: c300, ringers, maxiflex…
        foreach (preg_split('/[\s\-®™\/_]+/u', $name) ?: [] as $part) {
            $part = trim($part);
            if ($part === '' || mb_strlen($part) < 4) {
                continue;
            }
            if (in_array($part, ['size', 'rozmiar', 'gloves', 'glove', 'rekawice', 'rękawice', 'foam', 'with'], true)) {
                continue;
            }
            $tokens[] = $part;
        }
        $core = $this->gloveCodeCore($product);
        if ($core !== null) {
            $tokens[] = $core;
        }

        return array_values(array_unique(array_filter($tokens)));
    }

    /**
     * Alias modeli (Ansell 065-06 / Ringers 065 → r065, r-065, 065g).
     *
     * @return list<string>
     */
    public function modelAliases(Product $product): array
    {
        $brand = mb_strtolower($this->shortBrand((string) $product->manufacturer));
        $sku = mb_strtolower(trim((string) $product->sku));
        $name = mb_strtolower(trim((string) $product->name));
        $blob = trim($sku.' '.$name);
        $out = [];

        // RINGERS R065 / Ringers 065 / ringers-r065
        if (preg_match('/\bringers?\b/u', $blob) === 1
            && preg_match('/\br?[\s\-]?0*(\d{2,3})\b/u', $blob, $m) === 1) {
            $n = ltrim($m[1], '0');
            if ($n === '') {
                $n = $m[1];
            }
            $n = str_pad($n, 3, '0', STR_PAD_LEFT);
            // R065 → 065
            if (preg_match('/\br[\s\-]?0*(\d{2,3})\b/u', $blob, $rm) === 1) {
                $n = str_pad(ltrim($rm[1], '0') ?: $rm[1], 3, '0', STR_PAD_LEFT);
            }
            $out[] = 'r'.$n;
            $out[] = 'r-'.$n;
            $out[] = $n.'g';
            $out[] = 'ringers';
        }

        // Ansell size SKU 065-06 → artykuł 065
        if (($brand === 'ansell' || str_contains($blob, 'ansell') || str_contains($blob, 'ringers'))
            && preg_match('/^(\d{2,3})-(\d{2})$/', $sku, $m) === 1) {
            $art = str_pad($m[1], 3, '0', STR_PAD_LEFT);
            $out[] = 'r'.$art;
            $out[] = 'r-'.$art;
            $out[] = $art.'g';
            $out[] = $art;
        }

        // uvex C300 / HyFlex w nazwie już w strongImageTokens

        return array_values(array_unique($out));
    }

    /**
     * Zapytania Tavily w kolejności priorytetu.
     *
     * @return list<string>
     */
    public function searchQueries(Product $product, string $phase): array
    {
        $brand = $this->shortBrand((string) $product->manufacturer);
        $sku = trim((string) $product->sku);
        $name = trim((string) $product->name);
        $bare = $this->stripBrandPrefix($sku !== '' ? $sku : $name, $brand);
        // nazwa „1000 ZIMA” → rdzeń kodu 1000
        $codeCore = $this->gloveCodeCore($product) ?? $bare;
        $hint = $this->productHint($product);
        $phaseHint = $phase === 'industry' ? 'karta produktu' : 'datasheet OR karta';

        $queries = [];

        // 0) Seria rękawic URGENT (często źle zaimportowana jako PROS-1000)
        if ($this->looksLikeUrgentGloveSeries($product) && $codeCore !== '') {
            $queries[] = 'Urgent '.$codeCore.' rękawice';
            $queries[] = 'URGENT '.$codeCore;
            $queries[] = '"'.$codeCore.'" Urgent rękawice robocze';
            $queries[] = 'rękawice lateksem '.$codeCore.' Urgent';
        }

        // Oficjalna karta uvex musi zmieścić się w limitach eco/balanced (1–2 zapytania).
        // Kod bazowy 60497 występuje w slugu z rozmiarem, np. 6049706.
        if ($phase === 'manufacturer'
            && preg_match('/^\d{4,6}$/', $sku) === 1
            && str_contains(mb_strtolower($brand), 'uvex')) {
            $queries[] = 'site:uvex-safety.com '.$sku.' glove OR handschuh';
            $queries[] = 'site:uvex-safety.com/products '.$sku;
        }

        $queries[] = $this->productNameWithManufacturer($product);

        // 1) Jak Google — kod / nazwa zawsze z producentem
        if ($sku !== '') {
            $queries[] = $this->queryWithManufacturer($sku, $product);
            $queries[] = $this->queryWithManufacturer('"'.$sku.'"', $product);
        }
        // Ansell R065 / uvex model z aliasów
        foreach ($this->modelAliases($product) as $alias) {
            if (preg_match('/^r-?\d{2,4}/i', $alias) === 1 || preg_match('/^\d{3}g$/i', $alias) === 1) {
                $queries[] = trim($brand.' '.$alias);
                $queries[] = '"'.$alias.'" '.$brand;
            }
        }
        if ($brand !== '' && $bare !== '') {
            $queries[] = trim($brand.' '.$bare);
            if ($bare !== $sku) {
                $queries[] = trim('"'.$bare.'" '.$brand);
            }
        }
        if ($name !== '' && mb_strtolower($name) !== mb_strtolower($sku)
            && mb_strtolower($name) !== mb_strtolower($bare)) {
            $queries[] = trim($brand.' '.$name);
        }

        // 2) Dopiero potem warianty z hintem (gdy nazwa sugeruje kategorię)
        if ($hint !== '') {
            if ($brand !== '' && $bare !== '') {
                $queries[] = trim($brand.' '.$bare.' '.$hint);
            }
            if ($codeCore !== '' && $hint === 'rękawice') {
                $queries[] = trim($codeCore.' '.$hint);
            }
            if ($sku !== '') {
                $queries[] = trim('"'.$sku.'" '.$brand.' '.$hint);
            }
        }

        // 3) Datasheet / karta
        if ($bare !== '') {
            $queries[] = trim($brand.' '.$bare.' '.$phaseHint);
            $queries[] = trim($brand.' model '.$bare);
        }

        return array_values(array_unique(array_filter(
            array_map(
                fn (string $q): string => $this->queryWithManufacturer($q, $product),
                $queries
            ),
            static fn (string $q): bool => trim($q) !== ''
        )));
    }

    public function productNameWithManufacturer(Product $product): string
    {
        $name = trim((string) $product->name);
        $sku = trim((string) $product->sku);
        $parts = [];
        if ($name !== '') {
            $parts[] = $name;
        }
        if ($sku !== '' && ($name === '' || ! $this->phraseHasToken($name, $sku))) {
            $parts[] = $sku;
        }

        $phrase = $this->queryWithManufacturer(trim(implode(' ', $parts)), $product);
        $hint = $this->productHint($product);
        if ($hint !== '') {
            foreach (preg_split('/\s+/u', $hint) ?: [] as $word) {
                if ($word !== '' && ! $this->phraseHasToken($phrase, $word)) {
                    $phrase .= ' '.$word;
                }
            }
        }
        if (! $this->phraseHasToken($phrase, 'bhp')) {
            $phrase .= ' BHP';
        }

        return trim($phrase);
    }

    public function queryWithManufacturer(string $query, Product $product): string
    {
        $query = trim((string) preg_replace('/\s+/u', ' ', $query));
        $brand = $this->shortBrand((string) $product->manufacturer);
        if ($query === '' || $brand === '' || $this->phraseHasToken($query, $brand)) {
            return $query;
        }

        return $query.' '.$brand;
    }

    private function phraseHasToken(string $hay, string $token): bool
    {
        $hay = mb_strtolower($hay);
        $token = mb_strtolower(trim($token));
        if ($token === '' || mb_strlen($token) < 2) {
            return false;
        }

        return preg_match('/(^|[^a-z0-9])'.preg_quote($token, '/').'([^a-z0-9]|$)/iu', $hay) === 1;
    }

    /**
     * Czy tekst wyniku dotyczy tego produktu.
     */
    public function hayMentionsProduct(string $hay, Product $product): bool
    {
        $hay = mb_strtolower($hay);
        $brands = $this->acceptedBrands($product);
        $tokens = $this->matchTokens($product);
        $hayCompact = preg_replace('/[^a-z0-9]+/iu', '', $hay) ?? $hay;
        $hayDigits = preg_replace('/\D+/u', '', $hay) ?? '';

        $skuCompact = preg_replace('/[^a-z0-9]+/iu', '', mb_strtolower(trim((string) $product->sku))) ?? '';
        // NB27 ≠ NB27B — karta dłuższego wariantu nie może przejść przez nazwę „rubiflex”
        if ($this->urlContainsLongerAlphanumericSkuVariant($hay, $skuCompact)) {
            return false;
        }

        foreach ($tokens as $token) {
            if ($this->tokenInHay($hay, $hayCompact, $token)) {
                // krótki sam kod numeryczny → wymagaj marki (własnej lub URGENT przy serii rękawic)
                if ($this->isShortNumericToken($token) && $brands !== []
                    && ! $this->hayHasAnyBrand($hay, $hayCompact, $brands)) {
                    continue;
                }

                return true;
            }
        }

        // PROS-1001 vs „101/001”: cyfry z wyniku zawierają rdzeń ≥4 cyfr
        // ale NIE traktuj „1000g” / „500ml” jako kodu produktu
        $digitCore = $this->primaryDigitCore($product);
        if ($digitCore !== null && mb_strlen($digitCore) >= 4 && $hayDigits !== ''
            && str_contains($hayDigits, $digitCore)
            && ! $this->numericTokenOnlyAsMeasurement($hay, $digitCore)) {
            if ($brands === [] || $this->hayHasAnyBrand($hay, $hayCompact, $brands)) {
                return true;
            }
        }

        // karta URGENT …-1000-URGENT… przy błędnym producencie PROS w cenniku
        if ($this->looksLikeUrgentGloveSeries($product) && str_contains($hay, 'urgent')) {
            $code = $this->gloveCodeCore($product);
            if ($code !== null && $this->numericTokenAsProductCode($hay, $code)) {
                return true;
            }
        }

        return false;
    }

    public function coreInUrlOrTitle(string $url, string $title, Product $product): bool
    {
        return $this->hayMentionsProduct($url.' '.$title, $product);
    }

    public function preferredLocaleUrl(string $url, Product $product): string
    {
        $brand = mb_strtolower($this->shortBrand((string) $product->manufacturer));
        if (! str_contains($brand, 'ansell')) {
            return $url;
        }

        return preg_replace(
            '#^(https?://(?:www\.)?ansell\.com)/[a-z]{2}/[a-z]{2}/(?=products/)#i',
            '$1/pl/pl/',
            $url
        ) ?? $url;
    }

    public function shortBrand(string $manufacturer): string
    {
        $m = trim($manufacturer);
        if ($m === '') {
            return '';
        }
        $first = trim(explode('/', $m)[0] ?? $m);
        $first = trim(explode('(', $first)[0] ?? $first);

        return mb_substr($first, 0, 40);
    }

    public function stripBrandPrefix(string $code, string $brand): string
    {
        $code = trim($code);
        $brand = trim($brand);
        if ($code === '' || $brand === '') {
            return $code;
        }
        $pattern = '/^'.preg_quote($brand, '/').'[\s\-_\/]+/iu';
        $stripped = trim((string) preg_replace($pattern, '', $code));

        return $stripped !== '' ? $stripped : $code;
    }

    private function tokenInHay(string $hay, string $hayCompact, string $token): bool
    {
        if ($token === '') {
            return false;
        }
        // same cyfry: 1000 ≠ 1000g / 1000ml / 21000
        if (preg_match('/^\d{3,}$/', $token) === 1) {
            return $this->numericTokenAsProductCode($hay, $token)
                || $this->numericTokenAsProductCode($hayCompact, $token);
        }
        // alfanumeryczny kod (NB27): cały token — nie substring w NB27B
        if ($this->isAlphanumericProductCode($token)) {
            $tokenCompact = preg_replace('/[^a-z0-9]+/iu', '', $token) ?? $token;

            return $this->skuTokenInImageHay($hay, $hayCompact, $token, $tokenCompact);
        }
        if (str_contains($hay, $token)) {
            return true;
        }
        $tokenCompact = preg_replace('/[^a-z0-9]+/iu', '', $token) ?? $token;
        if ($tokenCompact === '' || ! str_contains($hayCompact, $tokenCompact)) {
            return false;
        }
        // „pros1000” w hayCompact z „PROS 1000g” — wymagaj kodu bez jednostki
        if (preg_match('/^[a-z]+(\d{3,6})$/i', $tokenCompact, $m) === 1) {
            return $this->numericTokenAsProductCode($hay, $m[1])
                || $this->numericTokenAsProductCode($hayCompact, $m[1]);
        }

        return true;
    }

    /**
     * Kod numeryczny jako samodzielny token — nie gramatura/jednostka (1000g, 500ml).
     */
    private function numericTokenAsProductCode(string $hay, string $token): bool
    {
        if ($token === '' || $hay === '') {
            return false;
        }
        // po kodzie nie może być cyfra ani litera jednostki (g/kg/ml…)
        return preg_match(
            '/(?<![0-9])'.preg_quote($token, '/').'(?![0-9a-z])/iu',
            $hay
        ) === 1;
    }

    /** Wszystkie wystąpienia rdzenia w tekście to tylko „1000g”, „500ml” itd. */
    private function numericTokenOnlyAsMeasurement(string $hay, string $token): bool
    {
        $count = preg_match_all(
            '/(?<![0-9])'.preg_quote($token, '/').'(?![0-9])/iu',
            $hay,
            $matches,
            PREG_OFFSET_CAPTURE
        );
        // brak bezpośredniego wystąpienia (np. tylko 101/001 → 101001 w hayDigits) — nie blokuj
        if ($count === 0 || ($matches[0] ?? []) === []) {
            return false;
        }

        foreach ($matches[0] as [$match, $offset]) {
            $after = mb_strtolower(mb_substr($hay, (int) $offset + mb_strlen((string) $match), 4));
            if (preg_match('/^(g|kg|ml|mm|cm|m)\b/u', $after) !== 1) {
                return false;
            }
        }

        return true;
    }

    private function isShortNumericToken(string $token): bool
    {
        return preg_match('/^\d{3,6}$/', $token) === 1;
    }

    private function primaryDigitCore(Product $product): ?string
    {
        foreach ([(string) $product->sku, (string) $product->name] as $value) {
            $bare = $this->stripBrandPrefix($value, $this->shortBrand((string) $product->manufacturer));
            if (preg_match('/(\d{4,})/u', $bare, $m)) {
                return $m[1];
            }
            $digits = preg_replace('/\D+/u', '', $bare) ?? '';
            if (mb_strlen($digits) >= 4) {
                return mb_substr($digits, 0, 8);
            }
        }

        return null;
    }

    /**
     * Opcjonalny dopisek kategorii — TYLKO gdy nazwa/SKU na to wskazuje.
     * Sam producent (np. „PROS”) nie narzuca „ubranie wodoochronne”.
     */
    private function productHint(Product $product): string
    {
        $nameSku = mb_strtolower(trim((string) $product->name.' '.(string) $product->sku));
        $category = mb_strtolower(trim((string) ($product->category ?? '')));
        $brand = mb_strtolower($this->shortBrand((string) $product->manufacturer));
        $blob = trim($nameSku.' '.$category);

        if (preg_match(
            '#(trzewik|p[oó]łbut|polbut|\bbuty\b|obuwie|\bs1\b|\bs3\b|\bsrc\b|\bhro\b|demar|befado)#u',
            $nameSku
        ) || preg_match('#(demar|befado)#u', $brand)) {
            return 'buty ochronne';
        }

        if (preg_match('#(glove|r[eę]kaw|maxiflex|maxicut|maxidry)#u', $blob)
            || preg_match('#^(atg|ansell|urgent)$#u', $brand)) {
            return 'rękawice';
        }

        // Odzież wodoochronna — słowa z nazwy, NIE sama marka PROS
        if (preg_match(
            '#(wodoochron|plavitex|kurtka|spodnie|ubranie|odzież|odziez|płaszcz|plaszcz)#u',
            $nameSku
        )) {
            return 'ubranie wodoochronne';
        }

        return '';
    }

    /**
     * Kody 1000–1xxx w kategorii rękawice — typowa seria URGENT (często źle jako PROS).
     */
    public function looksLikeUrgentGloveSeries(Product $product): bool
    {
        if ($this->productHint($product) !== 'rękawice') {
            return false;
        }
        $code = $this->gloveCodeCore($product);
        if ($code === null || preg_match('/^\d{3,4}$/', $code) !== 1) {
            return false;
        }
        $brand = mb_strtolower($this->shortBrand((string) $product->manufacturer));

        // już URGENT albo błędnie PROS / puste
        return $brand === '' || in_array($brand, ['pros', 'urgent', 'aj group', 'aj', 'pilne'], true);
    }

    public function gloveCodeCore(Product $product): ?string
    {
        $brand = $this->shortBrand((string) $product->manufacturer);
        foreach ([(string) $product->name, (string) $product->sku] as $value) {
            $bare = $this->stripBrandPrefix(trim($value), $brand);
            if (preg_match('/^(\d{3,4})\b/u', $bare, $m) === 1) {
                return $m[1];
            }
        }

        return $this->primaryDigitCore($product);
    }

    /**
     * @return list<string>
     */
    private function acceptedBrands(Product $product): array
    {
        $out = [];
        $main = mb_strtolower($this->shortBrand((string) $product->manufacturer));
        if ($main !== '') {
            $out[] = $main;
        }
        if ($this->looksLikeUrgentGloveSeries($product)) {
            $out[] = 'urgent';
        }

        return array_values(array_unique($out));
    }

    /**
     * @param  list<string>  $brands
     */
    private function hayHasAnyBrand(string $hay, string $hayCompact, array $brands): bool
    {
        foreach ($brands as $brand) {
            if ($brand === '') {
                continue;
            }
            if (str_contains($hay, $brand)) {
                return true;
            }
            $compact = preg_replace('/[^a-z0-9]+/iu', '', $brand) ?? $brand;
            if ($compact !== '' && str_contains($hayCompact, $compact)) {
                return true;
            }
        }

        return false;
    }
}
