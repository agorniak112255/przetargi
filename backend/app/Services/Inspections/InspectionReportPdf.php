<?php

declare(strict_types=1);

namespace App\Services\Inspections;

use App\Models\User;
use App\Support\PolishTime;
use Barryvdh\DomPDF\Facade\Pdf;

/**
 * Raport PDF modułu Przeglądy dla zaznaczonych klientów (wewnętrzny — z wartością netto ostatniego przeglądu lub zakupu):
 * dla każdego klienta wszystkie jego terminy (bez okna listy, także pominięte — z adnotacją). Dompdf + DejaVu Sans
 * (standardowe czcionki PDF nie mają ą, ę, ś), widok resources/views/inspections/report.blade.php.
 */
class InspectionReportPdf
{
    public function __construct(private readonly InspectionQuery $query) {}

    /** @param  list<int>  $customerGids  kolejność klientów w raporcie */
    public function render(array $customerGids, User $author): string
    {
        return Pdf::loadView('inspections.report', [
            'generatedAt' => PolishTime::now()->format('d.m.Y H:i'),
            'author' => (string) $author->name,
            'customers' => $this->sections($customerGids),
        ])
            ->setPaper('a4', 'landscape')
            ->setOption('isFontSubsettingEnabled', true)
            ->output();
    }

    /**
     * @param  list<int>  $customerGids
     * @return list<array{customer: array<string, mixed>, dismissal: array<string, mixed>|null, positions: list<array<string, mixed>>}>
     */
    public function sections(array $customerGids): array
    {
        $today = PolishTime::today();
        $rows = $this->query->dueRows($this->query->filtered(
            ['days' => null, 'status' => 'all', 'old' => true, 'dismissed' => true, 'customer_xl_gids' => $customerGids],
            $today,
        ));
        $byCustomer = [];
        $recipients = [];
        foreach ($rows as $row) {
            $byCustomer[(int) $row->customer_xl_gid][] = $row;
            if ($row->recipient_xl_gid !== null) {
                $recipients[] = (int) $row->recipient_xl_gid;
            }
        }
        $info = $this->query->customers([...$customerGids, ...$recipients]);
        $dismissals = $this->query->activeDismissals($customerGids, $today);

        $out = [];
        foreach ($customerGids as $gid) {
            $positions = [];
            foreach ($byCustomer[$gid] ?? [] as $row) {
                $p = $this->query->presentPosition($row, $today, $info, $dismissals[$gid]['positions'][(int) $row->inspection_position_id] ?? null);
                $p['state_label'] = InspectionQuery::stateLabel($p['status'], $p['days_left'], $p['overdue_days']);
                $p['type_label'] = InspectionQuery::typeLabel($p['xl_type']);
                $p['dismissal_label'] = $p['dismissal'] !== null ? $this->dismissalLabel($p['dismissal']) : null;
                $positions[] = $p;
            }
            $customerDismissal = $dismissals[$gid]['customer'] ?? null;
            if ($customerDismissal !== null) {
                $customerDismissal['label'] = $this->dismissalLabel($customerDismissal);
            }
            $out[] = [
                'customer' => $this->query->presentCustomer($gid, $info[$gid] ?? null),
                'dismissal' => $customerDismissal,
                'positions' => $positions,
            ];
        }

        return $out;
    }

    /** @param  array<string, mixed>  $dismissal */
    private function dismissalLabel(array $dismissal): string
    {
        return 'Pominięty: '.InspectionQuery::reasonLabel((string) $dismissal['reason'])
            .($dismissal['until_on'] !== null ? ' do '.$dismissal['until_on'] : ' na zawsze');
    }
}
