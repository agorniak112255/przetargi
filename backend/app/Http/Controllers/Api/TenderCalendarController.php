<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\Tenders\TenderCalendar;
use App\Support\PolishTime;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

/**
 * Kalendarz terminów składania ofert: GET /tenders/calendar?from=&to=&filter= (zakres do 62 dni) — przetargi,
 * które użytkownik może oglądać (view_all albo accessibleBy). Strumień A.
 */
class TenderCalendarController extends Controller
{
    public function __construct(
        private readonly TenderCalendar $calendar,
    ) {}

    public function index(Request $request): JsonResponse
    {
        $data = $request->validate([
            'from' => ['required', 'date_format:Y-m-d'],
            'to' => ['required', 'date_format:Y-m-d', 'after_or_equal:from'],
            'filter' => ['nullable', 'string', 'in:mine,invited'],
        ], [
            'from.required' => 'Podaj pierwszy dzień zakresu (from).',
            'from.date_format' => 'Pierwszy dzień zakresu musi mieć postać RRRR-MM-DD.',
            'to.required' => 'Podaj ostatni dzień zakresu (to).',
            'to.date_format' => 'Ostatni dzień zakresu musi mieć postać RRRR-MM-DD.',
            'to.after_or_equal' => 'Ostatni dzień zakresu nie może być wcześniejszy niż pierwszy.',
            'filter.in' => 'Nieznany filtr. Dozwolone: mine (prowadzę), invited (zaproszono mnie).',
        ]);

        $from = CarbonImmutable::createFromFormat('!Y-m-d', $data['from'], PolishTime::TIMEZONE);
        $to = CarbonImmutable::createFromFormat('!Y-m-d', $data['to'], PolishTime::TIMEZONE);
        if ((int) round($from->diffInDays($to)) > TenderCalendar::MAX_RANGE_DAYS) {
            throw ValidationException::withMessages([
                'to' => ['Kalendarz pokazuje najwyżej '.TenderCalendar::MAX_RANGE_DAYS.' dni naraz.'],
            ]);
        }

        $user = $request->user();
        $userId = (int) $user->id;
        $query = $this->calendar->visibleTo($user);
        // te same filtry co lista przetargów
        $filter = (string) ($data['filter'] ?? '');
        if ($filter === 'mine') {
            $query->where('owner_id', $userId);
        }
        if ($filter === 'invited') {
            $query->whereHas('invitations', static function (Builder $invitations) use ($userId): void {
                $invitations->where('user_id', $userId);
            });
        }

        return response()->json([
            'from' => $from->toDateString(),
            'to' => $to->toDateString(),
            'today' => PolishTime::today()->toDateString(),
            'events' => array_map(static fn (array $row): array => $row['event'], $this->calendar->rows($query, $from, $to)),
        ]);
    }
}
