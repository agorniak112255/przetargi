<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\PriceList;
use App\Models\Product;
use App\Models\ProductSourcePrice;
use App\Services\Enrichment\PriceListSourceSettings;

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
     * Źródła opisów cennika z pliku, z którego karta ma slot ceny (slot „file” jest jeden na kartę, więc cennik też).
     * null: karta niezapisana, bez slotu pliku, slot bez cennika albo cennik bez stron — przebieg idzie jak dotąd.
     * Celowo bez zakresu partii: ta sama karta ma te same źródła z „Pobierz” na karcie i z partii cennika.
     */
    public function sourceSettingsFor(Product $product): ?PriceListSourceSettings
    {
        if ($product->id === null) {
            return null;
        }
        $listId = ProductSourcePrice::query()
            ->where('product_id', $product->id)
            ->where('source_key', ProductSourcePrice::SOURCE_FILE)
            ->value('price_list_id');
        if ($listId === null) {
            return null;
        }
        $list = PriceList::query()->find((int) $listId);

        return $list !== null ? PriceListSourceSettings::fromList($list) : null;
    }

    /**
     * Karty ze slotem ceny z pliku tego cennika — tylko do nich stosują się źródła opisów cennika (sourceSettingsFor).
     * Bez product_ids: przy wpisie wspólnym z kontem B2B ostatnią aktualizacją bywa synchronizacja konta (Bolle: 414
     * kart konta, 1 z pliku).
     *
     * @return list<int>
     */
    public function fileSlotIds(PriceList $list): array
    {
        return $list->id === null ? [] : ($this->fileSlotIdsByList([(int) $list->id])[(int) $list->id] ?? []);
    }

    /**
     * @param  list<int>  $listIds
     * @return array<int, list<int>> id cennika => karty ze slotem pliku
     */
    public function fileSlotIdsByList(array $listIds): array
    {
        if ($listIds === []) {
            return [];
        }
        $byList = [];
        $rows = ProductSourcePrice::query()
            ->where('source_key', ProductSourcePrice::SOURCE_FILE)
            ->whereIn('price_list_id', $listIds)
            ->select(['price_list_id', 'product_id'])
            ->toBase()
            ->cursor();
        foreach ($rows as $row) {
            $byList[(int) $row->price_list_id][] = (int) $row->product_id;
        }

        return array_map(fn (array $ids): array => $this->normalized($ids), $byList);
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
