<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\Product;
use App\Models\ProductSubstitute;
use App\Models\TenderItem;
use App\Support\BhpAttributeNormalizer;
use Illuminate\Support\Collection;

/**
 * Snapshot porównawczy pozycji SIWZ: nasz produkt vs zamienniki vs konkurencja z cenników.
 */
final class BattlecardService
{
    private const SUBSTITUTE_LIMIT = 3;

    private const COMPETITOR_LIMIT = 3;

    private const COMPETITOR_MIN_SCORE = 50;

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
        $competitors = $this->buildCompetitors($item->requirement, $ours, $excludeIds);

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
            'substitutes' => $substitutes,
            'competitors' => $competitors,
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
            $out[] = $snap;
        }

        return $out;
    }

    /**
     * Produkty innych producentów z historii cenników (fallback: cały katalog).
     *
     * @param  list<int>  $excludeIds
     * @return list<array<string, mixed>>
     */
    private function buildCompetitors(string $requirement, ?Product $ours, array $excludeIds): array
    {
        $ourMfr = $ours !== null ? $this->normMfr((string) $ours->manufacturer) : '';

        [$pool, $fromPriceList] = $this->competitorPool($excludeIds, $ourMfr);
        if ($pool->isEmpty()) {
            return [];
        }

        $ranked = $this->matcher->rankProducts($requirement, $pool, self::COMPETITOR_LIMIT + 5);
        $out = [];
        foreach ($ranked as $row) {
            if ($row['score'] < self::COMPETITOR_MIN_SCORE) {
                continue;
            }
            /** @var Product $p */
            $p = $row['product'];
            if ($ourMfr !== '' && $this->normMfr((string) $p->manufacturer) === $ourMfr) {
                continue;
            }
            $snap = $this->productSnapshot($p, $row['score'], null, [], null, 'competitor');
            $snap['from_price_list'] = $fromPriceList;
            $out[] = $snap;
            if (count($out) >= self::COMPETITOR_LIMIT) {
                break;
            }
        }

        return $out;
    }

    /**
     * @param  list<int>  $excludeIds
     * @return array{0: Collection<int, Product>, 1: bool}
     */
    private function competitorPool(array $excludeIds, string $ourMfr): array
    {
        $base = Product::query()
            ->when($excludeIds !== [], fn ($q) => $q->whereNotIn('id', array_unique($excludeIds)))
            ->when($ourMfr !== '', function ($q) use ($ourMfr) {
                $q->whereRaw('LOWER(TRIM(manufacturer)) <> ?', [$ourMfr]);
            });

        $fromLists = (clone $base)
            ->whereHas('priceHistory', fn ($q) => $q->where('source', 'price_list_import'))
            ->limit(400)
            ->get();

        if ($fromLists->isNotEmpty()) {
            return [$fromLists, true];
        }

        return [$base->limit(400)->get(), false];
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
            'stock' => (int) ($product->stock ?? 0),
            'match_percent' => $matchPercent,
            'match_source' => $matchSource,
            'reasons' => $reasons,
        ];
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
            $highlights[] = 'Brak produktu głównego — uzupełnij match, aby porównać ofertę.';

            return $highlights;
        }

        $ourPrice = $ours['offer_price'] ?? $ours['catalog_price_net'] ?? null;
        foreach ($card['competitors'] as $comp) {
            $compPrice = $comp['catalog_price_net'] ?? null;
            if ($ourPrice === null || $compPrice === null) {
                continue;
            }
            $diff = ((float) $ourPrice - (float) $compPrice) / (float) $compPrice * 100;
            if ($diff <= -3) {
                $highlights[] = sprintf(
                    'Tańsi od %s (%s) o ok. %.0f%%.',
                    $comp['manufacturer'],
                    $comp['sku'],
                    abs($diff),
                );
            } elseif ($diff >= 3) {
                $highlights[] = sprintf(
                    'Drodzy vs %s (%s) o ok. %.0f%% — sprawdź zamiennik / marżę.',
                    $comp['manufacturer'],
                    $comp['sku'],
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

    private function normMfr(string $mfr): string
    {
        return mb_strtolower(trim($mfr));
    }
}
