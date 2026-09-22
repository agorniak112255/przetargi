<?php

declare(strict_types=1);

namespace App\Services\Enrichment;

/**
 * Kontrola twierdzeń opisu napisanego wyłącznie ze źródeł B2B (karta katalogowa PDF, tabelka i tekst sklepu —
 * ProductEnrichmentService::describeFromB2bSources). PDF-y bywają ubogie (ARTRA: materiały, podnosek, podeszwa,
 * norma), a model dopisuje ogólną wiedzę: 22.09.2026 karta 9495 „ARCASIO 732 616560 S1 P ESD” dostała „wodoodporną
 * cholewkę” (S1 P nie ma WRU), a 9524 „ARMEN 9003 2360 S1” — „pracę w wysokich temperaturach”, objaśnienie klasy
 * („200 J”, „15 kN”) i opis badania SR („laurylosiarczan sodu”, „gliceryna”).
 *
 * Twierdzenie o właściwości (rodzina z FAMILIES) jest pokryte, gdy źródła podają słowo tej rodziny (nie w zdaniu
 * przeczącym: „nie jest wodoodporne” nie pokrywa „wodoodporne”) ALBO oznaczenie, które ją zawiera — klasa obuwia
 * (S3 = wodoodporność, antyprzebicie, antystatyczność), oznaczenie dodatkowe (WRU, FO, CI, HI, SRC…) albo norma
 * wyrobu (EN 511 = zimno, EN 407 = ciepło). Wniosek z zapisu klasy jest dopuszczalny: to deklaracja producenta.
 * Szczegóły badań i liczby z objaśnień normy (J, kN, N, laurylosiarczan, gliceryna, płytka ceramiczna) muszą stać
 * w źródłach dosłownie.
 *
 * Przeczenie w opisie („bez wkładki antyprzebiciowej”, „nie jest wodoodporny”, „Wkładka antyprzebiciowa: brak”) nie
 * jest twierdzeniem o właściwości. Zdanie z niepokrytym twierdzeniem wypada z opisu, pozycja listy — z listy; zdania
 * i pozycje o braku danych („brak danych w źródle”) też — brak informacji to pominięcie, nie treść karty.
 */
final class SourceClaimGuard
{
    /** Oznaczenia dodatkowe obuwia, rozpoznawane w źródłach także poza zapisem klasy (wielkie litery). */
    private const STANDALONE_MARKINGS = ['WRU', 'WR', 'ESD', 'FO', 'CI', 'HI', 'HRO', 'SRA', 'SRB', 'SRC', 'SR'];

    /** Oznaczenia, które stoją za klasą obuwia w zapisie normy („S1 P SRC”, „O1 FO A”). */
    private const CLASS_MARKINGS = 'PL|PS|P|A|E|WRU|WR|CI|HI|HRO|FO|SRA|SRB|SRC|SR|ESD|M|AN|CR|LG|SC';

    private const ANTISTATIC_CLASSES = ['S1', 'S2', 'S3', 'S4', 'S5', 'S6', 'S7', 'O1', 'O2', 'O3', 'O4', 'O5', 'O6', 'O7'];

    /**
     * claim — twierdzenie w opisie (tekst po normalize: małe litery, bez polskich znaków);
     * claim_markings — oznaczenia jako twierdzenie (tekst oryginalny, wielkie litery — „ci”, „hi” to też słowa);
     * words — słowa rodziny w źródłach; markings/classes/norms — oznaczenia w źródłach, które ją zawierają;
     * negatable — przeczenie przed/za trafieniem znosi twierdzenie („bez metalu” jest twierdzeniem metal free);
     * literal — pokrycie wyłącznie dosłowną obecnością trafienia w źródłach.
     *
     * @var array<string, array{claim: string, claim_markings?: list<string>, words?: string, markings?: list<string>, classes?: list<string>, norms?: list<string>, negatable?: bool, literal?: bool}>
     */
    private const FAMILIES = [
        'wodoodporność' => [
            // „nie przemaka”, „bez przemakania”, „nie przepuszcza wody” to twierdzenie o wodoodporności — przeczenie przed
            // słowem-zagrożeniem go nie znosi (NEGATION_BEFORE)
            'claim' => '/wodoodporn\w*|wodoszczeln\w*|nieprzemakaln\w*|przemak\w*|hydrofobow\w*|wodoodpych\w*|przepuszcz\w*\s+(?:wody|wilgoci|plynow)|odporn\w*\s+na\s+(?:wod[aey]\b|wilgoc\w*|przemak\w*)|(?:przenikani|przesiakani|wnikani|absorpcj)\w*\s+(?:wody|plynow|cieczy)/u',
            'claim_markings' => ['WRU', 'WR'],
            'words' => '/wodoodp\w*|wodoszcz\w*|nieprzemak\w*|przemakan\w*|hydrofob\w*|(?:przenikani|przesiakani|wnikani|absorpcj)\w*\s+(?:wody|plynow|cieczy)/u',
            'markings' => ['WRU', 'WR'],
            'classes' => ['S2', 'S3', 'S6', 'S7', 'O2', 'O3', 'O6', 'O7'],
            'norms' => ['343'],
        ],
        'antyprzebicie' => [
            'claim' => '/antyprzebic\w*|przebici\w*|przekluci\w*/u',
            'words' => '/antyprzebic\w*|przebic\w*|przeklu\w*/u',
            'markings' => ['P', 'PL', 'PS'],
            'classes' => ['S3', 'S5', 'S7'],
            'norms' => ['388', '12568', '22568'],
        ],
        'antystatyczność' => [
            'claim' => '/antystatyczn\w*|antyelektrostatyczn\w*|elektrostatyczn\w*|(?:rozpraszani|odprowadz)\w*\s+(?:\w+\s+)?ladunk\w*/u',
            'words' => '/antystat\w*|antyelektrostat\w*|elektrostat\w*|\besd\b/u',
            'markings' => ['A', 'ESD'],
            'classes' => self::ANTISTATIC_CLASSES,
            'norms' => ['1149', '16350', '61340'],
        ],
        'ESD' => [
            'claim' => '/\besd\b/u',
            'words' => '/\besd\b/u',
            'markings' => ['ESD'],
            'norms' => ['61340'],
        ],
        'odporność na oleje' => [
            // początek słowa: „kolejny” to nie olej, a „zaolejone” (chwyt Tegro) tak
            'claim' => '/(?<!\pL)(?:za)?olej\w*|(?<!\pL)paliw\w*|weglowodor\w*/u',
            'claim_markings' => ['FO'],
            'words' => '/(?<!\pL)(?:za)?olej\w*|(?<!\pL)paliw\w*|weglowodor\w*/u',
            'markings' => ['FO'],
        ],
        'izolacja od zimna' => [
            'claim' => '/zimn\w*|mroz\w*|ocieplan\w*|termoizol\w*|(?:nisk|ujemn)\w*\s+temperatur\w*/u',
            'claim_markings' => ['CI'],
            'words' => '/zimn\w*|mroz\w*|ocieplan\w*|ocieplen\w*|termoizol\w*|(?:nisk|ujemn)\w*\s+temperatur\w*/u',
            'markings' => ['CI'],
            'norms' => ['511', '342', '14058'],
        ],
        'ochrona przed ciepłem' => [
            'claim' => '/(?:wysok|podwyzszon)\w*\s+temperatur\w*|temperatur\w*\s+(?:powyzej|ponad)\b|gorac\w*|ciepln\w*|(?:od|przed|na)\s+ciepl\w*|zaroodporn\w*|termoodporn\w*/u',
            'claim_markings' => ['HI', 'HRO'],
            // sama „temperatura” w źródle bywa warunkiem przechowywania (instrukcje) — nie pokrywa ochrony przed ciepłem
            'words' => '/(?:wysok|podwyzszon)\w*\s+temperatur\w*|temperatur\w*\s+(?:powyzej|ponad|do|przekraczajac\w*)\s+\d|gorac\w*|ciepln\w*|(?:od|przed|na)\s+ciepl\w*|zaroodp\w*|termoodp\w*/u',
            'markings' => ['HI', 'HRO'],
            'norms' => ['407', '11612'],
        ],
        'metal free' => [
            'claim' => '/metal\W?free|bezmetal\w*|niemetal\w*|bez\s+(?:\w+\s+){0,2}metal\w*|nie\s+zawiera\w*\s+(?:\w+\s+){0,2}metal\w*|woln\w*\s+od\s+(?:\w+\s+){0,1}metal\w*/u',
            'words' => '/metal\W?free|bezmetal\w*|niemetal\w*|bez\s+(?:\w+\s+){0,2}metal\w*|nie\s+zawiera\w*\s+(?:\w+\s+){0,2}metal\w*|woln\w*\s+od\s+(?:\w+\s+){0,1}metal\w*/u',
            'negatable' => false,
        ],
        'antypoślizg' => [
            'claim' => '/antyposlizg\w*|poslizg\w*/u',
            'claim_markings' => ['SRA', 'SRB', 'SRC', 'SR'],
            'words' => '/poslizg\w*/u',
            'markings' => ['SRA', 'SRB', 'SRC', 'SR'],
        ],
        'odporność chemiczna' => [
            'claim' => '/chemi\w*|kwas\w*|(?<!\w)lug(?:i|ow|ami)\b|rozpuszczalnik\w*|\bzrac\w*/u',
            'words' => '/chemi\w*|kwas\w*|(?<!\w)lug(?:i|ow|ami)\b|rozpuszczalnik\w*|\bzrac\w*/u',
            // EN ISO 374-5 to mikroorganizmy (VIRUS), nie chemia
            'norms' => ['374(?!\s*-\s*5)', '13832', '943', '14605', '13034', '16523'],
        ],
        'spawanie' => [
            // początek słowa: „mankiet wyspawany” to nie spawanie
            'claim' => '/(?<!\pL)spaw\w*|odprysk\w*|stopion\w*\s+metal\w*/u',
            'words' => '/(?<!\pL)spaw\w*|odprysk\w*|stopion\w*\s+metal\w*/u',
            'norms' => ['11611', '12477'],
        ],
        'elektroizolacja' => [
            'claim' => '/elektroizol\w*|dielektr\w*|izol\w*\s+elektr\w*|elektr\w*\s+izol\w*|pod\s+napieci\w*|napieci\w*\s+(?:do|robocz)\w*|porazeni\w*/u',
            'words' => '/elektroizol\w*|dielektr\w*|izol\w*\s+elektr\w*|elektr\w*\s+izol\w*|napieci\w*|porazeni\w*/u',
            'norms' => ['50321', '60903'],
        ],
        'szczegóły badań' => [
            'claim' => '/\d+(?:[.,]\d+)?\s*(?:kn|j|n)\b|dzul\w*|laurylosiarczan\w*|gliceryn\w*|ceramiczn\w*/u',
            'negatable' => false,
            'literal' => true,
        ],
    ];

    /**
     * Przeczenie tuż przed trafieniem: do trzech słów pomiędzy („nie jest odporne na przebicie”), bez przecinka i bez spójnika czy „z” — „bez podnoska,
     * z wodoodporną cholewką” i „bez podnoska i z wodoodporną” to nadal twierdzenie; wyjątek „nie tylko”. Przed
     * słowem-zagrożeniem przeczenie odwraca sens w twierdzenie: „bez przemakania”, „nie przemaka”, „nie przepuszcza
     * wody”, „bez ryzyka porażenia / poślizgu”.
     */
    private const NEGATION_BEFORE = '/\b(?:nie|bez|brak\w*)\b(?!\s+tylko)(?!\s+(?:ryzyk|obaw|zagroze|niebezpiecz|przemak|przepuszcz|przebic|przeklu|porazen|poslizg)\w*)(?:\s+(?!(?:z|ze|i|oraz|a|ale|lecz|lub|dzieki)\b)\w+){0,3}\s*$/u';

    /** Przeczenie jako wartość za trafieniem: „Wkładka antyprzebiciowa: brak”, „Wodoodporność – nie”. */
    private const NEGATION_AFTER = '/^\w*\s*[:\-–—]\s*(?:brak|nie|bez)\b/u';

    /** Zdanie albo pozycja listy o braku danych. */
    private const MISSING_DATA = '/brak\s+(?:danych|informacji)|(?:zrodl\w*|kart\w*|producent\w*|dostawc\w*)\s+nie\s+(?:podaj|okresl|zawier|wskazuj|precyzuj)\w*|nie\s+(?:podano|okreslono|wskazano|sprecyzowano)|\bnie\s+podan[aeoy]\b|\bb\/d\b|\bn\/a\b/u';

    private readonly string $sources;

    private readonly string $compactSources;

    /** @var array<string, bool> rodzina => pokryta przez źródła */
    private array $covered = [];

    public function __construct(string $sources)
    {
        $this->sources = self::normalize($sources);
        $this->compactSources = (string) preg_replace('/\s+/u', '', $this->sources);
        [$markings, $classes] = self::sourceMarkings($sources);
        $edition2011 = preg_match('/20345\s*:\s*(?:2004|2011)/u', $sources) === 1;

        foreach (self::FAMILIES as $name => $family) {
            if (($family['literal'] ?? false) === true) {
                continue;
            }
            $covered = array_intersect($family['markings'] ?? [], $markings) !== []
                || array_intersect($family['classes'] ?? [], $classes) !== []
                || $this->hasNorm($family['norms'] ?? [])
                || (isset($family['words']) && $this->hasWord($family['words'], ($family['negatable'] ?? true) === true));
            // EN ISO 20345:2004/2011 — odporność podeszwy na olej napędowy należała do wymagań klas S1–S3
            if ($name === 'odporność na oleje' && $edition2011 && array_intersect(['S1', 'S2', 'S3'], $classes) !== []) {
                $covered = true;
            }
            $this->covered[$name] = $covered;
        }
    }

    /**
     * Rodziny twierdzeń z tekstu, których źródła nie pokrywają (dla szczegółów badań — z trafieniem).
     *
     * @return list<string>
     */
    public function uncoveredClaims(string $text): array
    {
        $normalized = self::normalize($text);
        $uncovered = [];
        foreach (self::FAMILIES as $name => $family) {
            if (($family['literal'] ?? false) === true) {
                if (preg_match_all($family['claim'], $normalized, $m) > 0) {
                    foreach ($m[0] as $hit) {
                        if (! str_contains($this->compactSources, self::literalKey($hit))) {
                            $uncovered[] = $name.' ('.trim($hit).')';
                        }
                    }
                }

                continue;
            }
            if ($this->covered[$name] ?? false) {
                continue;
            }
            if ($this->claims($normalized, $family['claim'], ($family['negatable'] ?? true) === true)
                || self::claimsMarking($text, $family['claim_markings'] ?? [])) {
                $uncovered[] = $name;
            }
        }

        return array_values(array_unique($uncovered));
    }

    /**
     * Zdanie albo pozycja listy mówi o braku danych, a nie o wyrobie. „Parametr bez wartości” („Typ zapięcia:”) tylko
     * w pozycji listy — w opisie taka linia to nagłówek („Najważniejsze cechy:”).
     */
    public static function statesMissingData(string $text, bool $listItem = true): bool
    {
        $normalized = self::normalize($text);

        return preg_match(self::MISSING_DATA, $normalized) === 1
            // „Typ zapięcia:” / „Typ zapięcia: -” — parametr bez wartości
            || ($listItem && preg_match('/^[^:]{1,80}:\s*(?:[-–—?]\s*)?$/u', trim($normalized)) === 1);
    }

    /** Pozycja listy (specs, features, use_cases…) zostaje: mówi coś o wyrobie i każde jej twierdzenie ma pokrycie. */
    public function keeps(string $item): bool
    {
        return ! self::statesMissingData($item) && $this->uncoveredClaims($item) === [];
    }

    /**
     * Opis bez zdań z niepokrytym twierdzeniem i bez zdań o braku danych. Akapit bez usuniętych zdań zostaje co do
     * znaku; w akapicie z usuniętym zdaniem reszta zdań zostaje z ich separatorami.
     *
     * @return array{text: string, dropped: list<string>}
     */
    public function filterDescription(string $description): array
    {
        $dropped = [];
        $paragraphs = [];
        foreach (preg_split('/\n[ \t]*\n\s*/u', trim($description)) ?: [] as $paragraph) {
            $kept = [];
            $changed = false;
            foreach (self::sentences($paragraph) as [$separator, $sentence]) {
                $reasons = self::statesMissingData($sentence, false) ? ['brak danych'] : $this->uncoveredClaims($sentence);
                if ($reasons !== []) {
                    $dropped[] = implode(', ', $reasons).': '.trim($sentence);
                    $changed = true;

                    continue;
                }
                $kept[] = [$separator, $sentence];
            }
            if (! $changed) {
                $paragraphs[] = $paragraph;

                continue;
            }
            $text = '';
            foreach ($kept as $i => [$separator, $sentence]) {
                $text .= ($i === 0 ? '' : (str_contains($separator, "\n") ? "\n" : ' ')).trim($sentence);
            }
            if (trim($text) !== '') {
                $paragraphs[] = trim($text);
            }
        }

        return ['text' => implode("\n\n", $paragraphs), 'dropped' => $dropped];
    }

    /** Małe litery bez polskich znaków i z pojedynczymi spacjami — wzorce rodzin są pisane pod tę postać. */
    public static function normalize(string $text): string
    {
        $text = strtr(mb_strtolower($text), [
            'ą' => 'a', 'ć' => 'c', 'ę' => 'e', 'ł' => 'l', 'ń' => 'n', 'ó' => 'o', 'ś' => 's', 'ź' => 'z', 'ż' => 'z',
        ]);

        return (string) preg_replace('/[ \t\x{00A0}]+/u', ' ', $text);
    }

    /**
     * Zdania akapitu z separatorem przed każdym (pierwsze — pusty). Kropka po skrócie („kat. II”, „np. S3”) nie
     * kończy zdania — inaczej z opisu wypadłaby połowa zdania.
     *
     * @return list<array{0: string, 1: string}>
     */
    private static function sentences(string $paragraph): array
    {
        $parts = preg_split('/((?<=[.!?…])\s+(?=[\p{Lu}\d„"(])|\s*\n\s*)/u', $paragraph, -1, PREG_SPLIT_DELIM_CAPTURE) ?: [$paragraph];
        $units = [];
        $separator = '';
        foreach ($parts as $i => $part) {
            if ($i % 2 === 1) {
                $separator = $part;

                continue;
            }
            $last = count($units) - 1;
            if ($last >= 0 && ! str_contains($separator, "\n")
                && preg_match('/(?:^|[\s(])(?:kat|np|tzw|wg|nr|ok|tj|ang|min|max|m\.in|poz|art|rozm|dot|zob|ew|pkt|ds|ul)\.$/iu', $units[$last][1]) === 1) {
                $units[$last][1] .= $separator.$part;

                continue;
            }
            if (trim($part) !== '') {
                $units[] = [$separator, $part];
            }
        }

        return $units;
    }

    /**
     * Postać trafienia do dosłownego porównania ze źródłami: liczba z jednostką bez spacji („200 J” = „200J”), słowo
     * bez końcówki fleksyjnej („ceramicznej” pokrywa „ceramiczna”, „gliceryną” — „gliceryna”).
     */
    private static function literalKey(string $hit): string
    {
        $hit = (string) preg_replace('/\s+/u', '', $hit);
        if (preg_match('/^\p{L}+$/u', $hit) !== 1) {
            return $hit;
        }

        return mb_substr($hit, 0, max(4, mb_strlen($hit) - 3));
    }

    /** Twierdzenie rodziny w tekście: trafienie wzorca nie w przeczeniu (gdy rodzina zna przeczenie). */
    private function claims(string $normalized, string $pattern, bool $negatable): bool
    {
        if (preg_match_all($pattern, $normalized, $m, PREG_OFFSET_CAPTURE) < 1) {
            return false;
        }
        foreach ($m[0] as [$hit, $offset]) {
            if (! $negatable || ! self::negated($normalized, (string) $hit, (int) $offset)) {
                return true;
            }
        }

        return false;
    }

    private function hasWord(string $pattern, bool $negatable): bool
    {
        return $this->claims($this->sources, $pattern, $negatable);
    }

    /**
     * @param  list<string>  $norms  numery norm („511”, „61340”)
     */
    private function hasNorm(array $norms): bool
    {
        foreach ($norms as $number) {
            if (preg_match('/\b(?:en|iso|iec)[\s-]*(?:(?:iso|iec)[\s-]*)*'.$number.'(?!\d)/u', $this->sources) === 1) {
                return true;
            }
        }

        return false;
    }

    private static function negated(string $normalized, string $hit, int $offset): bool
    {
        // offset z preg_match_all to bajty — przedrostek i reszta liczone w bajtach, dopiero potem porównanie
        $before = substr($normalized, 0, $offset);
        $after = substr($normalized, $offset + strlen($hit));
        // przeczenie w obrębie zdania, nie z poprzedniego
        $before = (string) preg_replace('/^.*[.;!?\n]\s*/su', '', $before);
        // przeczenie tuż przed słowem-zagrożeniem to twierdzenie („nie przemaka”, „bez przemakania”); z czymś pomiędzy
        // („nie jest odporna na przebicie”) zostaje przeczeniem
        if (preg_match('/^(?:przemak|przepuszcz|przebic|przeklu|porazen|poslizg)/u', $hit) === 1
            && preg_match('/\b(?:nie|bez|brak\w*)\s*$/u', $before) === 1) {
            return preg_match(self::NEGATION_AFTER, $after) === 1;
        }

        return preg_match(self::NEGATION_BEFORE, $before) === 1 || preg_match(self::NEGATION_AFTER, $after) === 1;
    }

    /**
     * @param  list<string>  $markings
     */
    private static function claimsMarking(string $text, array $markings): bool
    {
        if ($markings === []) {
            return false;
        }

        return preg_match('/(?<![\p{L}\d])(?:'.implode('|', $markings).')(?![\p{L}\d])/u', $text) === 1;
    }

    /**
     * Oznaczenia i klasy obuwia ze źródeł (wielkie litery): klasy z zapisów („S1 P SRC” → klasa S1, oznaczenia P
     * i SRC; „S3L” → S3, L) i oznaczenia dodatkowe stojące osobno („ESD według EN IEC 61340”).
     *
     * @return array{0: list<string>, 1: list<string>}
     */
    private static function sourceMarkings(string $sources): array
    {
        $markings = [];
        $classes = [];
        $text = str_replace(['-', '_'], ' ', $sources);
        if (preg_match_all(
            '/(?<![\p{L}\d])(S[1-7]|SB|O[1-7]|OB)(?:\h?(PL|PS|P|L|S))?(?![\p{L}\d])((?:[\h,+\/]+(?:'.self::CLASS_MARKINGS.')(?![\p{L}\d]))*)/u',
            $text,
            $m,
            PREG_SET_ORDER,
        ) > 0) {
            foreach ($m as $hit) {
                $classes[] = $hit[1];
                if (($hit[2] ?? '') !== '') {
                    $markings[] = $hit[2];
                }
                foreach (preg_split('/[\h,+\/]+/u', trim($hit[3] ?? '')) ?: [] as $marking) {
                    if ($marking !== '') {
                        $markings[] = $marking;
                    }
                }
            }
        }
        if (preg_match_all('/(?<![\p{L}\d])('.implode('|', self::STANDALONE_MARKINGS).')(?![\p{L}\d])/u', $sources, $s) > 0) {
            array_push($markings, ...$s[1]);
        }

        return [array_values(array_unique($markings)), array_values(array_unique($classes))];
    }
}
