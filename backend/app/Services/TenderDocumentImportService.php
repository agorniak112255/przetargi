<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\Product;
use App\Models\Tender;
use App\Models\TenderCondition;
use App\Models\TenderDocument;
use App\Models\TenderItem;
use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use RuntimeException;

final class TenderDocumentImportService
{
    public function __construct(
        private readonly TenderDocumentTextExtractor $extractor,
        private readonly TenderDocumentAiAnalyzer $analyzer,
        private readonly TenderSpreadsheetItemExtractor $spreadsheetItems,
        private readonly TenderDocxItemExtractor $docxItems,
        private readonly TenderPricingService $pricing,
    ) {}

    /**
     * @param  list<string>  $targets
     * @return array{
     *     document: ?TenderDocument,
     *     mode: string,
     *     targets: list<string>,
     *     extracted_text: string,
     *     mapping_notes: ?string,
     *     items: list<array<string, mixed>>,
     *     conditions: list<array{category: ?string, content: string, selected?: bool}>
     * }
     */
    public function analyzeUpload(
        Tender $tender,
        UploadedFile $file,
        string $mode,
        array $targets,
        User $user,
    ): array {
        $mode = in_array($mode, ['simple', 'ai', 'full'], true) ? $mode : 'simple';
        $targets = array_values(array_intersect($targets, ['items', 'conditions']));
        if ($targets === []) {
            throw new RuntimeException('Wybierz cel: pozycje i/lub warunki.');
        }

        $ext = mb_strtolower($file->getClientOriginalExtension() ?: pathinfo($file->getClientOriginalName(), PATHINFO_EXTENSION));
        $tmp = $file->getRealPath();
        if ($tmp === false) {
            throw new RuntimeException('Nie można odczytać pliku.');
        }

        $text = $this->extractor->extract($tmp, $ext);
        $mappingNotes = null;

        $document = null;
        $diskPath = null;
        // DOCX zawsze zapisuj (szablon oferty); full = archiwum każdego formatu
        $persistFile = $mode === 'full' || in_array($ext, ['docx', 'doc'], true);
        if ($persistFile) {
            $diskPath = $file->store("tender-documents/{$tender->id}", 'local');
        }

        $sheetItems = null;
        $useAiMap = $mode === 'ai' || $mode === 'full';
        if (in_array('items', $targets, true) && in_array($ext, ['xlsx', 'xls', 'csv'], true)) {
            $sheet = $this->spreadsheetItems->extract($tmp, $useAiMap);
            if ($sheet !== null && $sheet['items'] !== []) {
                $sheetItems = $sheet['items'];
                $mappingNotes = $sheet['notes'].' Kolumny: '.json_encode($sheet['column_map'], JSON_UNESCAPED_UNICODE);
            }
        }
        if ($sheetItems === null && in_array('items', $targets, true) && $ext === 'docx') {
            $sheet = $this->docxItems->extract($tmp, $useAiMap);
            if ($sheet !== null && $sheet['items'] !== []) {
                $sheetItems = $sheet['items'];
                $mappingNotes = $sheet['notes'].' Kolumny: '.json_encode($sheet['column_map'], JSON_UNESCAPED_UNICODE);
            }
        }

        if ($mode === 'ai' || $mode === 'full') {
            try {
                $parsed = $this->analyzer->analyze($text, $targets);
            } catch (\Throwable) {
                $parsed = $this->analyzer->heuristic($text, $targets);
            }
            $needItems = in_array('items', $targets, true) && ($parsed['items'] ?? []) === [];
            $needCond = in_array('conditions', $targets, true) && ($parsed['conditions'] ?? []) === [];
            if ($needItems || $needCond) {
                $fallback = $this->analyzer->heuristic($text, $targets);
                if ($needItems) {
                    $parsed['items'] = $fallback['items'];
                }
                if ($needCond) {
                    $parsed['conditions'] = $fallback['conditions'];
                }
            }
        } else {
            $parsed = $this->analyzer->heuristic($text, $targets);
        }

        // Arkusz z wykrytymi kolumnami ma priorytet nad „sklejonym” tekstem
        if ($sheetItems !== null) {
            $parsed['items'] = $sheetItems;
        }

        $items = array_map(
            fn (array $r) => $this->normalizePreviewItem($r) + ['selected' => true],
            array_slice($parsed['items'], 0, 800),
        );
        $conditions = array_map(
            static fn (array $r) => $r + ['selected' => true],
            array_slice($parsed['conditions'], 0, 400),
        );

        // Archiwum: tryb pełny albo zawsze Word (szablon oferty DOCX).
        if ($persistFile) {
            $document = TenderDocument::query()->create([
                'tender_id' => $tender->id,
                'uploaded_by' => $user->id,
                'original_name' => $file->getClientOriginalName(),
                'disk_path' => $diskPath,
                'mime' => $file->getMimeType(),
                'extension' => $ext,
                'size_bytes' => (int) $file->getSize(),
                'mode' => $mode,
                'targets' => $targets,
                'extracted_text' => $text,
                'analysis_json' => [
                    'items' => $items,
                    'conditions' => $conditions,
                    'mapping_notes' => $mappingNotes,
                ],
            ]);
        }

        // Duży XLSX w JSON psuje odpowiedź HTTP — pełny tekst tylko w trybie prostym (edycja).
        $textForClient = $mode === 'simple'
            ? $text
            : (mb_strlen($text) > 12000 ? mb_substr($text, 0, 12000)."\n\n[… tekst ucięty w podglądzie …]" : $text);

        return [
            'document' => $document,
            'mode' => $mode,
            'targets' => $targets,
            'extracted_text' => $textForClient,
            'mapping_notes' => $mappingNotes,
            'items' => $items,
            'conditions' => $conditions,
        ];
    }

    /**
     * @return array{
     *     document: TenderDocument,
     *     mode: string,
     *     targets: list<string>,
     *     extracted_text: string,
     *     mapping_notes: ?string,
     *     items: list<array<string, mixed>>,
     *     conditions: list<array{category: ?string, content: string, selected?: bool}>
     * }
     */
    public function reanalyze(TenderDocument $document, string $mode, array $targets): array
    {
        $mode = in_array($mode, ['simple', 'ai', 'full'], true) ? $mode : (string) $document->mode;
        $targets = array_values(array_intersect($targets, ['items', 'conditions']));
        if ($targets === []) {
            $targets = is_array($document->targets) ? $document->targets : ['items', 'conditions'];
        }

        $text = (string) ($document->extracted_text ?? '');
        if ($text === '' && $document->disk_path && Storage::disk('local')->exists($document->disk_path)) {
            $abs = Storage::disk('local')->path($document->disk_path);
            $text = $this->extractor->extract($abs, (string) $document->extension);
            $document->extracted_text = $text;
        }
        if ($text === '') {
            throw new RuntimeException('Brak tekstu dokumentu do ponownej analizy.');
        }

        if ($mode === 'ai' || $mode === 'full') {
            $parsed = $this->analyzer->analyze($text, $targets);
        } else {
            $parsed = $this->analyzer->heuristic($text, $targets);
        }

        $items = array_map(
            fn (array $r) => $this->normalizePreviewItem($r) + ['selected' => true],
            array_slice($parsed['items'], 0, 800),
        );
        $conditions = array_map(static fn (array $r) => $r + ['selected' => true], $parsed['conditions']);

        if ($document->disk_path && in_array('items', $targets, true)) {
            $abs = Storage::disk('local')->path($document->disk_path);
            $ext = (string) $document->extension;
            $useAi = $mode === 'ai' || $mode === 'full';
            $sheet = null;
            if (in_array($ext, ['xlsx', 'xls', 'csv'], true)) {
                $sheet = $this->spreadsheetItems->extract($abs, $useAi);
            } elseif ($ext === 'docx') {
                $sheet = $this->docxItems->extract($abs, $useAi);
            }
            if ($sheet !== null && $sheet['items'] !== []) {
                $items = array_map(
                    fn (array $r) => $this->normalizePreviewItem($r) + ['selected' => true],
                    array_slice($sheet['items'], 0, 800),
                );
            }
        }

        $document->mode = $mode;
        $document->targets = $targets;
        $document->analysis_json = ['items' => $items, 'conditions' => $conditions];
        $document->save();

        $textForClient = $mode === 'simple'
            ? $text
            : (mb_strlen($text) > 12000 ? mb_substr($text, 0, 12000)."\n\n[… tekst ucięty w podglądzie …]" : $text);

        return [
            'document' => $document->fresh(),
            'mode' => $mode,
            'targets' => $targets,
            'extracted_text' => $textForClient,
            'mapping_notes' => null,
            'items' => $items,
            'conditions' => $conditions,
        ];
    }

    /**
     * @param  list<array<string, mixed>>  $items
     * @param  list<array{category?: ?string, content: string}>  $conditions
     * @return array{items_created: int, conditions_created: int}
     */
    public function commit(
        Tender $tender,
        array $items,
        array $conditions,
        bool $replaceItems,
        bool $replaceConditions,
        ?int $documentId,
        string $source = 'document',
    ): array {
        $itemsCreated = 0;
        $conditionsCreated = 0;

        DB::transaction(function () use (
            $tender,
            $items,
            $conditions,
            $replaceItems,
            $replaceConditions,
            $documentId,
            $source,
            &$itemsCreated,
            &$conditionsCreated,
        ): void {
            if ($replaceItems && $items !== []) {
                $tender->items()->delete();
            }
            if ($replaceConditions && $conditions !== []) {
                $tender->conditions()->delete();
            }

            if ($items !== []) {
                $lineNo = (int) ($tender->items()->max('line_no') ?? 0);
                foreach ($items as $row) {
                    $norm = $this->normalizePreviewItem($row);
                    $req = $norm['requirement'];
                    if ($req === '') {
                        continue;
                    }
                    $lineNo++;
                    $product = null;
                    if ($norm['sku'] !== null && $norm['sku'] !== '') {
                        $product = Product::query()->where('sku', $norm['sku'])->first();
                    }
                    $item = new TenderItem([
                        'tender_id' => $tender->id,
                        'line_no' => $lineNo,
                        'requirement' => $req,
                        'main_product_id' => $product?->id,
                        'quantity' => $norm['quantity'],
                        'offer_price' => $norm['offer_price'],
                        'ai_match_percent' => $product ? 100 : null,
                        'status' => $product ? 'matched' : 'brak',
                    ]);
                    if ($item->offer_price === null && $product !== null) {
                        $item->offer_price = $this->pricing->offerFromProduct($tender, $product);
                    }
                    $item->save();
                    $this->pricing->recalculateItemMargin($item);
                    $itemsCreated++;
                }
            }

            if ($conditions !== []) {
                $sort = (int) ($tender->conditions()->max('sort_order') ?? 0);
                foreach ($conditions as $row) {
                    $content = trim((string) ($row['content'] ?? ''));
                    if ($content === '') {
                        continue;
                    }
                    $sort++;
                    TenderCondition::query()->create([
                        'tender_id' => $tender->id,
                        'tender_document_id' => $documentId,
                        'sort_order' => $sort,
                        'category' => isset($row['category']) && $row['category'] !== ''
                            ? mb_substr((string) $row['category'], 0, 64)
                            : null,
                        'content' => $content,
                        'source' => $source,
                    ]);
                    $conditionsCreated++;
                }
            }
        });

        if ($tender->status === 'draft' && ($itemsCreated > 0 || $conditionsCreated > 0)) {
            $tender->status = 'wycena';
        }
        if ($itemsCreated > 0) {
            $this->pricing->recalculateTenderTotals($tender->fresh());
        }
        $tender->last_activity_at = now();
        $tender->save();

        return [
            'items_created' => $itemsCreated,
            'conditions_created' => $conditionsCreated,
        ];
    }

    public function deleteDocument(TenderDocument $document): void
    {
        if ($document->disk_path && Storage::disk('local')->exists($document->disk_path)) {
            Storage::disk('local')->delete($document->disk_path);
        }
        $document->delete();
    }

    /**
     * @param  array<string, mixed>  $row
     * @return array{sku: ?string, name: string, requirement: string, quantity: int, offer_price: ?float, currency: ?string, norms: ?string, description: ?string}
     */
    private function normalizePreviewItem(array $row): array
    {
        $sku = isset($row['sku']) ? trim((string) $row['sku']) : '';
        $name = trim((string) ($row['name'] ?? ''));
        $description = trim((string) ($row['description'] ?? ''));
        $norms = trim((string) ($row['norms'] ?? ''));
        $req = trim((string) ($row['requirement'] ?? ''));
        if ($name === '' && $description !== '') {
            $name = $description;
        }
        if ($name === '' && $req !== '') {
            $name = $req;
        }
        if ($req === '') {
            $req = trim(implode(' · ', array_filter([
                $name !== '' ? $name : null,
                ($description !== '' && mb_strtolower($description) !== mb_strtolower($name)) ? $description : null,
                $norms !== '' ? $norms : null,
            ])));
        }
        $qty = $row['quantity'] ?? 1;
        $price = $row['offer_price'] ?? $row['price'] ?? null;
        $price = is_numeric($price) ? round((float) $price, 2) : null;
        $currency = isset($row['currency']) && is_string($row['currency']) ? $row['currency'] : null;

        return [
            'sku' => $sku !== '' ? $sku : null,
            'name' => $name,
            'requirement' => $req !== '' ? $req : $name,
            'quantity' => max(1, is_numeric($qty) ? (int) $qty : 1),
            'offer_price' => $price,
            'currency' => $currency,
            'norms' => $norms !== '' ? $norms : null,
            'description' => ($description !== '' && mb_strtolower($description) !== mb_strtolower($name))
                ? $description
                : null,
        ];
    }
}
