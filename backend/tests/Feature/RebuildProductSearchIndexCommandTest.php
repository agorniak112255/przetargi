<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Product;
use App\Support\PpeAssortment;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * `products:rebuild-search-index` przelicza blob i rodzinę PPE. Rodzina liczy się z reguł, więc zmiana reguły (rękaw → rękawice,
 * przetarg 1 poz. 1) zmienia rodzinę przy tym samym blobie — przebudowa nie może jej pominąć po samym hashu.
 */
final class RebuildProductSearchIndexCommandTest extends TestCase
{
    use RefreshDatabase;

    public function test_recomputes_family_changed_by_rule_even_when_blob_is_unchanged_and_lists_it(): void
    {
        $sleeve = Product::query()->create([
            'sku' => '11202000',
            'name' => 'HyFlex 11202 SIZE 19\'\'/47,5 cm',
            'manufacturer' => 'Ansell',
            'norms' => 'EN 407: poziom 1 (ochrona termiczna do 100°C), EN ISO 13997: odporność na przecięcie poziom C',
            'description' => 'Rękaw ochronny Ansell HyFlex 11-202 o wysokiej widoczności chroni przedramię przed przecięciem, zapięcie na rzep.',
            'catalog_price_net' => 50,
            'purchase_price' => 46.6,
            'stock' => 1,
        ]);
        $jacket = Product::query()->create([
            'sku' => 'K-1',
            'name' => 'Kurtka robocza',
            'manufacturer' => 'PROS',
            'description' => 'Kurtka robocza z długim rękawem.',
            'catalog_price_net' => 90,
            'purchase_price' => 70,
            'stock' => 1,
        ]);
        $this->assertSame(PpeAssortment::FAMILY_GLOVES, $sleeve->fresh()->ppe_family, 'zapis karty liczy rodzinę nową regułą');
        $updatedAt = (string) $sleeve->fresh()->updated_at;
        // stan sprzed reguły: ten sam blob, rodzina pusta
        DB::table('products')->where('id', $sleeve->id)->update(['ppe_family' => null]);

        $this->artisan('products:rebuild-search-index')
            ->expectsOutputToContain('Przeliczono 1 z 2 kart.')
            ->expectsOutputToContain('Karty ze zmienioną rodziną: 1')
            ->expectsOutputToContain('11202000')
            ->assertSuccessful();

        $this->assertSame(PpeAssortment::FAMILY_GLOVES, $sleeve->fresh()->ppe_family);
        $this->assertSame(PpeAssortment::FAMILY_APPAREL, $jacket->fresh()->ppe_family);
        $this->assertSame($updatedAt, (string) $sleeve->fresh()->updated_at, 'przebudowa indeksu nie zmienia daty karty');
    }
}
