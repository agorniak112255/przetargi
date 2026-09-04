<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\Product;
use App\Models\ProductSubstitute;
use App\Models\TenderItem;
use App\Services\Ai\AiSettingsService;
use App\Services\Ai\AiTask;
use App\Support\BhpAttributeNormalizer;
use App\Support\OfferPricing;
use App\Support\PpeAssortment;
use Throwable;

/**
 * Snapshot pozycji SIWZ: propozycja główna + do 8 zamienników z katalogu.
 * (Katalog = oferta ogólnie dostępna wielu marek — bez bloku „konkurencja”.)
 */
final class BattlecardService
{
    private const SUBSTITUTE_LIMIT = 8;

    private const CATALOG_ALT_MIN_SCORE = 55;

    public function __construct(
        private readonly ProductMatchService $matcher,
        private readonly ProductAiSearchService $aiSearch,
        private readonly AiSettingsService $aiSettings,
        private readonly BhpAttributeNormalizer $bhpAttributes,
        private readonly PpeAssortment $assortment,
        private readonly NbpExchangeRateService $fx,
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
    public function forItem(TenderItem $item): array
    {
        $item->loadMissing(['mainProduct', 'tender']);
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
        );
        $substitutes = $this->sortSubstitutesCheapestFirst($substitutes);

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
            'substitutes' => array_slice($substitutes, 0, self::SUBSTITUTE_LIMIT),
            'competitors' => [],
            'highlights' => [],
        ];

        $card['highlights'] = $this->buildHighlights($card);

        return $card;
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
            $out[] = $snap;
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
    private function fillFromCatalog(string $requirement, array $existing, array $excludeIds, ?float $markupPercent): array
    {
        $need = self::SUBSTITUTE_LIMIT - count($existing);
        if ($need <= 0 || trim($requirement) === '') {
            return $existing;
        }

        $scored = $this->scoreCatalogAlternates($requirement, $excludeIds, $need + 8);
        if ($scored === []) {
            return $existing;
        }
        $top = $scored[0]['score'];
        $minKeep = max(self::CATALOG_ALT_MIN_SCORE, $top - 8);
        foreach ($scored as $row) {
            if ($row['score'] < $minKeep) {
                continue;
            }
            $snap = $this->productSnapshot($row['product'], $row['score'], null, [], null, 'substitute', $markupPercent);
            $snap['source'] = 'catalog';
            $snap['substitute_type'] = 'katalog';
            $existing[] = $snap;
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
     * @return list<array{product: Product, score: int}>
     */
    private function scoreCatalogAlternates(string $requirement, array $excludeIds, int $limit): array
    {
        $rows = [];
        $fromLlm = false;
        if ($this->aiSettings->isReady()) {
            try {
                $result = $this->aiSearch->search($requirement, max(8, $limit), false, AiTask::ProductSearch);
                $rows = is_array($result['products'] ?? null) ? $result['products'] : [];
                $fromLlm = $rows !== [];
            } catch (Throwable) {
                $rows = [];
            }
        }
        if ($rows === []) {
            $rows = $this->aiSearch->requirementCatalogRows($requirement, max(8, $limit));
        }

        $blocked = array_fill_keys($excludeIds, true);
        $scored = [];
        if ($rows === []) {
            return $this->scoreFamilyAlternates($requirement, $blocked, $limit);
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
            $score = $fromLlm
                ? (int) ($row['ai_match_percent'] ?? $explained['score'])
                : $explained['score'];
            if ($score < self::CATALOG_ALT_MIN_SCORE) {
                continue;
            }
            $scored[] = ['product' => $product, 'score' => $score];
        }
        usort($scored, static fn (array $a, array $b): int => $b['score'] <=> $a['score']);

        return $scored;
    }

    /**
     * @param  array<int, true>  $blocked
     * @return list<array{product: Product, score: int}>
     */
    private function scoreFamilyAlternates(string $requirement, array $blocked, int $limit): array
    {
        $family = $this->assortment->family($requirement);
        $query = Product::query();
        if ($family !== null) {
            $query->where('ppe_family', $family);
        }
        $exclude = array_keys($blocked);
        if ($exclude !== []) {
            $query->whereNotIn('id', $exclude);
        }
        $scored = [];
        foreach ($query->limit(max(20, $limit))->get() as $product) {
            if (! $product instanceof Product || ! $this->assortment->compatibleProduct($requirement, $product)) {
                continue;
            }
            $explained = $this->matcher->explainMatch($requirement, $product);
            if (($explained['reasons'][0]['code'] ?? '') === 'asortyment_reject'
                || $explained['score'] < self::CATALOG_ALT_MIN_SCORE) {
                continue;
            }
            $scored[] = ['product' => $product, 'score' => $explained['score']];
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
