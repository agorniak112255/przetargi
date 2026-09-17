<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Product;
use App\Services\ProductAiSearchService;
use App\Support\BhpAttributeNormalizer;
use App\Support\PpeAssortment;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Etap 2: tabelka z karty wyrobu u dostawcy wychodzi z products.description do products.shop_fields_summary.
 * U Protektu „Normy: EN 361…” nigdy nie były prozą opisu — po przeprowadzce karta rankingowa, normalizator
 * atrybutów BHP i rodzina asortymentu muszą czytać oba źródła, inaczej normy i parametry znikają z dopasowania.
 */
final class ShopFieldsRankingTest extends TestCase
{
    use RefreshDatabase;

    private const PROTEKT_SHOP_FIELDS = "Informacje handlowe\n"
        ."Kod towaru: 67E/2-LS PC\n"
        ."Jednostka sprzedaży: SZT\n"
        ."Specyfikacja techniczna\n"
        ."Materiał: taśma poliestrowa\n"
        .'Norma: EN 361';

    public function test_rank_card_shows_shop_fields_and_their_norms_when_description_is_empty(): void
    {
        $product = $this->protektHarness();

        $card = $this->rankCard($product, false);

        $this->assertContains('Norma: EN 361', $card['shop_fields'], 'wiersze karty dostawcy idą do modelu osobnym kluczem');
        $this->assertContains('Kod towaru: 67E/2-LS PC', $card['shop_fields']);
        $this->assertNotContains('Specyfikacja techniczna', $card['shop_fields'], 'sam nagłówek sekcji nie jest dowodem');
        $this->assertContains('EN 361', $card['description_norms'], 'norma z karty dostawcy nie może zniknąć z karty rankingowej');
        $this->assertSame('', (string) ($card['description'] ?? ''), 'dane sklepu nie są doklejane do opisu');
    }

    public function test_short_card_keeps_shop_fields_within_the_specs_limit(): void
    {
        $product = $this->protektHarness();

        $short = $this->rankCard($product, true);
        $long = $this->rankCard($product, false);

        $this->assertCount(2, $short['shop_fields'], 'karta krótka bierze tyle samo pozycji co specs');
        $this->assertLessThanOrEqual(8, count($long['shop_fields']));
        $this->assertContains('EN 361', $short['description_norms'], 'przycięte wiersze nie gubią normy — zostaje w description_norms');
        $this->assertArrayNotHasKey('description', $short);
    }

    public function test_bhp_attribute_normalizer_reads_norm_and_material_from_shop_fields(): void
    {
        $product = Product::query()->create([
            'sku' => '44-304',
            'name' => 'ATG 44-304',
            'manufacturer' => 'ATG',
            'category' => 'Rękawice',
            'description' => '',
            'shop_fields_summary' => "Specyfikacja techniczna\nMateriał: nitryl\nNorma: EN 388:2016",
            'enrichment_status' => Product::ENRICHMENT_DONE,
        ]);

        $attrs = app(BhpAttributeNormalizer::class)->forProduct($product);

        $this->assertContains('EN 388:2016', $attrs['normy_en']);
        $this->assertContains('nitryl', $attrs['materialy']);
    }

    public function test_ppe_family_is_resolved_from_shop_fields_only(): void
    {
        $product = Product::query()->create([
            'sku' => 'AB-101',
            'name' => 'PROTEKT AB-101',
            'manufacturer' => 'PROTEKT',
            'category' => 'Sprzęt chroniący przed upadkiem',
            'description' => '',
            'shop_fields_summary' => "Specyfikacja techniczna\nRodzaj: szelki bezpieczeństwa\nNorma: EN 361",
            'enrichment_status' => Product::ENRICHMENT_DONE,
        ]);

        $this->assertSame(
            PpeAssortment::FAMILY_FALL,
            app(PpeAssortment::class)->productFamily($product),
            'rodzinę widać tylko w tabelce z karty dostawcy'
        );
    }

    private function protektHarness(): Product
    {
        return Product::query()->create([
            'sku' => '67E/2-LS PC',
            'name' => 'PROTEKT 67E/2-LS PC',
            'manufacturer' => 'PROTEKT',
            'category' => 'Szelki bezpieczeństwa',
            'description' => '',
            'shop_fields_summary' => self::PROTEKT_SHOP_FIELDS,
            'catalog_price_net' => 100,
            'purchase_price' => 60,
            'stock' => 1,
            'enrichment_status' => Product::ENRICHMENT_DONE,
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function rankCard(Product $product, bool $short): array
    {
        $service = app(ProductAiSearchService::class);

        /** @var array<string, mixed> $card */
        $card = (new \ReflectionMethod($service, 'rankCard'))->invoke($service, $product, $short, []);

        return $card;
    }
}
