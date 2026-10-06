<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\InspectionPosition;
use App\Models\InspectionSuggestionRejection;
use App\Models\User;
use App\Services\Inspections\InspectionDueBuilder;
use App\Services\Inspections\InspectionQuery;
use App\Services\Inspections\InspectionSuggestions;
use App\Support\PolishTime;
use Illuminate\Database\Query\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * Lista pozycji modułu Przeglądy: usługi i towary z ERP XL z interwałem wybranym przez człowieka (InspectionPosition::
 * INTERVALS) i — dla towaru — usługą, która go odnawia. Interwału system nie wymyśla; podpowiedzi z wzorca to tylko
 * propozycje do zatwierdzenia. Po każdej zmianie pozycji przebudowa jej terminów (InspectionDueBuilder).
 */
class InspectionPositionController extends Controller
{
    private const MAX_ITEMS = 100;

    private const CATALOG_LIMIT = 50;

    public function __construct(private readonly InspectionQuery $query) {}

    public function index(): JsonResponse
    {
        $today = PolishTime::today()->toDateString();
        $counts = [];
        $rows = DB::table('inspection_due')
            ->groupBy('inspection_position_id')
            ->selectRaw('inspection_position_id, count(*) as due, sum(case when due_on < ? then 1 else 0 end) as overdue', [$today])
            ->get();
        foreach ($rows as $r) {
            $counts[(int) $r->inspection_position_id] = ['due' => (int) $r->due, 'overdue' => (int) $r->overdue];
        }
        $positions = InspectionPosition::query()
            ->with(['pattern:id,name', 'creator:id,name', 'updater:id,name'])
            ->orderBy('name')
            ->orderBy('id')
            ->get();
        $services = $this->servicesByGid($positions->pluck('renewed_by_xl_gid')->filter()->map(static fn ($g): int => (int) $g)->all());

        return response()->json([
            'data' => $positions->map(fn (InspectionPosition $p): array => $this->present($p, $services, $counts[(int) $p->id] ?? null))->values()->all(),
            'meta' => ['intervals' => InspectionPosition::INTERVALS],
        ]);
    }

    /**
     * Wyszukiwarka katalogu XL do dodawania pozycji: aktywne usługi (erp_services) i towary (erp_items), każde słowo
     * w kodzie albo nazwie; usługi przed towarami; najwyżej 50.
     */
    public function catalog(Request $request): JsonResponse
    {
        $v = $request->validate([
            'q' => ['nullable', 'string', 'max:150'],
            'type' => ['nullable', 'string', Rule::in(['all', 'goods', 'service'])],
        ], $this->messages());
        $words = array_slice(preg_split('/\s+/u', trim((string) ($v['q'] ?? '')), -1, PREG_SPLIT_NO_EMPTY) ?: [], 0, 8);
        $type = (string) ($v['type'] ?? 'all');

        $found = [];
        if ($type !== 'goods') {
            $q = DB::table('erp_services')->whereNull('removed_at')->where('archived', false);
            $this->words($q, $words, ['code', 'name']);
            foreach ($q->orderBy('code')->orderBy('id')->limit(self::CATALOG_LIMIT)->get(['xl_gid', 'xl_type', 'code', 'name', 'unit']) as $r) {
                $found[] = ['xl_gid' => (int) $r->xl_gid, 'xl_type' => InspectionPosition::TYPE_SERVICE, 'code' => (string) $r->code, 'name' => (string) $r->name, 'unit' => $r->unit];
            }
        }
        if ($type !== 'service' && count($found) < self::CATALOG_LIMIT) {
            $q = DB::table('erp_items')->whereNull('removed_at')->where('archived', false);
            $this->words($q, $words, ['code', 'name']);
            foreach ($q->orderBy('code')->orderBy('id')->limit(self::CATALOG_LIMIT - count($found))->get(['xl_gid', 'code', 'name', 'unit']) as $r) {
                $found[] = ['xl_gid' => (int) $r->xl_gid, 'xl_type' => InspectionPosition::TYPE_GOODS, 'code' => (string) $r->code, 'name' => (string) $r->name, 'unit' => $r->unit];
            }
        }

        $gids = array_column($found, 'xl_gid');
        $positions = $gids !== [] ? InspectionPosition::query()->whereIn('xl_gid', $gids)->pluck('id', 'xl_gid')->all() : [];
        $stats = $this->query->stats24m(
            array_column(array_filter($found, static fn (array $f): bool => $f['xl_type'] === InspectionPosition::TYPE_GOODS), 'xl_gid'),
            array_column(array_filter($found, static fn (array $f): bool => $f['xl_type'] === InspectionPosition::TYPE_SERVICE), 'xl_gid'),
            PolishTime::today(),
        );

        return response()->json([
            'data' => array_map(static fn (array $f): array => [
                ...$f,
                'unit' => $f['unit'] !== null ? (string) $f['unit'] : null,
                'position_id' => isset($positions[$f['xl_gid']]) ? (int) $positions[$f['xl_gid']] : null,
                'customers_24m' => $stats[$f['xl_gid']]['customers_24m'] ?? 0,
                'quantity_24m' => $stats[$f['xl_gid']]['quantity_24m'] ?? 0.0,
            ], $found),
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        $v = $request->validate($this->itemsRules(), $this->messages());
        /** @var User $user */
        $user = $request->user();
        $positions = $this->create($v['items'], $user, InspectionPosition::SOURCE_MANUAL, null);

        return response()->json(['data' => $this->presentMany($positions)], 201);
    }

    public function update(Request $request, InspectionPosition $position): JsonResponse
    {
        $v = $request->validate([
            'interval_months' => ['sometimes', 'required', 'integer', Rule::in(InspectionPosition::INTERVALS)],
            'renewed_by_xl_gid' => ['sometimes', 'nullable', 'integer', 'min:1'],
            'note' => ['sometimes', 'nullable', 'string', 'max:500'],
            'active' => ['sometimes', 'required', 'boolean'],
        ], $this->messages());
        if (array_key_exists('renewed_by_xl_gid', $v) && $v['renewed_by_xl_gid'] !== null) {
            $error = $this->renewalError((int) $position->xl_type, (int) $v['renewed_by_xl_gid']);
            if ($error !== null) {
                throw ValidationException::withMessages(['renewed_by_xl_gid' => $error]);
            }
        }
        /** @var User $user */
        $user = $request->user();

        $data = ['updated_by' => $user->id];
        if (array_key_exists('interval_months', $v)) {
            $data['interval_months'] = (int) $v['interval_months'];
        }
        if (array_key_exists('renewed_by_xl_gid', $v)) {
            $data['renewed_by_xl_gid'] = $v['renewed_by_xl_gid'] !== null ? (int) $v['renewed_by_xl_gid'] : null;
        }
        if (array_key_exists('note', $v)) {
            $data['note'] = $this->note($v['note']);
        }
        if (array_key_exists('active', $v)) {
            $data['active'] = (bool) $v['active'];
        }
        $position->update($data);
        $this->rebuild([(int) $position->id]);

        return response()->json(['data' => $this->presentMany(collect([$position->fresh() ?? $position]))[0]]);
    }

    /** Usunięcie pozycji — jej terminy, pominięcia i odrzucone podpowiedzi znikają kaskadą (klucze obce). */
    public function destroy(InspectionPosition $position): Response
    {
        $position->delete();

        return response()->noContent();
    }

    public function suggestions(InspectionSuggestions $suggestions): JsonResponse
    {
        return response()->json(['data' => $suggestions->suggestions()]);
    }

    /** Zatwierdzenie podpowiedzi: pozycje z source = suggestion i wskazaniem wzorca. */
    public function acceptSuggestions(Request $request): JsonResponse
    {
        $v = $request->validate([
            'pattern_id' => ['required', 'integer', Rule::exists('inspection_positions', 'id')],
            ...$this->itemsRules(),
        ], [
            ...$this->messages(),
            'pattern_id.required' => 'Brak pozycji wzorcowej.',
            'pattern_id.exists' => 'Pozycji wzorcowej nie ma już na liście — odśwież stronę.',
        ]);
        /** @var User $user */
        $user = $request->user();
        $positions = $this->create($v['items'], $user, InspectionPosition::SOURCE_SUGGESTION, (int) $v['pattern_id']);

        return response()->json(['data' => $this->presentMany($positions)], 201);
    }

    /** Odrzucenie podpowiedzi: ta pozycja XL nie wróci pod tym wzorcem. */
    public function rejectSuggestions(Request $request): Response
    {
        $v = $request->validate([
            'pattern_id' => ['required', 'integer', Rule::exists('inspection_positions', 'id')],
            'xl_gids' => ['required', 'array', 'min:1', 'max:'.self::MAX_ITEMS],
            'xl_gids.*' => ['integer', 'min:1', 'distinct'],
        ], [
            ...$this->messages(),
            'pattern_id.required' => 'Brak pozycji wzorcowej.',
            'pattern_id.exists' => 'Pozycji wzorcowej nie ma już na liście — odśwież stronę.',
            'xl_gids.required' => 'Zaznacz co najmniej jedną podpowiedź.',
            'xl_gids.min' => 'Zaznacz co najmniej jedną podpowiedź.',
        ]);
        /** @var User $user */
        $user = $request->user();
        foreach ($v['xl_gids'] as $gid) {
            InspectionSuggestionRejection::query()->firstOrCreate(
                ['pattern_position_id' => (int) $v['pattern_id'], 'xl_gid' => (int) $gid],
                ['user_id' => $user->id],
            );
        }

        return response()->noContent();
    }

    /**
     * Zapis nowych pozycji (po sprawdzeniu katalogu, duplikatów i usług odnawiających) i przebudowa ich terminów.
     *
     * @param  list<array<string, mixed>>  $items
     * @return Collection<int, InspectionPosition>
     */
    private function create(array $items, User $user, string $source, ?int $patternId): Collection
    {
        $gids = array_map(static fn (array $i): int => (int) $i['xl_gid'], $items);
        $services = DB::table('erp_services')->whereIn('xl_gid', $gids)->whereNull('removed_at')->get(['xl_gid', 'xl_type', 'code', 'name', 'unit'])->keyBy('xl_gid');
        $goods = DB::table('erp_items')->whereIn('xl_gid', $gids)->whereNull('removed_at')->get(['xl_gid', 'code', 'name', 'unit'])->keyBy('xl_gid');
        $existing = array_fill_keys(InspectionPosition::query()->whereIn('xl_gid', $gids)->pluck('xl_gid')->map(static fn ($g): int => (int) $g)->all(), true);

        $errors = [];
        $rows = [];
        foreach ($items as $i => $item) {
            $gid = (int) $item['xl_gid'];
            $catalog = $services->get($gid) ?? $goods->get($gid);
            if ($catalog === null) {
                $errors["items.$i.xl_gid"] = 'Tej pozycji nie ma w katalogu ERP XL (albo usunięto ją z XL) — odśwież wyszukiwanie.';

                continue;
            }
            if (isset($existing[$gid])) {
                $errors["items.$i.xl_gid"] = 'Pozycja „'.$catalog->name.'” jest już na liście przeglądów.';

                continue;
            }
            $type = $services->has($gid) ? InspectionPosition::TYPE_SERVICE : InspectionPosition::TYPE_GOODS;
            $renewedBy = isset($item['renewed_by_xl_gid']) ? (int) $item['renewed_by_xl_gid'] : null;
            if ($renewedBy !== null) {
                $error = $this->renewalError($type, $renewedBy);
                if ($error !== null) {
                    $errors["items.$i.renewed_by_xl_gid"] = $error;

                    continue;
                }
            }
            $rows[] = [
                'xl_gid' => $gid,
                'xl_type' => $type,
                'code' => mb_substr((string) $catalog->code, 0, 100),
                'name' => mb_substr((string) $catalog->name, 0, 500),
                'unit' => $catalog->unit !== null ? mb_substr((string) $catalog->unit, 0, 20) : null,
                'interval_months' => (int) $item['interval_months'],
                'renewed_by_xl_gid' => $renewedBy,
                'note' => $this->note($item['note'] ?? null),
                'active' => true,
                'source' => $source,
                'pattern_position_id' => $patternId,
                'created_by' => $user->id,
                'updated_by' => $user->id,
            ];
        }
        if ($errors !== []) {
            throw ValidationException::withMessages($errors);
        }

        $positions = DB::transaction(static fn (): Collection => collect(array_map(
            static fn (array $row): InspectionPosition => InspectionPosition::query()->create($row),
            $rows,
        )));
        $this->rebuild($positions->pluck('id')->map(static fn ($id): int => (int) $id)->all());

        return $positions;
    }

    /** Usługę odnawiającą ma tylko towar i musi to być usługa z katalogu XL. */
    private function renewalError(int $type, int $renewedBy): ?string
    {
        if ($type !== InspectionPosition::TYPE_GOODS) {
            return 'Usługę odnawiającą można wskazać tylko dla towaru (dla usługi termin liczy się od jej sprzedaży).';
        }
        if (! DB::table('erp_services')->where('xl_gid', $renewedBy)->whereNull('removed_at')->exists()) {
            return 'Usługa odnawiająca musi być usługą z katalogu ERP XL.';
        }

        return null;
    }

    /**
     * Przebudowa terminów pozycji (InspectionDueBuilder — przez kontener, testy mogą podmienić).
     *
     * @param  list<int>  $ids
     */
    private function rebuild(array $ids): void
    {
        if ($ids !== []) {
            app(InspectionDueBuilder::class)->rebuild($ids);
        }
    }

    private function note(mixed $note): ?string
    {
        $note = trim((string) $note);

        return $note !== '' ? $note : null;
    }

    /**
     * @param  Collection<int, InspectionPosition>  $positions
     * @return list<array<string, mixed>>
     */
    private function presentMany(Collection $positions): array
    {
        $ids = $positions->pluck('id')->all();
        $counts = [];
        if ($ids !== []) {
            $today = PolishTime::today()->toDateString();
            $rows = DB::table('inspection_due')
                ->whereIn('inspection_position_id', $ids)
                ->groupBy('inspection_position_id')
                ->selectRaw('inspection_position_id, count(*) as due, sum(case when due_on < ? then 1 else 0 end) as overdue', [$today])
                ->get();
            foreach ($rows as $r) {
                $counts[(int) $r->inspection_position_id] = ['due' => (int) $r->due, 'overdue' => (int) $r->overdue];
            }
        }
        $positions->each(static fn (InspectionPosition $p) => $p->loadMissing(['pattern:id,name', 'creator:id,name', 'updater:id,name']));
        $services = $this->servicesByGid($positions->pluck('renewed_by_xl_gid')->filter()->map(static fn ($g): int => (int) $g)->all());

        return $positions->map(fn (InspectionPosition $p): array => $this->present($p, $services, $counts[(int) $p->id] ?? null))->values()->all();
    }

    /**
     * @param  list<int>  $gids
     * @return array<int, object>
     */
    private function servicesByGid(array $gids): array
    {
        if ($gids === []) {
            return [];
        }
        $out = [];
        foreach (DB::table('erp_services')->whereIn('xl_gid', array_values(array_unique($gids)))->get(['xl_gid', 'code', 'name']) as $s) {
            $out[(int) $s->xl_gid] = $s;
        }

        return $out;
    }

    /**
     * @param  array<int, object>  $services
     * @param  array{due: int, overdue: int}|null  $counts
     * @return array<string, mixed>
     */
    private function present(InspectionPosition $p, array $services, ?array $counts): array
    {
        $renewedBy = null;
        if ($p->renewed_by_xl_gid !== null) {
            $s = $services[(int) $p->renewed_by_xl_gid] ?? null;
            // usługi nie ma już w kopii katalogu — numer zostaje, kod i nazwa nieznane
            $renewedBy = ['xl_gid' => (int) $p->renewed_by_xl_gid, 'code' => $s !== null ? (string) $s->code : null, 'name' => $s !== null ? (string) $s->name : null];
        }
        $pattern = $p->pattern;

        return [
            'id' => (int) $p->id,
            'xl_gid' => (int) $p->xl_gid,
            'xl_type' => (int) $p->xl_type,
            'code' => (string) $p->code,
            'name' => (string) $p->name,
            'unit' => $p->unit !== null ? (string) $p->unit : null,
            'interval_months' => (int) $p->interval_months,
            'renewed_by' => $renewedBy,
            'note' => $p->note,
            'active' => (bool) $p->active,
            'source' => (string) $p->source,
            'pattern' => $pattern !== null ? ['id' => (int) $pattern->id, 'name' => (string) $pattern->name] : null,
            'history_loaded' => $p->history_loaded_at !== null,
            'customers_due' => $counts['due'] ?? 0,
            'customers_overdue' => $counts['overdue'] ?? 0,
            'created_by_name' => $p->creator?->name,
            'updated_by_name' => $p->updater?->name,
            'updated_at' => $p->updated_at?->toIso8601String(),
        ];
    }

    /**
     * Każde słowo w którejś z kolumn.
     *
     * @param  list<string>  $words
     * @param  list<string>  $columns
     */
    private function words(Builder $q, array $words, array $columns): void
    {
        foreach ($words as $word) {
            $like = '%'.addcslashes($word, '%_\\').'%';
            $q->where(function (Builder $w) use ($like, $columns): void {
                foreach ($columns as $column) {
                    $w->orWhere($column, 'like', $like);
                }
            });
        }
    }

    /** @return array<string, list<mixed>> */
    private function itemsRules(): array
    {
        return [
            'items' => ['required', 'array', 'min:1', 'max:'.self::MAX_ITEMS],
            'items.*' => ['array'],
            'items.*.xl_gid' => ['required', 'integer', 'min:1', 'distinct'],
            'items.*.interval_months' => ['required', 'integer', Rule::in(InspectionPosition::INTERVALS)],
            'items.*.renewed_by_xl_gid' => ['nullable', 'integer', 'min:1'],
            'items.*.note' => ['nullable', 'string', 'max:500'],
        ];
    }

    /**
     * Komunikaty po polsku (aplikacja nie ma tłumaczeń walidacji).
     *
     * @return array<string, string>
     */
    private function messages(): array
    {
        $interval = 'Wybierz interwał z listy: '.implode(', ', InspectionPosition::INTERVALS).' miesięcy.';

        return [
            'integer' => 'Pole :attribute musi być liczbą całkowitą.',
            'boolean' => 'Pole :attribute musi mieć wartość tak albo nie.',
            'in' => 'Nieznana wartość pola :attribute.',
            'min' => 'Pole :attribute jest za małe (najmniej :min).',
            'max' => 'Pole :attribute jest za długie (najwyżej :max).',
            'array' => 'Pole :attribute musi być listą.',
            'string' => 'Pole :attribute musi być tekstem.',
            'required' => 'Brak wymaganego pola :attribute.',
            'items.required' => 'Zaznacz co najmniej jedną pozycję.',
            'items.min' => 'Zaznacz co najmniej jedną pozycję.',
            'items.max' => 'Naraz można dodać najwyżej :max pozycji — zaznacz mniej.',
            'items.*.xl_gid.distinct' => 'Ta sama pozycja jest zaznaczona dwa razy.',
            'items.*.interval_months.required' => $interval,
            'items.*.interval_months.in' => $interval,
            'interval_months.required' => $interval,
            'interval_months.in' => $interval,
            'items.*.note.max' => 'Uwaga może mieć najwyżej :max znaków.',
            'note.max' => 'Uwaga może mieć najwyżej :max znaków.',
            'xl_gids.*.distinct' => 'Ta sama podpowiedź jest zaznaczona dwa razy.',
        ];
    }
}
