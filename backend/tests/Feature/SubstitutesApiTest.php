<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Product;
use App\Models\ProductSubstitute;
use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

final class SubstitutesApiTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolesAndPermissionsSeeder::class);
    }

    public function test_handlowiec_can_create_and_filter_substitutes(): void
    {
        Sanctum::actingAs(User::factory()->withRole('handlowiec')->create());

        [$main, $sub, $other] = $this->products();

        $this->postJson('/api/substitutes', [
            'main_product_id' => $main->id,
            'substitute_product_id' => $sub->id,
            'type' => 'preferowany',
            'match_percent' => 91,
            'norms_ok' => true,
            'certs_ok' => false,
            'reason' => 'Test AI',
        ])
            ->assertCreated()
            ->assertJsonPath('approval_status', 'oczekuje')
            ->assertJsonPath('type', 'preferowany');

        ProductSubstitute::query()->create([
            'main_product_id' => $main->id,
            'substitute_product_id' => $other->id,
            'type' => 'tanszy',
            'match_percent' => 70,
            'approval_status' => 'zatwierdzony',
        ]);

        $this->getJson('/api/substitutes?approval_status=oczekuje')
            ->assertOk()
            ->assertJsonCount(1);

        $this->getJson('/api/substitutes?q=MAIN-A&type=preferowany')
            ->assertOk()
            ->assertJsonCount(1);
    }

    public function test_update_resets_approval_status(): void
    {
        $kierownik = User::factory()->withRole('kierownik')->create();
        Sanctum::actingAs($kierownik);

        [$main, $sub] = $this->products();
        $row = ProductSubstitute::query()->create([
            'main_product_id' => $main->id,
            'substitute_product_id' => $sub->id,
            'type' => 'tanszy',
            'match_percent' => 80,
            'approval_status' => 'zatwierdzony',
            'approved_by' => $kierownik->id,
            'reason' => 'Stary',
        ]);

        $this->patchJson("/api/substitutes/{$row->id}", [
            'reason' => 'Nowy powód',
            'match_percent' => 85,
        ])
            ->assertOk()
            ->assertJsonPath('approval_status', 'oczekuje')
            ->assertJsonPath('approved_by', null)
            ->assertJsonPath('reason', 'Nowy powód');
    }

    public function test_dyrektor_cannot_manage_but_can_approve(): void
    {
        Sanctum::actingAs(User::factory()->withRole('dyrektor')->create());

        [$main, $sub] = $this->products();

        $this->postJson('/api/substitutes', [
            'main_product_id' => $main->id,
            'substitute_product_id' => $sub->id,
            'type' => 'premium',
            'match_percent' => 90,
        ])->assertForbidden();

        $row = ProductSubstitute::query()->create([
            'main_product_id' => $main->id,
            'substitute_product_id' => $sub->id,
            'type' => 'premium',
            'match_percent' => 90,
            'approval_status' => 'oczekuje',
        ]);

        $this->patchJson("/api/substitutes/{$row->id}/approve", [
            'approval_status' => 'zatwierdzony',
        ])
            ->assertOk()
            ->assertJsonPath('approval_status', 'zatwierdzony');

        $this->deleteJson("/api/substitutes/{$row->id}")->assertForbidden();
    }

    public function test_unique_pair_and_delete(): void
    {
        Sanctum::actingAs(User::factory()->withRole('handlowiec')->create());

        [$main, $sub] = $this->products();

        $this->postJson('/api/substitutes', [
            'main_product_id' => $main->id,
            'substitute_product_id' => $sub->id,
            'type' => 'awaryjny',
            'match_percent' => 75,
        ])->assertCreated();

        $this->postJson('/api/substitutes', [
            'main_product_id' => $main->id,
            'substitute_product_id' => $sub->id,
            'type' => 'awaryjny',
            'match_percent' => 75,
        ])->assertStatus(422);

        $id = ProductSubstitute::query()->value('id');
        $this->deleteJson("/api/substitutes/{$id}")
            ->assertOk()
            ->assertJsonPath('ok', true);

        $this->assertDatabaseMissing('product_substitutes', ['id' => $id]);
    }

    /**
     * @return array{0: Product, 1: Product, 2: Product}
     */
    private function products(): array
    {
        $main = Product::query()->create([
            'sku' => 'MAIN-A',
            'name' => 'Produkt główny A',
            'manufacturer' => 'X',
            'catalog_price_net' => 10,
            'purchase_price' => 5,
            'stock' => 1,
        ]);
        $sub = Product::query()->create([
            'sku' => 'SUB-A',
            'name' => 'Zamiennik A',
            'manufacturer' => 'X',
            'catalog_price_net' => 8,
            'purchase_price' => 4,
            'stock' => 1,
        ]);
        $other = Product::query()->create([
            'sku' => 'SUB-B',
            'name' => 'Zamiennik B',
            'manufacturer' => 'Y',
            'catalog_price_net' => 9,
            'purchase_price' => 4,
            'stock' => 1,
        ]);

        return [$main, $sub, $other];
    }
}
