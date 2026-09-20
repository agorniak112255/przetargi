<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Services\ProductAiSearchService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use ReflectionMethod;
use Tests\TestCase;

/**
 * Przy równym procencie ocena modelu idzie przed wierszem, którego model nie widział.
 * Wiersze zapasowe mają płaskie 50, więc remis rozstrzygała cena zakupu: pod „kombinezon
 * chemoodporny na kwas siarkowy” na czoło listy wychodziła tania ścierka bawełniana
 * i rękaw Tyvek, a ocenione kombinezony spadały poza okno wyniku.
 */
final class ProductAiSearchRatedRowOrderTest extends TestCase
{
    use RefreshDatabase;

    /**
     * @param  list<array<string, mixed>>  $rows
     * @return list<array<string, mixed>>
     */
    private function sort(array $rows): array
    {
        $method = new ReflectionMethod(ProductAiSearchService::class, 'sortRankedByMatchPercent');

        return $method->invoke($this->app->make(ProductAiSearchService::class), $rows);
    }

    public function test_model_rated_row_beats_cheaper_catalog_row_at_equal_score(): void
    {
        $sorted = $this->sort([
            [
                'id' => 1,
                'sku' => 'SCIERKA',
                'ai_match_percent' => 50,
                'ai_match_source' => ProductAiSearchService::MATCH_SOURCE_CATALOG,
                'purchase_price_pln' => 3.0,
            ],
            [
                'id' => 2,
                'sku' => 'KOMBINEZON',
                'ai_match_percent' => 50,
                'ai_match_reason' => 'Chemoodporny, brak dowodu na kwas siarkowy 96%.',
                'purchase_price_pln' => 120.0,
            ],
        ]);

        $this->assertSame('KOMBINEZON', $sorted[0]['sku'], 'Nieoceniony wiersz katalogowy wyprzedził ocenę modelu.');
        $this->assertSame('SCIERKA', $sorted[1]['sku']);
    }

    public function test_higher_score_still_wins_over_source(): void
    {
        $sorted = $this->sort([
            [
                'id' => 1,
                'sku' => 'REGULA-92',
                'ai_match_percent' => 92,
                'ai_match_source' => ProductAiSearchService::MATCH_SOURCE_RULE,
                'purchase_price_pln' => 200.0,
            ],
            [
                'id' => 2,
                'sku' => 'MODEL-70',
                'ai_match_percent' => 70,
                'purchase_price_pln' => 10.0,
            ],
        ]);

        // Procent zostaje kluczem głównym — źródło rozstrzyga wyłącznie remis.
        $this->assertSame('REGULA-92', $sorted[0]['sku']);
    }

    public function test_cheaper_card_still_wins_between_two_model_rows(): void
    {
        $sorted = $this->sort([
            [
                'id' => 1,
                'sku' => 'DROZSZY',
                'ai_match_percent' => 80,
                'purchase_price_pln' => 300.0,
            ],
            [
                'id' => 2,
                'sku' => 'TANSZY',
                'ai_match_percent' => 80,
                'purchase_price_pln' => 90.0,
            ],
        ]);

        $this->assertSame('TANSZY', $sorted[0]['sku'], 'Remis dwóch ocen modelu ma dalej rozstrzygać cena.');
    }
}
