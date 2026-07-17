<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\PriceList;
use App\Models\Product;
use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use PhpOffice\PhpSpreadsheet\IOFactory;

final class PriceListImportService
{
    public function __construct(
        private readonly CurrencyDetector $currencyDetector,
    ) {}

    /**
     * @return array{price_list: PriceList, created: int, updated: int, skipped: int, errors: list<string>}
     */
    public function import(
        UploadedFile $file,
        string $manufacturer,
        string $version,
        User $user,
        ?string $defaultCategory = null,
    ): array {
        $path = $file->getRealPath();
        if ($path === false) {
            return $this->emptyResult('Nie można odczytać pliku.');
        }

        $rows = IOFactory::load($path)->getActiveSheet()->toArray(null, true, true, false);
        if ($rows === []) {
            return $this->emptyResult('Plik jest pusty.');
        }

        $header = array_map(
            static fn ($v) => mb_strtolower(trim((string) $v)),
            $rows[0] ?? []
        );
        $map = $this->resolveColumns($header);

        if (! isset($map['sku'], $map['name'], $map['catalog_price'])) {
            return $this->emptyResult(
                'Wymagane kolumny: sku (kod), nazwa, cena (katalogowa). Opcjonalnie: ean, kategoria, normy, rabat, zakup, stan, producent. '
                .'Dla cenników producentów użyj „Analizuj AI”.'
            );
        }

        return $this->persistImport(
            $file,
            $manufacturer,
            $version,
            $user,
            $this->collectFromSimpleRows(array_slice($rows, 1), $map, $defaultCategory, $manufacturer),
        );
    }

    /**
     * @param  array{
     *     manufacturer_detected?: ?string,
     *     currency?: ?string,
     *     notes?: string,
     *     sheets: list<array<string, mixed>>
     * }  $mapping
     * @return array{price_list: PriceList|null, created: int, updated: int, skipped: int, errors: list<string>}
     */
    public function importWithMapping(
        UploadedFile $file,
        string $manufacturer,
        string $version,
        User $user,
        array $mapping,
        ?string $defaultCategory = null,
    ): array {
        $path = $file->getRealPath();
        if ($path === false) {
            return $this->emptyResult('Nie można odczytać pliku.');
        }

        $collected = $this->collectFromMapping($path, $mapping, $defaultCategory, $manufacturer);

        return $this->persistImport($file, $manufacturer, $version, $user, $collected);
    }

    /**
     * @param  list<array<string, mixed>>  $products
     * @return array{price_list: PriceList|null, created: int, updated: int, skipped: int, errors: list<string>}
     */
    public function importFromProducts(
        UploadedFile $file,
        string $manufacturer,
        string $version,
        User $user,
        array $products,
        ?string $defaultCategory = null,
    ): array {
        $normalized = [];
        $skipped = 0;
        $errors = [];
        $skippedDetails = [];

        foreach ($products as $index => $row) {
            if (! is_array($row)) {
                $skipped++;
                $skippedDetails[] = [
                    'reason' => 'Pozycja '.($index + 1).': nieprawidłowy wiersz',
                    'row' => $index + 1,
                    'sheet' => null,
                    'sku' => null,
                    'name' => null,
                ];
                continue;
            }
            $sku = trim((string) ($row['sku'] ?? ''));
            $name = trim((string) ($row['name'] ?? ''));
            $price = $row['catalog_price_net'] ?? $row['catalog_price'] ?? null;
            if ($sku === '' || $name === '' || ! is_numeric($price)) {
                $msg = 'Pozycja '.($index + 1).': brak sku/nazwy/ceny';
                $errors[] = $msg;
                $skipped++;
                $skippedDetails[] = [
                    'reason' => $msg,
                    'row' => $index + 1,
                    'sheet' => null,
                    'sku' => $sku !== '' ? $sku : null,
                    'name' => $name !== '' ? $name : null,
                ];
                continue;
            }
            $discount = is_numeric($row['discount_percent'] ?? $row['discount'] ?? null)
                ? (float) ($row['discount_percent'] ?? $row['discount'])
                : 0.0;
            $purchase = is_numeric($row['purchase_price'] ?? $row['purchase'] ?? null)
                ? (float) ($row['purchase_price'] ?? $row['purchase'])
                : round((float) $price * (1 - ($discount / 100)), 2);

            $packQty = $row['pack_qty'] ?? null;
            $packaging = isset($row['packaging']) ? trim((string) $row['packaging']) : '';
            $currency = $this->currencyDetector->normalize(
                isset($row['currency']) && is_string($row['currency']) ? $row['currency'] : null,
                'PLN'
            );

            $normalized[] = [
                'sku' => $sku,
                'name' => $name,
                'manufacturer' => $manufacturer,
                'ean' => isset($row['ean']) ? (trim((string) $row['ean']) ?: null) : null,
                'category' => isset($row['category']) && is_string($row['category']) && $row['category'] !== ''
                    ? $row['category']
                    : $defaultCategory,
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

        if ($normalized === []) {
            return $this->emptyResult('Brak poprawnych pozycji do importu z PDF/AI.');
        }

        return $this->persistImport($file, $manufacturer, $version, $user, [
            'products' => $normalized,
            'skipped' => $skipped,
            'errors' => $errors,
            'skipped_details' => $skippedDetails,
            'rows_total' => count($products),
        ]);
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
    public function previewFromMapping(string $path, array $mapping, int $limit = 8): array
    {
        $collected = $this->collectFromMapping($path, $mapping, null, 'PREVIEW');
        $items = array_slice($collected['products'], 0, $limit);

        return [
            'items' => $items,
            'products' => $collected['products'],
            'products_found' => count($collected['products']),
            'rows_total' => $collected['rows_total'],
            'skipped' => $collected['skipped'],
            'errors_count' => count($collected['errors']),
        ];
    }

    /**
     * @param  array{
     *     products: list<array<string, mixed>>,
     *     skipped: int,
     *     errors: list<string>,
     *     rows_total: int
     * }  $collected
     * @return array{
     *     price_list: PriceList,
     *     created: int,
     *     updated: int,
     *     skipped: int,
     *     errors: list<string>,
     *     prices_changed: int,
     *     price_changes: list<array<string, mixed>>
     * }
     */
    private function persistImport(
        UploadedFile $file,
        string $manufacturer,
        string $version,
        User $user,
        array $collected,
    ): array {
        $created = 0;
        $updated = 0;
        $priceChanges = [];
        $updatedProducts = [];

        DB::transaction(function () use ($collected, &$created, &$updated, &$priceChanges, &$updatedProducts): void {
            foreach ($collected['products'] as $payload) {
                $sku = (string) $payload['sku'];
                unset($payload['sku']);
                $payload = $this->clampProductFields($payload);
                $existing = Product::query()->where('sku', $sku)->first();
                if ($existing !== null) {
                    $change = $this->detectPriceChange($existing, $payload, $sku);
                    if ($change !== null) {
                        $priceChanges[] = $change;
                    }
                    $updatedProducts[] = $this->summarizeUpdate($existing, $payload, $sku, $change !== null);
                    $existing->update($payload);
                    $updated++;
                } else {
                    Product::query()->create(['sku' => $sku, ...$payload]);
                    $created++;
                }
            }
        });


        // największe zmiany % najpierw
        usort($priceChanges, static fn (array $a, array $b): int => abs($b['catalog_pct']) <=> abs($a['catalog_pct']));

        $skippedDetails = is_array($collected['skipped_details'] ?? null)
            ? array_slice($collected['skipped_details'], 0, 100)
            : $this->skippedDetailsFromErrors($collected['errors'] ?? [], (int) $collected['skipped']);

        $priceList = PriceList::query()->create([
            'manufacturer' => $manufacturer,
            'version' => $version,
            'original_filename' => $file->getClientOriginalName(),
            'imported_by' => $user->id,
            'rows_total' => $collected['rows_total'],
            'products_created' => $created,
            'products_updated' => $updated,
            'prices_changed' => count($priceChanges),
            'rows_skipped' => $collected['skipped'],
            'errors' => array_slice($collected['errors'], 0, 50),
            'price_changes' => array_slice($priceChanges, 0, 100),
            'updated_products' => array_slice($updatedProducts, 0, 100),
            'skipped_details' => $skippedDetails,
        ]);

        return [
            'price_list' => $priceList->load('importer:id,name'),
            'created' => $created,
            'updated' => $updated,
            'skipped' => $collected['skipped'],
            'errors' => $collected['errors'],
            'prices_changed' => count($priceChanges),
            'price_changes' => array_slice($priceChanges, 0, 100),
            'updated_products' => array_slice($updatedProducts, 0, 100),
            'skipped_details' => $skippedDetails,
        ];
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    private function summarizeUpdate(Product $existing, array $payload, string $sku, bool $priceChanged): array
    {
        $fields = [];
        foreach (['name', 'manufacturer', 'category', 'currency', 'packaging'] as $field) {
            $old = (string) ($existing->{$field} ?? '');
            $new = (string) ($payload[$field] ?? $old);
            if ($old !== $new) {
                $fields[] = $field;
            }
        }
        foreach (['catalog_price_net', 'purchase_price', 'discount_percent', 'pack_qty'] as $field) {
            $old = (float) ($existing->{$field} ?? 0);
            $new = (float) ($payload[$field] ?? $old);
            if (abs($old - $new) >= 0.001) {
                $fields[] = $field;
            }
        }

        return [
            'sku' => $sku,
            'name' => (string) ($payload['name'] ?? $existing->name),
            'catalog_old' => round((float) $existing->catalog_price_net, 2),
            'catalog_new' => round((float) ($payload['catalog_price_net'] ?? $existing->catalog_price_net), 2),
            'purchase_old' => round((float) $existing->purchase_price, 2),
            'purchase_new' => round((float) ($payload['purchase_price'] ?? $existing->purchase_price), 2),
            'discount_old' => round((float) $existing->discount_percent, 2),
            'discount_new' => round((float) ($payload['discount_percent'] ?? $existing->discount_percent), 2),
            'price_changed' => $priceChanged,
            'fields' => $fields !== [] ? $fields : ['bez zmian wartości'],
        ];
    }

    /**
     * @param  list<string>  $errors
     * @return list<array<string, mixed>>
     */
    private function skippedDetailsFromErrors(array $errors, int $skippedTotal): array
    {
        $out = [];
        foreach (array_slice($errors, 0, 80) as $msg) {
            $out[] = [
                'reason' => $msg,
                'row' => null,
                'sheet' => null,
                'sku' => null,
                'name' => null,
            ];
        }
        $covered = count($errors);
        if ($skippedTotal > $covered) {
            array_unshift($out, [
                'reason' => 'Puste wiersze / nagłówki / sekcje bez ceny: '.($skippedTotal - $covered),
                'row' => null,
                'sheet' => null,
                'sku' => null,
                'name' => null,
            ]);
        }

        return array_slice($out, 0, 100);
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>|null
     */
    private function detectPriceChange(Product $existing, array $payload, string $sku): ?array
    {
        $oldCatalog = (float) $existing->catalog_price_net;
        $newCatalog = (float) ($payload['catalog_price_net'] ?? $oldCatalog);
        $oldPurchase = (float) $existing->purchase_price;
        $newPurchase = (float) ($payload['purchase_price'] ?? $oldPurchase);
        $oldDiscount = (float) $existing->discount_percent;
        $newDiscount = (float) ($payload['discount_percent'] ?? $oldDiscount);

        $catalogDiff = abs($oldCatalog - $newCatalog) >= 0.005;
        $purchaseDiff = abs($oldPurchase - $newPurchase) >= 0.005;
        $discountDiff = abs($oldDiscount - $newDiscount) >= 0.05;

        if (! $catalogDiff && ! $purchaseDiff && ! $discountDiff) {
            return null;
        }

        $catalogPct = $oldCatalog > 0
            ? round((($newCatalog - $oldCatalog) / $oldCatalog) * 100, 1)
            : ($newCatalog > 0 ? 100.0 : 0.0);

        return [
            'sku' => $sku,
            'name' => (string) ($payload['name'] ?? $existing->name),
            'catalog_old' => round($oldCatalog, 2),
            'catalog_new' => round($newCatalog, 2),
            'catalog_pct' => $catalogPct,
            'purchase_old' => round($oldPurchase, 2),
            'purchase_new' => round($newPurchase, 2),
            'discount_old' => round($oldDiscount, 2),
            'discount_new' => round($newDiscount, 2),
            'direction' => $catalogPct > 0.05 ? 'up' : ($catalogPct < -0.05 ? 'down' : 'flat'),
        ];
    }

    /**
     * @param  list<array<int, mixed>>  $dataRows
     * @param  array<string, int>  $map
     * @return array{products: list<array<string, mixed>>, skipped: int, errors: list<string>, rows_total: int}
     */
    private function collectFromSimpleRows(
        array $dataRows,
        array $map,
        ?string $defaultCategory,
        string $manufacturer,
    ): array {
        $products = [];
        $skipped = 0;
        $errors = [];
        $skippedDetails = [];
        $emptySkips = 0;
        $headerSkips = 0;

        $defaultCurrency = $this->currencyDetector->normalize(null, 'PLN');
        foreach ($dataRows as $index => $row) {
            $excelRow = $index + 2;
            $parsed = $this->parseRow($row, $map, $defaultCategory, $manufacturer, $excelRow, null, $defaultCurrency);
            if ($parsed['status'] === 'skip') {
                $skipped++;
                $emptySkips++;
                continue;
            }
            if ($parsed['status'] === 'error') {
                $msg = $parsed['message'] ?? "Wiersz {$excelRow}: błąd";
                $errors[] = $msg;
                $skipped++;
                $skippedDetails[] = [
                    'reason' => $msg,
                    'row' => $excelRow,
                    'sheet' => null,
                    'sku' => null,
                    'name' => null,
                ];
                continue;
            }
            $products[] = $parsed['product'];
        }

        return [
            'products' => $products,
            'skipped' => $skipped,
            'errors' => $errors,
            'skipped_details' => $this->finalizeSkippedDetails($skippedDetails, $emptySkips, $headerSkips, $skipped),
            'rows_total' => count($dataRows),
        ];
    }

    /**
     * @param  array{sheets: list<array<string, mixed>>}  $mapping
     * @return array{products: list<array<string, mixed>>, skipped: int, errors: list<string>, rows_total: int}
     */
    private function collectFromMapping(
        string $path,
        array $mapping,
        ?string $defaultCategory,
        string $manufacturer,
    ): array {
        $spreadsheet = IOFactory::load($path);
        $bySku = [];
        $skipped = 0;
        $errors = [];
        $rowsTotal = 0;

        foreach ($mapping['sheets'] as $sheetMap) {
            if (! ($sheetMap['include'] ?? false)) {
                continue;
            }
            $sheetName = (string) ($sheetMap['sheet'] ?? '');
            $sheet = $spreadsheet->getSheetByName($sheetName);
            if ($sheet === null) {
                $errors[] = "Brak arkusza: {$sheetName}";
                continue;
            }

            $cols = is_array($sheetMap['columns'] ?? null) ? $sheetMap['columns'] : [];
            $map = [];
            foreach (['sku', 'name', 'catalog_price', 'discount', 'purchase', 'ean', 'category', 'pack_qty', 'packaging', 'currency'] as $key) {
                if (isset($cols[$key]) && is_numeric($cols[$key])) {
                    $map[$key] = (int) $cols[$key];
                }
            }
            if (! isset($map['name'], $map['catalog_price'])) {
                $errors[] = "Arkusz {$sheetName}: niepełne mapowanie kolumn (wymagane: nazwa + cena)";
                continue;
            }

            $headerExcelRow = max(1, (int) ($sheetMap['header_excel_row'] ?? $sheetMap['header_row'] ?? 1));
            $headerIdx = $headerExcelRow - 1;
            $repeating = (bool) ($sheetMap['repeating_headers'] ?? false);
            $all = $sheet->toArray(null, true, true, false);
            $headerLabels = $this->headerLabels($all, $headerIdx, $map);
            $sheetDefaultCurrency = $this->currencyDetector->normalize(
                is_string($mapping['currency'] ?? null) ? $mapping['currency'] : null,
                $this->currencyDetector->detect(implode(' ', $headerLabels)) ?? 'PLN'
            );
            $dataRows = array_slice($all, $headerIdx + 1);
            $rowsTotal += count($dataRows);

            foreach ($dataRows as $index => $row) {
                $excelRow = $headerExcelRow + $index + 1;
                if ($this->isHeaderLikeRow($row, $map, $headerLabels, $repeating)) {
                    $skipped++;
                    continue;
                }

                $parsed = $this->parseRow(
                    $row,
                    $map,
                    $defaultCategory,
                    $manufacturer,
                    $excelRow,
                    $sheetName,
                    $sheetDefaultCurrency,
                );
                if ($parsed['status'] === 'skip') {
                    $skipped++;
                    continue;
                }
                if ($parsed['status'] === 'error') {
                    $errors[] = $parsed['message'] ?? "Wiersz {$excelRow}: błąd";
                    $skipped++;
                    continue;
                }

                $sku = (string) $parsed['product']['sku'];
                $bySku[$sku] = $parsed['product'];
            }
        }

        return [
            'products' => array_values($bySku),
            'skipped' => $skipped,
            'errors' => $errors,
            'rows_total' => $rowsTotal,
        ];
    }

    /**
     * @param  list<array<int, mixed>>  $all
     * @param  array<string, int>  $map
     * @return array<string, string>
     */
    private function headerLabels(array $all, int $headerRow, array $map): array
    {
        $header = $all[$headerRow] ?? [];
        $labels = [];
        foreach ($map as $key => $idx) {
            $labels[$key] = mb_strtolower(trim((string) ($header[$idx] ?? '')));
        }

        return $labels;
    }

    /**
     * @param  array<int, mixed>  $row
     * @param  array<string, int>  $map
     * @param  array<string, string>  $headerLabels
     */
    private function isHeaderLikeRow(array $row, array $map, array $headerLabels, bool $repeating): bool
    {
        $sku = isset($map['sku']) ? mb_strtolower(trim((string) ($row[$map['sku']] ?? ''))) : '';
        $name = mb_strtolower(trim((string) ($row[$map['name']] ?? '')));

        if ($sku === '' && $name === '') {
            return false;
        }

        if ($repeating || $sku !== '' || $name !== '') {
            foreach (['sku', 'kod produktu', 'product reference', 'sap id', 'kod', 'asortyment'] as $token) {
                if ($sku === $token || str_contains($sku, 'kod produktu') || $sku === 'sap id') {
                    return true;
                }
            }
            if (str_contains($name, 'cena sugerowana') || str_contains($name, 'cena hurtowa')
                || ($name === 'cena' || str_starts_with($name, 'cena '))) {
                return true;
            }
            if (($headerLabels['sku'] ?? '') !== '' && $sku === $headerLabels['sku']) {
                return true;
            }
            if (($headerLabels['name'] ?? '') !== '' && $name === $headerLabels['name']) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param  array<int, mixed>  $row
     * @param  array<string, int>  $map
     * @return array{status: string, product?: array<string, mixed>, message?: string}
     */
    private function parseRow(
        array $row,
        array $map,
        ?string $defaultCategory,
        string $manufacturer,
        int $excelRow,
        ?string $sheetName = null,
        ?string $defaultCurrency = 'PLN',
    ): array {
        $prefix = $sheetName !== null ? "[{$sheetName}] " : '';
        $sku = isset($map['sku']) ? trim((string) ($row[$map['sku']] ?? '')) : '';
        $name = trim((string) ($row[$map['name']] ?? ''));
        $priceRaw = $row[$map['catalog_price']] ?? null;

        if ($sku === '' && $name === '') {
            return ['status' => 'skip'];
        }

        // wiersze-sekcje typu „ODZIEŻ SPAWALNICZA…” bez ceny
        if ($name !== '' && ($priceRaw === null || $priceRaw === '') && $sku === '') {
            return ['status' => 'skip'];
        }

        if ($name === '' || $priceRaw === null || $priceRaw === '') {
            return [
                'status' => 'error',
                'message' => "{$prefix}Wiersz {$excelRow}: brak nazwy/ceny — pominięto",
            ];
        }

        if ($sku === '') {
            $slug = strtoupper(preg_replace('/[^A-Za-z0-9]+/', '-', $name) ?? 'POZ');
            $slug = trim(mb_substr($slug, 0, 28), '-');
            $sku = ($slug !== '' ? $slug : 'POZ').'-'.$excelRow;
        }

        $catalog = $this->toFloat($priceRaw);
        if ($catalog === null) {
            return [
                'status' => 'error',
                'message' => "{$prefix}Wiersz {$excelRow}: niepoprawna cena",
            ];
        }

        $discount = isset($map['discount'])
            ? ($this->toFloat($row[$map['discount']] ?? 0) ?? 0.0)
            : 0.0;

        $purchase = isset($map['purchase'])
            ? $this->toFloat($row[$map['purchase']] ?? null)
            : null;

        if ($purchase === null) {
            $purchase = round($catalog * (1 - ($discount / 100)), 2);
        }

        $category = $defaultCategory;
        if (isset($map['category'])) {
            $cat = trim((string) ($row[$map['category']] ?? ''));
            if ($cat !== '') {
                $category = $cat;
            }
        }

        $packQty = null;
        if (isset($map['pack_qty'])) {
            $packRaw = $this->toFloat($row[$map['pack_qty']] ?? null);
            if ($packRaw !== null) {
                $packQty = max(0, (int) round($packRaw));
            }
        }

        $packaging = null;
        if (isset($map['packaging'])) {
            $packaging = trim((string) ($row[$map['packaging']] ?? '')) ?: null;
        }

        $currency = $defaultCurrency ?? 'PLN';
        if (isset($map['currency'])) {
            $fromCol = $this->currencyDetector->detect((string) ($row[$map['currency']] ?? ''));
            if ($fromCol !== null) {
                $currency = $fromCol;
            }
        } else {
            $fromPrice = $this->currencyDetector->detect(is_string($priceRaw) ? $priceRaw : (string) $priceRaw);
            if ($fromPrice !== null) {
                $currency = $fromPrice;
            }
        }
        $currency = $this->currencyDetector->normalize($currency, $defaultCurrency ?? 'PLN');

        return [
            'status' => 'ok',
            'product' => [
                'sku' => $sku,
                'name' => $name,
                'manufacturer' => $manufacturer,
                'ean' => isset($map['ean']) ? trim((string) ($row[$map['ean']] ?? '')) ?: null : null,
                'category' => $category,
                'norms' => null,
                'catalog_price_net' => $catalog,
                'discount_percent' => $discount,
                'purchase_price' => $purchase,
                'currency' => $currency,
                'stock' => 0,
                'pack_qty' => $packQty,
                'packaging' => $packaging,
            ],
        ];
    }

    /**
     * @param  list<string>  $header
     * @return array<string, int>
     */
    private function resolveColumns(array $header): array
    {
        $find = static function (array $aliases) use ($header): ?int {
            foreach ($header as $i => $col) {
                foreach ($aliases as $alias) {
                    if ($col === $alias || str_contains($col, $alias)) {
                        return $i;
                    }
                }
            }

            return null;
        };

        $map = [];
        $defs = [
            'sku' => ['sku', 'symbol', 'kod', 'code', 'indeks', 'ref', 'product reference'],
            'name' => ['nazwa', 'name', 'produkt', 'opis', 'description'],
            'catalog_price' => ['cena_kat', 'cena katalog', 'cena_netto', 'cena', 'price', 'netto'],
            'discount' => ['upust', 'rabat', 'discount', 'marża', 'marza', 'mraza'],
            'purchase' => ['zakup', 'cena_zakupu', 'purchase', 'koszt', 'po upuście', 'po upust'],
            'ean' => ['ean', 'barcode'],
            'category' => ['kategoria', 'category', 'grupa'],
            'norms' => ['norma', 'normy', 'en ', 'standard'],
            'pack_qty' => [
                'ilość szt', 'ilosc szt', 'szt. w', 'szt w', 'w kart', 'w opak',
                'pack size', 'units per', 'ilość w opak', 'ilosc w opak', 'opak. zbior',
            ],
            'packaging' => ['opakowanie', 'packaging', 'jednostka', 'uom', 'pojemność', 'pojemnosc'],
            'currency' => ['waluta', 'currency', 'curr', 'iso'],
            'stock' => ['stan magazyn', 'stock', 'magazyn'],
            'manufacturer' => ['producent', 'manufacturer', 'marka', 'brand'],
        ];

        foreach ($defs as $key => $aliases) {
            $idx = $find($aliases);
            if ($idx !== null) {
                $map[$key] = $idx;
            }
        }

        return $map;
    }

    private function toFloat(mixed $value): ?float
    {
        if ($value === null || $value === '') {
            return null;
        }
        if (is_numeric($value)) {
            return (float) $value;
        }
        $s = str_replace([' ', "\xc2\xa0", '€', 'EUR', 'PLN', '%'], '', (string) $value);
        $s = str_replace(',', '.', $s);
        if (! is_numeric($s)) {
            return null;
        }

        return (float) $s;
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    private function clampProductFields(array $payload): array
    {
        $maxName = 1000;
        $name = trim((string) ($payload['name'] ?? ''));
        if (mb_strlen($name) > $maxName) {
            $desc = trim((string) ($payload['description'] ?? ''));
            if ($desc === '') {
                $payload['description'] = $name;
            }
            $payload['name'] = mb_substr($name, 0, $maxName);
        }

        return $payload;
    }

    /**
     * @return array{price_list: null, created: int, updated: int, skipped: int, errors: list<string>}
     */
    private function emptyResult(string $error): array
    {
        return [
            'price_list' => null,
            'created' => 0,
            'updated' => 0,
            'skipped' => 0,
            'errors' => [$error],
            'prices_changed' => 0,
            'price_changes' => [],
            'updated_products' => [],
            'skipped_details' => [],
        ];
    }
}
