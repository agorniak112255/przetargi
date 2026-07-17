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
     *     items: list<array{sku: ?string, name: string, requirement: string, quantity: int, offer_price: ?float, currency: ?string}>,
     *     column_map: array<string, int|null>,
     *     header_row: int,
     *     notes: string
     * }|null
     */
    public function extract(string $path, bool $useAiMapping = true): ?array
    {
        $book = IOFactory::load($path);
        $bestItems = [];
        $bestMap = [];
        $bestHeader = 1;
        $bestNotes = '';
        $bestCount = 0;

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

            $detected = $this->detectMapping($normalized, $useAiMapping);
            if ($detected === null) {
                continue;
            }

            $items = $this->rowsToItems($normalized, $detected['header_row'], $detected['columns']);
            if (count($items) > $bestCount) {
                $bestCount = count($items);
                $bestItems = $items;
                $bestMap = $detected['columns'];
                $bestHeader = $detected['header_row'];
                $bestNotes = $detected['notes'];
            }
        }

        if ($bestCount === 0) {
            return null;
        }

        return [
            'items' => array_slice($bestItems, 0, 500),
            'column_map' => $bestMap,
            'header_row' => $bestHeader,
            'notes' => $bestNotes,
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
            || ($best['columns']['sku'] ?? null) === null
            || (($best['columns']['name'] ?? null) === null && ($best['columns']['sku'] ?? null) === null));

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
                'kod', 'nr poz', 'pozycja nr', 'lp',
            ]),
            'name' => $this->findCol($labels, [
                'name of article', 'name of product', 'nazwa artykułu', 'nazwa artykulu',
                'nazwa produktu', 'nazwa towaru', 'opis produktu', 'asortyment',
                'name of article', 'product name', 'article name', 'nazwa', 'name', 'opis', 'description', 'produkt',
            ], exclude: ['project', 'client', 'klient', 'cena', 'price']),
            'offer_price' => $this->findPreferredPriceCol($labels),
            'quantity' => $this->findCol($labels, [
                'quantity', 'qty', 'ilość', 'ilosc', 'szt', 'amount', 'liczba',
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
            'special price from', 'cena specjalna od', 'cena od', 'new price', 'nowa cena',
            'cena oferty', 'offer price', 'cena jednostkowa', 'unit price',
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
                        ."- sku: numer artykułu / kod / Article\n"
                        ."- name: nazwa produktu / Name of Article (NIE nazwa projektu/klienta)\n"
                        ."- offer_price: cena oferty / special price (preferuj najnowszą cenę, nie % wzrostu)\n"
                        ."- quantity: ilość jeśli jest\n"
                        ."JSON: {\"header_row\":0,\"columns\":{\"sku\":3,\"name\":4,\"offer_price\":7,\"quantity\":null}}\n\n"
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
     * @return list<array{sku: ?string, name: string, requirement: string, quantity: int, offer_price: ?float, currency: ?string}>
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
            if ($sku === '' && $name === '') {
                continue;
            }
            // pomiń wiersze-nagłówki
            $skuLower = mb_strtolower($sku);
            $nameLower = mb_strtolower($name);
            if (in_array($skuLower, ['article', 'sku', 'kod'], true)
                || in_array($nameLower, ['name of article', 'nazwa', 'name'], true)) {
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

            if ($name === '' && $sku !== '') {
                $name = 'Pozycja '.$sku;
            }
            if ($sku !== '' && ! preg_match('/[A-Za-z0-9]/', $sku)) {
                $sku = '';
            }

            $reqParts = array_filter([$sku !== '' ? $sku : null, $name]);
            $items[] = [
                'sku' => $sku !== '' ? $sku : null,
                'name' => $name,
                'requirement' => implode(' · ', $reqParts),
                'quantity' => max(1, $qty),
                'offer_price' => $price,
                'currency' => $currency,
            ];
        }

        return $items;
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
