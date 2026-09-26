<?php

declare(strict_types=1);

namespace App\Support\RequirementCheck;

/**
 * Poziomy EN 407 odczytane z tekstu: kod tuż za numerem normy albo w nawiasie za tytułem normy („X1XXXX”,
 * „X.1.X.X.X” — karty ucinają końcowe pozycje; łączniki jak w En388Code, więc „EN 407. Opakowanie 1200 szt” to nie
 * kod) albo zapis słowny („ciepło kontaktowe poziom 1”, „Odporność na palenie - x (brak testu)”). Nazwy ciepła
 * kontaktowego, konwekcyjnego i promieniowania są terminami tej normy, więc czytamy je także bez numeru normy
 * („Ciepło kontaktowe: poziom 1 (do 100°C/15s)” w specyfikacji 44-304). Pozycja null = tekst jej nie podaje.
 */
final readonly class En407Code
{
    public const POSITIONS = [
        'flame' => 'palność',
        'contact' => 'ciepło kontaktowe',
        'convective' => 'ciepło konwekcyjne',
        'radiant' => 'ciepło promieniowania',
        'small_splash' => 'małe rozpryski metalu',
        'large_splash' => 'duże ilości metalu',
    ];

    private const NORM = '/(?<![\p{L}\d])EN\s?(?:ISO\s?)?407(?!\d)(?:\s?:\s?(?:19|20)\d{2}(?!\d))?/iu';

    private const STOP = '/;|(?<![\p{L}\d])(?:EN\s?(?:ISO\s?)?\d{3,5}(?!\d)|ANSI)/iu';

    /** 4–6 pozycji z jednakowym separatorem — także myślnikiem i ukośnikiem, jak EN 388 („X-1-X-X-X-X” u Canis). */
    private const CODE = '(?<![\p{L}\d.])([0-4Xx])([ .\/-]?)([0-4Xx])\2([0-4Xx])\2([0-4Xx])(?:\2([0-4Xx]))?(?:\2([0-4Xx]))?(?![\p{L}\d])';

    private const GAP = '\s*[:\-–—]?\s*(?:(?i:poziom|level)\p{L}*\s*[:\-–—]?\s*)?';

    private const VALUE = '([0-4Xx])(?![\p{L}\d])';

    /** Słowne pozycje czytane tylko za numerem normy — „palenie” czy „odpryski” bez normy znaczą co innego. */
    private const WINDOW_WORDS = [
        'flame' => '(?i:palen|paln|płomie|plomie)\p{L}*',
        'small_splash' => '(?i:drobn|mał|mal)\p{L}*\s+(?i:odprysk|rozprysk|kropl)\p{L}*(?:\s+\p{L}+){0,2}',
        'large_splash' => '(?i:duż|duz)\p{L}*\s+(?i:ilości|ilosci|odprysk|rozprysk)\p{L}*(?:\s+\p{L}+){0,2}',
    ];

    private const TERM_WORDS = [
        'contact' => '(?i:ciepł|ciepl)\p{L}*\s+(?i:kontakt)\p{L}*',
        'convective' => '(?i:ciepł|ciepl)\p{L}*\s+(?i:konwekc)\p{L}*',
        'radiant' => '(?i:promieniowan\p{L}*\s+ciepl|ciepł\p{L}*\s+promieni|ciepl\p{L}*\s+promieni)\p{L}*',
    ];

    /**
     * @param  array<string, string|null>  $levels  klucze jak POSITIONS
     * @param  string  $context  fragment, z którego odczytano poziomy (do °C w wymaganiu)
     */
    private function __construct(
        public array $levels,
        public string $text,
        public bool $worded,
        public string $context,
    ) {}

    public static function first(string $text): ?self
    {
        return self::allIn($text)[0] ?? null;
    }

    /**
     * Kody za każdym „EN 407”; gdy żadnego nie ma — jeden zapis słowny z terminów normy.
     *
     * @return list<self>
     */
    public static function allIn(string $text): array
    {
        $out = [];
        foreach (self::segments($text) as $segment) {
            $code = self::code($segment) ?? self::worded($segment, array_merge(self::WINDOW_WORDS, self::TERM_WORDS));
            if ($code !== null) {
                $out[] = $code;
            }
        }
        if ($out === []) {
            $terms = self::worded($text, self::TERM_WORDS);
            if ($terms !== null) {
                $out[] = $terms;
            }
        }

        return $out;
    }

    /**
     * „EN 407 poziom 1 (do 100°C)” bez nazwy parametru — nie wiadomo, której pozycji dotyczy. Zwraca dosłowny
     * fragment od numeru normy do poziomu; null, gdy tekst podaje pozycje albo nie podaje żadnego poziomu.
     */
    public static function unspecifiedLevel(string $text): ?string
    {
        if (self::allIn($text) !== []) {
            return null;
        }
        if (preg_match('/(?<![\p{L}\d])EN\s?(?:ISO\s?)?407(?!\d)(?:\s?:\s?(?:19|20)\d{2}(?!\d))?[^;\d]{0,40}?(?i:poziom|level)\p{L}*\s*[:\-–—]?\s*[1-4](?!\d)/u', $text, $m) === 1) {
            return trim($m[0]);
        }

        return null;
    }

    public function canonical(): string
    {
        $out = '';
        foreach (array_keys(self::POSITIONS) as $key) {
            $out .= $this->levels[$key] ?? '-';
        }

        return $out;
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
            $rest = mb_strcut($text, $offset + strlen($norm), 1600);
            $rest = preg_split(self::STOP, $rest, 2)[0] ?? '';
            $out[] = mb_substr($rest, 0, 480);
        }

        return $out;
    }

    private static function code(string $segment): ?self
    {
        $m = En388Code::codeMatch($segment, self::CODE);
        if ($m === null) {
            return null;
        }
        $digits = array_map(static fn (int $group): ?string => $m[$group][0] ?? null, [1, 3, 4, 5, 6, 7]);
        // „EN 407 (2004)” — rok, nie kod
        if ($m[2][0] === '' && $digits[4] === null && preg_match('/^(?:19|20)\d{2}$/', implode('', array_slice($digits, 0, 4))) === 1) {
            return null;
        }
        $levels = array_combine(
            array_keys(self::POSITIONS),
            array_map(static fn (?string $d): ?string => $d === null ? null : strtoupper($d), $digits),
        );

        return new self($levels, En388Code::codeLiteral($segment, $m), false, $segment);
    }

    /**
     * @param  array<string, string>  $words
     */
    private static function worded(string $text, array $words): ?self
    {
        $levels = array_fill_keys(array_keys(self::POSITIONS), null);
        $start = null;
        $end = null;
        foreach ($words as $key => $word) {
            if (preg_match('/'.$word.self::GAP.self::VALUE.'/u', $text, $m, PREG_OFFSET_CAPTURE) !== 1) {
                continue;
            }
            $levels[$key] = strtoupper($m[1][0]);
            $start = min($start ?? PHP_INT_MAX, $m[0][1]);
            $end = max($end ?? 0, $m[0][1] + strlen($m[0][0]));
        }
        if ($start === null || $end === null) {
            return null;
        }
        $literal = trim(substr($text, $start, $end - $start));

        return new self($levels, $literal, true, mb_strcut($text, $start, $end - $start + 60));
    }
}
