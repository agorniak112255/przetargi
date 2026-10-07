<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Jobs\ReindexProductEmbeddingJob;
use App\Models\PriceList;
use App\Models\Product;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

/**
 * products:restore-norms-column — kolumna norm skasowana przez ponowny import cennika (B1, plan 07.10.2026) wraca
 * z enrichment_payload.norms regułą zapisu opisu (8 pierwszych, po przecinku). Podgląd nic nie zapisuje; --apply
 * robi kopię i zapisuje przez model (blob i reindeks wektora); niepusta kolumna i karta bez listy norm zostają.
 */
final class RestoreNormsColumnCommandTest extends TestCase
{
    use RefreshDatabase;

    private Product $lost;

    private Product $blank;

    private Product $kept;

    private Product $noPayload;

    private Product $noNorms;

    private Product $other;

    private string $backup;

    protected function setUp(): void
    {
        parent::setUp();
        Queue::fake();
        // reindeks idzie do kolejki tylko przy włączonych wektorach (ReindexProductEmbeddingJob::dispatch)
        config(['ai.vector_enabled' => true, 'ai.qdrant_url' => 'http://qdrant.test:6333']);
        $this->backup = storage_path('app/repair-backups/test-restore-norms-column.json');
        @unlink($this->backup);

        $this->lost = $this->card('R-100', 'Ansell', null, ['norms' => ['EN 388:2016', 'EN ISO 21420', ' ', 5]]);
        $this->blank = $this->card('R-200', 'Ansell', '', ['norms' => ['EN 1', 'EN 2', 'EN 3', 'EN 4', 'EN 5', 'EN 6', 'EN 7', 'EN 8', 'EN 9']]);
        // normy z opisu B2B albo z Presty — niepustej kolumny polecenie nie rusza
        $this->kept = $this->card('R-300', 'Ansell', 'EN 166', ['norms' => ['EN 170']]);
        $this->noPayload = $this->card('R-400', 'Ansell', null, null);
        $this->noNorms = $this->card('R-500', 'Ansell', null, ['features' => ['Powłoka nitrylowa'], 'norms' => []]);
        $this->other = $this->card('K-100', 'Canis', null, ['norms' => ['EN 420']]);
        // zlecenia reindeksu z zakładania kart nie liczą się do przebiegu polecenia
        Queue::fake();
    }

    protected function tearDown(): void
    {
        @unlink($this->backup);
        parent::tearDown();
    }

    public function test_preview_reports_cards_and_writes_nothing(): void
    {
        $before = Product::query()->orderBy('id')->pluck('norms', 'id')->all();

        $this->artisan('products:restore-norms-column', ['--backup' => $this->backup])
            ->expectsOutputToContain('do odtworzenia z listy norm opisu: 3')
            ->expectsOutputToContain('R-100: „” → „EN 388:2016, EN ISO 21420”')
            ->expectsOutputToContain('Podgląd')
            ->assertSuccessful();

        $this->assertSame($before, Product::query()->orderBy('id')->pluck('norms', 'id')->all());
        $this->assertFileDoesNotExist($this->backup);
        Queue::assertNotPushed(ReindexProductEmbeddingJob::class);
    }

    public function test_apply_restores_empty_columns_with_backup_and_restore(): void
    {
        $blobBefore = (string) $this->lost->fresh()->search_blob;

        $this->artisan('products:restore-norms-column', ['--manufacturer' => 'ANSELL', '--apply' => true, '--backup' => $this->backup])
            ->expectsOutputToContain('Odtworzono kolumnę norm 2 kart')
            ->assertSuccessful();

        $lost = $this->lost->fresh();
        $this->assertSame('EN 388:2016, EN ISO 21420', $lost->norms);
        $this->assertSame('EN 1, EN 2, EN 3, EN 4, EN 5, EN 6, EN 7, EN 8', $this->blank->fresh()->norms);
        $this->assertSame('EN 166', $this->kept->fresh()->norms);
        $this->assertNull($this->noPayload->fresh()->norms);
        $this->assertNull($this->noNorms->fresh()->norms);
        // --manufacturer zawęża przebieg
        $this->assertNull($this->other->fresh()->norms);
        // zapis przez model: indeks tekstowy przeliczony, wektor do odświeżenia
        $this->assertNotSame($blobBefore, (string) $lost->search_blob);
        Queue::assertPushed(ReindexProductEmbeddingJob::class, fn (ReindexProductEmbeddingJob $job): bool => $job->productId === (int) $this->lost->id);
        Queue::assertNotPushed(ReindexProductEmbeddingJob::class, fn (ReindexProductEmbeddingJob $job): bool => $job->productId === (int) $this->kept->id);
        $this->assertFileExists($this->backup);

        $this->artisan('products:restore-norms-column', ['--restore' => $this->backup])
            ->expectsOutputToContain('Przywrócono 2 kart')
            ->assertSuccessful();

        $this->assertNull($this->lost->fresh()->norms);
        $this->assertSame('', $this->blank->fresh()->norms);
        $this->assertSame('EN 166', $this->kept->fresh()->norms);
    }

    public function test_price_list_and_limit_narrow_the_run(): void
    {
        $list = PriceList::query()->create([
            'manufacturer' => 'Canis', 'manufacturer_key' => PriceList::manufacturerKey('Canis'), 'version' => '2026-10',
            'rows_total' => 0, 'products_created' => 0, 'products_updated' => 0, 'rows_skipped' => 0,
            'product_ids' => [(int) $this->other->id, (int) $this->kept->id],
        ]);

        $this->artisan('products:restore-norms-column', ['--price-list' => (string) $list->id, '--apply' => true, '--backup' => $this->backup])
            ->expectsOutputToContain('Odtworzono kolumnę norm 1 kart')
            ->assertSuccessful();

        $this->assertSame('EN 420', $this->other->fresh()->norms);
        $this->assertNull($this->lost->fresh()->norms);

        $this->artisan('products:restore-norms-column', ['--limit' => '1', '--apply' => true, '--backup' => $this->backup])
            ->expectsOutputToContain('Odtworzono kolumnę norm 1 kart')
            ->assertSuccessful();

        $this->assertSame('EN 388:2016, EN ISO 21420', $this->lost->fresh()->norms);
        $this->assertSame('', $this->blank->fresh()->norms);
    }

    public function test_missing_price_list_fails_without_changes(): void
    {
        $this->artisan('products:restore-norms-column', ['--price-list' => '999', '--apply' => true, '--backup' => $this->backup])
            ->expectsOutputToContain('Nie ma cennika numer 999')
            ->assertFailed();

        $this->assertNull($this->lost->fresh()->norms);
        $this->assertFileDoesNotExist($this->backup);
    }

    /**
     * @param  array<string, mixed>|null  $payload
     */
    private function card(string $sku, string $manufacturer, ?string $norms, ?array $payload): Product
    {
        return Product::query()->create([
            'sku' => $sku, 'name' => 'Rękawice ochronne '.$sku, 'manufacturer' => $manufacturer,
            'catalog_price_net' => 10.0, 'purchase_price' => 8.0, 'currency' => 'PLN',
            'description' => $payload !== null ? 'Opis wyrobu ze strony producenta.' : null,
            'norms' => $norms,
            'enrichment_payload' => $payload,
        ]);
    }
}
