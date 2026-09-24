<?php

declare(strict_types=1);

namespace App\Services\Search;

/**
 * Szuka podejrzanych zapisanych zrozumień wymagania. Zrozumienie zapisuje się raz na zawsze, więc błąd modelu
 * zostaje na stałe: 24.09.2026 „spodnie do pasa z polipropylenu” zapisało się jako „spodnie robocze” — model
 * zgubił materiał i dopisał słowo, którego w wymaganiu nie ma.
 *
 * Porównanie jest czysto słownikowe i tylko wskazuje zapisy do obejrzenia przez człowieka: poprawiona literówka
 * albo slang przełożony w samej nazwie wyrobu (wampirki → dzianinowe) też zostaną pokazane, choć są dobre.
 */
final class UnderstandingAudit
{
    /** Krótsze słowa to spójniki, przyimki i skróty — nie niosą treści wymagania. */
    private const MIN_TOKEN_LENGTH = 5;

    /** Tyle początkowych liter musi się zgadzać, by odmiany (polipropylenu ~ polipropylenowe) były tym samym słowem. */
    private const PREFIX_LENGTH = 5;

    /**
     * „Zgubione” liczymy tylko dla krótkich wymagań (wiersz maila, pozycja z nazwą). Długi akapit SIWZ model ma
     * streścić — pierwsza wersja oznaczała 17 z 18 zapisów lokalnie („przetwórstwo spożywcze”, „kolor zielony”),
     * a taka lista nic już nie wskazuje. Dopisane słowo jest podejrzane przy każdej długości wymagania.
     */
    private const DROPPED_MAX_TOKENS = 8;

    /**
     * Słowa bez treści wyrobu (ilości, opakowania, zwroty z SIWZ i maili, ogólniki „ochronne … przed”) — ich brak
     * albo dopisanie nie jest błędem. Wypełniacze dopisane 24.09.2026 po przeglądzie 103 zapisów z serwera
     * („ilość około”, „zestaw … kompletów”, „o wymiarach”, „z funkcją ESD”, „z normą”, „wersja nagłowna”).
     * Zapis bez polskich znaków, bo porównanie idzie po złożeniu diakrytyków.
     */
    private const IGNORED = [
        'sztuk', 'sztuki', 'rozmiar', 'rozmiarze', 'rozmiary', 'rozmiaru', 'opakowanie', 'opakowania',
        'zgodnie', 'wymagania', 'wymaganiami', 'ktore', 'ktora', 'ktory',
        'ponizej', 'powyzej', 'prosze', 'oferte', 'wycene', 'zakup', 'zakupu', 'dostawa', 'dostawy',
        'ilosc', 'okolo', 'zestaw', 'komplet', 'kompletu', 'kompletow', 'wymiarach', 'wymiary',
        'funkcja', 'funkcji', 'norma', 'normy', 'norme', 'wersja', 'wersji', 'szczegolnosci', 'takze', 'wyposazenie',
        'ochrona', 'ochronne', 'ochronny', 'ochronna', 'ochronnych', 'przed',
    ];

    /** Pola odpowiedzi, które sterują wyszukiwaniem. search_phrases celowo pominięte — materiał tylko tam nie pomógł. */
    private const RETRIEVAL_FIELDS = ['needed', 'search_steps', 'constraints', 'model_name', 'manufacturer'];

    /**
     * Nazwa wyrobu w zrozumieniu — dopisane tu słowo zmienia to, czego szukamy („spodnie robocze”, „zapalniczki”).
     * Kroki wyszukiwania pominięte: tam model tłumaczy żargon na słowa katalogu (wampirki → dzianinowe, HRO →
     * podeszwa żaroodporna) i z nimi lista 103 zapisów z serwera miała 60 oznaczeń; sama nazwa i wypełniacze — 33,
     * a każdy z 10 zapisów skasowanych 24.09.2026 jako błędne dalej jest oznaczony.
     */
    private const NAMING_FIELDS = ['needed'];

    private const DIACRITICS = [
        'ą' => 'a', 'ę' => 'e', 'ł' => 'l', 'ó' => 'o', 'ś' => 's', 'ć' => 'c', 'ń' => 'n', 'ź' => 'z', 'ż' => 'z',
    ];

    /**
     * @param  array<string, mixed>  $answer  surowa odpowiedź modelu z kroku „zrozum wymaganie”
     * @return array{dropped: list<string>, added: list<string>}
     */
    public function check(string $requirement, array $answer): array
    {
        $requirementTokens = $this->tokens($requirement);
        $retrievalTokens = $this->tokens($this->fieldsText($answer, self::RETRIEVAL_FIELDS));
        $namingTokens = $this->tokens($this->fieldsText($answer, self::NAMING_FIELDS));

        return [
            'dropped' => count($requirementTokens) <= self::DROPPED_MAX_TOKENS
                ? $this->unmatched($requirementTokens, $retrievalTokens)
                : [],
            'added' => $this->unmatched($namingTokens, $requirementTokens),
        ];
    }

    /**
     * Słowo pasuje, gdy ma ten sam początek co inne albo gdy początek jednego stoi wewnątrz drugiego
     * („rękaw” ~ „narękawnik”, „antyelektrostatyczne” ~ „elektrostatyczne”).
     *
     * @param  list<string>  $tokens
     * @param  list<string>  $against
     * @return list<string>
     */
    private function unmatched(array $tokens, array $against): array
    {
        return array_values(array_filter($tokens, function (string $token) use ($against): bool {
            foreach ($against as $other) {
                if ($this->prefix($token) === $this->prefix($other)
                    || str_contains($other, $this->prefix($token))
                    || str_contains($token, $this->prefix($other))) {
                    return false;
                }
            }

            return true;
        }));
    }

    private function prefix(string $token): string
    {
        return mb_substr($token, 0, self::PREFIX_LENGTH);
    }

    /**
     * @param  array<string, mixed>  $answer
     * @param  list<string>  $fields
     */
    private function fieldsText(array $answer, array $fields): string
    {
        $parts = [];
        foreach ($fields as $field) {
            $value = $answer[$field] ?? null;
            foreach (is_array($value) ? $value : [$value] as $item) {
                if (is_string($item)) {
                    $parts[] = $item;
                }
            }
        }

        return implode(' ', $parts);
    }

    /**
     * Słowa treści: małe litery, bez polskich znaków, co najmniej 5 liter, bez cyfr i bez słów z listy pomijanych.
     * Bez powtórzeń, w kolejności pierwszego wystąpienia.
     *
     * @return list<string>
     */
    private function tokens(string $text): array
    {
        $folded = strtr(mb_strtolower($text), self::DIACRITICS);
        $tokens = [];
        foreach (preg_split('/[^\p{L}\p{N}]+/u', $folded, -1, PREG_SPLIT_NO_EMPTY) ?: [] as $token) {
            if (mb_strlen($token) < self::MIN_TOKEN_LENGTH
                || preg_match('/^\p{L}+$/u', $token) !== 1
                || in_array($token, self::IGNORED, true)) {
                continue;
            }
            $tokens[$token] = true;
        }

        return array_map('strval', array_keys($tokens));
    }
}
