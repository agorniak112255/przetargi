<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\PriceList;
use App\Models\Product;
use App\Models\ProductImage;
use App\Models\ProductSourcePrice;
use App\Models\User;
use App\Services\Enrichment\PriceListDescriptionSources;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Cenniki → „Z pliku”, miary jakości opisów (etap 1, 08.10.2026): werdykt tożsamości strony źródłowej
 * (enrichment_payload->identity->verdict), karty do przeglądu (products.review_reason) i karty ze zdjęciem.
 */
final class PriceListFilesQualityTest extends TestCase
{
    use RefreshDatabase;

    private const DESCRIPTION = 'Rękawice robocze powlekane nitrylem, mankiet ściągacz, norma EN 388.';

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolesAndPermissionsSeeder::class);
        Sanctum::actingAs(User::factory()->withRole('admin')->create());
    }

    public function test_row_counts_identity_verdicts_cards_to_review_and_cards_with_image(): void
    {
        $list = $this->list('Testowy');
        $other = $this->list('Inny');

        $hard = $this->card($list, 'Q-1', ['identity' => ['verdict' => 'hard', 'reason' => 'kod w adresie']]);
        $this->card($list, 'Q-2', ['identity' => ['verdict' => 'soft']], ['review_reason' => Product::REVIEW_IDENTITY_SOFT]);
        $this->card($list, 'Q-3', ['identity' => ['verdict' => 'none']], ['review_reason' => Product::REVIEW_IDENTITY_NONE]);
        // opis sprzed werdyktu, JSON null i wartość spoza listy → „unknown”, bez zgadywania
        $legacy = $this->card($list, 'Q-4', ['primary_source_url' => 'https://sklep.pl/q-4']);
        $this->card($list, 'Q-5', ['identity' => ['verdict' => null]]);
        $this->card($list, 'Q-6', ['identity' => ['verdict' => 'maybe']]);
        $this->card($list, 'Q-7', null);
        // bez opisu: werdykt się nie liczy, ale powód przeglądu tak (propozycja czeka przy karcie bez opisu)
        $this->card($list, 'Q-8', ['identity' => ['verdict' => 'hard']], [
            'description' => null,
            'review_reason' => Product::REVIEW_WORSE_VERSION,
        ]);

        // zdjęcia: dwa przy jednej karcie liczą się raz; zdjęcie karty innego cennika nie liczy się wcale
        $this->image($hard, 'a');
        $this->image($hard, 'b');
        $this->image($legacy, 'c');
        $this->image($this->card($other, 'O-1', null), 'd');

        $rows = collect($this->getJson('/api/price-lists/files')->assertOk()->json('lists'));
        $row = $rows->firstWhere('id', $list->id);

        $this->assertSame(8, $row['cards']);
        $this->assertSame(7, $row['described']);
        $this->assertSame(['hard' => 1, 'soft' => 1, 'none' => 1, 'unknown' => 4], $row['identity']);
        $this->assertSame($row['described'], array_sum($row['identity']));
        $this->assertSame(3, $row['to_review']);
        $this->assertSame(2, $row['with_image']);

        $otherRow = $rows->firstWhere('id', $other->id);
        $this->assertSame(['hard' => 0, 'soft' => 0, 'none' => 0, 'unknown' => 1], $otherRow['identity']);
        $this->assertSame(0, $otherRow['to_review']);
        $this->assertSame(1, $otherRow['with_image']);
    }

    public function test_description_sources_return_identity_and_review_reason_per_card(): void
    {
        $list = $this->list('Testowy');
        $soft = $this->card($list, 'S-1', ['identity' => ['verdict' => 'soft']], ['review_reason' => Product::REVIEW_IDENTITY_SOFT]);
        $bare = $this->card($list, 'S-2', null);

        $cards = app(PriceListDescriptionSources::class)->cards([$soft->id, $bare->id], null);

        $this->assertSame('soft', $cards[$soft->id]['identity']);
        $this->assertSame(Product::REVIEW_IDENTITY_SOFT, $cards[$soft->id]['review_reason']);
        $this->assertSame(PriceListDescriptionSources::IDENTITY_UNKNOWN, $cards[$bare->id]['identity']);
        $this->assertNull($cards[$bare->id]['review_reason']);
    }

    public function test_list_with_only_undescribed_card_has_zero_quality_counts(): void
    {
        $list = $this->list('Pusty');
        // karta bez opisu, bez werdyktu, bez powodu przeglądu i bez zdjęcia
        $this->card($list, 'E-1', null, ['description' => null]);

        $row = $this->getJson('/api/price-lists/files')->assertOk()->json('lists.0');

        $this->assertSame(['hard' => 0, 'soft' => 0, 'none' => 0, 'unknown' => 0], $row['identity']);
        $this->assertSame(0, $row['to_review']);
        $this->assertSame(0, $row['with_image']);
    }

    private function list(string $manufacturer): PriceList
    {
        return PriceList::query()->create([
            'manufacturer' => $manufacturer,
            'version' => '2026',
            'original_filename' => 'plik.xlsx',
            'rows_total' => 1,
            'products_created' => 1,
            'products_updated' => 0,
            'rows_skipped' => 0,
            'product_ids' => [],
        ])->fresh();
    }

    /**
     * @param  array<string, mixed>|null  $payload
     * @param  array<string, mixed>  $attributes
     */
    private function card(PriceList $list, string $sku, ?array $payload, array $attributes = []): Product
    {
        $card = Product::query()->create([
            'sku' => $sku,
            'name' => 'Rękawice testowe '.$sku,
            'manufacturer' => (string) $list->manufacturer,
            'description' => self::DESCRIPTION,
            'catalog_price_net' => 10,
            'purchase_price' => 8,
            'stock' => 0,
            'enrichment_payload' => $payload,
            ...$attributes,
        ]);
        ProductSourcePrice::query()->create([
            'product_id' => $card->id,
            'source_key' => ProductSourcePrice::SOURCE_FILE,
            'price_list_id' => $list->id,
            'catalog_price_net' => 10,
            'purchase_price' => 8,
            'currency' => 'PLN',
            'checked_at' => now(),
        ]);

        return $card;
    }

    private function image(Product $card, string $checksum): void
    {
        ProductImage::query()->create([
            'product_id' => $card->id,
            'path' => "products/{$card->id}/{$checksum}.jpg",
            'checksum' => $checksum,
        ]);
    }
}
