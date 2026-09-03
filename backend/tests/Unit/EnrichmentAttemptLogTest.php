<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Models\Product;
use App\Services\Enrichment\EnrichmentAttemptLog;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

final class EnrichmentAttemptLogTest extends TestCase
{
    use RefreshDatabase;

    public function test_snapshot_keeps_steps_and_caps_length(): void
    {
        $product = Product::query()->create([
            'sku' => 'LOG-1',
            'name' => 'Test',
            'manufacturer' => 'Uvex',
            'catalog_price_net' => 1,
            'purchase_price' => 1,
            'stock' => 0,
        ]);
        $log = new EnrichmentAttemptLog;
        $log->add('start', 'LOG-1');
        $log->add('drop', 'brak SKU', 'https://example.com/x');
        for ($i = 0; $i < 50; $i++) {
            $log->add('query', 'q'.$i);
        }
        $snap = $log->snapshot($product);
        $this->assertSame('LOG-1', $snap['sku']);
        $this->assertCount(40, $snap['steps']);
        $this->assertSame('start', $snap['steps'][0]['t']);
        $this->assertSame('https://example.com/x', $snap['steps'][1]['url'] ?? null);
    }
}
