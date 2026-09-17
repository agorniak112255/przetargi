<?php

declare(strict_types=1);

namespace Tests\Unit\RequirementCheck;

use App\Models\Product;
use App\Support\RequirementCheck\CardSource;
use App\Support\RequirementCheck\CardSources;
use PHPUnit\Framework\TestCase;

/**
 * Parametry z cennika rozstrzygają o klasie ochrony przy dopasowaniu, więc muszą dać się pokazać
 * w weryfikacji karty. Bez tego panel mówiłby „karta tego nie podaje” o wartości, na której
 * dopasowanie właśnie oparło decyzję.
 */
final class PriceListCardSourceTest extends TestCase
{
    public function test_price_list_columns_are_quotable_sources(): void
    {
        $product = new Product([
            'name' => 'ARYEL 320 671460 S3L',
            'price_list_attributes' => ['klasa_ochrony' => 'S3L', 'rozmiar' => '35-48'],
        ]);

        $texts = [];
        foreach (CardSources::fromProduct($product) as $source) {
            if ($source->source === CardSource::PRICE_LIST) {
                $texts[] = $source->text;
            }
        }

        $this->assertSame(['Klasa ochrony: S3L', 'Rozmiar: 35-48'], $texts);
    }

    public function test_card_without_price_list_columns_has_no_such_source(): void
    {
        $product = new Product(['name' => 'ARYEL 320 671460 S3L']);

        foreach (CardSources::fromProduct($product) as $source) {
            $this->assertNotSame(CardSource::PRICE_LIST, $source->source);
        }
    }
}
