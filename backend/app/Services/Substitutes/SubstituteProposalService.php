<?php

declare(strict_types=1);

namespace App\Services\Substitutes;

use App\Models\CardMatchCandidate;
use App\Models\ErpItemLink;
use App\Models\Product;
use App\Models\ProductIdentifier;
use App\Models\ProductSubstitute;
use App\Support\CanonicalBrand;
use App\Support\ProductSizeVariant;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Propozycje zamienników z katalogu (polecenie substitutes:propose): karty główne największych producentów w rodzinach
 * objętych automatem, do każdej kilka kart innych producentów, które przechodzą SubstituteMatcher.
 *
 * Zapis nigdy nie rusza decyzji człowieka: wiersze ręczne zostają, zatwierdzone tylko dostają świeże dowody (albo znak
 * „automat już nie potwierdza”), odrzucona para — w którąkolwiek stronę — nie wraca. Usuwane są wyłącznie własne
 * propozycje automatu w statusie „oczekuje”, których reguły już nie przepuszczają.
 */
final class SubstituteProposalService
{
    /** Ile najlepszych kandydatów (po sicie) porównujemy w pełni na jedną kartę główną. */
    private const FULL_COMPARE_LIMIT = 60;

    /** Identyfikatory, które wskazują ten sam wyrób (kod dostawcy bywa wspólny dla różnych wyrobów — pomijamy). */
    private const SAME_PRODUCT_IDENTIFIERS = [
        ProductIdentifier::TYPE_EAN,
        ProductIdentifier::TYPE_PACK_EAN,
        ProductIdentifier::TYPE_MANUFACTURER_CODE,
        ProductIdentifier::TYPE_MODEL_CODE,
    ];

    /** @var array<int, SubstituteProfile> */
    private array $profiles = [];

    /** @var array<int, array{manufacturer: string, name: string, eligible: ?string, erp: int, model: string}> */
    private array $meta = [];

    /** @var array<string, list<int>> rodzina|rodzaj wyrobu → karty */
    private array $byType = [];

    public function __construct(
        private readonly SubstituteProfiler $profiler,
        private readonly SubstituteMatcher $matcher,
        private readonly ProductSizeVariant $sizes,
    ) {}

    /**
     * Plan propozycji bez zapisu.
     *
     * @param  array{manufacturers: int, per_manufacturer: int, per_main: int, families: list<string>, only: list<string>, max_pairs?: int}  $options
     * @return array{brands: list<array{key: string, name: string, cards: int}>, mains: list<array<string, mixed>>, rejections: array<string, int>, profiles: int}
     */
    public function plan(array $options, ?callable $progress = null): array
    {
        $families = $options['families'] !== [] ? $options['families'] : SubstituteProfiler::FAMILIES;
        $this->loadProfiles($families);
        $brands = $this->topBrands($options['manufacturers'], $options['only']);

        $mains = [];
        $rejections = [];
        $pairs = 0;
        $maxPairs = (int) ($options['max_pairs'] ?? 0);
        foreach ($brands as $brand) {
            if ($maxPairs > 0 && $pairs >= $maxPairs) {
                break;
            }
            $kept = 0;
            $tried = 0;
            $keptIds = [];
            foreach ($this->mainCandidates($brand['key']) as $mainId) {
                if ($kept >= $options['per_manufacturer'] || $tried >= $options['per_manufacturer'] * 6
                    || ($maxPairs > 0 && $pairs >= $maxPairs)) {
                    break;
                }
                if ($this->duplicatesKeptMain($mainId, $keptIds)) {
                    continue;
                }
                $tried++;
                $picked = $this->substitutesFor($mainId, $options['per_main'], $rejections);
                if ($picked === []) {
                    continue;
                }
                if ($maxPairs > 0) {
                    $picked = array_slice($picked, 0, $maxPairs - $pairs);
                }
                $pairs += count($picked);
                $kept++;
                $keptIds[] = $mainId;
                $mains[] = ['main_id' => $mainId, 'brand' => $brand['name'], 'picks' => $picked];
                if ($progress !== null) {
                    $progress($brand['name'], $this->meta[$mainId]['name'], count($picked));
                }
            }
        }
        arsort($rejections);

        return [
            'brands' => $brands,
            'mains' => $mains,
            'rejections' => $rejections,
            'skipped_mains' => $this->skippedMains($brands),
            'profiles' => count($this->profiles),
        ];
    }

    /**
     * Zapis planu i przegląd starych propozycji automatu.
     *
     * @param  list<array<string, mixed>>  $mains  z plan()
     * @return array{created: int, refreshed: int, kept_decided: int, skipped_rejected: int, skipped_manual: int, stale_removed: int, stale_marked: int, rechecked_ok: int}
     */
    public function write(array $mains): array
    {
        $stats = ['created' => 0, 'refreshed' => 0, 'kept_decided' => 0, 'skipped_rejected' => 0, 'skipped_manual' => 0, 'stale_removed' => 0, 'stale_marked' => 0, 'rechecked_ok' => 0];
        $now = Carbon::now();
        $touched = [];
        foreach ($mains as $main) {
            foreach ($main['picks'] as $pick) {
                $mainId = (int) $main['main_id'];
                $subId = (int) $pick['product_id'];
                $outcome = $this->upsert($mainId, $subId, $pick['result'], $now);
                $stats[$outcome]++;
                $touched[$mainId.'-'.$subId] = true;
            }
        }
        foreach ($this->recheckStale($touched, $now) as $key => $count) {
            $stats[$key] += $count;
        }

        return $stats;
    }

    /**
     * @param  array<string, mixed>  $result  wynik SubstituteMatcher::compare (ok)
     */
    private function upsert(int $mainId, int $subId, array $result, Carbon $now): string
    {
        $reverse = ProductSubstitute::query()->where('main_product_id', $subId)->where('substitute_product_id', $mainId)->first();
        if ($reverse instanceof ProductSubstitute && $reverse->approval_status === 'odrzucony') {
            return 'skipped_rejected';
        }
        $row = ProductSubstitute::query()->where('main_product_id', $mainId)->where('substitute_product_id', $subId)->first();
        $fields = [
            'type' => $result['verdict'],
            'match_percent' => min(100, (int) $result['score']),
            'reason' => $result['reason'],
            'evidence' => $this->evidence($mainId, $subId, $result, $now),
            'generated_at' => $now,
        ];
        if (! $row instanceof ProductSubstitute) {
            ProductSubstitute::query()->create($fields + [
                'main_product_id' => $mainId,
                'substitute_product_id' => $subId,
                'norms_ok' => true,
                'certs_ok' => true,
                'approval_status' => 'oczekuje',
                'approved_by' => null,
                'source' => ProductSubstitute::SOURCE_AUTO,
            ]);

            return 'created';
        }
        if ($row->approval_status === 'odrzucony') {
            return 'skipped_rejected';
        }
        if ($row->source !== ProductSubstitute::SOURCE_AUTO) {
            return 'skipped_manual';
        }
        if ($row->approval_status === 'oczekuje') {
            $row->update($fields);

            return 'refreshed';
        }
        // zatwierdzona propozycja: decyzja i typ zostają, dowody świeże
        $row->update(['evidence' => $fields['evidence'], 'generated_at' => $now]);

        return 'kept_decided';
    }

    /**
     * Propozycje automatu, których ten przebieg nie wybrał: para sprawdzana od nowa. Dalej przechodzi — zostaje
     * (nie tasujemy listy osobie, która ją przegląda), z nowymi dowodami. Nie przechodzi — „oczekuje” znika,
     * zatwierdzona dostaje znak „automat już nie potwierdza” z powodem.
     *
     * @param  array<string, true>  $touched
     * @return array<string, int>
     */
    private function recheckStale(array $touched, Carbon $now): array
    {
        $stats = ['stale_removed' => 0, 'stale_marked' => 0, 'rechecked_ok' => 0];
        $rows = ProductSubstitute::query()
            ->where('source', ProductSubstitute::SOURCE_AUTO)
            ->whereIn('approval_status', ['oczekuje', 'zatwierdzony'])
            ->get();
        foreach ($rows as $row) {
            if (isset($touched[$row->main_product_id.'-'.$row->substitute_product_id])) {
                continue;
            }
            $result = $this->comparePair((int) $row->main_product_id, (int) $row->substitute_product_id);
            if ($result['ok'] === true) {
                $update = ['evidence' => $this->evidence((int) $row->main_product_id, (int) $row->substitute_product_id, $result, $now), 'generated_at' => $now];
                if ($row->approval_status === 'oczekuje') {
                    $update += ['type' => $result['verdict'], 'reason' => $result['reason'], 'match_percent' => min(100, (int) $result['score'])];
                }
                $row->update($update);
                $stats['rechecked_ok']++;

                continue;
            }
            if ($row->approval_status === 'oczekuje') {
                $row->delete();
                $stats['stale_removed']++;

                continue;
            }
            $evidence = is_array($row->evidence) ? $row->evidence : [];
            $evidence['stale'] = ['at' => $now->toIso8601String(), 'reason' => $result['reason']];
            $row->update(['evidence' => $evidence]);
            $stats['stale_marked']++;
        }

        return $stats;
    }

    /**
     * Ponowne porównanie zapisanej pary (karta mogła się zmienić albo zniknąć z rodziny).
     *
     * @return array<string, mixed>
     */
    public function comparePair(int $mainId, int $subId): array
    {
        $main = Product::query()->find($mainId);
        $sub = Product::query()->find($subId);
        if (! $main instanceof Product || ! $sub instanceof Product) {
            return ['ok' => false, 'reason' => 'karta nie istnieje'];
        }
        $mainProfile = $this->profiler->profile($main);
        $subProfile = $this->profiler->profile($sub);
        if ($mainProfile === null || $subProfile === null) {
            return ['ok' => false, 'reason' => 'karta poza rodzinami automatu'];
        }
        $why = $this->profiler->mainIneligibility($main, $mainProfile);
        if ($why !== null) {
            return ['ok' => false, 'reason' => "karta główna: {$why}"];
        }
        if ($this->sameProductIds($mainId)[$subId] ?? false) {
            return ['ok' => false, 'reason' => 'ten sam wyrób (wspólny kod lub EAN)'];
        }

        return $this->matcher->compare($main, $mainProfile, $sub, $subProfile);
    }

    /**
     * @param  array<string, mixed>  $result
     * @return array<string, mixed>
     */
    private function evidence(int $mainId, int $subId, array $result, Carbon $now): array
    {
        $family = $this->profiles[$mainId]->family ?? $this->profileFor($mainId)?->family ?? '';

        return [
            'version' => 1,
            'rules' => self::rulesHash(),
            'generated_at' => $now->toIso8601String(),
            'family' => $family,
            'family_label' => SubstituteBoardPresenter::familyLabel($family),
            'verdict' => $result['verdict'],
            'params' => $result['params'],
            'extra_in_sub' => $result['extra_in_sub'],
            'not_checked' => SubstituteMatcher::NOT_CHECKED[$family] ?? [],
            'fingerprints' => [
                'main' => ($this->profiles[$mainId] ?? $this->profileFor($mainId))?->fingerprint,
                'sub' => ($this->profiles[$subId] ?? $this->profileFor($subId))?->fingerprint,
            ],
            'stale' => null,
        ];
    }

    public static function rulesHash(): string
    {
        return sha1(json_encode([
            SubstituteMatcher::RULES_VERSION,
            SubstituteProfiler::NORM_WHITELIST,
            SubstituteProfiler::FOOTWEAR_MARKINGS_REQUIRED,
            SubstituteMatcher::SNR_MAX_ABOVE,
        ]) ?: '');
    }

    private function profileFor(int $id): ?SubstituteProfile
    {
        $product = Product::query()->find($id);

        return $product instanceof Product ? $this->profiler->profile($product) : null;
    }

    /**
     * @param  list<string>  $families
     */
    private function loadProfiles(array $families): void
    {
        $this->profiles = [];
        $this->meta = [];
        $this->byType = [];
        $erp = $this->erpSignal();
        Product::query()
            ->whereIn('ppe_family', $families)
            ->chunkById(300, function ($products) use ($erp): void {
                foreach ($products as $product) {
                    $profile = $this->profiler->profile($product);
                    if ($profile === null) {
                        continue;
                    }
                    $this->profiles[(int) $product->id] = $profile;
                    $this->byType[$profile->family.'|'.$profile->articleType][] = (int) $product->id;
                    $this->meta[(int) $product->id] = [
                        'manufacturer' => trim((string) $product->manufacturer),
                        'name' => (string) $product->name,
                        'eligible' => $this->profiler->mainIneligibility($product, $profile),
                        'erp' => $erp[(int) $product->id] ?? 0,
                        'model' => $profile->brandKey.'|'.$this->modelKey((string) $product->name, $profile->brandKey),
                    ];
                }
            });
    }

    /**
     * Sygnał „to sprzedajemy”: 2 — towar XL powiązany i sprzedany w ostatnim roku, 1 — tylko powiązany.
     *
     * @return array<int, int>
     */
    private function erpSignal(): array
    {
        $out = [];
        $since = Carbon::now()->subYear();
        foreach (DB::table('erp_item_links as l')
            ->join('erp_items as e', 'e.id', '=', 'l.erp_item_id')
            ->whereIn('l.status', [ErpItemLink::STATUS_CONFIRMED, ErpItemLink::STATUS_AUTO])
            ->select(['l.product_id', 'e.last_sale_at'])
            ->get() as $row) {
            $score = $row->last_sale_at !== null && Carbon::parse($row->last_sale_at)->greaterThanOrEqualTo($since) ? 2 : 1;
            $out[(int) $row->product_id] = max($out[(int) $row->product_id] ?? 0, $score);
        }

        return $out;
    }

    /**
     * Ile kart wybranych producentów nie może być kartą główną — według powodu (do raportu przebiegu).
     *
     * @param  list<array{key: string, name: string, cards: int}>  $brands
     * @return array<string, int>
     */
    private function skippedMains(array $brands): array
    {
        $keys = array_flip(array_column($brands, 'key'));
        $out = [];
        foreach ($this->profiles as $id => $profile) {
            $why = $this->meta[$id]['eligible'];
            if ($why === null || ! isset($keys[$profile->brandKey])) {
                continue;
            }
            $reason = preg_replace('/:.*$/u', '', $why) ?? $why;
            $out[$reason] = ($out[$reason] ?? 0) + 1;
        }
        arsort($out);

        return $out;
    }

    /**
     * Najwięksi producenci w rodzinach automatu (po liczbie kart; marki tego samego producenta razem).
     *
     * @param  list<string>  $only  nazwy producentów do zawężenia (puste = wszyscy)
     * @return list<array{key: string, name: string, cards: int}>
     */
    private function topBrands(int $limit, array $only): array
    {
        $counts = [];
        $names = [];
        foreach ($this->profiles as $id => $profile) {
            if ($profile->brandKey === '') {
                continue;
            }
            $counts[$profile->brandKey] = ($counts[$profile->brandKey] ?? 0) + 1;
            $name = $this->meta[$id]['manufacturer'];
            $names[$profile->brandKey][$name] = ($names[$profile->brandKey][$name] ?? 0) + 1;
        }
        arsort($counts);
        $onlyKeys = array_map(static fn (string $n): string => CanonicalBrand::key($n), $only);
        $out = [];
        foreach ($counts as $key => $count) {
            if ($onlyKeys !== [] && ! in_array($key, $onlyKeys, true)) {
                continue;
            }
            arsort($names[$key]);
            $out[] = ['key' => $key, 'name' => (string) array_key_first($names[$key]), 'cards' => $count];
            if (count($out) >= $limit) {
                break;
            }
        }

        return $out;
    }

    /**
     * Karty główne producenta w kolejności: sprzedawane (XL) przed powiązanymi i resztą, więcej sprawdzalnych parametrów
     * wyżej; rozrzut po rodzajach wyrobu (po kolei z każdej grupy), jeden model raz.
     *
     * @return list<int>
     */
    private function mainCandidates(string $brandKey): array
    {
        $groups = [];
        $seenModels = [];
        $ids = array_keys(array_filter(
            $this->profiles,
            fn (SubstituteProfile $p, int $id): bool => $p->brandKey === $brandKey && $this->meta[$id]['eligible'] === null,
            ARRAY_FILTER_USE_BOTH,
        ));
        usort($ids, fn (int $a, int $b): int => [$this->meta[$b]['erp'], count($this->profiles[$b]->levels), $a]
            <=> [$this->meta[$a]['erp'], count($this->profiles[$a]->levels), $b]);
        foreach ($ids as $id) {
            $model = $this->meta[$id]['model'];
            if (isset($seenModels[$model])) {
                continue;
            }
            $seenModels[$model] = true;
            $profile = $this->profiles[$id];
            $groups[$profile->family.'|'.$profile->articleType][] = $id;
        }
        $out = [];
        while ($groups !== []) {
            foreach ($groups as $key => $list) {
                $out[] = array_shift($groups[$key]);
                if ($groups[$key] === []) {
                    unset($groups[$key]);
                }
            }
        }

        return $out;
    }

    /**
     * @param  array<string, int>  $rejections
     * @return list<array{product_id: int, result: array<string, mixed>}>
     */
    private function substitutesFor(int $mainId, int $perMain, array &$rejections): array
    {
        $mainProfile = $this->profiles[$mainId];
        $sameProduct = $this->sameProductIds($mainId);
        $pool = [];
        foreach ($this->byType[$mainProfile->family.'|'.$mainProfile->articleType] ?? [] as $id) {
            $profile = $this->profiles[$id];
            if ($id === $mainId || isset($sameProduct[$id])) {
                continue;
            }
            $why = $this->matcher->prefilter($mainProfile, $profile);
            if ($why !== null) {
                continue;
            }
            $pool[$id] = $this->preScore($mainProfile, $profile, $id);
        }
        if ($pool === []) {
            return [];
        }
        arsort($pool);
        $main = Product::query()->find($mainId);
        if (! $main instanceof Product) {
            return [];
        }
        $accepted = [];
        foreach (array_slice(array_keys($pool), 0, self::FULL_COMPARE_LIMIT) as $candId) {
            $cand = Product::query()->find($candId);
            if (! $cand instanceof Product) {
                continue;
            }
            $result = $this->matcher->compare($main, $mainProfile, $cand, $this->profiles[$candId]);
            if ($result['ok'] !== true) {
                $reason = preg_replace('/:.*$/u', '', (string) $result['reason']) ?? (string) $result['reason'];
                $rejections[$reason] = ($rejections[$reason] ?? 0) + 1;

                continue;
            }
            $accepted[] = ['product_id' => $candId, 'result' => $result];
        }
        usort($accepted, fn (array $a, array $b): int => [$b['result']['score'], $this->meta[$b['product_id']]['erp'], $a['product_id']]
            <=> [$a['result']['score'], $this->meta[$a['product_id']]['erp'], $b['product_id']]);

        $picked = [];
        $brands = [];
        $models = [];
        foreach ($accepted as $item) {
            $profile = $this->profiles[$item['product_id']];
            $model = $this->meta[$item['product_id']]['model'];
            if (isset($brands[$profile->brandKey]) || isset($models[$model])) {
                continue;
            }
            $brands[$profile->brandKey] = true;
            $models[$model] = true;
            $picked[] = $item;
            if (count($picked) >= $perMain) {
                break;
            }
        }

        return $picked;
    }

    /**
     * Ta sama karta główna pod inną nazwą: ten sam rodzaj, te same poziomy i cechy, a nazwy dzielą co najmniej połowę
     * słów („3M PELTOR Optime I Nauszniki…” i „Nauszniki 3M PELTOR Optime I, żółte, pałąk”).
     *
     * @param  list<int>  $keptIds
     */
    private function duplicatesKeptMain(int $mainId, array $keptIds): bool
    {
        $profile = $this->profiles[$mainId];
        $signature = $this->profileSignature($profile);
        $words = $this->nameWords($mainId);
        foreach ($keptIds as $id) {
            if ($this->profileSignature($this->profiles[$id]) !== $signature) {
                continue;
            }
            $other = $this->nameWords($id);
            $union = count(array_unique([...$words, ...$other]));
            if ($union > 0 && count(array_intersect($words, $other)) / $union >= 0.5) {
                return true;
            }
        }

        return false;
    }

    private function profileSignature(SubstituteProfile $profile): string
    {
        return json_encode([
            $profile->family,
            $profile->articleType,
            array_map(static fn (array $l): string => mb_strtoupper($l['value']), $profile->levels),
            $profile->flags,
            $profile->markings,
        ]) ?: '';
    }

    /**
     * @return list<string>
     */
    private function nameWords(int $id): array
    {
        $words = preg_split('/\s+/', $this->modelKey($this->meta[$id]['name'], $this->profiles[$id]->brandKey)) ?: [];

        return array_values(array_unique(array_filter($words, static fn (string $w): bool => mb_strlen($w) >= 2)));
    }

    /**
     * Klucz modelu do pomijania wariantów jednego wyrobu: nazwa bez rozmiaru, kolorów, kodów wariantu (długie liczby,
     * „6791/3”) i dopisków automatów („VP VEND”), z liczbą modelu bez liter na końcu („11751VP” → „11751”).
     */
    private function modelKey(string $name, string $brandKey = ''): string
    {
        $t = mb_strtolower(Str::ascii($this->sizes->stripSizeFromName($name)));
        $out = [];
        foreach (preg_split('/[\s,;()]+/', $t) ?: [] as $token) {
            $token = trim($token, '.-–');
            // marka sklejona z kodem („HECKEL6794/3”) i sama marka nie odróżniają modeli
            if ($token === '' || str_contains($token, '/') || preg_match('/^\d{6,}$/', $token) === 1
                || ($brandKey !== '' && str_starts_with($token, $brandKey))
                || preg_match('/^(vp|vend|czarn\w*|bial\w*|szar\w*|niebiesk\w*|zielon\w*|zolt\w*|pomarancz\w*|czerwon\w*|braz\w*|granat\w*|black|white|gr[ae]y|blue|green|yellow|orange|red|brown|silver|navy|khaki|beige)$/', $token) === 1) {
                continue;
            }
            $out[] = preg_match('/^(\d{3,5})[a-z]+$/', $token, $m) === 1 ? $m[1] : $token;
        }

        return implode(' ', $out);
    }

    /** Wstępna kolejność kandydatów: te same wartości poziomów co karta główna, potem sprzedawane, potem wspólne normy. */
    private function preScore(SubstituteProfile $main, SubstituteProfile $cand, int $candId): int
    {
        $score = 0;
        foreach ($main->levels as $key => $level) {
            if (isset($cand->levels[$key])) {
                $score += mb_strtoupper($cand->levels[$key]['value']) === mb_strtoupper($level['value']) ? 10 : 4;
            }
        }

        return $score + 2 * $this->meta[$candId]['erp'] + count(array_intersect_key($cand->norms, $main->norms));
    }

    /**
     * Karty, które są tym samym wyrobem co karta główna: wspólny EAN / kod producenta / kod modelu albo para
     * w „Łączeniu kart” (oprócz odrzuconej).
     *
     * @return array<int, true>
     */
    private function sameProductIds(int $mainId): array
    {
        $values = ProductIdentifier::query()
            ->where('product_id', $mainId)
            ->whereIn('type', self::SAME_PRODUCT_IDENTIFIERS)
            ->whereNull('removed_at')
            ->whereNotNull('normalized')
            ->pluck('normalized')
            ->filter(static fn ($v): bool => mb_strlen((string) $v) >= 5)
            ->unique()
            ->values()
            ->all();
        $out = [];
        if ($values !== []) {
            foreach (ProductIdentifier::query()
                ->whereIn('normalized', $values)
                ->whereIn('type', self::SAME_PRODUCT_IDENTIFIERS)
                ->whereNull('removed_at')
                ->pluck('product_id') as $id) {
                $out[(int) $id] = true;
            }
        }
        foreach (CardMatchCandidate::query()
            ->where('status', '!=', CardMatchCandidate::STATUS_REJECTED)
            ->where(fn ($q) => $q->where('source_product_id', $mainId)->orWhere('target_product_id', $mainId))
            ->get(['source_product_id', 'target_product_id']) as $pair) {
            $out[(int) $pair->source_product_id] = true;
            if ($pair->target_product_id !== null) {
                $out[(int) $pair->target_product_id] = true;
            }
        }
        unset($out[$mainId]);

        return $out;
    }
}
