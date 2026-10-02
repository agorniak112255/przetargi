<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\ApproveProductSubstituteRequest;
use App\Http\Requests\StoreProductSubstituteRequest;
use App\Http\Requests\UpdateProductSubstituteRequest;
use App\Models\Product;
use App\Models\ProductSubstitute;
use App\Services\Pricing\SupplierSpecialMask;
use App\Services\Substitutes\SubstituteBoardPresenter;
use Illuminate\Database\Query\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class ProductSubstituteController extends Controller
{
    private const BOARD_PER_PAGE = 20;

    /** Wartości filtra rodziny oznaczające kartę główną bez rodziny („Inne”, klucz null w summary.families). */
    private const NO_FAMILY_KEYS = ['inne', 'null', 'none'];

    public function index(Request $request): JsonResponse
    {
        $query = ProductSubstitute::query()
            ->with([
                'mainProduct:id,sku,name,manufacturer',
                'substituteProduct:id,sku,name,manufacturer,catalog_price_net',
                'approver:id,name',
            ]);

        if ($request->filled('main_product_id')) {
            $query->where('main_product_id', $request->integer('main_product_id'));
        }

        if ($request->filled('approval_status')) {
            $query->where('approval_status', $request->string('approval_status'));
        }

        if ($request->filled('type')) {
            $query->where('type', $request->string('type'));
        }

        if ($request->filled('q')) {
            $term = trim((string) $request->string('q'));
            $like = '%'.$term.'%';
            $query->where(function ($builder) use ($like): void {
                $builder->whereHas('mainProduct', function ($q) use ($like): void {
                    $q->where('sku', 'like', $like)
                        ->orWhere('name', 'like', $like)
                        ->orWhere('manufacturer', 'like', $like);
                })->orWhereHas('substituteProduct', function ($q) use ($like): void {
                    $q->where('sku', 'like', $like)
                        ->orWhere('name', 'like', $like)
                        ->orWhere('manufacturer', 'like', $like);
                });
            });
        }

        $rows = $query->orderBy('main_product_id')->orderByDesc('match_percent')->get();
        $mask = SupplierSpecialMask::forUser($request->user());
        // tylko karty zamienne mają ceny (catalog_price_net) — hurtem, lista jest bez stronicowania
        $mask->preload($rows->pluck('substitute_product_id')->filter()->all());

        return response()->json($rows->map(fn (ProductSubstitute $row): ?ProductSubstitute => $this->masked($row, $mask)));
    }

    public function byMain(Request $request, Product $product): JsonResponse
    {
        $substitutes = ProductSubstitute::query()
            ->with(['substituteProduct', 'approver:id,name'])
            ->where('main_product_id', $product->id)
            ->get();
        $mask = SupplierSpecialMask::forUser($request->user());
        $mask->preload([(int) $product->id, ...$substitutes->pluck('substitute_product_id')->filter()->all()]);
        $presenter = new SubstituteBoardPresenter($mask);
        $presenter->register([$product, ...$substitutes->pluck('substituteProduct')->filter()->all()]);

        return response()->json([
            'main_product' => $mask->maskProduct($product),
            'main_card' => $presenter->card((int) $product->id),
            // kolejność jak na ekranie zamienników: status, typ, cena
            'substitutes' => $presenter->sortRows($substitutes)->map(function (ProductSubstitute $row) use ($mask, $presenter): array {
                $lite = $presenter->row($row);

                return [
                    ...$this->masked($row, $mask)->toArray(),
                    'card' => $lite['product'],
                    'price' => $lite['price'],
                    'summary' => $lite['summary'],
                ];
            })->all(),
        ]);
    }

    /**
     * Liczby do nagłówka i filtrów ekranu zamienników: wiersze wg statusu i pochodzenia, karty główne wg rodziny
     * i producenta.
     */
    public function summary(): JsonResponse
    {
        $totals = DB::table('product_substitutes')
            ->selectRaw('COUNT(*) AS row_count')
            ->selectRaw('COUNT(DISTINCT main_product_id) AS mains')
            ->selectRaw("SUM(CASE WHEN approval_status = 'oczekuje' THEN 1 ELSE 0 END) AS pending")
            ->selectRaw("SUM(CASE WHEN approval_status = 'zatwierdzony' THEN 1 ELSE 0 END) AS approved")
            ->selectRaw("SUM(CASE WHEN approval_status = 'odrzucony' THEN 1 ELSE 0 END) AS rejected")
            ->selectRaw('SUM(CASE WHEN source = ? THEN 1 ELSE 0 END) AS auto_rows', [ProductSubstitute::SOURCE_AUTO])
            ->selectRaw('SUM(CASE WHEN source = ? THEN 1 ELSE 0 END) AS manual_rows', [ProductSubstitute::SOURCE_MANUAL])
            ->first();
        // para zatwierdzona, której automat już nie potwierdza (evidence.stale ≠ null)
        $stale = ProductSubstitute::query()->whereNotNull('evidence->stale')->count();

        $families = [];
        foreach (DB::table('product_substitutes as ps')
            ->join('products as m', 'm.id', '=', 'ps.main_product_id')
            ->selectRaw('m.ppe_family AS family, COUNT(DISTINCT ps.main_product_id) AS mains')
            ->groupBy('m.ppe_family')
            ->get() as $row) {
            // pusta rodzina i brak rodziny to ta sama grupa „Inne”
            $key = $row->family !== null && $row->family !== '' ? (string) $row->family : null;
            $slot = $key ?? '';
            $families[$slot] ??= ['key' => $key, 'label' => SubstituteBoardPresenter::familyLabel($key), 'mains' => 0];
            $families[$slot]['mains'] += (int) $row->mains;
        }
        $families = array_values($families);
        // od najliczniejszej; „Inne” na końcu
        usort($families, static fn (array $a, array $b): int => [$a['key'] === null, $b['mains'], $a['label']] <=> [$b['key'] === null, $a['mains'], $b['label']]);

        $manufacturers = DB::table('product_substitutes as ps')
            ->join('products as m', 'm.id', '=', 'ps.main_product_id')
            ->whereNotNull('m.manufacturer')
            ->where('m.manufacturer', '!=', '')
            ->selectRaw('m.manufacturer AS name, COUNT(DISTINCT ps.main_product_id) AS mains')
            ->groupBy('m.manufacturer')
            ->orderBy('m.manufacturer')
            ->get()
            ->map(static fn (object $row): array => ['name' => (string) $row->name, 'mains' => (int) $row->mains])
            ->values()
            ->all();

        return response()->json([
            'totals' => [
                'mains' => (int) ($totals->mains ?? 0),
                'rows' => (int) ($totals->row_count ?? 0),
                'pending' => (int) ($totals->pending ?? 0),
                'approved' => (int) ($totals->approved ?? 0),
                'rejected' => (int) ($totals->rejected ?? 0),
                'auto' => (int) ($totals->auto_rows ?? 0),
                'manual' => (int) ($totals->manual_rows ?? 0),
                'stale' => $stale,
            ],
            'families' => $families,
            'manufacturers' => $manufacturers,
        ]);
    }

    /**
     * Zamienniki pogrupowane po karcie głównej, 20 kart głównych na stronę. Najpierw strona id kart głównych
     * spełniających filtry (z oczekującymi na początku, potem producent i nazwa), potem wiersze tylko tych kart —
     * w grupie tylko wiersze spełniające filtry.
     */
    public function board(Request $request): JsonResponse
    {
        $page = max(1, $request->integer('page', 1));
        $filtered = $this->boardRows($request);

        $groups = (clone $filtered)
            ->select('ps.main_product_id')
            ->selectRaw("MAX(CASE WHEN ps.approval_status = 'oczekuje' THEN 1 ELSE 0 END) AS has_pending")
            ->groupBy('ps.main_product_id', 'm.manufacturer', 'm.name')
            ->orderByDesc('has_pending')
            ->orderByRaw("CASE WHEN m.manufacturer IS NULL OR m.manufacturer = '' THEN 1 ELSE 0 END")
            ->orderBy('m.manufacturer')
            ->orderBy('m.name')
            ->orderBy('ps.main_product_id')
            ->paginate(self::BOARD_PER_PAGE, ['*'], 'page', $page);

        $mainIds = array_map(static fn (object $row): int => (int) $row->main_product_id, $groups->items());
        $rowIds = $mainIds === []
            ? []
            : (clone $filtered)->whereIn('ps.main_product_id', $mainIds)->pluck('ps.id')->map(static fn (mixed $id): int => (int) $id)->all();
        $rows = $rowIds === []
            ? collect()
            : ProductSubstitute::query()->with('approver:id,name')->whereIn('id', $rowIds)->get();

        $presenter = new SubstituteBoardPresenter(SupplierSpecialMask::forUser($request->user()));
        $presenter->load([...$mainIds, ...$rows->pluck('substitute_product_id')->all()]);
        $byMain = $rows->groupBy('main_product_id');

        $data = [];
        foreach ($mainIds as $mainId) {
            $group = $presenter->sortRows($byMain->get($mainId) ?? collect());
            $data[] = [
                'main' => $presenter->card($mainId),
                'chips' => $presenter->chips($group),
                'substitutes' => $group->map(static fn (ProductSubstitute $row): array => $presenter->row($row))->all(),
            ];
        }

        return response()->json([
            'data' => $data,
            'current_page' => $groups->currentPage(),
            'last_page' => $groups->lastPage(),
            'per_page' => self::BOARD_PER_PAGE,
            'total' => $groups->total(),
        ]);
    }

    public function store(StoreProductSubstituteRequest $request): JsonResponse
    {
        $data = $request->validated();

        $substitute = ProductSubstitute::query()->create([
            ...$data,
            'norms_ok' => $data['norms_ok'] ?? true,
            'certs_ok' => $data['certs_ok'] ?? true,
            'approval_status' => 'oczekuje',
            'approved_by' => null,
        ]);

        return response()->json(
            $this->masked($substitute->fresh([
                'mainProduct:id,sku,name,manufacturer',
                'substituteProduct:id,sku,name,manufacturer,catalog_price_net',
                'approver:id,name',
            ]), SupplierSpecialMask::forUser($request->user())),
            201
        );
    }

    public function update(
        UpdateProductSubstituteRequest $request,
        ProductSubstitute $productSubstitute
    ): JsonResponse {
        $data = $request->validated();

        $contentChanged = $this->contentChanged($productSubstitute, $data);

        $productSubstitute->update([
            ...$data,
            // zmiana treści to nowa propozycja do decyzji; wiersz automatu przejmuje człowiek (dowody zostają do wglądu)
            ...($contentChanged ? [
                'approval_status' => 'oczekuje',
                'approved_by' => null,
                'source' => ProductSubstitute::SOURCE_MANUAL,
            ] : []),
        ]);

        return response()->json(
            $this->masked($productSubstitute->fresh([
                'mainProduct:id,sku,name,manufacturer',
                'substituteProduct:id,sku,name,manufacturer,catalog_price_net',
                'approver:id,name',
            ]), SupplierSpecialMask::forUser($request->user()))
        );
    }

    public function destroy(ProductSubstitute $productSubstitute): JsonResponse
    {
        $user = request()->user();
        if (! $user?->can('substitutes.manage')) {
            abort(403);
        }

        // Propozycji automatu nie kasujemy: odrzucona para zostaje, żeby następny przebieg substitutes:propose
        // nie zaproponował jej znowu.
        if ($productSubstitute->source === ProductSubstitute::SOURCE_AUTO) {
            $productSubstitute->update([
                'approval_status' => 'odrzucony',
                'approved_by' => $user->id,
            ]);

            return response()->json(['ok' => true, 'rejected' => true]);
        }

        $productSubstitute->delete();

        return response()->json(['ok' => true]);
    }

    public function approve(
        ApproveProductSubstituteRequest $request,
        ProductSubstitute $productSubstitute
    ): JsonResponse {
        $data = $request->validated();
        $pending = $data['approval_status'] === 'oczekuje';
        $note = trim((string) ($data['note'] ?? ''));

        $productSubstitute->update([
            'approval_status' => $data['approval_status'],
            'approved_by' => $pending ? null : $request->user()->id,
            // uzasadnienie dotyczy tej decyzji — cofnięcie do „oczekuje” albo decyzja bez notatki je czyści
            'decision_note' => $pending || $note === '' ? null : $note,
        ]);

        return response()->json(
            $this->masked($productSubstitute->fresh([
                'mainProduct:id,sku,name,manufacturer',
                'substituteProduct:id,sku,name,manufacturer,catalog_price_net',
                'approver:id,name',
            ]), SupplierSpecialMask::forUser($request->user()))
        );
    }

    /**
     * Wiersze zamienników z kartą główną (m) i zamiennikiem (s), zawężone filtrami ekranu.
     */
    private function boardRows(Request $request): Builder
    {
        $query = DB::table('product_substitutes as ps')
            ->join('products as m', 'm.id', '=', 'ps.main_product_id')
            ->join('products as s', 's.id', '=', 'ps.substitute_product_id');

        if ($request->filled('q')) {
            $like = '%'.trim((string) $request->string('q')).'%';
            $query->where(static function (Builder $q) use ($like): void {
                foreach (['m.sku', 'm.name', 'm.manufacturer', 's.sku', 's.name', 's.manufacturer'] as $column) {
                    $q->orWhere($column, 'like', $like);
                }
            });
        }
        if ($request->filled('manufacturer')) {
            $query->where('m.manufacturer', (string) $request->string('manufacturer'));
        }
        if ($request->filled('family')) {
            $family = (string) $request->string('family');
            if (in_array($family, self::NO_FAMILY_KEYS, true)) {
                $query->where(static fn (Builder $q) => $q->whereNull('m.ppe_family')->orWhere('m.ppe_family', ''));
            } else {
                $query->where('m.ppe_family', $family);
            }
        }
        foreach (['status' => 'ps.approval_status', 'type' => 'ps.type', 'source' => 'ps.source'] as $param => $column) {
            if ($request->filled($param)) {
                $query->where($column, (string) $request->string($param));
            }
        }

        return $query;
    }

    /**
     * Zamiennik z kartami w widoku cen widza: bez uprawnienia cena specjalna B2B karty zamiennej w cenie
     * standardowej. Relacje podmienione tylko w odpowiedzi (klon karty nie da się zapisać).
     */
    private function masked(?ProductSubstitute $row, SupplierSpecialMask $mask): ?ProductSubstitute
    {
        if ($row === null || ! $mask->hides()) {
            return $row;
        }
        foreach (['substituteProduct', 'mainProduct'] as $relation) {
            $related = $row->relationLoaded($relation) ? $row->getRelation($relation) : null;
            // karta wczytana bez kolumn cen (mainProduct: id, sku, nazwa, producent) nie ma czego ukrywać — bez zapytań maski
            $priced = $related instanceof Product
                && array_intersect_key($related->getAttributes(), array_flip(['catalog_price_net', 'purchase_price', 'discount_percent'])) !== [];
            if ($priced) {
                $row->setRelation($relation, $mask->maskProduct($related));
            }
        }

        return $row;
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function contentChanged(ProductSubstitute $row, array $data): bool
    {
        $keys = [
            'main_product_id',
            'substitute_product_id',
            'type',
            'match_percent',
            'norms_ok',
            'certs_ok',
            'reason',
        ];

        foreach ($keys as $key) {
            if (! array_key_exists($key, $data)) {
                continue;
            }
            $incoming = $data[$key];
            $current = $row->getAttribute($key);
            if ($key === 'norms_ok' || $key === 'certs_ok') {
                if ((bool) $incoming !== (bool) $current) {
                    return true;
                }

                continue;
            }
            if ((string) $incoming !== (string) $current) {
                return true;
            }
        }

        return false;
    }
}
