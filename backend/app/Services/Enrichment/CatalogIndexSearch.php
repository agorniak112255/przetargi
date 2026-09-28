<?php

declare(strict_types=1);

namespace App\Services\Enrichment;

use App\Models\CatalogPage;
use App\Models\ManufacturerSite;
use App\Models\Product;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Szuka karty produktu w lokalnym indeksie sitemap — zanim zapytamy wyszukiwarkę.
 * Dopasowanie idzie po tokenach adresu: najpierw kod, potem marka ze słowami z nazwy.
 */
final class CatalogIndexSearch
{
    /** Ile stron zwracamy do dalszego przetwarzania. */
    private const MAX_HITS = 8;

    /** Ile kandydatów naraz przepuszczamy przez filtr tożsamości. */
    private const SQL_LIMIT = 40;

    /**
     * Ile kandydatów w sumie przeglądamy. Przy 2,5 mln adresów pospolity token
     * („3000”, marka) ma tysiące stron — właściwa bywa dalej niż pierwsze 40.
     */
    private const CANDIDATE_POOL = 400;

    /** Powyżej tylu stron token jest pospolity — dalej nie liczymy. */
    private const DF_CAP = 5000;

    /** @var list<array{url: string, reason: string}> */
    private array $rejections = [];

    /** @var array<string, true> */
    private array $except = [];

    /**
     * Hosty, do których ogranicza się bieżące findFor (null = cały indeks).
     *
     * @var list<string>|null
     */
    private ?array $onlyHosts = null;

    public function __construct(
        private readonly ProductSearchIdentity $identity,
        private readonly CatalogPageManufacturer $pageManufacturer,
        private readonly CatalogSitemapIndexer $indexer,
    ) {}

    /**
     * @param  list<string>  $exceptUrls  karty już sprawdzone — kolejne wywołanie daje następną partię
     * @param  list<string>|null  $onlyHosts  tylko strony tych hostów (i ich subdomen) — uzupełnianie krótkiego opisu
     *                                        B2B ze stron wskazanych przy koncie. Filtr stoi w SQL przy doborze kandydatów,
     *                                        nie po przycięciu puli: przy pospolitym tokenie 400 najlepszych stron całego
     *                                        indeksu mogłoby nie mieć ani jednej strony z tych hostów. Pusta lista po
     *                                        normalizacji = brak wyników.
     * @return list<array{url: string, title: string, snippet: string}>
     */
    public function findFor(Product $product, array $exceptUrls = [], ?array $onlyHosts = null): array
    {
        $this->rejections = [];
        $this->except = [];
        foreach ($exceptUrls as $url) {
            $key = mb_strtolower(trim((string) $url));
            if ($key !== '') {
                $this->except[$key] = true;
            }
        }
        $this->onlyHosts = null;
        if ($onlyHosts !== null) {
            $hosts = [];
            foreach ($onlyHosts as $host) {
                $bare = ManufacturerSite::normalizeHost((string) $host);
                if ($bare !== '') {
                    $hosts[$bare] = true;
                }
            }
            if ($hosts === []) {
                return [];
            }
            $this->onlyHosts = array_keys($hosts);
        }

        try {
            // Model z kolumny cennika („RADIM”, „BEDFORD”) idzie pierwszy: karta „bluza-kucharska-radim”
            // niesie tylko to jedno rzadkie słowo i przegrywała ranking z kartami, które zbierały
            // pospolite tokeny z angielskiej nazwy („chef”, „cotton”, „1150”) — batch #307, Canis.
            $hits = $this->mergeHits(
                $this->byModelName($product),
                $this->mergeHits($this->byCode($product), $this->byBrandAndName($product))
            );
            if ($hits !== []) {
                return $hits;
            }

            return $this->byDistinctiveName($product);
        } finally {
            // filtr hostów dotyczy tylko tego wywołania — usługa jest współdzielona z tym samym przebiegiem
            $this->onlyHosts = null;
        }
    }

    /**
     * Kandydaci z ostatniego findFor, których odsiał filtr tożsamości.
     *
     * @return list<array{url: string, reason: string}>
     */
    public function lastRejections(): array
    {
        return CandidateRejection::unique($this->rejections);
    }

    /**
     * Kody, po których ma sens szukać w adresie: SKU, rdzeń kodu cennikowego, wersja bez znaków.
     *
     * @return list<string>
     */
    public function codes(Product $product): array
    {
        $raw = [
            trim((string) $product->sku),
            $this->identity->catalogSkuWithoutSize($product),
            $this->identity->internalSkuCore($product),
            $this->identity->stripBrandPrefix(
                trim((string) $product->sku),
                $this->identity->shortBrand((string) $product->manufacturer)
            ),
        ];
        foreach ($this->identity->catalogArticleCodes($product) as $article) {
            $raw[] = $article;
        }
        // „MT-212-2” leży w indeksie pod adresem „maska-mt-212-p-8” — człon
        // z wariantem sklepy zostawiają dopiero w treści karty
        foreach ($this->identity->variantBaseCodes($product) as $base) {
            $raw[] = $base;
        }
        foreach ($this->identity->modelAliases($product) as $alias) {
            $raw[] = $alias;
        }
        $brand = $this->identity->shortBrand((string) $product->manufacturer);
        foreach ($this->identity->shopIdentityPhrases($product) as $phrase) {
            if ($this->identity->isWeakShopIndexPhrase($phrase, $product)) {
                continue;
            }
            $raw[] = $phrase;
            // „AlphaTec Pass-through Whistle Rectus 96KS” leży w adresie karty jako osobne
            // tokeny „passthrough” i „whistle” — sklejona fraza nie jest tokenem żadnej strony,
            // a cennikowe „passthru” z surowej nazwy nie trafia w rozwinięte słowo
            foreach ($this->phraseWordTokens($phrase, $brand, $product) as $word) {
                $raw[] = $word;
            }
        }
        foreach ($this->identity->skuSearchNeedles($product) as $needle) {
            $raw[] = $needle;
        }
        // „ARYA 300 673560 S1 P” — numer wariantu stoi w adresie jako osobny token
        // „673560”; sklejony cały kod nigdy nie jest tokenem indeksu
        foreach (preg_split('/[^a-z0-9]+/u', mb_strtolower(Str::ascii((string) $product->sku))) ?: [] as $part) {
            if ($this->isDistinctiveSkuSegment($part)) {
                $raw[] = $part;
            }
        }

        $out = [];
        foreach ($raw as $code) {
            $code = mb_strtolower(trim($code));
            if ($code === '' || mb_strlen($code) < 3) {
                continue;
            }
            // „131-s1” jest w adresie jako „131”, „s1” i „131s1”
            $compact = preg_replace('/[^a-z0-9]+/u', '', $code) ?? $code;
            $min = preg_match('/^\d{3}$/u', $compact) === 1 ? 3 : 4;
            if ($compact !== '' && mb_strlen($compact) >= $min && mb_strlen($compact) <= 64) {
                $out[] = $compact;
            }
            if (mb_strlen($code) <= 64 && preg_match('/^[a-z0-9]+$/u', $code) === 1) {
                $out[] = $code;
            }
        }

        // Ten sam tokenizer co przy indeksowaniu — „7-003 B S1” leży w adresie karty
        // jako „7003” i „003b”, a sklejony kod („7003bs1”) nie jest tokenem żadnej strony.
        // Cyfra i długość odsiewają zwykłe słowa z nazwy, które nie identyfikują produktu.
        foreach ($this->indexer->tokensFor('', (string) $product->sku) as $token) {
            if (mb_strlen($token) >= 4 && preg_match('/\d/u', $token) === 1) {
                $out[] = $token;
            }
        }

        // „Chin strap Peltor GH4” u dystrybutora Canis: kodu Canis nie ma w adresach kart 3M, a numer
        // marki towaru stoi w nazwie. Trzyznakowe „gh4” odpadało na progu 4 znaków i miejsca zajmowały
        // kaski X5000 „z paskiem podbródkowym” (batch #312).
        if ($this->identity->goodsBrandKeys($product) !== []) {
            foreach ($this->goodsBrandNameCodes($product) as $word) {
                $out[] = $word;
            }
        }

        $out = array_values(array_unique($out));

        return array_values(array_filter(
            $out,
            fn (string $code): bool => ! $this->identity->isAnsellGloveWarehouseRemnant($code, $product)
        ));
    }

    /**
     * Numer towaru z nazwy („GH4”, „6051”, „2890a”) bez klas ochrony i ilości: „FFP2”, „ABEK1”,
     * „A1P1D”, „GEN3” są na setkach kart, „250 pairs”, „190 g/m2”, „EN 166” nie identyfikują wyrobu.
     *
     * @return list<string>
     */
    private function goodsBrandNameCodes(Product $product): array
    {
        $words = preg_split('/[^a-z0-9]+/u', mb_strtolower(Str::ascii((string) $product->name)), -1, PREG_SPLIT_NO_EMPTY) ?: [];
        $out = [];
        foreach ($words as $i => $word) {
            $numeric = preg_match('/^\d{3,6}$/u', $word) === 1;
            if (! $numeric && preg_match('/^(?=.*\d)(?=.*[a-z])[a-z0-9]{3,10}$/u', $word) !== 1) {
                continue;
            }
            if (preg_match('/^(?:ffp\d|p\d|gen\d|(?:a|b|e|k|hg|ax|abe|abek)\d(?:p\d)?[dr]?)$/u', $word) === 1) {
                continue;
            }
            $next = $words[$i + 1] ?? '';
            $previous = $words[$i - 1] ?? '';
            if ($numeric && (preg_match('/^(?:pcs|pc|pairs?|prs|ml|g|gsm|cm|mm|db|kg|szt|par)$/u', $next) === 1
                || in_array($previous, ['en', 'iso', 'size', 'snr', 'din'], true))) {
                continue;
            }
            $out[] = $word;
        }

        return $out;
    }

    /**
     * @return list<array{url: string, title: string, snippet: string}>
     */
    private function byCode(Product $product): array
    {
        $codes = $this->codes($product);
        if ($codes === []) {
            return [];
        }

        $typePrefixes = $this->shortNumericVariantNeedsType($product, $codes)
            ? $this->identity->catalogTypeTokenPrefixes($product)
            : [];
        $weights = $this->tokenWeights($codes);
        $query = DB::table('catalog_page_tokens as t')
            ->whereIn('t.token', $codes);
        if ($typePrefixes !== []) {
            $this->requireTypeToken($query, $typePrefixes);
        }
        $scores = $this->scoredPageIds($query, $weights);
        // sklejony model („ultraneo420”, „9022002”) ma wagę kodu, z którego go wyprowadziliśmy
        foreach ($this->gluedNumericTokenPageIds($codes, $typePrefixes) + $this->splitModelTokenPageIds($codes) as $id => $code) {
            $scores[$id] = max($scores[$id] ?? 0, $weights[$code] ?? 0);
        }
        foreach ($this->familySizeCodePageIds($product) as $id => $code) {
            $scores[$id] = max($scores[$id] ?? 0, $weights[$code] ?? $this->tokenWeights([$code])[$code]);
        }

        return $this->pages($this->rankedIds($scores), $product);
    }

    /**
     * Karty niosące wszystkie słowa modelu z kolumny cennika (≥ 4 znaki). Bez kolumny modelu
     * albo przy słabej frazie („STANDARD”) nic nie zwraca — reszta wyszukiwania bez zmian.
     * Tożsamość karty (producent, typ, inny kod) sprawdza dalej pages() i filtr wyszukiwarki.
     *
     * @return list<array{url: string, title: string, snippet: string}>
     */
    private function byModelName(Product $product): array
    {
        $model = $this->identity->modelNamePhrase($product);
        if ($model === '' || $this->identity->isWeakShopIndexPhrase($model, $product)) {
            return [];
        }
        $words = [];
        foreach (preg_split('/[\s\-\/]+/u', mb_strtolower(Str::ascii($model))) ?: [] as $word) {
            $word = preg_replace('/[^a-z0-9]+/u', '', $word) ?? '';
            if (mb_strlen($word) >= 4) {
                $words[] = $word;
            }
        }
        $words = array_values(array_unique($words));
        if ($words === []) {
            return [];
        }

        $query = DB::table('catalog_page_tokens as t')->whereIn('t.token', $words);
        $hits = $this->pages(
            $this->rankedIds($this->scoredPageIds($query, $this->tokenWeights($words), count($words))),
            $product
        );
        if (! $this->identity->nameRequiresArticleType($product)) {
            return $hits;
        }

        // Model obejmuje całą kolekcję (BEDFORD: kurtki, spodnie, strona kolekcji). Pierwsze miejsca
        // dostają tylko karty zgodnego rodzaju — inaczej kurtki wypychały spodnie z limitu kart.
        return array_values(array_filter(
            $hits,
            fn (array $hit): bool => $this->identity->hayHasRequiredTypeFromName(
                $hit['url'].' '.$hit['title'].' '.$hit['snippet'],
                $product
            )
        ));
    }

    /**
     * Produkty bez kodu producenta („WKLADKI-ALUTERMICZNE”) rozpoznajemy po marce
     * i znaczących słowach z nazwy.
     *
     * @return list<array{url: string, title: string, snippet: string}>
     */
    private function byBrandAndName(Product $product): array
    {
        $brand = $this->brandToken($product);
        $words = $this->nameTokens($product);
        if ($brand === '' || $words === []) {
            return [];
        }

        $query = DB::table('catalog_page_tokens as t')
            ->whereIn('t.token', $words)
            ->whereExists(function ($q) use ($brand): void {
                $q->select(DB::raw(1))
                    ->from('catalog_page_tokens as b')
                    ->whereColumn('b.catalog_page_id', 't.catalog_page_id')
                    ->where('b.token', $brand);
            });

        return $this->pages(
            $this->rankedIds($this->scoredPageIds($query, $this->tokenWeights($words), min(2, count($words)))),
            $product
        );
    }

    /**
     * Karta bez SKU w slugu („peleryna-dla-niepelnosprawnych-wozek-aktywny”).
     *
     * @return list<array{url: string, title: string, snippet: string}>
     */
    private function byDistinctiveName(Product $product): array
    {
        $words = $this->nameTokens($product);
        if (count($words) < 3) {
            return [];
        }

        $typePrefixes = $this->shortNumericVariantNeedsType($product, $this->codes($product))
            ? $this->identity->catalogTypeTokenPrefixes($product)
            : [];
        $query = DB::table('catalog_page_tokens as t')
            ->whereIn('t.token', $words);
        if ($typePrefixes !== []) {
            $this->requireTypeToken($query, $typePrefixes);
        }

        return $this->pages(
            $this->rankedIds($this->scoredPageIds($query, $this->tokenWeights($words), 3)),
            $product
        );
    }

    /**
     * @param  list<string>  $typePrefixes
     */
    private function requireTypeToken(Builder $query, array $typePrefixes): void
    {
        $query->whereExists(function ($q) use ($typePrefixes): void {
            $q->select(DB::raw(1))
                ->from('catalog_page_tokens as typ')
                ->whereColumn('typ.catalog_page_id', 't.catalog_page_id')
                ->where(function ($inner) use ($typePrefixes): void {
                    foreach ($typePrefixes as $prefix) {
                        $inner->orWhere('typ.token', 'like', $prefix.'%');
                    }
                });
        });
    }

    /**
     * Strony z sumą wag trafionych tokenów. Rzadki kod przeważa nad pospolitym
     * tokenem — sama liczba trafień remisowała i o kolejności decydowała baza.
     *
     * @param  array<string, int>  $weights
     * @return array<int, int> id strony → wynik, od najlepszej
     */
    private function scoredPageIds(Builder $query, array $weights, ?int $minDistinct = null): array
    {
        $cases = [];
        $bindings = [];
        foreach ($weights as $token => $weight) {
            $cases[] = 'WHEN ? THEN ?';
            $bindings[] = (string) $token;
            $bindings[] = $weight;
        }
        $this->restrictToHosts($query, 't.catalog_page_id');
        $query->select('t.catalog_page_id')
            ->selectRaw('SUM(CASE t.token '.implode(' ', $cases).' ELSE 0 END) as score', $bindings)
            ->groupBy('t.catalog_page_id');
        if ($minDistinct !== null) {
            $query->havingRaw('COUNT(DISTINCT t.token) >= ?', [$minDistinct]);
        }

        $out = [];
        foreach ($query->orderByDesc('score')->orderBy('t.catalog_page_id')->limit(self::CANDIDATE_POOL)->get() as $row) {
            $out[(int) $row->catalog_page_id] = (int) $row->score;
        }

        return $out;
    }

    /**
     * Ogranicza zapytanie o tokeny do stron z hostów findFor(..., $onlyHosts): host strony w indeksie równy albo jego
     * subdomena. Indeks zapisuje host bez „www.”, ale starsze wiersze bywały z nim — oba zapisy.
     */
    private function restrictToHosts(Builder $query, string $pageIdColumn): void
    {
        if ($this->onlyHosts === null) {
            return;
        }
        $hosts = $this->onlyHosts;
        $query->whereExists(function ($q) use ($hosts, $pageIdColumn): void {
            $q->select(DB::raw(1))
                ->from('catalog_pages as hp')
                ->whereColumn('hp.id', $pageIdColumn)
                ->where(function ($inner) use ($hosts): void {
                    $exact = [];
                    foreach ($hosts as $host) {
                        $exact[] = $host;
                        $exact[] = 'www.'.$host;
                    }
                    $inner->whereIn('hp.host', $exact);
                    foreach ($hosts as $host) {
                        $inner->orWhere('hp.host', 'like', '%.'.$host);
                    }
                });
        });
    }

    /** Adres strony leży na jednym z hostów findFor(..., $onlyHosts) — druga zapora poza SQL (host wiersza ≠ host adresu). */
    private function urlOnAllowedHost(string $url): bool
    {
        if ($this->onlyHosts === null) {
            return true;
        }
        $host = ManufacturerSite::normalizeHost((string) (parse_url($url, PHP_URL_HOST) ?? ''));
        if ($host === '') {
            return false;
        }
        foreach ($this->onlyHosts as $allowed) {
            if ($host === $allowed || str_ends_with($host, '.'.$allowed)) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param  array<int, int>  $scores
     * @return list<int>
     */
    private function rankedIds(array $scores): array
    {
        // sortowanie stabilne — przy remisie zostaje kolejność z bazy
        arsort($scores);

        return array_slice(array_keys($scores), 0, self::CANDIDATE_POOL);
    }

    /**
     * Waga tokenu wg rzadkości: kod z jednej strony ≈ 900, token z ≥ 5000 stron = 100.
     *
     * @param  list<string>  $tokens
     * @return array<string, int>
     */
    private function tokenWeights(array $tokens): array
    {
        $out = [];
        foreach ($tokens as $token) {
            $token = (string) $token;
            $df = (int) Cache::remember(
                'catalog_token_df:v1:'.$token,
                now()->addHours(12),
                fn (): int => $this->documentFrequency($token)
            );
            $out[$token] = (int) round(100 * (1 + log((self::DF_CAP + 1) / ($df + 1))));
        }

        return $out;
    }

    /** Na ilu stronach stoi token — liczone najwyżej do DF_CAP, żeby nie skanować milionów wierszy. */
    private function documentFrequency(string $token): int
    {
        return DB::query()
            ->fromSub(
                DB::table('catalog_page_tokens')
                    ->select('catalog_page_id')
                    ->where('token', $token)
                    ->limit(self::DF_CAP),
                'df'
            )
            ->count();
    }

    /**
     * Kandydaci idą partiami w kolejności wyniku, aż uzbiera się MAX_HITS kart
     * po filtrze — wcześniej ucinało na 40 wierszach, zanim filtr cokolwiek przepuścił.
     *
     * @param  list<int>  $ids
     * @return list<array{url: string, title: string, snippet: string}>
     */
    private function pages(array $ids, Product $product): array
    {
        if ($ids === []) {
            return [];
        }

        $brand = $this->brandToken($product);
        $ambiguous = $this->isAmbiguousNumericSku($product);
        $codes = $this->codes($product);
        // rodzaj wyrobu wzięty z kategorii („Obuwie”) nie może odrzucać kart przed pobraniem —
        // adres „…/arya-300-673560-s1-pl” nie zawiera słowa „buty”, a to właściwa karta
        $needType = $this->shortNumericVariantNeedsType($product, $codes)
            && $this->identity->nameRequiresArticleType($product);
        $official = [];
        $withManufacturer = [];
        $withBrand = [];
        $rest = [];
        $accepted = 0;
        foreach (array_chunk($ids, self::SQL_LIMIT) as $chunk) {
            $pages = CatalogPage::query()
                ->whereIn('id', $chunk)
                ->get(['id', 'url', 'title', 'haystack', 'manufacturer'])
                ->keyBy('id');
            foreach ($chunk as $id) {
                $page = $pages->get($id);
                if ($page === null) {
                    continue;
                }
                $url = (string) $page->url;
                if ($url === '' || $this->isExcluded($url, $product) || ! $this->urlOnAllowedHost($url)) {
                    continue;
                }
                $pageManufacturer = $page->manufacturer !== null ? (string) $page->manufacturer : null;
                if ($this->isCatalogNoiseUrl($url)) {
                    $this->reject($url, CandidateRejection::NOISE_URL);

                    continue;
                }
                if ($this->pageManufacturer->conflictsWithProduct($pageManufacturer, $product)
                    && ! $this->inferredBrandMatchesPage($pageManufacturer, $product)
                    && ! $this->goodsBrandMatchesPage($pageManufacturer, $product)) {
                    $this->reject($url, CandidateRejection::MANUFACTURER_CONFLICT);

                    continue;
                }
                $row = [
                    'url' => $url,
                    'title' => (string) ($page->title ?? ''),
                    'snippet' => (string) ($page->haystack ?? ''),
                ];
                // producent nie pisze „buty” w adresie własnej karty — typ sprawdza treść po pobraniu
                if ($needType && ! $this->urlIsOfficialCatalogHost($url, $product)
                    && ! $this->identity->hayHasRequiredTypeFromName(
                        $url.' '.$row['title'].' '.(string) $page->haystack,
                        $product
                    )) {
                    $this->reject($url, CandidateRejection::TYPE_MISSING);

                    continue;
                }
                if ($this->identity->pageClaimsAnotherCode($url, $row['title'], $product)) {
                    $this->reject($url, CandidateRejection::CLAIMS_OTHER_CODE);

                    continue;
                }
                $hay = (string) $page->haystack;
                $matchesManufacturer = $this->pageManufacturer->matchesProduct($pageManufacturer, $product)
                    || $this->inferredBrandMatchesPage($pageManufacturer, $product)
                    || $this->goodsBrandMatchesPage($pageManufacturer, $product);
                $hasBrand = $matchesManufacturer
                    || $this->identity->hayHasBrand($hay, $product)
                    || ($brand !== '' && str_contains($hay, $brand));
                // ICD i producent mają tę samą rodzinę (pros) — karta z domeny marki
                // musi być przed wariantami kolorystycznymi sklepu (limit 8).
                if ($this->urlIsOfficialCatalogHost($url, $product)) {
                    $official[] = $row;
                } elseif ($matchesManufacturer) {
                    $withManufacturer[] = $row;
                } elseif ($hasBrand) {
                    $withBrand[] = $row;
                } elseif (! $ambiguous
                    || $this->identity->urlHasGluedNumericModel($url.' '.$row['title'].' '.$hay, $product)
                    || $this->identity->hayHasDistinctiveNamePhrase($url.' '.$row['title'].' '.$hay, $product)) {
                    $rest[] = $row;
                } else {
                    $this->reject($url, CandidateRejection::WEAK_MATCH);

                    continue;
                }
                $accepted++;
            }
            if ($accepted >= self::MAX_HITS) {
                break;
            }
        }

        return array_slice(array_merge($official, $withManufacturer, $withBrand, $rest), 0, self::MAX_HITS);
    }

    private function reject(string $url, string $reason): void
    {
        $this->rejections[] = ['url' => $url, 'reason' => $reason];
    }

    /** Karta już sprawdzona w poprzedniej partii — także pod adresem w preferowanym języku. */
    private function isExcluded(string $url, Product $product): bool
    {
        if ($this->except === []) {
            return false;
        }

        return isset($this->except[mb_strtolower($url)])
            || isset($this->except[mb_strtolower($this->identity->preferredLocaleUrl($url, $product))]);
    }

    private function inferredBrandMatchesPage(?string $pageManufacturer, Product $product): bool
    {
        $hint = $this->identity->inferredBrandHint($product);
        if ($hint === '' || $pageManufacturer === null || $pageManufacturer === '') {
            return false;
        }
        $pageKey = $this->pageManufacturer->knownKey($pageManufacturer);
        $hintKey = $this->pageManufacturer->knownKey($hint);

        return $pageKey !== null && $hintKey !== null
            && $this->pageManufacturer->familyOf($pageKey) === $this->pageManufacturer->familyOf($hintKey);
    }

    /** Filtr „6051” z modelem „3M” u dystrybutora Canis — karta 3M (icd.pl, shop-sks) to karta tego towaru. */
    private function goodsBrandMatchesPage(?string $pageManufacturer, Product $product): bool
    {
        if ($pageManufacturer === null || $pageManufacturer === '') {
            return false;
        }
        $pageKey = $this->pageManufacturer->knownKey($pageManufacturer);
        if ($pageKey === null) {
            return false;
        }
        foreach ($this->identity->goodsBrandKeys($product) as $brand) {
            $brandKey = $this->pageManufacturer->knownKey($brand);
            if ($brandKey !== null
                && $this->pageManufacturer->familyOf($brandKey) === $this->pageManufacturer->familyOf($pageKey)) {
                return true;
            }
        }

        return false;
    }

    private function isCatalogNoiseUrl(string $url): bool
    {
        $path = mb_strtolower((string) (parse_url($url, PHP_URL_PATH) ?? ''));
        foreach (['/attribute-name/', '/wpfd_file/', '/wpfd-', '/gutschein', '/impressum', '/imprint', '/kontakt'] as $bad) {
            if (str_contains($path, $bad)) {
                return true;
            }
        }

        return false;
    }

    private function urlIsOfficialCatalogHost(string $url, Product $product): bool
    {
        return $this->identity->isOfficialCatalogUrl($url, $product);
    }

    /**
     * Stary indeks ma „ultraneo” + „420”, a kod z karty to „ultraneo420”.
     *
     * @param  list<string>  $codes
     * @return array<int, string> id strony → kod, który ją znalazł
     */
    private function splitModelTokenPageIds(array $codes): array
    {
        $ids = [];
        foreach ($codes as $code) {
            if (preg_match('/^([a-z]{3,12})(\d{2,4})$/u', $code, $m) !== 1) {
                continue;
            }
            $word = $m[1];
            $num = $m[2];
            $query = DB::table('catalog_page_tokens as w')
                ->where('w.token', $word)
                ->whereExists(function ($q) use ($num): void {
                    $q->select(DB::raw(1))
                        ->from('catalog_page_tokens as n')
                        ->whereColumn('n.catalog_page_id', 'w.catalog_page_id')
                        ->where('n.token', $num);
                });
            $this->restrictToHosts($query, 'w.catalog_page_id');
            $found = $query->limit(self::SQL_LIMIT)->pluck('w.catalog_page_id');
            foreach ($found as $id) {
                $ids[(int) $id] ??= $code;
            }
        }

        return $ids;
    }

    /**
     * Skrócony kod z cennika (CBF0100, CDR0400, AF0100) stoi w indeksie strony producenta tylko w pełnej postaci z rozmiarem
     * (CBF010004 w tabeli części, dopisanej do tytułu przy indeksowaniu). Ten sam warunek co przy potwierdzaniu karty
     * (ProductSearchIdentity::officialFamilyPageListsSizeCodes): dopisek to cyfry rozmiaru, najwyżej z „C”/„C5”, i tylko
     * strona z domeny producenta — sklep z tą samą tabelą się nie liczy (27.09.2026: wyszukiwarka dawała dla CBF0100
     * strony przewodów i środków czyszczących z icd.pl).
     *
     * @return array<int, string> id strony → kod karty, który ją znalazł
     */
    private function familySizeCodePageIds(Product $product): array
    {
        $sku = preg_replace('/[^a-z0-9]+/u', '', mb_strtolower(trim((string) $product->sku))) ?? '';
        if (mb_strlen($sku) < 5 || preg_match('/^[a-z]{2,4}\d{3,}$/u', $sku) !== 1) {
            return [];
        }
        $query = DB::table('catalog_page_tokens as t')
            ->join('catalog_pages as p', 'p.id', '=', 't.catalog_page_id')
            ->where('t.token', 'like', $sku.'%')
            ->whereRaw('LENGTH(t.token) > ?', [strlen($sku)]);
        $this->restrictToHosts($query, 't.catalog_page_id');
        $rows = $query->select(['t.catalog_page_id', 't.token', 'p.url'])
            ->limit(self::SQL_LIMIT * 3)
            ->get();
        $ids = [];
        foreach ($rows as $row) {
            $suffix = substr((string) $row->token, strlen($sku));
            if (preg_match('/^\d{1,4}[a-z]{0,2}\d?$/u', $suffix) === 1
                && $this->identity->isOfficialCatalogUrl((string) $row->url, $product)) {
                $ids[(int) $row->catalog_page_id] ??= $sku;
            }
        }

        return $ids;
    }

    /**
     * Sklep skleja model z id karty (902 → 9022002). LIKE zamiast REGEXP — działa też na sqlite.
     *
     * @param  list<string>  $codes
     * @param  list<string>  $typePrefixes
     * @return array<int, string> id strony → kod, który ją znalazł
     */
    private function gluedNumericTokenPageIds(array $codes, array $typePrefixes): array
    {
        $ids = [];
        foreach ($codes as $code) {
            if (preg_match('/^\d{3,4}$/u', $code) !== 1) {
                continue;
            }
            $query = DB::table('catalog_page_tokens as t')
                ->where('t.token', 'like', $code.'%')
                ->whereRaw('LENGTH(t.token) >= ?', [strlen($code) + 3]);
            if ($typePrefixes !== []) {
                $this->requireTypeToken($query, $typePrefixes);
            }
            $this->restrictToHosts($query, 't.catalog_page_id');
            foreach ($query->select(['t.catalog_page_id', 't.token'])->limit(self::SQL_LIMIT * 3)->get() as $row) {
                $token = (string) $row->token;
                if (ctype_digit($token) && str_starts_with($token, $code)
                    && strlen($token) >= strlen($code) + 3) {
                    $ids[(int) $row->catalog_page_id] ??= $code;
                }
            }
        }

        return $ids;
    }

    /**
     * Słowa frazy sklepowej, które mogą stać w adresie jako osobne tokeny: „passthrough”,
     * „whistle” — bez marki i linii („alphatec”), bez krótkich członów („96ks”).
     *
     * @return list<string>
     */
    private function phraseWordTokens(string $phrase, string $brand, Product $product): array
    {
        $words = preg_split('/\s+/u', trim($phrase)) ?: [];
        if (count($words) < 2) {
            return [];
        }

        $out = [];
        foreach ($words as $word) {
            // „Pass-through” w adresie to „passthrough”; „wkładki” — „wkladki”
            $compact = preg_replace('/[^a-z0-9]+/u', '', mb_strtolower(Str::ascii($word))) ?? '';
            if (mb_strlen($compact) < 5 || mb_strlen($compact) > 64) {
                continue;
            }
            // „150cm” to token kazdej maty, sznurowki i pasa — nie odroznia niczego
            if ($this->identity->isBareMeasurement($compact)) {
                continue;
            }
            // marka i jej linie (Ansell, AlphaTec) stoją w adresie każdej karty producenta
            if ($brand !== '' && $this->identity->hayHasBrand($compact, $product)) {
                continue;
            }
            // Slabosc sprawdzilismy dla calej frazy; pojedyncze „pass-through” czy
            // „house” sa slabe jako fraza, ale jako token adresu to wlasnie one
            // odrozniaja karte. Pospolite slowa tnie DF_CAP, reszte filtr tozsamosci.
            $out[] = $compact;
        }

        return $out;
    }

    /** Człon SKU na tyle długi, że nie jest rozmiarem ani klasą („673560”, „ye30t”, nie „s1”, „300”). */
    private function isDistinctiveSkuSegment(string $part): bool
    {
        $len = mb_strlen($part);
        if (preg_match('/^\d+$/u', $part) === 1) {
            return $len >= 5 && $len <= 14;
        }

        return $len >= 5 && $len <= 32
            && preg_match('/[a-z]/u', $part) === 1
            && preg_match('/\d/u', $part) === 1;
    }

    private function isAmbiguousNumericSku(Product $product): bool
    {
        $sku = mb_strtolower(trim((string) $product->sku));

        return preg_match('/^\d{3,4}$/', $sku) === 1;
    }

    /**
     * Krótki numer (300, 109/O → 109) bez typu z nazwy trafia w znaki BC109
     * i ginie w limicie SQL wśród tysięcy tokenów.
     *
     * @param  list<string>  $codes
     */
    private function shortNumericVariantNeedsType(Product $product, array $codes): bool
    {
        foreach ($codes as $code) {
            if (preg_match('/^\d{3,4}$/u', $code) === 1) {
                return true;
            }
        }

        return false;
    }

    private function brandToken(Product $product): string
    {
        $brand = mb_strtolower($this->identity->shortBrand((string) $product->manufacturer));
        $brand = preg_replace('/[^a-z0-9]+/u', ' ', $brand) ?? $brand;
        $first = trim(explode(' ', trim($brand))[0] ?? '');

        return mb_strlen($first) >= 3 || $first === '3m' ? $first : '';
    }

    /**
     * @return list<string>
     */
    private function nameTokens(Product $product): array
    {
        $out = [];
        foreach ($this->identity->nameWords($product) as $word) {
            // w adresach stron „wkładki” występuje jako „wkladki”
            $out[] = mb_strtolower(Str::ascii($word));
        }
        foreach ($this->identity->catalogTypeTokenPrefixes($product) as $prefix) {
            $prefix = mb_strtolower(Str::ascii($prefix));
            if ($prefix !== '' && ! str_contains($prefix, ' ')) {
                $out[] = $prefix;
            }
        }
        foreach (preg_split('/[^a-z0-9]+/u', mb_strtolower((string) $product->name)) ?: [] as $word) {
            // liczby z nazwy („SECAIR 2000”) są mocnym sygnałem
            if (preg_match('/^\d{3,}$/u', $word) === 1) {
                $out[] = $word;
            }
        }

        return array_values(array_unique(array_slice(array_filter($out), 0, 6)));
    }

    /**
     * @param  list<array{url: string, title: string, snippet: string}>  $first
     * @param  list<array{url: string, title: string, snippet: string}>  $second
     * @return list<array{url: string, title: string, snippet: string}>
     */
    private function mergeHits(array $first, array $second): array
    {
        $seen = [];
        $out = [];
        foreach (array_merge($first, $second) as $row) {
            $url = mb_strtolower((string) ($row['url'] ?? ''));
            if ($url === '' || isset($seen[$url])) {
                continue;
            }
            $seen[$url] = true;
            $out[] = $row;
            if (count($out) >= self::MAX_HITS) {
                break;
            }
        }

        return $out;
    }
}
