<?php

declare(strict_types=1);

namespace App\Services\Bzp;

/**
 * Najważniejsze fragmenty treści ogłoszenia o zamówieniu z Biuletynu (okno szczegółów w zakładce Ogłoszenia).
 *
 * Tekst jest przepisywany SŁOWO W SŁOWO z wierszy ogłoszenia (BzpNoticeParser::lines — bez znaczników i CSS, każdy
 * akapit, punkt i wiersz tabeli w osobnym wierszu), z numeracją punktów jak w ogłoszeniu. Nic nie jest streszczane
 * ani wnioskowane — wybierane są tylko całe sekcje i punkty:
 *  - organization — SEKCJA „ZAMAWIAJĄCY” (nazwa, adres, telefon, e-mail),
 *  - communication — SEKCJA „UDOSTĘPNIANIE DOKUMENTÓW ZAMÓWIENIA I KOMUNIKACJA” bez punktu o RODO (formułka prawna),
 *  - subject — SEKCJA „PRZEDMIOT ZAMÓWIENIA” bez kryteriów oceny ofert (z nagłówkami „Część N”),
 *  - criteria — punkt „Kryteria oceny ofert” z podpunktami (przy częściach poprzedzony nagłówkiem „Część N”),
 *  - execution — punkty „Okres realizacji…”, „Termin realizacji…”, „Termin wykonania…” (kopia z przedmiotu zamówienia,
 *    żeby termin wykonania był widoczny bez czytania całej sekcji),
 *  - qualification — SEKCJA „KWALIFIKACJA WYKONAWCÓW” (warunki udziału, wymagane dokumenty i środki dowodowe),
 *  - deposit — z SEKCJI „WARUNKI ZAMÓWIENIA” tylko punkty o wadium i zabezpieczeniu należytego wykonania umowy,
 *  - deadlines — SEKCJA „PROCEDURA” (termin i miejsce składania ofert, otwarcie, związanie ofertą),
 *  - other — SEKCJA „POZOSTAŁE INFORMACJE”.
 * Sekcje rozpoznawane po tytule (nie po numerze rzymskim), punkty po treści etykiety — jak w BzpNoticeParser.
 * Kolejność wyniku = kolejność w ogłoszeniu (pierwszy wiersz sekcji); puste sekcje są pomijane.
 */
final class NoticeSections
{
    /** @var array<string, string> klucz → tytuł dla ludzi */
    public const TITLES = [
        'organization' => 'Zamawiający',
        'communication' => 'Strona postępowania i komunikacja',
        'subject' => 'Przedmiot zamówienia',
        'criteria' => 'Kryteria oceny ofert',
        'execution' => 'Termin wykonania zamówienia',
        'qualification' => 'Warunki udziału i wymagane dokumenty',
        'deposit' => 'Wadium i zabezpieczenie należytego wykonania umowy',
        'deadlines' => 'Terminy składania i otwarcia ofert',
        'other' => 'Pozostałe informacje',
    ];

    /** tytuł sekcji ogłoszenia (wielkimi literami) → rodzaj; sekcje spoza listy są pomijane */
    private const SECTION_KINDS = [
        'ZAMAWIAJĄCY' => 'organization',
        'UDOSTĘPNIANIE DOKUMENTÓW' => 'communication',
        'PRZEDMIOT ZAMÓWIENIA' => 'subject',
        'KWALIFIKACJA WYKONAWCÓW' => 'qualification',
        'WARUNKI ZAMÓWIENIA' => 'deposit',
        'PROCEDURA' => 'deadlines',
        'POZOSTAŁE INFORMACJE' => 'other',
    ];

    /**
     * @return list<array{key: string, title: string, text: string}>
     */
    public function extract(?string $html): array
    {
        if ($html === null || trim($html) === '') {
            return [];
        }

        /** @var array<string, array{first: int, lines: list<string>, lot: ?string}> $buckets */
        $buckets = [];
        $kind = null;
        $lot = null;
        $inCriteria = false;
        $criteriaPrefix = null;
        $inExecution = false;
        $depositPrefix = null;
        $skipPoint = false;

        $add = static function (string $key, string $line, int $index, ?string $lot) use (&$buckets): void {
            $buckets[$key] ??= ['first' => $index, 'lines' => [], 'lot' => null];
            // przy częściach zamówienia fragment poprzedza nagłówek części, której dotyczy
            if ($lot !== null && $buckets[$key]['lot'] !== $lot && $line !== $lot) {
                $buckets[$key]['lines'][] = $lot;
            }
            if ($lot !== null) {
                $buckets[$key]['lot'] = $lot;
            }
            $buckets[$key]['lines'][] = $line;
        };

        foreach (BzpNoticeParser::lines($html) as $index => $line) {
            if (preg_match('/^SEKCJA\s+[IVX]+\b\s*[-–—:]?\s*(.*)$/u', $line, $m) === 1) {
                $kind = self::sectionKind($m[1]);
                $lot = null;
                $inCriteria = false;
                $inExecution = false;
                $depositPrefix = null;
                $skipPoint = false;

                continue;
            }
            // stopka strony Biuletynu („2026-09-22 Biuletyn Zamówień PublicznychOgłoszenie o zamówieniu - …”)
            if (preg_match('/^\d{4}-\d{2}-\d{2}\s*Biuletyn Zamówień Publicznych/u', $line) === 1 || $kind === null) {
                continue;
            }

            $point = preg_match('/^(\d+(?:\.\d+)+)\.?\)\s*(.*)$/u', $line, $p) === 1 ? ['number' => $p[1], 'label' => $p[2]] : null;

            if ($kind === 'communication') {
                if ($point !== null) {
                    $skipPoint = preg_match('/^RODO\b/iu', $point['label']) === 1;
                }
                if (! $skipPoint) {
                    $add('communication', $line, $index, null);
                }

                continue;
            }

            if ($kind === 'deposit') {
                if ($point !== null) {
                    // podpunkt wybranego punktu (6.4.1 pod 6.4) należy do niego; inny punkt zaczyna od nowa
                    $subPoint = $depositPrefix !== null && str_starts_with($point['number'], $depositPrefix.'.');
                    if (! $subPoint) {
                        $depositPrefix = preg_match('/wadium|zabezpieczeni\w*\s+należytego\s+wykonania/iu', $point['label']) === 1
                            ? $point['number'] : null;
                    }
                }
                if ($depositPrefix !== null) {
                    $add('deposit', $line, $index, null);
                }

                continue;
            }

            if ($kind !== 'subject') {
                $add($kind, $line, $index, null);

                continue;
            }

            // przedmiot zamówienia: części, kryteria oceny, termin wykonania
            if (preg_match('/^Cz[eę][sś][cć]\s+\d{1,3}$/u', $line) === 1) {
                $lot = $line;
                $inCriteria = false;
                $inExecution = false;
                $add('subject', $line, $index, $lot);

                continue;
            }
            if ($point !== null) {
                $inExecution = false;
                if (preg_match('/^Kryteria\s+oceny\s+ofert/iu', $point['label']) === 1) {
                    $inCriteria = true;
                    $criteriaPrefix = $point['number'];
                } elseif ($inCriteria && $criteriaPrefix !== null && ! str_starts_with($point['number'], $criteriaPrefix.'.')) {
                    $inCriteria = false;
                }
                if (! $inCriteria && preg_match('/^(?:Okres\s+realizacji|Termin\s+realizacji|Termin\s+wykonania)/iu', $point['label']) === 1) {
                    $inExecution = true;
                }
            }
            if ($inCriteria) {
                $add('criteria', $line, $index, $lot);

                continue;
            }
            $add('subject', $line, $index, $lot);
            if ($inExecution) {
                $add('execution', $line, $index, $lot);
            }
        }

        uasort($buckets, static fn (array $a, array $b): int => $a['first'] <=> $b['first']);
        $out = [];
        foreach ($buckets as $key => $bucket) {
            $text = trim(implode("\n", $bucket['lines']));
            if ($text === '') {
                continue;
            }
            $out[] = ['key' => (string) $key, 'title' => self::TITLES[$key] ?? (string) $key, 'text' => $text];
        }

        return $out;
    }

    private static function sectionKind(string $title): ?string
    {
        $title = mb_strtoupper(trim($title));
        foreach (self::SECTION_KINDS as $needle => $kind) {
            if (str_contains($title, $needle)) {
                return $kind;
            }
        }

        return null;
    }
}
