<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Client;
use App\Models\ProcurementNotice;
use App\Models\ProcurementNoticeSkip;
use App\Services\Bzp\EzamowieniaDocuments;
use App\Services\Bzp\NoticeBhpLots;
use App\Services\Bzp\NoticeClientAmbiguousException;
use App\Services\Bzp\NoticeItemsBusyException;
use App\Services\Bzp\NoticeItemsReader;
use App\Services\Bzp\NoticeListQuery;
use App\Services\Bzp\NoticeSections;
use App\Services\Bzp\NoticeTenderCreator;
use App\Services\TenderAccessService;
use Illuminate\Contracts\Cache\LockTimeoutException;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use RuntimeException;

/**
 * Zakładka „Ogłoszenia”: lista ogłoszeń o zamówieniu z Biuletynu (NoticeListQuery), wspólna decyzja „pominięte”
 * i zakładanie przetargu z ogłoszenia (NoticeTenderCreator). Uprawnienia w trasach: lista i pomijanie —
 * tenders.create albo tenders.view_all; zakładanie przetargu — tenders.create.
 * „Pominięte” dotyczy postępowania (bzp_number), nie wersji ogłoszenia — nowa wersja nie wraca do „Nowe”.
 */
class NoticeController extends Controller
{
    public function __construct(
        private readonly NoticeListQuery $list,
        private readonly NoticeTenderCreator $creator,
        private readonly TenderAccessService $access,
        private readonly NoticeSections $sections,
        private readonly EzamowieniaDocuments $documents,
        private readonly NoticeBhpLots $bhpLots,
    ) {}

    /**
     * Szczegóły ogłoszenia (okno w zakładce Ogłoszenia): wiersz listy, fragmenty treści słowo w słowo (NoticeSections),
     * części z odczytu ogłoszenia (parsed.lots) i dokumenty postępowania (EzamowieniaDocuments). Pełna treść ogłoszeń
     * bez przetargu jest kasowana po bzp.html_retention_days dniach — wtedy sections = [], html_available = false
     * i zostają części zapisane przy pobraniu.
     */
    public function show(Request $request, ProcurementNotice $notice): JsonResponse
    {
        $this->ensureContractNotice($notice);
        $html = $notice->getRawOriginal('html_body');
        $htmlAvailable = is_string($html) && trim($html) !== '';
        $parsed = is_array($notice->parsed) ? $notice->parsed : [];

        $lots = [];
        foreach (is_array($parsed['lots'] ?? null) ? $parsed['lots'] : [] as $lot) {
            if (! is_array($lot)) {
                continue;
            }
            // czy część ma towary BHP (wnioskowanie z kodów CPV i opisu — powód w bhp_reason)
            $bhp = $this->bhpLots->forLot($lot);
            $lots[] = [
                'lot_no' => (int) ($lot['lot_no'] ?? 0),
                'name' => is_string($lot['name'] ?? null) ? $lot['name'] : null,
                'description' => is_string($lot['description'] ?? null) ? $lot['description'] : null,
                'cpv_main' => is_string($lot['cpv_main'] ?? null) ? $lot['cpv_main'] : null,
                'cpv_main_name' => is_string($lot['cpv_main_name'] ?? null) ? $lot['cpv_main_name'] : null,
                // jak total_value wiersza: „126 019,26 PLN”, bez waluty w ogłoszeniu — sama liczba
                'estimated_value' => NoticeListQuery::formatAmount($lot['estimated_value'] ?? null),
                'bhp' => $bhp['bhp'],
                'bhp_reason' => $bhp['reason'],
            ];
        }

        return response()->json([
            'row' => $this->list->row($notice, $request->user()),
            'sections' => $htmlAvailable ? $this->sections->extract($html) : [],
            'lots' => $lots,
            'documents' => $this->documents->forNotice($notice),
            'html_available' => $htmlAvailable,
            // „Załóż przetarg z pozycjami” / strefa plików: zakładanie (tenders.create) i odczyt dokumentów (tenders.import)
            'can_import_documents' => $request->user()->can('tenders.create') && $request->user()->can('tenders.import'),
            'html_note' => $htmlAvailable ? null : 'Pełnej treści tego ogłoszenia już nie przechowujemy (usuwana po '
                .(int) config('bzp.html_retention_days', 30).' dniach, gdy z ogłoszenia nie założono przetargu). '
                .'Poniżej części zamówienia odczytane przy pobraniu ogłoszenia; całość jest na stronie ogłoszenia w Biuletynie.',
        ]);
    }

    /**
     * Podsumowanie asortymentu z treści ogłoszenia (szczegóły ogłoszenia, NoticeItemsReader::readCached): towary
     * z ilościami i cechami przepisanymi z ogłoszenia. Uprawnienia jak show; model uruchamia tylko tenders.create
     * (także „Odczytaj ponownie”, ?refresh=1) — tenders.view_all bez create dostaje wynik zapamiętany albo items: null
     * z wyjaśnieniem. ?cached_only=1 — tylko wynik zapamiętany (bez modelu, bez blokad; panel pyta tak od razu po
     * otwarciu, a model dopiero, gdy panel zostaje otwarty). 422 — model nie odpowiedział albo ogłoszenie bez opisu
     * przedmiotu (reason: no_description — stan trwały, ponowienie nic nie da); 503 — model zajęty.
     */
    public function items(Request $request, ProcurementNotice $notice, NoticeItemsReader $reader): JsonResponse
    {
        $this->ensureContractNotice($notice);
        $canRunModel = $request->user()->can('tenders.create');
        $refresh = $request->boolean('refresh');
        abort_if($refresh && ! $canRunModel, 403, 'Ponowny odczyt asortymentu wymaga uprawnienia do zakładania przetargów.');
        $cachedOnly = $request->boolean('cached_only') && ! $refresh;

        // odczyt z modelem w jednym żądaniu
        @set_time_limit(180);

        try {
            $result = $reader->readCached($notice, $refresh, $canRunModel && ! $cachedOnly);
        } catch (NoticeItemsBusyException $e) {
            return response()->json(['message' => $e->getMessage()], 503);
        } catch (RuntimeException $e) {
            return response()->json([
                'message' => $e->getMessage(),
                'reason' => $e->getCode() === NoticeItemsReader::NO_DESCRIPTION ? 'no_description' : null,
            ], 422);
        }

        if ($result === null) {
            return response()->json([
                'items' => null,
                'lots' => [],
                'source' => null,
                'read_at' => null,
                'cached' => false,
                'note' => $canRunModel ? null : 'Asortymentu z tego ogłoszenia nikt jeszcze nie odczytał. Odczyt uruchamia osoba z uprawnieniem do zakładania przetargów.',
            ]);
        }

        return response()->json([
            'items' => $result['items'],
            'lots' => $result['lots'],
            'source' => $result['source'],
            'read_at' => $result['read_at'],
            'cached' => $result['cached'],
            'note' => null,
        ]);
    }

    public function index(Request $request): JsonResponse
    {
        $data = $request->validate([
            'tab' => ['nullable', Rule::in(NoticeListQuery::TABS)],
            'category' => ['nullable', 'string', Rule::in(array_keys((array) config('bzp.cpv_categories', [])))],
            'province' => ['nullable', 'string', 'max:10'],
            'q' => ['nullable', 'string', 'max:200'],
            'past' => ['nullable', 'boolean'],
            'page' => ['nullable', 'integer', 'min:1'],
            'source' => ['nullable', Rule::in(NoticeListQuery::SOURCES)],
        ]);

        return response()->json($this->list->list($request->user(), [
            'tab' => $data['tab'] ?? null,
            'category' => $data['category'] ?? null,
            'province' => $data['province'] ?? null,
            'q' => $data['q'] ?? null,
            'past' => filter_var($data['past'] ?? false, FILTER_VALIDATE_BOOLEAN),
            'page' => (int) ($data['page'] ?? 1),
            'source' => $data['source'] ?? null,
        ]));
    }

    public function skip(Request $request, ProcurementNotice $notice): JsonResponse
    {
        $this->ensureContractNotice($notice);

        if (! ProcurementNoticeSkip::query()->where('bzp_number', $notice->bzp_number)->exists()) {
            try {
                ProcurementNoticeSkip::query()->create([
                    'bzp_number' => $notice->bzp_number,
                    'procurement_notice_id' => $notice->id,
                    'user_id' => $request->user()->id,
                ]);
            } catch (UniqueConstraintViolationException) {
                // ktoś pominął to postępowanie w tej samej chwili — decyzja już jest
            }
        }

        return response()->json($this->list->row($notice, $request->user()));
    }

    public function unskip(Request $request, ProcurementNotice $notice): JsonResponse
    {
        $this->ensureContractNotice($notice);

        ProcurementNoticeSkip::query()->where('bzp_number', $notice->bzp_number)->delete();

        return response()->json($this->list->row($notice, $request->user()));
    }

    /**
     * Body: client_id (opcjonalnie) — zamawiający wybrany przez człowieka, dowolny istniejący klient. Bez niego przy
     * kilku pasujących klientach — 422 z listą client_candidates (nic nie powstaje).
     * document_ids (opcjonalnie) — dokumenty postępowania z platformy e-Zamówienia wybrane w oknie szczegółów
     * (id z documents.items). Serwer sprawdza tylko, że należą do tego postępowania, i oddaje je w odpowiedzi 201;
     * pobranie i odczyt robi kreator przetargu po jednym pliku przez POST /tenders/{tender}/documents/from-notice
     * (odczyt z modelem trwa jak przy wgraniu pliku — kilka plików w jednym żądaniu przekroczyłoby limit czasu).
     */
    public function createTender(Request $request, ProcurementNotice $notice): JsonResponse
    {
        $this->ensureContractNotice($notice);
        $data = $request->validate([
            'client_id' => ['nullable', 'integer', 'exists:clients,id'],
            'document_ids' => ['sometimes', 'array', 'max:30'],
            'document_ids.*' => ['string', 'max:191', 'regex:/^ocds-[A-Za-z0-9-]+_\d+$/'],
        ]);
        $documentIds = array_values(array_unique(array_map('strval', $data['document_ids'] ?? [])));
        if ($documentIds !== []) {
            // odczyt dokumentów w kreatorze (analyze, from-notice) wymaga tenders.import — bez niego nic nie powstaje
            abort_unless($request->user()->can('tenders.import'), 403, 'Dodawanie dokumentów do przetargu wymaga uprawnienia „Dodawanie dokumentów”.');
            $ocds = (string) $notice->ocds_id;
            $foreign = array_filter($documentIds, static fn (string $id): bool => ! str_starts_with($id, $ocds.'_'));
            if (! EzamowieniaDocuments::isEzamowienia($notice) || $foreign !== []) {
                $message = 'Dokumenty można pobrać automatycznie tylko z listy dokumentów tego postępowania na platformie e-Zamówienia.';

                return response()->json(['message' => $message, 'errors' => ['document_ids' => [$message]]], 422);
            }
        }
        $chosen = isset($data['client_id']) ? Client::query()->findOrFail((int) $data['client_id']) : null;

        try {
            $result = $this->creator->create($notice, $request->user(), $chosen);
        } catch (NoticeClientAmbiguousException $e) {
            return response()->json([
                'message' => $e->getMessage(),
                'errors' => ['client_id' => [$e->getMessage()]],
                'client_candidates' => $e->candidates,
            ], 422);
        } catch (LockTimeoutException) {
            return response()->json([
                'message' => 'Ktoś inny zakłada w tej chwili przetarg z ogłoszenia. Spróbuj ponownie za kilka sekund.',
            ], 423);
        }
        if (! $result['created']) {
            return response()->json([
                'message' => 'Przetarg z tym postępowaniem już jest ('.$result['tender']->number.').',
                'tender_id' => (int) $result['tender']->id,
                'tender_number' => (string) $result['tender']->number,
                // bez dostępu do przetargu front pokazuje sam numer zamiast przejścia
                'can_open' => $this->access->canView($request->user(), $result['tender']),
            ], 409);
        }

        return response()->json([
            'tender_id' => (int) $result['tender']->id,
            // do pobrania w kreatorze (from-notice), po jednym; [] = bez dokumentów z e-Zamówień
            'document_ids' => $documentIds,
        ], 201);
    }

    /** Lista i akcje dotyczą tylko ogłoszeń o zamówieniu (ogłoszenie o wyniku → 404). */
    private function ensureContractNotice(ProcurementNotice $notice): void
    {
        abort_if($notice->notice_type !== ProcurementNotice::TYPE_CONTRACT, 404, 'To nie jest ogłoszenie o zamówieniu.');
    }
}
