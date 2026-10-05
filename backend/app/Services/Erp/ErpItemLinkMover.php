<?php

declare(strict_types=1);

namespace App\Services\Erp;

use App\Models\ErpItemLink;

/**
 * Scalenie kart: powiązania towarów ERP XL ze scalanymi kartami przechodzą na kartę, która zostaje (inaczej usunięcie
 * karty wyzerowałoby product_id i ręczne potwierdzenie przepadło). Gdy karta docelowa ma już powiązanie z tym samym
 * towarem, zostaje mocniejsze: confirmed > rejected > auto > suggested — decyzja człowieka wygrywa z automatem.
 */
final class ErpItemLinkMover
{
    private const RANK = [
        ErpItemLink::STATUS_CONFIRMED => 4,
        ErpItemLink::STATUS_REJECTED => 3,
        ErpItemLink::STATUS_AUTO => 2,
        ErpItemLink::STATUS_SUGGESTED => 1,
    ];

    /**
     * @param  list<int>  $fromIds
     * @return int liczba przepiętych albo scalonych powiązań
     */
    public function move(array $fromIds, int $toId): int
    {
        $fromIds = array_values(array_diff(array_map('intval', $fromIds), [$toId]));
        if ($fromIds === []) {
            return 0;
        }
        $moved = 0;
        $links = ErpItemLink::query()->whereIn('product_id', $fromIds)->orderBy('id')->get();
        foreach ($links as $link) {
            $existing = ErpItemLink::query()
                ->where('erp_item_id', $link->erp_item_id)
                ->where('product_id', $toId)
                ->first();
            if ($existing === null) {
                $link->update(['product_id' => $toId]);
            } else {
                if ((self::RANK[$link->status] ?? 0) > (self::RANK[$existing->status] ?? 0)) {
                    $existing->fill($link->only(['status', 'method', 'matched_value', 'matched_code', 'evidence', 'decided_by', 'decided_at', 'auto_linked_at', 'last_seen_at']))->save();
                }
                $link->delete();
            }
            $moved++;
        }

        return $moved;
    }
}
