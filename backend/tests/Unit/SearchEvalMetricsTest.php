<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Support\SearchEvalMetrics;
use PHPUnit\Framework\TestCase;

final class SearchEvalMetricsTest extends TestCase
{
    public function test_recall_counts_expected_skus_regardless_of_order(): void
    {
        $expected = ['A-1', 'B-2'];

        $this->assertSame(1.0, SearchEvalMetrics::recall($expected, ['B-2', 'X', 'A-1']));
        $this->assertSame(0.5, SearchEvalMetrics::recall($expected, ['X', 'A-1']));
        $this->assertSame(0.0, SearchEvalMetrics::recall($expected, ['X', 'Y']));
    }

    public function test_sku_comparison_ignores_case_and_double_spaces(): void
    {
        $this->assertSame(1.0, SearchEvalMetrics::recall(['8145-S1PL ESD'], ['8145-s1pl  esd']));
    }

    public function test_empty_expected_set_is_never_a_hit(): void
    {
        $this->assertSame(0.0, SearchEvalMetrics::recall([], ['A-1']));
        $this->assertSame(0.0, SearchEvalMetrics::ndcgAt([], ['A-1'], 10));
    }

    public function test_recall_at_k_ignores_hits_below_the_cut(): void
    {
        $ranked = ['X', 'Y', 'A-1'];

        $this->assertSame(0.0, SearchEvalMetrics::recallAt(['A-1'], $ranked, 2));
        $this->assertSame(1.0, SearchEvalMetrics::recallAt(['A-1'], $ranked, 3));
    }

    public function test_precision_at_k_divides_by_k(): void
    {
        $this->assertSame(0.1, SearchEvalMetrics::precisionAt(['A-1'], ['A-1', 'X', 'Y'], 10));
        $this->assertSame(0.5, SearchEvalMetrics::precisionAt(['A-1'], ['A-1', 'X'], 2));
    }

    public function test_ndcg_rewards_hits_placed_higher(): void
    {
        $top = SearchEvalMetrics::ndcgAt(['A-1'], ['A-1', 'X', 'Y'], 10);
        $lower = SearchEvalMetrics::ndcgAt(['A-1'], ['X', 'Y', 'A-1'], 10);

        $this->assertSame(1.0, $top);
        $this->assertGreaterThan(0.0, $lower);
        $this->assertLessThan($top, $lower);
    }

    public function test_ndcg_is_one_when_all_expected_sit_on_top(): void
    {
        $this->assertSame(1.0, SearchEvalMetrics::ndcgAt(['A-1', 'B-2'], ['A-1', 'B-2', 'X'], 10));
    }

    public function test_reciprocal_rank_uses_first_hit_position(): void
    {
        $this->assertSame(1.0, SearchEvalMetrics::reciprocalRank(['A-1'], ['A-1', 'X']));
        $this->assertSame(0.5, SearchEvalMetrics::reciprocalRank(['A-1'], ['X', 'A-1']));
        $this->assertSame(0.0, SearchEvalMetrics::reciprocalRank(['A-1'], ['X', 'Y']));
    }

    public function test_violations_only_look_at_top_k(): void
    {
        $ranked = ['X', 'Y', 'BAD-1'];

        $this->assertSame([], SearchEvalMetrics::violations(['BAD-1'], $ranked, 2));
        $this->assertSame(['BAD-1'], SearchEvalMetrics::violations(['BAD-1'], $ranked, 3));
    }
}
