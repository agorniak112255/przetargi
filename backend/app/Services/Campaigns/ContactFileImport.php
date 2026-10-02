<?php

declare(strict_types=1);

namespace App\Services\Campaigns;

use App\Services\SpreadsheetCellReader;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Reader\BaseReader;
use PhpOffice\PhpSpreadsheet\Reader\Csv;
use RuntimeException;
use Throwable;

/**
 * Import adresów do grupy odbiorców z pliku (CSV, Excel). Plik idzie dwa razy: podgląd (nagłówki, próbki, propozycja
 * przypisania kolumn) i import z przypisaniem wybranym przez użytkownika — bez zapisywania pliku na serwerze.
 *
 * Wartości z pliku nie są poprawiane (wielkość liter, skróty) — tylko przycinane do długości pól kontaktu.
 */
final class ContactFileImport
{
    public const EXTENSIONS = ['csv', 'txt', 'xlsx', 'xls', 'ods'];

    public const FORMATS_LABEL = 'CSV albo Excel (xlsx, xls, ods)';

    public const FIELD_EMAIL = 'email';

    public const FIELD_FIRST_NAME = 'first_name';

    public const FIELD_LAST_NAME = 'last_name';

    public const FIELD_NAME = 'name';

    public const FIELD_COMPANY = 'company';

    /** Kolumna zgody: importujemy tylko wiersze z wartością „tak” — pusta albo inna = brak zgody. */
    public const FIELD_CONSENT = 'consent';

    public const FIELDS = [
        self::FIELD_EMAIL,
        self::FIELD_FIRST_NAME,
        self::FIELD_LAST_NAME,
        self::FIELD_NAME,
        self::FIELD_COMPANY,
        self::FIELD_CONSENT,
    ];

    /** Wiersze w podglądzie (z nagłówkiem). */
    public const PREVIEW_ROWS = 8;

    private const CONSENT_YES = ['1', 'tak', 't', 'yes', 'y', 'true', 'prawda', 'x'];

    /**
     * Podpisy kolumn → pole. Całe nagłówki po normalizacji (małe litery, bez polskich znaków, bez kropek i myślników).
     * „Nazwa kontaktu” ze sklepu to zwrot grzecznościowy (Pan/Pani) — celowo bez propozycji.
     */
    private const HEADER_FIELDS = [
        'email' => self::FIELD_EMAIL, 'e mail' => self::FIELD_EMAIL, 'mail' => self::FIELD_EMAIL,
        'adres email' => self::FIELD_EMAIL, 'adres e mail' => self::FIELD_EMAIL, 'email address' => self::FIELD_EMAIL,
        'e mail address' => self::FIELD_EMAIL, 'adres mailowy' => self::FIELD_EMAIL, 'poczta' => self::FIELD_EMAIL,
        'imie' => self::FIELD_FIRST_NAME, 'first name' => self::FIELD_FIRST_NAME, 'firstname' => self::FIELD_FIRST_NAME,
        'nazwisko' => self::FIELD_LAST_NAME, 'last name' => self::FIELD_LAST_NAME, 'lastname' => self::FIELD_LAST_NAME,
        'surname' => self::FIELD_LAST_NAME,
        'imie i nazwisko' => self::FIELD_NAME, 'imie nazwisko' => self::FIELD_NAME, 'nazwisko i imie' => self::FIELD_NAME,
        'osoba kontaktowa' => self::FIELD_NAME, 'full name' => self::FIELD_NAME, 'name' => self::FIELD_NAME,
        'firma' => self::FIELD_COMPANY, 'nazwa firmy' => self::FIELD_COMPANY, 'company' => self::FIELD_COMPANY,
        'company name' => self::FIELD_COMPANY, 'kontrahent' => self::FIELD_COMPANY, 'nazwa kontrahenta' => self::FIELD_COMPANY,
        'nazwa' => self::FIELD_COMPANY, 'organizacja' => self::FIELD_COMPANY,
        'newsletter' => self::FIELD_CONSENT, 'zgoda' => self::FIELD_CONSENT, 'zgoda marketingowa' => self::FIELD_CONSENT,
        'zgoda na newsletter' => self::FIELD_CONSENT, 'consent' => self::FIELD_CONSENT,
    ];

    public function __construct(private readonly SpreadsheetCellReader $cells) {}

    /**
     * Wiersze arkusza z numerami z pliku; puste wiersze pominięte. Wymiary arkusza sprawdzamy przed wczytaniem —
     * eksport z dziesiątkami tysięcy klientów zjadłby pamięć serwera, zanim import zdążyłby odmówić.
     *
     * @param  int  $maxSheetRows  najwyżej tyle wierszy arkusza (z pustymi i nagłówkiem)
     * @return array{sheets: list<string>, sheet: int, rows: list<array{line: int, cells: list<string>}>}
     */
    public function read(string $path, string $extension, int $sheet, int $maxSheetRows): array
    {
        $ext = mb_strtolower($extension);
        if (! in_array($ext, self::EXTENSIONS, true)) {
            throw new RuntimeException('Nieobsługiwany format pliku. Wgraj '.self::FORMATS_LABEL.'.');
        }
        try {
            $reader = $this->reader($path, $ext);
            $info = array_values($reader->listWorksheetInfo($path));
        } catch (Throwable) {
            throw new RuntimeException('Nie udało się odczytać pliku. Sprawdź, czy to '.self::FORMATS_LABEL.'.');
        }
        $names = array_map(static fn (array $i): string => trim((string) $i['worksheetName']), $info);
        if ($sheet < 0 || $sheet >= count($names)) {
            throw new RuntimeException('W pliku nie ma takiego arkusza.');
        }
        $total = (int) $info[$sheet]['totalRows'];
        if ($total > $maxSheetRows) {
            throw new RuntimeException('Arkusz ma '.$total.' wierszy — najwyżej '.$maxSheetRows.' w jednym imporcie. Podziel plik.');
        }

        try {
            $reader->setReadDataOnly(true);
            if (! $reader instanceof Csv) {
                $reader->setLoadSheetsOnly([(string) $info[$sheet]['worksheetName']]);
            }
            $book = $reader->load($path);
        } catch (Throwable) {
            throw new RuntimeException('Nie udało się odczytać pliku. Sprawdź, czy to '.self::FORMATS_LABEL.'.');
        }

        $rows = [];
        foreach ($this->cells->toRows($book->getSheet(0)) as $i => $cells) {
            $cells = array_map(static fn (string $c): string => trim(preg_replace('/\s+/u', ' ', $c) ?? $c), $cells);
            if (implode('', $cells) === '') {
                continue;
            }
            $rows[] = ['line' => $i + 1, 'cells' => $cells];
        }
        $book->disconnectWorksheets();

        return ['sheets' => $names, 'sheet' => $sheet, 'rows' => $rows];
    }

    /**
     * Podgląd: pierwsze wiersze, czy pierwszy wiersz wygląda na nagłówki i propozycja pola dla każdej kolumny.
     *
     * @param  list<array{line: int, cells: list<string>}>  $rows
     * @return array{has_header: bool, column_count: int, rows: list<list<string>>, suggested: list<string|null>}
     */
    public function preview(array $rows): array
    {
        $width = 0;
        foreach ($rows as $row) {
            $width = max($width, count($row['cells']));
        }
        $hasHeader = $rows !== [] && ! $this->rowHasEmail($rows[0]['cells']);

        $suggested = array_fill(0, $width, null);
        $taken = [];
        if ($hasHeader) {
            foreach ($rows[0]['cells'] as $i => $label) {
                $field = self::HEADER_FIELDS[$this->normalizeHeader($label)] ?? null;
                if ($field !== null && ! isset($taken[$field])) {
                    $suggested[$i] = $field;
                    $taken[$field] = true;
                }
            }
        }
        if (! isset($taken[self::FIELD_EMAIL])) {
            // bez podpisu: kolumna, w której większość wypełnionych komórek to adresy
            $sample = array_slice($rows, $hasHeader ? 1 : 0, 50);
            for ($i = 0; $i < $width; $i++) {
                $filled = 0;
                $emails = 0;
                foreach ($sample as $row) {
                    $cell = $row['cells'][$i] ?? '';
                    if ($cell === '') {
                        continue;
                    }
                    $filled++;
                    $emails += $this->emails($cell) !== [] ? 1 : 0;
                }
                if ($suggested[$i] === null && $filled > 0 && $emails * 2 > $filled) {
                    $suggested[$i] = self::FIELD_EMAIL;
                    break;
                }
            }
        }

        return [
            'has_header' => $hasHeader,
            'column_count' => $width,
            'rows' => array_map(
                static fn (array $r): array => array_pad($r['cells'], $width, ''),
                array_slice($rows, 0, self::PREVIEW_ROWS),
            ),
            'suggested' => $suggested,
        ];
    }

    /**
     * Wiersze danych → kontakty według przypisania kolumn. Komórka e-mail może mieć kilka adresów (przecinek, średnik,
     * spacja) — każdy staje się kontaktem z tą samą osobą i firmą. Pierwsze wystąpienie adresu wygrywa.
     *
     * @param  list<array{line: int, cells: list<string>}>  $rows  bez wiersza nagłówków
     * @param  array<string, int>  $mapping  pole → indeks kolumny
     * @return array{rows: array<string, array{name: string|null, company: string|null}>, invalid: list<string>, duplicates: int, empty: int, no_consent: int}
     */
    public function extract(array $rows, array $mapping): array
    {
        $out = [];
        $invalid = [];
        $duplicates = 0;
        $empty = 0;
        $noConsent = 0;
        $cell = static fn (array $cells, string $field): string => isset($mapping[$field]) ? ($cells[$mapping[$field]] ?? '') : '';

        foreach ($rows as $row) {
            $cells = $row['cells'];
            $raw = $cell($cells, self::FIELD_EMAIL);
            if ($raw === '') {
                $empty++;

                continue;
            }
            if (isset($mapping[self::FIELD_CONSENT]) && ! $this->isYes($cell($cells, self::FIELD_CONSENT))) {
                $noConsent++;

                continue;
            }
            $emails = $this->emails($raw);
            if ($emails === []) {
                $invalid[] = 'wiersz '.$row['line'].': '.mb_substr($raw, 0, 200);

                continue;
            }

            $name = $cell($cells, self::FIELD_NAME);
            if ($name === '') {
                $name = trim($cell($cells, self::FIELD_FIRST_NAME).' '.$cell($cells, self::FIELD_LAST_NAME));
            }
            $company = $cell($cells, self::FIELD_COMPANY);
            foreach ($emails as $email) {
                if (isset($out[$email])) {
                    $duplicates++;

                    continue;
                }
                $out[$email] = [
                    'name' => $name !== '' ? mb_substr($name, 0, 200) : null,
                    'company' => $company !== '' ? mb_substr($company, 0, 250) : null,
                ];
            }
        }

        return ['rows' => $out, 'invalid' => $invalid, 'duplicates' => $duplicates, 'empty' => $empty, 'no_consent' => $noConsent];
    }

    /**
     * Poprawne adresy z komórki (małe litery). „Jan Kowalski <jan@firma.pl>” → jan@firma.pl.
     *
     * @return list<string>
     */
    public function emails(string $cell): array
    {
        $found = [];
        foreach (preg_split('/[\s;,]+/u', $cell) ?: [] as $token) {
            $token = mb_strtolower(trim($token, " \t\"'<>()[]"));
            if (str_starts_with($token, 'mailto:')) {
                $token = substr($token, 7);
            }
            if ($token !== '' && strlen($token) <= 255 && filter_var($token, FILTER_VALIDATE_EMAIL) !== false) {
                $found[$token] = true;
            }
        }

        return array_keys($found);
    }

    private function reader(string $path, string $ext): BaseReader
    {
        if ($ext === 'csv' || $ext === 'txt') {
            // eksport z polskiego Excela/sklepu bywa w Windows-1250; separator (; , tab) czytnik rozpoznaje sam
            $reader = new Csv;
            $reader->setInputEncoding(Csv::GUESS_ENCODING);
            $reader->setFallbackEncoding('CP1250');

            return $reader;
        }

        $reader = IOFactory::createReaderForFile($path);
        if (! $reader instanceof BaseReader) {
            throw new RuntimeException('Nieobsługiwany format pliku.');
        }

        return $reader;
    }

    /** @param  list<string>  $cells */
    private function rowHasEmail(array $cells): bool
    {
        foreach ($cells as $cell) {
            if ($cell !== '' && $this->emails($cell) !== []) {
                return true;
            }
        }

        return false;
    }

    private function isYes(string $value): bool
    {
        return in_array(mb_strtolower(trim($value)), self::CONSENT_YES, true);
    }

    private function normalizeHeader(string $label): string
    {
        $text = mb_strtolower(trim($label));
        $text = strtr($text, ['ą' => 'a', 'ć' => 'c', 'ę' => 'e', 'ł' => 'l', 'ń' => 'n', 'ó' => 'o', 'ś' => 's', 'ź' => 'z', 'ż' => 'z']);
        $text = preg_replace('/[^a-z0-9]+/', ' ', $text) ?? $text;

        return trim($text);
    }
}
