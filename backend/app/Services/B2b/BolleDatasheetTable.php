<?php

declare(strict_types=1);

namespace App\Services\B2b;

use App\Support\NormCode;
use RuntimeException;
use Smalot\PdfParser\Parser;
use Throwable;

/**
 * Tabela wersji z karty technicznej Bolle („Technical Sheet”, PDF rodziny z b2b.bolle-safety.com) — kolumna
 * STANDARD (albo STANDARDS) i LENS MARKING wiersza, którego REFERENCE to kod pozycji sklepu (itemid).
 *
 * Układ (sprawdzony 28.09.2026 na kartach BAXTER, COBRA, FLASH, NESS+, IRI-s, VOLT 2.0, RUSH+ 2.0, TRYON RX,
 * VIENNA): wiersz nagłówka z „REFERENCE” i „STANDARD(S)” oraz innymi kolumnami (USE, VERSION, SIZE, LENS MARKING,
 * FRAME MARKING, COATING), dalej wiersze wersji; za kolumną STANDARD kody EAN opakowań (pierwszy = sztuka).
 * Tekst czytamy z położeniem (smalot getDataTm: x = tm[4], y = tm[5]). Kolumna = pas od połowy odstępu do nagłówka
 * po lewej do połowy odstępu do nagłówka po prawej — teksty komórek są wyśrodkowane i zaczynają się przed
 * nagłówkiem („EN ISO 16321-1 - EN ISO 16321-2” 27 jednostek przed „STANDARD”). Wiersz = linia kodu z kolumny
 * REFERENCE; komórka = najbliższa linia tekstów w pasie kolumny w odległości ±4 jednostek od linii kodu.
 *
 * FRAME MARKING nie czytamy: bywa dwuliniowe („F : EN166 FT” nad, „B : EN166 3 4 5 BT” pod linią kodu), więc
 * przypisanie linii do wiersza byłoby zgadywaniem. Komórka STANDARD z dwiema równie bliskimi liniami = nieczytelna.
 *
 * Wynik parse() to same dane odczytane z PDF (bez decyzji o pozycji) — nadaje się do pamięci podręcznej na plik;
 * decyzję, czy wiersz należy do pozycji i czy normy da się odczytać, podejmuje match().
 *
 * @phpstan-type DatasheetRow array{
 *     page: int,
 *     reference: string,
 *     lens: string|null,
 *     standard: string|null,
 *     ean: string|null,
 *     ambiguous: list<string>,
 *     block: string
 * }
 */
final class BolleDatasheetTable
{
    /** Zmiana odczytu = nowa wersja (klucz pamięci podręcznej odczytów w BolleB2bConnector). */
    public const VERSION = 1;

    /** Etykieta oznaczenia soczewki w parach norm (products.manufacturer_norms.rows). */
    public const LENS_LABEL = 'Oznaczenie soczewki';

    /** Teksty nagłówka tej samej linii różnią się o ułamek jednostki (RUSH+: SIZE 535,9, reszta 535,7). */
    private const HEADER_DY = 2.0;

    /**
     * Kod i jego dopisek w tej samej komórce (NESS+: „NESPSN10E” 392,4 i „S” 393,6 — wersja dla małej głowy).
     * Wiersze tabel są co 11 (RUSH+) albo 17 jednostek.
     */
    private const REFERENCE_DY = 2.0;

    /** Komórki wiersza leżą najwyżej 1 jednostkę od kodu; oznaczenia oprawki z sąsiednich linii — 3–4,5. */
    private const ROW_DY = 4.0;

    /** Teksty jednej linii komórki. */
    private const LINE_GAP = 1.0;

    /** Dwie linie komórki bliżej siebie (względem kodu) niż o tyle = nie wiadomo, która należy do wiersza. */
    private const TIE = 0.75;

    private const MAX_BLOCK = 1000;

    /**
     * Oznaczenia, które stoją w kolumnie STANDARD obok norm, ale normami nie są (NESS+: „EN166 - EN170 - UKCA”).
     */
    private const NON_NORM_MARKINGS = ['UKCA', 'CE'];

    /**
     * Pełne oznaczenie normy w komórce STANDARD — nic poza nim (rok i poprawka dopuszczone). Część komórki, która
     * nie jest w całości oznaczeniem ani znanym znakiem spoza norm, czyni komórkę nieczytelną.
     */
    private const NORM_PATTERN = '/^(?:PN-?\s*)?(?:EN|ISO|IEC)(?:\s*(?:ISO|IEC))?\s*\d{2,6}(?:-\d{1,3})*(?:\s*:\s*(?:19|20)\d{2})?(?:\s*\+\s*A\d{1,2}(?:\s*:\s*(?:19|20)\d{2})?)*$/u';

    /**
     * Wiersze tabel wersji ze wszystkich stron PDF. [] = plik bez takiej tabeli (VIENNA: bez kolumny STANDARD).
     * Nieczytelny PDF = RuntimeException.
     *
     * @return list<DatasheetRow>
     */
    public static function parse(string $bytes): array
    {
        if (! str_starts_with(ltrim(substr($bytes, 0, 1024)), '%PDF-')) {
            throw new RuntimeException('plik karty technicznej nie jest PDF');
        }
        try {
            $pdf = (new Parser)->parseContent($bytes);
            $pages = $pdf->getPages();
        } catch (Throwable $e) {
            throw new RuntimeException('PDF karty technicznej nieczytelny ('.$e->getMessage().')', 0, $e);
        }

        $rows = [];
        foreach (array_values($pages) as $index => $page) {
            try {
                $data = $page->getDataTm();
            } catch (Throwable $e) {
                throw new RuntimeException('strona '.($index + 1).' karty technicznej nieczytelna ('.$e->getMessage().')', 0, $e);
            }
            $items = [];
            foreach ($data as $entry) {
                if (! is_array($entry) || ! is_array($entry[0] ?? null) || ! is_string($entry[1] ?? null)) {
                    continue;
                }
                $text = self::inline($entry[1]);
                if ($text === '' || ! is_numeric($entry[0][4] ?? null) || ! is_numeric($entry[0][5] ?? null)) {
                    continue;
                }
                $items[] = ['x' => (float) $entry[0][4], 'y' => (float) $entry[0][5], 'text' => $text];
            }
            foreach (self::pageRows($items, $index + 1) as $row) {
                $rows[] = $row;
            }
        }

        return $rows;
    }

    /**
     * Normy i oznaczenie soczewki pozycji o kodzie $reference; null = pozycji nie ma w tabeli albo jej wiersza nie
     * da się odczytać bez zgadywania.
     *
     * - Kod: cała komórka REFERENCE równa kodowi (po trim, z rozróżnieniem wielkości liter). Wyjątek: kod
     *   z dopiskiem rozmiaru ze spacją („NESPSN10E S”, „RUSHPSPSIS S” — legenda karty: „S FOR SMALL SIZE”) jest
     *   wierszem pozycji tylko wtedy, gdy EAN wiersza i EAN pozycji są równe (sizeMarkedSameEan). Bez EAN-u
     *   dopisek mógłby oznaczać inny wyrób — wtedy wiersza nie bierzemy. Sam równy EAN przy innym kodzie nie
     *   wystarcza: karty mają literówki w EAN (RUSH+ 2.0: RUSPMN14E z EAN-em RUSPMN10E).
     *   Karta rodziny z samą nazwą rodziny w kolumnie REFERENCE (TRYON RX: „TRYON”) nie ma wiersza pozycji.
     * - EAN: gdy pozycja ma kod kreskowy, a wiersz podaje EAN sztuki — muszą być równe (GTIN, bez zer wiodących);
     *   wiersz bez EAN przechodzi na samym kodzie.
     * - STANDARD: rozbite na oznaczenia; znaki spoza norm (UKCA, CE) pominięte; część, która nie jest w całości
     *   oznaczeniem normy, albo komórka pusta czy niejednoznaczna = null (nic nie zapisujemy zamiast części).
     * - Kilka pasujących wierszy z różnym odczytem = null.
     *
     * @param  list<DatasheetRow>  $rows
     * @return array{norms: list<string>, lens: string|null, row: DatasheetRow}|null
     */
    public static function match(array $rows, string $reference, ?string $ean): ?array
    {
        $reference = trim($reference);
        if ($reference === '') {
            return null;
        }
        $ean = $ean !== null && preg_match('/^\d{8,14}$/', trim($ean)) === 1 ? trim($ean) : null;

        $found = null;
        foreach ($rows as $row) {
            if ($row['reference'] !== $reference && ! self::sizeMarkedSameEan($row, $reference, $ean)) {
                continue;
            }
            if (in_array('ean', $row['ambiguous'], true)) {
                return null;
            }
            if ($ean !== null && $row['ean'] !== null && ! self::sameGtin($ean, $row['ean'])) {
                continue;
            }
            if (in_array('standard', $row['ambiguous'], true)) {
                return null;
            }
            $norms = self::norms((string) $row['standard']);
            if ($norms === null) {
                return null;
            }
            $lens = in_array('lens', $row['ambiguous'], true) ? null : trim((string) $row['lens']);
            // „-” w kolumnie LENS MARKING (KOVER RX, B810 RX) to brak oznaczenia, nie oznaczenie
            $result = ['norms' => $norms, 'lens' => $lens !== '' && $lens !== '-' ? $lens : null, 'row' => $row];
            if ($found !== null && ($found['norms'] !== $result['norms'] || $found['lens'] !== $result['lens'])) {
                return null;
            }
            $found ??= $result;
        }

        return $found;
    }

    /**
     * Komórka REFERENCE = kod pozycji + spacja + dopisek rozmiaru z 1–2 wielkich liter, a EAN wiersza równy EAN-owi
     * pozycji (oba muszą być).
     *
     * @param  DatasheetRow  $row
     */
    private static function sizeMarkedSameEan(array $row, string $reference, ?string $ean): bool
    {
        return $ean !== null
            && $row['ean'] !== null
            && preg_match('/^'.preg_quote($reference, '/').' [A-Z]{1,2}$/', $row['reference']) === 1
            && self::sameGtin($ean, $row['ean']);
    }

    /**
     * Oznaczenia norm z komórki STANDARD, dosłownie (spacje zwinięte do jednej): „EN166 - EN172” → EN166, EN172;
     * „EN ISO 16321-1 - EN ISO 16321-2” → dwie normy. null = komórka pusta albo nieczytelna.
     *
     * @return list<string>|null
     */
    public static function norms(string $cell): ?array
    {
        $cell = self::inline($cell);
        if ($cell === '') {
            return null;
        }
        // Separator: myślnik ze spacją przynajmniej z jednej strony („EN166 -EN170”), półpauza, ukośnik, przecinek,
        // średnik. Myślnik bez spacji należy do numeru („16321-1”).
        $parts = preg_split('/\s*[–\/,;]\s*|\s+-\s*|\s*-\s+/u', $cell) ?: [];

        $norms = [];
        foreach ($parts as $part) {
            $part = trim($part);
            if ($part === '') {
                return null;
            }
            if (in_array(mb_strtoupper($part), self::NON_NORM_MARKINGS, true)) {
                continue;
            }
            if (preg_match(self::NORM_PATTERN, $part) !== 1 || ! NormCode::looksLikeNorm($part)) {
                return null;
            }
            if (! in_array($part, $norms, true)) {
                $norms[] = $part;
            }
        }

        return $norms !== [] ? $norms : null;
    }

    /**
     * @param  list<array{x: float, y: float, text: string}>  $items
     * @return list<DatasheetRow>
     */
    private static function pageRows(array $items, int $page): array
    {
        $anchors = array_values(array_filter($items, static fn (array $i): bool => $i['text'] === 'REFERENCE'));
        $footers = array_values(array_filter(
            $items,
            static fn (array $i): bool => str_contains(mb_strtoupper($i['text']), 'APPROVAL CERTIFICATES'),
        ));

        $rows = [];
        foreach ($anchors as $anchor) {
            $header = array_values(array_filter($items, static fn (array $i): bool => abs($i['y'] - $anchor['y']) <= self::HEADER_DY));
            usort($header, static fn (array $a, array $b): int => $a['x'] <=> $b['x']);
            $bands = self::bands($header);
            $refBand = null;
            $stdBand = null;
            $lensBand = null;
            foreach ($header as $position => $column) {
                if ($column === $anchor) {
                    $refBand = $bands[$position];
                } elseif ($stdBand === null && in_array($column['text'], ['STANDARD', 'STANDARDS'], true)) {
                    $stdBand = $bands[$position];
                } elseif ($lensBand === null && $column['text'] === 'LENS MARKING') {
                    $lensBand = $bands[$position];
                }
            }
            if ($refBand === null || $stdBand === null) {
                continue;
            }

            // Tabela kończy się na następnym nagłówku z REFERENCE (akcesoria) albo na stopce o certyfikatach.
            $bottom = -INF;
            foreach ([...$anchors, ...$footers] as $end) {
                if ($end['y'] < $anchor['y'] - self::HEADER_DY && $end['y'] > $bottom) {
                    $bottom = $end['y'];
                }
            }
            $body = array_values(array_filter(
                $items,
                static fn (array $i): bool => $i['y'] < $anchor['y'] - self::HEADER_DY && $i['y'] > $bottom,
            ));

            $headerText = implode(' | ', array_column($header, 'text'));
            $inReference = array_values(array_filter($body, static fn (array $i): bool => self::inBand($i, $refBand)));
            foreach (self::lines($inReference, self::REFERENCE_DY) as $line) {
                $rows[] = self::row($line, $body, $page, $headerText, $lensBand, $stdBand);
            }
        }

        return $rows;
    }

    /**
     * @param  list<array{x: float, y: float, text: string}>  $referenceLine
     * @param  list<array{x: float, y: float, text: string}>  $body
     * @param  array{0: float, 1: float}|null  $lensBand
     * @param  array{0: float, 1: float}  $stdBand
     * @return DatasheetRow
     */
    private static function row(array $referenceLine, array $body, int $page, string $headerText, ?array $lensBand, array $stdBand): array
    {
        $y = self::meanY($referenceLine);
        $ambiguous = [];

        // EAN sztuki: pierwszy kod z samych cyfr na prawo od początku kolumny STANDARD, w najbliższej linii.
        $eanItems = array_values(array_filter(
            $body,
            static fn (array $i): bool => $i['x'] >= $stdBand[0] && preg_match('/^\d{8,14}$/', $i['text']) === 1,
        ));
        [$eanLine, $eanTie] = self::nearestLine($eanItems, $y);
        if ($eanTie) {
            $ambiguous[] = 'ean';
        }
        $ean = $eanLine !== [] ? $eanLine[0]['text'] : null;
        $stdRight = $eanLine !== [] ? min($stdBand[1], $eanLine[0]['x']) : $stdBand[1];

        [$stdLine, $stdTie] = self::nearestLine(
            array_values(array_filter($body, static fn (array $i): bool => self::inBand($i, [$stdBand[0], $stdRight]))),
            $y,
        );
        if ($stdTie) {
            $ambiguous[] = 'standard';
        }

        $lensLine = [];
        if ($lensBand !== null) {
            [$lensLine, $lensTie] = self::nearestLine(
                array_values(array_filter($body, static fn (array $i): bool => self::inBand($i, $lensBand))),
                $y,
            );
            if ($lensTie) {
                $ambiguous[] = 'lens';
            }
        }

        // Dosłowny wiersz do źródła odczytu: teksty linii kodu i komórki, z których wzięliśmy wartości.
        $blockItems = array_values(array_filter($body, static fn (array $i): bool => abs($i['y'] - $y) <= self::REFERENCE_DY));
        foreach ([...$stdLine, ...array_slice($eanLine, 0, 1)] as $item) {
            if (! in_array($item, $blockItems, true)) {
                $blockItems[] = $item;
            }
        }
        usort($blockItems, static fn (array $a, array $b): int => $a['x'] <=> $b['x']);

        return [
            'page' => $page,
            'reference' => self::joined($referenceLine),
            'lens' => $lensLine !== [] ? self::joined($lensLine) : null,
            'standard' => $stdLine !== [] ? self::joined($stdLine) : null,
            'ean' => $ean,
            'ambiguous' => $ambiguous,
            'block' => mb_substr($headerText."\n".implode(' | ', array_column($blockItems, 'text')), 0, self::MAX_BLOCK),
        ];
    }

    /**
     * Pasy kolumn nagłówka: od połowy odstępu do sąsiada z lewej do połowy odstępu do sąsiada z prawej.
     *
     * @param  list<array{x: float, y: float, text: string}>  $header  posortowany po x
     * @return list<array{0: float, 1: float}>
     */
    private static function bands(array $header): array
    {
        $bands = [];
        $count = count($header);
        for ($i = 0; $i < $count; $i++) {
            $left = $i > 0 ? ($header[$i - 1]['x'] + $header[$i]['x']) / 2 : -INF;
            $right = $i < $count - 1 ? ($header[$i]['x'] + $header[$i + 1]['x']) / 2 : INF;
            $bands[] = [$left, $right];
        }

        return $bands;
    }

    /**
     * @param  array{x: float, y: float, text: string}  $item
     * @param  array{0: float, 1: float}  $band
     */
    private static function inBand(array $item, array $band): bool
    {
        return $item['x'] >= $band[0] && $item['x'] < $band[1];
    }

    /**
     * Najbliższa linia tekstów w odległości ROW_DY od $y. Drugi wynik: remis (dwie linie równie blisko) — wtedy
     * linia jest pusta.
     *
     * @param  list<array{x: float, y: float, text: string}>  $items
     * @return array{0: list<array{x: float, y: float, text: string}>, 1: bool}
     */
    private static function nearestLine(array $items, float $y): array
    {
        $near = array_values(array_filter($items, static fn (array $i): bool => abs($i['y'] - $y) <= self::ROW_DY));
        $lines = self::lines($near, self::LINE_GAP);
        if ($lines === []) {
            return [[], false];
        }
        usort($lines, static fn (array $a, array $b): int => abs(self::meanY($a) - $y) <=> abs(self::meanY($b) - $y));
        if (count($lines) > 1 && abs(self::meanY($lines[1]) - $y) - abs(self::meanY($lines[0]) - $y) < self::TIE) {
            return [[], true];
        }

        return [$lines[0], false];
    }

    /**
     * Teksty pogrupowane w linie (od góry strony), w linii posortowane po x.
     *
     * @param  list<array{x: float, y: float, text: string}>  $items
     * @return list<list<array{x: float, y: float, text: string}>>
     */
    private static function lines(array $items, float $gap): array
    {
        usort($items, static fn (array $a, array $b): int => $b['y'] <=> $a['y']);
        $lines = [];
        $current = [];
        foreach ($items as $item) {
            if ($current !== [] && $current[0]['y'] - $item['y'] > $gap) {
                $lines[] = $current;
                $current = [];
            }
            $current[] = $item;
        }
        if ($current !== []) {
            $lines[] = $current;
        }

        foreach ($lines as &$line) {
            usort($line, static fn (array $a, array $b): int => $a['x'] <=> $b['x']);
        }
        unset($line);

        return $lines;
    }

    /**
     * @param  list<array{x: float, y: float, text: string}>  $line
     */
    private static function meanY(array $line): float
    {
        return array_sum(array_column($line, 'y')) / max(1, count($line));
    }

    /**
     * @param  list<array{x: float, y: float, text: string}>  $line
     */
    private static function joined(array $line): string
    {
        return self::inline(implode(' ', array_column($line, 'text')));
    }

    private static function inline(string $text): string
    {
        // Tekst spoza UTF-8 (preg z /u zwraca null) — zwijamy same białe znaki ASCII, zamiast gubić tekst.
        return trim((string) (preg_replace('/[\s\x{00A0}]+/u', ' ', $text) ?? preg_replace('/\s+/', ' ', $text)));
    }

    /** Ten sam GTIN: EAN-13 i UPC-A tego samego wyrobu różnią się tylko zerem wiodącym. */
    private static function sameGtin(string $a, string $b): bool
    {
        return ltrim($a, '0') === ltrim($b, '0');
    }
}
