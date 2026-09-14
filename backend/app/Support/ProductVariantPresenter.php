<?php

declare(strict_types=1);

namespace App\Support;

use App\Models\ProductVariant;
use DateTimeInterface;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Wersje karty (np. format × podłoże znaku) dla API: „od–do” z aktywnych wersji z ceną,
 * ostatnia zmiana ceny każdej wersji i historia cen jednej wersji.
 *
 * Cena 0 albo brak ceny = wersja bez ceny (jak na karcie) — nie liczy się do „od–do”.
 * Przy różnych walutach „od–do” nie jest liczone (bez przeliczania kursem).
 */
final class ProductVariantPresenter
{
    private const IDS_PER_QUERY = 1000;

    public function __construct(private readonly ProductPriceChangeResolver $priceChanges) {}

    /**
     * Pola listy produktów — jedno zapytanie grupujące na stronę.
     *
     * @param  list<int>  $productIds
     * @return array<int, array{variants_count: int, variants_min_price: string|null, variants_currency: string|null}>
     */
    public function listSummaries(array $productIds): array
    {
        $productIds = array_values(array_unique(array_map('intval', $productIds)));
        $result = [];
        foreach (array_chunk($productIds, self::IDS_PER_QUERY) as $chunk) {
            $rows = DB::table('product_variants')
                ->selectRaw('product_id, COUNT(*) AS active_count')
                ->selectRaw('MIN(CASE WHEN purchase_price > 0 THEN purchase_price END) AS min_price')
                ->selectRaw('MIN(CASE WHEN purchase_price > 0 THEN currency END) AS min_currency')
                ->selectRaw('MAX(CASE WHEN purchase_price > 0 THEN currency END) AS max_currency')
                ->whereIn('product_id', $chunk)
                ->whereNull('removed_at')
                ->groupBy('product_id')
                ->get();

            foreach ($rows as $row) {
                $sameCurrency = $row->min_currency !== null && $row->min_currency === $row->max_currency;
                $result[(int) $row->product_id] = [
                    'variants_count' => (int) $row->active_count,
                    'variants_min_price' => $sameCurrency ? $this->money($row->min_price) : null,
                    'variants_currency' => $sameCurrency ? (string) $row->min_currency : null,
                ];
            }
        }

        return $result;
    }

    /**
     * Pole `variants` karty; null, gdy karta nie ma wersji.
     *
     * @return array<string, mixed>|null
     */
    public function forProduct(int $productId): ?array
    {
        $variants = ProductVariant::query()
            ->where('product_id', $productId)
            ->orderBy('sort_order')
            ->orderBy('id')
            ->get();
        if ($variants->isEmpty()) {
            return null;
        }

        $changes = $this->latestChanges($variants->pluck('id')->map(static fn (mixed $id): int => (int) $id)->all());

        $active = $variants->filter(static fn (ProductVariant $v): bool => $v->removed_at === null);
        $priced = $active->filter(static fn (ProductVariant $v): bool => $v->purchase_price !== null && (float) $v->purchase_price > 0);
        $currencies = $priced->map(static fn (ProductVariant $v): ?string => $v->currency)->unique()->values();
        $currency = $currencies->count() === 1 && $currencies->first() !== null ? (string) $currencies->first() : null;

        $dimensions = [];
        foreach ($variants as $variant) {
            foreach (array_keys(is_array($variant->attributes) ? $variant->attributes : []) as $key) {
                $key = (string) $key;
                if (! in_array($key, $dimensions, true)) {
                    $dimensions[] = $key;
                }
            }
        }

        $sources = ($active->isNotEmpty() ? $active : $variants)
            ->map(static fn (ProductVariant $v): string => (string) $v->source)
            ->filter(static fn (string $s): bool => $s !== '')
            ->unique()
            ->values();

        return [
            'count' => $variants->count(),
            'active_count' => $active->count(),
            'min_price' => $currency !== null ? $this->money($priced->min(static fn (ProductVariant $v): float => (float) $v->purchase_price)) : null,
            'max_price' => $currency !== null ? $this->money($priced->max(static fn (ProductVariant $v): float => (float) $v->purchase_price)) : null,
            'currency' => $currency,
            'source_label' => $sources->isNotEmpty()
                ? $sources->map(fn (string $s): string => $this->priceChanges->sourceLabel($s, false, null))->implode(', ')
                : null,
            'dimensions' => $dimensions,
            'items' => $variants->map(fn (ProductVariant $v): array => [
                'id' => (int) $v->id,
                'remote_id' => (string) $v->remote_id,
                'label' => (string) $v->label,
                'attributes' => is_array($v->attributes) && $v->attributes !== [] ? $v->attributes : (object) [],
                'purchase_price' => $this->money($v->purchase_price),
                'list_price_net' => $this->money($v->list_price_net),
                'currency' => $v->currency,
                'vat_rate' => $v->vat_rate !== null ? (float) $v->vat_rate : null,
                'unit' => $v->unit,
                'source_url' => $v->source_url,
                'sort_order' => (int) $v->sort_order,
                'price_checked_at' => $this->iso($v->price_checked_at),
                'last_seen_at' => $this->iso($v->last_seen_at),
                'removed_at' => $this->iso($v->removed_at),
                'last_price_change' => $changes[(int) $v->id] ?? null,
            ])->values()->all(),
        ];
    }

    /**
     * Historia cen jednej wersji od najnowszej, z poprzednią ceną i zmianą procentową.
     *
     * @return list<array<string, mixed>>
     */
    public function history(int $variantId, int $limit = 100): array
    {
        // Jeden wiersz więcej, żeby najstarszy pokazany miał poprzednią wartość.
        $rows = DB::table('product_variant_price_history')
            ->select(['id', 'b2b_sync_run_id', 'purchase_price', 'list_price_net', 'currency', 'source', 'created_at'])
            ->where('product_variant_id', $variantId)
            ->orderByDesc('created_at')
            ->orderByDesc('id')
            ->limit($limit + 1)
            ->get()
            ->values();

        $out = [];
        foreach ($rows->take($limit) as $i => $row) {
            $previous = $rows->get($i + 1);
            $out[] = [
                'id' => (int) $row->id,
                'purchase_price' => $this->money($row->purchase_price),
                'list_price_net' => $this->money($row->list_price_net),
                'currency' => $row->currency,
                'source' => $row->source,
                'source_label' => $this->priceChanges->sourceLabel($row->source, false, null),
                'b2b_sync_run_id' => $row->b2b_sync_run_id !== null ? (int) $row->b2b_sync_run_id : null,
                'created_at' => $this->iso($row->created_at),
                'purchase_old' => $previous !== null ? $this->money($previous->purchase_price) : null,
                'purchase_pct' => $previous !== null ? $this->pct($previous->purchase_price, $row->purchase_price) : null,
            ];
        }

        return $out;
    }

    /**
     * Ostatnia zmiana ceny zakupu każdej wersji — jedno zapytanie na paczkę wersji.
     * Pierwszy wiersz wersji to dodanie ceny, nie zmiana.
     *
     * @param  list<int>  $variantIds
     * @return array<int, array{purchase_old: string|null, purchase_new: string|null, pct: float|null, at: string|null, b2b_sync_run_id: int|null}>
     */
    private function latestChanges(array $variantIds): array
    {
        $result = [];
        foreach (array_chunk($variantIds, self::IDS_PER_QUERY) as $chunk) {
            $rows = DB::table('product_variant_price_history')
                ->select(['id', 'product_variant_id', 'b2b_sync_run_id', 'purchase_price', 'created_at'])
                ->whereIn('product_variant_id', $chunk)
                ->orderBy('product_variant_id')
                ->orderBy('created_at')
                ->orderBy('id')
                ->get();

            $previous = null;
            foreach ($rows as $row) {
                if (
                    $previous !== null
                    && (int) $previous->product_variant_id === (int) $row->product_variant_id
                    && $this->money($previous->purchase_price) !== $this->money($row->purchase_price)
                ) {
                    $result[(int) $row->product_variant_id] = [
                        'purchase_old' => $this->money($previous->purchase_price),
                        'purchase_new' => $this->money($row->purchase_price),
                        'pct' => $this->pct($previous->purchase_price, $row->purchase_price),
                        'at' => $this->iso($row->created_at),
                        'b2b_sync_run_id' => $row->b2b_sync_run_id !== null ? (int) $row->b2b_sync_run_id : null,
                    ];
                }
                $previous = $row;
            }
        }

        return $result;
    }

    private function money(mixed $value): ?string
    {
        return $value === null || $value === '' ? null : number_format((float) $value, 2, '.', '');
    }

    private function pct(mixed $old, mixed $new): ?float
    {
        if ($old === null || $old === '' || $new === null || $new === '' || (float) $old <= 0.0) {
            return null;
        }

        return round((((float) $new - (float) $old) / (float) $old) * 100, 2);
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
