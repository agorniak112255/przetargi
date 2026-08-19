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

    public function test_lab_coat_query_keeps_coat_and_drops_other_ppe(): void
    {
        $svc = $this->app->make(ProductAiSearchService::class);
        $query = 'FARTUCH LAB. ELANO-BAWEŁNA prosty, biały, rękawy wykończone zatrzaską. EN ISO 13688';

        $facets = $svc->extractFacetsForQuery($query);
        $this->assertSame('fartuch', $facets['product_type']);

        $coat = new Product([
            'sku' => 'LAB-COAT',
            'name' => 'Fartuch laboratoryjny elano-bawełna',
            'manufacturer' => 'X',
            'category' => 'Odzież',
            'description' => 'Fartuch lab. zapinany na zatrzaski, gramatura 210g',
        ]);
        $coat->id = 1;
        $boot = new Product([
            'sku' => 'PROS-106',
            'name' => '106 OB ZIMA BEZ PODNOSKA',
            'manufacturer' => 'URGENT',
            'category' => 'Obuwie',
            'description' => 'Trzewiki zimowe',
        ]);
        $boot->id = 2;
        $glove = new Product([
            'sku' => '34-800',
            'name' => 'KW Palm Coated',
            'manufacturer' => 'ATG',
            'category' => 'Rękawice',
            'description' => 'Rękawice powlekane',
        ]);
        $glove->id = 3;
        $coverall = new Product([
            'sku' => 'TD-0127',
            'name' => 'TYVEK Dual Finish',
            'manufacturer' => 'DuPont',
            'category' => 'Odzież',
            'description' => 'Kombinezon ochronny Tyvek',
        ]);
        $coverall->id = 4;

        $out = $svc->filterVectorCandidates(
            collect([$boot, $glove, $coverall, $coat]),
            $facets,
            [1 => 0.4, 2 => 0.9, 3 => 0.85, 4 => 0.8],
            10
        );

        $this->assertCount(1, $out);
        $this->assertSame('LAB-COAT', $out->first()->sku);
    }
}
