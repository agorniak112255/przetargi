<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\Product;
use App\Models\Tender;
use App\Models\TenderActivity;
use App\Models\TenderCondition;
use App\Models\TenderDocument;
use App\Models\TenderItem;
use App\Models\User;
use App\Services\Pricing\SupplierSpecialMask;
use App\Support\PpeAssortment;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use RuntimeException;

final class TenderDocumentImportService
{
    /** Pochodzenie pozycji (TenderItem::source): towar z treści ogłoszenia. */
    public const ITEM_SOURCE_NOTICE = 'notice_text';

    /** Pochodzenie pozycji (TenderItem::source): odczyt dokumentu postępowania. */
    public const ITEM_SOURCE_DOCUMENT = 'document';

    /** Dopasowanie wybrane przez człowieka — uzupełnienie pozycji z dokumentu go nie czyści. */
    private const HUMAN_MATCH_SOURCES = ['manual', 'custom', 'battlecard'];

    /** Słowa nazwy, które nie odróżniają towarów (początki słów po normalizacji). */
    private const NAME_STOPWORDS = [
        'dosta', 'zakup', 'oraz', 'wraz', 'typu', 'rodza', 'sztuk', 'para', 'pary', 'kompl', 'ochro', 'roboc',
        'specj', 'zgodn', 'wymag', 'zamow', 'przed', 'czesc',
    ];

    /** Separator numeru części w source_ref pozycji z ogłoszenia: „2026/BZP 00123456/01|część 2”. */
    private const LOT_REF_SEPARATOR = '|część ';

    public function __construct(
        private readonly TenderDocumentTextExtractor $extractor,
        private readonly TenderDocumentAiAnalyzer $analyzer,
        private readonly TenderSpreadsheetItemExtractor $spreadsheetItems,
        private readonly TenderDocxItemExtractor $docxItems,
        private readonly TenderPricingService $pricing,
        private readonly PpeAssortment $assortment,
    ) {}

    /**
     * @param  list<string>  $targets
     * @param  array{source: string, source_ref: ?string, source_url: ?string}|null  $origin  pochodzenie pliku pobranego automatycznie
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
        ?array $origin = null,
    ): array {
        $mode = in_array($mode, ['simple', 'ai', 'full'], true) ? $mode : 'simple';
        $targets = array_values(array_intersect($targets, ['items', 'conditions']));
        if ($targets === []) {
            throw new RuntimeException('Zaznacz, co odczytać z pliku: pozycje, warunki albo jedno i drugie.');
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
        // DOCX zawsze zapisuj (szablon oferty); full = archiwum każdego formatu; plik ze źródła zewnętrznego — zawsze
        // (pochodzenie, ponowny odczyt i pobranie bez ponownego odpytywania źródła)
        $persistFile = $mode === 'full' || in_array($ext, ['docx', 'doc'], true) || $origin !== null;
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

        $parsed = $this->parseText($text, $mode, $this->textTargets($targets, $sheetItems !== null));

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
                'source' => $origin['source'] ?? null,
                'source_ref' => isset($origin['source_ref']) ? mb_substr((string) $origin['source_ref'], 0, 191) : null,
                'source_url' => isset($origin['source_url']) ? mb_substr((string) $origin['source_url'], 0, 500) : null,
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
            throw new RuntimeException('Brak tekstu tego dokumentu — dodaj plik ponownie, żeby odczytać go jeszcze raz.');
        }

        // najpierw arkusz/tabela — gdy pozycje są stamtąd, model nie ma ich przepisywać
        $sheetItems = null;
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
                $sheetItems = $sheet['items'];
            }
        }

        $parsed = $mode === 'ai' || $mode === 'full'
            ? $this->parseText($text, $mode, $this->textTargets($targets, $sheetItems !== null))
            : $this->analyzer->heuristic($text, $targets);
        if ($sheetItems !== null) {
            $parsed['items'] = $sheetItems;
        }

        $items = array_map(
            fn (array $r) => $this->normalizePreviewItem($r) + ['selected' => true],
            array_slice($parsed['items'], 0, 800),
        );
        $conditions = array_map(static fn (array $r) => $r + ['selected' => true], $parsed['conditions']);

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
     * Pozycje z arkusza nie idą drugi raz do modelu: dla 15 długich opisów SIWZ
     * model przepisywał kilka tysięcy tokenów JSON, a wynik i tak był wyrzucany
     * (arkusz ma pierwszeństwo). Zostają tylko warunki — albo nic.
     *
     * @param  list<string>  $targets
     * @return list<string>
     */
    private function textTargets(array $targets, bool $itemsFromSheet): array
    {
        return $itemsFromSheet ? array_values(array_diff($targets, ['items'])) : $targets;
    }

    /**
     * @param  list<string>  $targets
     * @return array{items: list<array<string, mixed>>, conditions: list<array{category: ?string, content: string}>}
     */
    private function parseText(string $text, string $mode, array $targets): array
    {
        if ($targets === []) {
            return ['items' => [], 'conditions' => []];
        }
        if ($mode !== 'ai' && $mode !== 'full') {
            return $this->analyzer->heuristic($text, $targets);
        }
        try {
            $parsed = $this->analyzer->analyze($text, $targets);
        } catch (\Throwable) {
            return $this->analyzer->heuristic($text, $targets);
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

        return $parsed;
    }

    /**
     * Zapis podglądu do przetargu. Pozycje z dokumentu ($itemSource = 'document') z kluczem replaces_item_id uzupełniają
     * istniejącą pozycję z treści ogłoszenia (source = 'notice_text') zamiast dodawać nową; $removeItemIds — pozycje z
     * ogłoszenia, które człowiek kazał usunąć, bo dokument rozpisuje je na kilka pozycji. Przy $replaceItems (wszystko od
     * nowa) jedno i drugie jest pomijane. $source — pochodzenie warunków (bez zmian), $itemSource/$itemSourceRef —
     * pochodzenie zapisanych pozycji (TenderItem::source/source_ref; null — tekst wklejony, ręcznie).
     *
     * @param  list<array<string, mixed>>  $items
     * @param  list<array{category?: ?string, content: string}>  $conditions
     * @param  list<int>  $removeItemIds
     * @return array{
     *     items_created: int,
     *     items_updated: int,
     *     items_removed: int,
     *     conditions_created: int,
     *     updated: list<array<string, mixed>>,
     *     removed: list<array<string, mixed>>
     * }
     */
    public function commit(
        Tender $tender,
        array $items,
        array $conditions,
        bool $replaceItems,
        bool $replaceConditions,
        ?int $documentId,
        SupplierSpecialMask $mask,
        string $source = 'document',
        array $removeItemIds = [],
        ?string $itemSource = null,
        ?string $itemSourceRef = null,
    ): array {
        $itemsCreated = 0;
        $conditionsCreated = 0;
        $updated = [];
        $removed = [];
        $itemSourceRef = $itemSourceRef !== null ? mb_substr($itemSourceRef, 0, 191) : null;

        DB::transaction(function () use (
            $tender,
            $items,
            $conditions,
            $replaceItems,
            $replaceConditions,
            $documentId,
            $mask,
            $source,
            $removeItemIds,
            $itemSource,
            $itemSourceRef,
            &$itemsCreated,
            &$conditionsCreated,
            &$updated,
            &$removed,
        ): void {
            // blokada wiersza przetargu: dwa równoległe zapisy nie uzupełnią (nie usuną) tej samej pozycji dwa razy
            Tender::query()->whereKey($tender->id)->lockForUpdate()->first();

            if ($replaceItems && $items !== []) {
                $tender->items()->delete();
            }
            if ($replaceConditions && $conditions !== []) {
                $tender->conditions()->delete();
            }

            // pozycje z ogłoszenia, które ten dokument może uzupełnić albo usunąć (tylko zapis z dokumentu, bez „zastąp”)
            $noticeItems = ! $replaceItems && $itemSource === self::ITEM_SOURCE_DOCUMENT
                ? $tender->items()->where('source', self::ITEM_SOURCE_NOTICE)->get()->keyBy('id')
                : collect();
            $humanChosen = $this->humanChosenItemIds($noticeItems);
            $usedIds = [];
            $saved = [];

            if ($items !== []) {
                $lineNo = (int) ($tender->items()->max('line_no') ?? 0);
                foreach ($items as $row) {
                    $norm = $this->normalizePreviewItem($row);
                    $req = $norm['requirement'];
                    if ($req === '') {
                        continue;
                    }
                    // ten sam id dwa razy (albo spoza przetargu / nie z ogłoszenia) — pozycja trafia jako nowa
                    $replacesId = isset($row['replaces_item_id']) && is_numeric($row['replaces_item_id'])
                        ? (int) $row['replaces_item_id']
                        : null;
                    $target = $replacesId !== null && ! isset($usedIds[$replacesId]) ? $noticeItems->get($replacesId) : null;
                    if ($target instanceof TenderItem) {
                        $usedIds[$replacesId] = true;
                        $updated[] = $this->enrichNoticeItem($tender, $target, $norm, $humanChosen, $itemSourceRef, $mask);
                        $saved[] = $target;

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
                        'source' => $itemSource,
                        'source_ref' => $this->itemSourceRef($itemSource, $itemSourceRef, $row),
                    ]);
                    if ($item->offer_price === null && $product !== null) {
                        $item->offer_price = $this->pricing->offerFromProduct($tender, $product, $mask);
                    }
                    $item->save();
                    $saved[] = $item;
                    $itemsCreated++;
                }
            }

            // usunięcie pozycji z ogłoszenia, które dokument rozpisuje na kilka pozycji — tylko wybór człowieka
            foreach (array_values(array_unique(array_map('intval', $removeItemIds))) as $removeId) {
                $item = isset($usedIds[$removeId]) ? null : $noticeItems->get($removeId);
                if (! $item instanceof TenderItem) {
                    continue;
                }
                $removed[] = [
                    'id' => (int) $item->id,
                    'line_no' => (int) $item->line_no,
                    'requirement' => mb_substr((string) $item->requirement, 0, 500),
                    'quantity' => (int) $item->quantity,
                    'product' => $this->offerProductName($item),
                ];
                $item->delete();
            }

            if ($saved !== []) {
                // marże po pętli, jedną maską ceny standardowej z kartami wczytanymi hurtem
                $standard = $this->pricing->standardMask($saved);
                foreach ($saved as $item) {
                    $this->pricing->recalculateItemMargin($item, $standard);
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

        if ($tender->status === 'draft' && ($itemsCreated > 0 || $updated !== [] || $conditionsCreated > 0)) {
            $tender->status = 'wycena';
        }
        if ($itemsCreated > 0 || $updated !== [] || $removed !== []) {
            $this->pricing->recalculateTenderTotals($tender->fresh());
        }
        $tender->last_activity_at = now();
        $tender->save();

        return [
            'items_created' => $itemsCreated,
            'items_updated' => count($updated),
            'items_removed' => count($removed),
            'conditions_created' => $conditionsCreated,
            'updated' => $updated,
            'removed' => $removed,
        ];
    }

    /**
     * Uzupełnienie pozycji z ogłoszenia danymi z dokumentu: wymaganie z dokumentu, ilość z dokumentu (gdy ją podaje —
     * inaczej zostaje z ogłoszenia), pochodzenie 'document', numer pozycji bez zmian. Dopasowanie opisywało krótką nazwę
     * z ogłoszenia, więc jest czyszczone w całości (pozycja do ponownego „Dopasuj AI”) — chyba że produkt wybrał
     * człowiek (własny produkt, ręczny wybór, tańszy zamiennik, cena zmieniona ręcznie): wtedy zostaje do sprawdzenia.
     *
     * @param  array{sku: ?string, name: string, requirement: string, quantity: int, quantity_missing: bool, offer_price: ?float}  $norm
     * @param  array<int, true>  $humanChosen
     * @return array<string, mixed> wpis historii
     */
    private function enrichNoticeItem(
        Tender $tender,
        TenderItem $item,
        array $norm,
        array $humanChosen,
        ?string $sourceRef,
        SupplierSpecialMask $mask,
    ): array {
        $before = ['requirement' => (string) $item->requirement, 'quantity' => (int) $item->quantity];
        $productName = $this->offerProductName($item);
        $keepsProduct = $this->isMatchChosenByHuman($item, $humanChosen);

        $item->requirement = $norm['requirement'];
        if (! $norm['quantity_missing']) {
            $item->quantity = $norm['quantity'];
        }
        $item->source = self::ITEM_SOURCE_DOCUMENT;
        $item->source_ref = $sourceRef;

        if (! $keepsProduct) {
            $item->main_product_id = null;
            $item->clearVariant();
            $item->clearCompanion();
            $item->custom_name = null;
            $item->custom_url = null;
            $item->offer_price = null;
            $item->margin_percent = null;
            $item->setAttribute('margin_percent_standard', null);
            $item->battlecard_substitutes = null;
            $item->ai_match_percent = null;
            $item->ai_match_reasons = null;
            $item->match_source = null;
            $item->status = 'brak';
            // numer artykułu z dokumentu wskazuje kartę — jak przy nowej pozycji
            $product = $norm['sku'] !== null && $norm['sku'] !== ''
                ? Product::query()->where('sku', $norm['sku'])->first()
                : null;
            if ($product !== null) {
                $item->main_product_id = $product->id;
                $item->ai_match_percent = 100;
                $item->status = 'matched';
            }
            $item->offer_price = $norm['offer_price'];
            if ($item->offer_price === null && $product !== null) {
                $item->offer_price = $this->pricing->offerFromProduct($tender, $product, $mask);
            }
        }
        $item->save();
        // marża liczona po pętli doczytuje pełne karty — nie ze zdjętej ani okrojonej (nazwa do historii) relacji
        $item->unsetRelation('mainProduct');
        $item->unsetRelation('companionProduct');

        return [
            'id' => (int) $item->id,
            'line_no' => (int) $item->line_no,
            'before' => $before,
            // wymaganie z dokumentu jest w pozycji — do historii wystarczy jego początek
            'after' => ['requirement' => mb_substr((string) $item->requirement, 0, 1000), 'quantity' => (int) $item->quantity],
            'removed_product' => $keepsProduct ? null : $productName,
            'kept_product' => $keepsProduct ? $productName : null,
        ];
    }

    /**
     * Propozycje uzupełnienia pozycji z ogłoszenia (source = 'notice_text') pozycjami z podglądu dokumentu.
     * Para pasuje, gdy wszystkie istotne słowa nazwy z ogłoszenia (wymaganie przed „ — ”; słowa ≥ 4 znaki bez
     * ogólników typu „ochronne”, „dostawa”, plus pierwszy rzeczownik nazwy, także krótki: „pas”, „but”; odmiana po
     * wspólnym początku słowa) występują w nazwie albo wymaganiu pozycji z dokumentu. Wynik: najpierw zgodność
     * pierwszego rzeczownika (głowy) obu nazw, potem zgodność w samej nazwie z dokumentu, liczba słów z ogłoszenia,
     * pokrycie nazwy z dokumentu. Zaznaczone automatycznie (replaces_item_id) tylko przy wzajemnie najlepszym i jedynym
     * dopasowaniu 1:1 (bez remisu po żadnej stronie), ze zgodną głową, bez sprzecznej rodziny/rodzaju towaru
     * (PpeAssortment, np. latarka do hełmu ≠ hełm), z równą ilością albo ilością nieznaną w dokumencie i tylko gdy
     * pozycje z ogłoszenia pochodzą z jednej części zamówienia; pozostałe pasujące pozycje — replaces_options
     * (możliwe, do wyboru człowieka) z powodem w replaces_hint.
     *
     * @param  list<array<string, mixed>>  $previewItems
     * @return array{items: list<array<string, mixed>>, notice_items: list<array<string, mixed>>}
     */
    public function proposeNoticeItemReplacements(Tender $tender, array $previewItems): array
    {
        $noticeItems = TenderItem::query()
            ->where('tender_id', $tender->id)
            ->where('source', self::ITEM_SOURCE_NOTICE)
            ->with('mainProduct:id,name')
            ->orderBy('line_no')
            ->get();
        if ($noticeItems->isEmpty()) {
            return ['items' => array_values($previewItems), 'notice_items' => []];
        }
        $humanChosen = $this->humanChosenItemIds($noticeItems);
        $lots = array_values(array_unique(array_filter(
            $noticeItems->map(fn (TenderItem $n): ?int => self::noticeItemLot($n))->all(),
            static fn (?int $lot): bool => $lot !== null,
        )));
        // pozycje z kilku części: ta sama nazwa może być w każdej części — bez automatycznego zaznaczenia
        $multiLot = count($lots) > 1;

        $notices = [];
        foreach ($noticeItems as $n) {
            $name = self::noticeItemName((string) $n->requirement);
            $notices[(int) $n->id] = [
                'name' => $name,
                'head' => $this->headWord($name),
                'words' => $this->nameWords($name),
            ];
        }

        $scores = [];
        $heads = [];
        foreach (array_values($previewItems) as $di => $row) {
            $docName = trim((string) ($row['name'] ?? ''));
            $docReq = trim((string) ($row['requirement'] ?? ''));
            $docLabel = $docName !== '' ? $docName : $docReq;
            $docHead = $this->headWord($docLabel);
            $nameWords = $this->nameWords($docLabel);
            $allWords = array_merge($nameWords, $this->significantWords($docReq.' '.($row['description'] ?? '')));
            foreach ($notices as $nid => $notice) {
                $words = $notice['words'];
                if ($words === [] || ! $this->coversAll($words, $allWords)) {
                    continue;
                }
                $headMatch = $notice['head'] !== null && $docHead !== null && $this->wordsMatch($notice['head'], $docHead);
                $heads[$di][$nid] = $headMatch;
                $inName = $this->coversAll($words, $nameWords);
                $covered = 0;
                foreach ($nameWords as $w) {
                    if ($this->coversAll([$w], $words)) {
                        $covered++;
                    }
                }
                $coverage = $nameWords === [] ? 0 : (int) round(1000 * $covered / count($nameWords));
                $scores[$di][$nid] = ($headMatch ? 10_000_000 : 0) + ($inName ? 1_000_000 : 0) + count($words) * 1000 + $coverage;
            }
        }

        $bestNoticeFor = [];
        foreach ($scores as $di => $byNotice) {
            $bestNoticeFor[$di] = self::uniqueBest($byNotice);
        }
        $byNoticeDoc = [];
        foreach ($scores as $di => $byNotice) {
            foreach ($byNotice as $nid => $score) {
                $byNoticeDoc[$nid][$di] = $score;
            }
        }
        $bestDocFor = [];
        foreach ($byNoticeDoc as $nid => $byDoc) {
            $bestDocFor[$nid] = self::uniqueBest($byDoc);
        }

        $noticeById = $noticeItems->keyBy('id');
        $out = [];
        foreach (array_values($previewItems) as $di => $row) {
            $options = $scores[$di] ?? [];
            arsort($options);
            $auto = null;
            $hint = null;
            $nid = $bestNoticeFor[$di] ?? null;
            if ($options === []) {
                $hint = null;
            } elseif ($nid === null || ($bestDocFor[$nid] ?? null) !== $di) {
                $hint = 'kilka pasujących pozycji — dopasowanie nie jest jednoznaczne';
            } else {
                $notice = $noticeById->get($nid);
                $norm = $this->normalizePreviewItem($row);
                $docLabel = $norm['name'] !== '' ? $norm['name'] : $norm['requirement'];
                $rowLot = isset($row['lot_no']) && is_numeric($row['lot_no']) ? (int) $row['lot_no'] : null;
                $noticeLot = $notice instanceof TenderItem ? self::noticeItemLot($notice) : null;
                $hint = match (true) {
                    ! $notice instanceof TenderItem => 'pozycja z ogłoszenia niedostępna',
                    $multiLot => 'pozycje z ogłoszenia pochodzą z kilku części zamówienia',
                    $rowLot !== null && $noticeLot !== null && $rowLot !== $noticeLot => 'inna część zamówienia',
                    ! ($heads[$di][$nid] ?? false) => 'nazwy zaczynają się od innego rzeczownika',
                    $this->kindsConflict($notices[$nid]['name'], $docLabel) => 'inny rodzaj towaru',
                    ! $norm['quantity_missing'] && (int) $notice->quantity !== $norm['quantity'] => 'inna ilość',
                    default => null,
                };
                if ($hint === null) {
                    $auto = $nid;
                }
            }
            $row['replaces_item_id'] = $auto;
            $row['replaces_label'] = $auto !== null ? $this->noticeItemLabel($noticeById->get($auto)) : null;
            $row['replaces_options'] = array_map('intval', array_keys($options));
            // dlaczego „możliwe” nie zostało zaznaczone (wniosek aplikacji, do sprawdzenia przez człowieka)
            $row['replaces_hint'] = $hint;
            $out[] = $row;
        }

        $noticeList = $noticeItems->map(function (TenderItem $n) use ($humanChosen): array {
            return [
                'id' => (int) $n->id,
                'line_no' => (int) $n->line_no,
                'name' => self::noticeItemName((string) $n->requirement),
                'requirement' => (string) $n->requirement,
                'quantity' => (int) $n->quantity,
                'lot_no' => self::noticeItemLot($n),
                'label' => $this->noticeItemLabel($n),
                'product' => $this->offerProductName($n),
                'keeps_product' => $this->isMatchChosenByHuman($n, $humanChosen),
            ];
        })->values()->all();

        return ['items' => $out, 'notice_items' => $noticeList];
    }

    /** Nazwa towaru z ogłoszenia: wymaganie zapisane jako „nazwa — cechy z ogłoszenia”. */
    private static function noticeItemName(string $requirement): string
    {
        return trim(explode(' — ', $requirement, 2)[0]);
    }

    private function noticeItemLabel(?TenderItem $item): ?string
    {
        if ($item === null) {
            return null;
        }
        $lot = self::noticeItemLot($item);

        return self::noticeItemName((string) $item->requirement).' · ilość '.(int) $item->quantity
            .($lot !== null ? ' · część '.$lot : '');
    }

    /** Numer części zamówienia pozycji z ogłoszenia (z source_ref) — null, gdy nieznany. */
    private static function noticeItemLot(TenderItem $item): ?int
    {
        $ref = (string) ($item->source_ref ?? '');
        $pos = mb_strrpos($ref, self::LOT_REF_SEPARATOR);
        if ($pos === false) {
            return null;
        }
        $lot = mb_substr($ref, $pos + mb_strlen(self::LOT_REF_SEPARATOR));

        return ctype_digit($lot) ? (int) $lot : null;
    }

    /**
     * Pochodzenie zapisanej pozycji: dla towaru z ogłoszenia z numerem części — numer ogłoszenia i część.
     *
     * @param  array<string, mixed>  $row
     */
    private function itemSourceRef(?string $itemSource, ?string $ref, array $row): ?string
    {
        if ($itemSource !== self::ITEM_SOURCE_NOTICE || $ref === null || ! isset($row['lot_no']) || ! is_numeric($row['lot_no'])) {
            return $ref;
        }

        return mb_substr($ref, 0, 170).self::LOT_REF_SEPARATOR.(int) $row['lot_no'];
    }

    /**
     * Sprzeczny rodzaj towaru wg klasyfikatora asortymentu: obie nazwy mają rodzinę i są to różne rodziny albo obie
     * mają rodzaj wyrobu i są to różne rodzaje (np. „Latarka do hełmu” — akcesorium, „Hełm strażacki” — hełm).
     */
    private function kindsConflict(string $noticeName, string $docName): bool
    {
        $nFamily = $this->assortment->family($noticeName);
        $dFamily = $this->assortment->family($docName);
        if ($nFamily !== null && $dFamily !== null && $nFamily !== $dFamily) {
            return true;
        }
        $nType = $this->assortment->articleType($noticeName, $nFamily);
        $dType = $this->assortment->articleType($docName, $dFamily);
        // akcesorium po jednej stronie, a po drugiej inny albo nieznany rodzaj — nie ten sam towar
        // („Pas strażacki” vs „Pasek podbródkowy do hełmu strażackiego”)
        if (($nType === 'accessory') !== ($dType === 'accessory')) {
            return true;
        }

        return $nType !== null && $dType !== null && $nType !== $dType;
    }

    /**
     * Klucz z najwyższym wynikiem — null przy remisie na szczycie (dopasowanie nie jest jednoznaczne).
     *
     * @param  array<int, int>  $scores
     */
    private static function uniqueBest(array $scores): ?int
    {
        if ($scores === []) {
            return null;
        }
        arsort($scores);
        $keys = array_keys($scores);
        $values = array_values($scores);
        if (count($values) > 1 && $values[0] === $values[1]) {
            return null;
        }

        return (int) $keys[0];
    }

    /**
     * Istotne słowa: małe litery bez polskich znaków, same litery, co najmniej 4 znaki, bez ogólników.
     *
     * @return list<string>
     */
    private function significantWords(string $text): array
    {
        $out = [];
        foreach (self::tokens($text) as $t) {
            if (strlen($t) < 4 || self::isStopword($t)) {
                continue;
            }
            $out[$t] = true;
        }

        return array_keys($out);
    }

    /**
     * Słowa nazwy do porównania: istotne słowa i pierwszy rzeczownik (także krótki — „pas”, „but”).
     *
     * @return list<string>
     */
    private function nameWords(string $text): array
    {
        $words = $this->significantWords($text);
        $head = $this->headWord($text);
        if ($head !== null && ! in_array($head, $words, true)) {
            array_unshift($words, $head);
        }

        return $words;
    }

    /** Pierwsze słowo nazwy (≥ 3 litery, bez ogólników typu „dostawa”, „para”) — zwykle rzeczownik: rodzaj towaru. */
    private function headWord(string $text): ?string
    {
        foreach (self::tokens($text) as $t) {
            if (strlen($t) >= 3 && ! self::isStopword($t)) {
                return $t;
            }
        }

        return null;
    }

    /**
     * Małe litery bez polskich znaków, same litery.
     *
     * @return list<string>
     */
    private static function tokens(string $text): array
    {
        $text = strtr(mb_strtolower($text), [
            'ą' => 'a', 'ć' => 'c', 'ę' => 'e', 'ł' => 'l', 'ń' => 'n', 'ó' => 'o', 'ś' => 's', 'ź' => 'z', 'ż' => 'z',
        ]);

        return preg_split('/[^a-z]+/u', $text, -1, PREG_SPLIT_NO_EMPTY) ?: [];
    }

    private static function isStopword(string $token): bool
    {
        return in_array(substr($token, 0, 5), self::NAME_STOPWORDS, true) || in_array($token, self::NAME_STOPWORDS, true);
    }

    /**
     * Każde słowo z $needles ma odpowiednik w $haystack (wordsMatch).
     *
     * @param  list<string>  $needles
     * @param  list<string>  $haystack
     */
    private function coversAll(array $needles, array $haystack): bool
    {
        foreach ($needles as $n) {
            $found = false;
            foreach ($haystack as $h) {
                if ($this->wordsMatch($n, $h)) {
                    $found = true;
                    break;
                }
            }
            if (! $found) {
                return false;
            }
        }

        return true;
    }

    /**
     * To samo słowo w innej formie: wspólny początek ≥ 4 znaki i różnica najwyżej dwóch końcowych znaków krótszego
     * słowa („hełm”/„hełmy”, „rękawice”/„rękawic”, „kurtka”/„kurtki”); słowo 3-literowe („pas”, „but”) — całe na
     * początku drugiego, które jest dłuższe najwyżej o dwa znaki („pas”/„pasy”, „but”/„buty”).
     */
    private function wordsMatch(string $a, string $b): bool
    {
        $short = min(strlen($a), strlen($b));
        $common = 0;
        while ($common < $short && $a[$common] === $b[$common]) {
            $common++;
        }
        if ($short < 4) {
            // krótki rzeczownik („pas”, „but”) — tylko końcówki odmiany, nie zdrobnienie („pas” ≠ „pasek”)
            if ($common !== $short) {
                return false;
            }
            $ending = substr(strlen($a) > strlen($b) ? $a : $b, $short);

            return in_array($ending, ['', 'a', 'e', 'i', 'o', 'u', 'y', 'ie', 'em', 'ow', 'om', 'ami', 'ach'], true);
        }

        return $common >= 4 && $common >= $short - 2;
    }

    /**
     * Pozycje, w których produkt wybrał człowiek wg historii zmian pozycji (wpis od użytkownika): cena oferty zmieniona
     * przy tej samej karcie albo zmiana karty na obecną (np. wybór w oknie wyszukiwania — match_source 'ai').
     *
     * @param  Collection<int, TenderItem>  $items
     * @return array<int, true>
     */
    private function humanChosenItemIds(Collection $items): array
    {
        if ($items->isEmpty()) {
            return [];
        }
        $current = $items->mapWithKeys(fn (TenderItem $i): array => [(int) $i->id => (int) ($i->main_product_id ?? 0)])->all();
        $out = [];
        $activities = TenderActivity::query()
            ->whereIn('tender_item_id', array_keys($current))
            ->whereIn('action', ['item_updated', 'item_bulk_updated'])
            ->whereNotNull('user_id')
            ->get(['tender_item_id', 'meta']);
        foreach ($activities as $activity) {
            $itemId = (int) $activity->tender_item_id;
            $meta = is_array($activity->meta) ? $activity->meta : [];
            $before = is_array($meta['before'] ?? null) ? $meta['before'] : [];
            $after = is_array($meta['after'] ?? null) ? $meta['after'] : [];
            $productBefore = (int) ($before['main_product_id'] ?? 0);
            $productAfter = (int) ($after['main_product_id'] ?? 0);
            if ($productAfter !== 0 && $productAfter !== $productBefore && $productAfter === ($current[$itemId] ?? -1)) {
                $out[$itemId] = true;

                continue;
            }
            if (! array_key_exists('offer_price', $before) || ! array_key_exists('offer_price', $after)) {
                continue;
            }
            $priceBefore = is_numeric($before['offer_price']) ? round((float) $before['offer_price'], 2) : null;
            $priceAfter = is_numeric($after['offer_price']) ? round((float) $after['offer_price'], 2) : null;
            // ręczna cena przy TEJ karcie, która jest teraz na pozycji (nie przy karcie zmienionej później przez
            // „Dopasuj AI”) i nie przy pozycji bez produktu
            if ($priceBefore !== $priceAfter && $productBefore === $productAfter
                && $productAfter !== 0 && $productAfter === ($current[$itemId] ?? -1)) {
                $out[$itemId] = true;
            }
        }

        return $out;
    }

    /**
     * Produkt wybrany przez człowieka: własny produkt (custom_name — ale nie podpowiedź automatu, match_source
     * 'external'), ręczny wybór karty, tańszy zamiennik albo wybór/cena z historii zmian — uzupełnienie z dokumentu go
     * nie zdejmuje.
     *
     * @param  array<int, true>  $humanChosen
     */
    private function isMatchChosenByHuman(TenderItem $item, array $humanChosen): bool
    {
        return $item->isManualCustomOffer()
            || in_array($item->match_source, self::HUMAN_MATCH_SOURCES, true)
            || isset($humanChosen[(int) $item->id]);
    }

    private function offerProductName(TenderItem $item): ?string
    {
        $custom = trim((string) ($item->custom_name ?? ''));
        if ($custom !== '') {
            return $custom;
        }
        if ($item->main_product_id === null) {
            return null;
        }
        $name = trim((string) ($item->mainProduct?->name ?? ''));

        return $name !== '' ? $name : 'karta #'.$item->main_product_id;
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
     * @return array{sku: ?string, name: string, requirement: string, quantity: int, quantity_missing: bool, offer_price: ?float, currency: ?string, norms: ?string, description: ?string}
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
        $qty = $row['quantity'] ?? null;
        // ilość nie podana w dokumencie (analizator oznacza ją wprost; bez znacznika — gdy w ogóle jej brak):
        // pozycja dostaje 1 do poprawienia, a uzupełniana pozycja z ogłoszenia zachowuje swoją ilość
        $qtyMissing = array_key_exists('quantity_missing', $row)
            ? filter_var($row['quantity_missing'], FILTER_VALIDATE_BOOLEAN)
            : ! is_numeric($qty) || (int) $qty < 1;
        $price = $row['offer_price'] ?? $row['price'] ?? null;
        $price = is_numeric($price) ? round((float) $price, 2) : null;
        $currency = isset($row['currency']) && is_string($row['currency']) ? $row['currency'] : null;

        return [
            'sku' => $sku !== '' ? $sku : null,
            'name' => $name,
            'requirement' => $req !== '' ? $req : $name,
            'quantity' => max(1, is_numeric($qty) ? (int) $qty : 1),
            'quantity_missing' => $qtyMissing,
            'offer_price' => $price,
            'currency' => $currency,
            'norms' => $norms !== '' ? $norms : null,
            'description' => ($description !== '' && mb_strtolower($description) !== mb_strtolower($name))
                ? $description
                : null,
        ];
    }
}
