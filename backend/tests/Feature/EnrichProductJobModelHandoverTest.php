<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Jobs\ApplyModelDescriptionJob;
use App\Jobs\EnrichProductJob;
use App\Jobs\PrefetchProductSourcesJob;
use App\Models\Product;
use App\Models\ProductDescriptionVersion;
use App\Models\ProductEnrichmentBatch;
use App\Models\ProductEnrichmentBatchItem;
use App\Models\ProductEnrichmentCache;
use App\Services\Ai\AiSettingsService;
use App\Services\Enrichment\DescriptionVersionStore;
use App\Services\Enrichment\EnrichmentSlots;
use App\Services\Enrichment\ModelGroupPlanner;
use App\Services\Enrichment\ProductEnrichmentService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use RuntimeException;
use Tests\TestCase;

/**
 * Opis wspólny dla modelu (etap 2, 08.10.2026): po przebiegu lidera EnrichProductJob przekazuje model członkom —
 * wersja lidera → ApplyModelDescriptionJob (numer wersji w pozycjach członków), brak wersji (lider pominięty, bez
 * źródeł, wyczerpane próby) → sztafeta: kolejny członek liderem i prefetch. Przebieg lidera idzie z pamięci SKU (bez
 * sieci i modelu); wersja przebiegu przez szew zadania (versionOfRun), bo serwis jest klasą final.
 */
final class EnrichProductJobModelHandoverTest extends TestCase
{
    use RefreshDatabase;

    private const MODEL_KEY = 'testowy|LEAD|mata testowa';

    protected function setUp(): void
    {
        parent::setUp();
        Queue::fake();
        Storage::fake('public');
    }

    public function test_leader_with_version_hands_members_over_to_apply_job(): void
    {
        [$batch, $leader, $members] = $this->group();
        $this->skuCacheFor($leader);
        $job = $this->job($leader, $batch, false, static fn (): ?ProductDescriptionVersion => ProductDescriptionVersion::query()
            ->where('product_id', $leader->id)
            ->where('status', ProductDescriptionVersion::STATUS_PUBLISHED)
            ->orderByDesc('id')
            ->first());

        $job->handle(app(ProductEnrichmentService::class), app(AiSettingsService::class), app(EnrichmentSlots::class));

        $this->assertSame(Product::ENRICHMENT_DONE, $leader->fresh()->enrichment_status);
        $version = ProductDescriptionVersion::query()->where('product_id', $leader->id)->where('status', ProductDescriptionVersion::STATUS_PUBLISHED)->sole();
        $this->assertSame(ProductEnrichmentBatchItem::STATUS_DONE, $this->item($leader)->status);
        foreach ($members as $member) {
            $this->assertSame(ProductEnrichmentBatchItem::STATUS_QUEUED, $this->item($member)->status);
            $this->assertSame((int) $version->id, (int) $this->item($member)->model_leader_version_id);
        }
        Queue::assertPushed(ApplyModelDescriptionJob::class, 1);
        Queue::assertPushed(ApplyModelDescriptionJob::class, static fn (ApplyModelDescriptionJob $apply): bool => $apply->batchId === $batch->id
            && $apply->leaderId === $leader->id
            && $apply->leaderVersionId === (int) $version->id
            && $apply->queue === 'enrich');
        Queue::assertNotPushed(PrefetchProductSourcesJob::class);
    }

    public function test_leader_without_version_after_run_promotes_next_leader(): void
    {
        [$batch, $leader, $members] = $this->group();
        $this->skuCacheFor($leader);
        $job = $this->job($leader, $batch, false, static fn (): ?ProductDescriptionVersion => null);

        $job->handle(app(ProductEnrichmentService::class), app(AiSettingsService::class), app(EnrichmentSlots::class));

        $this->assertNextLeaderPrefetched($batch, $members, false);
    }

    public function test_leader_skipped_as_already_described_promotes_next_leader(): void
    {
        [$batch, $leader, $members] = $this->group(['enrichment_status' => Product::ENRICHMENT_DONE, 'description' => 'Mata testowa z gotowym opisem, pianka PVC.']);

        (new EnrichProductJob($leader->id, $batch->id))->handle(app(ProductEnrichmentService::class), app(AiSettingsService::class), app(EnrichmentSlots::class));

        $this->assertSame(ProductEnrichmentBatchItem::STATUS_SKIPPED, $this->item($leader)->status);
        $this->assertNextLeaderPrefetched($batch, $members, false);
    }

    public function test_failed_leader_promotes_next_leader_with_batch_force(): void
    {
        [$batch, $leader, $members] = $this->group(['enrichment_status' => Product::ENRICHMENT_RUNNING]);
        $batch->update(['force' => true]);

        (new EnrichProductJob($leader->id, $batch->id, true))->failed(new RuntimeException('Limit czasu zadania'));

        $this->assertSame(ProductEnrichmentBatchItem::STATUS_FAILED, $this->item($leader)->status);
        $this->assertSame(Product::ENRICHMENT_FAILED, $leader->fresh()->enrichment_status);
        $this->assertNextLeaderPrefetched($batch, $members, true);
    }

    /**
     * Lider zapisał opis, a potem przebieg padł (wyjątek po zapisie, limit czasu): ponowienie trafia w kartę „done”
     * bez wersji z własnego przebiegu — członkowie dostają wersję z tej partii (także zastąpioną nowszą z innej
     * partii), nie kolejnego lidera: sztafeta dawała drugie wywołanie modelu i opis A u lidera, B u członków.
     */
    public function test_retry_after_saved_version_hands_members_the_version_of_this_batch(): void
    {
        [$batch, $leader, $members] = $this->group(['enrichment_status' => Product::ENRICHMENT_DONE, 'description' => 'Mata testowa z opisem z poprzedniej próby, pianka PVC.']);
        $ofThisBatch = $this->version($leader, (int) $batch->id);
        $otherBatch = $this->batch(1);
        $newerOfOtherBatch = $this->version($leader, (int) $otherBatch->id);
        $this->assertSame(ProductDescriptionVersion::STATUS_SUPERSEDED, $ofThisBatch->fresh()->status);

        (new EnrichProductJob($leader->id, $batch->id))->handle(app(ProductEnrichmentService::class), app(AiSettingsService::class), app(EnrichmentSlots::class));

        $this->assertSame(ProductEnrichmentBatchItem::STATUS_SKIPPED, $this->item($leader)->status);
        $this->assertModelHandedOver($batch, $leader, $members, (int) $ofThisBatch->id);
        $this->assertNotSame((int) $newerOfOtherBatch->id, (int) $this->item($members[0])->model_leader_version_id);
    }

    /** Wyczerpane próby po zapisanym opisie (limit czasu w recordSourceDocuments): failed() też oddaje wersję z partii. */
    public function test_failed_leader_with_version_of_this_batch_hands_it_over_instead_of_relay(): void
    {
        [$batch, $leader, $members] = $this->group(['enrichment_status' => Product::ENRICHMENT_DONE, 'description' => 'Mata testowa z opisem z poprzedniej próby, pianka PVC.']);
        $version = $this->version($leader, (int) $batch->id);

        (new EnrichProductJob($leader->id, $batch->id))->failed(new RuntimeException('Limit czasu zadania'));

        $this->assertSame(ProductEnrichmentBatchItem::STATUS_FAILED, $this->item($leader)->status);
        $this->assertSame(Product::ENRICHMENT_DONE, $leader->fresh()->enrichment_status, 'zapisany opis zostaje na karcie');
        $this->assertModelHandedOver($batch, $leader, $members, (int) $version->id);
    }

    /**
     * Wyjątek w przekazaniu modelu (zakleszczenie przy zapisie pozycji członków, błąd kolejki) nie może wywrócić
     * rozliczonego lidera — ponowienie zadania dałoby drugie „done” bez force albo drugie wywołanie modelu z force.
     * Zadanie kończy się bez wyjątku z wpisem w dzienniku; przekazanie dokończy harmonogram.
     */
    public function test_handover_failure_does_not_throw_from_the_settled_leader_run(): void
    {
        [$batch, $leader, $members] = $this->group(['enrichment_status' => Product::ENRICHMENT_DONE, 'description' => 'Mata testowa z gotowym opisem, pianka PVC.']);
        app()->instance(ModelGroupPlanner::class, new class
        {
            public function membersOf(int $batchId, int $leaderId): array
            {
                throw new RuntimeException('SQLSTATE[40001]: Deadlock found when trying to get lock');
            }
        });
        Log::spy();

        (new EnrichProductJob($leader->id, $batch->id))->handle(app(ProductEnrichmentService::class), app(AiSettingsService::class), app(EnrichmentSlots::class));

        $this->assertSame(ProductEnrichmentBatchItem::STATUS_SKIPPED, $this->item($leader)->status);
        foreach ($members as $member) {
            $this->assertSame(ProductEnrichmentBatchItem::STATUS_QUEUED, $this->item($member)->status);
        }
        Queue::assertNothingPushed();
        Log::shouldHaveReceived('warning')->once()->withArgs(static fn (string $message, array $context): bool => $message === 'Model handover after leader run failed'
            && str_contains((string) $context['error'], 'Deadlock'));
    }

    public function test_card_without_model_members_hands_nothing_over(): void
    {
        $card = $this->card('SOLO-1', ['enrichment_status' => Product::ENRICHMENT_DONE, 'description' => 'Karta bez grupy modelu, opis gotowy.']);
        $batch = $this->batch(1);
        $this->seedItem($batch, $card, $card->id, null);

        (new EnrichProductJob($card->id, $batch->id))->handle(app(ProductEnrichmentService::class), app(AiSettingsService::class), app(EnrichmentSlots::class));

        $this->assertSame(ProductEnrichmentBatchItem::STATUS_SKIPPED, $this->item($card)->status);
        Queue::assertNotPushed(ApplyModelDescriptionJob::class);
        Queue::assertNotPushed(PrefetchProductSourcesJob::class);
    }

    public function test_cancelled_batch_hands_nothing_over(): void
    {
        [$batch, $leader] = $this->group(['enrichment_status' => Product::ENRICHMENT_DONE, 'description' => 'Mata testowa z gotowym opisem, pianka PVC.']);
        $batch->markCancelledFlag();

        (new EnrichProductJob($leader->id, $batch->id))->handle(app(ProductEnrichmentService::class), app(AiSettingsService::class), app(EnrichmentSlots::class));

        Queue::assertNotPushed(ApplyModelDescriptionJob::class);
        Queue::assertNotPushed(PrefetchProductSourcesJob::class);
    }

    /**
     * @param  list<Product>  $members
     */
    private function assertModelHandedOver(ProductEnrichmentBatch $batch, Product $leader, array $members, int $versionId): void
    {
        foreach ($members as $member) {
            $this->assertSame(ProductEnrichmentBatchItem::STATUS_QUEUED, $this->item($member)->status);
            $this->assertSame($leader->id, (int) $this->item($member)->model_leader_id);
            $this->assertSame($versionId, (int) $this->item($member)->model_leader_version_id);
        }
        Queue::assertPushed(ApplyModelDescriptionJob::class, 1);
        Queue::assertPushed(ApplyModelDescriptionJob::class, static fn (ApplyModelDescriptionJob $apply): bool => $apply->batchId === $batch->id
            && $apply->leaderId === $leader->id
            && $apply->leaderVersionId === $versionId);
        Queue::assertNotPushed(PrefetchProductSourcesJob::class);
    }

    /** Opublikowana wersja opisu lidera z przebiegu w partii (jak po publishDescription z batch_id). */
    private function version(Product $leader, int $batchId): ProductDescriptionVersion
    {
        return app(DescriptionVersionStore::class)->record($leader, ProductDescriptionVersion::STATUS_PUBLISHED, ProductDescriptionVersion::ORIGIN_ENRICHMENT, [
            'description' => (string) $leader->description,
            'primary_source_url' => 'https://example.com/mata-testowa',
            'identity_verdict' => 'hard',
            'batch_id' => $batchId,
        ]);
    }

    /**
     * @param  list<Product>  $members
     */
    private function assertNextLeaderPrefetched(ProductEnrichmentBatch $batch, array $members, bool $force): void
    {
        // bez adresu ręcznego, z bazowymi SKU i bez twardej bazy liderem zostaje karta o najniższym id
        $next = $members[0];
        Queue::assertNotPushed(ApplyModelDescriptionJob::class);
        Queue::assertPushed(PrefetchProductSourcesJob::class, 1);
        Queue::assertPushed(PrefetchProductSourcesJob::class, static fn (PrefetchProductSourcesJob $prefetch): bool => $prefetch->productId === $next->id
            && $prefetch->batchId === $batch->id
            && $prefetch->force === $force);
        foreach ($members as $member) {
            $this->assertSame($next->id, (int) $this->item($member)->model_leader_id);
            $this->assertSame(ProductEnrichmentBatchItem::STATUS_QUEUED, $this->item($member)->status);
        }
    }

    /**
     * Lider w kolejce i dwóch członków z pozycjami partii „queued” wskazującymi lidera (jak po enqueueProductIds z kluczem).
     *
     * @param  array<string, mixed>  $leaderAttributes
     * @return array{0: ProductEnrichmentBatch, 1: Product, 2: list<Product>}
     */
    private function group(array $leaderAttributes = []): array
    {
        $leader = $this->card('LEAD-1', ['enrichment_status' => Product::ENRICHMENT_QUEUED, ...$leaderAttributes]);
        $members = [$this->card('LEAD-2'), $this->card('LEAD-3')];
        $batch = $this->batch(3);
        $this->seedItem($batch, $leader, $leader->id, self::MODEL_KEY);
        foreach ($members as $member) {
            $this->seedItem($batch, $member, $leader->id, self::MODEL_KEY);
        }

        return [$batch, $leader, $members];
    }

    /**
     * Zadanie z podstawioną wersją przebiegu (szew versionOfRun) — po stronie serwisu wersję przebiegu oddaje
     * lastRunVersion() (część A); tu liczy się przekazanie modelu, nie księgowość wersji.
     *
     * @param  callable(): ?ProductDescriptionVersion  $version
     */
    private function job(Product $leader, ProductEnrichmentBatch $batch, bool $force, callable $version): EnrichProductJob
    {
        return new class($leader->id, $batch->id, $force, $version) extends EnrichProductJob
        {
            /** @param  callable(): ?ProductDescriptionVersion  $version */
            public function __construct(int $productId, int $batchId, bool $force, private $version)
            {
                parent::__construct($productId, $batchId, $force);
            }

            protected function versionOfRun(ProductEnrichmentService $enrichment): ?ProductDescriptionVersion
            {
                return ($this->version)();
            }
        };
    }

    /** Opis lidera z pamięci SKU — przebieg bez wyszukiwarki i modelu językowego (jak test_process_batch_item_uses_sku_cache). */
    private function skuCacheFor(Product $leader): void
    {
        ProductEnrichmentCache::query()->create([
            'manufacturer' => mb_strtolower((string) $leader->manufacturer),
            'sku' => mb_strtolower((string) $leader->sku),
            'description' => 'Opis z pamięci SKU: mata testowa antyzmęczeniowa do pomieszczeń suchych, pianka PVC.',
            'enrichment_payload' => ['features' => ['pianka PVC'], 'confidence' => 0.8, 'from_cache' => false],
            'image_urls' => [],
            'source_urls' => ['https://example.com/mata-testowa'],
        ]);
    }

    private function batch(int $total): ProductEnrichmentBatch
    {
        return ProductEnrichmentBatch::query()->create([
            'scope' => ProductEnrichmentBatch::SCOPE_PRODUCTS, 'scope_id' => 0, 'total' => $total, 'done' => 0, 'failed' => 0,
            'status' => ProductEnrichmentBatch::STATUS_QUEUED, 'force' => false,
        ]);
    }

    private function seedItem(ProductEnrichmentBatch $batch, Product $product, int $leaderId, ?string $modelKey): void
    {
        ProductEnrichmentBatchItem::query()->create([
            'batch_id' => $batch->id, 'product_id' => $product->id, 'sku' => $product->sku, 'name' => $product->name,
            'status' => ProductEnrichmentBatchItem::STATUS_QUEUED, 'previous_status' => Product::ENRICHMENT_NONE,
            'model_key' => $modelKey, 'model_leader_id' => $leaderId,
        ]);
    }

    private function item(Product $product): ProductEnrichmentBatchItem
    {
        return ProductEnrichmentBatchItem::query()->where('product_id', $product->id)->firstOrFail();
    }

    /** @param  array<string, mixed>  $attributes */
    private function card(string $sku, array $attributes = []): Product
    {
        return Product::query()->create([
            'sku' => $sku,
            'name' => 'Mata testowa '.$sku,
            'manufacturer' => 'Testowy',
            'catalog_price_net' => 10,
            'purchase_price' => 8,
            'stock' => 0,
            'enrichment_status' => Product::ENRICHMENT_QUEUED,
            ...$attributes,
        ]);
    }
}
