<?php

declare(strict_types=1);

namespace App\Services\B2b;

use App\Models\B2bAccount;
use App\Models\B2bProductLink;
use App\Models\Product;
use App\Models\ProductPriceHistory;
use App\Services\Enrichment\ProductImageDownloader;
use App\Services\PriceListImportService;
use Throwable;

/**
 * Wspólne zasady zapisu produktów z B2B do katalogu (dla każdego łącznika):
 * - karta dopasowana po powiązaniu z poprzedniego przebiegu, inaczej po dokładnym kodzie; kod karty
 *   innego producenta jest pomijany (wspólny import cenników skleja warianty po rdzeniu kodu i cenie);
 * - cena zawsze ze źródła; historia cen karty (źródło „b2b:{łącznik}”, przebieg) tylko przy nowej karcie
 *   lub zmianie ceny — bez wpisów w historii cenników (zmiany widać na karcie produktu z datą);
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
     *     total_remote: int,
     *     seen: int,
     *     created: int,
     *     updated: int,
     *     unchanged: int,
     *     skipped: int,
     *     descriptions: int,
     *     images: int,
     *     prices_changed: int,
     *     errors: list<string>,
     *     cancelled: bool
     * }
     */
    public function run(
        B2bAccount $account,
        B2bConnector $connector,
        ?int $limit = null,
        bool $dryRun = false,
        bool $withImages = true,
        ?callable $onProduct = null,
        ?B2bSyncProgress $progress = null,
    ): array {
        $progress?->log('info', 'Logowanie…');
        $progress?->flush();
        $connector->login();

        $stats = [
            'total_remote' => 0, 'seen' => 0, 'created' => 0, 'updated' => 0,
            'unchanged' => 0, 'skipped' => 0, 'descriptions' => 0, 'images' => 0,
        ];
        $errors = [];
        $pricesChanged = 0;
        $cancelled = false;
        $expected = static fn (int $total): int => $limit !== null ? min($limit, $total) : $total;
        $runId = $progress?->run()->id;

        foreach ($connector->products() as $remote) {
            if ($limit !== null && $stats['seen'] >= $limit) {
                break;
            }
            $stats['seen']++;
            $stats['total_remote'] = $connector->totalProducts();
            $label = $remote->sku !== '' ? $remote->sku : 'ID '.$remote->remoteId;
            if ($stats['seen'] === 1 && $progress !== null) {
                $progress->setTotal($expected($stats['total_remote']));
                $progress->log('info', $this->totalLine($stats['total_remote'], $limit));
            }

            try {
                $outcome = $this->syncProduct($account, $connector, $remote, $dryRun, $withImages, $runId);
            } catch (Throwable $e) {
                $outcome = ['status' => 'skipped', 'reason' => $e->getMessage()];
            }

            if ($outcome['status'] === 'skipped') {
                $stats['skipped']++;
                $errors[] = $label.': '.$outcome['reason'];
            } else {
                $stats[$outcome['status']]++;
                if (($outcome['price_change'] ?? null) !== null) {
                    $pricesChanged++;
                    $progress?->priceChange([
                        'product_id' => $outcome['product_id'],
                        ...$outcome['price_change'],
                        'at' => now()->toIso8601String(),
                    ]);
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

            $line = sprintf(
                '[%d/%d] %s — %s',
                $stats['seen'],
                $expected($stats['total_remote']),
                $label,
                match ($outcome['status']) {
                    'created' => 'nowy',
                    'updated' => 'zaktualizowany',
                    'unchanged' => 'bez zmian',
                    default => 'pominięty: '.$outcome['reason'],
                },
            );
            if ($onProduct !== null) {
                $onProduct($line);
            }

            if ($progress !== null) {
                // „bez zmian” tylko w licznikach — przy pełnym cenniku to tysiące wierszy szumu
                if ($outcome['status'] !== 'unchanged') {
                    $progress->log($outcome['status'] === 'skipped' ? 'warn' : 'info', $line);
                }
                if (($outcome['image_error'] ?? null) !== null) {
                    $progress->log('warn', $label.': zdjęcie — '.$outcome['image_error']);
                }
                $progress->setTotal($expected($stats['total_remote']));
                $progress->advance($label, [
                    'processed' => $stats['seen'],
                    'created' => $stats['created'],
                    'updated' => $stats['updated'],
                    'unchanged' => $stats['unchanged'],
                    'skipped' => $stats['skipped'],
                    'prices_changed' => $pricesChanged,
                    'descriptions' => $stats['descriptions'],
                    'images' => $stats['images'],
                ]);
                if ($progress->cancelRequested()) {
                    $cancelled = true;
                    break;
                }
            }
        }
        $stats['total_remote'] = $connector->totalProducts();
        if ($progress !== null) {
            $progress->setTotal($expected($stats['total_remote']));
            if ($stats['seen'] === 0) {
                $progress->log('info', $this->totalLine($stats['total_remote'], $limit));
            }
        }

        return [
            ...$stats,
            'prices_changed' => $pricesChanged,
            'errors' => $errors,
            'cancelled' => $cancelled,
        ];
    }

    private function totalLine(int $total, ?int $limit): string
    {
        return 'Produktów w B2B: '.$total.($limit !== null ? ' · próbka: '.min($limit, $total) : '');
    }

    /**
     * @return array<string, mixed>
     */
    private function syncProduct(
        B2bAccount $account,
        B2bConnector $connector,
        B2bRemoteProduct $remote,
        bool $dryRun,
        bool $withImages,
        ?int $runId,
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
        $dirty = true;
        if ($existing !== null) {
            $priceChange = $this->priceLists->detectPriceChange($existing, $payload, $remote->sku);
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
                'price_list_id' => null,
                'b2b_sync_run_id' => $runId,
                'catalog_price_net' => $product->catalog_price_net,
                'purchase_price' => $product->purchase_price,
                'source' => 'b2b:'.$connector::key(),
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
