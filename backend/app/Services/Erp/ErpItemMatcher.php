<?php

declare(strict_types=1);

namespace App\Services\Erp;

use App\Models\ErpItem;
use App\Models\ErpItemLink;
use App\Models\ProductIdentifier;
use App\Support\BrandDictionary;
use App\Support\BrandKey;
use App\Support\CanonicalBrand;
use App\Support\PpeAssortment;
use App\Support\ProductIdentifierCode;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Towar ERP XL → karta po kodzie producenta. W XL nie ma wspólnego klucza: kod producenta stoi w środku nazwy
 * („OKULARY UVEX 9174.065”), w Nazwa1 albo na końcu kodu towaru po przedrostku („SOK9301145”). Kod porównujemy
 * postacią ProductIdentifierCode::code() z kodami kart (product_identifiers bez EAN, products.sku, product_variants.sku).
 *
 * Sam kod nie wystarcza (analiza 29.09.2026 na produkcji: 8% trafień bez żadnego dowodu to w większości pomyłki —
 * „TRZEWIKI OAKLAND 7770” → rękawice TEGERA 12.7770). Automat łączy tylko jedną kartę z dowodem: dostawca z karty
 * towaru/PZ jest producentem karty, marka karty stoi w nazwie XL albo nazwy mają wspólne słowo. Krótka sama liczba
 * wymaga dostawcy albo marki; inna znana marka w nazwie XL bez dowodu marki karty → do sprawdzenia; inny rodzaj wyrobu
 * (PpeAssortment::family) wyklucza kartę. Reszta → „suggested” dla człowieka.
 *
 * Powiązania confirmed/rejected zostają nietknięte: towar z potwierdzonym powiązaniem pomijamy, odrzucona para nie wraca.
 */
final class ErpItemMatcher
{
    public const RULES_VERSION = 1;

    /** Kolejność źródeł kodu: pierwsze źródło z trafieniem wyznacza kandydatów. */
    private const TIERS = ['name1:token', 'name:token', 'xl_code:suffix', 'name1:pair', 'name:pair'];

    /** Najwięcej kart zapisanych jako propozycje jednego towaru. */
    private const MAX_SUGGESTIONS = 10;

    /** Numer normy (EN 388, DIN 13164) to nie kod wyrobu — „APTECZKA DIN 13164” trafiała we wkład do apteczki. */
    private const NORM = '/^(EN|ISO|PN|DIN)\d/';

    /** Ilość z jednostką („600ml”, „250M”, „100X100”) to nie kod. */
    private const MEASURE = '/^\d+([.,]\d+)?(ML|L|M|MM|CM|KG|G|SZT|PAR|MB|V|W|A|DB|X\d+.*)$/i';

    /** Słowa rodzaju, koloru i opisu — wspólne dla różnych wyrobów, nie są dowodem tożsamości. */
    private const GENERIC_WORDS = [
        'REKAWICE', 'BUTY', 'POLBUTY', 'TRZEWIKI', 'SANDALY', 'OKULARY', 'GOGLE', 'KURTKA', 'SPODNIE', 'KOMBINEZON',
        'BLUZA', 'OCHR', 'OCHRONNE', 'OCHRONNY', 'OCHRONNA', 'ROB', 'ROBOCZE', 'MESKIE', 'DAMSKIE', 'CZARNE', 'CZARNY',
        'BIALE', 'BIALY', 'ZOLTE', 'ZOLTY', 'SZARE', 'SZARY', 'NIEBIESKIE', 'NIEBIESKI', 'ZIELONE', 'ZIELONY',
        'GRANATOWY', 'CZERWONY', 'POMARANCZOWY', 'KOLOR', 'ROZMIAR', 'ZESTAW', 'ZATYCZKI', 'NAUSZNIKI', 'MASKA',
        'POLMASKA', 'FILTR', 'HELM', 'KASK', 'OSLONA', 'TWARZY', 'KAMIZELKA', 'KOSZULKA', 'CZAPKA', 'NITRYL',
        'NITRYLOWE', 'SKORA', 'SKORZANE', 'OCIEPLANE', 'OCIEPL', 'ZIMOWE', 'LETNIE', 'SZT', 'PAR', 'KARTON', 'OPK',
        'DLA', 'BEZ', 'WYSOKIE', 'NISKIE', 'OSTRZEG', 'OSTRZEGAWCZA', 'OSTRZEGAWCZE', 'POLAR', 'POLAROWA',
        'SOFTSHELL', 'SPAWAL', 'SPAWALNICZE', 'ESD', 'SRC', 'PLUS', 'PRO', 'LOW', 'HIGH', 'MID', 'NEW', 'TYP',
        'MODEL', 'ART', 'KOD', 'OBUWIE', 'BEZPIECZNE', 'ANTYSTATYCZNE', 'TRUDNOPALNE', 'WODOODPORNE', 'JEDNORAZOWE',
        'NYLON', 'POLIESTER', 'BAWELNA', 'KAPTUREM', 'KAPTUR', 'WKLADKI', 'WKLADKA', 'PODESZWA', 'METAL', 'FREE',
        // przegląd raportu na danych z 29.09.2026: rodzaj, materiał i kolor łączyły różne wyroby
        'SZYBKA', 'LINA', 'LINKA', 'UBRANIE', 'BEZBARWNE', 'BEZBARWNY', 'BEZB', 'DIN', 'OCIEPLACZ', 'FARTUCH', 'PCV',
        'POLO', 'APTECZKA', 'TARCZA', 'KALOSZE', 'PLASZCZ', 'SKARPETY', 'PASEK', 'KOSZULA', 'SHIRT', 'ZAREKAWEK',
        'NAKOLANNIKI', 'SZELKI', 'AMORTYZATOR', 'ZATRZASNIK', 'URZADZENIE', 'CUT', 'SCIAGACZEM', 'VIS', 'ODBLASKOWA',
    ];

    /** @var array<string, list<int>>|null kod → karty z products.sku i product_variants.sku */
    private ?array $skuIndex = null;

    /** @var array<string, string> słowo → klucz marki producenta ('' = nie marka) */
    private array $wordBrand = [];

    /** @var array<string, true>|null klucze marek: producenci kart (pierwsze słowo) i marki ze słownika */
    private ?array $knownBrands = null;

    public function __construct(
        private readonly PpeAssortment $assortment,
        private readonly BrandDictionary $brands,
    ) {}

    /**
     * @param  (callable(array<string, mixed>): void)|null  $report  wiersz raportu na każdy towar
     * @return array<string, int>
     */
    public function refresh(?callable $report = null): array
    {
        $startedAt = CarbonImmutable::now();
        $stats = ['items' => 0, 'auto' => 0, 'suggested' => 0, 'ambiguous' => 0, 'family_conflict' => 0,
            'no_code' => 0, 'no_match' => 0, 'confirmed_kept' => 0, 'removed_links' => 0];
        $this->skuIndex = null;
        $this->wordBrand = [];
        $this->knownBrands = null;

        ErpItem::query()
            ->whereNull('removed_at')
            ->where('archived', false)
            ->select(['id', 'xl_gid', 'code', 'name', 'name1', 'suppliers', 'stock_trade', 'last_sale_at'])
            ->chunkById(500, function (Collection $items) use (&$stats, $report, $startedAt): void {
                $this->matchBatch($items, $stats, $report, $startedAt);
            });

        // auto/suggested, których reguły już nie wskazały (także towary zarchiwizowane i usunięte z XL)
        $stats['removed_links'] = ErpItemLink::query()
            ->whereIn('status', [ErpItemLink::STATUS_AUTO, ErpItemLink::STATUS_SUGGESTED])
            ->where(fn ($q) => $q->whereNull('last_seen_at')->orWhere('last_seen_at', '<', $startedAt))
            ->delete();
        $this->skuIndex = null;

        return $stats;
    }

    /**
     * @param  Collection<int, ErpItem>  $items
     * @param  array<string, int>  $stats
     */
    private function matchBatch(Collection $items, array &$stats, ?callable $report, CarbonImmutable $now): void
    {
        $itemIds = $items->pluck('id')->all();
        $decided = ErpItemLink::query()
            ->whereIn('erp_item_id', $itemIds)
            ->whereIn('status', [ErpItemLink::STATUS_CONFIRMED, ErpItemLink::STATUS_REJECTED])
            ->get(['erp_item_id', 'product_id', 'status']);
        // potwierdzenie bez karty (karta usunięta) nie blokuje automatu — towar szuka karty od nowa
        $confirmedItems = $decided->where('status', ErpItemLink::STATUS_CONFIRMED)->whereNotNull('product_id')->pluck('erp_item_id')->flip();
        $rejected = [];
        foreach ($decided->where('status', ErpItemLink::STATUS_REJECTED) as $link) {
            $rejected[$link->erp_item_id][(int) $link->product_id] = true;
        }
        $purchaseSuppliers = [];
        foreach (DB::table('erp_item_purchases')->whereIn('erp_item_id', $itemIds)->whereNotNull('supplier')
            ->distinct()->get(['erp_item_id', 'supplier']) as $row) {
            $purchaseSuppliers[$row->erp_item_id][] = (string) $row->supplier;
        }

        $candidates = [];
        $codes = [];
        foreach ($items as $item) {
            $candidates[$item->id] = $this->extract($item);
            foreach ($candidates[$item->id] as $c) {
                $codes[$c['code']] = true;
            }
        }
        $byCode = $this->lookup(array_keys($codes));
        $productIds = [];
        foreach ($byCode as $ids) {
            foreach ($ids as $id) {
                $productIds[$id] = true;
            }
        }
        $products = $productIds === [] ? collect() : DB::table('products')
            ->whereIn('id', array_keys($productIds))
            ->get(['id', 'sku', 'name', 'manufacturer'])
            ->keyBy('id');

        foreach ($items as $item) {
            $stats['items']++;
            if ($confirmedItems->has($item->id)) {
                $stats['confirmed_kept']++;

                continue;
            }
            $suppliers = array_values(array_unique(array_filter([
                ...array_map(static fn (array $s): string => (string) ($s['supplier'] ?? ''), $item->suppliers ?? []),
                ...($purchaseSuppliers[$item->id] ?? []),
            ])));
            $decision = $this->decide($item, $candidates[$item->id], $byCode, $products, $suppliers, $rejected[$item->id] ?? []);
            $stats[$decision['outcome']]++;
            foreach ($decision['links'] as $link) {
                $this->saveLink($item->id, $link, $now);
            }
            if ($report !== null) {
                $report($this->reportRow($item, $decision, $products, $suppliers));
            }
        }
    }

    /**
     * Kody z nazwy, Nazwa1 i końcówki kodu XL.
     *
     * @return list<array{code: string, value: string, method: string, tier: string}>
     */
    public function extract(ErpItem $item): array
    {
        $out = [];
        foreach (['name1' => (string) $item->name1, 'name' => (string) $item->name] as $field => $text) {
            foreach ($this->tokens($text) as [$code, $value, $kind]) {
                $out[] = ['code' => $code, 'value' => $value, 'method' => $field, 'tier' => $field.':'.$kind];
            }
        }
        if (preg_match('/^\p{L}+(\d[\p{L}\d.\/-]*)$/u', (string) $item->code, $m) === 1) {
            $code = ProductIdentifierCode::code($m[1]);
            if ($code !== null && strlen($code) >= 5) {
                $out[] = ['code' => $code, 'value' => $m[1], 'method' => ErpItemLink::METHOD_XL_CODE, 'tier' => 'xl_code:suffix'];
            }
        }

        return $out;
    }

    /**
     * @return list<array{0: string, 1: string, 2: string}> [kod, wartość dosłownie, token|pair]
     */
    private function tokens(string $text): array
    {
        $raw = array_values(array_filter(preg_split('/[\s,;()]+/u', $text) ?: [], static fn (string $t): bool => $t !== ''));
        $out = [];
        foreach ($raw as $i => $token) {
            $clean = trim($token, ".'\"-/");
            $code = ProductIdentifierCode::code($clean);
            if ($code !== null && strlen($code) >= 4 && preg_match('/\d/', $code) === 1
                && preg_match(self::MEASURE, $clean) !== 1 && preg_match(self::NORM, $code) !== 1) {
                $out[] = [$code, $clean, 'token'];
            }
            // para sąsiednich słów („AZ 410”, „BASIC 5”) — druga część musi mieć cyfrę
            if (isset($raw[$i + 1])) {
                $pair = ProductIdentifierCode::code($token.$raw[$i + 1]);
                $next = ProductIdentifierCode::code($raw[$i + 1]);
                if ($pair !== null && strlen($pair) >= 6 && $next !== null && preg_match('/\d/', $next) === 1
                    && preg_match(self::NORM, $pair) !== 1) {
                    $out[] = [$pair, $token.' '.$raw[$i + 1], 'pair'];
                }
            }
        }

        return $out;
    }

    /**
     * @param  list<string>  $codes
     * @return array<string, list<int>>
     */
    private function lookup(array $codes): array
    {
        $index = $this->skuIndex();
        $out = [];
        foreach ($codes as $code) {
            if (isset($index[$code])) {
                $out[$code] = $index[$code];
            }
        }
        foreach (array_chunk($codes, 500) as $chunk) {
            $rows = DB::table('product_identifiers')
                ->whereIn('normalized', $chunk)
                ->whereNotIn('type', [ProductIdentifier::TYPE_EAN, ProductIdentifier::TYPE_PACK_EAN])
                ->whereNull('removed_at')
                ->whereNotNull('product_id')
                ->distinct()
                ->get(['normalized', 'product_id']);
            foreach ($rows as $row) {
                $out[(string) $row->normalized][] = (int) $row->product_id;
            }
        }
        foreach ($out as $code => $ids) {
            $out[$code] = array_values(array_unique($ids));
        }

        return $out;
    }

    /**
     * products.sku i product_variants.sku nie zawsze są w product_identifiers (karty ręczne, rozmiary) — indeks
     * w pamięci budowany strumieniowo raz na przebieg.
     *
     * @return array<string, list<int>>
     */
    private function skuIndex(): array
    {
        if ($this->skuIndex !== null) {
            return $this->skuIndex;
        }
        $index = [];
        foreach (DB::table('products')->select(['id', 'sku'])->whereNotNull('sku')->lazyById(5000) as $row) {
            $code = ProductIdentifierCode::code((string) $row->sku);
            if ($code !== null) {
                $index[$code][] = (int) $row->id;
            }
        }
        foreach (DB::table('product_variants')->select(['id', 'product_id', 'sku'])->whereNotNull('sku')
            ->whereNull('removed_at')->lazyById(5000) as $row) {
            $code = ProductIdentifierCode::code((string) $row->sku);
            if ($code !== null && (! isset($index[$code]) || ! in_array((int) $row->product_id, $index[$code], true))) {
                $index[$code][] = (int) $row->product_id;
            }
        }

        return $this->skuIndex = $index;
    }

    /**
     * @param  list<array{code: string, value: string, method: string, tier: string}>  $candidates
     * @param  array<string, list<int>>  $byCode
     * @param  Collection<int, object>  $products
     * @param  list<string>  $suppliers
     * @param  array<int, true>  $rejected
     * @return array{outcome: string, links: list<array<string, mixed>>, hit: array<string, string>|null, cards: list<array<string, mixed>>}
     */
    private function decide(ErpItem $item, array $candidates, array $byCode, Collection $products, array $suppliers, array $rejected): array
    {
        if ($candidates === []) {
            return ['outcome' => 'no_code', 'links' => [], 'hit' => null, 'cards' => []];
        }
        $hit = null;
        foreach (self::TIERS as $tier) {
            foreach ($candidates as $c) {
                if ($c['tier'] === $tier && ($byCode[$c['code']] ?? []) !== []) {
                    $hit = $c;
                    break 2;
                }
            }
        }
        if ($hit === null) {
            return ['outcome' => 'no_match', 'links' => [], 'hit' => null, 'cards' => []];
        }

        $xlText = trim($item->name.' '.$item->name1);
        $xlFamily = $this->assortment->family($xlText);
        $xlWords = $this->words($xlText);
        $xlBrandTokens = $this->brandTokens($xlText);
        $cards = [];
        $familyConflicts = [];
        foreach ($byCode[$hit['code']] as $productId) {
            $product = $products->get($productId);
            if ($product === null || isset($rejected[$productId])) {
                continue;
            }
            $cardFamily = $this->assortment->family((string) $product->name);
            if ($xlFamily !== null && $cardFamily !== null && $xlFamily !== $cardFamily) {
                $familyConflicts[] = ['product_id' => (int) $product->id, 'supplier_match' => false, 'brand_in_name' => false,
                    'other_brand' => null, 'shared_words' => [], 'weak_code' => false, 'family' => $cardFamily.' ≠ '.$xlFamily];

                continue;
            }
            $cards[] = $this->evidence($product, $xlWords, $xlBrandTokens, $suppliers, $hit);
        }
        if ($cards === []) {
            // karta odrzucona za rodzaj zostaje w raporcie do przeglądu, bez powiązania
            return ['outcome' => $familyConflicts !== [] ? 'family_conflict' : 'no_match', 'links' => [], 'hit' => $hit, 'cards' => $familyConflicts];
        }

        $total = count($cards);
        if ($total > 1) {
            $narrow = array_values(array_filter($cards, static fn (array $e): bool => $e['supplier_match'] || $e['brand_in_name']));
            if (count($narrow) === 1) {
                $cards = $narrow;
            }
        }
        if (count($cards) > 1) {
            $links = array_map(fn (array $e): array => $this->link(ErpItemLink::STATUS_SUGGESTED, $hit, $e + ['candidates' => $total]),
                array_slice($cards, 0, self::MAX_SUGGESTIONS));

            return ['outcome' => 'ambiguous', 'links' => $links, 'hit' => $hit, 'cards' => $cards];
        }

        $e = $cards[0] + ['candidates' => $total];
        $brandProof = $e['supplier_match'] || $e['brand_in_name'];
        $auto = ($brandProof || $e['shared_words'] !== [])
            && (! $e['weak_code'] || $brandProof)
            && ! ($e['other_brand'] !== null && ! $brandProof);
        $status = $auto ? ErpItemLink::STATUS_AUTO : ErpItemLink::STATUS_SUGGESTED;

        return ['outcome' => $auto ? 'auto' : 'suggested', 'links' => [$this->link($status, $hit, $e)], 'hit' => $hit, 'cards' => [$e]];
    }

    /**
     * @param  list<string>  $xlWords
     * @param  list<string>  $xlBrandTokens
     * @param  list<string>  $suppliers
     * @param  array{code: string, value: string, method: string, tier: string}  $hit
     * @return array<string, mixed>
     */
    private function evidence(object $product, array $xlWords, array $xlBrandTokens, array $suppliers, array $hit): array
    {
        $manufacturer = (string) ($product->manufacturer ?? '');
        $cardBrand = CanonicalBrand::key($manufacturer);
        $matchedSupplier = null;
        foreach ($suppliers as $supplier) {
            if ($cardBrand !== '' && CanonicalBrand::key($supplier) === $cardBrand) {
                $matchedSupplier = $supplier;
                break;
            }
        }
        $brandInName = false;
        $otherBrand = null;
        foreach ($xlBrandTokens as $word) {
            $brand = $this->wordBrand($word);
            if ($brand === '') {
                continue;
            }
            if ($brand === $cardBrand) {
                $brandInName = true;
            } elseif ($otherBrand === null) {
                $otherBrand = $word;
            }
        }
        $cardWords = $this->words((string) $product->name.' '.$manufacturer);
        $shared = array_values(array_diff(array_intersect($xlWords, $cardWords), self::GENERIC_WORDS));

        return [
            'product_id' => (int) $product->id,
            'supplier_match' => $matchedSupplier !== null,
            'supplier' => $matchedSupplier,
            'brand_in_name' => $brandInName,
            'other_brand' => $brandInName ? null : $otherBrand,
            'shared_words' => array_slice($shared, 0, 5),
            'weak_code' => preg_match('/^\d{1,5}$/', $hit['code']) === 1,
        ];
    }

    /** Klucz marki producenta, gdy słowo jest znaną marką katalogu (słownik marek); '' gdy nie. */
    private function wordBrand(string $word): string
    {
        if (! array_key_exists($word, $this->wordBrand)) {
            $key = BrandDictionary::key($word);
            $known = $key !== '' && isset($this->knownBrands()[$key]) && ! isset($this->brands->suppressed()[$key]);
            $this->wordBrand[$word] = $known ? CanonicalBrand::key($word) : '';
        }

        return $this->wordBrand[$word];
    }

    /** @return array<string, true> */
    private function knownBrands(): array
    {
        if ($this->knownBrands !== null) {
            return $this->knownBrands;
        }
        $generic = array_flip(array_map('strtolower', self::GENERIC_WORDS));
        $known = $this->brands->detectable();
        foreach (DB::table('products')->whereNotNull('manufacturer')->distinct()->pluck('manufacturer') as $manufacturer) {
            $key = BrandKey::of((string) $manufacturer);
            if ((strlen($key) >= 3 || preg_match('/\d/', $key) === 1) && strlen($key) >= 2 && ! isset($generic[$key])) {
                $known[$key] = true;
            }
        }

        return $this->knownBrands = $known;
    }

    /** @return list<string> słowa, które mogą być marką: litery z cyframi („3M”, „P4S”) albo same litery (≥ 3) */
    private function brandTokens(string $text): array
    {
        preg_match_all('/[A-Z0-9]+/', strtoupper(Str::ascii($text)), $m);

        return array_values(array_unique(array_filter($m[0], static fn (string $t): bool => preg_match('/[A-Z]/', $t) === 1
            && (strlen($t) >= 3 || (strlen($t) === 2 && preg_match('/\d/', $t) === 1)))));
    }

    /** @return list<string> słowa z samych liter (≥ 3), wielkimi literami bez polskich znaków */
    private function words(string $text): array
    {
        preg_match_all('/[A-Z]{3,}/', strtoupper(Str::ascii($text)), $m);

        return array_values(array_unique($m[0]));
    }

    /**
     * @param  array{code: string, value: string, method: string, tier: string}  $hit
     * @param  array<string, mixed>  $evidence
     * @return array<string, mixed>
     */
    private function link(string $status, array $hit, array $evidence): array
    {
        $productId = $evidence['product_id'];
        unset($evidence['product_id']);

        return [
            'product_id' => $productId,
            'status' => $status,
            'method' => $hit['method'],
            'matched_value' => mb_substr($hit['value'], 0, 150),
            'matched_code' => substr($hit['code'], 0, 100),
            'evidence' => $evidence + ['tier' => $hit['tier'], 'rules' => self::RULES_VERSION],
        ];
    }

    /** @param  array<string, mixed>  $link */
    private function saveLink(int $itemId, array $link, CarbonImmutable $now): void
    {
        $existing = ErpItemLink::query()
            ->where('erp_item_id', $itemId)
            ->where('product_id', $link['product_id'])
            ->first();
        if ($existing !== null && in_array($existing->status, [ErpItemLink::STATUS_CONFIRMED, ErpItemLink::STATUS_REJECTED], true)) {
            return;
        }
        $attributes = $link + ['last_seen_at' => $now];
        if ($existing !== null) {
            $existing->fill($attributes)->save();

            return;
        }
        ErpItemLink::query()->create(['erp_item_id' => $itemId] + $attributes);
    }

    /**
     * @param  array{outcome: string, links: list<array<string, mixed>>, hit: array<string, string>|null, cards: list<array<string, mixed>>}  $decision
     * @param  Collection<int, object>  $products
     * @param  list<string>  $suppliers
     * @return array<string, mixed>
     */
    private function reportRow(ErpItem $item, array $decision, Collection $products, array $suppliers): array
    {
        $first = $decision['cards'][0] ?? null;
        $product = $first !== null ? $products->get($first['product_id']) : null;

        return [
            'xl_gid' => $item->xl_gid,
            'xl_code' => $item->code,
            'xl_name' => $item->name,
            'xl_name1' => (string) $item->name1,
            'stock_trade' => (string) $item->stock_trade,
            'last_sale_at' => $item->last_sale_at?->toDateString() ?? '',
            'suppliers' => implode(', ', array_slice($suppliers, 0, 4)),
            'outcome' => $decision['outcome'],
            'matched_value' => $decision['hit']['value'] ?? '',
            'source' => $decision['hit']['tier'] ?? '',
            'cards' => count($decision['cards']),
            'product_id' => $product->id ?? '',
            'product_sku' => $product->sku ?? '',
            'product_manufacturer' => $product->manufacturer ?? '',
            'product_name' => $product->name ?? '',
            'supplier_match' => $first !== null && $first['supplier_match'] ? 'tak' : '',
            'brand_in_name' => $first !== null && $first['brand_in_name'] ? 'tak' : '',
            'shared_words' => $first !== null ? implode(' ', $first['shared_words']) : '',
            'other_brand' => $first['other_brand'] ?? '',
            'family' => $first['family'] ?? '',
        ];
    }
}
