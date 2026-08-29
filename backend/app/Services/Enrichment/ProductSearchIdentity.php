<?php

declare(strict_types=1);

namespace App\Services\Enrichment;

use App\Models\Product;
use App\Support\ProductSizeVariant;
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
     * Typ z nazwy produktu (rękawice, kombinezon…) musi wrócić w URL/tekście karty.
     *
     * @var array<string, list<string>>
     */
    private const TYPE_STEMS = [
        'gloves' => ['rekawic', 'glove', 'handschuh', 'gant'],
        'coverall' => ['kombinezon', 'coverall', 'overall', 'cvrl', 'protective suit', 'protection suit'],
        'jacket' => ['kurtk', 'jacket', 'plaszcz'],
        'trousers' => ['spodn', 'trouser', 'pant'],
        'sweatshirt' => ['bluza', 'sweatshirt'],
        'vest' => ['kamizelk', 'vest'],
        'mask' => ['maska', 'maski', 'maske'],
        'footwear' => ['buty', 'butow', 'obuwie', 'trzewik', 'polbut', 'footwear', 'klapk', 'chodak', 'clog'],
        'helmet' => ['kask', 'helm', 'casque'],
        'goggles' => ['okular', 'gogl'],
        'apron' => ['fartuch', 'apron'],
        'hearing' => ['nausznik'],
        'harness' => ['szelk'],
        'clothing' => ['ubranie', 'odziez'],
    ];

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
        foreach ($this->shopIdentityPhrases($product) as $phrase) {
            $out[] = mb_strtolower($phrase);
            $compact = preg_replace('/[^a-z0-9]+/iu', '', mb_strtolower($phrase)) ?? '';
            if ($compact !== '' && $compact !== mb_strtolower($phrase)) {
                $out[] = $compact;
            }
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

        if ($this->looksLikeJunkMediaPath($hay) || $this->looksLikeChemicalCatalogHit($hay)) {
            return false;
        }
        if ($this->urlSkuOnlyAsCasNumber($hay, $product)) {
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

        // NB27B / NB27S — 1–2 znaki to inny wariant. TX39ERRXL to kolor/rozmiar tego modelu.
        return preg_match(
            '/(?<![a-z0-9])'.preg_quote($skuCompact, '/').'[a-z0-9]{1,2}(?![a-z0-9])/iu',
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
        // TX39ERRXL / tx39bremen — sklep dokleja kolor albo nazwę, to ten sam model
        if ($skuCompact !== '' && preg_match('/^[a-z]{1,4}\d{2,4}$/u', $skuCompact) === 1
            && preg_match(
                '/(?<![a-z0-9])'.preg_quote($skuCompact, '/').'[a-z]{3,8}[a-z0-9]{0,4}(?![a-z0-9])/iu',
                $hay.' '.$hayCompact
            ) === 1) {
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

        $count = preg_match_all(
            '/(?<![0-9])'.preg_quote($token, '/').'(?!\s*(?:px|x\s*\d))/iu',
            $hay,
            $matches,
            PREG_OFFSET_CAPTURE
        );
        if ($count === 0 || ($matches[0] ?? []) === []) {
            return false;
        }
        foreach ($matches[0] as [$match, $offset]) {
            if (! $this->numericMatchIsCasRegistry($hay, (int) $offset, (string) $match)) {
                return true;
            }
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
            || str_contains($hay, 'world-map')
            || str_contains($hay, 'tcichemicals')
            || str_contains($hay, 'tci-chemicals')
            || str_contains($hay, 'acrosorganics')
            || str_contains($hay, 'acros-organics')
            || str_contains($hay, 'sigmaaldrich')
            || str_contains($hay, 'merckmillipore');
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
        foreach ($this->ansellStyleCodes($product) as $style) {
            $out[] = $style;
        }

        return array_values(array_unique($out));
    }

    /**
     * OR15S-00138-06 / „1500-OR STD CVRL HOOD 138.5XL” → seria 1500, kolor OR, model 138.
     *
     * @return array{color: ?string, series: ?string, model: ?string, prefix: ?string}
     */
    public function ansellCatalogBits(Product $product): array
    {
        $sku = strtoupper(trim((string) $product->sku));
        $name = strtoupper(trim((string) $product->name));
        $brand = mb_strtolower($this->shortBrand((string) $product->manufacturer));
        $looksAnsell = str_contains($brand, 'ansell')
            || str_contains($name, 'ALPHATEC')
            || str_contains($name, 'HYFLEX')
            || preg_match('/^[A-Z]{2}\d{2}[A-Z]?-\d{5}(?:-\d{2})?$/', $sku) === 1;
        $bits = ['color' => null, 'series' => null, 'model' => null, 'prefix' => null];
        if (! $looksAnsell) {
            return $bits;
        }

        if (preg_match('/^([A-Z]{2})(\d{2})[A-Z]?-0*(\d{3,5})(?:-\d{2})?$/', $sku, $m) === 1) {
            $bits['color'] = $m[1];
            $bits['prefix'] = explode('-', $sku)[0] ?? null;
            $decade = (int) $m[2];
            if ($decade >= 15 && $decade <= 59) {
                $bits['series'] = (string) ($decade * 100);
            }
            $model = ltrim($m[3], '0');
            $bits['model'] = $model !== '' ? $model : null;
        }
        if (preg_match('/\b([456]\d{3})-([A-Z]{2})\b/', $name, $m) === 1) {
            $bits['series'] ??= $m[1];
            $bits['color'] ??= $m[2];
        }
        if (preg_match('/\b([456]\d{3})\b/', $name, $m) === 1) {
            $bits['series'] ??= $m[1];
        }
        if (preg_match('/(?:HOOD|MODEL|CVRL)\s+(\d{3})\b/', $name, $m) === 1
            || preg_match('/\b(\d{3})-G\d{2}\b/', $name, $m) === 1
            || preg_match('/\b(\d{3})\.\d+XL\b/', $name, $m) === 1) {
            $bits['model'] ??= $m[1];
        }

        return $bits;
    }

    /** Nazwa z cennika bez rozmiaru: „1500-OR STD CVRL HOOD 138.5XL” → „1500-OR STD CVRL HOOD 138”. */
    public function ansellTradeName(Product $product): string
    {
        $name = trim((string) $product->name);
        if ($name === '') {
            return '';
        }
        // nie G?\d{2}.\d+XL — to zjada „38” z „138.5XL” i zostawia „HOOD 1”
        $name = (string) preg_replace('/[\s\-]+G\d{2}(?:\.\d+)?XL$/i', '', $name);
        $name = (string) preg_replace('/\.\d+XL$/i', '', $name);
        $name = (string) preg_replace('/\.(?:XXL|XL|[SML])$/i', '', $name);

        return trim($name, " \t-");
    }

    public function ansellStyleCodes(Product $product): array
    {
        $bits = $this->ansellCatalogBits($product);
        if ($bits['model'] === null && $bits['series'] === null && $bits['prefix'] === null) {
            return [];
        }

        $out = array_filter([
            $bits['model'],
            $bits['series'],
            $bits['color'],
            $bits['prefix'] !== null ? mb_strtolower($bits['prefix']) : null,
        ], static fn (?string $v): bool => $v !== null && $v !== '');
        if ($bits['series'] !== null && $bits['color'] !== null) {
            $out[] = $bits['series'].'-'.$bits['color'];
            $out[] = $bits['series'].$bits['color'];
        }
        if ($bits['model'] !== null) {
            $out[] = str_pad($bits['model'], 5, '0', STR_PAD_LEFT);
        }

        return array_values(array_unique(array_map(static fn (string $v): string => $v, $out)));
    }

    /**
     * Wcześniej: 00138 / nazwa z cennika. Później: bez wiodących zer (138).
     *
     * @return list<string>
     */
    public function ansellSearchPhrases(Product $product, string $when): array
    {
        $bits = $this->ansellCatalogBits($product);
        $model = $bits['model'];
        if ($model === null) {
            return [];
        }
        $padded = str_pad($model, 5, '0', STR_PAD_LEFT);
        $label = trim(($bits['series'] ?? '').($bits['color'] !== null ? '-'.$bits['color'] : ''));
        $trade = $this->ansellTradeName($product);
        $sku = trim((string) $product->sku);

        if ($when === 'early') {
            $out = [];
            $series = $bits['series'] ?? '';
            if ($series !== '') {
                $out[] = 'AlphaTec '.$series.' '.$model;
            }
            if ($label !== '') {
                $out[] = 'Ansell '.$label.' '.$padded;
            }
            if ($trade !== '' && mb_strtolower($trade) !== mb_strtolower($sku)) {
                $out[] = $trade;
            }

            return array_values(array_unique($out));
        }

        $out = [];
        if ($padded !== $model) {
            if ($label !== '') {
                $out[] = 'Ansell '.$label.' '.$model;
            }
            $out[] = 'Ansell '.$model.' kombinezon';
            $stripped = $this->skuWithoutLeadingZeros($sku);
            if ($stripped !== '' && $stripped !== $sku) {
                $out[] = $stripped;
            }
        }

        return array_values(array_unique($out));
    }

    /**
     * Oficjalne karty AlphaTec: sklepy często nie mają modelu, a slug Ansell jest stały.
     *
     * @return list<string>
     */
    public function ansellOfficialProductUrls(Product $product): array
    {
        $bits = $this->ansellCatalogBits($product);
        $series = $bits['series'];
        $model = $bits['model'];
        if ($series === null || $model === null) {
            return [];
        }
        $series = mb_strtolower($series);
        $model = mb_strtolower($model);
        $slugs = [
            'alphatec-'.$series.'-ultrasonically-welded-taped-model-'.$model,
            'alphatec-'.$series.'-standard-model-'.$model,
            'alphatec-'.$series.'-plus-model-'.$model,
            'alphatec-'.$series.'-standard-bound-model-'.$model,
            'alphatec-'.$series.'-stitched-taped-model-'.$model,
        ];
        $out = [];
        foreach (['pl/pl', 'gb/en'] as $locale) {
            foreach ($slugs as $slug) {
                $out[] = 'https://www.ansell.com/'.$locale.'/products/'.$slug;
            }
        }

        return $out;
    }

    /** Karta ansell.com/…/alphatec-4000-…-model-121 — typ (CVRL) nie musi być w slugu. */
    public function ansellOfficialPathHasModel(string $hay, Product $product): bool
    {
        $bits = $this->ansellCatalogBits($product);
        $series = $bits['series'];
        $model = $bits['model'];
        if ($series === null || $model === null) {
            return false;
        }
        $hay = mb_strtolower($hay);
        if (! str_contains($hay, 'ansell.com') && ! str_contains($hay, 'alphatec-'.$series)) {
            return false;
        }

        return preg_match(
            '/alphatec[-_]?'.preg_quote($series, '/').'\b/u',
            $hay
        ) === 1
            && preg_match('/(?:^|[^0-9])model[-_ ]'.preg_quote($model, '/').'(?:[^0-9]|$)/u', $hay) === 1;
    }

    /** OR15S-00138-06 → OR15S-138-06 (zera tylko z długich członów, nie z rozmiaru 06). */
    public function skuWithoutLeadingZeros(string $sku): string
    {
        $parts = preg_split('/(-)/', trim($sku), -1, PREG_SPLIT_DELIM_CAPTURE) ?: [];
        $out = '';
        foreach ($parts as $part) {
            if (preg_match('/^0+\d+$/', $part) === 1 && strlen($part) >= 4) {
                $out .= ltrim($part, '0') ?: '0';
            } else {
                $out .= $part;
            }
        }

        return $out;
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
        $shopPhrase = $this->shopIdentityPhrases($product)[0] ?? '';
        $warehouseSku = $this->looksLikeWarehouseArticleSku($product);
        $bare = $shopPhrase !== ''
            ? $shopPhrase
            : ($internalSku
                ? $this->internalSkuCore($product)
                : ($warehouseSku
                    ? ($this->catalogArticleCodes($product)[0] ?? $this->strippedProductName($product))
                    : $this->stripBrandPrefix($sku !== '' ? $sku : $name, $brand)));
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

        if ($phase === 'manufacturer'
            && (str_contains(mb_strtolower($brand), 'ansell') || $this->ansellStyleCodes($product) !== [])) {
            $early = $this->ansellSearchPhrases($product, 'early');
            $late = $this->ansellSearchPhrases($product, 'late');
            $paddedPhrase = null;
            foreach ($early as $phrase) {
                if (preg_match('/\b0+\d{2,5}\b/', $phrase) === 1) {
                    $paddedPhrase = $phrase;
                    break;
                }
            }
            if ($paddedPhrase !== null) {
                $queries[] = 'site:bpbhp.pl '.$paddedPhrase;
            } elseif (($early[0] ?? '') !== '') {
                $queries[] = 'site:bpbhp.pl '.$early[0];
            }
            // druga fraza site: musi być bez zer — drabinka bierze tylko 2× site:
            if (($late[0] ?? '') !== '') {
                $queries[] = 'site:bpbhp.pl '.$late[0];
            }
            if (($early[0] ?? '') !== '') {
                $queries[] = 'site:ansell.com '.$early[0];
            }
            foreach (array_slice($early, 1) as $phrase) {
                $queries[] = 'site:bpbhp.pl '.$phrase;
            }
            if ($sku !== '') {
                $queries[] = 'site:bpbhp.pl '.$sku;
            }
            foreach (array_slice($late, 1) as $phrase) {
                $queries[] = 'site:bpbhp.pl '.$phrase;
            }
        }

        if ($phase === 'manufacturer' && ! $this->queriesContainSite($queries)) {
            $hosts = $this->catalogSearchHosts($product);
            $phrase = $shopPhrase !== ''
                ? $shopPhrase
                : (($sku !== '' && ! $internalSku && ! $warehouseSku)
                    ? $sku
                    : ($this->catalogArticleCodes($product)[0] ?? $this->strippedProductName($product)));
            if ($phrase !== '' && $hosts !== []) {
                foreach ($hosts as $host) {
                    $queries[] = 'site:'.$host.' '.$phrase;
                }
            }
        }

        $queries[] = $this->productNameWithManufacturer($product);

        // 1) Jak Google — kod / nazwa zawsze z producentem
        if ($sku !== '' && ! $internalSku && ! $warehouseSku) {
            $queries[] = $this->queryWithManufacturer($sku, $product);
            $queries[] = $this->queryWithManufacturer('"'.$sku.'"', $product);
        }
        foreach ($this->catalogArticleCodes($product) as $article) {
            $queries[] = $this->queryWithManufacturer($article, $product);
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
        foreach ($this->variantBaseCodes($product) as $base) {
            $queries[] = $base;
        }

        // 2) Dopiero potem warianty z hintem (gdy nazwa sugeruje kategorię)
        if ($hint !== '') {
            if ($brand !== '' && $bare !== '') {
                $queries[] = trim($brand.' '.$bare.' '.$hint);
            }
            if ($codeCore !== '' && $hint === 'rękawice') {
                $queries[] = trim($codeCore.' '.$hint);
            }
            if ($sku !== '' && ! $internalSku && ! $warehouseSku) {
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
        $usableSku = $sku !== '' && ! $this->rawSkuIsOfflineNoise($product);
        // „PROS-121-S1-GUMA” to nasz kod złożony z opisu — w sieci działa dopiero
        // nazwa z producentem („121 S1 GUMA Urgent”), więc ona idzie pierwsza.
        $composedSku = $this->hasDescriptiveWordSegment($sku);
        $shopName = $this->shopIdentityPhrases($product)[0] ?? '';
        // Numer z cennika (34977068 / 212804580000) albo ogon „…-CZARNY-NYLON”
        // nie stoi na karcie sklepu.
        $preferCatalogName = $composedSku || $shopName !== ''
            || $this->looksLikeWarehouseArticleSku($product);

        $skuQueries = [];
        if ($usableSku && $shopName === '') {
            $skuQueries[] = $this->queryWithManufacturer($sku, $product);
            $bare = $this->stripBrandPrefix($sku, $brand);
            if ($bare !== '' && $bare !== $sku) {
                $skuQueries[] = $this->queryWithManufacturer($bare, $product);
            }
        } elseif ($usableSku) {
            $skuQueries[] = $this->queryWithManufacturer($sku, $product);
        }

        $nameQueries = [];
        if ($shopName !== '') {
            $nameQueries[] = $this->queryWithManufacturer($shopName, $product);
        }
        if ($name !== '' && mb_strtolower($name) !== mb_strtolower($sku)
            && mb_strtolower($name) !== mb_strtolower($shopName)) {
            $nameQueries[] = $this->queryWithManufacturer(
                $usableSku && ! $composedSku && ! $this->phraseHasToken($name, $sku)
                    ? $name.' '.$sku
                    : $name,
                $product
            );
        }

        $out = $preferCatalogName
            ? array_merge($nameQueries, $skuQueries)
            : array_merge($skuQueries, $nameQueries);
        $styleQueries = [];
        foreach ($this->ansellSearchPhrases($product, 'early') as $phrase) {
            if (! str_starts_with($phrase, 'site:')) {
                $styleQueries[] = $this->queryWithManufacturer($phrase, $product);
            }
        }
        $lateStyle = [];
        foreach ($this->ansellSearchPhrases($product, 'late') as $phrase) {
            if (! str_starts_with($phrase, 'site:')) {
                $lateStyle[] = $this->queryWithManufacturer($phrase, $product);
            }
        }
        $out = array_merge($styleQueries, $out, $lateStyle);
        // „URG-C-SPODNIE” w sklepie występuje jako „URG-C”, a „ERGOPRIMA45” jako „ERGOPRIMA”
        $core = $this->internalSkuCore($product);
        if ($shopName === '' && $core !== '' && mb_strtolower($core) !== mb_strtolower($sku)) {
            $out[] = $this->queryWithManufacturer($core, $product);
        }
        // „BLACK-FITT10” sprzedaje się jako „BLACK-FIT” — rozmiar w kodzie jest tylko nasz
        foreach ($this->skuSizeVariants($product) as $variant) {
            if (mb_strtolower($variant) !== mb_strtolower($sku)) {
                $out[] = $this->queryWithManufacturer($variant, $product);
            }
        }
        foreach ($this->catalogArticleCodes($product) as $article) {
            if (mb_strtolower($article) !== mb_strtolower($sku)) {
                $out[] = $this->queryWithManufacturer($article, $product);
            }
        }
        foreach ($this->variantBaseCodes($product) as $base) {
            $out[] = $this->queryWithManufacturer($base, $product);
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
        if ($sku !== '' && ! $this->rawSkuIsOfflineNoise($product)
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
        if ($this->looksLikeChemicalCatalogHit($hay)) {
            return false;
        }
        if (! $this->hayHasRequiredTypeFromName($hay, $product)) {
            return false;
        }
        $brands = $this->acceptedBrands($product);
        $tokens = $this->matchTokens($product);
        $hayCompact = preg_replace('/[^a-z0-9]+/iu', '', $hay) ?? $hay;
        $hayDigits = preg_replace('/\D+/u', '', $hay) ?? '';

        $skuCompact = preg_replace('/[^a-z0-9]+/iu', '', mb_strtolower(trim((string) $product->sku))) ?? '';
        // NB27 ≠ NB27B — karta dłuższego wariantu nie może przejść przez nazwę „rubiflex”
        if ($this->urlContainsLongerAlphanumericSkuVariant($hay, $skuCompact)) {
            return false;
        }
        $ansellModel = $this->ansellCatalogBits($product)['model'];
        if ($ansellModel !== null) {
            $padded = str_pad($ansellModel, 5, '0', STR_PAD_LEFT);
            $hasModel = $this->tokenInHay($hay, $hayCompact, $ansellModel)
                || ($padded !== $ansellModel && $this->tokenInHay($hay, $hayCompact, $padded));
            if (! $hasModel) {
                return false;
            }
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

        // „TARAJ HV” / „COMO BASIC” na karcie sklepu — bez naszego SKU w adresie
        if ($this->hayHasShopIdentity($hay, $hayCompact, $product)) {
            return true;
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
     * Model z kodu cennikowego (TARAJ HV, COMO BASIC) stoi na karcie — bez naszego SKU.
     */
    public function hayHasShopIdentity(string $hay, string $hayCompact, Product $product): bool
    {
        $brands = $this->acceptedBrands($product);
        foreach ($this->shopIdentityPhrases($product) as $phrase) {
            $phrase = mb_strtolower(trim($phrase));
            if (mb_strlen($phrase) < 4 || ! $this->tokenInHay($hay, $hayCompact, $phrase)) {
                continue;
            }
            $words = preg_split('/\s+/u', $phrase) ?: [];
            $distinct = 0;
            foreach ($words as $word) {
                if (mb_strlen($word) >= 4 && ! $this->isDescriptiveIdentityWord($word) && ! $this->isColorWord($word)) {
                    $distinct++;
                }
            }
            // jedno słowo („BEAGLE”, „ONE4ALL”) bez marki trafia w inną branżę
            $strong = $distinct >= 1 && count($words) >= 2;
            if ($strong || $brands === [] || $this->hayHasAnyBrand($hay, $hayCompact, $brands)) {
                return true;
            }
        }

        return false;
    }

    public function urlOrTitleHasShopIdentity(string $url, string $title, Product $product): bool
    {
        $hay = mb_strtolower($url.' '.$title);
        $hayCompact = preg_replace('/[^a-z0-9]+/iu', '', $hay) ?? $hay;

        return $this->hayHasShopIdentity($hay, $hayCompact, $product);
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
        if ($sku !== '' && ! $this->rawSkuIsOfflineNoise($product)) {
            $codes[] = $sku;
        }
        foreach ($this->catalogArticleCodes($product) as $article) {
            $codes[] = $article;
        }
        foreach ([$this->internalSkuCore($product), $this->gloveCodeCore($product)] as $code) {
            if (is_string($code) && mb_strlen($code) >= 4) {
                $codes[] = $code;
            }
        }
        foreach ($this->skuSizeVariants($product) as $variant) {
            $codes[] = $variant;
        }
        foreach ($this->ansellStyleCodes($product) as $style) {
            $codes[] = $style;
        }

        return array_values(array_unique(array_map('mb_strtolower', $codes)));
    }

    /**
     * Nazwa handlowa z karty sklepu (SOLO 977, KRYTECH 563) — nie numer artykułu z cennika.
     *
     * @return list<string>
     */
    public function catalogTradeNames(Product $product): array
    {
        $sizes = new ProductSizeVariant;
        $out = [];
        foreach ([(string) $product->name, ...$this->variantBaseCodes($product)] as $raw) {
            $raw = $sizes->stripSizeFromName(trim((string) $raw));
            if ($raw === '') {
                continue;
            }
            if (preg_match('/^(\p{L}{3,12})\s+(S[1-5]S?)\b/u', $raw, $safety) === 1
                && ! $this->isDescriptiveIdentityWord($safety[1])
                && ! $this->isApparelTypeWord($safety[1])) {
                $out[] = $safety[1].' '.$safety[2];
            }
            if (preg_match('/^(?:\p{L}{2,12}[\s\-]+){1,2}\d{2,4}$/u', $raw) !== 1) {
                continue;
            }
            if (preg_match('/^(\p{L}{2,12})/u', $raw, $lead) === 1
                && $this->isDescriptiveIdentityWord($lead[1])) {
                continue;
            }
            $out[] = $raw;
        }

        return array_values(array_unique($out));
    }

    /**
     * Karta innego modelu tej samej marki: strona filtropochłaniacza FP 211/1 wymienia
     * w treści kompatybilne maski MT 212/2, ale kartą maski nie jest. O tym, czyja to
     * karta, mówi adres i tytuł — nie wzmianka w akapicie.
     */
    public function pageClaimsAnotherCode(string $url, string $title, Product $product): bool
    {
        if ($this->urlOrTitleHasShopIdentity($url, $title, $product)) {
            return false;
        }
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
        foreach ($this->shopIdentityPhrases($product) as $trade) {
            $compact = $this->compactCode($trade);
            if (mb_strlen($compact) >= 4) {
                $codes[] = $compact;
            }
        }
        $codes = array_values(array_unique($codes));
        if ($codes === []) {
            return false;
        }

        foreach ($this->codeLikeTokens($url, $title) as $token) {
            $token = (string) $token;
            foreach ($codes as $code) {
                $code = (string) $code;
                if ($token === '' || $code === '') {
                    continue;
                }
                if (str_starts_with($code, $token) || str_starts_with($token, $code)) {
                    return true;
                }
                // URL/tytuł ma tylko ostatni człon („plus995”, „tec332”),
                // nazwa katalogowa jest dłuższa („soloplus995”, „temptec332”).
                if (preg_match('/\p{L}/u', $code) === 1
                    && preg_match('/\p{L}/u', $token) === 1
                    && (str_ends_with($code, $token) || str_ends_with($token, $code))) {
                    return true;
                }
            }
        }

        return false;
    }

    /**
     * Jak sklep nazywa model: „SOLO PLUS 995”, „BALTIK BLACK” — nie ogon z cennika.
     *
     * @return list<string>
     */
    public function shopIdentityPhrases(Product $product): array
    {
        $out = [];
        foreach ($this->catalogTradeNames($product) as $trade) {
            $out[] = $trade;
        }
        $fromName = $this->seriesFromDescriptiveName((string) $product->name);
        if ($fromName !== '') {
            $out[] = $fromName;
        }
        $fromVariant = $this->seriesWithTrailingVariant((string) $product->name);
        if ($fromVariant !== '') {
            $out[] = $fromVariant;
        }
        if ($this->looksLikeInternalSku($product) || $this->hasDescriptiveWordSegment((string) $product->sku)) {
            $fromSku = $this->seriesFromInternalSku((string) $product->sku, $product);
            if ($fromSku !== '') {
                $out[] = $fromSku;
            }
        }
        foreach ($this->variantBaseCodes($product) as $base) {
            $out[] = $base;
        }
        $variants = $this->skuSizeVariants($product);
        usort($variants, static fn (string $a, string $b): int => mb_strlen($a) <=> mb_strlen($b));
        foreach ($variants as $variant) {
            if ($this->isUsableSeriesPhrase($variant)) {
                $out[] = $variant;
            }
        }
        $core = $this->internalSkuCore($product);
        $corePhrase = str_replace('-', ' ', $core);
        if ($core !== '' && mb_strtolower($core) !== mb_strtolower(trim((string) $product->sku))
            && $this->isUsableSeriesPhrase($corePhrase)) {
            $out[] = $corePhrase;
            if ($corePhrase !== $core) {
                $out[] = $core;
            }
        }
        foreach ($this->nameModelPhrases($product) as $fromNameModel) {
            $out[] = $fromNameModel;
        }

        $uniq = [];
        foreach ($out as $phrase) {
            $phrase = trim((string) preg_replace('/\s+/u', ' ', $phrase));
            if ($phrase === '' || mb_strlen($phrase) < 3) {
                continue;
            }
            $key = mb_strtolower($phrase);
            if (! isset($uniq[$key])) {
                $uniq[$key] = $phrase;
            }
        }

        return $this->preferSpecificShopPhrases(array_values($uniq), $product);
    }

    /**
     * Hosty do site: — z config albo domeny producenta.
     *
     * @return list<string>
     */
    public function catalogSearchHosts(Product $product): array
    {
        $keys = $this->manufacturerKeyCandidates($product);
        $shops = $this->bareHosts($this->hostsFromConfigMap(
            (array) config('enrichment.catalog_search_hosts', []),
            $keys
        ));
        if ($shops !== []) {
            return $shops;
        }
        if ($this->shopIdentityPhrases($product) === []) {
            return [];
        }

        return $this->officialCatalogHosts($product);
    }

    /**
     * Oficjalne domeny z config — bez tabeli discovered sites.
     *
     * @return list<string>
     */
    public function officialCatalogHosts(Product $product): array
    {
        return $this->bareHosts($this->hostsFromConfigMap(
            (array) config('enrichment.manufacturer_domains', []),
            $this->manufacturerKeyCandidates($product)
        ));
    }

    /**
     * TX39, OPSBT11, URG-914 — kod katalogowy z literą i cyfrą, nie EAN z cennika.
     */
    public function hasDistinctiveCatalogSku(Product $product): bool
    {
        $sku = trim((string) $product->sku);
        if ($sku === '' || $this->looksLikeWarehouseArticleSku($product)) {
            return false;
        }

        return preg_match(
            '/^(?=[A-Z0-9\-]*\p{L})(?=[A-Z0-9\-]*\d)[A-Z0-9\-]{3,16}$/iu',
            $sku
        ) === 1;
    }

    /**
     * Sklepy, które indeksują kod katalogowy (TX39) — oficjalna strona marki często nie.
     *
     * @return list<string>
     */
    public function codeIndexRetailerHosts(): array
    {
        return $this->bareHosts([
            'sklep-system.pl',
            'workweargurus.com',
            'gvarant.pl',
            'optimumbhp.pl',
        ]);
    }

    /** @deprecated użyj shopIdentityPhrases — zostaje dla testów MAPA. */
    public function mapaCatalogName(Product $product): string
    {
        return $this->shopIdentityPhrases($product)[0] ?? $this->strippedProductName($product);
    }

    /**
     * Oznaczenie bez członu z wariantem: „MT-212-2” sprzedaje się jako „MASKA MT 212”,
     * a pełne „MT 212/2” zostaje dopiero w treści karty. Bez tej formy wyszukiwarka
     * zwraca wyłącznie sąsiedni model.
     *
     * @return list<string>
     */
    public function variantBaseCodes(Product $product): array
    {
        $out = [];
        foreach ([(string) $product->sku, (string) $product->name] as $source) {
            if (preg_match(
                '/(?<![\p{L}\d])(\p{L}{1,12})[\s\-]?(\d{2,4})[\-\/](\d{1,2})(?![\d\p{L}])/u',
                trim($source),
                $hit
            ) === 1) {
                if ($this->isDescriptiveIdentityWord($hit[1])) {
                    continue;
                }
                $out[] = mb_strtoupper($hit[1]).' '.$hit[2];
            }
        }

        return array_values(array_unique($out));
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
            '/(?<![\p{L}\d])(\p{Lu}{1,4})[\s\-]?(\d{2,4})((?:[\s\-\/]\d{1,3})*)(?!\d)/u',
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
            $compact = $this->compactCode((string) $code);
            $min = preg_match('/^\d{3}$/u', $compact) === 1 ? 3 : 4;
            if (mb_strlen($compact) >= $min) {
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
        // „…-p138481” to identyfikator sklepu, nie model — stąd zakaz urwania w cyfrach
        preg_match_all(
            '/(?<![\p{L}\d])(\p{L}{1,4})[\s\-]?(\d{2,4})((?:[\s\-\/]\d{1,3})*)(?!\d)/u',
            $hay,
            $matches,
            PREG_SET_ORDER
        );
        foreach ($matches as $hit) {
            if (in_array($hit[1], self::NORM_PREFIXES, true)) {
                continue;
            }
            $out[(string) $this->compactCode($hit[0])] = true;
        }
        if (preg_match_all('/\bmodel[\s\-]?(\d{2,4})\b/u', $hay, $modelHits)) {
            foreach ($modelHits[1] as $n) {
                $out[(string) $n] = true;
            }
        }

        return array_map(static fn (string|int $k): string => (string) $k, array_keys($out));
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
        if ($pattern === null) {
            return false;
        }
        if (preg_match_all($pattern, $hay, $matches, PREG_OFFSET_CAPTURE) < 1) {
            return false;
        }
        if (preg_match('/^\d{2,7}$/', trim($code)) !== 1) {
            return true;
        }
        foreach ($matches[0] as [$match, $offset]) {
            if (! $this->numericMatchIsCasRegistry($hay, (int) $offset, (string) $match)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Katalog odczynników (TCI, Acros, CAS) — nie karta BHP, nawet gdy snippet powtarza frazę z zapytania.
     */
    public function looksLikeChemicalCatalogHit(string $hay): bool
    {
        $n = mb_strtolower($hay);
        foreach ([
            'tcichemicals', 'tci-chemicals', 'tci chemicals',
            'acrosorganics', 'acros-organics', 'acros organics',
            'sigmaaldrich', 'sigma-aldrich', 'merckmillipore',
            'fishersci.com', 'alfa-aesar', 'alfa aesar',
        ] as $marker) {
            if (str_contains($n, $marker)) {
                return true;
            }
        }
        if (preg_match('/\bcas(?:\s*(?:nr|no\.?|number|numer))?\s*[:.]?\s*\d{2,7}-\d{2}-\d\b/u', $n) === 1) {
            return true;
        }
        foreach ([
            'benzophenon', 'fenylooctow', 'odczynnik chemiczny', 'odczynniki syntetyczne',
            'reagent grade', 'molecular formula', 'wzor sumaryczny', 'wzór sumaryczny',
            'trifluoromethyl', 'trifluoromethylo', 'substancja chemiczna',
        ] as $word) {
            if (str_contains($n, $word)) {
                return true;
            }
        }

        return false;
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
     * Gdy nazwa mówi „rękawice” / „kombinezon”, karta bez tego typu odpada.
     */
    public function hayHasRequiredTypeFromName(string $hay, Product $product): bool
    {
        if ($this->ansellOfficialPathHasModel($hay, $product)) {
            return true;
        }
        $name = $this->normalizeTypeText((string) $product->name);
        $page = $this->normalizeTypeText($hay);
        $required = [];
        foreach (self::TYPE_STEMS as $stems) {
            if ($this->textHasTypeStem($name, $stems)) {
                $required[] = $stems;
            }
        }
        if ($required === []) {
            return true;
        }
        foreach ($required as $stems) {
            if (! $this->textHasTypeStem($page, $stems)) {
                return false;
            }
        }

        return true;
    }

    /**
     * @param  list<string>  $stems
     */
    private function textHasTypeStem(string $normalized, array $stems): bool
    {
        foreach ($stems as $stem) {
            if ($stem === '') {
                continue;
            }
            // „buty” ≠ butyl / butyric na karcie odczynnika
            if ($stem === 'buty') {
                if (preg_match('/\bbuty\b/u', $normalized) === 1) {
                    return true;
                }

                continue;
            }
            if (str_contains($normalized, $stem)) {
                return true;
            }
        }

        return false;
    }

    private function normalizeTypeText(string $text): string
    {
        $text = mb_strtolower($text);
        $text = strtr($text, [
            'ą' => 'a', 'ć' => 'c', 'ę' => 'e', 'ł' => 'l', 'ń' => 'n',
            'ó' => 'o', 'ś' => 's', 'ź' => 'z', 'ż' => 'z',
        ]);

        return (string) preg_replace('/[^a-z0-9]+/u', ' ', $text);
    }

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

        $count = preg_match_all(
            '/(?<![0-9])'.preg_quote($token, '/').'(?![0-9a-z])/iu',
            $hay,
            $matches,
            PREG_OFFSET_CAPTURE
        );
        if ($count === 0 || ($matches[0] ?? []) === []) {
            return false;
        }
        foreach ($matches[0] as [$match, $offset]) {
            if (! $this->numericMatchIsCasRegistry($hay, (int) $offset, (string) $match)) {
                return true;
            }
        }

        return false;
    }

    /** Numer CAS (1868-00-4) nie jest SKU „1868”. */
    private function numericMatchIsCasRegistry(string $hay, int $offset, string $match): bool
    {
        if (preg_match('/^\d{2,7}$/', $match) !== 1) {
            return false;
        }
        $after = substr($hay, $offset + strlen($match), 8);

        return preg_match('/^-\d{2}-\d(?:\D|$)/', $after) === 1;
    }

    /** W URL SKU występuje tylko jako CAS (…/1868-00-4.png), nie jako model. */
    private function urlSkuOnlyAsCasNumber(string $hay, Product $product): bool
    {
        $sku = preg_replace('/\D+/u', '', mb_strtolower(trim((string) $product->sku))) ?? '';
        if (preg_match('/^\d{2,7}$/', $sku) !== 1) {
            return false;
        }
        $quoted = preg_quote($sku, '/');
        $hasCas = preg_match('/(?<![0-9])'.$quoted.'-\d{2}-\d(?![0-9])/u', $hay) === 1;
        $hasStandalone = preg_match('/(?<![0-9])'.$quoted.'(?![0-9\-])/u', $hay) === 1;

        return $hasCas && ! $hasStandalone;
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
        if ($this->looksLikeWarehouseArticleSku($product)) {
            $stripped = rtrim(preg_replace('/\D+/u', '', (string) $product->sku) ?? '', '0');
            if (mb_strlen($stripped) >= 8) {
                return $stripped;
            }
        }
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

        // CVRL / AlphaTec 4000 to kombinezon — zanim Ansell spadnie na domyślne „rękawice”
        if (preg_match('#(cvrl|coverall|kombinezon|overall|alphatec)#u', $blob) === 1) {
            return 'kombinezon';
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
        if ($sku === '') {
            return false;
        }

        $segments = array_values(array_filter(preg_split('/[\-\/ ]+/u', $sku) ?: []));
        if (count($segments) < 2) {
            return false;
        }
        // P-BUTY-126 — typ + numer, nie EAN. PROS-1001 zostaje kodem katalogowym.
        if (preg_match('/\d{3,}/u', $sku) === 1) {
            return $this->skuHasApparelTypeSegment($segments);
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
     * Długi numer z cennika (212804580000) — w sklepie jest model albo 2128-045-800.
     */
    public function looksLikeWarehouseArticleSku(Product $product): bool
    {
        $sku = trim((string) $product->sku);
        if (preg_match('/^\d{10,14}$/u', $sku) !== 1) {
            return false;
        }
        $stripped = rtrim($sku, '0');

        return mb_strlen($stripped) >= 6 && mb_strlen($sku) - mb_strlen($stripped) >= 3;
    }

    public function rawSkuIsOfflineNoise(Product $product): bool
    {
        return $this->looksLikeInternalSku($product) || $this->looksLikeWarehouseArticleSku($product);
    }

    /**
     * Numer katalogowy odczytany z kodu magazynowego: 212804580000 → 2128-045-800.
     *
     * @return list<string>
     */
    public function catalogArticleCodes(Product $product): array
    {
        if (! $this->looksLikeWarehouseArticleSku($product)) {
            return [];
        }
        $digits = preg_replace('/\D+/u', '', (string) $product->sku) ?? '';
        $out = [];
        if (mb_strlen($digits) >= 10) {
            $ten = mb_substr($digits, 0, 10);
            $out[] = mb_substr($ten, 0, 4).'-'.mb_substr($ten, 4, 3).'-'.mb_substr($ten, 7, 3);
            $out[] = $ten;
        }
        $stripped = rtrim($digits, '0');
        if (mb_strlen($stripped) >= 8) {
            $out[] = $stripped;
        }

        return array_values(array_unique($out));
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
        if ($sku === '') {
            return [];
        }
        // „ONE4ALL-IT08” — IT to rozmiar (taille), nie litera I i osobne T08
        if (preg_match('/^(.+)-IT(0\d|1[0-4])$/u', $sku, $it) === 1) {
            $core = rtrim($it[1], "-/+_. \t");

            return $this->isUsableSizeVariant($core) ? [$core] : [];
        }
        if (preg_match('/^(.*?)([A-Za-z0-9]{0,3})T(?:0\d|1[0-4])$/u', $sku, $m) !== 1) {
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

        // „ONE4ALL-IT08”, „COUPURE-IT11” — model + taille, także gdy w modelu jest cyfra
        if (preg_match('/^((?=.*\p{L})[\p{L}\d]{4,})-IT(0\d|1[0-4])$/u', $sku, $m) === 1) {
            return $m[1];
        }

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

        // KURTKA-OCIEPLANA-TARAJ-HV-55 → TARAJ-HV, nie KURTKA-OCIEPLANA-TARAJ
        $series = $this->seriesFromInternalSku((string) $product->sku, $product);
        if ($series !== '' && $this->skuHasDigitSegment((string) $product->sku)) {
            return str_replace(' ', '-', $series);
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
        foreach (preg_split('/[^a-z0-9]+/u', $main) ?: [] as $part) {
            if ($this->looksLikeBrandToken($part)) {
                $out[] = $part;
            }
        }
        // „Rękawica … TEGERA 104” przy producencie Ejendals — sklepy piszą Tegera, nie Ejendals
        foreach (preg_split('/[^\p{L}\p{N}]+/u', (string) $product->name) ?: [] as $raw) {
            if (preg_match('/^\p{Lu}{4,}$/u', $raw) === 1 && $this->looksLikeBrandToken(mb_strtolower($raw))) {
                $out[] = mb_strtolower($raw);
            }
        }
        if ($this->looksLikeUrgentGloveSeries($product)) {
            $out[] = 'urgent';
        }
        foreach ($this->officialCatalogHosts($product) as $host) {
            $label = explode('.', $host)[0] ?? '';
            if (mb_strlen($label) >= 3 && preg_match('/^[a-z0-9]+$/u', $label) === 1) {
                $out[] = $label;
            }
        }

        return array_values(array_unique($out));
    }

    /** Słowo z nazwy/producenta, które jest linią produktu, nie typem PPE. */
    private function looksLikeBrandToken(string $word): bool
    {
        $word = mb_strtolower(trim($word));
        if ($word === '' || mb_strlen($word) < 4 || preg_match('/\d/u', $word) === 1) {
            return false;
        }

        return ! in_array($word, [
            'gloves', 'group', 'safety', 'rekawica', 'rekawice', 'rekawiczki', 'tekstylna',
            'tekstylne', 'maska', 'buty', 'kombinezon', 'kurtka', 'spodnie', 'bluza',
            'kamizelka', 'ochronna', 'ochronne', 'ochronny', 'robocza', 'robocze', 'roboczy',
            'wodoochronny', 'wodoochronna', 'odziez', 'odzież', 'ubranie',
            'king', 'road',
        ], true);
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

    /**
     * @param  list<string>  $queries
     */
    private function queriesContainSite(array $queries): bool
    {
        foreach ($queries as $query) {
            if (preg_match('/\bsite:/i', $query) === 1) {
                return true;
            }
        }

        return false;
    }

    public function strippedProductName(Product $product): string
    {
        $name = trim((string) $product->name);
        $sku = trim((string) $product->sku);
        if ($name !== '' && mb_strtolower($name) !== mb_strtolower($sku)) {
            $stripped = (new ProductSizeVariant)->stripSizeFromName($name);

            return $stripped !== '' ? $stripped : $name;
        }

        return $this->variantBaseCodes($product)[0] ?? $sku;
    }

    private function seriesFromDescriptiveName(string $name): string
    {
        $name = trim($name);
        if (preg_match('/^(.+?)[\s]*[-–][\s]+(\p{L}.+)$/u', $name, $hit) !== 1) {
            return '';
        }
        $series = trim($hit[1], " \t-");
        if (! $this->isUsableSeriesPhrase($series)) {
            return '';
        }

        return $series;
    }

    /**
     * Model z nazwy, którego nie ma w SKU: „KENT S3”, „BEAGLE”, „OPEX”.
     *
     * @return list<string>
     */
    private function nameModelPhrases(Product $product): array
    {
        $name = trim((string) $product->name);
        $sku = trim((string) $product->sku);
        if ($name === '' || mb_strtolower($name) === mb_strtolower($sku)) {
            return [];
        }
        $skuHay = preg_replace('/[^a-z0-9]+/iu', '', mb_strtolower($sku)) ?? '';
        $tradeNames = $this->catalogTradeNames($product);
        $out = [];
        if (preg_match('/(\p{L}{3,12})\s+(S[1-5]S?)\b/u', $name, $safety) === 1
            && ! $this->isGenericCatalogNameWord($safety[1])) {
            $out[] = $safety[1].' '.$safety[2];
        }
        // OPSBT11 → OPSB; „OPEX” z angielskiej nazwy cennika nie stoi na karcie
        if ($this->skuSizeVariants($product) !== []) {
            return $out;
        }
        foreach (preg_split('/[^\p{L}\p{N}]+/u', $name) ?: [] as $word) {
            $word = trim((string) $word);
            if ($word === '' || mb_strlen($word) < 4 || mb_strlen($word) > 16) {
                continue;
            }
            if (preg_match('/\d/u', $word) === 1 || preg_match('/^T\d{1,2}$/iu', $word) === 1) {
                continue;
            }
            if ($this->isGenericCatalogNameWord($word) || $this->isHouseSkuPrefix($word)
                || in_array(mb_strtolower($word), $this->skuBrandWords($product), true)) {
                continue;
            }
            $compact = preg_replace('/[^a-z0-9]+/iu', '', mb_strtolower($word)) ?? '';
            if ($skuHay !== '' && $compact !== '' && str_contains($skuHay, $compact)) {
                continue;
            }
            $alreadyInTrade = false;
            foreach ($tradeNames as $trade) {
                if ($this->phraseHasToken($trade, $word)) {
                    $alreadyInTrade = true;
                    break;
                }
            }
            if ($alreadyInTrade) {
                continue;
            }
            $out[] = $word;
        }

        return $out;
    }

    private function isGenericCatalogNameWord(string $word): bool
    {
        if ($this->isDescriptiveIdentityWord($word) || $this->isApparelTypeWord($word)
            || $this->isColorWord($word) || $this->isLineQualifierWord($word)) {
            return true;
        }
        $word = mb_strtolower(trim($word));

        return in_array($word, [
            'gloves', 'glove', 'gants', 'gant', 'work', 'wear', 'indicator', 'precision',
            'resistant', 'cut', 'for', 'with', 'the', 'and', 'pairs', 'pair', 'size',
            'safety', 'protection', 'protective', 'garden', 'jardinage', 'comfort',
            'confort', 'cross', 'plus', 'pro', 'max', 'soft', 'hard', 'light', 'super',
            'ultra', 'line', 'type', 'hood', 'coverall', 'overall', 'cuffs', 'cuff',
            'sleeves', 'sleeve', 'collar', 'pocket', 'pockets', 'visor', 'strap',
        ], true);
    }

    /**
     * „COMO BASIC - HAPPY” w sklepie to „COMO HAPPY” — BASIC to nasza linia, nie wzór.
     */
    private function seriesWithTrailingVariant(string $name): string
    {
        $name = trim($name);
        if (preg_match('/^(.+?)[\s]*[-–][\s]+(\p{L}[\p{L}\d]{2,})$/u', $name, $hit) !== 1) {
            return '';
        }
        $variant = trim($hit[2]);
        if ($this->isDescriptiveIdentityWord($variant) || $this->isLineQualifierWord($variant)) {
            return '';
        }
        $model = [];
        foreach (preg_split('/[\s\-]+/u', trim($hit[1])) ?: [] as $word) {
            $word = trim((string) $word);
            if ($word === '' || $this->isDescriptiveIdentityWord($word)
                || $this->isLineQualifierWord($word) || $this->isApparelTypeWord($word)) {
                continue;
            }
            if (preg_match('/^\p{L}{3,}$/u', $word) === 1) {
                $model[] = $word;
            }
        }
        if ($model === []) {
            return '';
        }
        $phrase = $model[0].' '.$variant;

        return $this->isUsableSeriesPhrase($phrase) ? $phrase : '';
    }

    /**
     * @param  list<string>  $phrases
     * @return list<string>
     */
    private function preferSpecificShopPhrases(array $phrases, Product $product): array
    {
        $tail = $this->lastDistinctiveSkuWord($product);
        if ($tail === '' || $phrases === []) {
            return $phrases;
        }
        $preferred = [];
        $rest = [];
        foreach ($phrases as $phrase) {
            if ($this->phraseHasToken($phrase, $tail)) {
                $preferred[] = $phrase;
            } else {
                $rest[] = $phrase;
            }
        }

        return array_values(array_merge($preferred, $rest));
    }

    private function lastDistinctiveSkuWord(Product $product): string
    {
        $tail = '';
        foreach (preg_split('/[\-\/ ]+/u', trim((string) $product->sku.' '.$product->name)) ?: [] as $segment) {
            $segment = trim((string) $segment);
            if ($segment === '' || preg_match('/^\p{L}{3,}$/u', $segment) !== 1) {
                continue;
            }
            if ($this->isGenericCatalogNameWord($segment) || $this->isHouseSkuPrefix($segment)
                || in_array(mb_strtolower($segment), $this->skuBrandWords($product), true)) {
                continue;
            }
            $tail = $segment;
        }

        return $tail;
    }

    /**
     * @param  list<string>  $words
     * @return list<string>
     */
    private function dropMiddleQualifiers(array $words): array
    {
        if (count($words) < 3) {
            return $words;
        }
        $out = [];
        $last = count($words) - 1;
        foreach ($words as $i => $word) {
            if ($i > 0 && $i < $last && $this->isLineQualifierWord((string) $word)) {
                continue;
            }
            $out[] = $word;
        }

        return $out;
    }

    private function isLineQualifierWord(string $word): bool
    {
        $word = mb_strtolower(trim($word));
        $word = strtr($word, ['ą' => 'a', 'ć' => 'c', 'ę' => 'e', 'ł' => 'l', 'ń' => 'n', 'ó' => 'o', 'ś' => 's', 'ź' => 'z', 'ż' => 'z']);

        return in_array($word, [
            'basic', 'classic', 'print', 'standard', 'premium', 'plus', 'pro',
            'eco', 'light', 'soft', 'extra', 'new', 'maxi', 'mini',
        ], true);
    }

    private function seriesFromInternalSku(string $sku, ?Product $product = null): string
    {
        $brandWords = $this->skuBrandWords($product);
        $kept = [];
        $skippedType = '';
        $number = '';
        foreach (preg_split('/[\-\/ ]+/u', trim($sku)) ?: [] as $segment) {
            $segment = trim((string) $segment);
            if ($segment === '') {
                continue;
            }
            if (preg_match('/^\d{2,4}$/u', $segment) === 1) {
                if ($kept !== []) {
                    // 563 to numer modelu, 42/70 to rozmiar albo opakowanie
                    if (mb_strlen($segment) >= 3) {
                        return implode(' ', $kept).' '.$segment;
                    }
                    break;
                }
                $number = $segment;
                continue;
            }
            if (preg_match('/^\p{L}{2,}$/u', $segment) !== 1) {
                if ($kept !== []) {
                    break;
                }
                continue;
            }
            if ($this->isHouseSkuPrefix($segment)
                || in_array(mb_strtolower($segment), $brandWords, true)) {
                continue;
            }
            if ($this->isDescriptiveIdentityWord($segment)) {
                if ($kept !== []) {
                    break;
                }
                if ($this->isApparelTypeWord($segment)) {
                    $skippedType = $segment;
                }
                continue;
            }
            $kept[] = $segment;
            if (count($kept) >= 3) {
                break;
            }
        }
        $kept = $this->dropMiddleQualifiers($kept);
        if ($kept !== []) {
            $series = implode(' ', $kept);

            return $this->isUsableSeriesPhrase($series) ? $series : '';
        }
        if ($number !== '' && $skippedType !== '') {
            return mb_strtolower($skippedType).' '.$number;
        }

        return '';
    }

    private function isUsableSeriesPhrase(string $series): bool
    {
        $words = preg_split('/[\s\-]+/u', trim($series)) ?: [];
        if ($words === [] || count($words) > 3) {
            return false;
        }
        foreach ($words as $word) {
            if (mb_strlen($word) >= 4 && ! $this->isDescriptiveIdentityWord($word)) {
                return true;
            }
        }

        return false;
    }

    private function isDescriptiveIdentityWord(string $word): bool
    {
        $word = mb_strtolower(trim($word));
        $word = strtr($word, ['ą' => 'a', 'ć' => 'c', 'ę' => 'e', 'ł' => 'l', 'ń' => 'n', 'ó' => 'o', 'ś' => 's', 'ź' => 'z', 'ż' => 'z']);

        return in_array($word, [
            'kurtka', 'bluza', 'spodnie', 'kamizelka', 'rekawice', 'rekawica', 'buty', 'polbuty',
            'trzewiki', 'sandaly', 'maska', 'kask', 'fartuch', 'ocieplana', 'ocieplany',
            'ostrzegawcza', 'ostr', 'nylon', 'nylonowa', 'nylonowy', 'polyester', 'polyes',
            'poliester', 'pongee', 'bawelna', 'skora', 'lateks', 'nitryl', 'poliuretan',
            'powlekany', 'powlekana', 'czarny', 'czarna', 'bialy', 'biala', 'zolty', 'zolta',
            'zolte', 'szary', 'szara', 'grafit', 'czerwony', 'niebieski', 'zielony', 'brazowy',
            'granat', 'pomarancz', 'robocza', 'robocze', 'ochronna', 'ochronne',
            'wodoochronne', 'wodoochronny', 'hivis', 'hi', 'vis', 'free', 'dmf', 'cieg',
            'scieg', 'size', 'rozmiar', 'taille', 'szt', 'kpl', 'guma', 'polar', 'zima',
            'czapka', 'czapki', 'daszek', 'daszkiem', 'szwedzka', 'szwedzki', 'szwedzkie',
            'kombinezon', 'kombinezony', 'skarpety', 'skarpetki', 'skarpeta',
            'wkladki', 'wkladka', 'koszula', 'ogrodniczki', 'plaszcz',
        ], true);
    }

    private function isHouseSkuPrefix(string $word): bool
    {
        $word = mb_strtolower(trim($word));

        return in_array($word, ['pros', 'urg', 'urgent', 'pilne', 'aj'], true);
    }

    /**
     * @return list<string>
     */
    private function skuBrandWords(?Product $product): array
    {
        if ($product === null) {
            return [];
        }
        $out = [];
        foreach (preg_split('/[^a-z0-9]+/u', mb_strtolower($this->shortBrand((string) $product->manufacturer))) ?: [] as $word) {
            if (mb_strlen($word) >= 3) {
                $out[] = $word;
            }
        }

        return $out;
    }

    /**
     * @param  list<string>  $hosts
     * @return list<string>
     */
    private function bareHosts(array $hosts): array
    {
        $out = [];
        foreach ($hosts as $host) {
            $bare = preg_replace('/^www\./', '', mb_strtolower(trim($host))) ?? $host;
            if ($bare !== '' && ! isset($out[$bare])) {
                $out[$bare] = $bare;
            }
        }

        return array_values($out);
    }

    private function isColorWord(string $word): bool
    {
        $word = mb_strtolower(trim($word));
        $word = strtr($word, ['ą' => 'a', 'ć' => 'c', 'ę' => 'e', 'ł' => 'l', 'ń' => 'n', 'ó' => 'o', 'ś' => 's', 'ź' => 'z', 'ż' => 'z']);

        return in_array($word, [
            'black', 'white', 'grey', 'gray', 'blue', 'green', 'red', 'yellow',
            'orange', 'navy', 'brown', 'beige', 'pink',
        ], true);
    }

    private function skuHasDigitSegment(string $sku): bool
    {
        foreach (preg_split('/[\-\/ ]+/u', trim($sku)) ?: [] as $segment) {
            if (preg_match('/\d/u', (string) $segment) === 1) {
                return true;
            }
        }

        return false;
    }

    private function isApparelTypeWord(string $word): bool
    {
        $word = mb_strtolower(trim($word));
        $word = strtr($word, ['ą' => 'a', 'ć' => 'c', 'ę' => 'e', 'ł' => 'l', 'ń' => 'n', 'ó' => 'o', 'ś' => 's', 'ź' => 'z', 'ż' => 'z']);

        return in_array($word, [
            'kurtka', 'bluza', 'spodnie', 'kamizelka', 'rekawice', 'rekawica',
            'buty', 'polbuty', 'trzewiki', 'sandaly',
            'czapka', 'czapki', 'kombinezon', 'koszula', 'plaszcz', 'ogrodniczki',
        ], true);
    }

    /**
     * @param  list<string>  $segments
     */
    private function skuHasApparelTypeSegment(array $segments): bool
    {
        foreach ($segments as $segment) {
            if ($this->isApparelTypeWord((string) $segment)) {
                return true;
            }
        }

        return false;
    }

    /**
     * @return list<string>
     */
    private function manufacturerKeyCandidates(Product $product): array
    {
        $brand = mb_strtolower($this->shortBrand((string) $product->manufacturer));
        $norm = trim((string) preg_replace('/[^a-z0-9]+/u', '-', $brand), '-');
        if ($norm === '') {
            return [];
        }
        $out = [$norm];
        $parts = explode('-', $norm);
        if (($parts[0] ?? '') !== '') {
            $out[] = $parts[0];
        }
        if (isset($parts[1])) {
            $out[] = $parts[0].'-'.$parts[1];
        }

        return array_values(array_unique($out));
    }

    /**
     * @param  array<string, mixed>  $map
     * @param  list<string>  $keys
     * @return list<string>
     */
    private function hostsFromConfigMap(array $map, array $keys): array
    {
        $out = [];
        foreach ($map as $key => $domains) {
            if (! is_string($key) || ! is_array($domains)) {
                continue;
            }
            $nk = trim((string) preg_replace('/[^a-z0-9]+/u', '-', mb_strtolower($key)), '-');
            $hit = false;
            foreach ($keys as $want) {
                if ($nk === $want || str_contains($want, $nk) || str_contains($nk, $want)) {
                    $hit = true;
                    break;
                }
            }
            if (! $hit) {
                continue;
            }
            foreach ($domains as $domain) {
                if (! is_string($domain)) {
                    continue;
                }
                $host = mb_strtolower(trim(preg_replace('#^https?://#i', '', $domain) ?? $domain));
                $host = rtrim(explode('/', $host)[0] ?? $host, '/');
                $host = preg_replace('/^www\./', '', $host) ?? $host;
                if ($host !== '') {
                    $out[$host] = true;
                }
            }
        }

        return array_keys($out);
    }
}
