<?php

declare(strict_types=1);

namespace App\Services\Vector;

use App\Models\Product;
use App\Support\BhpAttributeNormalizer;
use App\Services\Ai\AiSettingsService;
use Illuminate\Support\Facades\Log;
use Throwable;

final class ProductEmbeddingIndexer
{
    public function __construct(
        private readonly AiSettingsService $settings,
        private readonly EmbeddingClient $embeddings,
        private readonly QdrantClient $qdrant,
        private readonly BhpAttributeNormalizer $bhpAttributes,
    ) {}

    public function shouldIndex(): bool
    {
        return $this->qdrant->isConfigured();
    }

    public function index(Product $product, bool $force = false): bool
    {
        if (! $this->shouldIndex()) {
            return false;
        }

        $text = $this->documentText($product);
        $hash = hash('sha256', $text);

        if (! $force && $product->embedding_hash === $hash && $product->embedding_synced_at !== null) {
            return false;
        }

        try {
            $vector = $this->embeddings->embed($text);
            $this->qdrant->upsert($product->id, $vector, [
                'sku' => (string) $product->sku,
                'name' => mb_substr((string) $product->name, 0, 255),
                'manufacturer' => (string) ($product->manufacturer ?? ''),
            ]);
            $product->forceFill([
                'embedding_hash' => $hash,
                'embedding_synced_at' => now(),
            ])->save();

            return true;
        } catch (Throwable $e) {
            Log::warning('Product embedding index failed', [
                'product_id' => $product->id,
                'error' => $e->getMessage(),
            ]);

            return false;
        }
    }

    public function delete(int $productId): void
    {
        if (! $this->shouldIndex()) {
            return;
        }

        try {
            $this->qdrant->delete($productId);
        } catch (Throwable $e) {
            Log::warning('Product embedding delete failed', [
                'product_id' => $productId,
                'error' => $e->getMessage(),
            ]);
        }
    }

    public function documentText(Product $product): string
    {
        $payload = is_array($product->enrichment_payload) ? $product->enrichment_payload : [];
        $parts = [
            (string) $product->sku,
            (string) $product->name,
            (string) ($product->manufacturer ?? ''),
            (string) ($product->category ?? ''),
            (string) ($product->norms ?? ''),
            (string) ($product->description ?? ''),
            $this->joinList($payload['materials'] ?? null),
            $this->joinList($payload['features'] ?? null),
            $this->joinList($payload['use_cases'] ?? null),
            $this->joinList($payload['norms'] ?? null),
            $this->bhpAttributes->toSearchText($this->bhpAttributes->forProduct($product)),
        ];

        $text = implode(' | ', array_values(array_filter(
            array_map(static fn (string $s): string => trim($s), $parts),
            static fn (string $s): bool => $s !== ''
        )));

        if (mb_strlen($text) > 8000) {
            $text = mb_substr($text, 0, 8000);
        }

        return $text !== '' ? $text : ('product:'.$product->id);
    }

    private function joinList(mixed $value): string
    {
        if (! is_array($value)) {
            return is_string($value) ? $value : '';
        }

        $items = [];
        foreach ($value as $item) {
            if (is_string($item) && trim($item) !== '') {
                $items[] = trim($item);
            }
        }

        return implode(', ', $items);
    }
}
