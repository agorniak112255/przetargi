<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Jobs\MergeB2bSizePricesJob;
use App\Models\B2bAccount;
use App\Models\B2bDescriptionSupplementAttempt;
use App\Models\B2bSyncRun;
use App\Models\CatalogSearchSite;
use App\Models\ManufacturerSite;
use App\Models\Product;
use App\Models\ProductSourcePrice;
use App\Models\ProductVariant;
use App\Services\B2b\B2bCodeLoginSite;
use App\Services\B2b\B2bConnectorRegistry;
use App\Services\B2b\B2bDescriptionSupplement;
use App\Services\B2b\B2bSizePriceMerger;
use App\Services\Pricing\ProductEffectivePrice;
use App\Support\EnrichmentSiteList;
use Illuminate\Contracts\Encryption\DecryptException;
use Illuminate\Http\Client\HttpClientException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use JsonException;
use RuntimeException;

class B2bAccountController extends Controller
{
    public function __construct(private readonly B2bConnectorRegistry $connectors) {}

    public function index(): JsonResponse
    {
        $accounts = B2bAccount::query()
            ->with(['creator:id,name', 'updater:id,name'])
            ->orderBy('id')
            ->get();

        // Liczniki prób i hosty z indeksu — po jednym zapytaniu na całą listę, nie na konto.
        $stats = $this->supplementStats($accounts->pluck('id')->map(static fn ($id): int => (int) $id)->all());
        $indexed = $this->indexedHosts(
            $accounts->flatMap(static fn (B2bAccount $account): array => $account->enrichmentHosts())->unique()->values()->all()
        );

        return response()->json(
            $accounts
                ->map(fn (B2bAccount $account): array => $this->view($account, $stats[(int) $account->id] ?? null, $indexed))
                ->values()
        );
    }

    public function connectors(): JsonResponse
    {
        return response()->json($this->connectors->options());
    }

    public function store(Request $request): JsonResponse
    {
        $data = $this->validated($request, creating: true);

        $account = B2bAccount::query()->create([
            ...$data,
            'created_by' => $request->user()->id,
            'updated_by' => $request->user()->id,
        ]);

        return response()->json($this->view($account->fresh()->load(['creator:id,name', 'updater:id,name'])), 201);
    }

    public function update(Request $request, B2bAccount $b2bAccount): JsonResponse
    {
        $data = $this->validated($request, creating: false);

        // Puste hasło w edycji = zostaw zapisane (formularz nie zna starego hasła).
        if (! isset($data['password']) || $data['password'] === '') {
            unset($data['password']);
        }

        $b2bAccount->fill([
            ...$data,
            'updated_by' => $request->user()->id,
        ]);

        // Sesja sklepu (i rozpoczęte logowanie kodem) należą do starego loginu, hasła albo witryny — po ich zmianie
        // przebieg nie może jej użyć, bo działałby na koncie, którego użytkownik już nie wskazuje.
        if ($b2bAccount->isDirty(['username', 'password', 'connector'])) {
            $b2bAccount->connector_session = null;
            $b2bAccount->connector_session_saved_at = null;
            Cache::forget($this->codeLoginCacheKey($b2bAccount));
        }

        $b2bAccount->save();

        return response()->json($this->view($b2bAccount->fresh()->load(['creator:id,name', 'updater:id,name'])));
    }

    /**
     * Ceny konta znikają z kart przed usunięciem konta: cena obowiązująca wraca do cennika z pliku albo innego konta
     * (klucz obcy slotu tylko zeruje b2b_account_id — slot „b2b:{id}” zostałby na karcie i nadal wygrywał).
     * Rozmiary konta (product_variants „size”, source „b2b:{id}”) razem ze slotem: wycofane (removed_at), nie
     * skasowane — klucz obcy też tylko zeruje b2b_account_id, a tabela rozmiarów pokazywałaby ceny usuniętego konta.
     */
    public function destroy(B2bAccount $b2bAccount, ProductEffectivePrice $effectivePrices): JsonResponse
    {
        $sourceKey = ProductSourcePrice::b2bKey((int) $b2bAccount->id);

        DB::transaction(function () use ($b2bAccount, $effectivePrices, $sourceKey): void {
            ProductVariant::query()
                ->sizes()
                ->where(static fn ($q) => $q->where('b2b_account_id', $b2bAccount->id)->orWhere('source', $sourceKey))
                ->whereNull('removed_at')
                ->update(['removed_at' => Carbon::now()]);

            $productIds = ProductSourcePrice::query()
                ->where('source_key', $sourceKey)
                ->orderBy('product_id')
                ->pluck('product_id')
                ->all();
            foreach (array_chunk($productIds, 500) as $chunk) {
                foreach (Product::query()->whereIn('id', $chunk)->get() as $product) {
                    $effectivePrices->deleteSlot($product, $sourceKey);
                }
            }

            $b2bAccount->delete();
        });

        return response()->json(['ok' => true]);
    }

    /**
     * POST, żeby każde odsłonięcie hasła trafiło do dziennika aktywności.
     */
    public function revealPassword(B2bAccount $b2bAccount): JsonResponse
    {
        try {
            $password = $b2bAccount->password;
        } catch (DecryptException) {
            return response()->json([
                'message' => 'Nie można odszyfrować hasła (zmieniony klucz aplikacji). Zapisz hasło ponownie.',
            ], 422);
        }

        return response()->json(['password' => $password ?? '']);
    }

    /**
     * „Sprawdź teraz” — przebieg rusza z harmonogramu (b2b:sync-due, co minutę) w ciągu minuty.
     */
    public function requestSync(B2bAccount $b2bAccount): JsonResponse
    {
        if (! $this->connectors->has((string) $b2bAccount->connector)) {
            return response()->json([
                'message' => 'Dla witryn tego konta nie ma jeszcze importera — automatyczne pobieranie cennika nie jest dostępne.',
            ], 422);
        }

        // Drugie zlecenie w trakcie przebiegu dałoby podwójne pobieranie tuż po zakończeniu pierwszego.
        if ($this->syncIsRunning($b2bAccount)) {
            return response()->json(['message' => 'Pobieranie już trwa.'], 409);
        }

        $b2bAccount->forceFill(['sync_requested_at' => now()])->save();

        return response()->json($this->view($b2bAccount->fresh()->load(['creator:id,name', 'updater:id,name'])));
    }

    /**
     * „Zaloguj kodem”, krok 1: łącznik loguje się e-mailem i hasłem aż do prośby o kod i zleca jego wysyłkę.
     * Stan logowania (ciasteczka, znaczniki transakcji — bez hasła) czeka zaszyfrowany w cache na kod z e-maila.
     */
    public function startLoginCode(B2bAccount $b2bAccount): JsonResponse
    {
        if ($this->syncIsRunning($b2bAccount)) {
            return response()->json(['message' => 'Pobieranie już trwa.'], 409);
        }

        try {
            $connector = $this->connectors->make($b2bAccount);
            if (! $connector instanceof B2bCodeLoginSite) {
                return response()->json(['message' => 'Łącznik tego konta nie loguje się kodem z e-maila.'], 422);
            }

            $started = $connector->startCodeLogin();
        } catch (RuntimeException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        } catch (HttpClientException $e) {
            return response()->json(['message' => 'Witryna nie odpowiada: '.$e->getMessage()], 422);
        }

        Cache::put(
            $this->codeLoginCacheKey($b2bAccount),
            Crypt::encryptString((string) json_encode($started['state'], JSON_THROW_ON_ERROR)),
            now()->addMinutes(self::CODE_LOGIN_TTL_MINUTES),
        );

        return response()->json(['message' => $started['message']]);
    }

    /**
     * „Zaloguj kodem”, krok 2: kod z e-maila kończy logowanie; sesja trafia na konto (szyfrowana), a pobieranie
     * rusza od razu, bo sesja sklepu żyje krótko. Zły kod zostawia stan w cache — użytkownik może go poprawić.
     */
    public function verifyLoginCode(Request $request, B2bAccount $b2bAccount): JsonResponse
    {
        $data = $request->validate([
            // 3M wysyła same cyfry, MSA cyfry z literami — format sprawdza łącznik (finishCodeLogin)
            'code' => ['required', 'string', 'regex:/^\s*[0-9A-Za-z]{4,10}\s*$/'],
        ]);

        if ($this->syncIsRunning($b2bAccount)) {
            return response()->json(['message' => 'Pobieranie już trwa.'], 409);
        }

        $state = $this->codeLoginState($b2bAccount);
        if ($state === null) {
            return response()->json([
                'message' => 'Kod wygasł albo nie wysłano go — kliknij „Wyślij kod” jeszcze raz.',
            ], 422);
        }

        try {
            $connector = $this->connectors->make($b2bAccount);
            if (! $connector instanceof B2bCodeLoginSite) {
                return response()->json(['message' => 'Łącznik tego konta nie loguje się kodem z e-maila.'], 422);
            }

            $session = $connector->finishCodeLogin($state, trim((string) $data['code']));
        } catch (RuntimeException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        } catch (HttpClientException $e) {
            return response()->json(['message' => 'Witryna nie odpowiada: '.$e->getMessage()], 422);
        }

        $b2bAccount->forceFill([
            'connector_session' => $session,
            'connector_session_saved_at' => now(),
            'sync_requested_at' => now(),
        ])->save();
        Cache::forget($this->codeLoginCacheKey($b2bAccount));

        return response()->json($this->view($b2bAccount->fresh()->load(['creator:id,name', 'updater:id,name'])));
    }

    /**
     * Okno postępu: najnowszy przebieg z dziennikiem, ostatnie przebiegi i czy cron harmonogramu żyje.
     */
    public function syncProgress(B2bAccount $b2bAccount): JsonResponse
    {
        $lastSeen = Cache::get(B2bSyncRun::SCHEDULER_HEARTBEAT_KEY);
        $lastSeenAt = is_string($lastSeen) && $lastSeen !== '' ? Carbon::parse($lastSeen) : null;

        // bez size_spread (do 2000 wyrobów, czyta je tylko polecenie scalania) — okno odpytuje co 2,5 s
        $latest = $b2bAccount->syncRuns()
            ->select(array_merge(['id', 'b2b_account_id', 'log', 'price_changes'], self::RUN_COLUMNS))
            ->orderByDesc('id')
            ->first();
        $recent = $b2bAccount->syncRuns()
            ->select(array_merge(['id', 'b2b_account_id'], self::RUN_COLUMNS))
            ->orderByDesc('id')
            ->limit(10)
            ->get();

        return response()->json([
            'scheduler' => [
                'last_seen_at' => $lastSeenAt?->toIso8601String(),
                'healthy' => $lastSeenAt !== null && $lastSeenAt->greaterThanOrEqualTo(now()->subMinutes(3)),
            ],
            'sync_requested_at' => $b2bAccount->sync_requested_at?->toIso8601String(),
            'run' => $latest !== null ? [
                ...$this->runView($latest),
                'log' => array_values($latest->log ?? []),
                'price_changes' => array_values($latest->price_changes ?? []),
            ] : null,
            'recent_runs' => $recent->map(fn (B2bSyncRun $run): array => $this->runView($run))->values(),
        ]);
    }

    /**
     * Zatrzymanie sprawdzane przez przebieg między produktami (co kilka sekund).
     */
    public function cancelSync(B2bAccount $b2bAccount): JsonResponse
    {
        $run = $b2bAccount->syncRuns()
            ->where('status', B2bSyncRun::STATUS_RUNNING)
            ->orderByDesc('id')
            ->first();
        if ($run === null) {
            return response()->json(['message' => 'Nie trwa żadne pobieranie.'], 422);
        }

        if ($run->cancel_requested_at === null) {
            $run->forceFill(['cancel_requested_at' => now()])->save();
        }

        return response()->json(['ok' => true]);
    }

    /**
     * „Scal rozmiary”: lista kart rozbitych według ceny z ostatniego przebiegu i stan zadania w tle (podgląd albo
     * scalanie). Zadanie „w kolejce/trwa” bez znaku życia dłużej niż STALE_MINUTES — pokazane jako błąd.
     */
    public function sizeMerge(B2bAccount $b2bAccount, B2bSizePriceMerger $merger): JsonResponse
    {
        return response()->json($this->sizeMergeView($b2bAccount, $merger));
    }

    /**
     * Start podglądu albo scalania w tle. Odmowa: łącznik bez cen rozmiarów, brak listy, trwająca synchronizacja
     * albo trwające scalanie tego konta.
     */
    public function startSizeMerge(Request $request, B2bAccount $b2bAccount, B2bSizePriceMerger $merger): JsonResponse
    {
        $data = $request->validate([
            'mode' => ['required', Rule::in(['preview', 'apply'])],
            'limit' => ['nullable', 'integer', 'min:1', 'max:100000'],
            'with_tenders' => ['sometimes', 'boolean'],
        ]);
        if (! $this->connectors->sendsSizePrices($b2bAccount->connector)) {
            return response()->json(['message' => 'Łącznik tego konta nie podaje cen rozmiarów — scalanie nie dotyczy jego kart.'], 422);
        }
        if ($this->syncIsRunning($b2bAccount)) {
            return response()->json(['message' => 'Trwa synchronizacja tego konta — scal po jej zakończeniu.'], 409);
        }
        $current = $this->sizeMergeState($b2bAccount);
        if ($current !== null && in_array($current['status'], ['queued', 'running'], true)) {
            return response()->json(['message' => 'Scalanie tego konta już trwa.'], 409);
        }
        $spread = $merger->spread($b2bAccount);
        if ($spread['reason'] !== null) {
            return response()->json(['message' => ucfirst($spread['reason']).'.'], 422);
        }
        $apply = $data['mode'] === 'apply';
        $backupPath = $apply ? B2bSizePriceMerger::newBackupPath($b2bAccount) : null;
        if ($apply && $backupPath === null) {
            return response()->json(['message' => 'Kopia zapasowa nie powstanie (brak katalogu storage/app/repair-backups) — nic nie scalono.'], 422);
        }

        $token = (string) Str::uuid();
        MergeB2bSizePricesJob::saveState((int) $b2bAccount->id, [
            'token' => $token,
            'mode' => $data['mode'],
            'status' => 'queued',
            'with_tenders' => (bool) ($data['with_tenders'] ?? false),
            'limit' => isset($data['limit']) ? (int) $data['limit'] : null,
            'run_id' => (int) $spread['run']->id,
            'started_at' => now()->toIso8601String(),
            'finished_at' => null,
            'processed' => 0,
            'total' => count($spread['groups']),
            'to_merge' => 0,
            'merged' => 0,
            'sizes' => 0,
            'tenders' => 0,
            'sku_renamed' => 0,
            'skipped' => [],
            'lines' => [],
            'backup_path' => $backupPath,
            'error' => null,
            'user_id' => $request->user()?->id,
        ]);
        MergeB2bSizePricesJob::dispatch((int) $b2bAccount->id, $token);

        return response()->json($this->sizeMergeView($b2bAccount, $merger), 202);
    }

    /**
     * „Uzupełnij krótkie opisy”: karty konta z opisem z B2B krótszym niż próg konta, szukane najpierw na stronach
     * z opisami konta (B2bDescriptionSupplement). apply=false — sam podgląd liczby kart, nic nie zleca.
     */
    public function supplementDescriptions(Request $request, B2bAccount $b2bAccount, B2bDescriptionSupplement $supplement): JsonResponse
    {
        $data = $request->validate([
            'apply' => ['sometimes', 'boolean'],
            'only_untried' => ['sometimes', 'boolean'],
        ]);
        if ($b2bAccount->enrichmentHosts() === []) {
            return response()->json([
                'message' => 'Konto nie ma stron z opisami — dodaj je w edycji konta („Strony z opisami”).',
            ], 422);
        }

        $onlyUntried = (bool) ($data['only_untried'] ?? true);
        $minChars = $b2bAccount->enrichmentMinChars();

        if (! (bool) ($data['apply'] ?? false)) {
            $candidates = count($supplement->candidateIds($b2bAccount, $onlyUntried));

            return response()->json([
                'candidates' => $candidates,
                'queued' => 0,
                'message' => $candidates === 0
                    ? "Brak kart z opisem z B2B krótszym niż {$minChars} znaków do uzupełnienia."
                    : "Kart z opisem z B2B krótszym niż {$minChars} znaków do uzupełnienia: {$candidates}.",
            ]);
        }

        $result = $supplement->queue($b2bAccount, null, $onlyUntried);

        return response()->json([
            'candidates' => (int) $result['candidates'],
            'queued' => (int) $result['queued'],
            'message' => (int) $result['queued'] === 0
                ? 'Nic nie zlecono — brak kart do uzupełnienia.'
                : "Zlecono uzupełnianie opisów w tle, kart: {$result['queued']}.",
        ]);
    }

    /** Okno postępu uzupełniania opisów konta: liczniki, karty w pracy z etapem, czekające na wyszukiwarkę, ostatnie wyniki. */
    public function supplementProgress(B2bAccount $b2bAccount, B2bDescriptionSupplement $supplement): JsonResponse
    {
        return response()->json($supplement->progress($b2bAccount));
    }

    /** „Zatrzymaj” — karty konta w kolejce i w pracy zatrzymane (wznowienie: „Uzupełnij krótkie opisy”). */
    public function stopSupplement(B2bAccount $b2bAccount, B2bDescriptionSupplement $supplement): JsonResponse
    {
        $stopped = $supplement->stop($b2bAccount);

        return response()->json([
            'stopped' => $stopped,
            'message' => $stopped === 0 ? 'Nic nie było w toku.' : "Zatrzymano uzupełnianie opisów, kart: {$stopped}.",
        ]);
    }

    /** „Zatrzymaj wszystko” — uzupełnianie opisów wszystkich kont. */
    public function stopAllSupplements(B2bDescriptionSupplement $supplement): JsonResponse
    {
        $stopped = $supplement->stop(null);

        return response()->json([
            'stopped' => $stopped,
            'message' => $stopped === 0 ? 'Nic nie było w toku.' : "Zatrzymano uzupełnianie opisów wszystkich kont, kart: {$stopped}.",
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function sizeMergeView(B2bAccount $account, B2bSizePriceMerger $merger): array
    {
        $spread = $merger->spreadSummary($account);
        $state = $this->sizeMergeState($account);

        return [
            'spread' => [...$spread, 'reason' => $spread['reason'] !== null ? ucfirst($spread['reason']).'.' : null],
            'state' => $state === null ? null : [
                ...array_intersect_key($state, array_flip([
                    'mode', 'status', 'with_tenders', 'limit', 'started_at', 'updated_at', 'finished_at', 'processed', 'total',
                    'to_merge', 'merged', 'sizes', 'tenders', 'sku_renamed', 'lines', 'backup_path', 'error',
                ])),
                'skipped' => $this->skippedList(is_array($state['skipped'] ?? null) ? $state['skipped'] : []),
            ],
            'sync_running' => $this->syncIsRunning($account),
        ];
    }

    /**
     * Stan zadania; „w kolejce/trwa” bez znaku życia od STALE_MINUTES — błąd (worker zatrzymany, zadanie przerwane).
     *
     * @return array<string, mixed>|null
     */
    private function sizeMergeState(B2bAccount $account): ?array
    {
        $state = MergeB2bSizePricesJob::state((int) $account->id);
        if ($state === null) {
            return null;
        }
        $updated = is_string($state['updated_at'] ?? null) ? Carbon::parse($state['updated_at']) : null;
        if (in_array($state['status'] ?? null, ['queued', 'running'], true)
            && ($updated === null || $updated->lt(now()->subMinutes(MergeB2bSizePricesJob::STALE_MINUTES)))) {
            $state['status'] = 'failed';
            $state['error'] = 'Zadanie w tle nie odpowiada od ponad '.MergeB2bSizePricesJob::STALE_MINUTES.' min (kolejka zatrzymana albo zadanie przerwane) — uruchom ponownie.';
        }

        return $state;
    }

    /**
     * @param  array<string, int>  $skipped
     * @return list<array{reason: string, count: int}>
     */
    private function skippedList(array $skipped): array
    {
        arsort($skipped);
        $out = [];
        foreach ($skipped as $reason => $count) {
            $out[] = ['reason' => (string) $reason, 'count' => (int) $count];
        }

        return $out;
    }

    private const CODE_LOGIN_TTL_MINUTES = 15;

    private function syncIsRunning(B2bAccount $account): bool
    {
        return $account->last_sync_status === B2bSyncRun::STATUS_RUNNING
            || $account->syncRuns()->where('status', B2bSyncRun::STATUS_RUNNING)->exists();
    }

    private function codeLoginCacheKey(B2bAccount $account): string
    {
        return 'b2b-code-login:'.$account->id;
    }

    /**
     * Stan rozpoczętego logowania kodem; nieczytelny (zmieniony klucz aplikacji, uszkodzony wpis) = brak stanu.
     *
     * @return array<string, mixed>|null
     */
    private function codeLoginState(B2bAccount $account): ?array
    {
        $encrypted = Cache::get($this->codeLoginCacheKey($account));
        if (! is_string($encrypted) || $encrypted === '') {
            return null;
        }

        try {
            $state = json_decode(Crypt::decryptString($encrypted), true, 512, JSON_THROW_ON_ERROR);
        } catch (DecryptException|JsonException) {
            return null;
        }

        return is_array($state) ? $state : null;
    }

    private const RUN_COLUMNS = [
        'status', 'trigger', 'started_at', 'finished_at', 'updated_at', 'total', 'processed', 'created',
        'updated', 'unchanged', 'skipped', 'prices_changed', 'descriptions', 'images', 'current_sku',
        'message', 'cancel_requested_at', 'progress_unit',
    ];

    /**
     * @return array<string, mixed>
     */
    private function runView(B2bSyncRun $run): array
    {
        return [
            'id' => $run->id,
            'status' => $run->status,
            'trigger' => $run->trigger,
            // products = postęp liczony w kartach; variants = w wersjach (liczniki nadal w kartach).
            'progress_unit' => (string) ($run->progress_unit ?: 'products'),
            'started_at' => $run->started_at?->toIso8601String(),
            'finished_at' => $run->finished_at?->toIso8601String(),
            'updated_at' => $run->updated_at?->toIso8601String(),
            'total' => $run->total,
            'processed' => (int) $run->processed,
            'created' => (int) $run->created,
            'updated' => (int) $run->updated,
            'unchanged' => (int) $run->unchanged,
            'skipped' => (int) $run->skipped,
            'prices_changed' => (int) $run->prices_changed,
            'descriptions' => (int) $run->descriptions,
            'images' => (int) $run->images,
            'current_sku' => $run->current_sku,
            'message' => $run->message,
            'cancel_requested' => $run->cancel_requested_at !== null,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function validated(Request $request, bool $creating): array
    {
        // Łącznik musimy znać przed walidacją: witryna publiczna (protekt.pl) nie ma hasła,
        // a klucz ustalany niżej byłby już po sprawdzeniu reguł.
        $connector = trim((string) $request->input('connector')) ?: (string) $this->connectors->keyForSites(
            array_map(static fn ($site): string => trim((string) $site), (array) $request->input('sites', []))
        );
        $needsPassword = $this->connectors->requiresPassword($connector ?: null);

        $data = $request->validate([
            // Nazwa konta zostaje wymagana także dla witryn bez logowania — jest etykietą konta w panelu i w CLI.
            'username' => ['required', 'string', 'max:255'],
            // Trzecie pole logowania — tylko dla witryn, które go wymagają (np. UVEX).
            'contractor_code' => ['nullable', 'string', 'max:100'],
            'password' => [$creating && $needsPassword ? 'required' : 'nullable', 'string', 'max:1000'],
            'sites' => ['required', 'array', 'min:1', 'max:20'],
            // Puste wiersze (ConvertEmptyStringsToNull → null) pomijamy, nie odrzucamy formularza.
            'sites.*' => ['nullable', 'string', 'max:255'],
            'note' => ['nullable', 'string', 'max:5000'],
            'connector' => ['nullable', 'string', Rule::in($this->connectors->keys())],
            'sync_frequency' => ['sometimes', 'string', Rule::in(B2bAccount::FREQUENCIES)],
            'sync_images' => ['sometimes', 'boolean'],
            // Strony z opisami (uzupełnianie krótkich opisów B2B) — brak klucza w żądaniu = lista bez zmian.
            'enrichment_sites' => ['sometimes', 'nullable', 'array', 'max:20'],
            'enrichment_sites.*' => ['nullable', 'string', 'max:255'],
            'enrichment_min_chars' => ['sometimes', 'nullable', 'integer', 'min:200', 'max:5000'],
        ], [
            'enrichment_sites.array' => 'Strony z opisami: podaj listę adresów.',
            'enrichment_sites.max' => 'Strony z opisami: najwyżej :max stron.',
            'enrichment_sites.*.string' => 'Strony z opisami: każdy wpis musi być adresem strony.',
            'enrichment_sites.*.max' => 'Strony z opisami: adres dłuższy niż :max znaków.',
            'enrichment_min_chars.integer' => 'Próg długości opisu musi być liczbą całkowitą.',
            'enrichment_min_chars.min' => 'Próg długości opisu: co najmniej :min znaków.',
            'enrichment_min_chars.max' => 'Próg długości opisu: najwyżej :max znaków.',
        ]);

        if (array_key_exists('enrichment_sites', $data)) {
            $data['enrichment_sites'] = $this->enrichmentSites((array) ($data['enrichment_sites'] ?? []));
        }

        if (array_key_exists('contractor_code', $data)) {
            $data['contractor_code'] = trim((string) $data['contractor_code']) ?: null;
        }

        $data['sites'] = array_values(array_unique(array_filter(
            array_map(static fn (?string $site): string => trim((string) $site), $data['sites']),
            static fn (string $site): bool => $site !== '',
        )));

        if ($data['sites'] === []) {
            throw ValidationException::withMessages(['sites' => 'Podaj co najmniej jedną witrynę.']);
        }

        $data['connector'] = ($data['connector'] ?? null) ?: $this->connectors->keyForSites($data['sites']);

        return $data;
    }

    /**
     * Wpisy „Strony z opisami” (adres strony albo domena) → hosty jak strony producentów (bez www., bez ścieżki),
     * bez powtórzeń. Pusta lista = null (konto bez uzupełniania).
     *
     * @param  array<int|string, mixed>  $sites
     * @return list<string>|null
     *
     * @throws ValidationException
     */
    private function enrichmentSites(array $sites): ?array
    {
        return EnrichmentSiteList::normalize($sites, 'enrichment_sites', 'Strony z opisami');
    }

    /**
     * Liczniki prób uzupełniania opisów według statusu — jednym zapytaniem grupującym dla wszystkich podanych kont
     * (plus drugie o karty czekające na wyszukiwarkę: waiting_search, next_retry_at).
     *
     * @param  list<int>  $accountIds
     * @return array<int, array<string, int|string|null>>
     */
    private function supplementStats(array $accountIds): array
    {
        $empty = array_fill_keys([
            B2bDescriptionSupplementAttempt::STATUS_QUEUED,
            B2bDescriptionSupplementAttempt::STATUS_RUNNING,
            B2bDescriptionSupplementAttempt::STATUS_REPLACED,
            B2bDescriptionSupplementAttempt::STATUS_KEPT,
            B2bDescriptionSupplementAttempt::STATUS_NO_PAGES,
            B2bDescriptionSupplementAttempt::STATUS_FAILED,
            B2bDescriptionSupplementAttempt::STATUS_CANCELLED,
        ], 0);
        // karty w kolejce czekające na wyszukiwarkę (przerwa bezpiecznika) i najbliższe ponowienie
        $empty['waiting_search'] = 0;
        $empty['next_retry_at'] = null;
        $out = array_fill_keys($accountIds, $empty);
        if ($accountIds === []) {
            return $out;
        }

        $rows = B2bDescriptionSupplementAttempt::query()
            ->whereIn('b2b_account_id', $accountIds)
            ->groupBy('b2b_account_id', 'status')
            ->selectRaw('b2b_account_id, status, COUNT(*) AS n')
            ->toBase()
            ->get();
        foreach ($rows as $row) {
            $status = (string) $row->status;
            if (isset($out[(int) $row->b2b_account_id]) && array_key_exists($status, $empty) && $status !== 'next_retry_at') {
                $out[(int) $row->b2b_account_id][$status] = (int) $row->n;
            }
        }
        $waiting = B2bDescriptionSupplementAttempt::query()
            ->whereIn('b2b_account_id', $accountIds)
            ->where('status', B2bDescriptionSupplementAttempt::STATUS_QUEUED)
            ->whereNotNull('retry_at')
            ->groupBy('b2b_account_id')
            ->selectRaw('b2b_account_id, COUNT(*) AS n, MIN(retry_at) AS next_retry')
            ->toBase()
            ->get();
        foreach ($waiting as $row) {
            if (isset($out[(int) $row->b2b_account_id])) {
                $out[(int) $row->b2b_account_id]['waiting_search'] = (int) $row->n;
                $out[(int) $row->b2b_account_id]['next_retry_at'] = Carbon::parse((string) $row->next_retry)->toIso8601String();
            }
        }

        return $out;
    }

    /**
     * Które z podanych hostów są w domenach dodanych do indeksu (CatalogSearchSite) — reszta to podpowiedź w panelu,
     * że program nie zna mapy tej strony.
     *
     * @param  list<string>  $hosts
     * @return array<string, true>
     */
    private function indexedHosts(array $hosts): array
    {
        if ($hosts === []) {
            return [];
        }

        $indexed = [];
        foreach (CatalogSearchSite::query()->whereIn('host', $hosts)->pluck('host') as $host) {
            $indexed[ManufacturerSite::normalizeHost((string) $host)] = true;
        }

        return $indexed;
    }

    /**
     * @param  array<string, int>|null  $supplementStats  liczniki z supplementStats() (lista kont); null = policz dla konta
     * @param  array<string, true>|null  $indexedHosts  wynik indexedHosts() (lista kont); null = sprawdź hosty konta
     * @return array<string, mixed>
     */
    private function view(B2bAccount $account, ?array $supplementStats = null, ?array $indexedHosts = null): array
    {
        $raw = $account->getRawOriginal('password');
        $enrichmentHosts = $account->enrichmentHosts();
        $supplementStats ??= $this->supplementStats([(int) $account->id])[(int) $account->id];
        $indexedHosts ??= $this->indexedHosts($enrichmentHosts);

        return [
            'id' => $account->id,
            'username' => $account->username,
            'contractor_code' => $account->contractor_code,
            'has_password' => is_string($raw) && $raw !== '',
            'sites' => $account->sites ?? [],
            'note' => $account->note,
            'connector' => $account->connector,
            'connector_label' => $this->connectors->label($account->connector),
            'sync_frequency' => $account->sync_frequency ?? 'off',
            'sync_images' => (bool) ($account->sync_images ?? true),
            // uzupełnianie krótkich opisów B2B ze stron konta (B2bDescriptionSupplement)
            'enrichment_sites' => $enrichmentHosts,
            'enrichment_min_chars' => $account->enrichment_min_chars,
            'enrichment_min_chars_effective' => $account->enrichmentMinChars(),
            'supplement_stats' => $supplementStats,
            'enrichment_hosts_not_indexed' => array_values(array_filter(
                $enrichmentHosts,
                static fn (string $host): bool => ! isset($indexedHosts[$host]),
            )),
            'sync_requested_at' => $account->sync_requested_at?->toIso8601String(),
            'last_sync_status' => $account->last_sync_status,
            'last_sync_started_at' => $account->last_sync_started_at?->toIso8601String(),
            'last_sync_finished_at' => $account->last_sync_finished_at?->toIso8601String(),
            'last_sync_message' => $account->last_sync_message,
            'last_price_list_id' => $account->last_price_list_id,
            // Sama sesja (ciasteczka sklepu) nigdy nie wychodzi do panelu — tylko kiedy ją zapisano.
            'connector_session_saved_at' => $account->connector_session_saved_at?->toIso8601String(),
            'requires_login_code' => $this->connectors->requiresLoginCode($account->connector),
            // łącznik z cenami rozmiarów — przycisk „Scal rozmiary” (karty rozbite dawniej według ceny)
            'size_price_merge' => $this->connectors->sendsSizePrices($account->connector),
            'created_by' => $account->creator?->only(['id', 'name']),
            'updated_by' => $account->updater?->only(['id', 'name']),
            'created_at' => $account->created_at?->toIso8601String(),
            'updated_at' => $account->updated_at?->toIso8601String(),
        ];
    }
}
