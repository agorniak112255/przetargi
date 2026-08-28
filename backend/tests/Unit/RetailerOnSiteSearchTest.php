<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Models\Product;
use App\Services\Enrichment\RetailerOnSiteSearch;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

final class RetailerOnSiteSearchTest extends TestCase
{
    public function test_keeps_matching_model_and_drops_neighbour(): void
    {
        Http::fake([
            'https://bpbhp.pl/catalogsearch/result/*' => Http::response(
                '<a href="https://bpbhp.pl/kombinezon-ansell-alphatec-4000-model-111">Kombinezon AlphaTec 4000 model 111</a>'
                .'<a href="https://bpbhp.pl/kombinezon-ansell-alphatec-4000-model-121">Kombinezon AlphaTec 4000 model 121</a>',
                200
            ),
            '*' => Http::response('empty', 200),
        ]);

        $product = new Product([
            'sku' => 'GR40T-00121-07',
            'name' => '4000-GR CVRL HOOD 121-G02.3XL',
            'manufacturer' => 'Ansell',
        ]);
        $hits = app(RetailerOnSiteSearch::class)->find($product);
        $urls = array_column($hits, 'url');

        $this->assertContains('https://bpbhp.pl/kombinezon-ansell-alphatec-4000-model-121', $urls);
        $this->assertNotContains('https://bpbhp.pl/kombinezon-ansell-alphatec-4000-model-111', $urls);
    }

    public function test_query_uses_style_not_article_number(): void
    {
        $search = app(RetailerOnSiteSearch::class);
        $query = $search->query(new Product([
            'sku' => 'GR40T-00121-07',
            'name' => '4000-GR CVRL HOOD 121-G02.3XL',
            'manufacturer' => 'Ansell',
        ]));

        $this->assertStringContainsString('AlphaTec', $query);
        $this->assertStringContainsString('4000', $query);
        $this->assertStringContainsString('121', $query);
        $this->assertStringNotContainsString('GR40T', $query);
        $this->assertStringContainsString('121', $search->queryBareModel(new Product([
            'sku' => 'GR40T-00121-07',
            'name' => '4000-GR CVRL HOOD 121-G02.3XL',
            'manufacturer' => 'Ansell',
        ])));
    }
}
