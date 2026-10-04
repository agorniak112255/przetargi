<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\PriceList;
use App\Models\ProductSourcePrice;

/**
 * Karty cennika producenta: price_lists.product_ids (karty OSTATNIEGO importu) razem z kartami, które mają slot ceny
 * z pliku tego cennika (product_source_prices source_key „file”, price_list_id). Sam product_ids gubi karty
 * z wcześniejszych aktualizacji, których nie było w ostatnim pliku, i karty, na które slot przeniosło scalanie
 * rozmiarów — usunięcie cennika Canis zdjęło 118 takim kartom cenę, a lista w Cennikach ich nie liczyła.
 */
final class PriceListCards
{
    /**
     * @return list<int> posortowane, bez powtórzeń, tylko > 0
     */
    public function ids(PriceList $list): array
    {
        $ids = $this->normalized($list->product_ids ?? []);
        if ($list->id === null) {
            return $ids;
        }
        $slotIds = ProductSourcePrice::query()
            ->where('source_key', ProductSourcePrice::SOURCE_FILE)
            ->where('price_list_id', $list->id)
            ->pluck('product_id')
            ->all();

        return $this->merged($ids, $slotIds);
    }

    /**
     * To samo co ids() dla wielu cenników — sloty jednym zapytaniem (po price_list_id, bez listy kart w IN).
     *
     * @param  iterable<PriceList>  $lists
     * @return array<int, list<int>> id cennika => karty
     */
    public function idsByList(iterable $lists): array
    {
        $byList = [];
        foreach ($lists as $list) {
            $byList[(int) $list->id] = $list->product_ids ?? [];
        }
        if ($byList === []) {
            return [];
        }

        $slotIds = [];
        $rows = ProductSourcePrice::query()
            ->where('source_key', ProductSourcePrice::SOURCE_FILE)
            ->whereIn('price_list_id', array_keys($byList))
            ->select(['price_list_id', 'product_id'])
            ->toBase()
            ->cursor();
        foreach ($rows as $row) {
            $slotIds[(int) $row->price_list_id][] = (int) $row->product_id;
        }

        $out = [];
        foreach ($byList as $listId => $productIds) {
            $out[$listId] = $this->merged($this->normalized($productIds), $slotIds[$listId] ?? []);
        }

        return $out;
    }

    /**
     * @param  list<int>  $ids
     * @param  array<mixed>  $more
     * @return list<int>
     */
    private function merged(array $ids, array $more): array
    {
        return $more === [] ? $ids : $this->normalized([...$ids, ...$more]);
    }

    /**
     * @param  array<mixed>  $raw
     * @return list<int>
     */
    private function normalized(array $raw): array
    {
        $ids = array_values(array_unique(array_filter(
            array_map('intval', $raw),
            static fn (int $id): bool => $id > 0
        )));
        sort($ids);

        return $ids;
    }
}
