<?php

declare(strict_types=1);

namespace App\Services;

use App\Services\Ai\AiSettingsService;
use App\Services\Ai\AiTask;
use App\Services\Ai\JsonResponseParser;
use App\Services\Ai\OpenAiCompatibleClient;
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
        private readonly JsonResponseParser $jsonParser,
        private readonly CurrencyDetector $currencyDetector,
        private readonly PriceListMetaDetector $metaDetector,
        private readonly SpreadsheetMappingHeuristic $spreadsheetHeuristic,
        private readonly PriceListSampleRoleResearcher $sampleRoleResearcher,
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

        $stats = $this->buildPreview($path, $mapping);
        if (($stats['products_found'] ?? 0) === 0) {
            $fallback = $this->spreadsheetHeuristic->detect($path);
            if ($fallback !== null) {
                $mapping = $fallback;
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

        $prompt = $this->pdfProductPrompt($hint);
        $fileSize = is_file($path) ? (int) filesize($path) : 0;
        $visionError = null;
        $aiReady = app(AiSettingsService::class)->isReady();

        $text = null;
        $extractError = null;
        try {
            $text = $this->pdfExtractor->extract($path);
        } catch (\Throwable $e) {
            $extractError = $e->getMessage();
        }
        $isScan = $text === null || mb_strlen($text) < 40;

        // Skan / zepsuty xref: native PDF parser nie da tekstu — strony jako obrazy.
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
                $visionError = 'Model nie rozpoznał pozycji w skanie PDF.';
            } catch (\Throwable $e) {
                $visionError = $e->getMessage();
            }
        }

        // 1) Vision tylko dla mniejszych PDF z warstwą tekstową (gdy AI skonfigurowane)
        if ($aiReady && ! $isScan && $fileSize > 0 && $fileSize <= 4_000_000) {
            try {
                @set_time_limit(240);
                $raw = $this->llm->chatWithPdf($prompt, $path, basename($path), AiTask::PriceListPdf);
                $json = $this->jsonParser->parse($raw['content']);
                $products = $this->normalizeProducts($json['products'] ?? [], $manufacturerHint);
                if ($products !== [] && $this->looksLikeGoodPdfExtract($products)) {
                    return $this->pdfResult(
                        $products,
                        $json,
                        $raw['model'],
                        'pdf-vision',
                        ['mode' => 'vision', 'file_mb' => round($fileSize / 1_000_000, 2)],
                        $originalName,
                        null,
                        null,
                        $manufacturerHint,
                    );
                }
            } catch (\Throwable $e) {
                $visionError = $e->getMessage();
            }
        } elseif (! $aiReady) {
            $visionError = 'AI wyłączone — tylko odczyt heurystyczny z tekstu PDF.';
        } else {
            $visionError = 'PDF '.round($fileSize / 1_000_000, 1).' MB — analiza tekstowa w częściach (bez vision).';
        }

        if ($text === null) {
            if (! $aiReady) {
                throw new RuntimeException(
                    'PDF jest skanem (brak warstwy tekstowej). Skonfiguruj AI z modelem vision albo wgraj XLSX.'
                );
            }
            throw new RuntimeException(
                'Nie udało się odczytać skanu PDF. '
                .($visionError ?? $extractError ?? 'Spróbuj ponownie albo wgraj XLSX.')
            );
        }

        // 2) Pełny tekst + heurystyki (Ansell/EMA, PROS) + opcjonalnie AI w chunkach
        @set_time_limit(600);
        $heuristicPros = $this->priceRowParser->parse($text, $manufacturerHint);
        $heuristicEma = $this->ansellEmaParser->parse($text, $manufacturerHint);
        $heuristicJs = $this->jsGlovesParser->looksLike($text)
            ? $this->jsGlovesParser->parse($text, $manufacturerHint)
            : [];
        $heuristic = $this->uniqueBySku(array_merge(
            $this->normalizeProducts($heuristicEma, $manufacturerHint ?: 'Ansell'),
            $this->normalizeProducts($heuristicPros, $manufacturerHint),
            $this->normalizeProducts($heuristicJs, $manufacturerHint ?: 'JS GLOVES'),
        ));

        // jeśli heurystyka już dała produkty — zwracamy od razu (niezależnie od AI)
        if ($heuristic !== [] && count($heuristic) >= 5) {
            $aiGuess = $heuristicEma !== []
                ? 'Ansell'
                : ($heuristicJs !== [] ? 'JS GLOVES' : ($heuristicPros !== [] ? 'PROS' : null));
            $meta = $this->metaDetector->resolve(
                $manufacturerHint,
                $originalName,
                $aiGuess,
                mb_substr($text, 0, 4000),
                $fromName['version'] ?? null,
            );
            foreach ($heuristic as $i => $row) {
                $heuristic[$i]['manufacturer'] = $meta['manufacturer'];
            }

            $docCurrency = $this->majorityCurrency($heuristic)
                ?? $this->currencyDetector->detect($text)
                ?? ($heuristicEma !== [] ? 'EUR' : 'PLN');

            return $this->pdfResult(
                $heuristic,
                [
                    'manufacturer_detected' => $meta['manufacturer'],
                    'currency' => $docCurrency,
                    'notes' => 'Odczyt heurystyczny z tekstu PDF ('.count($heuristic).' SKU). '
                        .($visionError ?? ''),
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

        $chunks = $aiReady ? $this->pdfExtractor->chunk($text, 28000, 1000) : [];
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

        foreach ($chunks as $idx => $chunk) {
            try {
                $part = $idx + 1;
                $total = count($chunks);
                $messages = [
                    [
                        'role' => 'system',
                        'content' => 'Jesteś ekspertem od cenników BHP. Zwracasz WYŁĄCZNIE JSON z tablicą products.',
                    ],
                    [
                        'role' => 'user',
                        'content' => $prompt
                            ."\n\nTo jest CZĘŚĆ {$part}/{$total} tekstu z dużego PDF. "
                            .'Wypisz produkty TYLKO z tej części.'
                            ."\n\nTEKST:\n".$chunk
                            ."\n\nWzorce: (A) 119.00 32 80.92; (B) NV15S-00138 … 50 PCE … 2.62.",
                    ],
                ];
                $raw = $this->llm->chat($messages, null, true, null, AiTask::PriceListPdf);
                $model = $raw['model'];
                $partJson = $this->jsonParser->parse($raw['content']);
                if (is_string($partJson['manufacturer_detected'] ?? null) && ($json['manufacturer_detected'] ?? '') === '') {
                    $json['manufacturer_detected'] = $partJson['manufacturer_detected'];
                }
                if (is_string($partJson['currency'] ?? null)) {
                    $json['currency'] = $partJson['currency'];
                }
                $aiProducts = array_merge(
                    $aiProducts,
                    $this->normalizeProducts($partJson['products'] ?? [], $manufacturerHint)
                );
            } catch (\Throwable) {
                $chunkErrors++;

                continue;
            }
        }

        $aiProducts = $this->uniqueBySku($aiProducts);
        $products = $this->mergePdfProducts($aiProducts, $heuristic, $manufacturerHint);

        if ($products === []) {
            $msg = 'Nie udało się odczytać pozycji z PDF (duży plik: '
                .round($fileSize / 1_000_000, 1).' MB, tekst: '.mb_strlen($text)
                .' znaków, części: '.count($chunks)
                .', błędy AI: '.$chunkErrors
                .', heurystyka EMA: '.count($heuristicEma)
                .', PROS: '.count($heuristicPros).').';
            if ($visionError !== null) {
                $msg .= ' '.$visionError;
            }
            throw new RuntimeException($msg);
        }

        $notes = trim(($json['notes'] ?? '').' Analiza PDF: '.count($products).' SKU.');
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
     * @return array<string, mixed>|null
     */
    private function analyzePdfViaPageImages(
        string $path,
        string $prompt,
        ?string $manufacturerHint,
        ?string $originalName,
        int $fileSize,
    ): ?array {
        $images = $this->pdfImageExtractor->prepareForVision($this->pdfImageExtractor->extract($path));
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
        foreach ($images as $image) {
            try {
                @set_time_limit(240);
                $raw = $this->llm->chatWithPageImages($prompt, [$image], AiTask::PriceListPdf);
                $model = $raw['model'];
                $partJson = $this->jsonParser->parse($raw['content']);
                if (is_string($partJson['manufacturer_detected'] ?? null) && ($json['manufacturer_detected'] ?? '') === '') {
                    $json['manufacturer_detected'] = $partJson['manufacturer_detected'];
                }
                if (is_string($partJson['currency'] ?? null)) {
                    $json['currency'] = $partJson['currency'];
                }
                if (is_string($partJson['notes'] ?? null) && ($json['notes'] ?? '') === '') {
                    $json['notes'] = $partJson['notes'];
                }
                $aiProducts = array_merge(
                    $aiProducts,
                    $this->normalizeProducts($partJson['products'] ?? [], $manufacturerHint)
                );
            } catch (\Throwable $e) {
                $lastError = $e->getMessage();
            }
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

    private function pdfProductPrompt(string $hint): string
    {
        return <<<PROMPT
{$hint}

Przeczytaj cennik PDF (tabela produktów BHP / odzież / chemia).
Format A (PROS / AJ Group):
1) Opis = NAZWA
2) Cena katalogowa
3) Upust [%]
4) Cena po upuście
Format B (Ansell / EMA / AlphaTec):
- Short/Long Base Style lub kod (np. NV15S-00138) = sku
- nazwa serii/modelu (AlphaTec 1500…) = name
- Price (EUR) = catalog_price
- Carton Qty = pack_qty (np. 50 PCE)
- purchase = null, discount = 0 jeśli brak upustu
Format C (tabela skan, np. PPO / obuwie):
- Model (np. 305 albo Model 0157) = sku
- Wyszczególnienie = name
- Cena = catalog_price, discount = 0, purchase = catalog_price
- Norma / sekcja = category
Oraz opcjonalnie: opakowanie, kategoria/sekcja.

Zwróć JSON:
{
  "manufacturer_detected": "string|null",
  "currency": "PLN|EUR|USD|GBP|CHF",
  "notes": "krótki opis po polsku",
  "products": [
    {
      "sku": "kod lub wygenerowany symbol",
      "name": "pełna nazwa z kolumny Opis",
      "catalog_price": 119.00,
      "discount": 32,
      "purchase": 80.92,
      "currency": "PLN",
      "pack_qty": null,
      "packaging": null,
      "category": "nazwa sekcji jeśli jest"
    }
  ]
}

TWARDE ZASADY:
- catalog_price = Cena katalogowa
- discount = Upust w procentach (np. 30 lub 32)
- purchase = Cena po upuście (NIE mylić z katalogową)
- currency = waluta TEJ pozycji (z wiersza/nagłówka: EUR, PLN, USD…); currency na poziomie pliku = dominująca
- name = tekst z Opis (np. "Kurtka wodoochronna…") — NIGDY "Produkt 80.92"
- sku = kod jeśli jest; jeśli brak kodu: skrót z nazwy (np. PROS-KURTKA-001), NIGDY cena jako sku
- NIE sklejaj upustu z ceną (błąd: 3280.92 zamiast upust=32 i cena=80.92)
- wypisz WSZYSTKIE wiersze produktów ze wszystkich stron
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
        $maxProductsPayload = 2500;
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
4) catalog_price = cena katalogowa / sugerowana / CENA CENNIK / Price (€/pc) — NIE kolumna „Zamówienie=0”
5) discount = upust / rabat / marża w % (kolumny: upust, rabat, discount, marża, PL Discount)
6) pack_qty = Quantity per box / ilość sztuk w kartonie / opakowaniu zbiorczym
7) packaging = Size / rozmiar / opakowanie / jednostka / pojemność
8) purchase = cena po upuście / zakup tylko jeśli jest osobna kolumna
9) currency = kolumna waluty jeśli jest (EUR/PLN/USD); currency na poziomie pliku = dominująca z nagłówka (Price EUR, PLN, zł…)
10) category = Category/Type / kategoria / grupa

Zasady:
- Często nad tabelą jest blok rabatów/kontaktów — header_excel_row to wiersz z „Kod produktu”/„Nazwa”/„Cena…”, nie wiersz 1.
- Nagłówki bywają PL+CZ+EN (Kod produktu, Nazwa, Article Number, Model Name and Description, CENA CENNIK).
- Układy z rozmiarami (S/M/L w kolejnych wierszach, nazwa tylko w 1. wierszu modelu) — i tak include=true; system uzupełni puste nazwy.
- include=true dla arkuszy z produktami nawet bez kolumny SKU (sku=null).
- Jeśli nagłówki powtarzają się w środku arkusza, ustaw repeating_headers=true.
- header_excel_row = sample_rows[].excel_row wiersza z nazwami kolumn.
- Nie ustawiaj include=false tylko dlatego, że brak kodu produktu.
- Gdy jest SAMPLE_ROLE_RESEARCH — skopiuj column z roles.* do columns.*.

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

            $hasRequired = $normCols['name'] !== null
                && $normCols['catalog_price'] !== null;

            $sheets[] = [
                'sheet' => (string) ($sheet['sheet'] ?? ''),
                'include' => (bool) ($sheet['include'] ?? false) && $hasRequired,
                'header_excel_row' => $headerExcel,
                'columns' => $normCols,
                'repeating_headers' => (bool) ($sheet['repeating_headers'] ?? false),
                'confidence' => (float) ($sheet['confidence'] ?? 0),
            ];
        }

        $included = array_values(array_filter($sheets, static fn (array $s) => $s['include'] === true));
        if ($included === []) {
            throw new RuntimeException('AI nie znalazło arkusza z produktami do importu.');
        }

        return [
            'manufacturer_detected' => isset($json['manufacturer_detected'])
                ? (is_string($json['manufacturer_detected']) ? $json['manufacturer_detected'] : null)
                : null,
            'currency' => isset($json['currency']) && is_string($json['currency'])
                ? $this->currencyDetector->normalize($json['currency'], null)
                : null,
            'notes' => is_string($json['notes'] ?? null) ? $json['notes'] : '',
            'sheets' => $sheets,
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
