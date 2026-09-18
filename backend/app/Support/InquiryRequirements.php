<?php

declare(strict_types=1);

namespace App\Support;

/**
 * Warunki szczególne z wiersza zapytania klienta.
 *
 * Powód istnienia: mail „Kombinezon chemoodporny (w szczególności na kwas
 * siarkowy 96%)” dostawał w ofercie kombinezon, którego karta ani słowem nie
 * mówi o kwasie siarkowym. List milczał o warunku, a milczenie czyta się jak
 * potwierdzenie. Tu wyciągamy taki warunek z tekstu klienta i sprawdzamy, czy
 * karta go potwierdza — cytatem z karty, nie domysłem.
 *
 * Dwa rodzaje warunków:
 *
 *  - **sprawdzalny** (`checkable`) — nazwana substancja, ewentualnie ze
 *    stężeniem („kwas siarkowy 96%”). Potwierdzenie to dosłowna wzmianka
 *    w karcie: nazwa albo wzór (H2SO4), a przy podanym stężeniu także ta sama
 *    liczba procent obok nazwy. Niepotwierdzony warunek zamyka drogę do listu:
 *    wyrób nie wchodzi jako propozycja, bo byłaby to odpowiedź na wymaganie,
 *    którego nikt nie sprawdził.
 *  - **do przeczytania przez człowieka** — cała klauzula po „w szczególności”,
 *    „w tym”, „odporność na”, gdy nie ma w niej znanej substancji. Takiej
 *    reguły nie da się sprawdzić maszynowo, więc jej nie udajemy: trafia do
 *    aplikacji jako ostrzeżenie dla handlowca i nie blokuje listu.
 *
 * Zasada nadrzędna: nic tu nie jest zgadywane. Brak wzmianki w karcie to
 * „karta tego nie podaje”, nigdy „wyrób tego nie ma”.
 */
final class InquiryRequirements
{
    /** Ile znaków po nazwie substancji szukamy w karcie jej stężenia. */
    private const NEAR = 80;

    /** Ile znaków za nazwą substancji w zapytaniu może stać jej stężenie. */
    private const PERCENT_NEAR = 24;

    /** Zwroty, po których klient stawia warunek szczególny. */
    private const MARKERS = [
        'w szczególności',
        'szczególnie',
        'w tym',
        'odporność na',
        'odpornością na',
        'odporny na',
        'odporna na',
        'odporne na',
        'odpornych na',
    ];

    /**
     * Substancje nazwane wprost. „kwas <przymiotnik>” łapie dowolny kwas, więc
     * lista nie musi być kompletna — reszta to nazwy, których pierwszy wyraz
     * sam nic nie znaczy.
     */
    private const SUBSTANCE = '/(?:kwas[a-zęy]*\s+\p{L}+|wodorotlenek\s+\p{L}+|soda\s+kaustyczna|ług\s+sodowy|lug\s+sodowy'
        .'|nadtlenek\s+wodoru|podchloryn\s+sodu|aceton[a-zuy]*|toluen[a-zuy]*|ksylen[a-zuy]*|metanol[a-zuy]*'
        .'|etanol[a-zuy]*|amoniak[a-zuy]*|formaldehyd[a-zuy]*|fenol[a-zuy]*|h2so4|hno3|h3po4|naoh|koh|nh3)/iu';

    /** Wzory chemiczne — karty producentów zapisują substancje także skrótem. */
    private const FORMULAS = [
        'kwas siarkowy' => 'h2so4',
        'kwas azotowy' => 'hno3',
        'kwas solny' => 'hcl',
        'kwas chlorowodorowy' => 'hcl',
        'kwas fosforowy' => 'h3po4',
        'kwas fluorowodorowy' => 'hf',
        'wodorotlenek sodu' => 'naoh',
        'soda kaustyczna' => 'naoh',
        'ług sodowy' => 'naoh',
        'lug sodowy' => 'naoh',
        'wodorotlenek potasu' => 'koh',
        'amoniak' => 'nh3',
    ];

    /** Rdzeń nazwy substancji → forma mianownikowa do wyszukiwania w karcie. */
    private const BASE_FORMS = [
        'kwasu' => 'kwas',
        'kwasem' => 'kwas',
        'kwasy' => 'kwas',
        'kwasów' => 'kwas',
        'acetonu' => 'aceton',
        'acetonem' => 'aceton',
        'toluenu' => 'toluen',
        'ksylenu' => 'ksylen',
        'metanolu' => 'metanol',
        'etanolu' => 'etanol',
        'amoniaku' => 'amoniak',
        'formaldehydu' => 'formaldehyd',
        'fenolu' => 'fenol',
    ];

    /**
     * Warunki szczególne z cytatu pozycji.
     *
     * @return list<array{text: string, needles: list<string>, percent: string|null, checkable: bool}>
     */
    public static function fromQuote(string $quote): array
    {
        $line = trim(preg_replace('/\s+/u', ' ', $quote) ?? $quote);
        if ($line === '') {
            return [];
        }

        $out = [];
        $seen = [];
        foreach (self::substances($line) as $requirement) {
            $key = mb_strtolower($requirement['text'], 'UTF-8');
            if (isset($seen[$key])) {
                continue;
            }
            $seen[$key] = true;
            $out[] = $requirement;
        }

        // Klauzula bez znanej substancji zostaje dla człowieka — maszynowo nie
        // ma czego sprawdzić, a warunek z niej i tak bywa najważniejszy.
        foreach (self::clauses($line) as $clause) {
            if (self::substances($clause) !== []) {
                continue;
            }
            $key = mb_strtolower($clause, 'UTF-8');
            if (isset($seen[$key])) {
                continue;
            }
            $seen[$key] = true;
            $out[] = ['text' => $clause, 'needles' => [], 'percent' => null, 'checkable' => false];
        }

        return $out;
    }

    /**
     * Warunki, których karta nie potwierdza — tylko sprawdzalne.
     *
     * @param  list<array<string, mixed>>  $requirements
     * @return list<string>
     */
    public static function unconfirmed(array $requirements, string $cardText): array
    {
        $out = [];
        foreach ($requirements as $requirement) {
            if (($requirement['checkable'] ?? false) !== true) {
                continue;
            }
            if (! self::confirms($requirement, $cardText)) {
                $out[] = (string) $requirement['text'];
            }
        }

        return $out;
    }

    /**
     * Czy karta potwierdza ten warunek. Przy podanym stężeniu nie wystarczy
     * sama nazwa substancji: „kwas siarkowy 78%” nie jest odpowiedzią na
     * „kwas siarkowy 96%”, a karta bez stężenia nie mówi o nim nic.
     *
     * @param  array<string, mixed>  $requirement
     */
    public static function confirms(array $requirement, string $cardText): bool
    {
        $text = self::haystack($cardText);
        if ($text === '') {
            return false;
        }

        $percent = $requirement['percent'] ?? null;
        /** @var list<string> $needles */
        $needles = is_array($requirement['needles'] ?? null) ? $requirement['needles'] : [];

        foreach ($needles as $needle) {
            $at = 0;
            while (($found = mb_stripos($text, $needle, $at)) !== false) {
                if ($percent === null) {
                    return true;
                }
                $window = mb_substr($text, $found, mb_strlen($needle) + self::NEAR);
                if (self::mentionsPercent($window, (string) $percent)) {
                    return true;
                }
                $at = $found + 1;
            }
        }

        return false;
    }

    /**
     * Nazwane substancje w tekście, każda z ewentualnym stężeniem.
     *
     * @return list<array{text: string, needles: list<string>, percent: string|null, checkable: bool}>
     */
    private static function substances(string $line): array
    {
        if (preg_match_all(self::SUBSTANCE, $line, $found, PREG_OFFSET_CAPTURE) !== 1
            && ($found[0] ?? []) === []) {
            return [];
        }

        $out = [];
        foreach ($found[0] as [$raw, $offset]) {
            $name = self::baseForm(trim((string) $raw));
            if ($name === '') {
                continue;
            }
            // Stężenie stoi zwykle zaraz za nazwą („kwas siarkowy 96%”).
            // Przesunięcia z preg_match_all są bajtowe, więc i tu liczymy bajty;
            // szukamy samych cyfr i procenta, więc ucięty ogon nic nie psuje.
            $tail = substr($line, (int) $offset + strlen((string) $raw), self::PERCENT_NEAR);
            $percent = self::percentIn($tail);

            $out[] = [
                'text' => $percent === null ? $name : $name.' '.$percent.'%',
                'needles' => self::needlesFor($name),
                'percent' => $percent,
                'checkable' => true,
            ];
        }

        return $out;
    }

    /**
     * Klauzule po zwrotach typu „w szczególności” — do końca nawiasu, zdania
     * albo wiersza. To one niosą warunek, o który klientowi najbardziej chodzi.
     *
     * @return list<string>
     */
    private static function clauses(string $line): array
    {
        $out = [];
        foreach (self::MARKERS as $marker) {
            $at = 0;
            while (($found = mb_stripos($line, $marker, $at)) !== false) {
                $from = $found + mb_strlen($marker);
                $rest = mb_substr($line, $from);
                $cut = preg_split('/[)\];.]|\s+(?:oraz|albo)\s+/u', $rest) ?: [];
                $clause = trim((string) ($cut[0] ?? ''), " \t,:-–—*");
                // „na” zostaje po samym zwrocie („w szczególności na kwas…”).
                $clause = trim((string) preg_replace('/^na\s+/iu', '', $clause));
                if (mb_strlen($clause) >= 3) {
                    $out[] = $clause;
                }
                $at = $found + 1;
            }
        }

        return $out;
    }

    /**
     * Czego szukać w karcie: nazwa substancji i jej wzór chemiczny.
     *
     * @return list<string>
     */
    private static function needlesFor(string $name): array
    {
        $key = mb_strtolower($name, 'UTF-8');
        $needles = [$key];
        if (isset(self::FORMULAS[$key])) {
            $needles[] = self::FORMULAS[$key];
        }

        return array_values(array_unique($needles));
    }

    /**
     * Forma mianownikowa: karty piszą „kwas siarkowy”, a mail bywa „odporność
     * na kwasu siarkowego 96%”. Rzeczowniki ze słownika, przymiotniki z końcówki.
     */
    private static function baseForm(string $raw): string
    {
        $words = preg_split('/\s+/u', mb_strtolower($raw, 'UTF-8')) ?: [];
        $words = array_map(
            static fn (string $word): string => self::BASE_FORMS[$word] ?? self::baseAdjective($word),
            $words,
        );

        return trim(implode(' ', $words));
    }

    /** „siarkowego”, „siarkowym” → „siarkowy”; „kaustycznej” → „kaustyczna”. */
    private static function baseAdjective(string $word): string
    {
        foreach (['iego' => 'i', 'ego' => 'y', 'emu' => 'y', 'ym' => 'y', 'ej' => 'a'] as $ending => $base) {
            if (mb_strlen($word) > mb_strlen($ending) + 3 && str_ends_with($word, $ending)) {
                return mb_substr($word, 0, mb_strlen($word) - mb_strlen($ending)).$base;
            }
        }

        return $word;
    }

    private static function percentIn(string $text): ?string
    {
        if (preg_match('/(\d{1,3}(?:[.,]\d+)?)\s*%/u', $text, $found) !== 1) {
            return null;
        }

        return str_replace(',', '.', $found[1]);
    }

    /** To samo stężenie w karcie: „96%”, „96 %”, „96,0%”. */
    private static function mentionsPercent(string $window, string $percent): bool
    {
        $wanted = (float) $percent;
        if (preg_match_all('/(\d{1,3}(?:[.,]\d+)?)\s*%/u', $window, $found) < 1) {
            return false;
        }
        foreach ($found[1] as $value) {
            if (abs(((float) str_replace(',', '.', (string) $value)) - $wanted) < 0.01) {
                return true;
            }
        }

        return false;
    }

    private static function haystack(string $cardText): string
    {
        return trim(preg_replace('/\s+/u', ' ', $cardText) ?? $cardText);
    }
}
