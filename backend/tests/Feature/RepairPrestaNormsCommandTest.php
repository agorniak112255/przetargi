<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Product;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

/**
 * SECURA 3000 (S56T0SM0, przetarg 1 poz. 13): import z PrestaShop wpisał cechy sklepu do norm karty,
 * a norma EN 140 z opisu nie trafiła nigdzie. Naprawa istniejących kart.
 */
final class RepairPrestaNormsCommandTest extends TestCase
{
    use RefreshDatabase;

    private const JUNK = ['1 sztuka', 'Bagnetowe Secura', 'Półmaska', 'Secura 3000', 'Silikon'];

    public function test_report_by_default_then_apply_removes_shop_features_from_norms(): void
    {
        Queue::fake();
        $secura = $this->product('S56T0SM0', [
            'description' => 'Półmaska SECURA 3000 z łącznikami bagnetowymi. Wyrób spełnia wymagania normy zharmonizowanej: PN-EN 140:2004 (EN 140:1998)',
            'norms' => implode(', ', self::JUNK),
            'enrichment_payload' => ['features' => self::JUNK, 'from_presta' => true, 'attributes' => ['normy_en' => self::JUNK]],
        ]);
        $ownNorms = $this->product('RNITZ-M', [
            'description' => 'Rękawice nitrylowe.',
            'norms' => 'EN 388:2016',
            'enrichment_payload' => ['from_presta' => true, 'attributes' => ['normy_en' => ['Nitryl', 'EN 388']]],
        ]);
        $notPresta = $this->product('LLM-1', [
            'description' => 'Karta z enrichmentu modelu.',
            'norms' => 'Silikon',
            'enrichment_payload' => ['attributes' => ['normy_en' => ['Silikon']]],
        ]);

        $this->artisan('products:repair-presta-norms')->assertSuccessful();
        $this->assertSame(implode(', ', self::JUNK), $secura->fresh()->norms, 'bez --apply nic nie zapisuje');

        $this->artisan('products:repair-presta-norms', ['--apply' => true])->assertSuccessful();

        $fresh = $secura->fresh();
        $this->assertSame('EN 140:2004, EN 140:1998', $fresh->norms);
        $this->assertSame(['EN 140:2004', 'EN 140:1998'], $fresh->enrichment_payload['attributes']['normy_en']);
        $this->assertSame(self::JUNK, $fresh->enrichment_payload['features'], 'cechy sklepu zostają w cechach');

        $own = $ownNorms->fresh();
        $this->assertSame('EN 388:2016', $own->norms, 'kolumny norms spoza listy importu nie nadpisujemy');
        $this->assertSame(['EN 388'], $own->enrichment_payload['attributes']['normy_en']);

        $this->assertSame('Silikon', $notPresta->fresh()->norms, 'karty spoza PrestaShop naprawa nie dotyka');
    }

    /** @param  array<string, mixed>  $overrides */
    private function product(string $sku, array $overrides): Product
    {
        return Product::query()->create(array_merge([
            'sku' => $sku,
            'name' => $sku,
            'manufacturer' => 'X',
            'catalog_price_net' => 10,
            'purchase_price' => 5,
            'stock' => 1,
            'enrichment_status' => Product::ENRICHMENT_DONE,
        ], $overrides));
    }
}
