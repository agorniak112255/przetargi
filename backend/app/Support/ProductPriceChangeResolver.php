<?php

declare(strict_types=1);

namespace App\Support;

use App\Models\PriceList;
use App\Services\B2b\B2bConnectorRegistry;
use App\Services\Pricing\SupplierSpecialMask;
use DateTimeInterface;
use Generator;
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
    public const FILE_SOURCES = ['price_list_import', 'price_list_discount'];

    public function __construct(private readonly B2bConnectorRegistry $connectors) {}

    /**
     * Ostatnia zmiana ceny dla każdego produktu — kilka zapytań niezależnie od liczby produktów.
     * Zmiana ukryta przed widzem bez uprawnienia do cen specjalnych (historia konta B2B albo pliku z oceną, D1) nie
     * liczy się: zostaje ostatnia zmiana wśród widocznych albo null.
     *
     * @param  list<int>  $productIds
     * @return array<int, array<string, mixed>|null> product_id => zmiana albo null
     */
    public function latestChanges(array $productIds, SupplierSpecialMask $mask): array
    {
        $productIds = array_values(array_unique(array_map('intval', $productIds)));
        $result = array_fill_keys($productIds, null);
        if ($productIds === []) {
            return $result;
        }

        /** @var array<int, array{row: object, previous: object}> $latest */
        $latest = [];
        foreach ($this->steps($productIds, $mask) as ['row' => $row, 'previous' => $previous]) {
            if ($previous !== null && $this->differs($previous, $row) && ! $this->hidden($row, $mask)) {
                $latest[(int) $row->product_id] = ['row' => $row, 'previous' => $previous];
            }
        }

        $priceLists = $this->priceLists(array_map(static fn (array $pair): mixed => $pair['row']->price_list_id, $latest));
        foreach ($latest as $productId => $pair) {
            $result[$productId] = $this->changePayload($pair['row'], $pair['previous'], $priceLists);
        }

        return $result;
    }

    /**
     * Ruchy cen w okresie (raport „Ruchy cen”): każda zmiana z created_at >= $from — wiersz różny od poprzedniego
     * porównywalnego wiersza tej samej grupy źródła — oraz osobno dodania (pierwszy wiersz grupy źródła na karcie).
     * Poprzedni wiersz może leżeć przed okresem, więc karty z ruchem w okresie czytane są z całą historią.
     * Wiersz po zmianie waluty w źródle nie jest ani zmianą, ani dodaniem (nie ma z czym porównać, źródło już było).
     * Wiersze ukryte maską (konto B2B albo plik z oceną ceny specjalnej) pomijane w całości — zmiany i dodania.
     * Strumień, nie lista: przy całej historii z okresu prawie wszystko to dodania (dziesiątki tysięcy elementów),
     * więc wołający trzyma tylko to, czego potrzebuje. Zapytania ruszają przy pierwszej iteracji.
     *
     * @return Generator<int, array<string, mixed>> changePayload + product_id, kind ('change'|'addition'),
     *                                              source_group; kolejność: karta, potem czas
     */
    public function changesSince(DateTimeInterface $from, SupplierSpecialMask $mask): Generator
    {
        // Laravel formatuje datę w zapytaniu bez strefy — granica w strefie aplikacji (tej, w której zapisano created_at).
        $since = Carbon::instance($from)->setTimezone((string) config('app.timezone', 'UTC'))->format('Y-m-d H:i:s');

        $productIds = DB::table('product_price_history')
            ->where('created_at', '>=', $since)
            ->distinct()
            ->orderBy('product_id')
            ->pluck('product_id')
            ->map(static fn (mixed $id): int => (int) $id)
            ->all();
        if ($productIds === []) {
            return;
        }

        // cenniki wierszy okresu z góry — elementy budowane w locie, bez trzymania wierszy wszystkich porcji
        $priceLists = $this->priceLists(DB::table('product_price_history')
            ->where('created_at', '>=', $since)
            ->whereNotNull('price_list_id')
            ->distinct()
            ->pluck('price_list_id')
            ->all());

        foreach ($this->steps($productIds, $mask) as ['row' => $row, 'previous' => $previous, 'first' => $first, 'group' => $group]) {
            if (substr((string) $row->created_at, 0, 19) < $since) {
                continue;
            }
            $kind = $first ? 'addition' : ($previous !== null && $this->differs($previous, $row) ? 'change' : null);
            if ($kind === null || $this->hidden($row, $mask)) {
                continue;
            }
            yield [
                ...$this->changePayload($row, $kind === 'change' ? $previous : null, $priceLists),
                'product_id' => (int) $row->product_id,
                'kind' => $kind,
                'source_group' => $group,
            ];
        }
    }

    /**
     * Wiersze historii kart po kolei (karta, created_at, id) z poprzednim porównywalnym wierszem tej samej grupy
     * źródła. Porcjami po IDS_PER_QUERY kart: jedno zapytanie historii i preload maski na porcję.
     *
     * @param  list<int>  $productIds
     * @return Generator<int, array{row: object, previous: object|null, first: bool, group: string}>
     */
    private function steps(array $productIds, SupplierSpecialMask $mask): Generator
    {
        foreach (array_chunk($productIds, self::IDS_PER_QUERY) as $chunk) {
            $mask->preload($chunk);
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
                $prior = $lastBySource[$group] ?? null;
                yield [
                    'row' => $row,
                    'previous' => $this->comparable($prior, $row),
                    // pierwszy wiersz grupy źródła na karcie = dodanie ceny
                    'first' => $prior === null,
                    'group' => $group,
                ];
                $lastBySource[$group] = $row;
            }
        }
    }

    /**
     * Historia cen produktu od najnowszej, z poprzednią wartością i zmianą procentową. Wiersz konta B2B albo pliku
     * z oceną ceny specjalnej widz bez uprawnienia dostaje bez cen (prices_hidden) — dawne wiersze mogły być ceną
     * specjalną.
     *
     * @return list<array<string, mixed>>
     */
    public function history(int $productId, int $limit, SupplierSpecialMask $mask): array
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
            // poprzedni wiersz jest z tej samej grupy źródła, więc ukrywa się razem z bieżącym
            $hidden = $this->hidden($row, $mask);

            $out[] = [
                'id' => (int) $row->id,
                'product_id' => (int) $row->product_id,
                'price_list_id' => $row->price_list_id !== null ? (int) $row->price_list_id : null,
                'catalog_price_net' => ! $hidden && $row->catalog_price_net !== null ? number_format((float) $row->catalog_price_net, 2, '.', '') : null,
                'purchase_price' => ! $hidden && $row->purchase_price !== null ? number_format((float) $row->purchase_price, 2, '.', '') : null,
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
                'purchase_old' => $hidden ? null : $purchaseOld,
                'catalog_old' => $hidden ? null : $catalogOld,
                'purchase_pct' => ! $hidden && $previous !== null ? $this->pct($purchaseOld, $purchaseNew) : null,
                'catalog_pct' => ! $hidden && $previous !== null ? $this->pct($catalogOld, $catalogNew) : null,
                // pierwszy wiersz tego źródła = dodanie ceny; bez poprzedniej wartości także przy zmianie waluty
                'first_in_source' => $first,
                'prices_hidden' => $hidden,
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
        if ($source === 'price_list_discount') {
            // rabat zmieniony w oknie cennika (PriceListDiscountService) — nasza decyzja, nie ruch dostawcy
            return 'Zmiana rabatu · '.($listLabel ?? 'Cennik (usunięty)');
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
    private function changePayload(object $row, ?object $previous, array $priceLists): array
    {
        $list = $row->price_list_id !== null ? ($priceLists[(int) $row->price_list_id] ?? null) : null;
        // bez poprzedniego wiersza (dodanie ceny) stare ceny i procenty są null
        $purchaseOld = $this->price($previous?->purchase_price);
        $purchaseNew = $this->price($row->purchase_price);
        $catalogOld = $this->price($previous?->catalog_price_net);
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
     * Wiersz historii ukryty przed widzem bez uprawnienia do cen specjalnych: cena konta B2B, którego slot na karcie
     * ma ocenę (cennik bazowy i rabat standardowy). Konto z przebiegu; wiersz „b2b…” bez przebiegu (sprzed dziennika
     * przebiegów, scalanie rozmiarów, dawne „b2b_api”) nie wskazuje konta — wtedy dowolny slot konta z oceną na karcie.
     * Import i rabat cennika z pliku — gdy wiersz pochodzi z cennika z ceną specjalną (price_lists.has_supplier_special,
     * SECURA: cena 40%) albo karta ma ślad takiego cennika lub slot pliku z oceną (hidesFileHistory). Inne źródła nie.
     */
    private function hidden(object $row, SupplierSpecialMask $mask): bool
    {
        if (! $mask->hides()) {
            return false;
        }
        if ($row->b2b_account_id !== null) {
            return $mask->hidesHistory((int) $row->product_id, (int) $row->b2b_account_id);
        }
        if (in_array(trim((string) $row->source), self::FILE_SOURCES, true)) {
            return $mask->flaggedPriceList($row->price_list_id !== null ? (int) $row->price_list_id : null)
                || $mask->hidesFileHistory((int) $row->product_id);
        }

        return str_starts_with(trim((string) $row->source), 'b2b')
            && $mask->hidesHistory((int) $row->product_id, null);
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

        // Szybka ścieżka dla surowej daty z bazy przy strefie UTC — ten sam wynik bez Carbon::parse, który przy
        // dziesiątkach tysięcy wierszy (raport „Ruchy cen”) zajmuje sekundy.
        if (is_string($value) && date_default_timezone_get() === 'UTC'
            && preg_match('/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}$/', $value) === 1) {
            return substr($value, 0, 10).'T'.substr($value, 11).'.000000Z';
        }

        // Ten sam format co serializacja dat modeli Laravela.
        return ($value instanceof DateTimeInterface ? Carbon::instance($value) : Carbon::parse((string) $value))->toISOString();
    }
}
