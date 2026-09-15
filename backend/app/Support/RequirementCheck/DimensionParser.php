<?php

declare(strict_types=1);

namespace App\Support\RequirementCheck;

/**
 * Odczyt wymiarów z tekstu wymagania albo pola karty: nazwa wymiaru przed liczbą, operator,
 * liczby i jednostka przeliczona na mm. Niczego nie porównuje — to robi DimensionChecker.
 *
 * Wymiar wiąże się z liczbą tylko wtedy, gdy nazwa stoi przed nią w obrębie jednego zdania
 * i najwyżej 25 znaków dalej („długość ok. 475 mm”, „grubość w części dłoniowej 0,45 mm”).
 * Liczba bez jednostki długości nie jest wymiarem (EN 388, rozmiary 35–48, AQL 1,5), a µm
 * liczymy tylko przy grubości — gdzie indziej to zwykle zapis filtra albo powłoki.
 *
 * @phpstan-type Measure array{label: ?string, qualifier: ?string, kind: string, unitless: bool, op: string, op_text: ?string, numbers: list<float>, values_mm: list<float>, text: string, alias: ?string, fragment: string, listed: bool}
 */
final class DimensionParser
{
    public const LENGTH = 'length';

    public const WIDTH = 'width';

    public const HEIGHT = 'height';

    public const THICKNESS = 'thickness';

    public const DIAMETER = 'diameter';

    public const DIMENSIONS = 'dimensions';

    /** „Rozmiary: 75x75 cm, 100x75 cm” — lista wariantów do zamówienia, nie wymiar jednej sztuki. */
    public const SIZES = 'sizes';

    public const KIND_SINGLE = 'single';

    public const KIND_RANGE = 'range';

    public const KIND_DIMS = 'dims';

    public const LABELS = [
        self::LENGTH => 'Długość',
        self::WIDTH => 'Szerokość',
        self::HEIGHT => 'Wysokość',
        self::THICKNESS => 'Grubość',
        self::DIAMETER => 'Średnica',
        self::DIMENSIONS => 'Wymiary',
    ];

    /**
     * Dookreślenie wymiaru: „długość mankietu” to nie długość rękawicy. Tylko znane części wyrobu —
     * nieznane słowo między nazwą a liczbą („Długość: 47,5 cm”, „długość całkowita”) nie zmienia klucza.
     *
     * @var array<string, array{0: string, 1: string}>
     */
    public const QUALIFIERS = [
        'cuff' => ['/^mankiet/u', 'mankietu'],
        'palm' => ['/^dło[nń]/u', 'w części dłoniowej'],
        'finger' => ['/^palc/u', 'palców'],
        'foot' => ['/^stop(?:a|y|ie|ę)$/u', 'stopy'],
        'insole' => ['/^wkładk/u', 'wkładki'],
        'sole' => ['/^podeszw/u', 'podeszwy'],
        'shaft' => ['/^cholew/u', 'cholewki'],
        'sleeve' => ['/^rękaw(?:a|u|y|ów)?$/u', 'rękawa'],
        'leg' => ['/^nogaw/u', 'nogawki'],
        'lens' => ['/^(?:soczew|szyb)/u', 'soczewki'],
        'lanyard' => ['/^link(?:a|i|ę)$/u', 'linki'],
        'webbing' => ['/^taśm/u', 'taśmy'],
        'hose' => ['/^(?:przewod|węż|węża)/u', 'przewodu'],
        'blade' => ['/^ostrz/u', 'ostrza'],
        'inner' => ['/^wewnętrzn/u', 'wewnętrzna'],
        'outer' => ['/^zewnętrzn/u', 'zewnętrzna'],
    ];

    private const MAX_GAP = 25;

    /** Znak cala tylko tuż po liczbie („19"”, „19 "”); „in” nie w „5 in 1”. */
    private const UNIT = '(?:mm|cm|[µμ]m|m(?![\/²³2])|cal[eia]?|(?<=\d|\d\x20)(?:\'\'|″|")|inch(?:es)?|in(?!\s*\d))(?![\p{L}\d²³])';

    /** Rzeczowniki, które przed „o długości” nazywają cały wyrób, nie jego część („rękaw o długości 47,5 cm”). */
    private const NOT_PARTS_BEFORE_O = ['sleeve', 'lens', 'inner', 'outer'];

    /** Słowa, na których kończy się szukanie części przed „o” („soczewki wykonane z poliwęglanu o grubości”). */
    private const PART_STOP_WORDS = ['z', 'ze', 'w', 'we', 'na', 'do', 'od', 'dla', 'i', 'oraz', 'lub', 'albo', 'a', 'bez', 'przy', 'po', 'przez', 'pod', 'nad', 'jako'];

    /** Pierwsza liczba: nie w środku kodu („EN166”, „87-320”, „2.X.4”); „Ø6,6” przepuszczamy. */
    private const NUM_HEAD = '(?<![\d.,\/\-])(?<![^\W\d_Øø])\d+(?:[.,]\d+)?';

    private const NUM = '\d+(?:[.,]\d+)?';

    private const ABBREVIATIONS = ['ok', 'min', 'max', 'maks', 'ca', 'dł', 'szer', 'wys', 'np', 'tj', 'tzn', 'in', 'nr', 'zob', 'pkt'];

    private const OPERATORS = [
        'approx' => 'ok\.?|około|ca\.?|~',
        'min' => 'min\.?|minimum|minimalnie|co\s+najmniej|nie\s+mniej\s+niż|od|powyżej|≥|>=',
        'max' => 'max\.?|maks\.?|maksymalnie|maksimum|nie\s+więcej\s+niż|do|poniżej|≤|<=',
    ];

    /**
     * @return list<array<string, mixed>>
     */
    public function parse(string $text): array
    {
        // Wycinki (text, fragment) bierzemy z oryginału — cudzysłowy zastąpione spacją mają tę samą długość.
        if (preg_match_all(self::tokenPattern(), self::withoutQuotationMarks($text), $matches, PREG_SET_ORDER | PREG_OFFSET_CAPTURE | PREG_UNMATCHED_AS_NULL) === false) {
            return [];
        }

        $out = [];
        $prevEnd = 0;
        $prev = null;
        $prevIndex = null;
        foreach ($matches as $m) {
            $start = (int) $m[0][1];
            $end = $start + strlen((string) $m[0][0]);
            $measure = self::measure($text, $m);
            $segStart = self::segmentStart($text, $prevEnd, $start);
            $before = substr($text, $segStart, $start - $segStart);

            $label = self::nearestLabel($before, $measure['kind']);
            $qualifier = null;
            $fragmentStart = $start;
            $listed = false;
            if ($label !== null) {
                $gap = substr($before, $label['end']);
                $qualifier = self::qualifier($gap, substr($before, 0, $label['start']));
                $fragmentStart = $segStart + $label['start'];
            } elseif ($prev !== null && $prev['label'] !== null
                && preg_match('/^\s*(?:[,\/]|\s(?:i|oraz|lub)\s)?\s*$/iu', substr($text, $prevEnd, $start - $prevEnd)) === 1) {
                // „Rozmiary: 75x75 cm, 100x75 cm” — druga pozycja listy dziedziczy nazwę wymiaru.
                $label = ['kind' => $prev['label']];
                $qualifier = $prev['qualifier'];
                $gap = '';
                // „Długość: 28 cm, 30 cm, 32 cm” — kilka wartości jednej miary to warianty wyrobu.
                $listed = true;
                if ($prevIndex !== null) {
                    $out[$prevIndex]['listed'] = true;
                }
            } else {
                $gap = $before;
            }

            [$op, $opText] = self::operator($gap);
            if ($measure['kind'] === self::KIND_RANGE) {
                [$op, $opText] = ['range', null];
            }

            $prevEnd = $end;
            $prev = [
                'label' => $label['kind'] ?? null,
                'qualifier' => $qualifier,
                'kind' => $measure['kind'],
                'unitless' => $measure['unitless'],
                'op' => $op,
                'op_text' => $opText,
                'numbers' => $measure['numbers'],
                'values_mm' => $measure['values_mm'],
                'text' => $measure['text'],
                'alias' => $measure['alias'],
                'fragment' => trim(substr($text, $fragmentStart, $end - $fragmentStart)),
                'listed' => $listed,
            ];
            $prevIndex = null;
            if ($measure['micro'] && $prev['label'] !== self::THICKNESS) {
                continue;
            }
            if ($prev['label'] === null && $measure['unitless'] && $measure['kind'] !== self::KIND_DIMS) {
                continue;
            }
            $out[] = $prev;
            $prevIndex = array_key_last($out);
        }

        return $out;
    }

    public static function unitFactor(string $unit): float
    {
        $unit = mb_strtolower($unit);

        return match (true) {
            $unit === 'mm' => 1.0,
            $unit === 'cm' => 10.0,
            $unit === 'm' => 1000.0,
            in_array($unit, ['µm', 'μm'], true) => 0.001,
            default => 25.4,
        };
    }

    /**
     * Cudzysłów otwierający i zamykający cytat zamienia na spację (ta sama długość w bajtach), żeby
     * „Model "Długość 30" rozm. 9” nie dało 30 cali. Znak po liczbie poza cytatem zostaje calem.
     */
    private static function withoutQuotationMarks(string $text): string
    {
        if (! str_contains($text, '"') || preg_match_all('/"|\R/u', $text, $hits, PREG_OFFSET_CAPTURE) < 1) {
            return $text;
        }
        $open = false;
        foreach ($hits[0] as [$char, $offset]) {
            if ($char !== '"') {
                $open = false;

                continue;
            }
            $inch = ! $open && preg_match('/\d\x20?$/', substr($text, max(0, $offset - 2), min(2, $offset))) === 1;
            if (! $inch) {
                $text[$offset] = ' ';
                $open = ! $open;
            }
        }

        return $text;
    }

    private static function tokenPattern(): string
    {
        $u = self::UNIT;
        $h = self::NUM_HEAD;
        $n = self::NUM;

        return '/'
            ."(?<d1>{$h})\\s*[x×]\\s*(?<d2>{$n})(?:\\s*[x×]\\s*(?<d3>{$n}))?\\s*(?<du>{$u})"
            ."|(?<![\\p{L}])od\\s+(?<o1>{$h})\\s*(?<o1u>{$u})?\\s+do\\s+(?<o2>{$n})\\s*(?<o2u>{$u})"
            ."|(?<r1>{$h})\\s*(?<r1u>{$u})?\\s*[-–—]\\s*(?<r2>{$n})\\s*(?<r2u>{$u})"
            ."|(?<s1>{$h})\\s*(?<s1u>{$u})(?:\\s*\\(\\s*(?<a1>{$n})\\s*(?<a1u>{$u})\\s*\\)|\\s*\\/\\s*(?<a2>{$n})\\s*(?<a2u>{$u}))?"
            ."|(?<u1>{$h})\\s*[x×\\/]\\s*(?<u2>{$n})(?:\\s*[x×\\/]\\s*(?<u3>{$n}))?"
            ."|(?<e1>{$h})\\s*[-–—]\\s*(?<e2>{$n})"
            ."|(?<f1>{$h})"
            .'/iu';
    }

    /**
     * @param  array<int|string, array{0: ?string, 1: int}>  $m
     * @return array{kind: string, unitless: bool, micro: bool, numbers: list<float>, values_mm: list<float>, text: string, alias: ?string}
     */
    private static function measure(string $text, array $m): array
    {
        $num = static fn (string $g): float => (float) str_replace(',', '.', (string) $m[$g][0]);
        $has = static fn (string $g): bool => isset($m[$g]) && $m[$g][0] !== null;
        $slice = static fn (string $from, string $to): string => substr($text, $m[$from][1], $m[$to][1] + strlen((string) $m[$to][0]) - $m[$from][1]);
        $micro = static fn (string ...$groups): bool => array_filter($groups, static fn (string $g): bool => $has($g) && in_array(mb_strtolower((string) $m[$g][0]), ['µm', 'μm'], true)) !== [];
        $base = ['unitless' => false, 'alias' => null];

        if ($has('d1')) {
            $numbers = array_map($num, array_values(array_filter(['d1', 'd2', 'd3'], $has)));
            $factor = self::unitFactor((string) $m['du'][0]);

            return $base + ['kind' => self::KIND_DIMS, 'micro' => $micro('du'), 'numbers' => $numbers,
                'values_mm' => array_map(static fn (float $v): float => $v * $factor, $numbers), 'text' => $slice('d1', 'du')];
        }
        foreach ([['o1', 'o1u', 'o2', 'o2u'], ['r1', 'r1u', 'r2', 'r2u']] as [$lo, $loUnit, $hi, $hiUnit]) {
            if ($has($lo)) {
                $hiFactor = self::unitFactor((string) $m[$hiUnit][0]);
                $loFactor = $has($loUnit) ? self::unitFactor((string) $m[$loUnit][0]) : $hiFactor;

                return $base + ['kind' => self::KIND_RANGE, 'micro' => $micro($loUnit, $hiUnit), 'numbers' => [$num($lo), $num($hi)],
                    'values_mm' => [$num($lo) * $loFactor, $num($hi) * $hiFactor], 'text' => trim((string) $m[0][0])];
            }
        }
        if ($has('s1')) {
            $primary = ['s1', 's1u'];
            $alias = $has('a1') ? ['a1', 'a1u'] : ($has('a2') ? ['a2', 'a2u'] : null);
            // „19'' (47,5 cm)” i „47,5 cm (19 cali)” to jedno ograniczenie — liczymy z wartości metrycznej.
            if ($alias !== null && self::unitFactor((string) $m['s1u'][0]) === 25.4 && self::unitFactor((string) $m[$alias[1]][0]) !== 25.4) {
                [$primary, $alias] = [$alias, $primary];
            }
            $value = $num($primary[0]);

            return ['kind' => self::KIND_SINGLE, 'unitless' => false, 'micro' => $micro($primary[1]), 'numbers' => [$value],
                'values_mm' => [$value * self::unitFactor((string) $m[$primary[1]][0])],
                'text' => $slice($primary[0], $primary[1]), 'alias' => $alias === null ? null : $slice($alias[0], $alias[1])];
        }

        $unitless = ['unitless' => true, 'micro' => false, 'values_mm' => [], 'text' => trim((string) $m[0][0]), 'alias' => null];
        if ($has('u1')) {
            return $unitless + ['kind' => self::KIND_DIMS, 'numbers' => array_map($num, array_values(array_filter(['u1', 'u2', 'u3'], $has)))];
        }
        if ($has('e1')) {
            return $unitless + ['kind' => self::KIND_RANGE, 'numbers' => [$num('e1'), $num('e2')]];
        }

        return $unitless + ['kind' => self::KIND_SINGLE, 'numbers' => [$num('f1')]];
    }

    /** Początek zdania albo koniec poprzedniej liczby — nazwa wymiaru nie przechodzi przez średnik ani kropkę. */
    private static function segmentStart(string $text, int $from, int $to): int
    {
        $chunk = substr($text, $from, $to - $from);
        $start = $from;
        if (preg_match_all('/[;\n\r•|]|\.(?=\s)/u', $chunk, $hits, PREG_OFFSET_CAPTURE) > 0) {
            foreach ($hits[0] as [$char, $offset]) {
                if ($char === '.' && preg_match('/(?<![\p{L}])(\p{L}+)$/u', substr($chunk, 0, $offset), $word) === 1
                    && in_array(mb_strtolower($word[1]), self::ABBREVIATIONS, true)) {
                    continue;
                }
                $start = $from + $offset + strlen($char);
            }
        }

        return $start;
    }

    /**
     * @return array{kind: string, start: int, end: int}|null
     */
    private static function nearestLabel(string $before, string $kind): ?array
    {
        $pattern = '/(?<![\p{L}])(?:'
            .'(?<length>długoś(?:ć|ci|cią)|dł\.|length)'
            .'|(?<width>szerokoś(?:ć|ci|cią)|szer\.|width)'
            .'|(?<height>wysokoś(?:ć|ci|cią)|wys\.|height)'
            .'|(?<thickness>gruboś(?:ć|ci|cią)|thickness)'
            .'|(?<diameter>średnic(?:a|y|ą|ę|e)|diameter|[Øø⌀])'
            .'|(?<dimensions>wymiar(?:y|ów|ach|ami)?|dimensions?)'
            .'|(?<sizes>rozmiar(?:y|ów|ach|ami)?)'
            .')(?![\p{L}])/iu';
        if (preg_match_all($pattern, $before, $hits, PREG_SET_ORDER | PREG_OFFSET_CAPTURE | PREG_UNMATCHED_AS_NULL) < 1) {
            return null;
        }

        $inWindow = [];
        foreach ($hits as $hit) {
            $end = $hit[0][1] + strlen((string) $hit[0][0]);
            if (mb_strlen(substr($before, $end)) > self::MAX_GAP) {
                continue;
            }
            foreach ([self::LENGTH, self::WIDTH, self::HEIGHT, self::THICKNESS, self::DIAMETER, self::DIMENSIONS, self::SIZES] as $name) {
                if ($hit[$name][0] !== null) {
                    $inWindow[] = ['kind' => $name, 'start' => (int) $hit[0][1], 'end' => $end];
                }
            }
        }
        if ($inWindow === []) {
            return null;
        }
        // „wymiary ok. Ø 6,6 x 23,5 cm” — para liczb należy do „wymiary”, nie do znaku średnicy.
        if ($kind === self::KIND_DIMS) {
            foreach (array_reverse($inWindow) as $label) {
                if (in_array($label['kind'], [self::DIMENSIONS, self::SIZES], true)) {
                    return $label;
                }
            }
        }

        return $inWindow[array_key_last($inWindow)];
    }

    private static function qualifier(string $gap, string $beforeLabel): ?string
    {
        $words = preg_match_all('/\p{L}+/u', mb_strtolower($gap), $hits) > 0 ? $hits[0] : [];
        foreach ($words as $word) {
            foreach (self::QUALIFIERS as $key => [$pattern]) {
                if (preg_match($pattern, $word) === 1) {
                    return $key;
                }
            }
        }
        $beforeLabel = mb_strtolower($beforeLabel);
        $part = self::partBeforeO($beforeLabel);
        if ($part !== null) {
            return $part;
        }
        // Poza tym przed nazwą liczy się tylko przymiotnik („wewnętrzna długość”); „soczewki z poliwęglanu
        // o grubości 2,3 mm” to grubość wyrobu, tak samo zapisana na karcie okularów.
        if (preg_match('/(\p{L}+)\s*$/u', $beforeLabel, $prevWord) === 1) {
            foreach (['inner', 'outer'] as $key) {
                if (preg_match(self::QUALIFIERS[$key][0], $prevWord[1]) === 1) {
                    return $key;
                }
            }
        }

        return null;
    }

    /**
     * „mankiet (ściągaczowy) o długości 30 cm” to długość mankietu, jak „długość mankietu 30 cm”.
     * Szukamy części najwyżej trzy słowa przed „o”, do przyimka („soczewki wykonane z poliwęglanu
     * o grubości” — bez dookreślenia, jak w wymaganiu poz. 6). Rękaw i soczewki to tu cały wyrób.
     */
    private static function partBeforeO(string $beforeLabel): ?string
    {
        if (preg_match('/(?:^|\s)o\s+$/u', $beforeLabel, $o, PREG_OFFSET_CAPTURE) !== 1) {
            return null;
        }
        $words = preg_split('/\s+/u', trim(mb_substr(substr($beforeLabel, 0, $o[0][1]), -60)), -1, PREG_SPLIT_NO_EMPTY) ?: [];
        foreach (array_slice(array_reverse($words), 0, 3) as $word) {
            if (preg_match('/^\p{L}+$/u', $word) !== 1 || in_array($word, self::PART_STOP_WORDS, true)) {
                return null;
            }
            foreach (self::QUALIFIERS as $key => [$pattern]) {
                if (preg_match($pattern, $word) === 1) {
                    return in_array($key, self::NOT_PARTS_BEFORE_O, true) ? null : $key;
                }
            }
        }

        return null;
    }

    /**
     * @return array{0: string, 1: ?string}
     */
    private static function operator(string $gap): array
    {
        foreach (self::OPERATORS as $op => $alternatives) {
            if (preg_match('/(?<![\p{L}])('.$alternatives.')\s*[:=]?\s*(?:[Øø⌀]\s*)?$/iu', $gap, $hit) === 1) {
                return [$op, $hit[1]];
            }
        }

        return ['exact', null];
    }
}
