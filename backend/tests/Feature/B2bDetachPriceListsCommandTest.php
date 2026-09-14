<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\B2bAccount;
use App\Models\B2bProductLink;
use App\Models\PriceList;
use App\Models\Product;
use App\Models\ProductPriceHistory;
use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

final class B2bDetachPriceListsCommandTest extends TestCase
{
    use RefreshDatabase;

    private Product $product;

    private B2bAccount $account;

    private PriceList $b2bList;

    private PriceList $running;

    private PriceList $fileList;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolesAndPermissionsSeeder::class);
        Queue::fake();

        $user = User::factory()->withRole('admin')->create();
        $this->product = Product::query()->create([
            'sku' => 'N/IF005',
            'name' => 'Alarm pożarowy',
            'manufacturer' => 'Anro',
            'catalog_price_net' => 40.80,
            'purchase_price' => 36.72,
            'discount_percent' => 10,
            'currency' => 'PLN',
        ]);
        $this->product->images()->create(['path' => 'products/x.png', 'is_primary' => true, 'sort_order' => 0]);

        $this->b2bList = PriceList::query()->create([
            'manufacturer' => 'Anro', 'version' => 'B2B 2026-09-14 18:25', 'original_filename' => 'b2b.anro.net.pl (API)',
            'imported_by' => $user->id, 'rows_total' => 1, 'products_created' => 1, 'product_ids' => [$this->product->id],
        ]);
        // przebieg starym kodem jeszcze trwa — wpis bez podsumowania
        $this->running = PriceList::query()->create([
            'manufacturer' => 'Anro', 'version' => 'B2B 2026-09-14 20:00', 'original_filename' => 'b2b.anro.net.pl (API)',
            'imported_by' => $user->id,
        ]);
        $this->fileList = PriceList::query()->create([
            'manufacturer' => 'Anro', 'version' => '2026', 'original_filename' => 'anro.xlsx', 'imported_by' => $user->id,
            'rows_total' => 1, 'product_ids' => [$this->product->id],
        ]);

        $this->account = B2bAccount::query()->create([
            'username' => 'jan', 'password' => 'sekret', 'sites' => ['b2b.anro.net.pl'], 'connector' => 'anro',
            'created_by' => $user->id, 'updated_by' => $user->id,
        ]);
        $this->account->forceFill(['last_price_list_id' => $this->b2bList->id])->save();
        B2bProductLink::query()->create(['b2b_account_id' => $this->account->id, 'remote_id' => '13507', 'product_id' => $this->product->id, 'remote_sku' => 'N/IF005']);

        $this->history($this->b2bList, 'b2b_api');
        $this->history($this->b2bList, 'b2b_api');
        $this->history($this->fileList, 'price_list_import');
    }

    public function test_dry_run_changes_nothing(): void
    {
        $this->artisan('b2b:detach-price-lists', ['--dry-run' => true])
            ->expectsOutputToContain('#'.$this->b2bList->id.' B2B 2026-09-14 18:25 · produkty w cenniku: 1 · wpisy historii cen do odpięcia: 2')
            ->assertSuccessful();

        $this->assertSame(3, PriceList::query()->count());
        $this->assertSame(2, ProductPriceHistory::query()->where('price_list_id', $this->b2bList->id)->where('source', 'b2b_api')->count());
        $this->assertSame($this->b2bList->id, $this->account->fresh()->last_price_list_id);
    }

    public function test_detaches_history_and_deletes_only_finished_b2b_lists(): void
    {
        $this->artisan('b2b:detach-price-lists')->assertSuccessful();

        $this->assertNull(PriceList::query()->find($this->b2bList->id));
        $this->assertNotNull(PriceList::query()->find($this->running->id));
        $this->assertNotNull(PriceList::query()->find($this->fileList->id));

        $this->assertTrue(Product::query()->whereKey($this->product->id)->exists());
        $this->assertSame(1, $this->product->images()->count());
        $this->assertSame(1, B2bProductLink::query()->where('product_id', $this->product->id)->count());
        $this->assertSame(3, ProductPriceHistory::query()->where('product_id', $this->product->id)->count());
        $this->assertSame(2, ProductPriceHistory::query()->whereNull('price_list_id')->where('source', 'b2b:anro')->count());
        $this->assertSame(1, ProductPriceHistory::query()->where('price_list_id', $this->fileList->id)->where('source', 'price_list_import')->count());
        $this->assertNull($this->account->fresh()->last_price_list_id);
    }

    private function history(PriceList $list, string $source): void
    {
        ProductPriceHistory::query()->create([
            'product_id' => $this->product->id,
            'price_list_id' => $list->id,
            'catalog_price_net' => 40.80,
            'purchase_price' => 36.72,
            'source' => $source,
        ]);
    }
}
