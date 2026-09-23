<?php

declare(strict_types=1);

namespace App\Support;

use App\Models\PriceList;
use App\Services\B2b\B2bConnectorRegistry;
use DateTimeInterface;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Zmiany cen produktu z historii (import cennika, synchronizacja B2B).
 *
 * Wiersz historii zapisuje tylko nowe ceny; poprzednia wartość to poprzedni wiersz
 * tego samego produktu z tego samego źródła (kolejność: created_at, id). Karta ma wiersze
 * z kilku źródeł (plik, konta B2B, np. producent w EUR i dystrybutor w PLN) — porównanie
 * między źródłami dawałoby fałszywe skoki. Pierwszy wiersz źródła to dodanie ceny, nie zmiana;
 * wiersze w różnych walutach nie są porównywane (null = waluta nieznana, starsze wiersze).
 */
final class ProductPriceChangeResolver
{
    private const IDS_PER_QUERY = 1000;

    /** Oba źródła piszą ceny slotu pliku — rabat z okna cennika zmienia cenę tego samego źródła. */
    private const FILE_SOURCES = ['price_list_import', 'price_list_discount'];

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
            $rows = $this->historyQuery(['product_id', 'price_list_id', 'catalog_price_net', 'purchase_price', 'currency', 'source', 'created_at'])
                ->whereIn('h.product_id', $chunk)
                ->orderBy('h.product_id')
                ->orderBy('h.created_at')
                ->orderBy('h.id')
                ->get();

            $productId = null;
            /** @var array<string, object> $lastBySource */
            $lastBySource = [];
            foreach ($rows as $row) {
                if ((int) $row->product_id !== $productId) {
                    $productId = (int) $row->product_id;
                    $lastBySource = [];
                }
                $group = $this->sourceGroup($row);
                $previous = $this->comparable($lastBySource[$group] ?? null, $row);
                if ($previous !== null && $this->differs($previous, $row)) {
                    $latest[$productId] = ['row' => $row, 'previous' => $previous];
                }
                $lastBySource[$group] = $row;
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
        // Wszystkie wiersze karty: poprzedni wiersz tego samego źródła może leżeć dowolnie daleko
        // (inne źródła między nimi), więc nie wystarczy jeden wiersz ponad limit.
        $rows = $this->historyQuery(['product_id', 'price_list_id', 'catalog_price_net', 'purchase_price', 'currency', 'source', 'created_at', 'updated_at'])
            ->where('h.product_id', $productId)
            ->orderBy('h.created_at')
            ->orderBy('h.id')
            ->get();

        /** @var array<int, array{previous: object|null, first: bool}> $steps */
        $steps = [];
        /** @var array<string, object> $lastBySource */
        $lastBySource = [];
        foreach ($rows as $row) {
            $group = $this->sourceGroup($row);
            $steps[(int) $row->id] = [
                'previous' => $this->comparable($lastBySource[$group] ?? null, $row),
                'first' => ! isset($lastBySource[$group]),
            ];
            $lastBySource[$group] = $row;
        }
        $rows = $rows->reverse()->take($limit)->values();

        $priceLists = $this->priceLists($rows->pluck('price_list_id')->all());
        $out = [];
        foreach ($rows as $row) {
            ['previous' => $previous, 'first' => $first] = $steps[(int) $row->id];
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
                'currency' => $this->currency($row->currency),
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
                // pierwszy wiersz tego źródła = dodanie ceny; bez poprzedniej wartości także przy zmianie waluty
                'first_in_source' => $first,
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
            'currency' => $this->currency($row->currency),
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

    /**
     * Wiersze historii z kontem B2B przebiegu (dwa konta jednego łącznika to dwa źródła ceny).
     *
     * @param  list<string>  $columns  kolumny product_price_history
     */
    private function historyQuery(array $columns): Builder
    {
        return DB::table('product_price_history as h')
            ->leftJoin('b2b_sync_runs as r', 'r.id', '=', 'h.b2b_sync_run_id')
            ->select(['h.id', ...array_map(static fn (string $c): string => 'h.'.$c, $columns), 'r.b2b_account_id']);
    }

    /**
     * Klucz źródła wiersza historii — jak sloty cen: pliki (import i rabat) to jeden slot, wiersz przebiegu B2B
     * należy do konta. Każde inne źródło osobno, także „b2b:…” bez przebiegu (sprzed dziennika przebiegów albo konta
     * usuniętego), dawne „b2b_api” i puste — nie wiadomo, z którym dzisiejszym slotem je utożsamić.
     */
    private function sourceGroup(object $row): string
    {
        $source = trim((string) $row->source);
        if (in_array($source, self::FILE_SOURCES, true)) {
            return 'file';
        }

        return $row->b2b_account_id !== null ? 'account:'.(int) $row->b2b_account_id : $source;
    }

    /**
     * Poprzedni wiersz źródła, jeśli da się z nim porównać: przy różnych znanych walutach nie da się.
     */
    private function comparable(?object $previous, object $row): ?object
    {
        if ($previous === null) {
            return null;
        }
        $old = $this->currency($previous->currency);
        $new = $this->currency($row->currency);

        return $old !== null && $new !== null && $old !== $new ? null : $previous;
    }

    private function currency(mixed $value): ?string
    {
        $code = strtoupper(trim((string) $value));

        return $code === '' ? null : $code;
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
