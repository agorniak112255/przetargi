<?php

declare(strict_types=1);

namespace Tests\Unit\RequirementCheck;

use App\Models\Product;
use App\Support\RequirementCheck\CardSource;
use App\Support\RequirementCheck\CardSources;
use App\Support\RequirementCheck\LevelChecker;
use Tests\TestCase;

/**
 * Tabelka z karty wyrobu u dostawcy jako źródło weryfikacji. ARTRA ma tabelki zamienione między wariantami
 * (karta 9577 z produkcji, 22.09.2026): „ARMEN 900 6060 O1 FO” pokazuje tabelkę „EN ISO 20345:2011 S1 P SRC”.
 * Normalizator zostawia klasę z nazwy, więc sprzeczność musi wyjść w weryfikacji karty — inaczej nikt jej
 * nie zobaczy.
 */
final class ShopFieldsCardSourceTest extends TestCase
{
    private const TABLE = "Parametry\ncholewka: wegańska RACYA SKINYUM™\npodnosek: stalowy LIBERYUM™\n"
        ."norma: EN ISO 20345:2011 S1 P SRC\nWaga: 430 gramów dla rozmiaru 42";

    public function test_each_row_of_the_supplier_table_is_a_separate_source(): void
    {
        $product = new Product(['name' => 'ARMEN 900 6060 O1 FO', 'shop_fields_summary' => self::TABLE]);

        $rows = array_values(array_filter(
            CardSources::fromProduct($product),
            static fn (CardSource $s): bool => $s->source === CardSource::SHOP_FIELDS,
        ));

        $this->assertSame(
            // nagłówek sekcji „Parametry” to nie twierdzenie o wyrobie — odpada
            ['cholewka: wegańska RACYA SKINYUM™', 'podnosek: stalowy LIBERYUM™', 'norma: EN ISO 20345:2011 S1 P SRC', 'Waga: 430 gramów dla rozmiaru 42'],
            array_map(static fn (CardSource $s): string => $s->text, $rows),
        );
        $this->assertFalse($rows[0]->searchable(), 'tabelki nie ma w tekście opisu okna weryfikacji');
    }

    /** Tabela rozmiarów ARTRY (~15 wierszy na karcie) i lista rozmiarów to szum dla modelu i sprawdzania wymiarów. */
    public function test_size_guide_and_size_list_are_not_card_sources(): void
    {
        $product = new Product([
            'name' => 'ARMEN 9003 2360 S1',
            'shop_fields_summary' => 'Parametry
norma: EN ISO 20345:2022 S1 FO SR
Rozmiar: 42
'
                .'Rozmiary: EU 35, EU 36, EU 37
Przewodnik po rozmiarach
Rozmiar EU 35: 21,8
Rozmiar EU 36: 22,4
'
                .'Inne
Kolor: czarny',
        ]);

        $rows = array_values(array_filter(
            CardSources::fromProduct($product),
            static fn (CardSource $s): bool => $s->source === CardSource::SHOP_FIELDS,
        ));

        $this->assertSame(
            ['norma: EN ISO 20345:2022 S1 FO SR', 'Rozmiar: 42', 'Kolor: czarny'],
            array_map(static fn (CardSource $s): string => $s->text, $rows),
        );
    }

    public function test_class_in_the_name_and_another_class_in_the_supplier_table_is_a_card_conflict(): void
    {
        $product = new Product([
            'name' => 'ARMEN 900 6060 O1 FO',
            'price_list_attributes' => ['klasa_ochrony' => 'O1'],
            'shop_fields_summary' => self::TABLE,
        ]);

        $conflicts = (new LevelChecker)->cardConflicts(CardSources::fromProduct($product));

        $this->assertCount(1, $conflicts);
        $this->assertSame('footwear_class', $conflicts[0]->key);
        $bySource = [];
        foreach ($conflicts[0]->values as $value) {
            foreach ($value['findings'] as $finding) {
                $bySource[$finding['source']][] = $value['value'];
            }
        }
        $this->assertSame(['O1'], $bySource[CardSource::NAME]);
        $this->assertSame(['O1'], $bySource[CardSource::PRICE_LIST]);
        $this->assertSame(['S1P'], $bySource[CardSource::SHOP_FIELDS]);
    }

    public function test_matching_class_in_the_supplier_table_is_no_conflict(): void
    {
        $product = new Product([
            'name' => 'ARMEN 9003 2360 S1',
            'shop_fields_summary' => "podnosek: kompozytowy LIBERYUM™\nnorma: EN ISO 20345:2022 S1 FO SR",
        ]);

        $this->assertSame([], (new LevelChecker)->cardConflicts(CardSources::fromProduct($product)));
    }
}
