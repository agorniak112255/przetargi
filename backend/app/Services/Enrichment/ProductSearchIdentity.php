<?php

declare(strict_types=1);

namespace App\Services\Enrichment;

use App\Models\Product;
use Illuminate\Support\Str;

/**
 * Jedna tożsamość produktu do wyszukiwania i filtrowania wyników.
 * SKU w cenniku bywa „PROS-1001”, a w sieci „1001” / „101/001” / „model 1001”.
 */
final class ProductSearchIdentity
{
    /** Prefiksy norm i certyfikatów — „EN 166” to nie oznaczenie modelu. */
    private const NORM_PREFIXES = ['en', 'iso', 'pn', 'din', 'ansi', 'astm', 'nfpa', 'ce', 'sr', 'nbr'];

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
        $core = $this->internalSkuCore($product);
        if ($core !== '') {
            $raw[] = $core;
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
                if ($this->numericTokenOutsideDimensions($hay, $token)
                    || $this->numericTokenOutsideDimensions($hayCompact, $token)) {
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
            return $this->numericTokenOutsideDimensions($hay, $skuCompact)
                || $this->numericTokenOutsideDimensions($hayCompact, $skuCompact);
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

    /**
     * Kod cyfrowy w URL zdjęcia, ale nie rozmiar miniatury (1202px-…, 1202x800).
     */
    private function numericTokenOutsideDimensions(string $hay, string $token): bool
    {
        if ($token === '' || $hay === '') {
            return false;
        }

        return preg_match(
            '/(?<![0-9])'.preg_quote($token, '/').'(?!\s*(?:px|x\s*\d))/iu',
            $hay
        ) === 1;
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
        $internalSku = $this->looksLikeInternalSku($product);
        $bare = $internalSku
            ? $this->internalSkuCore($product)
            : $this->stripBrandPrefix($sku !== '' ? $sku : $name, $brand);
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
        if ($sku !== '' && ! $internalSku) {
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
            if ($sku !== '' && ! $internalSku) {
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

    /**
     * Frazy, które realnie trafiają w kartę produktu — od najkrótszej.
     * „URG-914 Urgent” działa, „Kurtka ostrzegawcza URG-914 Urgent” też;
     * dopiski kategorii i BHP zostawiamy dopiero na dalsze próby.
     *
     * @return list<string>
     */
    public function primaryQueries(Product $product): array
    {
        $brand = $this->shortBrand((string) $product->manufacturer);
        $sku = trim((string) $product->sku);
        $name = trim((string) $product->name);
        $usableSku = $sku !== '' && ! $this->looksLikeInternalSku($product);
        // „PROS-121-S1-GUMA” to nasz kod złożony z opisu — w sieci działa dopiero
        // nazwa z producentem („121 S1 GUMA Urgent”), więc ona idzie pierwsza.
        $composedSku = $this->hasDescriptiveWordSegment($sku);

        $skuQueries = [];
        if ($usableSku) {
            $skuQueries[] = $this->queryWithManufacturer($sku, $product);
            $bare = $this->stripBrandPrefix($sku, $brand);
            if ($bare !== '' && $bare !== $sku) {
                $skuQueries[] = $this->queryWithManufacturer($bare, $product);
            }
        }

        $nameQueries = [];
        if ($name !== '' && mb_strtolower($name) !== mb_strtolower($sku)) {
            $nameQueries[] = $this->queryWithManufacturer(
                $usableSku && ! $composedSku && ! $this->phraseHasToken($name, $sku)
                    ? $name.' '.$sku
                    : $name,
                $product
            );
        }

        $out = $composedSku
            ? array_merge($nameQueries, $skuQueries)
            : array_merge($skuQueries, $nameQueries);
        // „URG-C-SPODNIE” w sklepie występuje jako „URG-C”, a „ERGOPRIMA45” jako „ERGOPRIMA”
        $core = $this->internalSkuCore($product);
        if ($core !== '' && mb_strtolower($core) !== mb_strtolower($sku)) {
            $out[] = $this->queryWithManufacturer($core, $product);
        }
        // „BLACK-FITT10” sprzedaje się jako „BLACK-FIT” — rozmiar w kodzie jest tylko nasz
        foreach ($this->skuSizeVariants($product) as $variant) {
            if (mb_strtolower($variant) !== mb_strtolower($sku)) {
                $out[] = $this->queryWithManufacturer($variant, $product);
            }
        }

        return array_values(array_unique(array_filter(
            $out,
            static fn (string $q): bool => trim($q) !== ''
        )));
    }

    /**
     * Człon będący zwykłym słowem („GUMA”, „ZIMA”, „BLUZA”) zdradza kod sklejony u nas —
     * wyszukiwarka takiego ciągu nie zna, choć numer modelu w środku jest prawdziwy.
     */
    private function hasDescriptiveWordSegment(string $sku): bool
    {
        $segments = array_values(array_filter(preg_split('/[\-\/ ]+/u', trim($sku)) ?: []));
        if (count($segments) < 2) {
            return false;
        }

        foreach ($segments as $segment) {
            if (preg_match('/^\p{L}{4,}$/u', $segment) === 1) {
                return true;
            }
        }

        return false;
    }

    public function productNameWithManufacturer(Product $product): string
    {
        $name = trim((string) $product->name);
        $sku = trim((string) $product->sku);
        $parts = [];
        if ($name !== '') {
            $parts[] = $name;
        }
        if ($sku !== '' && ! $this->looksLikeInternalSku($product)
            && ! $this->hasDescriptiveWordSegment($sku)
            && ($name === '' || ! $this->phraseHasToken($name, $sku))) {
            $parts[] = $sku;
        }
        if ($parts === [] && $sku !== '') {
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
        // nazwa „Maska MT 212/2” niesie już kod „MT-212-2” — doklejanie go psuje zapytanie
        if ($this->codeInText($hay, $token)) {
            return true;
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
                // kod będący zwykłym słowem („CASQUE” = kask po francusku) trafia w pół internetu
                if ($this->isWordLikeToken($token) && $brands !== []
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

        // „BLACKSTICK30+T11” to model plus rozmiar (taille) — w sklepie stoi sam model.
        // Wariant bez rozmiaru wpuszczamy wyłącznie razem z marką na stronie.
        foreach ($this->skuSizeVariants($product) as $variant) {
            if (! $this->tokenInHay($hay, $hayCompact, mb_strtolower($variant))) {
                continue;
            }
            if ($brands !== [] && ! $this->hayHasAnyBrand($hay, $hayCompact, $brands)) {
                continue;
            }

            return true;
        }

        // Gdy kod niesie nazwę modelu („COUPURE-IT11” → COUPURE), jej brak na stronie
        // oznacza inny model tej samej marki — dopasowanie po nazwie wpuściłoby fartuch
        // zamiast rękawicy.
        if ($this->internalSkuCore($product) !== '') {
            return false;
        }

        // „SKARPETY-POMARANCZ-ZOLTE” nie istnieje w sieci — zostaje marka i nazwa
        return $this->hayMatchesNameAndBrand($hay, $product);
    }

    /** Token bez cyfr to zwykłe słowo („casque”, „cut resistant gloves”), nie oznaczenie modelu. */
    private function isWordLikeToken(string $token): bool
    {
        if (preg_match('/\d/u', $token) === 1) {
            return false;
        }

        return mb_strlen((string) preg_replace('/[^\p{L}]+/u', '', $token)) >= 4;
    }

    /**
     * Produkty bez kodu producenta rozpoznajemy po marce i słowach z nazwy.
     */
    public function hayMatchesNameAndBrand(string $hay, Product $product): bool
    {
        if (! $this->hayHasBrand($hay, $product)) {
            return false;
        }

        return $this->nameTokensMatch($hay, $product) || $this->hayHasNamePhrase($hay, $product);
    }

    /**
     * Cała nazwa jako fraza („BLACK FIT” → „black-fit”). Ratuje krótkie nazwy,
     * których pojedyncze słowa są za krótkie, by je liczyć osobno.
     */
    public function hayHasNamePhrase(string $hay, Product $product): bool
    {
        $phrase = preg_replace('/[^a-z0-9]+/u', '', mb_strtolower(Str::ascii((string) $product->name))) ?? '';
        if (mb_strlen($phrase) < 6) {
            return false;
        }
        $hayCompact = preg_replace('/[^a-z0-9]+/u', '', mb_strtolower(Str::ascii($hay))) ?? '';

        return $hayCompact !== '' && str_contains($hayCompact, $phrase);
    }

    /**
     * Czy w tekście stoi dość słów z nazwy („skarpety … pomarańczowo-żółte”).
     * Odmiana: porównujemy po rdzeniu słowa, nie po całej formie.
     */
    public function nameTokensMatch(string $hay, Product $product, int $need = 2): bool
    {
        $tokens = $this->nameWords($product);
        $need = max(1, $need);
        // jedno słowo („spodnie”) pasuje do połowy sklepu — wymagamy pary
        if (count($tokens) < $need) {
            return false;
        }
        $hay = mb_strtolower($hay);

        $hits = 0;
        foreach ($tokens as $token) {
            $stem = mb_substr($token, 0, max(4, min(6, mb_strlen($token))));
            if (str_contains($hay, $stem)) {
                $hits++;
                if ($hits >= $need) {
                    return true;
                }
            }
        }

        return false;
    }

    /**
     * Znaczące słowa nazwy — bez ogólników, które ma każda karta BHP.
     *
     * @return list<string>
     */
    /**
     * Znaczące słowa z nazwy — bez rozmiarów, liczb i ogólników typu „robocze”.
     *
     * @return list<string>
     */
    public function nameWords(Product $product): array
    {
        $generic = ['bhp', 'robocze', 'roboczy', 'robocza', 'ochronne', 'ochronny', 'ochronna',
            'damskie', 'meskie', 'męskie', 'nowosc', 'nowość', 'szt', 'kpl', 'para', 'rozmiar'];

        $out = [];
        foreach (preg_split('/[^\p{L}\p{N}]+/u', mb_strtolower(trim((string) $product->name))) ?: [] as $word) {
            // liczby to kody — te sprawdzamy ściśle, nie po rdzeniu słowa
            if (mb_strlen($word) < 4 || preg_match('/\d/u', $word) === 1 || in_array($word, $generic, true)) {
                continue;
            }
            $out[] = $word;
        }

        return array_values(array_unique($out));
    }

    public function coreInUrlOrTitle(string $url, string $title, Product $product): bool
    {
        return $this->hayMentionsProduct($url.' '.$title, $product);
    }

    /**
     * Kody, których obecność w tekście przesądza o tożsamości produktu.
     *
     * @return list<string>
     */
    public function productCodes(Product $product): array
    {
        $codes = [];
        $sku = trim((string) $product->sku);
        if ($sku !== '' && ! $this->looksLikeInternalSku($product)) {
            $codes[] = $sku;
        }
        foreach ([$this->internalSkuCore($product), $this->gloveCodeCore($product)] as $code) {
            if (is_string($code) && mb_strlen($code) >= 4) {
                $codes[] = $code;
            }
        }
        foreach ($this->skuSizeVariants($product) as $variant) {
            $codes[] = $variant;
        }

        return array_values(array_unique(array_map('mb_strtolower', $codes)));
    }

    /**
     * Karta innego modelu tej samej marki: strona filtropochłaniacza FP 211/1 wymienia
     * w treści kompatybilne maski MT 212/2, ale kartą maski nie jest. O tym, czyja to
     * karta, mówi adres i tytuł — nie wzmianka w akapicie.
     */
    public function pageClaimsAnotherCode(string $url, string $title, Product $product): bool
    {
        $tokens = $this->codeLikeTokens($url, $title);

        return $tokens !== []
            && $this->compactProductCodes($product) !== []
            && ! $this->urlOrTitleCarriesCodeFamily($url, $title, $product);
    }

    /**
     * Czy adres albo tytuł niesie oznaczenie z rodziny naszego kodu. „MASKA MT 212”
     * to nasze „MT-212-2” bez członu z wariantem, ale „MT 213/2” to już inny model.
     */
    public function urlOrTitleCarriesCodeFamily(string $url, string $title, Product $product): bool
    {
        $codes = $this->compactProductCodes($product);
        if ($codes === []) {
            return false;
        }

        foreach ($this->codeLikeTokens($url, $title) as $token) {
            foreach ($codes as $code) {
                if (str_starts_with($code, $token) || str_starts_with($token, $code)) {
                    return true;
                }
            }
        }

        return false;
    }

    /**
     * Ile obcych oznaczeń modeli niesie treść. Strona zbiorcza producenta wymienia cały
     * katalog, karta produktu najwyżej akcesorium albo dwa. Liczymy tylko zapisy wielkimi
     * literami, bo w zdaniu „odporne do 250C” kodu nie ma.
     */
    public function foreignCodeCount(string $text, Product $product): int
    {
        $codes = $this->compactProductCodes($product);
        $seen = [];
        preg_match_all(
            '/(?<![\p{L}\d])(\p{Lu}{1,4})[\s\-]?(\d{2,4})((?:[\s\-\/]\d{1,3})*)/u',
            $text,
            $matches,
            PREG_SET_ORDER
        );

        foreach ($matches as $hit) {
            if (in_array(mb_strtolower($hit[1]), self::NORM_PREFIXES, true)) {
                continue;
            }
            $token = $this->compactCode($hit[0]);
            foreach ($codes as $code) {
                if (str_starts_with($code, $token) || str_starts_with($token, $code)) {
                    continue 2;
                }
            }
            $seen[$token] = true;
        }

        return count($seen);
    }

    /**
     * @return list<string>
     */
    private function compactProductCodes(Product $product): array
    {
        $out = [];
        foreach ($this->productCodes($product) as $code) {
            $compact = $this->compactCode($code);
            if (mb_strlen($compact) >= 4) {
                $out[] = $compact;
            }
        }

        return array_values(array_unique($out));
    }

    /**
     * Oznaczenia modelu z adresu i tytułu: „fp-211-1”, „mt 212/2”. Same liczby pomijamy,
     * bo to roczniki i rozmiary, a normy (EN 166) nie są kodem produktu.
     *
     * @return list<string>
     */
    private function codeLikeTokens(string $url, string $title): array
    {
        $path = (string) parse_url(mb_strtolower($url), PHP_URL_PATH);
        $path = (string) preg_replace('/\.[a-z]{2,5}$/u', '', $path);
        $hay = mb_strtolower($title).' '.str_replace(['/', '_'], ' ', $path);

        $out = [];
        preg_match_all(
            '/(?<![\p{L}\d])(\p{L}{1,4})[\s\-]?(\d{2,4})((?:[\s\-\/]\d{1,3})*)/u',
            $hay,
            $matches,
            PREG_SET_ORDER
        );
        foreach ($matches as $hit) {
            if (in_array($hit[1], self::NORM_PREFIXES, true)) {
                continue;
            }
            $out[$this->compactCode($hit[0])] = true;
        }

        return array_keys($out);
    }

    private function compactCode(string $code): string
    {
        return (string) preg_replace('/[^\p{L}\d]+/u', '', mb_strtolower($code));
    }

    public function hayHasProductCode(string $hay, Product $product): bool
    {
        foreach ($this->productCodes($product) as $code) {
            if ($this->codeInText($hay, $code)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Kod z separatorem pisze się wszędzie inaczej: nasz „MT-212-2” to w sieci
     * „MT 212/2”, „MT212/2” albo „MT 212-2”. Dopasowanie ignoruje separatory,
     * ale nie pozwala skleić dwóch członów liczbowych („212” + „2” ≠ „2122”).
     */
    public function codeInText(string $hay, string $code): bool
    {
        $pattern = $this->codePattern($code);

        return $pattern !== null && preg_match($pattern, $hay) === 1;
    }

    private function codePattern(string $code): ?string
    {
        $chunks = preg_split('/[^\p{L}\d]+/u', mb_strtolower(trim($code)), -1, PREG_SPLIT_NO_EMPTY) ?: [];
        if ($chunks === [] || mb_strlen(implode('', $chunks)) < 3) {
            return null;
        }

        $parts = [];
        foreach ($chunks as $i => $chunk) {
            if ($i > 0) {
                $glued = preg_match('/^\d+$/u', $chunks[$i - 1]) === 1 && preg_match('/^\d+$/u', $chunk) === 1;
                $parts[] = $glued ? '[\s\-\/\\\\._]+' : '[\s\-\/\\\\._]*';
            }
            $parts[] = preg_quote($chunk, '/');
        }

        return '/(?<![\p{L}\d])'.implode('', $parts).'(?![\p{L}\d])/iu';
    }

    /**
     * Czy w tekście stoi marka produktu. Bez niej sam kod „1202” trafia w Apollo 11
     * albo w szerokość miniatury, a nie w rękawice Urgent.
     */
    public function hayHasBrand(string $hay, Product $product): bool
    {
        $brands = $this->acceptedBrands($product);
        if ($brands === []) {
            return true;
        }
        $hay = mb_strtolower($hay);

        return $this->hayHasAnyBrand($hay, preg_replace('/[^a-z0-9]+/iu', '', $hay) ?? $hay, $brands);
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

        // Rękawice ocieplane polarem muszą wygrać z regułą odzieży niżej
        if (preg_match('#(glove|r[eę]kaw|maxiflex|maxicut|maxidry)#u', $blob)) {
            return 'rękawice';
        }

        // Kurtka/spodnie ostrzegawcze to hi-vis, nie ubranie wodoochronne
        if (preg_match('#(hsv|ostrzegawcz|odblask|hi-vis|hivis)#u', $blob) === 1
            && preg_match(
                '#(kurtka|spodnie|ubranie|odzież|odziez|płaszcz|plaszcz|bluza|kamizelk|koszulka|softshell|polar|kombinezon)#u',
                $blob
            ) === 1) {
            return 'odzież ostrzegawcza';
        }

        // Odzież wodoochronna — słowa z nazwy, NIE sama marka PROS
        if (preg_match(
            '#(wodoochron|plavitex|kurtka|spodnie|ubranie|odzież|odziez|płaszcz|plaszcz)#u',
            $nameSku
        )) {
            return 'ubranie wodoochronne';
        }

        // Odzież przed marką: Urgent szyje też bluzy i kamizelki, a niżej
        // sama marka wystarcza, by uznać produkt za rękawice.
        if (preg_match('#(bluza|koszulka|t-shirt|tshirt|polo|kamizelk|softshell|polar|czapk|kombinezon|fartuch)#u', $blob)) {
            return preg_match('#(hsv|ostrzegawcz|odblask|hi-vis|hivis)#u', $blob) === 1
                ? 'odzież ostrzegawcza'
                : 'odzież robocza';
        }

        if (preg_match('#^(atg|ansell|urgent)$#u', $brand)) {
            return 'rękawice';
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

    /**
     * Kod z naszego cennika (URG-HSV-WOR-BLUZA), a nie numer katalogowy producenta.
     * Takiego ciągu nie ma w sieci, więc każde zapytanie z nim wraca puste.
     */
    public function looksLikeInternalSku(Product $product): bool
    {
        $sku = trim((string) $product->sku);
        if ($sku === '' || preg_match('/\d{3,}/u', $sku) === 1) {
            return false;
        }

        $segments = array_values(array_filter(preg_split('/[\-\/ ]+/u', $sku) ?: []));
        if (count($segments) < 2) {
            return false;
        }
        // przy dwóch członach wymagamy dłuższego słowa: „URG-C” to kod, „WKLADKI-ALUTERMICZNE” nie
        $minWord = count($segments) >= 3 ? 4 : 7;

        foreach ($segments as $segment) {
            // człon będący słowem (BLUZA, SPODNIE) zdradza kod opisowy, nie model
            if (preg_match('/^\p{L}{'.$minWord.',}$/u', $segment) === 1) {
                return true;
            }
        }

        return false;
    }

    /**
     * Kod bez rozmiaru „taille”: „ATTACK6PEOM-BSCT12” → „ATTACK6PEOM-BSC” i „ATTACK6PEOM”.
     * Rostaing dokleja T08–T14 na końcu, czasem z literą wariantu przed nim, więc
     * zwracamy oba odczyty — trafi ten, który faktycznie stoi na karcie.
     *
     * @return list<string>
     */
    public function skuSizeVariants(Product $product): array
    {
        $sku = trim((string) $product->sku);
        if ($sku === '' || preg_match('/^(.*?)([A-Za-z0-9]{0,3})T(?:0\d|1[0-4])$/u', $sku, $m) !== 1) {
            return [];
        }

        // „CROSSFOREST10” — tu „T” kończy słowo FOREST, a rozmiarem jest samo 10.
        // Którego odczytu użyć, wie dopiero strona sklepu, więc dajemy oba.
        $out = [];
        foreach ([$m[1].$m[2], $m[1].$m[2].'T', $m[1]] as $candidate) {
            $candidate = rtrim($candidate, "-/+_. \t");
            if ($this->isUsableSizeVariant($candidate)) {
                $out[$candidate] = true;
            }
        }

        return array_keys($out);
    }

    /** Sam kolor albo przymiotnik pasuje do połowy katalogu marki, więc odpada. */
    private function isUsableSizeVariant(string $variant): bool
    {
        if (mb_strlen($variant) < 4) {
            return false;
        }

        return ! in_array(mb_strtolower($variant), [
            'black', 'white', 'grey', 'gray', 'noir', 'blanc', 'blue', 'green', 'red', 'jaune',
            'pro', 'plus', 'max', 'soft', 'light', 'basic', 'classic', 'premium', 'standard',
            'super', 'ultra', 'comfort', 'safety', 'glove', 'gant',
        ], true);
    }

    /**
     * Rdzeń kodu producenta z kodu cennikowego: „URG-C-SPODNIE” → „URG-C”.
     * Sklepy trzymają tę krótszą formę, opisowy ogon to już nasz dopisek.
     */
    public function internalSkuCore(Product $product): string
    {
        $sku = trim((string) $product->sku);

        // „BLACKNITT11” — Rostaing dokleja rozmiar jako T08–T14 (taille), więc to „T”
        // należy do rozmiaru, nie do nazwy modelu. Zakres cyfr chroni „COMFORT45”.
        if (preg_match('/^(\p{L}{5,})T(0\d|1[0-4])$/u', $sku, $m) === 1) {
            return $m[1];
        }

        // „ERGOPRIMA45”, „ERGOMASTER60VL” — model sklejony z rozmiarem, bez separatora.
        // Krótkie litery („NB27”) i długie ogony cyfr („MAXIFLEX34874”) zostają nietknięte,
        // bo tam cyfry są częścią numeru katalogowego, a nie rozmiarem.
        if (preg_match('/^(\p{L}{5,})(\d{1,3})(\p{L}{0,3})$/u', $sku, $m) === 1) {
            return $m[1];
        }

        if (! $this->looksLikeInternalSku($product)) {
            return '';
        }

        $segments = array_values(array_filter(preg_split('/[\-\/ ]+/u', trim((string) $product->sku)) ?: []));

        // „COUPURE-IT11”, „PROSOUD/1GAT11” — z przodu nazwa modelu, dalej rozmiar z cyfrą
        $lead = [];
        foreach ($segments as $segment) {
            if (preg_match('/^\p{L}{4,}$/u', $segment) !== 1) {
                break;
            }
            $lead[] = $segment;
        }
        if ($lead !== [] && count($lead) < count($segments)) {
            $core = implode('-', $lead);
            // „PROS-121-S1-GUMA” → sam prefiks hurtowni; model siedzi dalej, w nazwie
            if (mb_strlen($core) >= 5) {
                foreach (array_slice($segments, count($lead)) as $segment) {
                    if (preg_match('/\d/u', $segment) === 1) {
                        return $core;
                    }
                }
            }
        }

        // „URG-C-SPODNIE” — odwrotny układ: kod z przodu, nasz opis na końcu
        $kept = [];
        foreach ($segments as $segment) {
            if (preg_match('/^\p{L}{4,}$/u', $segment) === 1) {
                break;
            }
            $kept[] = $segment;
        }
        if (count($kept) < 2) {
            return '';
        }

        $core = implode('-', $kept);

        return mb_strlen($core) >= 4 ? $core : '';
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
            // marka jako całe słowo — „urgently” w tekście o Apollo to nie Urgent
            if (preg_match('/(?<![a-z0-9])'.preg_quote($brand, '/').'(?![a-z0-9])/iu', $hay) === 1) {
                return true;
            }
            $compact = preg_replace('/[^a-z0-9]+/iu', '', $brand) ?? $brand;
            // sklejona forma tylko dla marek wieloczłonowych („AJ Group” → ajgroup)
            if ($compact !== '' && $compact !== $brand && str_contains($hayCompact, $compact)) {
                return true;
            }
        }

        return false;
    }
}
