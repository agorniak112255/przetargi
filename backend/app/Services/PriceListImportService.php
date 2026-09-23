<?php

declare(strict_types=1);

namespace App\Services;

use App\Jobs\RegisterManufacturerCatalogJob;
use App\Jobs\ReindexProductEmbeddingJob;
use App\Models\B2bProductLink;
use App\Models\PrestaCategory;
use App\Models\PriceList;
use App\Models\PriceListImport;
use App\Models\Product;
use App\Models\ProductIdentifier;
use App\Models\ProductPriceHistory;
use App\Models\ProductSourcePrice;
use App\Models\User;
use App\Services\Catalog\CardOwnership;
use App\Services\Catalog\ProductIdentifierStore;
use App\Services\Presta\PrestaCategoryRewriteService;
use App\Services\Presta\ProductCategorySanitizer;
use App\Services\Pricing\ProductEffectivePrice;
use App\Support\CanonicalBrand;
use App\Support\ProductSizeVariant;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use PhpOffice\PhpSpreadsheet\Cell\Coordinate;
use PhpOffice\PhpSpreadsheet\IOFactory;
use Throwable;

final class PriceListImportService
{
    /** Pola karty porównywane w podsumowaniu aktualizacji (summarizeUpdate); inne pola dopisuje wołający. */
    public const SUMMARY_TEXT_FIELDS = ['name', 'manufacturer', 'category', 'currency', 'packaging'];

    public const SUMMARY_NUMBER_FIELDS = ['catalog_price_net', 'purchase_price', 'discount_percent', 'pack_qty'];

    public function __construct(
        private readonly CurrencyDetector $currencyDetector,
        private readonly AssortmentGroupService $assortmentGroups,
        private readonly ProductSizeVariant $sizes,
        private readonly SpreadsheetColumnMapper $columnMapper,
        private readonly ProductSpecialPriceImporter $specialPrices,
        private readonly SpreadsheetCellReader $cells,
        private readonly ProductCategorySanitizer $categorySanitizer,
        private readonly ProductSizeMergeService $sizeMerge,
        private readonly ProductEffectivePrice $effectivePrices,
        private readonly PrestaCategoryRewriteService $prestaCategories,
        private readonly ProductIdentifierStore $identifiers = new ProductIdentifierStore,
        private readonly CardOwnership $ownership = new CardOwnership,
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

        $rows = $this->cells->toRows(IOFactory::load($path)->getActiveSheet());
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
            $rows[$headerIdx] ?? [],
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
        if (! $this->isSpreadsheetUpload($file)) {
            return $this->emptyResult('PDF: użyj „Importuj wg AI” z listy po analizie, nie mapowania arkusza.');
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
                'category' => $this->cleanCategory(
                    isset($row['category']) && is_string($row['category']) && $row['category'] !== ''
                        ? $row['category']
                        : $defaultCategory,
                    $name
                ),
                'category_evidence' => $this->categoryEvidence(
                    isset($row['category']) && is_string($row['category']) && $row['category'] !== ''
                        ? $row['category']
                        : $defaultCategory,
                ),
                'norms' => null,
                // parametry wyrobu wypisane w samym cenniku — dokument producenta z datą obowiązywania
                'price_list_attributes' => is_array($row['price_list_attributes'] ?? null) && $row['price_list_attributes'] !== []
                    ? $row['price_list_attributes']
                    : null,
                'catalog_price_net' => (float) $price,
                'discount_percent' => $discount,
                'purchase_price' => $purchase,
                'currency' => $currency,
                'stock' => 0,
                'pack_qty' => is_numeric($packQty) ? max(0, (int) $packQty) : null,
                'packaging' => $packaging !== '' ? $packaging : null,
                // pozycje odczytane z PDF nie mają nagłówków kolumn — pole = rola (sku, ean)
                '_identifiers' => $this->rowIdentifiers(
                    [$sku, trim((string) ($row['ean'] ?? '')), $packaging],
                    ['sku' => 0, 'ean' => 1, 'packaging' => 2],
                ),
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
     * Nazwy, które import zapisałby z tego arkusza (po scaleniu rozmiarów i przycięciu pól), bez
     * zapisu do bazy — dla naprawy nazw zapisanych wcześniej z sąsiedniego wiersza cennika.
     *
     * @param  array{sheets: list<array<string, mixed>>}  $mapping
     * @return array<string, string> SKU → nazwa
     */
    public function productNamesFromMapping(string $path, array $mapping, string $manufacturer): array
    {
        return array_map(
            static fn (array $row): string => $row['name'],
            $this->productRowsFromMapping($path, $mapping, $manufacturer)
        );
    }

    /**
     * @param  array{sheets: list<array<string, mixed>>}  $mapping
     * @return array<string, array{name: string, packaging: ?string}> SKU → nazwa i opakowanie z cennika
     */
    public function productRowsFromMapping(string $path, array $mapping, string $manufacturer): array
    {
        $rows = [];
        foreach ($this->collectFromMapping($path, $mapping, null, $manufacturer)['products'] as $product) {
            $sku = (string) ($product['sku'] ?? '');
            unset($product['sku'], $product['_purchase_from_file']);
            $clamped = $this->clampProductFields($product);
            $name = trim((string) ($clamped['name'] ?? ''));
            $packaging = trim((string) ($clamped['packaging'] ?? ''));
            if ($sku !== '' && $name !== '') {
                $rows[$sku] = ['name' => $name, 'packaging' => $packaging !== '' ? $packaging : null];
            }
        }

        return $rows;
    }

    /**
     * @param  array{sheets: list<array<string, mixed>>}  $mapping
     * @return array{
     *     items: list<array<string, mixed>>,
     *     products_found: int,
     *     rows_total: int,
     *     skipped: int,
     *     errors_count: int,
     *     errors: list<string>,
     *     sheets: list<array<string, mixed>>
     * }
     */
    public function previewFromMapping(string $path, array $mapping, int $limit = 8): array
    {
        $collected = $this->collectFromMapping($path, $mapping, null, 'PREVIEW', true);
        // identyfikatory wierszy są dla zapisu importu, nie dla okna podglądu
        $collected['products'] = array_map(static function (array $product): array {
            unset($product['_identifiers']);

            return $product;
        }, $collected['products']);
        $items = array_slice($collected['products'], 0, $limit);

        return [
            'items' => $items,
            'products' => $collected['products'],
            'products_found' => count($collected['products']),
            'rows_total' => $collected['rows_total'],
            'skipped' => $collected['skipped'],
            'errors_count' => count($collected['errors']),
            'errors' => array_slice($collected['errors'], 0, 20),
            // mapowanie po korektach, czyli to, według którego import naprawdę czyta plik
            'sheets' => $collected['sheets'],
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
        $collected['products'] = $this->categoriesFromTree($collected['products']);
        $created = 0;
        $updated = 0;
        $priceChanges = [];
        $updatedProducts = [];
        $productIds = [];
        $skippedDetails = [];
        $importRow = null;

        // Wpis cennika powstaje na początku tej samej transakcji — sloty ceny pliku wskazują cennik, z którego
        // pochodzą (usunięcie cennika usuwa tylko jego slot). Liczniki uzupełniane na końcu; import dalej atomowy.
        /** @var PriceList $priceList */
        $priceList = DB::transaction(function () use ($file, $manufacturer, $version, $user, &$collected, &$created, &$updated, &$priceChanges, &$updatedProducts, &$productIds, &$skippedDetails, &$importRow): PriceList {
            // Jeden wpis na producenta: kolejna aktualizacja odnajduje swój cennik zamiast zakładać
            // następny. Pola opisują ostatnią aktualizację, historia idzie do price_list_imports.
            $priceList = PriceList::query()
                ->where('manufacturer_key', PriceList::manufacturerKey($manufacturer))
                ->first();
            $attributes = [
                'manufacturer' => $manufacturer,
                'manufacturer_key' => PriceList::manufacturerKey($manufacturer),
                'version' => $version,
                'original_filename' => $file->getClientOriginalName(),
                'imported_by' => $user->id,
                'rows_total' => $collected['rows_total'],
            ];
            if ($priceList === null) {
                $priceList = PriceList::query()->create($attributes);
            } else {
                $priceList->update($attributes);
            }

            $byManufacturer = [];
            $fileSlots = [];
            $historyIds = [];
            /** @var array<int, list<array<string, mixed>>> $identifierRows id karty => identyfikatory jej wierszy */
            $identifierRows = [];
            foreach ($collected['products'] as $payload) {
                $sku = (string) $payload['sku'];
                $rowIdentifiers = is_array($payload['_identifiers'] ?? null) ? $payload['_identifiers'] : [];
                unset($payload['sku'], $payload['_purchase_from_file'], $payload['_identifiers']);
                $payload = $this->clampProductFields($payload);
                if (($payload['description'] ?? null) === null) {
                    unset($payload['description']);
                }
                // cennik bez kolumny modelu nie kasuje nazwy modelu z poprzedniego importu
                if (($payload['model_name'] ?? null) === null) {
                    unset($payload['model_name']);
                }
                // ani EAN: cennik bez kolumny EAN albo z pustą komórką nie mówi, że wyrób kod stracił
                if (($payload['ean'] ?? null) === null) {
                    unset($payload['ean']);
                }
                $existing = $this->findExistingProduct($sku, $payload, $byManufacturer, $fileSlots);
                // sku jest UNIQUE — kod karty innego producenta: pozycja pominięta, bez drugiej karty i bez nadpisania
                if ($existing !== null && $this->foreignManufacturer($existing, (string) ($payload['manufacturer'] ?? ''))) {
                    $reason = 'kod należy do karty producenta '.$existing->manufacturer;
                    $collected['skipped']++;
                    $collected['errors'][] = $sku.': '.$reason;
                    if (is_array($collected['skipped_details'] ?? null)) {
                        $collected['skipped_details'][] = [
                            'reason' => $reason,
                            'row' => null,
                            'sheet' => null,
                            'sku' => $sku,
                            'name' => isset($payload['name']) ? (string) $payload['name'] : null,
                        ];
                    }

                    continue;
                }

                $slotValues = [
                    'catalog_price_net' => $payload['catalog_price_net'] ?? null,
                    'purchase_price' => $payload['purchase_price'] ?? null,
                    'discount_percent' => $payload['discount_percent'] ?? null,
                    'currency' => $payload['currency'] ?? null,
                    'pack_qty' => $payload['pack_qty'] ?? null,
                    'price_list_id' => $priceList->id,
                ];

                if ($existing !== null) {
                    // cena z pliku trafia tylko do slotu „file”; raporty porównują z poprzednią ceną z pliku. Karta bez
                    // slotu pliku z ceną z konta B2B (także w innej walucie) — dodanie ceny źródła, nie zmiana
                    // (previousSourcePrices).
                    $fileSlot = $this->fileSlot($existing, $fileSlots);
                    $before = $this->effectivePrices->previousSourcePrices($existing, $fileSlot, $slotValues);
                    $cardPayload = $payload;
                    // karta z powiązaniem B2B: nazwa i producent zostają na karcie (decyzja użytkownika 15.09.2026) —
                    // poza cennikiem producenta marki karty, gdy powiązania są tylko od dystrybutorów (producerFileOwnsCard)
                    if (B2bProductLink::query()->where('product_id', $existing->id)->exists()
                        && ! $this->producerFileOwnsCard($existing, $manufacturer)) {
                        unset($cardPayload['name'], $cardPayload['manufacturer']);
                    }
                    // kategoria wybrana ręcznie w panelu zostaje — cennik ani drzewo sklepu jej po cichu nie nadpisują
                    if ($existing->category_source === Product::CATEGORY_SOURCE_MANUAL) {
                        unset($cardPayload['category'], $cardPayload['category_source']);
                    }
                    $change = $this->detectPriceChange($before, $cardPayload, $sku);
                    if ($change !== null) {
                        $priceChanges[] = $change;
                    }
                    // pierwsza cena z pliku to punkt odniesienia dla kolejnych zmian tego źródła w historii
                    if ($change !== null || $fileSlot === null) {
                        $historyIds[(int) $existing->id] = true;
                    }
                    $updatedProducts[] = $this->summarizeUpdate($before, $cardPayload, $sku, $change !== null);
                    $updates = array_diff_key($cardPayload, array_flip(ProductEffectivePrice::PRICE_FIELDS));
                    // producent zgodny (sprawdzone wyżej), a nowy kod nie jest zajęty przez inną kartę
                    if ($sku !== (string) $existing->sku) {
                        $taken = Product::query()
                            ->where('sku', $sku)
                            ->where('id', '!=', $existing->id)
                            ->exists();
                        if (! $taken) {
                            $updates['sku'] = $sku;
                        }
                    }
                    $existing->update($updates);
                    $saved = $this->effectivePrices->saveSlot($existing, ProductSourcePrice::SOURCE_FILE, $slotValues);
                    $fileSlots[(int) $existing->id] = $saved['slot'];
                    $productIds[] = (int) $existing->id;
                    $identifierRows[(int) $existing->id] = [...($identifierRows[(int) $existing->id] ?? []), ...$rowIdentifiers];
                    $updated++;
                } else {
                    // nowa karta: cena z pliku jest też startową ceną obowiązującą
                    $createdProduct = Product::query()->create(['sku' => $sku, ...$payload]);
                    $saved = $this->effectivePrices->saveSlot($createdProduct, ProductSourcePrice::SOURCE_FILE, $slotValues);
                    $fileSlots[(int) $createdProduct->id] = $saved['slot'];
                    $historyIds[(int) $createdProduct->id] = true;
                    $productIds[] = (int) $createdProduct->id;
                    $identifierRows[(int) $createdProduct->id] = $rowIdentifiers;
                    $created++;
                }
            }

            $productIds = array_values(array_unique($productIds));

            // największe zmiany % najpierw
            usort($priceChanges, static fn (array $a, array $b): int => abs($b['catalog_pct']) <=> abs($a['catalog_pct']));

            $skippedDetails = is_array($collected['skipped_details'] ?? null)
                ? array_slice($collected['skipped_details'], 0, 100)
                : $this->skippedDetailsFromErrors($collected['errors'] ?? [], (int) $collected['skipped']);

            $priceList->update([
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

            // Raport tego przebiegu: zakres kart z niego potrzebny jest do cofnięcia samej aktualizacji,
            // bez kasowania całego katalogu producenta.
            $import = $importRow = PriceListImport::query()->create([
                'price_list_id' => $priceList->id,
                'source' => PriceListImport::SOURCE_FILE,
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

            // kody i EAN-y każdego wiersza pliku (także zwiniętych rozmiarów) z pochodzeniem — do łączenia kart źródeł
            $this->identifiers->recordFile($priceList, $import, $identifierRows);

            // historia: ceny slotu pliku (nie ceny obowiązującej karty), tylko nowa karta albo zmiana ceny z pliku
            foreach (array_keys($historyIds) as $productId) {
                $slot = $fileSlots[$productId] ?? null;
                if ($slot === null) {
                    continue;
                }
                ProductPriceHistory::query()->create([
                    'product_id' => $productId,
                    'price_list_id' => $priceList->id,
                    'price_list_import_id' => $import->id,
                    'catalog_price_net' => $slot->catalog_price_net,
                    'purchase_price' => $slot->purchase_price,
                    'currency' => $slot->currency,
                    'source' => 'price_list_import',
                ]);
            }

            return $priceList;
        });

        if ($productIds !== []) {
            foreach ($productIds as $productId) {
                ReindexProductEmbeddingJob::dispatch($productId);
            }
        }

        $specialCount = 0;
        $realPath = $file->getRealPath();
        if ($realPath !== false && $this->isSpreadsheetUpload($file)) {
            try {
                $specialCount = $this->specialPrices->importFromPath($realPath, $manufacturer);
            } catch (Throwable) {
                $specialCount = 0;
            }
        }

        RegisterManufacturerCatalogJob::dispatch($manufacturer, $productIds[0] ?? 0);

        try {
            $this->sizeMerge->merge($manufacturer, false);
        } catch (Throwable) {
            // import już zapisany — scalanie rozmiarów nie cofa cennika
        }

        return [
            'price_list' => $priceList->load('importer:id,name'),
            'price_list_import' => $importRow,
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
    public function summarizeUpdate(Product $existing, array $payload, string $sku, bool $priceChanged): array
    {
        $fields = [];
        foreach (self::SUMMARY_TEXT_FIELDS as $field) {
            $old = (string) ($existing->{$field} ?? '');
            $new = (string) ($payload[$field] ?? $old);
            if ($old !== $new) {
                $fields[] = $field;
            }
        }
        foreach (self::SUMMARY_NUMBER_FIELDS as $field) {
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
    public function detectPriceChange(Product $existing, array $payload, string $sku): ?array
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
        array $header = [],
    ): array {
        $products = [];
        $skipped = 0;
        $errors = [];
        $skippedDetails = [];
        $emptySkips = 0;
        $headerSkips = 0;

        $defaultCurrency = $this->currencyDetector->normalize(null, 'PLN');
        $carry = ['name' => null, 'category' => null, 'group' => null, 'name_group' => null];
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
            $products[] = [...$parsed['product'], '_identifiers' => $this->rowIdentifiers($row, $map, $header)];
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
     * @return array{
     *     products: list<array<string, mixed>>,
     *     skipped: int,
     *     errors: list<string>,
     *     rows_total: int,
     *     sheets: list<array{sheet: string, header_excel_row: int, columns: array<string, int>, available_columns: list<array{index: int, label: string, sample: string}>}>
     * }
     */
    private function collectFromMapping(
        string $path,
        array $mapping,
        ?string $defaultCategory,
        string $manufacturer,
        bool $describeColumns = false,
    ): array {
        $spreadsheet = IOFactory::load($path);
        $bySku = [];
        $skipped = 0;
        $errors = [];
        $rowsTotal = 0;
        $sheetDetails = [];

        foreach ($mapping['sheets'] as $sheetMap) {
            $role = (string) ($sheetMap['role'] ?? 'catalog');
            if ($role === 'special' || $role === 'skip') {
                continue;
            }
            if (! ($sheetMap['include'] ?? false)) {
                continue;
            }
            $sheetName = (string) ($sheetMap['sheet'] ?? '');
            if ($this->columnMapper->classifySheet($sheetName) === 'skip') {
                continue;
            }
            $sheet = $spreadsheet->getSheetByName($sheetName);
            if ($sheet === null) {
                $errors[] = "Brak arkusza: {$sheetName}";

                continue;
            }

            $cols = is_array($sheetMap['columns'] ?? null) ? $sheetMap['columns'] : [];
            $map = [];
            $mappable = array_merge(
                ['sku', 'sku_alt', 'name', 'name_extra', 'catalog_price', 'discount', 'purchase', 'ean', 'category', 'pack_qty', 'packaging', 'model_key', 'model_name', 'currency'],
                // kolumny z parametrem wyrobu przechodzą tak samo jak reszta mapowania
                SpreadsheetColumnMapper::attributeFields(),
            );
            foreach ($mappable as $key) {
                if (isset($cols[$key]) && is_numeric($cols[$key])) {
                    $map[$key] = (int) $cols[$key];
                }
            }
            // Role, które człowiek ustawił w oknie importu. Poniższe korekty ratują mapowanie zgadnięte przez
            // maszynę, ale na ręcznym mapowaniu robiłyby dokładnie to, przed czym to okno chroni: cofałyby wybór
            // bez śladu. Pole odznaczone świadomie (rola bez kolumny) też jest wyborem i nie wraca automatem.
            $locked = [];
            foreach ((array) ($sheetMap['locked_columns'] ?? []) as $role) {
                if (is_string($role) && $role !== '') {
                    $locked[$role] = true;
                }
            }
            // nazwa złożona z dwóch kolumn to zawsze decyzja człowieka — korekta nazwy nie ma tu czego ratować
            if (isset($map['name_extra'])) {
                $locked['name'] = true;
            }
            if (! isset($map['name'], $map['catalog_price'])) {
                $errors[] = "Arkusz {$sheetName}: niepełne mapowanie kolumn (wymagane: nazwa + cena)";

                continue;
            }

            // Mapowanie z analizy potrafi wskazać na nazwę kolumnę rodzaju wyrobu — w cenniku ARTRY „typ”,
            // przez co wszystkie karty nazywałyby się „półbuty”. Te same statystyki kolumn, które poprawiają
            // mapowanie wykrywane samodzielnie, stosujemy do mapowania przyszłego z zewnątrz.
            $headerExcelForFix = max(1, (int) ($sheetMap['header_excel_row'] ?? $sheetMap['header_row'] ?? 1));
            $maxColForFix = min(28, Coordinate::columnIndexFromString($sheet->getHighestDataColumn() ?: 'A'));
            if (! isset($locked['category'])) {
                $map = $this->columnMapper->correctCategoryColumn($sheet, $headerExcelForFix, $maxColForFix, $map);
            }
            if (! isset($locked['name'])) {
                $map = array_filter(
                    $this->columnMapper->correctNameColumn($sheet, $headerExcelForFix, $maxColForFix, $map),
                    static fn ($idx): bool => $idx !== null,
                );
            }
            // Analiza zwraca tylko kolumny cennikowe, więc klasa ochrony, rodzaj wyrobu i rozmiar
            // nie trafiały nigdzie. Rozpoznajemy je z nagłówka sami, po korekcie nazwy i kategorii,
            // żeby nie sięgnąć po kolumnę, którą właśnie zajęła nazwa.
            foreach ($this->columnMapper->attributeColumnsFor($sheet, $headerExcelForFix, $maxColForFix, $map) as $field => $idx) {
                if (isset($locked[$field])) {
                    continue;
                }
                $map[$field] ??= $idx;
            }

            $sheetDetails[] = [
                'sheet' => $sheetName,
                'header_excel_row' => $headerExcelForFix,
                'columns' => $map,
                'available_columns' => $describeColumns
                    ? $this->columnMapper->describeColumns($sheet, $headerExcelForFix, $maxColForFix)
                    : [],
            ];

            $headerExcelRow = max(1, (int) ($sheetMap['header_excel_row'] ?? $sheetMap['header_row'] ?? 1));
            $headerIdx = $headerExcelRow - 1;
            $repeating = (bool) ($sheetMap['repeating_headers'] ?? false);
            $all = $this->cells->toRows($sheet);
            $headerLabels = $this->headerLabels($all, $headerIdx, $map);
            $fromPriceColumn = isset($map['catalog_price'])
                ? $this->currencyDetector->detectFromColumnStack(
                    $this->columnHeaderStack($all, $headerIdx, $map['catalog_price'])
                )
                : null;
            $sheetDefaultCurrency = $fromPriceColumn
                ?? $this->currencyDetector->normalize(
                    is_string($mapping['currency'] ?? null) ? $mapping['currency'] : null,
                    $this->currencyDetector->detect(implode(' ', $headerLabels)) ?? 'PLN'
                );
            $dataRows = array_slice($all, $headerIdx + 1);
            $rowsTotal += count($dataRows);
            $carry = ['name' => null, 'category' => null, 'group' => null, 'name_group' => null];

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
                $product = $parsed['product'];
                // kod powtórzony w pliku — wiersz zastępuje poprzedni, ale kody i EAN-y obu zostają
                $product['_identifiers'] = [
                    ...($bySku[$sku]['_identifiers'] ?? []),
                    ...$this->rowIdentifiers($row, $map, $all[$headerIdx] ?? []),
                ];
                $bySku[$sku] = $product;
            }
        }

        $collapsed = $this->collapseSamePriceVariants(array_values($bySku));
        $skipped += $collapsed['removed'];

        return [
            'products' => $collapsed['products'],
            'skipped' => $skipped,
            'errors' => $errors,
            'rows_total' => $rowsTotal,
            'sheets' => $sheetDetails,
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

        $knownStems = [];
        foreach ($products as $product) {
            $stem = $this->sizes->skuTailStem((string) ($product['sku'] ?? ''));
            if ($stem !== null) {
                $knownStems[mb_strtolower($stem)] = $stem;
            }
        }

        $groups = [];
        $order = [];
        foreach ($products as $index => $product) {
            $key = $this->collapseGroupKey($product, $knownStems);
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
            if (str_starts_with($key, 'unique:')) {
                foreach ($items as $item) {
                    $out[] = $this->finalizeProductCode($item['product'], null);
                }

                continue;
            }
            if (str_starts_with($key, 'stem:') && count($items) > 1) {
                $hasBaseSku = false;
                foreach ($items as $item) {
                    $sku = (string) ($item['product']['sku'] ?? '');
                    if (isset($knownStems[mb_strtolower($sku)])) {
                        $hasBaseSku = true;
                        break;
                    }
                }
                $names = array_map(
                    static fn (array $item): string => (string) ($item['product']['name'] ?? ''),
                    $items,
                );
                if (! $hasBaseSku && ! $this->sizes->namesCompatibleForMerge($names)) {
                    foreach ($items as $item) {
                        $out[] = $this->finalizeProductCode($item['product'], null);
                    }

                    continue;
                }
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
                $model = trim((string) ($chosen['_model_key'] ?? $chosen['_size_core'] ?? ''));
                if ($model !== '' && trim((string) ($chosen['_size_core'] ?? '')) === '') {
                    $chosen['_size_core'] = $model;
                }
                // kody i EAN-y wszystkich zwiniętych rozmiarów zostają przy karcie (product_identifiers)
                $chosen['_identifiers'] = array_merge(...array_map(
                    static fn (array $item): array => $item['product']['_identifiers'] ?? [],
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
            $sizeOk = $sizeSuffix !== null && $sizeSuffix !== '' && $this->isSizePackaging($sizeSuffix);
            if ($sizeOk) {
                $product['sku'] = $model.'-'.$sizeSuffix;
            } elseif ($sizeSuffix === null || $sizeSuffix === '') {
                $product['sku'] = $model;
            }
        }

        return $this->stripInternalProductKeys($this->withStrippedSizeLabel($product));
    }

    /**
     * @param  array<string, mixed>  $product
     * @param  array<string, string>  $knownStems
     */
    private function collapseGroupKey(array $product, array $knownStems): string
    {
        $modelKey = trim((string) ($product['_model_key'] ?? ''));
        $name = trim((string) ($product['name'] ?? ''));
        $nameKey = mb_strtolower($this->sizes->stripSizeFromName($name) ?: $name);
        if ($modelKey !== '') {
            return 'model:'.mb_strtolower($modelKey).'|'.$nameKey;
        }

        $sku = (string) ($product['sku'] ?? '');
        $stem = $this->sizes->resolveMergeStem($sku, $knownStems);
        if ($stem !== null) {
            $price = $this->sizes->priceBucket(
                $product['catalog_price_net'] ?? 0,
                $product['purchase_price'] ?? 0,
            );

            return 'stem:'.mb_strtolower((string) ($product['manufacturer'] ?? '')).'|'.mb_strtolower($stem).'|'.$price;
        }

        $sizeKey = $this->sizes->groupKey(
            (string) ($product['manufacturer'] ?? ''),
            $name,
            $sku,
            isset($product['packaging']) ? (string) $product['packaging'] : null,
        );
        if ($sizeKey !== null) {
            return $sizeKey;
        }

        $packaging = (string) ($product['packaging'] ?? '');
        if ($nameKey !== '' && $this->isSizePackaging($packaging)) {
            return 'name:'.$nameKey;
        }

        return 'unique:'.($sku !== '' ? $sku : uniqid('p', true));
    }

    /**
     * @param  list<array<string, mixed>>  $variants
     * @return array<string, mixed>
     */
    private function pickVariantRepresentative(array $variants): array
    {
        $knownStems = [];
        foreach ($variants as $variant) {
            $stem = $this->sizes->skuTailStem((string) ($variant['sku'] ?? ''));
            if ($stem !== null) {
                $knownStems[mb_strtolower($stem)] = $stem;
            }
        }

        $best = $variants[0];
        $bestScore = -1;
        foreach ($variants as $variant) {
            $score = 0;
            $sku = (string) ($variant['sku'] ?? '');
            if (isset($knownStems[mb_strtolower($sku)])) {
                $score += 80;
            }
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

        $bestSku = (string) ($best['sku'] ?? '');
        $core = $this->sizes->skuCore($bestSku, (string) ($best['name'] ?? ''))
            ?? $this->sizes->resolveMergeStem($bestSku, $knownStems);
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
     * @param  array<string, Collection<int, Product>>  $byManufacturer
     * @param  array<int, ProductSourcePrice|null>  $fileSlots  product_id => slot „file” (null = brak slotu)
     */
    private function findExistingProduct(string $sku, array $payload, array &$byManufacturer, array &$fileSlots): ?Product
    {
        // karta po samym kodzie może należeć do innego producenta — persistImport pomija wtedy pozycję
        $hit = Product::query()->where('sku', $sku)->first();
        if ($hit !== null) {
            return $hit;
        }

        $mfr = trim((string) ($payload['manufacturer'] ?? ''));
        if ($mfr === '') {
            return null;
        }

        $price = $this->sizes->priceBucket(
            $payload['catalog_price_net'] ?? 0,
            $payload['purchase_price'] ?? 0,
        );
        if (! isset($byManufacturer[$mfr])) {
            $byManufacturer[$mfr] = Product::query()->where('manufacturer', $mfr)->get();
            $slots = ProductSourcePrice::query()
                ->where('source_key', ProductSourcePrice::SOURCE_FILE)
                ->whereIn('product_id', $byManufacturer[$mfr]->modelKeys())
                ->get()
                ->keyBy('product_id');
            foreach ($byManufacturer[$mfr] as $product) {
                if (! array_key_exists((int) $product->id, $fileSlots)) {
                    $fileSlots[(int) $product->id] = $slots->get($product->id);
                }
            }
        }

        $knownStems = [];
        foreach ($byManufacturer[$mfr] as $product) {
            $stem = $this->sizes->skuTailStem((string) $product->sku);
            if ($stem !== null) {
                $knownStems[mb_strtolower($stem)] = $stem;
            }
        }
        $incomingStem = $this->sizes->resolveMergeStem($sku, $knownStems);
        if ($incomingStem !== null) {
            $stemHit = null;
            foreach ($byManufacturer[$mfr] as $product) {
                $pStem = $this->sizes->resolveMergeStem((string) $product->sku, $knownStems);
                if ($pStem === null || mb_strtolower($pStem) !== mb_strtolower($incomingStem)) {
                    continue;
                }
                if ($this->sizeMerge->filePriceBucket($product, $this->fileSlot($product, $fileSlots)) !== $price) {
                    continue;
                }
                if (strcasecmp((string) $product->sku, $incomingStem) === 0) {
                    return $product;
                }
                $stemHit ??= $product;
            }
            if ($stemHit !== null) {
                return $stemHit;
            }
        }

        $key = $this->sizes->groupKey(
            $mfr,
            (string) ($payload['name'] ?? ''),
            $sku,
            isset($payload['packaging']) ? (string) $payload['packaging'] : null,
        );
        if ($key === null) {
            return null;
        }
        foreach ($byManufacturer[$mfr] as $product) {
            $pk = $this->sizes->groupKey(
                (string) $product->manufacturer,
                (string) $product->name,
                (string) $product->sku,
                $product->packaging !== null ? (string) $product->packaging : null,
            );
            if ($pk === $key && $this->sizeMerge->filePriceBucket($product, $this->fileSlot($product, $fileSlots)) === $price) {
                return $product;
            }
        }

        return null;
    }

    /**
     * Slot ceny z pliku karty (null = karta nie ma jeszcze ceny z pliku), z pamięcią na czas importu.
     *
     * @param  array<int, ProductSourcePrice|null>  $fileSlots
     */
    private function fileSlot(Product $product, array &$fileSlots): ?ProductSourcePrice
    {
        $id = (int) $product->id;
        if (! array_key_exists($id, $fileSlots)) {
            $fileSlots[$id] = ProductSourcePrice::query()
                ->where('product_id', $id)
                ->where('source_key', ProductSourcePrice::SOURCE_FILE)
                ->first();
        }

        return $fileSlots[$id];
    }

    /**
     * Karta innego producenta niż cennik — porównanie jak B2bCatalogSync::foreignManufacturer (małe litery, trim).
     * Pusty producent karty albo cennika = zgoda.
     */
    private function foreignManufacturer(Product $existing, string $manufacturer): bool
    {
        $card = mb_strtolower(trim((string) $existing->manufacturer));
        $incoming = mb_strtolower(trim($manufacturer));

        return $card !== '' && $incoming !== '' && $card !== $incoming;
    }

    /**
     * Cennik producenta marki karty (właściciel „file” wg CardOwnership, także sugerowany), a karta nie ma powiązania
     * z kontem B2B producenta tej marki — wtedy cennik dalej ustala nazwę i producenta karty. Powiązania dystrybutorów
     * (P4S, Raw-Pol, Ardon…, dopięte np. przez products:merge-duplicate) tego nie blokują: bez tego karta producenta
     * z pliku po scaleniu z kartą dystrybutora przestawała przyjmować nazwę ze swojego cennika.
     */
    private function producerFileOwnsCard(Product $card, string $listManufacturer): bool
    {
        if (! CanonicalBrand::same($listManufacturer, (string) $card->manufacturer)) {
            return false;
        }
        foreach ($this->ownership->ownerSourceKeys($card) as $key) {
            if ($key !== ProductSourcePrice::SOURCE_FILE) {
                return false;
            }
        }

        return true;
    }

    /**
     * @param  array<string, mixed>  $product
     * @return array<string, mixed>
     */
    /**
     * Identyfikatory wiersza cennika dosłownie z komórek (kod, drugi kod, EAN, kod modelu), zanim import zwinie
     * rozmiary i przerobi kod na kod karty. Pozycja = kod wiersza (bez kodu — drugi kod albo EAN); nazwa pola =
     * nagłówek kolumny, etykieta = rozmiar/opakowanie z wiersza. Wiersz bez żadnego kodu nic nie podaje.
     *
     * @param  array<int, mixed>  $row
     * @param  array<string, int>  $map
     * @param  array<int, mixed>  $header  wiersz nagłówka arkusza (surowy)
     * @return list<array{position: string, type: string, value: string, field: string|null, label: string|null}>
     */
    private function rowIdentifiers(array $row, array $map, array $header = []): array
    {
        $cell = static fn (string $role): string => isset($map[$role]) ? trim((string) ($row[$map[$role]] ?? '')) : '';
        $position = $cell('sku') !== '' ? $cell('sku') : ($cell('sku_alt') !== '' ? $cell('sku_alt') : $cell('ean'));
        if ($position === '') {
            return [];
        }
        $label = $cell('packaging') !== '' ? $cell('packaging') : $cell('attr_rozmiar');

        $out = [];
        foreach ([
            'sku' => ProductIdentifier::TYPE_SOURCE_CODE,
            'sku_alt' => ProductIdentifier::TYPE_ALT_CODE,
            'ean' => ProductIdentifier::TYPE_EAN,
            'model_key' => ProductIdentifier::TYPE_MODEL_CODE,
        ] as $role => $type) {
            $value = $cell($role);
            if ($value === '') {
                continue;
            }
            $field = isset($map[$role]) ? trim((string) ($header[$map[$role]] ?? '')) : '';
            $out[] = [
                'position' => $position,
                'type' => $type,
                'value' => $value,
                'field' => $field !== '' ? $field : $role,
                'label' => $label !== '' ? $label : null,
            ];
        }

        return $out;
    }

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
     * @param  list<list<mixed>>  $all
     * @return list<string>
     */
    private function columnHeaderStack(array $all, int $headerIdx, int $col, int $above = 3): array
    {
        $stack = [];
        $from = max(0, $headerIdx - $above);
        for ($i = $from; $i <= $headerIdx; $i++) {
            $stack[] = trim((string) ($all[$i][$col] ?? ''));
        }

        return $stack;
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
     * @param  array{name: ?string, category: ?string, group: ?string, name_group?: ?string, row_name?: ?string, row_key?: ?string}  $carry
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
            $carry = ['name' => null, 'category' => null, 'group' => null, 'name_group' => null];
        }

        $prefix = $sheetName !== null ? "[{$sheetName}] " : '';
        $skuFromCatalog = isset($map['sku']) ? $this->normalizeSku((string) ($row[$map['sku']] ?? '')) : '';
        $sku = $skuFromCatalog;
        if ($sku === '' && isset($map['sku_alt'])) {
            $sku = $this->normalizeSku((string) ($row[$map['sku_alt']] ?? ''));
        }
        $rawName = trim((string) ($row[$map['name']] ?? ''));
        if ($this->looksLikeNonProductName($rawName)) {
            $rawName = '';
        }
        // Nazwa złożona z dwóch kolumn. W cenniku ATG sama „Rodzina rękawic" powtarza się na kilkunastu
        // pozycjach (dziesięć kart „MaxiCut® Oil™"), a sam „Opis rękawicy" nie mówi, jaki to wyrób
        // („Ściągacz, oblanie 3/4"). Rolę name_extra ustawia wyłącznie człowiek w oknie importu, więc
        // złączonego tekstu nie przepuszczamy przez rozpoznawanie opisu — to jest wybrana nazwa, nie opis.
        $composedName = false;
        if (isset($map['name_extra'])) {
            $extra = trim((string) preg_replace('/\s+/u', ' ', (string) ($row[$map['name_extra']] ?? '')));
            if ($extra !== '' && ($rawName === '' || mb_stripos($extra, $rawName) !== false)) {
                // druga kolumna zawiera już pierwszą — doklejenie dałoby powtórzenie
                $rawName = $extra;
            } elseif ($extra !== '' && mb_stripos($rawName, $extra) === false) {
                $rawName .= ' — '.$extra;
                $composedName = true;
            }
        }
        $priceRaw = $row[$map['catalog_price']] ?? null;

        $groupKey = $this->resolveGroupKey($row, $map, $carry);
        $ownModel = isset($map['model_key']) ? $this->normalizeSku((string) ($row[$map['model_key']] ?? '')) : '';
        $inferredModel = $this->rowGroupKey($row, $map);
        $modelForProduct = $ownModel !== '' ? $ownModel : $inferredModel;
        if ($modelForProduct === null && $skuFromCatalog !== '') {
            $modelForProduct = $groupKey;
        }
        if ($groupKey !== null && ($carry['group'] ?? null) !== null && $groupKey !== $carry['group']) {
            $carry['name'] = null;
            $carry['category'] = null;
        }
        if ($groupKey !== null) {
            $carry['group'] = $groupKey;
        }
        // Model z kolumny nazwy modelu (Canis: „LESNÍK”) nie jest kodem grupy, ale mówi, czy wiersz
        // bez nazwy to wariant wiersza wyżej, czy osobny wyrób.
        $rowModelName = isset($map['model_name']) ? trim((string) ($row[$map['model_name']] ?? '')) : '';
        $carryKey = $groupKey ?? ($rowModelName !== '' ? 'model_name:'.mb_strtolower($rowModelName) : null);

        $description = null;
        $name = $rawName;
        if ($rawName !== '' && ! $composedName && $this->isDescriptionLike($rawName)) {
            // Nazwa z wiersza wyżej tylko w obrębie tego samego modelu (DuPont: tytuł w 1. wierszu
            // Reference, opis w kolejnych). Bez wspólnego klucza modelu każdy wiersz opisuje własny
            // wyrób — w cenniku Canis „Men´s shorts CXS LEONIS” przechodziło na setki kolejnych
            // pozycji (polo DOVER jako spodnie, koszulka NOME jako fartuch Ansell).
            $sameModelTitle = ($carry['name'] ?? null) !== null
                && $groupKey !== null
                && ($carry['name_group'] ?? null) === $groupKey;
            // Opisem jest tylko tekst pod tytułem tego samego modelu (DuPont). Wiersz z własnym kodem i długą nazwą to nazwa
            // produktu: cennik 3M 2026 („Osłona przed rozkurzem maski … do bezobsługowej półmaski … 4000+”) dawał nazwę z
            // pierwszych 80 znaków albo z wiersza wyżej, a całą nazwę w opisie — karta wyglądała na opisaną, choć opis
            // był tylko nazwą z cennika, i nie trafiała do pobrania opisu.
            $description = $sameModelTitle ? $rawName : null;
            $name = $sameModelTitle ? (string) $carry['name'] : $rawName;
            if (($carry['name'] ?? null) === null) {
                $carry['name'] = $this->titleFromDescription($rawName);
                $carry['name_group'] = $groupKey;
            }
        } elseif ($rawName === '' && $carryKey !== null) {
            // wiersz bez nazwy dziedziczy ją tylko po wierszu tego samego modelu; inaczej nazwą jest model
            if (($carry['row_name'] ?? null) !== null && ($carry['row_key'] ?? null) === $carryKey) {
                $name = (string) $carry['row_name'];
            } elseif (($carry['name'] ?? null) !== null && $groupKey !== null && ($carry['name_group'] ?? null) === $groupKey) {
                $name = (string) $carry['name'];
            } else {
                $name = $rowModelName;
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
                $carry['name_group'] = $groupKey;
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

        if ($name === '' && $modelForProduct !== null && $modelForProduct !== '') {
            $name = $modelForProduct;
        }
        if ($name === '') {
            return [
                'status' => 'error',
                'message' => "{$prefix}Wiersz {$excelRow}: brak nazwy/ceny — pominięto",
            ];
        }

        if ($sku === '') {
            if (isset($map['sku'])) {
                return ['status' => 'skip'];
            }
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
        // Cena zakupu wyższa od katalogowej to nie jest cena zakupu — w cenniku ARTRY jako „zakup”
        // wskazana została kolumna ceny detalicznej brutto w złotych obok ceny katalogowej w euro.
        // Zamiast zapisać 429 tam, gdzie powinno stać 36, liczymy zakup z upustu jak przy braku kolumny.
        if ($purchase !== null && $catalog > 0 && $purchase > $catalog) {
            $purchase = null;
        }
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
            $carry['name_group'] = $groupKey;
        } elseif (($carry['name'] ?? null) === null && $name !== '') {
            $carry['name'] = $name;
            $carry['name_group'] = $groupKey;
        }
        $carry['row_name'] = $name;
        $carry['row_key'] = $carryKey;

        return [
            'status' => 'ok',
            'product' => [
                'sku' => $sku,
                'name' => $name,
                'model_name' => $this->modelNameFromRow($row, $map, $sku),
                'manufacturer' => $manufacturer,
                'ean' => isset($map['ean']) ? trim((string) ($row[$map['ean']] ?? '')) ?: null : null,
                'category' => $this->cleanCategory(is_string($category) ? $category : null, $name),
                'category_evidence' => $this->categoryEvidence(is_string($category) ? $category : null),
                'description' => $description,
                'norms' => null,
                // pusto = cennik takich kolumn nie ma; nie zapisujemy pustej tablicy udającej odpowiedź
                'price_list_attributes' => $this->attributesFromRow($row, $map) ?: null,
                'catalog_price_net' => $catalog,
                'discount_percent' => $discount,
                'purchase_price' => $purchase,
                'currency' => $currency,
                'stock' => 0,
                'pack_qty' => $packQty,
                'packaging' => $packaging,
                '_model_key' => $modelForProduct,
                '_purchase_from_file' => $purchaseFromFile,
            ],
        ];
    }

    /**
     * Parametry wyrobu wypisane w kolumnach cennika (klasa ochrony, normy, rozmiar, kolor, materiał).
     * Wartość idzie dosłownie ze źródła — nie normalizujemy jej, bo to dokument producenta i każdą
     * pozycję trzeba móc do niego cofnąć. Puste komórki nie tworzą wpisu: brak danych to brak danych.
     *
     * @param  array<int, mixed>  $row
     * @param  array<string, int>  $map
     * @return array<string, string>
     */
    private function attributesFromRow(array $row, array $map): array
    {
        $out = [];
        foreach (SpreadsheetColumnMapper::attributeFields() as $field) {
            if (! isset($map[$field])) {
                continue;
            }
            $value = trim((string) ($row[$map[$field]] ?? ''));
            if ($value === '') {
                continue;
            }
            $out[substr($field, strlen('attr_'))] = mb_substr($value, 0, 190);
        }

        return $out;
    }

    /**
     * Grupa na karcie z drzewa kategorii sklepu, rozpoznana po nazwie wyrobu. Kategoria z cennika
     * dostawcy albo ta domyślna z formularza jest zapasem na wypadek, gdy nazwa nic nie mówi —
     * nie odwrotnie. Numer katalogowy dostawcy nigdy nie powinien być grupą, a nazwa wyrobu
     * zwykle wystarcza, żeby trafić w gałąź drzewa (klasa obuwia, „rękawice”, „kask”).
     *
     * Bez drzewa w bazie nic nie robimy: nie ma na co przepisywać.
     *
     * Pochodzenie idzie razem z kategorią (category_source): kolumna cennika = import, ścieżka drzewa dobrana
     * tutaj z nazwy/parametrów = presta_rewrite — ta nie jest dowodem rodzaju wyrobu przy dopasowaniu. Dowodem
     * zostaje category_evidence (kategoria z cennika albo formularza, sprzed zamiany na ścieżkę).
     *
     * @param  list<array<string, mixed>>  $products
     * @return list<array<string, mixed>>
     */
    private function categoriesFromTree(array $products): array
    {
        if ($products === []) {
            return $products;
        }
        foreach ($products as $index => $payload) {
            // pozycja bez klucza kategorii nie zmienia kategorii karty — pochodzenie też zostaje
            if (! array_key_exists('category', $payload)) {
                continue;
            }
            $products[$index]['category_source'] = trim((string) ($payload['category'] ?? '')) !== ''
                ? Product::CATEGORY_SOURCE_IMPORT
                : null;
        }
        foreach ($products as $index => $payload) {
            // Kategoria-dowód policzona przy zbieraniu wierszy (categoryEvidence) zostaje obok ścieżki drzewa, na
            // którą niżej przepisujemy category. Cennik bez prawdziwej kategorii nie kasuje dowodu z B2B ani z
            // poprzedniego importu.
            if (array_key_exists('category_evidence', $payload) && trim((string) $payload['category_evidence']) === '') {
                unset($products[$index]['category_evidence']);
            }
        }
        try {
            $tree = PrestaCategory::query()->where('active', true)->get();
        } catch (Throwable) {
            return $products;
        }
        if ($tree->isEmpty()) {
            return $products;
        }

        $cache = [];
        foreach ($products as $index => $payload) {
            $name = trim((string) ($payload['name'] ?? ''));
            if ($name === '') {
                continue;
            }
            // Parametry z cennika (rodzaj wyrobu, klasa ochrony) rozstrzygają podgrupę: nazwa ARTRY
            // jest samym kodem i nie mówi, czy to półbut, czy trzewik — mówi to kolumna „typ”.
            $attributes = is_array($payload['price_list_attributes'] ?? null)
                ? implode(' ', array_map(static fn ($v): string => (string) $v, $payload['price_list_attributes']))
                : '';
            $path = $this->prestaCategories->resolvePath(
                new Product([
                    'name' => $name,
                    'category' => trim((string) ($payload['category'] ?? '')),
                ]),
                $tree,
                $cache,
                $attributes,
            );
            if (is_string($path) && trim($path) !== '') {
                if ($path !== trim((string) ($payload['category'] ?? ''))) {
                    $products[$index]['category_source'] = Product::CATEGORY_SOURCE_PRESTA_REWRITE;
                }
                $products[$index]['category'] = $path;
            }
        }

        return $products;
    }

    /**
     * Nazwa modelu z cennika (Bollé: BAXTER, RUSH+ 2.0 XP). Musi mieć literę —
     * „2.0” samo w sobie to nie model — i nie może powtarzać kodu produktu.
     *
     * @param  array<int, mixed>  $row
     * @param  array<string, int>  $map
     */
    private function modelNameFromRow(array $row, array $map, string $sku): ?string
    {
        if (! isset($map['model_name'])) {
            return null;
        }
        $value = trim((string) preg_replace('/\s+/u', ' ', (string) ($row[$map['model_name']] ?? '')));
        if ($value === '' || preg_match('/\p{L}/u', $value) !== 1 || mb_strlen($value) > 120) {
            return null;
        }
        if (mb_strtolower($value) === mb_strtolower(trim($sku))) {
            return null;
        }

        return $value;
    }

    private function looksLikeNonProductName(string $name): bool
    {
        $l = mb_strtolower(trim($name));
        if ($l === '') {
            return false;
        }

        return preg_match('/^column\s*\d+$/', $l) === 1
            || str_contains($l, '€/pc')
            || str_contains($l, '£/pc')
            || (str_contains($l, 'price') && (str_contains($l, 'min.') || str_contains($l, 'min ')));
    }

    private function normalizeSku(string $value): string
    {
        $sku = trim($value);
        $sku = ltrim($sku, '=');
        $upper = strtoupper($sku);
        if ($sku === '' || in_array($upper, ['#N/A', 'N/A', 'NA', 'N.A.', '.', '-', '#REF!', '#VALUE!', '#NAME?'], true)) {
            return '';
        }
        // DuPont i podobne: „D13495380*” / „M*” — gwiazdka = made-to-order
        $sku = rtrim($sku, " \t*");

        return trim($sku);
    }

    /**
     * @param  array<int, mixed>  $row
     * @param  array<string, int>  $map
     * @param  array{name: ?string, category: ?string, group: ?string, name_group?: ?string, row_name?: ?string, row_key?: ?string}  $carry
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
        // „NEW 6/2026” w kolumnie uwag (CXS) — notatka o nowości, nie kod; stawała się
        // kodem produktu zamiast „1010-135-710-00” z kolumny kodu
        if (preg_match('/\b\d{1,2}\/(?:19|20)\d{2}\b/', $value) === 1
            || preg_match('/^(?:new|novinka|nowość|nowosc)\b/iu', $value) === 1) {
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

    private function cleanCategory(?string $category, string $name = ''): ?string
    {
        return $this->categorySanitizer->imported($category, $name);
    }

    /**
     * Kategoria-dowód (products.category_evidence): kolumna cennika albo domyślna kategoria z formularza, dosłownie.
     * Tylko prawdziwa — śmieć z komórki (isGarbage) i etykieta rodziny wymyślona z nazwy (imported → inferLabel) nie
     * są dowodem. Liczona z surowej wartości, zanim categoriesFromTree zamieni kategorię na ścieżkę drzewa sklepu.
     */
    private function categoryEvidence(?string $category): ?string
    {
        $category = trim((string) $category);
        if ($category === '' || $this->categorySanitizer->isGarbage($category)) {
            return null;
        }

        return mb_substr($category, 0, 255);
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    private function clampProductFields(array $payload): array
    {
        $maxName = 1000;
        $payload = $this->withStrippedSizeLabel($payload);
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
            'model_name' => 120,
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

    private function isSpreadsheetUpload(UploadedFile $file): bool
    {
        $name = mb_strtolower($file->getClientOriginalName());

        return str_ends_with($name, '.xlsx')
            || str_ends_with($name, '.xls')
            || str_ends_with($name, '.csv')
            || str_ends_with($name, '.ods');
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

    /**
     * @param  array<string, mixed>  $product
     * @return array<string, mixed>
     */
    private function withStrippedSizeLabel(array $product): array
    {
        $name = trim((string) ($product['name'] ?? ''));
        $clean = $this->sizes->stripSizeLabelFromName($name);
        if ($clean !== '' && $clean !== $name) {
            $product['name'] = $clean;
        }

        return $product;
    }
}
