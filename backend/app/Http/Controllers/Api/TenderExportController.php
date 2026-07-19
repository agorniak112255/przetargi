<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Tender;
use App\Services\TenderDocxOfferFiller;
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
    ) {}

    public function excel(Tender $tender): StreamedResponse
    {
        $tender->load(['client', 'items.mainProduct', 'owner']);

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
            ['Lp', 'Wymaganie', 'SKU', 'Produkt', 'Ilość', 'Cena oferty', 'Marża %', 'Wartość'],
        ], null, 'A1');

        $row = 9;
        foreach ($tender->items as $item) {
            $line = $item->offer_price !== null
                ? (float) $item->offer_price * $item->quantity
                : null;
            $sh->fromArray([[
                $item->line_no,
                $item->requirement,
                $item->mainProduct?->sku,
                $item->mainProduct?->name,
                $item->quantity,
                $item->offer_price,
                $item->margin_percent,
                $line,
            ]], null, 'A'.$row);
            $row++;
        }

        $filename = str_replace('/', '-', $tender->number).'_oferta.xlsx';

        return response()->streamDownload(function () use ($sheet): void {
            (new Xlsx($sheet))->save('php://output');
        }, $filename, [
            'Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
        ]);
    }

    public function pdf(Tender $tender): Response
    {
        $tender->load(['client', 'items.mainProduct', 'owner']);

        $pdf = Pdf::loadView('exports.offer', [
            'tender' => $tender,
        ])->setPaper('a4', 'portrait');

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
