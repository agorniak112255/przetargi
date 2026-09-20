<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Product;
use App\Services\Search\AiProductSearch;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Krótki kod modelu z zapytania („X2”) był szukany jako dowolny fragment nazwy i numeru, więc
 * trafiał w kable „PELTOR FLX2-200”. Te trafienia szły jako priorytetowe i przy nazwanym modelu
 * wyszukiwanie wracało z nimi od razu — pod „Peltor X2 wersja nagłowna” na czele stały kable do
 * radiotelefonów. Kod nazwanego modelu ma zaczynać oznaczenie na karcie, nie być ogonem dłuższego
 * słowa. Oznaczeń klas i filtrów („P3”, „S1”) reguła nie dotyczy — „P3” w „A2B2E2K2HgP3” stoi
 * po literze i jest poprawnym trafieniem.
 */
final class ProductAiSearchShortCodeBoundaryTest extends TestCase
{
    use RefreshDatabase;

    public function test_short_model_code_does_not_match_the_tail_of_a_longer_word(): void
    {
        // Opis z prawdziwej karty: przez „zestawy słuchawkowe ochronne” kabel dostaje rodzinę ochrony
        // słuchu i przechodzi bramkę zgodności — dlatego na produkcji dochodził do wyniku.
        $cable = $this->make(
            'FLX2-200',
            '3M™ PELTOR™ FLX2 Kabel J11 standardowy, Ex, FLX2-200',
            'Kabel 3M PELTOR FLX2 J11 Standard (FLX2-200) to profesjonalny przewód łączący zestawy słuchawkowe ochronne, '
            .'nauszniki przeciwhałasowe 3M PELTOR z radiotelefonami w systemach łączności.'
        );
        $earmuff = $this->make('1575818', '3M™ PELTOR™ Nauszniki przeciwhałasowe, żółte, nagłowne, X2A', 'Nauszniki przeciwhałasowe nagłowne, SNR 31 dB.');

        $ids = $this->app->make(AiProductSearch::class)
            ->candidates('Nauszniki przeciwhałasowe 3M Peltor X2 wersja nagłowna', 40)
            ->pluck('id')
            ->all();

        $this->assertContains($earmuff->id, $ids, 'nauszniki X2A nie weszły do puli');
        $this->assertNotContains($cable->id, $ids, 'kabel FLX2 wszedł do puli jako trafienie kodu „X2”');
    }

    public function test_filter_class_code_inside_a_combined_name_still_matches(): void
    {
        // „P3” nie jest kodem nazwanego modelu, więc granica słowa go nie dotyczy — a w „…HgP3”
        // stoi po literze, więc reguła dla modeli odrzuciłaby poprawne trafienie klasy filtra.
        $combined = $this->make('6099', 'Filtropochłaniacz 3M™ 6099, A2B2E2K2HgP3', 'Filtropochłaniacz wielogazowy z filtrem cząstek P3.');

        $ids = $this->app->make(AiProductSearch::class)
            ->candidates('Filtropochłaniacz do półmaski klasa P3', 40)
            ->pluck('id')
            ->all();

        $this->assertContains($combined->id, $ids, 'kod klasy po cyfrze („…HgP3”) przestał trafiać');
    }

    private function make(string $sku, string $name, string $description): Product
    {
        return Product::query()->create([
            'sku' => $sku,
            'name' => $name,
            'manufacturer' => '3M',
            'category' => 'Ochrona',
            'description' => $description,
            'catalog_price_net' => 50,
            'purchase_price' => 30,
            'stock' => 3,
            'enrichment_status' => Product::ENRICHMENT_DONE,
            'enriched_at' => now(),
        ]);
    }
}
