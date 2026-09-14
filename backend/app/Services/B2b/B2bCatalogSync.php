<?php

declare(strict_types=1);

namespace App\Services\B2b;

use App\Models\B2bAccount;
use App\Models\B2bProductLink;
use App\Models\PriceList;
use App\Models\Product;
use App\Models\ProductPriceHistory;
use App\Models\User;
use App\Services\Enrichment\ProductImageDownloader;
use App\Services\PriceListImportService;
use Throwable;

/**
 * Wspólne zasady zapisu produktów z B2B do katalogu (dla każdego łącznika):
 * - karta dopasowana po powiązaniu z poprzedniego przebiegu, inaczej po dokładnym kodzie; kod karty
 *   innego producenta jest pomijany (wspólny import cenników skleja warianty po rdzeniu kodu i cenie);
 * - cena zawsze ze źródła; historia cen tylko przy nowej karcie lub zmianie ceny;
 * - opis ze źródła, gdy karta go nie ma albo ma opis zapisany wcześniej przez synchronizację i
 *   niezmieniony od tamtej pory — opisu poprawionego ręcznie nie nadpisujemy;
 * - kategoria, link i zdjęcie tylko gdy puste; produktów znikniętych z B2B nie kasujemy.
 */
final class B2bCatalogSync
{
    public function __construct(
        private readonly PriceListImportService $priceLists,
        private readonly ProductImageDownloader $images,
    ) {}

    /**
     * @param  (callable(string): void)|null  $onProduct
     * @return array{
     *     price_list: ?PriceList,
     *     total_remote: int,
     *     seen: int,
     *     created: int,
     *     updated: int,
     *     unchanged: int,
     *     skipped: int,
     *     descriptions: int,
     *     images: int,
     *     prices_changed: int,
     *     errors: list<string>
     * }
     */
    public function run(
        B2bAccount $account,
        B2bConnector $connector,
        User $user,
        ?int $limit = null,
        bool $dryRun = false,
        bool $withImages = true,
        ?callable $onProduct = null,
    ): array {
        // Złe hasło ma przerwać przed założeniem wpisu w historii cenników.
        $connector->login();

        $priceList = $dryRun ? null : PriceList::query()->create([
            'manufacturer' => mb_substr($connector::label(), 0, 100),
            'version' => 'B2B '.now()->setTimezone(B2bAccount::SYNC_TIMEZONE)->format('Y-m-d H:i'),
            'original_filename' => $connector::host().' (API)',
            'imported_by' => $user->id,
        ]);

        $stats = [
            'total_remote' => 0, 'seen' => 0, 'created' => 0, 'updated' => 0,
            'unchanged' => 0, 'skipped' => 0, 'descriptions' => 0, 'images' => 0,
        ];
        $errors = [];
        $skippedDetails = [];
        $priceChanges = [];
        $updatedProducts = [];
        $productIds = [];

        try {
            foreach ($connector->products() as $remote) {
                if ($limit !== null && $stats['seen'] >= $limit) {
                    break;
                }
                $stats['seen']++;
                $stats['total_remote'] = $connector->totalProducts();
                $label = $remote->sku !== '' ? $remote->sku : 'ID '.$remote->remoteId;

                try {
                    $outcome = $this->syncProduct($account, $connector, $remote, $priceList, $dryRun, $withImages);
                } catch (Throwable $e) {
                    $outcome = ['status' => 'skipped', 'reason' => $e->getMessage()];
                }

                if ($outcome['status'] === 'skipped') {
                    $stats['skipped']++;
                    $errors[] = $label.': '.$outcome['reason'];
                    $skippedDetails[] = [
                        'reason' => $outcome['reason'],
                        'row' => $stats['seen'],
                        'sheet' => null,
                        'sku' => $remote->sku !== '' ? $remote->sku : null,
                        'name' => $remote->name !== '' ? $remote->name : null,
                    ];
                } else {
                    $stats[$outcome['status']]++;
                    if (isset($outcome['product_id'])) {
                        $productIds[] = $outcome['product_id'];
                    }
                    if (($outcome['price_change'] ?? null) !== null) {
                        $priceChanges[] = $outcome['price_change'];
                    }
                    if (($outcome['update_summary'] ?? null) !== null) {
                        $updatedProducts[] = $outcome['update_summary'];
                    }
                    if ($outcome['description'] ?? false) {
                        $stats['descriptions']++;
                    }
                    if ($outcome['image'] ?? false) {
                        $stats['images']++;
                    }
                    if (($outcome['image_error'] ?? null) !== null) {
                        $errors[] = $label.': zdjęcie — '.$outcome['image_error'];
                    }
                }

                if ($onProduct !== null) {
                    $onProduct(sprintf(
                        '[%d/%d] %s — %s',
                        $stats['seen'],
                        $limit !== null ? min($limit, $stats['total_remote']) : $stats['total_remote'],
                        $label,
                        match ($outcome['status']) {
                            'created' => 'nowy',
                            'updated' => 'zaktualizowany',
                            'unchanged' => 'bez zmian',
                            default => 'pominięty: '.$outcome['reason'],
                        },
                    ));
                }
            }
            $stats['total_remote'] = $connector->totalProducts();
        } finally {
            if ($priceList !== null) {
                if ($stats['created'] === 0 && $stats['updated'] === 0) {
                    // Codzienny przebieg bez zmian nie zaśmieca historii cenników.
                    $priceList->delete();
                    $priceList = null;
                } else {
                    usort($priceChanges, static fn (array $a, array $b): int => abs($b['catalog_pct']) <=> abs($a['catalog_pct']));
                    $priceList->update([
                        'rows_total' => $stats['seen'],
                        'products_created' => $stats['created'],
                        'products_updated' => $stats['updated'],
                        'prices_changed' => count($priceChanges),
                        'rows_skipped' => $stats['skipped'],
                        'errors' => array_slice($errors, 0, 50),
                        'price_changes' => array_slice($priceChanges, 0, 100),
                        'updated_products' => array_slice($updatedProducts, 0, 100),
                        'skipped_details' => array_slice($skippedDetails, 0, 100),
                        'product_ids' => array_values(array_unique($productIds)),
                    ]);
                }
            }
        }

        return [
            'price_list' => $priceList,
            ...$stats,
            'prices_changed' => count($priceChanges),
            'errors' => $errors,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function syncProduct(
        B2bAccount $account,
        B2bConnector $connector,
        B2bRemoteProduct $remote,
        ?PriceList $priceList,
        bool $dryRun,
        bool $withImages,
    ): array {
        if ($remote->remoteId === '' || $remote->sku === '' || $remote->name === '') {
            return ['status' => 'skipped', 'reason' => 'brak kodu lub nazwy'];
        }

        $link = B2bProductLink::query()
            ->where('b2b_account_id', $account->id)
            ->where('remote_id', $remote->remoteId)
            ->with('product')
            ->first();
        $linked = $link?->product;
        $existing = $linked ?? Product::query()->where('sku', $remote->sku)->first();
        $manufacturer = mb_substr(trim($connector->manufacturer($remote)), 0, 100);

        if ($existing !== null && $linked === null
            && mb_strtolower(trim((string) $existing->manufacturer)) !== mb_strtolower($manufacturer)) {
            return ['status' => 'skipped', 'reason' => 'kod należy do karty producenta '.$existing->manufacturer];
        }

        $price = $connector->price($remote);
        if ($price === null) {
            return ['status' => 'skipped', 'reason' => 'brak ceny w B2B'];
        }

        $payload = [
            'name' => mb_substr($remote->name, 0, 1000),
            'manufacturer' => $manufacturer,
            // cena konta (po rabacie) = zakup; cena bazowa dostawcy = katalogowa
            'catalog_price_net' => $price->base ?? $price->net,
            'purchase_price' => $price->net,
            'discount_percent' => $price->discountPercent,
            'currency' => $price->currency,
        ];
        if ($remote->category !== null && trim((string) ($existing?->category ?? '')) === '') {
            $payload['category'] = mb_substr($remote->category, 0, 255);
        }
        if ($remote->sourceUrl !== null && trim((string) ($existing?->shop_source_url ?? '')) === '') {
            $payload['shop_source_url'] = $remote->sourceUrl;
        }

        $descriptionHash = $link?->description_hash;
        if ($this->mayWriteDescription($existing, $link)) {
            $description = $connector->description($remote);
            if ($description !== '') {
                $descriptionHash = sha1($description);
                if ($existing === null || $description !== (string) $existing->description) {
                    $payload['description'] = $description;
                }
            }
        }

        $priceChange = null;
        $updateSummary = null;
        $dirty = true;
        if ($existing !== null) {
            $priceChange = $this->priceLists->detectPriceChange($existing, $payload, $remote->sku);
            $updateSummary = $this->priceLists->summarizeUpdate($existing, $payload, $remote->sku, $priceChange !== null);
            $existing->fill($payload);
            $dirty = $existing->isDirty();
        }
        $status = $existing === null ? 'created' : ($dirty ? 'updated' : 'unchanged');

        if ($dryRun) {
            return ['status' => $status, 'description' => isset($payload['description'])];
        }

        if ($existing !== null) {
            if ($dirty) {
                $existing->save();
            }
            $product = $existing;
        } else {
            $product = Product::query()->create(['sku' => $remote->sku, ...$payload]);
        }

        if ($existing === null || $priceChange !== null) {
            ProductPriceHistory::query()->create([
                'product_id' => $product->id,
                'price_list_id' => $priceList?->id,
                'catalog_price_net' => $product->catalog_price_net,
                'purchase_price' => $product->purchase_price,
                'source' => 'b2b_api',
            ]);
        }

        B2bProductLink::query()->updateOrCreate(
            ['b2b_account_id' => $account->id, 'remote_id' => $remote->remoteId],
            [
                'product_id' => $product->id,
                'remote_sku' => mb_substr($remote->sku, 0, 255),
                'description_hash' => $descriptionHash,
                'last_seen_at' => now(),
            ],
        );

        $image = false;
        $imageError = null;
        if ($withImages && ! $product->images()->exists()) {
            try {
                $remoteImage = $connector->image($remote);
                if ($remoteImage !== null) {
                    $image = $this->images->storeBytes($product, $remoteImage->bytes, $remoteImage->mime, $remoteImage->sourceUrl, 0) !== null;
                }
            } catch (Throwable $e) {
                $imageError = $e->getMessage();
            }
        }

        return [
            'status' => $status,
            'product_id' => (int) $product->id,
            'price_change' => $priceChange,
            'update_summary' => $dirty ? $updateSummary : null,
            'description' => isset($payload['description']),
            'image' => $image,
            'image_error' => $imageError,
        ];
    }

    private function mayWriteDescription(?Product $existing, ?B2bProductLink $link): bool
    {
        if ($existing === null || ! $existing->hasDescriptionText()) {
            return true;
        }

        return $link?->description_hash !== null
            && hash_equals($link->description_hash, sha1((string) $existing->description));
    }
}
