<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Campaign;
use App\Models\CampaignItem;
use App\Models\CampaignRecipient;
use App\Models\EmailSuppression;
use App\Models\ErpItemLink;
use App\Models\MailingList;
use App\Models\User;
use App\Models\UserMailAccount;
use App\Services\Campaigns\AudienceResolver;
use App\Services\Campaigns\CampaignItemPresenter;
use App\Services\Campaigns\CampaignRenderer;
use App\Services\Campaigns\CampaignResult;
use App\Services\Campaigns\CampaignSalesResult;
use App\Services\Campaigns\CampaignSender;
use App\Services\Erp\ErpItemCards;
use App\Services\Erp\InventoryQuery;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Symfony\Component\Mailer\Exception\TransportExceptionInterface;

/**
 * Kampanie reklamowe: projekt (pozycje, treść, odbiorcy), podgląd, test i start wysyłki ze skrzynki autora.
 * Tylko prezentacja i walidacja — wyliczenia pozycji, odbiorcy i wysyłka są w usługach App\Services\Campaigns.
 * Dostęp: autor albo campaigns.manage (inaczej 404); zmiany tylko w projekcie; wysyła wyłącznie autor.
 */
class CampaignController extends Controller
{
    private const NOT_DRAFT = 'Kampania została już wysłana — zduplikuj ją, żeby zmienić';

    private const NO_NEWLINE = '/[\r\n]/';

    /** Sortowanie w oknie „Pokaż / wybierz” klientów XL: klucz z adresu → kolumna erp_customers. */
    private const XL_CUSTOMER_SORTS = [
        'documents' => 'sale_documents_24m',
        'last_sale' => 'last_sale_at',
        'acronym' => 'acronym',
        'name' => 'name',
        'city' => 'city',
    ];

    private const STATUSES = [Campaign::STATUS_DRAFT, Campaign::STATUS_SENDING, Campaign::STATUS_SENT, Campaign::STATUS_CANCELLED];

    private const RECIPIENT_STATUSES = [
        CampaignRecipient::STATUS_PENDING,
        CampaignRecipient::STATUS_SENDING,
        CampaignRecipient::STATUS_SENT,
        CampaignRecipient::STATUS_FAILED,
        CampaignRecipient::STATUS_SKIPPED,
    ];

    public function __construct(
        private readonly CampaignItemPresenter $presenter,
        private readonly CampaignRenderer $renderer,
        private readonly CampaignSender $sender,
        private readonly AudienceResolver $audience,
        private readonly CampaignSalesResult $sales,
    ) {}

    public function index(Request $request): JsonResponse
    {
        $v = $request->validate([
            'scope' => ['nullable', 'string', Rule::in(['mine', 'all'])],
            'status' => ['nullable', 'string', Rule::in(['', ...self::STATUSES])],
            'page' => ['nullable', 'integer', 'min:1'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
        ]);
        /** @var User $user */
        $user = $request->user();
        $scope = $v['scope'] ?? 'mine';
        if ($scope === 'all' && ! $user->can('campaigns.manage')) {
            abort(403, 'Brak uprawnienia do oglądania kampanii innych użytkowników.');
        }

        $query = Campaign::query()
            ->with('user:id,name')
            ->withCount([
                'items',
                'recipients as recipients_total',
                'recipients as sent_count' => fn (Builder $q) => $q->where('status', CampaignRecipient::STATUS_SENT),
                'recipients as failed_count' => fn (Builder $q) => $q->where('status', CampaignRecipient::STATUS_FAILED),
            ])
            ->selectSub(
                'select sum('.InventoryQuery::valueSql('trade').') from campaign_items'
                .' join erp_items on erp_items.id = campaign_items.erp_item_id'
                .' where campaign_items.campaign_id = campaigns.id',
                'stock_value',
            );
        if ($scope === 'mine') {
            $query->where('campaigns.user_id', $user->id);
        }
        if (($v['status'] ?? '') !== '') {
            $query->where('campaigns.status', $v['status']);
        }

        $page = $query->orderByDesc('campaigns.created_at')->orderByDesc('campaigns.id')
            ->paginate((int) ($v['per_page'] ?? 25));
        $ids = $page->getCollection()->map(fn (Campaign $c): int => (int) $c->id)->values()->all();
        $results = CampaignResult::forCampaigns($ids);
        $sales = $this->sales->summaries($ids);

        return response()->json([
            'data' => $page->getCollection()->map(fn (Campaign $c): array => $this->listRow($c, $results[(int) $c->id] ?? null, $sales[(int) $c->id] ?? null))->values()->all(),
            'meta' => [
                'current_page' => $page->currentPage(),
                'last_page' => $page->lastPage(),
                'per_page' => $page->perPage(),
                'total' => $page->total(),
            ],
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        $v = $request->validate([
            'name' => ['nullable', 'string', 'max:200'],
            ...$this->itemIdRules(),
        ]);
        /** @var User $user */
        $user = $request->user();

        $campaign = DB::transaction(function () use ($v, $user): Campaign {
            $name = trim((string) ($v['name'] ?? ''));
            $campaign = Campaign::query()->create([
                'user_id' => $user->id,
                'name' => $name !== '' ? $name : 'Kampania '.now()->format('d.m.Y'),
                'subject' => '',
                'layout' => 'grid3',
                'status' => Campaign::STATUS_DRAFT,
            ]);
            $this->appendItems($campaign, $v['erp_item_ids'] ?? [], $v['product_ids'] ?? []);

            return $campaign;
        });

        return response()->json($this->present($campaign->fresh(), $user), 201);
    }

    public function show(Request $request, Campaign $campaign): JsonResponse
    {
        $this->authorizeView($request, $campaign);

        return response()->json($this->present($campaign, $request->user()));
    }

    public function update(Request $request, Campaign $campaign): JsonResponse
    {
        $this->authorizeView($request, $campaign);
        $this->ensureDraft($campaign);

        $v = $request->validate([
            'name' => ['sometimes', 'required', 'string', 'max:200'],
            'subject' => ['sometimes', 'nullable', 'string', 'max:200', 'not_regex:'.self::NO_NEWLINE],
            'preheader' => ['sometimes', 'nullable', 'string', 'max:200', 'not_regex:'.self::NO_NEWLINE],
            'heading' => ['sometimes', 'nullable', 'string', 'max:200'],
            'intro' => ['sometimes', 'nullable', 'string', 'max:5000'],
            'layout' => ['sometimes', 'required', 'string', Rule::in(Campaign::LAYOUTS)],
            'valid_until' => ['sometimes', 'nullable', 'date_format:Y-m-d'],
            'audience' => ['sometimes', 'array'],
            'audience.list_ids' => ['sometimes', 'array', 'max:100'],
            'audience.list_ids.*' => ['integer', 'distinct'],
            'audience.xl' => ['sometimes', 'array'],
            'audience.xl.mode' => ['sometimes', 'nullable', 'string', Rule::in(Campaign::XL_MODES)],
            'audience.xl.months' => ['sometimes', 'integer', Rule::in(Campaign::XL_MONTHS)],
            'audience.xl.only_mine' => ['sometimes', 'boolean'],
            // wybór klientów z okna „Pokaż / wybierz”; null = cała kategoria
            'audience.xl.customer_ids' => ['sometimes', 'nullable', 'array', 'max:20000'],
            'audience.xl.customer_ids.*' => ['integer'],
        ], $this->newlineMessages());

        $data = [];
        foreach (['name', 'preheader', 'heading', 'intro', 'layout', 'valid_until'] as $key) {
            if (array_key_exists($key, $v)) {
                $data[$key] = is_string($v[$key]) ? trim($v[$key]) : $v[$key];
            }
        }
        if (array_key_exists('subject', $v)) {
            // kolumna NOT NULL z domyślnym '' — brak tematu = pusty (start wysyłki go wymaga)
            $data['subject'] = trim((string) ($v['subject'] ?? ''));
        }

        $this->lockedDraft($campaign, function (Campaign $locked) use ($data, $v): void {
            if (array_key_exists('audience', $v)) {
                $data['audience'] = $this->mergedAudience($locked, $v['audience']);
            }
            if ($data !== []) {
                $locked->update($data);
            }
        });

        return response()->json($this->present($campaign->fresh(), $request->user()));
    }

    public function destroy(Request $request, Campaign $campaign): JsonResponse
    {
        $this->authorizeView($request, $campaign);
        $this->ensureDraft($campaign);
        $this->lockedDraft($campaign, static fn (Campaign $locked) => $locked->delete());

        return response()->json(['message' => 'Usunięto kampanię.']);
    }

    /** Kopia treści i pozycji (bez snapshotów, wyników, dat i odbiorców); autorem kopii jest duplikujący. */
    public function duplicate(Request $request, Campaign $campaign): JsonResponse
    {
        $this->authorizeView($request, $campaign);
        /** @var User $user */
        $user = $request->user();

        $copy = DB::transaction(function () use ($campaign, $user): Campaign {
            $settings = $campaign->audienceSettings();
            $settings['list_ids'] = $this->visibleListIds($user, $settings['list_ids']);
            $validUntil = $campaign->valid_until;

            $copy = Campaign::query()->create([
                'user_id' => $user->id,
                'name' => Str::limit($campaign->name.' (kopia)', 200, ''),
                'subject' => $campaign->subject,
                'preheader' => $campaign->preheader,
                'heading' => $campaign->heading,
                'intro' => $campaign->intro,
                'layout' => $campaign->layout,
                // termin ważności cen z przeszłości nie ma sensu w nowej kampanii
                'valid_until' => $validUntil !== null && ! $validUntil->lt(today()) ? $validUntil->toDateString() : null,
                'status' => Campaign::STATUS_DRAFT,
                'audience' => $settings,
                'duplicated_from_id' => $campaign->id,
            ]);
            foreach ($campaign->items()->get() as $item) {
                $copy->items()->create([
                    'position' => $item->position,
                    'erp_item_id' => $item->erp_item_id,
                    'product_id' => $item->product_id,
                    'promo_price_net' => $item->promo_price_net,
                    'price_before_net' => $item->price_before_net,
                    'note' => $item->note,
                ]);
            }

            return $copy;
        });

        return response()->json($this->present($copy->fresh(), $user), 201);
    }

    public function addItems(Request $request, Campaign $campaign): JsonResponse
    {
        $this->authorizeView($request, $campaign);
        $this->ensureDraft($campaign);
        $v = $request->validate($this->itemIdRules());

        $this->lockedDraft($campaign, fn (Campaign $locked) => $this->appendItems($locked, $v['erp_item_ids'] ?? [], $v['product_ids'] ?? []));

        return response()->json($this->present($campaign->fresh(), $request->user()));
    }

    public function updateItem(Request $request, Campaign $campaign, CampaignItem $item): JsonResponse
    {
        $this->authorizeView($request, $campaign);
        $this->ensureItemOf($campaign, $item);
        $this->ensureDraft($campaign);

        $v = $request->validate([
            'promo_price_net' => ['sometimes', 'nullable', 'numeric', 'min:0', 'max:9999999999.99'],
            'price_before_net' => ['sometimes', 'nullable', 'numeric', 'min:0', 'max:9999999999.99'],
            'note' => ['sometimes', 'nullable', 'string', 'max:300'],
            'product_id' => ['sometimes', 'nullable', 'integer', Rule::exists('products', 'id')],
            'position' => ['sometimes', 'integer', 'min:1', 'max:1000'],
        ]);

        $this->lockedDraft($campaign, function (Campaign $campaign) use ($item, $v): void {
            $data = array_intersect_key($v, array_flip(['promo_price_net', 'price_before_net', 'note', 'product_id']));
            if (array_key_exists('note', $data) && is_string($data['note'])) {
                $data['note'] = trim($data['note']) !== '' ? trim($data['note']) : null;
            }
            if ($data !== []) {
                $item->update($data);
            }
            if (array_key_exists('position', $v)) {
                $this->moveItem($campaign, $item, (int) $v['position']);
            }
        });

        return response()->json($this->present($campaign->fresh(), $request->user()));
    }

    public function removeItem(Request $request, Campaign $campaign, CampaignItem $item): JsonResponse
    {
        $this->authorizeView($request, $campaign);
        $this->ensureItemOf($campaign, $item);
        $this->ensureDraft($campaign);

        $this->lockedDraft($campaign, function (Campaign $campaign) use ($item): void {
            $item->delete();
            $this->renumber($campaign);
        });

        return response()->json($this->present($campaign->fresh(), $request->user()));
    }

    public function audience(Request $request, Campaign $campaign): JsonResponse
    {
        $this->authorizeView($request, $campaign);

        return response()->json($this->audience->preview($campaign));
    }

    /**
     * Okno „Pokaż / wybierz”: klienci XL z danej kategorii (kupowali te towary / z tej grupy / moi) z adresami,
     * szukanie, sortowanie i stronicowanie; `ids` = cała kategoria (do „zaznacz wszystkie”), `selected_ids` = zapisany
     * wybór, gdy dotyczy tej samej kategorii (null = cała kategoria albo inna kategoria).
     */
    public function xlCustomers(Request $request, Campaign $campaign): JsonResponse
    {
        $this->authorizeView($request, $campaign);
        $v = $request->validate([
            'mode' => ['required', 'string', Rule::in(Campaign::XL_MODES)],
            'months' => ['nullable', 'integer', Rule::in(Campaign::XL_MONTHS)],
            'only_mine' => ['nullable', 'boolean'],
            'search' => ['nullable', 'string', 'max:100'],
            'sort' => ['nullable', 'string', Rule::in(array_keys(self::XL_CUSTOMER_SORTS))],
            'dir' => ['nullable', 'string', Rule::in(['asc', 'desc'])],
            'page' => ['nullable', 'integer', 'min:1'],
            'per_page' => ['nullable', 'integer', 'min:10', 'max:200'],
        ]);
        $xl = [
            'mode' => (string) $v['mode'],
            'months' => (int) ($v['months'] ?? 24),
            'only_mine' => $v['mode'] !== 'mine' && (bool) ($v['only_mine'] ?? false),
        ];
        $warnings = [];
        $query = $this->audience->xlCategoryQuery($campaign, $xl, $warnings);
        $saved = $campaign->audienceSettings()['xl'];
        $sameCategory = [$saved['mode'], $saved['months'], $saved['only_mine']] === [$xl['mode'], $xl['months'], $xl['only_mine']];
        $empty = ['data' => [], 'meta' => ['current_page' => 1, 'last_page' => 1, 'per_page' => 50, 'total' => 0], 'ids' => [], 'selected_ids' => null, 'warnings' => $warnings];
        if ($query === null) {
            return response()->json($empty);
        }

        $ids = (clone $query)->orderBy('id')->pluck('id')->map(static fn ($id): int => (int) $id)->all();
        $search = trim((string) ($v['search'] ?? ''));
        if ($search !== '') {
            $like = '%'.addcslashes(mb_strtolower($search), '%_\\').'%';
            $query->where(static fn (Builder $q) => $q
                ->whereRaw('lower(acronym) like ?', [$like])
                ->orWhereRaw('lower(name) like ?', [$like])
                ->orWhereRaw('lower(city) like ?', [$like])
                ->orWhere('nip', 'like', $like)
                ->orWhereRaw('lower(emails) like ?', [$like]));
        }
        if ($xl['mode'] === 'items') {
            // ile pozycji kampanii ten klient kupował w oknie czasu
            $itemIds = $campaign->items()->whereNotNull('erp_item_id')->pluck('erp_item_id')->all();
            $cutoff = now()->startOfDay()->subMonthsNoOverflow($xl['months'])->toDateString();
            $query->withCount(['items as matched_items' => static fn (Builder $q) => $q
                ->whereIn('erp_item_id', $itemIds)->where('last_sale_at', '>=', $cutoff)]);
        }
        $sort = self::XL_CUSTOMER_SORTS[$v['sort'] ?? 'documents'];
        $dir = ($v['dir'] ?? ($sort === 'acronym' || $sort === 'name' || $sort === 'city' ? 'asc' : 'desc')) === 'asc' ? 'asc' : 'desc';
        $page = $query->orderBy($sort, $dir)->orderBy('id')->paginate((int) ($v['per_page'] ?? 50));

        $emails = $page->getCollection()->flatMap(static fn ($c): array => is_array($c->emails) ? $c->emails : [])->unique()->values()->all();
        $suppressed = $emails === [] ? [] : array_flip(EmailSuppression::query()->whereIn('email', $emails)->pluck('email')->all());
        $prefixes = array_map(static fn ($p): string => mb_strtolower((string) $p), (array) config('campaigns.excluded_local_prefixes', []));

        return response()->json([
            'data' => $page->getCollection()->map(static fn ($c): array => [
                'id' => (int) $c->id,
                'acronym' => (string) $c->acronym,
                'name' => $c->name,
                'city' => $c->city,
                'nip' => $c->nip,
                'emails' => array_map(static function (string $email) use ($suppressed, $prefixes): array {
                    $local = (string) strstr($email, '@', true);
                    $generic = false;
                    foreach ($prefixes as $prefix) {
                        $generic = $generic || ($prefix !== '' && str_starts_with($local, $prefix));
                    }

                    return ['email' => $email, 'skipped' => isset($suppressed[$email]) ? 'suppressed' : ($generic ? 'generic' : null)];
                }, is_array($c->emails) ? $c->emails : []),
                'last_sale_at' => $c->last_sale_at?->toDateString(),
                'documents_24m' => (int) $c->sale_documents_24m,
                'main_operator' => $c->main_operator,
                'matched_items' => $c->getAttribute('matched_items') !== null ? (int) $c->getAttribute('matched_items') : null,
            ])->values()->all(),
            'meta' => [
                'current_page' => $page->currentPage(),
                'last_page' => $page->lastPage(),
                'per_page' => $page->perPage(),
                'total' => $page->total(),
            ],
            'ids' => $ids,
            'selected_ids' => $sameCategory && $saved['customer_ids'] !== null
                ? array_values(array_intersect($saved['customer_ids'], $ids))
                : null,
            'warnings' => $warnings,
        ]);
    }

    public function preview(Request $request, Campaign $campaign): JsonResponse
    {
        $this->authorizeView($request, $campaign);
        $campaign->loadMissing('user.mailAccount');
        $author = $campaign->user;
        $rendered = $this->renderer->render($campaign, null, $author);
        $account = $author?->mailAccount;

        return response()->json([
            'subject' => $rendered['subject'],
            'preheader' => $campaign->preheader,
            'from' => $account === null ? null : ['name' => $account->from_name, 'address' => $account->from_address],
            'html' => $rendered['html'],
        ]);
    }

    /** Mail testowy — domyślnie na adres nadawcy skrzynki pytającego, a bez skrzynki na e-mail jego konta. */
    public function test(Request $request, Campaign $campaign): JsonResponse
    {
        $this->authorizeView($request, $campaign);
        $v = $request->validate([
            'email' => ['nullable', 'string', 'email', 'max:255'],
        ]);
        /** @var User $user */
        $user = $request->user();
        $to = trim((string) ($v['email'] ?? ''));
        if ($to === '') {
            $from = UserMailAccount::query()->where('user_id', $user->id)->value('from_address');
            $to = (string) ($from ?: $user->email);
        }

        try {
            $this->sender->sendTest($campaign, $to);
        } catch (TransportExceptionInterface $e) {
            throw ValidationException::withMessages([
                'email' => ['Nie udało się wysłać testu: '.Str::limit($e->getMessage(), 300)],
            ]);
        }

        return response()->json(['message' => 'Wysłano wiadomość testową na '.$to.'.']);
    }

    public function send(Request $request, Campaign $campaign): JsonResponse
    {
        $this->authorizeView($request, $campaign);
        /** @var User $user */
        $user = $request->user();
        if ((int) $campaign->user_id !== (int) $user->id) {
            abort(403, 'Wysłać kampanię może tylko jej autor — wychodzi z jego skrzynki.');
        }
        $this->ensureDraft($campaign);

        $started = $this->sender->start($campaign, $user);

        return response()->json($this->present($started->fresh() ?? $started, $user));
    }

    public function cancel(Request $request, Campaign $campaign): JsonResponse
    {
        $this->authorizeView($request, $campaign);
        if ($campaign->status !== Campaign::STATUS_SENDING) {
            abort(422, 'Anulować można tylko kampanię w trakcie wysyłki.');
        }

        $cancelled = $this->sender->cancel($campaign);

        return response()->json($this->present($cancelled->fresh() ?? $cancelled, $request->user()));
    }

    public function recipients(Request $request, Campaign $campaign): JsonResponse
    {
        $this->authorizeView($request, $campaign);
        $v = $request->validate([
            'status' => ['nullable', 'string', Rule::in(['', ...self::RECIPIENT_STATUSES])],
            'page' => ['nullable', 'integer', 'min:1'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:200'],
        ]);

        $query = $campaign->recipients()->orderBy('id');
        if (($v['status'] ?? '') !== '') {
            $query->where('status', $v['status']);
        }
        $page = $query->paginate((int) ($v['per_page'] ?? 50));

        return response()->json([
            'data' => $page->getCollection()->map(static fn (CampaignRecipient $r): array => [
                'id' => $r->id,
                'email' => $r->email,
                'name' => $r->name,
                'source' => $r->source,
                'status' => $r->status,
                'error' => $r->error,
                'sent_at' => $r->sent_at?->toIso8601String(),
                'unsubscribed_at' => $r->unsubscribed_at?->toIso8601String(),
            ])->values()->all(),
            'meta' => [
                'current_page' => $page->currentPage(),
                'last_page' => $page->lastPage(),
                'per_page' => $page->perPage(),
                'total' => $page->total(),
            ],
        ]);
    }

    /** @return array<string, list<mixed>> */
    private function itemIdRules(): array
    {
        $max = (int) config('campaigns.max_items');

        return [
            'erp_item_ids' => ['nullable', 'array', 'max:'.$max],
            'erp_item_ids.*' => ['integer', 'distinct', Rule::exists('erp_items', 'id')->whereNull('removed_at')],
            'product_ids' => ['nullable', 'array', 'max:'.$max],
            'product_ids.*' => ['integer', 'distinct', Rule::exists('products', 'id')],
        ];
    }

    /**
     * Dopisuje pozycje na koniec, pomijając już obecne (ten sam towar XL albo ta sama karta). Karta bez towaru XL
     * dostaje towar z pierwszego pewnego powiązania, jeśli jest.
     *
     * @param  list<int>  $erpItemIds
     * @param  list<int>  $productIds
     */
    private function appendItems(Campaign $campaign, array $erpItemIds, array $productIds): void
    {
        $existing = $campaign->items()->get(['id', 'position', 'erp_item_id', 'product_id']);
        $erpIds = $existing->pluck('erp_item_id')->filter()->map(fn ($id): int => (int) $id)->all();
        $cardIds = $existing->pluck('product_id')->filter()->map(fn ($id): int => (int) $id)->all();

        $new = [];
        foreach ($erpItemIds as $id) {
            $id = (int) $id;
            if (! in_array($id, $erpIds, true)) {
                $erpIds[] = $id;
                $new[] = ['erp_item_id' => $id, 'product_id' => null];
            }
        }

        $links = $this->linkedErpItems(array_map('intval', $productIds));
        foreach ($productIds as $productId) {
            $productId = (int) $productId;
            $erpId = $links[$productId] ?? null;
            if (in_array($productId, $cardIds, true) || ($erpId !== null && in_array($erpId, $erpIds, true))) {
                continue;
            }
            $cardIds[] = $productId;
            if ($erpId !== null) {
                $erpIds[] = $erpId;
            }
            $new[] = ['erp_item_id' => $erpId, 'product_id' => $productId];
        }

        $max = (int) config('campaigns.max_items');
        if ($existing->count() + count($new) > $max) {
            throw ValidationException::withMessages([
                'erp_item_ids' => ["Kampania może mieć najwyżej {$max} pozycji (teraz: {$existing->count()})."],
            ]);
        }

        $position = (int) $existing->max('position');
        foreach ($new as $row) {
            $campaign->items()->create([...$row, 'position' => ++$position]);
        }
    }

    /**
     * Karta → towar XL z pierwszego pewnego powiązania (potwierdzone przed automatycznym, potem najstarsze).
     *
     * @param  list<int>  $productIds
     * @return array<int, int>
     */
    private function linkedErpItems(array $productIds): array
    {
        if ($productIds === []) {
            return [];
        }
        $out = [];
        $links = ErpItemCards::linked(ErpItemLink::query())
            ->whereIn('product_id', $productIds)
            ->whereHas('item', fn (Builder $q) => $q->whereNull('removed_at'))
            ->orderByRaw('case when status = ? then 0 else 1 end', [ErpItemLink::STATUS_CONFIRMED])
            ->orderBy('id')
            ->get(['id', 'product_id', 'erp_item_id', 'status']);
        foreach ($links as $link) {
            $out[(int) $link->product_id] ??= (int) $link->erp_item_id;
        }

        return $out;
    }

    /** Przesuwa pozycję na miejsce N (od 1) i numeruje resztę po kolei. */
    private function moveItem(Campaign $campaign, CampaignItem $item, int $position): void
    {
        $ids = $campaign->items()->pluck('id')->map(fn ($id): int => (int) $id)->reject(fn (int $id): bool => $id === (int) $item->id)->values()->all();
        $index = max(0, min(count($ids), $position - 1));
        array_splice($ids, $index, 0, [(int) $item->id]);
        $this->writePositions($ids);
    }

    private function renumber(Campaign $campaign): void
    {
        $this->writePositions($campaign->items()->pluck('id')->map(fn ($id): int => (int) $id)->all());
    }

    /** @param list<int> $ids */
    private function writePositions(array $ids): void
    {
        foreach ($ids as $i => $id) {
            CampaignItem::query()->whereKey($id)->where('position', '!=', $i + 1)->update(['position' => $i + 1]);
        }
    }

    /**
     * Odbiorcy po zmianie: brakujące klucze z dotychczasowych ustawień. Grupy tylko autora albo wspólne.
     *
     * @param  array<string, mixed>  $input
     * @return array{list_ids: list<int>, xl: array{mode: string|null, months: int, only_mine: bool}}
     */
    private function mergedAudience(Campaign $campaign, array $input): array
    {
        $current = $campaign->audienceSettings();
        if (array_key_exists('list_ids', $input)) {
            $ids = array_values(array_unique(array_map('intval', (array) $input['list_ids'])));
            $author = $campaign->user()->firstOrFail();
            $visible = $this->visibleListIds($author, $ids);
            if (count($visible) !== count($ids)) {
                throw ValidationException::withMessages([
                    'audience.list_ids' => ['Wybrana grupa odbiorców nie istnieje albo nie jest dostępna dla autora kampanii.'],
                ]);
            }
            $current['list_ids'] = $ids;
        }
        $xl = is_array($input['xl'] ?? null) ? $input['xl'] : [];
        $category = [$current['xl']['mode'], $current['xl']['months'], $current['xl']['only_mine']];
        if (array_key_exists('mode', $xl)) {
            $current['xl']['mode'] = $xl['mode'];
        }
        if (array_key_exists('months', $xl)) {
            $current['xl']['months'] = (int) $xl['months'];
        }
        if (array_key_exists('only_mine', $xl)) {
            $current['xl']['only_mine'] = (bool) $xl['only_mine'];
        }
        if (array_key_exists('customer_ids', $xl)) {
            $current['xl']['customer_ids'] = is_array($xl['customer_ids'])
                ? array_values(array_unique(array_map('intval', $xl['customer_ids'])))
                : null;
        } elseif ($category !== [$current['xl']['mode'], $current['xl']['months'], $current['xl']['only_mine']]) {
            // zaznaczenie dotyczyło innej kategorii — po zmianie wraca „cała kategoria”
            $current['xl']['customer_ids'] = null;
        }
        if ($current['xl']['mode'] === null) {
            $current['xl']['customer_ids'] = null;
        }

        return $current;
    }

    /**
     * @param  list<int>  $ids
     * @return list<int> te z podanych grup, które należą do użytkownika albo są wspólne
     */
    private function visibleListIds(User $user, array $ids): array
    {
        if ($ids === []) {
            return [];
        }
        $visible = MailingList::query()
            ->whereIn('id', $ids)
            ->where(fn (Builder $q) => $q->where('user_id', $user->id)->orWhere('is_shared', true))
            ->pluck('id')
            ->map(fn ($id): int => (int) $id)
            ->all();

        return array_values(array_filter($ids, static fn (int $id): bool => in_array($id, $visible, true)));
    }

    private function authorizeView(Request $request, Campaign $campaign): void
    {
        /** @var User $user */
        $user = $request->user();
        if ((int) $campaign->user_id !== (int) $user->id && ! $user->can('campaigns.manage')) {
            abort(404);
        }
    }

    private function ensureDraft(Campaign $campaign): void
    {
        if (! $campaign->isDraft()) {
            abort(422, self::NOT_DRAFT);
        }
    }

    /**
     * Zmiana projektu pod blokadą kampanii z ponownym sprawdzeniem statusu — start() blokuje ten sam wiersz, więc
     * zmiana nie wejdzie do kampanii, która w międzyczasie zaczęła się wysyłać (snapshoty, odbiorcy).
     *
     * @param  callable(Campaign): mixed  $change
     */
    private function lockedDraft(Campaign $campaign, callable $change): void
    {
        DB::transaction(function () use ($campaign, $change): void {
            $locked = Campaign::query()->lockForUpdate()->findOrFail($campaign->id);
            $this->ensureDraft($locked);
            $change($locked);
        });
    }

    private function ensureItemOf(Campaign $campaign, CampaignItem $item): void
    {
        if ((int) $item->campaign_id !== (int) $campaign->id) {
            abort(404);
        }
    }

    /** @return array<string, string> */
    private function newlineMessages(): array
    {
        return [
            'subject.not_regex' => 'Temat musi być jedną linią.',
            'preheader.not_regex' => 'Zajawka musi być jedną linią.',
        ];
    }

    /**
     * @param  array<string, float|null>|null  $result  CampaignResult::forCampaigns
     * @param  array{customers: int, net_value: float, complete: bool}|null  $sales  CampaignSalesResult::summaries
     * @return array<string, mixed>
     */
    private function listRow(Campaign $c, ?array $result, ?array $sales = null): array
    {
        $stockValue = $c->getAttribute('stock_value');

        return [
            'id' => $c->id,
            'code' => $c->code,
            'name' => $c->name,
            'subject' => $c->subject,
            'status' => $c->status,
            'author' => ['id' => (int) $c->user_id, 'name' => (string) $c->user?->name],
            'items_count' => (int) $c->getAttribute('items_count'),
            'recipients_total' => (int) $c->getAttribute('recipients_total'),
            'sent' => (int) $c->getAttribute('sent_count'),
            'failed' => (int) $c->getAttribute('failed_count'),
            'created_at' => $c->created_at?->toIso8601String(),
            'sending_started_at' => $c->sending_started_at?->toIso8601String(),
            'sent_at' => $c->sent_at?->toIso8601String(),
            'stock_value' => $stockValue !== null ? round((float) $stockValue, 2) : null,
            'result' => $result,
            // kupili odbiorcy: ilu klientów z odbiorców kupiło pozycje kampanii i za ile netto (30 dni od wysyłki)
            'sales' => $sales,
        ];
    }

    /** @return array<string, mixed> pełna kampania (kontrakt „Campaign”) */
    private function present(Campaign $campaign, User $viewer): array
    {
        $campaign->loadMissing(['user.mailAccount', 'items']);
        $author = $campaign->user;
        $items = $campaign->items;

        return [
            'id' => $campaign->id,
            'code' => $campaign->code,
            'name' => $campaign->name,
            'subject' => $campaign->subject,
            'preheader' => $campaign->preheader,
            'heading' => $campaign->heading,
            'intro' => $campaign->intro,
            'layout' => $campaign->layout,
            'valid_until' => $campaign->valid_until?->toDateString(),
            'status' => $campaign->status,
            'audience' => $campaign->audienceSettings(),
            'author' => ['id' => (int) $campaign->user_id, 'name' => (string) $author?->name],
            'can_edit' => $campaign->isDraft(),
            'created_at' => $campaign->created_at?->toIso8601String(),
            'updated_at' => $campaign->updated_at?->toIso8601String(),
            'sending_started_at' => $campaign->sending_started_at?->toIso8601String(),
            'sent_at' => $campaign->sent_at?->toIso8601String(),
            'totals' => $campaign->totals,
            'items' => $author !== null ? $this->presenter->presentMany($items, $author) : [],
            'warnings' => $this->warnings($campaign, $viewer),
            'sales' => $campaign->isDraft() ? null : $this->sales->forCampaign($campaign),
        ];
    }

    /** @return list<string> */
    private function warnings(Campaign $campaign, User $viewer): array
    {
        if (! $campaign->isDraft()) {
            return [];
        }
        $out = [];
        if ($campaign->user?->mailAccount === null) {
            $out[] = (int) $campaign->user_id === (int) $viewer->id
                ? 'Nie ustawiono skrzynki w „Moja poczta” — bez niej kampanii nie da się wysłać.'
                : 'Autor kampanii nie ustawił skrzynki w „Moja poczta” — bez niej kampanii nie da się wysłać.';
        }
        if (trim((string) config('campaigns.public_url')) === '') {
            $out[] = 'Brak publicznego adresu aplikacji (CAMPAIGNS_PUBLIC_URL) — w mailu nie zadziała link wypisu ani zdjęcia.';
        }
        $orphans = $campaign->items->filter(fn (CampaignItem $i): bool => $i->erp_item_id === null && $i->product_id === null)->count();
        if ($orphans > 0) {
            $out[] = "Pozycje bez towaru i karty (usunięte z katalogu): {$orphans} — usuń je z kampanii.";
        }

        return $out;
    }
}
