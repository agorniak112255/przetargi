<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Product;
use App\Services\ProductMatchService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Wybór między kartami, które przeszły progi: ocena modelu → weto dowodów ze słów przy dużej różnicy → twarde
 * dowody → cena. Przetarg 1 poz. 2 (tenders:debug-match): model dał 95 osiemnastu rękawicom antyprzecięciowym,
 * a wygrywała karta z najdłuższym opisem zamiast pasującej i najtańszej.
 */
final class ProductMatchSelectionOrderTest extends TestCase
{
    use RefreshDatabase;

    public function test_equal_model_scores_pick_cheaper_card_over_richer_description(): void
    {
        $chemical = $this->card('76-833', 59.24);
        $canis = $this->card('3630-024-700-00', 6.09);
        $thin = $this->card('16500100', 13.83);

        $picked = $this->pick([
            $this->option($chemical, 95, 98),
            $this->option($canis, 95, 81),
            $this->option($thin, 95, 40),
        ]);

        $this->assertSame('3630-024-700-00', $picked, 'najdłuższy opis nie wygrywa z tańszą kartą o równej ocenie modelu');
    }

    public function test_large_word_evidence_gap_still_vetoes_cheap_card(): void
    {
        $insulating = $this->card('T5912100', 200);
        $esd = $this->card('ART 702 Air 6660 OB A E FO', 100);

        $picked = $this->pick([
            $this->option($insulating, 92, 99),
            $this->option($esd, 92, 35),
        ]);

        $this->assertSame('T5912100', $picked, 'karta z dowodami 35 nie wygrywa ceną z kartą z dowodami 99');
    }

    public function test_higher_model_tier_beats_cheaper_card_with_same_evidence(): void
    {
        $atg = $this->card('44-304', 32.84);
        $mapa = $this->card('34580008', 17.90);

        $picked = $this->pick([
            $this->option($atg, 95, 99),
            $this->option($mapa, 90, 99),
        ]);

        $this->assertSame('44-304', $picked, '95 i 90 modelu to różne poziomy — cena nie przestawia oceny');
    }

    public function test_model_scores_within_margin_are_a_tie_decided_by_price(): void
    {
        $dearer = $this->card('A-96', 50);
        $cheaper = $this->card('B-93', 30);

        $picked = $this->pick([
            $this->option($dearer, 96, 60),
            $this->option($cheaper, 93, 60),
        ]);

        $this->assertSame('B-93', $picked);
    }

    /** @param  list<array{product: Product, score: int, source: string, evidence: int, hard: int}>  $options */
    private function pick(array $options): string
    {
        $service = app(ProductMatchService::class);
        $picked = (new \ReflectionMethod($service, 'preferCheapestAmongCloseScores'))->invoke($service, $options);

        return (string) $picked['product']->sku;
    }

    /** @return array{product: Product, score: int, source: string, evidence: int, hard: int} */
    private function option(Product $product, int $score, int $evidence): array
    {
        return ['product' => $product, 'score' => $score, 'source' => 'ai', 'evidence' => $evidence, 'hard' => 0];
    }

    private function card(string $sku, float $price): Product
    {
        return Product::query()->create([
            'sku' => $sku,
            'name' => 'Karta '.$sku,
            'manufacturer' => 'X',
            'description' => 'Karta testowa z opisem dłuższym niż dwadzieścia cztery znaki.',
            'catalog_price_net' => $price,
            'purchase_price' => $price,
            'currency' => 'PLN',
            'stock' => 1,
            'enrichment_status' => Product::ENRICHMENT_DONE,
        ]);
    }
}
