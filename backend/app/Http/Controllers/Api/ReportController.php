<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Tender;
use App\Services\Pricing\SupplierSpecialMask;
use App\Services\Reports\CatalogReport;
use App\Services\Reports\CustomersReport;
use App\Services\Reports\DataSourcesReport;
use App\Services\Reports\PriceMovesReport;
use App\Services\Reports\SalesReport;
use App\Services\Reports\TenderEffectivenessReport;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpFoundation\StreamedResponse;

class ReportController extends Controller
{
    public function summary(Request $request): JsonResponse
    {
        $scopeOwn = ! $request->user()->can('tenders.view_all');
        $margin = $this->marginColumn($request);

        $byStatus = Tender::query()
            ->when($scopeOwn, fn ($q) => $q->accessibleBy($request->user()))
            ->select('status', DB::raw('COUNT(*) as count'), DB::raw('COALESCE(SUM(offer_value_net),0) as offer_value_net'), DB::raw('AVG('.$margin.') as avg_margin'))
            ->groupBy('status')
            ->orderBy('status')
            ->get()
            ->map(static fn ($row) => [
                'status' => $row->status,
                'count' => (int) $row->count,
                'offer_value_net' => round((float) $row->offer_value_net, 2),
                'avg_margin' => $row->avg_margin !== null ? round((float) $row->avg_margin, 1) : null,
            ]);

        $byOwner = Tender::query()
            ->when($scopeOwn, fn ($q) => $q->accessibleBy($request->user()))
            ->join('users', 'users.id', '=', 'tenders.owner_id')
            ->select(
                'tenders.owner_id',
                'users.name as owner_name',
                DB::raw('COUNT(*) as count'),
                DB::raw('COALESCE(SUM(tenders.offer_value_net),0) as offer_value_net'),
                DB::raw('AVG(tenders.'.$margin.') as avg_margin')
            )
            ->groupBy('tenders.owner_id', 'users.name')
            ->orderByDesc('offer_value_net')
            ->get()
            ->map(static fn ($row) => [
                'owner_id' => (int) $row->owner_id,
                'owner_name' => $row->owner_name,
                'count' => (int) $row->count,
                'offer_value_net' => round((float) $row->offer_value_net, 2),
                'avg_margin' => $row->avg_margin !== null ? round((float) $row->avg_margin, 1) : null,
            ]);

        return response()->json([
            'by_status' => $byStatus,
            'by_owner' => $byOwner,
        ]);
    }

    /** Eksport przetargów — ten sam zakres co raport „Sprzedaż i oferty”: bez view_all własne i te z zaproszeniem. */
    public function csv(Request $request): StreamedResponse
    {
        $scopeOwn = ! $request->user()->can('tenders.view_all');
        $margin = $this->marginColumn($request);

        $rows = Tender::query()
            ->when($scopeOwn, fn ($q) => $q->accessibleBy($request->user()))
            ->with(['client:id,name', 'owner:id,name'])
            ->orderByDesc('last_activity_at')
            ->get();

        return response()->streamDownload(static function () use ($rows, $margin): void {
            $out = fopen('php://output', 'w');
            if ($out === false) {
                return;
            }
            // deadline_time, notice_number i result_status dopisane na końcu — wcześniejsze kolumny bez zmian
            fputcsv($out, ['number', 'title', 'client', 'owner', 'status', 'offer_value_net', 'margin_percent', 'deadline', 'ai_percent', 'deadline_time', 'notice_number', 'result_status'], ';');
            foreach ($rows as $t) {
                fputcsv($out, [
                    $t->number,
                    $t->title,
                    $t->client?->name,
                    $t->owner?->name,
                    $t->status,
                    $t->offer_value_net,
                    $t->getAttribute($margin),
                    $t->deadline?->format('Y-m-d'),
                    $t->ai_percent,
                    $t->deadline_time,
                    $t->notice_number,
                    $t->result_status,
                ], ';');
            }
            fclose($out);
        }, 'raport-przetargi.csv', [
            'Content-Type' => 'text/csv; charset=UTF-8',
        ]);
    }

    /** Baza wiedzy: pokrycie kart opisem, zdjęciem, normami i dokumentami (całość i wg producenta). */
    public function catalog(Request $request, CatalogReport $report): JsonResponse
    {
        $this->requireAny($request, ['products.view']);

        return response()->json($report->build($request->user()));
    }

    /** Ruchy cen dostawców w okresie (7/30/90 dni) z maską cen specjalnych. */
    public function prices(Request $request, PriceMovesReport $report): JsonResponse
    {
        $this->requireAny($request, ['products.view']);

        return response()->json($report->build($request->user(), ['days' => $request->integer('days', 30)]));
    }

    /** Świeżość źródeł danych: konta B2B i cenniki z plików. */
    public function sources(Request $request, DataSourcesReport $report): JsonResponse
    {
        $this->requireAny($request, ['price_lists.view', 'b2b_accounts.view']);

        return response()->json($report->build($request->user()));
    }

    /** Sprzedaż i oferty: zapytania klientów, przetargi i kampanie — każda sekcja wg uprawnień użytkownika. */
    public function sales(Request $request, SalesReport $report): JsonResponse
    {
        return response()->json($report->build($request->user(), ['days' => $request->integer('days', 90)]));
    }

    /** Klienci z Comarch ERP XL: aktywność, odpływ, zasięg mailowy (dane jak w kampaniach — campaigns.use). */
    public function customers(Request $request, CustomersReport $report): JsonResponse
    {
        $this->requireAny($request, ['campaigns.use']);

        return response()->json($report->build($request->user()));
    }

    /**
     * Skuteczność przetargów (części wygrane / przegrane, powody, konkurenci) — okres po dacie terminu
     * (period=90d|year), zakres przetargów jak w raporcie „Sprzedaż i oferty”.
     */
    public function effectiveness(Request $request, TenderEffectivenessReport $report): JsonResponse
    {
        $this->requireAny($request, ['tenders.view_own', 'tenders.view_all']);

        return response()->json($report->build($request->user(), ['period' => $request->query('period')]));
    }

    /**
     * Skuteczność przetargów do Excela: CSV ze średnikiem i BOM (polskie znaki w Excelu), wiersz na część zamówienia.
     */
    public function effectivenessCsv(Request $request, TenderEffectivenessReport $report): StreamedResponse
    {
        $this->requireAny($request, ['tenders.view_own', 'tenders.view_all']);
        $user = $request->user();
        $params = ['period' => $request->query('period')];

        return response()->streamDownload(static function () use ($report, $user, $params): void {
            $out = fopen('php://output', 'w');
            if ($out === false) {
                return;
            }
            fwrite($out, "\xEF\xBB\xBF");
            fputcsv($out, $report->headers(), ';');
            foreach ($report->rows($user, $params) as $row) {
                fputcsv($out, $row, ';');
            }
            fclose($out);
        }, 'skutecznosc-przetargow.csv', [
            'Content-Type' => 'text/csv; charset=UTF-8',
        ]);
    }

    /**
     * Raport wymaga, poza reports.view z trasy, uprawnienia do danych, z których powstaje.
     *
     * @param  list<string>  $permissions
     */
    private function requireAny(Request $request, array $permissions): void
    {
        abort_unless($request->user()->canAny($permissions), 403, 'Brak uprawnienia do danych tego raportu.');
    }

    /**
     * Kolumna marży przetargu w widoku użytkownika: bez prices.supplier_special.view marża bliźniacza (od ceny
     * standardowej kart z ceną specjalną B2B — decyzja właściciela 30.09.2026). Stała nazwa, nie wejście użytkownika.
     */
    private function marginColumn(Request $request): string
    {
        return SupplierSpecialMask::forUser($request->user())->hides() ? 'margin_percent_standard' : 'margin_percent';
    }
}
