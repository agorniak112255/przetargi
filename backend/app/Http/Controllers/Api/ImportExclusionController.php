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
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Cenniki → Usunięte z pominięciem: karty usunięte z opcją „pomijaj przy imporcie” (grupa = jedno usunięcie karty)
 * i przywracanie ich pozycji. Uprawnienie products.delete — kto usuwa, ten widzi i przywraca.
 */
class ImportExclusionController extends Controller
{
    private const PER_PAGE_OPTIONS = [20, 50, 100];

    /** Sortowanie grup (jedno usunięcie karty) — agregat po wierszach pasujących do filtrów. */
    private const SORTS = [
        'deleted_at' => ['MAX(created_at)', 'desc'],
        'sku' => ['MIN(product_sku)', 'asc'],
        'name' => ['MIN(product_name)', 'asc'],
        'manufacturer' => ['MIN(product_manufacturer)', 'asc'],
        'hits' => ['SUM(hits)', 'desc'],
        'last_hit_at' => ['MAX(last_hit_at)', 'desc'],
        'positions' => ['COUNT(*)', 'desc'],
    ];

    public function __construct(
        private readonly ProductImportExclusions $exclusions,
        private readonly SourcePriceComparison $comparison,
    ) {}

    public function index(Request $request): JsonResponse
    {
        $data = $request->validate([
            'q' => ['sometimes', 'nullable', 'string', 'max:200'],
            'status' => ['sometimes', 'nullable', 'in:active,restored,all'],
            // zakres blokady: „b2b:{konto}” albo „file:{manufacturer_key}” (cennik z pliku po producencie)
            'source' => ['sometimes', 'nullable', 'string', 'max:120', 'regex:/^(b2b:\d+|file:.+)$/'],
            'manufacturer' => ['sometimes', 'nullable', 'string', 'max:100'],
            'deleted_by' => ['sometimes', 'nullable', 'integer', 'min:1'],
            // deleted = karta usunięta, detached = odpięto jedno źródło od karty, która została
            'kind' => ['sometimes', 'nullable', 'in:deleted,detached'],
            // hit = import już pominął pozycję, never = jeszcze ani razu
            'hits' => ['sometimes', 'nullable', 'in:hit,never'],
            'from' => ['sometimes', 'nullable', 'date_format:Y-m-d'],
            'to' => ['sometimes', 'nullable', 'date_format:Y-m-d'],
            'sort' => ['sometimes', 'nullable', 'in:'.implode(',', array_keys(self::SORTS))],
            'dir' => ['sometimes', 'nullable', 'in:asc,desc'],
            'per_page' => ['sometimes', 'nullable', 'integer', 'in:'.implode(',', self::PER_PAGE_OPTIONS)],
            'page' => ['sometimes', 'integer', 'min:1'],
        ]);
        $status = $data['status'] ?? 'active';
        $q = trim((string) ($data['q'] ?? ''));
        $source = trim((string) ($data['source'] ?? ''));
        $hits = $data['hits'] ?? null;
        $sort = $data['sort'] ?? 'deleted_at';
        [$sortExpr, $defaultDir] = self::SORTS[$sort];
        $dir = $data['dir'] ?? $defaultDir;

        // Filtry pozycji: wybierają grupy i zawężają pozycje pokazane w grupie (przywracanie grupy = widoczne pozycje).
        $positionFilters = static function (Builder $query) use ($status, $source, $hits): void {
            $query->when($status === 'active', static fn (Builder $inner) => $inner->whereNull('restored_at'))
                ->when($status === 'restored', static fn (Builder $inner) => $inner->whereNotNull('restored_at'))
                ->when($source !== '', static fn (Builder $inner) => $inner->where('scope_key', $source))
                ->when($hits === 'hit', static fn (Builder $inner) => $inner->where('hits', '>', 0))
                ->when($hits === 'never', static fn (Builder $inner) => $inner->where('hits', 0));
        };

        $groupQuery = ProductImportExclusion::query()
            ->select('deletion_id')
            ->selectRaw($sortExpr.' as sort_value')
            ->selectRaw('MAX(created_at) as deleted_at')
            ->tap($positionFilters)
            // dane karty, autor i czas są wspólne dla całej grupy (jeden zapis usunięcia)
            ->when(($data['manufacturer'] ?? '') !== '', static fn (Builder $query) => $query->where('product_manufacturer', $data['manufacturer']))
            ->when(isset($data['deleted_by']), static fn (Builder $query) => $query->where('deleted_by', (int) $data['deleted_by']))
            ->when(($data['kind'] ?? null) === 'detached', static fn (Builder $query) => $query->whereNotNull('product_snapshot->detached_source'))
            ->when(($data['kind'] ?? null) === 'deleted', static fn (Builder $query) => $query->whereNull('product_snapshot->detached_source'))
            ->when(isset($data['from']), static fn (Builder $query) => $query->where('created_at', '>=', Carbon::createFromFormat('Y-m-d', $data['from'])->startOfDay()))
            ->when(isset($data['to']), static fn (Builder $query) => $query->where('created_at', '<=', Carbon::createFromFormat('Y-m-d', $data['to'])->endOfDay()))
            ->when($q !== '', static function (Builder $query) use ($q): void {
                $like = '%'.str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $q).'%';
                $query->where(static function (Builder $inner) use ($like): void {
                    $inner->where('product_sku', 'like', $like)
                        ->orWhere('product_name', 'like', $like)
                        ->orWhere('product_manufacturer', 'like', $like)
                        ->orWhere('position_key', 'like', $like)
                        ->orWhere('remote_sku', 'like', $like)
                        ->orWhere('position_label', 'like', $like);
                });
            })
            ->groupBy('deletion_id');
        $groups = $groupQuery
            ->orderBy('sort_value', $dir)
            ->orderByDesc('deleted_at')
            ->orderByDesc('deletion_id')
            ->paginate((int) ($data['per_page'] ?? self::PER_PAGE_OPTIONS[0]));

        $deletionIds = collect($groups->items())->pluck('deletion_id')->map(static fn (mixed $id): string => (string) $id)->all();
        $rows = $deletionIds === [] ? collect() : ProductImportExclusion::query()
            ->whereIn('deletion_id', $deletionIds)
            ->orderBy('source_key')
            ->orderBy('position_key')
            ->get();
        /** @var array<int, int> $shownIds id pozycji pasujących do filtrów pozycji => indeks */
        $shownIds = $deletionIds === [] ? [] : array_flip(ProductImportExclusion::query()
            ->whereIn('deletion_id', $deletionIds)
            ->tap($positionFilters)
            ->pluck('id')
            ->map(static fn (mixed $id): int => (int) $id)
            ->all());

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
                // pozycje grupy, których filtr (stan, źródło, pomijanie) nie pokazuje
                'hidden_count' => $groupRows->reject(static fn (ProductImportExclusion $row): bool => isset($shownIds[(int) $row->id]))->count(),
                'positions' => $groupRows->filter(static fn (ProductImportExclusion $row): bool => isset($shownIds[(int) $row->id]))->map(fn (ProductImportExclusion $row): array => [
                    'id' => (int) $row->id,
                    'source_key' => (string) $row->source_key,
                    // wartość filtra „Źródło” (cennik z pliku po producencie, nie po numerze wpisu)
                    'scope_key' => (string) $row->scope_key,
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
                'per_page' => $groups->perPage(),
                'sort' => $sort,
                'dir' => $dir,
            ],
            'facets' => $this->facets(),
        ]);
    }

    /**
     * Listy do filtrów — z całej tabeli, niezależnie od bieżących filtrów (wybór nie znika po zawężeniu).
     *
     * @return array{sources: list<array{key: string, label: string, active: int, total: int}>, manufacturers: list<array{name: string, cards: int}>, users: list<array{id: int, name: string}>}
     */
    private function facets(): array
    {
        $scopes = ProductImportExclusion::query()
            ->select('scope_key')
            ->selectRaw('MAX(b2b_account_id) as account_id, MAX(manufacturer_key) as manufacturer_key, MAX(product_manufacturer) as product_manufacturer')
            ->selectRaw('COUNT(*) as total, SUM(CASE WHEN restored_at IS NULL THEN 1 ELSE 0 END) as active')
            ->groupBy('scope_key')
            ->get();
        $accounts = B2bAccount::query()->whereIn('id', $scopes->pluck('account_id')->filter()->map(static fn (mixed $id): int => (int) $id)->unique()->values()->all())->get()->keyBy('id');
        // cennik z pliku po producencie: nazwa z najnowszego wpisu cennika tego producenta
        $listNames = PriceList::query()
            ->whereIn('manufacturer_key', $scopes->pluck('manufacturer_key')->filter()->unique()->values()->all())
            ->orderBy('id')
            ->get(['manufacturer_key', 'manufacturer'])
            ->mapWithKeys(static fn (PriceList $list): array => [(string) $list->manufacturer_key => (string) $list->manufacturer]);
        $sources = $scopes->map(function (ProductImportExclusion $row) use ($accounts, $listNames): array {
            $scope = (string) $row->scope_key;
            $accountId = (int) ($row->getAttribute('account_id') ?? 0);
            $manufacturerKey = (string) ($row->getAttribute('manufacturer_key') ?? '');
            $label = str_starts_with($scope, 'b2b:')
                ? $this->comparison->accountLabel($accounts->get($accountId))
                : 'Cennik z pliku '.($listNames->get($manufacturerKey) ?? $row->getAttribute('product_manufacturer') ?? $manufacturerKey);

            return [
                'key' => $scope,
                'label' => $label,
                'active' => (int) $row->getAttribute('active'),
                'total' => (int) $row->getAttribute('total'),
            ];
        })->sortBy('label', SORT_NATURAL | SORT_FLAG_CASE)->values()->all();

        $manufacturers = ProductImportExclusion::query()
            ->whereNotNull('product_manufacturer')
            ->where('product_manufacturer', '!=', '')
            ->select('product_manufacturer')
            ->selectRaw('COUNT(DISTINCT deletion_id) as cards')
            ->groupBy('product_manufacturer')
            ->orderBy('product_manufacturer')
            ->get()
            ->map(static fn (ProductImportExclusion $row): array => ['name' => (string) $row->product_manufacturer, 'cards' => (int) $row->getAttribute('cards')])
            ->all();

        $userIds = ProductImportExclusion::query()->whereNotNull('deleted_by')->distinct()->pluck('deleted_by')->map(static fn (mixed $id): int => (int) $id)->all();
        $users = User::query()->whereIn('id', $userIds)->orderBy('name')->get(['id', 'name'])
            ->map(static fn (User $user): array => ['id' => (int) $user->id, 'name' => (string) $user->name])
            ->all();

        return ['sources' => $sources, 'manufacturers' => $manufacturers, 'users' => $users];
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
