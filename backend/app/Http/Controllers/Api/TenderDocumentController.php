<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\ProcurementNotice;
use App\Models\Tender;
use App\Models\TenderDocument;
use App\Services\Bzp\EzamowieniaDocuments;
use App\Services\Bzp\NoticeItemsBusyException;
use App\Services\Bzp\NoticeItemsReader;
use App\Services\Pricing\SupplierSpecialMask;
use App\Services\TenderActivityLogger;
use App\Services\TenderDocumentImportService;
use App\Services\TenderWorkflowService;
use App\Support\NoticeNumber;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use RuntimeException;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

class TenderDocumentController extends Controller
{
    public function __construct(
        private readonly TenderDocumentImportService $import,
        private readonly TenderWorkflowService $workflow,
        private readonly EzamowieniaDocuments $ezamowienia,
        private readonly TenderActivityLogger $activities,
    ) {}

    public function index(Tender $tender): JsonResponse
    {
        $docs = $tender->documents()
            ->with('uploader:id,name')
            ->get(['id', 'tender_id', 'uploaded_by', 'original_name', 'extension', 'source', 'source_url', 'size_bytes', 'mode', 'targets', 'disk_path', 'created_at']);

        return response()->json([
            'data' => $docs->map(static function (TenderDocument $d) {
                return [
                    'id' => $d->id,
                    'original_name' => $d->original_name,
                    'extension' => $d->extension,
                    'size_bytes' => $d->size_bytes,
                    'mode' => $d->mode,
                    'targets' => $d->targets,
                    'has_file' => $d->disk_path !== null,
                    // null = wgrany ręcznie; 'ezamowienia' = pobrany z platformy e-Zamówienia (source_url — skąd)
                    'source' => $d->source,
                    'source_url' => $d->source_url,
                    'created_at' => $d->created_at,
                    'uploader' => $d->uploader,
                ];
            }),
        ]);
    }

    public function analyze(Request $request, Tender $tender): JsonResponse
    {
        $this->assertEditable($tender);

        $data = $request->validate([
            'file' => ['required', 'file', 'max:51200'],
            'mode' => ['required', 'in:simple,ai,full'],
            'targets' => ['required'],
        ]);

        $targets = $this->parseTargets($data['targets']);
        $file = $request->file('file');
        $ext = mb_strtolower($file->getClientOriginalExtension() ?: '');
        if (! in_array($ext, ['pdf', 'xlsx', 'xls', 'csv', 'doc', 'docx'], true)) {
            throw ValidationException::withMessages([
                'file' => ['Dodaj plik PDF, Excel albo Word.'],
            ]);
        }

        try {
            $result = $this->import->analyzeUpload($tender, $file, $data['mode'], $targets, $request->user());
        } catch (RuntimeException $e) {
            throw ValidationException::withMessages(['file' => [$e->getMessage()]]);
        }
        $proposal = $this->import->proposeNoticeItemReplacements($tender, $result['items']);

        // historia: skąd pochodzi plik — tylko gdy plik trafił do archiwum (sam odczyt bez zapisu nie dodaje dokumentu;
        // pozycje i warunki trafiają do przetargu dopiero przy „commit”)
        if ($result['document'] !== null) {
            $name = (string) $file->getClientOriginalName();
            $this->activities->log($tender, 'document_added', $request->user(), null, [
                'source' => 'upload',
                'file_name' => $name,
                'document_id' => $result['document']->id,
                'note' => 'Dodano ręcznie plik „'.$name.'” do odczytu pozycji i warunków.',
            ]);
        }

        return response()->json([
            'document_id' => $result['document']?->id,
            'mode' => $result['mode'],
            'targets' => $result['targets'],
            'extracted_text' => $result['extracted_text'],
            'mapping_notes' => $result['mapping_notes'] ?? null,
            // replaces_item_id / replaces_options — propozycje uzupełnienia pozycji z treści ogłoszenia (notice_items)
            'items' => $proposal['items'],
            'notice_items' => $proposal['notice_items'],
            'conditions' => $result['conditions'],
            'items_count' => count($result['items']),
            'conditions_count' => count($result['conditions']),
        ]);
    }

    /**
     * Plik z platformy e-Zamówienia do odczytu w kreatorze — ta sama ścieżka co wgranie pliku (analyze): plik trafia do
     * TenderDocumentImportService::analyzeUpload i wraca podgląd pozycji i warunków; do przetargu zapisuje je dopiero
     * człowiek przez „commit”. Jeden plik na żądanie (odczyt z modelem trwa jak przy wgraniu).
     * Body: notice_document_id (objectId z listy dokumentów w szczegółach ogłoszenia), mode (simple|ai|full, domyślnie
     * ai), targets (items, conditions — domyślnie oba). Ogłoszenie: powiązane z przetargiem (contract_notice_id) albo
     * najnowsza wersja ogłoszenia o numerze z przetargu. Plik zawsze zostaje zapisany z pochodzeniem (source,
     * source_ref, source_url); drugi raz ten sam dokument → 409 z document_id istniejącego pliku (podgląd: GET show).
     */
    public function fromNotice(Request $request, Tender $tender): JsonResponse
    {
        $this->assertEditable($tender);

        $data = $request->validate([
            'notice_document_id' => ['required', 'string', 'max:191', 'regex:/^ocds-[A-Za-z0-9-]+_\d+$/'],
            'mode' => ['sometimes', 'in:simple,ai,full'],
            'targets' => ['sometimes'],
        ]);
        $documentId = (string) $data['notice_document_id'];
        $targets = isset($data['targets']) ? $this->parseTargets($data['targets']) : ['items', 'conditions'];

        $notice = $this->noticeFor($tender);
        if ($notice === null) {
            throw ValidationException::withMessages([
                'notice_document_id' => ['Ten przetarg nie jest połączony z ogłoszeniem z Biuletynu Zamówień Publicznych — dodaj pliki ręcznie.'],
            ]);
        }

        $existing = TenderDocument::query()
            ->where('tender_id', $tender->id)
            ->where('source', EzamowieniaDocuments::SOURCE)
            ->where('source_ref', $documentId)
            ->first(['id', 'original_name']);
        if ($existing !== null) {
            return response()->json([
                'message' => 'Plik „'.$existing->original_name.'” jest już w tym przetargu — otwórz jego podgląd na liście dokumentów.',
                'document_id' => (int) $existing->id,
            ], 409);
        }

        // pobranie pliku i odczyt z modelem w jednym żądaniu
        @set_time_limit(300);

        try {
            $download = $this->ezamowienia->download($notice, $documentId);
        } catch (RuntimeException $e) {
            throw ValidationException::withMessages(['notice_document_id' => [$e->getMessage()]]);
        }

        try {
            $file = new UploadedFile($download['path'], $download['file_name'], null, null, true);
            $result = $this->import->analyzeUpload($tender, $file, $data['mode'] ?? 'ai', $targets, $request->user(), [
                'source' => EzamowieniaDocuments::SOURCE,
                'source_ref' => $download['id'],
                'source_url' => $download['url'],
            ]);
        } catch (RuntimeException $e) {
            throw ValidationException::withMessages(['notice_document_id' => [$e->getMessage()]]);
        } finally {
            @unlink($download['path']);
        }
        $proposal = $this->import->proposeNoticeItemReplacements($tender, $result['items']);

        $this->activities->log($tender, 'document_added', $request->user(), null, [
            'source' => EzamowieniaDocuments::SOURCE,
            'file_name' => $download['file_name'],
            'document_id' => $result['document']?->id,
            'notice_document_id' => $download['id'],
            'url' => $download['url'],
            'note' => 'Pobrano z platformy e-Zamówienia plik „'.$download['file_name'].'” („'.$download['name'].'”) do odczytu pozycji i warunków.',
        ]);

        return response()->json([
            'document_id' => $result['document']?->id,
            'mode' => $result['mode'],
            'targets' => $result['targets'],
            'extracted_text' => $result['extracted_text'],
            'mapping_notes' => $result['mapping_notes'] ?? null,
            'items' => $proposal['items'],
            'notice_items' => $proposal['notice_items'],
            'conditions' => $result['conditions'],
            'items_count' => count($result['items']),
            'conditions_count' => count($result['conditions']),
            'source' => [
                'platform' => EzamowieniaDocuments::SOURCE,
                'notice_document_id' => $download['id'],
                'name' => $download['name'],
                'file_name' => $download['file_name'],
                'size_bytes' => $download['size'],
            ],
        ]);
    }

    /**
     * Pozycje z TREŚCI ogłoszenia przetargu (opisy części, sekcja „Przedmiot zamówienia”) — bez pliku, dla postępowań,
     * w których towary i ilości są w samym ogłoszeniu. Zwraca podgląd w kształcie odczytu dokumentu (document_id null).
     * Towary spoza BHP przychodzą odznaczone, pozycje bez cytatu w ogłoszeniu — odznaczone, ilość bez pokrycia w cytacie
     * — 1 z quantity_missing (do uzupełnienia).
     * auto_add (decyzja właściciela 03.10.2026: „pozycje BHP niech wpadają same”): gdy przetarg nie ma jeszcze pozycji,
     * od razu zapisuje towary BHP z cytatem znalezionym w ogłoszeniu i ilością z cytatu (added: true); reszta zostaje
     * w podglądzie do decyzji człowieka. Historia przetargu notuje każdy zapisany towar z cytatem z ogłoszenia.
     * Wymaganie pozycji = nazwa + „ — ” + cechy przepisane z ogłoszenia (spec, sprawdzone w tekście); zapisane pozycje
     * mają pochodzenie 'notice_text' (numer ogłoszenia) — dokument postępowania może je potem uzupełnić. Odczyt idzie
     * przez pamięć podręczną (NoticeItemsReader::readCached, refresh=1 — od nowa); zajęty model — 503.
     */
    public function fromNoticeText(Request $request, Tender $tender, NoticeItemsReader $reader): JsonResponse
    {
        $this->assertEditable($tender);
        $autoAdd = $request->boolean('auto_add');

        $notice = $this->noticeFor($tender);
        if ($notice === null) {
            throw ValidationException::withMessages([
                'notice' => ['Ten przetarg nie jest połączony z ogłoszeniem z Biuletynu Zamówień Publicznych — wpisz numer ogłoszenia albo dodaj dokumenty.'],
            ]);
        }

        // model uruchamia tylko osoba, która może zakładać przetargi (jak w szczegółach ogłoszenia) — inni dostają
        // zapamiętany wynik albo komunikat
        $mayRunModel = (bool) $request->user()?->can('tenders.create');
        $refresh = $request->boolean('refresh');
        abort_if($refresh && ! $mayRunModel, 403, 'Ponowny odczyt towarów z ogłoszenia wymaga uprawnienia do zakładania przetargów.');

        // odczyt z modelem w jednym żądaniu (albo gotowy wynik z pamięci podręcznej — np. po otwarciu szczegółów ogłoszenia)
        @set_time_limit(180);

        try {
            $result = $reader->readCached($notice, $refresh, $mayRunModel);
        } catch (NoticeItemsBusyException $e) {
            return response()->json(['message' => $e->getMessage()], 503);
        } catch (RuntimeException $e) {
            throw ValidationException::withMessages(['notice' => [$e->getMessage()]]);
        }
        if ($result === null) {
            throw ValidationException::withMessages(['notice' => [
                'Towarów z tego ogłoszenia nikt jeszcze nie odczytał. Odczyt modelem uruchamia osoba z uprawnieniem do zakładania przetargów (np. otwierając szczegóły ogłoszenia).',
            ]]);
        }

        $items = array_map(static function (array $row): array {
            $spec = isset($row['spec']) && is_string($row['spec']) && trim($row['spec']) !== '' ? trim($row['spec']) : null;

            return [
                'sku' => null,
                'name' => $row['name'],
                // wymaganie = nazwa + cechy przepisane z ogłoszenia (sprawdzone w tekście) — wyszukiwanie produktu ma
                // czym się kierować; kolumna wymagania w pozycji przyjmuje do 2000 znaków
                'requirement' => mb_substr($row['name'].($spec !== null ? ' — '.$spec : ''), 0, 2000),
                'spec' => $spec,
                'quantity' => $row['quantity'] ?? 1,
                'offer_price' => null,
                'currency' => null,
                'norms' => null,
                'description' => null,
                'selected' => $row['bhp'] && $row['quote_found'],
                'lot_no' => $row['lot_no'],
                'unit' => $row['unit'],
                'quantity_missing' => $row['quantity'] === null,
                'bhp' => $row['bhp'],
                'quote' => $row['quote'],
                'quote_found' => $row['quote_found'],
                'added' => false,
            ];
        }, $result['items']);

        $added = 0;
        $autoAddNote = null;
        if ($autoAdd) {
            $toAdd = array_keys(array_filter(
                $items,
                static fn (array $item): bool => $item['bhp'] && $item['quote_found'] && ! $item['quantity_missing'],
            ));
            if ($toAdd !== []) {
                // drugi odczyt (druga karta, ponowne wejście) nie dubluje pozycji: zapis tylko do pustego przetargu
                $added = DB::transaction(function () use ($tender, $items, $toAdd, $request, $notice): int {
                    $locked = Tender::query()->lockForUpdate()->findOrFail($tender->id);
                    if ($locked->items()->exists()) {
                        return 0;
                    }
                    $rows = array_map(static fn (int $i): array => $items[$i], $toAdd);

                    // pochodzenie pozycji: treść ogłoszenia — dokument postępowania może je później uzupełnić
                    return $this->import->commit(
                        $locked,
                        $rows,
                        [],
                        false,
                        false,
                        null,
                        SupplierSpecialMask::forUser($request->user()),
                        'notice',
                        itemSource: TenderDocumentImportService::ITEM_SOURCE_NOTICE,
                        itemSourceRef: (string) $notice->notice_number,
                    )['items_created'];
                });
                if ($added > 0) {
                    foreach ($toAdd as $i) {
                        $items[$i]['added'] = true;
                        $items[$i]['selected'] = false;
                    }
                    $lines = array_map(static function (int $i) use ($items): string {
                        $item = $items[$i];

                        return '• '.$item['name'].' — '.$item['quantity'].($item['unit'] !== null ? ' '.$item['unit'] : '')
                            .($item['lot_no'] !== null ? ' (część '.$item['lot_no'].')' : '').' — z ogłoszenia: „'.$item['quote'].'”'
                            .($item['spec'] !== null ? '; cechy z ogłoszenia: '.$item['spec'] : '');
                    }, $toAdd);
                    $this->activities->log($tender, 'items_from_notice', $request->user(), null, [
                        'source' => 'notice_text',
                        'notice_id' => $notice->id,
                        'notice_number' => $notice->notice_number,
                        'items' => array_map(static fn (int $i): array => [
                            'name' => $items[$i]['name'],
                            'quantity' => $items[$i]['quantity'],
                            'unit' => $items[$i]['unit'],
                            'lot_no' => $items[$i]['lot_no'],
                            'quote' => $items[$i]['quote'],
                            'spec' => $items[$i]['spec'],
                        ], $toAdd),
                        'note' => 'Dodano automatycznie '.$added.' '.match (true) {
                            $added === 1 => 'towar BHP',
                            $added % 10 >= 2 && $added % 10 <= 4 && ($added % 100 < 12 || $added % 100 > 14) => 'towary BHP',
                            default => 'towarów BHP',
                        }
                            .' z treści ogłoszenia '.$notice->notice_number." (odczyt modelem, cytaty sprawdzone w ogłoszeniu):\n"
                            .implode("\n", $lines),
                    ]);
                } else {
                    $autoAddNote = 'Przetarg ma już pozycje — towary z ogłoszenia są tylko w podglądzie.';
                }
            }
        }

        return response()->json([
            'added_count' => $added,
            'auto_add_note' => $autoAddNote,
            'document_id' => null,
            'mode' => 'ai',
            'targets' => ['items'],
            'extracted_text' => $reader->sourceText($notice),
            'mapping_notes' => 'Pozycje odczytane modelem z treści ogłoszenia '.$notice->notice_number.'.',
            'items' => $items,
            'conditions' => [],
            'items_count' => count($items),
            'conditions_count' => 0,
            'source' => [
                'notice_id' => $notice->id,
                'notice_number' => $notice->notice_number,
            ],
        ]);
    }

    public function reanalyze(Request $request, Tender $tender, TenderDocument $document): JsonResponse
    {
        $this->assertEditable($tender);
        $this->assertOwns($tender, $document);

        $data = $request->validate([
            'mode' => ['sometimes', 'in:simple,ai,full'],
            'targets' => ['sometimes'],
        ]);

        $targets = isset($data['targets'])
            ? $this->parseTargets($data['targets'])
            : (is_array($document->targets) ? $document->targets : ['items', 'conditions']);

        try {
            $result = $this->import->reanalyze(
                $document,
                $data['mode'] ?? (string) $document->mode,
                $targets,
            );
        } catch (RuntimeException $e) {
            throw ValidationException::withMessages(['document' => [$e->getMessage()]]);
        }
        $proposal = $this->import->proposeNoticeItemReplacements($tender, $result['items']);

        return response()->json([
            'document_id' => $result['document']->id,
            'mode' => $result['mode'],
            'targets' => $result['targets'],
            'extracted_text' => $result['extracted_text'],
            'items' => $proposal['items'],
            'notice_items' => $proposal['notice_items'],
            'conditions' => $result['conditions'],
            'items_count' => count($result['items']),
            'conditions_count' => count($result['conditions']),
        ]);
    }

    public function commit(Request $request, Tender $tender): JsonResponse
    {
        $this->assertEditable($tender);

        $data = $request->validate([
            'document_id' => ['nullable', 'integer', 'exists:tender_documents,id'],
            'replace_items' => ['sometimes', 'boolean'],
            'replace_conditions' => ['sometimes', 'boolean'],
            'items' => ['sometimes', 'array'],
            'items.*.requirement' => ['nullable', 'string', 'max:5000'],
            'items.*.name' => ['nullable', 'string', 'max:5000'],
            'items.*.sku' => ['nullable', 'string', 'max:128'],
            'items.*.quantity' => ['sometimes', 'integer', 'min:1'],
            'items.*.offer_price' => ['nullable', 'numeric'],
            'items.*.currency' => ['nullable', 'string', 'max:8'],
            // ilość nie podana w dokumencie — uzupełniana pozycja z ogłoszenia zachowuje swoją
            'items.*.quantity_missing' => ['sometimes', 'boolean'],
            // pozycja z treści ogłoszenia, którą ta pozycja z dokumentu uzupełnia (zamiast nowej)
            'items.*.replaces_item_id' => ['nullable', 'integer'],
            // część zamówienia (podgląd z treści ogłoszenia) — zapisywana w pochodzeniu pozycji
            'items.*.lot_no' => ['nullable', 'integer', 'min:1'],
            // pozycje z ogłoszenia do usunięcia — dokument rozpisuje je na kilka pozycji (wybór człowieka)
            'remove_item_ids' => ['sometimes', 'array', 'max:800'],
            'remove_item_ids.*' => ['integer'],
            // pochodzenie zapisywanych pozycji: dokument (domyślnie) albo podgląd z treści ogłoszenia
            'origin' => ['sometimes', 'in:document,notice_text'],
            'conditions' => ['sometimes', 'array'],
            'conditions.*.content' => ['required_with:conditions', 'string', 'max:5000'],
            'conditions.*.category' => ['nullable', 'string', 'max:64'],
            'simple_text' => ['sometimes', 'nullable', 'string', 'max:200000'],
            'simple_as' => ['sometimes', 'in:items,conditions,both'],
        ]);

        $items = $data['items'] ?? [];
        $conditions = $data['conditions'] ?? [];
        $removeIds = array_values(array_map('intval', $data['remove_item_ids'] ?? []));
        $simple = false;

        // tryb prosty: tekst → linie → pozycje/warunki
        if (($data['simple_text'] ?? '') !== '' && $items === [] && $conditions === []) {
            $simple = true;
            $as = $data['simple_as'] ?? 'both';
            $lines = preg_split('/\R/u', (string) $data['simple_text']) ?: [];
            foreach ($lines as $line) {
                $line = trim($line);
                if ($line === '') {
                    continue;
                }
                if ($as === 'items' || $as === 'both') {
                    $items[] = ['requirement' => $line, 'quantity' => 1];
                }
                if ($as === 'conditions' || $as === 'both') {
                    $conditions[] = ['content' => $line, 'category' => null];
                }
            }
        }

        $items = array_values(array_filter($items, static function (array $row): bool {
            $req = trim((string) ($row['requirement'] ?? ''));
            $name = trim((string) ($row['name'] ?? ''));
            $sku = trim((string) ($row['sku'] ?? ''));

            return $req !== '' || $name !== '' || $sku !== '';
        }));

        if ($items === [] && $conditions === [] && $removeIds === []) {
            throw ValidationException::withMessages([
                'items' => ['Nie zaznaczono żadnej pozycji ani warunku do zapisania.'],
            ]);
        }

        if (isset($data['document_id'])) {
            $doc = TenderDocument::query()->findOrFail($data['document_id']);
            $this->assertOwns($tender, $doc);
        }
        $documentId = isset($data['document_id']) ? (int) $data['document_id'] : null;

        // pochodzenie pozycji: tekst wklejony w trybie prostym — bez (jak ręcznie); podgląd z treści ogłoszenia —
        // ogłoszenie (numer); dokument — 'document' (tylko on uzupełnia i usuwa pozycje z ogłoszenia)
        $itemSource = null;
        $itemSourceRef = null;
        if (! $simple && ($data['origin'] ?? 'document') === TenderDocumentImportService::ITEM_SOURCE_NOTICE) {
            $itemSource = TenderDocumentImportService::ITEM_SOURCE_NOTICE;
            $itemSourceRef = $this->noticeFor($tender)?->notice_number;
        } elseif (! $simple) {
            $itemSource = TenderDocumentImportService::ITEM_SOURCE_DOCUMENT;
            $itemSourceRef = $documentId !== null ? 'dokument #'.$documentId : null;
        }

        $result = $this->import->commit(
            $tender,
            $items,
            $conditions,
            $request->boolean('replace_items', false),
            $request->boolean('replace_conditions', false),
            $documentId,
            // oferta z karty w cenach osoby, która zatwierdza import
            SupplierSpecialMask::forUser($request->user()),
            'document',
            $removeIds,
            $itemSource,
            $itemSourceRef,
        );

        $this->logNoticeItemChanges($tender, $request, $documentId, $result['updated'], $result['removed']);

        return response()->json([
            'items_created' => $result['items_created'],
            'items_updated' => $result['items_updated'],
            'items_removed' => $result['items_removed'],
            'conditions_created' => $result['conditions_created'],
        ]);
    }

    /**
     * Historia: pozycje z ogłoszenia uzupełnione dokumentem (było → jest, zdjęty albo zostawiony produkt) i usunięte,
     * bo dokument rozpisuje je na kilka pozycji.
     *
     * @param  list<array<string, mixed>>  $updated
     * @param  list<array<string, mixed>>  $removed
     */
    private function logNoticeItemChanges(Tender $tender, Request $request, ?int $documentId, array $updated, array $removed): void
    {
        $short = static fn (string $text): string => mb_strlen($text) > 160 ? mb_substr($text, 0, 160).'…' : $text;
        $positions = static fn (int $n): string => $n.' '.match (true) {
            $n === 1 => 'pozycję',
            $n % 10 >= 2 && $n % 10 <= 4 && ($n % 100 < 12 || $n % 100 > 14) => 'pozycje',
            default => 'pozycji',
        };
        if ($updated !== []) {
            $lines = array_map(static function (array $u) use ($short): string {
                $line = '• pozycja '.$u['line_no'].': „'.$short((string) $u['before']['requirement']).'” → „'
                    .$short((string) $u['after']['requirement']).'”';
                if ($u['before']['quantity'] !== $u['after']['quantity']) {
                    $line .= '; ilość '.$u['before']['quantity'].' → '.$u['after']['quantity'];
                }
                if ($u['removed_product'] !== null) {
                    $line .= '; zdjęto produkt „'.$u['removed_product'].'” — do ponownego doboru';
                }
                if ($u['kept_product'] !== null) {
                    $line .= '; zostaje ręcznie wybrany produkt „'.$u['kept_product'].'” — sprawdź';
                }

                return $line;
            }, $updated);
            $this->activities->log($tender, 'items_updated_from_document', $request->user(), null, [
                'document_id' => $documentId,
                'items' => $updated,
                'note' => 'Dokument uzupełnił '.$positions(count($updated))
                    ." z ogłoszenia (opis i ilość z dokumentu):\n".implode("\n", $lines),
            ]);
        }
        if ($removed !== []) {
            $lines = array_map(static fn (array $r): string => '• pozycja '.$r['line_no'].': „'.$short((string) $r['requirement']).'”, ilość '
                .$r['quantity'].($r['product'] !== null ? ', produkt „'.$r['product'].'”' : ''), $removed);
            $this->activities->log($tender, 'items_removed_by_document', $request->user(), null, [
                'document_id' => $documentId,
                'items' => $removed,
                'note' => 'Usunięto '.$positions(count($removed))
                    ." z ogłoszenia — dokument rozpisuje je na kilka pozycji:\n".implode("\n", $lines),
            ]);
        }
    }

    public function destroy(Tender $tender, TenderDocument $document): JsonResponse
    {
        $this->assertEditable($tender);
        $this->assertOwns($tender, $document);
        $this->import->deleteDocument($document);

        return response()->json(['ok' => true]);
    }

    public function download(Tender $tender, TenderDocument $document): BinaryFileResponse
    {
        $this->assertOwns($tender, $document);
        $diskPath = (string) ($document->disk_path ?? '');
        if ($diskPath === '' || ! Storage::disk('local')->exists($diskPath)) {
            throw ValidationException::withMessages([
                'document' => ['Ten dokument nie ma zapisanego pliku do pobrania.'],
            ]);
        }

        $name = basename((string) $document->original_name);
        if ($name === '' || $name === '.' || $name === '..') {
            $ext = trim((string) $document->extension);
            $name = $ext !== '' ? 'dokument.'.$ext : 'dokument';
        }

        $headers = [];
        $mime = trim((string) ($document->mime ?? ''));
        if ($mime !== '') {
            $headers['Content-Type'] = $mime;
        }

        return response()->download(Storage::disk('local')->path($diskPath), $name, $headers);
    }

    public function show(Tender $tender, TenderDocument $document): JsonResponse
    {
        $this->assertOwns($tender, $document);

        $text = (string) ($document->extracted_text ?? '');
        if (mb_strlen($text) > 12000) {
            $text = mb_substr($text, 0, 12000)."\n\n[… tekst ucięty w podglądzie …]";
        }

        $analysis = $document->analysis_json;
        $noticeItems = [];
        if (is_array($analysis)) {
            if (isset($analysis['items']) && is_array($analysis['items'])) {
                // propozycje uzupełnienia pozycji z ogłoszenia liczone na bieżąco (stan pozycji przetargu teraz)
                $rows = array_values(array_filter(array_slice($analysis['items'], 0, 400), 'is_array'));
                // odczyt zapisany przed oznaczaniem braku ilości: jedynka to zwykle domyślna wartość, nie ilość z
                // dokumentu — nie może nadpisać ilości z ogłoszenia
                $rows = array_map(static function (array $row): array {
                    if (! array_key_exists('quantity_missing', $row)) {
                        $row['quantity_missing'] = ! is_numeric($row['quantity'] ?? null) || (int) $row['quantity'] <= 1;
                    }

                    return $row;
                }, $rows);
                $proposal = $this->import->proposeNoticeItemReplacements($tender, $rows);
                $analysis['items'] = $proposal['items'];
                $noticeItems = $proposal['notice_items'];
            }
            if (isset($analysis['conditions']) && is_array($analysis['conditions'])) {
                $analysis['conditions'] = array_slice($analysis['conditions'], 0, 400);
            }
        }

        return response()->json([
            'id' => $document->id,
            'original_name' => $document->original_name,
            'mode' => $document->mode,
            'targets' => $document->targets,
            'has_file' => $document->disk_path !== null,
            'extracted_text' => $text,
            'analysis_json' => $analysis,
            'notice_items' => $noticeItems,
            'created_at' => $document->created_at,
        ]);
    }

    private function assertEditable(Tender $tender): void
    {
        if (! $this->workflow->canEditOffer($tender)) {
            throw ValidationException::withMessages([
                'tender' => ['Dokumentów nie można już dodawać — przetarg ma status „'.TenderWorkflowService::statusLabel($tender->status).'”.'],
            ]);
        }
    }

    /** Ogłoszenie o zamówieniu przetargu: powiązane (contract_notice_id) albo najnowsza wersja o numerze z przetargu. */
    private function noticeFor(Tender $tender): ?ProcurementNotice
    {
        if ($tender->contract_notice_id !== null) {
            $linked = ProcurementNotice::query()
                ->where('notice_type', ProcurementNotice::TYPE_CONTRACT)
                ->find($tender->contract_notice_id);
            if ($linked !== null) {
                return $linked;
            }
        }
        $bzp = NoticeNumber::parse($tender->notice_number)['bzp_number'] ?? null;
        if ($bzp === null) {
            return null;
        }

        return ProcurementNotice::query()
            ->where('notice_type', ProcurementNotice::TYPE_CONTRACT)
            ->where('bzp_number', $bzp)
            ->orderByDesc('notice_number')
            ->first();
    }

    private function assertOwns(Tender $tender, TenderDocument $document): void
    {
        if ((int) $document->tender_id !== (int) $tender->id) {
            abort(404);
        }
    }

    /**
     * @return list<string>
     */
    private function parseTargets(mixed $raw): array
    {
        if (is_string($raw)) {
            $raw = array_filter(array_map('trim', explode(',', $raw)));
        }
        if (! is_array($raw)) {
            return [];
        }
        $out = [];
        foreach ($raw as $t) {
            $t = (string) $t;
            if (in_array($t, ['items', 'conditions'], true)) {
                $out[] = $t;
            }
        }

        return array_values(array_unique($out));
    }
}
