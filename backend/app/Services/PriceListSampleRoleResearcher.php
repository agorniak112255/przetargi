<?php

declare(strict_types=1);

namespace App\Services;

use App\Services\Ai\AiSettingsService;
use App\Services\Ai\AiTask;
use App\Services\Ai\JsonResponseParser;
use App\Services\Ai\OpenAiCompatibleClient;
use App\Services\Enrichment\DuckDuckGoHtmlSearch;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use RuntimeException;
use Throwable;

/**
 * Przed mapowaniem całego cennika: bierze 1 pozycję, opcjonalnie sprawdza w sieci
 * co jest modelem / nazwą / article number / rozmiarem / ceną.
 */
final class PriceListSampleRoleResearcher
{
    public function __construct(
        private readonly OpenAiCompatibleClient $llm,
        private readonly JsonResponseParser $jsonParser,
        private readonly AiSettingsService $aiSettings,
    ) {}

    /**
     * @param  array{sheets: list<array<string, mixed>>}  $sample
     * @return array{
     *     sheet: string,
     *     header_excel_row: int,
     *     sample_excel_row: int,
     *     header_cells: list<string>,
     *     sample_cells: list<string>,
     *     roles: array<string, array{column: int|null, value: string|null, meaning: string}>,
     *     web_notes: string,
     *     confidence: float,
     *     source: string
     * }|null
     */
    public function research(array $sample, ?string $manufacturerHint = null): ?array
    {
        $position = $this->pickSamplePosition($sample);
        if ($position === null) {
            return null;
        }

        // W testach: sama heurystyka nagłówków (bez DB AI / sieci / LLM).
        if (app()->environment('testing')) {
            return $this->heuristicRoles($position, $manufacturerHint);
        }

        $webNotes = '';
        $source = 'ai-local';
        $cfg = $this->aiSettings->resolve();
        if (! ($cfg['enabled'] ?? false) || ! ($cfg['has_api_key'] ?? false)) {
            return $this->heuristicRoles($position, $manufacturerHint);
        }

        try {
            $webNotes = $this->fetchWebContext($position, $manufacturerHint, $cfg);
            if ($webNotes !== '') {
                $source = 'ai+web';
            }
        } catch (Throwable $e) {
            Log::info('Price list sample web research skipped', ['error' => $e->getMessage()]);
        }

        try {
            $roles = $this->classifyWithAi($position, $manufacturerHint, $webNotes);
            if ($roles === null) {
                return $this->heuristicRoles($position, $manufacturerHint);
            }

            return [
                ...$position,
                'roles' => $roles['roles'],
                'web_notes' => $webNotes !== '' ? $webNotes : (string) ($roles['web_notes'] ?? ''),
                'confidence' => (float) ($roles['confidence'] ?? 0.7),
                'source' => $source,
            ];
        } catch (Throwable $e) {
            Log::warning('Price list sample role AI failed', ['error' => $e->getMessage()]);

            return $this->heuristicRoles($position, $manufacturerHint);
        }
    }

    /**
     * @param  array{
     *     roles: array<string, array{column: int|null, value?: string|null, meaning?: string}>,
     *     sheet?: string,
     *     header_excel_row?: int,
     *     confidence?: float
     * }  $research
     * @param  array{sheets: list<array<string, mixed>>}  $mapping
     * @return array{sheets: list<array<string, mixed>>}
     */
    public function applyToMapping(array $mapping, array $research): array
    {
        $roles = $research['roles'] ?? [];
        if (! is_array($roles) || $roles === []) {
            return $mapping;
        }

        $targetSheet = (string) ($research['sheet'] ?? '');
        $headerRow = isset($research['header_excel_row']) ? (int) $research['header_excel_row'] : null;

        foreach ($mapping['sheets'] as $i => $sheet) {
            if (! is_array($sheet) || ! ($sheet['include'] ?? false)) {
                continue;
            }
            if ($targetSheet !== '' && (string) ($sheet['sheet'] ?? '') !== $targetSheet) {
                continue;
            }

            $cols = is_array($sheet['columns'] ?? null) ? $sheet['columns'] : [];
            foreach (['sku', 'name', 'catalog_price', 'packaging', 'pack_qty', 'category', 'model_key', 'ean'] as $field) {
                $col = $roles[$field]['column'] ?? null;
                if (is_numeric($col)) {
                    $cols[$field] = (int) $col;
                }
            }
            $sheet['columns'] = $cols;
            if ($headerRow !== null && $headerRow >= 1) {
                $sheet['header_excel_row'] = $headerRow;
            }
            $conf = (float) ($research['confidence'] ?? 0);
            if ($conf > (float) ($sheet['confidence'] ?? 0)) {
                $sheet['confidence'] = $conf;
            }
            $mapping['sheets'][$i] = $sheet;
            break;
        }

        $notes = trim((string) ($mapping['notes'] ?? ''));
        $web = $this->publicWebNotes((string) ($research['web_notes'] ?? ''));
        $mapping['notes'] = trim($notes.($web !== '' ? ' '.$web : ''));
        $mapping['sample_role_research'] = [
            'sheet' => $research['sheet'] ?? null,
            'sample_excel_row' => $research['sample_excel_row'] ?? null,
            'roles' => $roles,
            'source' => $research['source'] ?? null,
            'confidence' => $research['confidence'] ?? null,
        ];

        return $mapping;
    }

    private function publicWebNotes(string $web): string
    {
        $web = trim($web);
        if ($web === '') {
            return '';
        }
        $lower = mb_strtolower($web);
        foreach (['outlook.com', 'olmoauth', 'urlblockederror', 'http://', 'https://'] as $junk) {
            if (str_contains($lower, $junk)) {
                return '';
            }
        }

        return mb_substr($web, 0, 220);
    }

    /**
     * @param  array{sheets: list<array<string, mixed>>}  $sample
     * @return array{
     *     sheet: string,
     *     header_excel_row: int,
     *     sample_excel_row: int,
     *     header_cells: list<string>,
     *     sample_cells: list<string>
     * }|null
     */
    private function pickSamplePosition(array $sample): ?array
    {
        foreach ($sample['sheets'] as $sheet) {
            if (! is_array($sheet) || ! ($sheet['likely_product_sheet'] ?? true)) {
                continue;
            }
            $rows = $sheet['sample_rows'] ?? [];
            if (! is_array($rows) || count($rows) < 2) {
                continue;
            }

            $headerIdx = null;
            for ($i = 0; $i < min(12, count($rows)); $i++) {
                $cells = array_map(
                    static fn ($v) => mb_strtolower(trim((string) $v)),
                    $rows[$i]['cells'] ?? []
                );
                $blob = implode(' ', $cells);
                $hits = 0;
                foreach (['price', 'cena', 'article', 'sku', 'nazwa', 'description', 'model', 'reference'] as $token) {
                    if (str_contains($blob, $token)) {
                        $hits++;
                    }
                }
                if ($hits >= 2) {
                    $headerIdx = $i;
                    break;
                }
            }
            if ($headerIdx === null) {
                continue;
            }

            $headerCells = array_map(
                static fn ($v) => trim((string) $v),
                $rows[$headerIdx]['cells'] ?? []
            );

            for ($i = $headerIdx + 1; $i < count($rows); $i++) {
                $cells = array_map(
                    static fn ($v) => trim((string) $v),
                    $rows[$i]['cells'] ?? []
                );
                if (! $this->rowLooksPriced($cells)) {
                    continue;
                }
                $nonEmpty = count(array_filter($cells, static fn (string $c): bool => $c !== ''));
                if ($nonEmpty < 3) {
                    continue;
                }

                return [
                    'sheet' => (string) ($sheet['name'] ?? ''),
                    'header_excel_row' => (int) ($rows[$headerIdx]['excel_row'] ?? ($headerIdx + 1)),
                    'sample_excel_row' => (int) ($rows[$i]['excel_row'] ?? ($i + 1)),
                    'header_cells' => $headerCells,
                    'sample_cells' => $cells,
                ];
            }
        }

        return null;
    }

    /**
     * @param  list<string>  $cells
     */
    private function rowLooksPriced(array $cells): bool
    {
        foreach ($cells as $cell) {
            if ($cell === '') {
                continue;
            }
            $norm = str_replace([' ', ',', '€', 'EUR', 'PLN'], ['', '.', '', '', ''], $cell);
            if (is_numeric($norm) && (float) $norm > 0 && (float) $norm < 1_000_000) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param  array{
     *     header_cells: list<string>,
     *     sample_cells: list<string>,
     *     sheet: string
     * }  $position
     * @param  array<string, mixed>  $cfg
     */
    private function fetchWebContext(array $position, ?string $manufacturerHint, array $cfg): string
    {
        $tokens = [];
        foreach ($position['sample_cells'] as $cell) {
            $cell = trim($cell);
            if ($cell === '' || mb_strlen($cell) > 60) {
                continue;
            }
            if ($this->looksLikePrice($cell) || $this->looksLikeSize($cell)) {
                continue;
            }
            if (preg_match('/[A-Za-z].*\d|\d.*[A-Za-z]/', $cell) === 1 || preg_match('/^[A-Z]{1,4}\s+\d/', $cell) === 1) {
                $tokens[] = $cell;
            }
            if (count($tokens) >= 2) {
                break;
            }
        }
        if ($tokens === []) {
            foreach ($position['sample_cells'] as $cell) {
                $cell = trim($cell);
                if ($cell !== '' && mb_strlen($cell) >= 4 && mb_strlen($cell) <= 40 && ! $this->looksLikePrice($cell)) {
                    $tokens[] = $cell;
                    break;
                }
            }
        }
        if ($tokens === []) {
            return '';
        }

        $query = trim(($manufacturerHint ? $manufacturerHint.' ' : '').implode(' ', $tokens).' product model article');

        if ($this->aiSettings->usesFreeWebSearch()) {
            try {
                $parts = [];
                foreach (app(DuckDuckGoHtmlSearch::class)->search($query, 4) as $row) {
                    $parts[] = trim($row['title']).': '.trim($row['url']);
                }
                if ($parts !== []) {
                    return mb_substr(implode("\n", $parts), 0, 2500);
                }
            } catch (Throwable $e) {
                Log::info('Sample role DuckDuckGo failed', ['error' => $e->getMessage()]);
            }
        } elseif (! empty($cfg['tavily_api_key'])) {
            try {
                $response = Http::acceptJson()
                    ->timeout(18)
                    ->post('https://api.tavily.com/search', [
                        'api_key' => $cfg['tavily_api_key'],
                        'query' => $query,
                        'search_depth' => 'basic',
                        'max_results' => 4,
                        'include_answer' => true,
                    ]);
                if ($response->successful()) {
                    $data = $response->json();
                    $parts = [];
                    $answer = trim((string) ($data['answer'] ?? ''));
                    if ($answer !== '') {
                        $parts[] = $answer;
                    }
                    foreach (array_slice($data['results'] ?? [], 0, 4) as $row) {
                        if (! is_array($row)) {
                            continue;
                        }
                        $parts[] = trim((string) ($row['title'] ?? '')).': '.trim((string) ($row['content'] ?? ''));
                    }

                    return mb_substr(implode("\n", array_filter($parts)), 0, 1800);
                }
            } catch (Throwable $e) {
                Log::info('Tavily sample research failed', ['error' => $e->getMessage()]);
            }
        }

        if (! $this->aiSettings->usesFreeWebSearch() && ! empty($cfg['web_search_enabled'])) {
            try {
                $raw = $this->llm->responsesWithWebSearch(
                    "Na podstawie wyszukiwania w internecie: co oznaczają kody/nazwy w zapytaniu „{$query}”? "
                    .'Krótko po polsku: który ciąg to model/reference, który to nazwa handlowa, który to article/SKU, który to rozmiar. Max 8 zdań.',
                    22,
                    AiTask::WebSearch
                );

                return mb_substr(trim($raw['content']), 0, 1800);
            } catch (Throwable $e) {
                Log::info('AI web_search sample research failed', ['error' => $e->getMessage()]);
            }
        }

        return '';
    }

    /**
     * @param  array{
     *     header_cells: list<string>,
     *     sample_cells: list<string>,
     *     sheet: string,
     *     header_excel_row: int,
     *     sample_excel_row: int
     * }  $position
     * @return array{roles: array<string, array{column: int|null, value: string|null, meaning: string}>, web_notes?: string, confidence?: float}|null
     */
    private function classifyWithAi(array $position, ?string $manufacturerHint, string $webNotes): ?array
    {
        $payload = json_encode([
            'manufacturer_hint' => $manufacturerHint,
            'sheet' => $position['sheet'],
            'header_excel_row' => $position['header_excel_row'],
            'sample_excel_row' => $position['sample_excel_row'],
            'header_cells_0based' => $position['header_cells'],
            'sample_cells_0based' => $position['sample_cells'],
            'web_context' => $webNotes,
        ], JSON_UNESCAPED_UNICODE);

        $messages = [
            [
                'role' => 'system',
                'content' => 'Jesteś ekspertem od cenników BHP/PPE. Na podstawie JEDNEJ pozycji i kontekstu z sieci '
                    .'ustal role kolumn. Odpowiadasz wyłącznie JSON.',
            ],
            [
                'role' => 'user',
                'content' => <<<PROMPT
Przeanalizuj 1 pozycję z cennika. Indeksy kolumn są 0-based.

Zwróć JSON:
{
  "confidence": 0.0,
  "web_notes": "1-2 zdania po polsku",
  "roles": {
    "model_key": {"column": 2, "value": "TD 0125 S WH 00", "meaning": "kod modelu/reference (NIE article)"},
    "name": {"column": 4, "value": "NEW! TYVEK Dual Combi", "meaning": "nazwa handlowa modelu"},
    "sku": {"column": 5, "value": "D14681379", "meaning": "article number / unikalny kod pozycji/rozmiaru"},
    "packaging": {"column": 6, "value": "S", "meaning": "rozmiar"},
    "pack_qty": {"column": 7, "value": "25", "meaning": "ilość w opakowaniu"},
    "catalog_price": {"column": 9, "value": "2.68", "meaning": "cena jednostkowa"},
    "category": {"column": 1, "value": "Cat.III", "meaning": "kategoria lub null"}
  }
}

Zasady:
- model_key = Reference / Base Style / kod modelu (np. TD 0125 S WH 00) — to jest KOD produktu w ERP
- name = krótka nazwa handlowa (np. NEW! TYVEK Dual Combi), NIE długi opis techniczny jeśli w tej samej kolumnie jest też tytuł
- sku = Article Number (D1468…) — kod fabryczny rozmiaru; NIE mylić z modelem
- packaging = Size / rozmiar
- Jeśli brak kolumny: column=null
- Uwzględnij web_context gdy jest

Dane:
{$payload}
PROMPT
            ],
        ];

        $raw = $this->llm->chat($messages, null, true, null, AiTask::PriceListPdf);
        try {
            $json = $this->jsonParser->parse($raw['content']);
        } catch (RuntimeException) {
            $json = $this->llm->chatJson([
                ...$messages,
                ['role' => 'user', 'content' => 'Zwróć WYŁĄCZNIE poprawny JSON ze schematem roles.'],
            ], null, null, null, AiTask::PriceListPdf);
        }

        return $this->normalizeRolesPayload($json, $position);
    }

    /**
     * @param  array<string, mixed>  $json
     * @param  array{sample_cells: list<string>}  $position
     * @return array{roles: array<string, array{column: int|null, value: string|null, meaning: string}>, web_notes: string, confidence: float}|null
     */
    private function normalizeRolesPayload(array $json, array $position): ?array
    {
        $rolesIn = $json['roles'] ?? null;
        if (! is_array($rolesIn)) {
            return null;
        }

        $roles = [];
        foreach (['model_key', 'name', 'sku', 'packaging', 'pack_qty', 'catalog_price', 'category', 'ean'] as $field) {
            $row = $rolesIn[$field] ?? null;
            $col = is_array($row) && is_numeric($row['column'] ?? null) ? (int) $row['column'] : null;
            $value = null;
            if ($col !== null && isset($position['sample_cells'][$col])) {
                $value = trim((string) $position['sample_cells'][$col]) ?: null;
            } elseif (is_array($row) && isset($row['value'])) {
                $value = trim((string) $row['value']) ?: null;
            }
            $roles[$field] = [
                'column' => $col,
                'value' => $value,
                'meaning' => is_array($row) ? trim((string) ($row['meaning'] ?? '')) : '',
            ];
        }

        if ($roles['name']['column'] === null || $roles['catalog_price']['column'] === null) {
            return null;
        }

        return [
            'roles' => $roles,
            'web_notes' => is_string($json['web_notes'] ?? null) ? $json['web_notes'] : '',
            'confidence' => isset($json['confidence']) && is_numeric($json['confidence'])
                ? max(0.0, min(1.0, (float) $json['confidence']))
                : 0.7,
        ];
    }

    /**
     * @param  array{
     *     sheet: string,
     *     header_excel_row: int,
     *     sample_excel_row: int,
     *     header_cells: list<string>,
     *     sample_cells: list<string>
     * }  $position
     * @return array{
     *     sheet: string,
     *     header_excel_row: int,
     *     sample_excel_row: int,
     *     header_cells: list<string>,
     *     sample_cells: list<string>,
     *     roles: array<string, array{column: int|null, value: string|null, meaning: string}>,
     *     web_notes: string,
     *     confidence: float,
     *     source: string
     * }
     */
    private function heuristicRoles(array $position, ?string $manufacturerHint): array
    {
        $labels = array_map(
            static fn (string $v): string => mb_strtolower(trim($v)),
            $position['header_cells']
        );

        $find = static function (array $needles) use ($labels): ?int {
            foreach ($labels as $i => $label) {
                if ($label === '') {
                    continue;
                }
                foreach ($needles as $needle) {
                    if ($label === $needle || str_contains($label, $needle)) {
                        return $i;
                    }
                }
            }

            return null;
        };

        // Article Number przed Reference
        $sku = $find(['article number', 'artikelnummer', 'kod produktu', 'product code', 'sku', 'article']);
        $model = $find(['product reference', 'reference', 'model code', 'base style', 'ref']);
        if ($model !== null && $sku !== null && $model === $sku) {
            $model = $find(['product reference', 'reference', 'model']);
        }
        $name = $find(['model name', 'nazwa', 'name', 'description', 'opis']);
        $price = $find(['price(€', 'price (€', 'price', 'cena']);
        $size = $find(['size', 'rozmiar']);
        $qty = $find(['quantity per box', 'qty', 'ilość', 'ilosc']);
        $cat = $find(['category/type', 'category', 'kategoria']);

        $role = static function (?int $col, array $cells, string $meaning): array {
            return [
                'column' => $col,
                'value' => $col !== null ? (trim((string) ($cells[$col] ?? '')) ?: null) : null,
                'meaning' => $meaning,
            ];
        };

        $cells = $position['sample_cells'];

        return [
            ...$position,
            'roles' => [
                'model_key' => $role($model, $cells, 'kod modelu/reference'),
                'name' => $role($name, $cells, 'nazwa handlowa'),
                'sku' => $role($sku ?? $model, $cells, 'article/sku'),
                'packaging' => $role($size, $cells, 'rozmiar'),
                'pack_qty' => $role($qty, $cells, 'ilość w opakowaniu'),
                'catalog_price' => $role($price, $cells, 'cena'),
                'category' => $role($cat, $cells, 'kategoria'),
                'ean' => $role(null, $cells, ''),
            ],
            'web_notes' => $manufacturerHint
                ? "Heurystyka nagłówków (bez sieci), producent: {$manufacturerHint}."
                : 'Heurystyka nagłówków (bez sieci).',
            'confidence' => 0.55,
            'source' => 'heuristic-headers',
        ];
    }

    private function looksLikePrice(string $value): bool
    {
        $norm = str_replace([' ', ',', '€', 'EUR', 'PLN', '%'], ['', '.', '', '', '', ''], $value);

        return is_numeric($norm);
    }

    private function looksLikeSize(string $value): bool
    {
        return preg_match(
            '/^(XXS|XS|S|M|L|XL|XXL|XXXL|XXXXL|[2-6]XL|ONE\s*SIZE|ONESIZE|\d{1,2})$/i',
            trim($value)
        ) === 1;
    }
}
