<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Support\RrfFusion;
use PHPUnit\Framework\TestCase;

final class RrfFusionTest extends TestCase
{
    private RrfFusion $rrf;

    protected function setUp(): void
    {
        parent::setUp();
        $this->rrf = new RrfFusion;
    }

    public function test_card_present_in_two_sources_beats_leader_of_one(): void
    {
        $fused = $this->rrf->fuse([
            'text' => [10, 20, 30],
            'vector' => [40, 20, 50],
        ]);

        $this->assertSame(20, $fused[0]);
    }

    public function test_weight_promotes_source(): void
    {
        $unweighted = $this->rrf->fuse([
            'text' => [1, 2],
            'priority' => [2, 1],
        ]);
        $weighted = $this->rrf->fuse(
            ['text' => [1, 2], 'priority' => [2, 1]],
            ['priority' => 3.0],
        );

        $this->assertSame(1, $unweighted[0]);
        $this->assertSame(2, $weighted[0]);
    }

    public function test_zero_weight_source_is_ignored(): void
    {
        $fused = $this->rrf->fuse(
            ['text' => [7], 'vector' => [8]],
            ['vector' => 0.0],
        );

        $this->assertSame([7], $fused);
    }

    public function test_limit_and_deduplication(): void
    {
        $fused = $this->rrf->fuse(['a' => [1, 2, 3], 'b' => [3, 2, 1]], [], 2);

        $this->assertCount(2, $fused);
        $this->assertSame(array_unique($fused), $fused);
    }

    public function test_empty_input_returns_empty_list(): void
    {
        $this->assertSame([], $this->rrf->fuse([]));
        $this->assertSame([], $this->rrf->fuse(['text' => []]));
    }
}
