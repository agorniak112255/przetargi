<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Tender;
use App\Models\TenderDocument;
use App\Services\TenderDocumentImportService;
use App\Services\TenderWorkflowService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use RuntimeException;

class TenderDocumentController extends Controller
{
    public function __construct(
        private readonly TenderDocumentImportService $import,
        private readonly TenderWorkflowService $workflow,
    ) {}

    public function index(Tender $tender): JsonResponse
    {
        $docs = $tender->documents()
            ->with('uploader:id,name')
            ->get(['id', 'tender_id', 'uploaded_by', 'original_name', 'extension', 'size_bytes', 'mode', 'targets', 'disk_path', 'created_at']);

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
                'file' => ['Dozwolone: PDF, Excel (xlsx/xls/csv), Word (doc/docx).'],
            ]);
        }

        try {
            $result = $this->import->analyzeUpload($tender, $file, $data['mode'], $targets, $request->user());
        } catch (RuntimeException $e) {
            throw ValidationException::withMessages(['file' => [$e->getMessage()]]);
        }

        return response()->json([
            'document_id' => $result['document']?->id,
            'mode' => $result['mode'],
            'targets' => $result['targets'],
            'extracted_text' => $result['extracted_text'],
            'mapping_notes' => $result['mapping_notes'] ?? null,
            'items' => $result['items'],
            'conditions' => $result['conditions'],
            'items_count' => count($result['items']),
            'conditions_count' => count($result['conditions']),
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

        return response()->json([
            'document_id' => $result['document']->id,
            'mode' => $result['mode'],
            'targets' => $result['targets'],
            'extracted_text' => $result['extracted_text'],
            'items' => $result['items'],
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
            'conditions' => ['sometimes', 'array'],
            'conditions.*.content' => ['required_with:conditions', 'string', 'max:5000'],
            'conditions.*.category' => ['nullable', 'string', 'max:64'],
            'simple_text' => ['sometimes', 'nullable', 'string', 'max:200000'],
            'simple_as' => ['sometimes', 'in:items,conditions,both'],
        ]);

        $items = $data['items'] ?? [];
        $conditions = $data['conditions'] ?? [];

        // tryb prosty: tekst → linie → pozycje/warunki
        if (($data['simple_text'] ?? '') !== '' && $items === [] && $conditions === []) {
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

        if ($items === [] && $conditions === []) {
            throw ValidationException::withMessages([
                'items' => ['Brak pozycji/warunków do zapisania.'],
            ]);
        }

        if (isset($data['document_id'])) {
            $doc = TenderDocument::query()->findOrFail($data['document_id']);
            $this->assertOwns($tender, $doc);
        }

        $result = $this->import->commit(
            $tender,
            $items,
            $conditions,
            $request->boolean('replace_items', false),
            $request->boolean('replace_conditions', false),
            isset($data['document_id']) ? (int) $data['document_id'] : null,
            'document',
        );

        return response()->json($result);
    }

    public function destroy(Tender $tender, TenderDocument $document): JsonResponse
    {
        $this->assertEditable($tender);
        $this->assertOwns($tender, $document);
        $this->import->deleteDocument($document);

        return response()->json(['ok' => true]);
    }

    public function show(Tender $tender, TenderDocument $document): JsonResponse
    {
        $this->assertOwns($tender, $document);

        $text = (string) ($document->extracted_text ?? '');
        if (mb_strlen($text) > 12000) {
            $text = mb_substr($text, 0, 12000)."\n\n[… tekst ucięty w podglądzie …]";
        }

        $analysis = $document->analysis_json;
        if (is_array($analysis)) {
            if (isset($analysis['items']) && is_array($analysis['items'])) {
                $analysis['items'] = array_slice($analysis['items'], 0, 400);
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
            'created_at' => $document->created_at,
        ]);
    }

    private function assertEditable(Tender $tender): void
    {
        if (! $this->workflow->canEditOffer($tender)) {
            throw ValidationException::withMessages([
                'tender' => ['Import zablokowany — status: '.$tender->status],
            ]);
        }
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
