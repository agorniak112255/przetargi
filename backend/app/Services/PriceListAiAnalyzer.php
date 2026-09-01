<?php

declare(strict_types=1);

namespace App\Services;

use App\Jobs\AnalyzePriceListPdfChunkJob;
use App\Jobs\EnrichProductJob;
use App\Services\Ai\AiSettingsService;
use App\Services\Ai\AiTask;
use App\Services\Ai\JsonResponseParser;
use App\Services\Ai\OpenAiCompatibleClient;
use App\Services\Enrichment\EnrichmentSlots;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use RuntimeException;

final class PriceListAiAnalyzer
{
    public function __construct(
        private readonly PriceListStructureSampler $sampler,
        private readonly OpenAiCompatibleClient $llm,
        private readonly PriceListPdfTextExtractor $pdfExtractor,
        private readonly PdfEmbeddedImageExtractor $pdfImageExtractor,
        private readonly PdfPriceRowParser $priceRowParser,
        private readonly AnsellEmaPdfParser $ansellEmaParser,
        private readonly JsGlovesPdfParser $jsGlovesParser,
        private readonly RenexPdfParser $renexPdfParser,
        private readonly SungbooPdfParser $sungbooPdfParser,
        private readonly PdfGenericSkuPriceParser $genericSkuPriceParser,
        private readonly JsonResponseParser $jsonParser,
        private readonly CurrencyDetector $currencyDetector,
        private readonly PriceListMetaDetector $metaDetector,
        private readonly SpreadsheetMappingHeuristic $spreadsheetHeuristic,
        private readonly PriceListSampleRoleResearcher $sampleRoleResearcher,
        private readonly EnrichmentSlots $enrichmentSlots,
        private readonly PdfDocumentGroupAssigner $groupAssigner,
    ) {}

    /**
     * @return array{
     *     source: string,
     *     mapping: array<string, mixed>|null,
     *     products: list<array<string, mixed>>,
     *     preview: list<array<string, mixed>>,
     *     products_found: int,
     *     rows_total: int,
     *     skipped: int,
     *     errors_count: int,
     *     sample: array<string, mixed>,
     *     model: string,
     *     meta?: array{manufacturer: string, version: string, source: string}
     * }
     */
    public function analyze(string $path, ?string $manufacturerHint = null, ?string $originalName = null): array
    {
        if ($this->isPdf($path, $originalName)) {
            return $this->analyzePdf($path, $manufacturerHint, $originalName);
        }

        return $this->analyzeSpreadsheet($path, $manufacturerHint, $originalName);
    }

    /**
     * @return array<string, mixed>
     */
    private function analyzeSpreadsheet(string $path, ?string $manufacturerHint, ?string $originalName = null): array
    {
        $sample = $this->sampler->sample($path);
        if ($sample['sheets'] === []) {
            throw new RuntimeException('Plik nie zawiera arkuszy do analizy.');
        }

        // 1) Research jednej pozycji (opcjonalnie z sieci) — model vs nazwa vs article
        $sampleResearch = null;
        try {
            $sampleResearch = $this->sampleRoleResearcher->research($sample, $manufacturerHint);
        } catch (\Throwable) {
            $sampleResearch = null;
        }

        $prompt = $this->buildPrompt($sample, $manufacturerHint, $sampleResearch);
        $messages = [
            [
                'role' => 'system',
                'content' => 'Jesteś ekspertem od importu cenników BHP do systemu ERP. '
                    .'Odpowiadasz wyłącznie poprawnym JSON zgodnym ze schematem użytkownika. '
                    .'Indeksy kolumn są 0-based. header_excel_row to numer wiersza Excel (1-based) z sample_rows[].excel_row. '
                    .'Gdy podano sample_role_research — traktuj je jako źródło prawdy dla ról kolumn.',
            ],
            ['role' => 'user', 'content' => $prompt],
        ];
        $model = 'heuristic';
        $mapping = null;
        $aiReady = app(AiSettingsService::class)->isReady();

        if ($aiReady) {
            try {
                $raw = $this->llm->chat($messages, null, true, null, AiTask::PriceListPdf);
                $model = $raw['model'];
                try {
                    $json = $this->jsonParser->parse($raw['content']);
                } catch (RuntimeException) {
                    $json = $this->llm->chatJson([
                        ...$messages,
                        [
                            'role' => 'user',
                            'content' => 'Poprzednia odpowiedź była niepoprawna. Zwróć WYŁĄCZNIE jeden obiekt JSON zgodny ze schematem.',
                        ],
                    ], null, null, null, AiTask::PriceListPdf);
                }
                $mapping = $this->normalizeMapping($json);
            } catch (RuntimeException) {
                $mapping = null;
            }
        }

        if ($mapping === null) {
            $mapping = $this->spreadsheetHeuristic->detect($path);
            if ($mapping === null) {
                throw new RuntimeException(
                    'Nie znaleziono arkusza z produktami (AI + heurystyka). '
                    .'Sprawdź, czy w pliku jest tabela z nazwą i ceną.'
                );
            }
            $model = $aiReady ? $model.'+heuristic-fallback' : 'heuristic-xlsx';
        }

        if ($sampleResearch !== null) {
            $mapping = $this->sampleRoleResearcher->applyToMapping($mapping, $sampleResearch);
            $model .= '+sample-research';
        }

        $mapping = app(SpreadsheetColumnMapper::class)->refineMapping($path, $mapping);

        $stats = $this->buildPreview($path, $mapping);
        if (($stats['products_found'] ?? 0) === 0) {
            $fallback = $this->spreadsheetHeuristic->detect($path);
            if ($fallback !== null) {
                $mapping = app(SpreadsheetColumnMapper::class)->refineMapping($path, $fallback);
                $stats = $this->buildPreview($path, $mapping);
                $model .= '+heuristic-empty';
            }
        }
        $allProducts = is_array($stats['products'] ?? null) ? $stats['products'] : [];
        $mapping['currency'] = $mapping['currency']
            ?? $this->majorityCurrency($allProducts)
            ?? 'PLN';

        $preview = [];
        foreach (array_slice($stats['items'], 0, 12) as $item) {
            if (! is_array($item)) {
                continue;
            }
            if (! isset($item['currency']) || ! is_string($item['currency']) || $item['currency'] === '') {
                $item['currency'] = $mapping['currency'];
            }
            $preview[] = $item;
        }

        $textHint = $this->sampleTextHint($sample);
        $meta = $this->metaDetector->resolve(
            $manufacturerHint,
            $originalName,
            is_string($mapping['manufacturer_detected'] ?? null) ? $mapping['manufacturer_detected'] : null,
            $textHint,
            null,
        );
        $mapping['manufacturer_detected'] = $meta['manufacturer'];

        $assortmentGroups = app(AssortmentGroupService::class)->summarize(
            $allProducts,
            $meta['manufacturer'],
        );

        // Duże XLSX: NIE zwracamy pełnej listy produktów w JSON (psuje odpowiedź HTTP).
        // Import idzie przez mapping na serwerze.
        return [
            'source' => 'spreadsheet',
            'mapping' => $mapping,
            'products' => [],
            'preview' => $preview,
            'products_found' => $stats['products_found'],
            'rows_total' => $stats['rows_total'],
            'skipped' => $stats['skipped'],
            'errors_count' => $stats['errors_count'],
            'assortment_groups' => $assortmentGroups,
            'sample' => [
                'sheets' => array_map(
                    static fn (array $s): array => [
                        'name' => $s['name'],
                        'rows_total' => $s['rows_total'],
                        'likely_product_sheet' => $s['likely_product_sheet'],
                    ],
                    $sample['sheets']
                ),
            ],
            'model' => $model,
            'meta' => $meta,
            'sample_role_research' => $sampleResearch,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function analyzePdf(string $path, ?string $manufacturerHint, ?string $originalName = null): array
    {
        $fromName = $this->metaDetector->fromFilename($originalName);
        $effectiveHint = $fromName['manufacturer'] ?? $manufacturerHint;

        $hint = $effectiveHint !== null && $effectiveHint !== ''
            ? "Producent prawdopodobny (z nazwy pliku/formularza): {$effectiveHint}. Zweryfikuj po treści PDF."
            : 'Wykryj producenta z PDF (np. PROS / AJ Group / Ansell / 3M / Debstoko).';

        $prompt = $this->pdfCompactChunkPrompt($hint);
        $fileSize = is_file($path) ? (int) filesize($path) : 0;
        $aiReady = app(AiSettingsService::class)->isReady();

        $text = null;
        $extractError = null;
        try {
            $text = $this->pdfExtractor->extract($path);
        } catch (\Throwable $e) {
            $extractError = $e->getMessage();
        }
        $isScan = $text === null || mb_strlen($text) < 40;
        $heuristicPros = [];
        $heuristicEma = [];
        $heuristicJs = [];
        $heuristicRenex = [];
        $heuristicSungboo = [];
        $heuristicGeneric = [];
        $heuristic = [];
        $sungbooText = is_string($text) ? $text : '';
        if (is_string($text)) {
            $heuristicPros = $this->priceRowParser->parse($text, $manufacturerHint);
            $heuristicEma = $this->ansellEmaParser->parse($text, $manufacturerHint);
            $heuristicJs = $this->jsGlovesParser->looksLike($text)
                ? $this->jsGlovesParser->parse($text, $manufacturerHint)
                : [];
            $heuristicRenex = $this->renexPdfParser->parse($text);
            $heuristicGeneric = $this->genericSkuPriceParser->parse($text);
            $sungbooText = $text;
            if ($this->sungbooPdfParser->looksLike($text)) {
                $layout = $this->pdfExtractor->extractLayout($path);
                if (is_string($layout) && $layout !== '') {
                    $sungbooText = $layout;
                }
                $heuristicSungboo = $this->sungbooPdfParser->parse($sungbooText);
            }
            $heuristic = $this->uniqueBySku(array_merge(
                $this->normalizeProducts($heuristicEma, $manufacturerHint ?: 'Ansell'),
                $this->normalizeProducts($heuristicPros, $manufacturerHint),
                $this->normalizeProducts($heuristicJs, $manufacturerHint ?: 'JS GLOVES'),
                $this->normalizeProducts(
                    array_values(array_filter(
                        $heuristicRenex,
                        static fn (array $row): bool => is_numeric($row['catalog_price'] ?? null)
                    )),
                    $manufacturerHint ?: 'RENEX'
                ),
                $this->normalizeProducts($heuristicSungboo, $manufacturerHint ?: 'SUNGBOO'),
                $this->normalizeProducts($heuristicGeneric, $manufacturerHint),
            ));
        }
        if ($heuristic !== [] && count($heuristic) >= 5) {
            return $this->heuristicPdfResult(
                $heuristic,
                $heuristicEma,
                $heuristicPros,
                $heuristicJs,
                $heuristicSungboo,
                $heuristicSungboo !== [] ? $sungbooText : ($text ?? ''),
                $fileSize,
                $manufacturerHint,
                $originalName,
                $fromName['version'] ?? null,
            );
        }

        $visionError = null;
        if ($aiReady && $isScan) {
            try {
                $fromImages = $this->analyzePdfViaPageImages(
                    $path,
                    $prompt,
                    $manufacturerHint,
                    $originalName,
                    $fileSize,
                );
                if ($fromImages !== null) {
                    return $fromImages;
                }
                $visionError = 'Brak stron-obrazów do odczytu skanu.';
            } catch (\Throwable $e) {
                $visionError = $e->getMessage();
            }
        }

        if ($isScan) {
            throw new RuntimeException(
                'PDF jest skanem (brak warstwy tekstowej). '
                .($visionError ?? $extractError ?? 'Wgraj XLSX albo PDF z zaznaczalnym tekstem.')
            );
        }

        if ($heuristic === [] && ! $this->pdfExtractor->looksLikePricelist($text)) {
            $letter = is_string($originalName) && preg_match('/letter|okladka|okładka|pismo/i', $originalName) === 1;
            $dupont = $letter || (is_string($originalName) && preg_match('/dupont|tyvek/i', $originalName) === 1);
            $msg = $letter
                ? 'Ten PDF to list / okładka, nie tabela cennika. '
                : 'W PDF nie ma tabeli z kodami i cenami. ';
            $msg .= $dupont
                ? 'Dla DuPont wgraj arkusz XLSX (Reference, Article Number, Price) '
                    .'z tego samego pakietu — nie plik „letter”.'
                : 'Wgraj XLSX albo PDF z cenami w warstwie tekstowej.';
            throw new RuntimeException($msg);
        }

        $chunks = $aiReady ? $this->pdfExtractor->chunkByPriceBudget($text) : [];
        $aiProducts = [];
        $model = $aiReady ? 'text-chunks' : 'heuristic-only';
        $json = [
            'notes' => '',
            'manufacturer_detected' => $manufacturerHint,
            'currency' => null,
        ];
        $chunkErrors = 0;

        if (! $aiReady && $heuristic === []) {
            throw new RuntimeException(
                'Brak pozycji z heurystyki PDF oraz brak konfiguracji AI. '
                .'Uzupełnij Ustawienia AI albo użyj XLSX (Import prosty).'
            );
        }

        $ran = $this->runPdfChunksOnWorkers($chunks, $prompt, $manufacturerHint);
        if ($ran === null) {
            $ran = $this->runPdfChunksInProcess($chunks, $prompt, $manufacturerHint);
        }
        $aiProducts = $ran['products'];
        $model = $ran['model'] !== '' ? $ran['model'] : $model;
        $json['manufacturer_detected'] = $ran['manufacturer'] ?? $json['manufacturer_detected'];
        $json['currency'] = $ran['currency'] ?? $json['currency'];
        $chunkErrors = $ran['errors'];

        $aiProducts = $this->uniqueBySku($aiProducts);
        $products = $this->mergePdfProducts($aiProducts, $heuristic, $manufacturerHint);
        $manufForGroups = $this->metaDetector->resolve(
            $manufacturerHint,
            $originalName,
            null,
            mb_substr($text, 0, 4000),
            $fromName['version'] ?? null,
        )['manufacturer'];
        $products = $this->groupAssigner->assign($products, $text, $manufForGroups);

        if ($products === []) {
            $msg = 'Nie udało się odczytać pozycji z PDF (duży plik: '
                .round($fileSize / 1_000_000, 1).' MB, tekst: '.mb_strlen($text)
                .' znaków, części: '.count($chunks)
                .', błędy AI: '.$chunkErrors
                .', heurystyka EMA: '.count($heuristicEma)
                .', PROS: '.count($heuristicPros).').';
            throw new RuntimeException($msg);
        }

        $notes = trim(($json['notes'] ?? '').' Analiza PDF: '.count($products).' SKU w '.count($chunks).' częściach.');
        $json['notes'] = $notes;

        return $this->pdfResult(
            $products,
            $json,
            $model,
            'pdf-chunks',
            [
                'file_mb' => round($fileSize / 1_000_000, 2),
                'pdf_chars' => mb_strlen($text),
                'chunks' => count($chunks),
                'heuristic_ema' => count($heuristicEma),
                'heuristic_pros' => count($heuristicPros),
            ],
            $originalName,
            null,
            mb_substr($text, 0, 4000),
            $manufacturerHint,
        );
    }

    /**
     * @param  list<string>  $chunks
     * @return array{products: list<array<string, mixed>>, model: string, manufacturer: mixed, currency: mixed, errors: int}|null
     */
    private function runPdfChunksOnWorkers(array $chunks, string $prompt, ?string $manufacturerHint): ?array
    {
        if ($chunks === [] || config('queue.default') === 'sync') {
            return null;
        }
        $runId = 'pdfai_'.bin2hex(random_bytes(8));
        foreach ($chunks as $i => $chunk) {
            AnalyzePriceListPdfChunkJob::dispatch(
                $runId,
                $i,
                count($chunks),
                $chunk,
                $prompt,
                $manufacturerHint,
            );
        }
        $deadline = microtime(true) + 4.0;
        $taken = false;
        while (microtime(true) < $deadline) {
            if ($this->pdfRunTouchedByWorker($runId, count($chunks))) {
                $taken = true;
                break;
            }
            usleep(200_000);
        }
        if (! $taken) {
            $this->discardPdfChunkJobs($runId);

            return null;
        }

        $waitUntil = microtime(true) + 3300;
        while (microtime(true) < $waitUntil) {
            if (function_exists('set_time_limit')) {
                @set_time_limit(600);
            }
            if ($this->pdfRunResultsReady($runId, count($chunks))) {
                return $this->collectPdfChunkResults($runId, count($chunks), $manufacturerHint);
            }
            usleep(400_000);
        }
        $this->discardPdfChunkJobs($runId);

        return $this->collectPdfChunkResults($runId, count($chunks), $manufacturerHint);
    }

    /**
     * @param  list<string>  $chunks
     * @return array{products: list<array<string, mixed>>, model: string, manufacturer: mixed, currency: mixed, errors: int}
     */
    private function runPdfChunksInProcess(array $chunks, string $prompt, ?string $manufacturerHint): array
    {
        $pending = [];
        foreach ($chunks as $idx => $chunk) {
            $pending[] = ['idx' => $idx, 'text' => $chunk];
        }
        $total = count($chunks);
        $aiProducts = [];
        $model = '';
        $manufacturer = null;
        $currency = null;
        $chunkErrors = 0;
        while ($pending !== []) {
            if (function_exists('set_time_limit')) {
                @set_time_limit(600);
            }
            $locks = $this->acquirePdfWaveSlots(min(count($pending), $this->enrichmentSlots->limit()));
            $batch = array_splice($pending, 0, count($locks));
            $messageSets = [];
            foreach ($batch as $item) {
                $messageSets[] = $this->pdfChunkMessages($prompt, $item['idx'] + 1, $total, $item['text']);
            }
            try {
                $results = $this->llm->chatMany($messageSets, true, ['max_tokens' => 800], AiTask::PriceListPdf);
                foreach ($results as $i => $result) {
                    if (! ($result['ok'] ?? false)) {
                        try {
                            $raw = $this->llm->chat($messageSets[$i], null, true, ['max_tokens' => 800], AiTask::PriceListPdf);
                            $result = ['ok' => true, 'content' => $raw['content'], 'model' => $raw['model']];
                        } catch (\Throwable) {
                            $chunkErrors++;

                            continue;
                        }
                    }
                    $parsed = $this->productsFromChunkContent(
                        (string) ($result['content'] ?? ''),
                        (string) ($result['model'] ?? ''),
                        $manufacturerHint
                    );
                    $model = $parsed['model'] !== '' ? $parsed['model'] : $model;
                    $manufacturer ??= $parsed['manufacturer'];
                    $currency ??= $parsed['currency'];
                    $aiProducts = array_merge($aiProducts, $parsed['products']);
                    $chunkErrors += $parsed['errors'];
                }
            } finally {
                foreach ($locks as $lock) {
                    try {
                        $lock->release();
                    } catch (\Throwable) {
                    }
                }
            }
        }

        return [
            'products' => $aiProducts,
            'model' => $model,
            'manufacturer' => $manufacturer,
            'currency' => $currency,
            'errors' => $chunkErrors,
        ];
    }

    /**
     * @return array{products: list<array<string, mixed>>, model: string, manufacturer: mixed, currency: mixed, errors: int}
     */
    private function collectPdfChunkResults(string $runId, int $total, ?string $manufacturerHint): array
    {
        $aiProducts = [];
        $model = '';
        $manufacturer = null;
        $currency = null;
        $chunkErrors = 0;
        for ($i = 0; $i < $total; $i++) {
            $raw = Cache::get(AnalyzePriceListPdfChunkJob::resultKey($runId, $i));
            if (! is_array($raw) || ! ($raw['ok'] ?? false)) {
                $chunkErrors++;

                continue;
            }
            $parsed = $this->productsFromChunkContent(
                (string) ($raw['content'] ?? ''),
                (string) ($raw['model'] ?? ''),
                $manufacturerHint
            );
            $model = $parsed['model'] !== '' ? $parsed['model'] : $model;
            $manufacturer ??= $parsed['manufacturer'];
            $currency ??= $parsed['currency'];
            $aiProducts = array_merge($aiProducts, $parsed['products']);
            $chunkErrors += $parsed['errors'];
        }

        return [
            'products' => $aiProducts,
            'model' => $model,
            'manufacturer' => $manufacturer,
            'currency' => $currency,
            'errors' => $chunkErrors,
        ];
    }

    /**
     * @param  array<string, mixed>  $json
     * @return list<array<string, mixed>>
     */
    private function rowsFromAiChunk(array $json): array
    {
        $rows = $json['products'] ?? $json['p'] ?? [];
        if (! is_array($rows)) {
            return [];
        }
        $out = [];
        foreach ($rows as $row) {
            if (! is_array($row)) {
                continue;
            }
            if (array_is_list($row) && isset($row[0], $row[2])) {
                $price = $row[2];
                $group = isset($row[3]) && is_string($row[3]) && trim($row[3]) !== ''
                    ? trim($row[3])
                    : null;
                $out[] = [
                    'sku' => (string) $row[0],
                    'name' => (string) ($row[1] ?? $row[0]),
                    'catalog_price' => $price,
                    'discount' => 0,
                    'purchase' => $price,
                    'category' => $group,
                ];

                continue;
            }
            $out[] = $row;
        }

        return $out;
    }

    /**
     * @return array{products: list<array<string, mixed>>, model: string, manufacturer: mixed, currency: mixed, errors: int}
     */
    private function productsFromChunkContent(string $content, string $model, ?string $manufacturerHint): array
    {
        try {
            $partJson = $this->jsonParser->parse($content);

            return [
                'products' => $this->normalizeProducts($this->rowsFromAiChunk($partJson), $manufacturerHint),
                'model' => $model,
                'manufacturer' => is_string($partJson['manufacturer_detected'] ?? $partJson['m'] ?? null)
                    ? ($partJson['manufacturer_detected'] ?? $partJson['m'])
                    : null,
                'currency' => is_string($partJson['currency'] ?? $partJson['c'] ?? null)
                    ? ($partJson['currency'] ?? $partJson['c'])
                    : null,
                'errors' => 0,
            ];
        } catch (\Throwable) {
            return [
                'products' => [],
                'model' => $model,
                'manufacturer' => null,
                'currency' => null,
                'errors' => 1,
            ];
        }
    }

    private function pdfRunTouchedByWorker(string $runId, int $total): bool
    {
        for ($i = 0; $i < $total; $i++) {
            if (Cache::has(AnalyzePriceListPdfChunkJob::resultKey($runId, $i))) {
                return true;
            }
        }
        if (! Schema::hasTable('jobs')) {
            return false;
        }

        return DB::table('jobs')
            ->where('queue', EnrichProductJob::QUEUE)
            ->whereNotNull('reserved_at')
            ->where('payload', 'like', '%'.$runId.'%')
            ->exists();
    }

    private function pdfRunResultsReady(string $runId, int $total): bool
    {
        for ($i = 0; $i < $total; $i++) {
            if (! Cache::has(AnalyzePriceListPdfChunkJob::resultKey($runId, $i))) {
                return false;
            }
        }

        return true;
    }

    private function discardPdfChunkJobs(string $runId): void
    {
        if (! Schema::hasTable('jobs')) {
            return;
        }
        DB::table('jobs')
            ->where('queue', EnrichProductJob::QUEUE)
            ->where('payload', 'like', '%'.$runId.'%')
            ->delete();
    }

    /**
     * @return list<\Illuminate\Contracts\Cache\Lock>
     */
    private function acquirePdfWaveSlots(int $want): array
    {
        $want = max(1, $want);
        $locks = $this->enrichmentSlots->tryAcquireMany($want, 600);
        if ($locks !== []) {
            return $locks;
        }
        $wait = (float) config('ai.enrichment_slot_wait_seconds', 120);
        $one = $this->enrichmentSlots->acquire(600, $wait);
        if ($one === null) {
            $one = $this->enrichmentSlots->acquire(600, 180.0);
        }
        if ($one === null) {
            throw new RuntimeException(
                'Wszystkie sloty AI są zajęte (limit z Ustawień AI). Poczekaj, aż skończy się enrichment, i ponów analizę.'
            );
        }

        return [$one];
    }

    /**
     * @return list<array{role: string, content: string}>
     */
    private function pdfChunkMessages(string $prompt, int $part, int $total, string $chunk): array
    {
        return [
            [
                'role' => 'system',
                'content' => 'Zwracasz wyłącznie krótki JSON. Bez markdown i bez komentarzy.',
            ],
            [
                'role' => 'user',
                'content' => $prompt."\n\nCZĘŚĆ {$part}/{$total}:\n".$chunk,
            ],
        ];
    }

    /**
     * @return array<string, mixed>|null
     */
    private function analyzePdfViaPageImages(
        string $path,
        string $prompt,
        ?string $manufacturerHint,
        ?string $originalName,
        int $fileSize,
        string $kind = 'pages',
        int $maxImages = 2,
    ): ?array {
        $maxEdge = $kind === 'price_bitmaps' ? 800 : 1024;
        $images = $this->pdfImageExtractor->prepareForVision(
            $this->pdfImageExtractor->extract($path, $maxImages, $kind),
            $maxEdge,
            60,
        );
        if ($images === []) {
            return null;
        }

        $aiProducts = [];
        $model = 'pdf-vision-images';
        $json = [
            'notes' => '',
            'manufacturer_detected' => $manufacturerHint,
            'currency' => 'PLN',
        ];
        $lastError = null;
        try {
            @set_time_limit(240);
            $raw = $this->llm->chatWithPageImages($prompt, $images, AiTask::PriceListPdf);
            $model = $raw['model'];
            $partJson = $this->jsonParser->parse($raw['content']);
            if (is_string($partJson['manufacturer_detected'] ?? $partJson['m'] ?? null)) {
                $json['manufacturer_detected'] = $partJson['manufacturer_detected'] ?? $partJson['m'];
            }
            if (is_string($partJson['currency'] ?? $partJson['c'] ?? null)) {
                $json['currency'] = $partJson['currency'] ?? $partJson['c'];
            }
            if (is_string($partJson['notes'] ?? null) && ($json['notes'] ?? '') === '') {
                $json['notes'] = $partJson['notes'];
            }
            $aiProducts = $this->normalizeProducts($this->rowsFromAiChunk($partJson), $manufacturerHint);
        } catch (\Throwable $e) {
            $lastError = $e->getMessage();
        }

        $products = $this->uniqueBySku($aiProducts);
        if ($products === []) {
            throw new RuntimeException(
                $lastError ?? 'Model nie zwrócił pozycji ze skanu PDF.'
            );
        }
        if (! $this->looksLikeGoodPdfExtract($products)) {
            throw new RuntimeException(
                'Model źle odczytał skan PDF (brak nazw/cen).'
                .($lastError !== null ? ' '.$lastError : '')
            );
        }

        $json['notes'] = trim((string) ($json['notes'] ?? '').' Odczyt skanu PDF: '.count($products).' SKU.');

        return $this->pdfResult(
            $products,
            $json,
            $model,
            'pdf-vision-images',
            [
                'mode' => 'page-images',
                'file_mb' => round($fileSize / 1_000_000, 2),
                'pages' => count($images),
            ],
            $originalName,
            null,
            null,
            $manufacturerHint,
        );
    }

    /**
     * @param  list<array<string, mixed>>  $products
     * @return list<array<string, mixed>>
     */
    private function uniqueBySku(array $products): array
    {
        $bySku = [];
        foreach ($products as $p) {
            $sku = (string) ($p['sku'] ?? '');
            if ($sku === '') {
                continue;
            }
            $bySku[$sku] = $p;
        }

        return array_values($bySku);
    }

    /**
     * @param  list<array<string, mixed>>  $heuristic
     * @param  list<array<string, mixed>>  $heuristicEma
     * @param  list<array<string, mixed>>  $heuristicPros
     * @param  list<array<string, mixed>>  $heuristicJs
     * @param  list<array<string, mixed>>  $heuristicSungboo
     * @return array<string, mixed>
     */
    private function heuristicPdfResult(
        array $heuristic,
        array $heuristicEma,
        array $heuristicPros,
        array $heuristicJs,
        array $heuristicSungboo,
        string $text,
        int $fileSize,
        ?string $manufacturerHint,
        ?string $originalName,
        ?string $versionHint,
    ): array {
        $aiGuess = $heuristicEma !== []
            ? 'Ansell'
            : ($heuristicJs !== [] ? 'JS GLOVES'
                : ($heuristicSungboo !== [] ? 'SUNGBOO'
                    : ($heuristicPros !== [] ? 'PROS' : ($manufacturerHint ?: ''))));
        $meta = $this->metaDetector->resolve(
            $manufacturerHint,
            $originalName,
            $aiGuess,
            mb_substr($text, 0, 4000),
            $versionHint,
        );
        foreach ($heuristic as $i => $row) {
            $heuristic[$i]['manufacturer'] = $meta['manufacturer'];
        }
        $heuristic = $this->groupAssigner->assign($heuristic, $text, $meta['manufacturer']);
        $docCurrency = $this->majorityCurrency($heuristic)
            ?? $this->currencyDetector->detect($text)
            ?? ($heuristicEma !== [] ? 'EUR' : 'PLN');

        return $this->pdfResult(
            $heuristic,
            [
                'manufacturer_detected' => $meta['manufacturer'],
                'currency' => $docCurrency,
                'notes' => 'Odczyt heurystyczny z tekstu PDF ('.count($heuristic).' SKU).',
                'sheets' => [],
            ],
            'heuristic-pdf',
            'pdf-heuristic',
            [
                'file_mb' => round($fileSize / 1_000_000, 2),
                'pdf_chars' => mb_strlen($text),
                'heuristic_ema' => count($heuristicEma),
                'heuristic_pros' => count($heuristicPros),
                'heuristic_js_gloves' => count($heuristicJs),
            ],
            $originalName,
            $meta,
        );
    }

    private function pdfCompactChunkPrompt(string $hint): string
    {
        return <<<PROMPT
{$hint}
Wypisz produkty z tekstu. Tylko JSON:
{"c":"PLN","p":[["SKU","nazwa",12.5,"grupa"]]}
p = [kod, nazwa, cena, grupa z nagłówka sekcji]. Bez markdown.
PROMPT;
    }

    /**
     * @param  list<array<string, mixed>>  $products
     */
    private function looksLikeGoodPdfExtract(array $products): bool
    {
        if ($products === []) {
            return false;
        }
        $withNames = 0;
        $withPurchase = 0;
        foreach (array_slice($products, 0, 20) as $p) {
            $name = (string) ($p['name'] ?? '');
            if ($name !== '' && ! str_starts_with($name, 'Pozycja ') && ! str_starts_with($name, 'Produkt ')) {
                $withNames++;
            }
            if ((float) ($p['purchase_price'] ?? 0) > 0) {
                $withPurchase++;
            }
            $sku = (string) ($p['sku'] ?? '');
            if (preg_match('/^\d+[.,]\d{2}$/', $sku) === 1) {
                return false; // sku wygląda jak cena
            }
        }

        return $withPurchase >= max(1, (int) floor(count(array_slice($products, 0, 20)) * 0.5))
            || $withNames >= 3;
    }

    /**
     * @param  list<array<string, mixed>>  $products
     */
    private function hasRealNames(array $products): bool
    {
        foreach (array_slice($products, 0, 15) as $p) {
            $name = (string) ($p['name'] ?? '');
            if ($name !== '' && ! str_contains($name, 'uzupełnij nazwę') && ! str_starts_with($name, 'Produkt ')) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param  list<array<string, mixed>>  $ai
     * @param  list<array<string, mixed>>  $heuristic
     * @return list<array<string, mixed>>
     */
    private function mergePdfProducts(array $ai, array $heuristic, ?string $manufacturerHint): array
    {
        if ($ai !== [] && $this->looksLikeGoodPdfExtract($ai)) {
            return $ai;
        }
        if ($heuristic !== []) {
            // jeśli AI ma nazwy w tej samej kolejności — przepisz nazwy
            $merged = $this->normalizeProducts($heuristic, $manufacturerHint);
            if ($ai !== []) {
                foreach ($merged as $i => $row) {
                    if (! isset($ai[$i])) {
                        break;
                    }
                    $aiName = (string) ($ai[$i]['name'] ?? '');
                    if ($aiName !== '' && ! str_starts_with($aiName, 'Produkt ') && ! str_contains($aiName, 'uzupełnij')) {
                        $merged[$i]['name'] = $aiName;
                        $sku = (string) ($ai[$i]['sku'] ?? '');
                        if ($sku !== '' && preg_match('/^\d+[.,]\d{2}$/', $sku) !== 1) {
                            $merged[$i]['sku'] = $sku;
                        }
                    }
                }
            }

            return $merged;
        }

        return $ai;
    }

    /**
     * @param  list<array<string, mixed>>  $products
     * @param  array<string, mixed>  $json
     * @param  array<string, mixed>  $sample
     * @param  array{manufacturer: string, version: string, source: string}|null  $meta
     * @return array<string, mixed>
     */
    private function pdfResult(
        array $products,
        array $json,
        string $model,
        string $source,
        array $sample,
        ?string $originalName = null,
        ?array $meta = null,
        ?string $textSample = null,
        ?string $userHint = null,
    ): array {
        $meta ??= $this->metaDetector->resolve(
            $userHint,
            $originalName,
            is_string($json['manufacturer_detected'] ?? null) ? $json['manufacturer_detected'] : null,
            $textSample,
            null,
        );

        $docCurrency = $this->majorityCurrency($products)
            ?? (is_string($json['currency'] ?? null)
                ? $this->currencyDetector->normalize($json['currency'], 'PLN')
                : 'PLN');

        foreach ($products as $i => $p) {
            if (! isset($p['currency']) || ! is_string($p['currency']) || $p['currency'] === '') {
                $products[$i]['currency'] = $docCurrency;
            } else {
                $products[$i]['currency'] = $this->currencyDetector->normalize($p['currency'], $docCurrency);
            }
            $products[$i]['manufacturer'] = $meta['manufacturer'];
        }

        // Ogranicz payload HTTP — bardzo duże listy PDF mogą psuć JSON w przeglądarce.
        $maxProductsPayload = 12000;
        $payloadProducts = count($products) > $maxProductsPayload
            ? array_slice($products, 0, $maxProductsPayload)
            : $products;

        $assortmentGroups = app(AssortmentGroupService::class)->summarize(
            $products,
            $meta['manufacturer'],
        );

        return [
            'source' => $source,
            'mapping' => [
                'manufacturer_detected' => $meta['manufacturer'],
                'currency' => $docCurrency,
                'notes' => is_string($json['notes'] ?? null) ? $json['notes'] : 'Import z PDF',
                'sheets' => [],
            ],
            'products' => $payloadProducts,
            'preview' => array_slice($products, 0, 12),
            'products_found' => count($products),
            'rows_total' => count($products),
            'skipped' => 0,
            'errors_count' => 0,
            'assortment_groups' => $assortmentGroups,
            'sample' => $sample,
            'model' => $model,
            'products_truncated' => count($products) > $maxProductsPayload,
            'meta' => $meta,
        ];
    }

    /**
     * @param  array{sheets: list<array<string, mixed>>}  $sample
     */
    private function sampleTextHint(array $sample): string
    {
        $parts = [];
        foreach ($sample['sheets'] as $sheet) {
            $parts[] = (string) ($sheet['name'] ?? '');
            foreach (array_slice($sheet['sample_rows'] ?? [], 0, 6) as $row) {
                if (! is_array($row)) {
                    continue;
                }
                $parts[] = implode(' ', array_map('strval', $row['cells'] ?? []));
            }
        }

        return mb_substr(implode("\n", $parts), 0, 4000);
    }

    private function isPdf(string $path, ?string $originalName): bool
    {
        $name = mb_strtolower($originalName ?? basename($path));
        if (str_ends_with($name, '.pdf') || str_ends_with($name, '..pdf')) {
            return true;
        }
        $mime = @mime_content_type($path);

        return is_string($mime) && str_contains($mime, 'pdf');
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function normalizeProducts(mixed $rows, ?string $manufacturerHint): array
    {
        if (! is_array($rows)) {
            return [];
        }
        $out = [];
        $i = 0;
        foreach ($rows as $row) {
            if (! is_array($row)) {
                continue;
            }
            $i++;
            $name = trim((string) ($row['name'] ?? $row['opis'] ?? ''));
            $sku = trim((string) ($row['sku'] ?? $row['symbol'] ?? $row['kod'] ?? $row['model'] ?? ''));
            // odrzuć SKU będące ceną (np. 80.92 albo 3280.92)
            if ($sku !== '' && preg_match('/^\d+[.,]\d{2}$/', $sku) === 1) {
                $sku = '';
            }
            $price = $row['catalog_price'] ?? $row['catalog_price_net'] ?? $row['cena_katalogowa'] ?? null;
            if ($price === null || ! is_numeric($price)) {
                continue;
            }
            if ($name === '') {
                $name = sprintf('Pozycja %d (uzupełnij nazwę z PDF)', $i);
            }
            if ($sku === '') {
                $slug = strtoupper(preg_replace('/[^A-Za-z0-9]+/', '-', $name) ?? 'POZ');
                $slug = trim(mb_substr($slug, 0, 24), '-');
                $sku = ($slug !== '' ? $slug : 'POZ').'-'.sprintf('%03d', $i);
            }

            $discount = $row['discount'] ?? $row['discount_percent'] ?? $row['upust'] ?? 0;
            $discount = is_numeric($discount) ? (float) $discount : 0.0;
            $purchase = $row['purchase'] ?? $row['purchase_price'] ?? $row['cena_po_upuscie'] ?? null;
            $purchase = is_numeric($purchase) ? (float) $purchase : null;
            if ($purchase === null) {
                $purchase = round((float) $price * (1 - ($discount / 100)), 2);
            }
            $packQty = $row['pack_qty'] ?? null;
            $packaging = isset($row['packaging']) ? trim((string) $row['packaging']) : '';

            $currency = null;
            if (isset($row['currency']) && is_string($row['currency'])) {
                $currency = $this->currencyDetector->normalize($row['currency'], null);
            }
            if ($currency === null) {
                $currency = $this->currencyDetector->detect($name)
                    ?? $this->currencyDetector->normalize(null, 'PLN');
            }

            $out[] = [
                'sku' => $sku,
                'name' => $name,
                'manufacturer' => $manufacturerHint ?: 'PDF',
                'ean' => null,
                'category' => isset($row['category']) && is_string($row['category']) ? $row['category'] : null,
                'norms' => null,
                'catalog_price_net' => (float) $price,
                'discount_percent' => $discount,
                'purchase_price' => $purchase,
                'currency' => $currency,
                'stock' => 0,
                'pack_qty' => is_numeric($packQty) ? max(0, (int) $packQty) : null,
                'packaging' => $packaging !== '' ? $packaging : null,
            ];
        }

        return $out;
    }

    /**
     * @param  list<array<string, mixed>>  $products
     */
    private function majorityCurrency(array $products): ?string
    {
        $counts = [];
        foreach ($products as $p) {
            $c = isset($p['currency']) && is_string($p['currency'])
                ? $this->currencyDetector->normalize($p['currency'], null)
                : null;
            if ($c === null) {
                continue;
            }
            $counts[$c] = ($counts[$c] ?? 0) + 1;
        }
        if ($counts === []) {
            return null;
        }
        arsort($counts);

        return array_key_first($counts);
    }

    /**
     * @param  array{sheets: list<array<string, mixed>>}  $sample
     */
    /**
     * @param  array{sheets: list<array<string, mixed>>}  $sample
     * @param  array<string, mixed>|null  $sampleResearch
     */
    private function buildPrompt(array $sample, ?string $manufacturerHint, ?array $sampleResearch = null): string
    {
        $payload = json_encode($sample, JSON_UNESCAPED_UNICODE);
        $hint = $manufacturerHint !== null && $manufacturerHint !== ''
            ? "Producent podany przez użytkownika: {$manufacturerHint}."
            : 'Producent nieznany — spróbuj wykryć z pliku.';

        $researchBlock = '';
        if ($sampleResearch !== null) {
            $researchJson = json_encode($sampleResearch, JSON_UNESCAPED_UNICODE);
            $researchBlock = <<<RES

SAMPLE_ROLE_RESEARCH (źródło prawdy dla 1 pozycji — użyj tych kolumn):
{$researchJson}
Przykład DuPont: model_key="TD 0125 S WH 00", name="NEW! TYVEK Dual Combi", sku=Article Number (D1468…), packaging=Size.
RES;
        }

        return <<<PROMPT
{$hint}
{$researchBlock}

Poniżej próbki arkuszy z pliku XLSX (sample_rows = kolejne niepuste wiersze od góry).
Zmapuj strukturę do importu produktów.

Zwróć JSON:
{
  "manufacturer_detected": "string|null",
  "currency": "EUR|PLN|USD|GBP|CHF|null",
  "notes": "krótki opis po polsku",
  "sheets": [
    {
      "sheet": "dokładna nazwa arkusza",
      "include": true,
      "header_excel_row": 7,
      "columns": {
        "sku": 0,
        "name": 1,
        "catalog_price": 2,
        "discount": null,
        "purchase": null,
        "pack_qty": null,
        "packaging": null,
        "model_key": null,
        "currency": null,
        "ean": null,
        "category": null
      },
      "repeating_headers": false,
      "confidence": 0.0
    }
  ]
}

Priorytet pól (to nas interesuje):
1) model_key = kod modelu / Reference / Base Style (np. TD 0125 S WH 00) — to jest właściwy KOD produktu w systemie.
2) sku (kolumna Article Number) mapuj na Article Number gdy jest — system po imporcie ustawi Kod=model_key;
   Article służy tylko do rozróżnienia wariantów gdy cena zależy od rozmiaru.
3) name = krótka nazwa handlowa (np. NEW! TYVEK Dual Combi), NIE sam Article i NIE sam Reference.
   Ta sama kolumna bywa też długim opisem EN — i tak mapuj ją na name; system rozdzieli tytuł/opis.
4) catalog_price = Nowa cena cennikowa / cena katalogowa / MSRP / Price List Price / Price (€/pc)
   — NIE „Cena aktualna na dzień” (to data), NIE „Cena za”, NIE transport, NIE cena kartonu.
   Gdy są „Aktualna” i „Nowa” — bierz NOWĄ.
5) discount = tylko kolumna procentu (rabat %, PL Discount). NIE „cena po upustach” i NIE „procentowa zmiana ceny”.
6) pack_qty = Quantity per box / ilość sztuk w kartonie / opakowaniu zbiorczym
7) packaging = Size / rozmiar / opakowanie / jednostka / pojemność
8) purchase = Nowa cena po upustach / Dealer / List Price minus Discount / Price 26
   — NIE „Wskaźnik zakupów”.
9) currency = kolumna waluty jeśli jest (EUR/PLN/USD); currency na poziomie pliku = dominująca z nagłówka (Price EUR, PLN, zł…)
10) category = Category/Type / kategoria / grupa
11) sku = Numer katalogowy produktu / Article Number / kod — NIE Kod EAN i NIE numer klienta.

Zasady:
- Często nad tabelą jest blok rabatów/kontaktów — header_excel_row to wiersz z „Kod produktu”/„Nazwa”/„Cena…”, nie wiersz 1.
- Nagłówki bywają PL+CZ+EN (Kod produktu, Nazwa, Article Number, Model Name and Description, CENA CENNIK).
- Układy z rozmiarami (S/M/L w kolejnych wierszach, nazwa tylko w 1. wierszu modelu) — i tak include=true; system uzupełni puste nazwy.
- include=true dla arkuszy z produktami nawet bez kolumny SKU (sku=null).
- Jeśli nagłówki powtarzają się w środku arkusza, ustaw repeating_headers=true.
- header_excel_row = sample_rows[].excel_row wiersza z nazwami kolumn.
- Nie ustawiaj include=false tylko dlatego, że brak kodu produktu.
- Gdy jest SAMPLE_ROLE_RESEARCH — skopiuj column z roles.* do columns.*.
- Arkusze Special Pricing / EUA Prices / Disclaimer / Languages / Cover: include=false (ceny kontraktowe klientów system bierze osobno).

Dane:
{$payload}
PROMPT;
    }

    /**
     * @param  array<string, mixed>  $json
     * @return array{
     *     manufacturer_detected: ?string,
     *     currency: ?string,
     *     notes: string,
     *     sheets: list<array<string, mixed>>
     * }
     */
    private function normalizeMapping(array $json): array
    {
        $sheetsIn = $json['sheets'] ?? [];
        if (! is_array($sheetsIn) || $sheetsIn === []) {
            throw new RuntimeException('AI nie zwróciło mapowania arkuszy.');
        }

        $sheets = [];
        foreach ($sheetsIn as $sheet) {
            if (! is_array($sheet)) {
                continue;
            }
            $cols = is_array($sheet['columns'] ?? null) ? $sheet['columns'] : [];
            $normCols = [];
            foreach (['sku', 'name', 'catalog_price', 'discount', 'purchase', 'pack_qty', 'packaging', 'model_key', 'currency', 'ean', 'category'] as $key) {
                $v = $cols[$key] ?? null;
                $normCols[$key] = is_numeric($v) ? (int) $v : null;
            }

            $headerExcel = (int) ($sheet['header_excel_row'] ?? $sheet['header_row'] ?? 1);
            if ($headerExcel < 1) {
                $headerExcel = 1;
            }

            $role = is_string($sheet['role'] ?? null) ? (string) $sheet['role'] : 'catalog';
            $hasRequired = $normCols['name'] !== null
                && $normCols['catalog_price'] !== null;

            $sheets[] = [
                'sheet' => (string) ($sheet['sheet'] ?? ''),
                'include' => (bool) ($sheet['include'] ?? false) && $hasRequired && $role === 'catalog',
                'header_excel_row' => $headerExcel,
                'columns' => $normCols,
                'repeating_headers' => (bool) ($sheet['repeating_headers'] ?? false),
                'confidence' => (float) ($sheet['confidence'] ?? 0),
                'role' => $role,
            ];
        }

        $included = array_values(array_filter($sheets, static fn (array $s) => $s['include'] === true));
        if ($included === []) {
            throw new RuntimeException('AI nie znalazło arkusza z produktami do importu.');
        }
        $special = array_values(array_filter($sheets, static fn (array $s) => ($s['role'] ?? '') === 'special'));

        return [
            'manufacturer_detected' => isset($json['manufacturer_detected'])
                ? (is_string($json['manufacturer_detected']) ? $json['manufacturer_detected'] : null)
                : null,
            'currency' => isset($json['currency']) && is_string($json['currency'])
                ? $this->currencyDetector->normalize($json['currency'], null)
                : null,
            'notes' => is_string($json['notes'] ?? null) ? $json['notes'] : '',
            'sheets' => array_values([...$included, ...$special]),
        ];
    }

    /**
     * @param  array{sheets: list<array<string, mixed>>}  $mapping
     * @return array{
     *     items: list<array<string, mixed>>,
     *     products_found: int,
     *     rows_total: int,
     *     skipped: int,
     *     errors_count: int
     * }
     */
    private function buildPreview(string $path, array $mapping): array
    {
        return app(PriceListImportService::class)->previewFromMapping($path, $mapping, 8);
    }
}
