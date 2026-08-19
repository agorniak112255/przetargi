<?php

declare(strict_types=1);

namespace App\Services;

use App\Services\Ai\JsonResponseParser;
use App\Services\Ai\OpenAiCompatibleClient;
use PhpOffice\PhpSpreadsheet\IOFactory;

/**
 * Dynamiczne mapowanie arkusza SIWZ/cennika → pozycje przetargu (sku, nazwa, cena, ilość).
 * Nagłówki bywają różne — najpierw heurystyka, przy niepewności AI na samym wierszu nagłówka.
 */
final class TenderSpreadsheetItemExtractor
{
    public function __construct(
        private readonly OpenAiCompatibleClient $llm,
        private readonly JsonResponseParser $jsonParser,
        private readonly CurrencyDetector $currencyDetector,
    ) {}

    /**
     * @return array{
     *     items: list<array{sku: ?string, name: string, requirement: string, quantity: int, offer_price: ?float, currency: ?string, norms: ?string, description: ?string}>,
     *     column_map: array<string, int|null>,
     *     header_row: int,
     *     notes: string
     * }|null
     */
    public function extract(string $path, bool $useAiMapping = true): ?array
    {
        $book = IOFactory::load($path);
        $merged = [];
        $sheetNotes = [];
        $lastMap = $this->emptyColumnMap();
        $headerRow = 0;
        $usedSheets = 0;

        foreach ($book->getAllSheets() as $sheet) {
            $rows = $sheet->toArray(null, true, true, false);
            if ($rows === []) {
                continue;
            }
            $normalized = [];
            foreach ($rows as $r) {
                $normalized[] = array_map(
                    static fn ($v) => trim((string) ($v ?? '')),
                    is_array($r) ? $r : []
                );
            }

            $pack = $this->extractFromMatrix($normalized, $useAiMapping);
            if ($pack === null || $pack['items'] === []) {
                continue;
            }
            $usedSheets++;
            $title = trim((string) $sheet->getTitle());
            foreach ($pack['items'] as $item) {
                $merged[] = $item;
            }
            $sheetNotes[] = ($title !== '' ? $title.': ' : '').$pack['notes'];
            $lastMap = $pack['column_map'];
            $headerRow = $pack['header_row'];
        }

        if ($merged === []) {
            return null;
        }

        return [
            'items' => array_slice($merged, 0, 800),
            'column_map' => $lastMap,
            'header_row' => $headerRow,
            'notes' => $usedSheets > 1
                ? 'Scalono '.$usedSheets.' arkusze (nazwa / opis / normy). '.implode(' | ', $sheetNotes)
                : ($sheetNotes[0] ?? 'Mapowanie nagłówków.'),
        ];
    }

    /**
     * Wspólna ścieżka dla XLSX i tabel DOCX.
     *
     * @param  list<list<string>>  $rows
     * @return array{
     *     items: list<array{sku: ?string, name: string, requirement: string, quantity: int, offer_price: ?float, currency: ?string, norms: ?string, description: ?string}>,
     *     column_map: array<string, int|null>,
     *     header_row: int,
     *     notes: string
     * }|null
     */
    public function extractFromMatrix(array $rows, bool $useAiMapping = true): ?array
    {
        if ($rows === []) {
            return null;
        }

        $detected = $this->detectMapping($rows, $useAiMapping);
        if ($detected === null) {
            return null;
        }

        $items = $this->rowsToItems($rows, $detected['header_row'], $detected['columns']);
        if ($items === []) {
            return null;
        }

        return [
            'items' => array_slice($items, 0, 800),
            'column_map' => $detected['columns'],
            'header_row' => $detected['header_row'],
            'notes' => $detected['notes'],
        ];
    }

    /**
     * @param  list<list<string>>  $rows
     * @return array{header_row: int, columns: array<string, int|null>, notes: string}|null
     */
    private function detectMapping(array $rows, bool $useAiMapping): ?array
    {
        $best = null;
        $bestScore = 0;
        $scan = min(25, count($rows));

        for ($i = 0; $i < $scan; $i++) {
            $labels = array_map(static fn (string $v) => mb_strtolower($v), $rows[$i]);
            $blob = implode(' | ', $labels);
            if (mb_strlen($blob) < 4) {
                continue;
            }

            $cols = $this->mapHeaders($labels);
            $score = $this->scoreMapping($cols, $labels);
            $score += min(8, $this->countDataHits($rows, $i, $cols));

            if ($score > $bestScore) {
                $bestScore = $score;
                $best = [
                    'header_row' => $i,
                    'columns' => $cols,
                    'notes' => 'Mapowanie nagłówków (heurystyka).',
                ];
            }
        }

        $needAi = $useAiMapping && ($best === null || $bestScore < 8
            || ($best['columns']['name'] ?? null) === null);

        if ($needAi) {
            $ai = $this->aiMapHeaders($rows);
            if ($ai !== null) {
                $hits = $this->countDataHits($rows, $ai['header_row'], $ai['columns']);
                if ($hits >= 2 || $best === null) {
                    return $ai;
                }
            }
        }

        if ($best === null || $bestScore < 5) {
            return $this->aiMapHeaders($rows) ?? $best;
        }

        // uzupełnij brakujące przez AI tylko dla kolumn
        if ($useAiMapping && (($best['columns']['name'] ?? null) === null || ($best['columns']['offer_price'] ?? null) === null)) {
            $aiFill = $this->aiMapHeaders($rows);
            if ($aiFill !== null && $this->countDataHits($rows, $aiFill['header_row'], $aiFill['columns']) >= $this->countDataHits($rows, $best['header_row'], $best['columns'])) {
                return $aiFill;
            }
        }

        return $best;
    }

    /**
     * @param  list<string>  $labels  lowercase
     * @return array<string, int|null>
     */
    private function mapHeaders(array $labels): array
    {
        return [
            'sku' => $this->findCol($labels, [
                'article', 'artiku', 'artikel', 'sku', 'kod produktu', 'kod towaru', 'product code',
                'numer katalog', 'nr katalog', 'indeks', 'symbol', 'sap', 'ref.', 'reference',
                'kod',
            ], exclude: ['l.p', 'lp.', 'l.p.']),
            'name' => $this->findCol($labels, [
                'przedmiot zamówienia', 'przedmiot zamowienia', 'przedmiot',
                'name of article', 'name of product', 'nazwa artykułu', 'nazwa artykulu',
                'nazwa produktu', 'nazwa towaru', 'nazwa asortymentu', 'asortyment',
                'product name', 'article name', 'nazwa', 'name', 'produkt',
            ], exclude: ['project', 'client', 'klient', 'cena', 'price', 'podwykonawc', 'norm']),
            'description' => $this->findCol($labels, [
                'opis techniczny', 'szczegółowy opis', 'szczegolowy opis', 'specyfikacja',
                'opis produktu', 'description', 'opis',
            ], exclude: ['nazwa']),
            'norms' => $this->findCol($labels, [
                'normy', 'norma', 'en iso', 'standard', 'normy en', 'wymagane normy',
            ]),
            'offer_price' => $this->findPreferredPriceCol($labels),
            'quantity' => $this->findCol($labels, [
                'quantity', 'qty', 'ilości', 'ilosci', 'ilość', 'ilosc',
                'szt', 'amount', 'liczba', 'szacunkowe',
            ]),
            'project' => $this->findCol($labels, [
                'name of project', 'project', 'projekt', 'klient końcowy', 'odbiorca',
            ]),
            'client' => $this->findCol($labels, ['client', 'klient', 'customerawca']),
            'currency' => $this->findCol($labels, ['waluta', 'currency']),
        ];
    }

    /**
     * Preferuj najnowszą / „special price from…” nad „current”.
     *
     * @param  list<string>  $labels
     */
    private function findPreferredPriceCol(array $labels): ?int
    {
        $priority = [
            'cena jednostkowa netto', 'cena jednostkowa', 'special price from', 'cena specjalna od',
            'cena od', 'new price', 'nowa cena', 'cena oferty', 'offer price', 'unit price',
            'special price', 'cena specjalna', 'cena netto', 'cena', 'price',
        ];
        foreach ($priority as $needle) {
            $idx = $this->findCol($labels, [$needle]);
            if ($idx !== null) {
                return $idx;
            }
        }

        // ostatnia kolumna z „price”/„cena” (często „from 1st of June”)
        $last = null;
        foreach ($labels as $i => $label) {
            if (str_contains($label, 'price') || str_contains($label, 'cena')) {
                if (str_contains($label, 'increase') || str_contains($label, 'wzrost') || str_contains($label, '%')) {
                    continue;
                }
                $last = $i;
            }
        }

        return $last;
    }

    /**
     * @param  list<string>  $labels
     * @param  list<string>  $needles
     * @param  list<string>  $exclude
     */
    private function findCol(array $labels, array $needles, array $exclude = []): ?int
    {
        foreach ($labels as $i => $label) {
            if ($label === '') {
                continue;
            }
            foreach ($exclude as $ex) {
                if (str_contains($label, $ex) && ! $this->needleOverridesExclude($label, $needles)) {
                    continue 2;
                }
            }
            $compact = preg_replace('/\s+/', ' ', $label) ?? $label;
            foreach ($needles as $needle) {
                if ($compact === $needle || str_contains($compact, $needle)) {
                    return $i;
                }
            }
        }

        return null;
    }

    /**
     * @param  list<string>  $needles
     */
    private function needleOverridesExclude(string $label, array $needles): bool
    {
        foreach ($needles as $needle) {
            if (str_contains($label, $needle) && mb_strlen($needle) >= 8) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param  array<string, int|null>  $cols
     * @param  list<string>  $labels
     */
    private function scoreMapping(array $cols, array $labels): int
    {
        $score = 0;
        if ($cols['sku'] !== null) {
            $score += 3;
        }
        if ($cols['name'] !== null) {
            $score += 3;
        }
        if ($cols['offer_price'] !== null) {
            $score += 4;
        }
        if ($cols['quantity'] !== null) {
            $score += 1;
        }
        if (($cols['norms'] ?? null) !== null) {
            $score += 2;
        }
        $blob = implode(' ', $labels);
        if (str_contains($blob, 'article') || str_contains($blob, 'sku')) {
            $score += 1;
        }

        return $score;
    }

    /**
     * @param  list<list<string>>  $rows
     * @param  array<string, int|null>  $cols
     */
    private function countDataHits(array $rows, int $headerRow, array $cols): int
    {
        $hits = 0;
        $nameIdx = $cols['name'];
        $skuIdx = $cols['sku'];
        $priceIdx = $cols['offer_price'];
        for ($r = $headerRow + 1; $r < min(count($rows), $headerRow + 40); $r++) {
            $name = $nameIdx !== null ? trim((string) ($rows[$r][$nameIdx] ?? '')) : '';
            $sku = $skuIdx !== null ? trim((string) ($rows[$r][$skuIdx] ?? '')) : '';
            $price = $priceIdx !== null ? trim((string) ($rows[$r][$priceIdx] ?? '')) : '';
            if ($name === '' && $sku === '') {
                continue;
            }
            if ($priceIdx !== null && $price !== '' && ! preg_match('/\d/', $price)) {
                continue;
            }
            if ($name !== '' || preg_match('/\d{4,}/', $sku)) {
                $hits++;
            }
        }

        return $hits;
    }

    /**
     * @param  list<list<string>>  $rows
     * @return array{header_row: int, columns: array<string, int|null>, notes: string}|null
     */
    private function aiMapHeaders(array $rows): ?array
    {
        try {
            $sample = array_slice($rows, 0, 12);
            $payload = [];
            foreach ($sample as $i => $row) {
                $payload[] = ['row' => $i + 1, 'cells' => array_slice($row, 0, 16)];
            }
            $messages = [
                [
                    'role' => 'system',
                    'content' => 'Mapujesz kolumny arkusza przetargowego/cennika. Odpowiadasz tylko JSON.',
                ],
                [
                    'role' => 'user',
                    'content' => "Znajdź wiersz nagłówka (0-based index) i indeksy kolumn (0-based lub null):\n"
                        ."- sku: kod katalogowy produktu (NIE L.p. / lp / numer wiersza)\n"
                        ."- name: nazwa / nazwa asortymentu (NIE projekt/klient)\n"
                        ."- description: osobny opis, jeśli jest inną kolumną niż nazwa\n"
                        ."- norms: normy EN/ISO jeśli są w osobnej kolumnie\n"
                        ."- offer_price: cena oferty (nie % wzrostu)\n"
                        ."- quantity: ilość / szacunkowe ilości roczne\n"
                        ."JSON: {\"header_row\":2,\"columns\":{\"sku\":null,\"name\":1,\"description\":null,\"norms\":2,\"offer_price\":5,\"quantity\":4}}\n\n"
                        .json_encode($payload, JSON_UNESCAPED_UNICODE),
                ],
            ];
            $raw = $this->llm->chat($messages);
            $parsed = $this->jsonParser->parse($raw['content'] ?? '');
            if (! is_array($parsed)) {
                return null;
            }
            $header = (int) ($parsed['header_row'] ?? 0);
            $c = is_array($parsed['columns'] ?? null) ? $parsed['columns'] : [];
            $columns = [
                'sku' => isset($c['sku']) && is_numeric($c['sku']) ? (int) $c['sku'] : null,
                'name' => isset($c['name']) && is_numeric($c['name']) ? (int) $c['name'] : null,
                'description' => isset($c['description']) && is_numeric($c['description']) ? (int) $c['description'] : null,
                'norms' => isset($c['norms']) && is_numeric($c['norms']) ? (int) $c['norms'] : null,
                'offer_price' => isset($c['offer_price']) && is_numeric($c['offer_price']) ? (int) $c['offer_price'] : null,
                'quantity' => isset($c['quantity']) && is_numeric($c['quantity']) ? (int) $c['quantity'] : null,
                'project' => null,
                'client' => null,
                'currency' => null,
            ];
            if ($columns['sku'] === null && $columns['name'] === null) {
                return null;
            }

            return [
                'header_row' => max(0, $header),
                'columns' => $columns,
                'notes' => 'Mapowanie nagłówków (AI).',
            ];
        } catch (\Throwable) {
            return null;
        }
    }

    /**
     * @param  list<list<string>>  $rows
     * @param  array<string, int|null>  $cols
     * @return list<array{sku: ?string, name: string, requirement: string, quantity: int, offer_price: ?float, currency: ?string, norms: ?string, description: ?string}>
     */
    private function rowsToItems(array $rows, int $headerRow, array $cols): array
    {
        $items = [];
        $defaultCurrency = null;
        $headerBlob = implode(' ', $rows[$headerRow] ?? []);
        $defaultCurrency = $this->currencyDetector->detect($headerBlob);

        for ($r = $headerRow + 1; $r < count($rows); $r++) {
            $row = $rows[$r];
            $sku = $cols['sku'] !== null ? trim((string) ($row[$cols['sku']] ?? '')) : '';
            $name = $cols['name'] !== null ? trim((string) ($row[$cols['name']] ?? '')) : '';
            $description = ($cols['description'] ?? null) !== null
                ? trim((string) ($row[$cols['description']] ?? ''))
                : '';
            $norms = ($cols['norms'] ?? null) !== null
                ? trim((string) ($row[$cols['norms']] ?? ''))
                : '';
            if ($this->isLineNumberSku($sku)) {
                $sku = '';
            }
            if ($sku === '' && $name === '' && $description === '') {
                continue;
            }
            // pomiń wiersze-nagłówki
            $skuLower = mb_strtolower($sku);
            $nameLower = mb_strtolower($name);
            if (in_array($skuLower, ['article', 'sku', 'kod', 'l.p.', 'lp'], true)
                || in_array($nameLower, ['name of article', 'nazwa', 'nazwa asortymentu', 'name', 'przedmiot zamówienia'], true)
                || str_starts_with($nameLower, 'suma')
                || preg_match('/^\d+(\s*\(\d+\s*[x×]\s*\d+\))?$/u', $name) === 1) {
                continue;
            }
            // wiersz numeracji kolumn: 1 | 2 | 3 | 4 | 5
            if ($this->looksLikeColumnIndexRow($row)) {
                continue;
            }

            $priceRaw = $cols['offer_price'] !== null ? ($row[$cols['offer_price']] ?? null) : null;
            $price = $this->toFloat($priceRaw);
            $qty = 1;
            if ($cols['quantity'] !== null) {
                $q = $this->toFloat($row[$cols['quantity']] ?? null);
                if ($q !== null && $q >= 1) {
                    $qty = (int) round($q);
                }
            }

            $currency = $defaultCurrency;
            if ($cols['currency'] !== null) {
                $currency = $this->currencyDetector->detect((string) ($row[$cols['currency']] ?? ''))
                    ?? $currency;
            }
            if ($currency === null && is_string($priceRaw)) {
                $currency = $this->currencyDetector->detect($priceRaw);
            }

            if ($name === '' && $description !== '') {
                $name = $description;
            }
            if ($name === '' && $sku !== '') {
                $name = 'Pozycja '.$sku;
            }
            if ($sku !== '' && ! preg_match('/[A-Za-z0-9]/', $sku)) {
                $sku = '';
            }
            if ($norms !== '' && in_array(mb_strtolower($norms), ['normy', 'norma', '-', '—', '–', 'brak', 'n/a'], true)) {
                $norms = '';
            }

            $reqParts = array_values(array_filter([
                $name !== '' ? $name : null,
                ($description !== '' && mb_strtolower($description) !== mb_strtolower($name)) ? $description : null,
                $norms !== '' ? $norms : null,
            ]));
            $items[] = [
                'sku' => $sku !== '' ? $sku : null,
                'name' => $name,
                'requirement' => implode(' · ', $reqParts),
                'quantity' => max(1, $qty),
                'offer_price' => $price,
                'currency' => $currency,
                'norms' => $norms !== '' ? $norms : null,
                'description' => ($description !== '' && mb_strtolower($description) !== mb_strtolower($name))
                    ? $description
                    : null,
            ];
        }

        return $items;
    }

    /**
     * @param  list<string>  $row
     */
    private function looksLikeColumnIndexRow(array $row): bool
    {
        $nonEmpty = array_values(array_filter($row, static fn (string $c): bool => trim($c) !== ''));
        if (count($nonEmpty) < 3) {
            return false;
        }
        $numericish = 0;
        foreach ($nonEmpty as $cell) {
            if (preg_match('/^\d+(\s*\([^)]*\))?$/u', trim($cell)) === 1) {
                $numericish++;
            }
        }

        return $numericish >= max(3, (int) ceil(count($nonEmpty) * 0.7));
    }

    /**
     * @return array<string, int|null>
     */
    private function emptyColumnMap(): array
    {
        return [
            'sku' => null,
            'name' => null,
            'description' => null,
            'norms' => null,
            'offer_price' => null,
            'quantity' => null,
            'project' => null,
            'client' => null,
            'currency' => null,
        ];
    }

    private function isLineNumberSku(string $sku): bool
    {
        return $sku !== '' && preg_match('/^\d{1,4}\.$/', $sku) === 1;
    }

    private function toFloat(mixed $value): ?float
    {
        if ($value === null || $value === '') {
            return null;
        }
        if (is_numeric($value)) {
            return (float) $value;
        }
        $s = str_replace(["\xc2\xa0", ' ', '€', 'EUR', 'PLN', 'zł', 'ZL'], '', (string) $value);
        $s = str_replace('%', '', $s);
        $s = str_replace(',', '.', $s);
        if (! is_numeric($s)) {
            return null;
        }

        return (float) $s;
    }
}
