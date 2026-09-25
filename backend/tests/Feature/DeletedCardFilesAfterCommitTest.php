<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\B2bAccount;
use App\Models\B2bProductLink;
use App\Models\PriceList;
use App\Models\PriceListImport;
use App\Models\Product;
use App\Models\ProductDocument;
use App\Models\ProductImage;
use App\Models\User;
use App\Services\PriceListDeletionService;
use App\Services\ProductDeletionService;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Contracts\Filesystem\Filesystem;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;
use Mockery;
use RuntimeException;
use Tests\TestCase;

/**
 * Usunięcie karty (ProductDeletionService) i cennika (PriceListDeletionService: usuń cennik, cofnij aktualizację)
 * kasuje pliki zdjęć i dokumentów kart z dysku dopiero po commit. Wcześniej kasowało je w transakcji: wycofanie
 * (zakleszczenie przy dużym kasowaniu, błąd w dalszym kroku) zostawiało karty z wierszami product_images
 * i product_documents, ale bez plików — zdjęć i kart technicznych nie dało się odtworzyć.
 */
final class DeletedCardFilesAfterCommitTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();
        Queue::fake();
        Storage::fake('public');
        $this->user = User::factory()->create();
    }

    public function test_product_deletion_deletes_image_and_document_files_only_after_commit(): void
    {
        $card = $this->card('F-DEL');
        $files = $this->storedFiles($card);

        DB::transaction(function () use ($card, $files): void {
            app(ProductDeletionService::class)->deleteMany([$card->id], $this->user);
            $this->assertNull($card->fresh());
            Storage::disk('public')->assertExists($files);
        });

        Storage::disk('public')->assertMissing($files);
    }

    public function test_failed_product_deletion_keeps_card_rows_and_files(): void
    {
        $card = $this->card('F-ROLLBACK');
        $files = $this->storedFiles($card);
        $this->refuseDelete('products');

        try {
            app(ProductDeletionService::class)->deleteMany([$card->id], $this->user);
            $this->fail('Oczekiwano błędu bazy przy usuwaniu karty.');
        } catch (QueryException) {
        }

        $this->assertCardWithFiles($card, $files);
    }

    public function test_file_delete_error_does_not_fail_committed_product_deletion(): void
    {
        $this->seed(RolesAndPermissionsSeeder::class);
        Sanctum::actingAs(User::factory()->withRole('admin')->create());
        $card = $this->card('F-DISK');
        $image = 'products/'.$card->id.'/zablokowane.jpg';
        $document = 'products/'.$card->id.'/docs/karta.pdf';
        ProductImage::query()->create(['product_id' => $card->id, 'path' => $image, 'is_primary' => true, 'sort_order' => 0]);
        ProductDocument::query()->create(['product_id' => $card->id, 'path' => $document, 'kind' => ProductDocument::KIND_DATASHEET]);
        // dysk odmawia: wyjątek przy zdjęciu, false (bez 'throw' w konfiguracji dysku) przy dokumencie
        $disk = Mockery::mock(Filesystem::class);
        $disk->shouldReceive('delete')->with($image)->once()->andThrow(new RuntimeException('Permission denied'));
        $disk->shouldReceive('delete')->with($document)->once()->andReturnFalse();
        Storage::set('public', $disk);
        Log::spy();

        $this->deleteJson("/api/products/{$card->id}")
            ->assertOk()
            ->assertJsonPath('deleted', 1);

        $this->assertNull($card->fresh());
        Log::shouldHaveReceived('warning')
            ->with('Product files delete failed', Mockery::on(static fn (array $context): bool => $context['failed'] === 2
                && $context['paths'] === [$image, $document]))
            ->once();
    }

    public function test_price_list_deletion_deletes_files_of_deleted_cards_only_after_commit(): void
    {
        $deleted = $this->card('PL-DEL');
        $files = $this->storedFiles($deleted);
        // karta z powiązaniem B2B zostaje razem ze swoimi plikami
        $linked = $this->card('PL-B2B');
        $linkedFiles = $this->storedFiles($linked);
        $account = B2bAccount::query()->create(['username' => 'anro', 'password' => 'x', 'sites' => ['b2b.anro.pl'], 'connector' => 'anro']);
        B2bProductLink::query()->create(['b2b_account_id' => $account->id, 'remote_id' => 'PL-B2B', 'product_id' => $linked->id]);
        $list = $this->priceList([$deleted->id, $linked->id]);

        DB::transaction(function () use ($list, $deleted, $files): void {
            $meta = app(PriceListDeletionService::class)->delete($list, $this->user);
            $this->assertSame(1, $meta['products_deleted']);
            $this->assertNull($deleted->fresh());
            Storage::disk('public')->assertExists($files);
        });

        Storage::disk('public')->assertMissing($files);
        $this->assertCardWithFiles($linked, $linkedFiles);
    }

    public function test_failed_price_list_deletion_keeps_card_rows_and_files(): void
    {
        $card = $this->card('PL-ROLLBACK');
        $files = $this->storedFiles($card);
        $list = $this->priceList([$card->id]);
        // pada ostatni krok — karty są już usunięte, a kasowanie plików zarejestrowane
        $this->refuseDelete('price_lists');

        try {
            app(PriceListDeletionService::class)->delete($list, $this->user);
            $this->fail('Oczekiwano błędu bazy przy usuwaniu wpisu cennika.');
        } catch (QueryException) {
        }

        $this->assertNotNull($list->fresh());
        $this->assertCardWithFiles($card, $files);
    }

    public function test_undo_import_deletes_files_after_commit_and_keeps_files_of_cards_of_other_imports(): void
    {
        $only = $this->card('UNDO-A');
        $files = $this->storedFiles($only);
        $shared = $this->card('UNDO-B');
        $sharedFiles = $this->storedFiles($shared);
        $list = $this->priceList([$only->id, $shared->id]);
        $undone = $this->import($list, [$only->id, $shared->id]);
        $this->import($list, [$shared->id]);

        DB::transaction(function () use ($undone, $only, $files): void {
            $meta = app(PriceListDeletionService::class)->undoImport($undone, $this->user);
            $this->assertSame(1, $meta['products_deleted']);
            $this->assertNull($only->fresh());
            Storage::disk('public')->assertExists($files);
        });

        Storage::disk('public')->assertMissing($files);
        $this->assertCardWithFiles($shared, $sharedFiles);
    }

    public function test_failed_undo_import_keeps_card_rows_and_files(): void
    {
        $card = $this->card('UNDO-R');
        $files = $this->storedFiles($card);
        $import = $this->import($this->priceList([$card->id]), [$card->id]);
        // pada ostatni krok — karty są już usunięte, a kasowanie plików zarejestrowane
        $this->refuseDelete('price_list_imports');

        try {
            app(PriceListDeletionService::class)->undoImport($import, $this->user);
            $this->fail('Oczekiwano błędu bazy przy usuwaniu wpisu aktualizacji.');
        } catch (QueryException) {
        }

        $this->assertNotNull($import->fresh());
        $this->assertCardWithFiles($card, $files);
    }

    private function card(string $sku): Product
    {
        return Product::query()->create([
            'sku' => $sku,
            'name' => 'Karta '.$sku,
            'manufacturer' => 'ANRO',
            'catalog_price_net' => 10,
            'purchase_price' => 8,
        ]);
    }

    /**
     * Zdjęcie i karta techniczna na dysku, jak po pobraniu (ProductImageDownloader, ProductDocumentDownloader).
     *
     * @return list<string>
     */
    private function storedFiles(Product $card): array
    {
        $image = 'products/'.$card->id.'/zdjecie.jpg';
        $document = 'products/'.$card->id.'/docs/karta.pdf';
        Storage::disk('public')->put($image, 'jpg');
        Storage::disk('public')->put($document, 'pdf');
        ProductImage::query()->create(['product_id' => $card->id, 'path' => $image, 'is_primary' => true, 'sort_order' => 0]);
        ProductDocument::query()->create(['product_id' => $card->id, 'path' => $document, 'kind' => ProductDocument::KIND_DATASHEET]);

        return [$image, $document];
    }

    /**
     * @param  list<string>  $files
     */
    private function assertCardWithFiles(Product $card, array $files): void
    {
        $this->assertNotNull($card->fresh());
        $this->assertSame([$files[0]], ProductImage::query()->where('product_id', $card->id)->pluck('path')->all());
        $this->assertSame([$files[1]], ProductDocument::query()->where('product_id', $card->id)->pluck('path')->all());
        Storage::disk('public')->assertExists($files);
    }

    /** Usuwanie z tabeli pada w bazie (jak zakleszczenie przy dużym kasowaniu) — transakcja usługi się wycofuje. */
    private function refuseDelete(string $table): void
    {
        DB::unprepared("CREATE TRIGGER refuse_{$table}_delete BEFORE DELETE ON {$table} BEGIN SELECT RAISE(ABORT, 'zakleszczenie'); END");
    }

    /**
     * @param  list<int>  $productIds
     */
    private function priceList(array $productIds): PriceList
    {
        return PriceList::query()->create([
            'manufacturer' => 'ANRO',
            'version' => 'v1',
            'original_filename' => 'anro.xlsx',
            'rows_total' => count($productIds),
            'products_created' => count($productIds),
            'products_updated' => 0,
            'rows_skipped' => 0,
            'product_ids' => $productIds,
        ]);
    }

    /**
     * @param  list<int>  $productIds
     */
    private function import(PriceList $list, array $productIds): PriceListImport
    {
        return PriceListImport::query()->create([
            'price_list_id' => $list->id,
            'version' => 'v'.(PriceListImport::query()->count() + 1),
            'product_ids' => $productIds,
        ]);
    }
}
