<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\B2bAccount;
use App\Models\PriceList;
use App\Models\ProductImportExclusion;
use App\Models\User;
use App\Services\Catalog\ProductImportExclusions;
use App\Services\Pricing\SourcePriceComparison;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Cenniki → Usunięte z pominięciem: karty usunięte z opcją „pomijaj przy imporcie” (grupa = jedno usunięcie karty)
 * i przywracanie ich pozycji. Uprawnienie products.delete — kto usuwa, ten widzi i przywraca.
 */
class ImportExclusionController extends Controller
{
    private const PER_PAGE = 20;

    public function __construct(
        private readonly ProductImportExclusions $exclusions,
        private readonly SourcePriceComparison $comparison,
    ) {}

    public function index(Request $request): JsonResponse
    {
        $data = $request->validate([
            'q' => ['sometimes', 'nullable', 'string', 'max:200'],
            'status' => ['sometimes', 'nullable', 'in:active,restored,all'],
            'page' => ['sometimes', 'integer', 'min:1'],
        ]);
        $status = $data['status'] ?? 'active';
        $q = trim((string) ($data['q'] ?? ''));

        $groups = ProductImportExclusion::query()
            ->select('deletion_id')
            ->selectRaw('MAX(created_at) as deleted_at')
            ->when($status === 'active', static fn ($query) => $query->whereNull('restored_at'))
            ->when($status === 'restored', static fn ($query) => $query->whereNotNull('restored_at'))
            ->when($q !== '', static function ($query) use ($q): void {
                $like = '%'.str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $q).'%';
                $query->where(static function ($inner) use ($like): void {
                    $inner->where('product_sku', 'like', $like)
                        ->orWhere('product_name', 'like', $like)
                        ->orWhere('product_manufacturer', 'like', $like)
                        ->orWhere('position_key', 'like', $like)
                        ->orWhere('remote_sku', 'like', $like)
                        ->orWhere('position_label', 'like', $like);
                });
            })
            ->groupBy('deletion_id')
            ->orderByDesc('deleted_at')
            ->orderByDesc('deletion_id')
            ->paginate(self::PER_PAGE);

        $deletionIds = collect($groups->items())->pluck('deletion_id')->map(static fn (mixed $id): string => (string) $id)->all();
        $rows = $deletionIds === [] ? collect() : ProductImportExclusion::query()
            ->whereIn('deletion_id', $deletionIds)
            ->orderBy('source_key')
            ->orderBy('position_key')
            ->get();

        $accounts = B2bAccount::query()->whereIn('id', $rows->pluck('b2b_account_id')->filter()->unique()->values()->all())->get()->keyBy('id');
        $lists = PriceList::query()->whereIn('id', $rows->pluck('price_list_id')->filter()->unique()->values()->all())->get(['id', 'manufacturer'])->keyBy('id');
        $users = User::query()->whereIn('id', $rows->pluck('deleted_by')->merge($rows->pluck('restored_by'))->filter()->unique()->values()->all())->get(['id', 'name'])->keyBy('id');
        $userName = static fn (?int $id): ?string => $id !== null ? ($users->get($id)?->name ?? 'usunięty użytkownik') : null;

        $byGroup = $rows->groupBy('deletion_id');
        $out = [];
        foreach ($deletionIds as $deletionId) {
            $groupRows = $byGroup->get($deletionId, collect());
            $first = $groupRows->first();
            if (! $first instanceof ProductImportExclusion) {
                continue;
            }
            $snapshot = is_array($first->product_snapshot) ? $first->product_snapshot : [];
            $out[] = [
                'deletion_id' => $deletionId,
                'product' => [
                    'id' => $first->product_id,
                    'sku' => (string) $first->product_sku,
                    'name' => (string) $first->product_name,
                    'manufacturer' => $first->product_manufacturer,
                    'purchase_price' => $snapshot['purchase_price'] ?? null,
                    'catalog_price_net' => $snapshot['catalog_price_net'] ?? null,
                    'currency' => $snapshot['currency'] ?? null,
                ],
                // odpięcie jednego konta od karty, która została (usuwanie z listy kart konta dostawcy)
                'detached_source' => isset($snapshot['detached_source']) ? (string) $snapshot['detached_source'] : null,
                'deleted_at' => $groupRows->max('created_at')?->toIso8601String(),
                'deleted_by' => $userName($first->deleted_by),
                'active_count' => $groupRows->whereNull('restored_at')->count(),
                'positions' => $groupRows->map(fn (ProductImportExclusion $row): array => [
                    'id' => (int) $row->id,
                    'source_key' => (string) $row->source_key,
                    'source_label' => $row->b2b_account_id !== null
                        ? $this->comparison->accountLabel($accounts->get($row->b2b_account_id))
                        : 'Cennik z pliku '.($lists->get($row->price_list_id)?->manufacturer ?? $row->product_manufacturer ?? $row->manufacturer_key),
                    'match_kind' => (string) $row->match_kind,
                    'position_key' => (string) $row->position_key,
                    'remote_sku' => $row->remote_sku,
                    'position_label' => $row->position_label,
                    'hits' => (int) $row->hits,
                    'last_hit_at' => $row->last_hit_at?->toIso8601String(),
                    'restored_at' => $row->restored_at?->toIso8601String(),
                    'restored_by' => $userName($row->restored_by),
                ])->values()->all(),
            ];
        }

        return response()->json([
            'data' => $out,
            'meta' => [
                'current_page' => $groups->currentPage(),
                'last_page' => $groups->lastPage(),
                'total' => $groups->total(),
            ],
        ]);
    }

    public function restore(Request $request): JsonResponse
    {
        $user = $request->user();
        if ($user === null) {
            return response()->json(['message' => 'Brak autoryzacji.'], 401);
        }
        $data = $request->validate([
            'ids' => ['required', 'array', 'min:1', 'max:500'],
            'ids.*' => ['integer', 'distinct'],
        ]);

        $restored = $this->exclusions->restore(array_map('intval', $data['ids']), $user);

        return response()->json([
            'message' => $restored === 0
                ? 'Nic do przywrócenia — pozycje były już przywrócone.'
                : sprintf('Przywrócono %d %s. Karta wróci przy najbliższym imporcie lub synchronizacji.', $restored, $restored === 1 ? 'pozycję' : 'pozycji'),
            'restored' => $restored,
        ]);
    }
}
