<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Services\Reports\SalesTargetsReport;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

/**
 * Cele handlowców: GET /reports/targets?month=, PUT /reports/targets/{month} (reports.view + reports.targets.manage
 * — sprawdza trasa; okno: następny miesiąc, bieżący i 11 poprzednich) i własny cel GET /me/sales-target (każdy
 * zalogowany, tylko swój, bieżący miesiąc).
 */
class SalesTargetController extends Controller
{
    public function __construct(private readonly SalesTargetsReport $report) {}

    public function index(Request $request): JsonResponse
    {
        $request->validate([
            'month' => ['sometimes', 'nullable', 'string', 'regex:/^\d{4}-(0[1-9]|1[0-2])$/'],
        ], [
            'month.regex' => 'Miesiąc podaj jako RRRR-MM, na przykład 2026-10.',
        ]);
        $month = (string) ($request->query('month') ?: SalesTargetsReport::currentMonth());
        $this->ensureInWindow($month);

        return response()->json($this->report->build($month));
    }

    public function update(Request $request, string $month): JsonResponse
    {
        if (preg_match('/^\d{4}-(0[1-9]|1[0-2])$/', $month) !== 1) {
            throw ValidationException::withMessages(['month' => 'Miesiąc podaj jako RRRR-MM, na przykład 2026-10.']);
        }
        $this->ensureInWindow($month);

        $data = $request->validate([
            'targets' => ['present', 'array', 'max:500'],
            'targets.*' => ['array:user_id,amount'],
            'targets.*.user_id' => ['required', 'integer', 'distinct', 'exists:users,id'],
            // brak klucza amount = błąd (nie „usuń”) — usunięcie tylko jawnym null
            'targets.*.amount' => ['present', 'nullable', 'numeric', 'gt:0', 'max:999999999999.99'],
        ], [
            'targets.*.user_id.exists' => 'Nie ma takiego użytkownika.',
            'targets.*.user_id.distinct' => 'Ta sama osoba jest na liście dwa razy.',
            'targets.*.amount.gt' => 'Cel musi być większy od zera. Puste pole usuwa cel.',
            'targets.*.amount.numeric' => 'Cel musi być kwotą w złotych.',
            'targets.*.amount.present' => 'Brakuje kwoty celu (puste pole usuwa cel).',
        ]);

        /** @var User $user */
        $user = $request->user();
        /** @var list<array{user_id: int, amount: float|int|string|null}> $targets */
        $targets = array_values($data['targets']);
        $this->report->save($month, $targets, $user);

        return response()->json($this->report->build($month));
    }

    public function mine(Request $request): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();

        return response()->json($this->report->mine($user));
    }

    private function ensureInWindow(string $month): void
    {
        if (! SalesTargetsReport::inWindow($month)) {
            throw ValidationException::withMessages([
                'month' => 'Cele i realizację widać za następny miesiąc, bieżący i '.(SalesTargetsReport::WINDOW_MONTHS - 1).' poprzednich.',
            ]);
        }
    }
}
