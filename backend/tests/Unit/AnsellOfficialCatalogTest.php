<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Models\Product;
use App\Services\Enrichment\AnsellOfficialCatalog;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

final class AnsellOfficialCatalogTest extends TestCase
{
    public function test_finds_official_alphatec_page_when_shops_lack_the_model(): void
    {
        $url = 'https://www.ansell.com/pl/pl/products/alphatec-4000-ultrasonically-welded-taped-model-121';
        Http::fake([
            'https://r.jina.ai/'.$url => Http::response(
                "# AlphaTec 4000 Ultrasonically Welded & Taped - Model 121\n\n"
                .'The AlphaTec 4000 model 121 is a full body protection suit tested against chemicals. '
                .str_repeat('opis ', 40),
                200
            ),
            '*' => Http::response('Product Not Found — missing', 200),
        ]);

        $product = new Product([
            'sku' => 'GR40T-00121-09',
            'name' => '4000-GR CVRL HOOD 121-G02.5XL',
            'manufacturer' => 'Ansell',
        ]);
        $hits = app(AnsellOfficialCatalog::class)->find($product);

        $this->assertSame($url, $hits[0]['url'] ?? null);
        $this->assertStringContainsString('Model 121', $hits[0]['title'] ?? '');
    }

    public function test_skips_product_not_found(): void
    {
        Http::fake([
            '*' => Http::response('Title: Product Not Found | Ansell'."\n\n".str_repeat('nav ', 40), 200),
        ]);

        $hits = app(AnsellOfficialCatalog::class)->find(new Product([
            'sku' => 'GR40T-00121-09',
            'name' => '4000-GR CVRL HOOD 121-G02.5XL',
            'manufacturer' => 'Ansell',
        ]));

        $this->assertSame([], $hits);
    }
}
