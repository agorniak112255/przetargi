<?php

declare(strict_types=1);

namespace App\Support;

/**
 * Kanoniczny klucz oznaczenia normy (EN / PN-EN / EN ISO) i deduplikacja list norm.
 *
 * PO CO: listy norm produktu powstają ze scalenia kilku źródeł zwykłym `array_unique`,
 * więc „EN 388”, „EN 388:2016” i „EN388:2016+A1:2018” zostają na karcie jako trzy osobne
 * pozycje (zgłoszenie testerki: „w podpunkcie NORMY są podwójnie podane wszystkie normy”).
 * Klucz z tej klasy pozwala takie zapisy porównać, a `dedupe()` zwija je do JEDNEGO —
 * najbogatszego w informacje — zapisu w brzmieniu źródła.
 *
 * Czego klucz świadomie NIE skleja, bo w przetargu to naprawdę różne wymagania:
 * części normy (EN 374-1 ≠ EN 374-5 ≠ EN 374), człon ISO (EN ISO 20345 ≠ EN 20345,
 * EN ISO 13688 zostaje z ISO) oraz typ wyrobu (EN 374-1 Typ A ≠ EN 374-1 Typ B).
 *
 * Poza kluczem zostają: prefiks krajowy (PN-EN, DIN EN, BS EN), spacja lub jej brak,
 * rok wydania, poprawka (+A1:2018), wielkość liter, rodzaj myślnika i kropka na końcu —
 * a także poziomy ochrony (EN 388 4X42C to wciąż EN 388), które zamiast dzielić klucz
 * decydują o tym, który wariant `dedupe()` zostawia.
 */
final class NormCode
{
    /** Prefiksy krajowych wdrożeń normy europejskiej — nie zmieniają jej tożsamości. */
    private const PREFIKSY_KRAJOWE = 'PN|DIN|BS|SS|NF|UNI|UNE|NEN|SFS|CSN|STN|ÖNORM|ONORM';

    /** Token, od którego zaczyna się INNE oznaczenie — nigdy nie jest poziomem ochrony. */
    private const TOKENY_RODZIN = '/^(?:EN|ISO|IEC|PN|DIN|BS|SS|NF|UNI|UNE|NEN|SFS|CSN|STN)$/i';

    /** Dłuższy tekst z normą w środku to zdanie o normie, nie jej oznaczenie. */
    private const MAX_DLUGOSC = 60;

    /** Klucz tożsamości normy — do porównań i deduplikacji. Pusty string, gdy to nie jest norma. */
    public static function key(string $raw): string
    {
        $parsed = self::parse($raw);

        return $parsed === null ? '' : $parsed['key'];
    }

    /** Czy tekst w ogóle wygląda na oznaczenie normy. */
    public static function looksLikeNorm(string $raw): bool
    {
        return self::parse($raw) !== null;
    }

    /**
     * Rodzina i numer normy, o KTÓREJ mówi ten zapis — z jego początku, bez roku, poprawki i poziomów:
     * „EN 388:2016 + A1:2018 4331B” → „EN 388”, „EN ISO 374-1 Typ A” → „EN ISO 374-1”. Pusty string,
     * gdy zapis nie zaczyna się oznaczeniem normy.
     *
     * W odróżnieniu od key() działa też na zapisach, których nie da się rozebrać do końca: opisowych
     * („EN 388:2016 – 4121A (ścieranie 4, przecięcie Coup 1…)”) i z poziomem w nawiasie („EN 388:2016 (4121A)”).
     * Dzięki temu da się poznać, że dwa źródła mówią o tej samej normie, nawet jeśli jedno z nich pisze o niej
     * zdaniem — a przy sprzecznych poziomach trzeba wiedzieć, które zapisy dotyczą tej samej normy.
     */
    public static function leadFamily(string $raw): string
    {
        $text = self::tidy($raw);
        if ($text === '') {
            return '';
        }
        if (preg_match(
            '/^(?:(?:'.self::PREFIKSY_KRAJOWE.')[\s\-]+)?((?:EN|ISO|IEC)(?:[\s\-]*(?:ISO|IEC))*)[\s\-]*(\d{2,6}(?:-\d{1,3})*)(?![\d\-])/iu',
            $text,
            $m
        ) !== 1) {
            return '';
        }

        return mb_strtoupper((string) preg_replace('/[\s\-]+/u', ' ', $m[1])).' '.$m[2];
    }

    /**
     * Deduplikacja listy: pozycje o tym samym kluczu zwijamy do JEDNEJ — najbogatszej
     * w informacje. Kolejność pierwszego wystąpienia zachowana. Pozycje niebędące
     * normami przechodzą bez zmian (dedup po zwykłym porównaniu tekstu).
     *
     * Bogatszy jest wariant z poziomami ochrony („EN 388 4X42C” bije „EN 388”), a przy
     * remisie ten z rokiem i poprawką („EN 388:2016+A1:2018” bije „EN 388”). Zostaje
     * zapis źródła, nie sklejka — nie dopisujemy roku do wariantu, który go nie miał.
     *
     * Gdy dwa warianty tej samej normy niosą RÓŻNE poziomy („EN 388 4X42C” i „EN 388 3121X”,
     * czyli żaden zestaw nie zawiera drugiego), to sprzeczność źródeł — zostawiamy OBA i nie
     * zgadujemy, który opisuje ten produkt. Zestaw węższy zawarty w szerszym („S1” wobec
     * „S1 SRC”) sprzecznością nie jest: zwijamy go do wariantu szerszego, bo ten niesie
     * wszystko, co węższy.
     *
     * @param  list<string>  $items
     * @return list<string>
     */
    public static function dedupe(array $items): array
    {
        $out = [];
        /** @var array<string, list<array{pos: int, parsed: array{key: string, type: ?string, year: ?string, amendment: ?string, levels: list<string>}}>> $warianty */
        $warianty = [];
        /** @var array<string, true> $teksty */
        $teksty = [];

        foreach ($items as $item) {
            $text = trim($item);
            if ($text === '') {
                continue;
            }
            $parsed = self::parse($text);
            if ($parsed === null) {
                $id = (string) preg_replace('/\s+/u', ' ', $text);
                if (isset($teksty[$id])) {
                    continue;
                }
                $teksty[$id] = true;
                $out[] = $text;

                continue;
            }
            $slot = null;
            foreach ($warianty[$parsed['key']] ?? [] as $i => $wariant) {
                if (self::zgodnePoziomy($wariant['parsed']['levels'], $parsed['levels'])) {
                    $slot = $i;
                    break;
                }
            }
            if ($slot === null) {
                $out[] = $text;
                $warianty[$parsed['key']][] = ['pos' => count($out) - 1, 'parsed' => $parsed];

                continue;
            }
            if (self::bogatszy($parsed, $warianty[$parsed['key']][$slot]['parsed'])) {
                $out[$warianty[$parsed['key']][$slot]['pos']] = $text;
                $warianty[$parsed['key']][$slot]['parsed'] = $parsed;
            }
        }

        return array_values($out);
    }

    /**
     * Rozbiór oznaczenia na rodzinę, numer, typ, rok, poprawkę i poziomy.
     * `null`, gdy tekst nie jest oznaczeniem normy — wtedy klucz byłby mylący.
     *
     * @return array{key: string, type: ?string, year: ?string, amendment: ?string, levels: list<string>}|null
     */
    private static function parse(string $raw): ?array
    {
        $text = self::tidy($raw);
        if ($text === '' || mb_strlen($text) > self::MAX_DLUGOSC) {
            return null;
        }
        if (preg_match(
            '/^(?:(?:'.self::PREFIKSY_KRAJOWE.')[\s\-]+)?((?:EN|ISO|IEC)(?:[\s\-]*(?:ISO|IEC))*)[\s\-]*(\d{2,6}(?:-\d{1,3})*)(.*)$/iu',
            $text,
            $m
        ) !== 1) {
            return null;
        }
        $ogon = self::parseOgon($m[3]);
        if ($ogon === null) {
            return null;
        }
        $rodzina = mb_strtoupper((string) preg_replace('/[\s\-]+/u', ' ', $m[1]));

        return [
            'key' => $rodzina.' '.$m[2].($ogon['type'] !== null ? ' TYP '.$ogon['type'] : ''),
            'type' => $ogon['type'],
            'year' => $ogon['year'],
            'amendment' => $ogon['amendment'],
            'levels' => $ogon['levels'],
        ];
    }

    /** Zapisy różniące się tylko zapisem znaków sprowadzamy do jednej postaci przed rozbiorem. */
    private static function tidy(string $raw): string
    {
        $text = str_replace(["\u{00A0}", '–', '—', '−', '/'], [' ', '-', '-', '-', ' '], $raw);
        $text = trim((string) preg_replace('/\s+/u', ' ', $text));
        // myślnik lub punktor listy na początku wiersza
        $text = (string) preg_replace('/^[-*•]\s*/u', '', $text);

        return trim((string) preg_replace('/[.,;:]+$/u', '', $text));
    }

    /**
     * Ogon oznaczenia: rok, poprawka, typ, poziomy. `null`, gdy trafi się cokolwiek innego —
     * „EN 388, EN 374” czy „EN 388 dla monterów” muszą zostać tekstem, bo klucz „EN 388”
     * zgubiłby drugą normę albo treść zdania.
     *
     * @return array{type: ?string, year: ?string, amendment: ?string, levels: list<string>}|null
     */
    private static function parseOgon(string $rest): ?array
    {
        $type = null;
        $year = null;
        $amendment = null;
        $levels = [];
        $rest = trim($rest);

        while ($rest !== '') {
            if (preg_match('/^\+\s*A(\d{1,2})(?:\s*:\s*((?:19|20)\d{2}))?/i', $rest, $m) === 1) {
                $amendment = 'A'.$m[1].(($m[2] ?? '') !== '' ? ':'.$m[2] : '');
            } elseif (preg_match('/^:\s*((?:19|20)\d{2})(?:-\d{2})?(?![0-9])/', $rest, $m) === 1) {
                // „PN-EN 388:2017-02” — miesiąc wydania arkusza to wciąż to samo wydanie
                $year ??= $m[1];
            } elseif (preg_match('/^TYP(?:E|U)?\.?\s*([A-Za-z0-9]{1,3})(?![A-Za-z0-9])/i', $rest, $m) === 1) {
                $type = mb_strtoupper($m[1]);
            } elseif (preg_match('/^(?:KLASA|KL\.|CLASS)\s*(?=[A-Za-z0-9])/i', $rest, $m) === 1) {
                // sama etykieta „klasa” — jej wartość dopisze się niżej jako poziom
            } elseif (preg_match('/^((?:19|20)\d{2})(?![A-Za-z0-9])/', $rest, $m) === 1) {
                $year ??= $m[1];
            } elseif (preg_match('/^(-\s*)?([A-Za-z0-9]{1,8})(?![A-Za-z0-9])/', $rest, $m) === 1
                && self::poziom($m[2], $m[1] !== '')) {
                $levels[] = mb_strtoupper($m[2]);
            } else {
                return null;
            }
            $rest = ltrim(substr($rest, strlen($m[0])));
        }

        return [
            'type' => $type,
            'year' => $year,
            'amendment' => $amendment,
            'levels' => array_values(array_unique($levels)),
        ];
    }

    /** Czy token to poziom/klasa ochrony przy normie („4X42C”, „S1”, „SRC”, „FFP2”, „NR”). */
    private static function poziom(string $token, bool $poMyslniku): bool
    {
        if (preg_match(self::TOKENY_RODZIN, $token) === 1) {
            return false;
        }
        if (preg_match('/\d/', $token) !== 1) {
            // literowe oznaczenia są krótkie i wielkimi literami (SRC, NR, WR, CI, D);
            // „dla”, „oraz”, „Typu” to już słowa zdania
            return mb_strlen($token) <= 3 && $token === mb_strtoupper($token);
        }

        // „EN 374 - 1” to raczej część normy niż poziom — nie zgadujemy, oddajemy jako tekst
        return ! ($poMyslniku && preg_match('/^\d{1,3}$/', $token) === 1);
    }

    /**
     * Czy warianty można zwinąć: jeden zestaw poziomów zawiera drugi (pusty zawiera się w każdym).
     * Rozłączne zestawy to sprzeczność źródeł — zostają osobno.
     *
     * @param  list<string>  $a
     * @param  list<string>  $b
     */
    private static function zgodnePoziomy(array $a, array $b): bool
    {
        return array_diff($a, $b) === [] || array_diff($b, $a) === [];
    }

    /**
     * @param  array{key: string, type: ?string, year: ?string, amendment: ?string, levels: list<string>}  $kandydat
     * @param  array{key: string, type: ?string, year: ?string, amendment: ?string, levels: list<string>}  $obecny
     */
    private static function bogatszy(array $kandydat, array $obecny): bool
    {
        if (count($kandydat['levels']) !== count($obecny['levels'])) {
            return count($kandydat['levels']) > count($obecny['levels']);
        }

        return self::wagaWydania($kandydat) > self::wagaWydania($obecny);
    }

    /**
     * @param  array{key: string, type: ?string, year: ?string, amendment: ?string, levels: list<string>}  $parsed
     */
    private static function wagaWydania(array $parsed): int
    {
        return ($parsed['amendment'] !== null ? 2 : 0) + ($parsed['year'] !== null ? 1 : 0);
    }
}
