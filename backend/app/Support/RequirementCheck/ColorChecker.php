<?php

declare(strict_types=1);

namespace App\Support\RequirementCheck;

use App\Support\CatalogSlangDictionary;

/**
 * Kolor z wymagania porównany z kartą — inna barwa to „do sprawdzenia”, bo kolor bywa wariantem do zamówienia.
 *
 * Barwę z wymagania bierzemy tylko z fraz „w kolorze …”, „kolor: …”, „barwy …” i z przymiotnika przy
 * wyrobie w nagłówku — „biała lista” czy „z niebieskim wkładem” nie są kolorem wyrobu. Na karcie oceniamy
 * zdania osobno: ok tylko wtedy, gdy to samo zdanie podaje tę samą barwę (i hi-vis, jeśli wymagany).
 * „Kolor: żółty” w specyfikacji i „wysoka widoczność” w cechach to już wniosek → unclear.
 * „Odblaskowy” nie jest fluorescencyjny: taśma odblaskowa nie czyni odzieży barwą wysokiej widzialności.
 * Wymagana barwa części („z czarnym mankietem”, „Podeszwa: czarna”), zaprzeczona („niedostępny
 * w kolorze czarnym”) albo z nazwy-marki („Blue Grip”) nie potwierdza koloru wyrobu → co najwyżej unclear.
 */
final class ColorChecker implements ParameterChecker
{
    private const B = '(?<![\p{L}\p{N}])';

    private const E = '(?![\p{L}\p{N}])';

    /** Końcówki przymiotnika („żółty”, „żółtym”, „niebieskiej”) i człon złożenia („żółto-czarny”). */
    private const ENDINGS = '(?:iego|iemu|ego|emu|ymi|imi|ych|ich|iej|ej|ie|ym|im|y|a|e|ą|i|o(?=-))';

    /** Mała zamknięta lista barw: rdzenie przymiotników, rzeczowniki po „Kolor:” i nazwy angielskie (`en`) z opisów producentów. */
    private const COLORS = [
        'żółty' => ['roots' => ['żółt'], 'words' => [], 'en' => ['yellow']],
        'pomarańczowy' => ['roots' => ['pomarańczow'], 'words' => ['pomarańcz'], 'en' => ['orange']],
        'czerwony' => ['roots' => ['czerwon'], 'words' => ['czerwień'], 'en' => ['red']],
        'niebieski' => ['roots' => ['niebiesk'], 'words' => [], 'en' => ['blue']],
        'granatowy' => ['roots' => ['granatow'], 'words' => ['granat'], 'en' => ['navy']],
        'zielony' => ['roots' => ['zielon'], 'words' => ['zieleń'], 'en' => ['green']],
        'czarny' => ['roots' => ['czarn'], 'words' => ['czerń'], 'en' => ['black']],
        'biały' => ['roots' => ['biał'], 'words' => ['biel'], 'en' => ['white']],
        'szary' => ['roots' => ['szar'], 'words' => ['szarość'], 'en' => ['grey', 'gray']],
        'brązowy' => ['roots' => ['brązow'], 'words' => ['brąz'], 'en' => ['brown']],
        'fioletowy' => ['roots' => ['fioletow'], 'words' => ['fiolet'], 'en' => ['purple']],
        'różowy' => ['roots' => ['różow'], 'words' => [], 'en' => ['pink']],
        'beżowy' => ['roots' => ['beżow'], 'words' => ['beż'], 'en' => ['beige']],
        'khaki' => ['roots' => [], 'words' => ['khaki'], 'en' => []],
        'oliwkowy' => ['roots' => ['oliwkow'], 'words' => [], 'en' => ['olive']],
        'limonkowy' => ['roots' => ['limonkow'], 'words' => [], 'en' => ['lime']],
        'seledynowy' => ['roots' => ['seledynow'], 'words' => ['seledyn'], 'en' => []],
        'grafitowy' => ['roots' => ['grafitow'], 'words' => [], 'en' => []],
    ];

    /**
     * Części wyrobu, których barwa nie jest barwą wyrobu. „Powłoka” w rękawicach bywa barwą dłoni —
     * wtedy też tylko unclear, nie ok. Rdzenie polskie z dowolną końcówką, angielskie słowa całe
     * („seamless”, „solely”, „hoodie” to nie części).
     */
    private const PARTS = '(?:(?:podeszw|mankiet|ściągacz|logo|nadruk|napis|haft|sznurowad|sznurów|wstaw(?:k|ek)|lamów(?:k|ek)'
        .'|suwak|kaptur|podszew|powło[kc]|opraw|zausznik|taśm|wkład|obszyci|wykończeni)\p{L}*'
        .'|(?:sz(?:ew|w(?:y|u|em|ami|ach|ów|ie))|zam(?:ek|k(?:a|i|iem|u|ach|ów|ami))|pas(?:ek|k(?:a|i|iem|u|ach|ów|ami))'
        .'|(?:out|mid)?soles?|cuffs?|logos?|print(?:s|ed|ing)?|stitch(?:es|ed|ing)?|seams?|laces?|trims?|zip(?:per)?s?'
        .'|hoods?|linings?|coatings?|frames?|temples?|straps?|tapes?|inserts?|piping|palms?)'.self::E.')';

    /** Zaprzeczenie przed barwą w tym samym członie zdania; „bez” tylko tuż przed nią („bez lateksu w kolorze…” to nie zaprzeczenie). */
    private const NEGATION_BEFORE = '/(?<![\p{L}])(?:(?:nie|niedostępn\p{L}*|brak\p{L}*|not|no|without|unavailable)(?:\s+[^\s,|]+){0,4}|bez(?:\s+\p{L}+)?)\s+$/iu';

    /** Etykieta „Kolor:” tuż przed barwą zaczyna nowy parametr — wcześniejsze słowa go nie dotyczą. */
    private const COLOR_LABEL_BEFORE = '/(?<![\p{L}])(?:kolor|barwa|colou?r)\s*:\s*$/iu';

    private const OUT_NEGATED = 'negated';

    private const OUT_PART = 'part';

    private const OUT_NAME = 'name';

    private const OUT_NOTES = [
        self::OUT_NEGATED => 'Karta wymienia tę barwę w zaprzeczeniu („niedostępny”, „bez”) — sprawdź kolor.',
        self::OUT_PART => 'Barwa na karcie dotyczy części wyrobu (podeszwa, mankiet, nadruk…), nie całego wyrobu — sprawdź kolor.',
        self::OUT_NAME => 'Barwa występuje w nazwie (marka lub model), a nie jako kolor wyrobu — sprawdź kolor.',
    ];

    /** Barwa wysokiej widzialności; celowo bez „odblaskowy”. */
    private const HI_VIS = [
        'fluo(?:ro|re)scen\p{L}*',
        'fluo'.self::E,
        'hi[\s-]?vi[sz]\p{L}*',
        'high[\s-]?vi[sz](?:ibility)?'.self::E,
        'high\s+visibility',
        'wysok\p{L}*\s+(?:widoczno|widzialno)\p{L}*',
        'ostrzegawcz\p{L}*',
    ];

    /** „Kolor”, „w kolorze”, „barwy” — nie „barwnik”, „kolorystyka”. */
    private const COLOR_PHRASE = '/'.self::B.'(?:kolor(?:y|u|ze|em|ach|ów)?|barw(?:a|y|ie|ą|ach)?)'.self::E.'/iu';

    /** Przyimek tuż przed barwą w nagłówku: „z niebieskim wkładem”, „na czarnej podeszwie” — część, nie wyrób. */
    private const PREPOSITION_BEFORE = '/(?<![\p{L}])(?:z|ze|na|w|we|do|od|pod|przy|dla|bez)\s+(?:\p{L}+\s+)?$/iu';

    private const NOTE_OTHER_COLOR = 'Inna barwa na karcie — kolor bywa wariantem do zamówienia, sprawdź warianty.';

    private const NOTE_HI_VIS_ONLY = 'Karta nie łączy barwy z wysoką widocznością w jednym zdaniu — sprawdź kolor.';

    public function group(): string
    {
        return 'color';
    }

    public function check(string $requirement, array $cardSources): array
    {
        $required = self::requiredColor($requirement);
        if ($required === null) {
            return [];
        }

        $direct = [];
        $hints = [];
        $directOut = [];
        $hintOut = [];
        $otherColor = false;
        foreach ($cardSources as $source) {
            foreach (preg_split('/[.;!?\n]+/u', $source->text, -1, PREG_SPLIT_NO_EMPTY) ?: [] as $clause) {
                $colors = [];
                $hiVis = [];
                foreach ([...self::colorMatches($clause), ...self::hiVisMatches($clause)] as $match) {
                    [$text, $offset, $color] = $match;
                    $out = self::outOfScope($source, $clause, $text, $offset);
                    $relevant = $color === null ? $required['hi_vis'] : in_array($color, $required['colors'], true);
                    if ($out === null || (! $relevant && $out[0] !== self::OUT_NEGATED)) {
                        // część w innej barwie nadal świadczy o innej barwie wyrobu — zostaje jak dotąd
                        if ($color === null) {
                            $hiVis[] = $match;
                        } else {
                            $colors[] = $match;
                        }

                        continue;
                    }
                    if (! $relevant) {
                        // zaprzeczona inna barwa niczego nie mówi o wymaganej
                        continue;
                    }
                    [$reason, $start, $stop] = $out;
                    $span = trim(substr($clause, $start, $stop - $start));
                    $outFinding = CheckRow::finding($source, mb_strlen($span) <= 60 ? $span : $text, Status::Unclear, [
                        'colors' => $color === null ? [] : [$color],
                        'hi_vis' => $color === null,
                    ]);
                    if ($reason === self::OUT_NEGATED) {
                        // zaprzeczenie przy „Kolor: czarny” gdzie indziej to sprzeczność — ma być widoczna mimo ok
                        self::push($direct, $outFinding);
                        $directOut[$reason] = true;
                    } else {
                        self::push($hints, $outFinding);
                        $hintOut[$reason] = true;
                    }
                }

                $found = self::summarise($clause, $colors, $hiVis);
                if ($found === null) {
                    continue;
                }
                $extra = ['colors' => $found['colors'], 'hi_vis' => $found['hi_vis']];
                $finding = static fn (Status $verdict): array => CheckRow::finding($source, $found['text'], $verdict, $extra);

                if ($found['colors'] === []) {
                    // samo „wysoka widoczność” bez nazwy barwy
                    self::push($hints, $finding(Status::Unclear));
                } elseif ($required['colors'] === []) {
                    // wymaganie podaje tylko hi-vis — barwy karty nie ma z czym porównać
                    self::push($direct, $finding(Status::Unclear));
                } elseif (! self::sameColor($found['colors'], $required)) {
                    $otherColor = true;
                    self::push($direct, $finding(Status::Unclear));
                } elseif ($required['hi_vis'] && ! $found['hi_vis']) {
                    // „Kolor: żółty” przy wymaganym fluorescencyjnym żółtym — fluorescencji zdanie nie potwierdza
                    self::push($hints, $finding(Status::Unclear));
                } else {
                    self::push($direct, $finding(Status::Ok));
                }
            }
        }

        $hasOk = in_array(Status::Ok->value, array_column($direct, 'verdict'), true);
        $findings = $hasOk ? $direct : [...$direct, ...$hints];
        $status = Status::fromCardVerdicts(array_map(
            static fn (array $finding): Status => Status::from($finding['verdict']),
            $findings,
        ));
        $notes = array_map(
            static fn (string $reason): string => self::OUT_NOTES[$reason],
            array_keys($hasOk ? $directOut : $directOut + $hintOut),
        );
        if ($otherColor) {
            $notes[] = self::NOTE_OTHER_COLOR;
        }
        if ($notes === [] && $status === Status::Unclear) {
            $notes[] = self::NOTE_HI_VIS_ONLY;
        }
        $note = $notes === [] ? null : implode(' ', $notes);

        return [new CheckRow(
            key: 'color',
            label: 'Kolor',
            required: [
                'text' => self::describe($required),
                'quote' => CheckRow::quote($requirement, $required['fragment']),
                'colors' => $required['colors'],
                'hi_vis' => $required['hi_vis'],
            ],
            card: $findings,
            status: $status,
            note: $note,
        )];
    }

    /**
     * Barwa i hi-vis z wymagania: okna po „w kolorze/kolor:/barwy” do końca frazy oraz przymiotnik
     * w nagłówku bez przyimka przed nim.
     *
     * @return array{colors: list<string>, hi_vis: bool, alternatives: bool, fragment: string}|null
     */
    private static function requiredColor(string $requirement): ?array
    {
        $colors = [];
        $hiVis = false;
        $alternatives = false;
        $fragment = '';

        if (preg_match_all(self::COLOR_PHRASE, $requirement, $m, PREG_OFFSET_CAPTURE) > 0) {
            foreach ($m[0] as [$phrase, $offset]) {
                $window = self::phraseWindow(substr($requirement, $offset + strlen($phrase)));
                $found = self::analyse($window);
                if ($found === null) {
                    continue;
                }
                $colors = [...$colors, ...$found['colors']];
                $hiVis = $hiVis || $found['hi_vis'];
                $alternatives = $alternatives || preg_match('/\s(?:lub|albo)\s|\//iu', $window) === 1;
                if ($fragment === '') {
                    $fragment = trim($phrase.$window);
                }
            }
        }

        $head = CatalogSlangDictionary::requirementHead($requirement);
        foreach (self::headMatches($head) as [$text, $color]) {
            if ($color === null) {
                $hiVis = true;
            } else {
                $colors[] = $color;
            }
            if ($fragment === '') {
                $fragment = $text;
            }
        }

        $colors = array_values(array_unique($colors));
        if ($colors === [] && ! $hiVis) {
            return null;
        }

        return ['colors' => $colors, 'hi_vis' => $hiVis, 'alternatives' => $alternatives, 'fragment' => $fragment];
    }

    /**
     * Tekst po „w kolorze” do końca frazy: do kropki/nawiasu/średnika, a przecinek kończy frazę,
     * chyba że po nim jest kolejna barwa („żółty, pomarańczowy lub niebieski”).
     */
    private static function phraseWindow(string $after): string
    {
        $after = mb_substr($after, 0, 80);
        $after = (string) (preg_split('/[.;()\n]/u', $after, 2)[0] ?? '');
        $segments = explode(',', $after);
        $window = (string) array_shift($segments);
        foreach ($segments as $segment) {
            $lead = (string) preg_replace('/^\s*(?:lub|albo|oraz|i)\s+/iu', '', $segment);
            $found = self::analyse($lead);
            if ($found === null || $found['colors'] === [] || mb_stripos(ltrim($lead), $found['text']) !== 0) {
                break;
            }
            $window .= ','.$segment;
        }

        return $window;
    }

    /**
     * Barwy (i hi-vis jako null) w nagłówku wymagania, z pominięciem tych po przyimku.
     *
     * @return list<array{0: string, 1: ?string}>
     */
    private static function headMatches(string $head): array
    {
        $out = [];
        foreach ([...self::colorMatches($head), ...self::hiVisMatches($head)] as [$text, $offset, $color]) {
            if (preg_match(self::PREPOSITION_BEFORE, mb_substr(substr($head, 0, $offset), -30)) === 1) {
                continue;
            }
            $out[] = [$text, $color];
        }

        return $out;
    }

    /**
     * Barwy i hi-vis w jednym zdaniu karty albo oknie wymagania; `text` to dosłowny fragment
     * od pierwszej do ostatniej wzmianki (albo pierwsza wzmianka, gdy zdanie jest długie).
     *
     * @return array{colors: list<string>, hi_vis: bool, text: string}|null
     */
    private static function analyse(string $clause): ?array
    {
        return self::summarise($clause, self::colorMatches($clause), self::hiVisMatches($clause));
    }

    /**
     * @param  list<array{0: string, 1: int, 2: string}>  $colors
     * @param  list<array{0: string, 1: int, 2: null}>  $hiVis
     * @return array{colors: list<string>, hi_vis: bool, text: string}|null
     */
    private static function summarise(string $clause, array $colors, array $hiVis): ?array
    {
        $all = [...$colors, ...$hiVis];
        if ($all === []) {
            return null;
        }
        usort($all, static fn (array $a, array $b): int => $a[1] <=> $b[1]);
        $start = $all[0][1];
        $end = max(array_map(static fn (array $match): int => $match[1] + strlen($match[0]), $all));
        $span = trim(substr($clause, $start, $end - $start));

        return [
            'colors' => array_values(array_unique(array_column($colors, 2))),
            'hi_vis' => $hiVis !== [],
            'text' => mb_strlen($span) <= 60 ? $span : $all[0][0],
        ];
    }

    /**
     * @return list<array{0: string, 1: int, 2: string}>
     */
    private static function colorMatches(string $text): array
    {
        $out = [];
        foreach (self::COLORS as $color => $forms) {
            $alternatives = [...$forms['words'], ...$forms['en']];
            if ($forms['roots'] !== []) {
                $alternatives[] = '(?:jasno|ciemno)?(?:'.implode('|', $forms['roots']).')'.self::ENDINGS;
            }
            // „biała lista”, „czarna lista” to nie barwa wyrobu
            $pattern = '/'.self::B.'(?:'.implode('|', $alternatives).')'.self::E.'(?!\s+list)/iu';
            if (preg_match_all($pattern, $text, $m, PREG_OFFSET_CAPTURE) > 0) {
                foreach ($m[0] as [$match, $offset]) {
                    $out[] = [$match, $offset, $color];
                }
            }
        }

        return $out;
    }

    /**
     * Czy wzmianka na karcie nie dotyczy barwy wyrobu: [powód, początek, koniec fragmentu] w bajtach zdania
     * albo null. Patrzymy tylko na człon zdania (do przecinka/„|”): zaprzeczenie przed barwą, rzeczownik
     * części tuż po barwie („czarna gumowa podeszwa”) albo do 30 znaków przed nią („Kolor podeszwy: czarny”,
     * „Nadruk logo w kolorze…”), przyimek przed barwą bez słowa „kolor” obok („z czarnym mankietem”),
     * angielska barwa w nazwie bez słowa kolor albo pisana jak marka („Blue Grip”).
     *
     * @return array{0: string, 1: int, 2: int}|null
     */
    private static function outOfScope(CardSource $source, string $clause, string $match, int $offset): ?array
    {
        $end = $offset + strlen($match);
        // okno 240 bajtów przed barwą — długi opis bez przecinków nie może dać kwadratowego czasu
        $from = self::segmentStart($clause, max(0, $offset - 240), $offset);
        while ($from > 0 && $from < $offset && (ord($clause[$from]) & 0xC0) === 0x80) {
            $from++;
        }
        $before = substr($clause, $from, $offset - $from);
        if (preg_match(self::COLOR_LABEL_BEFORE, $before, $label, PREG_OFFSET_CAPTURE) === 1) {
            $from += $label[0][1];
            $before = substr($before, $label[0][1]);
        }
        $after = (string) (preg_split('/[,|]/u', substr($clause, $end), 2)[0] ?? '');

        if (preg_match(self::NEGATION_BEFORE, $before, $m, PREG_OFFSET_CAPTURE) === 1) {
            return [self::OUT_NEGATED, $from + $m[0][1], $end];
        }
        if (preg_match('/^\s*(?:\p{L}{4,}\s+)?'.self::PARTS.'/iu', $after, $m) === 1) {
            return [self::OUT_PART, $offset, $end + strlen($m[0])];
        }
        if (preg_match_all('/'.self::B.self::PARTS.'/iu', $before, $m, PREG_OFFSET_CAPTURE) > 0) {
            [$part, $at] = $m[0][array_key_last($m[0])];
            if (mb_strlen(substr($before, $at + strlen($part))) <= 30) {
                return [self::OUT_PART, $from + $at, $end];
            }
        }
        $colorWordAfter = preg_match('/^\s*(?:\p{L}+\s+)?(?:kolor|barw|odcie|colou?r)/iu', $after) === 1;
        if (! $colorWordAfter && preg_match(self::PREPOSITION_BEFORE, $before, $m, PREG_OFFSET_CAPTURE) === 1
            && preg_match(self::COLOR_PHRASE, $m[0][0]) !== 1) {
            return [self::OUT_PART, $from + $m[0][1], $end];
        }

        $english = array_merge(...array_column(self::COLORS, 'en'));
        if (in_array(mb_strtolower($match), $english, true)) {
            $labelled = '/'.self::B.'(?:kolor\p{L}*|barw\p{L}*|colou?rs?)'.self::E.'/iu';
            $nextWord = preg_match('/^\s+\p{Lu}[\p{L}\p{N}-]*/u', $after, $m) === 1 ? strlen($m[0]) : 0;
            if ($source->source === CardSource::NAME && preg_match($labelled, $source->text) !== 1) {
                return [self::OUT_NAME, $offset, $end + $nextWord];
            }
            if ($nextWord > 0 && preg_match('/^\p{Lu}/u', $match) === 1 && preg_match($labelled, $before) !== 1) {
                return [self::OUT_NAME, $offset, $end + $nextWord];
            }
        }

        return null;
    }

    /** Początek członu zdania z barwą w oknie [$from, $offset): bajt po ostatnim przecinku albo „|”, inaczej $from. */
    private static function segmentStart(string $clause, int $from, int $offset): int
    {
        $head = substr($clause, $from, $offset - $from);
        $cut = -1;
        foreach ([',', '|'] as $separator) {
            $at = strrpos($head, $separator);
            $cut = $at === false ? $cut : max($cut, $at);
        }

        return $from + $cut + 1;
    }

    /**
     * @return list<array{0: string, 1: int, 2: null}>
     */
    private static function hiVisMatches(string $text): array
    {
        $pattern = '/'.self::B.'(?:'.implode('|', self::HI_VIS).')/iu';
        if (preg_match_all($pattern, $text, $m, PREG_OFFSET_CAPTURE) < 1) {
            return [];
        }

        return array_map(static fn (array $match): array => [$match[0], $match[1], null], $m[0]);
    }

    /**
     * Ta sama barwa: te same kolory; przy alternatywach („żółty lub pomarańczowy”) wystarczy podzbiór.
     * „Żółto-czarny” na karcie przy wymaganym żółtym to już inna barwa.
     *
     * @param  list<string>  $cardColors
     * @param  array{colors: list<string>, alternatives: bool}  $required
     */
    private static function sameColor(array $cardColors, array $required): bool
    {
        $extra = array_diff($cardColors, $required['colors']);
        if ($extra !== []) {
            return false;
        }

        return $required['alternatives'] || array_diff($required['colors'], $cardColors) === [];
    }

    /**
     * @param  array{colors: list<string>, hi_vis: bool, alternatives: bool}  $required
     */
    private static function describe(array $required): string
    {
        $colors = implode($required['alternatives'] ? ' lub ' : ', ', $required['colors']);
        if (! $required['hi_vis']) {
            return $colors;
        }

        return $colors === '' ? 'wysoka widoczność' : $colors.' (wysoka widoczność)';
    }

    /**
     * @param  list<array<string, mixed>>  $findings
     * @param  array<string, mixed>  $finding
     */
    private static function push(array &$findings, array $finding): void
    {
        foreach ($findings as $existing) {
            if ($existing['source'] === $finding['source'] && mb_strtolower($existing['text']) === mb_strtolower($finding['text'])) {
                return;
            }
        }
        $findings[] = $finding;
    }
}
