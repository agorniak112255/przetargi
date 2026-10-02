<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Tender;
use App\Services\Pricing\SupplierSpecialMask;
use App\Services\TenderDocxOfferFiller;
use App\Services\TenderOfferExportService;
use App\Services\TenderWorkflowService;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use RuntimeException;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\StreamedResponse;

class TenderExportController extends Controller
{
    public function __construct(
        private readonly TenderDocxOfferFiller $docxFiller,
        private readonly TenderOfferExportService $offerExport,
    ) {}

    public function excel(Request $request, Tender $tender): StreamedResponse
    {
        // zakup, marże i porównanie zamienników w cenach osoby, która eksportuje
        $mask = SupplierSpecialMask::forUser($request->user());
        $rows = $this->offerExport->rows($tender, $mask);
        $tender->loadMissing(['client', 'owner']);

        $sheet = new Spreadsheet;
        $sh = $sheet->getActiveSheet();
        $sh->setTitle('Oferta');
        $sh->fromArray([
            ['Numer przetargu', $tender->number],
            ['Zamawiający', $tender->client?->name],
            ['Tytuł', $tender->title],
            ['Status', TenderWorkflowService::statusLabel($tender->status)],
            ['Marża, %', $this->offerExport->tenderMargin($tender, $mask)],
            ['Wartość netto', $tender->offer_value_net],
            [],
            [
                'Pozycja',
                'Wymaganie zamawiającego',
                'Kod produktu',
                'Produkt',
                'Nazwa w cenniku',
                'Producent',
                'Ilość',
                'Cena zakupu (po upuście)',
                'Cena w ofercie',
                'Marża, %',
                'Wartość pozycji',
                'Ocena dopasowania, %',
                'Sposób dobrania produktu',
                'Dlaczego ten produkt',
                'Zamienniki (kody produktów)',
                'Porównanie zamienników',
                'Link do produktu spoza katalogu',
            ],
        ], null, 'A1');

        $row = 9;
        foreach ($rows as $r) {
            $sh->fromArray([[
                $r['line_no'],
                $r['requirement'],
                $r['sku'],
                $r['product_name'],
                $r['catalog_name'],
                $r['manufacturer'],
                $r['quantity'],
                $r['purchase_price'],
                $r['offer_price'],
                $r['margin_percent'],
                $r['line_value'],
                $r['match_percent'],
                self::matchSourceLabel($r['match_source']),
                $r['match_reasons'],
                $r['substitute_skus'],
                $r['highlights'],
                $r['custom_url'] ?? '',
            ]], null, 'A'.$row);
            $row++;
        }

        $bc = $sheet->createSheet();
        $bc->setTitle('Zamienniki');
        $bc->fromArray([
            ['Pozycja', 'Kod produktu w ofercie', 'Ocena dopasowania, %', 'Zamienniki (kody produktów)', 'Uwagi'],
        ], null, 'A1');
        $bcRow = 2;
        foreach ($rows as $r) {
            $bc->fromArray([[
                $r['line_no'],
                $r['sku'],
                $r['match_percent'],
                $r['substitute_skus'],
                $r['highlights'],
            ]], null, 'A'.$bcRow);
            $bcRow++;
        }

        $sheet->setActiveSheetIndex(0);
        $filename = str_replace('/', '-', $tender->number).'_oferta.xlsx';

        return response()->streamDownload(function () use ($sheet): void {
            (new Xlsx($sheet))->save('php://output');
        }, $filename, [
            'Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
        ]);
    }

    /** Skąd produkt w pozycji — po ludzku; nieznana wartość zostaje bez zmian. */
    private static function matchSourceLabel(?string $source): string
    {
        return match ($source) {
            null, '' => '',
            'ai', 'vector' => 'Dopasowanie automatyczne',
            'ai_substitute' => 'Zamiennik innej marki dobrany automatycznie',
            'heuristic' => 'Dobrany po słowach z karty produktu',
            'manual' => 'Wybrany ręcznie',
            'battlecard' => 'Tańszy zamiennik',
            'custom' => 'Wpisany ręcznie (spoza katalogu)',
            'external' => 'Znaleziony w internecie (spoza katalogu)',
            default => $source,
        };
    }

    public function pdf(Request $request, Tender $tender): Response
    {
        $mask = SupplierSpecialMask::forUser($request->user());
        $rows = $this->offerExport->rows($tender, $mask);
        $tender->loadMissing(['client', 'owner']);

        $pdf = Pdf::loadView('exports.offer', [
            'tender' => $tender,
            'rows' => $rows,
            'tenderMargin' => $this->offerExport->tenderMargin($tender, $mask),
        ])->setPaper('a4', 'landscape');

        $filename = str_replace('/', '-', $tender->number).'_oferta.pdf';

        return $pdf->download($filename);
    }

    public function docx(Tender $tender): BinaryFileResponse|JsonResponse
    {
        try {
            $path = $this->docxFiller->fill($tender);
        } catch (RuntimeException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        $filename = str_replace('/', '-', $tender->number).'_oferta.docx';

        return response()->download($path, $filename, [
            'Content-Type' => 'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
        ])->deleteFileAfterSend(true);
    }
}
