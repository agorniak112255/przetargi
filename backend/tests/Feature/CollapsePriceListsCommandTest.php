<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\B2bAccount;
use App\Models\PriceList;
use App\Models\PriceListImport;
use App\Models\Product;
use App\Models\ProductPriceHistory;
use App\Models\ProductSourcePrice;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Zwinięcie Cenników do jednego wpisu na producenta nie może zgubić ani historii aktualizacji,
 * ani wskaźników, po których rozpoznaje się pochodzenie ceny.
 */
final class CollapsePriceListsCommandTest extends TestCase
{
    use RefreshDatabase;

    public function test_preview_changes_nothing(): void
    {
        [$older, $newer] = $this->twoArtraLists();

        $this->artisan('price-lists:collapse')->assertSuccessful();

        $this->assertSame(2, PriceList::query()->count());
        $this->assertSame(0, PriceListImport::query()->count());
        $this->assertNotNull(PriceList::query()->find($older->id));
        $this->assertNotNull(PriceList::query()->find($newer->id));
    }

    public function test_apply_keeps_one_entry_and_moves_history_to_the_log(): void
    {
        [$older, $newer] = $this->twoArtraLists();

        $this->artisan('price-lists:collapse --apply')->assertSuccessful();

        $this->assertSame(1, PriceList::query()->count());
        $survivor = PriceList::query()->sole();
        $this->assertSame($newer->id, $survivor->id);
        $this->assertSame('artra', $survivor->manufacturer_key);

        $imports = PriceListImport::query()->orderBy('id')->get();
        $this->assertCount(2, $imports);
        $this->assertEqualsCanonicalizing(
            [$older->id, $newer->id],
            $imports->pluck('legacy_price_list_id')->map(static fn ($id): int => (int) $id)->all(),
        );
        $this->assertEqualsCanonicalizing(
            ['v1', 'v2'],
            $imports->pluck('version')->all(),
        );
    }

    public function test_apply_repoints_price_pointers_to_the_surviving_entry(): void
    {
        [$older, $newer] = $this->twoArtraLists();
        $product = Product::query()->create([
            'sku' => 'ARAGON 920 6060 S2',
            'name' => 'ARAGON 920 6060 S2',
            'manufacturer' => 'ARTRA',
            'catalog_price_net' => 100,
            'purchase_price' => 80,
        ]);
        ProductSourcePrice::query()->create([
            'product_id' => $product->id,
            'source_key' => ProductSourcePrice::SOURCE_FILE,
            'price_list_id' => $older->id,
            'catalog_price_net' => 100,
            'purchase_price' => 80,
        ]);
        ProductPriceHistory::query()->create([
            'product_id' => $product->id,
            'price_list_id' => $older->id,
            'catalog_price_net' => 100,
            'purchase_price' => 80,
            'source' => 'price_list_import',
        ]);
        $account = B2bAccount::query()->create([
            'connector' => 'artra',
            'username' => 'artra',
            'sites' => ['artra.pl'],
            'last_price_list_id' => $older->id,
        ]);

        $this->artisan('price-lists:collapse --apply')->assertSuccessful();

        $this->assertSame($newer->id, (int) ProductSourcePrice::query()->sole()->price_list_id);
        $this->assertSame($newer->id, (int) ProductPriceHistory::query()->sole()->price_list_id);
        $this->assertSame($newer->id, (int) $account->fresh()->last_price_list_id);
    }

    public function test_different_manufacturers_are_not_merged(): void
    {
        $this->twoArtraLists();
        PriceList::query()->create(['manufacturer' => 'ARTRA SAFETY', 'version' => 'v1']);

        $this->artisan('price-lists:collapse --apply')->assertSuccessful();

        $this->assertSame(2, PriceList::query()->count());
        // zostaje zapis nazwy z najnowszego wpisu — „Artra” z v2, nie „ARTRA” z v1
        $this->assertEqualsCanonicalizing(
            ['Artra', 'ARTRA SAFETY'],
            PriceList::query()->pluck('manufacturer')->all(),
        );
    }

    public function test_running_twice_does_not_duplicate_the_log(): void
    {
        $this->twoArtraLists();

        $this->artisan('price-lists:collapse --apply')->assertSuccessful();
        $this->artisan('price-lists:collapse --apply')->assertSuccessful();

        $this->assertSame(2, PriceListImport::query()->count());
    }

    /**
     * @return array{0: PriceList, 1: PriceList}
     */
    private function twoArtraLists(): array
    {
        $user = User::factory()->create();

        return [
            PriceList::query()->create([
                'manufacturer' => 'ARTRA',
                'version' => 'v1',
                'imported_by' => $user->id,
                'product_ids' => [1, 2],
            ]),
            PriceList::query()->create([
                'manufacturer' => 'Artra',
                'version' => 'v2',
                'imported_by' => $user->id,
                'product_ids' => [2, 3],
            ]),
        ];
    }
}
