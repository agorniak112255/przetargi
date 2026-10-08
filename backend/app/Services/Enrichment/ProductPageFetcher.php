<?php

declare(strict_types=1);

namespace App\Services\Enrichment;

use App\Models\Product;
use App\Support\HtmlBareLessThan;
use App\Support\ImageUrlBlocklist;
use App\Support\NormCode;
use App\Support\ProductAccessoryExtractor;
use App\Support\ProductDescriptionText;
use App\Support\ProductSizeVariant;
use App\Support\ShopEntryId;
use GuzzleHttp\Cookie\CookieJar;
use GuzzleHttp\Psr7\Request as Psr7Request;
use GuzzleHttp\Psr7\Response as Psr7Response;
use GuzzleHttp\TransferStats;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Throwable;

final class ProductPageFetcher
{
    private const HTML_CACHE_PREFIX = 'enrich_page_html_v1:';

    // v2: wpis niesie też etykiety odsyłaczy (document_labels) — stare wpisy nie mają tego klucza
    private const READER_CACHE_PREFIX = 'enrich_page_reader_v2:';

    /** Adres po przekierowaniach dla strony z pamięci podręcznej (HTML trzymany pod adresem z zapytania). */
    private const FINAL_URL_CACHE_PREFIX = 'enrich_page_final_v1:';

    private const CACHE_TTL_HOURS = 24;

    private const MAX_CACHED_HTML_BYTES = 3_000_000;

    /**
     * Klasy i id bloków z innymi wyrobami (człon klasy: „related”, „product-related”, „block upsell”, „crosssell”,
     * „product-also”, „products-slider”, „recently-viewed”, „prev-next”). Bez „carousel”/„slider” w liczbie pojedynczej
     * — tak nazywa się też galeria zdjęć karty (gloves.co.uk: „carousel lightbox magnify”).
     */
    private const FOREIGN_BLOCK = '/(?:^|[\s_-])(?:related|upsell|up-sell|cross-?sell|similars?|recommend\w*|'
        .'also(?=[\s_-]|$)|also-?bought|alsobought|you-may-also|customers-also|recently-?viewed|viewed-products|'
        .'ostatnio-ogladane|podobne|polecane|polecamy|czesto-kupowane|frequently-bought|products-(?:slider|carousel)|'
        .'prev-next|product-prev|product-next)/i';

    /**
     * Blok „related” z plikami albo akcesoriami tego wyrobu („related-documents”, coba.com: <section id="accessories"
     * class="is-section related products"> w zakładce „Akcesoria”) — zostaje. Akcesoria to części wyrobu (krawędzie
     * Tough-Lock z kodami w tabeli części); ProductAccessoryExtractor i tak czyta je z pełnego HTML.
     */
    private const OWN_BLOCK = '/(?:^|[\s_-])(?:docs?|documents?|dokumenty|downloads?|files?|pliki|pdfs?|attachments?|'
        .'certificates?|certyfikaty|datasheets?|norms?|normy|accessor(?:y|ies)|akcesoria|zubeh(?:ö|oe?)r)(?:[\s_-]|$)/iu';

    /** Bezpieczniki kosztu cięcia bloków po nagłówkach (withoutRelatedProductHtml). */
    private const RELATED_CUT_MAX_ELEMENTS = 20000;

    private const RELATED_CUT_MAX_HEADINGS = 400;

    private const RELATED_CUT_MAX_FOREIGN_HEADINGS = 20;

    private const RELATED_CUT_MAX_CLIMB = 12;

    /**
     * Nagłówek bloku innych wyrobów, poradników i kategorii — tylko na początku krótkiego nagłówka. „Więcej” tylko
     * z grupą wyrobów: „Więcej ochrony dzięki powłoce” to treść karty. Bez „Akcesoria”/„Zubehör” (części wyrobu).
     */
    private const FOREIGN_HEADING = '/^\s*(?:podobne\s+produkty|produkty\s+podobne|powiązane\s+produkty|produkty\s+powiązane|'
        .'polecane\s+produkty|produkty\s+polecane|polecamy(?:\s+(?:również|też|także))?\s*:?\s*$|inni\s+kupili|klienci\s+(?:kupili|którzy|oglądali)|'
        .'często\s+kupowane|zobacz\s+(?:też|także|również)|inne\s+produkty|znaleźliśmy\s+inne|'
        .'(?:mogą|może)\s+cię\s+(?:również\s+|także\s+)?zainteresować|ostatnio\s+oglądane|'
        .'więcej\s+(?:produktów|rękawic|wyrobów|modeli|z\s+(?:tej\s+)?(?:kategorii|serii|kolekcji)|od\s+producenta)|'
        .'wybrane\s+kategorie|przewodniki?\s+zakupu|przewodniki?\s+zakupowe?|poradniki\b|'
        .'related\s+(?:products|items)|similar\s+products|you\s+may\s+also|customers\s+(?:also|who)|people\s+also|'
        .'frequently\s+bought|recently\s+viewed|more\s+from|other\s+products|buying\s+guides?|from\s+(?:the|our)\s+blog|'
        .'ähnliche\s+(?:produkte|artikel)|das\s+könnte\s+(?:sie|dich)\s+auch|kunden\s+kauften|zuletzt\s+angesehen|'
        .'weitere\s+produkte|ratgeber\b)/iu';

    private bool $bypassCache = false;

    private ?Product $matchingProduct = null;

    /** Profil producenta karty z fetch() — wzorce grafik reklamowych witryny (ImageUrlBlocklist); null bez karty. */
    private ?ManufacturerProfile $matchingProfile = null;

    /** @var list<array{url: string, reason: string}> */
    private array $rejections = [];

    /**
     * Etykieta odsyłacza, pod którym znaleziono dokument (adres => tekst linku). Na kartach sklepów
     * wszystkie pliki wiszą pod jednym „/download/file/id/…” i tylko etykieta mówi, co to za dokument.
     *
     * @var array<string, string>
     */
    private array $documentLabels = [];

    /**
     * Najwięcej stron w dzienniku przebiegu. Ponad limit wypada strona z najkrótszym tekstem (przy równych najstarsza),
     * nie najstarsza: strony producenta przychodzą w przebiegu najwcześniej, a źródło opisu to strona z treścią —
     * wypchnięcie najstarszych zabierałoby werdyktowi tożsamości właśnie stronę głównego źródła.
     */
    private const RUN_LOG_MAX_PAGES = 40;

    /**
     * Dziennik stron przebiegu wzbogacania (startRunLog … stopRunLog): każda strona oddana przez fetch(), po adresie
     * (Product::normalizeShopUrl). Strony karty przychodzą z kilku wywołań fetch w ProductEnrichmentService, a werdykt
     * tożsamości i zapis źródeł potrzebują ich razem. Null = dziennik wyłączony.
     *
     * @var array<string, array<string, mixed>>|null
     */
    private ?array $runLog = null;

    public function __construct(
        private readonly BlockedPageReader $blockedPages = new BlockedPageReader,
        private readonly ProductSearchIdentity $identity = new ProductSearchIdentity,
        private readonly PublicUrlFetcher $urls = new PublicUrlFetcher,
    ) {}

    public function bypassCache(bool $bypass = true): self
    {
        $this->bypassCache = $bypass;

        return $this;
    }

    /**
     * @param  list<array{url: string, title?: string, snippet?: string}>  $results
     * @param  list<string>  $manufacturerDomains  hosty producenta — wtedy bierzemy PDF ze strony (zwykle certyfikaty)
     * @return array{
     *     pages: list<array{url: string, text: string}>,
     *     image_urls: list<string>,
     *     trusted_image_urls: list<string>,
     *     document_urls: list<string>,
     *     document_labels: array<string, string>,
     *     rejected: list<array{url: string, reason: string}>
     * }
     */
    public function fetch(
        array $results,
        string $sku,
        int $maxPages = 2,
        array $manufacturerDomains = [],
        ?Product $product = null,
    ): array {
        $previous = $this->matchingProduct;
        $previousProfile = $this->matchingProfile;
        $previousRejections = $this->rejections;
        $previousLabels = $this->documentLabels;
        $this->matchingProduct = $product;
        // jak ProductEnrichmentService::profiles() — przez kontener, konstruktor bez zmian
        $this->matchingProfile = $product !== null ? app(ManufacturerProfiles::class)->for($product) : null;
        $this->rejections = [];
        $this->documentLabels = [];
        try {
            $out = $this->fetchInner($results, $sku, $maxPages, $manufacturerDomains);
            $out['rejected'] = CandidateRejection::unique($this->rejections);
            if ($this->runLog !== null) {
                foreach ($out['pages'] as $page) {
                    $this->logRunPage($page);
                }
            }

            return $out;
        } finally {
            $this->matchingProduct = $previous;
            $this->matchingProfile = $previousProfile;
            $this->rejections = $previousRejections;
            $this->documentLabels = $previousLabels;
        }
    }

    /**
     * @param  list<array{url: string, title?: string, snippet?: string}>  $results
     * @param  list<string>  $manufacturerDomains
     * @return array{
     *     pages: list<array{url: string, text: string}>,
     *     image_urls: list<string>,
     *     trusted_image_urls: list<string>,
     *     document_urls: list<string>,
     *     document_labels: array<string, string>
     * }
     */
    /**
     * Cała strona HTML do odczytu norm ze strony producenta (norms:from-manufacturer-pages). Ten sam klient, pamięć
     * podręczna i rozpoznanie zapory co przy opisie (fetchWave), ale bez czyszczenia treści: bramka tożsamości szuka
     * kodu w mikrodanych (cxs.net.pl ma „3210-012-000-00” tylko w itemprop="sku"), a ramka norm PrestaShopu stoi
     * w formularzu koszyka, który czyszczenie wycina. Bez czytnika zapór (BlockedPageReader) — on oddaje tekst,
     * nie HTML.
     *
     * Null: brak odpowiedzi, status błędu (404 strony wycofanego wyrobu to nie karta), zapora (Incapsula przed
     * ansell.com) albo treść, która nie jest stroną HTML (PDF karty technicznej pod adresem wyrobu).
     *
     * @return array{html: string, final_url: string, status: int, from_cache: bool}|null
     */
    public function fetchRaw(string $url): ?array
    {
        $url = trim($url);
        if (preg_match('#^https?://#i', $url) !== 1) {
            return null;
        }
        $cached = $this->cachedHtml($url);
        if ($cached !== null) {
            // pamięć podręczna trzyma adres z zapytania, nie po przekierowaniu — ten zostaje adresem strony
            return $this->looksLikeHtmlPage($cached) && ! $this->looksLikeBotWall($cached)
                ? ['html' => $cached, 'final_url' => $url, 'status' => 200, 'from_cache' => true]
                : null;
        }

        $response = $this->fetchWave([['url' => $url]])['0'] ?? null;
        if (! $response instanceof Response || ! $response->successful()) {
            return null;
        }
        $type = mb_strtolower($response->header('Content-Type'));
        $html = $response->body();
        if (($type !== '' && ! str_contains($type, 'html')) || ! $this->looksLikeHtmlPage($html)
            || $this->looksLikeBotWall($html)) {
            return null;
        }
        $final = $response->effectiveUri();

        return [
            'html' => $html,
            'final_url' => $final !== null ? (string) $final : $url,
            'status' => $response->status(),
            'from_cache' => false,
        ];
    }

    /**
     * Pary „norma → oznaczenie” z ramki norm strony, dosłownie — to samo co w opisie (normFacts), dla czytnika norm
     * ze strony producenta.
     *
     * @return list<array{label: string, value?: string}>
     */
    public function normFactsFromHtml(string $html): array
    {
        return $this->normFacts($html);
    }

    /**
     * Tytuły strony do bramki tożsamości: og:title, pierwszy <h1> i <title>, dosłownie, bez pustych.
     *
     * @return list<string>
     */
    public function pageTitles(string $html): array
    {
        $titles = [];
        foreach ([$this->extractOgTitle($html), $this->extractFirstHeading($html), $this->extractDocumentTitle($html)] as $title) {
            $title = trim((string) preg_replace('/\s+/u', ' ', $title));
            if ($title !== '' && ! in_array($title, $titles, true)) {
                $titles[] = $title;
            }
        }

        return $titles;
    }

    /**
     * Kody produktu z mikrodanych (itemprop sku/mpn) i z klasy Magento — dosłownie, jak markupSkus.
     *
     * @return list<string>
     */
    public function markupProductCodes(string $html): array
    {
        return $this->markupSkus($html);
    }

    /** Czy mikrodane strony mówią, że to inny wyrób niż ten kod (markupSkuNamesOtherProduct). */
    public function markupNamesOtherProduct(string $html, string $code): bool
    {
        return $this->markupSkuNamesOtherProduct($html, $code);
    }

    /**
     * Identyfikatory GŁÓWNEGO wyrobu strony, z rodzajem — do werdyktu tożsamości (SourceIdentity); bramki pobierania
     * biorą dalej markupSkus. Dosłownie, bez sprowadzania zapisu; gtin8/12/13/14 → gtin.
     *
     * - mikrodane: itemprop sku/mpn/gtin* pierwszego węzła itemscope Product/ProductGroup bez nadrzędnego wyrobu,
     *   z jego offers i hasVariant (<meta content=…> i atrybut content też); węzły wyrobów w nim zagnieżdżone
     *   (isRelatedTo, isSimilarTo, isAccessoryOrSparePartFor) i kafelki „podobne / klienci kupili” obok — nie;
     *   itemprop poza każdym itemscope tylko wtedy, gdy na stronie jest jedna taka wartość danego rodzaju;
     * - klasa body Magento catalog_product_view_sku_* (to zawsze wyrób strony);
     * - JSON-LD: pierwszy węzeł Product/ProductGroup (kolejność na stronie), jego offers i warianty hasVariant (karta
     *   rodziny producenta podaje kody rozmiarów i kolorów tylko tam, także jako odnośniki @id) oraz węzły Product
     *   z isVariantOf wskazującym na niego; pola wyrobów powiązanych nie są czytane;
     * - data-part (tabela części karty rodziny coba.com — tekst strony ucina tabelę) jako rodzaj „part”: przycisk
     *   z numerem części bywa też w karuzelach sklepów, więc SourceIdentity liczy go tylko na hoście producenta.
     *
     * @return list<array{type: 'sku'|'mpn'|'gtin'|'part', value: string}>
     */
    public function markupIdentifiers(string $html): array
    {
        $out = [];
        $add = static function (string $type, mixed $value) use (&$out): void {
            if (! is_string($value) && ! is_int($value)) {
                return;
            }
            $value = trim(html_entity_decode((string) $value, ENT_QUOTES | ENT_HTML5, 'UTF-8'));
            $type = str_starts_with(mb_strtolower($type), 'gtin') ? 'gtin' : mb_strtolower($type);
            $limit = $type === 'og_image' ? 2000 : 60;
            if ($value === '' || mb_strlen($value) > $limit || ! in_array($type, ['sku', 'mpn', 'gtin', 'part', 'og_image'], true)) {
                return;
            }
            $out[$type.'|'.$value] = ['type' => $type, 'value' => $value];
        };

        foreach ($this->mainMicrodataCodes($html) as [$type, $value]) {
            $add($type, $value);
        }
        if (preg_match('#\bcatalog_product_view_sku_([A-Za-z0-9][A-Za-z0-9._\-]{2,40})\b#', $html, $m)) {
            $add('sku', $m[1]);
        }
        if (preg_match_all('#\bdata-part=["\']([A-Za-z0-9][A-Za-z0-9._\-]{2,40})["\']#', $html, $parts)) {
            foreach ($parts[1] as $part) {
                $add('part', $part);
            }
        }
        foreach ($this->mainJsonLdCodes($html) as [$type, $value]) {
            $add($type, $value);
        }
        // główne zdjęcie strony — Cederroth podaje numer artykułu tylko w nazwie pliku (SourceIdentity::ogImageCarries)
        if (preg_match('#<meta\s[^>]*property=["\']og:image["\'][^>]*content=["\']([^"\']+)["\']#i', $html, $og)
            || preg_match('#<meta\s[^>]*content=["\']([^"\']+)["\'][^>]*property=["\']og:image["\']#i', $html, $og)) {
            $add('og_image', $og[1]);
        }

        return array_values($out);
    }

    /**
     * Kody z mikrodanych głównego wyrobu (markupIdentifiers).
     *
     * @return list<array{0: string, 1: string}>
     */
    private function mainMicrodataCodes(string $html): array
    {
        if (preg_match('/\bitemprop\s*=\s*["\']?[^"\'>]*\b(?:sku|mpn|gtin)/i', $html) !== 1) {
            return [];
        }
        $dom = new \DOMDocument;
        $previous = libxml_use_internal_errors(true);
        try {
            $loaded = $dom->loadHTML('<?xml encoding="UTF-8">'.HtmlBareLessThan::escape($html), LIBXML_NONET | LIBXML_COMPACT | LIBXML_NOERROR | LIBXML_NOWARNING);
        } finally {
            libxml_clear_errors();
            libxml_use_internal_errors($previous);
        }
        if (! $loaded) {
            return [];
        }
        $xpath = new \DOMXPath($dom);
        $tokens = static fn (\DOMElement $el, string $attr): array => preg_split('/\s+/', mb_strtolower(trim($el->getAttribute($attr)))) ?: [];
        $isProduct = static function (\DOMElement $el): bool {
            return preg_match('#schema\.org/(?:Product|ProductGroup|ProductModel|IndividualProduct|SomeProducts)\b#i', $el->getAttribute('itemtype')) === 1;
        };
        $scopeOf = static function (\DOMNode $node): ?\DOMElement {
            for ($n = $node->parentNode; $n instanceof \DOMElement; $n = $n->parentNode) {
                if ($n->hasAttribute('itemscope')) {
                    return $n;
                }
            }

            return null;
        };

        $main = null;
        foreach ($xpath->query('//*[@itemscope][@itemtype]') ?: [] as $el) {
            if (! $el instanceof \DOMElement || ! $isProduct($el)) {
                continue;
            }
            $top = true;
            for ($s = $scopeOf($el); $s !== null; $s = $scopeOf($s)) {
                if ($isProduct($s)) {
                    $top = false;
                    break;
                }
            }
            if ($top) {
                $main = $el;
                break;
            }
        }
        // Właściwość należy do wyrobu, gdy najbliższy itemscope to on albo łańcuch offers / hasVariant do niego.
        $belongsToMain = static function (?\DOMElement $scope) use ($main, $scopeOf, $tokens): bool {
            while ($main !== null && $scope !== null) {
                if ($scope->isSameNode($main)) {
                    return true;
                }
                if (array_intersect($tokens($scope, 'itemprop'), ['offers', 'hasvariant']) === []) {
                    return false;
                }
                $scope = $scopeOf($scope);
            }

            return false;
        };

        $codes = [];
        $orphans = [];
        foreach ($xpath->query('//*[@itemprop]') ?: [] as $el) {
            if (! $el instanceof \DOMElement) {
                continue;
            }
            $names = array_values(array_filter($tokens($el, 'itemprop'), static fn (string $t): bool => preg_match('/^(?:sku|mpn|gtin(?:8|12|13|14)?)$/', $t) === 1));
            if ($names === []) {
                continue;
            }
            $value = $el->hasAttribute('content') ? $el->getAttribute('content') : $el->textContent;
            $value = trim((string) preg_replace('/\s+/u', ' ', $value));
            if (mb_strlen($value) < 3 || mb_strlen($value) > 60) {
                continue;
            }
            $scope = $scopeOf($el);
            foreach ($names as $name) {
                if ($scope === null) {
                    $orphans[$name][$value] = true;
                } elseif ($belongsToMain($scope)) {
                    $codes[] = [$name, $value];
                }
            }
        }
        foreach ($orphans as $name => $values) {
            if (count($values) === 1) {
                $codes[] = [$name, (string) array_key_first($values)];
            }
        }

        return $codes;
    }

    /**
     * Kody z JSON-LD głównego wyrobu (markupIdentifiers).
     *
     * @return list<array{0: string, 1: mixed}>
     */
    private function mainJsonLdCodes(string $html): array
    {
        $list = static fn (mixed $v): array => is_array($v) ? (array_is_list($v) ? $v : [$v]) : [];
        $codes = [];
        foreach ($this->mainJsonLdProductNodes($html) as $item) {
            $offers = [];
            foreach ($list($item['offers'] ?? null) as $offer) {
                // AggregateOffer → offers → Offer
                $offers = [...$offers, $offer, ...(is_array($offer) ? $list($offer['offers'] ?? null) : [])];
            }
            foreach ([$item, ...$offers] as $holder) {
                if (! is_array($holder)) {
                    continue;
                }
                foreach (['sku', 'mpn', 'gtin', 'gtin8', 'gtin12', 'gtin13', 'gtin14'] as $key) {
                    $codes[] = [$key, $holder[$key] ?? null];
                }
            }
        }

        return $codes;
    }

    /**
     * Węzły JSON-LD głównego wyrobu strony: pierwszy Product/ProductGroup (kolejność na stronie), jego warianty
     * hasVariant (także odnośniki @id do @graph) i węzły Product z isVariantOf wskazującym na niego.
     *
     * @return list<array<string, mixed>>
     */
    private function mainJsonLdProductNodes(string $html): array
    {
        if (! preg_match_all('#<script[^>]+type=["\']application/ld\+json["\'][^>]*>(.*?)</script>#is', $html, $blocks)) {
            return [];
        }
        $isProduct = static function (mixed $node): bool {
            if (! is_array($node)) {
                return false;
            }
            $type = $node['@type'] ?? '';

            return array_intersect(is_array($type) ? $type : [$type], ['Product', 'ProductGroup']) !== [];
        };
        $products = [];
        $byId = [];
        foreach ($blocks[1] as $json) {
            $data = json_decode(trim((string) $json), true);
            if (! is_array($data)) {
                continue;
            }
            $nodes = isset($data['@graph']) && is_array($data['@graph']) ? $data['@graph'] : (array_is_list($data) ? $data : [$data]);
            foreach ($nodes as $node) {
                if (is_array($node) && is_string($node['@id'] ?? null) && $node['@id'] !== '') {
                    $byId[$node['@id']] = $node;
                }
                if ($isProduct($node)) {
                    $products[] = $node;
                }
            }
        }
        if ($products === []) {
            return [];
        }
        $list = static fn (mixed $v): array => is_array($v) ? (array_is_list($v) ? $v : [$v]) : [];
        $main = $products[0];
        $mainRefs = array_values(array_filter([
            is_string($main['@id'] ?? null) ? $main['@id'] : null,
            is_scalar($main['productGroupID'] ?? null) ? (string) $main['productGroupID'] : null,
        ], static fn (?string $s): bool => $s !== null && $s !== ''));

        $items = [$main];
        foreach ($list($main['hasVariant'] ?? null) as $variant) {
            // wariant jako odnośnik {"@id": …} do węzła w @graph
            if (is_array($variant) && count($variant) === 1 && is_string($variant['@id'] ?? null)) {
                $variant = $byId[$variant['@id']] ?? $variant;
            }
            $items[] = $variant;
        }
        foreach (array_slice($products, 1) as $node) {
            foreach ($list($node['isVariantOf'] ?? null) as $parent) {
                $ref = is_array($parent) ? ($parent['@id'] ?? $parent['productGroupID'] ?? null) : $parent;
                if (is_string($ref) && in_array($ref, $mainRefs, true)) {
                    $items[] = $node;
                    break;
                }
            }
        }

        return array_values(array_filter($items, 'is_array'));
    }

    /**
     * Włącza dziennik stron przebiegu od zera (runLog) — także gdy poprzedni przebieg nie doszedł do stopRunLog
     * (wyjątek przed blokiem try w serwisie): strony poprzedniej karty nie przechodzą do następnej.
     */
    public function startRunLog(): void
    {
        $this->runLog = [];
    }

    /**
     * Strony oddane przez fetch() od startRunLog, w kolejności pobrania (ponowne pobranie tego samego adresu
     * zastępuje wpis i przesuwa go na koniec), najwyżej RUN_LOG_MAX_PAGES — ponad limit wypadają najkrótsze.
     *
     * @return list<array<string, mixed>>
     */
    public function runLog(): array
    {
        return array_values($this->runLog ?? []);
    }

    public function stopRunLog(): void
    {
        $this->runLog = null;
    }

    /** @param  array<string, mixed>  $page */
    private function logRunPage(array $page): void
    {
        $url = (string) ($page['url'] ?? '');
        if ($url === '' || $this->runLog === null) {
            return;
        }
        $key = Product::normalizeShopUrl($url);
        unset($this->runLog[$key]);
        $this->runLog[$key] = $page;
        while (count($this->runLog) > self::RUN_LOG_MAX_PAGES) {
            $shortestKey = null;
            $shortest = PHP_INT_MAX;
            foreach ($this->runLog as $logKey => $logged) {
                $length = mb_strlen((string) ($logged['text'] ?? ''));
                if ($length < $shortest) {
                    [$shortestKey, $shortest] = [$logKey, $length];
                }
            }
            unset($this->runLog[$shortestKey]);
        }
    }

    /**
     * Tekst strony bez bloków innych wyrobów („Klienci kupili”, „Podobne produkty”), menu, nagłówka, stopki
     * i formularzy — do szukania kodu wyrobu w treści. Bez odsiewania akapitów po długości: kod w krótkim wierszu
     * („Kod: 11-800”) ma zostać.
     */
    public function mainTextFromHtml(string $html): string
    {
        return $this->htmlToText($this->stripShopChromeHtml($this->withoutRelatedProductHtml($html)));
    }

    /** Odpowiedź wygląda na dokument HTML, nie na PDF, obrazek czy JSON podany pod adresem karty. */
    private function looksLikeHtmlPage(string $body): bool
    {
        if (self::looksLikeBinaryMedia($body)) {
            return false;
        }

        return preg_match('#<(?:!doctype\s+html|html|head|body)\b#i', substr($body, 0, 20000)) === 1;
    }

    /**
     * Sklepy w całości rysowane skryptem (Salesforce Commerce) — HTML bez treści karty.
     * Karta producenta pod www.ansell.com zostaje; odpada wyłącznie sklepowa skorupa.
     */
    public static function looksLikeScriptOnlyShopHost(string $url): bool
    {
        $host = mb_strtolower((string) (parse_url($url, PHP_URL_HOST) ?? ''));
        if ($host === '') {
            return false;
        }
        foreach (['shop.ansell.com'] as $skip) {
            if ($host === $skip || str_ends_with($host, '.'.$skip)) {
                return true;
            }
        }

        return false;
    }

    private function fetchInner(array $results, string $sku, int $maxPages, array $manufacturerDomains): array
    {
        $wanted = max(1, $maxPages);
        $ranked = $this->rankResults($results, $sku);
        $skuNorm = mb_strtolower(trim($sku));
        $documents = [];
        foreach ($results as $row) {
            $u = (string) ($row['url'] ?? '');
            // PDF wprost z wyszukiwarki nie ma strony, która wiąże go z wyrobem — tylko z kodem w adresie, tytule albo opisie
            if (ProductDocumentDownloader::looksLikeDocumentUrl($u)
                && $this->documentNamesProduct($u, ($row['title'] ?? '').' '.($row['snippet'] ?? ''))) {
                $documents[] = $u;
            }
        }

        $htmlRows = [];
        $images = [];
        $trustedImages = [];
        foreach ($ranked as $row) {
            $u = (string) ($row['url'] ?? '');
            if (ProductImageDownloader::looksLikeImageUrl($u)) {
                if (ImageUrlBlocklist::blocked($u, $this->matchingProfile) === null) {
                    $images[] = $u;
                }

                continue;
            }
            // Sklep rysowany skryptem oddaje samą skorupę („Sorry to interrupt / CSS Error”)
            // zamiast karty. Zajmowała miejsce w limicie pobrań, a właściwy sklep bywał dopiero
            // za nią — i trafiała do opisu jako treść strony.
            if (self::looksLikeScriptOnlyShopHost($u)) {
                $this->rejections[] = ['url' => $u, 'reason' => CandidateRejection::SCRIPT_SHELL];

                continue;
            }
            if (! ProductDocumentDownloader::looksLikePdfUrl($u)) {
                $htmlRows[] = $row;
            }
        }
        $htmlRows = array_slice($htmlRows, 0, min(count($htmlRows), $wanted * 3));

        $goodPages = [];
        $fallbackPages = [];

        for ($offset = 0; $offset < count($htmlRows) && count($goodPages) < $wanted; $offset += $wanted) {
            $wave = array_values(array_slice($htmlRows, $offset, $wanted));
            $responses = $this->fetchWave($wave);
            foreach ($wave as $i => $row) {
                $this->ingestFetchedRow(
                    $row,
                    $responses[(string) $i] ?? null,
                    $skuNorm,
                    $manufacturerDomains,
                    $goodPages,
                    $fallbackPages,
                    $images,
                    $trustedImages,
                    $documents
                );
            }
        }

        $pages = $goodPages !== []
            ? $this->bestPages($goodPages, $skuNorm, $wanted)
            : array_slice($fallbackPages, 0, $wanted);

        $documents = array_values(array_unique($documents));

        return [
            'pages' => $pages,
            'image_urls' => array_values(array_unique($images)),
            'trusted_image_urls' => array_values(array_unique($trustedImages)),
            'document_urls' => $documents,
            // klucz dodatkowy — starsi odbiorcy biorą sam „document_urls” i go nie widzą
            'document_labels' => array_intersect_key($this->documentLabels, array_flip($documents)),
        ];
    }

    /**
     * @param  list<array{url: string, title?: string, snippet?: string}>  $htmlRows
     * @return array<string, mixed>
     */
    private function fetchWave(array $htmlRows): array
    {
        $responses = [];
        $pending = [];
        foreach ($htmlRows as $i => $row) {
            $cached = $this->cachedHtml((string) ($row['url'] ?? ''));
            if ($cached !== null) {
                $responses[(string) $i] = $this->htmlResponse($cached, $this->cachedFinalUrl((string) ($row['url'] ?? '')));

                continue;
            }
            $pending[$i] = $row;
        }
        if ($pending === []) {
            return $responses;
        }

        // jeden CookieJar na adres przez wszystkie przekierowania (jak dotąd w allow_redirects Guzzle)
        $jars = [];
        try {
            $live = $this->urls->pool(
                array_map(static fn (array $row): string => (string) ($row['url'] ?? ''), $pending),
                static function (PendingRequest $request, int|string $i) use (&$jars): PendingRequest {
                    return $request
                        ->timeout(20)
                        ->connectTimeout(4)
                        ->withHeaders([
                            'User-Agent' => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) '
                                .'AppleWebKit/537.36 (KHTML, like Gecko) '
                                .'Chrome/128.0.0.0 Safari/537.36',
                            'Accept' => 'text/html,application/xhtml+xml',
                        ])
                        ->withOptions(['cookies' => $jars[$i] ??= new CookieJar]);
                },
                PublicUrlFetcher::PAGE_MAX_BYTES,
            );
        } catch (Throwable $e) {
            Log::info('Product page fetch pool failed', [
                'urls' => array_slice(array_column($htmlRows, 'url'), 0, 3),
                'error' => $e->getMessage(),
            ]);

            return $responses;
        }

        foreach ($live as $key => $response) {
            $responses[(string) $key] = $response;
            $url = (string) ($pending[(int) $key]['url'] ?? '');
            if ($url === '' || ! $response instanceof Response || ! $response->successful()) {
                continue;
            }
            $html = $response->body();
            if ($html !== '' && ! $this->looksLikeBotWall($html)) {
                $this->storeHtml($url, $html);
                $this->storeFinalUrl($url, $response);
            }
        }

        return $responses;
    }

    private function cachedHtml(string $url): ?string
    {
        if ($this->bypassCache || $url === '') {
            return null;
        }
        $cached = Cache::get($this->htmlCacheKey($url));

        return is_string($cached) && $cached !== '' ? $cached : null;
    }

    private function storeHtml(string $url, string $html): void
    {
        if ($url === '' || strlen($html) > self::MAX_CACHED_HTML_BYTES) {
            return;
        }
        Cache::put($this->htmlCacheKey($url), $html, now()->addHours(self::CACHE_TTL_HOURS));
    }

    /**
     * Przekierowanie zapamiętane razem z HTML — inaczej kategoria, na którą sklep odsyła wycofany wyrób, z pamięci
     * wracałaby jako strona pod adresem karty z wyników i omijała sprawdzenie adresu docelowego.
     */
    private function storeFinalUrl(string $url, Response $response): void
    {
        $final = $response->effectiveUri();
        if ($url === '' || $final === null || ! $this->isRedirected($url, (string) $final)) {
            Cache::forget($this->finalUrlCacheKey($url));

            return;
        }
        Cache::put($this->finalUrlCacheKey($url), (string) $final, now()->addHours(self::CACHE_TTL_HOURS));
    }

    private function cachedFinalUrl(string $url): ?string
    {
        if ($url === '') {
            return null;
        }
        $final = Cache::get($this->finalUrlCacheKey($url));

        return is_string($final) && $final !== '' ? $final : null;
    }

    private function finalUrlCacheKey(string $url): string
    {
        return self::FINAL_URL_CACHE_PREFIX.hash('sha256', $url);
    }

    private function htmlCacheKey(string $url): string
    {
        return self::HTML_CACHE_PREFIX.hash('sha256', $url);
    }

    private function readerCacheKey(string $url): string
    {
        return self::READER_CACHE_PREFIX.hash('sha256', $url);
    }

    private function htmlResponse(string $html, ?string $finalUrl = null): Response
    {
        $psr = new Psr7Response(200, [
            'Content-Type' => 'text/html; charset=UTF-8',
        ], $html);
        $response = new Response($psr);
        if ($finalUrl !== null) {
            // effectiveUri() jak po pobraniu z sieci — adres docelowy przekierowania
            $response->transferStats = new TransferStats(new Psr7Request('GET', $finalUrl), $psr);
        }

        return $response;
    }

    /** Kody, którymi WAF-y odsyłają boty, zanim w ogóle dojdzie do treści karty. */
    private function isBlockedStatus(?int $status): bool
    {
        return $status !== null && in_array($status, [401, 403, 405, 429, 451, 503], true);
    }

    /** 3M/3mpolska: timeout (status null) — Akamai zrywa TLS, nie oddaje 403. */
    private function officialThreeMFetchNeedsReader(string $url, ?int $status): bool
    {
        return $status === null && $this->identity->isOfficialThreeMProductUrl($url);
    }

    /**
     * @param  list<array{url: string, text: string}>  $goodPages
     * @param  list<string>  $images
     * @param  list<string>  $trustedImages
     * @param  list<string>  $documents
     */
    private function ingestViaReader(
        string $url,
        array &$goodPages,
        array &$images,
        array &$documents,
        array &$trustedImages,
    ): bool {
        $viaReader = null;
        if (! $this->bypassCache) {
            $cached = Cache::get($this->readerCacheKey($url));
            // Wpis sprzed odczytu ramki norm (bez klucza norm_facts) jest nieaktualny: strona Ansella z pamięci
            // dawałaby przez 24 h opis bez norm producenta — czytamy ją jeszcze raz.
            if (is_array($cached) && isset($cached['text']) && array_key_exists('norm_facts', $cached)) {
                $viaReader = [
                    'text' => (string) ($cached['text'] ?? ''),
                    'image_urls' => is_array($cached['image_urls'] ?? null) ? $cached['image_urls'] : [],
                    'document_urls' => is_array($cached['document_urls'] ?? null) ? $cached['document_urls'] : [],
                    'document_labels' => is_array($cached['document_labels'] ?? null) ? $cached['document_labels'] : [],
                    'norm_facts' => is_array($cached['norm_facts'] ?? null) ? $cached['norm_facts'] : [],
                ];
            }
        }
        if ($viaReader === null) {
            $viaReader = $this->blockedPages->fetch($url);
            if ($viaReader === null) {
                return false;
            }
            Cache::put($this->readerCacheKey($url), $viaReader, now()->addHours(self::CACHE_TTL_HOURS));
        }

        $used = false;
        // null — czytnik nie dał tekstu, zostają same zdjęcia jak dotąd
        $confirmed = null;
        // Strona z czytnika musi nieść własne zdjęcia tak samo jak pobrana zwykłym HTML-em.
        // Bez tego zostawała tylko pula zbiorcza, a ta filtrowana jest po hoście — więc
        // packshoty z serwera mediów producenta (multimedia.3m.com) przepadały co do jednego.
        $readerPage = null;
        $pageImages = [];
        $pageTrusted = [];
        if ($viaReader['text'] !== '') {
            $skuNorm = mb_strtolower(trim((string) ($this->matchingProduct?->sku ?? '')));
            $text = $this->cleanFetchedPageText($this->withoutForeignReaderSections($viaReader['text']), $skuNorm);
            $confirmed = $text !== ''
                && ($this->matchingProduct === null || $this->pageConfirmsMatchingProduct($url, '', $text));
            if ($text !== '' && ! $confirmed) {
                // strona przeczytana, ale innego wariantu — nie zajmuje miejsca właściwej karty
                $this->rejections[] = $this->unconfirmedRejection($url, '', $text);
                $used = true;
            } elseif ($text !== '') {
                $optionSizes = (new ProductSizeVariant)->parseShopOptionSizes($text);
                if ($optionSizes !== []) {
                    $text = trim('Dostępne rozmiary: '.implode(', ', $optionSizes)."\n\n".$text);
                }
                // czytnik oddaje tekst, nie HTML — bez mikrodanych i bez adresu po przekierowaniu
                $page = ['url' => $url, 'final_url' => $url, 'text' => $text, 'markup_codes' => []];
                if ($optionSizes !== []) {
                    $page['option_sizes'] = $optionSizes;
                }
                // Ramka norm z pełnego markdownu (BlockedPageReader::normFacts) — tekst strony jej już nie niesie.
                // Za normy producenta bierze ją ProductEnrichmentService tylko ze strony producenta, jak przy HTML.
                if (($viaReader['norm_facts'] ?? []) !== []) {
                    $page['norm_facts'] = $viaReader['norm_facts'];
                }
                $accessories = (new ProductAccessoryExtractor)->fromText($text);
                if ($accessories !== []) {
                    $page['accessories'] = $accessories;
                }
                $readerPage = $page;
                $used = true;
            }
        }
        foreach ($confirmed === false ? [] : $viaReader['image_urls'] as $img) {
            if (! is_string($img) || $img === '' || ! $this->imageAllowedForProduct($img)
                || ImageUrlBlocklist::blocked($img, $this->matchingProfile) !== null) {
                continue;
            }
            $img = ProductImageDownloader::preferFullSizeUrl($img);
            $images[] = $img;
            $pageImages[] = $img;
            if ($this->matchingProduct !== null
                && $this->identity->isTrustedPageImageUrl($img, $this->matchingProduct)) {
                $trustedImages[] = $img;
                $pageTrusted[] = $img;
            }
            $used = true;
        }
        if ($readerPage !== null) {
            $readerPage['image_urls'] = array_values(array_unique($pageImages));
            $readerPage['trusted_image_urls'] = array_values(array_unique($pageTrusted));
            $readerPage['document_urls'] = array_values(array_filter($viaReader['document_urls'], 'is_string'));
            $goodPages[] = $readerPage;
        }
        foreach ($viaReader['document_urls'] as $doc) {
            $this->rememberDocumentLabel((string) $doc, (string) (($viaReader['document_labels'] ?? [])[$doc] ?? ''));
            // strona z czytnika niepotwierdzona albo bez tekstu — tylko pliki z kodem wyrobu (jak w zwykłym pobraniu)
            if ($confirmed === true || $this->documentNamesProduct((string) $doc)) {
                $documents[] = $doc;
            }
        }

        return $used;
    }

    /**
     * Ten sam próg co końcowe potwierdzenie karty. Przy SKU z wariantem („ARYA 300 673560 S1 P”)
     * karta innego wariantu tego modelu nie może zająć miejsca i zatrzymać pobierania kolejnych.
     *
     * @param  string|null  $finalUrl  adres po przekierowaniu, gdy inny niż z zapytania — tożsamość liczona na nim
     *                                 (sklep odsyła wycofany wyrób na kategorię, a adres z wyników niesie nasz kod);
     *                                 adres wskazany ręcznie zostaje zaufany pod adresem z zapytania
     */
    private function pageConfirmsMatchingProduct(string $url, string $title, string $text, ?string $finalUrl = null): bool
    {
        $product = $this->matchingProduct;
        if ($product === null) {
            return false;
        }
        if ($product->isTrustedShopUrl($url)) {
            return true;
        }
        $pageUrl = $finalUrl ?? $url;
        if ($this->identity->pageHasSkuOrNameAndManufacturer($pageUrl, $title, $text, $product)) {
            return true;
        }

        return ! $this->identity->requiresExactSkuOrNameOnCard($product)
            && $this->identity->isConfirmedProductCard($pageUrl, $title, $text, $product);
    }

    /**
     * Adres docelowy przekierowania albo null, gdy strona została pod adresem z zapytania (porównanie bez schematu,
     * „www.”, końcowego „/” i kotwicy — http→https czy dopisany ukośnik to nie przekierowanie na inną stronę).
     */
    private function redirectedTo(string $url, ?Response $response): ?string
    {
        $final = $response?->effectiveUri();

        return $final !== null && $this->isRedirected($url, (string) $final) ? (string) $final : null;
    }

    private function isRedirected(string $url, string $final): bool
    {
        $key = static fn (string $u): string => (string) preg_replace('/^www\./u', '', Product::normalizeShopUrl($u));

        return $final !== '' && $key($final) !== $key($url);
    }

    /**
     * Przekierowanie skończyło się na liście, nie na karcie: kategoria, wyniki wyszukiwania, strona producenta marki,
     * strona główna albo „brak produktu” (IdoSell: /pol_m_Okulary-ochronne-Bolle-7342.html, /noproduct.php). Adres
     * z naszym kodem (Shopify /collections/…/products/…) listą nie jest — o nim rozstrzyga potwierdzenie karty.
     */
    private function redirectLandsOnListing(string $final): bool
    {
        if ($this->identity->looksLikeNonProductCardUrl($final)) {
            return true;
        }
        if ($this->matchingProduct !== null && $this->identity->hayHasProductCode(mb_strtolower(urldecode($final)), $this->matchingProduct)) {
            return false;
        }
        $path = mb_strtolower(rawurldecode((string) (parse_url($final, PHP_URL_PATH) ?? '')));
        if ($path === '' || preg_match('#^/(?:[a-z]{2}(?:[-_][a-z]{2})?/?)?$#u', $path) === 1) {
            return true;
        }
        if (preg_match('#/(?:products?|produkt|produkty|p)/#u', $path) === 1) {
            return false;
        }

        return preg_match(
            '#(?:/[a-z]{3}_m_|/noproduct\.php|/search\.php|/c/|/(?:category|categories|kategoria|kategorie|kategorii|collections?|kolekcje?|'
            .'manufacturer|producent|producenci|brand|brands|marka|marki|szukaj|search|wyszukiwanie)(?:/|$|\.html?))#u',
            $path
        ) === 1;
    }

    /**
     * Wpis odrzucenia niepotwierdzonej strony — z rodzinami kodów, gdy tabela części producenta wymienia same cudze kody
     * (bez tego w przebiegu widać tylko „treść nie potwierdza produktu” i nie wiadomo, czemu karta poszła do ręki).
     *
     * @return array{url: string, reason: string, detail?: string}
     */
    private function unconfirmedRejection(string $url, string $title, string $text, ?string $finalUrl = null): array
    {
        $families = $this->matchingProduct !== null
            ? $this->identity->officialPageOtherCodeFamilies($finalUrl ?? $url, $title, $text, $this->matchingProduct)
            : [];

        return $families === []
            ? ['url' => $url, 'reason' => $this->unconfirmedReason()]
            : ['url' => $url, 'reason' => CandidateRejection::OTHER_CODE_FAMILY, 'detail' => mb_strtoupper(implode(', ', $families))];
    }

    private function unconfirmedReason(): string
    {
        return $this->matchingProduct !== null && $this->identity->requiresExactSkuOrNameOnCard($this->matchingProduct)
            ? CandidateRejection::UNCONFIRMED_STRICT
            : CandidateRejection::UNCONFIRMED;
    }

    /**
     * @param  array{url: string, title?: string, snippet?: string}  $row
     * @param  list<array{url: string, text: string}>  $goodPages
     * @param  list<array{url: string, text: string}>  $fallbackPages
     * @param  list<string>  $images
     * @param  list<string>  $trustedImages
     * @param  list<string>  $documents
     * @param  list<string>  $manufacturerDomains
     */
    private function ingestFetchedRow(
        array $row,
        mixed $response,
        string $skuNorm,
        array $manufacturerDomains,
        array &$goodPages,
        array &$fallbackPages,
        array &$images,
        array &$trustedImages,
        array &$documents,
    ): void {
        $url = (string) ($row['url'] ?? '');
        $ok = $response instanceof Response && $response->successful();

        // adres (albo przekierowanie) do sieci wewnętrznej — odmowa stała: bez czytnika i bez ponawiania
        if ($response instanceof BlockedUrlException) {
            $this->rejections[] = ['url' => $url, 'reason' => CandidateRejection::BLOCKED_HOST, 'detail' => BlockedUrlException::MESSAGE];

            return;
        }
        if (! $ok) {
            $status = $response instanceof Response ? $response->status() : null;
            // WAF sklepu (gloves.co.uk, rs-online) odrzuca IP serwerowni jeszcze przed treścią,
            // więc kartę czytamy przez zewnętrzny reader zamiast odpuszczać stronę.
            // 3mpolska.pl / 3m.com przy timeout (Akamai) też nie oddają statusu 403.
            // Reader ratuje kartę i przy 403 od WAF, i przy zerwanym TLS 3M (status null).
            // Ale tylko jawne 403/401/429… to blokada stała; timeout bez statusu zostaje
            // „nie odpowiedziała” i wraca do ponowienia.
            $walled = $this->isBlockedStatus($status);
            $readerTried = $walled || $this->officialThreeMFetchNeedsReader($url, $status);
            if ($readerTried && $this->ingestViaReader($url, $goodPages, $images, $documents, $trustedImages)) {
                return;
            }
            Log::info('Product page fetch skipped', ['url' => $url, 'status' => $status]);
            // 403 od WAF (hahn-kolb: Akamai) plus padnięty reader to blokada stała, nie
            // chwilowy brak odpowiedzi — „ponów później” nic tu nie da. Osobny powód,
            // żeby wyżej dało się odróżnić ją od timeoutu.
            // Zapora sklepu + limit readera (429) to nie blokada stała: reader za chwilę
            // przejdzie, więc karta wraca do ponowienia. BOT_WALL zostaje dla przypadku,
            // gdy sklep odmówił także readerowi — wtedy pomoże tylko człowiek.
            $permanentWall = $walled && ! $this->blockedPages->failureIsTransient($url);
            $this->rejections[] = array_filter([
                'url' => $url,
                'reason' => $permanentWall ? CandidateRejection::BOT_WALL : CandidateRejection::FETCH_FAILED,
                'detail' => match (true) {
                    $walled => $this->blockedPages->failureFor($url),
                    // karta 3M po zerwanym połączeniu szła przez czytnik — przebieg ma mówić, czemu i on padł
                    $readerTried => $this->blockedPages->failureFor($url) ?? 'brak odpowiedzi',
                    default => $status === null ? 'brak odpowiedzi' : 'status '.$status,
                },
            ]);
            $snippet = trim((string) ($row['snippet'] ?? ''));
            if ($snippet !== '') {
                $fallbackPages[] = ['url' => $url, 'text' => mb_substr($snippet, 0, 3000), 'snippet_only' => true];
            }

            return;
        }

        $html = $response->body();
        $contentType = strtolower((string) $response->header('Content-Type'));
        if (str_contains($contentType, 'image/') || str_contains($contentType, 'application/pdf')
            || self::looksLikeBinaryMedia($html)) {
            if (str_contains($contentType, 'pdf') || str_starts_with(ltrim($html), '%PDF')) {
                // Karta składana na żądanie podana wprost przez wyszukiwarkę nie ma strony, która wiąże ją
                // z wyrobem (adres bez kodu) — przyjmujemy ją tylko jako link z potwierdzonej karty producenta.
                if (! ProductDocumentDownloader::looksLikeGeneratedCardUrl($url)
                    && $this->documentNamesProduct($url, ($row['title'] ?? '').' '.($row['snippet'] ?? ''))) {
                    $documents[] = $url;
                }
            } else {
                $images[] = $url;
            }

            return;
        }
        if ($this->looksLikeBotWall($html)) {
            if (! $this->ingestViaReader($url, $goodPages, $images, $documents, $trustedImages)) {
                $this->rejections[] = array_filter([
                    'url' => $url,
                    'reason' => $this->blockedPages->failureIsTransient($url)
                        ? CandidateRejection::FETCH_FAILED
                        : CandidateRejection::BOT_WALL,
                    'detail' => $this->blockedPages->failureFor($url),
                ]);
                $snippet = trim((string) ($row['snippet'] ?? ''));
                if ($snippet !== '') {
                    $fallbackPages[] = ['url' => $url, 'text' => mb_substr($snippet, 0, 3000), 'snippet_only' => true];
                }
            }

            return;
        }

        // Tekst, rozmiary, zdjęcia, pliki i ramka norm — ze strony bez bloków innych wyrobów i poradników; mikrodane
        // (markup_codes, w tym data-part tabeli części) i akcesoria — z pełnego HTML, jak dotąd.
        $mainHtml = $this->withoutRelatedProductHtml($html);
        $optionSizes = (new ProductSizeVariant)->parseShopOptionSizes($mainHtml);
        $identityText = $this->extractProductIdentityText($mainHtml);
        $text = $this->extractProductPageText($mainHtml, $skuNorm);
        if ($identityText !== '') {
            $text = trim($identityText.($text !== '' ? "\n\n".$text : ''));
        }
        if ($optionSizes !== []) {
            $text = trim('Dostępne rozmiary: '.implode(', ', $optionSizes)."\n\n".$text);
        }
        // Meta-opis (name="description", og:description) nie trafia do tekstu strony — to hasło SEO, nie treść karty
        // (coba.com/product/esd-senso-dial: „ESD approved, measured according to IEC 61340-4-5” tylko w meta, a karta
        // szarego Senso Dial dostała ESD; audyt Coby 08.10.2026). Bramki tożsamości poniżej widzą go jak dotąd.
        $seoText = $this->extractSeoDescriptionText($html);
        $gateText = $seoText === '' || $text === '' ? $text : $text."\n\n".$seoText;
        $hinted = $this->matchingProduct !== null && $this->matchingProduct->isTrustedShopUrl($url);
        // Sklep odsyła wycofany wyrób na kategorię (specshop SILEXPSF, RUSHPSPSIS — Bolle, 08.10.2026), a strona szła
        // dalej pod adresem karty z wyników: opis powstał z tekstu kategorii. Lista po przekierowaniu odpada od razu,
        // inna strona docelowa musi się potwierdzić pod własnym adresem. Adres wskazany ręcznie — bez zmian.
        $redirectedTo = $this->matchingProduct !== null && ! $hinted
            ? $this->redirectedTo($url, $response)
            : null;
        if ($redirectedTo !== null && $this->redirectLandsOnListing($redirectedTo)) {
            $this->rejections[] = ['url' => $url, 'reason' => CandidateRejection::LISTING, 'detail' => 'przekierowanie na listę: '.$redirectedTo];

            return;
        }
        // Tytuł z wyników opisuje adres z zapytania — po przekierowaniu na inną ścieżkę liczy się tytuł strony docelowej
        // (sama zmiana parametrów albo wielkości liter to ta sama strona).
        $pageKey = static fn (string $u): string => mb_strtolower((string) preg_replace(['/^www\./u', '/\?.*$/su'], '', Product::normalizeShopUrl($u)));
        $samePath = $redirectedTo === null || $pageKey($redirectedTo) === $pageKey($url);
        $title = $samePath ? (string) ($row['title'] ?? '') : '';
        if ($title === '' && $identityText !== '') {
            $title = strtok($identityText, "\n") ?: $identityText;
        }
        // Karta rodziny producenta (coba.com/pl/produkt/cobastat) wymienia w tabeli części
        // „AS060003C” (na metr bieżący) i osobno „AS060003” (rolka) — dokładny numer obok
        // dłuższego to nasz produkt, nie cudzy. Batch #293: 65 takich kart poszło do kosza.
        if (! $hinted && $this->hayHasLongerAlphanumericSkuVariant($url.' '.$title.' '.$gateText, $skuNorm)
            && ! $this->hayHasExactAlphanumericSku($title.' '.$gateText, $skuNorm)
            && ! ($this->matchingProduct !== null
                && $this->identity->officialFamilyPageListsSizeCodes($url, $title, $gateText, $this->matchingProduct))) {
            $this->rejections[] = ['url' => $url, 'reason' => CandidateRejection::LONGER_VARIANT];

            return;
        }
        if (! $hinted && $this->matchingProduct !== null
            && $this->identity->isOfficialCatalogUrl($url, $this->matchingProduct)
            && $this->markupSkuNamesOtherProduct($html, (string) $this->matchingProduct->sku)) {
            $this->rejections[] = ['url' => $url, 'reason' => CandidateRejection::CLAIMS_OTHER_CODE];

            return;
        }
        $pageLooksLikeProduct = $this->matchingProduct !== null
            ? $this->pageConfirmsMatchingProduct($url, $title, $gateText, $redirectedTo)
            : ($this->pageMentionsSku($url, $gateText, $title, $skuNorm)
                || $this->pageMatchesProductIdentity($url, $gateText, $title));

        $pageImages = [];
        $pageTrusted = [];
        if ($text !== '' && ($this->matchingProduct === null || $pageLooksLikeProduct)) {
            foreach ($this->extractImageUrls($mainHtml, $url, $skuNorm) as $img) {
                if ($this->imageAllowedForProduct($img)) {
                    $pageImages[] = $img;
                    $images[] = $img;
                }
            }
            foreach ($this->extractStructuredImageUrls($mainHtml) as $img) {
                $absolute = $this->absolutize($img, $url);
                if ($absolute !== null) {
                    $absolute = ProductImageDownloader::preferFullSizeUrl($absolute);
                }
                if ($absolute !== null
                    && ! $this->isJunkImageUrl($absolute)
                    && ProductImageDownloader::looksLikeImageUrl($absolute)
                    && $this->imageAllowedForProduct($absolute)) {
                    $pageTrusted[] = $absolute;
                    $trustedImages[] = $absolute;
                }
            }
        }

        $fromManufacturer = $this->hostMatchesDomains($url, $manufacturerDomains);
        // Karta PDF składana na żądanie nie ma kodu w adresie — z wyrobem wiąże ją tylko strona, na której
        // stoi link: potwierdzona karta tego wyrobu u producenta. Pierwsze pobranie i karty z indeksu katalogu
        // nie podają domen producenta, stąd druga droga przez listę oficjalnych hostów.
        $bindsGeneratedCard = $this->matchingProduct !== null && $pageLooksLikeProduct && $text !== ''
            && ($fromManufacturer || $this->identity->isOfficialCatalogUrl($url, $this->matchingProduct));
        // pliki z kafelków innych wyrobów odpadają razem z kafelkami (mainHtml)
        $pageDocuments = $this->extractDocumentUrls($mainHtml, $url, $skuNorm, $fromManufacturer, $bindsGeneratedCard);
        // Strona innego wyrobu (albo bez treści) nie daje swoich plików jak zdjęć — zostają tylko pliki z kodem naszego
        // wyrobu w adresie albo opisie linku (27.09.2026: karty siostrzane coba.com dokładały cudze arkusze danych).
        $strict = $this->matchingProduct !== null && ($text === '' || ! $pageLooksLikeProduct);
        foreach ($pageDocuments as $doc) {
            if (! $strict || $this->documentNamesProduct($doc)) {
                $documents[] = $doc;
            }
        }

        if ($text !== '' && ($this->matchingProduct === null || $pageLooksLikeProduct)) {
            $finalUrl = $response->effectiveUri();
            $page = [
                'url' => $url,
                // adres po przekierowaniach (strona z pamięci podręcznej — adres z zapytania)
                'final_url' => $finalUrl !== null ? (string) $finalUrl : $url,
                'title' => $title,
                'text' => mb_substr($text, 0, 12000),
                'image_urls' => array_values(array_unique($pageImages)),
                'trusted_image_urls' => array_values(array_unique($pageTrusted)),
                // pliki tej strony — serwis bierze je tylko ze stron, z których powstał opis (jak zdjęcia)
                'document_urls' => $pageDocuments,
                // kody z mikrodanych do werdyktu tożsamości (SourceIdentity)
                'markup_codes' => $this->markupIdentifiers($html),
            ];
            if ($optionSizes !== []) {
                $page['option_sizes'] = $optionSizes;
            }
            // Pary z ramki norm — ProductEnrichmentService bierze je za normy producenta tylko ze strony producenta.
            $normFacts = $this->normFacts($mainHtml);
            if ($normFacts !== []) {
                $page['norm_facts'] = $normFacts;
            }
            $accessories = (new ProductAccessoryExtractor)->fromHtml($html);
            if ($accessories !== []) {
                $page['accessories'] = $accessories;
            }
            $goodPages[] = $page;
        } elseif ($this->matchingProduct !== null) {
            $this->rejections[] = $this->unconfirmedRejection($url, $title, $gateText, $redirectedTo);
        }
    }

    /**
     * Plik wiąże z wyrobem sam, bez strony, na której stoi: kod wyrobu w adresie albo opisie linku (reguła kodu
     * z granicami, nie podciąg — krótkie „202” nie trafia w „2020”). Bez karty do porównania — plik zostaje jak dotąd.
     */
    private function documentNamesProduct(string $doc, string $context = ''): bool
    {
        if ($this->matchingProduct === null) {
            return true;
        }
        // „pdf.php?id_product=103” to numer wpisu sklepu, nie kod wyrobu 103 (etap 3, §1.3)
        $hay = mb_strtolower(urldecode(ShopEntryId::strip($doc)).' '.($this->documentLabels[$doc] ?? '').' '.$context);

        return $this->identity->hayHasProductCode($hay, $this->matchingProduct);
    }

    /**
     * @param  list<array{url: string, text: string}>  $pages
     * @return list<array{url: string, text: string}>
     */
    private function bestPages(array $pages, string $skuNorm, int $wanted): array
    {
        usort($pages, function (array $a, array $b) use ($skuNorm): int {
            return $this->livePageScore($b, $skuNorm) <=> $this->livePageScore($a, $skuNorm);
        });

        return array_slice(array_values($pages), 0, $wanted);
    }

    /**
     * @param  array{url: string, text: string}  $page
     */
    private function livePageScore(array $page, string $skuNorm): int
    {
        $url = (string) ($page['url'] ?? '');
        $text = (string) ($page['text'] ?? '');
        $score = min(3000, mb_strlen($text));
        if ($this->matchingProduct !== null && $this->matchingProduct->isHintedShopUrl($url)) {
            $score += 8000;
        }
        if ($this->pageMentionsSku($url, $text, '', $skuNorm)) {
            $score += 4000;
        }

        return $score;
    }

    /**
     * @param  list<array{url: string, title?: string, snippet?: string}>  $results
     * @return list<array{url: string, title?: string, snippet?: string}>
     */
    private function rankResults(array $results, string $sku): array
    {
        $skuNorm = mb_strtolower(trim($sku));
        $skuCompact = preg_replace('/[^a-z0-9]/i', '', $skuNorm) ?? $skuNorm;
        $product = $this->matchingProduct;

        usort($results, function (array $a, array $b) use ($skuNorm, $skuCompact, $product): int {
            return $this->resultScore($b, $skuNorm, $skuCompact, $product)
                <=> $this->resultScore($a, $skuNorm, $skuCompact, $product);
        });

        return $results;
    }

    /**
     * @param  array{url: string, title?: string, snippet?: string}  $row
     */
    private function resultScore(array $row, string $skuNorm, string $skuCompact, ?Product $product): int
    {
        $score = self::score($row, $skuNorm, $skuCompact);
        if ($product !== null && $product->isHintedShopUrl((string) ($row['url'] ?? ''))) {
            $score += 10_000;
        }

        return $score;
    }

    /**
     * @param  array{url: string, title?: string, snippet?: string}  $row
     */
    private static function score(array $row, string $skuNorm, string $skuCompact): int
    {
        $url = mb_strtolower($row['url'] ?? '');
        $title = mb_strtolower((string) ($row['title'] ?? ''));
        $snippet = mb_strtolower((string) ($row['snippet'] ?? ''));
        $hay = $url.' '.$title.' '.$snippet;
        $score = 0;

        if ($skuNorm !== '' && str_contains($hay, $skuNorm)) {
            $score += 50;
        }
        $hayCompact = preg_replace('/[^a-z0-9]/i', '', $hay) ?? $hay;
        if ($skuCompact !== '' && str_contains($hayCompact, $skuCompact)) {
            $score += 30;
        }
        if (preg_match('/\b(\d{1,2}-\d{3})\b/', $skuNorm, $m)) {
            $core = $m[1];
            if (str_contains($url, $core) || str_contains($url, str_replace('-', '', $core))) {
                $score += 80;
            }
            if (str_contains($title, $core)) {
                $score += 30;
            }
        }
        if (str_contains($url, 'product') || str_contains($url, 'produkt') || str_contains($url, '/sklep/')) {
            $score += 10;
        }
        if (preg_match('#/(category|manufacturer|kategoria|producent|catalog)/#', $url)) {
            $score -= 50;
        }
        if (str_contains($url, 'atg') || str_contains($url, 'ansell') || str_contains($url, 'delta') || str_contains($url, 'demar')) {
            $score += 15;
        }
        if (str_contains($url, 'demar24') || str_contains($url, 'roboczystyl') || str_contains($url, 'gama-bhp') || str_contains($url, 'sklepzbhp')) {
            $score += 25;
        }
        if (str_contains($url, 'ceneo.pl') || str_contains($url, 'allegro.pl')) {
            $score -= 20;
        }
        if (str_contains($url, '/blogs/') || str_contains($url, '/blog/')) {
            $score -= 80;
        }
        if (preg_match('#ansell\.com/(?:cn|lac|hk|nz|apac|ap)/#', $url) === 1) {
            $score -= 40;
        }
        if (str_contains($url, 'ansell.com/pl/pl/products/')) {
            $score += 40;
        } elseif (str_contains($url, 'ansell.com/gb/en/products/')) {
            $score += 25;
        }

        return $score;
    }

    private function pageMatchesProductIdentity(string $url, string $text, string $title): bool
    {
        $product = $this->matchingProduct;
        if ($product === null) {
            return false;
        }

        return $this->identity->hayMentionsProduct($url.' '.$title.' '.$text, $product);
    }

    private function pageMentionsSku(string $url, string $text, string $title, string $skuNorm): bool
    {
        if ($skuNorm === '') {
            return false;
        }
        // „7-003 b 6060” → też „7-003 b” / „7-003b”
        $variants = [$skuNorm];
        if (preg_match('/^(\d{1,2}-\d{3}(?:\s+[a-z])?)/u', $skuNorm, $sm)) {
            $variants[] = $sm[1];
            $variants[] = str_replace(' ', '', $sm[1]);
        }
        $hay = mb_strtolower($url.' '.$title.' '.$text);
        $hayCompact = preg_replace('/[^a-z0-9]+/i', '', $hay) ?? $hay;
        if ($this->hayHasLongerAlphanumericSkuVariant($hay, $skuNorm)
            && ! $this->hayHasExactAlphanumericSku($title.' '.$text, $skuNorm)) {
            return false;
        }
        foreach ($variants as $variant) {
            if ($variant === '') {
                continue;
            }
            $compact = preg_replace('/[^a-z0-9]+/i', '', $variant) ?? $variant;
            if ($this->isAlphanumericSkuCode($compact)) {
                if (preg_match('/(?<![a-z0-9])'.preg_quote($variant, '/').'(?![a-z0-9])/iu', $hay) === 1) {
                    return true;
                }
                if ($compact !== '' && strlen($compact) >= 4
                    && preg_match('/(?<![a-z0-9])'.preg_quote($compact, '/').'(?![a-z0-9])/iu', $hay) === 1) {
                    return true;
                }

                continue;
            }
            if (str_contains($hay, $variant)) {
                return true;
            }
            if ($compact !== '' && strlen($compact) >= 4 && str_contains($hayCompact, $compact)) {
                return true;
            }
        }
        // PROS-101-S1-MAX ≈ URL …pros-101s-34… / tytuł „PROS 101/S”
        if ($this->pageMentionsBrandModelSku($hay, $hayCompact, $skuNorm)) {
            return true;
        }
        if (! preg_match('/\b(\d{1,2}-\d{3})\b/', $skuNorm, $m)) {
            // nazwa handlowa (CLIC UP, BOLT UP…) — tokeny w URL/tekście
            return $this->hayMentionsNameTokens($hay, $skuNorm);
        }
        $core = $m[1];
        if (! $this->containsArtCode($hay, $core)) {
            return false;
        }
        $parts = preg_split('/[\s\-·\/]+/u', $skuNorm) ?: [];
        $extras = [];
        foreach ($parts as $part) {
            $part = trim($part);
            if ($part === '' || $part === $core) {
                continue;
            }
            // litery/normy; wariant „B” (1 znak) też
            if (preg_match('/^[a-z][a-z0-9]*$/i', $part) === 1) {
                $extras[] = mb_strtolower($part);
            }
        }
        if ($extras === []) {
            return true;
        }
        $hayCompact = preg_replace('/[^a-z0-9]/i', '', $hay) ?? $hay;
        $hits = 0;
        foreach ($extras as $token) {
            if (str_contains($hay, $token) || str_contains($hayCompact, $token)) {
                $hits++;
            }
        }

        return $hits >= max(1, (int) ceil(count($extras) * 0.5));
    }

    private function looksLikeBotWall(string $html): bool
    {
        $trim = trim($html);
        if ($trim === '' || strlen($trim) < 800) {
            return true;
        }
        $hay = mb_strtolower($trim);

        return str_contains($hay, '_incapsula_resource')
            || str_contains($hay, 'incapsula')
            || str_contains($hay, 'imperva')
            || str_contains($hay, 'cf-browser-verification')
            || str_contains($hay, 'attention required! | cloudflare')
            || (str_contains($hay, 'captcha') && strlen($trim) < 4000);
    }

    /** JPEG/PNG/GIF/PDF wklejone jako „tekst strony” — llama-swap odpowiada 400. */
    public static function looksLikeBinaryMedia(string $body): bool
    {
        if ($body === '') {
            return false;
        }
        $head = substr($body, 0, 24);
        if (str_starts_with($head, "\xFF\xD8\xFF")
            || str_starts_with($head, "\x89PNG")
            || str_starts_with($head, 'GIF87a')
            || str_starts_with($head, 'GIF89a')
            || str_starts_with($head, '%PDF')
            || str_contains($head, 'JFIF')
            || str_contains($head, 'PNG')
            || str_contains($head, 'IHDR')) {
            return true;
        }
        $sample = substr($body, 0, 800);
        $controls = preg_match_all('/[\x00-\x08\x0B\x0C\x0E-\x1F]/', $sample);

        return is_int($controls) && $controls > 8;
    }

    /** Meta/og ucięte na „(Zobacz klasy …” zamiast pełnego opisu. */
    public static function looksLikeTruncatedShopTeaser(string $text): bool
    {
        $t = trim($text);
        if ($t === '') {
            return false;
        }
        if (preg_match('/\(\s*zobacz\b[^)]{0,80}$/iu', $t) === 1) {
            return true;
        }
        if (preg_match('/\b(zobacz|czytaj\s+(?:dalej|więcej)|rozwiń)\b.{0,80}(\.\.\.|…)\s*$/iu', $t) === 1) {
            return true;
        }

        return preg_match('/\bprzed\s*:\s*(?:\([^)]{0,80})?$/iu', $t) === 1;
    }

    public static function looksLikeCookieConsent(string $text): bool
    {
        $low = mb_strtolower($text);
        foreach ([
            'when you visit our website, we store cookies',
            'we store cookies on your browser',
            'strictly necessary cookies',
            'first party strictly necessary cookies',
            'california consumer privacy act',
            'sale of personal data',
            'you cannot opt-out of our first party',
            'these cookies collect information for analytics',
            'exercise my rights',
        ] as $needle) {
            if (str_contains($low, $needle)) {
                return true;
            }
        }

        return false;
    }

    public static function looksLikeCjkDump(string $text): bool
    {
        $han = preg_match_all('/\p{Han}/u', $text);

        return is_int($han) && $han >= 40;
    }

    public static function looksLikeRawLocaleDump(string $text): bool
    {
        if (self::looksLikeCookieConsent($text) || self::looksLikeCjkDump($text)) {
            return true;
        }
        $low = mb_strtolower($text);
        $hits = 0;
        foreach ([
            'nº artículo', 'descripción del producto', 'reducción de los riesgos',
            'cuartos limpios', '项目编号', '产品名称', '产品描述',
        ] as $needle) {
            if (str_contains($low, $needle) || str_contains($text, $needle)) {
                $hits++;
            }
        }

        return $hits >= 2;
    }

    public static function stripCookieConsentBlocks(string $text): string
    {
        $patterns = [
            '/When you visit our website, we store cookies.{0,8000}?(?=\n\n|\n# |\n\* \*\*(?!Your Privacy|Strictly Necessary|Sale of Personal)|\z)/isu',
            '/Under the California Consumer Privacy Act.{0,4000}?(?=\n\n|\n# |\z)/isu',
            '/These cookies (?:are|collect).{0,2500}?(?=\n\n|\n# |\z)/isu',
            '/\* \*\*Your Privacy\*\*.{0,2000}?(?=\n# |\z)/isu',
            '/\* \*\*Strictly Necessary Cookies\*\*.{0,2000}?(?=\n# |\z)/isu',
            '/\* \*\*Sale of Personal Data\*\*.{0,2000}?(?=\n# |\z)/isu',
        ];
        foreach ($patterns as $pattern) {
            $text = preg_replace($pattern, "\n\n", $text) ?? $text;
        }
        $text = preg_replace('/\n{3,}/u', "\n\n", $text) ?? $text;

        return trim($text);
    }

    /** Link „(Zobacz klasyfikację…)” / „czytaj dalej” — nie treść produktu. */
    public static function stripExpandLinkChrome(string $text): string
    {
        $text = preg_replace('/\(\s*zobacz\b[^)]{0,160}\)\s*/iu', '', $text) ?? $text;
        $text = preg_replace('/\(\s*zobacz\b[^)]{0,80}$/iu', '', $text) ?? $text;
        $text = preg_replace('/\b(czytaj\s+(?:dalej|więcej)|rozwiń)(?:\s*\.\.\.|\s*…)?\s*/iu', '', $text) ?? $text;
        $text = preg_replace('/[ \t]{2,}/u', ' ', $text) ?? $text;
        $text = preg_replace('/\n{3,}/u', "\n\n", $text) ?? $text;

        return trim($text);
    }

    /**
     * Tylko treść produktu — bez menu, koszyka, logowania, zwrotów, breadcrumbów.
     */
    private function extractOgTitle(string $html): string
    {
        if (preg_match('#property=["\']og:title["\'][^>]*content=["\']([^"\']+)["\']#i', $html, $m)
            || preg_match('#content=["\']([^"\']+)["\'][^>]*property=["\']og:title["\']#i', $html, $m)) {
            return trim(html_entity_decode($m[1], ENT_QUOTES | ENT_HTML5, 'UTF-8'));
        }

        return '';
    }

    private function extractDocumentTitle(string $html): string
    {
        if (preg_match('#<title[^>]*>(.*?)</title>#is', $html, $m)) {
            return trim(html_entity_decode(strip_tags(HtmlBareLessThan::escape($m[1])), ENT_QUOTES | ENT_HTML5, 'UTF-8'));
        }

        return '';
    }

    private function extractFirstHeading(string $html): string
    {
        if (preg_match('#<h1[^>]*>(.*?)</h1>#is', $html, $m)) {
            return trim(html_entity_decode(strip_tags(HtmlBareLessThan::escape($m[1])), ENT_QUOTES | ENT_HTML5, 'UTF-8'));
        }

        return '';
    }

    /** Tytuł / H1 / JSON-LD name — zostaje nawet gdy ciało karty to cennik rozmiarów. */
    private function extractProductIdentityText(string $html): string
    {
        $bits = [];
        foreach ([
            $this->extractOgTitle($html),
            $this->extractFirstHeading($html),
            $this->extractDocumentTitle($html),
        ] as $bit) {
            $bit = trim($bit);
            if ($bit !== '' && ! self::looksLikeCookieConsent($bit) && ! $this->looksLikeShopChrome($bit)) {
                $bits[] = $bit;
            }
        }
        foreach ($this->extractMetaProductFields($html) as $field) {
            $field = trim($field);
            if ($field !== '' && mb_strlen($field) <= 220 && ! self::looksLikeShopOfferDump($field)
                && ! self::looksLikeCookieConsent($field)) {
                $bits[] = $field;
            }
        }

        return implode("\n", array_values(array_unique($bits)));
    }

    /**
     * Treść karty dla modelu. Bez meta-opisu i og:description (extractSeoDescriptionText) — hasło SEO bywa inne niż
     * treść strony i niosło cechy, których karta nie podaje.
     */
    private function extractProductPageText(string $html, string $skuNorm): string
    {
        $chunks = [];
        // Meta-opis nie jest treścią, ale jak dotąd otwiera część o wyrobie przy odsiewaniu akapitów
        // (keepProductRelevantParagraphs): bez niego krótkie punkty opisu za nim odpadały (coba.com po angielsku).
        $seoContext = [];
        foreach (preg_split('/\n{2,}/u', $this->extractSeoDescriptionText($html)) ?: [] as $chunk) {
            $chunk = $this->usableExtractedChunk((string) $chunk);
            if ($chunk !== '') {
                $seoContext[] = $chunk;
            }
        }

        foreach ($this->extractMetaProductFields($html) as $field) {
            $field = $this->usableExtractedChunk($field);
            if ($field !== '') {
                $chunks[] = $field;
            }
        }

        $focused = $this->extractFocusedHtmlBlocks($html);
        if ($focused !== '' && ! $this->looksLikeEmbeddedCode($focused)) {
            $chunks[] = $focused;
        }
        if ($focused === '' || $this->looksLikeEmbeddedCode($focused) || mb_strlen($focused) < 180) {
            $chunks[] = $this->htmlToText($this->stripShopChromeHtml($html));
        }

        $text = trim(implode("\n\n", array_filter($chunks)));
        $text = $this->cleanFetchedPageText($text, $skuNorm, $seoContext);
        $norms = $this->extractNormBlocks($html);
        if ($text === '' || $norms === '' || str_contains($text, $norms)) {
            return $text;
        }

        // Po czyszczeniu: krótki wiersz („Normy: EN 343”) nie przeszedłby progu długości akapitu.
        return $text."\n\n".$norms;
    }

    /**
     * Ramka norm obok opisu (pros.pl: <ul class="norm-values">). Karta AJ GROUP 202 straciła przez jej
     * pominięcie EN ISO 13688 i EN 343 — strona ma blok opisu, więc reszty strony czytnik już nie brał,
     * a w dopasowaniu przetargu model odrzucał kartę za brak normy. Dosłowny tekst bloku, bez własnych
     * słów; blok bez kodu normy albo dłuższy niż ramka (słowniczek norm sklepu) jest pomijany.
     */
    private function extractNormBlocks(string $html): string
    {
        $values = [];
        foreach ($this->normFacts($html) as $fact) {
            $values[] = isset($fact['value']) ? $fact['label'].': '.$fact['value'] : $fact['label'];
        }

        return $values === [] ? '' : 'Normy: '.implode(', ', $values);
    }

    /**
     * Pary „norma → oznaczenie” z ramki norm, dosłownie. Pozycja listy z etykietą normy i wartością pod nią
     * (MAPA: <li> z „EN 388” i „1121X”) to jedna para; bez tego ramka szła do modelu płasko („EN 388, 1121X,
     * EN 374-1, Type A, ABCILMNOS”) i model brał czytelniej zapisany, ale stary kod ze sklepu (Butoflex 650,
     * 23.09.2026: „EN 388 (1.1.2.2)” z cas-technik.eu). Pozycja z jedną linią zostaje samą etykietą, tak jak
     * dotąd (pros.pl: „EN ISO 13688”, „EN 343”).
     *
     * @return list<array{label: string, value?: string}>
     */
    private function normFacts(string $html): array
    {
        $pattern = '#<(ul|ol|dl|div|section|table)\b[^>]*(?:id|class)=["\'][^"\']*(?<![a-z])norm(?:y|s)?(?![a-z])[^"\']*["\'][^>]*>(.*?)</\1>#isu';
        // Bez stripShopChromeHtml: PrestaShop trzyma ramkę norm wewnątrz <form> koszyka, który tamto
        // czyszczenie wycina w całości. Menu z linkiem „Normy” odpada niżej — nie ma w nim kodu normy.
        $html = preg_replace('#<(script|style|noscript)[^>]*>.*?</\1>#is', ' ', $html) ?? $html;
        if (! preg_match_all($pattern, $html, $m)) {
            return [];
        }
        $facts = [];
        foreach ($m[2] as $block) {
            $blockFacts = [];
            $allLines = [];
            $items = preg_match('#<li\b#i', (string) $block) === 1
                ? preg_split('#</li\s*>#i', (string) $block) ?: []
                : [(string) $block];
            foreach ($items as $item) {
                $lines = $this->normBlockLines((string) $item);
                if ($lines === []) {
                    continue;
                }
                $allLines = [...$allLines, ...$lines];
                $value = implode(' ', array_slice($lines, 1));
                // Wartość to oznaczenie („1121X”, „Type A ABCILMNOS”), nie zdanie — zdanie jako „poziom” normy
                // producenta wypchnęłoby potem prawdziwy kod tej normy (ManufacturerNormFacts::preferOver).
                if (count($lines) >= 2 && mb_strlen($value) <= 40 && $this->looksLikeNormLabel($lines[0])
                    && array_filter(array_slice($lines, 1), fn (string $line): bool => $this->looksLikeNormLabel($line)) === []) {
                    $blockFacts[] = ['label' => $lines[0], 'value' => $value];

                    continue;
                }
                foreach ($lines as $line) {
                    $blockFacts[] = ['label' => $line];
                }
            }
            $joined = implode(', ', $allLines);
            if (mb_strlen($joined) > 300 || preg_match('/\b(?:PN-)?EN(?:\s*ISO)?\s*\d{3,5}\b|\bISO\s*\d{4,5}\b/iu', $joined) !== 1) {
                continue;
            }
            foreach ($blockFacts as $fact) {
                if (! in_array($fact, $facts, true)) {
                    $facts[] = $fact;
                }
            }
        }

        return $facts;
    }

    /** @return list<string> */
    private function normBlockLines(string $html): array
    {
        $lines = [];
        foreach (preg_split('/\R+/u', $this->htmlToText($html)) ?: [] as $line) {
            // Myślnika nie obcinamy: „-50ºC” to temperatura ujemna, nie punktor.
            $line = (string) preg_replace('/^[\s:•]+|[\s:•]+$/u', '', $line);
            if ($line !== '' && mb_strtolower($line) !== 'normy') {
                $lines[] = $line;
            }
        }

        return $lines;
    }

    /** Etykieta pozycji ramki norm: oznaczenie normy albo kategoria ŚOI („Category 3”, „Kategoria III”). */
    private function looksLikeNormLabel(string $line): bool
    {
        return NormCode::leadFamily($line) !== ''
            || preg_match('/^(?:kategori[ai]|category|kat\.)\s*(?:I{1,3}|[123])$/iu', $line) === 1;
    }

    /**
     * @param  list<string>  $context  akapity przed tekstem (meta-opis), które przy odsiewaniu tylko otwierają część
     *                                 o wyrobie, jakby stały na początku tekstu — w wyniku ich nie ma
     */
    private function cleanFetchedPageText(string $text, string $skuNorm, array $context = []): string
    {
        $clean = fn (string $part): string => ProductDescriptionText::stripShopUi(
            self::stripExpandLinkChrome($this->stripShopChromePhrases(self::stripCookieConsentBlocks($part)))
        );
        $text = ProductDescriptionText::cutGenericCatalogAppendix($clean($text));
        $text = $this->keepProductRelevantParagraphs($text, $skuNorm, array_map($clean, $context));
        if (self::looksLikeCookieConsent($text) || self::looksLikeCjkDump($text)) {
            return '';
        }

        $text = ProductDescriptionText::stripShopUi($text);

        return mb_substr(trim($text), 0, 12000);
    }

    private function usableExtractedChunk(string $text): string
    {
        $text = self::stripExpandLinkChrome(trim($text));
        $text = ProductDescriptionText::stripShopUi($text);
        if ($text === '' || $this->looksLikeShopChrome($text) || self::looksLikeTruncatedShopTeaser($text)
            || self::looksLikeCompanyImprint($text) || self::looksLikeCookieConsent($text)
            || self::looksLikeShopOfferDump($text)) {
            return '';
        }

        return $text;
    }

    /**
     * @return list<string>
     */
    /**
     * Kod produktu z mikrodanych (itemprop sku/mpn) i z klasy body Magento.
     *
     * @return list<string>
     */
    private function markupSkus(string $html): array
    {
        $out = [];
        if (preg_match_all('#itemprop=["\'](?:sku|mpn)["\'][^>]*?(?:content=["\']([^"\']{3,40})["\'][^>]*>|>\s*([^<]{3,40}?)\s*<)#i', $html, $micro, PREG_SET_ORDER)) {
            foreach ($micro as $row) {
                $code = trim(html_entity_decode((string) (($row[1] ?? '') !== '' ? $row[1] : ($row[2] ?? '')), ENT_QUOTES | ENT_HTML5, 'UTF-8'));
                if ($code !== '') {
                    $out[$code] = $code;
                }
            }
        }
        if (preg_match('#\bcatalog_product_view_sku_([A-Za-z0-9][A-Za-z0-9._\-]{2,40})\b#', $html, $m)) {
            $out[$m[1]] = $m[1];
        }

        return array_values($out);
    }

    /**
     * Strona producenta w znacznikach mówi, że to inny produkt: SIRIUS szaro-zielona ma
     * itemprop sku „1010-035-708-00”, a szukamy „1010-001-703-00”. Nazwa („Sirius”), marka
     * i typ są wspólne dla wszystkich kolorów — bez tego karta innego koloru przechodziła.
     * Porównujemy tylko kody tego samego formatu; końcówka rozmiaru („-09”) to ten sam produkt,
     * a kod rodziny będący początkiem naszego (LM0102 ↔ LM010201) nie jest sprzecznością.
     */
    private function markupSkuNamesOtherProduct(string $html, string $sku): bool
    {
        $ours = $this->skuIdentityKey($sku);
        if (strlen($ours) < 5 || preg_match('/\d/', $ours) !== 1) {
            return false;
        }
        $comparable = 0;
        // Canis „1660-001-000-00” to wszystkie kolory, karta koloru ma „1660-001-411-00” — ten sam wyrób.
        // Tylko w tę stronę: nasz konkretny kolor przy innym kolorze na karcie nadal jest sprzecznością.
        $allColours = preg_match('/^(\d{4}-\d{3})-000-\d{2}$/', trim($sku), $family) === 1 ? $family[1] : null;
        foreach ($this->markupSkus($html) as $code) {
            if ($allColours !== null && preg_match('/^'.preg_quote($allColours, '/').'-\d{3}-\d{2}$/', trim($code)) === 1) {
                return false;
            }
            $theirs = $this->skuIdentityKey($code);
            // wewnętrzny numer sklepu („8156”) ma inny format — nie świadczy o innym produkcie
            if ($theirs === '' || abs(strlen($theirs) - strlen($ours)) > 3
                || ctype_digit($theirs[0]) !== ctype_digit($ours[0])) {
                continue;
            }
            if ($theirs === $ours || str_starts_with($theirs, $ours) || str_starts_with($ours, $theirs)) {
                return false;
            }
            $comparable++;
        }

        return $comparable > 0;
    }

    private function skuIdentityKey(string $sku): string
    {
        $key = mb_strtolower(trim($sku));
        $key = (string) preg_replace('/[\-\/\s]\d{1,2}$/u', '', $key);

        return (string) preg_replace('/[^a-z0-9]+/u', '', $key);
    }

    /**
     * Meta-opis strony (name="description") i og:description — tylko do bramek tożsamości w ingestFetchedRow, nie do
     * tekstu strony.
     */
    private function extractSeoDescriptionText(string $html): string
    {
        $out = [];
        if (preg_match('#name=["\']description["\'][^>]*content=["\']([^"\']+)["\']#i', $html, $m)
            || preg_match('#content=["\']([^"\']+)["\'][^>]*name=["\']description["\']#i', $html, $m)) {
            $out[] = trim(html_entity_decode($m[1], ENT_QUOTES | ENT_HTML5, 'UTF-8'));
        }
        $og = $this->extractOgDescription($html);
        if ($og !== '') {
            $out[] = $og;
        }

        return implode("\n\n", array_values(array_unique(array_filter($out, static fn (string $s): bool => $s !== ''))));
    }

    /**
     * Pola wyrobu z mikrodanych i JSON-LD, dosłownie: kody itemprop sku/mpn i pola GŁÓWNEGO wyrobu JSON-LD z jego
     * wariantami (jak markupIdentifiers) — węzeł Product innego wyrobu w @graph nie niesie cech tej karty.
     *
     * @return list<string>
     */
    private function extractMetaProductFields(string $html): array
    {
        $out = [];
        // Kod z mikrodanych i z klasy Magento. Karta cxs.net.pl (Canis) ma „1010-001-703-00” tylko
        // w itemprop="sku" i w body.catalog_product_view_sku_…, a nagłówek po polsku („Bluza robocza
        // CXS Sirius Lucius”) nie pasuje do angielskiej nazwy z cennika — bez kodu 37 kart
        // producenta szło do kosza jako „treść nie potwierdza produktu” (batch #305).
        foreach ($this->markupSkus($html) as $code) {
            $out[] = 'SKU: '.$code;
        }
        // JSON-LD: główny wyrób i jego warianty
        foreach ($this->mainJsonLdProductNodes($html) as $node) {
            foreach (['description', 'name', 'sku', 'brand', 'material', 'category'] as $key) {
                $val = $node[$key] ?? null;
                if (is_string($val) && trim($val) !== '') {
                    $out[] = trim($val);
                } elseif (is_array($val) && isset($val['name']) && is_string($val['name'])) {
                    $out[] = trim($val['name']);
                }
            }
        }

        return $out;
    }

    private function extractFocusedHtmlBlocks(string $html): string
    {
        $patterns = [
            '#<(?:div|section|article)[^>]*(?:id|class)=["\'][^"\']*(?:product[-_ ]?desc|product[-_]?page[-_]?desc|opis[-_ ]?produkt|short[-_ ]?desc|full[-_ ]?desc|product[-_ ]?detail|tab[-_ ]?description|description|resetcss|specyfik|cechy|parametr)[^"\']*["\'][^>]*>(.*?)</(?:div|section|article)>#is',
            // Rozwijane sekcje karty producenta (MAPA: id="foldable-content-specificadvantages", „…-applications”).
            // Bez nich z mapa-pro.com szło do modelu ~600 znaków (wykończenie i opakowanie), a zalety i zastosowania
            // brał ze sklepów — Butoflex 650, 23.09.2026. Tylko po id: klasy „applications” bywają menu.
            '#<(?:div|section|article)[^>]*\bid=["\'][^"\']*(?:advantages|applications|zastosowani|zalety)[^"\']*["\'][^>]*>(.*?)</(?:div|section|article)>#is',
            '#<div[^>]*itemprop=["\']description["\'][^>]*>(.*?)</div>#is',
            '#<(?:p|div)[^>]*itemprop=["\']description["\'][^>]*>(.*?)</(?:p|div)>#is',
        ];
        $parts = [];
        foreach ($patterns as $pattern) {
            if (preg_match_all($pattern, $html, $m)) {
                foreach ($m[1] as $block) {
                    // Kafelek innego wyrobu („Więcej rękawic”: <div class="product-details"> z nagłówkiem-linkiem
                    // „UltraNeo 420” i jego hasłem) łapał się na „product-detail” i szedł do modelu jako treść karty.
                    // Nagłówek z kotwicą („#collapse1” w akordeonie zakładek) to nie kafelek — ten zostaje.
                    if (preg_match('#<h[2-6]\b[^>]*>\s*<a\s[^>]*href=["\'](?!\s*(?:\#|javascript:))#i', (string) $block) === 1) {
                        continue;
                    }
                    // Lista w bloku opisu to jeden akapit: wcięcia między <li> rozbijały ją na akapity, a krótkie
                    // punkty („Sampling chemicals”) odpadały potem jako za krótkie.
                    $block = preg_replace_callback(
                        '#<(ul|ol)\b.*?</\1>#is',
                        static fn (array $m): string => preg_replace('/>\s+</', '><', $m[0]) ?? $m[0],
                        (string) $block
                    ) ?? (string) $block;
                    $t = self::stripExpandLinkChrome($this->htmlToText($this->stripShopChromeHtml((string) $block)));
                    $t = ProductDescriptionText::stripShopUi($t);
                    if (mb_strlen($t) >= 40 && ! $this->looksLikeShopChrome($t)
                        && ! $this->looksLikeEmbeddedCode($t) && ! self::looksLikeTruncatedShopTeaser($t)
                        && ! self::looksLikeRelatedProductTeaser($t)
                        && ! self::looksLikeShopOfferDump($t)) {
                        $parts[] = $t;
                    }
                }
            }
        }

        return trim(implode("\n\n", array_slice(array_unique($parts), 0, 16)));
    }

    private function stripShopChromeHtml(string $html): string
    {
        $html = preg_replace('#<(script|style|noscript|svg|iframe)[^>]*>.*?</\1>#is', ' ', $html) ?? $html;
        $html = preg_replace('#<(nav|header|footer|aside|form|button)[^>]*>.*?</\1>#is', ' ', $html) ?? $html;
        // typowe bloki sklepu
        $html = preg_replace(
            '#<(?:div|section|ul|li)[^>]*(?:id|class)=["\'][^"\']*(?:menu|navbar|breadcrumb|koszyk|cart|login|rejestr|footer|header|cookie|popup|modal|obserwowan|wishlist|porown|shipping|wysylk|platnosc|zwrot|regulamin|polityka)[^"\']*["\'][^>]*>.*?</(?:div|section|ul|li)>#is',
            ' ',
            $html
        ) ?? $html;

        return $html;
    }

    private function htmlToText(string $html): string
    {
        $html = preg_replace('#<(script|style|noscript)[^>]*>.*?</\1>#is', ' ', $html) ?? $html;
        // Wcięcia między wierszami tabeli dawały po „</tr>” pusty wiersz, więc każdy wiersz parametrów stał się
        // osobnym akapitem, a krótkie („Material Butyl”, „Thickness (mm) 1.45”) odpadały w keepProductRelevantParagraphs.
        $html = preg_replace_callback(
            '#<table\b.*?</table>#is',
            static fn (array $m): string => preg_replace('/>\s+</', '><', $m[0]) ?? $m[0],
            $html
        ) ?? $html;
        $html = preg_replace('#<\s*br\s*/?\s*>#i', "\n", $html) ?? $html;
        $html = preg_replace('#</(?:p|div|h[1-6]|section|article|table)>#i', "\n\n", $html) ?? $html;
        $html = preg_replace('#</(?:li|tr|ul|ol)>#i', "\n", $html) ?? $html;
        // komórki tabeli części: „<td>AB010008C</td><td>2 m</td>” sklejało się w „AB010008C2 m”
        // i kod wariantu nie potwierdzał karty
        $html = preg_replace('#</(?:td|th)>#i', ' ', $html) ?? $html;
        // „<35 megaomów”, „< 1000 V” to tekst, nie znacznik — strip_tags połykał go do najbliższego „>”
        $text = strip_tags(HtmlBareLessThan::escape($html));
        $text = html_entity_decode($text, ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $text = preg_replace('/[ \t]+/u', ' ', $text) ?? $text;
        $text = preg_replace('/\n{3,}/u', "\n\n", $text) ?? $text;

        return trim($text);
    }

    private function stripShopChromePhrases(string $text): string
    {
        $patterns = [
            '/\b(logowanie|zaloguj się|rejestracja|zarejestruj się|twoje konto|obserwowane|dodaj do koszyka|do koszyka|realizuj zamówienie|złóż zamówienie|suma:\s*[\d,\.]+\s*zł)\b/iu',
            '/\b(wyszukiwanie zaawansowane|jesteś tutaj|strona główna|sprawdź status zamówienia|sposoby płatności|prowizje|regulamin|polityka prywatności|odstąpienie od umowy)\b/iu',
            '/\b(łatwy zwrot|14\s*dni|kupuj i sprawdź|bez stresu i obaw|cena w punktach|kup za punkty|dodaj do porównania|dodaj do obserwowanych|powiadom mnie o dostępności)\b/iu',
            '/\b(sprawdź czasy i koszty wysyłki|wysyłka\s*:|dostępność\s*:|produkt dostępny w bardzo dużej ilości|nasza cena|cena katalogowa)\b/iu',
            '/\b(tel\.?\s*\d[\d\s]+|e-?mail\s*\S+@\S+)\b/iu',
            '/\|\s*/u',
        ];
        foreach ($patterns as $pattern) {
            $text = preg_replace($pattern, ' ', $text) ?? $text;
        }
        $text = preg_replace('/[ \t]{2,}/u', ' ', $text) ?? $text;
        $text = preg_replace('/\n{3,}/u', "\n\n", $text) ?? $text;

        return trim($text);
    }

    /**
     * @param  list<string>  $context  akapity oceniane przed tekstem (otwierają część o wyrobie), nie oddawane
     */
    private function keepProductRelevantParagraphs(string $text, string $skuNorm, array $context = []): string
    {
        $parts = preg_split('/\n{2,}/u', $text) ?: [$text];
        $contextParts = [];
        foreach ($context as $chunk) {
            foreach (preg_split('/\n{2,}/u', $chunk) ?: [$chunk] as $piece) {
                $contextParts[] = (string) $piece;
            }
        }
        $kept = [];
        $haveProduct = false;
        foreach ([...$contextParts, ...$parts] as $i => $part) {
            $isContext = $i < count($contextParts);
            $part = self::stripExpandLinkChrome(trim((string) $part));
            if ($part === '') {
                continue;
            }
            $low = mb_strtolower($part);
            $mentionsSku = $skuNorm !== '' && (
                str_contains($low, mb_strtolower($skuNorm))
                || $this->hayMentionsNameTokens($low, mb_strtolower($skuNorm))
            );
            if (mb_strlen($part) < 25 && ! $mentionsSku) {
                continue;
            }
            if ($this->looksLikeShopChrome($part) || self::looksLikeTruncatedShopTeaser($part)
                || self::looksLikeCompanyImprint($part)
                || self::looksLikeCookieConsent($part) || self::looksLikeCjkDump($part)
                || self::looksLikeRelatedProductTeaser($part)
                || self::looksLikeShopOfferDump($part)
                || str_contains($low, 'wähle eine option') || str_contains($low, 'wahle eine option')) {
                continue;
            }
            $productish = (bool) preg_match(
                '#(trzewik|p[oó]łbut|obuwie|buty|rękaw|ochron|chodnik|dywanik|norm|en\s*\d|iso|s3|s1|src|hro|o1|podnosek|podeszw|skór|nitryl|producent|przeznacz|materiał|cholewka|wkładka|kalosz|winter|gloss|clic)#iu',
                $low
            );
            if ($productish || $mentionsSku || mb_strlen($part) >= 120) {
                if (! $isContext) {
                    $kept[] = $part;
                }
                $haveProduct = true;
            } elseif ($haveProduct && ! $isContext) {
                $kept[] = $part;
            }
        }
        if ($kept === []) {
            $flat = $this->stripShopChromePhrases(trim(preg_replace('/\s+/u', ' ', $text) ?? $text));
            $flat = ProductDescriptionText::stripShopUi($flat);
            if ($flat === '' || self::looksLikeCompanyImprint($flat)
                || self::looksLikeCookieConsent($flat) || self::looksLikeCjkDump($flat)
                || self::looksLikeShopOfferDump($flat) || $this->looksLikeShopChrome($flat)) {
                return '';
            }

            return mb_substr($flat, 0, 10000);
        }

        return implode("\n\n", array_slice($kept, 0, 40));
    }

    /** Cennik wariantów / tabela rozmiarów Shopify — nie opis BHP. */
    public static function looksLikeShopOfferDump(string $text): bool
    {
        $priceHits = preg_match_all(
            '/\b(?:EU\s*)?(?:3[2-9]|4[0-9]|5[0-2])\s*[-–]\s*\d{1,5}(?:[.,]\d{2})?\s*(?:zł|eur|€)/iu',
            $text
        );
        if (is_int($priceHits) && $priceHits >= 4) {
            return true;
        }
        $eu = preg_match_all('/\bEU\s*(?:3[2-9]|4[0-9]|5[0-2])\b/iu', $text);
        $money = preg_match_all('/\b\d{1,5}(?:[.,]\d{2})?\s*(?:zł|eur|€)\b/iu', $text);
        if (is_int($eu) && is_int($money) && $eu >= 6 && $money >= 3) {
            return true;
        }

        return preg_match('/#{1,3}\s*jak dobrać rozmiar/iu', $text) === 1;
    }

    /** Karuzela „inne półbuty ARTRA …” z ceną — nie karta tego modelu. */
    public static function looksLikeRelatedProductTeaser(string $text): bool
    {
        $t = trim($text);
        if ($t === '' || mb_strlen($t) > 200) {
            return false;
        }

        return preg_match('/\b\d{1,3}[.,]\d{2}\s*zł\b/u', $t) === 1
            && preg_match('/\b(buty|półbuty|polbuty|trzewiki|rękawice|rekawice)\b/iu', $t) === 1;
    }

    private function looksLikeShopChrome(string $text): bool
    {
        if (self::looksLikeCookieConsent($text) || self::looksLikeRawLocaleDump($text)
            || self::looksLikeShopOfferDump($text)) {
            return true;
        }
        $low = mb_strtolower($text);
        $hits = 0;
        foreach ([
            'logowanie', 'rejestracja', 'do koszyka', 'obserwowane', 'realizuj zamówienie',
            'polityka prywatności', 'odstąpienie od umowy', 'łatwy zwrot', 'kup za punkty',
            'wyszukiwanie zaawansowane', 'jesteś tutaj', 'sprawdź status zamówienia',
            'sposoby płatności', 'dodano do koszyka', 'podaj swój adres e-mail',
            'informacje o nowościach',
            'czas wysyłki', 'indywidualna wycena', 'zapytaj o wycenę',
            'polityka bezpieczeństwa', 'zasady dostawy', 'zasady zwrotu',
            'zoom_out_map', 'chevron_left',
        ] as $needle) {
            if (str_contains($low, $needle)) {
                $hits++;
            }
        }
        if ($hits >= 2) {
            return true;
        }
        // sam slogan sklepu / koszyk
        if ($hits >= 1 && mb_strlen(trim($text)) < 160) {
            return true;
        }

        return (bool) preg_match('/^\s*(0,00\s*zł|suma:)/iu', $text);
    }

    /** Stopka / impressum firmy zamiast opisu wyrobu. */
    public static function looksLikeCompanyImprint(string $text): bool
    {
        $low = mb_strtolower($text);
        $company = str_contains($low, 'gmbh')
            || preg_match('/\bsp(?:[óo]łka)?\.?\s*z\s*o\.?\s*o\.?\b/u', $low) === 1;
        $street = preg_match('/\b(?:nordstra[sß]e|stra[sß]e|street|ul\.)\b/u', $low) === 1;
        $phone = preg_match('/\b(?:telefon|telefax|tel\.|fax\.|fax:)\b/u', $low) === 1;
        $maps = str_contains($low, 'google-maps')
            || str_contains($low, 'so finden sie uns')
            || str_contains($low, 'impressum')
            || str_contains($low, 'herausgeber');
        $about = (str_contains($low, 'do not sell my personal information')
                || str_contains($low, 'prowadzenie świata ku bezpieczniejszej')
                || str_contains($low, 'leading the world to a safer future'))
            && (str_contains($low, '1893') || str_contains($low, 'dunlop'));

        return $about || ($company && $street) || ($company && $phone) || ($street && $phone) || ($company && $maps);
    }

    /** CSS zmiennych motywu / JS widgetu wklejony w „opis”. */
    private function looksLikeEmbeddedCode(string $text): bool
    {
        if ($text === '') {
            return false;
        }

        return str_contains($text, '--wce-')
            || str_contains(mb_strtolower($text), 'intersectionobserver')
            || preg_match('/=>\s*\{/', $text) === 1
            || (substr_count($text, '{') >= 4 && substr_count($text, ';') >= 6);
    }

    /**
     * @return list<string>
     */
    private function extractDocumentUrls(
        string $html,
        string $pageUrl,
        string $skuNorm,
        bool $fromManufacturer = false,
        bool $bindsGeneratedCard = false,
    ): array {
        /** @var list<array{href: string, label: string}> $raw */
        $raw = [];
        if (preg_match_all('#<a\b[^>]*href=["\']([^"\']+\.pdf[^"\']*)["\'][^>]*>(.*?)</a>#is', $html, $m, PREG_SET_ORDER)) {
            foreach ($m as $row) {
                $raw[] = [
                    'href' => html_entity_decode((string) ($row[1] ?? ''), ENT_QUOTES | ENT_HTML5, 'UTF-8'),
                    // etykieta w oryginalnej pisowni — porównania niżej i tak robią mb_strtolower,
                    // a ten sam tekst bywa jedyną nazwą dokumentu („Instrukcja obsługi”)
                    'label' => trim(strip_tags((string) ($row[2] ?? ''))),
                ];
            }
        }
        if (preg_match_all('#href=["\']([^"\']+\.pdf[^"\']*)["\']#i', $html, $m)) {
            foreach ($m[1] as $href) {
                $raw[] = [
                    'href' => html_entity_decode((string) $href, ENT_QUOTES | ENT_HTML5, 'UTF-8'),
                    'label' => '',
                ];
            }
        }
        if (preg_match_all('#https?://[^"\'\s<>]+\.pdf(?:\?[^"\'\s<>]*)?#i', $html, $m)) {
            foreach ($m[0] as $href) {
                $raw[] = ['href' => (string) $href, 'label' => ''];
            }
        }
        // Ansell: /products/…/pds|doc|ukdoc/…
        if (preg_match_all('#href=["\']([^"\']+/(?:pds|doc|ukdoc)/[^"\']+)["\']#i', $html, $m)) {
            foreach ($m[1] as $href) {
                $raw[] = [
                    'href' => html_entity_decode((string) $href, ENT_QUOTES | ENT_HTML5, 'UTF-8'),
                    'label' => 'document',
                ];
            }
        }
        if (preg_match_all('#href=["\']([^"\']+\.ashx[^"\']*)["\']#i', $html, $m)) {
            foreach ($m[1] as $href) {
                $raw[] = [
                    'href' => html_entity_decode((string) $href, ENT_QUOTES | ENT_HTML5, 'UTF-8'),
                    'label' => '',
                ];
            }
        }

        $out = [];
        /** @var array<string, true> $unbound */
        $unbound = [];
        $skuTokens = $this->skuTokens($skuNorm);
        foreach ($raw as $item) {
            $abs = $this->absolutize($item['href'], $pageUrl);
            if ($abs === null || ! ProductDocumentDownloader::looksLikeDocumentUrl($abs)) {
                continue;
            }
            $meta = mb_strtolower(urldecode($abs));
            $label = $item['label'];
            $hay = $meta.' '.mb_strtolower($label);

            if (ProductDocumentDownloader::looksLikeJunkDocument($hay)) {
                continue;
            }

            $matched = $skuNorm !== '' && str_contains($hay, mb_strtolower($skuNorm));
            foreach ($skuTokens as $token) {
                if (str_contains($hay, $token)) {
                    $matched = true;
                    break;
                }
            }
            // „CLIC UP” → plik …CLIC_UP….pdf
            if (! $matched && $this->hayMentionsNameTokens($meta, $skuNorm)) {
                $matched = true;
            }
            if ($matched) {
                $out[] = $abs;
                $this->rememberDocumentLabel($abs, $label);

                continue;
            }
            // Nic w adresie ani w etykiecie nie wiąże pliku z tym wyrobem. Zostaje tylko pytanie,
            // czy plik w ogóle wygląda na dokument wyrobu (deklaracja, certyfikat, karta) —
            // katalog całej marki, cennik czy broszura odpadają także u producenta. Domena
            // producenta daje pierwszeństwo (niżej), ale nie immunitet na dowolny PDF.
            if (! $this->looksLikeCertificateDocument($hay)) {
                continue;
            }
            if ($fromManufacturer) {
                $out[] = $abs;
                $this->rememberDocumentLabel($abs, $label);

                continue;
            }
            $unbound[$abs] = true;
            $this->rememberDocumentLabel($abs, $label);
        }

        // Sklep: „deklaracja zgodności” bez kodu i nazwy bierzemy tylko wtedy, gdy na stronie jest
        // DOKŁADNIE JEDNA taka pozycja. Karta jednego wyrobu ma przy sobie swoją deklarację i to
        // przyjęcie jest sensowne; lista deklaracji całego sklepu (albo stopka z dokumentami)
        // ma ich wiele i żadna nie musi dotyczyć tego wyrobu — to była skarga testerki.
        if (count($unbound) === 1) {
            $out[] = (string) array_key_first($unbound);
        }
        if ($bindsGeneratedCard) {
            $card = $this->generatedCardLink($html, $pageUrl);
            if ($card !== null) {
                $out[] = $card;
            }
        }

        return array_values(array_unique($out));
    }

    /**
     * „Pobierz kartę produktu w pliku PDF” (pros.pl: /modules/x13producttopdf/pdf.php?id_product=211…) — najwyżej
     * jeden taki link, z tego samego hosta co strona. Wołane tylko dla potwierdzonej karty wyrobu u producenta.
     */
    private function generatedCardLink(string $html, string $pageUrl): ?string
    {
        if (! preg_match_all('#<a\b[^>]*href=["\']([^"\']+)["\'][^>]*>(.*?)</a>#is', $html, $m, PREG_SET_ORDER)) {
            return null;
        }
        $pageHost = preg_replace('/^www\./', '', mb_strtolower((string) parse_url($pageUrl, PHP_URL_HOST)));
        foreach ($m as $row) {
            $abs = $this->absolutize(html_entity_decode((string) $row[1], ENT_QUOTES | ENT_HTML5, 'UTF-8'), $pageUrl);
            if ($abs === null || ! ProductDocumentDownloader::looksLikeGeneratedCardUrl($abs)) {
                continue;
            }
            $host = preg_replace('/^www\./', '', mb_strtolower((string) parse_url($abs, PHP_URL_HOST)));
            $label = trim(strip_tags((string) $row[2]));
            if ($host === '' || $host !== $pageHost
                || ProductDocumentDownloader::looksLikeJunkDocument(mb_strtolower($abs.' '.$label))) {
                continue;
            }
            $this->rememberDocumentLabel($abs, $label);

            return $abs;
        }

        return null;
    }

    /** Pierwsza niepusta etykieta wygrywa: ten sam plik bywa linkowany też z pustej ikonki. */
    private function rememberDocumentLabel(string $url, string $label): void
    {
        $label = trim($label);
        if ($url === '' || $label === '' || ($this->documentLabels[$url] ?? '') !== '') {
            return;
        }
        $this->documentLabels[$url] = mb_substr($label, 0, 255);
    }

    private function looksLikeCertificateDocument(string $hay): bool
    {
        // „declaration”/„doc_” liczą się jako dokument wyrobu tylko dla deklaracji zgodności wyrobu — deklaracja
        // opakowania (Ansell PPWR przy 387 kartach) mówi o kartonie, nie o rękawicy
        if (ProductDocumentDownloader::looksLikePackagingDeclaration($hay)) {
            return false;
        }
        foreach ([
            'deklarac', 'declaration', 'conformity', 'certyfik', 'certificate',
            'datasheet', 'datenblatt', 'karta-katalog', 'karta_katalog', 'karta produktu',
            'karta techniczn', 'karta-techniczn', 'karta_techniczn',
            'product-info', 'product_info', 'productinfo', 'produktinfo',
            'informacje o produkcie', 'product information',
            'doc_', '_doc', '/doc/', 'doctype', 'ue-type', 'eu-type',
            'examination', 'attestation', 'swiadectw', 'świadectw', 'pdb_', '_pdb',
        ] as $needle) {
            if (str_contains($hay, $needle)) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param  list<string>  $domains
     */
    private function hostMatchesDomains(string $url, array $domains): bool
    {
        if ($domains === []) {
            return false;
        }
        $host = mb_strtolower((string) (parse_url($url, PHP_URL_HOST) ?? ''));
        if ($host === '') {
            return false;
        }
        foreach ($domains as $domain) {
            $d = mb_strtolower(trim($domain));
            if ($d === '') {
                continue;
            }
            if ($host === $d || str_ends_with($host, '.'.$d)) {
                return true;
            }
        }

        return false;
    }

    /**
     * @return list<string>
     */
    private function extractImageUrls(string $html, string $pageUrl, string $skuNorm): array
    {
        $rawUrls = [];

        if (preg_match_all('#<img\b[^>]*>#i', $html, $imgTags)) {
            foreach ($imgTags[0] as $tag) {
                if (! is_string($tag)) {
                    continue;
                }
                foreach (['src', 'data-src', 'data-lazy-src'] as $attr) {
                    if (preg_match('#\b'.$attr.'=["\']([^"\']+)["\']#i', $tag, $m)) {
                        $rawUrls[] = $m[1];
                    }
                }
                if (preg_match('#\bsrcset=["\']([^"\']+)["\']#i', $tag, $m)) {
                    foreach ($this->parseSrcset($m[1]) as $u) {
                        $rawUrls[] = $u;
                    }
                }
            }
        }

        if (preg_match_all('#srcset=["\']([^"\']+)["\']#i', $html, $sets)) {
            foreach ($sets[1] as $set) {
                foreach ($this->parseSrcset($set) as $u) {
                    $rawUrls[] = $u;
                }
            }
        }

        // Galerie JS często przechowują pełny obraz poza znacznikiem <img>.
        if (preg_match_all(
            '#\b(?:data-big|data-full|data-image|data-zoom-image|data-img-name)=["\']([^"\']+)["\']#i',
            $html,
            $galleryImages
        )) {
            foreach ($galleryImages[1] as $u) {
                $rawUrls[] = $u;
            }
        }

        $shoperOriginals = [];
        if (preg_match_all(
            '#href=["\']([^"\']*/userdata/public/gfx/[^"\']+\.(?:jpe?g|png|webp))["\']#i',
            $html,
            $shoperHits
        )) {
            foreach ($shoperHits[1] as $u) {
                $shoperOriginals[] = $u;
                $rawUrls[] = $u;
            }
        }

        /** @var list<string> $trustedUrls og:image / JSON-LD / itemprop / Shoper gfx — galeria karty */
        $trustedUrls = $this->extractStructuredImageUrls($html);
        foreach ($shoperOriginals as $u) {
            $trustedUrls[] = $u;
        }
        foreach ($trustedUrls as $u) {
            $rawUrls[] = $u;
        }

        // Ansell Sitecore: /-/media/.../065g_primary.ashx
        if (preg_match_all('#(?:https?:)?//[^"\'\s<>]+/-/media/[^"\'\s<>]+\.ashx[^"\'\s<>]*#i', $html, $m)) {
            foreach ($m[0] as $u) {
                $rawUrls[] = str_starts_with($u, '//') ? 'https:'.$u : $u;
                $trustedUrls[] = str_starts_with($u, '//') ? 'https:'.$u : $u;
            }
        }
        if (preg_match_all('#/-/media/[^"\'\s<>]+\.ashx[^"\'\s<>]*#i', $html, $m)) {
            foreach ($m[0] as $path) {
                $rawUrls[] = $path;
                $trustedUrls[] = $path;
            }
        }
        // Magento / JSON galeria (często escaped \/ )
        if (preg_match_all('#https?:\\\\?/\\\\?/[^"\'\s<>]+/(?:media/catalog/product|media/wysiwyg)/[^"\'\s<>]+\.(?:jpe?g|png|webp)#i', $html, $m)) {
            foreach ($m[0] as $u) {
                $rawUrls[] = str_replace('\\/', '/', $u);
            }
        }
        if (preg_match_all('#https?://[^"\'\s<>]+/media/catalog/product/[^"\'\s<>]+\.(?:jpe?g|png|webp)#i', $html, $m)) {
            foreach ($m[0] as $u) {
                $rawUrls[] = $u;
            }
        }
        // PrestaShop: /34818-large_default/nazwa.jpg
        if (preg_match_all('#https?://[^"\'\s<>]+/\d+-(?:large_default|medium_default|home_default|pdt_\d+)/[^"\'\s<>]+\.(?:jpe?g|png|webp)#i', $html, $m)) {
            foreach ($m[0] as $u) {
                $rawUrls[] = $u;
                if (str_contains(mb_strtolower($u), 'large_default')) {
                    $trustedUrls[] = $u;
                }
            }
        }

        if ($skuNorm !== '' && preg_match_all(
            '#(/[^"\'\s<>]*'.preg_quote($skuNorm, '#').'[^"\'\s<>]*\.(?:jpe?g|png|webp|gif)(?:\.webp)?)#i',
            $html,
            $m
        )) {
            foreach ($m[1] as $path) {
                $rawUrls[] = $path;
            }
        }

        $skuTokens = $this->skuTokens($skuNorm);
        $trustedAbs = [];
        foreach ($trustedUrls as $t) {
            $a = $this->absolutize(trim((string) $t), $pageUrl);
            if ($a !== null) {
                $a = ProductImageDownloader::preferFullSizeUrl($a);
                $trustedAbs[mb_strtolower($a)] = true;
            }
        }
        $pageHost = mb_strtolower((string) (parse_url($pageUrl, PHP_URL_HOST) ?? ''));

        $candidates = [];
        foreach ($rawUrls as $src) {
            if (! is_string($src) || $src === '') {
                continue;
            }
            $src = trim(explode(' ', trim($src))[0] ?? '');
            $abs = $this->absolutize($src, $pageUrl);
            if ($abs !== null) {
                $abs = ProductImageDownloader::preferFullSizeUrl($abs);
            }
            if ($abs === null || $this->isJunkImageUrl($abs) || ! ProductImageDownloader::looksLikeImageUrl($abs)) {
                continue;
            }
            $meta = mb_strtolower($abs);
            // Canis / imgserver: ?w=95 to kafle menu, nie karta produktu
            if (preg_match('/[?&]w=(\d+)/i', $abs, $wm) && (int) $wm[1] < 200) {
                continue;
            }
            if (preg_match('/[?&]h=(\d+)/i', $abs, $hm) && (int) $hm[1] < 200) {
                continue;
            }
            // WordPress thumbs: -80x80, -150x150…
            if (preg_match('/-(\d{2,4})x(\d{2,4})\.(jpe?g|png|webp)(\?|$)/i', $meta, $wm)
                && (((int) $wm[1] < 400) || ((int) $wm[2] < 400))) {
                continue;
            }
            // Shoper: productGfx_*_120_120 / _300_300 to miniatury; _0_0 to oryginał
            if (ProductImageDownloader::isSmallShoperCacheUrl($meta)) {
                continue;
            }
            // uvex imgproxy miniatury /w:60/h:60/
            if (preg_match('#/w:(\d+)/h:(\d+)/#i', $meta, $wm)
                && (((int) $wm[1] < 200) || ((int) $wm[2] < 200))) {
                continue;
            }
            // PrestaShop miniatury kolorów / thumbs
            if (preg_match('#/\d+-(small_default|cart_default|pdt_180)/#i', $meta)) {
                continue;
            }
            $score = 5;
            $skuInUrl = false;
            $trusted = isset($trustedAbs[$meta]);
            if ($trusted) {
                $score += 100;
                $skuInUrl = true;
            }
            if ($skuNorm !== '' && $this->metaContainsSkuToken($meta, $skuNorm)) {
                $score += 120;
                $skuInUrl = true;
            }
            foreach ($skuTokens as $token) {
                if ($this->metaContainsSkuToken($meta, $token)) {
                    $score += 80;
                    $skuInUrl = true;
                    break;
                }
            }
            if ($this->hayHasLongerAlphanumericSkuVariant($meta.' '.$pageUrl, $skuNorm)) {
                continue;
            }
            // PROS-101… ↔ …pros-101s-34…
            if (! $skuInUrl && $this->pageMentionsBrandModelSku($meta, preg_replace('/[^a-z0-9]+/i', '', $meta) ?? $meta, $skuNorm)) {
                $score += 70;
                $skuInUrl = true;
            }
            // Inny kod art. — na karcie z dwoma kodami (9-003B/7-003B) galeria może mieć jeden z nich
            $pageHasOurCore = $skuTokens !== [] && $this->containsArtCode(mb_strtolower($pageUrl), $skuTokens[0] ?? '');
            if (! $pageHasOurCore && $this->urlLooksLikeWrongSku($meta, $skuNorm, $skuTokens)) {
                continue;
            }
            if (str_contains($meta, 'pim/products') || str_contains($meta, 'product_detail') || str_contains($meta, 'media/catalog/product') || str_contains($meta, 'shop-media') || str_contains($meta, 'zdjecia-safety') || str_contains($meta, 'trzewiki') || str_contains($meta, 'polbuty')) {
                $score += 50;
                $skuInUrl = true;
            }
            // menu / kafle nawigacji uvex (nie zdjęcie produktu) — też w base64 imgproxy
            $decodedMeta = $meta;
            if (preg_match('#/([A-Za-z0-9_\-+/=]{24,})$#', (string) (parse_url($abs, PHP_URL_PATH) ?? ''), $bm)) {
                $raw = strtr($bm[1], '-_', '+/');
                $pad = strlen($raw) % 4;
                if ($pad > 0) {
                    $raw .= str_repeat('=', 4 - $pad);
                }
                $dec = base64_decode($raw, true);
                if (is_string($dec) && $dec !== '') {
                    $decodedMeta .= ' '.mb_strtolower($dec);
                }
            }
            if (preg_match('#(menu-|menue-|/01_menue|menue-pics|menu-neuheit|menukachel|favicon)#i', $decodedMeta) === 1) {
                continue;
            }
            // uvex shop-media na karcie produktu — prawdziwa galeria
            if (str_contains($meta, 'shop-media')) {
                $score += 120;
                $skuInUrl = true;
                $trusted = true;
            }
            // PrestaShop galeria produktu
            if (preg_match('#/\d+-(large_default|medium_default|home_default|pdt_\d+)/#i', $meta)) {
                $score += 85;
                $skuInUrl = true;
            }
            // Shoper: cache productGfx (duży) albo oryginał /userdata/public/gfx/
            if (preg_match('#/productgfx_\d+_\d+_\d+/#i', $meta) || str_contains($meta, '/userdata/public/gfx/')) {
                $score += 85;
                $skuInUrl = true;
            }
            if (str_contains($meta, 'large_default')) {
                $score += 40;
            }
            if (str_contains($meta, 'product_detail_large') || str_contains($meta, '_hr.')) {
                $score += 40;
            }
            if (preg_match('/_[sm]\.(jpe?g|png|webp)(\?|$)/i', $meta)) {
                $score -= 25; // miniatury Demar _s/_m
            }
            if (str_contains($meta, 'thumb') || str_contains($meta, 'thumbnail') || str_ends_with($meta, '.gif') || str_contains($meta, '.gif?')) {
                $score -= 60;
            }
            if (str_contains($meta, 'logo') || str_contains($meta, 'fav') || str_contains($meta, 'piktogram') || str_contains($meta, 'social-media') || str_contains($meta, 'manufacturer-logo') || str_contains($meta, '/img/m/')) {
                continue;
            }
            // ten sam host co karta + slug produktu w nazwie pliku
            $imgHost = mb_strtolower((string) (parse_url($abs, PHP_URL_HOST) ?? ''));
            if ($pageHost !== '' && $imgHost === $pageHost && $this->imageSlugOverlapsPage($meta, mb_strtolower($pageUrl))) {
                $score += 45;
                $skuInUrl = true;
            }
            // Bez śladu SKU w URL pozostaw jako słaby kandydat. Trafność obrazu
            // zweryfikuje później AI Vision na podstawie produktu i kontekstu karty.
            if (! $skuInUrl && ! $pageHasOurCore && ! $trusted) {
                $score = min($score, 1);
            }
            if ($score > 0) {
                $candidates[] = ['url' => $abs, 'score' => $score];
            }
        }

        usort($candidates, static fn (array $a, array $b): int => $b['score'] <=> $a['score']);

        $out = [];
        foreach ($candidates as $row) {
            $out[] = $row['url'];
        }

        // limit 6 nie może odciąć packshotu, gdy karta wymienia najpierw zdjęcia z zastosowania
        return array_slice(ProductImageDownloader::packshotsFirst(array_values(array_unique($out))), 0, 6);
    }

    /**
     * @return list<string>
     */
    private function extractStructuredImageUrls(string $html): array
    {
        $html = $this->withoutRelatedJsonLdImages($html);
        $urls = [];
        foreach ([
            '#property=["\']og:image["\'][^>]*content=["\']([^"\']+)["\']#i',
            '#content=["\']([^"\']+)["\'][^>]*property=["\']og:image["\']#i',
            '#name=["\']og:image["\'][^>]*content=["\']([^"\']+)["\']#i',
            '#content=["\']([^"\']+)["\'][^>]*name=["\']og:image["\']#i',
            '#itemprop=["\']image["\'][^>]*\bcontent=["\']([^"\']+)["\']#i',
            '#\bcontent=["\']([^"\']+)["\'][^>]*itemprop=["\']image["\']#i',
        ] as $pattern) {
            if (preg_match_all($pattern, $html, $matches)) {
                foreach ($matches[1] as $url) {
                    $urls[] = $url;
                }
            }
        }

        if (preg_match_all('#"image"\s*:\s*\[([^\]]+)\]#is', $html, $blocks)) {
            foreach ($blocks[1] as $block) {
                $block = str_replace('\\/', '/', (string) $block);
                if (preg_match_all('#https?://[^"\'\s]+#i', $block, $matches)) {
                    foreach ($matches[0] as $url) {
                        $urls[] = $url;
                    }
                }
            }
        }
        if (preg_match_all('#"image"\s*:\s*"(https?:\\\\?/\\\\?/[^"]+)"#i', $html, $matches)) {
            foreach ($matches[1] as $url) {
                $urls[] = str_replace('\\/', '/', $url);
            }
        }

        if (preg_match_all(
            '#href=["\']([^"\']*/(?:userdata/public/gfx|environment/cache/images/productGfx_)[^"\']+\.(?:jpe?g|png|webp))["\']#i',
            $html,
            $shoper
        )) {
            foreach ($shoper[1] as $url) {
                if (! ProductImageDownloader::isSmallShoperCacheUrl($url)) {
                    $urls[] = $url;
                }
            }
        }

        return array_values(array_unique($urls));
    }

    /** Shoper JSON-LD: isRelatedTo niesie packshoty innych kart (maska, zestaw). */
    private function withoutRelatedJsonLdImages(string $html): string
    {
        $stripped = preg_replace('#"isRelatedTo"\s*:\s*\[.*?]#s', '"isRelatedTo":[]', $html);

        return is_string($stripped) ? $stripped : $html;
    }

    /**
     * @return list<string>
     */
    private function parseSrcset(string $srcset): array
    {
        $out = [];
        foreach (explode(',', $srcset) as $part) {
            $part = trim($part);
            if ($part === '') {
                continue;
            }
            $url = trim(explode(' ', $part)[0] ?? '');
            if ($url !== '') {
                $out[] = $url;
            }
        }

        return $out;
    }

    private function imageAllowedForProduct(string $url): bool
    {
        if ($this->matchingProduct === null) {
            return true;
        }

        return ! $this->identity->imageUrlMentionsForeignBrand($url, $this->matchingProduct);
    }

    /**
     * Strona bez bloków innych wyrobów i zajawek spoza karty — przed tekstem dla modelu, zdjęciami i plikami strony.
     * Kafelek „Podobne produkty” niesie hasło innego wyrobu (coba.com: „Mata elektroizolacyjna spełniająca wymogi
     * normy BS EN 61111:2009” przy zwykłym COBAswitch, „o właściwościach elektrostatycznych” przy Tough-Lock Eco),
     * blok „Customers also bought” sklepu — cudze cechy bezpieczeństwa (gloves.co.uk przy HexArmor 4062: „class 00,
     * do 500 V” z rękawicy Ansella), a kafelek poradnika — normy z tytułu artykułu („DIN 51097 and DIN 51130” na
     * coba.com/pc/cable-protectors). Audyty Coby i UVEX, 08.10.2026.
     *
     * Wycinany jest cały element, z zagnieżdżeniem (dawny regex kończył blok na pierwszym „</div>” i reszta kafelków
     * zostawała): (a) po klasie/id (FOREIGN_BLOCK), (b) po nagłówku (FOREIGN_HEADING) — przodek, którego tekst tym
     * nagłówkiem się zaczyna, albo nagłówek z rodzeństwem do następnego nagłówka tego samego lub wyższego stopnia.
     * Nigdy blok z <h1> (tytuł karty) ani <html>/<body>/<main>. Reszta HTML zostaje bajt w bajt — tabela części,
     * mikrodane i JSON-LD głównego wyrobu stoją poza tymi blokami. Akcesoriów („Akcesoria”, „Zubehör”) nie tniemy:
     * to części tego wyrobu (krawędzie Tough-Lock z kodami w tabeli części), a ProductAccessoryExtractor czyta je
     * osobno z pełnego HTML.
     */
    private function withoutRelatedProductHtml(string $html): string
    {
        if ($html === '' || ! str_contains($html, '<')) {
            return $html;
        }
        $tree = $this->htmlElementRanges($html);
        $count = count($tree['name']);
        if ($count === 0) {
            return $html;
        }
        $h1Starts = [];
        /** @var list<array{0: int, 1: int}> $headings początek i stopień każdego <h1>–<h6> */
        $headings = [];
        foreach ($tree['name'] as $i => $name) {
            if (preg_match('/^h([1-6])$/', $name, $hm) === 1) {
                $headings[] = [$tree['start'][$i], (int) $hm[1]];
                if ($hm[1] === '1') {
                    $h1Starts[] = $tree['start'][$i];
                }
            }
        }
        $holdsTitle = static function (int $i) use ($tree, $h1Starts): bool {
            foreach ($h1Starts as $start) {
                if ($start >= $tree['start'][$i] && $start < $tree['end'][$i]) {
                    return true;
                }
            }

            return false;
        };
        $protected = ['html', 'head', 'body', 'main'];

        /** @var list<array{0: int, 1: int}> $cuts */
        $cuts = [];
        for ($i = 0; $i < $count; $i++) {
            $marks = $tree['marks'][$i];
            if ($marks === '' || in_array($tree['name'][$i], $protected, true)
                || preg_match(self::FOREIGN_BLOCK, $marks) !== 1 || preg_match(self::OWN_BLOCK, $marks) === 1
                || $holdsTitle($i)) {
                continue;
            }
            $cuts[] = [$tree['start'][$i], $tree['end'][$i]];
        }

        // Bezpiecznik kosztu (przegląd bezpieczeństwa 08.10.2026): cięcie po nagłówkach liczy tekst przodków i skanuje
        // nagłówki przy każdym kroku w górę — na stronie z tysiącami elementów albo nagłówków (złośliwa albo wygenerowana)
        // byłoby kwadratowe. Wtedy zostaje samo cięcie po klasie/id; na prawdziwych kartach (≤ kilka tysięcy elementów,
        // kilkadziesiąt nagłówków) bez zmian.
        $headingCuts = $count <= self::RELATED_CUT_MAX_ELEMENTS && count($headings) <= self::RELATED_CUT_MAX_HEADINGS;
        $foreignHeadings = 0;
        for ($i = 0; $headingCuts && $i < $count; $i++) {
            $level = $this->foreignHeadingLevel($html, $tree, $i);
            if ($level === null) {
                continue;
            }
            if (++$foreignHeadings > self::RELATED_CUT_MAX_FOREIGN_HEADINGS) {
                break;
            }
            $headingText = $this->elementText($html, $tree['start'][$i], $tree['end'][$i]);
            // Inny nagłówek tego samego albo wyższego stopnia w przodku = przodek obejmuje też inną sekcję (np. opis
            // wyrobu pod <h2>, gdy <h1> to logo w nagłówku strony) — tam już nie wchodzimy.
            $holdsOtherHeading = static function (int $scope) use ($tree, $headings, $i, $level): bool {
                foreach ($headings as [$start, $headingLevel]) {
                    if ($start !== $tree['start'][$i] && $headingLevel <= $level
                        && $start >= $tree['start'][$scope] && $start < $tree['end'][$scope]) {
                        return true;
                    }
                }

                return false;
            };
            // w górę, dopóki nagłówek otwiera tekst przodka (coba.com: div.mb-8 > h2 „Buying Guides” w kontenerze z kafelkami)
            $node = $i;
            $climbs = 0;
            while (($parent = $tree['parent'][$node]) >= 0
                && ++$climbs <= self::RELATED_CUT_MAX_CLIMB
                && ! in_array($tree['name'][$parent], [...$protected, 'article'], true)
                && ! $holdsTitle($parent)
                && ! $holdsOtherHeading($parent)
                && str_starts_with($this->elementText($html, $tree['start'][$parent], $tree['end'][$parent]), $headingText)) {
                $node = $parent;
            }
            if ($node !== $i) {
                $cuts[] = [$tree['start'][$node], $tree['end'][$node]];

                continue;
            }
            // nagłówek wśród rodzeństwa: on i elementy za nim do następnego nagłówka tego samego albo wyższego stopnia
            $parent = $tree['parent'][$i];
            $end = $tree['end'][$i];
            for ($j = $i + 1; $parent >= 0 && $j < $count && $tree['start'][$j] < $tree['innerEnd'][$parent]; $j++) {
                if ($tree['parent'][$j] !== $parent || $tree['start'][$j] < $tree['end'][$i]) {
                    continue;
                }
                $name = $tree['name'][$j];
                if ((preg_match('/^h([1-6])$/', $name, $hm) === 1 && (int) $hm[1] <= $level)
                    || in_array($name, ['footer', 'main', 'article'], true) || $holdsTitle($j)) {
                    break;
                }
                $end = max($end, $tree['end'][$j]);
            }
            // bez rodzica albo bez rodzeństwa za nagłówkiem — sam nagłówek
            $cuts[] = [$tree['start'][$i], $parent >= 0 ? min($end, max($tree['end'][$i], $tree['innerEnd'][$parent])) : $end];
        }
        if ($cuts === []) {
            return $html;
        }

        usort($cuts, static fn (array $a, array $b): int => $a[0] <=> $b[0]);
        $out = '';
        $pos = 0;
        foreach ($cuts as [$start, $end]) {
            if ($end <= $pos) {
                continue;
            }
            if ($start > $pos) {
                $out .= substr($html, $pos, $start - $pos);
            }
            // odstęp zamiast bloku — słowa po obu stronach się nie sklejają
            $out .= "\n";
            $pos = max($pos, $end);
        }

        return $out.substr($html, $pos);
    }

    /**
     * To samo cięcie dla markdownu z czytnika zapór (BlockedPageReader — strona za WAF-em przychodzi tekstem, bez
     * HTML): od nagłówka bloku innych wyrobów („## Related items”, „Customers also bought” podkreślone „---”) do
     * następnego nagłówka tego samego lub wyższego stopnia albo do końca. Krótki samodzielny wiersz z takim hasłem
     * (tytuł bloku jako zwykły akapit) — do następnego nagłówka dowolnego stopnia albo do końca.
     */
    private function withoutForeignReaderSections(string $markdown): string
    {
        $lines = preg_split('/\R/u', $markdown) ?: [];
        $count = count($lines);
        $plain = static fn (string $line): string => trim((string) preg_replace(
            ['/!?\[([^\]]*)\]\([^)]*\)/u', '/^#{1,6}\s*/u', '/[*_`]+/u', '/\s+/u'],
            ['$1', '', '', ' '],
            $line
        ));
        // stopień nagłówka wiersza $i (0 = nie nagłówek); podkreślenie setext to wiersz $i + 1
        $level = static function (int $i) use ($lines, $count): int {
            $line = (string) $lines[$i];
            if (preg_match('/^\s{0,3}(#{1,6})\s+\S/u', $line, $m) === 1) {
                return strlen($m[1]);
            }
            if (trim($line) !== '' && $i + 1 < $count && preg_match('/^\s{0,3}(=+|-+)\s*$/u', (string) $lines[$i + 1], $m) === 1) {
                return str_starts_with($m[1], '=') ? 1 : 2;
            }

            return 0;
        };
        $out = [];
        for ($i = 0; $i < $count; $i++) {
            $text = $plain((string) $lines[$i]);
            $own = $level($i);
            $standalone = $own === 0 && $text !== '' && mb_strlen($text) <= 40
                && preg_match('/[.!?;]\s*\S/u', $text) !== 1;
            if ($text === '' || mb_strlen($text) > 80 || ($own === 0 && ! $standalone)
                || preg_match(self::FOREIGN_HEADING, $text) !== 1) {
                $out[] = $lines[$i];

                continue;
            }
            $stop = $own === 0 ? 6 : $own;
            $j = $i + ($own > 0 && ! preg_match('/^\s{0,3}#/u', (string) $lines[$i]) ? 2 : 1);
            while ($j < $count) {
                $next = $level($j);
                if ($next > 0 && $next <= $stop) {
                    break;
                }
                $j++;
            }
            $out[] = '';
            $i = $j - 1;
        }

        return implode("\n", $out);
    }

    /**
     * Stopień nagłówka bloku innych wyrobów (h2–h6 → 2–6; <legend>, role="heading" i krótki tytuł z klasą „title”
     * → 3) albo null, gdy element nie jest takim nagłówkiem.
     *
     * @param  array{name: list<string>, marks: list<string>, heading: list<bool>, start: list<int>, innerEnd: list<int>, end: list<int>, parent: list<int>}  $tree
     */
    private function foreignHeadingLevel(string $html, array $tree, int $i): ?int
    {
        $name = $tree['name'][$i];
        if (preg_match('/^h([2-6])$/', $name, $m) === 1) {
            $level = (int) $m[1];
        } elseif ($name === 'legend' || $tree['heading'][$i]) {
            $level = 3;
        } else {
            return null;
        }
        // tani filtr przed złożeniem tekstu: nagłówek to krótki element
        if ($tree['end'][$i] - $tree['start'][$i] > 2000) {
            return null;
        }
        $text = $this->elementText($html, $tree['start'][$i], $tree['end'][$i]);

        return $text !== '' && mb_strlen($text) <= 80 && preg_match(self::FOREIGN_HEADING, $text) === 1 ? $level : null;
    }

    /**
     * Elementy HTML z położeniem w oryginalnym tekście — lekkie drzewo do wycięcia bloków bez przepisywania strony
     * (DOMDocument::saveHTML zmienia cudzysłowy i encje, a dalsze odczyty idą regexami po surowym HTML). Kolumny
     * zamiast tablicy na element: strona do 3 MB to dziesiątki tysięcy znaczników, a CLI na serwerze ma 128 MB.
     * Zawartość <script>/<style>/<textarea> i komentarze są pomijane: skrypt motywu buduje „<div class="…upsell…">”
     * w tekście JS (cxs.net.pl). Zamknięcie domyka też niedomknięte elementy nad nim; niedomknięte do końca strony
     * kończą się na końcu dokumentu.
     *
     * - marks: „klasa id” (pusty, gdy elementu nie mają), heading: role="heading" albo krótki tytuł z klasą
     *   „title/heading/header” (p, div, span, strong, b), parent: indeks rodzica albo -1.
     *
     * @return array{name: list<string>, marks: list<string>, heading: list<bool>, start: list<int>, innerEnd: list<int>, end: list<int>, parent: list<int>}
     */
    private function htmlElementRanges(string $html): array
    {
        $tree = ['name' => [], 'marks' => [], 'heading' => [], 'start' => [], 'innerEnd' => [], 'end' => [], 'parent' => []];
        $void = ['area', 'base', 'br', 'col', 'embed', 'hr', 'img', 'input', 'link', 'meta', 'param', 'source', 'track', 'wbr'];
        $length = strlen($html);
        $stack = [];
        $pos = 0;
        while ($pos < $length
            && preg_match('#<!--|<(/?)([a-zA-Z][a-zA-Z0-9:-]*)([^>]*)>#', $html, $m, PREG_OFFSET_CAPTURE, $pos) === 1) {
            $offset = (int) $m[0][1];
            if ($m[0][0] === '<!--') {
                $close = strpos($html, '-->', $offset + 4);
                $pos = $close === false ? $length : $close + 3;

                continue;
            }
            $name = strtolower((string) $m[2][0]);
            $end = $offset + strlen((string) $m[0][0]);
            $pos = $end;
            if ($m[1][0] === '/') {
                for ($k = count($stack) - 1; $k >= 0; $k--) {
                    if ($tree['name'][$stack[$k]] !== $name) {
                        continue;
                    }
                    $target = $stack[$k];
                    while (count($stack) > $k) {
                        $idx = (int) array_pop($stack);
                        $tree['innerEnd'][$idx] = $offset;
                        $tree['end'][$idx] = $idx === $target ? $end : $offset;
                    }
                    break;
                }

                continue;
            }
            $attrs = (string) $m[3][0];
            $class = $attrs === '' ? '' : $this->attributeValue($attrs, 'class');
            $marks = $attrs === '' ? '' : trim($class.' '.$this->attributeValue($attrs, 'id'));
            $idx = count($tree['name']);
            $tree['name'][] = $name;
            $tree['marks'][] = $marks;
            $tree['heading'][] = $attrs !== '' && (preg_match('/\brole\s*=\s*["\']?heading/i', $attrs) === 1
                || (in_array($name, ['p', 'div', 'span', 'strong', 'b'], true)
                    && preg_match('/(?:^|[\s_-])(?:title|heading|header)(?:[\s_-]|$)/i', $class) === 1));
            $tree['start'][] = $offset;
            $tree['innerEnd'][] = $length;
            $tree['end'][] = $length;
            $tree['parent'][] = $stack === [] ? -1 : (int) $stack[count($stack) - 1];
            if (in_array($name, $void, true) || str_ends_with(rtrim($attrs), '/')) {
                $tree['innerEnd'][$idx] = $end;
                $tree['end'][$idx] = $end;

                continue;
            }
            if (in_array($name, ['script', 'style', 'textarea'], true)) {
                $close = stripos($html, '</'.$name, $end);
                $closeEnd = $close === false ? $length : (strpos($html, '>', $close) ?: $length - 1) + 1;
                $tree['innerEnd'][$idx] = $close === false ? $length : $close;
                $tree['end'][$idx] = $closeEnd;
                $pos = $closeEnd;

                continue;
            }
            $stack[] = $idx;
        }

        return $tree;
    }

    /** Tekst fragmentu HTML jednym ciągiem, bez skryptów i znaczników. */
    private function elementText(string $html, int $start, int $end): string
    {
        $inner = substr($html, $start, $end - $start);
        $inner = preg_replace('#<(script|style|noscript)\b[^>]*>.*?</\1>#is', ' ', $inner) ?? $inner;
        $text = html_entity_decode((string) preg_replace('/<[^>]+>/', ' ', HtmlBareLessThan::escape($inner)), ENT_QUOTES | ENT_HTML5, 'UTF-8');

        return trim((string) preg_replace('/\s+/u', ' ', $text));
    }

    private function attributeValue(string $attrs, string $name): string
    {
        return preg_match('/(?:^|\s)'.preg_quote($name, '/').'\s*=\s*(?:"([^"]*)"|\'([^\']*)\'|([^\s>]+))/i', $attrs, $m) === 1
            ? html_entity_decode(($m[1] ?? '').($m[2] ?? '').($m[3] ?? ''), ENT_QUOTES | ENT_HTML5, 'UTF-8')
            : '';
    }

    private function isJunkImageUrl(string $url): bool
    {
        if (ProductImageDownloader::isManufacturerSiteGraphicUrl($url) || ProductImageDownloader::isSiteIdentityGraphicUrl($url)) {
            return true;
        }
        // zaślepki sklepów po członach nazwy i grafiki reklamowe z profilu producenta (coba: StandUpforHealth)
        if (ImageUrlBlocklist::blocked($url, $this->matchingProfile) !== null) {
            return true;
        }
        $u = mb_strtolower($url);
        foreach ([
            'logo', 'icon', 'sprite', 'favicon', 'banner', 'payment',
            'dhl', 'inpost', 'poczta', 'ups', 'fedex', 'dpd', 'gls',
            'koszyk', 'wallet', 'payu', 'przelewy', 'blik',
            'ochronki na buty', 'shoe-cover', 'shoe_cover', 'nakladki', 'folie-na',
            'placeholder', 'blank', 'pixel', 'bg_environment', 'environment_oily', '.svg',
            '/upload/img/seo/', '/upload/img/icons/', '/upload/img/logo/',
            // placeholdery Magento / lazy-load
            'loader', 'spinner', 'loading', 'preloader', 'ajax-loader', 'load.gif',
            'loading.gif', 'loader-1', 'loader-2', 'progress.gif',
            'related', 'upsell', 'crosssell', 'widget',
            'piktogram', 'social-media', 'logo-social', 'btn-youtube', '/_icons/',
            // uvex menu / kafle / mapy — nie produkt
            'menue-', 'menu-', '/01_menue', 'menue-pics', 'menu_pics', 'flag',
            'sustainability', 'bamboo-twinflex', 'world-map', 'sitemap',
        ] as $needle) {
            if (str_contains($u, $needle)) {
                return true;
            }
        }

        // /cart/ i cart_default — nie host redcart.pl
        return preg_match('#(?<![a-z])cart(?![a-z])#', $u) === 1;
    }

    private function extractOgDescription(string $html): string
    {
        if (preg_match('#property=["\']og:description["\'][^>]*content=["\']([^"\']+)["\']#i', $html, $m)
            || preg_match('#content=["\']([^"\']+)["\'][^>]*property=["\']og:description["\']#i', $html, $m)) {
            return trim(html_entity_decode($m[1], ENT_QUOTES | ENT_HTML5, 'UTF-8'));
        }

        return '';
    }

    private function containsArtCode(string $hay, string $core): bool
    {
        $core = mb_strtolower(trim($core));
        if ($core === '') {
            return false;
        }
        if (preg_match('/(?<![0-9])'.preg_quote($core, '/').'(?![0-9])/u', $hay) === 1) {
            return true;
        }
        $compact = str_replace('-', '', $core);

        return preg_match('/(?<![0-9])'.preg_quote($compact, '/').'(?![0-9])/u', $hay) === 1;
    }

    private function hayMentionsNameTokens(string $hay, string $skuNorm): bool
    {
        $parts = preg_split('/[\s\-·\/_]+/u', mb_strtolower(trim($skuNorm))) ?: [];
        $tokens = [];
        foreach ($parts as $part) {
            $part = trim($part);
            if ($part !== '' && mb_strlen($part) >= 2) {
                $tokens[] = $part;
            }
        }
        if ($tokens === []) {
            return false;
        }
        $normSet = ['s1', 's2', 's3', 'src', 'hro', 'o1', 'o2', 'fo', 'ci', 'hi', 'wr', 'an'];
        $nameTokens = array_values(array_filter($tokens, static fn (string $t): bool => ! in_array($t, $normSet, true)));
        $normTokens = array_values(array_filter($tokens, static fn (string $t): bool => in_array($t, $normSet, true)));
        if ($nameTokens === []) {
            $nameTokens = $tokens;
            $normTokens = [];
        }
        $hayCompact = preg_replace('/[^a-z0-9]+/i', '', $hay) ?? $hay;
        foreach ($nameTokens as $token) {
            if (! str_contains($hay, $token) && ! str_contains($hayCompact, $token)) {
                return false;
            }
        }
        if ($normTokens === []) {
            return true;
        }
        $hits = 0;
        foreach ($normTokens as $token) {
            if (str_contains($hay, $token) || str_contains($hayCompact, $token)) {
                $hits++;
            }
        }

        return $hits >= max(1, (int) ceil(count($normTokens) * 0.5));
    }

    /**
     * @return list<string>
     */
    private function skuTokens(string $skuNorm): array
    {
        $tokens = [];
        if (preg_match('/\d{1,2}-\d{3}/', $skuNorm, $m)) {
            $tokens[] = $m[0]; // 9-084
            $tokens[] = str_replace('-', '', $m[0]); // 9084
        }
        if (preg_match('/\b(\d{4,})\b/', $skuNorm, $m)) {
            $tokens[] = $m[1];
        }
        // PROS-101-S1-MAX → 101
        if (preg_match('/(?:^|[\-_])(\d{2,4})(?:[\-_]|$)/', $skuNorm, $m)) {
            $tokens[] = $m[1];
        }

        return array_values(array_unique(array_filter($tokens)));
    }

    /**
     * SKU „PROS-101-S1-MAX” vs strona „pros 101/S” / „pros-101s-34”.
     */
    private function pageMentionsBrandModelSku(string $hay, string $hayCompact, string $skuNorm): bool
    {
        $parts = preg_split('/[\s\-_\/]+/u', mb_strtolower(trim($skuNorm))) ?: [];
        $parts = array_values(array_filter($parts, static fn (string $p): bool => mb_strlen($p) >= 2));
        if (count($parts) < 2) {
            return false;
        }
        $brand = null;
        $model = null;
        foreach ($parts as $part) {
            if ($brand === null && preg_match('/^[a-z]{2,}$/u', $part) === 1) {
                $brand = $part;
            }
            if ($model === null && preg_match('/^\d{2,4}[a-z]?$/u', $part) === 1) {
                $model = $part;
            }
        }
        if ($brand === null || $model === null) {
            return false;
        }
        $modelDigits = preg_replace('/\D+/u', '', $model) ?? $model;
        $hasBrand = str_contains($hay, $brand) || str_contains($hayCompact, $brand);
        $hasModel = str_contains($hay, $model)
            || ($modelDigits !== '' && (
                preg_match('/(?<![0-9])'.preg_quote($modelDigits, '/').'(?![0-9])/u', $hay) === 1
                || str_contains($hayCompact, $modelDigits)
            ));

        return $hasBrand && $hasModel;
    }

    private function imageSlugOverlapsPage(string $imageUrl, string $pageUrl): bool
    {
        $imgPath = (string) (parse_url($imageUrl, PHP_URL_PATH) ?? '');
        $pagePath = (string) (parse_url($pageUrl, PHP_URL_PATH) ?? '');
        $imgBase = mb_strtolower((string) pathinfo($imgPath, PATHINFO_FILENAME));
        $pageBase = mb_strtolower(basename($pagePath));
        if ($imgBase === '' || $pageBase === '') {
            return false;
        }
        $imgBits = array_values(array_filter(
            preg_split('/[\s\-_]+/u', $imgBase) ?: [],
            static fn (string $w): bool => mb_strlen($w) >= 4
        ));
        $hits = 0;
        foreach ($imgBits as $bit) {
            if (str_contains($pageBase, $bit) || str_contains($pagePath, $bit)) {
                $hits++;
                // Canis: plik 13074_2216-00.jpg przy slugu …_p13074
                if (preg_match('/^\d{4,}$/', $bit) === 1) {
                    return true;
                }
            }
        }

        return $hits >= 2;
    }

    /** NB27 w tekście/URL z NB27B/NB27S — inny wariant produktu. */
    private function hayHasLongerAlphanumericSkuVariant(string $hay, string $skuNorm): bool
    {
        $skuCompact = preg_replace('/[^a-z0-9]+/iu', '', mb_strtolower(trim($skuNorm))) ?? '';
        if ($skuCompact === '' || mb_strlen($skuCompact) < 3 || ! $this->isAlphanumericSkuCode($skuCompact)) {
            return false;
        }

        return preg_match(
            '/(?<![a-z0-9])'.preg_quote($skuCompact, '/').'[a-z0-9]+/iu',
            mb_strtolower($hay)
        ) === 1;
    }

    /**
     * Dokładny kod jako osobny token w treści karty (nie w adresie — slug rodziny go nie niesie):
     * „AS060003” obok „AS060003C” w tabeli części. Dopiero brak dokładnego kodu przy dłuższym
     * wariancie oznacza cudzą kartę (NB27 ≠ NB27B).
     */
    private function hayHasExactAlphanumericSku(string $hay, string $skuNorm): bool
    {
        $sku = mb_strtolower(trim($skuNorm));
        $skuCompact = preg_replace('/[^a-z0-9]+/iu', '', $sku) ?? '';
        if ($skuCompact === '' || ! $this->isAlphanumericSkuCode($skuCompact)) {
            return false;
        }
        $hay = mb_strtolower($hay);
        foreach (array_unique([$sku, $skuCompact]) as $needle) {
            if (preg_match('/(?<![a-z0-9])'.preg_quote($needle, '/').'(?![a-z0-9])/iu', $hay) === 1) {
                return true;
            }
        }

        return false;
    }

    private function isAlphanumericSkuCode(string $token): bool
    {
        $compact = preg_replace('/[^a-z0-9]+/iu', '', mb_strtolower($token)) ?? '';
        if ($compact === '' || mb_strlen($compact) < 3) {
            return false;
        }

        return preg_match('/[a-z]/u', $compact) === 1
            && preg_match('/\d/u', $compact) === 1;
    }

    private function metaContainsSkuToken(string $meta, string $token): bool
    {
        $token = mb_strtolower(trim($token));
        if ($token === '') {
            return false;
        }
        $compact = preg_replace('/[^a-z0-9]+/iu', '', $token) ?? $token;
        if ($this->isAlphanumericSkuCode($compact)) {
            return preg_match('/(?<![a-z0-9])'.preg_quote($token, '/').'(?![a-z0-9])/iu', $meta) === 1
                || preg_match('/(?<![a-z0-9])'.preg_quote($compact, '/').'(?![a-z0-9])/iu', $meta) === 1;
        }

        return str_contains($meta, $token);
    }

    /**
     * @param  list<string>  $skuTokens
     */
    private function urlLooksLikeWrongSku(string $urlMeta, string $skuNorm, array $skuTokens): bool
    {
        if ($skuTokens === []) {
            return false;
        }
        if (! preg_match_all('/\b(\d{1,2}-\d{3})\b/', $urlMeta, $m)) {
            return false;
        }
        foreach ($m[1] as $found) {
            $ok = false;
            foreach ($skuTokens as $token) {
                if ($found === $token || str_replace('-', '', $found) === $token) {
                    $ok = true;
                    break;
                }
            }
            if (! $ok && ! str_contains($skuNorm, $found)) {
                return true;
            }
        }

        return false;
    }

    private function absolutize(string $url, string $base): ?string
    {
        $url = html_entity_decode(trim($url), ENT_QUOTES | ENT_HTML5, 'UTF-8');
        if ($url === '' || str_starts_with($url, 'data:')) {
            return null;
        }
        if (str_starts_with($url, '//')) {
            $url = 'https:'.$url;
        }
        if (str_starts_with($url, 'http://') || str_starts_with($url, 'https://')) {
            return $url;
        }
        $parts = parse_url($base);
        if (! is_array($parts) || empty($parts['scheme']) || empty($parts['host'])) {
            return null;
        }
        $origin = $parts['scheme'].'://'.$parts['host'];
        if (! empty($parts['port'])) {
            $origin .= ':'.$parts['port'];
        }
        if (str_starts_with($url, '/')) {
            return $origin.$url;
        }
        $path = $parts['path'] ?? '/';
        $dir = preg_replace('#/[^/]*$#', '/', $path) ?? '/';

        return $origin.$dir.$url;
    }
}
