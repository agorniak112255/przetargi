<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\B2bAccount;
use App\Models\Product;
use App\Models\ProductSourcePrice;
use App\Models\ProductVariant;
use App\Models\User;
use App\Services\Pricing\SourcePriceComparison;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Karta po ręcznym połączeniu ma wiersze rozmiarów kilku kont (producent i dystrybutor, 28.09.2026): każdy wiersz
 * niesie swoje źródło i etykietę konta; „od–do” liczone jak dotąd ze wszystkich aktywnych wierszy.
 */
final class ProductVariantItemSourcesTest extends TestCase
{
    use RefreshDatabase;

    public function test_size_rows_of_two_accounts_carry_their_source_and_label(): void
    {
        $this->seed(RolesAndPermissionsSeeder::class);
        $admin = User::factory()->withRole('admin')->create();
        Sanctum::actingAs($admin);
        $mascot = $this->account($admin, 'mascot-konto', 'mascot');
        $p4s = $this->account($admin, 'p4s-konto', 'p4s');
        $product = Product::query()->create([
            'sku' => '20079-230', 'name' => 'Kurtka Mascot 20079-230', 'manufacturer' => 'MASCOT',
            'catalog_price_net' => 120, 'purchase_price' => 89.90, 'stock' => 1,
        ]);
        $this->sizeRow($product, $mascot, 'm-s', 'S', 89.90, 0);
        $this->sizeRow($product, $mascot, 'm-xl', 'XL', 99.90, 1);
        $this->sizeRow($product, $p4s, 'p-s', 'S', 95.00, 0);
        $this->sizeRow($product, $p4s, 'p-3xl', '3XL', 109.00, 2);
        $comparison = app(SourcePriceComparison::class);
        $mascotLabel = $comparison->accountLabel($mascot);
        $p4sLabel = $comparison->accountLabel($p4s);
        $this->assertNotSame($mascotLabel, $p4sLabel);

        $response = $this->getJson('/api/products/'.$product->id)
            ->assertOk()
            ->assertJsonPath('variants.kind', 'size')
            ->assertJsonPath('variants.count', 4)
            ->assertJsonPath('variants.min_price', '89.90')
            ->assertJsonPath('variants.max_price', '109.00')
            ->assertJsonPath('variants.source_label', $mascotLabel.', '.$p4sLabel);

        $items = collect($response->json('variants.items'))
            ->map(static fn (array $item): array => [$item['remote_id'], $item['source'], $item['source_label']])
            ->sortBy(0)
            ->values()
            ->all();
        $this->assertSame([
            ['m-s', ProductSourcePrice::b2bKey((int) $mascot->id), $mascotLabel],
            ['m-xl', ProductSourcePrice::b2bKey((int) $mascot->id), $mascotLabel],
            ['p-3xl', ProductSourcePrice::b2bKey((int) $p4s->id), $p4sLabel],
            ['p-s', ProductSourcePrice::b2bKey((int) $p4s->id), $p4sLabel],
        ], $items);
    }

    private function account(User $admin, string $username, string $connector): B2bAccount
    {
        return B2bAccount::query()->create([
            'username' => $username, 'password' => 'sekret', 'sites' => [$connector.'.example.test'], 'connector' => $connector,
            'created_by' => $admin->id, 'updated_by' => $admin->id,
        ]);
    }

    private function sizeRow(Product $product, B2bAccount $account, string $remoteId, string $label, float $price, int $sortOrder): void
    {
        ProductVariant::query()->create([
            'product_id' => $product->id,
            'kind' => ProductVariant::KIND_SIZE,
            'b2b_account_id' => $account->id,
            'source' => ProductSourcePrice::b2bKey((int) $account->id),
            'remote_id' => $remoteId,
            'sku' => $product->sku.'-'.$label,
            'label' => $label,
            'purchase_price' => $price,
            'currency' => 'PLN',
            'sort_order' => $sortOrder,
            'price_checked_at' => Carbon::parse('2026-09-28 02:00:00'),
            'last_seen_at' => Carbon::parse('2026-09-28 02:00:00'),
        ]);
    }
}
