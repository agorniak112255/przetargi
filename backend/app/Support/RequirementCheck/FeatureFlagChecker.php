<?php

declare(strict_types=1);

namespace App\Support\RequirementCheck;

/**
 * Cechy tak/nie z wymagania (antystatyczny, bez lateksu, bez silikonu, kontakt z żywnością…) porównane z kartą.
 *
 * Zamknięty słownik: wiersz powstaje tylko, gdy wymaganie wprost wymaga cechy. Na karcie kolejno:
 * zaprzeczenie wprost („BEZ SILIKONU Nie”, „nie jest wolny od lateksu”) → fail, zaprzeczenie w wątpliwym
 * szyku („nie przemakające antystatyczne”) → unclear, wzorzec wprost → ok, sprzeczność bez poprzedzającego
 * zaprzeczenia („lateks naturalny”) → fail. Pokrewne sformułowania („bezpieczny dla żywności”, ESD przy
 * antystatycznym, uczulenie na lateks) to tylko `unclear` i pokazujemy je wyłącznie, gdy karta nie mówi
 * o cesze wprost — wniosek nigdy nie daje ok. Celowo nie używamy `PpeAssortment::requiresAntistatic`:
 * łączy ESD z antystatycznością, a tu mają być rozróżnione.
 */
final class FeatureFlagChecker implements ParameterChecker
{
    /** Początek słowa — `\b` z /u nie widzi polskich liter („żywnością”). */
    private const B = '(?<![\p{L}\p{N}])';

    private const E = '(?![\p{L}\p{N}])';

    /**
     * Wartość komórki tabeli „ETYKIETA Nie” na końcu wiersza albo przed znakiem („Bez lateksu: nie.”,
     * „…: nie, bez silikonu: tak”, „| Nie |”) — nie „bez lateksu nie powodują uczuleń”.
     */
    private const CELL_END = '(?=[ \t*]*(?:$|[.,;|]))';

    private const CELL_NO = '[ \t]*[:\-–|]?[ \t]*(?:nie|no|brak)';

    private const CELL_UNKNOWN = '[ \t]*[:\-–|]?[ \t]*(?:nie\s+dotyczy|n\/d|brak\s+danych|[-–—])';

    /** Zaprzeczenie tuż przed sprzecznością: „bez powłoki lateksowej”, „wolne od składników silikonowych”. */
    private const GUARD = '/(?<![\p{L}])(?:bez|nie|woln\p{L}*|free|no|non|without|brak)(?![\p{L}])[^.;,!?\n]{0,25}$/iu';

    /** „uczulonych na lateks naturalny” mówi o alergii użytkownika, nie o składzie wyrobu. */
    private const ALLERGY = '/(?<![\p{L}])(?:uczul|alergi|allerg|nadwrażliw)\p{L}*[^.;,!?\n]{0,25}$/iu';

    /** Słowo między zaprzeczeniem a cechą; spójnik albo nowe orzeczenie przerywa związek („nie jest ciężkie i jest antystatyczne”). */
    private const FILLER = '\s+(?!(?:i|oraz|a|ale|lecz|lub|albo|czy|jest|są|and|or|but|is|are|tylko|only)'.self::E.')[\p{L}\p{N}][\p{L}\p{N}\/-]*';

    /** Koniec frazy przed trafieniem (dopuszcza cudzysłów otwierający). */
    private const BEFORE_HIT = '[\s„"“(]+$';

    /**
     * Zaprzeczenie, które jednoznacznie dotyczy cechy tuż po nim: „nie jest wolny od”, „nie są to buty”,
     * „bez właściwości”, „bez rzepów”, „nieprzeznaczone do”, „nie należy stosować do”, „unsuitable for”.
     */
    private const NEGATION_STRICT = '/'.self::B.'(?:'
        .'(?:nie\s+(?:jest|są|było|będzie|ma|mają|posiada\p{L}*|należy|wolno|można|nadaj\p{L}*\s+się|spełnia\p{L}*'
        .'|przeznaczon\p{L}*|odpowiedni\p{L}*|dopuszczon\p{L}*|zalecan\p{L}*|do|dla)(?:\s+to)?'
        .'|nie(?:przeznaczon|odpowiedni|dopuszczon|zalecan|nadając)\p{L}*'
        .'|unsuitable|not(?!\s+only'.self::E.')|never|cannot)(?:'.self::FILLER.'){0,2}'
        .'|(?:bez|brak\p{L}*|without|lack\s+of|no)\s+(?:właściwości|cech\p{L}*|funkcji|ochrony|properties)(?:'.self::FILLER.')?'
        .'|bez|brak|nie|non|no|without'
        .')'.self::BEFORE_HIT.'/iu';

    /** Zaprzeczenie w tej samej frazie, ale nie wprost przy cesze — „nie przemakające antystatyczne”. */
    private const NEGATION_WEAK = '/'.self::B.'(?:nie(?!\s+tylko'.self::E.')|bez|brak\p{L}*|no|not|non|without|nigdy|never)(?:'.self::FILLER.'){1,3}'.self::BEFORE_HIT.'/iu';

    /** W wymaganiu cecha po zaprzeczeniu nie jest żądana: „nie musi być antystatyczne”, „nie wymaga się…”. */
    private const REQUIREMENT_NEGATION_BEFORE = '/'.self::B.'(?:nie\s+(?:musi|muszą|wymaga\p{L}*|trzeba|potrzeb\p{L}*|może|mogą|dopuszcza\p{L}*)'
        .'|not\s+(?:required|necessary|needed)|no\s+need)(?:'.self::FILLER.'){0,3}'.self::BEFORE_HIT.'/iu';

    /** …i po cesze: „właściwości antystatyczne nie są wymagane”, „antystatyczne — opcjonalnie”. */
    private const REQUIREMENT_NEGATION_AFTER = '/^[^.;,!?\n]{0,30}?'.self::B.'(?:nie\s+(?:jest|są)\s+(?:wymagan|konieczn|potrzebn|obowiązkow)\p{L}*'
        .'|niewymagan\p{L}*|niekonieczn\p{L}*|nieobowiązkow\p{L}*|opcjonaln\p{L}*|zbędn\p{L}*|not\s+(?:required|necessary)|optional)/iu';

    /** Fraza przed trafieniem nie sięga przez kropkę, średnik ani nowy wiersz. */
    private const CLAUSE_BYTES = 160;

    private const ESD_NOTE = 'ESD to nie to samo co antystatyczny — sprawdź';

    private const FOOD_NOTE = 'Karta mówi o żywności innymi słowami, bez deklaracji kontaktu z żywnością — sprawdź';

    private const ALLERGY_NOTE = 'Karta wspomina uczulenie na lateks, nie deklaruje składu bez lateksu — sprawdź';

    /** @var array<string, array<string, mixed>>|null */
    private static ?array $features = null;

    public function group(): string
    {
        return 'flags';
    }

    public function check(string $requirement, array $cardSources): array
    {
        $rows = [];
        foreach (self::features() as $key => $feature) {
            $required = self::requiredFragment($requirement, $feature);
            if ($required === null) {
                continue;
            }

            // Dedup przed finding(): przy powtórzonej frazie cytat liczyłby się tysiące razy (recenzja: 6 s).
            $findings = [];
            $seen = [];
            foreach ($cardSources as $source) {
                foreach (self::scan($source, $feature) as [$text, $verdict, $display]) {
                    if (self::firstSeen($seen, $source, $display, $verdict)) {
                        $finding = CheckRow::finding($source, $text, $verdict);
                        $findings[] = $display === $text ? $finding : ['text' => $display] + $finding;
                    }
                }
            }

            $note = null;
            if ($findings === []) {
                foreach ($cardSources as $source) {
                    foreach (self::matches($feature['related'], $source->text) as $text) {
                        if (self::firstSeen($seen, $source, $text, Status::Unclear)) {
                            $findings[] = CheckRow::finding($source, $text, Status::Unclear);
                            $note = $feature['related_note'];
                        }
                    }
                }
            }

            $rows[] = new CheckRow(
                key: $key,
                label: $feature['label'],
                required: ['text' => 'tak', 'quote' => CheckRow::quote($requirement, $required)],
                card: $findings,
                status: Status::fromCardVerdicts(array_map(
                    static fn (array $finding): Status => Status::from($finding['verdict']),
                    $findings,
                )),
                note: $note,
                gate: $feature['gate'],
            );
        }

        return $rows;
    }

    /**
     * Fragment wymagania, który wprost wymaga cechy. Zaprzeczenia maskujemy najpierw: „nie jest
     * antystatyczny” nie tworzy wiersza, a „bez lateksu” tworzy wiersz `latex_free`, nigdy „lateks”.
     * Trafienie po zaprzeczeniu („nie musi być antystatyczne”) albo przed „nie jest wymagane” pomijamy.
     *
     * @param  array<string, mixed>  $feature
     */
    private static function requiredFragment(string $requirement, array $feature): ?string
    {
        $masked = $requirement;
        self::take($feature['unknown'], $masked, $requirement);
        self::take($feature['negated'], $masked, $requirement);
        foreach (self::take($feature['positive'], $masked, $requirement) as [$fragment, $offset, $length]) {
            $before = self::clauseBefore($requirement, $offset);
            if (preg_match(self::NEGATION_STRICT, $before) === 1
                || preg_match(self::NEGATION_WEAK, $before) === 1
                || preg_match(self::REQUIREMENT_NEGATION_BEFORE, $before) === 1
                || preg_match(self::REQUIREMENT_NEGATION_AFTER, substr($requirement, $offset + $length, self::CLAUSE_BYTES)) === 1) {
                continue;
            }

            return $fragment;
        }

        return null;
    }

    /**
     * Znaleziska wprost w jednym polu karty w kolejności: nieznana wartość komórki, zaprzeczenie,
     * wzorzec (z zaprzeczeniem przed nim w tej samej frazie), sprzeczność. Każde dopasowanie
     * maskujemy, żeby „BEZ SILIKONU Nie” nie dało też ok.
     *
     * @param  array<string, mixed>  $feature
     * @return list<array{0: string, 1: Status, 2: string}> dosłowny fragment, werdykt, tekst do pokazania
     */
    private static function scan(CardSource $source, array $feature): array
    {
        $text = $source->text;
        $masked = $text;
        $out = [];
        foreach (self::take($feature['unknown'], $masked, $text) as [$fragment]) {
            $out[] = [$fragment, Status::Unclear, self::unglue($fragment)];
        }
        foreach (self::take($feature['negated'], $masked, $text) as [$fragment]) {
            $out[] = [$fragment, Status::Fail, self::unglue($fragment)];
        }
        foreach (self::take($feature['positive'], $masked, $text) as [$fragment, $offset, $length]) {
            $before = self::clauseBefore($text, $offset);
            $verdict = match (true) {
                preg_match(self::NEGATION_STRICT, $before, $m) === 1 => Status::Fail,
                preg_match(self::NEGATION_WEAK, $before, $m) === 1 => Status::Unclear,
                default => Status::Ok,
            };
            if ($verdict !== Status::Ok) {
                // znalezisko obejmuje zaprzeczenie — „nie są bez lateksu”, nie samo „bez lateksu”
                $fragment = trim(substr($text, $offset - strlen($m[0]), strlen($m[0]) + $length));
            }
            $out[] = [$fragment, $verdict, $fragment];
        }
        if ($feature['contradiction'] !== null
            && preg_match_all($feature['contradiction'], $masked, $m, PREG_OFFSET_CAPTURE) > 0) {
            foreach ($m[0] as [$match, $offset]) {
                $before = self::clauseBefore($masked, $offset, 80);
                if (preg_match(self::GUARD, $before) !== 1 && preg_match(self::ALLERGY, $before) !== 1) {
                    $fragment = substr($text, $offset, strlen($match));
                    $out[] = [$fragment, Status::Fail, $fragment];
                }
            }
        }
        // Lista materiałów z samym „lateks” to materiał karty, nie wzmianka w zdaniu.
        if ($source->source === CardSource::MATERIALS && $feature['material'] !== null
            && preg_match($feature['material'], $text) === 1) {
            $out[] = [$text, Status::Fail, $text];
        }

        return $out;
    }

    /**
     * Tekst przed trafieniem w tej samej frazie (bez kropki, średnika, nowego wiersza). Cięcie po
     * bajtach, nie mb_substr po całym prefiksie — to było kwadratowe przy tysiącach trafień.
     */
    private static function clauseBefore(string $text, int $offset, int $bytes = self::CLAUSE_BYTES): string
    {
        $start = max(0, $offset - $bytes);
        // początek okna w środku znaku UTF-8 — odcinamy bajty kontynuacji
        $window = (string) preg_replace('/^[\x80-\xBF]+/', '', substr($text, $start, $offset - $start));
        $clauses = preg_split('/[.;\n]/', $window);

        return (string) end($clauses);
    }

    /**
     * Komórki `<td>` sklejone przy zdejmowaniu HTML („Bez lateksuNie”) — do pokazania z odstępem.
     * Tylko mała litera etykiety przed wartością od wielkiej; „ANTYSTATYCZNENIE” zostaje dosłownie.
     */
    private static function unglue(string $fragment): string
    {
        return (string) preg_replace('/(?<=\p{Ll})(?=(?:Nie|NIE|No|NO|Brak|BRAK)$)/u', ' ', $fragment);
    }

    /**
     * @param  array<string, true>  $seen
     */
    private static function firstSeen(array &$seen, CardSource $source, string $text, Status $verdict): bool
    {
        $key = $source->source."\0".$verdict->value."\0".mb_strtolower($text);
        if (isset($seen[$key])) {
            return false;
        }
        $seen[$key] = true;

        return true;
    }

    /**
     * Dopasowania wzorca w masce; zwraca dosłowne fragmenty oryginału z przesunięciem i długością
     * (w bajtach, bez obcięcia spacji) i zamazuje je spacjami tej samej długości, żeby przesunięcia
     * zostały zgodne z oryginałem.
     *
     * @return list<array{0: string, 1: int, 2: int}>
     */
    private static function take(?string $pattern, string &$masked, string $original): array
    {
        if ($pattern === null || preg_match_all($pattern, $masked, $m, PREG_OFFSET_CAPTURE) < 1) {
            return [];
        }
        $out = [];
        foreach ($m[0] as [$match, $offset]) {
            $length = strlen($match);
            $out[] = [trim(substr($original, $offset, $length)), $offset, $length];
            $masked = substr_replace($masked, str_repeat(' ', $length), $offset, $length);
        }

        return $out;
    }

    /**
     * @return list<string>
     */
    private static function matches(?string $pattern, string $text): array
    {
        if ($pattern === null || preg_match_all($pattern, $text, $m) < 1) {
            return [];
        }

        return array_map('trim', $m[0]);
    }

    /**
     * Słownik cech. `positive` służy i w wymaganiu, i na karcie; z niego powstają też formy
     * tabelowe „ETYKIETA Nie” (fail) i „ETYKIETA nie dotyczy” (unclear).
     *
     * @return array<string, array<string, mixed>>
     */
    private static function features(): array
    {
        if (self::$features !== null) {
            return self::$features;
        }

        $antistatic = [
            'anty(?:elektro)?statyczn\p{L}*',
            'anti[\s-]?(?:electro)?static\p{L}*',
            'EN\s*(?:ISO\s*)?1149(?:-\d+)?'.self::E,
        ];
        $esd = [
            'ESD'.self::E,
            'EN\s*(?:IEC\s*)?61340(?:-\d+)*'.self::E,
        ];
        // „bez lateksu i silikonu”, „bez silikonu, lateksu”, „latex- and silicone-free” — obie cechy
        $enumeration = [
            'bez\s+(?:dodatku\s+)?lateks\p{L}*(?:\s*,\s*|\s+(?:i|oraz)\s+)(?:bez\s+)?silikon\p{L}*',
            'bez\s+(?:dodatku\s+)?silikon\p{L}*(?:\s*,\s*|\s+(?:i|oraz)\s+)(?:bez\s+)?lateks\p{L}*',
            '(?:latex|silicone)-?\s*(?:and|&|,)\s*(?:latex|silicone)[\s-]*free',
        ];

        $definitions = [
            'antistatic' => [
                'label' => 'Antystatyczny',
                'gate' => 'antistatic',
                'positive' => $antistatic,
                'negated' => [
                    'nie\s+(?:jest\s+|są\s+|posiada\p{L}*\s+(?:właściwości\s+)?|ma\s+właściwości\s+)?anty(?:elektro)?statyczn\p{L}*',
                    'nieanty(?:elektro)?statyczn\p{L}*',
                    '(?:non|not)[\s-]+anti[\s-]?static\p{L}*',
                ],
                'contradiction' => [],
                'material' => null,
                'related' => $esd,
                'related_note' => self::ESD_NOTE,
            ],
            'esd' => [
                'label' => 'ESD',
                'gate' => null,
                'positive' => $esd,
                'negated' => [],
                'contradiction' => [],
                'material' => null,
                'related' => $antistatic,
                'related_note' => self::ESD_NOTE,
            ],
            'latex_free' => [
                'label' => 'Bez lateksu',
                'gate' => null,
                'positive' => [
                    ...$enumeration,
                    'bez\s+(?:dodatku\s+|zawartości\s+)?lateks\p{L}*',
                    'nie\s+zawiera\p{L}*\s+lateks\p{L}*',
                    'woln\p{L}*\s+od\s+lateks\p{L}*',
                    'latex[\s-]*free',
                    'free\s+(?:of|from)\s+latex',
                ],
                'negated' => [],
                'contradiction' => [
                    'z\s+(?:naturalnego\s+)?lateks\p{L}*',
                    'lateks\p{L}*\s+naturaln\p{L}*',
                    'naturaln\p{L}*\s+lateks\p{L}*',
                    'lateksow\p{L}*',
                    'zawiera\p{L}*\s+lateks\p{L}*',
                    'natural\s+rubber\s+latex',
                    'latex[\s-]+coat\p{L}*',
                ],
                'material' => '/^\s*(?:lateks\p{L}*(?:\s+naturaln\p{L}*)?|(?:natural\s+rubber\s+)?latex)\s*$/iu',
                // alergia użytkownika sugeruje brak lateksu, ale nie jest deklaracją składu
                'related' => [
                    '(?:uczul|alergi|nadwrażliw)\p{L}*\s+na\s+(?:lateks\p{L}*|latex)(?:\s+naturaln\p{L}*)?',
                    '(?:natural\s+rubber\s+)?latex\s+allerg\p{L}*',
                    'allerg\p{L}*\s+to\s+(?:natural\s+rubber\s+)?latex',
                ],
                'related_note' => self::ALLERGY_NOTE,
            ],
            'silicone_free' => [
                'label' => 'Bez silikonu',
                'gate' => null,
                'positive' => [
                    ...$enumeration,
                    'bez\s+(?:dodatku\s+|zawartości\s+)?silikon\p{L}*',
                    'nie\s+zawiera\p{L}*\s+silikon\p{L}*',
                    'woln\p{L}*\s+od\s+silikon\p{L}*',
                    'silicone[\s-]*free',
                    'free\s+(?:of|from)\s+silicone',
                ],
                'negated' => [],
                'contradiction' => [
                    'z\s+silikon\p{L}*',
                    'silikonow\p{L}*',
                    'zawiera\p{L}*\s+silikon\p{L}*',
                    'contains\s+silicone',
                ],
                'material' => '/^\s*(?:silikon\p{L}*|silicone)\s*$/iu',
                'related' => [],
                'related_note' => null,
            ],
            'food_contact' => [
                'label' => 'Kontakt z żywnością',
                'gate' => null,
                'positive' => [
                    'kontakt\p{L}*\s+z\s+żywnoś\p{L}*',
                    'kontakt\p{L}*\s+z\s+(?:artykułami|produktami)\s+spożywczymi',
                    'food[\s-]+contact',
                ],
                'negated' => [
                    'nie\s+(?:nadaj\p{L}*\s+się\s+|(?:jest|są)\s+przeznaczon\p{L}*\s+)?do\s+kontakt\p{L}*\s+z\s+żywnoś\p{L}*',
                    'not\s+(?:suitable\s+|approved\s+|intended\s+)?for\s+food[\s-]+contact',
                ],
                'contradiction' => [],
                'material' => null,
                'related' => [
                    'bezpieczn\p{L}*\s+dla\s+żywności',
                    'HACCP'.self::E,
                    'food[\s-]+(?:safe|handling)',
                    'wymog\p{L}*\s+żywności',
                ],
                'related_note' => self::FOOD_NOTE,
            ],
            'fda' => [
                'label' => 'FDA',
                'gate' => null,
                'positive' => ['FDA'.self::E],
                'negated' => [],
                'contradiction' => [],
                'material' => null,
                'related' => [],
                'related_note' => null,
            ],
            'seamless' => [
                'label' => 'Bezszwowy',
                'gate' => null,
                'positive' => ['bezszwow\p{L}*', 'bez\s+szwów', 'seamless'],
                'negated' => ['nie\s+(?:jest\s+|są\s+)?bezszwow\p{L}*'],
                // „szwy zgrzewane” nie przeczą bezszwowej dzianinie — tylko konstrukcja cięta i szyta
                'contradiction' => [
                    '(?:cięt|krojon)\p{L}*\s+i\s+szyt\p{L}*',
                    'cut[\s-]*(?:and|&)[\s-]*sew\p{L}*',
                ],
                'material' => null,
                'related' => [],
                'related_note' => null,
            ],
            'hook_and_loop' => [
                'label' => 'Zapięcie na rzep',
                'gate' => null,
                // końcówki wprost, żeby „rzepak” nie był rzepem
                'positive' => [
                    'rzep(?:y|u|em|ów|ami|ach|a|ie)?'.self::E,
                    'velcro',
                    'hook[\s-]*(?:and|&)[\s-]*loop',
                ],
                'negated' => [],
                'contradiction' => [],
                'material' => null,
                'related' => [],
                'related_note' => null,
            ],
        ];

        $compiled = [];
        foreach ($definitions as $key => $definition) {
            $positive = implode('|', $definition['positive']);
            $compiled[$key] = [
                'label' => $definition['label'],
                'gate' => $definition['gate'],
                'unknown' => '/'.self::B.'(?:'.$positive.')\p{L}*'.self::CELL_UNKNOWN.self::CELL_END.'/imu',
                'negated' => '/'.self::B.'(?:'.implode('|', [
                    ...$definition['negated'],
                    '(?:'.$positive.')\p{L}*'.self::CELL_NO.self::E.self::CELL_END,
                ]).')/imu',
                'positive' => '/'.self::B.'(?:'.$positive.')/iu',
                'contradiction' => $definition['contradiction'] === []
                    ? null
                    : '/'.self::B.'(?:'.implode('|', $definition['contradiction']).')/iu',
                'material' => $definition['material'],
                'related' => $definition['related'] === []
                    ? null
                    : '/'.self::B.'(?:'.implode('|', $definition['related']).')/iu',
                'related_note' => $definition['related_note'],
            ];
        }

        return self::$features = $compiled;
    }
}
