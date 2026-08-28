<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Jobs\EnrichProductJob;
use App\Models\Product;
use App\Models\ProductEnrichmentBatch;
use App\Services\Ai\AiSettingsService;
use App\Services\Enrichment\EnrichmentSlots;
use App\Services\Enrichment\ProductEnrichmentService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

final class EnrichmentSlotsTest extends TestCase
{
    use RefreshDatabase;

    public function test_acquire_stops_at_configured_limit(): void
    {
        $this->setConcurrency(2);
        $slots = app(EnrichmentSlots::class);

        $this->assertNotNull($slots->acquire(60, 0.0));
        $this->assertNotNull($slots->acquire(60, 0.0));
        $this->assertNull($slots->acquire(60, 0.0), 'Trzeci slot poza limitem 2');
    }

    public function test_try_acquire_many_skips_busy_slots(): void
    {
        $this->setConcurrency(4);
        $slots = app(EnrichmentSlots::class);

        $busy = $slots->acquire(60, 0.0);
        $this->assertNotNull($busy);

        $wave = $slots->tryAcquireMany(4, 60);
        $this->assertCount(3, $wave, '3 wolne z limitu 4, jeden już zajęty');
        $this->assertNull($slots->acquire(60, 0.0));

        foreach ($wave as $lock) {
            $lock->release();
        }
        $busy->release();
    }

    public function test_freed_slot_is_reused(): void
    {
        $this->setConcurrency(1);
        $slots = app(EnrichmentSlots::class);

        $first = $slots->acquire(60, 0.0);
        $this->assertNotNull($first);
        $this->assertNull($slots->acquire(60, 0.0));

        $first->release();
        $this->assertNotNull($slots->acquire(60, 0.0), 'Po zwolnieniu slot musi być znów wolny');
    }

    public function test_job_requeues_product_when_all_slots_busy(): void
    {
        $this->setConcurrency(1);
        $slots = app(EnrichmentSlots::class);
        $busy = $slots->acquire(600, 0.0);
        $this->assertNotNull($busy);

        $product = Product::query()->create([
            'sku' => '131-S1',
            'name' => '131 S1',
            'manufacturer' => 'Urgent',
            'catalog_price_net' => 10,
            'purchase_price' => 5,
            'stock' => 1,
            'enrichment_status' => Product::ENRICHMENT_QUEUED,
        ]);
        $batch = ProductEnrichmentBatch::query()->create([
            'scope' => ProductEnrichmentBatch::SCOPE_PRODUCT,
            'scope_id' => $product->id,
            'total' => 1,
            'done' => 0,
            'failed' => 0,
            'status' => ProductEnrichmentBatch::STATUS_QUEUED,
            'force' => false,
        ]);

        $this->assertNull($slots->acquire(60, 0.0), 'Limit 1 — drugi slot nie może być wolny');

        config(['ai.enrichment_slot_wait_seconds' => 1]);
        Queue::fake();
        $job = new EnrichProductJob($product->id, $batch->id);
        $job->handle(app(ProductEnrichmentService::class), app(AiSettingsService::class), $slots);

        Queue::assertPushedOn('enrich', EnrichProductJob::class);
        $this->assertSame(
            Product::ENRICHMENT_QUEUED,
            $product->fresh()?->enrichment_status,
            'Produkt bez slotu zostaje w kolejce, nie leci w running',
        );
    }

    private function setConcurrency(int $value): void
    {
        DB::table('ai_settings')->updateOrInsert(
            ['id' => 1],
            ['match_concurrency' => $value, 'updated_at' => now(), 'created_at' => now()],
        );
    }
}
