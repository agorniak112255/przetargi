<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\ComposeClientInquiryRequest;
use App\Http\Requests\MarkClientInquiryRepliedRequest;
use App\Http\Requests\PickClientInquiryProductRequest;
use App\Http\Requests\QueueClientInquiryReplyRequest;
use App\Http\Requests\StoreClientInquiryRequest;
use App\Http\Requests\UpdateClientInquiryRequest;
use App\Jobs\AnalyzeClientInquiryJob;
use App\Models\ClientInquiry;
use App\Models\OfferComposeRequest;
use App\Models\User;
use App\Services\ClientInquiryService;
use App\Services\InquiryFileText;
use App\Services\Pricing\SupplierSpecialMask;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use RuntimeException;
use Throwable;

class ClientInquiryController extends Controller
{
    /**
     * Odstęp, po jakim dodatek do Thunderbirda pyta znowu o prośby „Zapisz i wyślij” (nagłówek X-Poll-After), gdy
     * handlowiec jest w aplikacji — tylko tam może kliknąć „Zapisz i wyślij”. Przy 15 s czekał średnio 7 s na okno
     * odpowiedzi, stąd 5 s.
     */
    private const QUEUE_POLL_FAST_SECONDS = 5;

    /**
     * Handlowca nie ma w aplikacji od QUEUE_PRESENCE_MINUTES. Najwyżej 30 s: po kliknięciu aplikacja czeka na odebranie
     * listu ok. 40 s (InquiryReply: WATCH_TRIES × WATCH_EVERY_MS), potem pisze, że Thunderbird go nie odebrał.
     */
    private const QUEUE_POLL_SLOW_SECONDS = 30;

    /** Sygnał obecności z aplikacji (usePresence → users.last_seen_at) idzie co minutę przy widocznej karcie. */
    private const QUEUE_PRESENCE_MINUTES = 15;

    public function __construct(
        private readonly ClientInquiryService $inquiries,
    ) {}

    /**
     * Lista zapytań: stronicowana i filtrowana po stronie bazy.
     *
     * Po roku pracy wpisów będą tysiące, więc nic tu nie wolno wczytywać
     * „na całą tabelę” — filtry i stronicowanie idą do SQL-a, a w PHP
     * liczona jest tylko jedna strona wyników.
     */
    public function index(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'q' => ['nullable', 'string', 'max:200'],
            'status' => ['nullable', 'string', 'in:all,waiting,replied'],
            'channel' => ['nullable', 'string', 'in:all,web,thunderbird,file'],
            'scope' => ['nullable', 'string', 'in:mine,all'],
            'user_id' => ['nullable', 'integer', 'exists:users,id'],
            'from' => ['nullable', 'date_format:Y-m-d'],
            'to' => ['nullable', 'date_format:Y-m-d', 'after_or_equal:from'],
            'page' => ['nullable', 'integer', 'min:1'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
        ], [
            'q.string' => 'Szukana fraza musi być tekstem.',
            'q.max' => 'Szukana fraza może mieć najwyżej 200 znaków.',
            'status.in' => 'Nieznany status. Dozwolone: all, waiting, replied.',
            'channel.in' => 'Nieznane źródło. Dozwolone: all, web, thunderbird, file.',
            'scope.in' => 'Nieznany zakres. Dozwolone: mine, all.',
            'user_id.integer' => 'Identyfikator użytkownika musi być liczbą.',
            'user_id.exists' => 'Nie ma takiego użytkownika.',
            'from.date_format' => 'Data „od” musi być w formacie RRRR-MM-DD.',
            'to.date_format' => 'Data „do” musi być w formacie RRRR-MM-DD.',
            'to.after_or_equal' => 'Data „do” nie może być wcześniejsza niż data „od”.',
            'page.integer' => 'Numer strony musi być liczbą.',
            'page.min' => 'Numer strony musi być większy od zera.',
            'per_page.integer' => 'Liczba wyników na stronie musi być liczbą.',
            'per_page.min' => 'Liczba wyników na stronie musi być większa od zera.',
            'per_page.max' => 'Na jedną stronę można pobrać najwyżej 100 zapytań.',
        ]);

        $user = $request->user();
        $canViewAll = $user->can('inquiries.view_all');
        $scope = (string) ($validated['scope'] ?? 'mine');

        if ($scope === 'all' && ! $canViewAll) {
            abort(403, 'Brak uprawnienia do oglądania zapytań innych użytkowników.');
        }

        $perPage = (int) ($validated['per_page'] ?? 25);

        $query = ClientInquiry::query()
            // Bez dużych kolumn (source_body, reply_body) — do listy ich nie potrzeba.
            // „analysis” i „answers” zostają, bo z nich liczy się attention_count,
            // a liczy się je tylko dla jednej strony wyników.
            ->select([
                'id',
                'user_id',
                'client_id',
                'source_subject',
                'reply_subject',
                'source_channel',
                'source_from_name',
                'source_from_email',
                'source_sent_at',
                'source_message_id',
                'source_fingerprint',
                'source_fingerprint_tail',
                'duplicate_of_id',
                'contact',
                'analysis',
                'answers',
                'replied_at',
                'send_requested_at',
                'created_at',
                'analysis_status',
                'analysis_started_at',
            ])
            // has_reply bez wczytywania całej treści listu
            ->selectRaw("CASE WHEN reply_body IS NOT NULL AND reply_body <> '' THEN 1 ELSE 0 END as has_reply")
            ->with(['client:id,name', 'user:id,name'])
            ->orderByDesc('created_at')
            ->orderByDesc('id');

        if ($scope === 'all') {
            if (! empty($validated['user_id'])) {
                $query->where('user_id', (int) $validated['user_id']);
            }
        } else {
            $query->where('user_id', $user->id);
        }

        $status = (string) ($validated['status'] ?? 'all');
        if ($status === 'waiting') {
            $query->whereNull('replied_at');
        } elseif ($status === 'replied') {
            $query->whereNotNull('replied_at');
        }

        $channel = (string) ($validated['channel'] ?? 'all');
        if ($channel !== 'all') {
            $query->where('source_channel', $channel);
        }

        if (! empty($validated['from'])) {
            $query->whereDate('created_at', '>=', $validated['from']);
        }
        if (! empty($validated['to'])) {
            $query->whereDate('created_at', '<=', $validated['to']);
        }

        $q = trim((string) ($validated['q'] ?? ''));
        if ($q !== '') {
            $like = '%'.$q.'%';
            $query->where(function ($builder) use ($like): void {
                $builder
                    ->where('source_subject', 'like', $like)
                    ->orWhere('reply_subject', 'like', $like)
                    ->orWhere('source_from_name', 'like', $like)
                    ->orWhere('source_from_email', 'like', $like)
                    ->orWhere('contact->company', 'like', $like)
                    ->orWhere('source_body', 'like', $like);
            });
        }

        $paginator = $query->paginate($perPage, ['*'], 'page', $validated['page'] ?? null);

        // Ilu jeszcze ludzi ma ten sam mail — liczone jednym zapytaniem dla całej
        // strony, nie per wiersz, żeby lista nie zwalniała przy tysiącach wpisów.
        $duplicateCounts = $this->duplicateCounts(collect($paginator->items()));

        $data = collect($paginator->items())->map(fn (ClientInquiry $row): array => [
            'id' => $row->id,
            'source_subject' => $row->source_subject,
            'reply_subject' => $row->reply_subject,
            'client' => $row->client !== null
                ? ['id' => $row->client->id, 'name' => $row->client->name]
                : null,
            'created_at' => $row->created_at?->toIso8601String(),
            'source_channel' => (string) $row->source_channel,
            'source_from_name' => $row->source_from_name,
            'source_from_email' => $row->source_from_email,
            'source_sent_at' => $row->source_sent_at?->toIso8601String(),
            'has_reply' => (bool) $row->getAttribute('has_reply'),
            'replied_at' => $row->replied_at?->toIso8601String(),
            'send_requested_at' => $row->send_requested_at?->toIso8601String(),
            'attention_count' => $this->inquiries->attentionCount($row),
            // analiza w tle: queued / running / done / failed
            'analysis_status' => $row->effectiveAnalysisStatus(),
            'contact' => is_array($row->contact) ? $row->contact : null,
            'user' => $row->user !== null
                ? ['id' => $row->user->id, 'name' => $row->user->name]
                : null,
            'duplicate_of_id' => $row->duplicate_of_id,
            'duplicates_count' => $duplicateCounts[$row->id] ?? 0,
        ])->values();

        return response()->json([
            'data' => $data,
            'meta' => [
                'page' => $paginator->currentPage(),
                'per_page' => $paginator->perPage(),
                'total' => $paginator->total(),
                'last_page' => $paginator->lastPage(),
                'can_view_all' => $canViewAll,
            ],
        ]);
    }

    /**
     * Liczba cudzych zapytań z tego samego maila, dla każdego wiersza strony.
     *
     * Jedno zapytanie na całą stronę: bierzemy identyfikatory maili i odciski
     * treści z widocznych wierszy, a dopasowanie robimy w PHP.
     *
     * @param  Collection<int, ClientInquiry>  $rows
     * @return array<int, int>
     */
    private function duplicateCounts(Collection $rows): array
    {
        $messageIds = $rows->pluck('source_message_id')->filter()->unique()->values()->all();
        $hashes = $rows
            ->flatMap(fn (ClientInquiry $row): array => [$row->source_fingerprint, $row->source_fingerprint_tail])
            ->filter()
            ->unique()
            ->values()
            ->all();

        if ($messageIds === [] && $hashes === []) {
            return [];
        }

        $related = ClientInquiry::query()
            ->select(['id', 'source_message_id', 'source_fingerprint', 'source_fingerprint_tail'])
            ->where(function ($builder) use ($messageIds, $hashes): void {
                if ($messageIds !== []) {
                    $builder->orWhereIn('source_message_id', $messageIds);
                }
                if ($hashes !== []) {
                    $builder->orWhereIn('source_fingerprint', $hashes)
                        ->orWhereIn('source_fingerprint_tail', $hashes);
                }
            })
            ->get();

        $counts = [];
        foreach ($rows as $row) {
            $rowHashes = array_values(array_filter([$row->source_fingerprint, $row->source_fingerprint_tail]));
            $counts[$row->id] = $related
                ->filter(function (ClientInquiry $other) use ($row, $rowHashes): bool {
                    if ($other->id === $row->id) {
                        return false;
                    }
                    if ($row->source_message_id !== null && $other->source_message_id === $row->source_message_id) {
                        return true;
                    }
                    $otherHashes = array_filter([$other->source_fingerprint, $other->source_fingerprint_tail]);

                    return array_intersect($rowHashes, $otherHashes) !== [];
                })
                ->count();
        }

        return $counts;
    }

    public function preferences(Request $request): JsonResponse
    {
        // Tylko ustawienia listu; warunki oferty z ostatniego zapytania przepisuje
        // analiza przy zakładaniu nowego i strona odpowiedzi czyta je stamtąd.
        $last = $this->inquiries->lastPreferences($request->user());

        return response()->json([
            'tone' => $last['tone'],
            'price_mode' => $last['price_mode'],
            'margin' => $last['margin'],
        ]);
    }

    public function store(StoreClientInquiryRequest $request): JsonResponse
    {
        if (function_exists('set_time_limit')) {
            @set_time_limit(180);
        }

        $data = $request->validated();

        // Powtórne kliknięcie w dodatku ma otworzyć istniejące zapytanie,
        // a nie uruchomić drugiej analizy tego samego maila.
        $existing = $this->inquiries->existingForMessage(
            $request->user(),
            isset($data['source_message_id']) ? (string) $data['source_message_id'] : null,
        );
        if ($existing instanceof ClientInquiry) {
            return response()->json($this->presentFor($request, $existing->load('client')));
        }

        // Ten sam mail u kilku handlowców: zanim ruszy kosztowna analiza,
        // sprawdzamy, czy ktoś już tym nie siedzi. Odcisk treści liczymy z tej
        // samej, oczyszczonej wersji maila, którą dostaje model.
        $fingerprints = $this->inquiries->fingerprints(
            ClientInquiryService::analysisText(
                (string) $data['body'],
                $data['source_channel'] ?? null,
                isset($data['subject']) && trim((string) $data['subject']) !== '' ? trim((string) $data['subject']) : null,
            )
        );
        $force = (bool) ($data['force'] ?? false);
        $other = $this->inquiries->findOthersInquiry(
            $request->user(),
            isset($data['source_message_id']) ? (string) $data['source_message_id'] : null,
            $fingerprints,
        );

        if ($other !== null && ! $force) {
            $owner = $other['inquiry']->user?->name ?? 'inna osoba';

            return response()->json([
                'message' => 'Tym zapytaniem zajmuje się już '.$owner.'.',
                'duplicate' => $this->inquiries->duplicateRef($other['inquiry'], $other['match']),
            ], 409);
        }

        try {
            // Zapytanie powstaje od razu, analiza idzie w tle (przy 50 pozycjach kilka minut — serwer ucina tak
            // długie żądania). Strona zapytania pokazuje postęp i sama się odświeża.
            $inquiry = $this->inquiries->createPending(
                $request->user(),
                (string) $data['body'],
                (string) $data['tone'],
                isset($data['client_id']) ? (int) $data['client_id'] : null,
                isset($data['subject']) ? (string) $data['subject'] : null,
                [
                    'message_id' => isset($data['source_message_id']) ? (string) $data['source_message_id'] : null,
                    'channel' => isset($data['source_channel']) ? (string) $data['source_channel'] : null,
                    'from' => isset($data['source_from']) ? (string) $data['source_from'] : null,
                    'sent_at' => isset($data['source_sent_at']) ? (string) $data['source_sent_at'] : null,
                    'file_name' => isset($data['source_file_name']) ? (string) $data['source_file_name'] : null,
                    // świadoma kopia cudzego zapytania — wiążemy oba, żeby było widać parę
                    'duplicate_of_id' => $other === null ? null : $other['inquiry']->id,
                ],
            );
        } catch (Throwable $e) {
            return response()->json(['message' => 'Nie udało się założyć zapytania: '.$e->getMessage()], 422);
        }
        AnalyzeClientInquiryJob::dispatch((int) $inquiry->id, (string) $inquiry->analysis_run_id);
        // Przy kolejce „sync” (testy, lokalnie bez workera) analiza już się policzyła.
        $inquiry->refresh()->load('client');

        return response()->json($this->presentFor($request, $inquiry), 201);
    }

    /**
     * Tekst zapytania z pliku klienta (Excel, PDF, Word). Zapytanie jeszcze nie powstaje: tekst wraca do pola treści,
     * handlowiec go przegląda i dopiero wtedy wysyła jak wklejony mail (`source_channel: file`). Pliku nie zapisujemy.
     */
    public function fileText(Request $request, InquiryFileText $files): JsonResponse
    {
        $request->validate([
            'file' => ['required', 'file', 'max:20480'],
        ], [
            'file.required' => 'Wybierz plik.',
            'file.max' => 'Plik jest za duży (limit 20 MB).',
        ]);
        $file = $request->file('file');
        $ext = mb_strtolower((string) $file->getClientOriginalExtension());
        if (! in_array($ext, InquiryFileText::EXTENSIONS, true)) {
            throw ValidationException::withMessages(['file' => ['Dozwolone pliki: '.InquiryFileText::FORMATS_LABEL.'.']]);
        }

        try {
            $text = $files->extract((string) $file->getRealPath(), $ext);
        } catch (Throwable $e) {
            // uszkodzony plik albo skan: komunikat czytnika mówi handlowcowi, co zrobić
            throw ValidationException::withMessages(['file' => [
                $e instanceof RuntimeException ? $e->getMessage() : 'Nie udało się odczytać pliku: '.$e->getMessage(),
            ]]);
        }

        return response()->json([
            'text' => $text,
            'chars' => mb_strlen($text),
            'file_name' => $file->getClientOriginalName(),
        ]);
    }

    /**
     * Cudze zapytanie otwiera tylko rola z `inquiries.view_others` — i tylko
     * do podglądu: wszystkie zmiany (list, wybór wyrobu, wysyłka) zostają
     * przy autorze, bo pilnuje ich `assertOwner`.
     */
    public function show(Request $request, ClientInquiry $inquiry): JsonResponse
    {
        if (! $request->user()->can('inquiries.view_others')) {
            $this->assertOwner($request, $inquiry);
        }
        // List przeliczamy tylko autorowi — podgląd cudzego zapytania niczego w nim nie zapisuje.
        if ((int) $inquiry->user_id === (int) $request->user()->id && $inquiry->isAnalyzed()) {
            $this->inquiries->withPriceMask(
                $this->viewer($request),
                fn (): ClientInquiry => $this->inquiries->refreshStoredReply($inquiry),
            );
        }

        return response()->json($this->presentFor($request, $inquiry->load('client')));
    }

    /**
     * List w innym szablonie bez zapisu — dla oglądającego cudze zapytanie (`inquiries.view_others`), który szablonu
     * zmienić nie może: autor i Thunderbird dalej dostają zapisany list. Ceny od widoku oglądającego, jak w present().
     */
    public function replyPreview(Request $request, ClientInquiry $inquiry): JsonResponse
    {
        if (! $request->user()->can('inquiries.view_others')) {
            $this->assertOwner($request, $inquiry);
        }
        $this->assertAnalyzed($inquiry);
        $tone = (string) $request->validate([
            'tone' => ['required', 'string', Rule::in(ClientInquiry::TONES)],
        ])['tone'];

        return response()->json(['tone' => $tone, ...$this->inquiries->previewReply($inquiry, $tone, $this->viewer($request))]);
    }

    public function compose(ComposeClientInquiryRequest $request, ClientInquiry $inquiry): JsonResponse
    {
        $this->assertOwner($request, $inquiry);
        $this->assertAnalyzed($inquiry);

        if (function_exists('set_time_limit')) {
            @set_time_limit(180);
        }

        $data = $request->validated();
        // Puste „answers” (np. samo przełączenie szablonu listu) walidator
        // pomija w wyniku — bez tego domyślnego pustego zbioru leciał błąd.
        /** @var array<string, array{option_id: string, custom?: string|null}> $answers */
        $answers = is_array($data['answers'] ?? null) ? $data['answers'] : [];

        try {
            // list liczy się od ceny, którą autor widzi (prices.supplier_special.view)
            $inquiry = $this->inquiries->withPriceMask($this->viewer($request), fn (): ClientInquiry => $this->inquiries->compose(
                $inquiry,
                $answers,
                // brak klucza w żądaniu = nie ruszaj zapisanego dopisku
                array_key_exists('extra_note', $data) ? $data['extra_note'] : false,
                // zmiana szablonu listu ze strony odpowiedzi; brak pola = bez zmiany
                isset($data['tone']) ? (string) $data['tone'] : null,
                // warunki oferty; brak klucza = zostaw zapisane
                array_key_exists('terms', $data) && is_array($data['terms']) ? $data['terms'] : false,
            ));
        } catch (RuntimeException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        } catch (Throwable $e) {
            return response()->json(['message' => 'Błąd pisania odpowiedzi: '.$e->getMessage()], 422);
        }

        return response()->json($this->presentFor($request, $inquiry));
    }

    /**
     * Wyrób wyszukany ręcznie (zwykłe szukanie albo AI) wstawiony przy pozycji.
     * Wynik jest ten sam co po kliknięciu alternatywy: przepisany list.
     */
    public function pickProduct(PickClientInquiryProductRequest $request, ClientInquiry $inquiry): JsonResponse
    {
        $this->assertOwner($request, $inquiry);
        $this->assertAnalyzed($inquiry);

        if (function_exists('set_time_limit')) {
            @set_time_limit(180);
        }

        $data = $request->validated();

        try {
            $inquiry = $this->inquiries->pickProduct(
                $inquiry,
                (string) $data['item_id'],
                (int) $data['product_id'],
                $this->viewer($request),
                // brak klucza = nie ruszaj zapisanego dopisku / warunków
                array_key_exists('extra_note', $data) ? $data['extra_note'] : false,
                array_key_exists('terms', $data) && is_array($data['terms']) ? $data['terms'] : false,
            );
        } catch (RuntimeException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        } catch (Throwable $e) {
            return response()->json(['message' => 'Błąd pisania odpowiedzi: '.$e->getMessage()], 422);
        }

        return response()->json($this->presentFor($request, $inquiry));
    }

    /** Ręczne poprawki tematu/treści listu przez pracownika. */
    public function update(UpdateClientInquiryRequest $request, ClientInquiry $inquiry): JsonResponse
    {
        $this->assertOwner($request, $inquiry);
        $this->assertAnalyzed($inquiry);

        $data = $request->validated();
        $changes = [];
        if (array_key_exists('reply_subject', $data)) {
            $changes['reply_subject'] = (string) $data['reply_subject'];
        }
        if (array_key_exists('reply_body', $data)) {
            $changes['reply_body'] = (string) $data['reply_body'];
            // Ręczna poprawka treści unieważnia tabelę — poszłaby do klienta
            // z innym tekstem niż ten, który pracownik przed chwilą zatwierdził.
            // Wraca przy ponownym złożeniu listu (wybór produktu, zmiana cen).
            $changes['reply_html'] = null;
        }
        if ($changes !== []) {
            $inquiry->forceFill($changes)->save();
        }

        return response()->json($this->presentFor($request, $inquiry->load('client')));
    }

    /** Oznaczenie „wysłano” (idempotentne): true ustawia raz, false kasuje. */
    public function replied(MarkClientInquiryRepliedRequest $request, ClientInquiry $inquiry): JsonResponse
    {
        $this->assertOwner($request, $inquiry);

        $replied = (bool) $request->validated()['replied'];
        if ($replied && $inquiry->replied_at === null) {
            $inquiry->forceFill(['replied_at' => now()])->save();
        } elseif (! $replied && $inquiry->replied_at !== null) {
            $inquiry->forceFill(['replied_at' => null])->save();
        }

        return response()->json($this->presentFor($request, $inquiry->load('client')));
    }

    /**
     * Usunięcie własnego zapytania. Cudzych nie ruszamy — nawet kierownik,
     * który widzi je na liście, nie kasuje pracy innej osoby.
     *
     * Kopie tego samego maila zostają; tracą tylko odnośnik do oryginału.
     */
    public function destroy(Request $request, ClientInquiry $inquiry): JsonResponse
    {
        $this->assertOwner($request, $inquiry);

        $inquiry->delete();

        return response()->json(['ok' => true]);
    }

    /**
     * Zapytania czekające na wysyłkę z klienta pocztowego. Odpytuje to dodatek
     * do Thunderbirda — przeglądarka nie ma jak sięgnąć do poczty na komputerze.
     */
    public function queued(Request $request): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();
        $viewer = SupplierSpecialMask::forUser($user);
        $rows = ClientInquiry::query()
            ->where('user_id', $user->id)
            ->whereNotNull('send_requested_at')
            ->whereNotNull('source_message_id')
            // stare prośby pomijamy — dodatek mógł być wtedy wyłączony
            ->where('send_requested_at', '>=', now()->subDay())
            ->orderBy('send_requested_at')
            ->limit(10)
            ->get()
            ->map(fn (ClientInquiry $row): array => [
                'id' => $row->id,
                'source_message_id' => $row->source_message_id,
                'reply_subject' => $row->reply_subject,
                'reply_body' => $row->reply_body,
                // tabela odtwarzana z odpowiedzi (stare listy bez reply_html) liczy ceny jak autor je widzi
                'reply_html' => $this->inquiries->withPriceMask($viewer, fn (): ?string => $this->inquiries->replyHtmlFor($row)),
                'requested_at' => $row->send_requested_at?->toIso8601String(),
            ]);

        // Dodatek 1.24.0+ pyta z with_offers=1 i dostaje obiekt z ofertami „Otwórz w Thunderbirdzie”. Starszy pyta bez
        // parametru i dostaje tablicę jak dotąd — nie umie otworzyć oferty, więc nie może jej też podjąć i zgubić.
        if ($request->boolean('with_offers')) {
            $this->markOfferAddonSeen($user);
            $offers = OfferComposeRequest::query()
                ->pendingFor($user)
                ->orderBy('requested_at')
                ->limit(5)
                ->get()
                ->map(fn (OfferComposeRequest $row): array => [
                    'id' => $row->id,
                    'subject' => $row->subject,
                    'body_html' => $row->body_html,
                    'body_text' => $row->body_text,
                    'requested_at' => $row->requested_at?->toIso8601String(),
                ]);

            return response()->json(['inquiries' => $rows, 'offers' => $offers])
                ->header('X-Poll-After', (string) $this->queuePollSeconds($user, $rows->isNotEmpty() || $offers->isNotEmpty()));
        }

        // 82% zapytań do API szło stąd (25.09.2026: 22,7 tys. w 5 h, dodatek co 5 s na 6–7 komputerach). Ciało zostaje
        // tablicą — dodatek sprzed 1.23.0 nagłówka nie czyta i pyta co 5 s jak dotąd.
        return response()->json($rows)
            ->header('X-Poll-After', (string) $this->queuePollSeconds($user, $rows->isNotEmpty()));
    }

    /**
     * Znacznik „dodatek umie otwierać oferty” dla okna oferty (OfferComposeController::status). Zapis najwyżej raz
     * na minutę i jednym warunkowym UPDATE — dodatek pyta co 5–30 s z kilku komputerów.
     */
    private function markOfferAddonSeen(User $user): void
    {
        $seen = $user->thunderbird_offers_seen_at;
        if ($seen !== null && $seen->gt(now()->subMinute())) {
            return;
        }
        User::query()
            ->whereKey($user->id)
            ->where(fn ($q) => $q->whereNull('thunderbird_offers_seen_at')->orWhere('thunderbird_offers_seen_at', '<=', now()->subMinute()))
            ->update(['thunderbird_offers_seen_at' => now()]);
    }

    /** Co ile sekund dodatek ma zapytać znowu: szybko, gdy prośba czeka albo handlowiec jest w aplikacji. */
    private function queuePollSeconds(User $user, bool $pending): int
    {
        if ($pending) {
            return self::QUEUE_POLL_FAST_SECONDS;
        }
        $seen = $user->last_seen_at;

        return $seen !== null && $seen->gte(now()->subMinutes(self::QUEUE_PRESENCE_MINUTES))
            ? self::QUEUE_POLL_FAST_SECONDS
            : self::QUEUE_POLL_SLOW_SECONDS;
    }

    /**
     * Które z podanych maili mają już zapytanie i kto je prowadzi.
     *
     * Dodatek do Thunderbirda pyta o paczkę Message-ID i oznacza nimi pozycje
     * na liście wiadomości, żeby na każdym komputerze było widać, że mailem
     * ktoś się już zajmuje — razem z nazwiskiem i z tym, czy odpowiedź poszła.
     *
     * Odpowiedź obejmuje zapytania wszystkich handlowców, bo to jest właśnie
     * sedno: ten sam mail trafia do kilku osób. Nie ma w niej treści maila,
     * tematu ani treści odpowiedzi — tylko numer zapytania, autor i daty.
     *
     * Brak klucza w odpowiedzi znaczy „sprawdzone, nie ma nic”: dodatek
     * zdejmuje wtedy swoje oznaczenie z maila.
     */
    public function lookup(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'message_ids' => ['required', 'array', 'min:1', 'max:200'],
            'message_ids.*' => ['required', 'string', 'max:255'],
        ], [
            'message_ids.required' => 'Podaj identyfikatory wiadomości.',
            'message_ids.array' => 'Identyfikatory wiadomości muszą być listą.',
            'message_ids.min' => 'Podaj co najmniej jeden identyfikator wiadomości.',
            'message_ids.max' => 'Na raz można sprawdzić najwyżej 200 wiadomości.',
            'message_ids.*.required' => 'Identyfikator wiadomości nie może być pusty.',
            'message_ids.*.string' => 'Identyfikator wiadomości musi być tekstem.',
            'message_ids.*.max' => 'Identyfikator wiadomości może mieć najwyżej 255 znaków.',
        ]);

        $found = $this->inquiries->byMessageIds($request->user(), $validated['message_ids']);

        // Rzutowanie na obiekt: pusty wynik ma zostać w JSON-ie mapą „{}”,
        // nie tablicą „[]” — dodatek czyta go po kluczach.
        return response()->json(['data' => (object) $found]);
    }

    /**
     * Message-ID zapytań ruszonych od podanej chwili.
     *
     * Świeżo zainstalowany dodatek (albo taki, którego komputer był tydzień
     * wyłączony) nie wie, które maile oznaczyć. Przejście po całej skrzynce
     * byłoby drogie, więc pytamy odwrotnie: serwer podaje identyfikatory
     * maili, wokół których coś się działo, a dodatek szuka ich u siebie.
     */
    public function messageIds(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'since' => ['nullable', 'date'],
        ], [
            'since.date' => 'Parametr „since” musi być datą.',
        ]);

        $since = empty($validated['since'])
            ? null
            : CarbonImmutable::parse((string) $validated['since']);

        return response()->json($this->inquiries->messageIdsTouchedSince($since));
    }

    /** Prośba o wysyłkę z Thunderbirda: true zgłasza, false kasuje po podjęciu. */
    public function queueReply(QueueClientInquiryReplyRequest $request, ClientInquiry $inquiry): JsonResponse
    {
        $this->assertOwner($request, $inquiry);
        $this->assertAnalyzed($inquiry);

        $queued = (bool) $request->validated()['queued'];

        if ($queued) {
            if ($inquiry->source_message_id === null) {
                return response()->json([
                    'message' => 'To zapytanie nie pochodzi z maila, więc nie ma na co odpowiedzieć w Thunderbirdzie.',
                ], 422);
            }
            if ((string) $inquiry->reply_body === '') {
                return response()->json(['message' => 'Najpierw dokończ treść odpowiedzi.'], 422);
            }
        }

        $inquiry->forceFill(['send_requested_at' => $queued ? now() : null])->save();

        return response()->json($this->presentFor($request, $inquiry->load('client')));
    }

    /**
     * Ponowna analiza po błędzie albo po przerwanym przebiegu — autor. Z uprawnieniem `inquiries.reanalyze`
     * także gotowa i cudza (cudze tylko z `inquiries.view_others`, jak podgląd); autor zapytania się nie zmienia.
     * Zadanie idzie do kolejki raz, nawet przy podwójnym kliknięciu — decyduje warunkowy zapis nowego przebiegu.
     */
    public function retryAnalysis(Request $request, ClientInquiry $inquiry): JsonResponse
    {
        $user = $request->user();
        $owner = (int) $inquiry->user_id === (int) $user->id;
        $mayReanalyze = $user->can('inquiries.reanalyze');
        if (! $owner && ! ($mayReanalyze && $user->can('inquiries.view_others'))) {
            abort(403, 'Brak dostępu do tego zapytania.');
        }
        if ($mayReanalyze && $inquiry->isAnalyzed() && ($blocker = $this->inquiries->reanalysisBlocker($inquiry)) !== null) {
            return response()->json(['message' => $blocker], 409);
        }
        $runId = $this->inquiries->restartAnalysis($inquiry, $mayReanalyze);
        if ($runId === null) {
            $fresh = $inquiry->fresh();
            $message = match (true) {
                $fresh === null => 'Zapytanie zostało usunięte.',
                $fresh->isAnalyzed() && $mayReanalyze => $this->inquiries->reanalysisBlocker($fresh) ?? 'Nie udało się uruchomić analizy ponownie.',
                $fresh->isAnalyzed() => 'Analiza tego zapytania jest już gotowa.',
                default => 'Analiza tego zapytania jeszcze trwa.',
            };

            return response()->json(['message' => $message], 409);
        }
        AnalyzeClientInquiryJob::dispatch((int) $inquiry->id, $runId);

        return response()->json($this->presentFor($request, $inquiry->refresh()->load('client')));
    }

    /** Zmiany zapytania dopiero po analizie — inaczej zapis handlowca i wynik analizy nadpisałyby się nawzajem. */
    private function assertAnalyzed(ClientInquiry $inquiry): void
    {
        $status = $inquiry->effectiveAnalysisStatus();
        if ($status === ClientInquiry::ANALYSIS_FAILED) {
            abort(409, 'Analiza zapytania nie powiodła się — uruchom ją ponownie.');
        }
        if ($status !== ClientInquiry::ANALYSIS_DONE) {
            abort(409, 'Analiza zapytania jeszcze trwa — poczekaj na wynik.');
        }
    }

    /**
     * Widok zapytania dla zalogowanego: present() plus to, co może on sam — ponowić nieudaną analizę (autor albo
     * `inquiries.reanalyze`) i uruchomić od nowa gotową (`inquiries.reanalyze`; powód blokady, gdy nie wolno).
     *
     * @return array<string, mixed>
     */
    private function presentFor(Request $request, ClientInquiry $inquiry): array
    {
        $user = $request->user();
        $view = $this->inquiries->present($inquiry, $this->viewer($request));
        $owner = (int) $inquiry->user_id === (int) $user->id;
        $mayReanalyze = $user->can('inquiries.reanalyze') && ($owner || $user->can('inquiries.view_others'));
        $view['can_retry_analysis'] = ($view['can_retry_analysis'] ?? false) && ($owner || $mayReanalyze);
        $done = $inquiry->isAnalyzed();
        $view['can_reanalyze'] = $mayReanalyze && $done;
        $view['reanalyze_blocked'] = $mayReanalyze && $done ? $this->inquiries->reanalysisBlocker($inquiry) : null;

        return $view;
    }

    /** Widok ceny specjalnej B2B zalogowanego (prices.supplier_special.view) — ceny kandydatów, oferty i listu. */
    private function viewer(Request $request): SupplierSpecialMask
    {
        return SupplierSpecialMask::forUser($request->user());
    }

    private function assertOwner(Request $request, ClientInquiry $inquiry): void
    {
        if ((int) $inquiry->user_id !== (int) $request->user()->id) {
            abort(403, 'Brak dostępu do tego zapytania.');
        }
    }
}
