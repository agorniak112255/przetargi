<?php

declare(strict_types=1);

namespace App\Support\RequirementCheck;

/**
 * Poziomy EN 388 odczytane z tekstu: kod („4X42C”, „4 3 4 1 B”, „2.X.4.2.C”, „(1X42C)”, stary „4131”, końcowe „P”)
 * albo zapis słowny („ścieranie 4, przecięcie (Coup Test) 3, … przecięcie wg metody ISO – poziom B”). Czytamy tylko
 * fragment za numerem normy, do następnej normy albo średnika — „EN 388 (2.X.4.2.C), EN 407 (X.1.X.X.X)” nie może
 * oddać kodu EN 407, a „EN 3880” nie jest EN 388. Kod musi stać tuż za numerem normy (LEAD) albo w nawiasie za samym
 * tytułem normy (TITLE) — „EN 388, nr art. 4543” czy „EN 388. Karton 1200 par.” to nie kod.
 * Pozycja null = tekst jej nie podaje; „X” = nie badano.
 *
 * Wydanie normy (`edition`) tylko dosłownie z tekstu: rok za numerem („EN 388:2016”, „EN 388:2016 + A1:2018” → 2016)
 * albo w nawiasie przed kodem („EN 388 (2003) 4131”). Z formatu kodu go nie zgadujemy — EN 388:2003 „4542” i EN 388:2016
 * „4X42C” to dwie prawdziwe wartości tego samego wyrobu, a przypisanie wydania z domysłu podałoby wniosek jako fakt.
 */
final readonly class En388Code
{
    /** Pozycje w kolejności kodu z nazwami do tabeli porównania. */
    public const POSITIONS = [
        'abrasion' => 'ścieranie',
        'coupe' => 'przecięcie (Coup Test)',
        'tear' => 'rozdzieranie',
        'puncture' => 'przekłucie',
        'iso' => 'przecięcie ISO 13997',
        'impact' => 'uderzenie (P)',
    ];

    /**
     * Między numerem normy a kodem tylko ciasny łącznik w jednej linii: separatory, rok w nawiasie („(2003) 4X43C”),
     * „z poziomami min.”, „poziom”, „kod”. Przecinek, kropka kończąca zdanie, nowa linia czy dowolne słowa — to już
     * nie kod. Wspólne z En407Code.
     */
    public const LEAD = '(?:[\h:\-–—]+|\(\h*(?:19|20)\d{2}\h*\)|(?i:z\h+poziom\p{L}*|poziom\p{L}*|level\p{L}*|min\.|minimum|min|co\h+najmniej|kod\p{L}*)(?![\p{L}\d]))*\(?\h*';

    /** Tytuł normy z samych liter, a za nim kod w nawiasie: „EN 388:2016 – Rękawice chroniące … (4343B)”. */
    public const TITLE = '[\h:\-–—]*[\p{L}\h\-–]{3,120}?\(\h*';

    /** Grupa 1 = rok wydania („:2016”); rok poprawki („+ A1:2018”) nie zmienia wydania. */
    private const NORM = '/(?<![\p{L}\d])EN\s?(?:ISO\s?)?388(?!\d)(?:\s?:\s?((?:19|20)\d{2})(?!\d))?(?:\s*\+\s*A\d(?:\s?:\s?(?:19|20)\d{2}(?!\d))?)?/iu';

    /** Rok wydania w nawiasie tuż za numerem normy: „EN 388 (2003) 4131”. */
    private const EDITION_IN_LEAD = '/^[\h:\-–—]*\(\h*((?:19|20)\d{2})\h*\)/u';

    /** Koniec fragmentu normy: średnik albo numer kolejnej normy („EN 407”, „EN ISO 21420”, „ANSI”). */
    private const STOP = '/;|(?<![\p{L}\d])(?:EN\s?(?:ISO\s?)?\d{3,5}(?!\d)|ANSI)/iu';

    /** Jednakowy separator między pozycjami — „2.X.4 2C” to nie kod. Małe litery też: „4x43c”. */
    private const CODE = '(?<![\p{L}\d.])([0-5Xx])([ .]?)([0-5Xx])\2([0-5Xx])\2([0-5Xx])(?:\2([A-Fa-fXx]))?(?:\h?([Pp]))?(?![\p{L}\d])';

    private const GAP = '\s*(?:\([^()]{0,20}\)\s*)?[:\-–—]?\s*(?:(?i:poziom|level)\p{L}*\s*[:\-–—]?\s*)?';

    /** @param array<string, string|null> $levels klucze jak POSITIONS */
    private function __construct(
        public array $levels,
        public string $text,
        public bool $worded,
        public ?string $edition = null,
    ) {}

    public static function first(string $text): ?self
    {
        return self::allIn($text)[0] ?? null;
    }

    /**
     * @return list<self>
     */
    public static function allIn(string $text): array
    {
        $out = [];
        foreach (self::segments($text) as [$segment, $edition]) {
            $code = self::code($segment, $edition) ?? self::worded($segment, $edition);
            if ($code !== null) {
                $out[] = $code;
            }
        }

        return $out;
    }

    /**
     * Pełny kod zapisany kodem (nie słowami) — dosłowny, bez spacji i kropek między pozycjami: „4 1 2 1 X” → „4121X”,
     * „1.1.2.2” → „1122”. Null dla zapisu słownego: ten bywa częściowy („ścieranie 4”) i nie jest kodem karty.
     */
    public function compact(): ?string
    {
        if ($this->worded) {
            return null;
        }

        return mb_strtoupper(preg_replace('/[\h.]/u', '', $this->text) ?? $this->text);
    }

    /**
     * Czy dwa odczyty mogą sobie przeczyć: to samo wydanie albo wydanie niepodane przy którymkolwiek. Dwa różne
     * podane wydania (2003 i 2016) to dwie wartości wyrobu, nie sprzeczność.
     */
    public static function comparableEditions(?string $a, ?string $b): bool
    {
        return $a === null || $b === null || $a === $b;
    }

    /** Zapis do wyświetlenia: „2X42C”, „4341B”; pozycja niepodana jako „-”. */
    public function canonical(): string
    {
        $out = '';
        foreach (array_keys(self::POSITIONS) as $key) {
            if ($key === 'impact') {
                $out .= $this->levels[$key] ?? '';
            } else {
                $out .= $this->levels[$key] ?? '-';
            }
        }

        return $out;
    }

    /**
     * Kod z początku fragmentu za numerem normy: najpierw za ciasnym łącznikiem, potem w nawiasie za tytułem normy.
     * Zwraca dopasowanie z przesunięciami grup (grupa 1 = pierwsza pozycja) albo null.
     *
     * @return array<int, array{0: string|null, 1: int}>|null
     */
    public static function codeMatch(string $segment, string $code): ?array
    {
        foreach (['/^'.self::LEAD.$code.'/u', '/^'.self::TITLE.$code.'\h*\)/u'] as $pattern) {
            if (preg_match($pattern, $segment, $m, PREG_OFFSET_CAPTURE | PREG_UNMATCHED_AS_NULL) === 1) {
                return $m;
            }
        }

        return null;
    }

    /**
     * Dosłowny zapis kodu: od pierwszej do ostatniej podanej pozycji.
     *
     * @param  array<int, array{0: string|null, 1: int}>  $m
     */
    public static function codeLiteral(string $segment, array $m): string
    {
        $end = 0;
        foreach (array_slice($m, 1, null, true) as [$group, $offset]) {
            if ($group !== null && $group !== '') {
                $end = max($end, $offset + strlen($group));
            }
        }

        return trim(substr($segment, $m[1][1], $end - $m[1][1]));
    }

    /**
     * @return list<array{0: string, 1: string|null}> [fragment za numerem normy, rok wydania albo null]
     */
    private static function segments(string $text): array
    {
        if (preg_match_all(self::NORM, $text, $m, PREG_OFFSET_CAPTURE | PREG_UNMATCHED_AS_NULL) < 1) {
            return [];
        }
        $out = [];
        foreach ($m[0] as $i => [$norm, $offset]) {
            $rest = mb_strcut($text, $offset + strlen($norm), 1200);
            $rest = preg_split(self::STOP, $rest, 2)[0] ?? '';
            $segment = mb_substr($rest, 0, 320);
            $edition = $m[1][$i][0] ?? null;
            if ($edition === null && preg_match(self::EDITION_IN_LEAD, $segment, $lead) === 1) {
                $edition = $lead[1];
            }
            $out[] = [$segment, $edition];
        }

        return $out;
    }

    private static function code(string $segment, ?string $edition = null): ?self
    {
        $m = self::codeMatch($segment, self::CODE);
        if ($m === null) {
            return null;
        }
        $value = static fn (int $group): ?string => $m[$group][0] === null ? null : strtoupper($m[$group][0]);
        // „EN 388 (2003)” — rok, nie stary kod 2-0-0-3
        if ($m[6][0] === null && $m[2][0] === '' && preg_match('/^(?:19|20)\d{2}$/', $m[1][0].$m[3][0].$m[4][0].$m[5][0]) === 1) {
            return null;
        }

        return new self([
            'abrasion' => $value(1),
            'coupe' => $value(3),
            'tear' => $value(4),
            'puncture' => $value(5),
            'iso' => $value(6),
            'impact' => $value(7),
        ], self::codeLiteral($segment, $m), false, $edition);
    }

    private static function worded(string $segment, ?string $edition = null): ?self
    {
        $cut = '(?i:przecię|przecie|przeciec)\p{L}*';
        $patterns = [
            // „przetarcie” to w polskich kartach ścieranie (Canis/CXS: „odporność na przetarcie - 2”). Tylko
            // „przetarci…/przetarć” — samo „przetar” łapałoby „przetargu 2” z treści zamówienia.
            'abrasion' => ['/(?i:ściera|sciera|przetarci|przetarć)\p{L}*'.self::GAP.'([0-4Xx])(?![\p{L}\d])/u'],
            'coupe' => [
                '/'.$cut.'[^;,\d]{0,30}?(?i:coup)\p{L}*(?i:\s*test)?["”]?\s*\)?\s*[:\-–—]?\s*(?:(?i:poziom)\p{L}*\s*)?([0-5Xx])(?![\p{L}\d])/u',
                // Bez słowa „coup” cyfra tuż za przecięciem to też Coup Test: poziomy EN 388 w tej pozycji to cyfry
                // 0–5, a ISO 13997 to litery A–F, więc cyfra nie może być wynikiem ISO (Canis/CXS: „odporność na
                // przecięcie - 1”). „X” tu nie wchodzi — bez „coup” nie wiadomo, której metody dotyczy. Bez nawiasu
                // z GAP i bez liczby z jednostką: „przecięcie (TDM) 2 N”, „przecięcie 3,5 N” to wynik ISO w niutonach,
                // nie poziom Coup. Samo słowo przecięcia, nie każde „przecię…”: „przeciętnie 3 miesiące” to nie poziom.
                '/(?i:przecięci|przecieci|przecięć|przeciec)\p{L}*\s*[:\-–—]?\s*(?:(?i:poziom|level)\p{L}*\s*[:\-–—]?\s*)?([0-5])(?![\p{L}\d]|[,.]\d|\h*(?i:N|niuton\p{L}*)(?!\p{L}))/u',
            ],
            'tear' => ['/(?i:rozdziera|rozdzier|rozerwa|rozdar)\p{L}*'.self::GAP.'([0-4Xx])(?![\p{L}\d])/u'],
            'puncture' => ['/(?i:przekłu|przeklu|przebic)\p{L}*'.self::GAP.'([0-4Xx])(?![\p{L}\d])/u'],
            'iso' => [
                '/(?i:iso)\s*139[79]7\s*[:\-–—]?\s*(?:(?i:poziom)\p{L}*\s*)?([A-F])(?![\p{L}\d])/u',
                // „przecięcie ISO – klasa D” (Canis) jak „poziom D”
                '/'.$cut.'(?![^;,\d]{0,30}?(?i:coup))[^;,\d]{0,40}?(?i:poziom|klas)\p{L}*\s*[:\-–—]?\s*([A-F])(?![\p{L}\d])/u',
            ],
        ];

        $levels = array_fill_keys(array_keys(self::POSITIONS), null);
        $start = null;
        $end = null;
        foreach ($patterns as $key => $alternatives) {
            foreach ($alternatives as $pattern) {
                if (preg_match($pattern, $segment, $m, PREG_OFFSET_CAPTURE) !== 1) {
                    continue;
                }
                $levels[$key] = strtoupper($m[1][0]);
                $start = min($start ?? PHP_INT_MAX, $m[0][1]);
                $end = max($end ?? 0, $m[0][1] + strlen($m[0][0]));
                break;
            }
        }
        if ($start === null || $end === null) {
            return null;
        }

        return new self($levels, trim(substr($segment, $start, $end - $start)), true, $edition);
    }
}
