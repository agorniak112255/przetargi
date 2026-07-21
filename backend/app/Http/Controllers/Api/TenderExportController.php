<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Tender;
use App\Services\TenderDocxOfferFiller;
use App\Services\TenderOfferExportService;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Http\JsonResponse;
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

    public function excel(Tender $tender): StreamedResponse
    {
        $rows = $this->offerExport->rows($tender);
        $tender->loadMissing(['client', 'owner']);

        $sheet = new Spreadsheet();
        $sh = $sheet->getActiveSheet();
        $sh->setTitle('Oferta');
        $sh->fromArray([
            ['Numer', $tender->number],
            ['Klient', $tender->client?->name],
            ['Tytuł', $tender->title],
            ['Status', $tender->status],
            ['Marża %', $tender->margin_percent],
            ['Wartość netto', $tender->offer_value_net],
            [],
            [
                'Lp',
                'Wymaganie SIWZ',
                'SKU',
                'Produkt (PL)',
                'Nazwa cennik',
                'Producent',
                'Ilość',
                'Zakup (po upuście)',
                'Cena oferty',
                'Marża %',
                'Wartość',
                'Match %',
                'Źródło match',
                'Uzasadnienie',
                'Zamienniki SKU',
                'Battlecard',
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
                $r['match_source'],
                $r['match_reasons'],
                $r['substitute_skus'],
                $r['highlights'],
            ]], null, 'A'.$row);
            $row++;
        }

        $bc = $sheet->createSheet();
        $bc->setTitle('Battlecard');
        $bc->fromArray([
            ['Lp', 'SKU oferty', 'Match %', 'Zamienniki', 'Highlighty'],
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

    public function pdf(Tender $tender): Response
    {
        $rows = $this->offerExport->rows($tender);
        $tender->loadMissing(['client', 'owner']);

        $pdf = Pdf::loadView('exports.offer', [
            'tender' => $tender,
            'rows' => $rows,
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
