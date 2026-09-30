<?php

declare(strict_types=1);

namespace App\Services\Tenders;

use App\Models\Tender;
use App\Models\TenderItem;
use App\Services\Pricing\SupplierSpecialMask;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;

/**
 * Przetarg w odpowiedzi API widziany oczami użytkownika (decyzja właściciela 30.09.2026). Bez uprawnienia
 * prices.supplier_special.view karty z ceną specjalną B2B pokazują cenę standardową (zakup, katalogowa, PLN,
 * rozmiary konta), a marże pozycji i przetargu to marża bliźniacza (margin_percent_standard) pod tą samą nazwą
 * margin_percent — panel nie wie o masce. Uprawniony dostaje toArray() bez zmian.
 *
 * Działa na tablicy po wszystkich zapisach: modele zostają prawdziwe (zapis maskowanej kopii i tak rzuca wyjątek).
 */
final class TenderPriceView
{
    /**
     * Przetarg z pozycjami (jeśli wczytane): karty i rozmiary w widoku cen widza, marże bliźniacze.
     *
     * @return array<string, mixed>
     */
    public function tender(Tender $tender, SupplierSpecialMask $mask): array
    {
        $row = $tender->toArray();
        if (! $mask->hides()) {
            return $row;
        }
        $row = $this->withTwinMargin($row, $tender);
        if (! $tender->relationLoaded('items') || ! is_array($row['items'] ?? null)) {
            return $row;
        }
        $mask->preload($this->productIds($tender->items));
        foreach ($tender->items->values() as $i => $item) {
            if (is_array($row['items'][$i] ?? null)) {
                $row['items'][$i] = $this->itemRow($row['items'][$i], $item, $mask);
            }
        }

        return $row;
    }

    /**
     * Jedna pozycja (odpowiedź edycji pozycji).
     *
     * @return array<string, mixed>
     */
    public function item(TenderItem $item, SupplierSpecialMask $mask): array
    {
        $row = $item->toArray();

        return $mask->hides() ? $this->itemRow($row, $item, $mask) : $row;
    }

    /**
     * Przetarg na liście (bez pozycji) — tylko marża bliźniacza.
     *
     * @return array<string, mixed>
     */
    public function summary(Tender $tender, SupplierSpecialMask $mask): array
    {
        $row = $tender->toArray();

        return $mask->hides() ? $this->withTwinMargin($row, $tender) : $row;
    }

    /**
     * Zamienniki kart przetargu pogrupowane po karcie głównej (ProductSubstitute z substituteProduct).
     *
     * @param  Collection<int|string, Collection<int, Model>>  $byMain
     * @return array<int|string, mixed>
     */
    public function substitutesByMain(Collection $byMain, SupplierSpecialMask $mask): array
    {
        $rows = $byMain->toArray();
        if (! $mask->hides()) {
            return $rows;
        }
        $mask->preload($byMain->flatten()->pluck('substitute_product_id')->filter()->map(static fn (mixed $id): int => (int) $id)->all());
        foreach ($rows as $mainId => $group) {
            if (! is_array($group)) {
                continue;
            }
            foreach ($group as $i => $substitute) {
                if (is_array($substitute['substitute_product'] ?? null)) {
                    $rows[$mainId][$i]['substitute_product'] = $mask->productRow($substitute['substitute_product']);
                }
            }
        }

        return $rows;
    }

    /** Marża pozycji w widoku widza (liczba albo null — eksport, pokrycie). */
    public function itemMargin(TenderItem $item, SupplierSpecialMask $mask): ?float
    {
        $margin = $item->getAttribute($mask->hides() ? 'margin_percent_standard' : 'margin_percent');

        return $margin !== null ? (float) $margin : null;
    }

    /** Marża przetargu w widoku widza (liczba albo null). */
    public function tenderMargin(Tender $tender, SupplierSpecialMask $mask): ?float
    {
        $margin = $tender->getAttribute($mask->hides() ? 'margin_percent_standard' : 'margin_percent');

        return $margin !== null ? (float) $margin : null;
    }

    /**
     * @param  array<string, mixed>  $row
     * @return array<string, mixed>
     */
    private function itemRow(array $row, TenderItem $item, SupplierSpecialMask $mask): array
    {
        $row = $this->withTwinMargin($row, $item);
        if (is_array($row['main_product'] ?? null)) {
            $row['main_product'] = $mask->productRow($row['main_product']);
            if (is_array($row['main_product']['active_variants'] ?? null)) {
                $row['main_product']['active_variants'] = array_map(
                    static fn (mixed $variant): mixed => is_array($variant) ? $mask->variantRow($variant) : $variant,
                    $row['main_product']['active_variants'],
                );
            }
        }
        if (is_array($row['main_variant'] ?? null)) {
            $row['main_variant'] = $mask->variantRow($row['main_variant']);
        }
        if (is_array($row['companion_product'] ?? null)) {
            $row['companion_product'] = $mask->productRow($row['companion_product']);
        }
        if (is_array($row['tender'] ?? null) && $item->relationLoaded('tender') && $item->tender !== null) {
            $row['tender'] = $this->withTwinMargin($row['tender'], $item->tender);
        }

        return $row;
    }

    /**
     * margin_percent → marża bliźniacza tego samego modelu (decimal:2 jak oryginał; NULL = „—” w panelu).
     *
     * @param  array<string, mixed>  $row
     * @return array<string, mixed>
     */
    private function withTwinMargin(array $row, Model $model): array
    {
        if (array_key_exists('margin_percent', $row)) {
            $row['margin_percent'] = $model->getAttribute('margin_percent_standard');
        }

        return $row;
    }

    /**
     * @param  Collection<int, TenderItem>  $items
     * @return list<int>
     */
    private function productIds(Collection $items): array
    {
        return $items
            ->flatMap(static fn (TenderItem $item): array => [$item->main_product_id, $item->companion_product_id])
            ->filter()
            ->map(static fn (mixed $id): int => (int) $id)
            ->unique()
            ->values()
            ->all();
    }
}
