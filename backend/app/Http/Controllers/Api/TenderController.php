<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\ProductSubstitute;
use App\Models\Tender;
use App\Models\TenderItem;
use App\Models\TenderLotOffer;
use App\Services\NbpExchangeRateService;
use App\Services\Pricing\SourcePriceComparison;
use App\Services\Pricing\SupplierSpecialMask;
use App\Services\ProductMatchService;
use App\Services\TenderActivityLogger;
use App\Services\TenderCoverageService;
use App\Services\TenderPricingService;
use App\Services\Tenders\TenderPriceView;
use App\Services\Tenders\TenderResultService;
use App\Services\TenderWorkflowService;
use App\Support\NoticeNumber;
use App\Support\OfferPricing;
use App\Support\PolishTime;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;

class TenderController extends Controller
{
    /** „GG:MM” (też „G:MM” i „GG:MM:SS”) — godzina składania ofert w czasie polskim */
    private const DEADLINE_TIME_RULE = 'regex:/^([01]?\d|2[0-3]):[0-5]\d(:[0-5]\d)?$/';

    /** Statusy przetargu w toku (przed złożeniem oferty) — filtry „bez godziny” i „bez numeru ogłoszenia”. */
    private const IN_PROGRESS_STATUSES = ['draft', 'wycena', 'akceptacja_km', 'akceptacja_dyrektor', 'zatwierdzona'];

    private const TIME_WITHOUT_DATE = 'Godzinę składania można wpisać tylko razem z datą terminu.';

    public function __construct(
        private readonly TenderWorkflowService $workflow,
        private readonly TenderCoverageService $coverage,
        private readonly TenderActivityLogger $activities,
        private readonly ProductMatchService $matcher,
        private readonly TenderPricingService $pricing,
        private readonly NbpExchangeRateService $fx,
        private readonly SourcePriceComparison $comparison,
        private readonly TenderPriceView $priceView,
        private readonly TenderResultService $results,
    ) {}

    public function index(Request $request): JsonResponse
    {
        $user = $request->user();
        $userId = (int) $user->id;

        $query = Tender::query()
            ->with(['client:id,name', 'owner:id,name'])
            ->withCount('items');

        if (! $user->can('tenders.view_all')) {
            $query->accessibleBy($user);
        }

        if ($request->filled('status')) {
            $query->where('status', $request->string('status'));
        }

        $filter = (string) $request->input('filter', '');
        if ($filter === 'mine') {
            $query->where('owner_id', $userId);
        }
        if ($filter === 'invited') {
            $query->whereHas('invitations', static function ($invitations) use ($userId): void {
                $invitations->where('user_id', $userId);
            });
        }
        if ($filter === 'unassigned') {
            $query->whereNull('owner_id');
        }
        if ($filter === 'deadline_soon') {
            $query->whereNotNull('deadline')
                ->whereDate('deadline', '<=', now()->addDays(7))
                ->whereDate('deadline', '>=', now()->toDateString())
                ->whereNotIn('status', ['archiwum', 'exported', 'odrzucony']);
        }
        if ($filter === 'no_result') {
            // po terminie (dzień terminu minął w Polsce), bez wyniku; bez szkiców i odrzuconych (jak przypomnienie „wpisz wynik”)
            $query->whereNull('result_status')
                ->whereNotNull('deadline')
                ->whereDate('deadline', '<', PolishTime::today()->toDateString())
                ->whereNotIn('status', ['draft', 'odrzucony']);
        }
        if ($filter === 'no_deadline_time') {
            $query->whereNull('deadline_time')->whereIn('status', self::IN_PROGRESS_STATUSES);
        }
        if ($filter === 'no_notice') {
            $query->whereNull('notice_number')->whereIn('status', self::IN_PROGRESS_STATUSES);
        }
        if ($request->filled('owner_id')) {
            $query->where('owner_id', (int) $request->integer('owner_id'));
        }

        $mask = SupplierSpecialMask::forUser($user);

        return response()->json($query->orderByDesc('last_activity_at')->get()
            ->map(fn (Tender $tender): array => $this->priceView->summary($tender, $mask))
            ->values());
    }

    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'title' => ['required', 'string', 'max:255'],
            'client_id' => ['required', 'exists:clients,id'],
            'deadline' => ['nullable', 'date'],
            'deadline_time' => ['nullable', 'string', self::DEADLINE_TIME_RULE],
            'notice_number' => ['nullable', 'string', 'max:60'],
            'owner_id' => ['nullable', 'integer', 'exists:users,id'],
            'number' => ['nullable', 'string', 'max:50', 'unique:tenders,number'],
            'target_margin_percent' => ['sometimes', 'numeric', 'min:0', 'max:500'],
        ], self::deadlineMessages());

        $deadlineTime = self::filledText($data['deadline_time'] ?? null);
        if ($deadlineTime !== null && empty($data['deadline'])) {
            throw ValidationException::withMessages(['deadline_time' => [self::TIME_WITHOUT_DATE]]);
        }
        $noticeNumber = self::noticeNumber($data['notice_number'] ?? null);

        $number = $data['number'] ?? Tender::nextNumber();

        $tender = Tender::query()->create([
            'number' => $number,
            'title' => $data['title'],
            'client_id' => $data['client_id'],
            'owner_id' => $data['owner_id'] ?? $request->user()->id,
            'deadline' => $data['deadline'] ?? null,
            'deadline_time' => $deadlineTime,
            'notice_number' => $noticeNumber,
            'status' => 'draft',
            'ai_percent' => 0,
            'target_margin_percent' => $data['target_margin_percent'] ?? OfferPricing::markupPercent(),
            'last_activity_at' => now(),
        ]);

        $this->activities->log($tender, 'created', $request->user(), null, [
            'title' => $tender->title,
        ]);

        return response()->json(
            $tender->load(['client:id,name', 'owner:id,name'])->loadCount('items'),
            201
        );
    }

    public function update(Request $request, Tender $tender): JsonResponse
    {
        $data = $request->validate([
            'title' => ['sometimes', 'string', 'max:255'],
            'deadline' => ['sometimes', 'nullable', 'date'],
            'deadline_time' => ['sometimes', 'nullable', 'string', self::DEADLINE_TIME_RULE],
            'notice_number' => ['sometimes', 'nullable', 'string', 'max:60'],
            'owner_id' => ['sometimes', 'nullable', 'integer', 'exists:users,id'],
            'client_id' => ['sometimes', 'integer', 'exists:clients,id'],
            'target_margin_percent' => ['sometimes', 'numeric', 'min:0', 'max:500'],
        ], self::deadlineMessages());

        $noticeNumber = array_key_exists('notice_number', $data) ? self::noticeNumber($data['notice_number']) : null;
        $noticeChanged = array_key_exists('notice_number', $data) && $noticeNumber !== $tender->notice_number;
        // inne postępowanie (nie sama wersja numeru „…/01”) — dane z Biuletynu dotyczyły poprzedniego ogłoszenia
        $procedureChanged = $noticeChanged && ! self::sameProcedure($tender->notice_number, $noticeNumber);
        if ($procedureChanged && ! $request->user()->can('tenders.edit_offer') && $this->hasBulletinData($tender)) {
            // odpięcie kasuje dane wyniku z Biuletynu — a wynik może zmieniać tylko osoba z edycją oferty
            return response()->json([
                'message' => 'Zmiana numeru ogłoszenia usunie dane pobrane z Biuletynu Zamówień Publicznych. Może to zrobić osoba z uprawnieniem do edycji oferty.',
            ], 403);
        }

        $before = [
            'title' => $tender->title,
            'deadline' => $tender->deadline?->format('Y-m-d'),
            'deadline_time' => $tender->deadline_time,
            'notice_number' => $tender->notice_number,
            'owner_id' => $tender->owner_id,
            'client_id' => $tender->client_id,
            'target_margin_percent' => $tender->target_margin_percent,
        ];

        if (array_key_exists('title', $data)) {
            $tender->title = $data['title'];
        }
        if (array_key_exists('deadline', $data)) {
            $tender->deadline = $data['deadline'];
            // bez daty nie ma godziny — wyczyszczenie daty czyści godzinę
            if (empty($data['deadline'])) {
                $tender->deadline_time = null;
            }
        }
        if (array_key_exists('deadline_time', $data)) {
            $time = self::filledText($data['deadline_time']);
            if ($time !== null && $tender->deadline === null) {
                throw ValidationException::withMessages(['deadline_time' => [self::TIME_WITHOUT_DATE]]);
            }
            $tender->deadline_time = $time;
        }
        if ($noticeChanged) {
            $tender->notice_number = $noticeNumber;
            // nocne łączenie sprawdzi przetarg na nowo (np. inna wersja ogłoszenia o zamówieniu)
            $tender->bzp_checked_at = null;
            if ($procedureChanged) {
                // powiązania z ogłoszeniami Biuletynu dotyczyły poprzedniego postępowania — łączy się je od nowa.
                // Ta sama wersja postępowania zachowuje powiązania: części usunięte przez człowieka nie wracają
                $tender->contract_notice_id = null;
                $tender->result_notice_id = null;
            }
        }
        if (array_key_exists('owner_id', $data)) {
            $tender->owner_id = $data['owner_id'];
        }
        if (array_key_exists('client_id', $data)) {
            $tender->client_id = $data['client_id'];
        }

        $oldTarget = $tender->targetMarkupPercent();
        $reprice = false;
        if (array_key_exists('target_margin_percent', $data)) {
            if (! $this->workflow->canEditOffer($tender)) {
                throw ValidationException::withMessages([
                    'tender' => ['Narzutu nie można już zmieniać — przetarg ma status „'.TenderWorkflowService::statusLabel($tender->status).'”.'],
                ]);
            }
            $tender->target_margin_percent = $data['target_margin_percent'];
            $reprice = abs($oldTarget - (float) $data['target_margin_percent']) >= 0.0001;
        }

        $tender->last_activity_at = now();
        DB::transaction(function () use ($tender, $procedureChanged, $request, $before): void {
            $tender->save();
            if ($procedureChanged) {
                // dane części wpisane z poprzedniego ogłoszenia (zwycięzca, ceny, wynik) nie dotyczą nowego numeru
                $this->results->detachNotice($tender, $request->user(), $before['notice_number']);
            }
        });

        $mask = SupplierSpecialMask::forUser($request->user());
        if ($reprice) {
            // pozycje bez ceny oferty dostają ją z widoku cen osoby zmieniającej marżę
            $this->pricing->applyTargetMarginChange(
                $tender,
                $oldTarget,
                (float) $tender->target_margin_percent,
                $mask,
            );
        }

        $this->activities->log($tender, 'updated', $request->user(), null, [
            'before' => $before,
            'after' => [
                'title' => $tender->title,
                'deadline' => $tender->deadline?->format('Y-m-d'),
                'deadline_time' => $tender->deadline_time,
                'notice_number' => $tender->notice_number,
                'owner_id' => $tender->owner_id,
                'client_id' => $tender->client_id,
                'target_margin_percent' => $tender->target_margin_percent,
            ],
        ]);

        return response()->json($this->priceView->summary(
            $tender->fresh()->load(['client:id,name', 'owner:id,name'])->loadCount('items'),
            $mask,
        ));
    }

    /**
     * @return array<string, string>
     */
    private static function deadlineMessages(): array
    {
        return [
            'deadline_time.regex' => 'Godzina składania ofert musi mieć postać GG:MM, np. 10:00.',
            'deadline_time.string' => 'Godzina składania ofert musi mieć postać GG:MM, np. 10:00.',
            'notice_number.max' => 'Numer ogłoszenia jest za długi.',
            'notice_number.string' => 'Nieznany zapis numeru ogłoszenia.',
        ];
    }

    private static function filledText(mixed $value): ?string
    {
        if (! is_string($value)) {
            return null;
        }
        $value = trim($value);

        return $value === '' ? null : $value;
    }

    /**
     * Numer ogłoszenia w zapisie znormalizowanym (Biuletyn albo TED); pusty = null; inny zapis → 422.
     */
    private static function noticeNumber(mixed $value): ?string
    {
        $text = self::filledText($value);
        if ($text === null) {
            return null;
        }
        $parsed = NoticeNumber::parse($text);
        if ($parsed === null) {
            throw ValidationException::withMessages([
                'notice_number' => [
                    'Nieznany zapis numeru ogłoszenia. Przykłady: 2026/BZP 00431178/01 (Biuletyn Zamówień Publicznych) '
                    .'albo 606345-2026 (Dziennik Urzędowy Unii Europejskiej, TED).',
                ],
            ]);
        }

        return $parsed['normalized'];
    }

    /**
     * To samo postępowanie: ten sam numer Biuletynu bez wersji („2026/BZP 00431178” i „…/01”) albo ten sam numer
     * TED. Numer ogłoszenia o wyniku wpisany zamiast numeru ogłoszenia o zamówieniu to inny numer — inne postępowanie.
     */
    private static function sameProcedure(?string $before, ?string $after): bool
    {
        $a = NoticeNumber::parse($before);
        $b = NoticeNumber::parse($after);
        if ($a === null || $b === null || $a['source'] !== $b['source']) {
            return false;
        }
        $key = static fn (array $n): string => $n['source'] === NoticeNumber::SOURCE_BZP ? (string) $n['bzp_number'] : $n['normalized'];

        return $key($a) === $key($b);
    }

    /** Część powiązana z ogłoszeniem Biuletynu albo oferta innej firmy wpisana przez Biuletyn. */
    private function hasBulletinData(Tender $tender): bool
    {
        return $tender->lots()
            ->where(static function ($query): void {
                $query->whereNotNull('bzp_notice_id')
                    ->orWhereHas('offers', static fn ($offers) => $offers->where('source', '<>', TenderLotOffer::SOURCE_MANUAL));
            })
            ->exists();
    }

    public function destroy(Tender $tender): JsonResponse
    {
        foreach ($tender->documents()->get(['id', 'disk_path']) as $document) {
            $path = (string) ($document->disk_path ?? '');
            if ($path !== '' && Storage::disk('local')->exists($path)) {
                Storage::disk('local')->delete($path);
            }
        }
        $tender->delete();

        return response()->json(['ok' => true]);
    }

    public function show(Request $request, Tender $tender): JsonResponse
    {
        $tender->load([
            'client',
            'owner:id,name,role',
            'items.mainProduct.images',
            'items.mainProduct.activeVariants:'.TenderItemController::VARIANT_COLUMNS,
            'items.mainVariant:'.TenderItemController::VARIANT_COLUMNS,
            'items.companionProduct.images',
            'conditions.statusUser:id,name',
            'statusHistories.user:id,name,role',
        ]);

        // stare pozycje (sprzed feature) nie mają ai_match_reasons — dolicz i zapisz
        foreach ($tender->items as $item) {
            /** @var TenderItem $item */
            if ($item->main_product_id === null || $item->mainProduct === null) {
                continue;
            }
            if (is_array($item->ai_match_reasons) && $item->ai_match_reasons !== []) {
                continue;
            }
            $explained = $this->matcher->explainMatch($item->requirement, $item->mainProduct);
            $item->ai_match_reasons = $explained['reasons'];
            if ($item->match_source === null) {
                $item->match_source = 'heuristic';
            }
            $item->saveQuietly();
        }

        $tender->setRelation(
            'documents',
            $tender->documents()
                ->with('uploader:id,name')
                ->get([
                    'id',
                    'tender_id',
                    'uploaded_by',
                    'original_name',
                    'extension',
                    'size_bytes',
                    'mode',
                    'targets',
                    'disk_path',
                    'created_at',
                ])
                ->map(static function ($d) {
                    $d->setAttribute('has_file', $d->disk_path !== null);
                    unset($d->disk_path);

                    return $d;
                })
        );

        foreach ($tender->items as $item) {
            $product = $item->mainProduct;
            if ($product === null) {
                continue;
            }
            $withFx = $this->fx->appendPricePln($product->toArray());
            $product->setAttribute('purchase_price_pln', $withFx['purchase_price_pln'] ?? null);
            $product->setAttribute('price_pln', $withFx['price_pln'] ?? null);
            $this->pricing->appendVariantPricesPln($item);
        }
        // „taniej u …” przy cenie zakupu wybranej karty — informacja; cena oferty bez zmian (decyzja 2 planu łączenia
        // kart). Hurtem dla wszystkich kart przetargu, nie zapytania na pozycję.
        $mainProducts = $tender->items->pluck('mainProduct')->filter()->unique('id')->values();
        $mask = SupplierSpecialMask::forUser($request->user());
        $cheaper = $this->comparison->cheaperSources($mainProducts, $mask);
        // warunek zamawiania obowiązującego źródła (UVEX „po 10 szt.”) — też hurtem
        $orderQuantities = $this->comparison->orderQuantities($mainProducts, $mask);
        foreach ($tender->items as $item) {
            if ($item->mainProduct !== null) {
                $item->mainProduct->setAttribute('cheaper_source', $cheaper[(int) $item->mainProduct->id] ?? null);
                $item->mainProduct->setAttribute('order_quantity', $orderQuantities[(int) $item->mainProduct->id] ?? null);
            }
        }

        $mainIds = $tender->items
            ->pluck('main_product_id')
            ->filter()
            ->unique()
            ->values();

        $substitutes = ProductSubstitute::query()
            ->with([
                'mainProduct:id,sku,name',
                'substituteProduct:id,sku,name,catalog_price_net,stock',
                'approver:id,name',
            ])
            ->whereIn('main_product_id', $mainIds)
            // jak zamienniki pozycji (BattlecardService): ręczne nieodrzucone albo zatwierdzone — bez propozycji
            // automatu czekających na decyzję
            ->where('approval_status', '!=', 'odrzucony')
            ->where(static fn ($q) => $q->where('source', ProductSubstitute::SOURCE_MANUAL)->orWhere('approval_status', 'zatwierdzony'))
            ->get()
            ->groupBy('main_product_id');

        return response()->json([
            'tender' => $this->priceView->tender($tender, $mask),
            'substitutes_by_main' => $this->priceView->substitutesByMain($substitutes, $mask),
            'can_edit' => $this->workflow->canEditOffer($tender),
            'next_statuses' => $this->workflow->nextStatusesFor($tender, $request->user()),
            'coverage' => $this->coverage->summarize($tender, $mask),
        ]);
    }

    public function transition(Request $request, Tender $tender): JsonResponse
    {
        $data = $request->validate([
            'status' => ['required', 'string'],
            'note' => ['nullable', 'string', 'max:1000'],
        ]);

        $from = $tender->status;
        $tender = $this->workflow->transition(
            $tender,
            $data['status'],
            $request->user(),
            $data['note'] ?? null
        );

        $this->activities->log($tender, 'status_changed', $request->user(), null, [
            'from' => $from,
            'to' => $tender->status,
            'note' => $data['note'] ?? null,
        ]);

        $tender->load([
            'client',
            'owner:id,name,role',
            'items.mainProduct.images',
            'items.mainProduct.activeVariants:'.TenderItemController::VARIANT_COLUMNS,
            'items.mainVariant:'.TenderItemController::VARIANT_COLUMNS,
            'items.companionProduct.images',
            'statusHistories.user:id,name,role',
        ]);
        foreach ($tender->items as $item) {
            $this->pricing->appendVariantPricesPln($item);
        }
        $mask = SupplierSpecialMask::forUser($request->user());

        return response()->json([
            'tender' => $this->priceView->tender($tender, $mask),
            'can_edit' => $this->workflow->canEditOffer($tender),
            'next_statuses' => $this->workflow->nextStatusesFor($tender, $request->user()),
            'coverage' => $this->coverage->summarize($tender, $mask),
        ]);
    }
}
