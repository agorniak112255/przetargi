<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Support\ColourWords;
use Tests\TestCase;

/**
 * Słownik kolorów do rdzenia nazwy modelu i doboru zdjęcia w kolorze karty (etap 2 opisów z cenników).
 */
final class ColourWordsTest extends TestCase
{
    public function test_polish_and_english_colour_words_map_to_canonical_colour(): void
    {
        foreach ([
            'Szary' => 'grey', 'szare' => 'grey', 'szarego' => 'grey', 'gray' => 'grey', 'grey' => 'grey',
            'ciemnoszary' => 'grey', 'Ciemnoszara' => 'grey', 'jasnoszary' => 'grey',
            'Czarny' => 'black', 'Czarna' => 'black', 'Czarno' => 'black', 'czarnych' => 'black', 'black' => 'black',
            'Żółte' => 'yellow', 'Żółto' => 'yellow', 'Żółta' => 'yellow', 'zolty' => 'yellow', 'yellow' => 'yellow',
            'Zielony' => 'green', 'Niebieski' => 'blue', 'Czerwony' => 'red', 'Biały' => 'white', 'white' => 'white',
            'Brązowy' => 'brown', 'brown' => 'brown', 'Pomarańczowy' => 'orange', 'orange' => 'orange',
            'Beżowy' => 'beige', 'beige' => 'beige', 'Granatowy' => 'navy', 'navy' => 'navy',
            'Antracyt' => 'anthracite', 'antracytowy' => 'anthracite', 'anthracite' => 'anthracite',
            'charcoal' => 'charcoal', 'Stalowy' => 'steel', 'Clear' => 'clear', 'przezroczysty' => 'clear',
            'transparent' => 'clear', 'srebrny' => 'silver', 'silver' => 'silver', 'różowy' => 'pink', 'pink' => 'pink',
            'fioletowy' => 'purple', 'złoty' => 'gold',
        ] as $word => $colour) {
            $this->assertSame($colour, ColourWords::canonical($word), $word);
            $this->assertTrue(ColourWords::is($word), $word);
        }
    }

    public function test_is_superset_of_product_search_identity_colour_words(): void
    {
        // lista i początki słów z ProductSearchIdentity::isColorWord (prywatna metoda) — każde jej słowo jest kolorem i tu
        $words = [
            'black', 'white', 'grey', 'gray', 'blue', 'green', 'red', 'yellow', 'orange', 'navy', 'brown', 'beige', 'pink',
            'silver', 'srebrny', 'srebrna', 'srebrne', 'zielony', 'zielona', 'zielone', 'zolty', 'zolta', 'zolte', 'czarny',
            'czarna', 'czarne', 'bialy', 'biala', 'biale', 'granatowy', 'granatowa', 'niebieski', 'niebieska', 'czerwony',
            'czerwona', 'czerwone', 'przezroczysty', 'przezroczysta', 'przezroczyste', 'transparent',
            // początki zielon, zolt, czarn, bial, granat, niebiesk, czerwon, czerw, srebrn, przezroczyst z dowolną końcówką
            'zielonkawy', 'żółtawy', 'czarnych', 'białym', 'granat', 'niebieskie', 'czerwonymi', 'czerwień', 'srebrnym',
            'przezroczystych',
        ];
        foreach ($words as $word) {
            $this->assertTrue(ColourWords::is($word), $word);
        }
    }

    public function test_words_outside_dictionary_are_not_colours(): void
    {
        // „krawędzie” zostaje w rdzeniu („Deckplate Czarny/Żółte krawędzie” to inny model niż „Deckplate Czarny”);
        // angielskie „steel” to w nazwach plików zdjęć podnosek, nie kolor
        foreach (['krawędzie', 'Light', 'Otwarta', 'Pełna', 'ESD', 'Nitryl', 'Standard', 'Krata', 'Taśma', 'steel', 'x', 'mb', '', 'Czar', 'Szarfa', 'Safety'] as $word) {
            $this->assertFalse(ColourWords::is($word), $word);
            $this->assertNull(ColourWords::canonical($word), $word);
        }
    }

    public function test_colour_in_image_file_name(): void
    {
        $this->assertSame('black', ColourWords::inUrl('https://www.coba.com/wp-content/uploads/2020/02/af-orthomat-standard-workplace-matting-black-1.jpg'));
        $this->assertSame('grey', ColourWords::inUrl('https://www.coba.com/pl/wp-content/uploads/sites/6/2024/09/Gray.jpg'));
        $this->assertSame('black', ColourWords::inUrl('https://www.coba.com/x/AF010003C_OrthomatStd_09xLinear_Black.jpg'));
        $this->assertSame('black', ColourWords::inUrl('https://www.coba.com/pl/wp-content/uploads/sites/6/2026/04/Superdry-Contract-Edges_Black.png?v=yellow'), 'tylko nazwa pliku, bez zapytania');
        $this->assertNull(ColourWords::inUrl('https://www.coba.com/pl/wp-content/uploads/sites/6/2022/10/Solid-Fatigue-Step-Leisure_07.jpg'));
        $this->assertNull(ColourWords::inUrl('https://www.coba.com/x/SS070002B1M_FatStepEdgeB1_Yel_Male-scaled.jpg'), 'skrót „Yel” nie jest w słowniku');
        $this->assertNull(ColourWords::inUrl('https://shop.example/black/photo_1.jpg'), 'katalog nie liczy się');
        $this->assertNull(ColourWords::inUrl('https://shop.example/img/steel-toe-boot.jpg'));
    }

    public function test_all_colours_of_name_and_file_name_as_sets_for_two_colour_cards(): void
    {
        // karty dwubarwne Coby (ok. 48 na produkcji): zbiór kolorów w kolejności, bez powtórzeń
        $this->assertSame(['black', 'blue'], ColourWords::allInName('COBAwash Czarny/Niebieski 0.6m x 0.85m'));
        $this->assertSame(['white', 'red'], ColourWords::allInName('COBAtape Biało/Czerwona 50mm x 18.3m'));
        $this->assertSame(['yellow', 'black'], ColourWords::allInName('COBAGRiP Nakładka na schody Żółto/Czarna 1m x 345mm x 55mm'));
        $this->assertSame(['black', 'yellow'], ColourWords::allInName('Deckplate Czarny/Żółte krawędzie 0.6m x 18.3m (15mm)'));
        $this->assertSame(['black'], ColourWords::allInName('Gripfoot Standard Taśma 50mm x 18.3m - Czarna (czarny)'), 'ten sam kolor dwa razy to jeden');
        $this->assertSame(['grey'], ColourWords::allInName('Orthomat Standard Szary 0.6m x 0.9m (9.5mm)'));
        $this->assertSame([], ColourWords::allInName('Akcesoria Krata GRP - Uchwyt typu C - 25mm'));
        $this->assertSame([], ColourWords::allInName(''));

        $this->assertSame(['black', 'yellow'], ColourWords::allInUrl('https://www.coba.com/wp-content/uploads/2020/02/af-deckplate-matting-black-yellow-1.jpg'));
        $this->assertSame(['black'], ColourWords::allInUrl('https://www.coba.com/wp-content/uploads/2020/02/af-orthomat-standard-workplace-matting-black-1.jpg'));
        $this->assertSame(['grey'], ColourWords::allInUrl('https://www.coba.com/pl/wp-content/uploads/sites/6/2024/09/Gray.jpg'));
        $this->assertSame([], ColourWords::allInUrl('https://www.coba.com/pl/wp-content/uploads/sites/6/2022/10/Solid-Fatigue-Step-Leisure_07.jpg'));
        $this->assertSame([], ColourWords::allInUrl('https://shop.example/black/photo_1.jpg?c=yellow'), 'katalog i zapytanie nie liczą się');

        $this->assertTrue(ColourWords::sameSet(['black', 'yellow'], ['yellow', 'black']), 'kolejność nie rozstrzyga');
        $this->assertTrue(ColourWords::sameSet(['black', 'black'], ['black']), 'powtórzenia nie rozstrzygają');
        $this->assertTrue(ColourWords::sameSet([], []), 'dwa puste zbiory są równe — co znaczy brak koloru, decyduje wołający');
        $this->assertFalse(ColourWords::sameSet(['black'], ['black', 'blue']), 'karta jednobarwna ≠ dwubarwna');
        $this->assertFalse(ColourWords::sameSet(['black', 'blue'], ['black', 'brown']));
        $this->assertFalse(ColourWords::sameSet(['black'], []));

        // pierwsze słowo (inName/inUrl) zostaje pierwszym elementem zbioru
        $this->assertSame('black', ColourWords::inName('COBAwash Czarny/Niebieski 0.6m x 0.85m'));
        $this->assertSame('black', ColourWords::inUrl('https://www.coba.com/x/af-deckplate-matting-black-yellow-1.jpg'));
    }

    public function test_colour_in_card_name_is_the_first_colour_word(): void
    {
        $this->assertSame('grey', ColourWords::inName('Orthomat Standard Szary 0.6m x 0.9m (9.5mm)'));
        $this->assertSame('black', ColourWords::inName('Deckplate Czarny/Żółte krawędzie 0.6m x 18.3m (15mm)'));
        $this->assertSame('black', ColourWords::inName('COBAGRiP Nakładka na schody Czarno/Żółta 1m x 345mm x 55mm'));
        $this->assertSame('clear', ColourWords::inName('Gripfoot Standard Taśma 50mm x 18.3m - Clear (przezroczysty)'));
        $this->assertSame('black', ColourWords::inName('Vyna-Plush Czarny/Stalowy 0.9m x 1.2m'));
        $this->assertNull(ColourWords::inName('Akcesoria Krata GRP - Uchwyt typu C - 25mm'));
        $this->assertNull(ColourWords::inName('First-Step'));
    }
}
