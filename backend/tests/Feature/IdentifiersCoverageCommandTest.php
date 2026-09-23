<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\B2bAccount;
use App\Models\B2bProductLink;
use App\Models\PriceList;
use App\Models\Product;
use App\Models\ProductIdentifier;
use App\Models\ProductShopCard;
use App\Models\ProductSourcePrice;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

final class IdentifiersCoverageCommandTest extends TestCase
{
    use RefreshDatabase;

    public function test_reports_coverage_per_source_and_shared_codes_without_changing_anything(): void
    {
        Queue::fake();
        $account = B2bAccount::query()->create(['username' => 'p4s', 'password' => 'x', 'connector' => 'p4s', 'sites' => ['b2b.p4s.pl']]);
        $list = PriceList::query()->create(['manufacturer' => 'TEGERA', 'version' => '2026-01']);

        $p4sCard = $this->card('12.008');
        $p4sOther = $this->card('12.009');
        $fileCard = $this->card('8');
        foreach ([$p4sCard, $p4sOther] as $card) {
            B2bProductLink::query()->create(['b2b_account_id' => $account->id, 'remote_id' => 'R'.$card->id, 'product_id' => $card->id]);
        }
        // obie karty P4S mają EAN w tabelce sklepu; identyfikator EAN ma tylko pierwsza
        foreach ([$p4sCard, $p4sOther] as $card) {
            ProductShopCard::query()->create([
                'product_id' => $card->id,
                'b2b_account_id' => $account->id,
                'fields' => [['section' => 'Informacje handlowe', 'rows' => [['name' => 'EAN', 'value' => '5711074644834']]]],
                'synced_at' => now(),
            ]);
        }
        ProductSourcePrice::query()->create([
            'product_id' => $fileCard->id, 'source_key' => ProductSourcePrice::SOURCE_FILE, 'price_list_id' => $list->id,
            'catalog_price_net' => 10, 'purchase_price' => 8,
        ]);

        $b2b = 'b2b:'.$account->id;
        $file = 'file:'.$list->id;
        $this->identifier($p4sCard, $b2b, 'ean', '5711074644834', '05711074644834');
        $this->identifier($p4sCard, $b2b, 'manufacturer_code', '8', '8', 'tegera');
        $this->identifier($p4sCard, $b2b, 'ean', '5711074644835', null);
        $this->identifier($p4sOther, $b2b, 'source_code', '12.009', '12009', 'tegera', removed: true);
        $this->identifier($fileCard, $file, 'ean', '5711074644834', '05711074644834');
        $this->identifier($fileCard, $file, 'source_code', '8', '8', 'tegera');
        $before = ProductIdentifier::query()->orderBy('id')->get()->toArray();

        Artisan::call('identifiers:coverage', ['--json' => true]);
        $report = json_decode(Artisan::output(), true);

        $sources = collect($report['sources'])->keyBy('source_key');
        $this->assertSame(2, $sources[$b2b]['cards']);
        $this->assertSame(1, $sources[$b2b]['cards_with_identifiers']);
        $this->assertSame(1, $sources[$b2b]['cards_with_ean']);
        $this->assertSame(1, $sources[$b2b]['invalid_ean']);
        $this->assertSame(1, $sources[$b2b]['cards_with_manufacturer_code']);
        $this->assertSame(1, $sources[$b2b]['removed']);
        $this->assertSame(1, $sources[$b2b]['shop_ean_without_identifier']);
        $this->assertSame(1, $sources[$file]['cards']);
        $this->assertSame(1, $sources[$file]['cards_with_ean']);
        $this->assertNull($sources[$file]['shop_ean_without_identifier']);
        // ten sam EAN i ten sam kod TEGERA „8” na karcie P4S i karcie z cennika
        $this->assertSame(['shared_ean' => 1, 'shared_ean_cards' => 2, 'shared_code' => 1, 'shared_code_cards' => 2], $report['overlaps']);
        $this->assertSame(2, $report['totals']['cards_with_identifiers']);
        $this->assertSame(1, $report['totals']['identifiers_removed']);

        $this->assertSame(0, Artisan::call('identifiers:coverage'));
        $this->assertStringContainsString('Wspólny EAN na kartach różnych źródeł: 1 EAN-ów, 2 kart', Artisan::output());
        $this->assertSame($before, ProductIdentifier::query()->orderBy('id')->get()->toArray());
    }

    private function card(string $sku): Product
    {
        return Product::query()->create([
            'sku' => $sku, 'name' => 'Rękawice Tegera '.$sku, 'manufacturer' => 'TEGERA',
            'catalog_price_net' => 10, 'purchase_price' => 8, 'stock' => 0,
        ]);
    }

    private function identifier(Product $card, string $source, string $type, string $value, ?string $normalized, ?string $brand = null, bool $removed = false): void
    {
        ProductIdentifier::query()->create([
            'product_id' => $card->id,
            'source_key' => $source,
            'position_key' => (string) $card->sku,
            'type' => $type,
            'value' => $value,
            'normalized' => $normalized,
            'brand_key' => $brand,
            'last_seen_at' => now(),
            'removed_at' => $removed ? now() : null,
        ]);
    }
}
