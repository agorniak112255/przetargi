<?php

declare(strict_types=1);

namespace App\Support;

/**
 * Słowa koloru w nazwach kart i w nazwach plików zdjęć (etap 2 opisów z cenników: rdzeń nazwy modelu
 * i zdjęcie w kolorze karty). Nadzbiór ProductSearchIdentity::isColorWord — tam słowo koloru to token nieprzydatny
 * w wyszukiwaniu, tu słowo zdejmowane z nazwy i tłumaczone na kolor kanoniczny (angielski, małymi literami:
 * „szary” → „grey”), żeby porównać kolor karty z kolorem w nazwie pliku zdjęcia
 * („af-orthomat-standard-workplace-matting-black-1.jpg”, „Gray.jpg”).
 *
 * Odmiana po polsku z końcówki („Czarny/Żółte”, „Czarno/Żółta”, „szarego”); odcień („ciemnoszary”, „jasnoszary”)
 * to ten sam kolor kanoniczny — nazwa pliku producenta rzadko odróżnia odcień, a inny kolor (czarny zamiast szarego)
 * jest groźniejszy niż inny odcień. Słowo spoza słownika nie jest kolorem: „krawędzie” w „Czarny/Żółte krawędzie”
 * zostaje w rdzeniu nazwy i rozdziela modele.
 */
final class ColourWords
{
    /** całe słowo (małe litery, bez polskich znaków) => kolor kanoniczny */
    private const WORDS = [
        'black' => 'black', 'white' => 'white', 'grey' => 'grey', 'gray' => 'grey', 'blue' => 'blue', 'green' => 'green',
        'red' => 'red', 'yellow' => 'yellow', 'orange' => 'orange', 'navy' => 'navy', 'brown' => 'brown',
        'beige' => 'beige', 'pink' => 'pink', 'silver' => 'silver', 'gold' => 'gold', 'purple' => 'purple',
        'violet' => 'purple', 'clear' => 'clear', 'transparent' => 'clear', 'anthracite' => 'anthracite',
        'charcoal' => 'charcoal', 'graphite' => 'graphite', 'khaki' => 'khaki', 'olive' => 'olive', 'lime' => 'lime',
        'turquoise' => 'turquoise', 'burgundy' => 'burgundy', 'cream' => 'cream',
        // rzeczowniki po polsku (bez odmiany przymiotnikowej); angielskiego „steel” tu nie ma — w nazwach plików
        // zdjęć to podnosek („steel-toe”), nie kolor
        'antracyt' => 'anthracite', 'grafit' => 'graphite', 'bordo' => 'burgundy',
    ];

    /**
     * Początek polskiego przymiotnika => kolor; reszta słowa to końcówka odmiany (-y, -a, -e, -o, -i, -ego, -ej, -ych,
     * -ym, -ymi; „ą”/„ę” po zdjęciu ogonków to „a”/„e”).
     */
    private const STEMS = [
        'czarn' => 'black', 'bial' => 'white', 'szar' => 'grey', 'niebiesk' => 'blue', 'zielon' => 'green',
        'czerwon' => 'red', 'zolt' => 'yellow', 'pomaranczow' => 'orange', 'granatow' => 'navy', 'brazow' => 'brown',
        'bezow' => 'beige', 'rozow' => 'pink', 'srebrn' => 'silver', 'zlot' => 'gold', 'fioletow' => 'purple',
        'przezroczyst' => 'clear', 'transparentn' => 'clear', 'bezbarwn' => 'clear', 'antracytow' => 'anthracite',
        'grafitow' => 'graphite', 'stalow' => 'steel', 'oliwkow' => 'olive', 'limonkow' => 'lime',
        'turkusow' => 'turquoise', 'bordow' => 'burgundy', 'kremow' => 'cream',
    ];

    /**
     * Początki z ProductSearchIdentity::isColorWord liczone jak tam — z dowolną końcówką („granat”, „czerwień”) —
     * żeby ta lista była nadzbiorem tamtej.
     */
    private const LEGACY_PREFIXES = [
        'zielon' => 'green', 'zolt' => 'yellow', 'czarn' => 'black', 'bial' => 'white', 'granat' => 'navy',
        'niebiesk' => 'blue', 'czerwon' => 'red', 'czerw' => 'red', 'srebrn' => 'silver', 'przezroczyst' => 'clear',
    ];

    private const ENDINGS = '/^(?:y|a|e|o|i|ego|ej|ych|ym|ymi)?$/';

    public static function is(string $word): bool
    {
        return self::canonical($word) !== null;
    }

    /** Kolor kanoniczny słowa („Szary” → „grey”, „Czarno” → „black”, „Clear” → „clear”) albo null, gdy to nie kolor. */
    public static function canonical(string $word): ?string
    {
        $w = (string) preg_replace('/[^a-z]+/', '', self::fold($word));
        if ($w === '') {
            return null;
        }
        if (isset(self::WORDS[$w])) {
            return self::WORDS[$w];
        }
        // odcień: „ciemnoszary”, „jasnoniebieski”, także z myślnikiem już zdjętym przez fold()
        $bare = preg_replace('/^(?:ciemno|jasno)/', '', $w) ?? $w;
        foreach (self::STEMS as $stem => $colour) {
            if (str_starts_with($bare, $stem) && preg_match(self::ENDINGS, substr($bare, strlen($stem))) === 1) {
                return $colour;
            }
        }
        foreach (self::LEGACY_PREFIXES as $prefix => $colour) {
            if (str_starts_with($bare, $prefix)) {
                return $colour;
            }
        }

        return null;
    }

    /**
     * Kolor z nazwy pliku w adresie zdjęcia („…/af-orthomat-standard-workplace-matting-black-1.jpg” → „black”,
     * „…/2024/09/Gray.jpg” → „grey”); tylko nazwa pliku, bez katalogów i zapytania. Pierwsze słowo koloru rozstrzyga;
     * null = nazwa pliku nie mówi o kolorze.
     */
    public static function inUrl(string $url): ?string
    {
        return self::allInUrl($url)[0] ?? null;
    }

    /**
     * Kolor z nazwy karty („Orthomat Standard Szary 0.6m x 0.9m” → „grey”, „Deckplate Czarny/Żółte krawędzie” → „black”:
     * pierwsze słowo koloru). null = nazwa nie ma słowa koloru. Do porównań kart dwubarwnych służy allInName().
     */
    public static function inName(string $name): ?string
    {
        return self::allInName($name)[0] ?? null;
    }

    /**
     * Wszystkie kolory z nazwy karty w kolejności wystąpienia, bez powtórzeń („COBAwash Czarny/Niebieski” →
     * ['black', 'blue'], „Deckplate Czarny/Żółte krawędzie” → ['black', 'yellow']); [] = nazwa nie mówi o kolorze.
     * Karta dwubarwna to inny kolor niż karta jednobarwna — porównuj zbiory (sameSet), nie pierwsze słowo.
     *
     * @return list<string>
     */
    public static function allInName(string $name): array
    {
        $out = [];
        foreach (preg_split('/[^\p{L}]+/u', $name) ?: [] as $token) {
            if ($token === '') {
                continue;
            }
            $colour = self::canonical($token);
            if ($colour !== null && ! in_array($colour, $out, true)) {
                $out[] = $colour;
            }
        }

        return $out;
    }

    /**
     * Wszystkie kolory z nazwy pliku w adresie zdjęcia („…/matting-black-yellow-1.jpg” → ['black', 'yellow']);
     * tylko nazwa pliku, bez katalogów i zapytania. [] = nazwa pliku nie mówi o kolorze.
     *
     * @return list<string>
     */
    public static function allInUrl(string $url): array
    {
        $path = (string) (parse_url(trim($url), PHP_URL_PATH) ?? '');
        $file = rawurldecode(basename($path));
        $out = [];
        foreach (preg_split('/[^a-z]+/', self::fold($file)) ?: [] as $token) {
            if ($token === '') {
                continue;
            }
            $colour = self::canonical($token);
            if ($colour !== null && ! in_array($colour, $out, true)) {
                $out[] = $colour;
            }
        }

        return $out;
    }

    /**
     * Te same kolory (bez względu na kolejność): ['black', 'yellow'] = ['yellow', 'black']; ['black'] ≠ ['black', 'blue'].
     * Dwa puste zbiory też są równe — wołający sam decyduje, co znaczy brak koloru.
     *
     * @param  list<string>  $a
     * @param  list<string>  $b
     */
    public static function sameSet(array $a, array $b): bool
    {
        $a = array_values(array_unique($a));
        $b = array_values(array_unique($b));
        sort($a);
        sort($b);

        return $a === $b;
    }

    /** Małe litery bez polskich znaków („Żółto-Czarna” → „zolto-czarna”); separatory zostają do podziału na słowa. */
    private static function fold(string $word): string
    {
        $w = mb_strtolower(trim($word), 'UTF-8');

        return strtr($w, [
            'ą' => 'a', 'ć' => 'c', 'ę' => 'e', 'ł' => 'l', 'ń' => 'n', 'ó' => 'o', 'ś' => 's', 'ź' => 'z', 'ż' => 'z',
            'ä' => 'a', 'ö' => 'o', 'ü' => 'u', 'ß' => 'ss', 'é' => 'e', 'è' => 'e',
        ]);
    }
}
