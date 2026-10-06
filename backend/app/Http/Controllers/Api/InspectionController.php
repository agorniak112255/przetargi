<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\InspectionDismissal;
use App\Models\InspectionPosition;
use App\Models\User;
use App\Services\Inspections\InspectionQuery;
use App\Services\Inspections\InspectionReportPdf;
use App\Support\PolishTime;
use App\Support\XlsxStreamWriter;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

/**
 * Moduł „Przeglądy” — lista terminów u klientów (z faktur ERP XL, wyliczona przez InspectionDueBuilder), szczegóły
 * klienta, eksport do Excela, raport PDF i pomijanie klientów. Niczego nie zapisuje w XL. Termin to „ostatni przegląd
 * lub zakup + interwał pozycji” — interwał wpisał człowiek; stan (zaległy / nadchodzący) liczony na dziś w Polsce.
 */
class InspectionController extends Controller
{
    public function __construct(private readonly InspectionQuery $query) {}

    public function index(Request $request): JsonResponse
    {
        $v = $request->validate($this->listRules(), $this->messages());
        /** @var User $user */
        $user = $request->user();
        $today = PolishTime::today();
        [$f, $mineUnavailable] = $this->filters($v, $user);
        $page = (int) ($v['page'] ?? 1);
        $perPage = (int) ($v['per_page'] ?? 50);
        $sort = (string) ($v['sort'] ?? 'due');

        $total = 0;
        $data = [];
        if (! $mineUnavailable) {
            $result = $this->query->customerPage($f, $today, $page, $perPage, $sort);
            $total = $result['total'];
            $data = $this->query->groups($f, $today, $result['customers']);
        }

        return response()->json([
            'data' => $data,
            'meta' => [
                'total' => $total,
                'page' => $page,
                'per_page' => $perPage,
                'today' => $today->toDateString(),
                'days' => $f['days'],
                'mine_unavailable' => $mineUnavailable,
                'locations' => $this->query->locations(),
                'positions' => InspectionPosition::query()->where('active', true)->orderBy('name')->orderBy('id')
                    ->get(['id', 'name', 'interval_months'])
                    ->map(static fn (InspectionPosition $p): array => ['id' => (int) $p->id, 'name' => (string) $p->name, 'interval_months' => (int) $p->interval_months])
                    ->all(),
                'permissions' => [
                    'manage' => $user->can('inspections.manage'),
                    'offer' => $user->can('inspections.offer'),
                ],
            ],
        ]);
    }

    /**
     * Szczegóły klienta: wszystkie jego terminy (bez okna i z pominiętymi), sprzedaż pozycji i usług odnawiających
     * z faktur XL, oferty przeglądu i pominięcia (także wygasłe).
     */
    public function show(int $xlGid): JsonResponse
    {
        $today = PolishTime::today();
        $info = $this->query->customers([$xlGid]);
        $rows = $this->query->dueRows($this->query->filtered($this->allRowsFilter([$xlGid]), $today));
        $hasSales = DB::table('inspection_sale_lines')->where('customer_xl_gid', $xlGid)->exists();
        if (! isset($info[$xlGid]) && $rows === [] && ! $hasSales) {
            abort(404, 'Nie ma takiego klienta w przeglądach.');
        }

        $recipientGids = array_values(array_filter(array_map(static fn (object $r): ?int => $r->recipient_xl_gid !== null ? (int) $r->recipient_xl_gid : null, $rows)));
        $info += $this->query->customers($recipientGids);
        $dismissals = $this->query->activeDismissals([$xlGid], $today)[$xlGid] ?? ['positions' => []];

        $allDismissals = $this->query->dismissalQuery()
            ->where('x.customer_xl_gid', $xlGid)
            ->orderByDesc('x.id')
            ->get()
            ->map(function (object $row) use ($today): array {
                $d = $this->query->presentDismissal($row);
                $d['active'] = $d['until_on'] === null || $d['until_on'] >= $today->toDateString();

                return $d;
            })
            ->all();

        return response()->json([
            'customer' => $this->query->presentCustomer($xlGid, $info[$xlGid] ?? null),
            'positions' => array_map(
                fn (object $row): array => $this->query->presentPosition($row, $today, $info, $dismissals['positions'][(int) $row->inspection_position_id] ?? null),
                $rows,
            ),
            'dismissal' => $dismissals['customer'] ?? null,
            'history' => $this->history($xlGid),
            'offers' => $this->query->offerQuery()
                ->where('o.customer_xl_gid', $xlGid)
                ->orderByDesc('o.id')
                ->limit(100)
                ->get()
                ->map(fn (object $row): array => $this->query->presentOffer($row))
                ->all(),
            'dismissals' => $allDismissals,
        ]);
    }

    /**
     * Excel: te same filtry co lista (wszystkie strony) albo — gdy podano zaznaczonych klientów — wszystkie ich terminy
     * bez filtrów listy, jak raport PDF (zaznaczenie przeżywa zmianę filtrów; klient zaznaczony przy innym oknie nie może
     * po cichu wypaść z pliku). Wiersz = klient × pozycja.
     */
    public function export(Request $request): BinaryFileResponse
    {
        $v = $request->validate([
            ...$this->listRules(),
            // jak raport PDF — numery idą w adresie (GET), więcej nie zmieści się w limicie długości adresu
            'customer_xl_gids' => ['nullable', 'array', 'max:200'],
            'customer_xl_gids.*' => ['integer', 'min:1'],
        ], [
            ...$this->messages(),
            'customer_xl_gids.max' => 'Eksport zaznaczonych mieści najwyżej :max klientów — zaznacz mniej albo wyczyść zaznaczenie, żeby wyeksportować całą listę.',
        ]);
        /** @var User $user */
        $user = $request->user();
        $today = PolishTime::today();
        [$f, $mineUnavailable] = $this->filters($v, $user);
        if (isset($v['customer_xl_gids']) && $v['customer_xl_gids'] !== []) {
            $f = [
                ...$f,
                'location' => null, 'position_id' => null, 'ident' => null, 'q' => '', 'with_email' => false,
                ...$this->allRowsFilter(array_values(array_unique(array_map('intval', $v['customer_xl_gids'])))),
            ];
            $mineUnavailable = false;
        }
        $customers = $mineUnavailable ? [] : $this->query->allCustomers($f, $today, (string) ($v['sort'] ?? 'due'));

        $path = tempnam(sys_get_temp_dir(), 'insp');
        if ($path === false) {
            abort(500, 'Nie udało się przygotować pliku.');
        }
        $xlsx = new XlsxStreamWriter($path);
        $xlsx->addSheet('Przeglądy', [16, 40, 14, 18, 36, 12, 14, 48, 9, 9, 12, 14, 18, 12, 12, 14, 14, 14, 30, 14, 16, 14, 30, 22, 30, 30], header: true);
        $xlsx->addRow([
            'Klient (akronim)', 'Nazwa klienta', 'NIP', 'Miasto', 'Adresy e-mail', 'Numer klienta w ERP XL',
            'Kod pozycji', 'Pozycja (usługa lub towar)', 'Rodzaj', 'Jednostka', 'Interwał w miesiącach',
            'Termin przeglądu', 'Stan', 'Ilość do przeglądu', 'Liczba sprzedaży bez przeglądu',
            'Ostatni przegląd lub zakup', 'Ilość w ostatnim', 'Wartość netto ostatniego', 'Faktury ostatniego',
            'Pierwsza sprzedaż', 'Oddział', 'Operator ERP XL', 'Odbiorca (inny niż nabywca)',
            'Inna karta z tym NIP-em ma późniejszą sprzedaż', 'Pominięcie', 'Ostatnia wysłana oferta przeglądu',
        ]);
        $rowsCount = 0;
        foreach (array_chunk($customers, 300) as $chunk) {
            foreach ($this->query->groups($f, $today, $chunk) as $group) {
                $c = $group['customer'];
                $offer = $group['last_offer'];
                $offerText = $offer !== null
                    ? trim(($offer['code'] ?? '').' '.substr((string) $offer['sent_at'], 0, 10).' '.($offer['user_name'] ?? ''))
                    : null;
                foreach ($group['positions'] as $p) {
                    $dismissal = $p['dismissal'] ?? $group['dismissal'];
                    $xlsx->addRow([
                        $c['acronym'], $c['name'], $c['nip'], $c['city'], implode(', ', $c['emails']), $c['xl_gid'],
                        $p['code'], $p['name'], InspectionQuery::typeLabel($p['xl_type']), $p['unit'], $p['interval_months'],
                        XlsxStreamWriter::date($p['due_on']), InspectionQuery::stateLabel($p['status'], $p['days_left'], $p['overdue_days']),
                        $p['open_quantity'], $p['open_count'],
                        XlsxStreamWriter::date($p['last_on']), $p['last_quantity'], $p['last_net'],
                        implode(', ', array_map(static fn (array $d): string => (string) ($d['number'] ?? ''), $p['last_documents'])),
                        XlsxStreamWriter::date($p['first_on']),
                        $p['location_name'], $p['operator_ident'], $p['recipient']['name'] ?? null,
                        $p['same_nip_newer'] ? 'tak' : 'nie',
                        $dismissal !== null ? $this->dismissalText($dismissal) : null,
                        $offerText,
                    ]);
                    $rowsCount++;
                }
            }
        }

        $xlsx->addSheet('Zestawienie', [40, 40]);
        $xlsx->addRow(['Przeglądy — eksport', XlsxStreamWriter::dateTime(PolishTime::now())]);
        $xlsx->addRow(['Przygotował', (string) $user->name]);
        $xlsx->addRow(['Klientów w pliku', count($customers)]);
        $xlsx->addRow(['Wierszy (klient × pozycja)', $rowsCount]);
        $xlsx->addRow(['Termin', $f['days'] !== null ? 'w ciągu '.$f['days'].' dni (i zaległe)' : 'bez ograniczenia']);
        $xlsx->addRow(['Starsze zaległe (ponad 3 interwały)', $f['old'] ? 'pokazane' : 'ukryte']);
        $xlsx->addRow(['Pominięci klienci', $f['dismissed'] ? 'pokazani' : 'ukryci']);
        $xlsx->addRow(['Źródło', 'faktury sprzedaży z ERP XL; termin wyliczony: ostatni przegląd lub zakup + interwał pozycji']);
        $xlsx->close();

        return response()->download($path, 'przeglady-'.PolishTime::now()->format('Y-m-d').'.xlsx', [
            'Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
        ])->deleteFileAfterSend();
    }

    /** Raport PDF dla zaznaczonych klientów (wewnętrzny — z wartością netto ostatniego przeglądu). */
    public function report(Request $request, InspectionReportPdf $pdf): Response
    {
        $v = $request->validate([
            'customer_xl_gids' => ['required', 'array', 'min:1', 'max:200'],
            'customer_xl_gids.*' => ['integer', 'min:1'],
        ], [
            ...$this->messages(),
            'customer_xl_gids.required' => 'Zaznacz co najmniej jednego klienta.',
            'customer_xl_gids.min' => 'Zaznacz co najmniej jednego klienta.',
            'customer_xl_gids.max' => 'Raport mieści najwyżej :max klientów — zaznacz mniej.',
        ]);
        /** @var User $user */
        $user = $request->user();
        $gids = array_values(array_unique(array_map('intval', $v['customer_xl_gids'])));

        return response($pdf->render($gids, $user), 200, [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => 'attachment; filename="przeglady-raport-'.PolishTime::now()->format('Y-m-d').'.pdf"',
        ]);
    }

    /** Pominięcie klienta (position_id null) albo jednej pozycji klienta; ten sam zakres — nadpisuje poprzednie. */
    public function storeDismissal(Request $request): JsonResponse
    {
        $today = PolishTime::today();
        $v = $request->validate([
            'customer_xl_gid' => ['required', 'integer', 'min:1'],
            'position_id' => ['nullable', 'integer', Rule::exists('inspection_positions', 'id')],
            'until_on' => ['nullable', 'date_format:Y-m-d', 'after:'.$today->toDateString()],
            'reason' => ['required', 'string', Rule::in(InspectionDismissal::REASONS)],
            'note' => ['nullable', 'string', 'max:300'],
        ], [
            ...$this->messages(),
            'customer_xl_gid.required' => 'Brak klienta do pominięcia.',
            'position_id.exists' => 'Tej pozycji nie ma już na liście przeglądów — odśwież stronę.',
            'until_on.after' => 'Data „pomiń do” musi być późniejsza niż dziś.',
            'until_on.date_format' => 'Podaj datę „pomiń do” w formacie RRRR-MM-DD.',
            'reason.required' => 'Wybierz powód pominięcia.',
            'reason.in' => 'Wybierz powód pominięcia z listy.',
            'note.max' => 'Uwaga może mieć najwyżej :max znaków.',
        ]);
        $gid = (int) $v['customer_xl_gid'];
        $known = DB::table('inspection_due')->where('customer_xl_gid', $gid)->exists()
            || DB::table('erp_customers')->where('xl_gid', $gid)->exists();
        if (! $known) {
            throw ValidationException::withMessages(['customer_xl_gid' => 'Nie ma takiego klienta w przeglądach — odśwież stronę.']);
        }
        /** @var User $user */
        $user = $request->user();
        $positionId = isset($v['position_id']) ? (int) $v['position_id'] : null;

        $dismissal = InspectionDismissal::query()->updateOrCreate(
            ['customer_xl_gid' => $gid, 'inspection_position_id' => $positionId],
            [
                'until_on' => $v['until_on'] ?? null,
                'reason' => (string) $v['reason'],
                'note' => isset($v['note']) && trim((string) $v['note']) !== '' ? trim((string) $v['note']) : null,
                'user_id' => $user->id,
            ],
        );
        $row = $this->query->dismissalQuery()->where('x.id', $dismissal->id)->first();

        return response()->json(['dismissal' => $row !== null ? $this->query->presentDismissal($row) : null], 201);
    }

    public function destroyDismissal(int $id): Response
    {
        $dismissal = InspectionDismissal::query()->find($id);
        if ($dismissal === null) {
            abort(404, 'To pominięcie już nie istnieje.');
        }
        $dismissal->delete();

        return response()->noContent();
    }

    /**
     * Sprzedaż klienta z faktur XL: pozycje z listy przeglądów i usługi, które je odnawiają — od najnowszych.
     *
     * @return list<array<string, mixed>>
     */
    private function history(int $xlGid): array
    {
        $positions = InspectionPosition::query()->get(['xl_gid', 'code', 'name', 'renewed_by_xl_gid']);
        $names = [];
        foreach ($positions as $p) {
            $names[(int) $p->xl_gid] = ['code' => (string) $p->code, 'name' => (string) $p->name];
        }
        $renewing = $positions->pluck('renewed_by_xl_gid')->filter()->map(static fn ($g): int => (int) $g)->unique()->values()->all();
        $missing = array_values(array_diff($renewing, array_keys($names)));
        if ($missing !== []) {
            foreach (DB::table('erp_services')->whereIn('xl_gid', $missing)->get(['xl_gid', 'code', 'name']) as $s) {
                $names[(int) $s->xl_gid] = ['code' => (string) $s->code, 'name' => (string) $s->name];
            }
        }
        $gids = array_values(array_unique([...array_keys($names), ...$renewing]));
        if ($gids === []) {
            return [];
        }

        return DB::table('inspection_sale_lines')
            ->where('customer_xl_gid', $xlGid)
            ->whereIn('xl_item_gid', $gids)
            ->orderByDesc('issued_on')
            ->orderByDesc('document_id')
            ->orderBy('line')
            ->limit(InspectionQuery::HISTORY_LIMIT)
            ->get()
            ->map(static fn (object $r): array => [
                'issued_on' => substr((string) $r->issued_on, 0, 10),
                'sold_on' => $r->sold_on !== null ? substr((string) $r->sold_on, 0, 10) : null,
                'document_number' => (string) $r->document_number,
                'xl_gid' => (int) $r->xl_item_gid,
                'code' => $names[(int) $r->xl_item_gid]['code'] ?? null,
                'name' => $names[(int) $r->xl_item_gid]['name'] ?? null,
                'quantity' => (float) $r->quantity,
                'net_value' => (float) $r->net_value,
                'is_correction' => $r->corrects_document_type !== null || in_array((int) $r->document_type, [2041, 2045], true),
                'location' => $r->location !== null ? (string) $r->location : null,
                'operator_ident' => $r->operator_ident !== null ? (string) $r->operator_ident : null,
            ])
            ->all();
    }

    /**
     * Filtry listy z parametrów; „moi klienci” bez operatora XL przy koncie — pusta lista (mine_unavailable).
     *
     * @param  array<string, mixed>  $v
     * @return array{0: array<string, mixed>, 1: bool}
     */
    private function filters(array $v, User $user): array
    {
        $mine = (bool) ($v['mine'] ?? false);
        $ident = $mine ? strtoupper(trim((string) $user->getAttribute('erp_operator_ident'))) : null;
        $f = [
            'days' => (int) ($v['days'] ?? InspectionQuery::DEFAULT_DAYS),
            'status' => (string) ($v['status'] ?? 'all'),
            'old' => (bool) ($v['old'] ?? false),
            'location' => isset($v['location']) && $v['location'] !== '' ? (string) $v['location'] : null,
            'position_id' => isset($v['position_id']) ? (int) $v['position_id'] : null,
            'ident' => $ident !== '' ? $ident : null,
            'q' => (string) ($v['q'] ?? ''),
            'with_email' => (bool) ($v['with_email'] ?? false),
            'dismissed' => (bool) ($v['dismissed'] ?? false),
        ];

        return [$f, $mine && $ident === ''];
    }

    /**
     * Wszystkie terminy klientów — bez okna, ze starymi zaległymi i pominiętymi (szczegóły, raport).
     *
     * @param  list<int>  $customers
     * @return array<string, mixed>
     */
    private function allRowsFilter(array $customers): array
    {
        return ['days' => null, 'status' => 'all', 'old' => true, 'dismissed' => true, 'customer_xl_gids' => $customers];
    }

    /** @param  array<string, mixed>  $dismissal */
    private function dismissalText(array $dismissal): string
    {
        $text = InspectionQuery::reasonLabel((string) $dismissal['reason'])
            .($dismissal['until_on'] !== null ? ' do '.$dismissal['until_on'] : ' na zawsze');

        return $dismissal['note'] !== null ? $text.' ('.$dismissal['note'].')' : $text;
    }

    /** @return array<string, list<mixed>> */
    private function listRules(): array
    {
        return [
            'days' => ['nullable', 'integer', 'min:0', 'max:'.InspectionQuery::MAX_DAYS],
            'status' => ['nullable', 'string', Rule::in(InspectionQuery::STATUSES)],
            'old' => ['nullable', 'boolean'],
            // oddział: cyfry z początku kodu magazynu (01 = Rzeszów)
            'location' => ['nullable', 'string', 'regex:/^\d{1,10}$/'],
            'position_id' => ['nullable', 'integer', 'min:1'],
            'mine' => ['nullable', 'boolean'],
            'q' => ['nullable', 'string', 'max:150'],
            'with_email' => ['nullable', 'boolean'],
            'dismissed' => ['nullable', 'boolean'],
            'page' => ['nullable', 'integer', 'min:1'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:200'],
            'sort' => ['nullable', 'string', Rule::in(InspectionQuery::SORTS)],
        ];
    }

    /**
     * Komunikaty po polsku (aplikacja nie ma tłumaczeń walidacji) — filtry ustawia ekran, więc ogólne zdania wystarczą.
     *
     * @return array<string, string>
     */
    private function messages(): array
    {
        return [
            'integer' => 'Pole :attribute musi być liczbą całkowitą.',
            'boolean' => 'Pole :attribute musi mieć wartość 0 albo 1.',
            'in' => 'Nieznana wartość pola :attribute.',
            'min' => 'Pole :attribute jest za małe (najmniej :min).',
            'max' => 'Pole :attribute jest za duże (najwyżej :max).',
            'regex' => 'Nieznany oddział.',
            'array' => 'Pole :attribute musi być listą.',
            'string' => 'Pole :attribute musi być tekstem.',
            'days.max' => 'Termin można ustawić najwyżej na :max dni naprzód.',
            'per_page.max' => 'Na stronie mieści się najwyżej :max klientów.',
        ];
    }
}
