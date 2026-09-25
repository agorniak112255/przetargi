<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Product;
use App\Services\ProductAiSearchService;
use App\Support\PpeAssortment;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Golden opisowy15-03 (K10, 25.09.2026): wiersz zapasowy ARSO 701 616560 S1 P ESD (nazwa = goły kod, opis „Sandał
 * bezpieczny…”) dostawał dopisek „karta nie potwierdza, że to sandał”, choć bramka asortymentu czyta typ z opisu.
 */
final class UnratedRowFootwearTypeNoteTest extends TestCase
{
    use RefreshDatabase;

    private const QUERY = 'Sandały ochronne S1P ESD z odkrytą cholewką';

    public function test_bare_code_card_described_as_sandal_gets_no_false_note(): void
    {
        $card = $this->card('ARSO 701 616560 S1 P ESD', 'Sandał bezpieczny ARSO 701 616560 S1 P ESD z oddychającą cholewką. Podeszwa PU/TPU.');

        $this->assertSame('', $this->note($card));
    }

    public function test_card_without_type_anywhere_keeps_the_note(): void
    {
        $card = $this->card('ARSO 701 616560 S1 P ESD', 'Model ARSO 701 616560 S1 P ESD z oddychającą cholewką. Podeszwa PU/TPU.');

        $this->assertStringContainsString('nie potwierdza', $this->note($card));
    }

    public function test_type_named_on_card_wins_over_description(): void
    {
        $card = $this->card('Półbuty bezpieczne AROSIO 730 S1 P ESD', 'Sandał bezpieczny AROSIO 730 dla porównania. Półbut z noskiem.');

        $this->assertStringContainsString('a karta to', $this->note($card));
    }

    private function note(Product $product): string
    {
        $service = app(ProductAiSearchService::class);
        $nameType = app(PpeAssortment::class)->articleType($product->name.' '.$product->sku);
        $cardType = (new \ReflectionMethod($service, 'noteCardType'))->invoke($service, self::QUERY, $product, $nameType);
        $wantType = app(PpeAssortment::class)->articleType(self::QUERY);

        return (new \ReflectionMethod($service, 'unratedTypeGapNote'))->invoke($service, $wantType, $cardType);
    }

    private function card(string $name, string $description): Product
    {
        return Product::query()->create([
            'sku' => $name,
            'name' => $name,
            'manufacturer' => 'ARTRA',
            'description' => $description,
            'catalog_price_net' => 100,
            'purchase_price' => 60,
            'stock' => 1,
            'enrichment_status' => Product::ENRICHMENT_DONE,
        ]);
    }
}
