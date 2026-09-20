<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Support\ProductModelFuzzy;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Szukanie zamienników nie może kotwiczyć się na marce i modelu z wymagania — retrieval oddaje
 * wtedy wyłącznie karty nazwanego modelu, czyli to, co zamiennik ma zastąpić. Cechy wyrobu
 * (mocowanie, klasa, normy) muszą zostać, bo zamiennik ma je spełniać.
 */
final class ProductModelFuzzyWithoutNamedModelTest extends TestCase
{
    use RefreshDatabase;

    private function fuzzy(): ProductModelFuzzy
    {
        return $this->app->make(ProductModelFuzzy::class);
    }

    public function test_brand_and_model_words_are_removed_but_features_stay(): void
    {
        $fuzzy = $this->fuzzy();

        $stripped = $fuzzy->withoutNamedModel('Nauszniki przeciwhałasowe 3M Peltor X2 wersja nagłowna');
        $this->assertSame('Nauszniki przeciwhałasowe wersja nagłowna', $stripped);
        $this->assertFalse($fuzzy->usesModelAnchoredCatalogSearch($stripped), 'po zdjęciu modelu retrieval dalej się kotwiczy');

        $this->assertSame(
            'Rękawice EN 388 EN 511',
            $fuzzy->withoutNamedModel('Rękawice MAPA TEMP-ICE 700 EN 388 EN 511')
        );
    }

    public function test_requirement_without_named_model_is_left_untouched(): void
    {
        $query = 'Rękawice PCV długie do łokci';

        $this->assertSame($query, $this->fuzzy()->withoutNamedModel($query));
    }
}
