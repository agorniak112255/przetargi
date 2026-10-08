<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Models\ErpItem;
use App\Models\ErpItemLink;
use App\Models\Product;
use App\Models\User;
use App\Services\Erp\ErpLinkDecisions;
use App\Services\Erp\InventoryQuery;
use App\Support\PolishTime;
use App\Support\XlsxStreamWriter;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Query\Builder as QueryBuilder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use InvalidArgumentException;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

/**
 * Ekran „Powiązania z ERP XL”: towary XL (aktywne, bez usuniętych z XL) z wynikiem łączenia i powiązanymi kartami,
 * liczniki braków i decyzje człowieka. Niczego nie zapisuje w XL.
 *
 * Zalegające (stale_months) i wartość zapasu liczą się jak w Zapasach (InventoryQuery, wszystkie magazyny) — żeby
 * najpierw łączyć towar, w którym stoi najwięcej pieniędzy.
 */
class ErpItemController extends Controller
{
    /** Filtr statusu → wyniki łączenia (erp_items.match_outcome). */
    private const STATUS_OUTCOMES = [
        'linked' => ['auto', 'confirmed'],
        'auto' => ['auto'],
        'confirmed' => ['confirmed'],
        'review' => ['suggested', 'ambiguous', 'name_suggested', 'search_suggested'],
        'no_card' => ['no_match', 'family_conflict'],
        'no_code' => ['no_code'],
        'rejected' => ['rejected'],
    ];

    /** Grupy asortymentu XL po pierwszej literze kodu towaru. */
    private const GROUPS = ['A', 'B', 'S', 'T', 'H'];

    private const SORTS = [
        'stock' => 'stock_trade',
        'last_sale' => 'last_sale_at',
        'last_purchase' => 'last_purchase_at',
        'code' => 'code',
        'name' => 'name',
    ];

    /** Sortowania po wartościach z powiązań (podzapytania) — towary bez wartości zawsze na końcu. */
    private const LINK_SORTS = ['status', 'card', 'linked_at', 'linked_by'];

    /** Sortowanie po wartości zapasu (valueSql) — towary bez stanu albo bez ceny zakupu zawsze na końcu. */
    private const SORT_VALUE = 'value';

    /** Zalegające: stan i brak sprzedaży od N miesięcy (progi z Zapasów). */
    private const STALE_MONTHS = [3, 6, 12, 24];

    /** Kafelek „Zalegające bez karty” — domyślny próg Zapasów. */
    private const SUMMARY_STALE_MONTHS = 6;

    /** Filtr liczby propozycji kart (powiązania auto / suggested z kartą): jedna — gotowe do zbiorczego potwierdzenia. */
    private const PROPOSALS = ['one', 'many', 'none'];

    private const PROPOSAL_LABELS = [
        'one' => 'jedna karta',
        'many' => 'kilka kart',
        'none' => 'bez propozycji',
    ];

    /** Kolejność statusów przy sortowaniu: połączone, do decyzji, bez karty, bez kodu, odrzucone, nie przeliczone. */
    private const OUTCOME_RANK = [
        'confirmed' => 0,
        'auto' => 1,
        'suggested' => 2,
        'ambiguous' => 2,
        'name_suggested' => 2,
        'search_suggested' => 2,
        'no_match' => 3,
        'family_conflict' => 3,
        'no_code' => 4,
        'rejected' => 5,
    ];

    /** „Połączył” w filtrze i zestawieniu: automat zamiast osoby. */
    private const LINKER_AUTO = 'auto';

    /** Filtr statusu: potwierdzone przez człowieka karty, które automat już wcześniej połączył (auto_linked_at). */
    private const STATUS_AFTER_AUTO = 'confirmed_after_auto';

    /** Status w eksporcie — jak plakietki na ekranie. */
    private const OUTCOME_LABELS = [
        'auto' => 'połączone automatycznie',
        'confirmed' => 'potwierdzone',
        'suggested' => 'do decyzji',
        'ambiguous' => 'do decyzji: kilka kart',
        'name_suggested' => 'do decyzji: z nazwy karty',
        'search_suggested' => 'do decyzji: z wyszukiwarki',
        'no_match' => 'kod bez karty',
        'family_conflict' => 'kod bez karty (inna rodzina)',
        'no_code' => 'bez kodu',
        'rejected' => 'odrzucone',
    ];

    private const STATUS_FILTER_LABELS = [
        'unlinked' => 'bez karty (wszystko poza połączonymi)',
        'review' => 'do decyzji',
        'no_card' => 'kod bez karty w katalogu',
        'no_code' => 'bez kodu w nazwie',
        'linked' => 'połączone (auto + potwierdzone)',
        'auto' => 'połączone automatycznie',
        'confirmed' => 'potwierdzone ręcznie',
        self::STATUS_AFTER_AUTO => 'potwierdzone po automacie',
        'rejected' => 'odrzucone',
    ];

    private const METHOD_LABELS = [
        'name' => 'z nazwy XL',
        'name1' => 'z Nazwa1',
        'xl_code' => 'z kodu XL',
        'card_name' => 'z nazwy karty',
        'search' => 'z wyszukiwarki',
        'manual' => 'wybrane ręcznie',
    ];

    private const SORT_LABELS = [
        'stock' => 'stan HANDEL',
        self::SORT_VALUE => 'wartość zapasu',
        'last_sale' => 'ostatnia sprzedaż',
        'last_purchase' => 'ostatni zakup',
        'code' => 'kod XL',
        'name' => 'nazwa XL',
        'status' => 'status',
        'card' => 'karta',
        'linked_at' => 'data połączenia',
        'linked_by' => 'kto połączył',
    ];

    /**
     * Wartości powiązania łączącego towar (linking()): kiedy — potwierdzenie człowieka albo założenie przez automat;
     * kto — id osoby, 0 = automat, -1 = potwierdzenie osoby usuniętej z systemu.
     */
    private const LINKED_AT_SQL = "case when pl.status = 'confirmed' then coalesce(pl.decided_at, pl.created_at) else coalesce(pl.auto_linked_at, pl.created_at) end";

    private const LINKER_SQL = "case when pl.status = 'confirmed' then coalesce(pl.decided_by, -1) else 0 end";

    private const LINKER_NAME_SQL = "case when pl.status = 'confirmed' then coalesce((select u.name from users u where u.id = pl.decided_by), '') else 'Automat' end";

    private const OUTCOMES = ['auto', 'confirmed', 'suggested', 'ambiguous', 'name_suggested', 'search_suggested', 'no_match', 'family_conflict', 'no_code', 'rejected'];

    private const LINK_ORDER = [
        ErpItemLink::STATUS_CONFIRMED => 0,
        ErpItemLink::STATUS_AUTO => 1,
        ErpItemLink::STATUS_SUGGESTED => 2,
        ErpItemLink::STATUS_REJECTED => 3,
    ];

    public function __construct(private readonly ErpLinkDecisions $decisions) {}

    public function index(Request $request): JsonResponse
    {
        $v = $request->validate($this->listRules($request) + [
            'page' => ['nullable', 'integer', 'min:1'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:200'],
        ]);
        $query = $this->filtered($v);
        // zestawienie „kto ile połączył” dla tych samych filtrów, bez filtra osoby — widać wszystkich w okresie
        $linkers = $this->linkers(clone $query);
        $this->filterLinkerAndSort($query, $v);
        $page = $this->withValue($query)
            ->with(['links.product:id,sku,name,manufacturer', 'links.decider:id,name'])
            ->paginate((int) ($v['per_page'] ?? 50));

        return response()->json([
            'data' => $page->getCollection()->map(fn (ErpItem $item): array => $this->present($item))->values()->all(),
            'meta' => [
                'current_page' => $page->currentPage(),
                'last_page' => $page->lastPage(),
                'per_page' => $page->perPage(),
                'total' => $page->total(),
                'linkers' => $linkers,
            ],
        ]);
    }

    /**
     * Plik Excel z towarami przy tych samych filtrach i sortowaniu co lista (wszystkie strony) — arkusz „Towary”
     * i „Zestawienie” (użyte filtry, kto ile połączył). Zapis strumieniowy: pełna lista to ponad 30 tys. wierszy.
     */
    public function export(Request $request): BinaryFileResponse
    {
        $v = $request->validate($this->listRules($request));
        $query = $this->filtered($v);
        $linkers = $this->linkers(clone $query);
        $this->filterLinkerAndSort($query, $v);
        $ids = $query->pluck('id')->all();

        $path = tempnam(sys_get_temp_dir(), 'erpxl');
        if ($path === false) {
            abort(500, 'Nie udało się przygotować pliku.');
        }
        $xlsx = new XlsxStreamWriter($path);
        $xlsx->addSheet('Towary', [14, 45, 18, 7, 10, 10, 12, 12, 12, 26, 30, 22, 45, 16, 14, 18, 22, 17, 17, 22], header: true);
        $xlsx->addRow(['Kod XL', 'Nazwa XL', 'Nazwa1', 'Jedn.', 'Stan HANDEL', 'Stan wszystkie magazyny',
            'Wartość zapasu (zł, cena zakupu)', 'Ostatnia sprzedaż', 'Ostatni zakup', 'Ostatni dostawca', 'Status', 'Karta (SKU)',
            'Nazwa karty', 'Producent karty', 'Skąd kod', 'Kod z XL', 'Połączył', 'Data połączenia', 'Automat połączył',
            'Odrzucone karty (SKU)']);
        foreach (array_chunk($ids, 500) as $chunk) {
            $items = $this->withValue(ErpItem::query())
                ->whereIn('id', $chunk)
                ->with(['links.product:id,sku,name,manufacturer', 'links.decider:id,name'])
                ->get()
                ->keyBy('id');
            foreach ($chunk as $id) {
                $item = $items->get($id);
                if ($item !== null) {
                    $xlsx->addRow($this->exportRow($this->present($item)));
                }
            }
        }

        $xlsx->addSheet('Zestawienie', [40, 40]);
        $xlsx->addRow(['Powiązania z ERP XL — eksport', XlsxStreamWriter::dateTime(PolishTime::now())]);
        $xlsx->addRow(['Towarów w pliku', count($ids)]);
        $xlsx->addRow([]);
        $xlsx->addRow(['Filtry']);
        foreach ($this->filterLabels($v) as [$label, $value]) {
            $xlsx->addRow([$label, $value]);
        }
        $xlsx->addRow([]);
        $xlsx->addRow(['Kto połączył (te filtry, bez filtra „Połączył”)', 'Towarów']);
        foreach ($linkers as $l) {
            $xlsx->addRow([$l['name'], $l['count']]);
        }
        $xlsx->close();

        return response()->download($path, 'powiazania-erp-xl-'.PolishTime::now()->format('Y-m-d').'.xlsx', [
            'Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
        ])->deleteFileAfterSend();
    }

    /** @return array<string, list<mixed>> */
    private function listRules(Request $request): array
    {
        return [
            'status' => ['nullable', 'string', Rule::in([...array_keys(self::STATUS_OUTCOMES), 'unlinked', self::STATUS_AFTER_AUTO])],
            'group' => ['nullable', 'string', Rule::in([...self::GROUPS, 'other'])],
            'in_stock' => ['nullable', 'boolean'],
            'sold_months' => ['nullable', 'integer', Rule::in([3, 6, 12])],
            'stale_months' => ['nullable', 'integer', Rule::in(self::STALE_MONTHS)],
            'proposals' => ['nullable', 'string', Rule::in(self::PROPOSALS)],
            'supplier_match' => ['nullable', 'boolean'],
            'supplier' => ['nullable', 'string', 'max:100'],
            'search' => ['nullable', 'string', 'max:150'],
            'linked_by' => ['nullable', 'string', 'regex:/^(auto|[1-9]\d{0,9})$/'],
            'linked_from' => ['nullable', 'date_format:Y-m-d'],
            'linked_to' => ['nullable', 'date_format:Y-m-d', ...($request->filled('linked_from') ? ['after_or_equal:linked_from'] : [])],
            'sort' => ['nullable', 'string', Rule::in([...array_keys(self::SORTS), ...self::LINK_SORTS, self::SORT_VALUE])],
            'dir' => ['nullable', 'string', Rule::in(['asc', 'desc'])],
        ];
    }

    /**
     * Filtry listy bez filtra „Połączył” (ten dokłada filterLinkerAndSort — zestawienie liczy się bez niego).
     *
     * @param  array<string, mixed>  $v
     * @return Builder<ErpItem>
     */
    private function filtered(array $v): Builder
    {
        $query = $this->active();
        $status = (string) ($v['status'] ?? '');
        if ($status === 'unlinked') {
            $query->where(fn (Builder $q) => $q->whereNull('match_outcome')->orWhereNotIn('match_outcome', ['auto', 'confirmed']));
        } elseif ($status === self::STATUS_AFTER_AUTO) {
            $after = $this->linking('pl.auto_linked_at');
            $query->where('match_outcome', 'confirmed')->whereRaw('('.$after->toSql().') is not null', $after->getBindings());
        } elseif ($status !== '') {
            $query->whereIn('match_outcome', self::STATUS_OUTCOMES[$status]);
        }
        $group = (string) ($v['group'] ?? '');
        if ($group === 'other') {
            foreach (self::GROUPS as $letter) {
                $query->where('code', 'not like', $letter.'%');
            }
        } elseif ($group !== '') {
            $query->where('code', 'like', $group.'%');
        }
        if (! empty($v['in_stock'])) {
            $query->where('stock_trade', '>', 0);
        }
        if (! empty($v['sold_months'])) {
            $query->where('last_sale_at', '>=', now()->subMonths((int) $v['sold_months'])->toDateString());
        }
        if (! empty($v['stale_months'])) {
            $this->stale($query, (int) $v['stale_months']);
        }
        $proposals = (string) ($v['proposals'] ?? '');
        if ($proposals !== '') {
            $count = '(select count(*) from erp_item_links pc where pc.erp_item_id = erp_items.id'
                .' and pc.product_id is not null and pc.status in (?, ?))';
            $query->whereRaw($count.match ($proposals) {
                'one' => ' = 1',
                'many' => ' > 1',
                default => ' = 0',
            }, [ErpItemLink::STATUS_AUTO, ErpItemLink::STATUS_SUGGESTED]);
        }
        if (! empty($v['supplier_match'])) {
            // propozycja z dowodem „dostawca z zakupów XL = producent karty” (ErpItemMatcher / ErpSearchSuggester)
            $query->whereHas('links', fn (Builder $l) => $l->whereNotNull('product_id')
                ->whereIn('status', [ErpItemLink::STATUS_AUTO, ErpItemLink::STATUS_SUGGESTED])
                ->where('evidence->supplier_match', true));
        }
        $supplier = trim((string) ($v['supplier'] ?? ''));
        if ($supplier !== '') {
            $query->where('last_supplier', 'like', '%'.$this->like($supplier).'%');
        }
        $search = trim((string) ($v['search'] ?? ''));
        if ($search !== '') {
            $like = '%'.$this->like($search).'%';
            $query->where(fn (Builder $q) => $q->where('code', 'like', $like)
                ->orWhere('name', 'like', $like)
                ->orWhere('name1', 'like', $like)
                ->orWhereHas('links.product', fn (Builder $p) => $p->where('sku', 'like', $like)));
        }
        if (! empty($v['linked_from'])) {
            $query->where($this->linking(self::LINKED_AT_SQL), '>=', $this->polishDayStart((string) $v['linked_from']));
        }
        if (! empty($v['linked_to'])) {
            $query->where($this->linking(self::LINKED_AT_SQL), '<', $this->polishDayStart((string) $v['linked_to'], 1));
        }

        return $query;
    }

    /**
     * @param  Builder<ErpItem>  $query
     * @param  array<string, mixed>  $v
     */
    private function filterLinkerAndSort(Builder $query, array $v): void
    {
        $linkedBy = (string) ($v['linked_by'] ?? '');
        if ($linkedBy !== '') {
            $query->where($this->linking(self::LINKER_SQL), '=', $linkedBy === self::LINKER_AUTO ? 0 : (int) $linkedBy);
        }
        $sort = (string) ($v['sort'] ?? 'stock');
        $dir = ($v['dir'] ?? 'desc') === 'asc' ? 'asc' : 'desc';
        if ($sort === self::SORT_VALUE) {
            $query->orderByRaw(self::valueSql().' is null')->orderByRaw(self::valueSql().' '.$dir);
        } elseif (in_array($sort, self::LINK_SORTS, true)) {
            $this->orderByLinkValue($query, $sort, $dir);
        } else {
            $query->orderBy(self::SORTS[$sort], $dir);
        }
        $query->orderBy('id');
    }

    /**
     * Wiersz arkusza z present() — te same reguły co ekran (kto i kiedy, kolejność kart).
     *
     * @param  array<string, mixed>  $row
     * @return list<mixed>
     */
    private function exportRow(array $row): array
    {
        /** @var list<array<string, mixed>> $links */
        $links = $row['links'];
        $visible = array_values(array_filter($links, static fn (array $l): bool => $l['status'] !== ErpItemLink::STATUS_REJECTED));
        $rejected = array_values(array_filter($links, static fn (array $l): bool => $l['status'] === ErpItemLink::STATUS_REJECTED));
        $first = $visible[0] ?? null;
        $skus = static fn (array $list): string => implode('; ', array_map(
            static fn (array $l): string => $l['product']['sku'] ?? 'karta usunięta',
            $list,
        ));
        /** @var array{auto: bool, by: string|null, at: string|null, auto_at: string|null}|null $linked */
        $linked = $row['linked'];

        return [
            $row['code'],
            $row['name'],
            $row['name1'],
            $row['unit'],
            $row['stock_trade'],
            $row['stock_total'],
            $row['stock_value'],
            XlsxStreamWriter::date($row['last_sale_at']),
            XlsxStreamWriter::date($row['last_purchase_at']),
            $row['last_supplier'],
            $this->statusLabel($row['outcome'], $linked),
            $skus($visible),
            $first['product']['name'] ?? null,
            $first['product']['manufacturer'] ?? null,
            $first !== null ? (self::METHOD_LABELS[$first['method']] ?? $first['method']) : null,
            $first['matched_value'] ?? $row['match_value'],
            $linked === null ? null : ($linked['auto'] ? 'automat' : $linked['by']),
            XlsxStreamWriter::dateTime($this->polish($linked['at'] ?? null)),
            XlsxStreamWriter::dateTime($this->polish($linked['auto_at'] ?? null)),
            $rejected === [] ? null : $skus($rejected),
        ];
    }

    /** @param  array{auto: bool, by: string|null, at: string|null, auto_at: string|null}|null  $linked */
    private function statusLabel(?string $outcome, ?array $linked): string
    {
        $label = self::OUTCOME_LABELS[$outcome ?? ''] ?? 'nie przeliczone';

        return $outcome === 'confirmed' && ($linked['auto_at'] ?? null) !== null ? $label.' po automacie' : $label;
    }

    private function polish(?string $iso): ?CarbonImmutable
    {
        return $iso === null ? null : CarbonImmutable::parse($iso)->setTimezone(PolishTime::TIMEZONE);
    }

    /**
     * Użyte filtry po ludzku — arkusz „Zestawienie”.
     *
     * @param  array<string, mixed>  $v
     * @return list<array{0: string, 1: string}>
     */
    private function filterLabels(array $v): array
    {
        $status = (string) ($v['status'] ?? '');
        $linkedBy = (string) ($v['linked_by'] ?? '');
        $labels = [
            ['Status', self::STATUS_FILTER_LABELS[$status] ?? 'wszystkie'],
            ['Grupa', ($v['group'] ?? '') !== '' ? (string) $v['group'] : 'wszystkie'],
            ['Sprzedaż', ! empty($v['sold_months']) ? 'w ostatnich '.$v['sold_months'].' mies.' : 'dowolna'],
            ['Tylko ze stanem HANDEL', ! empty($v['in_stock']) ? 'tak' : 'nie'],
            ['Zalegające (stan, bez sprzedaży)', ! empty($v['stale_months']) ? 'od '.$v['stale_months'].' mies.' : '—'],
            ['Propozycje kart', self::PROPOSAL_LABELS[(string) ($v['proposals'] ?? '')] ?? 'dowolnie'],
            ['Dostawca XL = producent karty', ! empty($v['supplier_match']) ? 'tak' : '—'],
            ['Dostawca', trim((string) ($v['supplier'] ?? '')) ?: '—'],
            ['Szukaj', trim((string) ($v['search'] ?? '')) ?: '—'],
            ['Połączył', match (true) {
                $linkedBy === '' => 'wszyscy',
                $linkedBy === self::LINKER_AUTO => 'automat',
                default => (string) (User::query()->whereKey((int) $linkedBy)->value('name') ?? 'użytkownik #'.$linkedBy),
            }],
            ['Połączone od', (string) ($v['linked_from'] ?? '') ?: '—'],
            ['Połączone do', (string) ($v['linked_to'] ?? '') ?: '—'],
        ];
        $sort = (string) ($v['sort'] ?? 'stock');
        $labels[] = ['Sortowanie', (self::SORT_LABELS[$sort] ?? $sort).', '.(($v['dir'] ?? 'desc') === 'asc' ? 'rosnąco' : 'malejąco')];

        return $labels;
    }

    public function summary(): JsonResponse
    {
        $byOutcome = array_fill_keys(self::OUTCOMES, 0);
        foreach ($this->active()->selectRaw('match_outcome, count(*) as c')->groupBy('match_outcome')->get() as $row) {
            if ($row->match_outcome !== null && isset($byOutcome[$row->match_outcome])) {
                $byOutcome[$row->match_outcome] = (int) $row->c;
            }
        }
        $unlinked = fn (): Builder => $this->active()
            ->where(fn (Builder $q) => $q->whereNull('match_outcome')->orWhereNotIn('match_outcome', ['auto', 'confirmed']));
        $groups = [];
        foreach ($this->active()
            ->selectRaw("substr(code, 1, 1) as letter, count(*) as total, sum(case when match_outcome in ('auto', 'confirmed') then 1 else 0 end) as linked")
            ->groupBy(DB::raw('substr(code, 1, 1)'))
            ->get() as $row) {
            $letter = in_array(strtoupper((string) $row->letter), self::GROUPS, true) ? strtoupper((string) $row->letter) : 'other';
            $groups[$letter] ??= ['group' => $letter, 'total' => 0, 'linked' => 0];
            $groups[$letter]['total'] += (int) $row->total;
            $groups[$letter]['linked'] += (int) $row->linked;
        }
        $order = array_flip([...self::GROUPS, 'other']);
        uksort($groups, static fn (string $a, string $b): int => $order[$a] <=> $order[$b]);
        $syncedAt = $this->active()->max('synced_at');
        $stale = InventoryQuery::totals($this->stale($unlinked(), self::SUMMARY_STALE_MONTHS), valueSql: self::valueSql());

        return response()->json([
            'total' => $this->active()->count(),
            'synced_at' => $syncedAt !== null ? Carbon::parse((string) $syncedAt)->toIso8601String() : null,
            'by_outcome' => $byOutcome,
            'unlinked_sold_12m' => $unlinked()->where('last_sale_at', '>=', now()->subMonths(12)->toDateString())->count(),
            'unlinked_in_stock' => $unlinked()->where('stock_trade', '>', 0)->count(),
            // zalegające bez karty jak w Zapasach: wartość = ilość × cena zakupu, value_unknown = bez ceny zakupu
            'unlinked_stale' => ['months' => self::SUMMARY_STALE_MONTHS] + $stale,
            'groups' => array_values($groups),
            'linkers' => $this->linkers($this->active()),
        ]);
    }

    public function confirm(Request $request, ErpItemLink $link): JsonResponse
    {
        try {
            $item = $this->decisions->confirm($link, $request->user());
        } catch (InvalidArgumentException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        return response()->json(['item' => $this->presentFresh($item)]);
    }

    public function reject(Request $request, ErpItemLink $link): JsonResponse
    {
        return response()->json(['item' => $this->presentFresh($this->decisions->reject($link, $request->user()))]);
    }

    public function link(Request $request, ErpItem $item): JsonResponse
    {
        $v = $request->validate(['product_id' => ['required', 'integer', 'exists:products,id']]);
        $product = Product::query()->findOrFail((int) $v['product_id']);

        return response()->json(['item' => $this->presentFresh($this->decisions->link($item, $product, $request->user()))]);
    }

    public function bulkConfirm(Request $request): JsonResponse
    {
        $v = $request->validate([
            'ids' => ['required', 'array', 'min:1', 'max:200'],
            'ids.*' => ['integer'],
        ]);

        return response()->json(['confirmed' => $this->decisions->bulkConfirm(array_map('intval', $v['ids']), $request->user())]);
    }

    /** @return Builder<ErpItem> */
    private function active(): Builder
    {
        return ErpItem::query()->whereNull('removed_at')->where('archived', false);
    }

    private function like(string $value): string
    {
        return addcslashes($value, '%_\\');
    }

    /**
     * Zalegające jak lista Zapasów (InventoryQuery::inStock + unsoldSince, wszystkie magazyny): stan > 0 i ostatnia
     * sprzedaż przed progiem; nigdy niesprzedany — tylko gdy jego najstarsza partia leży dłużej niż próg.
     *
     * @param  Builder<ErpItem>  $query
     * @return Builder<ErpItem>
     */
    private function stale(Builder $query, int $months): Builder
    {
        return InventoryQuery::unsoldSince(
            $query->whereRaw(InventoryQuery::quantitySql().' > 0'),
            CarbonImmutable::today()->subMonthsNoOverflow($months),
        );
    }

    /**
     * Wartość zapasu towaru jak w Zapasach (ilość × cena zakupu: partie, a bez nich stan × ostatnia PZ, wszystkie
     * magazyny); towar bez stanu — null, nie „0 zł”.
     */
    private static function valueSql(): string
    {
        return '(case when '.InventoryQuery::quantitySql().' > 0 then '.InventoryQuery::valueSql().' end)';
    }

    /**
     * @param  Builder<ErpItem>  $query
     * @return Builder<ErpItem>
     */
    private function withValue(Builder $query): Builder
    {
        return $query->select('erp_items.*')->selectRaw(self::valueSql().' as purchase_value');
    }

    /**
     * Podzapytanie o powiązanie, które łączy towar z kartą: najnowsze potwierdzone, bez niego automat — ta sama
     * reguła co linkedBy() przy wierszu.
     */
    private function linking(string $select): QueryBuilder
    {
        return DB::table('erp_item_links as pl')
            ->selectRaw($select)
            ->whereColumn('pl.erp_item_id', 'erp_items.id')
            ->whereNotNull('pl.product_id')
            ->whereIn('pl.status', [ErpItemLink::STATUS_CONFIRMED, ErpItemLink::STATUS_AUTO])
            ->orderByRaw("case when pl.status = 'confirmed' then 0 else 1 end")
            ->orderByRaw('coalesce(pl.decided_at, pl.created_at) desc')
            ->orderByDesc('pl.id')
            ->limit(1);
    }

    /** @param  Builder<ErpItem>  $query */
    private function orderByLinkValue(Builder $query, string $sort, string $dir): void
    {
        if ($sort === 'status') {
            $sql = 'case match_outcome';
            $bindings = [];
            foreach (self::OUTCOME_RANK as $outcome => $rank) {
                $sql .= ' when ? then '.$rank;
                $bindings[] = $outcome;
            }
            $query->orderByRaw($sql.' else 9 end '.$dir, $bindings);

            return;
        }
        $value = match ($sort) {
            // pierwsza karta w kolejności wiersza: potwierdzona, automat, propozycja (bez odrzuconych)
            'card' => DB::table('erp_item_links as cl')
                ->join('products as cp', 'cp.id', '=', 'cl.product_id')
                ->select('cp.sku')
                ->whereColumn('cl.erp_item_id', 'erp_items.id')
                ->where('cl.status', '!=', ErpItemLink::STATUS_REJECTED)
                ->orderByRaw("case cl.status when 'confirmed' then 0 when 'auto' then 1 else 2 end")
                ->orderBy('cl.id')
                ->limit(1),
            'linked_by' => $this->linking(self::LINKER_NAME_SQL),
            default => $this->linking(self::LINKED_AT_SQL),
        };
        $sql = '('.$value->toSql().')';
        $query->orderByRaw($sql.' is null', $value->getBindings())->orderByRaw($sql.' '.$dir, $value->getBindings());
        if ($sort === 'linked_by') {
            $at = $this->linking(self::LINKED_AT_SQL);
            $query->orderByRaw('('.$at->toSql().') desc', $at->getBindings());
        }
    }

    /**
     * Kto ile towarów połączył (automat pierwszy, potem osoby od największej liczby).
     *
     * @param  Builder<ErpItem>  $query
     * @return list<array{key: string|null, name: string, count: int}>
     */
    private function linkers(Builder $query): array
    {
        $rows = DB::query()
            ->fromSub($query->select(['linker' => $this->linking(self::LINKER_SQL)]), 'x')
            ->whereNotNull('linker')
            ->selectRaw('linker, count(*) as c')
            ->groupBy('linker')
            ->get();
        $names = User::query()
            ->whereIn('id', $rows->pluck('linker')->map(fn ($id): int => (int) $id)->filter(fn (int $id): bool => $id > 0)->all())
            ->pluck('name', 'id');
        $out = [];
        foreach ($rows as $row) {
            $id = (int) $row->linker;
            $out[] = [
                'key' => match (true) {
                    $id === 0 => self::LINKER_AUTO,
                    $id > 0 => (string) $id,
                    default => null,
                },
                'name' => match (true) {
                    $id === 0 => 'automat',
                    $id > 0 => (string) ($names[$id] ?? 'użytkownik #'.$id),
                    default => 'osoba usunięta z systemu',
                },
                'count' => (int) $row->c,
            ];
        }
        usort($out, static fn (array $a, array $b): int => [$a['key'] !== self::LINKER_AUTO, $b['count'], $a['name']]
            <=> [$b['key'] !== self::LINKER_AUTO, $a['count'], $b['name']]);

        return $out;
    }

    /** Północ dnia polskiego (+ dni) jako chwila UTC w zapisie bazy. */
    private function polishDayStart(string $date, int $addDays = 0): string
    {
        return CarbonImmutable::createFromFormat('!Y-m-d', $date, PolishTime::TIMEZONE)
            ->addDays($addDays)
            ->utc()
            ->format('Y-m-d H:i:s');
    }

    /**
     * Kto i kiedy połączył towar z kartą — powiązanie wybrane jak w linking().
     *
     * auto_at — kiedy automat połączył tę kartę (przy potwierdzonej: „po automacie”; null = automat nie łączył albo
     * potwierdzenie sprzed zapisywania tej daty).
     *
     * @return array{auto: bool, by: string|null, at: string|null, auto_at: string|null}|null
     */
    private function linkedBy(ErpItem $item): ?array
    {
        $link = $item->links
            ->filter(static fn (ErpItemLink $l): bool => $l->product_id !== null
                && in_array($l->status, [ErpItemLink::STATUS_CONFIRMED, ErpItemLink::STATUS_AUTO], true))
            ->sort(static function (ErpItemLink $a, ErpItemLink $b): int {
                $at = static fn (ErpItemLink $l): string => (string) ($l->decided_at ?? $l->created_at)?->format('Y-m-d H:i:s');

                return [$a->status !== ErpItemLink::STATUS_CONFIRMED, $at($b), $b->id]
                    <=> [$b->status !== ErpItemLink::STATUS_CONFIRMED, $at($a), $a->id];
            })
            ->first();
        if ($link === null) {
            return null;
        }
        $auto = $link->status === ErpItemLink::STATUS_AUTO;
        $autoAt = $link->auto_linked_at ?? ($auto ? $link->created_at : null);
        $at = $auto ? $autoAt : ($link->decided_at ?? $link->created_at);

        return [
            'auto' => $auto,
            'by' => $auto ? null : ($link->decider?->name ?? ($link->decided_by !== null ? 'użytkownik #'.$link->decided_by : 'osoba usunięta z systemu')),
            'at' => $at?->toIso8601String(),
            'auto_at' => $autoAt?->toIso8601String(),
        ];
    }

    /** @return array<string, mixed> */
    private function presentFresh(ErpItem $item): array
    {
        $fresh = $this->withValue(ErpItem::query())
            ->with(['links.product:id,sku,name,manufacturer', 'links.decider:id,name'])
            ->find($item->id);

        return $this->present($fresh ?? $item);
    }

    /** @return array<string, mixed> */
    private function present(ErpItem $item): array
    {
        $links = $item->links
            ->sortBy(fn (ErpItemLink $l): string => (self::LINK_ORDER[$l->status] ?? 9).'-'.str_pad((string) $l->id, 10, '0', STR_PAD_LEFT))
            ->map(static fn (ErpItemLink $l): array => [
                'id' => $l->id,
                'status' => $l->status,
                'method' => $l->method,
                'matched_value' => $l->matched_value,
                'evidence' => $l->evidence,
                'decided_at' => $l->decided_at?->toIso8601String(),
                'decided_by' => $l->decider?->name,
                'auto_linked_at' => $l->auto_linked_at?->toIso8601String(),
                'product' => $l->product === null ? null : [
                    'id' => $l->product->id,
                    'sku' => (string) $l->product->sku,
                    'name' => (string) $l->product->name,
                    'manufacturer' => $l->product->manufacturer !== '' ? $l->product->manufacturer : null,
                ],
            ])
            ->values()
            ->all();

        return [
            'id' => $item->id,
            'xl_gid' => $item->xl_gid,
            'code' => $item->code,
            'name' => $item->name,
            'name1' => $item->name1,
            'unit' => $item->unit,
            'archived' => (bool) $item->archived,
            'stock_trade' => (float) $item->stock_trade,
            'stock_total' => (float) $item->stock_total,
            // ilość × cena zakupu (withValue); null = bez stanu albo bez ceny zakupu
            'stock_value' => $item->getAttribute('purchase_value') !== null ? round((float) $item->getAttribute('purchase_value'), 2) : null,
            'last_sale_at' => $item->last_sale_at?->toDateString(),
            'last_purchase_at' => $item->last_purchase_at?->toDateString(),
            'last_supplier' => $item->last_supplier,
            'outcome' => $item->match_outcome,
            'match_value' => $item->match_value,
            'linked' => $this->linkedBy($item),
            'links' => $links,
        ];
    }
}
