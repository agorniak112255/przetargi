<?php

declare(strict_types=1);

namespace App\Support;

use App\Models\Product;

/**
 * Cechy bezpieczeństwa wyrobu z nazwy karty i ze źródła (etap 3 opisów z cenników, §1.5): napięcie (kV), klasa
 * izolacji (00–4), klasa filtra cząstek (P1–P3), półmaska filtrująca (FFP1–3) i grupa gazu pochłaniacza (A, B, E, K,
 * AX i połączenia, z klasą 1–3). Audyt SECURA 08.10.2026: półbuty 30 kV opisane ze strony 20 kV, zestaw ELSEC 5/10 kV
 * ze zdjęciem 2,5 kV, pochłaniacz E2 ze strony A2, filtr P2 ze zdjęciem P1.
 *
 * Sprzeczność (conflict) = ta sama cecha po obu stronach bez części wspólnej; brak cechy po którejś stronie to nie
 * sprzeczność (strona rodziny „2,5/5/10 kV” przechodzi przy każdym z trzech zestawów). Napięcia porównywane po klasie
 * izolacji (KV_CLASS: 1 kV = 5 kV = klasa 0). Niczego nie zapisuje.
 *
 * Zawężenia przeciw fałszywym trafieniom: grupa gazu tylko w tekście o pochłaniaczu albo filtrze („A4” to format
 * papieru, „B2B” sklep), bez poprawek norm („EN 388:2016+A1:2018”); klasa izolacji tylko z etykietą („klasa 0”)
 * i w tekście o napięciu albo izolacji (inaczej „klasa 2” bywa klasą widzialności czy EN 343).
 */
final class SafetyFeatures
{
    public const KEYS = ['kv', 'insulation', 'particle', 'ffp', 'gas'];

    /** Kontekst pochłaniacza albo filtra — wtedy „A2”, „E2”, „ABEK1” to grupa gazu. */
    private const GAS_CONTEXT = '/poch[łl]aniacz|filtr|filter|absorber|(?<![\p{L}])gaz|(?<![\p{L}])gas(?![\p{L}])|cartridge|canister/iu';

    /** Kontekst napięcia albo izolacji — wtedy „klasa 0” to klasa izolacji. */
    private const INSULATION_CONTEXT = '/\d\s*kv(?![\p{L}])|elektroizol|dielektr|izolac|izoluj|insulat|napi[eę]ci|voltage|60903|50321|61111/iu';

    private const NUMBER = '\d{1,3}(?:[.,]\d{1,2})?';

    /**
     * Napięcie → klasa izolacji wg EN 60903 (rękawice) i EN 50321 (obuwie): napięcie użytkowania i napięcie próby tej
     * samej klasy to ten sam wyrób (karta „klasa 0 do 1 kV” i tytuł „kl. 0, 5 kV”; półbuty 30 kV i strona „do 26,5 kV”).
     */
    private const KV_CLASS = [
        '0.5' => '00', '2.5' => '00',
        '1' => '0', '5' => '0',
        '7.5' => '1', '10' => '1',
        '17' => '2', '20' => '2',
        '26.5' => '3', '30' => '3',
        '36' => '4', '40' => '4',
    ];

    /**
     * @return array{kv: list<string>, insulation: list<string>, particle: list<string>, ffp: list<string>, gas: list<string>}
     */
    public static function in(string $text): array
    {
        $out = self::empty();
        $text = trim($text);
        if ($text === '') {
            return $out;
        }
        // poprawki norm („+A1:2018”, „A2:2009”) i „B2B” / „B2C” to nie grupy gazu
        $clean = (string) preg_replace('/\+\s*A\d{1,2}(?::\s*\d{4})?/iu', ' ', $text);
        $clean = (string) preg_replace('/(?<![\p{L}\p{N}])A\d{1,2}:\s*\d{4}/iu', ' ', $clean);
        $clean = (string) preg_replace('/(?<![\p{L}\p{N}])B2[BC](?![\p{L}\p{N}])/iu', ' ', $clean);

        $out['kv'] = self::kilovolts($clean);
        if (preg_match(self::INSULATION_CONTEXT, $clean) === 1) {
            $out['insulation'] = self::insulationClasses($clean);
        }
        if (preg_match_all('/(?<![\p{L}\p{N}])FFP\s?([123])(?![\p{N}])/iu', $clean, $m) > 0) {
            $out['ffp'] = self::unique(array_map(static fn (string $c): string => 'FFP'.$c, $m[1]));
        }
        // „P2”, „P3 R”, „A2P3” — przed P litera nie może stać („FFP2”, „SP3”), cyfra tak (grupa gazu z filtrem)
        if (preg_match_all('/(?<![\p{L}])P([123])(?![\p{N}])/iu', $clean, $m) > 0) {
            $out['particle'] = self::unique(array_map(static fn (string $c): string => 'P'.$c, $m[1]));
        }
        if (preg_match(self::GAS_CONTEXT, $clean) === 1) {
            $out['gas'] = self::gasTokens($clean);
        }

        return $out;
    }

    /**
     * Cechy z adresu: ścieżka po ShopEntryId::strip (z nazwą pliku), separatory adresu jak spacje; „2-5-kv” = 2,5 kV.
     * Zapytanie i host nie liczą się.
     *
     * Adres gubi przecinek: securabc.com pisze „2,5 kV” jako „elsec-25-kv” (strona i zdjęcie „46-large_default/
     * elsec-25-kv.jpg”). Liczba kV z adresu od 2 cyfr, niekończąca się zerem, ma więc też odczyt z przecinkiem przed
     * ostatnią cyfrą („25” → 25 i 2.5, „265” → 26.5); „10”, „20”, „30” zostają sobą — nikt nie pisze „1,0 kV”. Ciąg
     * kilku liczb przed „kv” („zestaw-elsec-5-10-kv”, „elsec-2-5-5-10-kv”) to lista albo zapis z przecinkiem — nie
     * wiadomo — więc nie daje napięcia (wcześniej liczyła się ostatnia liczba). Wyjątek: dwie liczby, druga
     * jednocyfrowa („2-5-kv”) = 2,5 kV; po zerze tylko „0-5-kv” (0,5 kV) — „klasa-0-1-kv” to klasa 0 do 1 kV.
     *
     * @return array{kv: list<string>, insulation: list<string>, particle: list<string>, ffp: list<string>, gas: list<string>}
     */
    public static function inUrl(string $url): array
    {
        $stripped = ShopEntryId::strip($url);
        $path = parse_url($stripped, PHP_URL_PATH);
        $path = rawurldecode(is_string($path) ? $path : $stripped);
        if (trim($path) === '') {
            return self::empty();
        }
        // „2-5-kv”, „2.5-kv” w adresie = 2,5 kV (przecinek w adresie staje się myślnikiem); dłuższy ciąg liczb — niepewny
        $path = (string) preg_replace_callback(
            '/(?<![\p{N}])\d{1,3}(?:[\-_.]\d{1,3})+[\-_]?kv(?![\p{L}])/iu',
            static function (array $m): string {
                preg_match_all('/\d+/', $m[0], $numbers);
                [$first, $second] = $numbers[0] + [null, null];
                // „0-5-kv” = 0,5 kV, ale „klasa-0-1-kv” to klasa 0 do 1 kV, nie 0,1 kV
                if (count($numbers[0]) === 2 && strlen((string) $first) <= 2 && strlen((string) $second) === 1
                    && ($first !== '0' || $second === '5')) {
                    return ' '.$first.','.$second.' kV ';
                }

                return ' ';
            },
            $path
        );
        $path = (string) preg_replace('/(?<=\p{N})[.](?=\p{N})/u', ' ', $path);
        $path = (string) preg_replace('/[\-_\/+.]+/u', ' ', $path);

        $out = self::in($path);
        $readings = [];
        foreach ($out['kv'] as $value) {
            $readings[] = $value;
            if (preg_match('/^\d{2,3}$/', $value) === 1 && ! str_ends_with($value, '0')) {
                $readings[] = rtrim(rtrim(sprintf('%.2f', (int) $value / 10), '0'), '.');
            }
        }
        $out['kv'] = self::unique($readings);

        return $out;
    }

    /**
     * @return array{kv: list<string>, insulation: list<string>, particle: list<string>, ffp: list<string>, gas: list<string>}
     */
    public static function ofCard(Product $p): array
    {
        return self::in((string) $p->name);
    }

    /**
     * Pierwsza sprzeczność (kolejność KEYS) albo null: „30 kV ≠ 20 kV”, „P2 ≠ P1”, „E2 ≠ A2”. Grupa gazu po składnikach:
     * „ABEK1” zawiera A1, więc nie przeczy „A1”; składnik bez klasy („ABEK”) pasuje do każdej klasy tej litery.
     * Napięcia po klasie izolacji (KV_CLASS): 1 kV i 5 kV to ta sama klasa 0, 30 kV i 20 kV — klasy 3 i 2; napięcie spoza
     * tabeli porównywane wprost.
     *
     * @param  array<string, mixed>  $card
     * @param  array<string, mixed>  $found
     */
    public static function conflict(array $card, array $found): ?string
    {
        foreach (self::KEYS as $key) {
            $a = self::listOf($card[$key] ?? []);
            $b = self::listOf($found[$key] ?? []);
            if ($a === [] || $b === []) {
                continue;
            }
            $shared = match ($key) {
                'gas' => self::gasOverlap($a, $b),
                'kv' => array_intersect(self::kvClasses($a), self::kvClasses($b)) !== [],
                default => array_intersect($a, $b) !== [],
            };
            if (! $shared) {
                return self::label($key, $a).' ≠ '.self::label($key, $b);
            }
        }

        return null;
    }

    /**
     * Suma cech kilku źródeł (np. adres strony i tytuł) — operator „+” na tablicach PHP zostawiłby tylko pierwszą.
     *
     * @param  array<string, mixed>  ...$sets
     * @return array{kv: list<string>, insulation: list<string>, particle: list<string>, ffp: list<string>, gas: list<string>}
     */
    public static function merge(array ...$sets): array
    {
        $out = self::empty();
        foreach ($sets as $set) {
            foreach (self::KEYS as $key) {
                $out[$key] = self::unique([...$out[$key], ...self::listOf($set[$key] ?? [])]);
            }
        }

        return $out;
    }

    /**
     * @return array{kv: list<string>, insulation: list<string>, particle: list<string>, ffp: list<string>, gas: list<string>}
     */
    private static function empty(): array
    {
        return ['kv' => [], 'insulation' => [], 'particle' => [], 'ffp' => [], 'gas' => []];
    }

    /**
     * Klasy izolacji z etykietą, także lista i zakres („klasa 0, 1 i 2”, „Class 0-4” = 0, 1, 2, 3, 4).
     *
     * @return list<string>
     */
    private static function insulationClasses(string $text): array
    {
        $class = '(?:00|0|1|2|3|4)(?![\p{N}]|[.,]\p{N})';
        $pattern = '/(?<![\p{L}])(?:klas[aeyę]?|kl\.|class(?:es)?)\s*('.$class.'(?:\s*(?:,|\/|-|–|i|lub|oraz|and|or|&)\s*'.$class.')*)/iu';
        if (preg_match_all($pattern, $text, $m) < 1) {
            return [];
        }
        $order = ['00', '0', '1', '2', '3', '4'];
        $out = [];
        foreach ($m[1] as $list) {
            preg_match_all('/00|[0-4]/', $list, $values);
            $values = $values[0];
            if (count($values) === 2 && preg_match('/[\-–]/u', $list) === 1) {
                $from = array_search($values[0], $order, true);
                $to = array_search($values[1], $order, true);
                if (is_int($from) && is_int($to) && $from <= $to) {
                    $values = array_slice($order, $from, $to - $from + 1);
                }
            }
            array_push($out, ...$values);
        }

        return self::unique($out);
    }

    /**
     * Napięcia przed „kV”, także lista „2,5/5/10 kV” i „5 kV / 10 kV”. Przecinek bez spacji to część dziesiętna.
     * Zakres („1–36 kV”, „3,5-17 kV”) nie wskazuje jednego napięcia — pomijany.
     *
     * @return list<string>
     */
    private static function kilovolts(string $text): array
    {
        $n = self::NUMBER;
        $text = (string) preg_replace('/(?<![\p{L}\p{N}.,])'.$n.'\s*(?:-|–|—)\s*'.$n.'\s*kV(?![\p{L}])/iu', ' ', $text);
        $pattern = '/(?<![\p{L}\p{N}.,])((?:'.$n.'\s*(?:kV\s*)?(?:\/|;|\s+i\s+|\s+lub\s+|\s+oraz\s+|,\s+)\s*)*'.$n.')\s*kV(?![\p{L}])/iu';
        if (preg_match_all($pattern, $text, $m) < 1) {
            return [];
        }
        $out = [];
        foreach ($m[1] as $list) {
            preg_match_all('/'.$n.'(?![\p{N}])/u', $list, $numbers);
            foreach ($numbers[0] as $number) {
                $value = (float) str_replace(',', '.', $number);
                if ($value > 0) {
                    $out[] = rtrim(rtrim(sprintf('%.2f', $value), '0'), '.');
                }
            }
        }

        return self::unique($out);
    }

    /**
     * Grupy gazu jako osobne ciągi: „A2”, „E2”, „ABEK1”, „A1B1E1K1”, „AX”; za nimi granica albo filtr cząstek („A2P3”).
     *
     * @return list<string>
     */
    private static function gasTokens(string $text): array
    {
        $pattern = '/(?<![\p{L}\p{N}+])(AX|(?:A[1-3]?)?(?:B[1-3]?)?(?:E[1-3]?)?(?:K[1-3]?)?)(?=$|[^\p{L}\p{N}]|P[123](?![\p{N}]))/iu';
        if (preg_match_all($pattern, $text, $m) < 1) {
            return [];
        }
        $out = [];
        foreach ($m[1] as $token) {
            $token = mb_strtoupper($token);
            $letters = (int) preg_match_all('/[ABEK]/', $token);
            // bez klasy liczą się tylko AX i połączenia od trzech liter („ABE”, „ABEK”) — sama litera albo dwie
            // („A”, „be”, „ak”) to słowo, inicjał czy rozmiar
            if ($token === '' || (preg_match('/\d/', $token) !== 1 && $token !== 'AX' && $letters < 3)) {
                continue;
            }
            $out[] = $token;
        }

        return self::unique($out);
    }

    /**
     * Napięcia jako klasy izolacji („c:0”), a spoza tabeli KV_CLASS — same wartości („v:12”).
     *
     * @param  list<string>  $values
     * @return list<string>
     */
    private static function kvClasses(array $values): array
    {
        return array_values(array_unique(array_map(
            static fn (string $v): string => isset(self::KV_CLASS[$v]) ? 'c:'.self::KV_CLASS[$v] : 'v:'.$v,
            $values
        )));
    }

    /**
     * @param  list<string>  $a
     * @param  list<string>  $b
     */
    private static function gasOverlap(array $a, array $b): bool
    {
        $atomsA = array_merge(...array_map(self::gasAtoms(...), $a));
        $atomsB = array_merge(...array_map(self::gasAtoms(...), $b));
        foreach ($atomsA as [$letter, $class]) {
            foreach ($atomsB as [$otherLetter, $otherClass]) {
                if ($letter === $otherLetter && ($class === '' || $otherClass === '' || $class === $otherClass)) {
                    return true;
                }
            }
        }

        return false;
    }

    /**
     * Składniki grupy gazu: „ABEK1” → A1, B1, E1, K1 (klasa na końcu dotyczy wszystkich), „A1B2” → A1, B2, „AX” → AX.
     *
     * @return list<array{0: string, 1: string}>
     */
    private static function gasAtoms(string $token): array
    {
        if (preg_match_all('/(AX|[ABEK])([1-3]?)/', mb_strtoupper($token), $m, PREG_SET_ORDER) < 1) {
            return [];
        }
        $atoms = array_map(static fn (array $g): array => [$g[1], $g[2]], $m);
        $classes = array_values(array_filter(array_column($atoms, 1), static fn (string $c): bool => $c !== ''));
        $last = $atoms[count($atoms) - 1][1];
        if (count($classes) === 1 && $last !== '') {
            foreach ($atoms as $i => $atom) {
                $atoms[$i][1] = $last;
            }
        }

        return $atoms;
    }

    /**
     * @param  list<string>  $values
     */
    private static function label(string $key, array $values): string
    {
        return implode(', ', array_map(static fn (string $v): string => match ($key) {
            'kv' => $v.' kV',
            'insulation' => 'klasa '.$v,
            default => $v,
        }, $values));
    }

    /**
     * @return list<string>
     */
    private static function listOf(mixed $values): array
    {
        if (! is_array($values)) {
            return [];
        }

        return self::unique(array_map(static fn (mixed $v): string => trim((string) $v), array_filter($values, 'is_scalar')));
    }

    /**
     * @param  array<int|string, string>  $values
     * @return list<string>
     */
    private static function unique(array $values): array
    {
        return array_values(array_unique(array_filter($values, static fn (string $v): bool => $v !== '')));
    }
}
