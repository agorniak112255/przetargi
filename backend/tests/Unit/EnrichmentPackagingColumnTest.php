<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Models\Product;
use App\Services\Enrichment\ProductEnrichmentService;
use ReflectionMethod;
use Tests\TestCase;

/**
 * Batch #308: RADIM i MOFOS miały gotowy opis, ale lista rozmiarów z kilku kart przekroczyła
 * 120 znaków kolumny packaging i zapis padał błędem SQL „Data too long”.
 */
final class EnrichmentPackagingColumnTest extends TestCase
{
    public function test_size_list_longer_than_packaging_column_stays_only_in_attribute(): void
    {
        $service = app(ProductEnrichmentService::class);
        $method = new ReflectionMethod(ProductEnrichmentService::class, 'applyExtractedSizes');
        $product = new Product(['sku' => '1150-001-100-00', 'name' => 'Chef´s jacket, two-lined, white colour', 'manufacturer' => 'Canis']);
        $sizes = ['XS', 'S', 'M', 'L', 'XL', '2XL', '3XL', '4XL', '5XL', '6XL', '7XL'];
        foreach ([44, 48, 52, 56, 60, 64, 68, 72, 94, 98, 102, 106, 110, 114, 118, 122, 126, 130, 134] as $size) {
            $sizes[] = (string) $size;
        }

        $long = $method->invoke($service, $product, [], [], '', $sizes);
        $this->assertGreaterThan(120, mb_strlen((string) ($long['attributes']['rozmiar'] ?? '')), 'fixture: lista dłuższa niż kolumna');
        $this->assertNull($long['packaging']);

        $short = $method->invoke($service, $product, [], [], '', ['S', 'M', 'L', 'XL']);
        $this->assertNotNull($short['packaging'], 'krótka lista rozmiarów nadal trafia do packaging');
        $this->assertLessThanOrEqual(120, mb_strlen((string) $short['packaging']));
    }
}
