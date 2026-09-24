<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\Product;
use App\Models\ProductSubstitute;
use App\Models\TenderItem;
use App\Services\Ai\AiSettingsService;
use App\Services\Ai\AiTask;
use App\Services\Search\AiProductSearch;
use App\Support\BhpAttributeNormalizer;
use App\Support\OfferPricing;
use App\Support\PpeAssortment;
use App\Support\RequirementCheck\CardSources;
use App\Support\RequirementCheck\CheckRow;
use App\Support\RequirementCheck\En388Code;
use App\Support\RequirementCheck\LevelChecker;
use App\Support\RequirementCheck\PackageChecker;
use App\Support\RequirementCheck\Status;
use Throwable;

/**
 * Snapshot pozycji SIWZ: propozycja główna + do 8 zamienników z katalogu.
 * (Katalog = oferta ogólnie dostępna wielu marek — bez bloku „konkurencja”.)
 *
 * Zamiennik z katalogu dobiera ocena słowna (explainMatch) albo — po ręcznym dopasowaniu — wyszukiwarka z modelem.
 * Ocena słowna liczy wspólne słowa i obecność nazw norm, nie ich poziomy: uwagi eksperta 24.09 (przetarg 1, poz. 7)
 * — zimowe rękawice Canis EN 388 2X31X dostały 99% przy wymaganym 4341B. Dlatego każdy zamiennik przechodzi przez
 * Weryfikację karty (poziomy i klasy, pojemność i zestaw): kartę z katalogu, która przeczy wymaganiu albo nie podaje wymaganego poziomu,
 * pomijamy; zamiennik przypięty przez człowieka zostaje z wynikiem weryfikacji. „Tańszy o X%” i zbiorcza zamiana na
 * tańszy tylko dla zamiennika zgodnego z SIWZ albo zatwierdzonego.
 */
final class BattlecardService
{
    private const SUBSTITUTE_LIMIT = 8;

    private const CATALOG_ALT_MIN_SCORE = 55;

    /** Wynik liczony ze wspólnych słów (explainMatch) — nie pokazujemy go jako „% dopasowania”. */
    private const BASIS_WORDS = 'words';

    /** Ocena modelu z wyszukiwarki AI. */
    private const BASIS_MODEL = 'model';

    /** Procent zapisany przy zamienniku przez człowieka (Zamienniki). */
    private const BASIS_RELATION = 'relation';

    public function __construct(
        private readonly ProductMatchService $matcher,
        private readonly AiProductSearch $aiSearch,
        private readonly AiSettingsService $aiSettings,
        private readonly BhpAttributeNormalizer $bhpAttributes,
        private readonly PpeAssortment $assortment,
        private readonly NbpExchangeRateService $fx,
        private readonly LevelChecker $levels,
        private readonly PackageChecker $package,
    ) {}

    /**
     * @return array{
     *     requirement: array{line_no: int, text: string},
     *     ours: ?array<string, mixed>,
     *     substitutes: list<array<string, mixed>>,
     *     competitors: list<array<string, mixed>>,
     *     highlights: list<string>
     * }
     */
    /**
     * $allowAi domyślnie = $refresh (odświeżenie po ręcznym dopasowaniu pyta model
     * o zamienniki). Dopasowanie całej oferty przekazuje false: druga runda
     * wyszukiwania AI na każdą pozycję trwała dłużej niż samo dopasowanie, bez
     * paska postępu, i przeglądarka zrywała żądanie.
     */
    public function forItem(TenderItem $item, bool $refresh = false, ?bool $allowAi = null): array
    {
        $item->loadMissing(['mainProduct', 'tender']);
        if (! $refresh && is_array($item->battlecard_substitutes)) {
            return $this->cardFromStored($item);
        }

        $card = $this->buildCard($item, $allowAi ?? $refresh);
        $this->persistSubstitutes($item, $card['substitutes']);

        return $card;
    }

    /**
     * @return array{
     *     requirement: array{line_no: int, text: string},
     *     ours: ?array<string, mixed>,
     *     substitutes: list<array<string, mixed>>,
     *     competitors: list<array<string, mixed>>,
     *     highlights: list<string>
     * }
     */
    private function buildCard(TenderItem $item, bool $allowAi): array
    {
        $ours = $item->mainProduct;
        $markupPercent = $item->tender?->targetMarkupPercent();
        $excludeIds = [];
        if ($ours !== null) {
            $excludeIds[] = (int) $ours->id;
        }

        $substitutes = $this->buildSubstitutes($ours, $excludeIds, $item->requirement, $markupPercent);
        $substitutes = $this->fillFromCatalog(
            $item->requirement,
            $substitutes,
            $excludeIds,
            $markupPercent,
            $allowAi,
        );
        $substitutes = $this->sortSubstitutesCheapestFirst($substitutes);

        return $this->assembleCard($item, array_slice($substitutes, 0, self::SUBSTITUTE_LIMIT), $markupPercent);
    }

    /**
     * @return array{
     *     requirement: array{line_no: int, text: string},
     *     ours: ?array<string, mixed>,
     *     substitutes: list<array<string, mixed>>,
     *     competitors: list<array<string, mixed>>,
     *     highlights: list<string>
     * }
     */
    private function cardFromStored(TenderItem $item): array
    {
        $markupPercent = $item->tender?->targetMarkupPercent();
        $oursId = $item->mainProduct?->id;
        $requirement = (string) $item->requirement;
        $rows = is_array($item->battlecard_substitutes) ? $item->battlecard_substitutes : [];
        $ids = [];
        foreach ($rows as $row) {
            if (! is_array($row)) {
                continue;
            }
            $id = (int) ($row['product_id'] ?? 0);
            if ($id > 0 && ($oursId === null || $id !== (int) $oursId)) {
                $ids[] = $id;
            }
        }
        $products = $ids === []
            ? collect()
            : Product::query()->whereIn('id', array_values(array_unique($ids)))->get()->keyBy('id');

        $substitutes = [];
        foreach ($rows as $row) {
            if (! is_array($row)) {
                continue;
            }
            $id = (int) ($row['product_id'] ?? 0);
            $product = $products->get($id);
            if (! $product instanceof Product) {
                continue;
            }
            $source = $row['source'] ?? 'catalog';
            // Zapisane przed weryfikacją (24.09) listy trzymały karty sprzeczne z SIWZ — sprawdzamy przy każdym odczycie.
            $verification = $this->verify($requirement, $product);
            if ($source !== 'relation' && ! $this->catalogVerificationAllows($verification)) {
                continue;
            }
            $snap = $this->productSnapshot(
                $product,
                (int) ($row['match_percent'] ?? 0),
                null,
                [],
                null,
                'substitute',
                $markupPercent,
            );
            $snap['substitute_type'] = $row['substitute_type'] ?? null;
            $snap['approval_status'] = $row['approval_status'] ?? null;
            $snap['reason'] = $row['reason'] ?? null;
            $snap['source'] = $source;
            // lista bez podstawy wyniku powstała przed 24.09 z oceny słownej (albo z relacji)
            $snap['match_basis'] = $row['match_basis'] ?? ($source === 'relation' ? self::BASIS_RELATION : self::BASIS_WORDS);
            $substitutes[] = $this->withVerification($snap, $verification);
        }

        return $this->assembleCard($item, $substitutes, $markupPercent);
    }

    /**
     * @param  list<array<string, mixed>>  $substitutes
     * @return array{
     *     requirement: array{line_no: int, text: string},
     *     ours: ?array<string, mixed>,
     *     substitutes: list<array<string, mixed>>,
     *     competitors: list<array<string, mixed>>,
     *     highlights: list<string>
     * }
     */
    private function assembleCard(TenderItem $item, array $substitutes, ?float $markupPercent): array
    {
        $ours = $item->mainProduct;
        $card = [
            'requirement' => [
                'line_no' => (int) $item->line_no,
                'text' => (string) $item->requirement,
            ],
            'ours' => $ours === null ? null : $this->productSnapshot(
                $ours,
                (int) ($item->ai_match_percent ?? 0),
                $item->offer_price !== null ? (float) $item->offer_price : null,
                is_array($item->ai_match_reasons) ? $item->ai_match_reasons : [],
                $item->match_source,
                'ours',
                $markupPercent,
            ),
            'substitutes' => $substitutes,
            'competitors' => [],
            'highlights' => [],
        ];
        $card['highlights'] = $this->buildHighlights($card);

        return $card;
    }

    /**
     * @param  list<array<string, mixed>>  $substitutes
     */
    private function persistSubstitutes(TenderItem $item, array $substitutes): void
    {
        $stored = [];
        foreach ($substitutes as $snap) {
            $id = (int) ($snap['product_id'] ?? 0);
            if ($id <= 0) {
                continue;
            }
            $stored[] = [
                'product_id' => $id,
                'match_percent' => (int) ($snap['match_percent'] ?? 0),
                'source' => $snap['source'] ?? null,
                'match_basis' => $snap['match_basis'] ?? null,
                'substitute_type' => $snap['substitute_type'] ?? null,
                'approval_status' => $snap['approval_status'] ?? null,
                'reason' => $snap['reason'] ?? null,
            ];
        }
        $item->battlecard_substitutes = $stored;
        $item->save();
    }

    /**
     * @param  list<int>  $excludeIds
     * @return list<array<string, mixed>>
     */
    private function buildSubstitutes(?Product $ours, array &$excludeIds, string $requirement, ?float $markupPercent): array
    {
        if ($ours === null) {
            return [];
        }

        $rows = ProductSubstitute::query()
            ->with('substituteProduct')
            ->where('main_product_id', $ours->id)
            ->orderByDesc('match_percent')
            ->limit(self::SUBSTITUTE_LIMIT)
            ->get();

        $out = [];
        foreach ($rows as $row) {
            $p = $row->substituteProduct;
            if (! $p instanceof Product) {
                continue;
            }
            if ($requirement !== '' && ! $this->assortment->compatibleProduct($requirement, $p)) {
                continue;
            }
            $excludeIds[] = (int) $p->id;
            $snap = $this->productSnapshot(
                $p,
                (int) ($row->match_percent ?? 0),
                null,
                [],
                null,
                'substitute',
                $markupPercent,
            );
            $snap['substitute_type'] = $row->type;
            $snap['approval_status'] = $row->approval_status;
            $snap['reason'] = $row->reason;
            $snap['source'] = 'relation';
            $snap['match_basis'] = self::BASIS_RELATION;
            $out[] = $this->withVerification($snap, $this->verify($requirement, $p));
        }

        return $out;
    }

    /**
     * Uzupełnij brakujące sloty zamienników top matchami z całego katalogu (SIWZ).
     *
     * @param  list<array<string, mixed>>  $existing
     * @param  list<int>  $excludeIds
     * @return list<array<string, mixed>>
     */
    private function fillFromCatalog(
        string $requirement,
        array $existing,
        array $excludeIds,
        ?float $markupPercent,
        bool $allowAi = false,
    ): array {
        $need = self::SUBSTITUTE_LIMIT - count($existing);
        if ($need <= 0 || trim($requirement) === '') {
            return $existing;
        }

        $scored = $this->scoreCatalogAlternates($requirement, $excludeIds, $need + 8, $allowAi);
        if ($scored === []) {
            return $existing;
        }
        $top = $scored[0]['score'];
        $minKeep = max(self::CATALOG_ALT_MIN_SCORE, $top - 8);
        foreach ($scored as $row) {
            if ($row['score'] < $minKeep) {
                continue;
            }
            $verification = $this->verify($requirement, $row['product']);
            if (! $this->catalogVerificationAllows($verification)) {
                continue;
            }
            $snap = $this->productSnapshot($row['product'], $row['score'], null, [], null, 'substitute', $markupPercent);
            $snap['source'] = 'catalog';
            $snap['substitute_type'] = 'katalog';
            $snap['match_basis'] = $row['basis'];
            $existing[] = $this->withVerification($snap, $verification);
            if (count($existing) >= self::SUBSTITUTE_LIMIT) {
                break;
            }
        }

        return $existing;
    }

    /**
     * Kolejne wyniki z tej samej ścieżki co „Szukaj w katalogu”, nie pierwsze 500 kart po cenie.
     *
     * @param  list<int>  $excludeIds
     * @return list<array{product: Product, score: int, basis: string}>
     */
    private function scoreCatalogAlternates(string $requirement, array $excludeIds, int $limit, bool $allowAi = false): array
    {
        $rows = [];
        $fromLlm = false;
        if ($allowAi && $this->aiSettings->isReady()) {
            try {
                $result = $this->aiSearch->find($requirement, max(8, $limit), AiTask::ProductSearch);
                $rows = is_array($result['products'] ?? null) ? $result['products'] : [];
                $fromLlm = $rows !== [];
            } catch (Throwable) {
                $rows = [];
            }
        }
        if ($rows === []) {
            $rows = $this->aiSearch->catalogRows($requirement, max(8, $limit));
        }

        $blocked = array_fill_keys($excludeIds, true);
        $scored = [];
        if ($rows === []) {
            return $this->scoreRetrievedAlternates($requirement, $blocked, $limit);
        }
        foreach ($rows as $row) {
            $id = (int) ($row['id'] ?? 0);
            if ($id <= 0 || isset($blocked[$id])) {
                continue;
            }
            $product = Product::query()->find($id);
            if (! $product instanceof Product) {
                continue;
            }
            if (! $this->assortment->compatibleProduct($requirement, $product)) {
                continue;
            }
            $explained = $this->matcher->explainMatch($requirement, $product);
            if (($explained['reasons'][0]['code'] ?? '') === 'asortyment_reject') {
                continue;
            }
            $modelScore = $fromLlm && isset($row['ai_match_percent']) ? (int) $row['ai_match_percent'] : null;
            $score = $modelScore ?? $explained['score'];
            if ($score < self::CATALOG_ALT_MIN_SCORE) {
                continue;
            }
            $scored[] = ['product' => $product, 'score' => $score, 'basis' => $modelScore !== null ? self::BASIS_MODEL : self::BASIS_WORDS];
        }
        usort($scored, static fn (array $a, array $b): int => $b['score'] <=> $a['score']);

        return $scored;
    }

    /**
     * @param  array<int, true>  $blocked
     * @return list<array{product: Product, score: int, basis: string}>
     */
    private function scoreRetrievedAlternates(string $requirement, array $blocked, int $limit): array
    {
        // Karty z tego samego retrievalu co wyszukiwarka AI (bez pytania modelu), w kolejności
        // trafności. Dotąd brało się pierwsze 20 kart rodziny w kolejności bazy — przy rodzinach
        // po 1000–3000 kart to loteria, a gdy rodziny wymagania nie dało się rozpoznać, pierwsze
        // 20 kart całego katalogu. Ocena i próg zostają te same: dowody z karty (explainMatch).
        $scored = [];
        foreach ($this->aiSearch->candidates($requirement, max(40, $limit), true) as $product) {
            if (! $product instanceof Product
                || isset($blocked[(int) $product->id])
                || ! $this->assortment->compatibleProduct($requirement, $product)) {
                continue;
            }
            $explained = $this->matcher->explainMatch($requirement, $product);
            if (($explained['reasons'][0]['code'] ?? '') === 'asortyment_reject'
                || $explained['score'] < self::CATALOG_ALT_MIN_SCORE) {
                continue;
            }
            $scored[] = ['product' => $product, 'score' => $explained['score'], 'basis' => self::BASIS_WORDS];
        }
        usort($scored, static fn (array $a, array $b): int => $b['score'] <=> $a['score']);

        return $scored;
    }

    /**
     * @param  list<array{code: string, label: string, points: int}>  $reasons
     * @return array<string, mixed>
     */
    private function productSnapshot(
        Product $product,
        int $matchPercent,
        ?float $offerPrice,
        array $reasons,
        ?string $matchSource,
        string $role,
        ?float $markupPercent = null,
    ): array {
        $payload = is_array($product->enrichment_payload) ? $product->enrichment_payload : [];
        $attrs = $this->bhpAttributes->forProduct($product);
        $norms = $product->norms;
        if (($norms === null || $norms === '') && is_array($attrs['normy_en'] ?? null) && $attrs['normy_en'] !== []) {
            $norms = implode(', ', array_map('strval', $attrs['normy_en']));
        } elseif (($norms === null || $norms === '') && is_array($payload['norms'] ?? null)) {
            $norms = implode(', ', array_map('strval', $payload['norms']));
        }

        $sourceCurrency = strtoupper(trim((string) ($product->currency ?? 'PLN'))) ?: 'PLN';
        $catalogPln = $this->fx->catalogPln($product);
        $purchasePln = $this->fx->purchasePln($product);
        $suggested = OfferPricing::fromPurchase($purchasePln, $markupPercent);
        $offerOut = $offerPrice;
        if ($offerOut !== null && $this->fx->isForeign($sourceCurrency) && $product->purchase_price !== null) {
            $unconverted = OfferPricing::fromPurchase($product->purchase_price, $markupPercent);
            if ($unconverted !== null && abs($offerOut - $unconverted) < 0.03) {
                $offerOut = $suggested;
            }
        }

        return [
            'role' => $role,
            'product_id' => $product->id,
            'sku' => $product->sku,
            'name' => $product->name,
            'description' => $product->description,
            'manufacturer' => $product->manufacturer,
            'category' => $product->category,
            'norms' => $norms,
            'attributes' => $attrs,
            'source_currency' => $sourceCurrency,
            'currency' => 'PLN',
            'catalog_price_net' => $catalogPln,
            'offer_price' => $offerOut,
            'purchase_price' => $purchasePln,
            'suggested_offer_price' => $suggested,
            'stock' => (int) ($product->stock ?? 0),
            'match_percent' => $matchPercent,
            'match_source' => $matchSource,
            'reasons' => $reasons,
        ];
    }

    /**
     * @param  list<array<string, mixed>>  $substitutes
     * @return list<array<string, mixed>>
     */
    private function sortSubstitutesCheapestFirst(array $substitutes): array
    {
        usort($substitutes, static function (array $a, array $b): int {
            $pa = (float) ($a['purchase_price'] ?? $a['catalog_price_net'] ?? PHP_FLOAT_MAX);
            $pb = (float) ($b['purchase_price'] ?? $b['catalog_price_net'] ?? PHP_FLOAT_MAX);
            if ($pa === $pb) {
                return ((int) ($b['match_percent'] ?? 0)) <=> ((int) ($a['match_percent'] ?? 0));
            }

            return $pa <=> $pb;
        });

        return $substitutes;
    }

    /**
     * Najtańszy zamiennik (po upuście) tańszy o co najmniej $minSavePercent względem propozycji.
     *
     * @return array{product_id: int, sku: string, purchase_price: float, save_percent: float, match_percent: int}|null
     */
    public function bestCheaperSubstitute(TenderItem $item, float $minSavePercent = 3.0): ?array
    {
        $card = $this->forItem($item);
        $ours = $card['ours'];
        if ($ours === null) {
            return null;
        }
        $ourPurchase = $this->effectivePurchase($ours);
        if ($ourPurchase <= 0) {
            return null;
        }

        $best = null;
        $bestPrice = $ourPurchase;
        foreach ($card['substitutes'] as $sub) {
            if (! ($sub['price_comparable'] ?? false)) {
                continue;
            }
            $price = $this->effectivePurchase($sub);
            if ($price <= 0 || $price >= $bestPrice) {
                continue;
            }
            $save = ($ourPurchase - $price) / $ourPurchase * 100;
            if ($save < $minSavePercent) {
                continue;
            }
            $best = [
                'product_id' => (int) $sub['product_id'],
                'sku' => (string) $sub['sku'],
                'purchase_price' => $price,
                'save_percent' => round($save, 1),
                'match_percent' => (int) ($sub['match_percent'] ?? 0),
            ];
            $bestPrice = $price;
        }

        return $best;
    }

    /**
     * Weryfikacja karty — poziomy i klasy (EN 388, poziom cięcia, EN 407, kategoria ŚOI, klasa obuwia, FFP, SNR, klasa
     * uderzenia) oraz opakowanie (pojemność, sztuka czy zestaw; pomiar na produkcji 24.09 w PackageChecker). Wymiarów,
     * cech tak/nie i koloru tu nie używamy: cechy dały w pomiarze sprzeczności kart same fałszywe alarmy, a wymiarów
     * i koloru jako sita zamienników nikt nie zmierzył. Status: fail — któryś parametr przeczy; missing — karta nie podaje
     * któregoś; check — pola karty sobie przeczą; ok — wszystkie podane i spełnione; none — nie ma czego sprawdzić.
     *
     * @return array{status: string, rows: list<array{label: string, status: string, note: ?string}>}
     */
    private function verify(string $requirement, Product $product): array
    {
        if (trim($requirement) === '') {
            return ['status' => 'none', 'rows' => []];
        }
        $rows = [];
        $statuses = [];
        $sources = CardSources::fromProduct($product);
        foreach ([...$this->levels->check($requirement, $sources), ...$this->package->check($requirement, $sources)] as $row) {
            $status = $this->coupXWithIsoMet($row) ? Status::Unclear : $row->status;
            $rows[] = ['label' => $row->label, 'status' => $status->value, 'note' => $row->note];
            $statuses[] = $status;
        }
        if ($statuses === []) {
            return ['status' => 'none', 'rows' => []];
        }
        $status = match (Status::worst($statuses)) {
            Status::Fail => 'fail',
            Status::Missing => 'missing',
            Status::Unclear => 'check',
            Status::Ok => 'ok',
        };

        return ['status' => $status, 'rows' => $rows];
    }

    /**
     * EN 388:2016 — Coup Test „X” przy zbadanej literze ISO 13997 to zwykły zapis (ostrze tępieje, rozstrzyga ISO):
     * MAPA KryTech 644 „4X43D” przy wymaganym „4341B”. Wiersz ma status „brak” (cyfr i liter nie przeliczamy), ale
     * jedyny brak to Coup X, a wymagana litera ISO jest spełniona — zamiennik pokazujemy „do sprawdzenia”, nie ukrywamy.
     */
    private function coupXWithIsoMet(CheckRow $row): bool
    {
        if ($row->key !== 'en388' || $row->status !== Status::Missing || $row->positions === null) {
            return false;
        }
        $coupeName = En388Code::POSITIONS['coupe'];
        $isoName = En388Code::POSITIONS['iso'];
        $isoMet = false;
        foreach ($row->positions as $position) {
            $missingCoupX = $position['name'] === $coupeName && $position['card'] === 'X';
            if ($position['name'] === $isoName && $position['status'] === Status::Ok->value) {
                $isoMet = true;
            } elseif ($position['status'] === Status::Missing->value && ! $missingCoupX) {
                return false;
            }
        }

        return $isoMet;
    }

    /**
     * Kartę z katalogu proponujemy, gdy nie przeczy wymaganiu i podaje wymagane poziomy. Brak poziomu to nie
     * sprzeczność, ale zamiennik bez dowodu to karta „podobna słowami” — tego ekspert nie chce widzieć jako 99%.
     *
     * @param  array{status: string}  $verification
     */
    private function catalogVerificationAllows(array $verification): bool
    {
        return $verification['status'] !== 'fail' && $verification['status'] !== 'missing';
    }

    /**
     * @param  array<string, mixed>  $snap
     * @param  array{status: string, rows: list<array<string, mixed>>}  $verification
     * @return array<string, mixed>
     */
    private function withVerification(array $snap, array $verification): array
    {
        $snap['verification'] = $verification;
        // cenę porównujemy tylko z zamiennikiem, który na pewno spełnia SIWZ albo zatwierdził go człowiek — i nie przeczy SIWZ
        $snap['price_comparable'] = $verification['status'] === 'ok'
            || ($verification['status'] !== 'fail'
                && ($snap['source'] ?? null) === 'relation' && ($snap['approval_status'] ?? null) === 'zatwierdzony');

        return $snap;
    }

    /** @param  array<string, mixed>  $snap */
    private function effectivePurchase(array $snap): float
    {
        $purchase = $snap['purchase_price'] ?? null;
        if ($purchase !== null && (float) $purchase > 0) {
            return (float) $purchase;
        }
        $catalog = $snap['catalog_price_net'] ?? null;
        if ($catalog !== null && (float) $catalog > 0) {
            return (float) $catalog;
        }

        return 0.0;
    }

    /**
     * @param  array{
     *     ours: ?array<string, mixed>,
     *     substitutes: list<array<string, mixed>>,
     *     competitors: list<array<string, mixed>>
     * }  $card
     * @return list<string>
     */
    private function buildHighlights(array $card): array
    {
        $highlights = [];
        $ours = $card['ours'];
        if ($ours === null) {
            $highlights[] = 'Brak propozycji głównej — uzupełnij match, aby porównać ofertę.';

            return $highlights;
        }

        $ourPrice = $ours['purchase_price'] ?? $ours['catalog_price_net'] ?? null;
        foreach ($card['substitutes'] as $sub) {
            if (! ($sub['price_comparable'] ?? false)) {
                continue;
            }
            $subPrice = $sub['purchase_price'] ?? $sub['catalog_price_net'] ?? null;
            if ($ourPrice === null || $subPrice === null || (float) $subPrice <= 0 || (float) $ourPrice <= 0) {
                continue;
            }
            $diff = ((float) $ourPrice - (float) $subPrice) / (float) $ourPrice * 100;
            if ($diff >= 3) {
                $highlights[] = sprintf(
                    'Zamiennik %s (%s) tańszy o ok. %.0f%% (po upuście).',
                    $sub['sku'],
                    $sub['manufacturer'],
                    $diff,
                );
            }
        }

        $approvedSub = collect($card['substitutes'])
            ->first(fn (array $s): bool => ($s['approval_status'] ?? '') === 'zatwierdzony');
        if ($approvedSub !== null) {
            $highlights[] = sprintf(
                'Dostępny zatwierdzony zamiennik: %s (%s%%).',
                $approvedSub['sku'],
                $approvedSub['match_percent'],
            );
        }

        if (($ours['match_percent'] ?? 0) < ProductMatchService::MIN_MATCH_SCORE) {
            $highlights[] = 'Słabe dopasowanie do SIWZ (< '.ProductMatchService::MIN_MATCH_SCORE.'%) — zweryfikuj ręcznie.';
        }

        return array_slice($highlights, 0, 4);
    }
}
