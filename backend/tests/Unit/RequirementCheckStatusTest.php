<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Support\RequirementCheck\CardSource;
use App\Support\RequirementCheck\CheckRow;
use App\Support\RequirementCheck\Status;
use PHPUnit\Framework\TestCase;

final class RequirementCheckStatusTest extends TestCase
{
    public function test_no_card_findings_means_missing_not_fail(): void
    {
        $this->assertSame(Status::Missing, Status::fromCardVerdicts([]));
    }

    public function test_card_fields_that_contradict_each_other_are_unclear(): void
    {
        // kolumna norm „kat. II”, specyfikacja „Kategoria: III” — nie wiemy, któremu polu wierzyć
        $this->assertSame(Status::Unclear, Status::fromCardVerdicts([Status::Ok, Status::Fail]));
    }

    public function test_worst_verdict_wins_otherwise(): void
    {
        $this->assertSame(Status::Ok, Status::fromCardVerdicts([Status::Ok, Status::Ok]));
        $this->assertSame(Status::Unclear, Status::fromCardVerdicts([Status::Ok, Status::Unclear]));
        $this->assertSame(Status::Fail, Status::worst([Status::Ok, Status::Missing, Status::Fail]));
        $this->assertSame(Status::Missing, Status::worst([Status::Ok, Status::Missing]));
        $this->assertSame(Status::Missing, Status::worst([]));
    }

    public function test_finding_offers_search_phrase_only_when_it_is_in_the_shown_text(): void
    {
        $specs = new CardSource(CardSource::SPECS, 'Długość: 19 cali (47,5 cm)');
        $name = new CardSource(CardSource::NAME, "HyFlex 11202 SIZE 19''/47,5 cm");

        $this->assertSame('47,5 cm', CheckRow::finding($specs, '47,5 cm', Status::Ok)['find']);
        $this->assertNull(CheckRow::finding($specs, '475 mm', Status::Ok)['find'], 'wartość po przeliczeniu nie jest w tekście karty');
        $this->assertNull(CheckRow::finding($name, '47,5 cm', Status::Ok)['find'], 'nazwy nie ma w opisie okna');
    }

    public function test_long_quote_is_cut_around_the_value(): void
    {
        $text = str_repeat('Rękawy ochronne do prac z ryzykiem przecięcia. ', 6).'Model o długości 19 cali (47,5 cm) w kolorze wysokiej widoczności. '.str_repeat('Pranie w 40°C. ', 6);

        $quote = CheckRow::quote($text, '47,5 cm');

        $this->assertStringContainsString('47,5 cm', $quote);
        $this->assertLessThanOrEqual(122, mb_strlen($quote));
        $this->assertStringStartsWith('…', $quote);
    }

    public function test_quote_finds_value_split_by_line_break_in_long_description(): void
    {
        $text = str_repeat('Opis ogólny rękawów ochronnych HyFlex. ', 40)."Model o długości\n19 cali (47,5 cm).".str_repeat(' Pranie w 40°C.', 40);

        $quote = CheckRow::quote($text, "długości\n19 cali");

        $this->assertStringContainsString('długości 19 cali', $quote);
        $this->assertLessThanOrEqual(122, mb_strlen($quote));
    }

    public function test_value_longer_than_quote_limit_does_not_break_quote(): void
    {
        $value = str_repeat('EN 388:2016+A1:2018 ', 8);

        $quote = CheckRow::quote(str_repeat('x ', 200).$value.str_repeat(' y', 200), $value);

        $this->assertStringContainsString('EN 388', $quote);
        $this->assertLessThanOrEqual(122, mb_strlen($quote));
    }
}
