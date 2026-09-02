<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Product;
use App\Models\Tender;
use App\Models\TenderItem;
use App\Services\TenderPricingService;
use App\Services\TenderWorkflowService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use PhpOffice\PhpSpreadsheet\IOFactory;

class TenderImportController extends Controller
{
    public function __construct(
        private readonly TenderPricingService $pricing,
        private readonly TenderWorkflowService $workflow,
    ) {}

    public function store(Request $request, Tender $tender): JsonResponse
    {
        if (! $this->workflow->canEditOffer($tender)) {
            throw ValidationException::withMessages([
                'tender' => ['Import zablokowany — status: '.$tender->status],
            ]);
        }

        $request->validate([
            'file' => ['required', 'file', 'mimes:xlsx,xls,csv', 'max:10240'],
            'replace' => ['sometimes', 'boolean'],
        ]);

        $path = $request->file('file')->getRealPath();
        if ($path === false) {
            throw ValidationException::withMessages(['file' => ['Nie można odczytać pliku.']]);
        }

        $sheet = IOFactory::load($path)->getActiveSheet();
        $rows = $sheet->toArray(null, true, true, false);

        if ($rows === []) {
            throw ValidationException::withMessages(['file' => ['Plik jest pusty.']]);
        }

        $header = array_map(
            static fn ($v) => mb_strtolower(trim((string) $v)),
            $rows[0] ?? []
        );

        $map = $this->resolveColumns($header);
        $dataRows = array_slice($rows, 1);
        $created = 0;
        $errors = [];

        DB::transaction(function () use ($request, $tender, $dataRows, $map, &$created, &$errors): void {
            if ($request->boolean('replace', true)) {
                $tender->items()->delete();
            }

            $lineNo = (int) ($tender->items()->max('line_no') ?? 0);

            foreach ($dataRows as $index => $row) {
                $excelRow = $index + 2;
                $requirement = trim((string) ($row[$map['requirement']] ?? ''));
                if ($requirement === '') {
                    continue;
                }

                $quantity = 1;
                if (isset($map['quantity'])) {
                    $quantity = max(1, (int) ($row[$map['quantity']] ?? 1));
                }

                $sku = isset($map['sku']) ? trim((string) ($row[$map['sku']] ?? '')) : '';
                $offerRaw = isset($map['offer_price']) ? $row[$map['offer_price']] : null;

                $product = null;
                if ($sku !== '') {
                    $product = Product::query()->where('sku', $sku)->first();
                    if ($product === null) {
                        $errors[] = "Wiersz {$excelRow}: nie znaleziono SKU {$sku}";
                    }
                }

                $lineNo++;
                $item = new TenderItem([
                    'tender_id' => $tender->id,
                    'line_no' => $lineNo,
                    'requirement' => $requirement,
                    'main_product_id' => $product?->id,
                    'quantity' => $quantity,
                    'ai_match_percent' => $product ? 100 : null,
                    'status' => $product ? 'matched' : 'brak',
                ]);

                if ($offerRaw !== null && $offerRaw !== '') {
                    $item->offer_price = (float) str_replace(',', '.', (string) $offerRaw);
                } elseif ($product !== null) {
                    $item->offer_price = $this->pricing->offerFromProduct($tender, $product);
                }

                $item->save();
                $this->pricing->recalculateItemMargin($item);
                $created++;
            }
        });

        if ($tender->status === 'draft') {
            $tender->status = 'wycena';
        }

        $this->pricing->recalculateTenderTotals($tender->fresh());
        $tender->last_activity_at = now();
        $tender->save();

        return response()->json([
            'imported' => $created,
            'errors' => $errors,
            'tender_id' => $tender->id,
        ]);
    }

    /**
     * @param  list<string>  $header
     * @return array{requirement: int, quantity?: int, sku?: int, offer_price?: int}
     */
    private function resolveColumns(array $header): array
    {
        $find = static function (array $aliases) use ($header): ?int {
            foreach ($header as $i => $col) {
                foreach ($aliases as $alias) {
                    if ($col === $alias || str_contains($col, $alias)) {
                        return $i;
                    }
                }
            }

            return null;
        };

        $requirement = $find(['wymaganie', 'requirement', 'opis', 'siwz', 'pozycja', 'nazwa']) ?? 0;
        $quantity = $find(['ilosc', 'ilość', 'quantity', 'qty', 'szt']);
        $sku = $find(['sku', 'kod', 'code', 'indeks']);
        $offer = $find(['cena', 'oferta', 'offer', 'price']);

        $map = ['requirement' => $requirement];
        if ($quantity !== null) {
            $map['quantity'] = $quantity;
        }
        if ($sku !== null) {
            $map['sku'] = $sku;
        }
        if ($offer !== null) {
            $map['offer_price'] = $offer;
        }

        return $map;
    }
}
