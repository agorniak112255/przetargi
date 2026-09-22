<?php

declare(strict_types=1);

namespace App\Services\B2b;

use Carbon\CarbonInterface;
use PhpOffice\PhpSpreadsheet\IOFactory;
use RuntimeException;

/**
 * Cennik bazowy UVEX (xlsx „Cennik do pobrania” ze strony startowej panelu) i dopasowanie do niego kart sklepu.
 * Układ sprawdzony na pliku z 22.09.2026: arkusz na grupę wyrobów, wiersz 1 = nagłówek, kolumna A = kod, cena
 * w kolumnie z „KATALOG” w nagłówku (C, w „Buty Heckel” D), nazwa wiersza = komórki między kodem a ceną (B, w „Buty
 * Heckel” B seria + C model). Ceny bywają długimi ułamkami (916,8525000000001) — zaokrąglamy do groszy.
 *
 * Kod sklepu ↔ kod cennika (sprawdzone na koncie 22.09.2026), zawsze najpierw dokładnie (bez znaków innych niż litery
 * i cyfry, bez wielkości liter: „9705.014” ↔ 9705014), a dopiero bez trafienia regułą właściwą dla postaci kodu:
 * - buty „NNNN/D/rozmiar” ↔ „NNNND”, tylko w arkuszach „Buty…”; Heckel „HECKEL6273/3/37” ↔ „62733” (bez „HECKEL”);
 * - rękawice z dwucyfrowym rozmiarem na końcu „6002306” ↔ „60023”, tylko w arkuszach „Rękawice…”;
 * - HexArmor „HA2039(L)” — wiersze cennika mają własne kody 60xxx, więc tylko po oznaczeniu modelu w nazwie wiersza
 *   („HexArmor Rig Lizard® 2039”), jako całe słowo; bez trafienia i dla modelu cyfry+litery („3041NSR”) jeszcze raz
 *   po samych cyfrach.
 * Wszystko niejednoznaczne (kilka wierszy, ten sam kod z różnymi cenami) = brak dopasowania — cena bazowa
 * cudzego wiersza dałaby fałszywą „cenę specjalną”.
 *
 * Arkusze pominięte (EXCLUDED_SHEETS) nie są wczytywane wcale — reguła rabatu „dowolna” też ich nie dosięgnie.
 */
final class UvexBasePriceList
{
    /**
     * „Odzież” — decyzja użytkownika 22.09.2026: ceny z sezonu 23/24, nieaktualne. „Ogólne” — pusty arkusz.
     */
    public const EXCLUDED_SHEETS = ['Ogólne', 'Odzież'];

    /** Tyle przykładów kart z niejednoznacznym wierszem trafia do podsumowania przebiegu. */
    private const CONFLICT_EXAMPLES = 5;

    /**
     * @var array<string, list<array{sheet: string, code: string, name: string, price: float}>> kod znormalizowany → wiersze
     */
    private array $byCode = [];

    /** @var list<array{sheet: string, code: string, name: string, price: float}> */
    private array $rows = [];

    /** @var list<string> arkusze wczytane */
    private array $sheets = [];

    /** @var list<string> arkusze pominięte z powodem (poza EXCLUDED_SHEETS) */
    private array $skippedSheets = [];

    private int $matched = 0;

    private int $partial = 0;

    private int $missing = 0;

    private int $conflicts = 0;

    /** @var list<string> */
    private array $conflictExamples = [];

    /**
     * @param  list<array{sheet: string, code: string, name: string, price: float}>  $rows
     * @param  list<string>  $sheets
     * @param  list<string>  $skippedSheets
     */
    private function __construct(
        private readonly string $fileName,
        private readonly CarbonInterface $fetchedAt,
        array $rows,
        array $sheets,
        array $skippedSheets,
    ) {
        $this->rows = $rows;
        $this->sheets = $sheets;
        $this->skippedSheets = $skippedSheets;
        foreach ($rows as $row) {
            $this->byCode[self::key($row['code'])][] = $row;
        }
    }

    /**
     * Wczytanie pliku. Plik bez żadnego wiersza z ceną = wyjątek: pusty cennik wyzerowałby ceny bazowe wszystkich
     * kart, a to prawie na pewno zmiana układu pliku, nie wycofanie cennika.
     */
    public static function fromXlsx(string $bytes, string $fileName, CarbonInterface $fetchedAt): self
    {
        $path = tempnam(sys_get_temp_dir(), 'uvex-cennik-');
        if ($path === false) {
            throw new RuntimeException('nie udało się utworzyć pliku tymczasowego cennika');
        }
        $excluded = array_map('mb_strtolower', self::EXCLUDED_SHEETS);
        $rows = [];
        $sheets = [];
        $skipped = [];
        try {
            file_put_contents($path, $bytes);
            $reader = IOFactory::createReader('Xlsx');
            $reader->setReadDataOnly(true);
            foreach ($reader->load($path)->getAllSheets() as $sheet) {
                $title = self::clean($sheet->getTitle());
                if (in_array(mb_strtolower($title), $excluded, true)) {
                    continue;
                }
                $sheetRows = self::sheetRows($title, $sheet->toArray(null, true, false, false));
                if ($sheetRows === null) {
                    $skipped[] = $title.' (brak kolumny „KATALOG” w nagłówku)';

                    continue;
                }
                $sheets[] = $title;
                array_push($rows, ...$sheetRows);
            }
        } finally {
            @unlink($path);
        }

        if ($rows === []) {
            throw new RuntimeException('cennik '.$fileName.' bez żadnego wiersza z kodem i ceną (zmieniony układ pliku?)');
        }

        return new self($fileName, $fetchedAt, $rows, $sheets, $skipped);
    }

    /**
     * Wiersz cennika dla kodów karty (rozmiary jednej karty). Każdy kod, który trafia, musi trafiać w ten sam
     * wiersz — inaczej karta łączyłaby wyroby o różnych cenach bazowych (konflikt, brak dopasowania). Kody bez
     * trafienia obok trafionych są dozwolone (karta liczona jako częściowa). Liczniki idą do podsumowania.
     *
     * @param  list<string>  $codes
     * @return array{sheet: string, code: string, name: string, price: float}|null
     */
    public function match(array $codes): ?array
    {
        $found = null;
        $unmatched = 0;
        $conflict = false;
        foreach (array_values(array_unique($codes)) as $code) {
            $result = $this->matchCode($code);
            if ($result === null) {
                $unmatched++;

                continue;
            }
            if ($result === false || ($found !== null && $found !== $result)) {
                $conflict = true;
                break;
            }
            $found = $result;
        }

        if ($conflict) {
            $this->conflicts++;
            if (count($this->conflictExamples) < self::CONFLICT_EXAMPLES) {
                $this->conflictExamples[] = (string) ($codes[0] ?? '');
            }

            return null;
        }
        if ($found === null) {
            $this->missing++;

            return null;
        }
        $this->matched++;
        if ($unmatched > 0) {
            $this->partial++;
        }

        return $found;
    }

    /**
     * Skąd cena bazowa — do slotu konta (base_price_source, do 255 znaków).
     *
     * @param  array{sheet: string, code: string, name: string, price: float}  $row
     */
    public function sourceOf(array $row): string
    {
        return mb_substr(
            $this->fileName.' · arkusz '.$row['sheet'].' · '.$row['name'].' · pobrano '.$this->fetchedAt->format('Y-m-d'),
            0,
            255,
        );
    }

    public function fileName(): string
    {
        return $this->fileName;
    }

    public function rowCount(): int
    {
        return count($this->rows);
    }

    /** @return list<string> */
    public function sheets(): array
    {
        return $this->sheets;
    }

    /** @return list<string> */
    public function skippedSheets(): array
    {
        return $this->skippedSheets;
    }

    /**
     * @return array{matched: int, partial: int, missing: int, conflicts: int, conflict_examples: list<string>}
     */
    public function counters(): array
    {
        return [
            'matched' => $this->matched,
            'partial' => $this->partial,
            'missing' => $this->missing,
            'conflicts' => $this->conflicts,
            'conflict_examples' => $this->conflictExamples,
        ];
    }

    /**
     * Wiersz dla jednego kodu sklepu: null = brak w cenniku, false = niejednoznaczny (kilka wierszy).
     *
     * @return array{sheet: string, code: string, name: string, price: float}|false|null
     */
    private function matchCode(string $code): array|false|null
    {
        $code = trim($code);
        if ($code === '') {
            return null;
        }

        $exact = self::single($this->byCode[self::key($code)] ?? []);
        if ($exact !== null) {
            return $exact;
        }

        // Heckel: „HECKEL6273/3/37” — dalej jak zwykły but
        $shoe = (string) preg_replace('/^heckel\s*/i', '', $code);
        if (preg_match('#^(\d{4})/(\d)/[^/]+$#', $shoe, $m) === 1) {
            return self::single($this->inSheets($m[1].$m[2], static fn (string $sheet): bool => str_starts_with($sheet, 'buty')));
        }

        // rękawice: dwie ostatnie cyfry to rozmiar; kody rękawic w cenniku mają co najmniej 5 cyfr (60023) —
        // krótszy rdzeń trafiałby przypadkiem w cudze wiersze
        if (preg_match('/^(\d{5,})\d{2}$/', $code, $m) === 1) {
            return self::single($this->inSheets($m[1], static fn (string $sheet): bool => str_starts_with($sheet, 'rękawice')));
        }

        if (preg_match('/^HA/i', $code) === 1) {
            return $this->hexArmor($code);
        }

        return null;
    }

    /**
     * HexArmor po oznaczeniu modelu w nazwie wiersza: kod bez „HA”, bez rozmiaru „(L)” / „/8” i bez spacji.
     *
     * @return array{sheet: string, code: string, name: string, price: float}|false|null
     */
    private function hexArmor(string $code): array|false|null
    {
        $model = (string) preg_replace('/^HA/i', '', $code);
        $model = (string) preg_replace('/\([^)]*\)\s*$/', '', $model);
        $model = (string) preg_replace('#/[^/]*$#', '', $model);
        $model = (string) preg_replace('/\s+/u', '', $model);
        if ($model === '') {
            return null;
        }

        $found = $this->byModel($model);
        if ($found === [] && preg_match('/^(\d+)\p{L}+$/u', $model, $m) === 1) {
            $found = $this->byModel($m[1]);
        }

        return self::single($found);
    }

    /**
     * @return list<array{sheet: string, code: string, name: string, price: float}>
     */
    private function byModel(string $model): array
    {
        $pattern = '/(?<![\p{L}\p{N}])'.preg_quote($model, '/').'(?![\p{L}\p{N}])/iu';
        $found = [];
        foreach ($this->rows as $row) {
            if (! str_contains(mb_strtolower($row['sheet']), 'hexarmor')) {
                continue;
            }
            $name = str_replace(['®', '™'], ' ', $row['name']);
            if (preg_match($pattern, $name) === 1) {
                $found[] = $row;
            }
        }

        return $found;
    }

    /**
     * @param  callable(string): bool  $sheetFits  nazwa arkusza małymi literami
     * @return list<array{sheet: string, code: string, name: string, price: float}>
     */
    private function inSheets(string $code, callable $sheetFits): array
    {
        return array_values(array_filter(
            $this->byCode[self::key($code)] ?? [],
            static fn (array $row): bool => $sheetFits(mb_strtolower($row['sheet'])),
        ));
    }

    /**
     * Jeden wiersz; ten sam kod kilka razy z tą samą ceną w tym samym arkuszu to ten sam wiersz powtórzony.
     * Różne ceny albo różne arkusze (inna kategoria = inny rabat standardowy) = niejednoznaczne (false).
     *
     * @param  list<array{sheet: string, code: string, name: string, price: float}>  $rows
     * @return array{sheet: string, code: string, name: string, price: float}|false|null
     */
    private static function single(array $rows): array|false|null
    {
        if ($rows === []) {
            return null;
        }
        foreach ($rows as $row) {
            if ($row['price'] !== $rows[0]['price'] || $row['sheet'] !== $rows[0]['sheet'] || self::key($row['code']) !== self::key($rows[0]['code'])) {
                return false;
            }
        }

        return $rows[0];
    }

    /**
     * Wiersze arkusza z ceną; null = nagłówek bez kolumny „KATALOG”.
     *
     * @param  array<int, array<int, mixed>>  $cells
     * @return list<array{sheet: string, code: string, name: string, price: float}>|null
     */
    private static function sheetRows(string $sheet, array $cells): ?array
    {
        $header = array_shift($cells) ?? [];
        $priceColumn = null;
        foreach ($header as $index => $cell) {
            // od kolumny C: A to kod, B nazwa — nagłówek kodu „Nr katalogowy” nie może zostać wzięty za cenę
            if ((int) $index >= 2 && is_scalar($cell) && str_contains(mb_strtoupper((string) $cell), 'KATALOG')) {
                $priceColumn = (int) $index;
                break;
            }
        }
        if ($priceColumn === null) {
            return null;
        }

        $rows = [];
        foreach ($cells as $row) {
            $code = self::codeText($row[0] ?? null);
            $price = self::price($row[$priceColumn] ?? null);
            if ($code === '' || $price === null) {
                continue;
            }
            $name = [];
            for ($i = 1; $i < $priceColumn; $i++) {
                $part = is_scalar($row[$i] ?? null) ? self::clean((string) $row[$i]) : '';
                if ($part !== '') {
                    $name[] = $part;
                }
            }
            $rows[] = ['sheet' => $sheet, 'code' => $code, 'name' => implode(' ', $name), 'price' => $price];
        }

        return $rows;
    }

    /** Kod dosłownie: liczba całkowita (także zapisana jako 9705014.0) bez części ułamkowej. */
    private static function codeText(mixed $value): string
    {
        if (is_int($value)) {
            return (string) $value;
        }
        if (is_float($value)) {
            return floor($value) === $value ? sprintf('%.0F', $value) : (string) $value;
        }

        return is_string($value) ? self::clean($value) : '';
    }

    private static function price(mixed $value): ?float
    {
        if (is_string($value)) {
            $text = str_replace([' ', "\u{00A0}", ','], ['', '', '.'], trim($value));
            $value = is_numeric($text) ? (float) $text : null;
        }
        if (! is_int($value) && ! is_float($value)) {
            return null;
        }
        $price = round((float) $value, 2);

        return $price > 0 ? $price : null;
    }

    private static function key(string $code): string
    {
        return mb_strtolower((string) preg_replace('/[^\p{L}\p{N}]+/u', '', $code));
    }

    private static function clean(string $text): string
    {
        return trim((string) preg_replace('/[\s\x{00A0}]+/u', ' ', $text));
    }
}
