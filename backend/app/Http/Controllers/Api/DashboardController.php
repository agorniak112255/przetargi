<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\B2bAccount;
use App\Models\B2bSyncRun;
use App\Models\Campaign;
use App\Models\CampaignRecipient;
use App\Models\CampaignReply;
use App\Models\ClientInquiry;
use App\Models\InquiryOrderHint;
use App\Models\PriceListImport;
use App\Models\Product;
use App\Models\ProductSubstitute;
use App\Models\Tender;
use App\Models\User;
use App\Services\B2b\B2bConnectorRegistry;
use App\Services\Campaigns\CampaignResult;
use App\Services\Erp\InventoryBoardTotals;
use App\Services\Erp\InventoryQuery;
use App\Services\Erp\InventorySnapshots;
use App\Services\Notifications\OfferValidityPlanner;
use App\Services\Pricing\SupplierSpecialMask;
use App\Services\ProductCatalogHealthService;
use App\Support\PolishTime;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Dashboard (makieta „A + C”, 02.10.2026): po jednej karcie na moduł — przetargi, produkty, zapasy, cenniki, kampanie.
 * Sekcja bez uprawnienia do modułu jest null (karta znika). Same tanie zapytania: liczniki, zapis stanu zapasów z nocy
 * zamiast liczenia koszyków na żywo, bez przechodzenia przez cały katalog jak raport jakości katalogu.
 */
class DashboardController extends Controller
{
    /** Przetargi poza pracą: „w toku” to reszta (jak licznik terminów). */
    private const TENDER_CLOSED = ['exported', 'odrzucony', 'archiwum'];

    /** Etapy na karcie — bez odrzuconych i archiwum. */
    private const TENDER_STAGES_HIDDEN = ['odrzucony', 'archiwum'];

    private const UPCOMING_LIMIT = 4;

    /** „Do zrobienia dziś”: najwięcej tylu spraw jednego rodzaju. */
    private const TODO_LIMIT = 10;

    /** Termin składania: przetargi jeszcze niezłożone (jak przypomnienia tenders:remind). */
    private const TODO_DEADLINE_STATUSES = ['draft', 'wycena', 'akceptacja_km', 'akceptacja_dyrektor', 'zatwierdzona'];

    private const TODO_DEADLINE_DAYS = 7;

    private const TODO_INQUIRY_DAYS = 14;

    /** „Wpisz wynik”: bez szkiców i odrzuconych, najdłużej tyle dni po terminie (jak przypomnienia). */
    private const TODO_RESULT_SKIP_STATUSES = ['draft', 'odrzucony'];

    private const TODO_RESULT_DAYS = 60;

    private const WON_DAYS = 90;

    private const IMPORTS_LIMIT = 3;

    private const PRICE_CHANGE_DAYS = 7;

    /** Zapasy: magazyny handlowe, wszystkie oddziały — domyślny widok raportu dla zarządu. */
    private const STOCK_SCOPE = 'trade';

    private const STOCK_BUCKETS = ['stock', 'no_sale_6', 'no_sale_12', 'no_sale_24', 'never_sold'];

    private const TOP_UNSOLD_LIMIT = 3;

    private const CAMPAIGNS_RECENT = 3;

    private const CAMPAIGN_DAYS = 30;

    /** Kampanie po starcie wysyłki — cudze widać z campaigns.view (jak lista kampanii). */
    private const CAMPAIGN_STARTED = [Campaign::STATUS_SENDING, Campaign::STATUS_SENT, Campaign::STATUS_CANCELLED];

    public function __construct(
        private readonly B2bConnectorRegistry $connectors,
        private readonly ProductCatalogHealthService $catalogHealth,
        private readonly OfferValidityPlanner $offerValidity,
    ) {}

    public function __invoke(Request $request): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();

        return response()->json([
            // trasa wymaga dashboard.view — „Do zrobienia dziś” jest więc zawsze; jego części zależą od modułów
            'todo' => $user->can('dashboard.view') ? $this->todo($user) : null,
            'tenders' => $user->canAny(['tenders.view_own', 'tenders.view_all']) ? $this->tenders($user) : null,
            'products' => $user->can('products.view') ? $this->products() : null,
            'stock' => $user->canAny(['inventory.view', 'inventory.report.view']) ? $this->stock() : null,
            'prices' => $user->canAny(['price_lists.view', 'b2b_accounts.view']) ? $this->prices($user) : null,
            'campaigns' => $user->canAny(['campaigns.use', 'campaigns.view']) ? $this->campaigns($user) : null,
        ]);
    }

    /**
     * „Do zrobienia dziś” — tylko sprawy zalogowanej osoby (przetargi, które prowadzi albo do których ją zaproszono,
     * jej zapytania, jej wzmianki), nawet gdy widzi cudze. Każda lista ma limit; „dziś” w czasie polskim.
     *
     * @return array{items: list<array<string, mixed>>, won_90d: array{won_lots: int, decided_lots: int}|null}
     */
    private function todo(User $user): array
    {
        $canTenders = $user->canAny(['tenders.view_own', 'tenders.view_all']);
        $items = [];
        if ($canTenders) {
            array_push($items, ...$this->todoDeadlines($user));
        }
        if ($user->can('inquiries.use')) {
            $waiting = $this->todoInquiries($user);
            if ($waiting !== null) {
                $items[] = $waiting;
            }
            array_push($items, ...$this->todoOfferValidity($user));
        }
        if ($canTenders) {
            array_push($items, ...$this->todoResults($user));
        }
        array_push($items, ...$this->todoMentions($user));

        return [
            'items' => $items,
            'won_90d' => $canTenders ? $this->won90d($user) : null,
        ];
    }

    /** Przetargi użytkownika (opiekun albo zaproszony) — bez cudzych, nawet z tenders.view_all. */
    private function mine(User $user): Builder
    {
        return Tender::query()->accessibleBy($user);
    }

    /**
     * Termin składania dziś lub w ciągu 7 dni (przetarg jeszcze niezłożony) i braki oferty: pozycje bez produktu
     * (bez karty i bez własnej nazwy) i bez ceny — jednym zapytaniem z GROUP BY tender_id (same agregaty).
     * Dzisiejszy termin z godziną, która już minęła, to już nie sprawa na dziś: odpada w zapytaniu (godzina „na
     * zegarze” w Polsce, żeby nie zajmował miejsca w limicie) i ostatecznie przez PolishTime::deadlineAt().
     *
     * @return list<array<string, mixed>>
     */
    private function todoDeadlines(User $user): array
    {
        $now = PolishTime::now();
        $today = $now->startOfDay();
        $tenders = $this->mine($user)
            ->with('client:id,name')
            ->whereIn('status', self::TODO_DEADLINE_STATUSES)
            ->whereNotNull('deadline')
            ->whereDate('deadline', '>=', $today->toDateString())
            ->whereDate('deadline', '<=', $today->addDays(self::TODO_DEADLINE_DAYS)->toDateString())
            ->where(static function (Builder $q) use ($today, $now): void {
                $q->whereDate('deadline', '>', $today->toDateString())
                    ->orWhereNull('deadline_time')
                    ->orWhere('deadline_time', '>', $now->format('H:i:s'));
            })
            ->orderBy('deadline')
            ->orderByRaw('deadline_time is null')
            ->orderBy('deadline_time')
            ->orderBy('id')
            ->limit(self::TODO_LIMIT)
            ->get(['id', 'number', 'notice_number', 'title', 'client_id', 'deadline', 'deadline_time'])
            ->reject(static function (Tender $t) use ($now): bool {
                $at = PolishTime::deadlineAt($t);

                return $at !== null && $at->lessThanOrEqualTo($now);
            })
            ->values();
        if ($tenders->isEmpty()) {
            return [];
        }

        $missing = DB::table('tender_items')
            ->whereIn('tender_id', $tenders->pluck('id')->all())
            ->groupBy('tender_id')
            ->select('tender_id')
            ->selectRaw("sum(case when main_product_id is null and trim(coalesce(custom_name, '')) = '' then 1 else 0 end) as without_product")
            ->selectRaw('sum(case when offer_price is null then 1 else 0 end) as without_price')
            ->get()
            ->keyBy('tender_id');

        return $tenders->map(static function (Tender $t) use ($missing): array {
            $m = $missing[$t->id] ?? null;

            return [
                'kind' => 'tender_deadline',
                'tender_id' => (int) $t->id,
                'number' => (string) $t->number,
                'notice_number' => $t->notice_number,
                'title' => $t->title,
                'client' => $t->client?->name,
                'deadline' => $t->deadline !== null ? Carbon::parse($t->deadline)->toDateString() : null,
                'deadline_time' => $t->deadline_time,
                'missing' => [
                    'without_product' => (int) ($m->without_product ?? 0),
                    'without_price' => (int) ($m->without_price ?? 0),
                ],
                'url' => '/tenders/'.$t->id,
            ];
        })->values()->all();
    }

    /**
     * Zapytania użytkownika bez odpowiedzi dłużej niż dobę (od chwili wysłania przez klienta, gdy ją znamy) —
     * bez duplikatów cudzego wpisu i tylko z ostatnich 14 dni (starsze to już historia, nie sprawa na dziś).
     *
     * @return array<string, mixed>|null
     */
    private function todoInquiries(User $user): ?array
    {
        $now = now();
        $waiting = static fn (): Builder => ClientInquiry::query()
            ->where('user_id', $user->id)
            ->whereNull('replied_at')
            ->whereNull('duplicate_of_id')
            ->where('created_at', '>=', $now->copy()->subDays(self::TODO_INQUIRY_DAYS))
            ->whereRaw('coalesce(source_sent_at, created_at) <= ?', [$now->copy()->subDay()->format('Y-m-d H:i:s')]);

        $count = $waiting()->count();
        if ($count === 0) {
            return null;
        }
        $oldest = $waiting()
            ->with('client:id,name')
            ->orderByRaw('coalesce(source_sent_at, created_at)')
            ->orderBy('id')
            ->first(['id', 'client_id', 'contact', 'source_from_name', 'source_from_email', 'source_sent_at', 'created_at']);

        return [
            'kind' => 'inquiries_waiting',
            'count' => $count,
            'oldest' => $oldest !== null ? [
                'id' => (int) $oldest->id,
                'client' => $oldest->client?->name
                    ?? (is_array($oldest->contact) && is_string($oldest->contact['company'] ?? null) && $oldest->contact['company'] !== '' ? $oldest->contact['company'] : null)
                    ?? $oldest->source_from_name
                    ?? $oldest->source_from_email,
                'since' => ($oldest->source_sent_at ?? $oldest->created_at)?->toIso8601String(),
            ] : null,
            'url' => '/inquiries?status=waiting',
            // lista pod linkiem pokazuje wszystkie zapytania bez odpowiedzi — karta liczy tylko te, opis to mówi
            'scope_label' => 'bez odpowiedzi ponad dobę, z ostatnich '.self::TODO_INQUIRY_DAYS.' dni',
        ];
    }

    /**
     * Własne oferty z zapytań, którym kończy się ważność, a wynik nie jest wpisany — od ostatniego dnia roboczego przed
     * końcem ważności do jej końca (ta sama reguła co przypomnienie offer_validity_ending). has_hint: ERP XL podpowiada
     * możliwe zamówienie z tej oferty (wniosek — handlowiec sprawdza i wpisuje wynik sam).
     *
     * @return list<array<string, mixed>>
     */
    private function todoOfferValidity(User $user): array
    {
        $rows = array_slice($this->offerValidity->ending(PolishTime::today(), (int) $user->id), 0, self::TODO_LIMIT);
        if ($rows === []) {
            return [];
        }
        $ids = array_map(static fn (array $r): int => (int) $r['inquiry']->id, $rows);
        $withHint = array_flip(InquiryOrderHint::query()->whereIn('client_inquiry_id', $ids)->distinct()
            ->pluck('client_inquiry_id')->map(static fn ($id): int => (int) $id)->all());

        return array_map(static fn (array $r): array => [
            'kind' => 'offer_validity_ending',
            'inquiry_id' => (int) $r['inquiry']->id,
            'client' => OfferValidityPlanner::clientLabel($r['inquiry']),
            'subject' => $r['inquiry']->source_subject ?? $r['inquiry']->reply_subject,
            'valid_until' => $r['valid_until']->toDateString(),
            'has_hint' => isset($withHint[(int) $r['inquiry']->id]),
            'url' => '/inquiries/'.$r['inquiry']->id,
        ], $rows);
    }

    /**
     * Po terminie składania, bez wyniku: od dnia po terminie przez 60 dni, bez szkiców i odrzuconych.
     *
     * @return list<array<string, mixed>>
     */
    private function todoResults(User $user): array
    {
        $today = PolishTime::today();

        return $this->mine($user)
            ->with('client:id,name')
            ->whereNotIn('status', self::TODO_RESULT_SKIP_STATUSES)
            ->whereNull('result_status')
            ->whereNotNull('deadline')
            ->whereDate('deadline', '<', $today->toDateString())
            ->whereDate('deadline', '>=', $today->subDays(self::TODO_RESULT_DAYS)->toDateString())
            ->orderBy('deadline')
            ->orderBy('id')
            ->limit(self::TODO_LIMIT)
            ->get(['id', 'number', 'title', 'client_id', 'deadline'])
            ->map(static fn (Tender $t): array => [
                'kind' => 'tender_result_needed',
                'tender_id' => (int) $t->id,
                'number' => (string) $t->number,
                'title' => $t->title,
                'client' => $t->client?->name,
                'deadline' => $t->deadline !== null ? Carbon::parse($t->deadline)->toDateString() : null,
                'url' => '/tenders/'.$t->id.'?tab=wynik',
            ])
            ->values()
            ->all();
    }

    /**
     * Nieprzeczytane wzmianki z dzwonka (powiadomienie typu tender_mention). Filtr LIKE tylko zawęża nieprzeczytane
     * powiadomienia tej osoby; o rodzaju rozstrzyga odczytane pole `type`.
     *
     * @return list<array<string, mixed>>
     */
    private function todoMentions(User $user): array
    {
        $out = [];
        $rows = $user->unreadNotifications()
            ->where('data', 'like', '%"tender_mention"%')
            ->limit(self::TODO_LIMIT * 3)
            ->get(['id', 'data', 'created_at']);
        foreach ($rows as $n) {
            $data = is_array($n->data) ? $n->data : [];
            if (($data['type'] ?? null) !== 'tender_mention') {
                continue;
            }
            $out[] = [
                'kind' => 'mention',
                'notification_id' => (string) $n->id,
                'title' => (string) ($data['title'] ?? $data['message'] ?? 'Wspomniano o Tobie w komentarzu'),
                'body' => isset($data['body']) && is_string($data['body']) ? $data['body'] : null,
                'url' => isset($data['url']) && is_string($data['url']) ? $data['url'] : null,
                'created_at' => $n->created_at?->toIso8601String(),
            ];
            if (count($out) >= self::TODO_LIMIT) {
                break;
            }
        }

        return $out;
    }

    /**
     * Części z rozstrzygnięciem (wygrana albo przegrana) w przetargach z terminem w ostatnich 90 dniach — zakres
     * jak karta „Przetargi” (z tenders.view_all wszystkie). Same agregaty, bez GROUP BY.
     *
     * @return array{won_lots: int, decided_lots: int}
     */
    private function won90d(User $user): array
    {
        $today = PolishTime::today();
        $tenderIds = ($user->can('tenders.view_all') ? Tender::query() : Tender::query()->accessibleBy($user))
            ->whereNotNull('deadline')
            ->whereDate('deadline', '>=', $today->subDays(self::WON_DAYS)->toDateString())
            ->whereDate('deadline', '<=', $today->toDateString())
            ->select('id');
        $row = DB::table('tender_lots')
            ->whereIn('tender_id', $tenderIds)
            ->whereIn('outcome', ['won', 'lost'])
            ->selectRaw("count(*) as decided, sum(case when outcome = 'won' then 1 else 0 end) as won")
            ->first();

        return [
            'won_lots' => (int) ($row->won ?? 0),
            'decided_lots' => (int) ($row->decided ?? 0),
        ];
    }

    /** @return array<string, mixed> */
    private function tenders(User $user): array
    {
        $seeAll = $user->can('tenders.view_all');
        // bez prices.supplier_special.view marża bliźniacza (od ceny standardowej kart z ceną specjalną B2B)
        $marginColumn = SupplierSpecialMask::forUser($user)->hides() ? 'margin_percent_standard' : 'margin_percent';
        $scoped = static fn (): Builder => $seeAll ? Tender::query() : Tender::query()->accessibleBy($user);
        $active = static fn (): Builder => $scoped()->whereNotIn('status', self::TENDER_CLOSED);
        // „dziś” w czasie polskim (aplikacja liczy w UTC — między 0:00 a 2:00 byłby to jeszcze wczorajszy dzień)
        $todayPl = PolishTime::today();
        $today = $todayPl->toDateString();

        $stages = $scoped()
            ->whereNotIn('status', self::TENDER_STAGES_HIDDEN)
            ->groupBy('status')
            ->selectRaw('status, count(*) as cnt, coalesce(sum(offer_value_net), 0) as value_net')
            ->get()
            ->map(static fn ($r): array => [
                'status' => (string) $r->status,
                'count' => (int) $r->cnt,
                'value_net' => round((float) $r->value_net, 2),
            ])
            ->values()
            ->all();

        return [
            'active' => $active()->count(),
            'value_net' => round((float) $active()->sum('offer_value_net'), 2),
            // null = żaden przetarg w toku nie ma jeszcze marży (nie 0%)
            'avg_margin_percent' => ($margin = $active()->whereNotNull($marginColumn)->avg($marginColumn)) !== null ? round((float) $margin, 1) : null,
            'deadline_soon' => $active()
                ->whereNotNull('deadline')
                ->whereDate('deadline', '<=', $todayPl->addDays(7)->toDateString())
                ->whereDate('deadline', '>=', $today)
                ->count(),
            'stages' => $stages,
            // tylko pola wiersza terminu — bez cen i marż (maska cen specjalnych nie ma tu czego ukrywać)
            'upcoming' => $active()
                ->with('client:id,name')
                ->whereNotNull('deadline')
                ->whereDate('deadline', '>=', $today)
                ->orderBy('deadline')
                ->orderByRaw('deadline_time is null')
                ->orderBy('deadline_time')
                ->orderBy('id')
                ->limit(self::UPCOMING_LIMIT)
                ->get(['id', 'number', 'title', 'client_id', 'status', 'deadline', 'deadline_time'])
                ->map(static fn (Tender $t): array => [
                    'id' => $t->id,
                    'number' => $t->number,
                    'title' => $t->title,
                    'client' => $t->client?->name,
                    'status' => $t->status,
                    'deadline' => $t->deadline !== null ? Carbon::parse($t->deadline)->toDateString() : null,
                    'deadline_time' => $t->deadline_time,
                ])
                ->values()
                ->all(),
        ];
    }

    /**
     * Liczniki jak raport jakości katalogu (ProductCatalogHealthService::report): „bez opisu” bez kart do ręcznego
     * opisu, „z opisem” po obcięciu spacji.
     *
     * @return array<string, mixed>
     */
    private function products(): array
    {
        return [
            'total' => Product::query()->count(),
            'with_description' => Product::query()->whereRaw("trim(coalesce(description, '')) <> ''")->count(),
            'missing_description' => Product::query()
                ->where('enrichment_status', '!=', Product::ENRICHMENT_MANUAL)
                ->where(fn (Builder $q) => $q->whereNull('description')->orWhere('description', ''))
                ->count(),
            'missing_images' => Product::query()->whereDoesntHave('images')->count(),
            'manual_review' => Product::query()->where('enrichment_status', Product::ENRICHMENT_MANUAL)->count(),
            'vector_indexed' => $this->catalogHealth->vectorReport()['indexed'],
            'substitutes_pending' => ProductSubstitute::query()->where('approval_status', 'oczekuje')->count(),
        ];
    }

    /**
     * Koszyki z ostatniego zapisu stanu (InventorySnapshots, noc po odczycie z XL) — te same liczby co historia raportu
     * dla zarządu; null, gdy jeszcze nic nie zapisano. Najdroższy towar bez sprzedaży ponad rok — na żywo (3 wiersze).
     *
     * @return array<string, mixed>
     */
    private function stock(): array
    {
        $row = DB::table(InventorySnapshots::TABLE)
            ->where('location', '')
            ->where('scope', self::STOCK_SCOPE)
            ->orderByDesc('taken_on')
            ->first(['taken_on', 'totals']);
        $buckets = null;
        if ($row !== null) {
            $totals = json_decode((string) $row->totals, true);
            $saved = is_array($totals['buckets'] ?? null) ? $totals['buckets'] : [];
            $buckets = [];
            foreach (self::STOCK_BUCKETS as $key) {
                $b = $saved[$key] ?? null;
                $buckets[$key] = is_array($b) ? ['items' => (int) ($b['items'] ?? 0), 'value' => round((float) ($b['value'] ?? 0), 2)] : null;
            }
        }

        $value = InventoryQuery::valueSql(self::STOCK_SCOPE);
        $sale = InventoryQuery::lastSaleSql();
        $top = InventoryBoardTotals::today()->bucketQuery('no_sale_12', self::STOCK_SCOPE)
            ->whereRaw($value.' is not null')
            ->select(['erp_items.id', 'erp_items.code', 'erp_items.name'])
            ->selectRaw($value.' as purchase_value')
            ->selectRaw($sale.' as scope_last_sale')
            ->orderByRaw($value.' desc')
            ->orderBy('erp_items.code')
            ->limit(self::TOP_UNSOLD_LIMIT)
            ->get()
            ->map(static fn ($item): array => [
                'code' => (string) $item->code,
                'name' => (string) $item->name,
                'value' => round((float) $item->getAttribute('purchase_value'), 2),
                'last_sale_at' => $item->getAttribute('scope_last_sale') !== null
                    ? Carbon::parse((string) $item->getAttribute('scope_last_sale'))->toDateString()
                    : null,
            ])
            ->values()
            ->all();

        return [
            'as_of' => $row !== null ? substr((string) $row->taken_on, 0, 10) : null,
            'buckets' => $buckets,
            'top_unsold' => $top,
        ];
    }

    /** @return array<string, mixed> */
    private function prices(User $user): array
    {
        $accounts = null;
        if ($user->can('b2b_accounts.view')) {
            $accounts = B2bAccount::query()
                ->orderBy('id')
                ->get(['id', 'username', 'connector', 'sync_frequency', 'last_sync_status', 'last_sync_finished_at', 'last_sync_message'])
                ->map(function (B2bAccount $a): array {
                    $state = $this->accountState($a);

                    return [
                        'id' => $a->id,
                        'label' => $this->connectors->label($a->connector) ?? $a->username,
                        'state' => $state,
                        'finished_at' => $a->last_sync_finished_at?->toIso8601String(),
                        // błąd tylko przy koncie, które ma się synchronizować — wyłączone nie straszy starym
                        'message' => $state === 'failed' ? $a->last_sync_message : null,
                        'progress' => null,
                    ];
                })
                ->values()
                ->all();
            $running = array_column(array_filter($accounts, static fn (array $a): bool => $a['state'] === 'running'), 'id');
            if ($running !== []) {
                $progress = B2bSyncRun::query()
                    ->whereIn('b2b_account_id', $running)
                    ->where('status', B2bSyncRun::STATUS_RUNNING)
                    ->orderBy('id')
                    ->get(['b2b_account_id', 'total', 'processed'])
                    ->mapWithKeys(static fn (B2bSyncRun $r): array => [
                        (int) $r->b2b_account_id => (int) $r->total > 0 ? (int) floor((int) $r->processed / (int) $r->total * 100) : null,
                    ]);
                foreach ($accounts as $i => $a) {
                    $accounts[$i]['progress'] = $progress[$a['id']] ?? null;
                }
            }
        }

        $canLists = $user->can('price_lists.view');

        return [
            'accounts' => $accounts,
            // zmiany cen z przebiegów B2B i z plików — oba zapisują raport importu
            'prices_changed_7d' => $canLists
                ? (int) PriceListImport::query()->where('created_at', '>=', now()->subDays(self::PRICE_CHANGE_DAYS))->sum('prices_changed')
                : null,
            'file_imports' => $canLists
                ? PriceListImport::query()
                    ->with('priceList:id,manufacturer')
                    ->where('source', PriceListImport::SOURCE_FILE)
                    ->orderByDesc('created_at')
                    ->orderByDesc('id')
                    ->limit(self::IMPORTS_LIMIT)
                    ->get(['id', 'price_list_id', 'rows_total', 'prices_changed', 'created_at'])
                    ->map(static fn (PriceListImport $i): array => [
                        'id' => $i->id,
                        'manufacturer' => $i->priceList?->manufacturer,
                        'rows_total' => (int) $i->rows_total,
                        'prices_changed' => (int) $i->prices_changed,
                        'created_at' => $i->created_at?->toIso8601String(),
                    ])
                    ->values()
                    ->all()
                : null,
        ];
    }

    /** ok / failed / running / off (bez łącznika albo bez harmonogramu) / never (jeszcze nie sprawdzane). */
    private function accountState(B2bAccount $account): string
    {
        if ($account->last_sync_status === B2bSyncRun::STATUS_RUNNING) {
            return 'running';
        }
        if (trim((string) $account->connector) === '' || ($account->sync_frequency ?? 'off') === 'off') {
            return 'off';
        }

        return match ($account->last_sync_status) {
            B2bSyncRun::STATUS_OK => 'ok',
            B2bSyncRun::STATUS_FAILED => 'failed',
            B2bSyncRun::STATUS_CANCELLED => 'cancelled',
            default => 'never',
        };
    }

    /**
     * Widoczność jak lista kampanii: campaigns.manage — wszystkie, campaigns.view — własne i cudze po starcie wysyłki,
     * reszta — własne.
     *
     * @return array<string, mixed>
     */
    private function campaigns(User $user): array
    {
        $manager = $user->can('campaigns.manage');
        $viewer = $user->can('campaigns.view');
        $visible = static function () use ($user, $manager, $viewer): Builder {
            $query = Campaign::query();
            if ($manager) {
                return $query;
            }
            if ($viewer) {
                return $query->where(static fn (Builder $q) => $q->where('campaigns.user_id', $user->id)->orWhereIn('campaigns.status', self::CAMPAIGN_STARTED));
            }

            return $query->where('campaigns.user_id', $user->id);
        };
        $since = now()->subDays(self::CAMPAIGN_DAYS);

        $next = $visible()
            ->where('status', Campaign::STATUS_SCHEDULED)
            ->whereNotNull('scheduled_at')
            ->orderBy('scheduled_at')
            ->first(['id', 'name', 'scheduled_at']);

        $recent = $visible()
            ->whereIn('status', [Campaign::STATUS_SENDING, Campaign::STATUS_SENT])
            ->withCount([
                'recipients as sent_count' => fn (Builder $q) => $q->where('status', CampaignRecipient::STATUS_SENT),
                'recipients as clicked_count' => fn (Builder $q) => $q->where('clicks', '>', 0),
                'replies',
            ])
            ->orderByRaw('coalesce(sent_at, sending_started_at) desc')
            ->orderByDesc('id')
            ->limit(self::CAMPAIGNS_RECENT)
            ->get(['id', 'name', 'status', 'sent_at', 'sending_started_at']);
        $results = CampaignResult::forCampaigns($recent->map(static fn (Campaign $c): int => (int) $c->id)->all());

        $visibleIds = $visible()->pluck('id')->all();

        return [
            'drafts' => $visible()->where('status', Campaign::STATUS_DRAFT)->count(),
            'next' => $next !== null ? [
                'id' => $next->id,
                'name' => $next->name,
                'scheduled_at' => $next->scheduled_at?->toIso8601String(),
            ] : null,
            'sent_30d' => CampaignRecipient::query()
                ->whereIn('campaign_id', $visibleIds)
                ->where('status', CampaignRecipient::STATUS_SENT)
                ->where('sent_at', '>=', $since)
                ->count(),
            'replies_30d' => CampaignReply::query()
                ->whereIn('campaign_id', $visibleIds)
                ->where('received_at', '>=', $since)
                ->count(),
            'recent' => $recent->map(static fn (Campaign $c): array => [
                'id' => $c->id,
                'name' => $c->name,
                'status' => $c->status,
                'sent_at' => ($c->sent_at ?? $c->sending_started_at)?->toIso8601String(),
                'sent' => (int) $c->getAttribute('sent_count'),
                'clicked' => (int) $c->getAttribute('clicked_count'),
                'replies' => (int) $c->getAttribute('replies_count'),
                'drop_percent' => $results[(int) $c->id]['drop_percent'] ?? null,
            ])->values()->all(),
        ];
    }
}
