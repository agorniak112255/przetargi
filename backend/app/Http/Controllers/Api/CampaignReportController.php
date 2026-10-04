<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\Campaigns\CampaignReportScope;
use App\Services\Reports\CampaignsReport;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Raport „Wynik kampanii” (Raporty → zakładka). KONTRAKT zamrożony 04.10.2026.
 * GET /api/reports/campaigns?month=RRRR-MM[&user_id=N] — JSON CampaignsReport (frontend/src/lib/reports.ts)
 * GET /api/reports/campaigns/csv?month=RRRR-MM[&user_id=N] — pozycje faktur przypisane kampaniom (audyt premii)
 * Dostęp tylko z zakresem CampaignReportScope (bez reports.view): brak zakresu albo osoba spoza niego — 403;
 * miesiąc spoza okna (bieżący i 11 poprzednich) — 422.
 */
class CampaignReportController extends Controller
{
    public function __construct(
        private readonly CampaignsReport $report,
        private readonly CampaignReportScope $scope,
    ) {}

    public function index(Request $request): JsonResponse
    {
        [$month, $scope, $userId] = $this->params($request);

        return response()->json($this->report->build($month, $scope, $request->user(), $userId));
    }

    public function csv(Request $request): StreamedResponse
    {
        [$month, $scope, $userId] = $this->params($request);
        $report = $this->report;

        return response()->streamDownload(static function () use ($report, $month, $scope, $userId): void {
            $out = fopen('php://output', 'w');
            if ($out === false) {
                return;
            }
            fwrite($out, "\xEF\xBB\xBF");
            fputcsv($out, CampaignsReport::csvHeaders(), ';');
            foreach ($report->csvRows($month, $scope, $userId) as $row) {
                fputcsv($out, $row, ';');
            }
            fclose($out);
        }, 'wynik-kampanii-'.$month.'.csv', [
            'Content-Type' => 'text/csv; charset=UTF-8',
        ]);
    }

    /**
     * Zakres patrzącego, miesiąc i filtr osoby — wspólne dla JSON i CSV.
     *
     * @return array{0: string, 1: array{mode: string, user_ids: list<int>|null}, 2: int|null}
     */
    private function params(Request $request): array
    {
        $viewer = $request->user();
        $scope = $this->scope->for($viewer);
        abort_if($scope === null, 403, 'Brak dostępu do wyniku kampanii.');

        $request->validate([
            'month' => ['sometimes', 'nullable', 'string', 'regex:/^\d{4}-(0[1-9]|1[0-2])$/'],
            'user_id' => ['sometimes', 'nullable', 'integer', 'min:1'],
        ], [
            'month.regex' => 'Miesiąc podaj jako RRRR-MM, na przykład 2026-10.',
            'user_id.integer' => 'Osobę wskaż numerem.',
        ]);
        $month = (string) ($request->query('month') ?: CampaignsReport::currentMonth());
        if (! CampaignsReport::inWindow($month)) {
            throw ValidationException::withMessages(['month' => 'Raport obejmuje bieżący miesiąc i 11 poprzednich.']);
        }

        $userId = $request->filled('user_id') ? (int) $request->query('user_id') : null;
        abort_if($userId !== null && ! $this->scope->allows($viewer, $userId), 403, 'Ta osoba jest poza Twoim zakresem raportu.');

        return [$month, $scope, $userId];
    }
}
