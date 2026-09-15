<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Product;
use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Laravel\Sanctum\Sanctum;
use Tests\Support\Opisowy15Fixture;
use Tests\TestCase;

/**
 * Porównanie parametrów wymagania z kartą w oknie „Weryfikacja karty”. Same reguły — żadne żądanie
 * do modelu nie może wyjść.
 */
final class ProductRequirementCheckApiTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolesAndPermissionsSeeder::class);
        Http::fake();
    }

    protected function tearDown(): void
    {
        Http::assertNothingSent();
        parent::tearDown();
    }

    public function test_returns_groups_with_rows_in_contract_shape(): void
    {
        Sanctum::actingAs(User::factory()->withRole('admin')->create());
        $product = $this->seededCard('11202000');

        $groups = $this->postJson("/api/products/{$product->id}/requirement-check", ['query' => Opisowy15Fixture::requirement(1)])
            ->assertOk()
            ->assertJsonStructure(['groups'])
            ->json('groups');

        foreach ($groups as $group) {
            $this->assertContains($group['key'], ['dimensions', 'levels', 'flags', 'color']);
            $this->assertNotSame([], $group['rows'], 'pusta grupa nie trafia do odpowiedzi');
            foreach ($group['rows'] as $row) {
                $this->assertSame(['key', 'label', 'required', 'card', 'status', 'note', 'positions', 'gate'], array_keys($row));
                $this->assertContains($row['status'], ['ok', 'fail', 'missing', 'unclear']);
                foreach ($row['card'] as $finding) {
                    $this->assertContains($finding['source'], ['name', 'norms', 'specs', 'features', 'payload_norms', 'materials', 'description']);
                }
            }
        }
    }

    public function test_query_is_validated(): void
    {
        Sanctum::actingAs(User::factory()->withRole('admin')->create());
        $product = $this->seededCard('11202000');

        $this->postJson("/api/products/{$product->id}/requirement-check", [])->assertStatus(422);
        $this->postJson("/api/products/{$product->id}/requirement-check", ['query' => 'ab'])->assertStatus(422);
    }

    public function test_user_without_product_view_permission_is_rejected(): void
    {
        Sanctum::actingAs(User::factory()->create());
        $product = $this->seededCard('11202000');

        $this->postJson("/api/products/{$product->id}/requirement-check", ['query' => Opisowy15Fixture::requirement(1)])
            ->assertStatus(403);
    }

    /** Karta zapisana w bazie — Opisowy15Fixture::product() buduje ją tylko w pamięci, a trasa wiąże {product} z bazą. */
    private function seededCard(string $sku): Product
    {
        return Product::query()->findOrFail(Opisowy15Fixture::seed([$sku])[$sku]);
    }
}
