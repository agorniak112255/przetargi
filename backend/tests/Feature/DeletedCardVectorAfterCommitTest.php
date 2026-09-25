<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\B2bAccount;
use App\Models\B2bProductLink;
use App\Models\PriceList;
use App\Models\PriceListImport;
use App\Models\Product;
use App\Models\User;
use App\Services\PriceListDeletionService;
use App\Services\ProductDeletionService;
use DomainException;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Queue;
use Mockery;
use Tests\Support\FakeQdrant;
use Tests\TestCase;

/**
 * Usunięcie karty (ProductDeletionService) i cennika (PriceListDeletionService: usuń cennik, cofnij aktualizację)
 * kasuje wektory kart w Qdrant dopiero po commit. Wcześniej kasowało je w transakcji: wycofanie (np. zakleszczenie
 * przy dużym kasowaniu) zostawiało karty bez punktu w Qdrant, ale z embedding_hash — zwykły reindeks (bez --force)
 * takiej karty nie odtwarzał i znikała z wyszukiwania wektorowego.
 */
final class DeletedCardVectorAfterCommitTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();
        Queue::fake();
        $this->user = User::factory()->create();
    }

    public function test_product_deletion_deletes_vectors_after_commit_in_one_request(): void
    {
        FakeQdrant::enable();
        $a = $this->card('DEL-A');
        $b = $this->card('DEL-B');

        DB::transaction(function () use ($a, $b): void {
            app(ProductDeletionService::class)->deleteMany([$a->id, $b->id], $this->user);
            $this->assertNull($a->fresh());
            $this->assertSame([], FakeQdrant::deletedIds());
        });

        $this->assertSame([(int) $a->id, (int) $b->id], FakeQdrant::deletedIds());
        Http::assertSentCount(1);
    }

    public function test_failed_product_deletion_keeps_cards_and_their_vectors(): void
    {
        FakeQdrant::enable();
        $card = $this->card('DEL-R');
        $this->refuseDelete('products');

        try {
            app(ProductDeletionService::class)->deleteMany([$card->id], $this->user);
            $this->fail('Oczekiwano błędu bazy przy usuwaniu karty.');
        } catch (QueryException) {
        }

        $this->assertNotNull($card->fresh()?->embedding_hash);
        $this->assertSame([], FakeQdrant::deletedIds());
    }

    public function test_product_deletion_rolled_back_by_caller_keeps_cards_and_their_vectors(): void
    {
        FakeQdrant::enable();
        $card = $this->card('DEL-O');

        try {
            DB::transaction(function () use ($card): void {
                app(ProductDeletionService::class)->deleteMany([$card->id], $this->user);
                throw new DomainException('Wywołujący wycofuje całą operację.');
            });
            $this->fail('Oczekiwano wycofania.');
        } catch (DomainException) {
        }

        $this->assertNotNull($card->fresh()?->embedding_hash);
        $this->assertSame([], FakeQdrant::deletedIds());
    }

    public function test_qdrant_error_is_logged_and_product_deletion_stays(): void
    {
        FakeQdrant::enable(500);
        Log::spy();
        $card = $this->card('DEL-Q');

        $result = app(ProductDeletionService::class)->deleteMany([$card->id], $this->user);

        $this->assertSame(1, $result['deleted']);
        $this->assertNull($card->fresh());
        $this->assertSame([(int) $card->id], FakeQdrant::deletedIds());
        Log::shouldHaveReceived('warning')
            ->with('Product embeddings delete failed', Mockery::on(static fn (array $context): bool => $context['product_ids'] === [(int) $card->id]))
            ->once();
    }

    public function test_price_list_deletion_deletes_vectors_of_deleted_cards_only_after_commit(): void
    {
        FakeQdrant::enable();
        $a = $this->card('PL-A');
        $b = $this->card('PL-B');
        // karta z powiązaniem B2B zostaje razem z wektorem
        $linked = $this->card('PL-B2B');
        $account = B2bAccount::query()->create(['username' => 'anro', 'password' => 'x', 'sites' => ['b2b.anro.pl'], 'connector' => 'anro']);
        B2bProductLink::query()->create(['b2b_account_id' => $account->id, 'remote_id' => 'PL-B2B', 'product_id' => $linked->id]);
        $list = $this->priceList([$a->id, $b->id, $linked->id]);

        DB::transaction(function () use ($list, $a): void {
            $meta = app(PriceListDeletionService::class)->delete($list, $this->user);
            $this->assertSame(2, $meta['products_deleted']);
            $this->assertNull($a->fresh());
            $this->assertSame([], FakeQdrant::deletedIds());
        });

        $this->assertSame([(int) $a->id, (int) $b->id], FakeQdrant::deletedIds());
        $this->assertNotNull($linked->fresh());
    }

    public function test_failed_price_list_deletion_keeps_cards_and_their_vectors(): void
    {
        FakeQdrant::enable();
        $card = $this->card('PL-R');
        $list = $this->priceList([$card->id]);
        // pada ostatni krok — karty są już usunięte, a kasowanie wektorów zarejestrowane
        $this->refuseDelete('price_lists');

        try {
            app(PriceListDeletionService::class)->delete($list, $this->user);
            $this->fail('Oczekiwano błędu bazy przy usuwaniu wpisu cennika.');
        } catch (QueryException) {
        }

        $this->assertNotNull($card->fresh()?->embedding_hash);
        $this->assertNotNull($list->fresh());
        $this->assertSame([], FakeQdrant::deletedIds());
    }

    public function test_undo_import_deletes_vectors_after_commit_and_keeps_cards_of_other_imports(): void
    {
        FakeQdrant::enable();
        $only = $this->card('UNDO-A');
        $shared = $this->card('UNDO-B');
        $list = $this->priceList([$only->id, $shared->id]);
        $undone = $this->import($list, [$only->id, $shared->id]);
        $this->import($list, [$shared->id]);

        DB::transaction(function () use ($undone, $only): void {
            $meta = app(PriceListDeletionService::class)->undoImport($undone, $this->user);
            $this->assertSame(1, $meta['products_deleted']);
            $this->assertNull($only->fresh());
            $this->assertSame([], FakeQdrant::deletedIds());
        });

        $this->assertSame([(int) $only->id], FakeQdrant::deletedIds());
        $this->assertNotNull($shared->fresh());
    }

    public function test_failed_undo_import_keeps_cards_and_their_vectors(): void
    {
        FakeQdrant::enable();
        $card = $this->card('UNDO-R');
        $import = $this->import($this->priceList([$card->id]), [$card->id]);
        // pada ostatni krok — karty są już usunięte, a kasowanie wektorów zarejestrowane
        $this->refuseDelete('price_list_imports');

        try {
            app(PriceListDeletionService::class)->undoImport($import, $this->user);
            $this->fail('Oczekiwano błędu bazy przy usuwaniu wpisu aktualizacji.');
        } catch (QueryException) {
        }

        $this->assertNotNull($card->fresh()?->embedding_hash);
        $this->assertNotNull($import->fresh());
        $this->assertSame([], FakeQdrant::deletedIds());
    }

    /** Karta zaindeksowana w Qdrant — ma embedding_hash, więc zwykły reindeks jej nie ruszy. */
    private function card(string $sku): Product
    {
        return Product::query()->create([
            'sku' => $sku,
            'name' => 'Karta '.$sku,
            'manufacturer' => 'ANRO',
            'catalog_price_net' => 10,
            'purchase_price' => 8,
            'embedding_hash' => str_repeat('a', 64),
            'embedding_synced_at' => now(),
        ]);
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
