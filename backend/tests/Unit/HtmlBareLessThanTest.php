<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Support\HtmlBareLessThan;
use PHPUnit\Framework\TestCase;

/**
 * Sprawdza sam tekst przed loadHTML — błąd siedzi w libxml 2.9 z produkcji, a lokalny libxml 2.10 go nie ma, więc
 * test drzewa DOM przeszedłby także bez poprawki.
 */
final class HtmlBareLessThanTest extends TestCase
{
    public function test_less_than_before_a_digit_or_space_becomes_an_entity(): void
    {
        $this->assertSame('3950 - &lt;4700 (OD4+)', HtmlBareLessThan::escape('3950 - <4700 (OD4+)'));
        $this->assertSame('rezystancja skrośna &lt;35 megaomów', HtmlBareLessThan::escape('rezystancja skrośna <35 megaomów'));
        $this->assertSame('napięcie &lt; 1000 V, &lt;= 5 mm, &lt;-', HtmlBareLessThan::escape('napięcie < 1000 V, <= 5 mm, <-'));
    }

    public function test_tags_comments_and_declarations_stay_untouched(): void
    {
        $html = '<?xml encoding="utf-8"?><!DOCTYPE html><!-- uwaga --><p class="a">x<br/><B>y</B></p><a href="/b?a=1&amp;b=2">z</a>';

        $this->assertSame($html, HtmlBareLessThan::escape($html));
    }
}
