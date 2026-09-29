<?php

declare(strict_types=1);

namespace App\Services\Erp;

use App\Models\ErpItem;
use App\Models\ErpItemLink;
use App\Models\Product;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/**
 * Decyzje człowieka o powiązaniach towar ERP XL ↔ karta (ekran „Powiązania z ERP XL”). Potwierdzenie i odrzucenie są
 * trwałe — erp:match ich nie zmienia. Po każdej decyzji wynik na towarze (match_outcome) odpowiada temu, co zostało.
 */
final class ErpLinkDecisions
{
    public function confirm(ErpItemLink $link, User $user): ErpItem
    {
        if ($link->product_id === null) {
            throw new InvalidArgumentException('Karta tego powiązania została usunięta — wybierz kartę ręcznie.');
        }

        return DB::transaction(function () use ($link, $user): ErpItem {
            $link->update(['status' => ErpItemLink::STATUS_CONFIRMED, 'decided_by' => $user->id, 'decided_at' => now()]);
            $this->dropUndecided((int) $link->erp_item_id, (int) $link->id);

            return $this->refreshOutcome((int) $link->erp_item_id);
        });
    }

    public function reject(ErpItemLink $link, User $user): ErpItem
    {
        return DB::transaction(function () use ($link, $user): ErpItem {
            $link->update(['status' => ErpItemLink::STATUS_REJECTED, 'decided_by' => $user->id, 'decided_at' => now()]);

            return $this->refreshOutcome((int) $link->erp_item_id);
        });
    }

    /** Ręczne połączenie z wybraną kartą — także z kartą wcześniej odrzuconą. */
    public function link(ErpItem $item, Product $product, User $user): ErpItem
    {
        return DB::transaction(function () use ($item, $product, $user): ErpItem {
            $link = ErpItemLink::query()->firstOrNew(['erp_item_id' => $item->id, 'product_id' => $product->id]);
            if (! $link->exists || $link->status === ErpItemLink::STATUS_REJECTED) {
                $link->fill(['method' => ErpItemLink::METHOD_MANUAL, 'matched_value' => null, 'matched_code' => null, 'evidence' => null]);
            }
            $link->fill(['status' => ErpItemLink::STATUS_CONFIRMED, 'decided_by' => $user->id, 'decided_at' => now()])->save();
            $this->dropUndecided((int) $item->id, (int) $link->id);

            return $this->refreshOutcome((int) $item->id);
        });
    }

    /**
     * @param  list<int>  $ids
     * @return int potwierdzone
     */
    public function bulkConfirm(array $ids, User $user): int
    {
        $confirmed = 0;
        $links = ErpItemLink::query()
            ->whereIn('id', $ids)
            ->whereIn('status', [ErpItemLink::STATUS_AUTO, ErpItemLink::STATUS_SUGGESTED])
            ->whereNotNull('product_id')
            ->orderBy('id')
            ->get();
        foreach ($links as $link) {
            // wcześniejsze potwierdzenie w tej paczce mogło usunąć tę propozycję (ten sam towar)
            if (! ErpItemLink::query()->whereKey($link->id)->exists()) {
                continue;
            }
            $this->confirm($link, $user);
            $confirmed++;
        }

        return $confirmed;
    }

    /** Automat i propozycje tego towaru odpadają, gdy człowiek wskazał kartę. */
    private function dropUndecided(int $itemId, int $keepLinkId): void
    {
        ErpItemLink::query()
            ->where('erp_item_id', $itemId)
            ->whereKeyNot($keepLinkId)
            ->whereIn('status', [ErpItemLink::STATUS_AUTO, ErpItemLink::STATUS_SUGGESTED])
            ->delete();
    }

    private function refreshOutcome(int $itemId): ErpItem
    {
        $item = ErpItem::query()->findOrFail($itemId);
        $statuses = ErpItemLink::query()
            ->where('erp_item_id', $itemId)
            ->whereNotNull('product_id')
            ->pluck('status')
            ->countBy()
            ->all();
        $suggested = $statuses[ErpItemLink::STATUS_SUGGESTED] ?? 0;
        $outcome = match (true) {
            isset($statuses[ErpItemLink::STATUS_CONFIRMED]) => ErpItemLink::STATUS_CONFIRMED,
            isset($statuses[ErpItemLink::STATUS_AUTO]) => ErpItemLink::STATUS_AUTO,
            // zostało kilka propozycji: dalej „kilka kart” albo „z nazwy karty”
            $suggested > 1 => in_array($item->match_outcome, ['ambiguous', 'name_suggested'], true) ? $item->match_outcome : 'ambiguous',
            $suggested === 1 => $item->match_outcome === 'name_suggested' ? 'name_suggested' : 'suggested',
            isset($statuses[ErpItemLink::STATUS_REJECTED]) => 'rejected',
            default => $item->match_outcome,
        };
        $item->update(['match_outcome' => $outcome]);

        return $item;
    }
}
