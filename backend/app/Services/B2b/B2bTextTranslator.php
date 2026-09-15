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
    /** Segment z listą parametrów z łącznika (BolleB2bConnector::description) — zostaje dosłownie. */
    private const PARAMETERS_PREFIX = 'Parametry:';

    /** Poniżej tej długości tłumaczonego tekstu bez kontroli długości — krótkie frazy po polsku bywają dwa razy dłuższe. */
    private const LENGTH_CHECK_MIN_CHARS = 200;

    private const LENGTH_RATIO_MIN = 0.6;

    private const LENGTH_RATIO_MAX = 1.8;

    /**
     * Słowa wielkimi literami, które wolno przetłumaczyć — powszechne skróty, a nie nazwy własne:
     * N/A („nie dotyczy”), PPE (po polsku ŚOI). UV, PC, PVC, LED, ESD po polsku brzmią tak samo,
     * więc zostają chronione — ich zniknięcie z wyniku oznacza zgubiony fakt.
     */
    private const TRANSLATABLE_UPPERCASE = ['N/A', 'PPE'];

    /**
     * Oznaczenie normy: EN 166, EN ISO 16321-1, EN 166:2001, ANSI Z87.1, DIN EN 175. Prefiks wielkimi literami,
     * żeby nie łapać zwykłych słów; „PN-EN 166” daje to samo oznaczenie co „EN 166”.
     */
    private const NORM_PATTERN = '/(?<![\p{L}\p{N}])(ANSI(?:\/ISEA)?|DIN|EN|IEC|ISO)((?:[ \x{00A0}]+(?:EN|IEC|ISO))*)[ \x{00A0}]*([A-Z]?\d+(?:[.:\-–]\d+)*)/u';

    /** Liczba z wymiarem sklejonym z jednostką („17.5CM”) — jednostkę wolno zapisać po polsku, liczbę pilnuje kontrola liczb. */
    private const MEASUREMENT_PATTERN = '/^\d+(?:[.,]\d+)?(?:mm|cm|m|km|mg|g|kg|ml|l|nm|µm|h|min|s|v|kv|w|hz|db|°c|°f|pa|kpa)$/iu';

    public function __construct(private readonly OpenAiCompatibleClient $llm) {}

    /**
     * @return array{description: string, name: string|null} name = null, gdy $name było null
     *
     * @throws B2bTranslationRejected tłumaczenie odrzucone przez walidację (tekst źródła zostaje)
     * @throws \RuntimeException błąd wywołania modelu (job ponawia)
     */
    public function translate(string $description, ?string $name = null): array
    {
        if (! mb_check_encoding($description, 'UTF-8') || ($name !== null && ! mb_check_encoding($name, 'UTF-8'))) {
            throw new B2bTranslationRejected('tekst źródła nie jest poprawnym UTF-8');
        }

        $sourceName = $name !== null && trim($name) !== '' ? trim($name) : null;
        $segments = self::splitSegments($description);
        /** @var array<int, string> $translatable  indeks segmentu => tekst źródła */
        $translatable = [];
        foreach ($segments as $index => $segment) {
            if (! str_starts_with($segment, self::PARAMETERS_PREFIX)) {
                $translatable[$index] = $segment;
            }
        }
        if ($translatable === [] && $sourceName === null) {
            return ['description' => $description, 'name' => $name];
        }

        $sources = array_values($translatable);
        $payload = json_encode(
            ['name' => $sourceName, 'segments' => $sources],
            JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR
        );
        // Zapas tokenów: chatJson przy finish_reason=length ponawia ze skróconym wejściem — tego nie chcemy.
        $maxTokens = max(1200, (int) ceil(mb_strlen($payload) * 1.6 / 3));

        $response = $this->llm->chatJsonEnrichment([
            ['role' => 'system', 'content' => self::systemPrompt()],
            ['role' => 'user', 'content' => $payload],
        ], 0.0, $maxTokens);

        $translated = self::validated($response, $sources, $sourceName);

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
Dostajesz JSON {"name": nazwa produktu albo null, "segments": [fragmenty opisu]}.
Zadanie: WIERNE TŁUMACZENIE na polski — segment po segmencie, zdanie po zdaniu. Nie piszesz nowego opisu.

ZASADY:
- Nie dodawaj, nie usuwaj, nie uogólniaj i nie wzmacniaj faktów. Nie dopisuj norm, klas, certyfikatów, parametrów, zastosowań ani korzyści, których nie ma w źródle.
- Układ bez zmian: każda linia źródła = dokładnie jedna linia wyniku, w tej samej kolejności. Nie łącz i nie dziel linii. Znaczniki list („- ”) zostają.
- BEZ ZMIAN, dosłownie i z tą samą wielkością liter: nazwy marek, rodzin i modeli (np. Bollé, TRYON BSSI, RUSH+ 2.0, VOLT, MY6), kody i numery artykułów (B809, PSSTRYOC13B), znaki ® i ™, oznaczenia norm (EN 166, EN ISO 16321-1, ANSI Z87.1), nazwy technologii i powłok pisane wielkimi literami (PLATINUM, ASAF, FLEX160°), oznaczenia rozmiarów (S, L, XL, Extra Large, One size).
- Liczby: każda liczba ze źródła zostaje; wolno zapisać przecinek dziesiętny i polską jednostkę (17.5CM → 17,5 cm). Liczb zapisanych słownie nie zamieniaj na cyfry i odwrotnie. Nie dopisuj żadnej nowej liczby.
- Terminologia okularów i ŚOI (spójnie w całym tekście): temples → zauszniki, nose bridge → mostek nosowy, nose pads → noski, sideshields / side shields → osłony boczne, frame → oprawka, lens → soczewka, lens tint → odcień soczewki, anti-fog → przeciwmgielna, anti-scratch → odporna na zarysowania, wrap-around → panoramiczna (owijająca).
- Tekst już po polsku zwróć bez zmian.
- name: człon przed pierwszym „ – ” (albo „ - ”, gdy nie ma „ – ”) zostaw dosłownie, przetłumacz resztę. name=null → zwróć null.
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

    /** Człon przed pierwszym „ – ” (albo „ - ”, gdy brak „ – ”): kod, model, rozmiar — zostaje dosłownie. */
    private static function nameFamily(string $name): ?string
    {
        foreach ([' – ', ' - '] as $separator) {
            $position = mb_strpos($name, $separator);
            if ($position !== false && $position > 0) {
                return mb_substr($name, 0, $position);
            }
        }

        return null;
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
     * („17,50” i „17.5” to ta sama liczba). „1,000” w stylu angielskim da inną liczbę niż „1000” — takie
     * tłumaczenie zostanie odrzucone, co jest bezpieczne (zostaje źródło).
     *
     * @return array<string, int>
     */
    private static function numbers(string $text): array
    {
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

    private static function assertProtectedTokensKept(string $source, string $result): void
    {
        $missing = [];
        foreach (self::protectedTokens($source) as $token) {
            $pattern = '/(?<![\p{L}\p{N}])'.preg_quote($token, '/').'(?![\p{L}\p{N}])/u';
            if (preg_match($pattern, $result) !== 1) {
                $missing[] = $token;
            }
        }
        if ($missing !== []) {
            throw new B2bTranslationRejected('zgubiony token: '.implode(', ', $missing));
        }
    }

    /**
     * Słowa, które muszą przejść do wyniku dosłownie (jako całe słowo):
     * - z cyfrą i literą (B809, FLEX160°, PSSTRYOC13B), poza liczbą sklejoną z jednostką (17.5CM),
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
        foreach (preg_split('/\s+/u', $text, -1, PREG_SPLIT_NO_EMPTY) ?: [] as $raw) {
            $word = (string) preg_replace('/^\p{P}+|\p{P}+$/u', '', $raw);
            $protected = $word !== '' && ! in_array($word, self::TRANSLATABLE_UPPERCASE, true) && (
                (preg_match('/\p{N}/u', $word) === 1 && preg_match('/\p{L}/u', $word) === 1
                    && preg_match(self::MEASUREMENT_PATTERN, $word) !== 1)
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
