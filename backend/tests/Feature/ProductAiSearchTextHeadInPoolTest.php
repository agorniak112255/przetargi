<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Product;
use App\Services\ProductAiSearchService;
use App\Support\PpeAssortment;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Collection;
use Tests\TestCase;

/**
 * Golden opisowy15-07 (pomiar 25.09.2026 na produkcji): ATG 44-304 był 1. w wyszukiwaniu tekstowym, a po fuzji rang
 * 167. — poza pulą 80, więc model go nie widział, choć karta potwierdza wszystkie warunki.
 */
final class ProductAiSearchTextHeadInPoolTest extends TestCase
{
    use RefreshDatabase;

    private const QUERY = 'Rękawice ochronne antyprzecięciowe powlekane, do prac w środowisku zaolejonym';

    public function test_top_text_hit_outside_fused_pool_joins_the_pool(): void
    {
        $pool = $this->gloves('CUT-', 80);
        $atg = $this->atg();

        $out = $this->append($pool, [$atg->id, $pool[0]->id], 80);

        $this->assertCount(81, $out);
        $this->assertSame('44-304', $out->last()->sku, 'na końcu puli — do 24 kart oceny wybiera go liczba warunków');
    }

    public function test_pool_without_change_when_text_head_is_already_there(): void
    {
        $pool = $this->gloves('CUT-', 80);

        $out = $this->append($pool, [$pool[5]->id, $pool[0]->id, $pool[70]->id], 80);

        $this->assertSame($pool->pluck('id')->all(), $out->pluck('id')->all());
    }

    public function test_short_lists_and_substitutes_get_no_text_head(): void
    {
        $pool = $this->gloves('CUT-', 40);
        $atg = $this->atg();

        $this->assertCount(40, $this->append($pool, [$atg->id], 40));
        $this->assertCount(12, $this->append($pool->take(12), [$atg->id], 12));
    }

    public function test_card_of_another_brand_is_not_added_when_query_names_a_brand(): void
    {
        $pool = $this->gloves('UVEX-', 80, 'UVEX');
        $atg = $this->atg();
        $query = 'Rękawice ochronne antyprzecięciowe uvex powlekane';

        $out = $this->append($pool, [$atg->id], 80, $query, ['manufacturer' => 'UVEX', 'needed' => 'rękawice antyprzecięciowe uvex']);

        $this->assertNotContains('44-304', $out->pluck('sku')->all());
    }

    public function test_incompatible_text_hit_is_not_added(): void
    {
        $pool = $this->gloves('CUT-', 80);
        $boot = Product::query()->create([
            'sku' => 'BOOT-1',
            'name' => 'Trzewiki bezpieczne S3 antyprzecięciowe',
            'manufacturer' => 'X',
            'category' => 'Obuwie',
            'ppe_family' => PpeAssortment::FAMILY_FOOTWEAR,
            'catalog_price_net' => 10,
            'purchase_price' => 5,
            'stock' => 1,
            'enrichment_status' => Product::ENRICHMENT_DONE,
        ]);

        $out = $this->append($pool, [$boot->id], 80);

        $this->assertNotContains('BOOT-1', $out->pluck('sku')->all());
    }

    /**
     * @param  Collection<int, Product>  $pool
     * @param  list<int>  $textIds
     * @param  array<string, mixed>  $intent
     * @return Collection<int, Product>
     */
    private function append(Collection $pool, array $textIds, int $limit, string $query = self::QUERY, array $intent = []): Collection
    {
        $service = app(ProductAiSearchService::class);
        $requirement = (new \ReflectionMethod($service, 'assortmentText'))->invoke($service, $query, (string) ($intent['needed'] ?? ''));

        return (new \ReflectionMethod($service, 'appendTextHead'))->invoke(
            $service,
            $query,
            $intent + ['needed' => 'rękawice antyprzecięciowe'],
            $requirement,
            $pool,
            $pool,
            $textIds,
            $limit,
        );
    }

    /** @return Collection<int, Product> */
    private function gloves(string $prefix, int $count, string $manufacturer = 'MAPA'): Collection
    {
        $pool = collect();
        for ($i = 1; $i <= $count; $i++) {
            $pool->push(Product::query()->create([
                'sku' => $prefix.$i,
                'name' => 'Rękawice antyprzecięciowe powlekane '.$manufacturer.' '.$i,
                'manufacturer' => $manufacturer,
                'category' => 'Rękawice',
                'ppe_family' => PpeAssortment::FAMILY_GLOVES,
                'catalog_price_net' => 10,
                'purchase_price' => 5,
                'stock' => 1,
                'enrichment_status' => Product::ENRICHMENT_DONE,
            ]));
        }

        return $pool;
    }

    private function atg(): Product
    {
        return Product::query()->create([
            'sku' => '44-304',
            'manufacturer' => 'ATG',
            'name' => 'Ściągacz, oblanie części chwytnej',
            'description' => 'Rękawice antyprzecięciowe ATG MaxiCut Oil do pracy w środowisku zaolejonym.',
            'norms' => 'EN 388:2016 + A1:2018, EN 407:2004',
            'category' => 'Rękawice',
            'ppe_family' => PpeAssortment::FAMILY_GLOVES,
            'catalog_price_net' => 10,
            'purchase_price' => 5,
            'stock' => 1,
            'enrichment_status' => Product::ENRICHMENT_DONE,
        ]);
    }
}
