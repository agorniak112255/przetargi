<?php

declare(strict_types=1);

namespace App\Services\B2b;

use App\Services\Ai\OpenAiCompatibleClient;

/**
 * Tłumaczenie opisu (i opcjonalnie nazwy) karty z importu B2B na polski — wierne, bez dodawania i usuwania faktów.
 *
 * Oryginału nie przechowujemy, więc wynik modelu musi przejść walidację względem źródła: liczba segmentów i linii,
 * liczby, normy, kody i nazwy własne, człon rodziny w nazwie, długość. Każde naruszenie = B2bTranslationRejected
 * (karta zostaje z tekstem źródła). Sekcja „Parametry:” z łącznika nie idzie do modelu — etykiety są już polskie,
 * a wartości cech to nazwy handlowe.
 */
class B2bTextTranslator
{
    /** Łącznik i półpauzy są w zakresach wymienne — „1030-1400nm” i „1030–1400 nm” to ten sam token. */
    private const DASHES = '[-–—]';

    /** Segment z listą parametrów z łącznika (BolleB2bConnector::description) — zostaje dosłownie. */
    private const PARAMETERS_PREFIX = 'Parametry:';

    /** Poniżej tej długości tłumaczonego tekstu bez kontroli długości — krótkie frazy po polsku bywają dwa razy dłuższe. */
    private const LENGTH_CHECK_MIN_CHARS = 200;

    private const LENGTH_RATIO_MIN = 0.6;

    private const LENGTH_RATIO_MAX = 1.8;

    /**
     * Słowa wielkimi literami, które wolno przetłumaczyć — powszechne skróty, a nie nazwy własne:
     * N/A („nie dotyczy”), PPE (po polsku ŚOI). UV, PC, PVC, LED, ESD zostają chronione — ich zniknięcie
     * z wyniku oznacza zgubiony fakt (PVC wolno zapisać polskim skrótem PCW/PCV — TOKEN_EQUIVALENTS).
     * Do tego angielskie słowa funkcyjne pisane wersalikami dla podkreślenia („FLASH is THE reference” — 23.09.2026
     * odrzucane jako „zgubiony token: THE”); nazwą modelu nie są.
     */
    private const TRANSLATABLE_UPPERCASE = [
        'N/A', 'PPE',
        'THE', 'AND', 'FOR', 'WITH', 'YOUR', 'YOU', 'OUR', 'ARE', 'THIS', 'THAT', 'FROM', 'BUT', 'NOT',
    ];

    /**
     * Chroniony token źródła => zapisy w wyniku, które go spełniają. PVC (polichlorek winylu) to po polsku PCW
     * albo PCV — ten sam materiał (23.09.2026 „oprawki BL150 z PCW” odrzucone jako „zgubiony token: PVC”).
     * PC (poliwęglan) to inny materiał i zamiennikiem PVC nie jest („oprawka z PC” zostaje odrzucona).
     */
    private const TOKEN_EQUIVALENTS = [
        'PVC' => ['PVC', 'PCW', 'PCV'],
    ];

    /**
     * Angielskie słowa funkcyjne: segment, który je zawiera i wrócił z modelu bez zmian, nie został przetłumaczony
     * (23.09.2026 karta HUSTLN50E zapisana jako „tłumaczenie” identyczne z angielskim źródłem). Tylko słowa, których
     * nie ma w polszczyźnie — tekst po polsku model słusznie zwraca bez zmian.
     */
    private const ENGLISH_FUNCTION_WORDS = ['the', 'and', 'with', 'for', 'your', 'you', 'our', 'are', 'is', 'of', 'this', 'that', 'from', 'its'];

    /** Tyle różnych słów funkcyjnych wystarcza, by uznać segment za angielski. */
    private const ENGLISH_WORDS_MIN = 2;

    /**
     * Oznaczenie normy: EN 166, EN ISO 16321-1, EN 166:2001, ANSI Z87.1, DIN EN 175. Prefiks wielkimi literami,
     * żeby nie łapać zwykłych słów; „PN-EN 166” daje to samo oznaczenie co „EN 166”.
     */
    private const NORM_PATTERN = '/(?<![\p{L}\p{N}])(ANSI(?:\/ISEA)?|DIN|EN|IEC|ISO)((?:[ \x{00A0}]+(?:EN|IEC|ISO))*)[ \x{00A0}]*([A-Z]?\d+(?:[.:\-–]\d+)*)/u';

    /**
     * Liczba (albo wymiary „96x39”) sklejona z jednostką („17.5CM”, „0.05ms”, „96x39mm”, „120m/s”) — jednostkę wolno
     * zapisać po polsku („96 x 39 mm”), liczby pilnuje kontrola liczb.
     */
    private const UNITS = 'mm|cm|m|km|mg|g|kg|ml|l|nm|µm|h|min|ms|s|m\/s|km\/h|v|kv|w|hz|db|°c|°f|pa|kpa';

    private const MEASUREMENT_PATTERN = '/^\d+(?:[.,]\d+)?(?:[x×]\d+(?:[.,]\d+)?)*(?:'.self::UNITS.')$/iu';

    /**
     * Zakres z jednostką („855nm-1090nm”, „180-315nm”, „3-5mm”) — po polsku pisze się go inaczej
     * („od 855 nm do 1090 nm”), więc nie jest to token do przepisania znak w znak. Liczb pilnuje kontrola liczb.
     */
    private const MEASUREMENT_RANGE_PATTERN = '/^\d+(?:[.,]\d+)?(?:'.self::UNITS.')?[-–—]\d+(?:[.,]\d+)?(?:'.self::UNITS.')$/iu';

    /** Liczba złączona łącznikiem ze zwykłym słowem („3-point”, „5-points”) — po polsku „3-punktowe”; liczby pilnuje kontrola liczb. */
    private const NUMBER_WORD_PATTERN = '/^\d+(?:[.,]\d+)?-\p{Ll}+$/u';

    public function __construct(private readonly OpenAiCompatibleClient $llm) {}

    /**
     * @return array{description: string, name: string|null} name = null, gdy $name było null
     *
     * @throws B2bTranslationRejected tłumaczenie odrzucone przez walidację (tekst źródła zostaje)
     * @throws \RuntimeException błąd wywołania modelu (job ponawia)
     */
    public function translate(string $description, ?string $name = null, ?string $cardName = null): array
    {
        if (! mb_check_encoding($description, 'UTF-8') || ($name !== null && ! mb_check_encoding($name, 'UTF-8'))) {
            throw new B2bTranslationRejected('tekst źródła nie jest poprawnym UTF-8');
        }

        $sourceName = $name !== null && trim($name) !== '' ? trim($name) : null;
        $segments = self::splitSegments($description);
        /** @var array<int, string> $translatable  indeks segmentu => tekst źródła */
        $translatable = [];
        foreach ($segments as $index => $segment) {
            if (! str_starts_with($segment, self::PARAMETERS_PREFIX) && ! self::isUppercaseOnly($segment)) {
                $translatable[$index] = $segment;
            }
        }
        if ($translatable === [] && $sourceName === null) {
            return ['description' => $description, 'name' => $name];
        }

        $sources = array_values($translatable);
        $context = $cardName !== null && trim($cardName) !== '' ? trim($cardName) : null;
        $payload = json_encode(
            ['product' => $context, 'name' => $sourceName, 'segments' => $sources],
            JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR
        );
        // Zapas tokenów: chatJson przy finish_reason=length ponawia ze skróconym wejściem — tego nie chcemy.
        $maxTokens = max(1200, (int) ceil(mb_strlen($payload) * 1.6 / 3));

        $response = $this->llm->chatJsonEnrichment([
            ['role' => 'system', 'content' => self::systemPrompt()],
            ['role' => 'user', 'content' => $payload],
        ], 0.0, $maxTokens);

        try {
            $translated = self::validated($response, $sources, $sourceName);
        } catch (B2bTranslationRejected $e) {
            throw $e->withResponse($response);
        }

        foreach (array_keys($translatable) as $position => $index) {
            $segments[$index] = $translated['segments'][$position];
        }

        return [
            'description' => $translatable === [] ? $description : implode("\n\n", $segments),
            'name' => $sourceName === null ? $name : $translated['name'],
        ];
    }

    private static function systemPrompt(): string
    {
        return <<<'SYS'
Jesteś tłumaczem kart produktów BHP na język polski.
Dostajesz JSON {"product": nazwa karty w katalogu (po polsku) albo null, "name": nazwa produktu albo null, "segments": [fragmenty opisu]}.
Zadanie: WIERNE TŁUMACZENIE na polski — segment po segmencie, zdanie po zdaniu. Nie piszesz nowego opisu.

ZASADY:
- Nie dodawaj, nie usuwaj, nie uogólniaj i nie wzmacniaj faktów. Nie dopisuj norm, klas, certyfikatów, parametrów, zastosowań ani korzyści, których nie ma w źródle.
- Układ bez zmian: każda linia źródła = dokładnie jedna linia wyniku, w tej samej kolejności. Nie łącz i nie dziel linii. Znaczniki list („- ”) zostają.
- BEZ ZMIAN, dosłownie i z tą samą wielkością liter: nazwy marek, rodzin i modeli (np. Bollé, TRYON BSSI, RUSH+ 2.0, VOLT, MY6), kody i numery artykułów (B809, PSSTRYOC13B), znaki ® i ™, oznaczenia norm (EN 166, EN ISO 16321-1, ANSI Z87.1), nazwy technologii i powłok pisane wielkimi literami (PLATINUM, ASAF, FLEX160°), oznaczenia rozmiarów (S, L, XL, Extra Large, One size).
- Liczby: każda liczba ze źródła zostaje; wolno zapisać przecinek dziesiętny i polską jednostkę (17.5CM → 17,5 cm). Liczb zapisanych słownie nie zamieniaj na cyfry i odwrotnie. Nie dopisuj żadnej nowej liczby.
- product to nazwa tej samej karty po polsku — tylko kontekst: trzymaj się jej słownictwa (gdy karta mówi „szyba chroniąca przed laserem”, nie pisz „okno ochronne”). Nie tłumacz jej i nie dopisuj do wyniku.
- Terminologia ochrony przed laserem: laser safety window → szyba chroniąca przed laserem, laser protection filter → filtr chroniący przed laserem, laser radiation → promieniowanie laserowe, within the bulk material → w samym materiale, daylight transmission → przepuszczalność światła dziennego, visual brightness → jasność widzenia, colour recognition → rozpoznawanie barw, alignment protection → ochrona przy justowaniu, optical density (OD) → gęstość optyczna (OD), coating → powłoka, anti-scratch → odporna na zarysowania.
- Terminologia okularów i ŚOI (spójnie w całym tekście): temples → zauszniki, nose bridge → mostek nosowy, nose pads → noski, sideshields / side shields → osłony boczne, frame → oprawka, lens → soczewka, lens tint → odcień soczewki, anti-fog → przeciwmgielna, anti-scratch → odporna na zarysowania, wrap-around → panoramiczna (owijająca).
- Nazwy wyrobów (name i tytuły segmentów): safety glasses → okulary ochronne, safety goggle(s) → gogle ochronne, over-the-glasses → okulary ochronne nakładane na okulary korekcyjne, prescription safety glasses → okulary ochronne korekcyjne, welding safety glasses → okulary spawalnicze, welding helmet → przyłbica spawalnicza (nie „kask” ani „hełm” — kask to ochrona głowy), face shield / faceguard → osłona twarzy, tilting shield → osłona uchylna, spare lens → wizjer zapasowy (osłony i przyłbice) albo soczewka zapasowa (okulary i gogle), screen guard / protection plates → szybka ochronna, headband / headgear → nagłowie, sweatband / sweat band → napotnik, foam and strap kit → zestaw pianki i paska, optical insert → wkładka korekcyjna, cord / strap → sznurek, case → etui, eco pack of N pieces → opakowanie ekologiczne N szt., pack of N pieces → opakowanie N szt. Odcienie soczewek: clear → bezbarwne, smoke → przyciemniane, copper → miedziane, amber → bursztynowe, bronze → brązowe, polarized → polaryzacyjne.
- Tekst już po polsku zwróć bez zmian.
- name: człon przed pierwszym „ – ” albo „ - ” (który pierwszy) zostaw dosłownie, przetłumacz resztę. name=null → zwróć null.
- Bez HTML, markdown i komentarzy.

Zwróć TYLKO JSON — bez pola thought/reasoning. Pierwszy znak to {.
{"name": "…" albo null, "segments": ["…"]}
segments ma tyle samo elementów co na wejściu, w tej samej kolejności.
SYS;
    }

    /**
     * Segmenty opisu oddzielone pustą linią (bez pustych).
     *
     * @return list<string>
     */
    private static function splitSegments(string $text): array
    {
        $text = str_replace(["\r\n", "\r"], "\n", $text);
        $segments = [];
        foreach (preg_split('/\n\s*\n/u', $text) ?: [] as $segment) {
            $segment = trim($segment);
            if ($segment !== '') {
                $segments[] = $segment;
            }
        }

        return $segments;
    }

    /**
     * Akapit bez małych liter (Bollé featureddescription: ucięte znaczniki kategorii „CASES ACCESSORIES CASES N”,
     * „RUSH+ - KIT SAFETY SPARE”) zostaje dosłownie i nie idzie do modelu — miesza nazwy modeli ze zwykłymi słowami,
     * więc tłumaczenie gubiłoby nazwę własną albo było odrzucane (15.09.2026: 5 kart).
     */
    private static function isUppercaseOnly(string $segment): bool
    {
        return preg_match('/\p{L}/u', $segment) === 1 && preg_match('/\p{Ll}/u', $segment) !== 1;
    }

    /**
     * @param  array<string, mixed>  $response
     * @param  list<string>  $sources
     * @return array{segments: list<string>, name: string|null}
     */
    private static function validated(array $response, array $sources, ?string $sourceName): array
    {
        $segments = $response['segments'] ?? null;
        if (! is_array($segments) || ! array_is_list($segments) || count($segments) !== count($sources)) {
            throw new B2bTranslationRejected(sprintf(
                'inna liczba segmentów: w źródle %d, w odpowiedzi %s',
                count($sources),
                is_array($segments) ? (string) count($segments) : 'brak tablicy segments'
            ));
        }

        $results = [];
        foreach ($sources as $position => $source) {
            $label = sprintf('segment %d', $position + 1);
            if (! is_string($segments[$position])) {
                throw new B2bTranslationRejected($label.': wynik nie jest tekstem');
            }
            $result = trim(str_replace(["\r\n", "\r"], "\n", $segments[$position]));
            if ($result === '') {
                throw new B2bTranslationRejected($label.': pusty wynik');
            }
            self::assertNoMarkup($result, $label);
            $sourceLines = self::nonEmptyLineCount($source);
            $resultLines = self::nonEmptyLineCount($result);
            if ($sourceLines !== $resultLines) {
                throw new B2bTranslationRejected(sprintf(
                    '%s: inna liczba linii — w źródle %d, w tłumaczeniu %d',
                    $label,
                    $sourceLines,
                    $resultLines
                ));
            }
            if ($result === trim($source) && self::looksEnglish($source)) {
                throw new B2bTranslationRejected($label.': model zwrócił tekst źródła bez tłumaczenia');
            }
            $results[] = $result;
        }

        $resultName = null;
        if ($sourceName !== null) {
            $candidate = $response['name'] ?? null;
            if (! is_string($candidate) || trim($candidate) === '') {
                throw new B2bTranslationRejected('pusta nazwa w odpowiedzi');
            }
            $resultName = trim($candidate);
            if (preg_match('/\R/u', $resultName) === 1) {
                throw new B2bTranslationRejected('nazwa w odpowiedzi ma kilka linii');
            }
            self::assertNoMarkup($resultName, 'nazwa');
            $family = self::nameFamily($sourceName);
            if ($family !== null && ! str_starts_with($resultName, $family)) {
                throw new B2bTranslationRejected(sprintf(
                    'zmieniony człon nazwy „%s” (wynik: „%s”)',
                    $family,
                    $resultName
                ));
            }
        }

        $sourceText = implode("\n", $sourceName === null ? $sources : [$sourceName, ...$sources]);
        $resultText = implode("\n", $resultName === null ? $results : [$resultName, ...$results]);

        self::assertSameNorms($sourceText, $resultText);
        self::assertSameNumbers($sourceText, $resultText);
        self::assertProtectedTokensKept($sourceText, $resultText);
        self::assertLength($sourceText, $resultText);

        return ['segments' => $results, 'name' => $resultName];
    }

    private static function assertNoMarkup(string $text, string $label): void
    {
        if (str_starts_with($text, '```') || preg_match('/<\/?[a-z][a-z0-9-]*(?:\s[^<>]*)?\/?>/i', $text) === 1) {
            throw new B2bTranslationRejected($label.': znaczniki markdown/HTML w wyniku');
        }
    }

    private static function looksEnglish(string $text): bool
    {
        preg_match_all('/(?<![\p{L}\p{N}])('.implode('|', self::ENGLISH_FUNCTION_WORDS).')(?![\p{L}\p{N}])/iu', $text, $matches);

        return count(array_unique(array_map('mb_strtolower', $matches[1]))) >= self::ENGLISH_WORDS_MIN;
    }

    private static function nonEmptyLineCount(string $text): int
    {
        $count = 0;
        foreach (preg_split('/\R/u', $text) ?: [] as $line) {
            if (trim($line) !== '') {
                $count++;
            }
        }

        return $count;
    }

    /**
     * Człon przed pierwszym „ – ” albo „ - ” (który wcześniej): kod i model — zostaje dosłownie. Do 23.09.2026 „ – ” miał
     * pierwszeństwo, więc w „BL150 - Pack of 10 pieces – Clear safety goggle” dosłownie miało zostać także „Pack of 10
     * pieces” i poprawne tłumaczenie („BL150 - Zestaw 10 sztuk – …”) było odrzucane.
     */
    private static function nameFamily(string $name): ?string
    {
        $positions = array_filter(
            [mb_strpos($name, ' – '), mb_strpos($name, ' - ')],
            static fn (int|false $position): bool => $position !== false && $position > 0,
        );

        return $positions === [] ? null : mb_substr($name, 0, min($positions));
    }

    private static function assertSameNorms(string $source, string $result): void
    {
        $sourceNorms = self::norms($source);
        $resultNorms = self::norms($result);
        $lost = array_values(array_diff($sourceNorms, $resultNorms));
        $added = array_values(array_diff($resultNorms, $sourceNorms));
        $problems = [];
        if ($lost !== []) {
            $problems[] = 'zgubiona norma: '.implode(', ', $lost);
        }
        if ($added !== []) {
            $problems[] = 'dopisana norma: '.implode(', ', $added);
        }
        if ($problems !== []) {
            throw new B2bTranslationRejected(implode('; ', $problems));
        }
    }

    /** @return list<string> */
    private static function norms(string $text): array
    {
        preg_match_all(self::NORM_PATTERN, $text, $matches, PREG_SET_ORDER);
        $norms = [];
        foreach ($matches as $match) {
            $prefix = trim((string) preg_replace('/[ \x{00A0}]+/u', ' ', $match[1].$match[2]));
            $norms[] = $prefix.' '.str_replace('–', '-', $match[3]);
        }

        return array_values(array_unique($norms));
    }

    private static function assertSameNumbers(string $source, string $result): void
    {
        $sourceNumbers = self::numbers($source);
        $resultNumbers = self::numbers($result);
        $lost = [];
        $added = [];
        foreach ($sourceNumbers as $value => $count) {
            $missing = $count - ($resultNumbers[$value] ?? 0);
            for ($i = 0; $i < $missing; $i++) {
                $lost[] = (string) $value;
            }
        }
        foreach ($resultNumbers as $value => $count) {
            $extra = $count - ($sourceNumbers[$value] ?? 0);
            for ($i = 0; $i < $extra; $i++) {
                $added[] = (string) $value;
            }
        }
        $problems = [];
        if ($lost !== []) {
            $problems[] = 'zgubiona liczba: '.implode(', ', $lost);
        }
        if ($added !== []) {
            $problems[] = 'dopisana liczba: '.implode(', ', $added);
        }
        if ($problems !== []) {
            throw new B2bTranslationRejected(implode('; ', $problems));
        }
    }

    /**
     * Multizbiór liczb po ujednoliceniu zapisu: przecinek dziesiętny = kropka, bez zer wiodących i końcowych
     * („17,50” i „17.5” to ta sama liczba). Separator tysięcy — angielski przecinek („11,500”) i polska spacja
     * („11 500”) — znika po obu stronach; bez tego każdy opis z takimi liczbami byłby odrzucany (16.09.2026
     * karty UVEX ze strony producenta: „from 635nm to 11,500nm”).
     *
     * @return array<string, int>
     */
    private static function numbers(string $text): array
    {
        $text = self::withoutThousandsSeparators($text);
        preg_match_all('/(?<!\d)\d+(?:[.,]\d+)?/u', $text, $matches);
        $numbers = [];
        foreach ($matches[0] as $raw) {
            [$integer, $fraction] = array_pad(explode('.', str_replace(',', '.', $raw), 2), 2, '');
            $integer = ltrim($integer, '0');
            $fraction = rtrim($fraction, '0');
            $value = ($integer === '' ? '0' : $integer).($fraction === '' ? '' : '.'.$fraction);
            $numbers[$value] = ($numbers[$value] ?? 0) + 1;
        }

        return $numbers;
    }

    /**
     * Separator tysięcy znika tylko w pełnym zapisie grup po trzy cyfry i tylko gdy liczba nie zaczyna się od
     * zera: „1,030” i „11 500” dają 1030 i 11500, a ułamek „0,125” zostaje ułamkiem.
     */
    private static function withoutThousandsSeparators(string $text): string
    {
        $pattern = '/(?<![\d.,])[1-9]\d{0,2}(?:,\d{3})+(?![\d.,])'
            .'|(?<![\d.,])[1-9]\d{0,2}(?:[ \x{00A0}]\d{3})+(?![\d.,])/u';

        return (string) preg_replace_callback(
            $pattern,
            static fn (array $m): string => str_replace([',', ' ', "\u{00A0}"], '', $m[0]),
            $text,
        );
    }

    /**
     * Wzorzec tokenu, który ma przejść do tłumaczenia dosłownie. Odstęp między liczbą a tym, co po niej,
     * jest dozwolony („1,030-1,400nm” w źródle i „1030-1400 nm” po polsku to ten sam token), ale samego
     * tekstu nie sklejamy — „P6P21 z powłoką” musi zostać dwoma słowami, inaczej token wygląda na zgubiony.
     */
    private static function tokenPattern(string $token): string
    {
        $parts = preg_split('/(?<=\d)(?=\p{L})/u', self::withoutThousandsSeparators($token)) ?: [$token];
        // zakres po polsku bywa pisany półpauzą („1030–1400 nm”), a w źródle łącznikiem
        $quoted = array_map(
            // strtr, nie str_replace: podmiany nie mogą wejść w siebie nawzajem (klasa zawiera myślniki)
            static fn (string $part): string => strtr(preg_quote($part, '/'), [
                '\-' => self::DASHES,
                '–' => self::DASHES,
                '—' => self::DASHES,
            ]),
            $parts,
        );

        return '/(?<![\p{L}\p{N}])'.implode('[ \x{00A0}]?', $quoted).'(?![\p{L}\p{N}])/u';
    }

    private static function assertProtectedTokensKept(string $source, string $result): void
    {
        // po obu stronach ten sam zapis separatora tysięcy — reszta zostaje bez zmian
        $haystack = self::withoutThousandsSeparators($result);
        $missing = [];
        foreach (self::protectedTokens($source) as $token) {
            $kept = false;
            foreach (self::TOKEN_EQUIVALENTS[$token] ?? [$token] as $variant) {
                if (preg_match(self::tokenPattern($variant), $haystack) === 1) {
                    $kept = true;
                    break;
                }
            }
            if (! $kept) {
                $missing[] = $token;
            }
        }
        if ($missing !== []) {
            throw new B2bTranslationRejected('zgubiony token: '.implode(', ', $missing));
        }
    }

    /**
     * Słowa, które muszą przejść do wyniku dosłownie (jako całe słowo):
     * - z cyfrą i literą (B809, FLEX160°, PSSTRYOC13B), poza liczbą albo wymiarami sklejonymi z jednostką
     *   (17.5CM, 96x39mm, 120m/s) i liczbą złączoną ze słowem (3-point),
     * - ze znakiem ® lub ™,
     * - pisane w całości wielkimi literami, co najmniej 3 litery (PLATINUM, TRYON, RUSH+), poza TRANSLATABLE_UPPERCASE,
     * - liczba dziesiętna tuż po takim słowie, bez interpunkcji między nimi („2.0” w „RUSH+ 2.0”) — to część nazwy modelu,
     *   nie wartość, więc „2,0” jest tu błędem.
     *
     * @return list<string>
     */
    private static function protectedTokens(string $text): array
    {
        $tokens = [];
        $previousProtected = false;
        // Słowa sklejone interpunkcją bez odstępu („(D3 D4 D5),Overflow chute” — 23.09.2026 „zgubiony token:
        // D5),Overflow”, bo model wstawił spację) to osobne słowa. Dzielimy tylko przed literą: „EN166:2001”,
        // „17.5CM” i „1,030” zostają w całości.
        $words = [];
        foreach (preg_split('/\s+/u', $text, -1, PREG_SPLIT_NO_EMPTY) ?: [] as $chunk) {
            array_push($words, ...(preg_split('/[,;:()\[\]]+(?=\p{L})/u', $chunk, -1, PREG_SPLIT_NO_EMPTY) ?: [$chunk]));
        }
        foreach ($words as $raw) {
            $word = (string) preg_replace('/^\p{P}+|\p{P}+$/u', '', $raw);
            $protected = $word !== '' && ! in_array($word, self::TRANSLATABLE_UPPERCASE, true) && (
                // kod wyrobu ma wielką literę (B809, P1P10, FLEX160°); zlepki małymi literami to proza
                // źródła („5x eyelets”, „to1055nm”, „855nm-1090nm”) — tam pilnujemy tylko liczb
                (preg_match('/\p{N}/u', $word) === 1 && preg_match('/\p{Lu}/u', $word) === 1
                    && preg_match(self::MEASUREMENT_PATTERN, $word) !== 1
                    && preg_match(self::MEASUREMENT_RANGE_PATTERN, $word) !== 1
                    && preg_match(self::NUMBER_WORD_PATTERN, $word) !== 1)
                || preg_match('/[®™]/u', $word) === 1
                || (preg_match('/\p{Ll}/u', $word) !== 1 && preg_match_all('/\p{Lu}/u', $word) >= 3)
                || ($previousProtected && preg_match('/^\d+[.,]\d+$/', $word) === 1)
            );
            if ($protected) {
                $tokens[$word] = true;
            }
            // Liczba należy do nazwy tylko wtedy, gdy poprzednie słowo nie kończy się interpunkcją („TRYON. 2.5 mm”).
            $previousProtected = $protected && $raw === $word;
        }

        return array_map('strval', array_keys($tokens));
    }

    private static function assertLength(string $source, string $result): void
    {
        $sourceLength = mb_strlen($source);
        if ($sourceLength < self::LENGTH_CHECK_MIN_CHARS) {
            return;
        }
        $resultLength = mb_strlen($result);
        $ratio = $resultLength / $sourceLength;
        if ($ratio < self::LENGTH_RATIO_MIN || $ratio > self::LENGTH_RATIO_MAX) {
            throw new B2bTranslationRejected(sprintf(
                '%s tłumaczenie: %d znaków wobec %d w źródle (dozwolone 0,6–1,8×)',
                $ratio < self::LENGTH_RATIO_MIN ? 'za krótkie' : 'za długie',
                $resultLength,
                $sourceLength
            ));
        }
    }
}
