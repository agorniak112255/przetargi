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
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
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

    public function test_apply_gives_a_key_to_manufacturers_that_have_a_single_entry(): void
    {
        $lonely = PriceList::query()->create(['manufacturer' => 'CANIS', 'version' => 'v1']);
        $this->assertNull($lonely->manufacturer_key);

        $this->artisan('price-lists:collapse --apply')->assertSuccessful();

        // bez klucza kolejny import założyłby obok tego wpisu drugi, czyli dokładnie to, co zwijamy
        $this->assertSame('canis', $lonely->fresh()->manufacturer_key);
    }

    public function test_preview_reports_entries_without_a_key_even_when_nothing_is_merged(): void
    {
        PriceList::query()->create(['manufacturer' => 'CANIS', 'version' => 'v1']);

        $this->artisan('price-lists:collapse')
            ->expectsOutputToContain('Wpisów bez klucza producenta: 1')
            ->assertSuccessful();

        $this->assertNull(PriceList::query()->sole()->manufacturer_key);
    }

    public function test_price_list_card_returns_its_update_history(): void
    {
        $this->seed(RolesAndPermissionsSeeder::class);
        Sanctum::actingAs(User::factory()->withRole('admin')->create());
        [, $newer] = $this->twoArtraLists();
        $this->artisan('price-lists:collapse --apply')->assertSuccessful();

        $body = $this->getJson('/api/price-lists/'.$newer->id)->assertOk()->json();

        $this->assertCount(2, $body['imports']);
        $this->assertEqualsCanonicalizing(['v1', 'v2'], array_column($body['imports'], 'version'));
        $this->assertSame(2, collect($this->getJson('/api/price-lists')->assertOk()->json())
            ->firstWhere('id', $newer->id)['imports_count']);
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
