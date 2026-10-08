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
        // „charcoal” to angielska nazwa polskiego „antracyt” (Coba: karta „Toughrib Antracyt” ↔ plik
        // „TR010004_Toughrib_08x12_Charcoal.jpg”, „Needlepunch Antracyt” ↔ „…-matting-charcoal-3.jpg”; ponowny audyt
        // 08.10.2026). Grafit zostaje osobnym kolorem: Demar, Fagum-Stomil, Łukpol i MASCOT mają w katalogu karty
        // „grafit” i „antracyt” jako dwa różne warianty tego samego wyrobu.
        'charcoal' => 'anthracite', 'graphite' => 'graphite', 'khaki' => 'khaki', 'olive' => 'olive', 'lime' => 'lime',
        'turquoise' => 'turquoise', 'burgundy' => 'burgundy', 'cream' => 'cream',
        // rzeczowniki po polsku (bez odmiany przymiotnikowej); angielskiego „steel” tu nie ma — w nazwach plików
        // zdjęć to podnosek („steel-toe”), nie kolor
        'antracyt' => 'anthracite', 'grafit' => 'graphite', 'bordo' => 'burgundy',
    ];

    /**
     * Skróty kolorów spotykane tylko w nazwach plików zdjęć: Coba „…_Blk_Coner.jpg”, „…_Yel_Bk-…”, „…_BlkYel_06x09.jpg”,
     * Safety Jogger „…-GRY-CTLG.JPG”, 3M „…-blu-…”, JHK „…-bk-l_01.jpg”, Honeywell „…-BRN-…”. W nazwach kart się nie
     * liczą (canonical() ich nie zna) — tam „gry”, „org” czy „bk” to zwykłe słowa albo kody. W nazwie pliku liczą się
     * tylko jako osobne słowo z samych liter („Yel_Bk”, „blk-1”), nie przyklejone do cyfr („BK1234” to kod).
     */
    private const FILE_ABBREVIATIONS = [
        'blk' => 'black', 'bk' => 'black', 'yel' => 'yellow', 'ylw' => 'yellow', 'gry' => 'grey', 'wht' => 'white',
        'blu' => 'blue', 'grn' => 'green', 'org' => 'orange', 'brn' => 'brown', 'anth' => 'anthracite',
    ];

    /** Najkrótszy człon sklejki kolorów w nazwie pliku („blkyel” = „blk” + „yel”); dwuliterowe „bk” tylko osobno. */
    private const FILE_COMPOUND_MIN_PIECE = 3;

    /** Dłuższe słowo nie jest sklejką kolorów (najdłuższa sensowna to kilka słów: „blackyellowgrey”) — bez rozkładu. */
    private const FILE_COMPOUND_MAX_LENGTH = 32;

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
     * Słowo z samych liter (między znakami spoza liter i cyfr) przechodzi przez inFileToken() — pełne słowo koloru, skrót
     * („Blk”, „Yel”, „Bk”) albo sklejka („BlkYel”, „BlackYellow”, „Blkyel” → czarny i żółty; ponowny audyt Coby
     * 08.10.2026: „DAF010701_Orthomat_Diamond_BlkYel_06x09.jpg” nie mówił o kolorze, „…-BlackYellow_isolated.jpg”
     * o żadnym). Słowo z literami i cyframi („black1”, „09xLinear”) dzielone jest na ciągi liter i liczą się w nim tylko
     * pełne słowa koloru (canonical) — skrót przy cyfrach to zwykle kod („BK1234”).
     *
     * @return list<string>
     */
    public static function allInUrl(string $url): array
    {
        $path = (string) (parse_url(trim($url), PHP_URL_PATH) ?? '');
        $file = rawurldecode(basename($path));
        $out = [];
        foreach (preg_split('/[^a-z0-9]+/', self::fold($file), -1, PREG_SPLIT_NO_EMPTY) ?: [] as $token) {
            if (ctype_alpha($token)) {
                $colours = self::inFileToken($token);
            } else {
                $colours = [];
                foreach (preg_split('/[^a-z]+/', $token, -1, PREG_SPLIT_NO_EMPTY) ?: [] as $letters) {
                    $colour = self::canonical($letters);
                    if ($colour !== null) {
                        $colours[] = $colour;
                    }
                }
            }
            foreach ($colours as $colour) {
                if (! in_array($colour, $out, true)) {
                    $out[] = $colour;
                }
            }
        }

        return $out;
    }

    /**
     * Kolory jednego słowa nazwy pliku zdjęcia (same litery, np. słowo z ModelImagePicker::fileName): pełne słowo koloru
     * (canonical: „Gray” → ['grey'], „żółty” → ['yellow']), skrót z FILE_ABBREVIATIONS („Yel” → ['yellow']) albo sklejka
     * w całości złożona ze słów koloru ze słownika (WORDS) i skrótów co najmniej FILE_COMPOUND_MIN_PIECE-literowych
     * („BlkYel”, „blackyellow” → ['black', 'yellow']). Sklejka musi rozłożyć się bez reszty — „greenline”, „redwood”
     * to nie kolory. [] = słowo nie mówi o kolorze.
     *
     * @return list<string>
     */
    public static function inFileToken(string $token): array
    {
        $w = self::fold($token);
        if ($w === '' || ! ctype_alpha($w)) {
            return [];
        }
        $colour = self::canonical($w) ?? (self::FILE_ABBREVIATIONS[$w] ?? null);
        if ($colour !== null) {
            return [$colour];
        }

        return self::compound($w);
    }

    /**
     * Rozkład sklejki na człony koloru (co najmniej dwa) bez reszty; pierwszy rozkład od najdłuższego członu.
     *
     * @return list<string> kolory kanoniczne bez powtórzeń; [] = to nie sklejka kolorów
     */
    private static function compound(string $w): array
    {
        if (strlen($w) > self::FILE_COMPOUND_MAX_LENGTH) {
            return [];
        }
        static $pieces = null;
        if ($pieces === null) {
            $pieces = [];
            foreach ([...self::WORDS, ...self::FILE_ABBREVIATIONS] as $piece => $colour) {
                if (strlen($piece) >= self::FILE_COMPOUND_MIN_PIECE && ctype_alpha($piece)) {
                    $pieces[$piece] = $colour;
                }
            }
            uksort($pieces, static fn (string $a, string $b): int => strlen($b) <=> strlen($a));
        }
        $split = static function (string $rest) use (&$split, $pieces): ?array {
            if ($rest === '') {
                return [];
            }
            foreach ($pieces as $piece => $colour) {
                if (str_starts_with($rest, $piece)) {
                    $tail = $split(substr($rest, strlen($piece)));
                    if ($tail !== null) {
                        return [$colour, ...$tail];
                    }
                }
            }

            return null;
        };
        $parts = $split($w);
        if ($parts === null || count($parts) < 2) {
            return [];
        }

        return array_values(array_unique($parts));
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
