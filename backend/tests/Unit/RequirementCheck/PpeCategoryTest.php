<?php

declare(strict_types=1);

namespace Tests\Unit\RequirementCheck;

use App\Support\RequirementCheck\PpeCategory;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class PpeCategoryTest extends TestCase
{
    /**
     * @return iterable<string, array{string, int, string}>
     */
    public static function categories(): iterable
    {
        yield 'skrót w kolumnie norm' => ['EN ISO 21420:2020, kat. II, EN ISO 13997', 2, 'kat. II'];
        yield 'wymaganie poz. 1' => ['Wymagane: ŚOI kategorii III; EN 420', 3, 'kategorii III'];
        yield 'specyfikacja' => ['Kategoria: III', 3, 'Kategoria: III'];
        yield 'angielski wielkimi' => ['CAT III', 3, 'CAT III'];
        yield 'bez spacji po kropce' => ['EN 420:2003 + A1:2009, Cat.III EN 407', 3, 'Cat.III'];
        yield 'kolejność odwrotna' => ['środek ochrony indywidualnej II kategorii', 2, 'II kategorii'];
    }

    #[Test]
    #[DataProvider('categories')]
    public function reads_category(string $text, int $level, string $literal): void
    {
        $category = PpeCategory::first($text);

        $this->assertSame($level, $category?->level);
        $this->assertSame($literal, $category->text);
    }

    #[Test]
    public function takes_longest_roman_numeral(): void
    {
        $this->assertSame(3, PpeCategory::first('kat. III')?->level, '„kat. II” w „kat. III” to nie kategoria II');
        $this->assertSame('III', PpeCategory::first('kat. III')?->roman());
    }

    /**
     * @return iterable<string, array{string, list<int>, string}>
     */
    public static function categoryLists(): iterable
    {
        yield 'ukośniki' => ['Kategoria: I/II/III', [1, 2, 3], 'Kategoria: I/II/III'];
        yield 'zakres z łącznikiem' => ['zgodne z rozporządzeniem (kat. I-III)', [1, 3], 'kat. I-III'];
        yield 'zakres z półpauzą' => ['kat. I–III', [1, 3], 'kat. I–III'];
        yield 'wyliczenie słowne' => ['kategorii I, II i III', [1, 2, 3], 'kategorii I, II i III'];
    }

    #[Test]
    #[DataProvider('categoryLists')]
    public function reads_listed_categories_as_variants(string $text, array $levels, string $literal): void
    {
        $category = PpeCategory::first($text);

        $this->assertSame($levels, $category?->levels);
        $this->assertTrue($category->isList(), 'kilka kategorii to warianty, nie kategoria produktu');
        $this->assertSame($literal, $category->text);
        $this->assertSame(1, $category->level, 'najniższa z wymienionych');
        $this->assertFalse(PpeCategory::first('kat. II, EN ISO 13997')?->isList());
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function notCategories(): iterable
    {
        yield 'spójnik „i”' => ['produkty tej kategorii i wymagania'];
        yield 'klasa obuwia OB (poz. 12)' => ['obuwia zawodowego kategorii OB'];
        yield 'klasa obuwia S1 P (poz. 3)' => ['Sandały ochronne kategorii S1 P wg EN ISO 20345'];
        yield 'kategoria sklepu' => ['Kategoria: montaż'];
        yield 'rzymska liczba w słowie' => ['kategoria Inne'];
    }

    #[Test]
    #[DataProvider('notCategories')]
    public function ignores_text_that_is_not_ppe_category(string $text): void
    {
        $this->assertNull(PpeCategory::first($text));
    }
}
