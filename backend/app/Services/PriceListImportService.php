<?php

declare(strict_types=1);

namespace App\Services;

use App\Jobs\RegisterManufacturerCatalogJob;
use App\Jobs\ReindexProductEmbeddingJob;
use App\Models\PriceList;
use App\Models\Product;
use App\Models\ProductPriceHistory;
use App\Models\User;
use App\Support\ProductSizeVariant;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use PhpOffice\PhpSpreadsheet\IOFactory;

final class PriceListImportService
{
    public function __construct(
        private readonly CurrencyDetector $currencyDetector,
        private readonly AssortmentGroupService $assortmentGroups,
        private readonly ProductSizeVariant $sizes,
        private readonly SpreadsheetColumnMapper $columnMapper,
        private readonly ProductSpecialPriceImporter $specialPrices,
    ) {}

    /**
     * @param  array<string, mixed>|null  $groupOptions
     * @return array{price_list: PriceList, created: int, updated: int, skipped: int, errors: list<string>}
     */
    public function import(
        UploadedFile $file,
        string $manufacturer,
        string $version,
        User $user,
        ?string $defaultCategory = null,
        ?array $groupOptions = null,
    ): array {
        $path = $file->getRealPath();
        if ($path === false) {
            return $this->emptyResult('Nie można odczytać pliku.');
        }

        $rows = IOFactory::load($path)->getActiveSheet()->toArray(null, true, true, false);
        if ($rows === []) {
            return $this->emptyResult('Plik jest pusty.');
        }

        $headerHit = $this->findSimpleHeaderRow($rows);
        if ($headerHit === null) {
            return $this->emptyResult(
                'Wymagane kolumny: sku (kod), nazwa, cena (katalogowa). Opcjonalnie: ean, kategoria, normy, rabat, zakup, stan, producent. '
                .'Dla cenników producentów użyj „Analizuj AI”.'
            );
        }
        [$headerIdx, $map] = $headerHit;

        $collected = $this->collectFromSimpleRows(
            array_slice($rows, $headerIdx + 1),
            $map,
            $defaultCategory,
            $manufacturer,
            $headerIdx + 2,
        );
        try {
            $collected = $this->applyGroupOptions($collected, $manufacturer, $groupOptions);
        } catch (\InvalidArgumentException $e) {
            return $this->emptyResult($e->getMessage());
        }

        return $this->persistImport(
            $file,
            $manufacturer,
            $version,
            $user,
            $collected,
        );
    }

    /**
     * @param  array{
     *     manufacturer_detected?: ?string,
     *     currency?: ?string,
     *     notes?: string,
     *     sheets: list<array<string, mixed>>
     * }  $mapping
     * @param  array<string, mixed>|null  $groupOptions
     * @return array{price_list: PriceList|null, created: int, updated: int, skipped: int, errors: list<string>}
     */
    public function importWithMapping(
        UploadedFile $file,
        string $manufacturer,
        string $version,
        User $user,
        array $mapping,
        ?string $defaultCategory = null,
        ?array $groupOptions = null,
    ): array {
        $path = $file->getRealPath();
        if ($path === false) {
            return $this->emptyResult('Nie można odczytać pliku.');
        }

        $collected = $this->collectFromMapping($path, $mapping, $defaultCategory, $manufacturer);
        try {
            $collected = $this->applyGroupOptions($collected, $manufacturer, $groupOptions);
        } catch (\InvalidArgumentException $e) {
            return $this->emptyResult($e->getMessage());
        }

        return $this->persistImport($file, $manufacturer, $version, $user, $collected);
    }

    /**
     * @param  list<array<string, mixed>>  $products
     * @param  array<string, mixed>|null  $groupOptions
     * @return array{price_list: PriceList|null, created: int, updated: int, skipped: int, errors: list<string>}
     */
    public function importFromProducts(
        UploadedFile $file,
        string $manufacturer,
        string $version,
        User $user,
        array $products,
        ?string $defaultCategory = null,
        ?array $groupOptions = null,
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

        $collapsed = $this->collapseSamePriceVariants($normalized);
        $normalized = $collapsed['products'];
        $skipped += $collapsed['removed'];

        $collected = [
            'products' => $normalized,
            'skipped' => $skipped,
            'errors' => $errors,
            'skipped_details' => $skippedDetails,
            'rows_total' => count($products),
        ];
        try {
            $collected = $this->applyGroupOptions($collected, $manufacturer, $groupOptions);
        } catch (\InvalidArgumentException $e) {
            return $this->emptyResult($e->getMessage());
        }

        return $this->persistImport($file, $manufacturer, $version, $user, $collected);
    }

    /**
     * @param  array{
     *     products: list<array<string, mixed>>,
     *     skipped: int,
     *     errors: list<string>,
     *     rows_total: int,
     *     skipped_details?: list<array<string, mixed>>
     * }  $collected
     * @param  array<string, mixed>|null  $groupOptions
     * @return array{
     *     products: list<array<string, mixed>>,
     *     skipped: int,
     *     errors: list<string>,
     *     rows_total: int,
     *     skipped_details?: list<array<string, mixed>>
     * }
     */
    private function applyGroupOptions(array $collected, string $manufacturer, ?array $groupOptions): array
    {
        if ($groupOptions === null || $groupOptions === []) {
            return $collected;
        }

        $collected['products'] = $this->assortmentGroups->applyToProducts(
            $collected['products'],
            $manufacturer,
            $groupOptions,
        );

        return $collected;
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
        $productIds = [];

        DB::transaction(function () use ($collected, &$created, &$updated, &$priceChanges, &$updatedProducts, &$productIds): void {
            $byManufacturer = [];
            foreach ($collected['products'] as $payload) {
                $sku = (string) $payload['sku'];
                unset($payload['sku'], $payload['_purchase_from_file']);
                $payload = $this->clampProductFields($payload);
                if (($payload['description'] ?? null) === null) {
                    unset($payload['description']);
                }
                $existing = $this->findExistingProduct($sku, $payload, $byManufacturer);
                if ($existing !== null) {
                    $change = $this->detectPriceChange($existing, $payload, $sku);
                    if ($change !== null) {
                        $priceChanges[] = $change;
                    }
                    $updatedProducts[] = $this->summarizeUpdate($existing, $payload, $sku, $change !== null);
                    $existing->update($payload);
                    $productIds[] = (int) $existing->id;
                    $updated++;
                } else {
                    $createdProduct = Product::query()->create(['sku' => $sku, ...$payload]);
                    $productIds[] = (int) $createdProduct->id;
                    $created++;
                }
            }
        });

        $productIds = array_values(array_unique($productIds));

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
            'product_ids' => $productIds,
        ]);

        if ($productIds !== []) {
            $products = Product::query()
                ->whereIn('id', $productIds)
                ->get(['id', 'catalog_price_net', 'purchase_price']);
            foreach ($products as $product) {
                ProductPriceHistory::query()->create([
                    'product_id' => $product->id,
                    'price_list_id' => $priceList->id,
                    'catalog_price_net' => $product->catalog_price_net,
                    'purchase_price' => $product->purchase_price,
                    'source' => 'price_list_import',
                ]);
            }

            foreach ($productIds as $productId) {
                ReindexProductEmbeddingJob::dispatch($productId);
            }
        }

        $specialCount = 0;
        $realPath = $file->getRealPath();
        if ($realPath !== false) {
            $specialCount = $this->specialPrices->importFromPath($realPath, $manufacturer);
        }

        RegisterManufacturerCatalogJob::dispatch($manufacturer, $productIds[0] ?? 0);

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
            'product_ids' => $productIds,
            'special_prices' => $specialCount,
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
     * @param  list<array<string, mixed>>  $details
     * @return list<array<string, mixed>>
     */
    private function finalizeSkippedDetails(
        array $details,
        int $emptySkips,
        int $headerSkips,
        int $skippedTotal,
    ): array {
        $out = $details;
        if ($emptySkips > 0) {
            array_unshift($out, [
                'reason' => 'Puste wiersze / sekcje bez ceny: '.$emptySkips,
                'row' => null,
                'sheet' => null,
                'sku' => null,
                'name' => null,
            ]);
        }
        if ($headerSkips > 0) {
            array_unshift($out, [
                'reason' => 'Pominięte wiersze nagłówków: '.$headerSkips,
                'row' => null,
                'sheet' => null,
                'sku' => null,
                'name' => null,
            ]);
        }
        unset($skippedTotal);

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
        int $firstDataExcelRow = 2,
    ): array {
        $products = [];
        $skipped = 0;
        $errors = [];
        $skippedDetails = [];
        $emptySkips = 0;
        $headerSkips = 0;

        $defaultCurrency = $this->currencyDetector->normalize(null, 'PLN');
        $carry = ['name' => null, 'category' => null, 'group' => null];
        foreach ($dataRows as $index => $row) {
            $excelRow = $firstDataExcelRow + $index;
            $parsed = $this->parseRow(
                $row,
                $map,
                $defaultCategory,
                $manufacturer,
                $excelRow,
                null,
                $defaultCurrency,
                $carry,
            );
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

        $collapsed = $this->collapseSamePriceVariants($products);
        $skipped += $collapsed['removed'];

        return [
            'products' => $collapsed['products'],
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
            $role = (string) ($sheetMap['role'] ?? 'catalog');
            if ($role === 'special' || $role === 'skip') {
                continue;
            }
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
            foreach (['sku', 'name', 'catalog_price', 'discount', 'purchase', 'ean', 'category', 'pack_qty', 'packaging', 'model_key', 'currency'] as $key) {
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
            $carry = ['name' => null, 'category' => null, 'group' => null];

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
                    $carry,
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

        $collapsed = $this->collapseSamePriceVariants(array_values($bySku));
        $skipped += $collapsed['removed'];

        return [
            'products' => $collapsed['products'],
            'skipped' => $skipped,
            'errors' => $errors,
            'rows_total' => $rowsTotal,
        ];
    }

    /**
     * Ten sam model (Reference / nazwa+rozmiar): jedna pozycja gdy cena identyczna;
     * różne ceny (rozmiar/wariant) → zostaw każdą pozycję.
     *
     * @param  list<array<string, mixed>>  $products
     * @return array{products: list<array<string, mixed>>, removed: int}
     */
    private function collapseSamePriceVariants(array $products): array
    {
        if ($products === []) {
            return ['products' => [], 'removed' => 0];
        }

        $groups = [];
        $order = [];
        foreach ($products as $index => $product) {
            $key = $this->collapseGroupKey($product);
            if (! isset($groups[$key])) {
                $groups[$key] = [];
                $order[] = $key;
            }
            $groups[$key][] = ['index' => $index, 'product' => $product];
        }

        $out = [];
        $removed = 0;
        foreach ($order as $key) {
            $items = $groups[$key];
            if (str_starts_with($key, 'sku:')) {
                foreach ($items as $item) {
                    $out[] = $this->finalizeProductCode($item['product'], null);
                }

                continue;
            }

            if (count($items) === 1) {
                $out[] = $this->finalizeProductCode($items[0]['product'], null);

                continue;
            }

            $priceKeys = [];
            foreach ($items as $item) {
                $priceKeys[$this->sizes->priceBucket(
                    $item['product']['catalog_price_net'] ?? 0,
                    $item['product']['purchase_price'] ?? 0,
                )] = true;
            }

            if (count($priceKeys) === 1) {
                $chosen = $this->pickVariantRepresentative(array_map(
                    static fn (array $item): array => $item['product'],
                    $items,
                ));
                // Kod = model (Reference), nie Article Number rozmiaru
                $out[] = $this->finalizeProductCode($chosen, null);
                $removed += count($items) - 1;

                continue;
            }

            foreach ($items as $item) {
                $pack = trim((string) ($item['product']['packaging'] ?? ''));
                if ($pack === '') {
                    $pack = (string) ($this->sizes->extractSize(
                        (string) ($item['product']['name'] ?? ''),
                        (string) ($item['product']['sku'] ?? ''),
                        null,
                    ) ?? '');
                }
                $out[] = $this->finalizeProductCode(
                    $item['product'],
                    $pack !== '' ? $pack : null,
                );
            }
        }

        return ['products' => $out, 'removed' => $removed];
    }

    /**
     * Kod w systemie = model (Reference). Article Number tylko gdy brak modelu.
     * Przy różnych cenach rozmiarów: model-ROZMIAR.
     *
     * @param  array<string, mixed>  $product
     * @return array<string, mixed>
     */
    private function finalizeProductCode(array $product, ?string $sizeSuffix): array
    {
        $model = trim((string) ($product['_model_key'] ?? $product['_size_core'] ?? ''));
        if ($model === '') {
            $model = (string) ($this->sizes->skuCore(
                (string) ($product['sku'] ?? ''),
                (string) ($product['name'] ?? ''),
            ) ?? '');
        }
        if ($model !== '') {
            $product['sku'] = $sizeSuffix !== null && $sizeSuffix !== ''
                ? $model.'-'.$sizeSuffix
                : $model;
        }

        return $this->stripInternalProductKeys($product);
    }

    /**
     * @param  array<string, mixed>  $product
     */
    private function collapseGroupKey(array $product): string
    {
        $modelKey = trim((string) ($product['_model_key'] ?? ''));
        $name = trim((string) ($product['name'] ?? ''));
        $nameKey = mb_strtolower($this->sizes->stripSizeFromName($name) ?: $name);
        if ($modelKey !== '') {
            return 'model:'.mb_strtolower($modelKey).'|'.$nameKey;
        }

        $sizeKey = $this->sizes->groupKey(
            (string) ($product['manufacturer'] ?? ''),
            $name,
            (string) ($product['sku'] ?? ''),
            isset($product['packaging']) ? (string) $product['packaging'] : null,
        );
        if ($sizeKey !== null) {
            return $sizeKey;
        }

        $packaging = (string) ($product['packaging'] ?? '');
        if ($nameKey !== '' && $this->isSizePackaging($packaging)) {
            return 'name:'.$nameKey;
        }

        return 'sku:'.(string) ($product['sku'] ?? uniqid('p', true));
    }

    /**
     * @param  list<array<string, mixed>>  $variants
     * @return array<string, mixed>
     */
    private function pickVariantRepresentative(array $variants): array
    {
        $best = $variants[0];
        $bestScore = -1;
        foreach ($variants as $variant) {
            $score = 0;
            $pack = strtoupper((string) ($variant['packaging'] ?? ''));
            if ($pack === 'M') {
                $score += 50;
            } elseif (in_array($pack, ['L', 'ONE SIZE', 'ONESIZE'], true)) {
                $score += 30;
            } elseif ($pack === 'S') {
                $score += 10;
            }
            if (trim((string) ($variant['description'] ?? '')) !== '') {
                $score += 20;
            }
            if (! $this->isDescriptionLike((string) ($variant['name'] ?? ''))) {
                $score += 5;
            }
            if ($score > $bestScore) {
                $bestScore = $score;
                $best = $variant;
            }
        }

        $core = $this->sizes->skuCore((string) ($best['sku'] ?? ''), (string) ($best['name'] ?? ''));
        $best['name'] = $this->sizes->stripSizeFromName((string) ($best['name'] ?? '')) ?: (string) ($best['name'] ?? '');
        $best['packaging'] = null;
        if ($core !== null) {
            $best['_size_core'] = $core;
        }

        return $best;
    }

    private function isSizePackaging(string $packaging): bool
    {
        $pack = strtoupper(trim($packaging));
        if ($pack === '') {
            return false;
        }

        return preg_match(
            '/^(XXS|XS|S|M|L|XL|XXL|XXXL|XXXXL|[2-6]XL|ONE\s*SIZE|ONESIZE|\d{1,2})$/',
            $pack
        ) === 1;
    }

    /**
     * @param  array<string, mixed>  $payload
     * @param  array<string, \Illuminate\Support\Collection<int, Product>>  $byManufacturer
     */
    private function findExistingProduct(string $sku, array $payload, array &$byManufacturer): ?Product
    {
        $hit = Product::query()->where('sku', $sku)->first();
        if ($hit !== null) {
            return $hit;
        }

        $mfr = trim((string) ($payload['manufacturer'] ?? ''));
        $key = $this->sizes->groupKey(
            $mfr,
            (string) ($payload['name'] ?? ''),
            $sku,
            isset($payload['packaging']) ? (string) $payload['packaging'] : null,
        );
        if ($key === null || $mfr === '') {
            return null;
        }

        $price = $this->sizes->priceBucket(
            $payload['catalog_price_net'] ?? 0,
            $payload['purchase_price'] ?? 0,
        );
        if (! isset($byManufacturer[$mfr])) {
            $byManufacturer[$mfr] = Product::query()->where('manufacturer', $mfr)->get();
        }
        foreach ($byManufacturer[$mfr] as $product) {
            $pk = $this->sizes->groupKey(
                (string) $product->manufacturer,
                (string) $product->name,
                (string) $product->sku,
                $product->packaging !== null ? (string) $product->packaging : null,
            );
            if ($pk === $key && $this->sizes->priceBucket($product->catalog_price_net, $product->purchase_price) === $price) {
                return $product;
            }
        }

        return null;
    }

    /**
     * @param  array<string, mixed>  $product
     * @return array<string, mixed>
     */
    private function stripInternalProductKeys(array $product): array
    {
        unset($product['_model_key'], $product['_size_core']);

        return $product;
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
     * @param  array{name: ?string, category: ?string, group: ?string}  $carry
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
        ?array &$carry = null,
    ): array {
        if ($carry === null) {
            $carry = ['name' => null, 'category' => null, 'group' => null];
        }

        $prefix = $sheetName !== null ? "[{$sheetName}] " : '';
        $sku = isset($map['sku']) ? $this->normalizeSku((string) ($row[$map['sku']] ?? '')) : '';
        $rawName = trim((string) ($row[$map['name']] ?? ''));
        $priceRaw = $row[$map['catalog_price']] ?? null;

        $groupKey = $this->resolveGroupKey($row, $map, $carry);
        if ($groupKey !== null && ($carry['group'] ?? null) !== null && $groupKey !== $carry['group']) {
            $carry['name'] = null;
            $carry['category'] = null;
        }
        if ($groupKey !== null) {
            $carry['group'] = $groupKey;
        }

        $description = null;
        $name = $rawName;
        if ($rawName !== '' && $this->isDescriptionLike($rawName)) {
            $description = $rawName;
            $name = ($carry['name'] ?? null) !== null
                ? (string) $carry['name']
                : $this->titleFromDescription($rawName);
            if (($carry['name'] ?? null) === null) {
                $carry['name'] = $name;
            }
        } elseif ($rawName === '' && ($carry['name'] ?? null) !== null) {
            $name = (string) $carry['name'];
        }

        if ($sku === '' && $name === '' && $rawName === '') {
            return ['status' => 'skip'];
        }

        // wiersze-sekcje / uwagi bez ceny (tytuł w kodzie albo w nazwie)
        if ($priceRaw === null || $priceRaw === '') {
            if ($rawName !== '' && ! $this->isDescriptionLike($rawName)) {
                $carry['name'] = $rawName;
            } elseif ($sku !== '' && $name === '') {
                $carry['category'] = $sku;
            }
            if (isset($map['category'])) {
                $cat = trim((string) ($row[$map['category']] ?? ''));
                if ($cat !== '') {
                    $carry['category'] = $cat;
                }
            }

            return ['status' => 'skip'];
        }

        if ($name === '') {
            return [
                'status' => 'error',
                'message' => "{$prefix}Wiersz {$excelRow}: brak nazwy/ceny — pominięto",
            ];
        }

        if ($sku === '') {
            $slugBase = ! $this->isDescriptionLike($name) ? $name : ($carry['name'] ?? $name);
            $slug = strtoupper(preg_replace('/[^A-Za-z0-9]+/', '-', $slugBase) ?? 'POZ');
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
        if ($discount > 0 && $discount <= 1) {
            $discount *= 100;
        }
        if ($discount > 90) {
            $discount = 0.0;
        }

        $purchaseFromFile = false;
        $purchase = isset($map['purchase'])
            ? $this->toFloat($row[$map['purchase']] ?? null)
            : null;
        if ($purchase !== null && $purchase > 0) {
            $purchaseFromFile = true;
            if ($catalog > 0) {
                $discount = round((1 - ($purchase / $catalog)) * 100, 2);
                $discount = max(0.0, min(100.0, $discount));
            }
        }

        if ($purchase === null) {
            $purchase = round($catalog * (1 - ($discount / 100)), 2);
        }

        $category = $defaultCategory;
        if (isset($map['category'])) {
            $cat = trim((string) ($row[$map['category']] ?? ''));
            if ($cat !== '') {
                $category = $cat;
                $carry['category'] = $cat;
            } elseif (
                $groupKey !== null
                && ($carry['category'] ?? null) !== null
                && ($category === null || $category === '')
            ) {
                // tylko w układach grupowanych (Reference/model w kolumnie obok)
                $category = $carry['category'];
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
            $packaging = $this->normalizeSku((string) ($row[$map['packaging']] ?? '')) ?: null;
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

        if ($rawName !== '' && ! $this->isDescriptionLike($rawName)) {
            $carry['name'] = $rawName;
        } elseif (($carry['name'] ?? null) === null && $name !== '') {
            $carry['name'] = $name;
        }

        return [
            'status' => 'ok',
            'product' => [
                'sku' => $sku,
                'name' => $name,
                'manufacturer' => $manufacturer,
                'ean' => isset($map['ean']) ? trim((string) ($row[$map['ean']] ?? '')) ?: null : null,
                'category' => $category,
                'description' => $description,
                'norms' => null,
                'catalog_price_net' => $catalog,
                'discount_percent' => $discount,
                'purchase_price' => $purchase,
                'currency' => $currency,
                'stock' => 0,
                'pack_qty' => $packQty,
                'packaging' => $packaging,
                '_model_key' => $groupKey ?? ($carry['group'] ?? null),
                '_purchase_from_file' => $purchaseFromFile,
            ],
        ];
    }

    private function normalizeSku(string $value): string
    {
        $sku = trim($value);
        if ($sku === '' || strtoupper($sku) === '#N/A') {
            return '';
        }
        // DuPont i podobne: „D13495380*” / „M*” — gwiazdka = made-to-order
        $sku = rtrim($sku, " \t*");

        return trim($sku);
    }

    /**
     * @param  array<int, mixed>  $row
     * @param  array<string, int>  $map
     * @param  array{name: ?string, category: ?string, group: ?string}  $carry
     */
    private function resolveGroupKey(array $row, array $map, array $carry): ?string
    {
        if (isset($map['model_key'])) {
            $mapped = $this->normalizeSku((string) ($row[$map['model_key']] ?? ''));
            if ($mapped !== '') {
                return $mapped;
            }
        }

        // np. DuPont: Reference (model_key) tylko w 1. wierszu, a kod modelu jest też w kol. A
        $inferred = $this->rowGroupKey($row, $map);
        if ($inferred !== null) {
            return $inferred;
        }

        if (isset($map['model_key'])
            && isset($carry['group'])
            && is_string($carry['group'])
            && $carry['group'] !== ''
        ) {
            return $carry['group'];
        }

        return null;
    }

    /**
     * Klucz grupy modelu (np. Reference w kol. A) — resetuje przenoszenie nazwy przy nowym modelu.
     *
     * @param  array<int, mixed>  $row
     * @param  array<string, int>  $map
     */
    private function rowGroupKey(array $row, array $map): ?string
    {
        $used = array_flip(array_values($map));
        $max = min(4, count($row));
        for ($i = 0; $i < $max; $i++) {
            if (isset($used[$i])) {
                continue;
            }
            $value = $this->normalizeSku((string) ($row[$i] ?? ''));
            if ($value !== '' && $this->looksLikeModelCode($value)) {
                return $value;
            }
        }

        return null;
    }

    private function looksLikeModelCode(string $value): bool
    {
        if (mb_strlen($value) < 4 || mb_strlen($value) > 48) {
            return false;
        }
        if (str_contains($value, ' ')) {
            // kody typu „TD 0125 S WH 00” / „TK GEVJ T YL 00”
            if (preg_match('/^[A-Z0-9][A-Z0-9 .\-\/]{3,}$/i', $value) !== 1) {
                return false;
            }
            $words = preg_split('/\s+/', $value) ?: [];
            if (count($words) > 8) {
                return false;
            }
        }

        return preg_match('/[A-Za-z]/', $value) === 1 && preg_match('/\d/', $value) === 1;
    }

    private function isDescriptionLike(string $text): bool
    {
        $text = trim(preg_replace('/\s+/u', ' ', $text) ?? $text);
        $len = mb_strlen($text);
        if ($len >= 120) {
            return true;
        }
        $words = preg_split('/\s+/u', $text, -1, PREG_SPLIT_NO_EMPTY);
        $wordCount = is_array($words) ? count($words) : 0;
        if ($len >= 70 && $wordCount >= 14) {
            return true;
        }
        if ($wordCount >= 20) {
            return true;
        }
        $sentences = preg_match_all('/[.!?]/u', $text);

        return $len >= 60 && $sentences >= 2 && $wordCount >= 10;
    }

    private function titleFromDescription(string $description): string
    {
        $text = trim(preg_replace('/\s+/u', ' ', $description) ?? $description);
        if (preg_match('/^(.{12,90}?)[.!?]/u', $text, $m) === 1) {
            return trim($m[1]);
        }

        return mb_substr($text, 0, 80);
    }

    /**
     * @param  list<string>  $header
     * @return array<string, int>
     */
    private function resolveColumns(array $header): array
    {
        $mapped = $this->columnMapper->mapLabels($header);
        $map = [];
        foreach ($mapped as $key => $idx) {
            if ($idx !== null) {
                $map[$key] = $idx;
            }
        }

        return $map;
    }

    /**
     * @param  list<array<int, mixed>>  $rows
     * @return array{0: int, 1: array<string, int>}|null
     */
    private function findSimpleHeaderRow(array $rows): ?array
    {
        $limit = min(25, count($rows));
        $best = null;
        $bestScore = 0;

        for ($i = 0; $i < $limit; $i++) {
            $header = array_map(
                static fn ($v) => mb_strtolower(trim((string) $v)),
                $rows[$i] ?? []
            );
            $map = $this->resolveColumns($header);
            if (! isset($map['sku'], $map['name'], $map['catalog_price'])) {
                continue;
            }

            $dataHits = 0;
            $scanTo = min($i + 12, count($rows) - 1);
            for ($j = $i + 1; $j <= $scanTo; $j++) {
                $name = trim((string) ($rows[$j][$map['name']] ?? ''));
                $price = $this->toFloat($rows[$j][$map['catalog_price']] ?? null);
                if ($name !== '' && $price !== null) {
                    $dataHits++;
                }
            }
            if ($dataHits === 0) {
                continue;
            }

            $score = $dataHits;
            if (isset($map['discount'])) {
                $score++;
            }
            if (isset($map['purchase'])) {
                $score++;
            }
            if ($score > $bestScore) {
                $bestScore = $score;
                $best = [$i, $map];
            }
        }

        return $best;
    }

    private function looksLikeDateString(string $value): bool
    {
        $value = trim($value);

        return preg_match('/^\d{1,2}[.\/-]\d{1,2}[.\/-]\d{2,4}$/', $value) === 1
            || preg_match('/^\d{4}[.\/-]\d{1,2}[.\/-]\d{1,2}$/', $value) === 1;
    }

    private function toFloat(mixed $value): ?float
    {
        if ($value === null || $value === '') {
            return null;
        }
        if (is_int($value) || is_float($value)) {
            return (float) $value;
        }
        if (is_numeric($value)) {
            return (float) $value;
        }

        $s = trim((string) $value);
        if ($this->looksLikeDateString($s)) {
            return null;
        }
        $s = str_replace(["\xc2\xa0", "\xe2\x80\xaf"], ' ', $s);
        $s = preg_replace('/\s+/u', '', $s) ?? $s;
        $s = mb_strtolower($s);
        $s = str_replace(
            ['zł.', 'zł', 'zl', 'pln', '€', 'eur', 'usd', 'gbp', '%', '$', '£'],
            '',
            $s
        );
        $s = $this->normalizeDecimalString($s);
        if ($s === '' || $s === '-' || $s === '.') {
            return null;
        }
        if (! is_numeric($s)) {
            $s = preg_replace('/[^\d.\-]/u', '', $s) ?? '';
            if ($s === '' || $s === '-' || ! is_numeric($s)) {
                return null;
            }
        }

        return (float) $s;
    }

    private function normalizeDecimalString(string $s): string
    {
        $hasComma = str_contains($s, ',');
        $hasDot = str_contains($s, '.');
        if ($hasComma && $hasDot) {
            $lastComma = (int) strrpos($s, ',');
            $lastDot = (int) strrpos($s, '.');
            if ($lastDot > $lastComma) {
                return str_replace(',', '', $s);
            }

            return str_replace(',', '.', str_replace('.', '', $s));
        }
        if ($hasComma) {
            return str_replace(',', '.', $s);
        }

        return $s;
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

        $limits = [
            'category' => 255,
            'manufacturer' => 100,
            'packaging' => 120,
            'ean' => 32,
            'currency' => 8,
        ];
        foreach ($limits as $field => $max) {
            $value = $payload[$field] ?? null;
            if (! is_string($value) || $value === '') {
                continue;
            }
            $value = trim($value);
            if (mb_strlen($value) > $max) {
                $payload[$field] = mb_substr($value, 0, $max);
            }
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
            'product_ids' => [],
            'special_prices' => 0,
        ];
    }
}
