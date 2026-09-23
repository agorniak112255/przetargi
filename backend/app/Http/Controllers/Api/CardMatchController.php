<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\B2bAccount;
use App\Models\CardMatchCandidate;
use App\Models\Product;
use App\Models\ProductImage;
use App\Models\ProductSourcePrice;
use App\Models\User;
use App\Services\Catalog\CardMatchFinder;
use App\Services\Catalog\CardMatchMerger;
use App\Services\Pricing\SourcePriceComparison;
use DomainException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Validation\Rule;
use Throwable;

/**
 * Ekran „Łączenie kart” (plan łączenia kart, etap C): propozycje połączenia karty dystrybutora z kartą producenta
 * z CardMatchFinder, decyzja człowieka przez CardMatchMerger. Lista w stałej liczbie zapytań na stronę — karty,
 * zdjęcia, sloty cen, cenniki i konta hurtowo dla całej strony.
 */
class CardMatchController extends Controller
{
    private const DEFAULT_PER_PAGE = 50;

    private const MAX_PER_PAGE = 200;

    /** Tyle propozycji naraz w „Połącz zaznaczone” / „Odrzuć zaznaczone” — każde połączenie to osobna transakcja. */
    private const MAX_BULK = 200;

    public function __construct(
        private readonly CardMatchMerger $merger,
        private readonly SourcePriceComparison $comparison,
    ) {}

    public function index(Request $request): JsonResponse
    {
        $data = $request->validate([
            'status' => ['nullable', 'string', Rule::in(CardMatchCandidate::STATUSES)],
            // bez rodzaju — wszystkie (zakładki „Niepewne”, „Odrzucone”, „Zrobione”)
            'kind' => ['nullable', 'string', Rule::in(CardMatchCandidate::KINDS)],
            'page' => ['nullable', 'integer', 'min:1'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:'.self::MAX_PER_PAGE],
        ]);
        $status = (string) ($data['status'] ?? CardMatchCandidate::STATUS_PENDING);

        $query = CardMatchCandidate::query()
            ->with('decider:id,name')
            ->where('status', $status);
        if (isset($data['kind'])) {
            $query->where('kind', (string) $data['kind']);
        }
        if (in_array($status, [CardMatchCandidate::STATUS_REJECTED, CardMatchCandidate::STATUS_MERGED], true)) {
            $query->orderByDesc('decided_at')->orderByDesc('id');
        } else {
            // marka po marce — decyzje o jednym producencie obok siebie
            $query->orderBy('brand')->orderBy('id');
        }
        $page = $query->paginate((int) ($data['per_page'] ?? self::DEFAULT_PER_PAGE));
        $rows = $this->present($page->getCollection());
        $page->setCollection(collect($rows));

        return response()->json($page);
    }

    public function summary(): JsonResponse
    {
        return response()->json($this->summaryPayload());
    }

    /** Przeliczenie propozycji na żądanie (poza dziennym przebiegiem products:match-candidates). */
    public function refresh(): JsonResponse
    {
        $result = app(CardMatchFinder::class)->refresh();

        return response()->json($this->summaryPayload(isset($result['refreshed_at']) ? (string) $result['refreshed_at'] : null));
    }

    public function merge(Request $request, CardMatchCandidate $candidate): JsonResponse
    {
        $error = $this->decide('merge', $candidate, $this->user($request), null);
        if ($error !== null) {
            return response()->json(['message' => $error], 422);
        }

        return response()->json($this->present(collect([$candidate->refresh()->load('decider:id,name')]))[0]);
    }

    public function reject(Request $request, CardMatchCandidate $candidate): JsonResponse
    {
        $data = $request->validate(['note' => ['nullable', 'string', 'max:300']]);
        $error = $this->decide('reject', $candidate, $this->user($request), $data['note'] ?? null);
        if ($error !== null) {
            return response()->json(['message' => $error], 422);
        }

        return response()->json($this->present(collect([$candidate->refresh()->load('decider:id,name')]))[0]);
    }

    public function bulk(Request $request): JsonResponse
    {
        $data = $request->validate([
            'action' => ['required', 'string', Rule::in(['merge', 'reject'])],
            'ids' => ['required', 'array', 'min:1', 'max:'.self::MAX_BULK],
            'ids.*' => ['required', 'integer', 'distinct'],
            'note' => ['nullable', 'string', 'max:300'],
        ]);
        $user = $this->user($request);
        $ids = array_map('intval', $data['ids']);
        $candidates = CardMatchCandidate::query()->whereIn('id', $ids)->get()->keyBy('id');

        $results = [];
        // po kolei, każde w swojej transakcji: błąd jednej pary nie cofa pozostałych, a połączona już karta
        // dystrybutora w kolejnej parze daje czytelny powód zamiast wyjątku
        foreach ($ids as $id) {
            $candidate = $candidates->get($id);
            $error = $candidate === null
                ? 'Brak propozycji #'.$id.'.'
                : $this->decide((string) $data['action'], $candidate, $user, $data['note'] ?? null);
            $results[] = ['id' => $id, 'ok' => $error === null, 'error' => $error];
        }

        return response()->json(['results' => $results]);
    }

    /** null = zrobione; inaczej powód po polsku (nic nie zmienione). */
    private function decide(string $action, CardMatchCandidate $candidate, User $user, ?string $note): ?string
    {
        try {
            if ($action === 'merge') {
                $this->merger->merge($candidate, $user);
            } else {
                $this->merger->reject($candidate, $user, $note);
            }
        } catch (DomainException $e) {
            return $e->getMessage();
        } catch (Throwable $e) {
            report($e);

            return ($action === 'merge' ? 'Połączenie' : 'Odrzucenie').' nie powiodło się: '.$e->getMessage();
        }

        return null;
    }

    private function user(Request $request): User
    {
        /** @var User $user */
        $user = $request->user();

        return $user;
    }

    /**
     * Liczniki statusów (wszystkie rodzaje razem — jak przed krokiem 5), te same liczniki w podziale na rodzaj
     * i pomiar sygnału propozycji z planem (do decyzji i niepewne, bez kind=merge).
     *
     * @return array{pending: int, conflict: int, rejected: int, merged: int, refreshed_at: string|null, by_kind: array<string, array<string, int>>, signals: array<string, int>}
     */
    private function summaryPayload(?string $refreshedAt = null): array
    {
        $totals = array_fill_keys(CardMatchCandidate::STATUSES, 0);
        $byKind = array_fill_keys(CardMatchCandidate::KINDS, $totals);
        foreach (CardMatchCandidate::query()
            ->toBase()
            ->select(['kind', 'status'])
            ->selectRaw('count(*) as aggregate')
            ->groupBy('kind', 'status')
            ->get() as $row) {
            $status = (string) $row->status;
            $kind = (string) $row->kind;
            if (! array_key_exists($status, $totals)) {
                continue;
            }
            $totals[$status] += (int) $row->aggregate;
            if (array_key_exists($kind, $byKind)) {
                $byKind[$kind][$status] += (int) $row->aggregate;
            }
        }

        $signals = [
            CardMatchCandidate::SIGNAL_SIZE => 0,
            CardMatchCandidate::SIGNAL_COLOR => 0,
            CardMatchCandidate::SIGNAL_UNKNOWN => 0,
        ];
        foreach (CardMatchCandidate::query()
            ->toBase()
            ->select('plan->signal as signal')
            ->selectRaw('count(*) as aggregate')
            ->whereIn('status', [CardMatchCandidate::STATUS_PENDING, CardMatchCandidate::STATUS_CONFLICT])
            ->where('kind', '!=', CardMatchCandidate::KIND_MERGE)
            ->groupBy('plan->signal')
            ->get() as $row) {
            // plan bez sygnału (nie powinien się zdarzyć przy kind != merge) liczony jako niepewny — nie jako rozmiar
            $signal = is_string($row->signal) && array_key_exists($row->signal, $signals)
                ? $row->signal
                : CardMatchCandidate::SIGNAL_UNKNOWN;
            $signals[$signal] += (int) $row->aggregate;
        }

        if ($refreshedAt === null) {
            // odświeżenie ustawia last_seen_at wszystkim propozycjom, które znalazło
            $last = CardMatchCandidate::query()->max('last_seen_at');
            $refreshedAt = $last !== null ? Carbon::parse((string) $last)->toIso8601String() : null;
        }

        return [
            'pending' => $totals[CardMatchCandidate::STATUS_PENDING],
            'conflict' => $totals[CardMatchCandidate::STATUS_CONFLICT],
            'rejected' => $totals[CardMatchCandidate::STATUS_REJECTED],
            'merged' => $totals[CardMatchCandidate::STATUS_MERGED],
            'refreshed_at' => $refreshedAt,
            'by_kind' => $byKind,
            'signals' => $signals,
        ];
    }

    /**
     * Propozycje w kształcie CardMatch (kontrakt ekranu). Stała liczba zapytań niezależnie od liczby wierszy:
     * karty (także karty producenta z planu „pozycja → karta”), zdjęcia, sloty cen (+ cenniki) i konta B2B — po
     * jednym zapytaniu na stronę.
     *
     * @param  Collection<int, CardMatchCandidate>  $candidates  z załadowanym decider
     * @return list<array<string, mixed>>
     */
    private function present(Collection $candidates): array
    {
        $productIds = $candidates
            ->flatMap(static fn (CardMatchCandidate $c): array => [
                (int) $c->source_product_id,
                (int) $c->target_product_id,
                ...self::planProductIds($c->plan),
            ])
            ->filter()
            ->unique()
            ->values()
            ->all();

        $products = collect();
        $thumbs = [];
        $slots = collect();
        $accounts = collect();
        if ($productIds !== []) {
            $products = Product::query()
                ->whereIn('id', $productIds)
                ->get(['id', 'sku', 'name', 'manufacturer', 'purchase_price', 'currency', 'description', 'enrichment_status'])
                ->keyBy('id');
            // zdjęcie główne, a bez znacznika pierwsze w kolejności karty
            foreach (ProductImage::query()
                ->whereIn('product_id', $productIds)
                ->orderByDesc('is_primary')
                ->orderBy('sort_order')
                ->orderBy('id')
                ->get(['id', 'product_id', 'is_primary', 'sort_order']) as $image) {
                $thumbs[(int) $image->product_id] ??= $image->thumbUrl();
            }
            $slots = ProductSourcePrice::query()
                ->with('priceList:id,manufacturer,version')
                ->whereIn('product_id', $productIds)
                ->orderBy('id')
                ->get(['id', 'product_id', 'source_key', 'b2b_account_id', 'price_list_id', 'purchase_price', 'currency'])
                ->groupBy('product_id');
            $accountIds = $slots->flatten(1)
                ->filter(static fn (ProductSourcePrice $s): bool => $s->isB2b())
                ->map(static fn (ProductSourcePrice $s): int => (int) ($s->b2b_account_id ?? (int) substr((string) $s->source_key, 4)))
                ->filter()
                ->unique()
                ->values()
                ->all();
            $accounts = $accountIds === []
                ? collect()
                : B2bAccount::query()->whereIn('id', $accountIds)->get(['id', 'connector', 'sites'])->keyBy('id');
        }

        $brief = function (?int $id) use ($products, $thumbs, $slots, $accounts): ?array {
            $product = $id !== null ? $products->get($id) : null;
            if (! $product instanceof Product) {
                return null;
            }

            return [
                'id' => (int) $product->id,
                'sku' => (string) $product->sku,
                'name' => (string) $product->name,
                'manufacturer' => $product->manufacturer !== null ? (string) $product->manufacturer : null,
                'purchase_price' => $product->purchase_price,
                'currency' => $product->currency,
                'thumb_url' => $thumbs[(int) $product->id] ?? null,
                'has_description' => $product->hasUsableDescription(),
                'sources' => ($slots->get((int) $product->id) ?? collect())
                    ->map(fn (ProductSourcePrice $slot): array => [
                        'source_key' => (string) $slot->source_key,
                        'label' => $this->comparison->sourceLabel($slot, $accounts),
                        'purchase_price' => $slot->purchase_price,
                        'currency' => $slot->currency,
                    ])
                    ->values()
                    ->all(),
            ];
        };

        return $candidates->map(static fn (CardMatchCandidate $c): array => [
            'id' => (int) $c->id,
            'status' => (string) $c->status,
            'kind' => (string) ($c->kind ?? CardMatchCandidate::KIND_MERGE),
            'signal' => is_array($c->plan) && is_string($c->plan['signal'] ?? null) ? $c->plan['signal'] : null,
            'plan_hash' => $c->plan_hash,
            'plan' => self::presentPlan($c->plan, $brief),
            'matched_by' => $c->matched_by,
            'matched_value' => $c->matched_value,
            'matched_source_key' => $c->matched_source_key,
            'brand' => $c->brand,
            'hits' => (int) $c->hits,
            'positions' => (int) $c->positions,
            'reason' => $c->reason,
            'conflict_product_ids' => is_array($c->conflict_product_ids)
                ? array_map('intval', $c->conflict_product_ids)
                : null,
            'decided_by' => $c->decider !== null ? ['id' => (int) $c->decider->id, 'name' => (string) $c->decider->name] : null,
            'decided_at' => $c->decided_at?->toIso8601String(),
            'last_seen_at' => $c->last_seen_at?->toIso8601String(),
            'source' => $brief($c->source_product_id !== null ? (int) $c->source_product_id : null),
            'source_snapshot' => is_array($c->source_snapshot) ? $c->source_snapshot : null,
            'target' => $brief($c->target_product_id !== null ? (int) $c->target_product_id : null),
        ])->values()->all();
    }

    /**
     * Karty wskazane w planie „pozycja → karta” — do tych samych hurtowych zapytań co karty propozycji.
     *
     * @return list<int>
     */
    private static function planProductIds(mixed $plan): array
    {
        if (! is_array($plan) || ! is_array($plan['positions'] ?? null)) {
            return [];
        }
        $ids = [];
        foreach ($plan['positions'] as $position) {
            if (! is_array($position)) {
                continue;
            }
            if (isset($position['target_product_id'])) {
                $ids[] = (int) $position['target_product_id'];
            }
            foreach (is_array($position['target_ids'] ?? null) ? $position['target_ids'] : [] as $id) {
                $ids[] = (int) $id;
            }
        }

        return $ids;
    }

    /**
     * Plan jak w bazie (CardMatchFinder), każda pozycja z kartą producenta w kształcie CardBrief (null: pozycja bez
     * karty albo trafiająca w kilka kart — wtedy target_ids). Karta usunięta po odświeżeniu też daje null.
     *
     * @param  callable(?int): ?array<string, mixed>  $brief
     * @return array<string, mixed>|null
     */
    private static function presentPlan(mixed $plan, callable $brief): ?array
    {
        if (! is_array($plan)) {
            return null;
        }
        if (is_array($plan['positions'] ?? null)) {
            $plan['positions'] = array_values(array_map(
                static fn (mixed $position): mixed => is_array($position)
                    ? array_merge($position, ['target' => $brief(isset($position['target_product_id']) ? (int) $position['target_product_id'] : null)])
                    : $position,
                $plan['positions'],
            ));
        }

        return $plan;
    }
}
