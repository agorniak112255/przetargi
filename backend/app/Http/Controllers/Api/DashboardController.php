<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\B2bAccount;
use App\Models\B2bSyncRun;
use App\Models\Campaign;
use App\Models\CampaignRecipient;
use App\Models\CampaignReply;
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
use App\Services\Pricing\SupplierSpecialMask;
use App\Services\ProductCatalogHealthService;
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
    ) {}

    public function __invoke(Request $request): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();

        return response()->json([
            'tenders' => $user->canAny(['tenders.view_own', 'tenders.view_all']) ? $this->tenders($user) : null,
            'products' => $user->can('products.view') ? $this->products() : null,
            'stock' => $user->canAny(['inventory.view', 'inventory.report.view']) ? $this->stock() : null,
            'prices' => $user->canAny(['price_lists.view', 'b2b_accounts.view']) ? $this->prices($user) : null,
            'campaigns' => $user->canAny(['campaigns.use', 'campaigns.view']) ? $this->campaigns($user) : null,
        ]);
    }

    /** @return array<string, mixed> */
    private function tenders(User $user): array
    {
        $seeAll = $user->can('tenders.view_all');
        // bez prices.supplier_special.view marża bliźniacza (od ceny standardowej kart z ceną specjalną B2B)
        $marginColumn = SupplierSpecialMask::forUser($user)->hides() ? 'margin_percent_standard' : 'margin_percent';
        $scoped = static fn (): Builder => $seeAll ? Tender::query() : Tender::query()->accessibleBy($user);
        $active = static fn (): Builder => $scoped()->whereNotIn('status', self::TENDER_CLOSED);
        $today = now()->toDateString();

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
                ->whereDate('deadline', '<=', now()->addDays(7))
                ->whereDate('deadline', '>=', $today)
                ->count(),
            'stages' => $stages,
            // tylko pola wiersza terminu — bez cen i marż (maska cen specjalnych nie ma tu czego ukrywać)
            'upcoming' => $active()
                ->with('client:id,name')
                ->whereNotNull('deadline')
                ->whereDate('deadline', '>=', $today)
                ->orderBy('deadline')
                ->orderBy('id')
                ->limit(self::UPCOMING_LIMIT)
                ->get(['id', 'number', 'title', 'client_id', 'status', 'deadline'])
                ->map(static fn (Tender $t): array => [
                    'id' => $t->id,
                    'number' => $t->number,
                    'title' => $t->title,
                    'client' => $t->client?->name,
                    'status' => $t->status,
                    'deadline' => $t->deadline !== null ? Carbon::parse($t->deadline)->toDateString() : null,
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
