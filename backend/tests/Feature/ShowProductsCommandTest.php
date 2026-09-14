<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Product;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

final class ShowProductsCommandTest extends TestCase
{
    use RefreshDatabase;

    public function test_prints_card_details_and_missing_skus_without_changing_anything(): void
    {
        $product = Product::query()->create([
            'sku' => '2136-025-808-00',
            'name' => 'Sandały bezpieczne S1 P ESD',
            'manufacturer' => 'Canis',
            'category' => 'Obuwie',
            'description' => 'Sandały bezpieczne z podnoskiem i wkładką antyprzebiciową, ESD, podeszwa odporna na oleje.',
            'norms' => 'EN ISO 20345 S1 P',
            'catalog_price_net' => 120,
            'purchase_price' => 80,
            'currency' => 'PLN',
            'stock' => 1,
            'enrichment_status' => Product::ENRICHMENT_DONE,
            'enriched_at' => now(),
        ]);
        $updatedAt = (string) $product->fresh()->updated_at;

        $this->artisan('products:show', ['sku' => ['2136-025-808-00', 'NIE-MA']])
            ->expectsOutputToContain('== 2136-025-808-00 · Sandały bezpieczne S1 P ESD · Canis')
            ->expectsOutputToContain('opis pobrany:')
            ->expectsOutputToContain('normy: EN ISO 20345 S1 P')
            ->expectsOutputToContain('opis (')
            ->expectsOutputToContain('NIE-MA: brak karty w katalogu')
            ->assertSuccessful();

        $this->assertSame($updatedAt, (string) $product->fresh()->updated_at, 'polecenie tylko czyta');
    }
}
