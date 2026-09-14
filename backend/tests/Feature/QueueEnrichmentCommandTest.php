<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\AiSetting;
use App\Models\Product;
use App\Models\ProductEnrichmentBatch;
use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

/**
 * Po naprawie nazw cennika 3M 2026 opisy trzeba pobrać ponownie, ale do przetargów potrzebne są środki ochrony, nie
 * ścierniwa. Polecenie wybiera karty po producencie i kategorii, pomija opisane, dzieli na partie wg limitu z Ustawień AI.
 */
final class QueueEnrichmentCommandTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Queue::fake();
        $this->seed(RolesAndPermissionsSeeder::class);
        User::factory()->withRole('admin')->create();
        AiSetting::query()->create([
            'enabled' => true,
            'provider' => 'openai_compatible',
            'base_url' => 'https://api.openai.com/v1',
            'api_key' => 'sk-test-key-1234567890',
            'model' => 'gpt-4o-mini',
            'timeout_seconds' => 60,
            'temperature' => 0.1,
            'enrichment_batch_limit' => 2,
        ]);
        $ppe = 'Środki ochrony indywidualnej';
        $this->card('1406213', '3M™ 2890 Gogle ochronne, szczelne, zaciemnienie spawalnicze 5.0, 2895S', $ppe, Product::ENRICHMENT_NONE);
        $this->card('GH1N', '3M™ Pasek podbródkowy do hełmu ochronnego G22 i G3000', $ppe, Product::ENRICHMENT_NONE);
        $this->card('9914', '3M™ Półmaska filtrująca 9914', $ppe, Product::ENRICHMENT_NONE);
        $this->card('9310+', '3M™ Półmaska filtrująca 9310+', $ppe, Product::ENRICHMENT_DONE);
        $this->card('34340', 'Elastyczne arkusze do szlifowania ręcznego 3M™ Hookit™ 270J', 'Materiały ścierne', Product::ENRICHMENT_NONE);
    }

    public function test_preview_counts_matching_cards_and_queues_nothing(): void
    {
        $this->artisan('products:queue-enrichment', ['--manufacturer' => '3M', '--category' => 'Środki ochrony indywidualnej'])
            ->expectsOutputToContain('Do pobrania opisu: 3 kart — 2 partii po 2')
            ->expectsOutputToContain('Podgląd')
            ->assertSuccessful();

        $this->assertSame(0, ProductEnrichmentBatch::query()->count());
    }

    public function test_apply_queues_only_undescribed_cards_of_category_in_batches(): void
    {
        $this->artisan('products:queue-enrichment', ['--manufacturer' => '3M', '--category' => 'Środki ochrony indywidualnej', '--apply' => true])
            ->expectsOutputToContain('Zlecono pobranie opisu: 3 kart w 2 partiach')
            ->assertSuccessful();

        $this->assertSame([2, 1], ProductEnrichmentBatch::query()->orderBy('id')->pluck('total')->map(static fn ($n): int => (int) $n)->all());
    }

    public function test_requires_a_filter(): void
    {
        $this->artisan('products:queue-enrichment')
            ->expectsOutputToContain('Podaj --manufacturer= albo --category=')
            ->assertFailed();
    }

    private function card(string $sku, string $name, string $category, string $status): void
    {
        Product::query()->create([
            'sku' => $sku,
            'name' => $name,
            'manufacturer' => '3M',
            'category' => $category,
            'catalog_price_net' => 10,
            'purchase_price' => 8,
            'stock' => 1,
            'enrichment_status' => $status,
        ]);
    }
}
