<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Services\ProductAiSearchService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use ReflectionMethod;
use Tests\TestCase;

/**
 * Wiersz zapasowy zostaje propozycją, ale ma powiedzieć, czego nie spełnia. Przy zapytaniu
 * „Kalosze chemoodporne…” lista pokazywała półbuty i trzewiki z notą „ten sam rodzaj w katalogu”
 * — bez słowa o tym, że to inny wyrób niż kalosz.
 */
final class ProductAiSearchTypeGapNoteTest extends TestCase
{
    use RefreshDatabase;

    private function note(?string $wantType, ?string $cardType): string
    {
        $method = new ReflectionMethod(ProductAiSearchService::class, 'unratedTypeGapNote');

        return (string) $method->invoke(
            $this->app->make(ProductAiSearchService::class),
            $wantType,
            $cardType
        );
    }

    public function test_other_article_type_is_named_in_the_note(): void
    {
        $note = $this->note('kalosz', 'polbut');

        $this->assertStringContainsString('kalosz', $note);
        $this->assertStringContainsString('polbut', $note);
    }

    public function test_unknown_card_type_says_card_does_not_confirm_it(): void
    {
        $note = $this->note('kalosz', null);

        $this->assertStringContainsString('nie potwierdza', $note);
        $this->assertStringContainsString('kalosz', $note);
    }

    public function test_matching_type_gets_no_note(): void
    {
        $this->assertSame('', $this->note('kalosz', 'kalosz'));
    }

    public function test_query_without_article_type_gets_no_note(): void
    {
        // Wymaganie bez rzeczownika typu (np. sama norma) nie ma czego porównywać.
        $this->assertSame('', $this->note(null, 'polbut'));
    }
}
