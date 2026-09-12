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

    /** /pl/pl bywa 404, a karta żyje na /us/en lub /gb/en. */
    private const ANSELL_CARD_LOCALES = ['pl/pl', 'gb/en', 'us/en'];

    /**
     * Typ z nazwy produktu (rękawice, kombinezon…) musi wrócić w URL/tekście karty.
     *
     * @var array<string, list<string>>
     */
    private const TYPE_STEMS = [
        'gloves' => ['rekawic', 'rukavic', 'glove', 'glv', 'handschuh', 'gant'],
        'coverall' => [
            'kombinezon', 'kombineza', 'coverall', 'overall', 'cvrl',
            'protective suit', 'protection suit', 'chin strap', 'chinstrap',
        ],
        'jacket' => ['kurtk', 'kangurk', 'jacket', 'jacke', 'plaszcz', 'bunda', 'parka'],
        'cape' => ['peleryn', 'poncho', 'poncz'],
        'trousers' => ['spodn', 'trouser', 'pant', 'ogrodniczk', 'dungaree', 'bib brace', 'kalhot'],
        'cap' => ['czapk', 'czepek', 'czepk'],
        'sweatshirt' => ['bluza', 'sweatshirt'],
        'vest' => ['kamizelk', 'vest'],
        'mask' => ['maska', 'maski', 'maske'],
        'footwear' => [
            'buty', 'butow', 'obuwie', 'obuv', 'boty', 'trzewik', 'polbut', 'footwear',
            'klapk', 'chodak', 'clog', 'sandal', 'schuh', 'chaussure', 'scarpa', 'zapato',
            'calzado', 'kotnik', 'monterk', 'shoe', 'boot', 'wader', 'woder', 'spodniobut',
        ],
        'helmet' => ['kask', 'helm', 'casque'],
        'goggles' => ['okular', 'gogl', 'brille', 'eyewear', 'spectacle'],
        'apron' => ['fartuch', 'apron'],
        'hearing' => ['nausznik', 'ochronnik', 'earmuff', 'headset'],
        'harness' => ['szelk'],
        'clothing' => ['ubranie', 'odziez'],
        'signage' => ['tablic', 'piktogram', 'oznakowan', 'znak kierunk'],
        'extinguisher' => ['gasnic', 'extinguisher', 'feuerlosch'],
        'firstaid' => ['aptecz', 'first aid', 'firstaid', 'verbandkasten'],
        'tape' => ['tasma', 'tasmy', 'tasmow', 'adhesive tape', 'warning tape', 'isolierband', 'tape'],
        'mat' => ['chodnik', 'dywanik', 'matting', 'insulating mat'],
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
            // sam rdzeń cyfrowy (≥3) z kodu typu PROS-1001 / 101/001 — ale nie z wymiaru:
            // „200” z „LENGTH 200CM” potwierdzało pasowi kartę rękawicy alphatec-23-200
            if (preg_match_all('/\d{3,}(?!\s?(?:cm|mm|m|km|kg|g|mg|l|ml)\b)/iu', $low, $m)) {
                foreach ($m[0] as $digits) {
                    $out[] = $digits;
                }
            }
        }

        foreach ($this->modelAliases($product) as $alias) {
            $out[] = $alias;
        }
        foreach ($this->shopIdentityPhrases($product) as $phrase) {
            // „LENGTH”, „BCKL”, „150CM” z „GREY PES BLT & YKK BCKL LENGTH 150CM” — każde
            // z osobna potwierdzało przez str_contains kartę sznurowadeł „150cm length”.
            // Słaba fraza i sam wymiar nie mogą być tokenem tożsamości.
            if ($this->isWeakShopIndexPhrase($phrase, $product) || $this->isBareMeasurement($phrase)) {
                continue;
            }
            $out[] = mb_strtolower($phrase);
            $compact = preg_replace('/[^a-z0-9]+/iu', '', mb_strtolower($phrase)) ?? '';
            if ($compact !== '' && $compact !== mb_strtolower($phrase)) {
                $out[] = $compact;
            }
        }

        $out = array_values(array_unique(array_filter(
            $out,
            fn (string $t): bool => $t !== '' && mb_strlen($t) >= 3 && ! $this->isBareMeasurement($t)
        )));

        // za krótkie same „100” itd. — zostaw tylko gdy nie ma lepszych
        return $out !== [] ? $out : array_values(array_filter([mb_strtolower($sku), mb_strtolower($name)]));
    }

    /** „150cm”, „85 cm”, „500ml” — wymiar z jednostką nie identyfikuje produktu. */
    public function isBareMeasurement(string $token): bool
    {
        return preg_match('/^\d+(?:[.,]\d+)?\s?(?:cm|mm|m|km|kg|g|mg|l|ml|szt|pcs|pair|pairs|mb)$/iu', trim($token)) === 1;
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
        if ($this->imageUrlMentionsForeignBrand($url, $product)) {
            return false;
        }
        if ($this->urlSkuOnlyAsCasNumber($hay, $product)) {
            return false;
        }
        if ($this->imageUrlHasForeignType($url, $product)) {
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
        foreach ($this->variantBaseCodes($product) as $base) {
            $base = mb_strtolower(trim($base));
            $baseCompact = preg_replace('/[^a-z0-9]+/iu', '', $base) ?? $base;
            if ($base === '' || mb_strlen($baseCompact) < 3) {
                continue;
            }
            if (($this->skuTokenInImageHay($hay, $hayCompact, $base, $baseCompact)
                    || preg_match('/\bmodel[\s\-]?'.preg_quote($base, '/').'\b/u', $hay) === 1)
                && $this->imageHayHasRequiredType($url, $product)) {
                return true;
            }
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
                    // „GRZMOT” to linia (czapka + spodnie) — bez typu w URL nie pomijaj Vision
                    if ($this->isAlphanumericProductCode($token)
                        || preg_match('/^\d{4,}$/', $token) === 1
                        || $this->imageHayHasRequiredType($url, $product)) {
                        return true;
                    }
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

    /**
     * PORTWEST-A620.jpg przy produkcie Ansell — cudza marka w nazwie pliku.
     */
    public function imageUrlMentionsForeignBrand(string $url, Product $product): bool
    {
        $path = mb_strtolower(urldecode((string) (parse_url($url, PHP_URL_PATH) ?? '')));
        if ($path === '') {
            $path = mb_strtolower(urldecode($url));
        }
        $own = $this->ownBrandTokens($product);
        foreach ($this->catalogBrandTokens() as $brand) {
            if (isset($own[$brand])) {
                continue;
            }
            if (preg_match('/(?:^|[\/_.\-])'.preg_quote($brand, '/').'(?:[\/_.\-]|$)/u', $path) === 1) {
                return true;
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
        // Ansell PIM — tylko packshoty produktów. Całe /-/media/ to też banery i zdjęcia
        // „lifestyle” (osoba pisząca list trafiła jako zdjęcie KleenGuard G40).
        if (str_contains($host, 'ansell') || ($brand === 'ansell' && str_contains($u, 'product-assets'))) {
            return str_contains($u, 'product-assets');
        }
        if (str_contains($host, 'urgent.com.pl') || str_contains($host, 'urgent.pl')) {
            return str_contains($u, 'wp-content')
                || str_contains($u, 'upload')
                || str_contains($u, 'product')
                || preg_match('/(?<![0-9])\d{3,4}(?![0-9])/u', $u) === 1;
        }
        // 3M Media Web Server — id bez SKU w nazwie (3m-pps-kit.jpg)
        if ($this->manufacturerIsThreeM($product)
            && ($host === 'multimedia.3m.com' || str_ends_with($host, '.multimedia.3m.com'))
            && str_contains($u, '/mws/media/')) {
            return true;
        }
        // Magento / Presta / Shoper — galeria karty bez SKU w nazwie pliku
        if (str_contains($u, 'media/catalog/product') || str_contains($u, 'large_default') || str_contains($u, 'pim/products')
            || str_contains($u, '/userdata/public/gfx/')
            || (preg_match('#/productgfx_\d+_\d+_\d+/#i', $u) === 1
                && ! ProductImageDownloader::isSmallShoperCacheUrl($u))) {
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
            if (in_array($part, ['size', 'rozmiar', 'gloves', 'glove', 'rekawice', 'rękawice', 'foam', 'with'], true)
                || $this->isGenericCatalogNameWord($part) || $this->isColorWord($part)) {
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
        // RINGERS 259B (SKU 259B-13): model z literą nie łapie się w \d{2,3}\b, więc za
        // model brany był rozmiar „13” → r013. Karta Ansella to ringers-r259b.
        if (preg_match('/\bringers?\s+r?[\s\-]?(\d{2,3}[a-z])\b/u', $name, $lm) === 1) {
            $out[] = 'r'.$lm[1];
            $out[] = 'r-'.$lm[1];
            $out[] = $lm[1];
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
        // HyFlex 11919VP100 → 11-919 (tak piszą Ansell i sklepy)
        $gloveModel = $this->ansellGloveModel($product);
        if ($gloveModel !== null) {
            $out[] = $gloveModel;
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

        if (preg_match('/^([A-Z]{2})(\d{2})[A-Z]?-0*(\d{3,5})(?:-\d{2})?(?:-G\d{2})?$/', $sku, $m) === 1) {
            $decade = (int) $m[2];
            // AC01P-00070-00 to część / akcesorium, nie kolor AC + model 70 kombinezonu.
            if ($decade >= 15 && $decade <= 59) {
                $bits['color'] = $m[1];
                $bits['prefix'] = explode('-', $sku)[0] ?? null;
                $bits['series'] = (string) ($decade * 100);
                $model = ltrim($m[3], '0');
                $bits['model'] = $model !== '' ? $model : null;
            }
        }
        if (preg_match('/\b([1-6]\d{3})-([A-Z]{2})\b/', $name, $m) === 1) {
            $bits['series'] ??= $m[1];
            $bits['color'] ??= $m[2];
        }
        if (preg_match('/\b([1-6]\d{3})\b/', $name, $m) === 1) {
            $bits['series'] ??= $m[1];
        }
        $fromName = null;
        if (preg_match('/(?:HOOD|MODEL|CVRL|APRON)\s+(\d{3})\b/', $name, $m) === 1
            || preg_match('/\b(\d{3})-G\d{2}\b/', $name, $m) === 1) {
            $fromName = $m[1];
        }
        $fromType = $this->ansellModelFromGarmentType($name, $bits['series']);
        $fromSizeToken = null;
        if (preg_match('/\b(\d{3})\.\d+XL\b/', $name, $m) === 1) {
            $fromSizeToken = $m[1];
        }
        $bits['model'] ??= $fromName ?? $fromType ?? $fromSizeToken;
        // „AlphaTec 66-300 model 111-G09” — seria z myślnikiem, nie 4-cyfrówka 1500/4000
        if ($bits['series'] === null && $bits['model'] !== null
            && preg_match('/\b(\d{2}-\d{3})\b/', $name, $hyphenSeries) === 1) {
            $bits['series'] = $hyphenSeries[1];
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
        $name = trim($name, " \t-");
        $model = $this->ansellCatalogBits($product)['model'];
        if (is_string($model) && $model !== ''
            && preg_match('/\s+(\d{3})$/', $name, $m) === 1
            && $m[1] !== $model) {
            $name = trim((string) preg_replace('/\s+\d{3}$/', '', $name));
        }

        return $name;
    }

    public function ansellStyleCodes(Product $product): array
    {
        $bits = $this->ansellCatalogBits($product);
        if ($bits['model'] === null && $bits['series'] === null && $bits['prefix'] === null) {
            return [];
        }

        $hyphenSeries = is_string($bits['series']) && preg_match('/^\d{2}-\d{3}$/', $bits['series']) === 1;
        $out = array_filter([
            $hyphenSeries ? null : $bits['model'],
            $bits['series'],
            $bits['color'],
            $bits['prefix'] !== null ? mb_strtolower($bits['prefix']) : null,
        ], static fn (?string $v): bool => $v !== null && $v !== '');
        if ($bits['series'] !== null && $bits['color'] !== null) {
            $out[] = $bits['series'].'-'.$bits['color'];
            $out[] = $bits['series'].$bits['color'];
        }
        if ($bits['model'] !== null && ! $hyphenSeries) {
            $out[] = str_pad($bits['model'], 5, '0', STR_PAD_LEFT);
        }

        return array_values(array_unique(array_map(static fn (string $v): string => $v, $out)));
    }

    /**
     * Cennik Ansell często daje rozmiar zamiast modelu: „C/W HOOD, CHIN STRAP 181.5XL”.
     * To kroj 111 (kaptur + pasek pod brodę), nie model 181.
     */
    private function ansellModelFromGarmentType(string $name, ?string $series): ?string
    {
        if ($series === null || $series === '') {
            return null;
        }
        $n = mb_strtoupper($name);
        if (str_contains($n, 'CHIN STRAP') || str_contains($n, 'CHINSTRAP')) {
            return '111';
        }

        return null;
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
                $line = $this->ansellIsBioClean($product) ? 'BioClean' : 'AlphaTec';
                $out[] = $line.' '.$series.' '.$model;
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
            $type = $this->requiredArticleTypeLabel($product) ?? 'kombinezon';
            $out[] = 'Ansell '.$model.' '.$type;
            $stripped = $this->skuWithoutLeadingZeros($sku);
            if ($stripped !== '' && $stripped !== $sku) {
                $out[] = $stripped;
            }
        }

        return array_values(array_unique($out));
    }

    /**
     * bpbhp i ansell.com pierwsze — reszta to zmapowane sklepy z config.
     *
     * @return list<string>
     */
    public function ansellSearchHosts(Product $product): array
    {
        $hosts = $this->bareHosts(array_merge(
            $this->catalogSearchHosts($product),
            $this->officialCatalogHosts($product),
            ['bpbhp.pl', 'ansell.com']
        ));
        $prefer = ['bpbhp.pl', 'ansell.com'];
        $head = [];
        foreach ($prefer as $host) {
            if (in_array($host, $hosts, true)) {
                $head[] = $host;
            }
        }
        $tail = array_values(array_filter(
            $hosts,
            static fn (string $host): bool => ! in_array($host, $prefer, true)
        ));

        return array_values(array_unique(array_merge($head, $tail)));
    }

    /**
     * AC01P-00070-00 — część z cennika, nie „Ansell -AC 00070”.
     * Cennik pisze Glove Link, sklepy i Ansell — Glove Connector.
     *
     * @return list<string>
     */
    private function ansellPartShopPhrases(Product $product): array
    {
        $sku = strtoupper(trim((string) $product->sku));
        if (preg_match('/^[A-Z]{2}(\d{2})[A-Z]?-\d{5}(?:-\d{2})?(?:-[A-Z0-9]+)?$/', $sku, $m) !== 1
            || (int) $m[1] >= 15) {
            return [];
        }
        $name = trim((string) $product->name);
        $base = $name !== '' && mb_strlen($name) >= 8 ? $name : $sku;
        $out = [$base];
        $asConnector = trim((string) preg_replace('/\bglove\s+link\b/iu', 'Glove Connector', $base));
        $asLink = trim((string) preg_replace('/\bglove\s+connector\b/iu', 'Glove Link', $base));
        foreach ([$asConnector, $asLink] as $alt) {
            if ($alt !== '' && strcasecmp($alt, $base) !== 0) {
                $out[] = $alt;
            }
        }
        $expanded = $this->expandAnsellPartCatalogName($base);
        if ($expanded !== '' && strcasecmp($expanded, $base) !== 0) {
            $out[] = $expanded;
        }

        return array_values(array_unique($out));
    }

    /** PASSTHRU/WHSTL/BLT z cennika — sklepy piszą pass-through / whistle / belt. */
    private function expandAnsellPartCatalogName(string $name): string
    {
        $text = trim((string) preg_replace('/\s*&\s*/u', ' ', $name));
        $text = (string) preg_replace('/\bpassthru\b/iu', 'pass-through', $text);
        $text = (string) preg_replace('/\bpass[\s\-]?thru\b/iu', 'pass-through', $text);
        $text = (string) preg_replace('/\bwhstl\b/iu', 'whistle', $text);
        $text = (string) preg_replace('/\bblt\b/iu', 'belt', $text);
        $text = (string) preg_replace('/\bbckl\b/iu', 'buckle', $text);
        $text = (string) preg_replace('/\bavnt\b/iu', 'AlphaTec', $text);
        $text = (string) preg_replace('/\blength\b/iu', '', $text);
        $text = (string) preg_replace('/\b(\d+)\s*cm\b/iu', '$1cm', $text);
        $text = trim((string) preg_replace('/\s+/u', ' ', $text));
        if ($text === '') {
            return '';
        }
        if (preg_match('/\b(alphatec|hyflex|bioclean|kleenguard)\b/iu', $text) !== 1) {
            $text = 'AlphaTec '.$text;
        }
        $keep = [
            'alphatec' => 'AlphaTec',
            'ykk' => 'YKK',
            'pes' => 'PES',
            'rectus' => 'Rectus',
        ];
        $words = [];
        foreach (preg_split('/\s+/u', $text) ?: [] as $word) {
            $low = mb_strtolower($word);
            if (isset($keep[$low])) {
                $words[] = $keep[$low];
            } elseif (preg_match('/^\d/u', $word) === 1) {
                $words[] = $word;
            } else {
                $words[] = mb_strtoupper(mb_substr($word, 0, 1)).mb_strtolower(mb_substr($word, 1));
            }
        }

        return trim(implode(' ', $words));
    }

    /** AC01P-00070-00 — część z cennika, nie „Ansell -AC 00070”. */
    private function ansellPartShopPhrase(Product $product): string
    {
        $phrases = $this->ansellPartShopPhrases($product);
        foreach ($phrases as $phrase) {
            if (preg_match('/\bglove\s+connector\b/iu', $phrase) === 1) {
                return $phrase;
            }
        }
        foreach ($phrases as $phrase) {
            if (preg_match('/\b(pass-through|whistle|belt|buckle)\b/iu', $phrase) === 1) {
                return $phrase;
            }
        }

        return $phrases[0] ?? '';
    }

    /** AC01P-00070-00 → A01P070 (numer stylu na karcie Ansell). */
    private function ansellAccessoryStyleCode(Product $product): ?string
    {
        if (preg_match('/^AC01P-0*(\d{3,5})(?:-\d{2})?(?:-[A-Z0-9]+)?$/iu', (string) $product->sku, $m) !== 1) {
            return null;
        }

        return 'A01P'.str_pad($m[1], 3, '0', STR_PAD_LEFT);
    }

    /** AlphaTec 3000 192 — nie G02 ani magazynowe YE30T-00192-09-G01. */
    public function ansellSeriesModelPhrase(Product $product): string
    {
        $fromBits = $this->ansellSearchPhrases($product, 'early')[0] ?? '';
        if ($fromBits !== '') {
            return $fromBits;
        }

        return $this->ansellGloveSearchPhrase($product);
    }

    /** Cennik skleja „HyFlex 11580”, sklepy i Ansell piszą „HyFlex 11-580”. */
    private function ansellGloveSearchPhrase(Product $product): string
    {
        $model = $this->ansellGloveModel($product);
        $line = $this->ansellGloveLine($product);
        if ($model === null || $line === null) {
            return '';
        }
        $name = trim((string) $product->name);
        if (preg_match('/^([A-Za-z][A-Za-z0-9\-]{1,24})(?=\s)/u', $name, $m) === 1) {
            return $m[1].' '.$model;
        }

        return $line.' '.$model;
    }

    /** Cennik pisze KLNGD/KG, karty — KleenGuard G80 / G10 Flex. */
    private function kleenGuardShopPhrase(Product $product): string
    {
        $name = mb_strtoupper(trim((string) $product->name));
        if (preg_match('/\b(?:KLNGD|KLEENGUARD)\b/u', $name) !== 1
            && preg_match('/^KG\b/u', $name) !== 1) {
            return '';
        }
        if (preg_match('/\b(KGA\d{2,3}|[AG]\d{2})\b/u', $name, $m) !== 1) {
            return '';
        }
        $phrase = 'KleenGuard '.$m[1];
        $variant = $this->kleenGuardVariantKey($product);

        return match ($variant) {
            'comfort-plus' => $phrase.' Comfort Plus',
            'flex' => $phrase.' Flex',
            '2pro' => $phrase.' 2Pro',
            default => $phrase,
        };
    }

    /** G10 Comfort Plus, G10 Flex i G10 2Pro to trzy różne rękawice. */
    private function kleenGuardVariantKey(Product $product): ?string
    {
        $name = mb_strtolower(trim((string) $product->name));
        if (preg_match('/\b(?:klngd|kleenguard)\b/u', $name) !== 1
            && preg_match('/^kg\b/u', $name) !== 1) {
            return null;
        }
        if (str_contains($name, 'comfort plus') || str_contains($name, 'comfortplus')) {
            return 'comfort-plus';
        }
        if (preg_match('/\b2\s*pro\b/u', $name) === 1) {
            return '2pro';
        }
        if (preg_match('/\bflex\b/u', $name) === 1) {
            return 'flex';
        }

        return null;
    }

    private function kleenGuardHayHasVariant(string $hay, string $variant): bool
    {
        $hay = mb_strtolower($hay);

        return match ($variant) {
            'comfort-plus' => str_contains($hay, 'comfort-plus')
                || str_contains($hay, 'comfort plus')
                || str_contains($hay, 'comfortplus'),
            '2pro' => preg_match('/\b2[- ]?pro\b/u', $hay) === 1,
            'flex' => preg_match('/g10[-_ ]flex|\bflex[-_ ](?:blue|ntrl|nitrile)/u', $hay) === 1,
            default => false,
        };
    }

    private function kleenGuardPageClaimsForeignVariant(string $url, string $title, Product $product): bool
    {
        $ours = $this->kleenGuardVariantKey($product);
        if ($ours === null) {
            return false;
        }
        $hay = mb_strtolower($url.' '.$title);
        foreach (['comfort-plus', 'flex', '2pro'] as $other) {
            if ($other !== $ours && $this->kleenGuardHayHasVariant($hay, $other)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Karta KG w sklepie: nazwa cennika + SKU (ansell.com oddaje tylko landing KleenGuard).
     *
     * @return list<string>
     */
    public function kleenGuardCatalogCardUrls(Product $product): array
    {
        if ($this->kleenGuardShopPhrase($product) === '') {
            return [];
        }
        $sku = mb_strtolower(trim((string) $product->sku));
        $slug = trim((string) preg_replace('/[^a-z0-9]+/u', '-', mb_strtolower(trim((string) $product->name))), '-');
        if ($sku === '' || $slug === '') {
            return [];
        }
        if (! str_ends_with($slug, '-'.$sku)) {
            $slug .= '-'.$sku;
        }

        return ['https://labproinc.com/products/'.$slug];
    }

    /** G02 / BOOT 192 — ogon cennika, nie model karty. */
    private function isAnsellGarmentSuffixPhrase(string $phrase, Product $product): bool
    {
        if ($this->ansellCatalogBits($product)['model'] === null) {
            return false;
        }
        $phrase = trim($phrase);

        return preg_match('/^G\d{2}$/iu', $phrase) === 1
            || preg_match('/^BOOT\s+\d{3}$/iu', $phrase) === 1;
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
            return array_values(array_unique(array_merge(
                $this->ansellNamedProductOfficialUrls($product),
                $this->ansellGloveOfficialUrls($product),
            )));
        }
        $series = mb_strtolower($series);
        $model = mb_strtolower($model);
        $slugs = $this->ansellOfficialSlugs($product, $series, $model);
        $out = [];
        foreach ($slugs as $slug) {
            foreach (self::ANSELL_CARD_LOCALES as $locale) {
                $out[] = 'https://www.ansell.com/'.$locale.'/products/'.$slug;
            }
        }

        return $out;
    }

    /**
     * Model rękawicy Ansell z kodu cennika: 11919VP100 / „HyFlex 11919VP Size 10,0” → 11-919,
     * 38003PP110 → 38-003. Cennik skleja model z dopiskiem opakowania i rozmiarem,
     * a Ansell i sklepy piszą model z myślnikiem (hyflex-11-919).
     */
    public function ansellGloveModel(Product $product): ?string
    {
        $sku = mb_strtoupper(trim((string) $product->sku));
        $name = mb_strtoupper(trim((string) $product->name));
        $isAnsell = str_contains(mb_strtolower($this->shortBrand((string) $product->manufacturer)), 'ansell')
            || $this->ansellGloveLine($product) !== null;
        if (! $isAnsell) {
            return null;
        }
        if (preg_match('/^(\d{2})(\d{3})[A-Z]{1,4}\d{0,3}$/u', $sku, $m) === 1
            || preg_match('/^(\d{2})-(\d{3})(?:-\d{1,3})?$/u', $sku, $m) === 1
            || ($this->ansellGloveLine($product) !== null
                && preg_match('/\b(\d{2})-?(\d{3})[A-Z]{0,4}\b/u', $name, $m) === 1)) {
            return $m[1].'-'.$m[2];
        }

        return $this->nameModelConfirmedByCatalogSku($product);
    }

    /**
     * Kod modelu z nazwy potwierdzony kodem cennika: „EDGE 48128 Size 11.0” + SKU 48128110
     * → 48-128. Cennik skleja model z rozmiarem, więc SKU zaczyna się od kodu modelu i sam
     * za niego ręczy — nowa linia działa bez dopisywania jej nazwy do mapy. Wcześniej model
     * wychodził tylko dla ośmiu znanych linii, a EDGE, DERMASHIELD, FiberTuf i AccuTech szły
     * w zapytanie sklejone („EDGE 48128”) i nie trafiały w kartę, choć karta była w indeksie.
     */
    private function nameModelConfirmedByCatalogSku(Product $product): ?string
    {
        $skuDigits = preg_replace('/\D+/u', '', (string) $product->sku) ?? '';
        if (mb_strlen($skuDigits) < 5) {
            return null;
        }
        $name = mb_strtoupper(trim((string) $product->name));
        if (preg_match_all('/(?<!\d)(\d{2})[\s\-]?(\d{3})(?!\d)/u', $name, $hits, PREG_SET_ORDER) < 1) {
            return null;
        }
        foreach ($hits as $hit) {
            $code = $hit[1].$hit[2];
            // „AlphaTec 09430” przy SKU 9430100 — cennik gubi wiodące zero
            foreach ([$code, ltrim($code, '0')] as $variant) {
                if ($variant !== '' && str_starts_with($skuDigits, $variant)) {
                    return $hit[1].'-'.$hit[2];
                }
            }
        }

        return null;
    }

    /** Linia rękawic Ansell z nazwy — do adresu karty (hyflex-11-919). */
    private function ansellGloveLine(Product $product): ?string
    {
        $name = mb_strtolower((string) $product->name);
        foreach ([
            'hyflex' => 'hyflex',
            'alphatec' => 'alphatec',
            'activarmr' => 'activarmr',
            'touchntuff' => 'touchntuff',
            'microflex' => 'microflex',
            'sol-vex' => 'solvex',
            'solvex' => 'solvex',
            'versatouch' => 'versatouch',
        ] as $needle => $slug) {
            if (str_contains($name, $needle)) {
                return $slug;
            }
        }

        // Linia spoza mapy („EDGE 48128 Size 11.0”): nazwa cennika zaczyna się od linii,
        // a kod modelu potwierdza SKU — to wystarczy na slug adresu karty. Zgadujemy tylko
        // dla Ansella, żeby kod modelu nie zaczął powstawać dla cudzych wyrobów.
        if (! str_contains(mb_strtolower($this->shortBrand((string) $product->manufacturer)), 'ansell')) {
            return null;
        }
        if ($this->nameModelConfirmedByCatalogSku($product) !== null
            && preg_match('/^([A-Za-z][A-Za-z\-]{2,19})(?=\s)/u', trim((string) $product->name), $m) === 1) {
            return mb_strtolower($m[1]);
        }

        return null;
    }

    /**
     * AlphaTec Glove Link / Connector 070 — nazwa z cennika, nie seria 1500/4000.
     *
     * @return list<string>
     */
    private function ansellNamedProductOfficialUrls(Product $product): array
    {
        if ($this->ansellCatalogBits($product)['series'] !== null
            || $this->ansellGloveModel($product) !== null) {
            return [];
        }
        $slugs = [];
        $phrases = $this->ansellPartShopPhrases($product);
        $preferred = $this->ansellPartShopPhrase($product);
        if ($preferred !== '') {
            $phrases = array_values(array_unique([$preferred, ...$phrases]));
        }
        foreach ($phrases as $phrase) {
            $slug = $this->ansellNamedProductSlug($phrase);
            if ($slug !== null) {
                $slugs[] = $slug;
            }
        }
        if ($slugs === []) {
            $slug = $this->ansellNamedProductSlug(trim((string) $product->name));
            if ($slug !== null) {
                $slugs[] = $slug;
            }
        }
        $out = [];
        foreach (array_values(array_unique($slugs)) as $slug) {
            foreach (self::ANSELL_CARD_LOCALES as $locale) {
                $out[] = 'https://www.ansell.com/'.$locale.'/products/alphatec-'.$slug;
            }
        }

        return $out;
    }

    /** AlphaTec Glove Connector 070 → glove-connector; pass-through whistle Rectus 96KS → pass-through-whistle-rectus. */
    private function ansellNamedProductSlug(string $phrase): ?string
    {
        $phrase = trim((string) preg_replace('/\s+[A-Z]*\d{2,4}[A-Z]{0,3}$/iu', '', $phrase));
        if (preg_match('/^alphatec\s+((?:[a-z][a-z0-9\-]{2,}(?:\s+[a-z][a-z0-9\-]{2,}){0,5}))$/iu', $phrase, $m) !== 1) {
            return null;
        }
        $rest = trim((string) preg_replace('/[^a-z0-9]+/iu', '-', mb_strtolower($m[1])), '-');
        if ($rest === '' || mb_strlen($rest) < 4) {
            return null;
        }

        return $rest;
    }

    private function ansellGloveOfficialUrls(Product $product): array
    {
        $model = $this->ansellGloveModel($product);
        $line = $this->ansellGloveLine($product);
        if ($model === null || $line === null) {
            return [];
        }
        $out = [];
        foreach (self::ANSELL_CARD_LOCALES as $locale) {
            $out[] = 'https://www.ansell.com/'.$locale.'/products/'.$line.'-'.$model;
        }

        return $out;
    }

    /** Numer modelu rękawicy Ansell na stronie: 11-919, 11 919 albo 11919. */
    private function hayHasAnsellGloveModel(string $hay, string $model): bool
    {
        [$head, $tail] = explode('-', $model);

        return preg_match('/(?<!\d)'.$head.'[\s\-]?'.$tail.'(?!\d)/u', mb_strtolower($hay)) === 1;
    }

    /** Karta HyFlex 11-842 przy naszym 11-919 — inny model tej samej linii. */
    private function urlOrTitleHasForeignAnsellGloveModel(string $url, string $title, Product $product): bool
    {
        $ours = $this->ansellGloveModel($product);
        if ($ours === null) {
            return false;
        }
        $hay = mb_strtolower(urldecode((string) (parse_url($url, PHP_URL_PATH) ?? '')).' '.$title);
        if ($this->hayHasAnsellGloveModel($hay, $ours)) {
            return false;
        }
        if (preg_match_all('/(?<!\d)(\d{2})[\s\-](\d{3})(?!\d)/u', $hay, $hits, PREG_SET_ORDER) < 1) {
            return false;
        }
        foreach ($hits as $hit) {
            if ($ours !== $hit[1].'-'.$hit[2]) {
                return true;
            }
        }

        return false;
    }

    /**
     * @return list<string>
     */
    private function ansellOfficialSlugs(Product $product, string $series, string $model): array
    {
        if ($this->ansellIsBioClean($product)) {
            return [
                'bioclean-'.$series.'-hooded-coverall-model-'.$model,
                'bioclean-'.$series.'-coverall-with-hood-model-'.$model,
                'bioclean-'.$series.'-sterile-hooded-coverall-model-'.$model,
            ];
        }
        $name = mb_strtoupper((string) $product->name);
        $apron = str_contains($name, 'APRON') || str_contains($name, 'FARTUCH');
        $standardSlugs = [
            'alphatec-'.$series.'-standard-model-'.$model,
            'alphatec-'.$series.'-standard-bound-model-'.$model,
        ];
        $otherCoverallSlugs = [
            'alphatec-'.$series.'-ultrasonically-welded-taped-model-'.$model,
            'alphatec-'.$series.'-plus-model-'.$model,
            'alphatec-'.$series.'-stitched-taped-model-'.$model,
        ];
        $coverallSlugs = $this->ansellPrefersStandardSlug($product)
            ? [...$standardSlugs, ...$otherCoverallSlugs]
            : [
                'alphatec-'.$series.'-ultrasonically-welded-taped-model-'.$model,
                'alphatec-'.$series.'-standard-model-'.$model,
                'alphatec-'.$series.'-plus-model-'.$model,
                'alphatec-'.$series.'-standard-bound-model-'.$model,
                'alphatec-'.$series.'-stitched-taped-model-'.$model,
            ];
        $apronSlugs = [
            'alphatec-'.$series.'-standard-apron-stitched-model-'.$model,
            'alphatec-'.$series.'-apron-ultrasonically-welded-model-'.$model,
            'alphatec-'.$series.'-apron-stitched-model-'.$model,
        ];

        return $apron ? [...$apronSlugs, ...$coverallSlugs] : [...$coverallSlugs, ...$apronSlugs];
    }

    public function ansellIsBioClean(Product $product): bool
    {
        $blob = mb_strtoupper(trim((string) $product->name.' '.(string) $product->sku));

        return str_contains($blob, 'BIOCLEAN')
            || str_contains($blob, 'TSPLUS')
            || str_contains($blob, 'TS-PLUS')
            || (bool) preg_match('/-BC-/', $blob);
    }

    /** WH20B / „STD CVRL” → AlphaTec Standard, nie BioClean i nie taped. */
    public function ansellPrefersStandardSlug(Product $product): bool
    {
        if ($this->ansellIsBioClean($product)) {
            return false;
        }
        $sku = strtoupper(trim((string) $product->sku));
        $name = mb_strtoupper((string) $product->name);

        return str_contains($name, ' STD ')
            || str_contains($name, 'STANDARD')
            || preg_match('/^[A-Z]{2}\d{2}B(?:-|$)/', $sku) === 1;
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
        $line = $this->ansellIsBioClean($product) ? 'bioclean' : 'alphatec';
        if (! str_contains($hay, 'ansell.com') && ! str_contains($hay, $line.'-'.$series)) {
            return false;
        }

        return preg_match(
            '/'.$line.'[-_]?'.preg_quote($series, '/').'\b/u',
            $hay
        ) === 1
            && preg_match('/(?:^|[^0-9])modell?[-_ ]'.preg_quote($model, '/').'(?:[^0-9]|$)/u', $hay) === 1;
    }

    /**
     * Karta ansell.com/…/products/alphatec-airline-passthrough dla „AVNT PASSTHRU WHSTL …”:
     * slug nie niesie SKU części, ale niesie rozwinięty typ z cennika (PASSTHRU → pass-through).
     * Pisownie passthrough / pass-through / passthru liczymy jako jedno. Pas (BLT) tej strony nie dostaje.
     */
    private function ansellOfficialPathHasPartType(string $hay, Product $product): bool
    {
        if ($this->ansellPartShopPhrases($product) === []) {
            return false;
        }
        // lokalizacja to pl/pl, au/en albo int/en
        if (preg_match('~ansell\.com/(?:[a-z]{2,3}/[a-z]{2}/)?products/([a-z0-9\-]+)~iu', $hay, $m) !== 1) {
            return false;
        }
        $slug = preg_replace('/[^a-z0-9]+/u', '', mb_strtolower($m[1])) ?? '';
        $expanded = mb_strtolower($this->expandAnsellPartCatalogName(trim((string) $product->name)));
        $partTypes = [
            'pass-through' => ['passthrough', 'passthru'],
            'whistle' => ['whistle'],
            'belt' => ['belt'],
            'buckle' => ['buckle'],
        ];
        foreach ($partTypes as $type => $slugSpellings) {
            if (preg_match('/\b'.preg_quote($type, '/').'\b/u', $expanded) !== 1) {
                continue;
            }
            foreach ($slugSpellings as $spelling) {
                if (str_contains($slug, $spelling)) {
                    return true;
                }
            }
        }

        return false;
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
        $brand = $this->manufacturerLooksUnrelatedToProduct($product)
            ? ''
            : $this->shortBrand((string) $product->manufacturer);
        $sku = trim((string) $product->sku);
        $name = trim((string) $product->name);
        $internalSku = $this->looksLikeInternalSku($product);
        $shopPhrase = $this->firstStrongShopPhrase($product);
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
            $phrase = $this->ansellSeriesModelPhrase($product);
            if ($phrase !== '') {
                foreach ($this->ansellSearchHosts($product) as $host) {
                    $queries[] = 'site:'.$host.' '.$phrase;
                }
                $queries[] = $this->queryWithManufacturer($phrase, $product);
            }
            foreach ($this->ansellSearchPhrases($product, 'late') as $late) {
                $queries[] = $late;
            }
        }

        if ($phase === 'manufacturer' && ! $this->queriesContainSite($queries)) {
            $hosts = $this->catalogSearchHosts($product);
            $phrase = $this->catalogSitePhrase($product);
            if ($phrase !== '' && $hosts !== []) {
                $extra = $this->sharedShortSkuQueryExtra($product);
                $sitePhrase = trim($phrase.($extra !== '' ? ' '.$extra : ''));
                foreach ($hosts as $host) {
                    $queries[] = 'site:'.$host.' '.$sitePhrase;
                }
            }
        }

        $queries[] = $this->productNameWithManufacturer($product);

        // 1) Jak Google — kod / nazwa zawsze z producentem (też 12-cyfrowy numer CXS)
        if ($sku !== '' && ! $internalSku) {
            $withoutSize = $this->catalogSkuWithoutSize($product);
            if ($withoutSize !== '' && mb_strtolower($withoutSize) !== mb_strtolower($sku)) {
                $queries[] = $this->queryWithManufacturer($this->quoteSearchOperators($withoutSize), $product);
            }
            $queries[] = $this->queryWithManufacturer($this->quoteSearchOperators($sku), $product);
            $queries[] = $this->queryWithManufacturer('"'.$sku.'"', $product);
            foreach ($this->skuSearchNeedles($product) as $needle) {
                if (mb_strtolower($needle) === mb_strtolower($sku)
                    || mb_strtolower($needle) === mb_strtolower($withoutSize)) {
                    continue;
                }
                $queries[] = $this->queryWithManufacturer($this->quoteSearchOperators($needle), $product);
            }
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
        $name = $this->usableProductName($product);
        $usableSku = $sku !== '' && ! $this->looksLikeInternalSku($product);
        // „PROS-121-S1-GUMA” to nasz kod złożony z opisu — w sieci działa dopiero
        // nazwa z producentem („121 S1 GUMA Urgent”), więc ona idzie pierwsza.
        $composedSku = $this->hasDescriptiveWordSegment($sku);
        $shopName = $this->firstStrongShopPhrase($product);
        $fullNameEarly = $this->strippedProductName($product);
        if ($fullNameEarly === '') {
            $fullNameEarly = $name;
        }
        // Model z nazwy (BEAGLE, C500, Carbon ESD PU Top) przed numerem magazynowym.
        $bareNumeric = $this->skuIsBareNumericModel($product);
        $preferCatalogName = $composedSku || $shopName !== ''
            || $this->skuIsSharedShortCode($product)
            || ($this->looksLikeCompactTradeName($fullNameEarly)
                && ! $this->hasDistinctiveCatalogSku($product));

        $skuQueries = [];
        $withoutSize = $this->catalogSkuWithoutSize($product);
        if ($usableSku && $withoutSize !== '' && mb_strtolower($withoutSize) !== mb_strtolower($sku)) {
            $skuQueries[] = $this->queryWithManufacturer($this->quoteSearchOperators($withoutSize), $product);
        }
        if ($usableSku && $shopName === '') {
            if (! $this->rawSkuIsOfflineNoise($product)) {
                $skuQueries[] = $this->queryWithManufacturer($this->quoteSearchOperators($sku), $product);
                $bare = $this->stripBrandPrefix($sku, $brand);
                if ($bare !== '' && $bare !== $sku) {
                    $skuQueries[] = $this->queryWithManufacturer($this->quoteSearchOperators($bare), $product);
                }
            }
        } elseif ($usableSku && ! $this->rawSkuIsOfflineNoise($product)) {
            $skuQueries[] = $this->queryWithManufacturer($this->quoteSearchOperators($sku), $product);
        }
        if ($usableSku && $this->skuIsSharedShortCode($product)) {
            $extra = $this->sharedShortSkuQueryExtra($product);
            if ($extra !== '') {
                $skuQueries = [$this->queryWithManufacturer(trim($sku.' '.$extra), $product)];
            }
        }
        if ($bareNumeric && $usableSku && $shopName === ''
            && ! $this->looksLikeCompactTradeName($fullNameEarly)) {
            $type = $this->requiredArticleTypeLabel($product);
            $typeBit = $type !== null ? trim(explode('/', $type)[0]) : '';
            $modelQuery = trim('model '.$sku.($typeBit !== '' ? ' '.$typeBit : ''));
            array_unshift($skuQueries, $this->queryWithManufacturer($modelQuery, $product));
        }
        if ($usableSku) {
            foreach ($this->skuSearchNeedles($product) as $needle) {
                if (mb_strtolower($needle) === mb_strtolower($sku)
                    || mb_strtolower($needle) === mb_strtolower($withoutSize)) {
                    continue;
                }
                $skuQueries[] = $this->queryWithManufacturer($this->quoteSearchOperators($needle), $product);
            }
        }

        $nameQueries = [];
        $fullName = $this->strippedProductName($product);
        if ($fullName === '') {
            $fullName = $name;
        }
        $compactName = $this->looksLikeCompactTradeName($fullName);
        // Katalogowa fraza sklepu (COMO HAPPY, KENT S3) zostaje pierwsza.
        // Jedno słowo z nazwy („Carbon”) nie zastępuje „Carbon ESD PU Top Eider”.
        if ($shopName !== '' && (! $this->shopPhraseIsWeakerThanName($shopName, $fullName)
            || preg_match('/\d|[-\/]/u', $shopName) === 1)) {
            // Jednowyrazowa fraza wyjęta z dłuższej nazwy („Compo” z „BASIC Compo Low S3”)
            // zostaje w zapytaniach, ale nie wyprzedza pełnej nazwy — to ona stoi w sklepie.
            if ($fullName !== '' && $this->shopPhraseIsLoneWordOfName($shopName, $fullName)) {
                $nameQueries[] = $this->queryWithManufacturer($fullName, $product);
            }
            $nameQueries[] = $this->queryWithManufacturer($shopName, $product);
        }
        if ($compactName && mb_strtolower($fullName) !== mb_strtolower($sku)
            && mb_strtolower($fullName) !== mb_strtolower($shopName)) {
            $nameQueries[] = $this->queryWithManufacturer($fullName, $product);
        } elseif ($nameQueries === [] && $fullName !== '' && mb_strtolower($fullName) !== mb_strtolower($sku)) {
            $nameQueries[] = $this->queryWithManufacturer($fullName, $product);
        }
        if ($usableSku && ! $composedSku && $fullName !== ''
            && mb_strtolower($fullName) !== mb_strtolower($sku)
            && ! $this->phraseHasToken($fullName, $sku)) {
            $nameQueries[] = $this->queryWithManufacturer($fullName.' '.$sku, $product);
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
        // Numer magazynowy (212804580000) w sklepie stoi rzadko, ale u dystrybutora bywa.
        // Nie otwiera drabiny — zamyka ją, po nazwie i kodach katalogowych.
        if ($usableSku && $shopName !== '' && $this->rawSkuIsOfflineNoise($product)) {
            $out[] = $this->queryWithManufacturer($this->quoteSearchOperators($sku), $product);
        }
        foreach ($this->variantBaseCodes($product) as $base) {
            $out[] = $this->queryWithManufacturer($base, $product);
        }
        if ($withoutSize !== '' && mb_strtolower($withoutSize) !== mb_strtolower($sku)
            && ! $composedSku && ! $this->looksLikeInternalSku($product)
            && $this->distributorPrefixedCatalogSku($product) === '') {
            array_unshift($out, $this->queryWithManufacturer($withoutSize, $product));
        }
        $hintBrand = $this->inferredBrandHint($product);
        if ($hintBrand !== '') {
            $catalog = $this->distributorPrefixedCatalogSku($product);
            if ($catalog !== '') {
                array_unshift($out, trim($catalog.' '.$hintBrand));
            }
            $trade = $this->strippedProductName($product);
            if ($trade !== '') {
                array_unshift($out, trim($trade.' '.$hintBrand));
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
        $name = $this->usableProductName($product);
        $sku = trim((string) $product->sku);
        $parts = [];
        if ($name !== '') {
            $parts[] = $name;
        }
        if ($sku !== '' && ! $this->looksLikeInternalSku($product)
            && ! $this->hasDescriptiveWordSegment($sku)
            && ($name === '' || ! $this->phraseHasToken($name, $sku))) {
            $catalog = $this->distributorPrefixedCatalogSku($product);
            $parts[] = $this->quoteSearchOperators($catalog !== '' ? $catalog : $sku);
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
        if (preg_match('/\bsite:/i', $query) === 1) {
            return $query;
        }
        if ($this->manufacturerLooksUnrelatedToProduct($product)) {
            return $query;
        }
        $nameBrand = $this->leadingNameBrand($product);
        $hint = $this->inferredBrandHint($product);
        $brand = $hint !== '' ? $hint : $this->shortBrand((string) $product->manufacturer);
        if ($query === '') {
            return $query;
        }
        if ($nameBrand !== '' && mb_strtolower($nameBrand) !== mb_strtolower($brand)) {
            if (! $this->phraseHasToken($query, $nameBrand)) {
                $query .= ' '.$nameBrand;
            }
        } elseif ($brand !== '' && ! $this->phraseHasToken($query, $brand)) {
            $query .= ' '.$brand;
        }

        return $this->appendCatalogSearchBrand($query, $product);
    }

    /** AJ GROUP w cenniku, na sklepie „PROS model 725”. */
    private function appendCatalogSearchBrand(string $query, Product $product): string
    {
        $catalog = $this->catalogSearchBrand($product);
        if ($catalog === '' || $this->phraseHasToken($query, $catalog)) {
            return $query;
        }

        return trim($query.' '.$catalog);
    }

    /**
     * Marka z oficjalnej domeny, gdy cennik ma nazwę firmy (AJ GROUP), a sklep inną (PROS).
     * Nie dopina siostrzanej marki (Eider ≠ Cerva).
     */
    public function catalogSearchBrand(Product $product): string
    {
        $legal = mb_strtolower($this->shortBrand((string) $product->manufacturer));
        $legalCompact = preg_replace('/[^a-z0-9]+/u', '', $legal) ?? '';
        if ($legal === '' || (preg_match('/\s/u', $legal) !== 1
            && ! str_contains($legal, 'group') && ! str_ends_with($legalCompact, 'group'))) {
            return '';
        }
        $family = array_fill_keys($this->brandFamilyOf($legal), true);
        foreach ($this->officialCatalogHosts($product) as $host) {
            $host = preg_replace('/^www\./u', '', mb_strtolower(trim($host))) ?? '';
            $label = explode('.', $host)[0] ?? '';
            if ($label === '' || mb_strlen($label) < 3) {
                continue;
            }
            if ($label === $legal || $label === $legalCompact) {
                continue;
            }
            if (! isset($family[$label])) {
                continue;
            }

            return mb_strtoupper($label);
        }

        return '';
    }

    public function skuIsBareNumericModel(Product $product): bool
    {
        return preg_match('/^\d{3,4}$/u', trim((string) $product->sku)) === 1;
    }

    /**
     * SKU „TP 0 275T OR CE-L” — OR/AND/NOT to operatory wyszukiwarki, nie część kodu.
     */
    public function quoteSearchOperators(string $phrase): string
    {
        $phrase = trim((string) preg_replace('/\s+/u', ' ', $phrase));
        if ($phrase === '' || (str_starts_with($phrase, '"') && str_ends_with($phrase, '"'))) {
            return $phrase;
        }
        if (preg_match('/^(site:\S+)\s+(.+)$/i', $phrase, $site) === 1) {
            return $site[1].' '.$this->quoteSearchOperators($site[2]);
        }
        if (preg_match('/\b(?:OR|AND|NOT)\b/', $phrase) !== 1) {
            return $phrase;
        }

        return '"'.$phrase.'"';
    }

    /** Reddit / GitHub / pornosy — nie karta BHP, tylko śmieci z zepsutej wyszukiwarki. */
    public static function isJunkSearchHost(string $url): bool
    {
        $host = mb_strtolower((string) (parse_url($url, PHP_URL_HOST) ?? ''));
        $host = preg_replace('/^www\./', '', $host) ?? $host;
        if ($host === '') {
            return false;
        }
        foreach ([
            'reddit.com', 'github.com', 'githubusercontent.com',
            'microsoft.com', 'office.com', 'live.com', 'msn.com',
            'telegram.org', 't.me',
            'wikipedia.org', 'wiktionary.org', 'zhihu.com', 'quora.com',
            'sjp.pwn.pl', 'wsjp.pl', 'synonim.net', 'britannica.com',
            'olx.pl', 'olx.com',
            'facebook.com', 'instagram.com', 'twitter.com', 'x.com',
            'tiktok.com', 'youtube.com', 'youtu.be',
            'pinterest.com', 'linkedin.com',
            'stackoverflow.com', 'stackexchange.com',
            'medium.com', 'blogspot.com',
            'imdb.com', 'filmweb.pl', 'rottentomatoes.com', 'letterboxd.com',
            'joyclub.de', 'joyclub.com', 'joy-club.de',
            'spankingtube.com', 'xhamster.com', 'xgaytube.com',
            'pornhub.com', 'xvideos.com', 'xnxx.com',
        ] as $blocked) {
            if ($host === $blocked || str_ends_with($host, '.'.$blocked)) {
                return true;
            }
        }

        return false;
    }

    /** CEJN w nazwie przy producencie GVS — szukaj marki z nazwy, nie z cennika. */
    public function leadingNameBrand(Product $product): string
    {
        $name = $this->usableProductName($product);
        if ($name === '' || preg_match('/^(\p{Lu}{3,12})\b/u', $name, $hit) !== 1) {
            return '';
        }
        $brand = $hit[1];
        if (! $this->looksLikeBrandToken(mb_strtolower($brand))) {
            return '';
        }
        // KRYTECH / KENT / COMO to model, nie inna firma — zostaw producenta z cennika.
        $shop = $this->firstStrongShopPhrase($product);
        if (mb_strtolower($brand) === mb_strtolower(trim((string) $product->sku))
            || mb_strtolower($brand) === mb_strtolower($name)
            || ($shop !== '' && $this->phraseHasToken($shop, $brand))) {
            return '';
        }
        // CRACKDOWN D.GREY — model, zostaw COFRA. CEJN Double Action Coupler — inna firma niż GVS.
        if ($shop === '' && $this->phraseHasToken($name, $brand)) {
            $rest = trim((string) preg_replace('/^'.preg_quote($brand, '/').'\b/iu', '', $name));
            $words = preg_split('/\s+/u', $rest, -1, PREG_SPLIT_NO_EMPTY) ?: [];
            if (count($words) < 3) {
                return '';
            }
        }

        return $brand;
    }

    /** 471 w slugu sklepu (boe-471) to nasz CovaSpec 471, nie cudzy kod. */
    public function urlOrTitleCarriesShopModelNumber(string $url, string $title, Product $product): bool
    {
        $hay = mb_strtolower($url.' '.$title);
        $hayCompact = preg_replace('/[^a-z0-9]+/iu', '', $hay) ?? $hay;
        foreach ($this->specificModelNumbers($product) as $number) {
            if ($this->numericTokenAsProductCode($hay, (string) $number)
                || $this->numericTokenAsProductCode($hayCompact, (string) $number)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Numer modelu z frazy sklepu i SKU (471, 121), nie wspólna linia (4000).
     *
     * @return list<string>
     */
    private function specificModelNumbers(Product $product): array
    {
        $fromSku = [];
        if (preg_match_all('/\d{3,5}/u', (string) $product->sku, $hits) !== false) {
            foreach ($hits[0] as $n) {
                $fromSku[] = (string) $n;
            }
        }
        $ansell = $this->ansellCatalogBits($product)['model'] ?? null;
        if (is_string($ansell) && $ansell !== '') {
            $fromSku[] = $ansell;
            $fromSku[] = ltrim($ansell, '0');
        }
        $fromShop = [];
        if (preg_match_all('/\d{3,5}/u', $this->firstStrongShopPhrase($product), $hits) !== false) {
            foreach ($hits[0] as $n) {
                $fromShop[] = (string) $n;
            }
        }
        if ($fromSku !== [] && $fromShop !== []) {
            $overlap = [];
            foreach ($fromShop as $shop) {
                foreach ($fromSku as $sku) {
                    if ($shop === $sku || str_ends_with($sku, $shop) || str_ends_with($shop, $sku)) {
                        $overlap[] = $shop;
                        $overlap[] = $sku;
                    }
                }
            }
            if ($overlap !== []) {
                return array_values(array_unique($overlap));
            }
        }
        $out = $fromSku;
        if ($fromShop !== []) {
            $shopModel = (string) end($fromShop);
            $ansellModel = is_string($ansell) && $ansell !== '';
            // 471 przy SKU 047106941E; nie linia 4000 przy modelu Ansell 121
            if ($this->digitsHitSpecificModel([$shopModel], $fromSku)
                || ($fromSku !== [] && ! $ansellModel && mb_strlen($shopModel) <= 4
                    && max(array_map('strlen', $fromSku)) >= 5)) {
                $out[] = $shopModel;
            } elseif ($fromSku === []) {
                $out[] = $shopModel;
            }
        }

        return array_values(array_unique($out));
    }

    /**
     * @param  list<string>  $digits
     * @param  list<string>  $specific
     */
    private function digitsHitSpecificModel(array $digits, array $specific): bool
    {
        foreach ($digits as $digit) {
            foreach ($specific as $code) {
                if ($digit === $code || str_ends_with($code, $digit) || str_ends_with($digit, $code)) {
                    return true;
                }
            }
        }

        return false;
    }

    /** Krótka nazwa handlowa, nie ogon z cennika („… - czarny nylon powlekany”). */
    private function looksLikeCompactTradeName(string $name): bool
    {
        $name = trim($name);
        if ($name === '' || mb_strlen($name) > 48) {
            return false;
        }
        $words = preg_split('/\s+/u', $name) ?: [];
        if (count($words) < 2 || count($words) > 6) {
            return false;
        }
        if (preg_match('/\s[-–]\s+\p{L}.{20,}/u', $name) === 1) {
            return false;
        }
        $specific = 0;
        foreach ($words as $word) {
            $word = trim((string) preg_replace('/[^\p{L}\p{N}]+/u', '', $word));
            if ($word === '' || $this->isGenericCatalogNameWord($word)
                || $this->isApparelTypeWord($word) || $this->isDescriptiveIdentityWord($word)) {
                continue;
            }
            $specific++;
        }

        return $specific > 0;
    }

    /** „Carbon” z „Carbon ESD PU Top” nie może zająć slotu zamiast pełnej nazwy. */
    /**
     * Fraza sklepowa to pojedyncze słowo wyjęte z nazwy, a nazwa niesie jeszcze
     * linię i klasę wyrobu („Compo” wobec „BASIC Compo Low S3”).
     */
    private function shopPhraseIsLoneWordOfName(string $shop, string $name): bool
    {
        $shop = trim($shop);
        if ($shop === '' || preg_match('/\s/u', $shop) === 1) {
            return false;
        }
        if (mb_strtolower($shop) === mb_strtolower(trim($name)) || ! $this->phraseHasToken($name, $shop)) {
            return false;
        }
        $words = array_values(array_filter(
            preg_split('/[^\p{L}\p{N}]+/u', $name) ?: [],
            static fn (string $w): bool => trim($w) !== ''
        ));

        return count($words) >= 3;
    }

    private function shopPhraseIsWeakerThanName(string $shop, string $name): bool
    {
        $shop = mb_strtolower(trim($shop));
        $name = mb_strtolower(trim($name));
        if ($shop === '' || $name === '' || $shop === $name) {
            return false;
        }
        $shopWords = preg_split('/\s+/u', $shop) ?: [];
        if (count($shopWords) !== 1 || ! $this->phraseHasToken($name, $shop)) {
            return false;
        }
        foreach (preg_split('/[^\p{L}\p{N}]+/u', $name) ?: [] as $word) {
            $word = mb_strtolower(trim((string) $word));
            if ($word === '' || $word === $shop || mb_strlen($word) < 3) {
                continue;
            }
            if ($this->isGenericCatalogNameWord($word) || $this->isPackOrSizeToken($word)
                || preg_match('/^\d+$/u', $word) === 1) {
                continue;
            }

            return true;
        }

        return false;
    }

    /** GREY-BLUE / ORANGE — sam kolor nie nadaje się na site: ani model. */
    private function isColorOnlyShopPhrase(string $phrase): bool
    {
        $useful = [];
        foreach (preg_split('/[\s\-\/]+/u', mb_strtolower(trim($phrase))) ?: [] as $word) {
            $word = trim((string) $word);
            if ($word === '' || $this->isPackOrSizeToken($word) || $this->isColorWord($word)) {
                continue;
            }
            $useful[] = $word;
        }

        return $useful === [];
    }

    public function manufacturerIsThreeM(Product $product): bool
    {
        $key = preg_replace(
            '/[^a-z0-9]+/u',
            '',
            mb_strtolower($this->shortBrand((string) $product->manufacturer))
        ) ?? '';

        return $key === '3m';
    }

    /** Karta 3M: /3M/pl_PL/p/d/v0005202/ — numer katalogowy nie stoi w adresie. */
    public function isOfficialThreeMProductUrl(string $url): bool
    {
        $host = mb_strtolower((string) (parse_url($url, PHP_URL_HOST) ?? ''));
        $host = preg_replace('/^www\./', '', $host) ?? $host;
        $official = $host === '3m.com'
            || str_ends_with($host, '.3m.com')
            || $host === '3mpolska.pl';
        if (! $official) {
            return false;
        }
        $path = (string) (parse_url($url, PHP_URL_PATH) ?? '');

        return preg_match('#/p/[di]/#i', $path) === 1;
    }

    /**
     * SJ5202 / PN60150 / 4959F / 50601 — numer z nazwy 3M, nie „1550 ml”.
     *
     * @return list<string>
     */
    public function threeMCatalogCodesFromName(Product $product): array
    {
        if (! $this->manufacturerIsThreeM($product)) {
            return [];
        }
        $name = trim((string) $product->name);
        if ($name === '') {
            return [];
        }
        $out = [];
        if (preg_match_all('/\b(SJ\d{4}[A-Z]?)\b/iu', $name, $m) !== false) {
            foreach ($m[1] as $code) {
                $out[] = strtoupper((string) $code);
            }
        }
        if (preg_match_all('/\bPN\s*(\d{4,6})\b/iu', $name, $m) !== false) {
            foreach ($m[1] as $n) {
                $out[] = 'PN'.$n;
                $out[] = (string) $n;
            }
        }
        if (preg_match_all('/\b(\d{4,5}[A-Z]{1,2})\b/u', $name, $m) !== false) {
            foreach ($m[1] as $code) {
                $out[] = (string) $code;
            }
        }
        if (preg_match_all('/\b(0?\d{4,5})\b/u', $name, $m) !== false) {
            foreach ($m[1] as $n) {
                $n = (string) $n;
                if ($this->threeMNumberLooksLikeMeasure($n, $name)) {
                    continue;
                }
                $out[] = $n;
                $stripped = ltrim($n, '0');
                if ($stripped !== '' && $stripped !== $n) {
                    $out[] = $stripped;
                }
            }
        }
        $sku = trim((string) $product->sku);
        if (preg_match_all('/\b(\d{3})\b/u', $name, $m) !== false) {
            foreach ($m[1] as $n) {
                $n = (string) $n;
                if ($this->threeMNumberLooksLikeMeasure($n, $name)) {
                    continue;
                }
                if ($n === $sku || $n === ltrim($sku, '0')) {
                    $out[] = $n;
                }
            }
        }
        $uniq = [];
        foreach ($out as $code) {
            $code = trim($code);
            if ($code === '' || mb_strlen($code) < 3) {
                continue;
            }
            $uniq[mb_strtolower($code)] = $code;
        }

        return array_values($uniq);
    }

    private function threeMNumberLooksLikeMeasure(string $n, string $name): bool
    {
        return preg_match(
            '/\b'.preg_quote($n, '/').'\s*(?:mm|ml|cm|m|l|kg|szt|per\s+case)\b/iu',
            $name
        ) === 1;
    }

    private function preferredThreeMShopPhrase(Product $product): string
    {
        $skuCompact = $this->compactCode((string) $product->sku);
        $preferred = [];
        $same = [];
        foreach ($this->threeMCatalogCodesFromName($product) as $code) {
            if (! $this->isStrongShopPhrase($code) || $this->isColorOnlyShopPhrase($code)) {
                continue;
            }
            $compact = $this->compactCode($code);
            if ($skuCompact !== '' && $compact !== '' && (
                $compact === $skuCompact
                || str_contains($skuCompact, $compact)
                || str_contains($compact, $skuCompact)
            )) {
                $same[] = $code;
            } else {
                $preferred[] = $code;
            }
        }
        if ($preferred !== []) {
            return $preferred[0];
        }

        return $same[0] ?? '';
    }

    /** „6000” przy „Tychem 6000 FR ThermoPro” — za ogólne na site:/zapytanie. */
    private function shopPhraseIsBareSeriesNumberWeakerThanTradeName(string $phrase, string $name): bool
    {
        if (preg_match('/^\d{3,5}$/u', trim($phrase)) !== 1) {
            return false;
        }
        foreach (preg_split('/[^\p{L}\p{N}]+/u', $name) ?: [] as $word) {
            $word = trim((string) $word);
            if ($word === '' || mb_strtolower($word) === mb_strtolower($phrase)) {
                continue;
            }
            if (mb_strlen($word) >= 5 && preg_match('/^\p{L}{5,16}$/u', $word) === 1
                && ! $this->isGenericCatalogNameWord($word)
                && ! $this->isApparelTypeWord($word)) {
                return true;
            }
        }

        return false;
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
        if ($this->looksLikeUnrelatedSignage($hay, $product) && ! $this->hayHasProductCode($hay, $product)) {
            return false;
        }
        if ($this->looksLikeUnrelatedApparel($hay, $product)) {
            return false;
        }
        if ($this->looksLikeUnrelatedHandToolPage($hay, $product)) {
            return false;
        }
        // Nazwa modelu z cennika (BAXTER dla BAXCSP) z marką na stronie to tożsamość
        // karty — nazwa produktu u Bollé jest opisem soczewek („taśma elastyczna”),
        // więc bramka typu z nazwy zjadłaby każdą właściwą kartę.
        $modelVerdict = $this->modelNameVerdict($hay, $product);
        if ($modelVerdict !== null) {
            return $modelVerdict;
        }
        if (! $this->hayHasRequiredTypeFromName($hay, $product)) {
            return false;
        }
        $kleenVariant = $this->kleenGuardVariantKey($product);
        if ($kleenVariant !== null && ! $this->kleenGuardHayHasVariant($hay, $kleenVariant)) {
            return false;
        }
        if ($this->gluedNumericModelConfirmsCard($hay, $product)) {
            return true;
        }
        if ($this->hayHasDistinctiveNamePhrase($hay, $product)) {
            return true;
        }
        $gloveModel = $this->ansellGloveModel($product);
        if ($gloveModel !== null
            && $this->hayHasAnsellGloveModel($hay, $gloveModel)
            && $this->hayHasBrand($hay, $product)) {
            return true;
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
            if ($this->ansellOfficialPathHasModel($hay, $product)) {
                return true;
            }
        }
        // Strona rodziny akcesorium ansell.com/…/products/alphatec-airline-passthrough
        // nie niesie SKU ani modelu — niesie rozwinięty typ części z cennika (PASSTHRU).
        if ($this->ansellOfficialPathHasPartType($hay, $product)) {
            return true;
        }

        foreach ($tokens as $token) {
            if ($this->tokenInHay($hay, $hayCompact, $token)) {
                // „Flex” / „Blue” / „Easy” — marketing, nie model (Easy Flex ≠ G10 Flex)
                if ($this->isGenericCatalogNameWord($token) || $this->isColorWord($token)) {
                    continue;
                }
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
                if ($this->tokenIsProductCode($token, $product)) {
                    if ($this->pageAgreesWithBrandAndName($hay, '', $product)) {
                        return true;
                    }
                    // Sam kod bez marki to za mało (T-31 Ardon ≠ tablica CABINAID), ale
                    // strona może nieść mocniejszy dowód niżej — tożsamość sklepową albo
                    // rdzeń cyfrowy. Kończymy przegląd tokenów, nie całe sprawdzanie.
                    break;
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
        foreach ($this->skuSearchNeedles($product) as $needle) {
            $needle = mb_strtolower($needle);
            if ($needle === '' || $needle === mb_strtolower(trim((string) $product->sku))) {
                continue;
            }
            if (! $this->tokenInHay($hay, $hayCompact, $needle)) {
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

    /**
     * Karta, z której wolno wziąć opis i zdjęcie: to ten produkt, nie inny model.
     */
    public function isConfirmedProductCard(string $url, string $title, string $text, Product $product): bool
    {
        if (self::isJunkSearchHost($url) || $this->looksLikeUnrelatedRetailHost($url, $product)
            || $this->looksLikeNonProductCardUrl($url) || $this->pageLooksLikeMultiProductListing($text)
            || $this->looksLikeUnrelatedHandToolPage($url.' '.$title.' '.$text, $product)) {
            return false;
        }
        if ($this->manufacturerIsThreeM($product) && $this->isOfficialThreeMProductUrl($url)) {
            $card = $url.' '.$title.' '.$text;
            if (($this->hayHasProductCode($card, $product)
                    || $this->urlOrTitleHasShopIdentity($url, $title.' '.$text, $product))
                && ! $this->pageClaimsAnotherCode($url, $title, $product)
                && $this->hayHasRequiredTypeFromName($card, $product)) {
                return true;
            }
        }
        $hay = $url.' '.$title.' '.$text;
        if (! $this->hayMentionsProduct($hay, $product)) {
            return false;
        }
        if ($this->pageClaimsAnotherCode($url, $title, $product)) {
            return false;
        }
        // Rękawica Ansell ma numer modelu na każdej karcie — sama linia („AlphaTec”, „HyFlex”)
        // i marka wpuszczały AlphaTec 58-270 jako nasze 38003PP.
        $gloveModel = $this->ansellGloveModel($product);
        if ($gloveModel !== null && ! $this->hayHasAnsellGloveModel($hay, $gloveModel)) {
            return false;
        }
        // 0100 ≠ art.1006: krótki numer magazynowy musi być na karcie, nie sama „kangurka”.
        if (preg_match('/^\d{3,4}$/u', trim((string) $product->sku)) === 1
            && ! $this->hayHasProductCode($hay, $product)
            && ! $this->urlOrTitleCarriesShopModelNumber($url, $title, $product)
            && ! $this->hayHasDistinctiveNamePhrase($hay, $product)) {
            return false;
        }
        if ($this->gluedNumericModelConfirmsCard($hay, $product)
            && $this->hayHasRequiredTypeFromName($hay, $product)) {
            return true;
        }
        $hayCompact = preg_replace('/[^a-z0-9]+/iu', '', mb_strtolower($hay)) ?? '';
        if ($this->urlOrTitleHasNamedShopIdentity($url, $title, $product)
            || $this->hayHasShopIdentity(mb_strtolower($hay), $hayCompact, $product)) {
            return $this->hayHasRequiredTypeFromName($hay, $product);
        }

        return $this->pageAgreesWithBrandAndName($hay, $url, $product);
    }

    /** Kupon, impressum, kontakt — nie karta jednego wyrobu. */
    public function looksLikeNonProductCardUrl(string $url): bool
    {
        $path = mb_strtolower((string) (parse_url($url, PHP_URL_PATH) ?? ''));
        foreach ([
            '/gutschein', '/voucher', '/coupon', '/impressum', '/imprint', '/kontakt',
            '/blogs/', '/blog/',
        ] as $bad) {
            if (str_contains($path, $bad)) {
                return true;
            }
        }
        if (preg_match('#/(?:about-us|about|o-nas|o-firmie|contact-us|press-releases)(/|$)#', $path) === 1) {
            return true;
        }
        // Producent trzyma na własnej domenie także komunikaty giełdowe. „Ansell
        // to acquire Ringers Gloves” niesie markę i słowo „gloves”, więc filtr
        // treści je przepuszcza — a to komunikat prasowy, nie karta produktu.
        if (preg_match('#/(?:investor-cent(?:er|re)|investor-relations|investors|asx-announcements|announcements|media-releases?|news-?room|press-release)(/|$)#', $path) === 1) {
            return true;
        }
        // Strony zagrożeń, branż i usług producenta („hazards/chemical-resistance”,
        // „ansellguardian-chemical”) niosą markę i słowo z nazwy produktu, więc filtr
        // treści je przepuszczał — IXELL X Chemical dostał z nich folder reklamowy.
        if (preg_match('#/(?:hazards?|industries|industry|solutions|services|resources|why-[a-z]+|[a-z]*guardian[a-z\-]*)(/|$)#', $path) === 1) {
            return true;
        }
        // Na ansell.com karta produktu leży wyłącznie pod /products/…; reszta
        // (kategorie „protective-clothing”, marki, kampanie) to listingi i marketing.
        $host = mb_strtolower((string) (parse_url($url, PHP_URL_HOST) ?? ''));
        if (str_ends_with($host, 'ansell.com') && ! str_starts_with($host, 'shop.')
            && ! str_contains($path, '/products/') && ! str_contains($path, '/-/media/')) {
            return true;
        }

        return false;
    }

    /** Listing wielu modeli (Woo: RAPTOR + DEFENDOR + LEVIOR na jednej stronie). */
    public function pageLooksLikeMultiProductListing(string $text): bool
    {
        $low = mb_strtolower($text);
        $optionBlocks = substr_count($low, 'wähle eine option') + substr_count($low, 'wahle eine option');
        if ($optionBlocks >= 2) {
            return true;
        }

        return preg_match('/kunden,\s+die\s+sich\s+f[uü]r\s+diesen\s+artikel/u', $low) === 1;
    }

    /** Czy na stronie stoi któryś z kodów produktu na tyle długi, by nie trafić przypadkiem. */
    private function hayHasDistinctiveProductCode(string $hay, Product $product): bool
    {
        $hay = mb_strtolower($hay);
        $hayCompact = preg_replace('/[^a-z0-9]+/iu', '', $hay) ?? $hay;
        foreach ($this->productCodes($product) as $code) {
            if ($this->isDistinctiveProductCode($code) && $this->tokenInHay($hay, $hayCompact, $code)) {
                return true;
            }
        }

        return false;
    }

    /** Kod na tyle długi i mieszany, że przypadkowe trafienie w sieci jest nierealne. */
    private function isDistinctiveProductCode(string $token): bool
    {
        $compact = $this->compactCode($token);

        return mb_strlen($compact) >= 6
            && preg_match('/\p{L}/u', $compact) === 1
            && preg_match('/\d/u', $compact) === 1;
    }

    private function tokenIsProductCode(string $token, Product $product): bool
    {
        $token = mb_strtolower(trim($token));
        if ($token === '') {
            return false;
        }
        $compact = $this->compactCode($token);
        foreach ($this->productCodes($product) as $code) {
            if ($token === $code || ($compact !== '' && $compact === $this->compactCode($code))) {
                return true;
            }
        }

        return false;
    }

    /**
     * Po trafieniu kodu karta musi mieć markę (albo domenę producenta)
     * i choć część nazwy — inaczej T-31 z Ardon przechodzi jako tablica CABINAID.
     */
    public function pageAgreesWithBrandAndName(string $hay, string $url, Product $product): bool
    {
        $blob = mb_strtolower(trim($url.' '.$hay));
        if ($blob === '') {
            return false;
        }
        if ($this->looksLikeUnrelatedApparel($blob, $product)) {
            return false;
        }
        if (! $this->hayHasBrand($blob, $product) && ! $this->hayHasOfficialHost($blob, $url, $product)) {
            // Długi kod z literami i cyframi („NV2032CE”) razem ze słowem z nazwy
            // („Astro Cleat”) identyfikuje kartę sam — hurtownia nie musi wypisywać
            // marki z cennika. Krótki kod („T-31”) tej ulgi nie dostaje.
            if (! $this->hayHasDistinctiveProductCode($blob, $product)
                || ! $this->hayHasSpecificNameToken($blob, $product)) {
                return false;
            }
        }
        $nameTokens = $this->distinctiveIdentityNameTokens($product);
        if ($nameTokens === []) {
            return true;
        }
        $hayCompact = preg_replace('/[^a-z0-9]+/iu', '', $blob) ?? $blob;
        foreach ($nameTokens as $token) {
            if ($this->tokenInHay($blob, $hayCompact, $token)) {
                return true;
            }
            $stemLen = max(4, min(6, mb_strlen($token)));
            $stem = mb_substr($token, 0, $stemLen);
            if (mb_strlen($stem) >= 4 && str_contains($blob, $stem)) {
                return true;
            }
        }
        // TX39 po angielsku („Bib & Brace”) bez „ogrodniczki” — zostaje typ z nazwy.
        // Sam kod+marka (V742 Cofra bez OWERTON) nie wystarcza.
        if ($this->skuIsSharedShortCode($product)) {
            return false;
        }

        return $this->nameRequiresArticleType($product)
            && $this->hayHasRequiredTypeFromName($blob, $product);
    }

    /**
     * @return list<string>
     */
    private function distinctiveIdentityNameTokens(Product $product): array
    {
        $out = [];
        foreach ($this->nameWords($product) as $word) {
            if ($this->isColorWord($word)) {
                continue;
            }
            $out[] = $word;
        }
        foreach (preg_split('/[^\p{L}\p{N}]+/u', trim((string) $product->name)) ?: [] as $raw) {
            if (preg_match('/^\p{L}{3}$/u', $raw) !== 1) {
                continue;
            }
            $word = mb_strtolower($raw);
            if ($this->isColorWord($word) || $this->isGenericCatalogNameWord($word)
                || $this->tokenIsProductCode($word, $product)) {
                continue;
            }
            $out[] = $word;
        }
        $skuCompact = $this->compactCode((string) $product->sku);
        foreach ($this->shopIdentityPhrases($product) as $phrase) {
            $phrase = mb_strtolower(trim($phrase));
            if ($phrase === '' || $this->tokenIsProductCode($phrase, $product) || $this->isColorWord($phrase)) {
                continue;
            }
            $phraseCompact = $this->compactCode($phrase);
            if ($skuCompact !== '' && $phraseCompact !== ''
                && (str_contains($skuCompact, $phraseCompact) || str_contains($phraseCompact, $skuCompact))) {
                continue;
            }
            $out[] = $phrase;
            foreach (preg_split('/[^\p{L}\p{N}]+/u', $phrase) ?: [] as $word) {
                if (mb_strlen($word) < 3 || $this->isColorWord($word) || $this->tokenIsProductCode($word, $product)) {
                    continue;
                }
                $wordCompact = $this->compactCode($word);
                if ($skuCompact !== '' && $wordCompact !== '' && str_contains($skuCompact, $wordCompact)) {
                    continue;
                }
                $out[] = $word;
            }
        }

        $uniq = array_values(array_unique($out));
        $specific = array_values(array_filter(
            $uniq,
            fn (string $token): bool => ! $this->tokenIsArticleTypeWord($token)
        ));

        return $specific !== [] ? $specific : $uniq;
    }

    /**
     * T-31 / T-34 — sam kod w site: łapie koszulę; dopisz typ i część nazwy.
     */
    public function sharedShortSkuQueryExtra(Product $product): string
    {
        if (! $this->skuIsSharedShortCode($product)) {
            return '';
        }
        $bits = [];
        $type = $this->requiredArticleTypeLabel($product);
        if ($type !== null) {
            $bits[] = trim(explode('/', $type)[0]);
        }
        foreach ($this->distinctiveIdentityNameTokens($product) as $token) {
            if ($this->tokenIsArticleTypeWord($token) || in_array($token, $bits, true)) {
                continue;
            }
            $bits[] = $token;
            if (count($bits) >= 3) {
                break;
            }
        }

        return implode(' ', $bits);
    }

    public function hayHasSpecificNameToken(string $hay, Product $product): bool
    {
        $blob = mb_strtolower($hay);
        $hayCompact = preg_replace('/[^a-z0-9]+/iu', '', $blob) ?? $blob;
        foreach ($this->distinctiveIdentityNameTokens($product) as $token) {
            if ($this->tokenIsArticleTypeWord($token)) {
                continue;
            }
            if ($this->tokenInHay($blob, $hayCompact, $token)) {
                return true;
            }
        }

        return false;
    }

    private function hayHasOfficialHost(string $hay, string $url, Product $product): bool
    {
        $host = mb_strtolower((string) (parse_url($url, PHP_URL_HOST) ?? ''));
        $host = preg_replace('/^www\./', '', $host) ?? $host;
        $blob = mb_strtolower($url.' '.$hay);
        $hosts = array_values(array_unique(array_merge(
            $this->officialCatalogHosts($product),
            $this->catalogSearchHosts($product),
        )));
        foreach ($hosts as $official) {
            $official = preg_replace('/^www\./', '', mb_strtolower(trim($official))) ?? '';
            if ($official === '') {
                continue;
            }
            if ($host === $official || ($host !== '' && str_ends_with($host, '.'.$official))) {
                return true;
            }
            if (str_contains($blob, $official)) {
                return true;
            }
        }

        return false;
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
            // „150cm” ma cyfre i litere jak model C500, ale to wymiar — sam potwierdzal
            // pasowi 150 cm karte sznurowadel 150 cm
            if (! $this->isStrongShopPhrase($phrase) || $this->isBareMeasurement($phrase)) {
                continue;
            }
            $words = preg_split('/[\s\-]+/u', $phrase) ?: [];
            $hasDigit = preg_match('/\d/u', $phrase) === 1;
            $digitOnly = preg_match('/^\d+$/u', (string) preg_replace('/[\s\-]+/u', '', $phrase)) === 1;
            $multi = count($words) >= 2;
            // C500 / Showa 310 — model z literą nie wymaga marki z cennika (Eider, SUNGBOO).
            // Samo „1001” / „1900” bez marki to za dużo internetu.
            if (($hasDigit && ! $digitOnly) || $multi || $brands === []
                || $this->hayHasAnyBrand($hay, $hayCompact, $brands)) {
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
        $hayCompact = preg_replace('/[^a-z0-9]+/u', '', mb_strtolower(Str::ascii($hay))) ?? '';
        if ($hayCompact === '') {
            return false;
        }
        foreach ($this->compactNamePhraseVariants($product) as $phrase) {
            if (mb_strlen($phrase) >= 6 && str_contains($hayCompact, $phrase)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Ta sama nazwa z synonimem typu („dywanik” ↔ „chodnik”).
     *
     * @return list<string>
     */
    private function compactNamePhraseVariants(Product $product): array
    {
        $base = preg_replace('/[^a-z0-9]+/u', '', mb_strtolower(Str::ascii((string) $product->name))) ?? '';
        if ($base === '') {
            return [];
        }
        $out = [$base];
        foreach ($this->typeStemsInText((string) $product->name) as $stems) {
            $present = [];
            foreach ($stems as $stem) {
                $stem = preg_replace('/[^a-z0-9]+/u', '', mb_strtolower(Str::ascii($stem))) ?? '';
                if ($stem !== '' && str_contains($base, $stem)) {
                    $present[] = $stem;
                }
            }
            if ($present === []) {
                continue;
            }
            usort($present, static fn (string $a, string $b): int => mb_strlen($b) <=> mb_strlen($a));
            foreach ($stems as $alt) {
                $alt = preg_replace('/[^a-z0-9]+/u', '', mb_strtolower(Str::ascii($alt))) ?? '';
                if ($alt === '') {
                    continue;
                }
                foreach ($present as $from) {
                    if ($from === $alt) {
                        continue;
                    }
                    $out[] = str_replace($from, $alt, $base);
                }
            }
        }

        return array_values(array_unique($out));
    }

    /** Długa, unikalna nazwa w slugu (910 bez SKU w adresie) — nie „kurtka”. */
    public function hayHasDistinctiveNamePhrase(string $hay, Product $product): bool
    {
        $phrase = preg_replace('/[^a-z0-9]+/u', '', mb_strtolower(Str::ascii((string) $product->name))) ?? '';
        if (mb_strlen($phrase) < 24) {
            return false;
        }

        return $this->hayHasNamePhrase($hay, $product);
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
        // „HyFlex 11618 Size 11,0” — size/pair/pack/vend z cennika to rozmiar i opakowanie,
        // nie model; z marką potwierdzały każdą kartę „…-gloves-size-8” tego producenta
        $generic = ['bhp', 'robocze', 'roboczy', 'robocza', 'ochronne', 'ochronny', 'ochronna',
            'damskie', 'meskie', 'męskie', 'nowosc', 'nowość', 'szt', 'kpl', 'para', 'rozmiar',
            'size', 'sizes', 'pair', 'pairs', 'pack', 'packs', 'vend', 'vending', 'each', 'piece', 'pieces'];

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
        if ($sku !== '') {
            $codes[] = $sku;
            $compactSku = preg_replace('/[^a-z0-9]+/u', '', mb_strtolower($sku)) ?? '';
            if ($compactSku !== '' && $compactSku !== mb_strtolower($sku) && mb_strlen($compactSku) >= 3) {
                $codes[] = $compactSku;
            }
        }
        $withoutSize = $this->catalogSkuWithoutSize($product);
        if ($withoutSize !== '' && mb_strtolower($withoutSize) !== mb_strtolower($sku)
            && ! $this->isAnsellGloveWarehouseRemnant($withoutSize, $product)) {
            $codes[] = $withoutSize;
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
        foreach ($this->skuSearchNeedles($product) as $needle) {
            $codes[] = $needle;
        }
        foreach ($this->ansellStyleCodes($product) as $style) {
            $codes[] = $style;
        }
        $gloveModel = $this->ansellGloveModel($product);
        if ($gloveModel !== null) {
            $codes[] = $gloveModel;
        }
        $accessory = $this->ansellAccessoryStyleCode($product);
        if ($accessory !== null) {
            $codes[] = $accessory;
        }
        foreach ($this->threeMCatalogCodesFromName($product) as $code) {
            $codes[] = $code;
        }
        // RINGERS 259B: karta Ansella to ringers-r259b. Bez „r259b” wśród kodów
        // pageClaimsAnotherCode brał własną kartę za cudzy model (56 lokalizacji odrzuconych).
        foreach ($this->modelAliases($product) as $alias) {
            if (preg_match('/^r\d{2,3}[a-z]$/u', $alias) === 1) {
                $codes[] = $alias;
            }
        }
        if ($this->manufacturerIsThreeM($product) && preg_match('/^\d{4}$/u', $sku) === 1) {
            $codes[] = '0'.$sku;
        }
        if ($this->manufacturerIsThreeM($product) && preg_match('/^0\d{4}$/u', $sku) === 1) {
            $codes[] = ltrim($sku, '0');
        }

        $codes = array_values(array_unique(array_map('mb_strtolower', $codes)));

        return array_values(array_filter(
            $codes,
            fn (string $code): bool => ! $this->isAnsellGloveWarehouseRemnant($code, $product)
        ));
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
        foreach ([$this->usableProductName($product), ...$this->variantBaseCodes($product)] as $raw) {
            $raw = $sizes->stripSizeFromName(trim((string) $raw));
            if ($raw === '') {
                continue;
            }
            if (preg_match('/^(\p{L}{3,12})\s+(S[1-5]S?)\b/u', $raw, $safety) === 1
                && ! $this->isDescriptiveIdentityWord($safety[1])
                && ! $this->isApparelTypeWord($safety[1])
                && ! $this->isGenericCatalogNameWord($safety[1])) {
                $out[] = $safety[1].' '.$safety[2];
            }
            if (preg_match('/(\p{L}{4,14})\s+Pro[- ]?X\b/u', $raw, $pro) === 1
                && ! $this->isDescriptiveIdentityWord($pro[1])
                && ! $this->isApparelTypeWord($pro[1])
                && ! $this->isGenericCatalogNameWord($pro[1])) {
                $out[] = $pro[1].' Pro-X';
            }
            if (preg_match('/(\p{L}{4,14})[®™]?\s+(\d{3,5})\b/u', $raw, $line) === 1
                && ! $this->isDescriptiveIdentityWord($line[1])
                && ! $this->isApparelTypeWord($line[1])
                && ! $this->isGenericCatalogNameWord($line[1])) {
                $out[] = $line[1].' '.$line[2];
            }
            if (preg_match_all(
                '/(?<![\p{L}\d])(\p{L}{2,3})\s+(\d{2,3})(?=\s+(?:S[1-5]S?|ESD|SRC|HRO)\b)/u',
                $raw,
                $shoeCodes,
                PREG_SET_ORDER
            ) !== false) {
                foreach ($shoeCodes as $shoe) {
                    if ($this->isDescriptiveIdentityWord($shoe[1]) || $this->isApparelTypeWord($shoe[1])) {
                        continue;
                    }
                    $out[] = mb_strtoupper($shoe[1]).' '.$shoe[2];
                }
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
        if ($this->ansellPageClaimsForeignSeries($url, $title, $product)
            || $this->ansellPageClaimsForeignLine($url, $product)
            || $this->ansellPageClaimsForeignVariant($url, $product)
            || $this->kleenGuardPageClaimsForeignVariant($url, $title, $product)
            || $this->urlOrTitleHasForeignAnsellGloveModel($url, $title, $product)) {
            return true;
        }
        // SKU 205, sklep „model 285” — ta sama kurtka, pełna nazwa w slugu.
        if ($this->hayHasDistinctiveNamePhrase($url.' '.$title, $product)) {
            return false;
        }
        // Najpierw nasz model (CovaSpec 471 / 471 w slugu). Inaczej numer sklepu
        // (boe-471, art. 860) wygląda jak cudzy kod i odrzuca dobrą kartę.
        if ($this->urlOrTitleCarriesShopModelNumber($url, $title, $product)) {
            return false;
        }
        if ($this->urlOrTitleHasForeignModelNumber($url, $title, $product)) {
            return true;
        }
        if ($this->urlOrTitleHasNamedShopIdentity($url, $title, $product)) {
            return false;
        }
        $tokens = $this->codeLikeTokens($url, $title);

        return $tokens !== []
            && $this->compactProductCodes($product) !== []
            && ! $this->urlOrTitleCarriesCodeFamily($url, $title, $product);
    }

    /**
     * Model z literą w tytule/URL (CovaSpec 471, KRYTECH 563). Same „4000” / „121”
     * nie uniewinniają karty innego numeru tej samej linii.
     */
    public function urlOrTitleHasNamedShopIdentity(string $url, string $title, Product $product): bool
    {
        $hay = mb_strtolower($url.' '.$title);
        $hayCompact = preg_replace('/[^a-z0-9]+/iu', '', $hay) ?? $hay;
        $specific = $this->specificModelNumbers($product);
        foreach ($this->shopIdentityPhrases($product) as $phrase) {
            $phrase = mb_strtolower(trim($phrase));
            if ($phrase === '' || preg_match('/^\d+$/u', $this->compactCode($phrase)) === 1) {
                continue;
            }
            if (! $this->isStrongShopPhrase($phrase) || ! $this->tokenInHay($hay, $hayCompact, $phrase)) {
                continue;
            }
            preg_match_all('/\d{3,5}/u', $phrase, $nums);
            $digits = $nums[0] ?? [];
            // AlphaTec 4000 nie uniewinnia modelu 111. CRACKDOWN (bez cyfr) zostaje.
            if ($digits !== [] && $specific !== [] && ! $this->digitsHitSpecificModel($digits, $specific)) {
                continue;
            }
            $words = preg_split('/[\s\-]+/u', $phrase) ?: [];
            $hasDigit = preg_match('/\d/u', $phrase) === 1;
            $digitOnly = preg_match('/^\d+$/u', (string) preg_replace('/[\s\-]+/u', '', $phrase)) === 1;
            // Samo „Lucky” w tytule filmu ≠ karta Cofra — wymagaj marki albo domeny katalogu.
            if (! (($hasDigit && ! $digitOnly) || count($words) >= 2
                || $this->hayHasBrand($hay, $product)
                || $this->hayHasOfficialHost($hay, $url, $product))) {
                continue;
            }

            return true;
        }

        return false;
    }

    /** BioClean 2000 to nie karta AlphaTec 2000 (ta sama seria/model, inna linia). */
    private function ansellPageClaimsForeignLine(string $url, Product $product): bool
    {
        $path = mb_strtolower((string) (parse_url($url, PHP_URL_PATH) ?? $url));
        if ($this->ansellIsBioClean($product)) {
            return preg_match('/alphatec[-_]/u', $path) === 1;
        }

        return preg_match('/bioclean[-_]/u', $path) === 1;
    }

    /** Kombinezon STD/bound to nie karta taped/plus tej samej serii. */
    private function ansellPageClaimsForeignVariant(string $url, Product $product): bool
    {
        if (! $this->ansellPrefersStandardSlug($product)) {
            return false;
        }
        $path = mb_strtolower((string) (parse_url($url, PHP_URL_PATH) ?? $url));

        return str_contains($path, 'ultrasonically')
            || str_contains($path, 'plus-model')
            || str_contains($path, 'stitched-taped');
    }

    /** AlphaTec 3000 model 213 to nie karta AlphaTec 2000 model 213. */
    private function ansellPageClaimsForeignSeries(string $url, string $title, Product $product): bool
    {
        $series = $this->ansellCatalogBits($product)['series'];
        if (! is_string($series) || $series === '') {
            return false;
        }
        $hay = mb_strtolower($url.' '.$title);
        if (preg_match_all('/alphatec[-_ \/]?(\d{4})\b/u', $hay, $hits) < 1) {
            return false;
        }
        foreach ($hits[1] as $found) {
            if ((string) $found !== $series) {
                return true;
            }
        }

        return false;
    }

    /**
     * „model 111” przy naszym 121 — wspólna linia (4000) nie uniewinnia innego numeru.
     */
    private function urlOrTitleHasForeignModelNumber(string $url, string $title, Product $product): bool
    {
        $ours = $this->compactProductCodes($product);
        foreach ($this->shopIdentityPhrases($product) as $phrase) {
            $compact = $this->compactCode($phrase);
            if (mb_strlen($compact) >= 3) {
                $ours[] = $compact;
            }
        }
        $ours = array_values(array_unique($ours));
        if ($ours === []) {
            return false;
        }
        foreach ($this->codeLikeTokens($url, $title) as $token) {
            if (preg_match('/^\d{3,5}$/u', $token) !== 1) {
                continue;
            }
            $mine = false;
            foreach ($ours as $code) {
                if ($this->tokenMatchesOurCode($token, (string) $code)) {
                    $mine = true;
                    break;
                }
            }
            if ($this->looksLikeSizeSiblingCode($token, $product)) {
                continue;
            }
            if (! $mine) {
                return true;
            }
        }

        $sku = trim((string) $product->sku);
        if (preg_match('/^\d{3,4}$/u', $sku) !== 1) {
            return false;
        }
        if (preg_match_all('/(?<![0-9a-z])(\d{3,4})(?![0-9a-z])/u', mb_strtolower($url.' '.$title), $nums) < 1) {
            return false;
        }
        foreach ($nums[1] as $n) {
            $n = (string) $n;
            if ($this->looksLikeSizeSiblingCode($n, $product)) {
                continue;
            }
            foreach ($ours as $code) {
                if ($this->tokenMatchesOurCode($n, (string) $code)) {
                    continue 2;
                }
            }

            return true;
        }

        return false;
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
        if ($this->gluedNumericModelConfirmsCard($url.' '.$title, $product)) {
            return $this->ansellGloveUrlCarriesModel($url, $title, $product);
        }
        if ($codes === []) {
            return false;
        }

        foreach ($this->codeLikeTokens($url, $title) as $token) {
            $token = (string) $token;
            foreach ($codes as $code) {
                if ($this->tokenMatchesOurCode($token, (string) $code)) {
                    return $this->ansellGloveUrlCarriesModel($url, $title, $product);
                }
            }
        }

        return false;
    }

    /** 09-430 w slugu, nie samo 9430 z cennika (Peli 9430). */
    private function ansellGloveUrlCarriesModel(string $url, string $title, Product $product): bool
    {
        $model = $this->ansellGloveModel($product);

        return $model === null || $this->hayHasAnsellGloveModel($url.' '.$title, $model);
    }

    /** 9430100 − rozmiar 10 = 9430; karta Ansella to 09-430. */
    public function isAnsellGloveWarehouseRemnant(string $code, Product $product): bool
    {
        $model = $this->ansellGloveModel($product);
        if ($model === null) {
            return false;
        }
        $codeDigits = preg_replace('/\D+/u', '', $code) ?? '';
        $modelDigits = preg_replace('/\D+/u', '', $model) ?? '';
        $stripped = preg_replace('/\D+/u', '', $this->catalogSkuWithoutSize($product)) ?? '';

        return $codeDigits !== '' && $stripped !== '' && $modelDigits !== ''
            && $codeDigits === $stripped
            && $stripped !== $modelDigits;
    }

    /**
     * Jak sklep nazywa model: „SOLO PLUS 995”, „BALTIK BLACK” — nie ogon z cennika.
     *
     * @return list<string>
     */
    public function shopIdentityPhrases(Product $product): array
    {
        $out = [];
        // nazwa modelu z cennika idzie pierwsza: BAXTER dla BAXCSP, gdy nazwa
        // produktu to opis soczewek, a kodu nie ma w żadnym sklepie jako słowa
        $modelName = $this->modelNamePhrase($product);
        if ($modelName !== '') {
            $out[] = $modelName;
        }
        $ansellPhrase = $this->ansellSeriesModelPhrase($product);
        if ($ansellPhrase !== '') {
            $out[] = $ansellPhrase;
        }
        foreach ($this->ansellPartShopPhrases($product) as $part) {
            $out[] = $part;
        }
        $kleen = $this->kleenGuardShopPhrase($product);
        if ($kleen !== '') {
            $out[] = $kleen;
        }
        foreach ($this->threeMCatalogCodesFromName($product) as $code) {
            $out[] = $code;
        }
        foreach ($this->catalogTradeNames($product) as $trade) {
            $out[] = $trade;
        }
        $fromName = $this->seriesFromDescriptiveName($this->usableProductName($product));
        if ($fromName !== '') {
            $out[] = $fromName;
        }
        $fromVariant = $this->seriesWithTrailingVariant($this->usableProductName($product));
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
        foreach ($this->nameCatalogCodes($product) as $fromNameCode) {
            $out[] = $fromNameCode;
        }
        foreach ($this->embeddedModelCodes((string) $product->name) as $embedded) {
            $out[] = $embedded;
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

        $ranked = $this->preferSpecificShopPhrases(array_values($uniq), $product);
        $ranked = $this->preferNumberFromName($ranked, $product);
        // nazwa modelu z cennika na czele — to ona idzie do sklepu i do site:
        if ($modelName !== '') {
            $ranked = array_values(array_unique([$modelName, ...$ranked]));
        }

        return array_values(array_filter(
            $ranked,
            fn (string $phrase): bool => ($phrase === $modelName || $this->isStrongShopPhrase($phrase))
                // Sama marka („OX-ON”) nie jest tożsamością modelu — pasuje do całego katalogu.
                && ! $this->phraseIsBrandOnly($phrase, $product)
                && ! $this->phraseIsTypeOrColorOnly($phrase)
                && ! $this->isAnsellGarmentSuffixPhrase($phrase, $product)
        ));
    }

    /**
     * Hosty do site: — sklepy z config, inaczej oficjalna domena producenta.
     * Bez nazwy handlowej też: „półbuty S2” → site:reis.pl 002-100.
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
        $inferred = $this->inferredCatalogHosts($product);
        if ($inferred !== []) {
            return $this->preferCatalogHostsForProduct(
                $product,
                array_values(array_unique(array_merge($inferred, $shops)))
            );
        }
        if ($shops !== []) {
            return $this->preferCatalogHostsForProduct($product, $shops);
        }

        return $this->officialCatalogHosts($product);
    }

    /** Fraza do site: — numer modelu (911), nie kawałek nazwy sprzed myślnika. */
    public function catalogSitePhrase(Product $product): string
    {
        $identity = $this->catalogSiteIdentity($product);
        if ($identity === '') {
            return '';
        }

        return $this->quoteSearchOperators($identity);
    }

    /** Pełne SKU, gdy fraza sklepu to tylko seria („AROX 733”). Inaczej jak dotychczas. */
    private function catalogSiteIdentity(Product $product): string
    {
        $sku = trim((string) $product->sku);
        $shop = $this->firstStrongShopPhrase($product);
        if ($sku !== '' && preg_match('/\s/u', $sku) === 1
            && ! $this->looksLikeInternalSku($product)
            && ! $this->looksLikeWarehouseArticleSku($product)
            && $this->shopPhraseIsSeriesPrefixOfIdentity($shop, $sku)) {
            return $sku;
        }

        return $this->siteSearchPhrase(
            $product,
            $shop,
            $this->looksLikeInternalSku($product),
            $this->looksLikeWarehouseArticleSku($product)
        );
    }

    private function shopPhraseIsSeriesPrefixOfIdentity(string $shop, string $identity): bool
    {
        $shop = mb_strtolower(trim($shop));
        $identity = mb_strtolower(trim($identity));
        if ($shop === '' || $identity === '' || $shop === $identity) {
            return false;
        }

        return str_starts_with($identity, $shop.' ') || str_starts_with($identity, $shop.'-');
    }

    /** Pełny kod katalogowy (AROX 733 641460 S1 PL ESD) — karta musi mieć SKU albo nazwę, nie samą serię. */
    public function requiresExactSkuOrNameOnCard(Product $product): bool
    {
        $sku = trim((string) $product->sku);
        if ($sku === '' || $this->looksLikeInternalSku($product)
            || $this->looksLikeWarehouseArticleSku($product)) {
            return false;
        }

        return preg_match('/\s/u', $sku) === 1
            && preg_match('/\p{L}/u', $sku) === 1
            && preg_match('/\d/u', $sku) === 1;
    }

    /** Producent oraz SKU albo nazwa — w URL, tytule albo opisie. */
    public function pageHasSkuOrNameAndManufacturer(
        string $url,
        string $title,
        string $text,
        Product $product,
    ): bool {
        $hay = mb_strtolower($url.' '.$title.' '.$text);
        $hayCompact = preg_replace('/[^a-z0-9]+/iu', '', $hay) ?? $hay;
        if (! $this->hayHasBrand($hay, $product) && ! $this->hayHasOfficialHost($hay, $url, $product)) {
            return false;
        }
        $sku = trim((string) $product->sku);
        $name = trim($this->usableProductName($product));
        if ($sku !== '' && ! $this->looksLikeInternalSku($product)
            && $this->tokenInHay($hay, $hayCompact, mb_strtolower($sku))) {
            return true;
        }

        return $name !== '' && $this->tokenInHay($hay, $hayCompact, mb_strtolower($name));
    }

    /**
     * Cennik ma złą markę albo kod magazynowy — karta jest u innej marki.
     *
     * @return array{brand: string, hosts: list<string>}|null
     */
    private function inferredBrandResolution(Product $product): ?array
    {
        $sku = strtoupper(trim((string) $product->sku));
        $name = mb_strtolower((string) $product->name);
        $mfr = trim((string) preg_replace(
            '/[^a-z0-9]+/u',
            '-',
            mb_strtolower($this->shortBrand((string) $product->manufacturer))
        ), '-');

        $code = $this->distributorPrefixedCatalogSku($product);
        if ($code !== '' && preg_match('/^ST\d{3}[A-Z]{2}$/u', $code) === 1) {
            return [
                'brand' => 'U-Power',
                'hosts' => $this->bareHosts(array_merge(
                    ['misterworker.com'],
                    (array) config('enrichment.manufacturer_domains.u-power', []),
                )),
            ];
        }

        if (preg_match('/^T51\d+/u', $sku) === 1
            && ($mfr === '3m' || preg_match('/\b(gondor|astor|raptor)\b/u', $name) === 1)) {
            return [
                'brand' => 'Infield',
                'hosts' => $this->bareHosts(array_merge(
                    ['infield-safety.com'],
                    (array) config('enrichment.manufacturer_domains.infield', []),
                )),
            ];
        }

        if (preg_match('/^(FA|FC|FD)\d+/u', $sku) === 1 && str_starts_with($mfr, 'reis')) {
            return [
                'brand' => 'Dickies',
                'hosts' => $this->bareHosts(array_merge(
                    ['workwearnation.com'],
                    (array) config('enrichment.manufacturer_domains.dickies', []),
                )),
            ];
        }

        if (str_contains($mfr, 'showa')
            && preg_match('/basic worker eco|worklife tiger/u', $name) === 1) {
            return [
                'brand' => 'Otto Schachner',
                'hosts' => $this->bareHosts(['os-safetycenter.de']),
            ];
        }

        if ($mfr === 'pip' && (preg_match('/^6552\d+/u', $sku) === 1
            || preg_match('/^10Y1532/u', $sku) === 1
            || preg_match('/cocoon evo|runner mid|sprinter yellow|glovebox/u', $name) === 1)) {
            return [
                'brand' => 'Honeywell',
                'hosts' => $this->bareHosts(array_merge(
                    ['automation.honeywell.com'],
                    (array) config('enrichment.manufacturer_domains.honeywell', []),
                )),
            ];
        }

        if (str_contains($mfr, 'perfect')
            && preg_match('/sivochem|dermatril|pharmatril/u', $name) === 1) {
            return [
                'brand' => 'Honeywell',
                'hosts' => $this->bareHosts(['automation.honeywell.com']),
            ];
        }

        return null;
    }

    /**
     * @return list<string>
     */
    public function inferredCatalogHosts(Product $product): array
    {
        return $this->inferredBrandResolution($product)['hosts'] ?? [];
    }

    public function inferredBrandHint(Product $product): string
    {
        return $this->inferredBrandResolution($product)['brand'] ?? '';
    }

    /** Adres leży na oficjalnej domenie marki (artra.pl, ansell.com) albo jej subdomenie. */
    public function isOfficialCatalogUrl(string $url, Product $product): bool
    {
        $host = preg_replace('/^www\./u', '', mb_strtolower((string) (parse_url($url, PHP_URL_HOST) ?? ''))) ?? '';
        if ($host === '') {
            return false;
        }
        foreach ($this->officialCatalogHosts($product) as $official) {
            $official = preg_replace('/^www\./u', '', mb_strtolower(trim($official))) ?? '';
            if ($official !== '' && ($host === $official || str_ends_with($host, '.'.$official))) {
                return true;
            }
        }

        return false;
    }

    /**
     * Oficjalne domeny z config — bez tabeli discovered sites.
     *
     * @return list<string>
     */
    public function officialCatalogHosts(Product $product): array
    {
        $inferred = $this->inferredCatalogHosts($product);
        if ($this->manufacturerLooksUnrelatedToProduct($product)) {
            return $this->preferCatalogHostsForProduct($product, $inferred);
        }

        $configured = $this->bareHosts($this->hostsFromConfigMap(
            (array) config('enrichment.manufacturer_domains', []),
            array_merge($this->manufacturerKeyCandidates($product), $this->nameBrandKeys($product))
        ));

        return $this->preferCatalogHostsForProduct(
            $product,
            array_values(array_unique(array_merge($inferred, $configured)))
        );
    }

    /**
     * Kurtka dziecięca jest na SportPROS, peleryna na BeMoreGreen — pierwsze site:
     * idzie na ten sklep, nie na homepage innej domeny marki.
     *
     * @param  list<string>  $hosts
     * @return list<string>
     */
    private function preferCatalogHostsForProduct(Product $product, array $hosts): array
    {
        $first = $this->preferredCatalogHostNeedles($product);
        if ($first === [] || $hosts === []) {
            return array_values($hosts);
        }
        $want = array_fill_keys($this->bareHosts($first), true);
        $head = [];
        $tail = [];
        foreach ($hosts as $host) {
            $bare = $this->bareHosts([$host])[0] ?? '';
            if ($bare !== '' && isset($want[$bare])) {
                $head[] = $host;
            } else {
                $tail[] = $host;
            }
        }

        return array_values(array_unique(array_merge($head, $tail)));
    }

    /**
     * @return list<string>
     */
    private function preferredCatalogHostNeedles(Product $product): array
    {
        $blob = $this->normalizeTypeText(trim((string) $product->name.' '.(string) $product->sku));
        if (preg_match('/\b(dzieci|dzieciec|dziewcz|chlopc|kids|junior)/u', $blob) === 1) {
            return ['sportpros.pl'];
        }
        if (preg_match('/\bpeleryn/u', $blob) === 1) {
            return ['bemoregreen.eu'];
        }

        return [];
    }

    public function firstStrongShopPhrase(Product $product): string
    {
        $modelName = $this->modelNamePhrase($product);
        if ($modelName !== '') {
            return $modelName;
        }
        $ansellPhrase = $this->ansellSeriesModelPhrase($product);
        if ($ansellPhrase !== '') {
            return $ansellPhrase;
        }
        $part = $this->ansellPartShopPhrase($product);
        if ($part !== '') {
            return $part;
        }
        $kleen = $this->kleenGuardShopPhrase($product);
        if ($kleen !== '') {
            return $kleen;
        }
        $threeM = $this->preferredThreeMShopPhrase($product);
        if ($threeM !== '') {
            return $threeM;
        }
        $name = $this->strippedProductName($product);
        $prefixed = mb_strtolower($this->distributorPrefixedCatalogSku($product));
        foreach ($this->shopIdentityPhrases($product) as $phrase) {
            if (! $this->isStrongShopPhrase($phrase) || $this->shopPhraseLosesToCatalogSku($phrase, $product)) {
                continue;
            }
            if ($this->isColorOnlyShopPhrase($phrase)) {
                continue;
            }
            if ($prefixed !== '' && mb_strtolower($phrase) === $prefixed && $this->looksLikeCompactTradeName($name)) {
                continue;
            }
            if ($this->shopPhraseIsBareSeriesNumberWeakerThanTradeName($phrase, $name)) {
                continue;
            }
            if (! $this->shopPhraseIsWeakerThanName($phrase, $name)) {
                return $phrase;
            }
        }
        foreach ($this->shopIdentityPhrases($product) as $phrase) {
            if ($this->isColorOnlyShopPhrase($phrase)) {
                continue;
            }
            if ($prefixed !== '' && mb_strtolower($phrase) === $prefixed && $this->looksLikeCompactTradeName($name)) {
                continue;
            }
            if ($this->shopPhraseIsBareSeriesNumberWeakerThanTradeName($phrase, $name)) {
                continue;
            }
            if ($this->isStrongShopPhrase($phrase)
                && ! $this->shopPhraseLosesToCatalogSku($phrase, $product)
                && preg_match('/\d|[-\/]/u', $phrase) === 1) {
                return $phrase;
            }
        }
        if ($prefixed !== '' && $name !== '' && $this->looksLikeCompactTradeName($name)
            && $this->isStrongShopPhrase($name)
            && ! $this->shopPhraseLosesToCatalogSku($name, $product)) {
            return $name;
        }

        return '';
    }

    /**
     * GVS/RPB 04-322-100 — „GVS CEJN” i „Hose” to złącze/opis, nie model.
     * SKU NN-NNN(-wariant) wygrywa z frazą bez cyfry.
     */
    private function shopPhraseLosesToCatalogSku(string $phrase, Product $product): bool
    {
        $sku = trim((string) $product->sku);
        if (preg_match('/^\d{2}-\d{2,4}(?:-[A-Z0-9]{1,8})?$/iu', $sku) === 1) {
            return preg_match('/\d/u', $phrase) !== 1;
        }
        if ($this->skuIsBareNumericModel($product)) {
            return preg_match('/\d/u', $phrase) !== 1;
        }

        return false;
    }

    /**
     * true — nazwa modelu (albo sam kod) i marka są na stronie, a typ z kategorii się
     * zgadza; false — strona niesie kod rodzeństwa z cennika (BAXPSI przy BAXCSP);
     * null — nazwa modelu nic nie rozstrzyga, decydują pozostałe reguły.
     */
    private function modelNameVerdict(string $hay, Product $product): ?bool
    {
        $model = mb_strtolower($this->modelNamePhrase($product));
        if ($model === '') {
            return null;
        }
        $hayCompact = preg_replace('/[^a-z0-9]+/iu', '', $hay) ?? $hay;
        foreach ($this->siblingModelCodes($product) as $sibling) {
            if ($this->tokenInHay($hay, $hayCompact, $sibling)) {
                return false;
            }
        }
        $brands = $this->acceptedBrands($product);
        if ($brands !== [] && ! $this->hayHasAnyBrand($hay, $hayCompact, $brands)) {
            return null;
        }
        $modelCompact = $this->compactCode($model);
        $hasModel = $this->tokenInHay($hay, $hayCompact, $model)
            // „RUSH+ 2.0 XP” w adresie to „rush-2-0-xp” — sklejona forma
            || (preg_match('/\s/u', $model) === 1 && $modelCompact !== '' && str_contains($hayCompact, $modelCompact));
        $skuCompact = $this->compactCode((string) $product->sku);
        $hasCode = $skuCompact !== '' && $this->tokenInHay($hay, $hayCompact, $skuCompact);
        if (! $hasModel && ! $hasCode) {
            return null;
        }
        // typ z kategorii („Okulary ochronne”) musi stać na stronie — Bollé ma też gogle narciarskie
        $page = $this->normalizeTypeText($hay);
        foreach ($this->typeStemsInText((string) ($product->category ?? '')) as $stems) {
            if (! $this->textHasTypeStem($page, $stems)) {
                return null;
            }
        }

        return true;
    }

    /** @var array<string, list<string>> */
    private array $siblingCodes = [];

    /**
     * Kody innych wariantów tego samego modelu z cennika (BAXPSI, BAXPSF przy
     * BAXCSP) — karta z takim kodem to karta rodzeństwa, nie nasza.
     *
     * @return list<string>
     */
    private function siblingModelCodes(Product $product): array
    {
        $model = trim((string) ($product->model_name ?? ''));
        if ($model === '' || ! $product->exists) {
            return [];
        }
        $own = $this->compactCode((string) $product->sku);
        $key = mb_strtolower(trim((string) $product->manufacturer)).'|'.mb_strtolower($model).'|'.$product->getKey();
        if (! isset($this->siblingCodes[$key])) {
            $codes = [];
            $skus = Product::query()
                ->where('manufacturer', (string) $product->manufacturer)
                ->where('model_name', $model)
                ->whereKeyNot($product->getKey())
                ->limit(50)
                ->pluck('sku');
            foreach ($skus as $sku) {
                $compact = $this->compactCode((string) $sku);
                if ($compact !== '' && $compact !== $own) {
                    $codes[] = $compact;
                }
            }
            $this->siblingCodes[$key] = array_values(array_unique($codes));
        }

        return $this->siblingCodes[$key];
    }

    /** Nazwa modelu z cennika jako fraza sklepowa — tylko gdy ma literę i nie jest samym kodem. */
    public function modelNamePhrase(Product $product): string
    {
        $value = trim((string) preg_replace('/\s+/u', ' ', (string) ($product->model_name ?? '')));
        if ($value === '' || preg_match('/\p{L}/u', $value) !== 1) {
            return '';
        }
        if ($this->compactCode($value) === $this->compactCode((string) $product->sku)) {
            return '';
        }

        return $value;
    }

    public function isStrongShopPhrase(string $phrase): bool
    {
        $phrase = trim($phrase);
        if ($phrase === '' || mb_strlen($phrase) < 3) {
            return false;
        }
        if (preg_match('/^[A-Z]{2,8}[-\/][A-Z0-9]{1,8}(?:[-\/][A-Z0-9]{1,8}){0,2}$/iu', $phrase) === 1) {
            return true;
        }
        $useful = [];
        foreach (preg_split('/[\s\-]+/u', $phrase) ?: [] as $word) {
            $word = trim((string) $word);
            if ($word === '' || $this->isGenericCatalogNameWord($word) || $this->isHouseSkuPrefix($word)
                || $this->isPackOrSizeToken($word)) {
                continue;
            }
            $useful[] = $word;
        }
        if ($useful === []) {
            return false;
        }
        $joined = implode('', $useful);
        if (preg_match('/\d/u', $joined) === 1) {
            return mb_strlen($joined) >= 3;
        }
        if (count($useful) >= 2) {
            return true;
        }

        return mb_strlen($useful[0]) >= 4 && preg_match('/^\p{L}{4,16}$/u', $useful[0]) === 1;
    }

    /**
     * TX39, OPSBT11, URG-914 — kod katalogowy z literą i cyfrą, nie EAN z cennika.
     */
    public function hasDistinctiveCatalogSku(Product $product): bool
    {
        if ($this->looksLikeWarehouseArticleSku($product)) {
            return false;
        }
        foreach ([$this->catalogSkuWithoutSize($product), trim((string) $product->sku)] as $sku) {
            $sku = str_replace('/', '-', trim($sku));
            if ($sku === '') {
                continue;
            }
            if (preg_match(
                '/^(?=[A-Z0-9\-]*\p{L})(?=[A-Z0-9\-]*\d)[A-Z0-9\-]{3,16}$/iu',
                $sku
            ) === 1) {
                return true;
            }
        }

        return false;
    }

    /**
     * Kod katalogowy bez rozmiaru z cennika: „G3175/40” → „G3175”, „CADIZ-42” → „CADIZ”.
     */
    public function catalogSkuWithoutSize(Product $product): string
    {
        $sku = trim((string) $product->sku);
        if ($sku === '') {
            return '';
        }
        $prefixed = $this->distributorPrefixedCatalogSku($product);
        if ($prefixed !== '') {
            return $prefixed;
        }
        // „ONE4ALL-IT08” — „IT” z cyframi to w całości rozmiar (taille), więc kod bez
        // rozmiaru kończy się na modelu, a nie na rozciętym markerze „ONE4ALL-IT”.
        if (preg_match('/^(.+)-IT(?:0\d|1[0-4])$/u', $sku, $it) === 1) {
            $core = rtrim($it[1], "-/+_. \t");
            if ($core !== '') {
                return $core;
            }
        }
        $sizes = new ProductSizeVariant;
        $stripped = $sizes->stripWearSizeSuffix($sku);
        if (is_string($stripped) && $stripped !== '' && mb_strtolower($stripped) !== mb_strtolower($sku)) {
            return $stripped;
        }
        $core = $sizes->skuCore($sku, $product->name);
        if (is_string($core) && $core !== '' && mb_strtolower($core) !== mb_strtolower($sku)) {
            return $core;
        }

        return $sku;
    }

    /**
     * Pełny SKU, potem ten sam kod bez 1 i 2 znaków z końca (resztka taille).
     *
     * @return list<string>
     */
    public function skuSearchNeedles(Product $product): array
    {
        $sizes = new ProductSizeVariant;
        $seen = [];
        $out = [];
        foreach ([$this->catalogSkuWithoutSize($product), trim((string) $product->sku)] as $code) {
            $code = trim($code);
            if ($code === '' || $this->isAnsellGloveWarehouseRemnant($code, $product)) {
                continue;
            }
            $key = mb_strtolower($code);
            if (! isset($seen[$key])) {
                $seen[$key] = true;
                $out[] = $code;
            }
            foreach ($sizes->skuSearchFallbacks($code) as $fallback) {
                if ($this->isAnsellGloveWarehouseRemnant($fallback, $product)) {
                    continue;
                }
                $fallbackKey = mb_strtolower($fallback);
                if (! isset($seen[$fallbackKey])) {
                    $seen[$fallbackKey] = true;
                    $out[] = $fallback;
                }
            }
        }
        foreach ($this->variantBaseCodes($product) as $base) {
            $base = trim($base);
            if (preg_match('/^\d{2,4}[A-Z]?$/u', $base) !== 1) {
                continue;
            }
            $key = mb_strtolower($base);
            if (! isset($seen[$key])) {
                $seen[$key] = true;
                $out[] = $base;
            }
        }

        return $out;
    }

    /**
     * site: — kod bez rozmiaru, bez marki. „G 3175” → „G3175”; „KRYTECH 563” zostaje.
     */
    private function siteSearchPhrase(
        Product $product,
        string $shopPhrase,
        bool $internalSku,
        bool $warehouseSku,
    ): string {
        $prefixed = $this->distributorPrefixedCatalogSku($product);
        if ($prefixed !== '') {
            return $this->quoteSearchOperators($prefixed);
        }
        $sku = trim((string) $product->sku);
        $coded = $this->catalogSkuWithoutSize($product);
        $stripped = $coded !== '' && mb_strtolower($coded) !== mb_strtolower($sku);
        foreach ($this->variantBaseCodes($product) as $base) {
            $base = trim($base);
            if (preg_match('/^\d{2,4}$/u', $base) === 1) {
                return $this->quoteSearchOperators($base);
            }
        }
        // 911 / 910 — nie „PELERYNA DLA NIEPEŁNOSPRAWNYCH” z myślnika w nazwie
        if ($this->skuIsBareNumericModel($product) && $sku !== '') {
            return $this->quoteSearchOperators($sku);
        }
        if ($shopPhrase !== '' && (! $stripped || ! $this->phraseContainsSizeTail($shopPhrase, $sku, $coded))) {
            $shopCompact = preg_replace('/[^a-z0-9]+/u', '', mb_strtolower($shopPhrase)) ?? '';
            $codeCompact = preg_replace('/[^a-z0-9]+/u', '', mb_strtolower($coded)) ?? '';
            if ($stripped && $shopCompact === $codeCompact
                && preg_match('/^\p{L}{1,2}\s*\d{2,6}$/u', $shopPhrase) === 1
                && preg_match('/\p{L}/u', $coded) === 1
                && preg_match('/\d/u', $coded) === 1) {
                return $this->quoteSearchOperators((string) preg_replace('/\s+/u', '', $coded));
            }

            return $this->quoteSearchOperators($shopPhrase);
        }
        if ($coded !== '' && preg_match('/\p{L}/u', $coded) === 1 && preg_match('/\d/u', $coded) === 1) {
            return $this->quoteSearchOperators((string) preg_replace('/\s+/u', '', $coded));
        }
        if ($sku !== '' && ! $internalSku && ! $warehouseSku) {
            return $this->quoteSearchOperators($coded !== '' ? $coded : $sku);
        }

        return $this->quoteSearchOperators(
            $this->catalogArticleCodes($product)[0] ?? $this->strippedProductName($product)
        );
    }

    private function phraseContainsSizeTail(string $phrase, string $sku, string $coded): bool
    {
        $tail = ltrim(mb_substr($sku, mb_strlen($coded)), "/-_ \t");
        if ($tail === '') {
            return false;
        }

        return preg_match(
            '/(?<![a-z0-9])'.preg_quote(mb_strtolower($tail), '/').'(?![a-z0-9])/u',
            mb_strtolower($phrase)
        ) === 1;
    }

    /**
     * Fraza „TRACK” nie idzie do indeksu, gdy jest już kod G3175.
     */
    public function isWeakShopIndexPhrase(string $phrase, Product $product): bool
    {
        $phrase = trim($phrase);
        if ($phrase === '' || $this->isApparelTypeWord($phrase) || $this->isDescriptiveIdentityWord($phrase)) {
            return true;
        }
        // T5163000 w cenniku, na karcie Infield jest „Raptor”
        if (($this->rawSkuIsOfflineNoise($product) || $this->inferredBrandHint($product) !== '')
            && $this->isStrongShopPhrase($phrase)) {
            return false;
        }
        if (preg_match('/^\d{3,6}$/u', trim((string) $product->sku)) === 1
            && preg_match('/^\p{L}{3,}$/u', $phrase) === 1) {
            return true;
        }
        if (! $this->hasDistinctiveCatalogSku($product)) {
            return false;
        }
        $core = preg_replace('/[^a-z0-9]+/u', '', mb_strtolower($this->catalogSkuWithoutSize($product))) ?? '';
        if ($core === '' || preg_match('/\d/u', $core) !== 1 || preg_match('/\p{L}/u', $core) !== 1) {
            return false;
        }
        $compact = preg_replace('/[^a-z0-9]+/u', '', mb_strtolower($phrase)) ?? '';
        if ($compact === $core || str_contains($core, $compact) || str_contains($compact, $core)) {
            return false;
        }

        return preg_match('/\d/u', $compact) !== 1;
    }

    /** T-31 / A12 — za krótki kod, koliduje między katalogami. */
    public function skuIsSharedShortCode(Product $product): bool
    {
        return preg_match('/^[A-Z]-?\d{1,3}$/iu', trim((string) $product->sku)) === 1;
    }

    /**
     * Sklepy, które indeksują kod katalogowy (TX39) — oficjalna strona marki często nie.
     *
     * @return list<string>
     */
    public function codeIndexRetailerHosts(): array
    {
        return $this->bareHosts([
            'gvarant.pl',
            'optimumbhp.pl',
            'sklep-system.pl',
            'workweargurus.com',
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
        $sku = strtoupper(trim((string) $product->sku));
        $sizes = new ProductSizeVariant;
        // 109/O, 109-C — model + jednoliterowy wariant (kolor), nie rozmiar i nie 101/001
        if (preg_match('/^(\d{2,4})[\/\-]([A-Z])$/u', $sku, $m) === 1
            && ! $sizes->looksLikeWearSize($m[2])) {
            $out[] = $m[1];
        }
        // 001/A/ELR — model + litera + ogon cennika; sklep ma model-001 / model-001a
        if (preg_match('/^(\d{2,4})[\/\-]([A-Z])[\/\-]([A-Z]{2,4})$/u', $sku, $m) === 1
            && ! $sizes->looksLikeWearSize($m[2])
            && ! $sizes->looksLikeWearSize($m[3])) {
            $out[] = $m[1];
            $out[] = $m[1].$m[2];
        }

        return array_values(array_unique($out));
    }

    /**
     * Prefiksy tokenów typu z nazwy — zawężają indeks, gdy model to krótki numer (109/O → 109).
     *
     * @return list<string>
     */
    public function catalogTypeTokenPrefixes(Product $product): array
    {
        $out = [];
        foreach ($this->requiredTypeStems($product, true) as $stems) {
            foreach ($stems as $stem) {
                $stem = mb_strtolower(trim($stem));
                if (mb_strlen($stem) >= 4) {
                    $out[] = $stem;
                }
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
        // „…-p-2110” to ten sam identyfikator sklepu co „…-p2110”: osobny człon
        // po myślniku zrównał kartę odczynnika z końcówką SKU R014BAP2110
        $path = (string) preg_replace('/[_-][pc][_-]?\d+(?=\/|$)/u', '', $path);
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

    /** 54331 i 54335 to ten sam G10 Flex, inny rozmiar — nie „cudzy model”. */
    private function looksLikeSizeSiblingCode(string $token, Product $product): bool
    {
        $ours = preg_replace('/\D+/u', '', (string) $product->sku) ?? '';
        $theirs = preg_replace('/\D+/u', '', $token) ?? '';
        if (strlen($ours) < 5 || strlen($theirs) !== strlen($ours)) {
            return false;
        }

        return substr($ours, 0, -1) === substr($theirs, 0, -1);
    }

    /** „471” z tytułu CovaSpec 471 to nasz model, nie cudzy kod przy SKU 047106941E. */
    private function tokenMatchesOurCode(string $token, string $code): bool
    {
        if ($token === '' || $code === '') {
            return false;
        }
        if ($token === $code || str_starts_with($code, $token) || str_starts_with($token, $code)) {
            return true;
        }
        if (preg_match('/\p{L}/u', $code) === 1 && str_ends_with($code, $token)) {
            return true;
        }
        if (preg_match('/\p{L}/u', $code) === 1 && preg_match('/\p{L}/u', $token) === 1
            && (str_ends_with($code, $token) || str_ends_with($token, $code))) {
            return true;
        }

        return false;
    }

    public function hayHasProductCode(string $hay, Product $product): bool
    {
        foreach ($this->productCodes($product) as $code) {
            if ($this->codeInText($hay, $code)) {
                return true;
            }
        }

        return $this->gluedNumericModelConfirmsCard($hay, $product);
    }

    /**
     * Sklep skleja 902→9022002. Ansell 121 w 12111020 Stahlwille to nie ten model.
     */
    private function gluedNumericModelConfirmsCard(string $hay, Product $product): bool
    {
        if (! $this->urlHasGluedNumericModel($hay, $product)) {
            return false;
        }
        if ($this->ansellCatalogBits($product)['model'] === null) {
            return true;
        }

        return $this->hayHasBrand($hay, $product) || $this->ansellOfficialPathHasModel($hay, $product);
    }

    /**
     * Sklep skleja krótki model z id karty (902 → …-9022002) albo dopiska płeć (905 → 905m).
     */
    public function urlHasGluedNumericModel(string $hay, Product $product): bool
    {
        $hay = mb_strtolower($hay);
        $allowGender = $this->nameAllowsGenderModelSuffix($product);
        foreach ($this->compactProductCodes($product) as $code) {
            if (preg_match('/^\d{3,4}$/u', $code) !== 1) {
                continue;
            }
            $quoted = preg_quote($code, '/');
            if (preg_match('/(?<![0-9a-z])'.$quoted.'[0-9]{3,}(?![0-9a-z])/u', $hay) === 1) {
                return true;
            }
            if ($allowGender
                && preg_match('/(?<![0-9a-z])'.$quoted.'[md](?![0-9a-z])/u', $hay) === 1) {
                return true;
            }
        }

        return false;
    }

    /** Peleryna / kurtka 905m, 905d — nie 1000g ani L9020300. */
    private function nameAllowsGenderModelSuffix(Product $product): bool
    {
        $name = $this->normalizeTypeText((string) $product->name);
        foreach (['cape', 'jacket', 'trousers', 'vest', 'coverall', 'sweatshirt', 'clothing', 'apron'] as $key) {
            if ($this->textHasTypeStem($name, self::TYPE_STEMS[$key])) {
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
        $code = trim($code);
        // „321001900010budowlane” — sklep skleja długi numer z kolejnym słowem
        if (preg_match('/^\d{8,}$/u', $code) === 1
            && preg_match('/(?<![0-9])'.preg_quote($code, '/').'(?![0-9])/u', $hay) === 1) {
            return true;
        }
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

    /** Motoryzacja / marketplace z kolizją SKU (V742 → O'Reilly), nie karta BHP. */
    public function looksLikeUnrelatedRetailHost(string $url, Product $product): bool
    {
        $host = mb_strtolower((string) (parse_url($url, PHP_URL_HOST) ?? ''));
        if ($host === '') {
            return false;
        }
        $brand = mb_strtolower(trim((string) $product->manufacturer));
        if ($brand !== '' && mb_strlen($brand) >= 3 && str_contains($host, $brand)) {
            return false;
        }
        if (self::isJunkSearchHost($url)) {
            return true;
        }
        foreach ([
            'oreillyauto.com', 'autozone.com', 'rockauto.com', 'napaonline.com',
            'advanceautoparts.com', 'autopartswarehouse.com', 'etsy.com',
        ] as $blocked) {
            if ($host === $blocked || str_ends_with($host, '.'.$blocked)) {
                return true;
            }
        }
        $hay = mb_strtolower($url);
        foreach (['crankcase', 'breather-hose', 'ignition-coil', 'spark-plug'] as $auto) {
            if (str_contains($hay, $auto)) {
                return true;
            }
        }

        return false;
    }

    /** RS „12111020 ratchet” / „132-5274 screwdriver” przy kombinezonie Ansell. */
    public function looksLikeUnrelatedHandToolPage(string $hay, Product $product): bool
    {
        if (! $this->nameRequiresArticleType($product)) {
            return false;
        }
        $name = $this->normalizeTypeText((string) $product->name.' '.(string) $product->sku);
        foreach (['gloves', 'coverall', 'jacket', 'cape', 'trousers', 'vest', 'sweatshirt', 'apron', 'clothing'] as $key) {
            if ($this->textHasTypeStem($name, self::TYPE_STEMS[$key])) {
                $page = $this->normalizeTypeText($hay);
                $hits = 0;
                foreach ([
                    'ratchet', 'screwdriver', 'socket wrench', 'spanners', 'hand tools',
                    'quick release', 'vde approved', 'din 3122', 'hex torx',
                    'socket wrenches', 'reversible ratchet',
                ] as $marker) {
                    if (str_contains($page, $marker)) {
                        $hits++;
                    }
                }

                return $hits >= 2;
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

    /**
     * Tablica „Uwaga pies Beagle” nie jest butem BEAGLE — sama rasa w tytule nie wystarczy.
     */
    public function looksLikeUnrelatedSignage(string $hay, Product $product): bool
    {
        $productBlob = $this->normalizeTypeText(
            trim((string) $product->name.' '.(string) $product->sku.' '.(string) ($product->category ?? ''))
        );
        if (preg_match('/\b(tablic[ae]|tabliczk|znak ostrzeg|piktogram|uwaga pies)\b/u', $productBlob) === 1) {
            return false;
        }
        $page = $this->normalizeTypeText($hay);

        return preg_match(
            '/\b(tablica informacyjna|tabliczka informacyjna|tablica ostrzeg|znak ostrzeg'
            .'|uwaga pies|warning dog|beware of(?: the)? dog|information board'
            .'|tablica pvc|naklejka uwaga)\b/u',
            $page
        ) === 1;
    }

    /** T-31 tablicy nie jest koszulą — odzież z tym samym kodem odpada. */
    public function looksLikeUnrelatedApparel(string $hay, Product $product): bool
    {
        $productBlob = $this->normalizeTypeText(
            trim((string) $product->name.' '.(string) $product->sku.' '.(string) ($product->category ?? ''))
        );
        if (! $this->textHasTypeStem($productBlob, self::TYPE_STEMS['signage'])) {
            return false;
        }
        $page = $this->normalizeTypeText($hay);

        return preg_match(
            '/\b(koszul|flanel|bluza|kurtk|spodn|ogrodniczk|sweatshirt|jacket|trouser)\w*\b/u',
            $page
        ) === 1;
    }

    private function codePattern(string $code): ?string
    {
        $raw = preg_split('/[^\p{L}\d]+/u', mb_strtolower(trim($code)), -1, PREG_SPLIT_NO_EMPTY) ?: [];
        $chunks = [];
        foreach ($raw as $chunk) {
            // 3W1 na karcie to „3 W 1” — rozdziel litery i cyfry, zostaw NB27 jako nb+27
            if (preg_match_all('/\d+|\p{L}+/u', $chunk, $bits) === 1 || ($bits[0] ?? []) === []) {
                $chunks[] = $chunk;
            } else {
                foreach ($bits[0] as $bit) {
                    $chunks[] = $bit;
                }
            }
        }
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
        $page = $this->normalizeTypeText($hay);
        $required = $this->requiredTypeStems($product, true);
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
     * Typ z nazwy (czapka / spodnie) — bez kategorii „Odzież”, żeby nie wymagać „odziez” w URL.
     */
    public function imageHayHasRequiredType(string $hay, Product $product): bool
    {
        if ($this->ansellOfficialPathHasModel($hay, $product)) {
            return true;
        }
        $page = $this->normalizeTypeText($hay);
        $required = $this->requiredTypeStems($product, false);
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

    public function nameRequiresArticleType(Product $product): bool
    {
        $name = $this->normalizeTypeText((string) $product->name);
        foreach (self::TYPE_STEMS as $stems) {
            if ($this->textHasTypeStem($name, $stems)) {
                return true;
            }
        }

        return $this->skuImpliesFootwear($product);
    }

    public function imageUrlHasForeignType(string $url, Product $product): bool
    {
        $name = $this->normalizeTypeText((string) $product->name);
        $hay = $this->normalizeTypeText(urldecode($url).' '.$this->decodeEmbeddedUrls($url));
        $own = [];
        foreach (self::TYPE_STEMS as $key => $stems) {
            if ($this->textHasTypeStem($name, $stems)) {
                $own[$key] = true;
            }
        }
        if ($own === [] && $this->skuImpliesFootwear($product)) {
            $own['footwear'] = true;
        }
        if ($own === []) {
            return false;
        }
        foreach (self::TYPE_STEMS as $key => $stems) {
            if (isset($own[$key])) {
                continue;
            }
            if ($this->textHasTypeStem($hay, $stems)) {
                return true;
            }
        }

        return false;
    }

    public function requiredArticleTypeLabel(Product $product): ?string
    {
        $name = $this->normalizeTypeText((string) $product->name);
        $labels = [
            'gloves' => 'rękawice',
            'coverall' => 'kombinezon',
            'jacket' => 'kurtka',
            'cape' => 'peleryna',
            'trousers' => 'spodnie lub ogrodniczki',
            'cap' => 'czapka / nakrycie głowy z daszkiem',
            'sweatshirt' => 'bluza',
            'vest' => 'kamizelka',
            'mask' => 'maska',
            'footwear' => 'obuwie',
            'helmet' => 'kask / hełm',
            'goggles' => 'okulary lub gogle',
            'apron' => 'fartuch',
            'hearing' => 'nauszniki',
            'harness' => 'szelki',
            'clothing' => 'odzież',
            'signage' => 'tablica / oznakowanie',
            'extinguisher' => 'gaśnica',
            'firstaid' => 'apteczka',
            'tape' => 'taśma',
            'mat' => 'chodnik lub dywanik',
        ];
        foreach (self::TYPE_STEMS as $key => $stems) {
            if ($this->textHasTypeStem($name, $stems)) {
                return $labels[$key] ?? $key;
            }
        }
        if ($this->skuImpliesFootwear($product)) {
            return $labels['footwear'];
        }

        return null;
    }

    /**
     * @return list<list<string>>
     */
    private function requiredTypeStems(Product $product, bool $includeCategory): array
    {
        $fromName = $this->typeStemsInText((string) $product->name);
        if ($fromName !== []) {
            return $fromName;
        }
        // Drzewo sklepu („RĘKAWICE…”) nie nadpisuje nazwy wyrobu
        // („Płatek zaworu wydechowego”) — inaczej indeks ma kartę, a potwierdzenie ją zjada.
        if ($includeCategory && count($this->nameWords($product)) < 2) {
            $fromCategory = $this->typeStemsInText((string) ($product->category ?? ''));
            if ($fromCategory !== []) {
                return $fromCategory;
            }
        }
        if ($this->skuImpliesFootwear($product)) {
            return [self::TYPE_STEMS['footwear']];
        }

        return [];
    }

    /**
     * @return list<list<string>>
     */
    private function typeStemsInText(string $text): array
    {
        $normalized = $this->normalizeTypeText($text);
        $required = [];
        $keys = [];
        foreach (self::TYPE_STEMS as $key => $stems) {
            if ($this->textHasTypeStem($normalized, $stems)) {
                $required[] = $stems;
                $keys[] = $key;
            }
        }
        // „Ubranie [kurtka + spodnie]” to komplet — sklep ma /ubranie-model-101112, nie kurtkę
        $setPieces = ['jacket', 'trousers', 'coverall', 'vest', 'sweatshirt', 'apron'];
        if (in_array('clothing', $keys, true)
            && array_intersect($keys, $setPieces) !== []
            && count($required) > 1) {
            return [self::TYPE_STEMS['clothing']];
        }
        // PVC BOOT / sock przy CVRL to kombinezon z butami, nie obuwie
        if (in_array('coverall', $keys, true) && in_array('footwear', $keys, true)) {
            $required = [];
            foreach ($keys as $key) {
                if ($key === 'footwear') {
                    continue;
                }
                $required[] = self::TYPE_STEMS[$key];
            }
        }

        return $required;
    }

    /** G3175/40, CADIZ-42 — rozmiar EU w SKU oznacza obuwie, nawet przy nazwie „TRACK”. */
    private function skuImpliesFootwear(Product $product): bool
    {
        // Numer magazynowy to jeden ciąg cyfr — „33” z 1002933 nie jest rozmiarem buta.
        if (preg_match('/^\d{5,}$/u', trim((string) $product->sku)) === 1) {
            return false;
        }
        $size = (new ProductSizeVariant)->extractSize($product->name, $product->sku);
        if ($size === null || ! is_numeric($size)) {
            return false;
        }
        // „45CM” w nazwie to długość mankietu rękawicy, nie rozmiar obuwia.
        if (preg_match('/\b'.preg_quote((string) $size, '/').'\s*(?:cm|mm|m\b|")/iu', (string) $product->name) === 1) {
            return false;
        }
        $n = (int) $size;

        return $n >= 32 && $n <= 50;
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
            if (in_array($stem, ['obuv', 'boty', 'schuh'], true)) {
                if (preg_match('/\b'.preg_quote($stem, '/').'/u', $normalized) === 1) {
                    return true;
                }

                continue;
            }
            if ($stem === 'shoe') {
                if (preg_match('/\bshoes?\b/u', $normalized) === 1) {
                    return true;
                }

                continue;
            }
            if ($stem === 'boot') {
                if (preg_match('/\bboots?\b/u', $normalized) === 1) {
                    return true;
                }

                continue;
            }
            if ($stem === 'glv') {
                if (preg_match('/\bglv\b/u', $normalized) === 1) {
                    return true;
                }

                continue;
            }
            if ($stem === 'pant') {
                if (preg_match('/\bpant(?:s|aloon)?\b/u', $normalized) === 1) {
                    return true;
                }

                continue;
            }
            if ($stem === 'tape') {
                if (preg_match('/\btapes?\b/u', $normalized) === 1) {
                    return true;
                }

                continue;
            }
            if ($stem === 'overall') {
                // „193mm Overall” / „Overall Length” to wymiar narzędzia, nie kombinezon
                if (preg_match('/(?:\d+\s*)?(?:mm|cm|in|inch)\s+overall\b/u', $normalized) === 1
                    || preg_match('/\boverall\s+(?:length|size|width)\b/u', $normalized) === 1) {
                    continue;
                }
                if (preg_match('/\boveralls?\b/u', $normalized) === 1) {
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
        if (preg_match('#--(?!pl$)[a-z]{2}$#i', $url) === 1) {
            $url = (string) preg_replace('#--[a-z]{2}$#i', '--pl', $url);
        }
        $brand = mb_strtolower($this->shortBrand((string) $product->manufacturer));
        if (! str_contains($brand, 'ansell')) {
            return $url;
        }
        $keep = implode('|', array_map(
            static fn (string $locale): string => preg_quote($locale, '#'),
            self::ANSELL_CARD_LOCALES
        ));
        if (preg_match('#^https?://(?:www\.)?ansell\.com/(?:'.$keep.')/products/#i', $url) === 1) {
            return $url;
        }

        return preg_replace(
            '#^(https?://(?:www\.)?ansell\.com)/.+?/(?=products/)#i',
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
        // cennik ma „Bole”, sklepy piszą Bollé/Bolle — zapytanie z „Bole” nic nie znajdzie
        if (in_array(mb_strtolower($first), ['bole', 'bolle', 'bollé', 'bolle safety', 'bollé safety'], true)) {
            return 'Bolle';
        }

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
        // alfanumeryczny kod (NB27): cały token — nie substring w NB27B.
        // „HSV 3W1” z spacją to fraza sklepu, nie jeden kod — granica słowa skleja HSV z „odblaskowa”.
        if ($this->isAlphanumericProductCode($token) && ! preg_match('/\s/u', $token)) {
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

        $boundary = mb_strlen($token) >= 8 ? '(?![0-9])' : '(?![0-9a-z])';
        $count = preg_match_all(
            '/(?<![0-9])'.preg_quote($token, '/').$boundary.'/iu',
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
            '#(trzewik|p[oó]łbut|polbut|\bbuty\b|obuwie|\bobuv\b|\bboty\b|schuh|chaussure|footwear'
            .'|wader|woder|spodniobut|\bs1\b|\bs3\b|\bsrc\b|\bhro\b|demar|befado)#u',
            $nameSku
        ) || preg_match('#(demar|befado)#u', $brand) || $this->skuImpliesFootwear($product)) {
            return 'buty ochronne';
        }

        // CVRL / AlphaTec 4000 to kombinezon — tylko z nazwy/SKU, nie z „Kombinezony / akcesoria”
        if (preg_match('#(cvrl|coverall|kombinezon|overall|alphatec|chin\\s*strap|c/w\\s*hood)#u', $nameSku) === 1) {
            return 'kombinezon';
        }

        // Rękawice ocieplane polarem muszą wygrać z regułą odzieży niżej
        if (preg_match('#(glove|\bglv\b|r[eę]kaw|maxiflex|maxicut|maxidry)#u', $blob)) {
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
        if ($this->distributorPrefixedCatalogSku($product) !== '') {
            return true;
        }
        // 10–12 cyfr to numer magazynowy CXS/Canis (310000300010), nie EAN-13
        if (preg_match('/^\d{10,12}$/u', $sku) === 1) {
            return true;
        }
        if (preg_match('/CONFIG/iu', $sku) === 1) {
            return true;
        }
        // SOR76332-08 — prefiks Sordin z cennika, na karcie jest 76302 / Supreme Pro-X
        if (preg_match('/^SOR[-]?\d{4,6}(?:-\d{2,3})?$/iu', $sku) === 1) {
            return true;
        }
        // 047106941E — artykuł dystrybutora + wariant, w katalogu jest CovaSpec 471
        if (preg_match('/^\d{7,12}[A-Z]$/u', $sku) === 1) {
            return true;
        }
        // 03-952-UK / 10120606-SP — akcesorium z krajem albo częścią
        if (preg_match('/^[A-Z0-9.\-]+-(UK|EU|US|CF|SP)$/iu', $sku) === 1) {
            return true;
        }
        // 1002933 przy nazwie ALTOCHUT 1012 — inny numer w cenniku niż model
        if (preg_match('/^\d{6,9}$/u', $sku) === 1) {
            $skuCompact = $this->compactCode($sku);
            foreach ($this->shopIdentityPhrases($product) as $phrase) {
                $shopCompact = $this->compactCode($phrase);
                if ($shopCompact !== '' && ! str_contains($skuCompact, $shopCompact)
                    && ! str_contains($shopCompact, $skuCompact)) {
                    return true;
                }
            }
        }

        return false;
    }

    public function rawSkuIsOfflineNoise(Product $product): bool
    {
        return $this->looksLikeInternalSku($product)
            || $this->looksLikeWarehouseArticleSku($product)
            || $this->skuDiffersFromStrongShopIdentity($product);
    }

    /**
     * MA1120 / N15T138 / 35110 — w sklepie jest REUNION / Model 138 / DRAFT.
     */
    public function skuDiffersFromStrongShopIdentity(Product $product): bool
    {
        $shop = $this->firstStrongShopPhrase($product);
        if ($shop === '' || $this->isColorOnlyShopPhrase($shop)) {
            return false;
        }
        $sku = trim((string) $product->sku);
        if ($sku === '') {
            return false;
        }
        $skuCompact = $this->compactCode($sku);
        $shopCompact = $this->compactCode($shop);
        if ($skuCompact !== '' && $shopCompact !== '' && (
            $skuCompact === $shopCompact
            || str_contains($shopCompact, $skuCompact)
            || str_contains($skuCompact, $shopCompact)
        )) {
            return false;
        }
        $shopLooksLikeTrade = preg_match('/\p{L}{4,}/u', $shop) === 1
            || ($this->manufacturerIsThreeM($product) && (
                preg_match('/^SJ\d{4}/iu', $shop) === 1
                || preg_match('/^(?:PN)?\d{3,6}[A-Z]{0,2}$/iu', $shop) === 1
            ));
        if (! $shopLooksLikeTrade) {
            return false;
        }
        $skuLooksLikeCode = $this->looksLikeWarehouseArticleSku($product)
            || $this->looksLikeInternalSku($product)
            || preg_match('/^[A-Z]{1,3}\d{3,}/iu', $sku) === 1
            || preg_match('/^\d{4,}$/u', $skuCompact) === 1
            || preg_match('/^[A-Z0-9]{2,}\d[A-Z0-9]*$/iu', $sku) === 1;

        return $skuLooksLikeCode && $this->isStrongShopPhrase($shop);
    }

    /**
     * Numer katalogowy odczytany z kodu magazynowego: 212804580000 → 2128-045-800.
     *
     * @return list<string>
     */
    public function catalogArticleCodes(Product $product): array
    {
        $sku = trim((string) $product->sku);
        $prefixed = $this->distributorPrefixedCatalogSku($product);
        if ($prefixed !== '') {
            return [$prefixed];
        }
        if (preg_match('/^SOR[-]?(\d{4,6})(?:-(\d{2,3}))?$/iu', $sku, $sordin) === 1) {
            $out = [$sordin[1]];
            if (($sordin[2] ?? '') !== '') {
                $out[] = $sordin[1].'-'.$sordin[2];
            }

            return $out;
        }
        if ($this->manufacturerIsThreeM($product) && preg_match('/^7100\d{6,}$/u', $sku) === 1) {
            return [$sku];
        }
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
        if (mb_strlen($digits) >= 12) {
            $out[] = mb_substr($digits, 0, 4).'-'.mb_substr($digits, 4, 3).'-'
                .mb_substr($digits, 7, 3).'-'.mb_substr($digits, 10, 2);
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
        $fromSize = $this->catalogSkuWithoutSize($product);
        $fromSizeList = ($fromSize !== '' && mb_strtolower($fromSize) !== mb_strtolower($sku)
            && $this->isUsableSizeVariant($fromSize))
            ? [$fromSize]
            : [];
        // „ONE4ALL-IT08” — IT to rozmiar (taille), nie litera I i osobne T08
        if (preg_match('/^(.+)-IT(0\d|1[0-4])$/u', $sku, $it) === 1) {
            $core = rtrim($it[1], "-/+_. \t");

            // Całe „-IT08” jest rozmiarem, więc wariant obcięty o same cyfry („ONE4ALL-IT”)
            // rozcinałby marker w połowie — w sklepie taki kod nie istnieje.
            return $this->isUsableSizeVariant($core) ? [$core] : [];
        }
        if (preg_match('/^(.*?)([A-Za-z0-9]{0,3})T(?:0\d|1[0-4])$/u', $sku, $m) !== 1) {
            return $fromSizeList;
        }

        // „CROSSFOREST10” — tu „T” kończy słowo FOREST, a rozmiarem jest samo 10.
        // Którego odczytu użyć, wie dopiero strona sklepu, więc dajemy oba.
        $out = [];
        foreach ($fromSizeList as $candidate) {
            $out[$candidate] = true;
        }
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

        // „URG-C-SPODNIE” / „PROS-URG-A-SPODNIE” — prefiks hurtowni + typ na końcu
        $kept = [];
        foreach ($segments as $segment) {
            if ($this->isHouseSkuPrefix($segment) && mb_strtolower($segment) !== 'urg') {
                continue;
            }
            if (preg_match('/^\p{L}{4,}$/u', $segment) === 1 && ! $this->isHouseSkuPrefix($segment)) {
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
        $core = $this->manufacturerLegalCore((string) $product->manufacturer);
        if ($core !== '' && $core !== $main) {
            $out[] = $core;
        }
        foreach (preg_split('/[^a-z0-9]+/u', $main.' '.$core) ?: [] as $part) {
            if ($this->looksLikeBrandToken($part) || $this->looksLikeShortManufacturerCore($part)) {
                $out[] = $part;
            }
        }
        // „Rękawica … TEGERA 104” przy Ejendals — tylko znana linia katalogowa, nie model LUCKY.
        $catalogBrands = array_fill_keys($this->catalogBrandTokens(), true);
        foreach (preg_split('/[^\p{L}\p{N}]+/u', (string) $product->name) ?: [] as $raw) {
            $low = mb_strtolower($raw);
            if (preg_match('/^\p{Lu}{4,}$/u', $raw) === 1 && $this->looksLikeBrandToken($low)
                && ! $this->isColorWord($low) && isset($catalogBrands[$low])) {
                $out[] = $low;
            }
        }
        foreach ($this->nameBrandKeys($product) as $key) {
            $out[] = str_replace('-', ' ', $key);
            $out[] = $key;
        }
        foreach ($this->brandFamilyOf($this->shortBrand((string) $product->manufacturer)) as $alias) {
            $out[] = $alias;
        }
        if ($core !== '') {
            foreach ($this->brandFamilyOf($core) as $alias) {
                $out[] = $alias;
            }
        }
        if ($this->looksLikeUrgentGloveSeries($product)) {
            $out[] = 'urgent';
        }
        $hint = $this->inferredBrandHint($product);
        if ($hint !== '') {
            $out[] = mb_strtolower($hint);
        }
        foreach ($this->officialCatalogHosts($product) as $host) {
            $label = explode('.', $host)[0] ?? '';
            if (mb_strlen($label) >= 3 && preg_match('/^[a-z0-9]+$/u', $label) === 1) {
                $out[] = $label;
            }
        }

        return array_values(array_unique($out));
    }

    /**
     * Marki własne produktu (wraz z aliasami linii) — nie odrzucamy ich w URL zdjęcia.
     *
     * @return array<string, true>
     */
    private function ownBrandTokens(Product $product): array
    {
        $out = [];
        foreach ($this->acceptedBrands($product) as $brand) {
            foreach ($this->brandFamilyOf($brand) as $alias) {
                $out[$alias] = true;
            }
        }

        return $out;
    }

    /**
     * @return list<string>
     */
    private function catalogBrandTokens(): array
    {
        $out = [];
        foreach (array_keys((array) config('enrichment.manufacturer_domains', [])) as $key) {
            foreach ($this->brandFamilyOf((string) $key) as $alias) {
                $out[$alias] = true;
            }
        }
        foreach (['portwest', 'tegera', 'ejendals', 'showa', 'jalas', 'kleenguard', 'cofra', 'coverguard'] as $extra) {
            $out[$extra] = true;
        }

        return array_keys($out);
    }

    /**
     * @return list<string>
     */
    private function brandFamilyOf(string $brand): array
    {
        $brand = mb_strtolower(trim($brand));
        $compact = preg_replace('/[^a-z0-9]+/u', '', $brand) ?? $brand;
        $families = [
            'ansell' => ['ansell', 'kleenguard', 'kimberly', 'ringers', 'activarmr', 'alphatec', 'bioclean'],
            'kleenguard' => ['ansell', 'kleenguard', 'kimberly'],
            'atg' => ['atg', 'maxiflex', 'maxicut', 'maxidry'],
            'maxiflex' => ['atg', 'maxiflex', 'maxicut', 'maxidry'],
            'urgent' => ['urgent', 'pilne'],
            'pilne' => ['urgent', 'pilne'],
            'pros' => ['pros', 'aj group', 'ajgroup'],
            'aj group' => ['pros', 'aj group', 'ajgroup'],
            'aj-group' => ['pros', 'aj group', 'ajgroup'],
            'ajgroup' => ['pros', 'aj group', 'ajgroup'],
            'ejendals' => ['ejendals', 'tegera'],
            'tegera' => ['ejendals', 'tegera'],
            'delta' => ['delta', 'deltaplus'],
            'delta-plus' => ['delta', 'deltaplus'],
            'deltaplus' => ['delta', 'deltaplus'],
            'gvs' => ['gvs', 'rpb'],
            'rpb' => ['gvs', 'rpb'],
            'sir' => ['sir', 'sirsafety'],
            'sir safety' => ['sir', 'sirsafety'],
            'sir-safety' => ['sir', 'sirsafety'],
            'sordin' => ['sordin', 'hellberg'],
            'hellberg' => ['sordin', 'hellberg'],
            'arelax' => ['arelax', 'artra'],
            'artra' => ['arelax', 'artra'],
            'eider' => ['eider', 'cerva'],
            'cerva' => ['eider', 'cerva'],
            'infield' => ['infield'],
            'bole' => ['bolle', 'bollé', 'bole'],
            'bolle' => ['bolle', 'bollé', 'bole'],
            'bollé' => ['bolle', 'bollé', 'bole'],
            'bollesafety' => ['bolle', 'bollé', 'bole'],
        ];
        $out = [];
        if ($compact !== '' && (mb_strlen($compact) >= 4
            || $this->looksLikeShortManufacturerCore($compact)
            || in_array($compact, ['3m', 'msa', 'atg', 'kcl', 'gvs', 'pip'], true))) {
            $out[] = $compact;
        }
        foreach ($families[$brand] ?? $families[$compact] ?? [] as $alias) {
            $out[] = $alias;
        }

        return array_values(array_unique($out));
    }

    /** Słowo z nazwy/producenta, które jest linią produktu, nie typem PPE. */
    /** „MAT Sp. z o.o.” → „mat” — sklepy piszą MAT, nie formę prawną. */
    private function manufacturerLegalCore(string $manufacturer): string
    {
        $brand = $this->shortBrand($manufacturer);
        $stripped = preg_replace(
            '/[\s,]+(?:sp(?:[óo]łka)?\.?\s*z\s*o\.?\s*o\.?|s\.?\s*a\.?|gmbh|ltd\.?|limited|inc\.?|corp\.?|s\.?r\.?o\.?|llc)\.?$/iu',
            '',
            $brand
        ) ?? $brand;
        $core = mb_strtolower(trim($stripped));

        return $this->looksLikeShortManufacturerCore($core) || mb_strlen($core) >= 4
            ? $core
            : '';
    }

    private function looksLikeShortManufacturerCore(string $word): bool
    {
        $word = mb_strtolower(trim($word));

        return preg_match('/^[a-z]{3}$/u', $word) === 1
            && ! in_array($word, ['the', 'and', 'for', 'new', 'old'], true);
    }

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

    /** Nazwa z cennika, bez myślnika i „brak danych”. */
    public function usableProductName(Product $product): string
    {
        $name = trim((string) $product->name);
        if ($name === '' || preg_match('/^[-–—_.\/\s]+$/u', $name) === 1) {
            return '';
        }
        $norm = mb_strtolower(trim((string) Str::ascii($name)));
        $norm = (string) preg_replace('/\s+/u', ' ', $norm);
        if (in_array($norm, ['brak danych', 'brak', 'n/a', 'na', 'none', 'null', 'bd'], true)) {
            return '';
        }

        return $name;
    }

    public function strippedProductName(Product $product): string
    {
        $name = $this->usableProductName($product);
        $sku = trim((string) $product->sku);
        if ($name !== '' && mb_strtolower($name) !== mb_strtolower($sku)) {
            $stripped = (new ProductSizeVariant)->stripSizeFromName($name);
            $stripped = $this->stripPackagingFromName($stripped !== '' ? $stripped : $name);

            return $stripped !== '' ? $stripped : $name;
        }

        return $this->variantBaseCodes($product)[0] ?? $sku;
    }

    /** „LEEMED (BOX/50PCS)” → „LEEMED” — opakowanie nie jest modelem. */
    private function stripPackagingFromName(string $name): string
    {
        $name = trim($name);
        $name = preg_replace(
            '/\s*[\(\[\{]\s*(?:box|carton|pack|opak\.?)[\s\/.\-]*\d{0,5}\s*(?:pcs?|szt|stk)?\s*[\)\]\}]\s*/iu',
            '',
            $name
        ) ?? $name;
        $name = preg_replace(
            '/\s*\b(?:box|carton|pack)\s*[\/\-]?\s*\d{1,5}\s*(?:pcs?|szt|stk)\b/iu',
            '',
            $name
        ) ?? $name;
        $name = preg_replace('/\s*\b\d{1,5}\s*(?:pcs?|szt|stk)\b/iu', '', $name) ?? $name;
        $name = preg_replace(
            '/\s*[-–]\s*(?:polybag|polybags|pouch|sachet|blister|dispenser|refill|opakowanie)\s*$/iu',
            '',
            $name
        ) ?? $name;

        return trim($name, " \t-–()");
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
        $name = $this->usableProductName($product);
        $sku = trim((string) $product->sku);
        if ($name === '' || mb_strtolower($name) === mb_strtolower($sku)) {
            return [];
        }
        $skuHay = preg_replace('/[^a-z0-9]+/iu', '', mb_strtolower($sku)) ?? '';
        $tradeNames = $this->catalogTradeNames($product);
        $out = [];
        if (preg_match_all('/(\p{L}{3,12})\s+(S[1-5]S?)\b/u', $name, $safetyHits, PREG_SET_ORDER) !== false) {
            foreach ($safetyHits as $safety) {
                if (! $this->isGenericCatalogNameWord($safety[1])) {
                    $out[] = $safety[1].' '.$safety[2];
                }
            }
        }
        // OPSBT11 → OPSB; „OPEX” z angielskiej nazwy cennika nie stoi na karcie
        if ($this->skuSizeVariants($product) !== []) {
            return $out;
        }
        foreach ($this->embeddedModelCodes($name) as $embedded) {
            $out[] = $embedded;
        }
        foreach (preg_split('/[^\p{L}\p{N}]+/u', $name) ?: [] as $word) {
            $word = trim((string) $word);
            if ($word === '' || mb_strlen($word) < 4 || mb_strlen($word) > 16) {
                continue;
            }
            if (preg_match('/^T\d{1,2}$/iu', $word) === 1 || $this->isPackOrSizeToken($word)) {
                continue;
            }
            if (preg_match('/\d/u', $word) === 1 && ! $this->isStrongShopPhrase($word)) {
                continue;
            }
            if ($this->isGenericCatalogNameWord($word) || $this->isHouseSkuPrefix($word)
                || $this->tokenIsArticleTypeWord($word)
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
            'nowosc', 'nowość', 'wzwyz', 'wzwyż', 'technology', 'advanced', 'flexible',
            'flex', 'easy', 'ntrl', 'glv', 'natural', 'nitrile', 'nitryl', 'palm', 'coated',
            'handling', 'foam', 'welur', 'metalic', 'metallic', 'bydleca', 'bydlęca', 'elastanem',
            'kartonikow', 'kartoników', 'ocynk', 'gorny', 'górny', 'sznurowane',
            'kadry', 'opinacze', 'polsaperki', 'półsaperki', 'canvas', 'color', 'colour',
            'stock', 'ce', 'non', 'met', 'ociepl', 'wentylacja', 'pachami', 'uiglenie',
            'nierdzewnej', 'techniczne', 'polietylenowe', 'jednorazowe', 'wlokninowe',
            'low', 'mid', 'high', 's1', 's2', 's3', 's4', 's5', 'src', 'hro',
            'battery', 'hose', 'belt', 'buckle', 'adapter', 'charger', 'clip', 'plug',
            'hinge', 'door', 'assembly', 'mount', 'inch', 'volt', 'calibration',
            'breathing', 'airline',
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
        if ($this->isDescriptiveIdentityWord($variant) || $this->isLineQualifierWord($variant)
            || $this->isPackOrSizeToken($variant)) {
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
        if ($phrases === []) {
            return [];
        }
        if ($this->skuIsWarehousePrefix($product)) {
            return $this->preferBySkuTail($phrases, $product);
        }
        $core = preg_replace('/[^a-z0-9]+/u', '', mb_strtolower($this->catalogSkuWithoutSize($product))) ?? '';
        if ($core !== '' && preg_match('/\p{L}/u', $core) === 1 && preg_match('/\d/u', $core) === 1) {
            $matching = [];
            $rest = [];
            foreach ($phrases as $phrase) {
                $compact = preg_replace('/[^a-z0-9]+/u', '', mb_strtolower($phrase)) ?? '';
                if ($compact !== '' && ($compact === $core
                    || str_contains($core, $compact)
                    || str_contains($compact, $core))) {
                    $matching[] = $phrase;
                } else {
                    $rest[] = $phrase;
                }
            }
            if ($matching !== []) {
                return array_values(array_merge($matching, $rest));
            }
        }

        return $this->preferBySkuTail($phrases, $product);
    }

    /**
     * Numer stojący w nazwie („OX-ON Flexible Advanced 1900 CE”) to model ze sklepu.
     * Numer wyprowadzony z obciętego kodu cennikowego („92066” → „9206”) to domysł,
     * więc przy dwóch gołych liczbach pierwsza jest ta z nazwy.
     *
     * @param  list<string>  $phrases
     * @return list<string>
     */
    private function preferNumberFromName(array $phrases, Product $product): array
    {
        $name = (string) $product->name;
        if (trim($name) === '') {
            return $phrases;
        }
        $fromName = [];
        $rest = [];
        foreach ($phrases as $phrase) {
            if (preg_match('/^\d{3,6}$/u', trim($phrase)) === 1 && ! $this->phraseHasToken($name, $phrase)) {
                $rest[] = $phrase;
            } else {
                $fromName[] = $phrase;
            }
        }

        return $rest === [] ? $phrases : array_values(array_merge($fromName, $rest));
    }

    private function phraseIsBrandOnly(string $phrase, Product $product): bool
    {
        $phrase = mb_strtolower(trim($phrase));
        if ($phrase === '') {
            return false;
        }
        $brand = mb_strtolower(trim((string) $product->manufacturer));

        return $phrase === $brand || $phrase === mb_strtolower($this->shortBrand((string) $product->manufacturer));
    }

    /** „Okulary” / „przezroczyste” to typ i kolor, nie model. */
    private function phraseIsTypeOrColorOnly(string $phrase): bool
    {
        $words = preg_split('/[\s\-]+/u', trim($phrase)) ?: [];
        if ($words === []) {
            return true;
        }
        foreach ($words as $word) {
            $word = trim((string) $word);
            if ($word === '' || $this->isColorWord($word) || $this->tokenIsArticleTypeWord($word)
                || $this->isDescriptiveIdentityWord($word) || $this->isGenericCatalogNameWord($word)) {
                continue;
            }

            return false;
        }

        return true;
    }

    /** Numer magazynowy / prefiks cennika — bez shopIdentityPhrases, żeby nie zapętlać rankingu. */
    private function skuIsWarehousePrefix(Product $product): bool
    {
        $sku = trim((string) $product->sku);

        return preg_match('/^\d{10,12}$/u', $sku) === 1
            || preg_match('/^SOR[-]?\d{4,6}(?:-\d{2,3})?$/iu', $sku) === 1
            || preg_match('/^\d{7,12}[A-Z]$/u', $sku) === 1
            || preg_match('/^[A-Z0-9.\-]+-(UK|EU|US|CF|SP)$/iu', $sku) === 1
            || preg_match('/^\d{6,9}$/u', $sku) === 1
            || $this->distributorPrefixedCatalogSku($product) !== '';
    }

    /**
     * WST068GM → ST068GM — litera z cennika + kod katalogowy (U-Power ST068GM).
     */
    public function distributorPrefixedCatalogSku(Product $product): string
    {
        $sku = strtoupper(trim((string) $product->sku));
        if (preg_match('/^[A-Z]([A-Z]{2}\d{3}[A-Z]{2})$/u', $sku, $m) !== 1) {
            return '';
        }

        return $m[1];
    }

    /** Whirlpool w cenniku odzieży BHP — nie doklejaj do zapytań ani site:. */
    public function manufacturerLooksUnrelatedToProduct(Product $product): bool
    {
        $brand = mb_strtolower($this->shortBrand((string) $product->manufacturer));
        if ($brand === '') {
            return false;
        }
        $hay = mb_strtolower($this->usableProductName($product).' '.trim((string) $product->sku));
        if ($this->phraseHasToken($hay, $brand)) {
            return false;
        }
        $first = explode(' ', $brand)[0] ?? $brand;

        return in_array($first, [
            'whirlpool', 'indesit', 'hotpoint', 'amica', 'beko', 'candy',
            'electrolux', 'zanussi', 'gorenje', 'samsung', 'lg',
        ], true);
    }

    /**
     * @param  list<string>  $phrases
     * @return list<string>
     */
    private function preferBySkuTail(array $phrases, Product $product): array
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
                || $this->isPackOrSizeToken($segment)
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
            'advanced', 'flexible', 'flex', 'easy', 'natural', 'ntrl', 'comfort', 'wet',
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
                // HSV-3W1 — „3W1” to model, nie rozmiar; nie ucinaj serii na tym członie
                if ($kept !== [] && preg_match('/^(?=.*\d)(?=.*\p{L})[\p{L}\d]{2,8}$/u', $segment) === 1) {
                    $kept[] = $segment;
                    $series = implode(' ', $kept);

                    return $this->isUsableSeriesPhrase($series) ? $series : '';
                }
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
        $compact = preg_replace('/[^a-z0-9]+/iu', '', mb_strtolower($series)) ?? '';
        if (preg_match('/\p{L}/u', $compact) === 1 && preg_match('/\d/u', $compact) === 1
            && mb_strlen($compact) >= 4) {
            return true;
        }
        foreach ($words as $word) {
            if (mb_strlen($word) >= 4 && ! $this->isDescriptiveIdentityWord($word)
                && ! $this->isGenericCatalogNameWord($word)) {
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
            'obuv', 'boty', 'schuhe', 'chaussure', 'footwear',
            'meska', 'meskie', 'damska', 'damskie', 'krotka', 'krotki', 'odblaskowa', 'odblaskowy', 'odblaskowe',
            'przeciwdeszczowa', 'przeciwdeszczowy', 'przeciwdeszczowe',
            'trzewiki', 'sandaly', 'maska', 'kask', 'fartuch', 'ocieplana', 'ocieplany',
            'ostrzegawcza', 'ostr', 'nylon', 'nylonowa', 'nylonowy', 'polyester', 'polyes',
            'poliester', 'pongee', 'bawelna', 'skora', 'lateks', 'nitryl', 'poliuretan',
            'powlekany', 'powlekana', 'czarny', 'czarna', 'bialy', 'biala', 'zolty', 'zolta',
            'zolte', 'szary', 'szara', 'grafit', 'czerwony', 'niebieski', 'zielony', 'brazowy',
            'granat', 'pomarancz', 'robocza', 'robocze', 'ochronna', 'ochronne',
            'ubranie', 'odziez', 'pasa',
            'wodoochronne', 'wodoochronny', 'hivis', 'hi', 'vis', 'free', 'dmf', 'cieg',
            'scieg', 'size', 'rozmiar', 'taille', 'szt', 'kpl', 'guma', 'polar', 'zima',
            'czapka', 'czapki', 'daszek', 'daszkiem', 'szwedzka', 'szwedzki', 'szwedzkie',
            'kombinezon', 'kombinezony', 'skarpety', 'skarpetki', 'skarpeta',
            'wkladki', 'wkladka', 'koszula', 'ogrodniczki', 'plaszcz',
            'nowosc', 'nowość', 'wzwyz', 'wzwyż',
        ], true);
    }

    private function isHouseSkuPrefix(string $word): bool
    {
        $word = mb_strtolower(trim($word));

        return in_array($word, ['pros', 'urg', 'urgent', 'pilne', 'aj', 'sor'], true);
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

        if (in_array($word, [
            'black', 'white', 'grey', 'gray', 'blue', 'green', 'red', 'yellow',
            'orange', 'navy', 'brown', 'beige', 'pink', 'silver',
            'srebrny', 'srebrna', 'srebrne',
            'zielony', 'zielona', 'zielone', 'zolty', 'zolta', 'zolte',
            'czarny', 'czarna', 'czarne', 'bialy', 'biala', 'biale',
            'granatowy', 'granatowa', 'niebieski', 'niebieska',
            'czerwony', 'czerwona', 'czerwone',
            'przezroczysty', 'przezroczysta', 'przezroczyste', 'transparent',
        ], true)) {
            return true;
        }
        foreach (['zielon', 'zolt', 'czarn', 'bial', 'granat', 'niebiesk', 'czerwon', 'czerw', 'srebrn', 'przezroczyst'] as $stem) {
            if (str_starts_with($word, $stem)) {
                return true;
            }
        }

        return false;
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

    private function tokenIsArticleTypeWord(string $token): bool
    {
        if ($this->isApparelTypeWord($token)) {
            return true;
        }
        $normalized = $this->normalizeTypeText($token);
        foreach (self::TYPE_STEMS as $stems) {
            if ($this->textHasTypeStem($normalized, $stems)) {
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
            'buty', 'polbuty', 'trzewiki', 'sandaly', 'obuv', 'boty', 'schuhe',
            'chaussure', 'footwear', 'rukavice', 'bunda', 'kalhoty',
            'czapka', 'czapki', 'kombinezon', 'koszula', 'plaszcz', 'ogrodniczki',
            'ubranie', 'odziez',
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
    /**
     * Marki z nazwy, które mają stronę w config (Showa przy Eider, C500 → uvex).
     *
     * @return list<string>
     */
    public function nameBrandKeys(Product $product): array
    {
        $hay = mb_strtolower(trim((string) $product->name.' '.$product->sku));
        $out = [];
        foreach (array_keys((array) config('enrichment.manufacturer_domains', [])) as $key) {
            if (! is_string($key)) {
                continue;
            }
            $nk = trim((string) preg_replace('/[^a-z0-9]+/u', '-', mb_strtolower($key)), '-');
            if ($nk === '' || mb_strlen(str_replace('-', '', $nk)) < 4) {
                continue;
            }
            $label = str_replace('-', ' ', $nk);
            if ($this->phraseHasToken($hay, $nk) || $this->phraseHasToken($hay, $label)) {
                $out[] = $nk;
            }
        }
        $sku = trim((string) $product->sku);
        if (preg_match('/^\d{5}$/u', $sku) === 1
            && preg_match('/\bC[1-9]\d{2}\b/u', (string) $product->name) === 1) {
            $out[] = 'uvex';
        }

        return array_values(array_unique($out));
    }

    /**
     * @return list<string>
     */
    private function nameCatalogCodes(Product $product): array
    {
        $name = trim((string) $product->name);
        if ($name === '') {
            return [];
        }
        $out = [];
        if (preg_match_all(
            '/\b([A-Z]{2,8}[-\/][A-Z0-9]{1,8}(?:[-\/][A-Z0-9]{1,8}){0,2})\b/u',
            $name,
            $hits
        ) === false) {
            return [];
        }
        foreach ($hits[1] as $code) {
            $code = (string) $code;
            if ($this->isPackagingPhrase($code)) {
                continue;
            }
            if ($this->isStrongShopPhrase($code)) {
                $out[] = str_replace('/', '-', $code);
            }
        }

        return $out;
    }

    /**
     * @return list<string>
     */
    private function embeddedModelCodes(string $name): array
    {
        $name = trim($name);
        if ($name === '') {
            return [];
        }
        $out = [];
        if (preg_match_all('/(?<![\p{L}\d])([A-Z]{1,3}\d{2,5}|\d{4})(?![\p{L}\d])/u', $name, $hits) === false) {
            return [];
        }
        foreach ($hits[1] as $code) {
            $code = (string) $code;
            if ($this->isPackOrSizeToken($code)) {
                continue;
            }
            $out[] = $code;
        }

        return array_values(array_unique($out));
    }

    private function isPackOrSizeToken(string $token): bool
    {
        $token = mb_strtolower(trim($token));
        if (preg_match('/^(0?[5-9]|1[0-4]|0[0-9])$/u', $token) === 1) {
            return true;
        }
        if (in_array($token, [
            'box', 'pcs', 'pc', 'carton', 'pack', 'packs', 'pair', 'pairs',
            'polybag', 'polybags', 'pouch', 'sachet', 'blister', 'dispenser', 'refill',
        ], true)) {
            return true;
        }

        return preg_match('/^\d{1,5}(?:pcs?|szt|stk)$/u', $token) === 1;
    }

    private function isPackagingPhrase(string $phrase): bool
    {
        $parts = preg_split('/[^\p{L}\p{N}]+/u', mb_strtolower(trim($phrase)), -1, PREG_SPLIT_NO_EMPTY) ?: [];
        if ($parts === []) {
            return false;
        }
        foreach ($parts as $part) {
            if (! $this->isPackOrSizeToken((string) $part)) {
                return false;
            }
        }

        return true;
    }

    private function manufacturerKeyCandidates(Product $product): array
    {
        $brand = mb_strtolower($this->shortBrand((string) $product->manufacturer));
        $norm = trim((string) preg_replace('/[^a-z0-9]+/u', '-', $brand), '-');
        if ($norm === '') {
            return [];
        }
        $out = [$norm];
        $parts = explode('-', $norm);
        if (mb_strlen((string) ($parts[0] ?? '')) >= 4) {
            $out[] = $parts[0];
        }
        if (isset($parts[1])) {
            $out[] = $parts[0].'-'.$parts[1];
        }
        $core = $this->manufacturerLegalCore((string) $product->manufacturer);
        if ($core !== '') {
            $out[] = trim((string) preg_replace('/[^a-z0-9]+/u', '-', $core), '-');
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
                if ($nk === $want) {
                    $hit = true;
                    break;
                }
                if (mb_strlen($nk) >= 4 && mb_strlen($want) >= 4
                    && (str_contains($want, $nk) || str_contains($nk, $want))) {
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
