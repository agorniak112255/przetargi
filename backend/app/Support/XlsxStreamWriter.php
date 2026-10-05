<?php

declare(strict_types=1);

namespace App\Support;

use DateTimeImmutable;
use DateTimeInterface;
use DateTimeZone;
use RuntimeException;
use ZipArchive;

/**
 * Plik XLSX zapisywany wiersz po wierszu do pliku tymczasowego — pamięć nie rośnie z liczbą wierszy (PhpSpreadsheet
 * trzyma każdą komórkę jako obiekt: 30 tys. wierszy × 20 kolumn nie mieści się w limicie PHP). Tekst jako inlineStr,
 * liczby jako liczby, daty jako prawdziwe daty Excela (czas „na zegarze” podanego obiektu). Arkusz z header=true ma
 * pogrubiony, zamrożony pierwszy wiersz i autofiltr.
 */
final class XlsxStreamWriter
{
    private const STYLE_DATETIME = 1;

    private const STYLE_DATE = 2;

    private const STYLE_HEADER = 3;

    /** @var list<array{name: string, path: string, widths: list<float|int>, header: bool, rows: int, cols: int}> */
    private array $sheets = [];

    /** @var resource|null */
    private $handle = null;

    public function __construct(private readonly string $path) {}

    /** Komórka z samą datą (bez godziny); null zostaje pustą komórką. */
    public static function date(DateTimeInterface|string|null $value): ?XlsxCell
    {
        if (is_string($value)) {
            $value = DateTimeImmutable::createFromFormat('!Y-m-d', substr($value, 0, 10), new DateTimeZone('UTC')) ?: null;
        }

        return $value === null ? null : new XlsxCell($value, self::STYLE_DATE);
    }

    /** Komórka z datą i godziną; null zostaje pustą komórką. */
    public static function dateTime(?DateTimeInterface $value): ?XlsxCell
    {
        return $value === null ? null : new XlsxCell($value, self::STYLE_DATETIME);
    }

    /** @param  list<float|int>  $widths  szerokości kolumn w znakach */
    public function addSheet(string $name, array $widths = [], bool $header = false): void
    {
        $this->closeSheet();
        $path = tempnam(sys_get_temp_dir(), 'xlsxs');
        $handle = $path === false ? false : fopen($path, 'wb');
        if ($path === false || $handle === false) {
            throw new RuntimeException('Nie udało się utworzyć pliku tymczasowego arkusza.');
        }
        $this->handle = $handle;
        $this->sheets[] = [
            'name' => $this->sheetName($name),
            'path' => $path,
            'widths' => array_values($widths),
            'header' => $header,
            'rows' => 0,
            'cols' => 0,
        ];
    }

    /** @param  list<string|int|float|bool|XlsxCell|null>  $cells */
    public function addRow(array $cells): void
    {
        if ($this->handle === null || $this->sheets === []) {
            throw new RuntimeException('Najpierw addSheet().');
        }
        $i = array_key_last($this->sheets);
        $rowNo = ++$this->sheets[$i]['rows'];
        $headerRow = $this->sheets[$i]['header'] && $rowNo === 1;
        $xml = '<row r="'.$rowNo.'">';
        foreach (array_values($cells) as $col => $value) {
            $ref = self::column($col).$rowNo;
            if ($value === null || $value === '') {
                continue;
            }
            $this->sheets[$i]['cols'] = max($this->sheets[$i]['cols'], $col + 1);
            if ($value instanceof XlsxCell) {
                $xml .= '<c r="'.$ref.'" s="'.$value->style.'"><v>'.self::serial($value->value).'</v></c>';
            } elseif (is_int($value) || is_float($value)) {
                $xml .= '<c r="'.$ref.'"'.($headerRow ? ' s="'.self::STYLE_HEADER.'"' : '').'><v>'.(is_finite((float) $value) ? $value : 0).'</v></c>';
            } else {
                $text = is_bool($value) ? ($value ? 'tak' : 'nie') : $value;
                $xml .= '<c r="'.$ref.'" t="inlineStr"'.($headerRow ? ' s="'.self::STYLE_HEADER.'"' : '').'><is><t xml:space="preserve">'
                    .self::escape($text).'</t></is></c>';
            }
        }
        fwrite($this->handle, $xml.'</row>');
    }

    /** Składa plik XLSX pod ścieżką z konstruktora i usuwa pliki tymczasowe arkuszy. */
    public function close(): void
    {
        $this->closeSheet();
        if ($this->sheets === []) {
            $this->addSheet('Arkusz1');
            $this->closeSheet();
        }
        $zip = new ZipArchive;
        if ($zip->open($this->path, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
            throw new RuntimeException('Nie udało się utworzyć pliku XLSX.');
        }
        $sheetFiles = [];
        try {
            $zip->addFromString('[Content_Types].xml', $this->contentTypes());
            $zip->addFromString('_rels/.rels', '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
                .'<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'
                .'<Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/officeDocument" Target="xl/workbook.xml"/>'
                .'</Relationships>');
            $zip->addFromString('xl/workbook.xml', $this->workbook());
            $zip->addFromString('xl/_rels/workbook.xml.rels', $this->workbookRels());
            $zip->addFromString('xl/styles.xml', self::styles());
            foreach ($this->sheets as $n => $sheet) {
                $file = $this->sheetFile($sheet);
                $sheetFiles[] = $file;
                $zip->addFile($file, 'xl/worksheets/sheet'.($n + 1).'.xml');
            }
            $zip->close();
        } finally {
            foreach ([...$sheetFiles, ...array_column($this->sheets, 'path')] as $file) {
                if (is_file($file)) {
                    @unlink($file);
                }
            }
            $this->sheets = [];
        }
    }

    private function closeSheet(): void
    {
        if ($this->handle !== null) {
            fclose($this->handle);
            $this->handle = null;
        }
    }

    /**
     * Pełny plik arkusza: nagłówek (zamrożenie, szerokości) + zapisane wiersze + autofiltr.
     *
     * @param  array{name: string, path: string, widths: list<float|int>, header: bool, rows: int, cols: int}  $sheet
     */
    private function sheetFile(array $sheet): string
    {
        $file = $sheet['path'].'.xml';
        $out = fopen($file, 'wb');
        $in = fopen($sheet['path'], 'rb');
        if ($out === false || $in === false) {
            throw new RuntimeException('Nie udało się złożyć arkusza.');
        }
        $head = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            .'<worksheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main">';
        if ($sheet['header']) {
            $head .= '<sheetViews><sheetView workbookViewId="0"><pane ySplit="1" topLeftCell="A2" activePane="bottomLeft" state="frozen"/></sheetView></sheetViews>';
        }
        if ($sheet['widths'] !== []) {
            $head .= '<cols>';
            foreach ($sheet['widths'] as $i => $w) {
                $head .= '<col min="'.($i + 1).'" max="'.($i + 1).'" width="'.$w.'" customWidth="1"/>';
            }
            $head .= '</cols>';
        }
        fwrite($out, $head.'<sheetData>');
        stream_copy_to_stream($in, $out);
        fclose($in);
        $tail = '</sheetData>';
        if ($sheet['header'] && $sheet['cols'] > 0) {
            $tail .= '<autoFilter ref="'.$this->filterRange($sheet).'"/>';
        }
        fwrite($out, $tail.'</worksheet>');
        fclose($out);

        return $file;
    }

    /** @param  array{rows: int, cols: int}  $sheet */
    private function filterRange(array $sheet): string
    {
        return 'A1:'.self::column(max(0, $sheet['cols'] - 1)).max(1, $sheet['rows']);
    }

    private function contentTypes(): string
    {
        $xml = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            .'<Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types">'
            .'<Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/>'
            .'<Default Extension="xml" ContentType="application/xml"/>'
            .'<Override PartName="/xl/workbook.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.sheet.main+xml"/>'
            .'<Override PartName="/xl/styles.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.styles+xml"/>';
        foreach (array_keys($this->sheets) as $n) {
            $xml .= '<Override PartName="/xl/worksheets/sheet'.($n + 1).'.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.worksheet+xml"/>';
        }

        return $xml.'</Types>';
    }

    private function workbook(): string
    {
        $sheets = '';
        $names = '';
        foreach ($this->sheets as $n => $sheet) {
            $sheets .= '<sheet name="'.self::escape($sheet['name']).'" sheetId="'.($n + 1).'" r:id="rId'.($n + 1).'"/>';
            if ($sheet['header'] && $sheet['cols'] > 0) {
                [$from, $to] = explode(':', $this->filterRange($sheet));
                $abs = static fn (string $ref): string => preg_replace('/^([A-Z]+)(\d+)$/', '$$1$$2', $ref) ?? $ref;
                $names .= '<definedName name="_xlnm._FilterDatabase" localSheetId="'.$n.'" hidden="1">'
                    .self::escape("'".str_replace("'", "''", $sheet['name'])."'!".$abs($from).':'.$abs($to)).'</definedName>';
            }
        }

        return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            .'<workbook xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main" xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships">'
            .'<sheets>'.$sheets.'</sheets>'
            .($names !== '' ? '<definedNames>'.$names.'</definedNames>' : '')
            .'</workbook>';
    }

    private function workbookRels(): string
    {
        $xml = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            .'<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">';
        $n = count($this->sheets);
        for ($i = 1; $i <= $n; $i++) {
            $xml .= '<Relationship Id="rId'.$i.'" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/worksheet" Target="worksheets/sheet'.$i.'.xml"/>';
        }

        return $xml.'<Relationship Id="rId'.($n + 1).'" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/styles" Target="styles.xml"/>'
            .'</Relationships>';
    }

    /** Style: 0 zwykły, 1 data i godzina, 2 data, 3 pogrubiony nagłówek. */
    private static function styles(): string
    {
        return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            .'<styleSheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main">'
            .'<numFmts count="2"><numFmt numFmtId="164" formatCode="yyyy-mm-dd hh:mm"/><numFmt numFmtId="165" formatCode="yyyy-mm-dd"/></numFmts>'
            .'<fonts count="2"><font><sz val="11"/><name val="Calibri"/></font><font><b/><sz val="11"/><name val="Calibri"/></font></fonts>'
            .'<fills count="2"><fill><patternFill patternType="none"/></fill><fill><patternFill patternType="gray125"/></fill></fills>'
            .'<borders count="1"><border><left/><right/><top/><bottom/><diagonal/></border></borders>'
            .'<cellStyleXfs count="1"><xf numFmtId="0" fontId="0" fillId="0" borderId="0"/></cellStyleXfs>'
            .'<cellXfs count="4">'
            .'<xf numFmtId="0" fontId="0" fillId="0" borderId="0" xfId="0"/>'
            .'<xf numFmtId="164" fontId="0" fillId="0" borderId="0" xfId="0" applyNumberFormat="1"/>'
            .'<xf numFmtId="165" fontId="0" fillId="0" borderId="0" xfId="0" applyNumberFormat="1"/>'
            .'<xf numFmtId="0" fontId="1" fillId="0" borderId="0" xfId="0" applyFont="1"/>'
            .'</cellXfs>'
            .'<cellStyles count="1"><cellStyle name="Normal" xfId="0" builtinId="0"/></cellStyles>'
            .'</styleSheet>';
    }

    /** Liczba seryjna Excela z czasu „na zegarze” obiektu (bez przeliczania strefy). */
    private static function serial(DateTimeInterface $value): string
    {
        $wall = new DateTimeImmutable($value->format('Y-m-d H:i:s'), new DateTimeZone('UTC'));

        return rtrim(rtrim(number_format($wall->getTimestamp() / 86400 + 25569, 6, '.', ''), '0'), '.');
    }

    /** 0 → A, 25 → Z, 26 → AA. */
    private static function column(int $index): string
    {
        $name = '';
        for ($n = $index + 1; $n > 0; $n = intdiv($n - 1, 26)) {
            $name = chr(65 + ($n - 1) % 26).$name;
        }

        return $name;
    }

    /** Znaki niedozwolone w XML (sterujące z XL) wypadają, reszta escapowana. */
    private static function escape(string $text): string
    {
        $clean = preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/', '', $text) ?? '';
        if (! mb_check_encoding($clean, 'UTF-8')) {
            $clean = mb_convert_encoding($clean, 'UTF-8', 'UTF-8');
        }

        return htmlspecialchars($clean, ENT_XML1 | ENT_QUOTES, 'UTF-8');
    }

    /** Nazwa arkusza: bez []:*?/\, najwyżej 31 znaków. */
    private function sheetName(string $name): string
    {
        $clean = trim((string) preg_replace('/[\[\]:*?\/\\\\]/', ' ', $name));

        return mb_substr($clean !== '' ? $clean : 'Arkusz'.(count($this->sheets) + 1), 0, 31);
    }
}
