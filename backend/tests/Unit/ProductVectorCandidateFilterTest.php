<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Models\Product;
use App\Services\ProductAiSearchService;
use Tests\TestCase;

final class ProductVectorCandidateFilterTest extends TestCase
{
    public function test_filters_out_mechanical_gloves_for_chemical_query(): void
    {
        $svc = $this->app->make(ProductAiSearchService::class);

        $chem = new Product([
            'sku' => 'XG35B',
            'name' => 'Rubiflex XG',
            'manufacturer' => 'uvex',
            'category' => 'Rękawice chemiczne',
            'norms' => 'EN 374',
            'description' => 'Rękawice do kwasów i rozpuszczalników',
        ]);
        $chem->id = 1;
        $mech = new Product([
            'sku' => '34-874',
            'name' => 'MaxiFlex Ultimate',
            'manufacturer' => 'ATG',
            'category' => 'Rękawice',
            'norms' => 'EN 388',
            'description' => 'Rękawice robocze precyzyjne',
        ]);
        $mech->id = 2;

        $facets = $svc->extractFacetsForQuery('rękawice do pracy z kwasami i rozpuszczalnikami');
        $out = $svc->filterVectorCandidates(collect([$mech, $chem]), $facets, [1 => 0.7, 2 => 0.95], 10);

        $this->assertCount(1, $out);
        $this->assertSame('XG35B', $out->first()->sku);
    }
}
