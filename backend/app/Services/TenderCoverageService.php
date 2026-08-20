<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\ProductSubstitute;
use App\Models\Tender;
use App\Models\TenderItem;
use App\Services\Ai\AiSettingsService;

final class TenderCoverageService
{
    public const MIN_MATCH_SCORE = ProductMatchService::MIN_MATCH_SCORE;

    /** Minimalna akceptowalna marża pozycji (%). */
    public const MIN_MARGIN_PERCENT = 15.0;

    public function __construct(
        private readonly AiSettingsService $aiSettings,
    ) {}

    /**
     * @return array{
     *     total: int,
     *     with_product: int,
     *     without_product: int,
     *     without_price: int,
     *     weak_match: int,
     *     low_margin: int,
     *     substitutes_pending: int,
     *     ready: bool,
     *     blockers: list<string>,
     *     item_ids: array{
     *         without_product: list<int>,
     *         without_price: list<int>,
     *         weak_match: list<int>,
     *         low_margin: list<int>
     *     }
     * }
     */
    public function summarize(Tender $tender): array
    {
        $tender->loadMissing('items');

        $withoutProduct = [];
        $withoutPrice = [];
        $weakMatch = [];
        $lowMargin = [];

        foreach ($tender->items as $item) {
            /** @var TenderItem $item */
            if (! $item->hasOfferProduct()) {
                $withoutProduct[] = $item->id;
            }
            if ($item->offer_price === null) {
                $withoutPrice[] = $item->id;
            }
            if (
                $item->main_product_id !== null
                && ($item->ai_match_percent === null || (int) $item->ai_match_percent < self::MIN_MATCH_SCORE)
            ) {
                $weakMatch[] = $item->id;
            }
            if (
                $item->margin_percent !== null
                && (float) $item->margin_percent < self::MIN_MARGIN_PERCENT
            ) {
                $lowMargin[] = $item->id;
            }
        }

        $mainIds = $tender->items->pluck('main_product_id')->filter()->unique()->values();
        $subsPending = $mainIds->isEmpty()
            ? 0
            : ProductSubstitute::query()
                ->whereIn('main_product_id', $mainIds)
                ->where('approval_status', 'oczekuje')
                ->count();

        $total = $tender->items->count();
        $withProduct = $total - count($withoutProduct);

        $blockers = [];
        if ($withoutProduct !== []) {
            $blockers[] = 'Brak produktu: '.count($withoutProduct);
        }
        if ($withoutPrice !== []) {
            $blockers[] = 'Brak ceny: '.count($withoutPrice);
        }
        if ($weakMatch !== []) {
            $blockers[] = 'Słabe dopasowanie (<'.self::MIN_MATCH_SCORE.'%): '.count($weakMatch);
        }
        if ($lowMargin !== []) {
            $blockers[] = 'Niska marża (<'.(int) self::MIN_MARGIN_PERCENT.'%): '.count($lowMargin);
        }
        if ($subsPending > 0) {
            $blockers[] = 'Zamienniki oczekujące: '.$subsPending;
        }

        return [
            'total' => $total,
            'with_product' => $withProduct,
            'without_product' => count($withoutProduct),
            'without_price' => count($withoutPrice),
            'weak_match' => count($weakMatch),
            'low_margin' => count($lowMargin),
            'substitutes_pending' => $subsPending,
            'ready' => $blockers === [] && $total > 0,
            'blockers' => $blockers,
            'item_ids' => [
                'without_product' => $withoutProduct,
                'without_price' => $withoutPrice,
                'weak_match' => $weakMatch,
                'low_margin' => $lowMargin,
            ],
            'thresholds' => [
                'min_match_score' => self::MIN_MATCH_SCORE,
                'min_margin_percent' => self::MIN_MARGIN_PERCENT,
                'match_concurrency' => $this->aiSettings->matchConcurrency(),
            ],
        ];
    }
}
