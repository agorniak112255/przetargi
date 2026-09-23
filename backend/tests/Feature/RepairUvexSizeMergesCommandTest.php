<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Product;
use App\Models\ProductDocument;
use App\Models\ProductImage;
use App\Models\ProductImageRejection;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

final class RepairUvexSizeMergesCommandTest extends TestCase
{
    use RefreshDatabase;

    public function test_preview_changes_nothing_and_apply_repairs_cards_once(): void
    {
        Queue::fake();

        // K JUNIOR niebieski: SKU obcięte, na liście scalonych limonka (znów osobna karta) i różowy (jeszcze nie)
        $blue = $this->card(23226, '2600.0', 'Ochronniki słuchu uvex K JUNIOR niebieski 2600.010', ['2600.011', '2600.013']);
        $this->card(42442, '2600.011', 'Ochronniki słuchu uvex K JUNIOR limonka 2600.011');
        $own = $this->image(24522, $blue->id, true, 'https://izam.test/niebieski.jpg');
        $foreign = $this->image(24523, $blue->id, false, 'https://izam.test/limonka.jpg');
        // Super f OTG 1,7: dokumenty wersji 3 i 5 — jeden wraca na swoją kartę, drugi ta karta już ma
        $superF = $this->card(23489, '9169.5', 'Okulary Super f OTG spawalnicze 9169.541', ['9169.543', '9169.545']);
        $shade3 = $this->card(42486, '9169.543', 'Okulary Super f OTG spawalnicze 9169.543');
        $shade5 = $this->card(42487, '9169.545', 'Okulary Super f OTG spawalnicze 9169.545');
        $this->document(14623, $superF->id, 'aaa', 'SST 9169.543.pdf');
        $this->document(14624, $superF->id, 'bbb', 'SST 9169.545.pdf');
        $this->document(20001, $shade5->id, 'bbb', 'SST 9169.545.pdf');
        // kod docelowy zajęty — SKU zostaje, reszta naprawy idzie dalej
        $this->card(23180, '2112.0', 'Zatyczki X-fit ze sznurkiem 2112.010', []);
        $this->card(30000, '2112.010', 'Inna karta z tym kodem');

        $this->artisan('products:repair-uvex-size-merges')->assertSuccessful();

        $this->assertSame('2600.0', $blue->fresh()->sku);
        $this->assertNotNull(ProductImage::query()->find($foreign->id));
        $this->assertSame(2, ProductDocument::query()->where('product_id', $superF->id)->count());

        $backup = storage_path('framework/testing/uvex-repair.json');
        $this->artisan('products:repair-uvex-size-merges', ['--apply' => true, '--backup' => $backup])
            ->expectsOutputToContain('SKU 2112.010 ma już karta #30000')
            ->expectsOutputToContain('Naprawiono 2 kart.')
            ->assertSuccessful();

        $blue->refresh();
        $this->assertSame('2600.010', $blue->sku);
        $this->assertSame(['2600.013'], $blue->enrichment_payload['merged_size_skus']);
        $this->assertSame(['2600.011'], $blue->enrichment_payload['unmerged_size_skus']);
        $this->assertNotNull(ProductImage::query()->find($own->id));
        $this->assertNull(ProductImage::query()->find($foreign->id));
        $this->assertTrue(ProductImageRejection::query()->where('product_id', $blue->id)->exists());

        $superF->refresh();
        $this->assertSame('9169.541', $superF->sku);
        $this->assertArrayNotHasKey('merged_size_skus', $superF->enrichment_payload);
        $this->assertSame(0, ProductDocument::query()->where('product_id', $superF->id)->count());
        $this->assertSame($shade3->id, ProductDocument::query()->find(14623)?->product_id);
        $this->assertNull(ProductDocument::query()->find(14624));
        $this->assertSame(1, ProductDocument::query()->where('product_id', $shade5->id)->count());

        $this->assertSame('2112.0', Product::query()->find(23180)?->sku);

        $this->assertFileExists($backup);
        $saved = json_decode((string) file_get_contents($backup), true);
        $this->assertSame('2600.0', collect($saved)->firstWhere('product_id', 23226)['sku']);
        @unlink($backup);

        // drugi przebieg: niczego już nie ma do zrobienia poza zajętym SKU
        $this->artisan('products:repair-uvex-size-merges')
            ->expectsOutputToContain('#23226 [2600.010]: bez zmian')
            ->expectsOutputToContain('#23489 [9169.541]: bez zmian')
            ->assertSuccessful();
    }

    /**
     * @param  list<string>|null  $merged
     */
    private function card(int $id, string $sku, string $name, ?array $merged = null): Product
    {
        return Product::query()->forceCreate([
            'id' => $id,
            'sku' => $sku,
            'name' => $name,
            'manufacturer' => 'UVEX',
            'catalog_price_net' => 10,
            'purchase_price' => 10,
            'enrichment_payload' => $merged !== null ? ['merged_size_skus' => $merged] : null,
        ]);
    }

    private function image(int $id, int $productId, bool $primary, string $url): ProductImage
    {
        return ProductImage::query()->forceCreate([
            'id' => $id,
            'product_id' => $productId,
            'path' => 'products/'.$id.'.jpg',
            'source_url' => $url,
            'is_primary' => $primary,
            'sort_order' => $primary ? 0 : 1,
            'checksum' => hash('sha256', $url),
        ]);
    }

    private function document(int $id, int $productId, string $checksum, string $name): void
    {
        ProductDocument::query()->forceCreate([
            'id' => $id,
            'product_id' => $productId,
            'path' => 'docs/'.$id.'.pdf',
            'source_url' => 'https://izam.test/'.$name,
            'kind' => ProductDocument::KIND_DATASHEET,
            'checksum' => $checksum,
        ]);
    }
}
