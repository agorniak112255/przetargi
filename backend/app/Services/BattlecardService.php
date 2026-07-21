<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\Product;
use App\Models\ProductSubstitute;
use App\Models\TenderItem;
use App\Support\BhpAttributeNormalizer;
use App\Support\OfferPricing;

/**
 * Snapshot pozycji SIWZ: propozycja główna + do 2 zamienników z katalogu.
 * (Katalog = oferta ogólnie dostępna wielu marek — bez bloku „konkurencja”.)
 */
final class BattlecardService
{
    private const SUBSTITUTE_LIMIT = 2;

    private const CATALOG_ALT_MIN_SCORE = 55;

    public function __construct(
        private readonly ProductMatchService $matcher,
        private readonly BhpAttributeNormalizer $bhpAttributes,
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
        $item->loadMissing('mainProduct');
        $ours = $item->mainProduct;
        $excludeIds = [];
        if ($ours !== null) {
            $excludeIds[] = (int) $ours->id;
        }

        $substitutes = $this->buildSubstitutes($ours, $excludeIds);
        $substitutes = $this->fillFromCatalog(
            $item->requirement,
            $substitutes,
            $excludeIds,
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
    private function buildSubstitutes(?Product $ours, array &$excludeIds): array
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
            $excludeIds[] = (int) $p->id;
            $snap = $this->productSnapshot(
                $p,
                (int) ($row->match_percent ?? 0),
                null,
                [],
                null,
                'substitute',
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
    private function fillFromCatalog(string $requirement, array $existing, array $excludeIds): array
    {
        $need = self::SUBSTITUTE_LIMIT - count($existing);
        if ($need <= 0 || trim($requirement) === '') {
            return $existing;
        }

        $pool = Product::query()
            ->when($excludeIds !== [], fn ($q) => $q->whereNotIn('id', array_unique($excludeIds)))
            ->limit(500)
            ->get();

        if ($pool->isEmpty()) {
            return $existing;
        }

        $ranked = $this->matcher->rankProducts($requirement, $pool, $need + 8);
        foreach ($ranked as $row) {
            if ($row['score'] < self::CATALOG_ALT_MIN_SCORE) {
                continue;
            }
            /** @var Product $p */
            $p = $row['product'];
            $snap = $this->productSnapshot($p, $row['score'], null, [], null, 'substitute');
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
    ): array {
        $payload = is_array($product->enrichment_payload) ? $product->enrichment_payload : [];
        $attrs = $this->bhpAttributes->forProduct($product);
        $norms = $product->norms;
        if (($norms === null || $norms === '') && is_array($attrs['normy_en'] ?? null) && $attrs['normy_en'] !== []) {
            $norms = implode(', ', array_map('strval', $attrs['normy_en']));
        } elseif (($norms === null || $norms === '') && is_array($payload['norms'] ?? null)) {
            $norms = implode(', ', array_map('strval', $payload['norms']));
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
            'catalog_price_net' => $product->catalog_price_net !== null
                ? (float) $product->catalog_price_net
                : null,
            'offer_price' => $offerPrice,
            'purchase_price' => $product->purchase_price !== null
                ? (float) $product->purchase_price
                : null,
            'suggested_offer_price' => OfferPricing::fromPurchase($product->purchase_price),
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
        $ourPurchase = (float) ($ours['purchase_price'] ?? 0);
        if ($ourPurchase <= 0) {
            return null;
        }

        $best = null;
        $bestPrice = $ourPurchase;
        foreach ($card['substitutes'] as $sub) {
            $price = (float) ($sub['purchase_price'] ?? 0);
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
