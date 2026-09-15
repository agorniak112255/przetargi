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

    private const NORM = '/(?<![\p{L}\d])EN\s?(?:ISO\s?)?388(?!\d)(?:\s?:\s?(?:19|20)\d{2}(?!\d))?(?:\s*\+\s*A\d(?:\s?:\s?(?:19|20)\d{2}(?!\d))?)?/iu';

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
        foreach (self::segments($text) as $segment) {
            $code = self::code($segment) ?? self::worded($segment);
            if ($code !== null) {
                $out[] = $code;
            }
        }

        return $out;
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
     * @return list<string>
     */
    private static function segments(string $text): array
    {
        if (preg_match_all(self::NORM, $text, $m, PREG_OFFSET_CAPTURE) < 1) {
            return [];
        }
        $out = [];
        foreach ($m[0] as [$norm, $offset]) {
            $rest = mb_strcut($text, $offset + strlen($norm), 1200);
            $rest = preg_split(self::STOP, $rest, 2)[0] ?? '';
            $out[] = mb_substr($rest, 0, 320);
        }

        return $out;
    }

    private static function code(string $segment): ?self
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
        ], self::codeLiteral($segment, $m), false);
    }

    private static function worded(string $segment): ?self
    {
        $cut = '(?i:przecię|przecie|przeciec)\p{L}*';
        $patterns = [
            'abrasion' => ['/(?i:ściera|sciera)\p{L}*'.self::GAP.'([0-4Xx])(?![\p{L}\d])/u'],
            'coupe' => ['/'.$cut.'[^;,\d]{0,30}?(?i:coup)\p{L}*(?i:\s*test)?["”]?\s*\)?\s*[:\-–—]?\s*(?:(?i:poziom)\p{L}*\s*)?([0-5Xx])(?![\p{L}\d])/u'],
            'tear' => ['/(?i:rozdziera|rozdzier|rozerwa|rozdar)\p{L}*'.self::GAP.'([0-4Xx])(?![\p{L}\d])/u'],
            'puncture' => ['/(?i:przekłu|przeklu|przebic)\p{L}*'.self::GAP.'([0-4Xx])(?![\p{L}\d])/u'],
            'iso' => [
                '/(?i:iso)\s*139[79]7\s*[:\-–—]?\s*(?:(?i:poziom)\p{L}*\s*)?([A-F])(?![\p{L}\d])/u',
                '/'.$cut.'(?![^;,\d]{0,30}?(?i:coup))[^;,\d]{0,40}?(?i:poziom)\p{L}*\s*[:\-–—]?\s*([A-F])(?![\p{L}\d])/u',
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

        return new self($levels, trim(substr($segment, $start, $end - $start)), true);
    }
}
