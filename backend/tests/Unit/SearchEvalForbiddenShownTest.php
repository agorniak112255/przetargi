<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Support\SearchEvalMetrics;
use PHPUnit\Framework\TestCase;

/**
 * Decyzja B z 25.09.2026: zakazana karta pokazana poniżej progu zapisu pod właściwą kartą ≥ progu
 * (ARMEN 6660 z 60% pod 1010 z 99%) to ostrzeżenie o kolorze, nie błąd blokujący. Ścisłe `violations`
 * zostaje bez zmian; `forbiddenShown` wydziela z niego takie wiersze.
 */
final class SearchEvalForbiddenShownTest extends TestCase
{
    private const MIN = 65;

    public function test_proposal_below_threshold_under_a_right_card_is_shown(): void
    {
        $rows = [$this->row('GOOD', 99), $this->row('BAD', 60)];

        $this->assertSame(['BAD'], SearchEvalMetrics::forbiddenShown(['BAD'], ['GOOD'], $rows, 10, self::MIN));
    }

    /** Recenzja 25.09.2026: procent wiersza reguły/listy zapasowej to kolejność z puli, nie ocena — nie osłania. */
    public function test_rule_or_catalog_row_above_threshold_does_not_cover_a_forbidden_proposal(): void
    {
        foreach (['rule', 'catalog'] as $source) {
            $rows = [['sku' => 'GOOD', 'percent' => 99, 'source' => $source], $this->row('BAD', 60)];

            $this->assertSame([], SearchEvalMetrics::forbiddenShown(['BAD'], ['GOOD'], $rows, 10, self::MIN), $source);
        }
    }

    public function test_forbidden_card_at_or_above_threshold_stays_blocking(): void
    {
        $this->assertSame([], SearchEvalMetrics::forbiddenShown(['BAD'], ['GOOD'], [$this->row('GOOD', 99), $this->row('BAD', 65)], 10, self::MIN));
        $this->assertSame([], SearchEvalMetrics::forbiddenShown(['BAD'], ['GOOD'], [$this->row('GOOD', 99), $this->row('BAD', 99)], 10, self::MIN));
    }

    public function test_forbidden_card_without_a_right_card_above_it_stays_blocking(): void
    {
        // zakazana nad wzorcową
        $this->assertSame([], SearchEvalMetrics::forbiddenShown(['BAD'], ['GOOD'], [$this->row('BAD', 60), $this->row('GOOD', 99)], 10, self::MIN));
        // wzorcowa nad nią, ale poniżej progu
        $this->assertSame([], SearchEvalMetrics::forbiddenShown(['BAD'], ['GOOD'], [$this->row('GOOD', 64), $this->row('BAD', 60)], 10, self::MIN));
        // nad nią tylko karta neutralna
        $this->assertSame([], SearchEvalMetrics::forbiddenShown(['BAD'], ['GOOD'], [$this->row('OTHER', 99), $this->row('BAD', 60)], 10, self::MIN));
        // wiersz bez oceny
        $this->assertSame([], SearchEvalMetrics::forbiddenShown(['BAD'], ['GOOD'], [$this->row('GOOD', 99), $this->row('BAD', null)], 10, self::MIN));
    }

    public function test_forbidden_card_does_not_shield_another_forbidden_card(): void
    {
        $rows = [$this->row('BAD-1', 99), $this->row('BAD-2', 60)];

        $this->assertSame([], SearchEvalMetrics::forbiddenShown(['BAD-1', 'BAD-2'], ['GOOD'], $rows, 10, self::MIN));
    }

    public function test_only_top_k_counts_like_violations(): void
    {
        $rows = [$this->row('GOOD', 99), $this->row('X', 90), $this->row('BAD', 60)];

        $this->assertSame([], SearchEvalMetrics::violations(['BAD'], array_column($rows, 'sku'), 2));
        $this->assertSame([], SearchEvalMetrics::forbiddenShown(['BAD'], ['GOOD'], $rows, 2, self::MIN));
        $this->assertSame(['BAD'], SearchEvalMetrics::forbiddenShown(['BAD'], ['GOOD'], $rows, 3, self::MIN));
    }

    public function test_same_sku_twice_is_shown_only_when_every_occurrence_qualifies(): void
    {
        $rows = [$this->row('GOOD', 99), $this->row('bad', 60), $this->row('BAD ', 90)];

        $this->assertSame([], SearchEvalMetrics::forbiddenShown(['BAD'], ['GOOD'], $rows, 10, self::MIN));
    }

    public function test_hits_at_lists_skus_from_the_list_found_in_top_k(): void
    {
        $ranked = ['X', 'b-2', 'A-1', 'C-3'];

        $this->assertSame(['A-1', 'B-2'], SearchEvalMetrics::hitsAt(['A-1', 'B-2', 'C-3'], $ranked, 3));
        $this->assertSame([], SearchEvalMetrics::hitsAt([], $ranked, 3));
    }

    /** @return array{sku: string, percent: int|null} */
    private function row(string $sku, ?int $percent): array
    {
        return ['sku' => $sku, 'percent' => $percent];
    }
}
