<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Jobs\ReindexProductEmbeddingJob;
use App\Models\B2bAccount;
use App\Models\B2bProductLink;
use App\Models\Product;
use App\Models\ProductImage;
use App\Models\ProductSourcePrice;
use App\Models\ProductVariant;
use App\Models\ProductVariantPriceHistory;
use App\Models\ProductVisualCheck;
use App\Models\User;
use App\Services\ProductSizeMergeService;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Bus\UniqueLock;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use RuntimeException;
use Tests\Support\FakeQdrant;
use Tests\TestCase;

/**
 * ProductSizeMergeService::mergeDuplicate (ręczne łączenie kart, 28.09.2026): wiersze rozmiarów (kind „size”) z historią
 * cen, działania z wyszukiwarki i oceny zdjęć karty dołączanej przechodzą na kartę, która zostaje — dotąd kaskada
 * kasowała je razem z kartą. Wersje Sign Project (kind „version”) nie przechodzą (wywołujący odmawia takiego scalenia).
 */
final class ProductMergeDuplicateSizeRowsTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    private B2bAccount $mmm;

    private B2bAccount $p4s;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolesAndPermissionsSeeder::class);
        Queue::fake();
        $this->user = User::factory()->withRole('admin')->create();
        $this->mmm = $this->account('mmm', '3m');
        $this->p4s = $this->account('p4s', 'p4s');
    }

    public function test_merge_duplicate_moves_size_rows_with_price_history(): void
    {
        [$keep, $drop] = $this->pair();
        $keepRow = $this->sizeRow($keep, $this->mmm, 'MMM-S', 'S', 30.00);
        $dropS = $this->sizeRow($drop, $this->p4s, 'P4S-S', 'S', 31.20);
        $dropL = $this->sizeRow($drop, $this->p4s, 'P4S-L', 'L', 33.50, removed: true);
        $version = ProductVariant::query()->create([
            'product_id' => $drop->id,
            'kind' => ProductVariant::KIND_VERSION,
            'source' => 'b2b:signproject',
            'remote_id' => 'V1',
            'label' => 'A4 folia',
            'purchase_price' => 10.00,
            'currency' => 'PLN',
        ]);
        $history = ProductVariantPriceHistory::query()->whereIn('product_variant_id', [$dropS->id, $dropL->id])->orderBy('id')->pluck('id')->all();
        $this->assertCount(3, $history);

        app(ProductSizeMergeService::class)->mergeDuplicate($keep, $drop);

        $this->assertNull(Product::query()->find($drop->id));
        // te same wiersze (id), z powodem usunięcia i historią cen — historia idzie za id wiersza
        $this->assertSame(
            [$keepRow->id, $dropS->id, $dropL->id],
            ProductVariant::query()->where('product_id', $keep->id)->orderBy('id')->pluck('id')->all(),
        );
        $this->assertNotNull($dropL->fresh()->removed_at);
        $this->assertSame(['31.20', '33.50'], [(string) $dropS->fresh()->purchase_price, (string) $dropL->fresh()->purchase_price]);
        $this->assertSame(
            $history,
            ProductVariantPriceHistory::query()->whereIn('product_variant_id', [$dropS->id, $dropL->id])->orderBy('id')->pluck('id')->all(),
        );
        // wersja Sign Project nie przechodzi — znika z kartą (scalenia takiej karty wywołujący nie dopuszcza)
        $this->assertNull(ProductVariant::query()->find($version->id));
        // powiązanie i slot dystrybutora na karcie, która zostaje — synchronizacja znajdzie tam swoje wiersze
        $this->assertSame($keep->id, (int) B2bProductLink::query()->where('b2b_account_id', $this->p4s->id)->value('product_id'));
        $this->assertTrue(ProductSourcePrice::query()->where('product_id', $keep->id)->where('source_key', ProductSourcePrice::b2bKey((int) $this->p4s->id))->exists());
    }

    public function test_merge_duplicate_moves_search_actions_without_duplicate_pairs(): void
    {
        [$keep, $drop] = $this->pair();
        $event = DB::table('search_events')->insertGetId(['query' => 'półmaska', 'created_at' => now(), 'updated_at' => now()]);
        $other = DB::table('search_events')->insertGetId(['query' => 'maska', 'created_at' => now(), 'updated_at' => now()]);
        DB::table('search_event_actions')->insert([
            ['search_event_id' => $event, 'product_id' => $keep->id, 'action' => 'open', 'created_at' => now(), 'updated_at' => now()],
            ['search_event_id' => $event, 'product_id' => $drop->id, 'action' => 'open', 'created_at' => now(), 'updated_at' => now()],
            ['search_event_id' => $event, 'product_id' => $drop->id, 'action' => 'pick', 'created_at' => now(), 'updated_at' => now()],
            ['search_event_id' => $other, 'product_id' => $drop->id, 'action' => 'add_to_offer', 'created_at' => now(), 'updated_at' => now()],
        ]);

        app(ProductSizeMergeService::class)->mergeDuplicate($keep, $drop);

        // „open” tego wyszukiwania był już na karcie, która zostaje — duplikat znika z kartą dołączaną
        $this->assertSame(
            [[$event, 'open'], [$event, 'pick'], [$other, 'add_to_offer']],
            DB::table('search_event_actions')->orderBy('search_event_id')->orderBy('id')->get()
                ->map(static fn (object $r): array => [(int) $r->search_event_id, (string) $r->action])->all(),
        );
        $this->assertSame(3, DB::table('search_event_actions')->where('product_id', $keep->id)->count());
    }

    public function test_merge_duplicate_moves_visual_checks_with_their_images(): void
    {
        [$keep, $drop] = $this->pair();
        $keepImage = $this->image($keep, 'sum-keep', 0, true);
        $dropImage = $this->image($drop, 'sum-drop', 0, true);
        // to samo zdjęcie co na karcie, która zostaje (ta sama suma) — moveMedia je kasuje, ocena znika kaskadą
        $dropDuplicate = $this->image($drop, 'sum-keep', 1, false);
        $this->check($keep, $keepImage, ProductVisualCheck::ANSWER_CLOSED);
        $moved = $this->check($drop, $dropImage, ProductVisualCheck::ANSWER_OPEN);
        $this->check($drop, $dropDuplicate, ProductVisualCheck::ANSWER_CLOSED);

        app(ProductSizeMergeService::class)->mergeDuplicate($keep, $drop);

        $this->assertNull(ProductImage::query()->find($dropDuplicate->id));
        $this->assertSame($keep->id, (int) $dropImage->fresh()->product_id);
        $this->assertSame($keep->id, (int) $moved->fresh()->product_id);
        $this->assertSame(
            [[$keepImage->id, 'closed'], [$dropImage->id, 'open']],
            ProductVisualCheck::query()->where('product_id', $keep->id)->orderBy('id')->get()
                ->map(static fn (ProductVisualCheck $c): array => [(int) $c->product_image_id, (string) $c->answer])->all(),
        );
        $this->assertSame(2, ProductVisualCheck::query()->count());
    }

    public function test_reindex_runs_after_the_callers_commit(): void
    {
        FakeQdrant::enable();
        [$keep, $drop] = $this->pair();

        DB::transaction(function () use ($keep, $drop): void {
            app(ProductSizeMergeService::class)->mergeDuplicate($keep, $drop);
            // zlecenie z haka modelu trzyma blokadę ShouldBeUnique — jak po jego przetworzeniu
            app(UniqueLock::class)->release(new ReindexProductEmbeddingJob($keep->id));
            Queue::assertNotPushed(ReindexProductEmbeddingJob::class, static fn (ReindexProductEmbeddingJob $job): bool => $job->force);
        });

        Queue::assertPushed(
            ReindexProductEmbeddingJob::class,
            static fn (ReindexProductEmbeddingJob $job): bool => $job->force && $job->productId === $keep->id,
        );
    }

    public function test_rolled_back_merge_keeps_rows_on_the_duplicate_and_does_not_reindex(): void
    {
        FakeQdrant::enable();
        [$keep, $drop] = $this->pair();
        $row = $this->sizeRow($drop, $this->p4s, 'P4S-S', 'S', 31.20);

        try {
            DB::transaction(function () use ($keep, $drop): void {
                app(ProductSizeMergeService::class)->mergeDuplicate($keep, $drop);
                app(UniqueLock::class)->release(new ReindexProductEmbeddingJob($keep->id));

                throw new RuntimeException('wycofanie');
            });
        } catch (RuntimeException) {
            // oczekiwane
        }

        $this->assertNotNull($drop->fresh());
        $this->assertSame($drop->id, (int) $row->fresh()->product_id);
        Queue::assertNotPushed(ReindexProductEmbeddingJob::class, static fn (ReindexProductEmbeddingJob $job): bool => $job->force);
    }

    /**
     * Karta producenta 3M (powiązanie konta 3M) i karta dystrybutora P4S tego samego wyrobu, każda ze slotem konta.
     *
     * @return array{0: Product, 1: Product}
     */
    private function pair(): array
    {
        $keep = Product::query()->create([
            'sku' => '3M-6100', 'name' => 'Półmaska 3M 6100', 'manufacturer' => '3M',
            'purchase_price' => 30.00, 'catalog_price_net' => 30.00, 'currency' => 'PLN',
        ]);
        $drop = Product::query()->create([
            'sku' => '6100', 'name' => 'Półmaska 3M 6000 rozmiar S', 'manufacturer' => '3M',
            'purchase_price' => 31.20, 'catalog_price_net' => 31.20, 'currency' => 'PLN',
        ]);
        foreach ([[$keep, $this->mmm, 'MMM-6100', 30.00], [$drop, $this->p4s, '1001', 31.20]] as [$card, $account, $remote, $price]) {
            B2bProductLink::query()->create(['b2b_account_id' => $account->id, 'remote_id' => $remote, 'product_id' => $card->id]);
            ProductSourcePrice::query()->create([
                'product_id' => $card->id,
                'source_key' => ProductSourcePrice::b2bKey((int) $account->id),
                'b2b_account_id' => $account->id,
                'catalog_price_net' => $price,
                'purchase_price' => $price,
                'currency' => 'PLN',
                'checked_at' => now(),
            ]);
        }

        return [$keep, $drop];
    }

    private function sizeRow(Product $card, B2bAccount $account, string $remoteId, string $label, float $price, bool $removed = false): ProductVariant
    {
        $row = ProductVariant::query()->create([
            'product_id' => $card->id,
            'kind' => ProductVariant::KIND_SIZE,
            'b2b_account_id' => $account->id,
            'source' => ProductSourcePrice::b2bKey((int) $account->id),
            'remote_id' => $remoteId,
            'sku' => $remoteId,
            'label' => $label,
            'purchase_price' => $price,
            'currency' => 'PLN',
            'price_checked_at' => now(),
            'last_seen_at' => now(),
            'removed_at' => $removed ? now() : null,
        ]);
        ProductVariantPriceHistory::query()->create([
            'product_variant_id' => $row->id,
            'purchase_price' => $price - 1,
            'currency' => 'PLN',
            'source' => 'b2b:test',
        ]);
        if ($removed) {
            ProductVariantPriceHistory::query()->create([
                'product_variant_id' => $row->id,
                'purchase_price' => $price,
                'currency' => 'PLN',
                'source' => 'b2b:test',
            ]);
        }

        return $row;
    }

    private function image(Product $card, string $checksum, int $sortOrder, bool $primary): ProductImage
    {
        return ProductImage::query()->create([
            'product_id' => $card->id,
            'path' => 'remote',
            'source_url' => 'https://img.example.test/'.$checksum.'-'.$card->id.'.png',
            'checksum' => $checksum,
            'sort_order' => $sortOrder,
            'is_primary' => $primary,
        ]);
    }

    private function check(Product $card, ProductImage $image, string $answer): ProductVisualCheck
    {
        return ProductVisualCheck::query()->create([
            'product_id' => $card->id,
            'product_image_id' => $image->id,
            'feature' => ProductVisualCheck::FEATURE_CLOSED_HEEL,
            'answer' => $answer,
            'prompt_version' => 'heel-test',
        ]);
    }

    private function account(string $username, string $connector): B2bAccount
    {
        return B2bAccount::query()->create([
            'username' => $username,
            'password' => 'sekret',
            'sites' => [$connector.'.example.test'],
            'connector' => $connector,
            'created_by' => $this->user->id,
            'updated_by' => $this->user->id,
        ]);
    }
}
