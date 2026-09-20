<?php

declare(strict_types=1);

namespace App\Services\Enrichment;

use App\Jobs\ReindexProductEmbeddingJob;
use App\Models\Product;
use App\Models\ProductAccessory;
use App\Models\ProductDocument;
use App\Models\ProductEnrichmentCache;
use App\Models\ProductImage;
use App\Support\ProductSearchBlob;
use Illuminate\Support\Facades\DB;
use JsonException;

/**
 * Cofnięcie karty do kolejki wzbogacania z kopią zapasową. Opis, cache SKU, zdjęcia, dokumenty
 * i akcesoria pobrane z sieci pochodzą z tej samej (złej) karty, więc znikają razem; wgrane ręcznie
 * (bez adresu źródła) i akcesoria z Presty/ręczne zostają. Pliki na dysku zostają — kopia przywraca wiersze.
 */
final class ProductEnrichmentResetter
{
    /** Kolumny produktu zapisywane w kopii i przywracane 1:1. */
    private const PRODUCT_COLUMNS = [
        'id', 'sku', 'name', 'description', 'norms', 'packaging', 'shop_source_url', 'enrichment_status',
        'enriched_at', 'enrichment_error', 'enrichment_trace', 'enrichment_payload',
        // Normy z karty producenta: reset ich nie zeruje (nie pochodzą z wzbogacania), ale kopia ma je nieść,
        // żeby przywrócenie oddawało kartę w całości — także gdy przebieg producenta zapisał je pomiędzy.
        'manufacturer_norms',
    ];

    /**
     * @param  list<Product>  $products
     * @return string|null błąd albo null, gdy kopia zapisana i odczytana w całości
     */
    public function writeBackup(string $path, string $label, array $products): ?string
    {
        $entries = array_map(fn (Product $product): array => $this->snapshot($product), $products);
        $dir = dirname($path);
        if (! is_dir($dir) && ! mkdir($dir, 0775, true) && ! is_dir($dir)) {
            return "nie można utworzyć katalogu {$dir}";
        }
        try {
            $json = json_encode(
                ['label' => $label, 'created_at' => now()->toIso8601String(), 'products' => $entries],
                JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR
            );
            if (file_put_contents($path, $json) === false) {
                return 'zapis pliku nie powiódł się';
            }
            // kontrola przed pierwszą zmianą: kopia musi dać się odczytać w całości
            $read = json_decode((string) file_get_contents($path), true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException $e) {
            return $e->getMessage();
        }

        return count($read['products'] ?? []) === count($products) ? null : 'kopia jest niekompletna';
    }

    /**
     * @param  array<string, mixed>  $extraUpdates  np. poprawiona nazwa i opakowanie z cennika
     */
    public function reset(Product $product, array $extraUpdates = []): void
    {
        DB::transaction(function () use ($product, $extraUpdates): void {
            $this->deleteWebData($product);
            // hak Product::saving przelicza indeks tekstowy, Product::updated wysyła reindeks
            $product->update($extraUpdates + [
                'description' => null,
                'norms' => null,
                'enrichment_payload' => null,
                'enrichment_status' => Product::ENRICHMENT_NONE,
                'enriched_at' => null,
                'enrichment_error' => null,
                'enrichment_trace' => null,
                'shop_source_url' => null,
            ]);
        });
    }

    /**
     * @return int|string liczba przywróconych produktów albo błąd
     */
    public function restore(string $path): int|string
    {
        if (! is_file($path)) {
            return "brak kopii zapasowej: {$path}";
        }
        try {
            $data = json_decode((string) file_get_contents($path), true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException $e) {
            return "kopia zapasowa jest uszkodzona: {$e->getMessage()}";
        }

        $tables = [
            'images' => (new ProductImage)->getTable(),
            'documents' => (new ProductDocument)->getTable(),
            'accessories' => (new ProductAccessory)->getTable(),
            'caches' => (new ProductEnrichmentCache)->getTable(),
        ];
        $restored = 0;
        foreach ((array) ($data['products'] ?? []) as $entry) {
            $columns = (array) ($entry['product'] ?? []);
            $id = (int) ($columns['id'] ?? 0);
            $product = $id > 0 ? Product::query()->find($id) : null;
            if ($product === null) {
                continue;
            }
            unset($columns['id']);
            DB::transaction(function () use ($product, $columns, $entry, $tables): void {
                // Po ponownym pobraniu karta ma nowe zdjęcia, dokumenty i cache o tych samych kluczach
                // unikalnych (product_id+checksum, manufacturer+sku) — ustępują stanowi z kopii.
                $this->deleteWebData($product);
                DB::table($product->getTable())->where('id', $product->id)->update($columns);
                foreach ($tables as $section => $table) {
                    foreach ((array) ($entry[$section] ?? []) as $row) {
                        DB::table($table)->insertOrIgnore((array) $row);
                    }
                }
                // zapis surowy omija hak modelu — indeks tekstowy liczymy z przywróconych kolumn
                $fresh = Product::query()->findOrFail($product->id);
                foreach (app(ProductSearchBlob::class)->build($fresh) as $column => $value) {
                    $fresh->setAttribute($column, $value);
                }
                $fresh->saveQuietly();
            });
            ReindexProductEmbeddingJob::dispatch($product->id);
            $restored++;
        }

        return $restored;
    }

    /**
     * @return array<string, mixed>
     */
    private function snapshot(Product $product): array
    {
        $key = ProductEnrichmentCache::normalizeKey((string) $product->manufacturer, (string) $product->sku);
        $rows = static fn (string $table, callable $scope): array => $scope(DB::table($table))
            ->get()
            ->map(static fn (object $row): array => (array) $row)
            ->all();

        return [
            'product' => (array) DB::table($product->getTable())->where('id', $product->id)->first(self::PRODUCT_COLUMNS),
            'images' => $rows((new ProductImage)->getTable(), static fn ($q) => $q->where('product_id', $product->id)),
            'documents' => $rows((new ProductDocument)->getTable(), static fn ($q) => $q->where('product_id', $product->id)),
            'accessories' => $rows((new ProductAccessory)->getTable(), static fn ($q) => $q->where('product_id', $product->id)
                ->where('source', ProductAccessory::SOURCE_ENRICHMENT)),
            'caches' => $rows((new ProductEnrichmentCache)->getTable(), static fn ($q) => $q
                ->where('manufacturer', $key['manufacturer'])
                ->where('sku', $key['sku'])),
        ];
    }

    private function deleteWebData(Product $product): void
    {
        $key = ProductEnrichmentCache::normalizeKey((string) $product->manufacturer, (string) $product->sku);
        ProductEnrichmentCache::query()->where('manufacturer', $key['manufacturer'])->where('sku', $key['sku'])->delete();
        ProductImage::query()->where('product_id', $product->id)->whereNotNull('source_url')->where('source_url', '!=', '')->delete();
        // pliki z paneli B2B zostają — nie pochodzą z internetu i wracają tylko przez pobranie cennika
        ProductDocument::query()->where('product_id', $product->id)->whereNull('b2b_account_id')
            ->whereNotNull('source_url')->where('source_url', '!=', '')->delete();
        ProductAccessory::query()->where('product_id', $product->id)->where('source', ProductAccessory::SOURCE_ENRICHMENT)->delete();
    }
}
