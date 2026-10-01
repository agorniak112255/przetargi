<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Campaign;
use App\Models\CampaignClick;
use App\Models\CampaignItem;
use App\Models\CampaignRecipient;
use App\Models\CampaignReply;
use App\Models\CampaignTemplate;
use App\Models\Contact;
use App\Models\EmailSuppression;
use App\Models\ErpItemLink;
use App\Models\MailingList;
use App\Models\User;
use App\Models\UserMailAccount;
use App\Services\Campaigns\AudienceResolver;
use App\Services\Campaigns\CampaignBlocks;
use App\Services\Campaigns\CampaignItemPresenter;
use App\Services\Campaigns\CampaignRenderer;
use App\Services\Campaigns\CampaignReplySync;
use App\Services\Campaigns\CampaignResult;
use App\Services\Campaigns\CampaignSalesResult;
use App\Services\Campaigns\CampaignSender;
use App\Services\Campaigns\CampaignSuggestions;
use App\Services\Erp\ErpItemCards;
use App\Services\Erp\InventoryQuery;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
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

    private const STATUSES = [Campaign::STATUS_DRAFT, Campaign::STATUS_SCHEDULED, Campaign::STATUS_SENDING, Campaign::STATUS_SENT, Campaign::STATUS_CANCELLED];

    private const SCHEDULED = 'Kampania jest zaplanowana — cofnij planowanie, żeby ją zmienić';

    /** Statusy, w których kampanię po wysyłce można usunąć (campaigns.delete). */
    private const DELETABLE_SENT = [Campaign::STATUS_SENT, Campaign::STATUS_CANCELLED];

    /** Najdalej tyle dni naprzód można zaplanować wysyłkę. */
    private const SCHEDULE_MAX_DAYS = 60;

    /** Najwięcej odznaczonych adresów we wszystkich grupach kampanii razem. */
    private const MAX_LIST_EXCLUSIONS = 20000;

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
                'recipients as clicked_count' => fn (Builder $q) => $q->where('clicks', '>', 0),
                'replies',
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
                'blocks' => CampaignBlocks::standard(),
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
            // adresy odznaczone w oknie „Pokaż / wybierz” grupy; klucz zastępuje całość
            'audience.list_exclusions' => ['sometimes', 'array', 'max:100'],
            'audience.list_exclusions.*' => ['array'],
            'audience.list_exclusions.*.list_id' => ['required', 'integer'],
            'audience.list_exclusions.*.contact_ids' => ['present', 'array', 'max:'.self::MAX_LIST_EXCLUSIONS],
            'audience.list_exclusions.*.contact_ids.*' => ['integer'],
            'audience.xl' => ['sometimes', 'array'],
            'audience.xl.mode' => ['sometimes', 'nullable', 'string', Rule::in(Campaign::XL_MODES)],
            'audience.xl.months' => ['sometimes', 'integer', Rule::in(Campaign::XL_MONTHS)],
            'audience.xl.only_mine' => ['sometimes', 'boolean'],
            // wybór klientów z okna „Pokaż / wybierz”; null = cała kategoria
            'audience.xl.customer_ids' => ['sometimes', 'nullable', 'array', 'max:20000'],
            'audience.xl.customer_ids.*' => ['integer'],
            'blocks' => ['sometimes', 'array'],
            'brand_color' => ['sometimes', 'nullable', 'string', Rule::in(CampaignBlocks::BRAND_COLORS)],
        ], $this->newlineMessages());
        $excluded = 0;
        foreach ((array) ($v['audience']['list_exclusions'] ?? []) as $entry) {
            $excluded += count((array) ($entry['contact_ids'] ?? []));
        }
        if ($excluded > self::MAX_LIST_EXCLUSIONS) {
            throw ValidationException::withMessages(['audience.list_exclusions' => ['Za dużo odznaczonych adresów.']]);
        }

        $data = [];
        // bloki zastępują heading/intro/layout — stary front bez bloków zmienia je jak dotąd
        $fields = $request->has('blocks')
            ? ['name', 'preheader', 'valid_until', 'brand_color']
            : ['name', 'preheader', 'heading', 'intro', 'layout', 'valid_until', 'brand_color'];
        foreach ($fields as $key) {
            if (array_key_exists($key, $v)) {
                $data[$key] = is_string($v[$key]) ? trim($v[$key]) : $v[$key];
            }
        }
        if ($request->has('blocks')) {
            $data['blocks'] = CampaignBlocks::validate($request->input('blocks'), false);
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

    /**
     * Projekt usuwa autor (albo campaigns.manage). Wysłaną lub anulowaną — tylko z campaigns.delete, razem z odbiorcami,
     * kliknięciami i odpowiedziami (klucze kaskadowe); wypisy z mailingu zostają (email_suppressions.campaign_id → null).
     * W wysyłce i zaplanowanej — najpierw anulowanie albo zdjęcie z planu.
     */
    public function destroy(Request $request, Campaign $campaign): JsonResponse
    {
        $this->authorizeView($request, $campaign);
        if ($campaign->isDraft()) {
            $this->lockedDraft($campaign, static fn (Campaign $locked) => $locked->delete());

            return response()->json(['message' => 'Usunięto kampanię.']);
        }
        $this->ensureDeletableSent($campaign);
        if (! $request->user()->can('campaigns.delete')) {
            abort(403, 'Usuwanie wysłanych kampanii wymaga uprawnienia „Kampanie — usuwanie wysłanych”.');
        }
        DB::transaction(function () use ($campaign): void {
            $locked = Campaign::query()->whereKey($campaign->id)->lockForUpdate()->firstOrFail();
            // status mógł się zmienić między sprawdzeniem a blokadą (np. start zaplanowanej)
            $this->ensureDeletableSent($locked);
            $locked->delete();
        });

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
            // odznaczenia tylko w grupach, które zostały w kopii
            $settings['list_exclusions'] = Campaign::normalizeListExclusions($settings['list_exclusions'], $settings['list_ids']);
            $validUntil = $campaign->valid_until;

            $copy = Campaign::query()->create([
                'user_id' => $user->id,
                'name' => Str::limit($campaign->name.' (kopia)', 200, ''),
                'subject' => $campaign->subject,
                'preheader' => $campaign->preheader,
                'heading' => $campaign->heading,
                'intro' => $campaign->intro,
                'layout' => $campaign->layout,
                'blocks' => $campaign->effectiveBlocks(),
                'brand_color' => $campaign->brand_color,
                'template_id' => $campaign->template_id,
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
            // krótki opis w mailu (układy „z opisem”); pusty = wycinek opisu karty
            'description' => ['sometimes', 'nullable', 'string', 'max:300'],
            'product_id' => ['sometimes', 'nullable', 'integer', Rule::exists('products', 'id')],
            'position' => ['sometimes', 'integer', 'min:1', 'max:1000'],
        ]);

        $this->lockedDraft($campaign, function (Campaign $campaign) use ($item, $v): void {
            $data = array_intersect_key($v, array_flip(['promo_price_net', 'price_before_net', 'note', 'description', 'product_id']));
            foreach (['note', 'description'] as $field) {
                if (array_key_exists($field, $data) && is_string($data[$field])) {
                    $data[$field] = trim($data[$field]) !== '' ? trim($data[$field]) : null;
                }
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

    /**
     * Okno „Pokaż / wybierz” grupy odbiorców: kontakty grupy (podstawa wysyłki, wypisani) i nazwy innych wybranych grup,
     * w których kontakt też jest bez odznaczenia (wtedy dostanie maila mimo odznaczenia tutaj). `ids` = cała grupa
     * (do „zaznacz wszystkich”), `excluded_ids` = zapisane odznaczenia tej grupy. Grupa autora kampanii albo wspólna.
     */
    public function listContacts(Request $request, Campaign $campaign): JsonResponse
    {
        $this->authorizeView($request, $campaign);
        $v = $request->validate([
            'list_id' => ['required', 'integer'],
            'search' => ['nullable', 'string', 'max:150'],
            'page' => ['nullable', 'integer', 'min:1'],
            'per_page' => ['nullable', 'integer', 'min:10', 'max:200'],
        ]);
        $author = $campaign->user;
        if ($author === null) {
            abort(404);
        }
        $list = MailingList::query()->whereKey((int) $v['list_id'])
            ->where(static fn (Builder $q) => $q->where('user_id', $author->id)->orWhere('is_shared', true))
            ->first();
        if ($list === null) {
            abort(404);
        }

        $settings = $campaign->audienceSettings();
        /** @var array<int, array<int, int>> $excludedBy grupa → odznaczone kontakty (klucze) */
        $excludedBy = [];
        foreach ($settings['list_exclusions'] as $entry) {
            $excludedBy[$entry['list_id']] = array_flip($entry['contact_ids']);
        }
        $ids = DB::table('mailing_list_contact')->where('mailing_list_id', $list->id)->orderBy('contact_id')
            ->pluck('contact_id')->map(static fn ($id): int => (int) $id)->all();
        $own = $excludedBy[$list->id] ?? [];
        $excludedIds = array_values(array_filter($ids, static fn (int $id): bool => isset($own[$id])));

        $query = $list->contacts()->orderBy('contacts.email')->orderBy('contacts.id');
        $search = trim((string) ($v['search'] ?? ''));
        if ($search !== '') {
            $like = '%'.addcslashes(mb_strtolower($search), '%_\\').'%';
            $query->where(static fn (Builder $q) => $q
                ->whereRaw('lower(contacts.email) like ?', [$like])
                ->orWhereRaw('lower(contacts.name) like ?', [$like])
                ->orWhereRaw('lower(contacts.company) like ?', [$like]));
        }
        $page = $query->paginate((int) ($v['per_page'] ?? 50));
        $contacts = $page->getCollection();

        $emails = $contacts->map(static fn (Contact $c): string => mb_strtolower((string) $c->email))->unique()->values()->all();
        $suppressed = $emails === [] ? [] : array_flip(EmailSuppression::query()->whereIn('email', $emails)->pluck('email')
            ->map(static fn ($e): string => mb_strtolower((string) $e))->all());

        // inne wybrane grupy (tylko widoczne dla autora — jak przy wysyłce), w których kontakt jest bez odznaczenia
        $others = array_values(array_filter($this->visibleListIds($author, $settings['list_ids']), static fn (int $id): bool => $id !== (int) $list->id));
        $alsoIn = [];
        $pageIds = $contacts->map(static fn (Contact $c): int => (int) $c->id)->all();
        if ($others !== [] && $pageIds !== []) {
            $names = MailingList::query()->whereIn('id', $others)->pluck('name', 'id')->all();
            $member = [];
            foreach (DB::table('mailing_list_contact')->whereIn('mailing_list_id', $others)->whereIn('contact_id', $pageIds)
                ->get(['mailing_list_id', 'contact_id']) as $row) {
                $listId = (int) $row->mailing_list_id;
                $contactId = (int) $row->contact_id;
                if (! isset($excludedBy[$listId][$contactId])) {
                    $member[$contactId][$listId] = true;
                }
            }
            foreach ($member as $contactId => $lists) {
                // kolejność grup jak w wyborze kampanii
                foreach ($others as $listId) {
                    if (isset($lists[$listId], $names[$listId])) {
                        $alsoIn[$contactId][] = (string) $names[$listId];
                    }
                }
            }
        }

        return response()->json([
            'list' => ['id' => (int) $list->id, 'name' => (string) $list->name],
            'data' => $contacts->map(static fn (Contact $c): array => [
                'id' => (int) $c->id,
                'email' => (string) $c->email,
                'name' => $c->name,
                'company' => $c->company,
                'basis' => $c->pivot->basis,
                'basis_note' => $c->pivot->basis_note,
                'added_at' => $c->pivot->created_at !== null ? Carbon::parse($c->pivot->created_at)->toIso8601String() : null,
                'skipped' => isset($suppressed[mb_strtolower((string) $c->email)]) ? 'suppressed' : null,
                'also_in' => $alsoIn[(int) $c->id] ?? [],
            ])->values()->all(),
            'meta' => [
                'current_page' => $page->currentPage(),
                'last_page' => $page->lastPage(),
                'per_page' => $page->perPage(),
                'total' => $page->total(),
            ],
            'ids' => $ids,
            'excluded_ids' => $excludedIds,
        ]);
    }

    /**
     * Lista odbiorców przed wysyłką: dokładnie ci, do których pójdzie mail (view=send), albo pominięci z powodem
     * (view=skipped). `checksum` z adresów do wysyłki — start z tą sumą odmówi, gdy lista zmieni się w międzyczasie.
     * from_lists / from_xl to adresy przed odrzuceniem duplikatów i pominiętych (jak total_raw w podglądzie).
     */
    public function audienceRecipients(Request $request, Campaign $campaign): JsonResponse
    {
        $this->authorizeView($request, $campaign);
        $v = $request->validate([
            'view' => ['nullable', 'string', Rule::in(['send', 'skipped'])],
            'search' => ['nullable', 'string', 'max:150'],
            'page' => ['nullable', 'integer', 'min:1'],
            'per_page' => ['nullable', 'integer', 'min:10', 'max:200'],
        ]);
        $r = $this->audience->recipientList($campaign);
        $skipped = ($v['view'] ?? 'send') === 'skipped';
        $rows = $skipped ? $r['skipped'] : $r['final'];

        $search = mb_strtolower(trim((string) ($v['search'] ?? '')));
        if ($search !== '') {
            $rows = array_values(array_filter($rows, static fn (array $row): bool => str_contains($row['email'], $search)
                || str_contains(mb_strtolower((string) $row['name']), $search)
                || str_contains(mb_strtolower($row['origin']), $search)));
        }
        $perPage = (int) ($v['per_page'] ?? 50);
        $total = count($rows);
        $current = (int) ($v['page'] ?? 1);

        return response()->json([
            'summary' => [
                'total' => count($r['final']),
                'from_lists' => $r['list_rows'],
                'from_xl' => $r['xl_emails'],
                'duplicates' => $r['duplicates'],
                'skipped' => [
                    'invalid' => $r['invalid'],
                    'generic' => $r['excluded_generic'],
                    'suppressed' => $r['suppressed'],
                    'capped' => $r['capped'],
                ],
            ],
            'checksum' => AudienceResolver::checksum(array_column($r['final'], 'email')),
            'warnings' => $r['warnings'],
            'data' => array_map(static fn (array $row): array => [
                'email' => $row['email'],
                'name' => $row['name'],
                'source' => $row['source'],
                'origin' => $row['origin'],
                'reason' => $skipped ? $row['reason'] : null,
            ], array_slice($rows, ($current - 1) * $perPage, $perPage)),
            'meta' => [
                'current_page' => $current,
                'last_page' => max(1, (int) ceil($total / $perPage)),
                'per_page' => $perPage,
                'total' => $total,
            ],
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

    /**
     * Podgląd z niezapisanymi blokami i kolorem (edytor treści) i prawdziwymi pozycjami — nic nie zapisuje. Bloki
     * sprawdzane jak przy zapisie projektu (bez wymogu kompletności).
     */
    public function previewDraft(Request $request, Campaign $campaign): JsonResponse
    {
        $this->authorizeView($request, $campaign);
        $v = $request->validate([
            'blocks' => ['present', 'array'],
            'brand_color' => ['sometimes', 'nullable', 'string', Rule::in(CampaignBlocks::BRAND_COLORS)],
        ]);
        $blocks = CampaignBlocks::validate($request->input('blocks'), false);
        $campaign->loadMissing('user.mailAccount');
        $author = $campaign->user;
        $rendered = $this->renderer->renderBlocks($campaign, $blocks, $v['brand_color'] ?? null, null, $author);
        $account = $author?->mailAccount;

        return response()->json([
            'subject' => $rendered['subject'],
            'preheader' => $campaign->preheader,
            'from' => $account === null ? null : ['name' => $account->from_name, 'address' => $account->from_address],
            'html' => $rendered['html'],
        ]);
    }

    /**
     * Zastosowanie szablonu w projekcie: kopia bloków i koloru (template_id = null → „Standard SUPON” i kolor
     * domyślny). Szablon musi być widoczny dla pytającego (własny albo wspólny), inaczej 404.
     */
    public function applyTemplate(Request $request, Campaign $campaign): JsonResponse
    {
        $this->authorizeView($request, $campaign);
        $this->ensureDraft($campaign);
        $v = $request->validate([
            'template_id' => ['present', 'nullable', 'integer'],
        ]);
        /** @var User $user */
        $user = $request->user();
        $template = null;
        if ($v['template_id'] !== null) {
            $template = CampaignTemplate::query()
                ->whereKey((int) $v['template_id'])
                ->where(fn (Builder $q) => $q->where('user_id', $user->id)->orWhere('is_shared', true))
                ->first() ?? abort(404);
        }

        $this->lockedDraft($campaign, static fn (Campaign $locked) => $locked->update([
            'blocks' => is_array($template?->blocks) ? CampaignBlocks::upgrade($template->blocks) : CampaignBlocks::standard(),
            'brand_color' => $template?->brand_color,
            'template_id' => $template?->id,
        ]));

        return response()->json($this->present($campaign->fresh(), $user));
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
        // suma kontrolna listy z okna potwierdzenia — inna lista w chwili startu = 422, nic nie wychodzi
        $v = $request->validate(['recipients_checksum' => ['nullable', 'string', 'size:40']]);
        $this->ensureDraft($campaign);

        $started = $this->sender->start($campaign, $user, $v['recipients_checksum'] ?? null);

        return response()->json($this->present($started->fresh() ?? $started, $user));
    }

    /** Zaplanowanie wysyłki na godzinę (ISO 8601 z przesunięciem strefy; zapis w UTC). Tylko autor. */
    public function schedule(Request $request, Campaign $campaign): JsonResponse
    {
        $this->authorizeView($request, $campaign);
        /** @var User $user */
        $user = $request->user();
        if ((int) $campaign->user_id !== (int) $user->id) {
            abort(403, 'Zaplanować kampanię może tylko jej autor — wyjdzie z jego skrzynki.');
        }
        $v = $request->validate([
            'scheduled_at' => ['required', 'date', 'after:+1 minute', 'before:+'.self::SCHEDULE_MAX_DAYS.' days'],
        ], [
            'scheduled_at.after' => 'Wybierz godzinę w przyszłości.',
            'scheduled_at.before' => 'Wysyłkę można zaplanować najdalej '.self::SCHEDULE_MAX_DAYS.' dni naprzód.',
        ]);
        $this->ensureDraft($campaign);

        $scheduled = $this->sender->schedule($campaign, $user, Carbon::parse((string) $v['scheduled_at'])->utc());

        return response()->json($this->present($scheduled, $user));
    }

    /** Cofnięcie planowania — kampania wraca do projektu. Autor albo campaigns.manage. */
    public function unschedule(Request $request, Campaign $campaign): JsonResponse
    {
        $this->authorizeView($request, $campaign);
        if ($campaign->status !== Campaign::STATUS_SCHEDULED) {
            abort(422, 'Kampania nie jest zaplanowana — mogła już wystartować.');
        }

        $draft = $this->sender->unschedule($campaign);

        return response()->json($this->present($draft, $request->user()));
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

    /**
     * „Zaproponuj pozycje”: ranking zalegającego towaru do projektu kampanii (CampaignSuggestions) z powodami.
     * mine = tylko towar, który kupowali klienci autora (opiekun w XL).
     */
    public function suggestions(Request $request, Campaign $campaign, CampaignSuggestions $suggestions): JsonResponse
    {
        $this->authorizeView($request, $campaign);
        $this->ensureDraft($campaign);
        $v = $request->validate(['mine' => ['nullable', 'boolean']]);
        $result = $suggestions->suggest($campaign, (bool) ($v['mine'] ?? false), 30);

        return response()->json([
            'data' => $result['rows'],
            'free' => max(0, (int) config('campaigns.max_items') - $campaign->items()->count()),
            'mine_available' => $result['mine_available'],
            'min_months' => CampaignSuggestions::MIN_MONTHS,
        ]);
    }

    /**
     * „Sprawdź skrzynkę teraz”: odczyt odpowiedzi ze skrzynki autora kampanii od razu (zwykle co 10 minut w tle).
     * Tylko wysłana kampania i włączony odczyt w „Moja poczta” autora.
     */
    public function checkReplies(Request $request, Campaign $campaign, CampaignReplySync $replies): JsonResponse
    {
        $this->authorizeView($request, $campaign);
        if ($campaign->sending_started_at === null) {
            abort(422, 'Odpowiedzi sprawdzamy dopiero po wysyłce kampanii.');
        }
        $account = UserMailAccount::query()->where('user_id', $campaign->user_id)->first();
        if ($account === null || ! $account->imap_enabled) {
            abort(422, 'Liczenie odpowiedzi jest wyłączone w „Moja poczta” autora kampanii.');
        }

        $result = $replies->checkNow($account);
        $message = match (true) {
            $result['busy'] => 'Skrzynka jest właśnie sprawdzana — odśwież za chwilę.',
            $result['error'] !== null => 'Nie udało się sprawdzić skrzynki: '.$result['error'],
            $result['replies'] === 0 => 'Sprawdzono — brak nowych odpowiedzi.',
            default => 'Sprawdzono — nowe odpowiedzi: '.$result['replies'].'.',
        };

        return response()->json([
            'ok' => ! $result['busy'] && $result['error'] === null,
            'new' => $result['replies'],
            'message' => $message,
            'replies' => $this->replySummary($campaign),
        ]);
    }

    public function recipients(Request $request, Campaign $campaign): JsonResponse
    {
        $this->authorizeView($request, $campaign);
        $v = $request->validate([
            'status' => ['nullable', 'string', Rule::in(['', ...self::RECIPIENT_STATUSES])],
            // tylko ci, którzy kliknęli — od ostatniego kliknięcia („do kogo zadzwonić”)
            'clicked' => ['nullable', 'boolean'],
            'page' => ['nullable', 'integer', 'min:1'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:200'],
        ]);

        $query = $campaign->recipients();
        if (($v['status'] ?? '') !== '') {
            $query->where('status', $v['status']);
        }
        if ((bool) ($v['clicked'] ?? false)) {
            $query->where('clicks', '>', 0)->orderByDesc('clicks')->orderBy('first_clicked_at');
        }
        $query->orderBy('id');
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
                'first_clicked_at' => $r->first_clicked_at?->toIso8601String(),
                'clicks' => (int) $r->clicks,
                'replied_at' => $r->replied_at?->toIso8601String(),
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
     * Odbiorcy po zmianie: brakujące klucze z dotychczasowych ustawień. Grupy tylko autora albo wspólne — istniejąca
     * cudza prywatna grupa to błąd, nieistniejąca (np. usunięta w międzyczasie) po cichu wypada. list_exclusions
     * zastępuje całość; wpisy grup spoza list_ids znikają.
     *
     * @param  array<string, mixed>  $input
     * @return array{list_ids: list<int>, list_exclusions: list<array{list_id: int, contact_ids: list<int>}>,
     *     xl: array{mode: string|null, months: int, only_mine: bool, customer_ids: list<int>|null}}
     */
    private function mergedAudience(Campaign $campaign, array $input): array
    {
        $current = $campaign->audienceSettings();
        if (array_key_exists('list_ids', $input)) {
            $ids = array_values(array_unique(array_map('intval', (array) $input['list_ids'])));
            $author = $campaign->user()->firstOrFail();
            $existing = $ids === [] ? [] : MailingList::query()->whereIn('id', $ids)->pluck('id')->map(static fn ($id): int => (int) $id)->all();
            $ids = array_values(array_filter($ids, static fn (int $id): bool => in_array($id, $existing, true)));
            $visible = $this->visibleListIds($author, $ids);
            if (count($visible) !== count($ids)) {
                throw ValidationException::withMessages([
                    'audience.list_ids' => ['Wybrana grupa odbiorców nie istnieje albo nie jest dostępna dla autora kampanii.'],
                ]);
            }
            $current['list_ids'] = $ids;
        }
        $current['list_exclusions'] = Campaign::normalizeListExclusions(
            array_key_exists('list_exclusions', $input) ? $input['list_exclusions'] : $current['list_exclusions'],
            $current['list_ids'],
        );
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

    private function ensureDeletableSent(Campaign $campaign): void
    {
        if ($campaign->isDraft() || in_array($campaign->status, self::DELETABLE_SENT, true)) {
            return;
        }
        abort(422, $campaign->status === Campaign::STATUS_SCHEDULED
            ? 'Kampania jest zaplanowana — najpierw zdejmij ją z planu.'
            : 'Kampania jest w trakcie wysyłki — najpierw anuluj wysyłkę.');
    }

    private function ensureDraft(Campaign $campaign): void
    {
        if (! $campaign->isDraft()) {
            abort(422, $campaign->status === Campaign::STATUS_SCHEDULED ? self::SCHEDULED : self::NOT_DRAFT);
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
            // odbiorcy, którzy kliknęli link w mailu (bez skanerów poczty)
            'clicked' => (int) $c->getAttribute('clicked_count'),
            // odpowiedzi klientów odczytane ze skrzynki handlowca (IMAP)
            'replies' => (int) $c->getAttribute('replies_count'),
            'created_at' => $c->created_at?->toIso8601String(),
            'scheduled_at' => $c->scheduled_at?->toIso8601String(),
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
        $campaign->loadMissing(['user.mailAccount', 'items', 'template:id,name']);
        $author = $campaign->user;
        $items = $campaign->items;

        return [
            'id' => $campaign->id,
            'code' => $campaign->code,
            'name' => $campaign->name,
            'subject' => $campaign->subject,
            'preheader' => $campaign->preheader,
            // heading, intro i layout dla zgodności ze starym frontem — treść maila opisują blocks
            'heading' => $campaign->heading,
            'intro' => $campaign->intro,
            'layout' => $campaign->layout,
            'template_id' => $campaign->template_id !== null ? (int) $campaign->template_id : null,
            'template_name' => $campaign->template?->name,
            'blocks' => $campaign->effectiveBlocks(),
            'brand_color' => $campaign->brand_color,
            'valid_until' => $campaign->valid_until?->toDateString(),
            'status' => $campaign->status,
            'audience' => $campaign->audienceSettings(),
            'author' => ['id' => (int) $campaign->user_id, 'name' => (string) $author?->name],
            'can_edit' => $campaign->isDraft(),
            // projekt usuwa autor; wysłaną albo anulowaną — tylko z uprawnieniem campaigns.delete
            'can_delete' => $campaign->isDraft() || (in_array($campaign->status, self::DELETABLE_SENT, true) && $viewer->can('campaigns.delete')),
            'created_at' => $campaign->created_at?->toIso8601String(),
            'updated_at' => $campaign->updated_at?->toIso8601String(),
            'scheduled_at' => $campaign->scheduled_at?->toIso8601String(),
            // zaplanowana nie wystartowała o swojej godzinie (powód; kampania wróciła do projektu)
            'schedule_error' => $campaign->schedule_error,
            'sending_started_at' => $campaign->sending_started_at?->toIso8601String(),
            'sent_at' => $campaign->sent_at?->toIso8601String(),
            'totals' => $campaign->totals,
            'items' => $author !== null ? $this->presenter->presentMany($items, $author) : [],
            'warnings' => $this->warnings($campaign, $viewer),
            'sales' => $campaign->sending_started_at === null ? null : $this->sales->forCampaign($campaign),
            'clicks' => $campaign->sending_started_at === null ? null : $this->clickSummary($campaign),
            'replies' => $campaign->sending_started_at === null ? null : $this->replySummary($campaign),
        ];
    }

    /**
     * Kliknięcia w linki maila: odbiorcy, którzy kliknęli, i kliknięcia ludzi per pozycja (offer = „Zapytaj o ofertę”,
     * product = strona produktu). Skanery poczty tylko w liczniku bots.
     *
     * @return array{recipients: int, total: int, bots: int, items: list<array{campaign_item_id: int, offer: int, product: int}>}
     */
    private function clickSummary(Campaign $campaign): array
    {
        $rows = CampaignClick::query()->where('campaign_id', $campaign->id)
            ->groupBy('campaign_item_id', 'kind', 'suspected_bot')
            ->selectRaw('campaign_item_id, kind, suspected_bot, count(*) as c')
            ->get();
        $items = [];
        $total = $bots = 0;
        foreach ($rows as $row) {
            $count = (int) $row->getAttribute('c');
            if ((bool) $row->suspected_bot) {
                $bots += $count;

                continue;
            }
            $total += $count;
            if ($row->campaign_item_id === null) {
                continue;
            }
            $id = (int) $row->campaign_item_id;
            $items[$id] ??= ['campaign_item_id' => $id, 'offer' => 0, 'product' => 0];
            $items[$id][$row->kind === CampaignClick::KIND_OFFER ? 'offer' : 'product'] += $count;
        }

        return [
            'recipients' => CampaignRecipient::query()->where('campaign_id', $campaign->id)->where('clicks', '>', 0)->count(),
            'total' => $total,
            'bots' => $bots,
            'items' => array_values($items),
        ];
    }

    /**
     * Odpowiedzi klientów (ze skrzynki autora, IMAP): liczba, ilu odbiorców odpowiedziało, ostatnie 100 i stan odczytu.
     *
     * @return array<string, mixed>
     */
    private function replySummary(Campaign $campaign): array
    {
        $account = UserMailAccount::query()->where('user_id', $campaign->user_id)->first(['imap_enabled', 'imap_checked_at', 'imap_error']);

        return [
            'total' => $campaign->replies()->count(),
            'recipients' => $campaign->replies()->whereNotNull('campaign_recipient_id')->distinct()->count('campaign_recipient_id'),
            'enabled' => $account !== null && (bool) $account->imap_enabled,
            'checked_at' => $account?->imap_checked_at?->toIso8601String(),
            'error' => $account?->imap_error,
            'list' => $campaign->replies()->with('recipient:id,email')->orderByDesc('received_at')->limit(100)->get()
                ->map(static fn (CampaignReply $r): array => [
                    'id' => (int) $r->id,
                    'from_email' => $r->from_email,
                    'from_name' => $r->from_name,
                    'subject' => $r->subject,
                    'item_code' => $r->item_code,
                    'matched_by' => $r->matched_by,
                    'received_at' => $r->received_at?->toIso8601String(),
                    'recipient_email' => $r->recipient?->email,
                ])->values()->all(),
        ];
    }

    /** @return list<string> */
    private function warnings(Campaign $campaign, User $viewer): array
    {
        if (! $campaign->isDraft()) {
            return [];
        }
        $out = [];
        if (trim((string) $campaign->schedule_error) !== '') {
            $out[] = 'Zaplanowana wysyłka nie wystartowała: '.$campaign->schedule_error;
        }
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
