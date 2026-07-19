<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\Tender;
use App\Models\TenderDocument;
use App\Models\TenderItem;
use DOMDocument;
use DOMElement;
use DOMXPath;
use Illuminate\Support\Facades\Storage;
use RuntimeException;
use ZipArchive;

/**
 * Wypełnia formularz ofertowy DOCX cenami z pozycji przetargu.
 */
final class TenderDocxOfferFiller
{
    public function __construct(
        private readonly TenderDocxItemExtractor $docxTables,
    ) {}

    /**
     * @return string ścieżka do wygenerowanego pliku tymczasowego
     */
    public function fill(Tender $tender, ?string $templateAbsolutePath = null): string
    {
        $template = $templateAbsolutePath ?? $this->resolveTemplatePath($tender);
        $tender->loadMissing(['items.mainProduct', 'client']);

        $xml = $this->docxTables->documentXml($template);
        $dom = new DOMDocument;
        $dom->preserveWhiteSpace = true;
        if (@$dom->loadXML($xml) === false) {
            throw new RuntimeException('Nieprawidłowy szablon DOCX.');
        }
        $xp = new DOMXPath($dom);
        $xp->registerNamespace('w', 'http://schemas.openxmlformats.org/wordprocessingml/2006/main');

        $items = $tender->items()->orderBy('line_no')->get();
        if ($items->isEmpty()) {
            throw new RuntimeException('Brak pozycji w przetargu do wypełnienia oferty.');
        }

        $filled = false;
        $sumNet = 0.0;
        foreach ($xp->query('//w:tbl') ?: [] as $tbl) {
            if (! $tbl instanceof DOMElement) {
                continue;
            }
            $result = $this->fillOfferTable($xp, $tbl, $items);
            if ($result === null) {
                continue;
            }
            $filled = true;
            $sumNet = $result['sum_net'];
        }

        if (! $filled) {
            throw new RuntimeException(
                'W DOCX nie znaleziono tabeli ofertowej (nagłówki: L.p. / Przedmiot / Cena jednostkowa).'
            );
        }

        $this->fillSummaryTable($xp, $sumNet);

        $tmpDir = storage_path('app/tmp');
        if (! is_dir($tmpDir)) {
            mkdir($tmpDir, 0755, true);
        }
        $outPath = $tmpDir.'/oferta_'.$tender->id.'_'.uniqid('', true).'.docx';
        if (! copy($template, $outPath)) {
            throw new RuntimeException('Nie można skopiować szablonu DOCX.');
        }

        $zip = new ZipArchive;
        if ($zip->open($outPath) !== true) {
            @unlink($outPath);
            throw new RuntimeException('Nie można zapisać uzupełnionego DOCX.');
        }
        $zip->addFromString('word/document.xml', $dom->saveXML() ?: $xml);
        $zip->close();

        return $outPath;
    }

    public function resolveTemplatePath(Tender $tender): string
    {
        $doc = TenderDocument::query()
            ->where('tender_id', $tender->id)
            ->whereIn('extension', ['docx', 'doc'])
            ->whereNotNull('disk_path')
            ->orderByDesc('id')
            ->first();

        if ($doc === null || ! is_string($doc->disk_path) || $doc->disk_path === '') {
            throw new RuntimeException(
                'Brak zapisanego formularza DOCX. Wgraj formularz ofertowy w zakładce Dokumenty (tryb pełny lub Word).'
            );
        }
        if (! Storage::disk('local')->exists($doc->disk_path)) {
            throw new RuntimeException('Plik formularza DOCX nie istnieje na dysku.');
        }

        return Storage::disk('local')->path($doc->disk_path);
    }

    /**
     * @param  \Illuminate\Support\Collection<int, TenderItem>  $items
     * @return array{sum_net: float}|null
     */
    private function fillOfferTable(DOMXPath $xp, DOMElement $tbl, $items): ?array
    {
        $rows = [];
        foreach ($xp->query('./w:tr', $tbl) ?: [] as $tr) {
            if ($tr instanceof DOMElement) {
                $rows[] = $tr;
            }
        }
        if (count($rows) < 3) {
            return null;
        }

        $headerIdx = null;
        $cols = null;
        foreach ($rows as $i => $tr) {
            $labels = array_map('mb_strtolower', $this->rowTexts($xp, $tr));
            $blob = implode(' | ', $labels);
            if (! str_contains($blob, 'cena') && ! str_contains($blob, 'przedmiot')) {
                continue;
            }
            if (! (str_contains($blob, 'l.p') || str_contains($blob, 'lp') || str_contains($blob, 'przedmiot'))) {
                continue;
            }
            $map = $this->mapOfferColumns($labels);
            if ($map['name'] === null || ($map['unit_price'] === null && $map['line_total'] === null)) {
                continue;
            }
            $headerIdx = $i;
            $cols = $map;
            break;
        }

        if ($headerIdx === null || $cols === null) {
            return null;
        }

        $byLine = [];
        foreach ($items as $item) {
            $byLine[(int) $item->line_no] = $item;
        }

        $sum = 0.0;
        $matched = 0;
        for ($i = $headerIdx + 1; $i < count($rows); $i++) {
            $tr = $rows[$i];
            $texts = $this->rowTexts($xp, $tr);
            $joined = mb_strtolower(implode(' ', $texts));
            if (str_contains($joined, 'suma')) {
                $this->setCellText($xp, $tr, $cols['line_total'] ?? $cols['unit_price'], $this->formatPl($sum));
                continue;
            }
            if ($this->looksLikeColumnIndexRow($texts)) {
                continue;
            }

            $lp = $cols['lp'] !== null ? (int) preg_replace('/\D+/', '', $texts[$cols['lp']] ?? '') : 0;
            $name = $cols['name'] !== null ? trim((string) ($texts[$cols['name']] ?? '')) : '';
            $item = $lp > 0 && isset($byLine[$lp])
                ? $byLine[$lp]
                : $this->matchByName($items, $name);
            if ($item === null) {
                continue;
            }

            $qty = max(1, (int) $item->quantity);
            if ($cols['qty'] !== null) {
                $qRaw = $this->parsePlFloat($texts[$cols['qty']] ?? '');
                if ($qRaw !== null && $qRaw >= 1) {
                    $qty = (int) round($qRaw);
                }
            }
            $unit = $item->offer_price !== null ? (float) $item->offer_price : null;
            if ($unit === null) {
                continue;
            }
            $line = round($unit * $qty, 2);
            $sum += $line;
            $matched++;

            if ($cols['unit_price'] !== null) {
                $this->setCellText($xp, $tr, $cols['unit_price'], $this->formatPl($unit));
            }
            if ($cols['line_total'] !== null) {
                $this->setCellText($xp, $tr, $cols['line_total'], $this->formatPl($line));
            }
        }

        return $matched > 0 ? ['sum_net' => round($sum, 2)] : null;
    }

    /**
     * @param  list<string>  $labels lowercase
     * @return array{lp: ?int, name: ?int, qty: ?int, unit_price: ?int, line_total: ?int}
     */
    private function mapOfferColumns(array $labels): array
    {
        return [
            'lp' => $this->findCol($labels, ['l.p', 'lp', 'poz']),
            'name' => $this->findCol($labels, ['przedmiot', 'nazwa', 'opis', 'asortyment']),
            'qty' => $this->findCol($labels, ['ilość', 'ilosc', 'quantity', 'qty']),
            'unit_price' => $this->findCol($labels, ['cena jednostkowa', 'cena jedn', 'unit price', 'cena netto']),
            'line_total' => $this->findCol($labels, ['łączna wartość', 'laczna wartosc', 'wartość netto', 'wartosc netto', 'suma']),
        ];
    }

    /**
     * @param  list<string>  $labels
     * @param  list<string>  $needles
     */
    private function findCol(array $labels, array $needles): ?int
    {
        foreach ($labels as $i => $label) {
            foreach ($needles as $needle) {
                if ($label !== '' && str_contains($label, $needle)) {
                    return $i;
                }
            }
        }

        return null;
    }

    /**
     * @param  \Illuminate\Support\Collection<int, TenderItem>  $items
     */
    private function matchByName($items, string $name): ?TenderItem
    {
        $name = mb_strtolower(trim($name));
        if ($name === '' || mb_strlen($name) < 8) {
            return null;
        }
        foreach ($items as $item) {
            $req = mb_strtolower((string) $item->requirement);
            $prod = mb_strtolower((string) ($item->mainProduct?->name ?? ''));
            if (($req !== '' && (str_contains($req, $name) || str_contains($name, $req)))
                || ($prod !== '' && (str_contains($prod, $name) || str_contains($name, $prod)))) {
                return $item;
            }
        }

        return null;
    }

    private function fillSummaryTable(DOMXPath $xp, float $sumNet): void
    {
        $gross = round($sumNet * 1.23, 2);
        foreach ($xp->query('//w:tbl') ?: [] as $tbl) {
            if (! $tbl instanceof DOMElement) {
                continue;
            }
            foreach ($xp->query('./w:tr', $tbl) ?: [] as $tr) {
                if (! $tr instanceof DOMElement) {
                    continue;
                }
                $texts = $this->rowTexts($xp, $tr);
                $blob = mb_strtolower(implode(' ', $texts));
                if (str_contains($blob, 'wartość netto') || str_contains($blob, 'wartosc netto')) {
                    $this->setLastNonEmptyOrAppend($xp, $tr, $this->formatPl($sumNet));
                } elseif (str_contains($blob, 'wartość brutto') || str_contains($blob, 'wartosc brutto')) {
                    $this->setLastNonEmptyOrAppend($xp, $tr, $this->formatPl($gross));
                }
            }
        }
    }

    private function setLastNonEmptyOrAppend(DOMXPath $xp, DOMElement $tr, string $value): void
    {
        $cells = [];
        foreach ($xp->query('./w:tc', $tr) ?: [] as $tc) {
            if ($tc instanceof DOMElement) {
                $cells[] = $tc;
            }
        }
        if ($cells === []) {
            return;
        }
        $target = $cells[count($cells) - 1];
        $this->writeTcText($xp, $target, $value);
    }

    private function setCellText(DOMXPath $xp, DOMElement $tr, ?int $colIdx, string $value): void
    {
        if ($colIdx === null) {
            return;
        }
        $cells = [];
        foreach ($xp->query('./w:tc', $tr) ?: [] as $tc) {
            if ($tc instanceof DOMElement) {
                $cells[] = $tc;
            }
        }
        if (! isset($cells[$colIdx])) {
            return;
        }
        $this->writeTcText($xp, $cells[$colIdx], $value);
    }

    private function writeTcText(DOMXPath $xp, DOMElement $tc, string $value): void
    {
        $texts = $xp->query('.//w:t', $tc);
        if ($texts !== false && $texts->length > 0) {
            $first = true;
            foreach ($texts as $node) {
                if ($first) {
                    $node->nodeValue = $value;
                    $first = false;
                } else {
                    $node->parentNode?->removeChild($node);
                }
            }

            return;
        }

        $ns = 'http://schemas.openxmlformats.org/wordprocessingml/2006/main';
        $p = $tc->ownerDocument?->createElementNS($ns, 'w:p');
        $r = $tc->ownerDocument?->createElementNS($ns, 'w:r');
        $t = $tc->ownerDocument?->createElementNS($ns, 'w:t', $value);
        if ($p === null || $r === null || $t === null) {
            return;
        }
        if (preg_match('/^\s|\s$/u', $value) === 1) {
            $t->setAttributeNS('http://www.w3.org/XML/1998/namespace', 'xml:space', 'preserve');
        }
        $r->appendChild($t);
        $p->appendChild($r);
        $tc->appendChild($p);
    }

    /**
     * @return list<string>
     */
    private function rowTexts(DOMXPath $xp, DOMElement $tr): array
    {
        $cells = [];
        foreach ($xp->query('./w:tc', $tr) ?: [] as $tc) {
            $parts = [];
            foreach ($xp->query('.//w:t', $tc) ?: [] as $t) {
                $parts[] = $t->textContent;
            }
            $cells[] = trim(preg_replace('/\s+/u', ' ', implode('', $parts)) ?? '');
        }

        return $cells;
    }

    /**
     * @param  list<string>  $row
     */
    private function looksLikeColumnIndexRow(array $row): bool
    {
        $nonEmpty = array_values(array_filter($row, static fn (string $c): bool => trim($c) !== ''));
        if (count($nonEmpty) < 3) {
            return false;
        }
        $hits = 0;
        foreach ($nonEmpty as $cell) {
            if (preg_match('/^\d+(\s*\([^)]*\))?$/u', trim($cell)) === 1) {
                $hits++;
            }
        }

        return $hits >= max(3, (int) ceil(count($nonEmpty) * 0.7));
    }

    private function formatPl(float $n): string
    {
        return number_format($n, 2, ',', ' ');
    }

    private function parsePlFloat(string $raw): ?float
    {
        $s = str_replace(["\xc2\xa0", ' '], '', trim($raw));
        $s = str_replace(',', '.', $s);
        if (! is_numeric($s)) {
            return null;
        }

        return (float) $s;
    }
}
