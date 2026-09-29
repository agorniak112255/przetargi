<?php

declare(strict_types=1);

namespace App\Services\Erp;

use App\Models\ErpItem;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;

/**
 * Wspólne reguły Zapasów — lista „Zalegające” i raport dla zarządu liczą tak samo.
 *
 * Wartość = ilość × cena zakupu (decyzja użytkownika 30.09.2026): najpierw wartość partii leżących na stanie
 * (TwZ_KsiegowaNetto), a bez niej stan × cena z ostatniej PZ. Sama ostatnia PZ bywa błędna — SNAU51000-04-S: PZ 1 szt.
 * za 11 600,60 zł, poprawione RW 1 szt. + PW 40 szt. po 290,02 zł; partie mają 290,02.
 */
final class InventoryQuery
{
    /**
     * Cena jednostki podstawowej w PLN z ostatniej PZ towaru (ta sama kolejność co ErpItem::purchases). Wartość w SQL,
     * żeby sortowanie i suma szły po całej liście, nie po stronie.
     */
    public const LAST_PRICE_SQL = '(select p.unit_price_pln from erp_item_purchases p where p.erp_item_id = erp_items.id'
        .' and p.unit_price_pln is not null order by p.purchased_at desc, p.document_id desc limit 1)';

    public const VALUE_SQL = '(coalesce(erp_items.stock_value, (erp_items.stock_total * '.self::LAST_PRICE_SQL.')))';

    /** @return Builder<ErpItem> towar z XL (bez usuniętych) ze stanem na którymkolwiek magazynie */
    public static function inStock(): Builder
    {
        return ErpItem::query()->whereNull('removed_at')->where('stock_total', '>', 0);
    }

    /**
     * Ostatnia sprzedaż (FS, paragon, WZ) starsza niż próg. Nigdy niesprzedany liczy się tylko wtedy, gdy jego najstarsza
     * partia leży dłużej niż próg (albo jej data jest nieznana) — inaczej świeża dostawa wyglądałaby jak zaleganie.
     *
     * @param  Builder<ErpItem>  $query
     * @return Builder<ErpItem>
     */
    public static function unsoldSince(Builder $query, CarbonImmutable $cutoff, bool $neverSold = true): Builder
    {
        $date = $cutoff->toDateString();

        return $query->where(function (Builder $q) use ($date, $neverSold): void {
            $q->where('last_sale_at', '<', $date);
            if ($neverSold) {
                $q->orWhere(fn (Builder $n) => $n->whereNull('last_sale_at')
                    ->where(fn (Builder $l) => $l->whereNull('oldest_lot_at')->orWhere('oldest_lot_at', '<=', $date)));
            }
        });
    }

    /**
     * Najstarsza partia na stanie przyjęta najpóźniej w dniu progu. Uwaga: PW z pary RW → PW zakłada nową partię
     * i „odmładza” tę datę.
     *
     * @param  Builder<ErpItem>  $query
     * @return Builder<ErpItem>
     */
    public static function lotOlderThan(Builder $query, CarbonImmutable $cutoff): Builder
    {
        return $query->whereNotNull('oldest_lot_at')->where('oldest_lot_at', '<=', $cutoff->toDateString());
    }

    /**
     * Liczba pozycji, wartość i ile bez wartości — jedno zapytanie.
     *
     * @param  Builder<ErpItem>  $query
     * @return array{items: int, value: float, value_unknown: int}
     */
    public static function totals(Builder $query): array
    {
        $row = (clone $query)->toBase()
            ->selectRaw('count(*) as items, coalesce(sum('.self::VALUE_SQL.'), 0) as value,'
                .' sum(case when '.self::VALUE_SQL.' is null then 1 else 0 end) as value_unknown')
            ->first();

        return [
            'items' => (int) ($row->items ?? 0),
            'value' => round((float) ($row->value ?? 0), 2),
            'value_unknown' => (int) ($row->value_unknown ?? 0),
        ];
    }
}
