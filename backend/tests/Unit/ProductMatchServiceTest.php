<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Models\Product;
use App\Services\ProductMatchService;
use Illuminate\Support\Collection;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

final class ProductMatchServiceTest extends TestCase
{
    private ProductMatchService $matcher;

    protected function setUp(): void
    {
        parent::setUp();
        $this->matcher = app(ProductMatchService::class);
    }

    #[Test]
    public function short_numeric_fragment_does_not_match_longer_sku(): void
    {
        $products = new Collection([
            $this->fakeProduct([
                'sku' => '60028',
                'name' => 'ATHLETIC ALLROUND',
                'manufacturer' => 'uvex',
                'description' => 'Rękawice ochronne uvex athletic allround do prac montażowych.',
            ]),
        ]);

        $best = $this->matcher->bestMatch(
            'Rękawice ochronne POLROK Safety PK 600 G - szare',
            $products
        );

        $this->assertNotNull($best);
        $this->assertLessThan(
            ProductMatchService::MIN_MATCH_SCORE,
            $best['score'],
            '600 nie może dawać trafienia na SKU 60028'
        );
    }

    #[Test]
    public function exact_sku_in_requirement_matches_strongly(): void
    {
        $products = new Collection([
            $this->fakeProduct([
                'sku' => '60028',
                'name' => 'ATHLETIC ALLROUND',
                'manufacturer' => 'uvex',
            ]),
            $this->fakeProduct([
                'sku' => '34-274',
                'name' => 'KW Palm Coated',
                'manufacturer' => 'ATG',
                'description' => 'Rękawice nitrylowe',
                'enrichment_payload' => ['materials' => ['nitryl']],
            ]),
        ]);

        $best = $this->matcher->bestMatch(
            'Rękawice ATHLETIC art. 60028 rozmiar 9',
            $products
        );

        $this->assertNotNull($best);
        $this->assertSame('60028', $best['product']->sku);
        $this->assertGreaterThanOrEqual(ProductMatchService::MIN_MATCH_SCORE, $best['score']);
    }

    #[Test]
    public function gloves_requirement_does_not_match_footwear(): void
    {
        $products = new Collection([
            $this->fakeProduct([
                'sku' => '7255',
                'name' => 'GLOSS UP 2 WINTER S3 CI SRC',
                'manufacturer' => 'uvex',
                'category' => 'obuwie',
                'description' => 'Trzewiki ocieplane winter S3 CI SRC.',
                'norms' => 'EN ISO 20345 S3 CI SRC',
            ]),
            $this->fakeProduct([
                'sku' => 'RWD-1',
                'name' => 'DRAGON WINTER RWD',
                'manufacturer' => 'DRAGON',
                'description' => 'Rękawice 5-palcowe ocieplane powlekane gumą.',
                'enrichment_payload' => ['materials' => ['guma']],
            ]),
        ]);

        $best = $this->matcher->bestMatch(
            'Rękawice 5-palcowe ocieplane termoodporne, powlekane gumą, DRAGON WINTER RWD',
            $products
        );

        $this->assertNotNull($best);
        $this->assertSame('RWD-1', $best['product']->sku);
        $this->assertNotSame('7255', $best['product']->sku);
    }

    #[Test]
    public function matches_by_material_and_model_code_when_sku_missing(): void
    {
        $products = new Collection([
            $this->fakeProduct([
                'sku' => '60028',
                'name' => 'ATHLETIC ALLROUND',
                'manufacturer' => 'uvex',
                'description' => 'Lekkie rękawice montażowe z powłoką piankową.',
            ]),
            $this->fakeProduct([
                'sku' => 'RNITZ-9',
                'name' => 'Rękawice nitrylowe ze ściągaczem',
                'manufacturer' => 'REJS / Raw Pol',
                'description' => 'Rękawice robocze nitrylowe RNITZ kat. 2 ze ściągaczem. Materiał: nitryl.',
                'enrichment_payload' => [
                    'materials' => ['nitryl'],
                    'features' => ['ściągacz'],
                ],
            ]),
        ]);

        $best = $this->matcher->bestMatch(
            'Rękawice robocze nitrylowe REJS (Raw Pol) RNITZ kat. 2 ze ściągaczem',
            $products
        );

        $this->assertNotNull($best);
        $this->assertSame('RNITZ-9', $best['product']->sku);
        $this->assertGreaterThanOrEqual(ProductMatchService::MIN_MATCH_SCORE, $best['score']);
    }

    /**
     * @param  array<string, mixed>  $attrs
     */
    private function fakeProduct(array $attrs): Product
    {
        $p = new Product;
        $p->forceFill(array_merge([
            'id' => random_int(1, 999999),
            'sku' => 'X',
            'name' => 'X',
            'manufacturer' => 'X',
            'category' => null,
            'norms' => null,
            'description' => null,
            'enrichment_payload' => null,
            'purchase_price' => 1,
            'catalog_price_net' => 1,
        ], $attrs));

        return $p;
    }
}
