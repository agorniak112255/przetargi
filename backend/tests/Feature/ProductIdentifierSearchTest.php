<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Product;
use App\Models\ProductIdentifier;
use App\Models\ProductVariant;
use App\Models\User;
use App\Support\ProductIdentifierCode;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Wyszukiwarka listy produktów po numerach ze źródła ceny (product_identifiers) — zgłoszenie 06.10.2026: ELTEN MAVERICK
 * black 0723381-0 leży na karcie 0723341-0 (kolory red + black w jednej karcie) i „723381” nie znajdowało nic.
 */
final class ProductIdentifierSearchTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolesAndPermissionsSeeder::class);
        Cache::forget('nbp.table_a.rates');
        Http::fake(['api.nbp.pl/*' => Http::response([['effectiveDate' => '2026-10-06', 'rates' => [['code' => 'EUR', 'mid' => 4.0]]]])]);
        Sanctum::actingAs(User::factory()->withRole('admin')->create());
    }

    public function test_article_number_of_a_merged_colour_finds_the_card_and_shows_the_number(): void
    {
        $maverick = $this->elten();
        $this->card('X-1', 'Kurtka', 'PROS');

        foreach (['723381', '0723381', '0723381-0', '07233810', '0723381 0'] as $q) {
            $rows = $this->rows($q);
            $this->assertSame([$maverick->id], array_column($rows, 'id'), $q);
            $this->assertSame(['0723381-0 (black)'], $rows[0]['matched_codes'], $q);
            $this->assertSame([], $rows[0]['erp_codes'], $q);
        }
        // numer równy SKU karty — karta jak dotąd, numeru nie powtarzamy obok SKU
        $rows = $this->rows('0723341-0');
        $this->assertSame([$maverick->id], array_column($rows, 'id'));
        $this->assertSame([], $rows[0]['matched_codes']);
    }

    public function test_phrase_with_a_name_or_brand_and_the_number_finds_the_card(): void
    {
        $maverick = $this->elten();
        $this->card('X-1', 'ELTEN LARROX Low', 'ELTEN');

        $this->assertSame([$maverick->id], array_column($this->rows('elten 723381'), 'id'));
        $this->assertSame([$maverick->id], array_column($this->rows('maverick 723381'), 'id'));
        $this->assertSame([$maverick->id], array_column($this->rows('Maverick 723381 43'), 'id'));
        // numer pasuje, ale wpisana marka nie — karta ELTEN nie wychodzi
        $this->assertSame([], $this->rows('uvex 723381'));
        // okno „Połącz towar XL z kartą” (każde słowo zawęża listę)
        $rows = $this->getJson('/api/products?words=all&q='.rawurlencode('maverick 723381'))->assertOk()->json('data');
        $this->assertSame([$maverick->id], array_column($rows, 'id'));
        $this->assertSame(['0723381-0 (black)'], $rows[0]['matched_codes']);
        $this->assertSame(
            [['word' => 'maverick', 'count' => 1], ['word' => '723381', 'count' => 1]],
            $this->getJson('/api/products/word-counts?q='.rawurlencode('maverick 723381'))->assertOk()->json('words'),
        );
    }

    public function test_numbers_removed_from_the_source_do_not_count(): void
    {
        $card = $this->card('0723341-0', 'ELTEN MAVERICK Low ESD S3S', 'ELTEN');
        $this->identifier($card, '0723381-0', 'black', removed: true);

        $this->assertSame([], $this->rows('723381'));
    }

    public function test_number_needs_five_characters_with_a_digit(): void
    {
        $card = $this->card('S503', 'Portwest Kurtka Navy', 'Portwest');
        $this->identifier($card, 'S503NVRM', 'Navy (NVR) / M');
        $this->identifier($card, 'ABCDEFGH', null, type: ProductIdentifier::TYPE_ALT_CODE);

        $this->assertSame(['S503'], array_column($this->rows('S503NVRM'), 'sku'));
        $this->assertSame(['S503'], array_column($this->rows('503NV'), 'sku'));
        // krótki kawałek albo same litery — numer nie trafia
        $this->assertSame([], $this->rows('03NV'));
        $this->assertSame([], $this->rows('abcdefgh'));
    }

    public function test_ean_matches_only_whole(): void
    {
        $card = $this->card('U-1', 'Okulary', 'UVEX');
        $this->identifier($card, '4031101234564', null, type: ProductIdentifier::TYPE_EAN);
        $this->card('U-2', 'Okulary inne', 'UVEX');

        $this->assertSame(['U-1'], array_column($this->rows('4031101234564'), 'sku'));
        $this->assertSame(['U-1'], array_column($this->rows('04031101234564'), 'sku'));
        $this->assertSame([], $this->rows('40311012345'));
    }

    public function test_card_with_the_whole_number_comes_first_and_other_filters_still_apply(): void
    {
        // nazwa z numerem w środku — trafienie po nazwie; karta z numerem rozmiaru równym frazie stoi przed nią
        $this->card('A-1', 'Kurtka zamiennik S503NVRM', 'PROS');
        $portwest = $this->card('Z-1', 'Kurtka Navy', 'Portwest');
        $this->identifier($portwest, 'S503NVRM', 'Navy (NVR) / M');

        $this->assertSame(['Z-1', 'A-1'], array_column($this->rows('S503NVRM'), 'sku'));
        $this->assertSame(['A-1'], array_column(
            $this->getJson('/api/products?sort=sku&manufacturer=PROS&q=S503NVRM')->assertOk()->json('data'),
            'sku',
        ));
    }

    public function test_size_code_finds_the_card_when_only_the_size_row_knows_it(): void
    {
        // Canis: łącznik zapisuje numer koloru, kod rozmiaru zna tylko wiersz rozmiaru
        $canis = $this->card('1010-001-410-00', 'Bluza CXS SIRIUS LUCIUS, męska', 'Canis');
        $this->sizeRow($canis, '1010-001-410-46', 'kolor niebiesko-szary / 46');
        $this->sizeRow($canis, '1010-001-410-48', 'kolor niebiesko-szary / 48', removed: true);
        $this->sizeRow($canis, '3310-001-163-10', '3310-001-163-10');

        $rows = $this->rows('1010-001-410-46');
        $this->assertSame([$canis->id], array_column($rows, 'id'));
        $this->assertSame(['1010-001-410-46 (kolor niebiesko-szary / 46)'], $rows[0]['matched_codes']);
        $this->assertSame([$canis->id], array_column($this->rows('canis 1010-001-410-46'), 'id'));
        $this->assertSame([], $this->rows('uvex 1010-001-410-46'));
        // rozmiar zdjęty ze źródła nie liczy się
        $this->assertSame([], $this->rows('1010-001-410-48'));
        // etykieta równa kodowi nie powtarza się w nawiasie
        $this->assertSame(['3310-001-163-10'], $this->rows('3310-001-163-10')[0]['matched_codes']);
    }

    public function test_card_found_by_its_number_does_not_list_size_codes(): void
    {
        $maverick = $this->elten();
        $this->sizeRow($maverick, '0723381-0 38', 'black / 38');
        $this->sizeRow($maverick, '0723381-0 39', 'black / 39');

        $this->assertSame(['0723381-0 (black)'], $this->rows('723381')[0]['matched_codes']);
        $this->assertSame(['0723381-0 (black)'], $this->rows('0723381-0 38')[0]['matched_codes']);
    }

    public function test_global_search_shows_the_matched_number(): void
    {
        $maverick = $this->elten();

        $items = collect($this->getJson('/api/search?q=723381')->assertOk()->json('groups'))->firstWhere('key', 'products')['items'];

        $this->assertSame([$maverick->id], array_column($items, 'id'));
        $this->assertSame('numer w cenniku: 0723381-0 (black)', $items[0]['detail']);
    }

    /** @return list<array<string, mixed>> */
    private function rows(string $q): array
    {
        return $this->getJson('/api/products?sort=sku&q='.rawurlencode($q))->assertOk()->json('data');
    }

    /** Karta ELTEN z dwoma kolorami jak na produkcji (#63713): red 0723341-0 (wiodący) i black 0723381-0. */
    private function elten(): Product
    {
        $card = $this->card('0723341-0', 'ELTEN MAVERICK Low ESD S3S', 'ELTEN');
        $this->identifier($card, '0723341-0', 'red');
        $this->identifier($card, '0723381-0', 'black');

        return $card;
    }

    private function card(string $sku, string $name, string $manufacturer): Product
    {
        return Product::query()->create([
            'sku' => $sku, 'name' => $name, 'manufacturer' => $manufacturer,
            'catalog_price_net' => 10, 'purchase_price' => 5, 'stock' => 0,
        ]);
    }

    private function sizeRow(Product $card, string $sku, string $label, bool $removed = false): void
    {
        ProductVariant::query()->create([
            'product_id' => $card->id, 'kind' => ProductVariant::KIND_SIZE, 'source' => 'b2b:36', 'remote_id' => $sku,
            'sku' => $sku, 'label' => $label, 'removed_at' => $removed ? now() : null,
        ]);
    }

    private function identifier(
        Product $card,
        string $value,
        ?string $label,
        string $type = ProductIdentifier::TYPE_SOURCE_CODE,
        bool $removed = false,
    ): void {
        ProductIdentifier::query()->create([
            'product_id' => $card->id, 'source_key' => 'b2b:26', 'position_key' => $value.'/38', 'type' => $type,
            'value' => $value, 'normalized' => ProductIdentifierCode::normalize($type, $value), 'variant_label' => $label,
            'removed_at' => $removed ? now() : null,
        ]);
    }
}
