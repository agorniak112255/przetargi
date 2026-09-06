<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Jobs\ReindexProductEmbeddingJob;
use App\Models\Product;
use App\Services\Vector\ProductEmbeddingIndexer;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Tests\TestCase;

final class ProductEmbeddingReindexOnSaveTest extends TestCase
{
    use RefreshDatabase;

    private function product(): Product
    {
        return Product::query()->create([
            'sku' => 'GLOVE-1',
            'name' => 'Rękawice chemiczne',
            'manufacturer' => 'Ansell',
            'description' => 'Odporne na kwasy.',
            'catalog_price_net' => 10,
            'purchase_price' => 6,
            'stock' => 5,
        ]);
    }

    public function test_editing_description_queues_reindex(): void
    {
        $product = $this->product();
        Bus::fake();

        $product->update(['description' => 'Odporne na kwasy i amoniak, EN 374.']);

        Bus::assertDispatched(
            ReindexProductEmbeddingJob::class,
            static fn (ReindexProductEmbeddingJob $job): bool => $job->productId === $product->id,
        );
    }

    public function test_price_only_change_does_not_queue_reindex(): void
    {
        $product = $this->product();
        Bus::fake();

        // Cena nie wchodzi do dokumentu embeddingu — reindeks byłby czystym kosztem.
        $product->update(['purchase_price' => 7.5]);

        Bus::assertNotDispatched(ReindexProductEmbeddingJob::class);
    }

    public function test_new_product_queues_reindex(): void
    {
        Bus::fake();

        $this->product();

        Bus::assertDispatched(ReindexProductEmbeddingJob::class);
    }

    public function test_document_hash_changes_with_embedding_model(): void
    {
        $indexer = app(ProductEmbeddingIndexer::class);
        $text = 'GLOVE-1 | Rękawice chemiczne';

        config()->set('ai.embedding_model', 'model-a');
        $first = $indexer->documentHash($text);

        config()->set('ai.embedding_model', 'model-b');
        $second = $indexer->documentHash($text);

        // Wektory z innego modelu leżą w innej przestrzeni — muszą przeliczyć się od nowa.
        $this->assertNotSame($first, $second);
    }
}
