<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\B2bAccount;
use App\Models\B2bProductLink;
use App\Models\Product;
use App\Models\ProductSourcePrice;
use App\Services\Pricing\ProductEffectivePrice;
use App\Services\Pricing\SourcePriceComparison;
use App\Services\Pricing\SupplierSpecialMask;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Tests\Support\SupplierSpecialFixture;
use Tests\TestCase;

/**
 * Porównanie cen ze źródeł w widoku standardowym: ranking, różnice, „taniej u …” i najwyższa cena rozmiaru liczone
 * od ceny, którą widz widzi (cena standardowa zamiast ceny specjalnej konta). Zwycięzca (explain) zostaje prawdziwy.
 */
final class SourcePriceComparisonMaskTest extends TestCase
{
    use RefreshDatabase;
    use SupplierSpecialFixture;

    private SourcePriceComparison $comparison;

    private ProductEffectivePrice $prices;

    protected function setUp(): void
    {
        parent::setUp();
        Queue::fake();
        Cache::forget('nbp.table_a.rates');
        $this->setUpSupplierSpecial();
        $this->travelTo(Carbon::parse('2026-09-30 12:00:00'));
        $this->comparison = app(SourcePriceComparison::class);
        $this->prices = app(ProductEffectivePrice::class);
    }

    public function test_revealing_keeps_real_ranking_and_no_cheaper_source(): void
    {
        [$product, $uvexKey, $distributorKey] = $this->cardWithDistributor();

        $rows = $this->forCard($product, SupplierSpecialMask::revealing())['rows'];
        $this->assertSame(1, $rows[$uvexKey]['price_rank']);
        $this->assertTrue($rows[$uvexKey]['is_cheapest']);
        $this->assertSame(173.19, $rows[$uvexKey]['purchase_price_pln']);
        $this->assertSame(0.0, $rows[$uvexKey]['diff_to_effective_pct']);
        $this->assertSame(2, $rows[$distributorKey]['price_rank']);
        $this->assertSame(12.6, $rows[$distributorKey]['diff_to_effective_pct']);

        $this->assertNull($this->comparison->cheaperSources(collect([$product->fresh()]), SupplierSpecialMask::revealing())[$product->id]);
    }

    public function test_hiding_ranks_against_standard_price_and_shows_cheaper_distributor(): void
    {
        [$product, $uvexKey, $distributorKey] = $this->cardWithDistributor();

        $result = $this->forCard($product, SupplierSpecialMask::hiding());
        $rows = $result['rows'];
        // dystrybutor 195 jest tańszy od ceny standardowej 211,37, choć droższy od prawdziwej ceny konta
        $this->assertSame(1, $rows[$distributorKey]['price_rank']);
        $this->assertTrue($rows[$distributorKey]['is_cheapest']);
        $this->assertSame(-7.7, $rows[$distributorKey]['diff_to_effective_pct']);
        $this->assertSame(2, $rows[$uvexKey]['price_rank']);
        $this->assertSame(211.37, $rows[$uvexKey]['purchase_price_pln']);
        $this->assertSame(0.0, $rows[$uvexKey]['diff_to_effective_pct']);
        $this->assertNoSpecialLeak((string) json_encode($result));

        $cheaper = $this->comparison->cheaperSources(collect([$product->fresh()]), SupplierSpecialMask::hiding())[$product->id];
        $this->assertSame([
            'source_key' => $distributorKey,
            'label' => 'B2B Anro',
            'purchase_price_pln' => 195.0,
            'diff_pct' => -7.7,
        ], $cheaper);
        // zwycięzca zostaje prawdziwy — od konta UVEX kupujemy
        $this->assertSame($uvexKey, $this->prices->explain($product->fresh())['winner']?->source_key);
    }

    public function test_size_price_max_is_scaled_for_hiding_mask(): void
    {
        $fixture = $this->supplierSpecialCard();
        $product = $fixture['product']->fresh();

        $real = $this->comparison->orderQuantities(collect([$product]), SupplierSpecialMask::revealing())[$product->id];
        $this->assertSame('190.00', $real['size_price_max']);

        $masked = $this->comparison->orderQuantities(collect([$product]), SupplierSpecialMask::hiding())[$product->id];
        $this->assertSame('231.89', $masked['size_price_max']);
        $this->assertSame('PLN', $masked['size_price_currency']);
        $this->assertSame(
            $masked,
            $this->comparison->orderQuantityOf($fixture['slot']->fresh(), SupplierSpecialMask::hiding()),
        );
        $this->assertNoSpecialLeak((string) json_encode($masked));
        // oryginalny slot nietknięty
        $this->assertSame('190.00', $fixture['slot']->size_price_max);
    }

    public function test_hiding_adds_constant_queries_to_cheaper_sources(): void
    {
        $cards = collect();
        foreach (range(1, 5) as $i) {
            [$product] = $this->cardWithDistributor('SKU-'.$i);
            $cards->push($product->fresh());
        }

        $count = function (SupplierSpecialMask $mask) use ($cards): int {
            DB::flushQueryLog();
            DB::enableQueryLog();
            $this->comparison->cheaperSources($cards, $mask);
            $queries = count(DB::getQueryLog());
            DB::disableQueryLog();

            return $queries;
        };

        $this->assertSame($count(SupplierSpecialMask::revealing()) + 2, $count(SupplierSpecialMask::hiding()));
    }

    /**
     * Karta UVEX z ceną specjalną 173,19 (standard 211,37) i dystrybutor Anro po 195,00.
     *
     * @return array{0: Product, 1: string, 2: string}
     */
    private function cardWithDistributor(string $sku = '60148-UVEX'): array
    {
        $fixture = $this->supplierSpecialCard($sku);
        $anro = B2bAccount::query()->where('connector', 'anro')->first() ?? $this->otherAccount('anro');
        ProductSourcePrice::query()->create([
            'product_id' => $fixture['product']->id,
            'source_key' => ProductSourcePrice::b2bKey((int) $anro->id),
            'b2b_account_id' => $anro->id,
            'catalog_price_net' => 220,
            'purchase_price' => 195,
            'currency' => 'PLN',
            'checked_at' => Carbon::now()->subDays(2),
        ]);
        B2bProductLink::query()->create([
            'b2b_account_id' => $anro->id,
            'remote_id' => 'ANRO-'.$sku,
            'remote_sku' => $sku,
            'product_id' => $fixture['product']->id,
        ]);

        return [
            $fixture['product'],
            ProductSourcePrice::b2bKey((int) $fixture['account']->id),
            ProductSourcePrice::b2bKey((int) $anro->id),
        ];
    }

    /**
     * @return array{rows: array<string, array<string, mixed>>, rates: array{as_of: string|null, source: string}}
     */
    private function forCard(Product $product, SupplierSpecialMask $mask): array
    {
        $product = $product->fresh();
        $slots = ProductSourcePrice::query()
            ->with(['account:id,connector,sites', 'priceList:id,manufacturer,version,suggested_prices'])
            ->where('product_id', $product->id)
            ->get();

        return $this->comparison->forCard($product, $slots, $this->prices->explain($product), $mask);
    }
}
