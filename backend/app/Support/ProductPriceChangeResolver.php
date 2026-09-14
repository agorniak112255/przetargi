<?php

declare(strict_types=1);

namespace App\Support;

use App\Models\PriceList;
use App\Services\B2b\B2bConnectorRegistry;
use DateTimeInterface;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Zmiany cen produktu z historii (import cennika, synchronizacja B2B).
 *
 * Wiersz historii zapisuje tylko nowe ceny; poprzednia wartość to poprzedni wiersz
 * tego samego produktu (kolejność: created_at, id). Pierwszy wiersz produktu to
 * dodanie ceny, nie zmiana.
 */
final class ProductPriceChangeResolver
{
    private const IDS_PER_QUERY = 1000;

    public function __construct(private readonly B2bConnectorRegistry $connectors) {}

    /**
     * Ostatnia zmiana ceny dla każdego produktu — kilka zapytań niezależnie od liczby produktów.
     *
     * @param  list<int>  $productIds
     * @return array<int, array<string, mixed>|null> product_id => zmiana albo null
     */
    public function latestChanges(array $productIds): array
    {
        $productIds = array_values(array_unique(array_map('intval', $productIds)));
        $result = array_fill_keys($productIds, null);
        if ($productIds === []) {
            return $result;
        }

        /** @var array<int, array{row: object, previous: object}> $latest */
        $latest = [];
        foreach (array_chunk($productIds, self::IDS_PER_QUERY) as $chunk) {
            $rows = DB::table('product_price_history')
                ->select(['id', 'product_id', 'price_list_id', 'catalog_price_net', 'purchase_price', 'source', 'created_at'])
                ->whereIn('product_id', $chunk)
                ->orderBy('product_id')
                ->orderBy('created_at')
                ->orderBy('id')
                ->get();

            $previous = null;
            foreach ($rows as $row) {
                if ($previous !== null && (int) $previous->product_id === (int) $row->product_id && $this->differs($previous, $row)) {
                    $latest[(int) $row->product_id] = ['row' => $row, 'previous' => $previous];
                }
                $previous = $row;
            }
        }

        $priceLists = $this->priceLists(array_map(static fn (array $pair): mixed => $pair['row']->price_list_id, $latest));
        foreach ($latest as $productId => $pair) {
            $result[$productId] = $this->changePayload($pair['row'], $pair['previous'], $priceLists);
        }

        return $result;
    }

    /**
     * Historia cen produktu od najnowszej, z poprzednią wartością i zmianą procentową.
     *
     * @return list<array<string, mixed>>
     */
    public function history(int $productId, int $limit = 100): array
    {
        // Jeden wiersz więcej, żeby najstarszy pokazany miał poprzednią wartość.
        $rows = DB::table('product_price_history')
            ->select(['id', 'product_id', 'price_list_id', 'catalog_price_net', 'purchase_price', 'source', 'created_at', 'updated_at'])
            ->where('product_id', $productId)
            ->orderByDesc('created_at')
            ->orderByDesc('id')
            ->limit($limit + 1)
            ->get()
            ->values();

        $priceLists = $this->priceLists($rows->pluck('price_list_id')->all());
        $out = [];
        foreach ($rows->take($limit) as $i => $row) {
            $previous = $rows->get($i + 1);
            $list = $row->price_list_id !== null ? ($priceLists[(int) $row->price_list_id] ?? null) : null;
            $purchaseOld = $previous !== null ? $this->price($previous->purchase_price) : null;
            $catalogOld = $previous !== null ? $this->price($previous->catalog_price_net) : null;
            $purchaseNew = $this->price($row->purchase_price);
            $catalogNew = $this->price($row->catalog_price_net);

            $out[] = [
                'id' => (int) $row->id,
                'product_id' => (int) $row->product_id,
                'price_list_id' => $row->price_list_id !== null ? (int) $row->price_list_id : null,
                'catalog_price_net' => $row->catalog_price_net !== null ? number_format((float) $row->catalog_price_net, 2, '.', '') : null,
                'purchase_price' => $row->purchase_price !== null ? number_format((float) $row->purchase_price, 2, '.', '') : null,
                'source' => $row->source,
                'source_label' => $this->sourceLabel($row->source, $row->price_list_id !== null, $list),
                'created_at' => $this->iso($row->created_at),
                'updated_at' => $this->iso($row->updated_at),
                'price_list' => $list !== null ? [
                    'id' => (int) $list->id,
                    'manufacturer' => $list->manufacturer,
                    'version' => $list->version,
                    'created_at' => $this->iso($list->created_at),
                ] : null,
                'purchase_old' => $purchaseOld,
                'catalog_old' => $catalogOld,
                'purchase_pct' => $previous !== null ? $this->pct($purchaseOld, $purchaseNew) : null,
                'catalog_pct' => $previous !== null ? $this->pct($catalogOld, $catalogNew) : null,
            ];
        }

        return $out;
    }

    /**
     * Czytelna nazwa źródła ceny. Nieznane źródło zostaje pokazane dosłownie.
     */
    public function sourceLabel(?string $source, bool $hadPriceList, ?PriceList $list): string
    {
        $source = trim((string) $source);
        $listLabel = $list !== null ? trim('Cennik '.trim((string) $list->manufacturer).' '.trim((string) $list->version)) : null;

        if ($source === 'price_list_import') {
            return $listLabel ?? 'Cennik (usunięty)';
        }
        if (str_starts_with($source, 'b2b:')) {
            $key = substr($source, 4);

            return ($this->connectors->label($key) ?? $key).' B2B';
        }
        if ($source === 'b2b_api') {
            return $listLabel !== null ? 'B2B · '.$listLabel : 'B2B';
        }
        if ($source === '') {
            if ($listLabel !== null) {
                return $listLabel;
            }

            return $hadPriceList ? 'Cennik (usunięty)' : 'brak źródła';
        }

        return $source;
    }

    /**
     * @param  array<int, PriceList>  $priceLists
     * @return array<string, mixed>
     */
    private function changePayload(object $row, object $previous, array $priceLists): array
    {
        $list = $row->price_list_id !== null ? ($priceLists[(int) $row->price_list_id] ?? null) : null;
        $purchaseOld = $this->price($previous->purchase_price);
        $purchaseNew = $this->price($row->purchase_price);
        $catalogOld = $this->price($previous->catalog_price_net);
        $catalogNew = $this->price($row->catalog_price_net);
        $purchasePct = $this->pct($purchaseOld, $purchaseNew);
        $catalogPct = $this->pct($catalogOld, $catalogNew);

        // Procent liczony od zakupu; od katalogu, gdy zakupu nie da się porównać
        // albo zmienił się tylko katalog (inaczej zmiana wyglądałaby na 0%).
        $purchaseChanged = $purchaseOld !== $purchaseNew;
        $basis = $purchasePct !== null && ($purchaseChanged || $catalogPct === null) ? 'purchase' : ($catalogPct !== null ? 'catalog' : null);

        return [
            'at' => $this->iso($row->created_at),
            'source' => $row->source,
            'source_label' => $this->sourceLabel($row->source, $row->price_list_id !== null, $list),
            'purchase_old' => $purchaseOld,
            'purchase_new' => $purchaseNew,
            'catalog_old' => $catalogOld,
            'catalog_new' => $catalogNew,
            'pct' => $basis === 'purchase' ? $purchasePct : ($basis === 'catalog' ? $catalogPct : null),
            'pct_basis' => $basis,
            'purchase_pct' => $purchasePct,
            'catalog_pct' => $catalogPct,
        ];
    }

    /**
     * @param  array<int|string, mixed>  $ids
     * @return array<int, PriceList>
     */
    private function priceLists(array $ids): array
    {
        $ids = array_values(array_unique(array_filter(array_map('intval', array_filter($ids, static fn (mixed $id): bool => $id !== null)))));
        if ($ids === []) {
            return [];
        }

        return PriceList::query()
            ->select(['id', 'manufacturer', 'version', 'original_filename', 'created_at'])
            ->whereIn('id', $ids)
            ->get()
            ->keyBy('id')
            ->all();
    }

    private function differs(object $previous, object $row): bool
    {
        return $this->price($previous->purchase_price) !== $this->price($row->purchase_price)
            || $this->price($previous->catalog_price_net) !== $this->price($row->catalog_price_net);
    }

    private function price(mixed $value): ?float
    {
        return $value === null || $value === '' ? null : round((float) $value, 2);
    }

    private function pct(?float $old, ?float $new): ?float
    {
        if ($old === null || $new === null || $old <= 0.0) {
            return null;
        }

        return round((($new - $old) / $old) * 100, 2);
    }

    private function iso(mixed $value): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }

        // Ten sam format co serializacja dat modeli Laravela.
        return ($value instanceof DateTimeInterface ? Carbon::instance($value) : Carbon::parse((string) $value))->toISOString();
    }
}
