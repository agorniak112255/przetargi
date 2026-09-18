<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Product;
use App\Models\User;
use App\Services\Presta\PrestaDescriptionHtml;
use App\Support\ProductSearchBlob;
use App\Support\RequirementCheck\CardSource;
use App\Support\RequirementCheck\CardSources;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Parametry wpisane ręcznie na karcie: wiersze „parametr: wartość”, których nie rusza żadna
 * automatyka. Wchodzą do indeksu wyszukiwania, do porównania z wymaganiami przetargu (z własną
 * etykietą źródła) i do opisu wysyłanego do sklepu.
 */
final class ProductManualSpecsTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolesAndPermissionsSeeder::class);
    }

    public function test_rows_are_saved_and_an_empty_table_clears_the_field(): void
    {
        Sanctum::actingAs(User::factory()->withRole('handlowiec')->create());
        $product = $this->product();

        $this->patchJson("/api/products/{$product->id}/manual-specs", [
            'specs' => [
                ['label' => 'Waga', 'value' => '1,2 kg'],
                ['label' => 'Temperatura pracy', 'value' => 'od -20 °C'],
                // wiersz bez wartości nie jest parametrem i nie ma po co go trzymać
                ['label' => 'Kolor', 'value' => '   '],
            ],
        ])->assertStatus(422)->assertJsonValidationErrors('specs.2.value');

        $this->patchJson("/api/products/{$product->id}/manual-specs", [
            'specs' => [
                ['label' => 'Waga', 'value' => '1,2 kg'],
                ['label' => 'Temperatura pracy', 'value' => 'od -20 °C'],
            ],
        ])->assertOk()->assertJsonPath('manual_specs.0.label', 'Waga');

        $this->assertSame(
            [['label' => 'Waga', 'value' => '1,2 kg'], ['label' => 'Temperatura pracy', 'value' => 'od -20 °C']],
            $product->refresh()->manual_specs
        );

        $this->patchJson("/api/products/{$product->id}/manual-specs", ['specs' => []])->assertOk();
        $this->assertNull($product->refresh()->manual_specs);
    }

    public function test_too_many_rows_are_refused(): void
    {
        Sanctum::actingAs(User::factory()->withRole('handlowiec')->create());
        $product = $this->product();
        $rows = [];
        for ($i = 0; $i <= Product::MANUAL_SPECS_MAX_ROWS; $i++) {
            $rows[] = ['label' => 'Parametr '.$i, 'value' => 'wartość'];
        }

        $this->patchJson("/api/products/{$product->id}/manual-specs", ['specs' => $rows])
            ->assertStatus(422)
            ->assertJsonValidationErrors('specs');
    }

    public function test_manual_rows_reach_the_search_index(): void
    {
        $product = $this->product(['manual_specs' => [['label' => 'Gramatura', 'value' => '245 g/m2']]]);

        // blob jest znormalizowany (male litery, bez ogonkow) — parametr ma w nim stanac w calosci
        $this->assertStringContainsString('gramatura: 245 g/m2', (string) $product->refresh()->search_blob);
        $this->assertStringContainsString('Gramatura: 245 g/m2', ProductSearchBlob::manualSpecsText($product));
    }

    public function test_requirement_check_quotes_manual_rows_with_their_own_source(): void
    {
        $product = $this->product(['manual_specs' => [['label' => 'Tłumienie', 'value' => 'SNR 32 dB']]]);

        $sources = CardSources::fromProduct($product);
        $manual = array_values(array_filter($sources, static fn ($s): bool => $s->source === CardSource::MANUAL));

        $this->assertCount(1, $manual);
        $this->assertStringContainsString('SNR 32 dB', $manual[0]->text);
        $this->assertStringContainsString('Tłumienie', $manual[0]->text);
    }

    public function test_manual_rows_go_to_the_shop_description(): void
    {
        $product = $this->product([
            'description' => 'Nauszniki przeciwhałasowe z regulowanym pałąkiem, do pracy przy maszynach.',
            'manual_specs' => [['label' => 'Tłumienie', 'value' => 'SNR 32 dB']],
        ]);

        $html = app(PrestaDescriptionHtml::class)->fromProduct($product);

        $this->assertStringContainsString('Parametry', $html);
        $this->assertStringContainsString('SNR 32 dB', $html);
    }

    /**
     * @param  array<string, mixed>  $extra
     */
    private function product(array $extra = []): Product
    {
        return Product::query()->create(array_merge([
            'sku' => 'NAUSZ-32',
            'name' => 'Nauszniki przeciwhałasowe',
            'manufacturer' => '3M',
        ], $extra));
    }
}
