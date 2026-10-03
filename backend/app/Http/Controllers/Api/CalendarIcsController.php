<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Tender;
use App\Models\User;
use App\Services\Calendar\IcsBuilder;
use App\Services\Tenders\TenderCalendar;
use App\Support\PolishTime;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpFoundation\Response;

/**
 * Publiczny plik kalendarza GET /calendar/{token}.ics (poza logowaniem i dziennikiem aktywności, limit 60/min).
 * Uprawnienia właściciela adresu sprawdzane przy każdym pobraniu; bez cen. Strumień A.
 *
 * Nieznany klucz (także stary, unieważniony) → 404. Właściciel bez tenders.view_own i tenders.view_all albo z zakresem
 * „wszystkie” bez tenders.view_all → 403 (adres zostaje, zadziała znowu po przywróceniu uprawnienia). Zakres „moje” to
 * przetargi prowadzone i te, do których zaproszono — także u osoby, która widzi wszystkie.
 */
class CalendarIcsController extends Controller
{
    /** Okno terminów w pliku: od 90 dni wstecz do roku naprzód (czas polski). */
    private const PAST_DAYS = 90;

    private const FUTURE_DAYS = 365;

    private const EVENT_MINUTES = 30;

    /** Ślad ostatniego pobrania zapisujemy najwyżej raz na kwadrans — programy pobierają plik co kilka minut. */
    private const USED_AT_RESOLUTION_MINUTES = 15;

    public function __construct(
        private readonly TenderCalendar $calendar,
        private readonly IcsBuilder $ics,
    ) {}

    public function show(Request $request, string $token): Response
    {
        $user = User::query()->where('calendar_token_hash', hash('sha256', $token))->first();
        if ($user === null) {
            return $this->plain('Nie ma takiego kalendarza. Adres mógł zostać wyłączony albo zastąpiony nowym.', 404);
        }
        $scope = $user->calendar_scope === 'all' ? 'all' : 'mine';
        if (! $user->canAny(['tenders.view_own', 'tenders.view_all']) || ($scope === 'all' && ! $user->can('tenders.view_all'))) {
            return $this->plain('Właściciel tego kalendarza nie ma już dostępu do tych przetargów.', 403);
        }

        $query = $scope === 'all' ? Tender::query() : Tender::query()->accessibleBy($user);
        $query->where('status', '!=', 'odrzucony');
        $today = PolishTime::today();
        $rows = $this->calendar->rows($query, $today->subDays(self::PAST_DAYS), $today->addDays(self::FUTURE_DAYS));

        $appUrl = rtrim((string) config('app.frontend_url'), '/');
        $host = parse_url((string) config('app.url'), PHP_URL_HOST);
        $host = is_string($host) && $host !== '' ? $host : 'przetargi';
        $events = [];
        foreach ($rows as ['tender' => $tender, 'event' => $event]) {
            $link = $appUrl.'/tenders/'.$event['tender_id'];
            $events[] = [
                'uid' => 'tender-'.$event['tender_id'].'-deadline@'.$host,
                'summary' => 'Termin składania ofert: '.$event['title'],
                'description' => $this->description($tender, $event, $link),
                'url' => $link,
                'date' => $event['date'],
                'time' => $event['time'],
                'duration_minutes' => self::EVENT_MINUTES,
                'last_modified' => $tender->updated_at,
            ];
        }
        $name = $scope === 'all' ? 'Przetargi: terminy składania ofert (wszystkie)' : 'Przetargi: terminy składania ofert (moje)';

        $this->touchUsedAt($user);

        return response($this->ics->calendar($name, $events), 200, [
            'Content-Type' => 'text/calendar; charset=utf-8',
            'Content-Disposition' => 'inline; filename="przetargi.ics"',
            'Cache-Control' => 'private, no-store',
            'X-Robots-Tag' => 'noindex',
        ]);
    }

    /**
     * Numer, tytuł, zamawiający, termin i link — bez cen i wartości oferty.
     *
     * @param  array<string, mixed>  $event
     */
    private function description(Tender $tender, array $event, string $link): string
    {
        $lines = ['Przetarg: '.$event['number']];
        if ($event['notice_number'] !== null && $event['notice_number'] !== '') {
            $lines[] = 'Numer ogłoszenia: '.$event['notice_number'];
        }
        $lines[] = 'Tytuł: '.$event['title'];
        if ($event['client'] !== null && $event['client'] !== '') {
            $lines[] = 'Zamawiający: '.$event['client'];
        }
        $lines[] = 'Termin składania ofert: '.PolishTime::formatDeadline($tender);
        $lines[] = 'Otwórz w aplikacji: '.$link;

        return implode("\n", $lines);
    }

    private function touchUsedAt(User $user): void
    {
        $last = $user->calendar_used_at;
        if ($last !== null && $last->greaterThan(now()->subMinutes(self::USED_AT_RESOLUTION_MINUTES))) {
            return;
        }
        // bez updated_at konta — pobranie kalendarza to nie zmiana danych użytkownika
        DB::table('users')->where('id', $user->id)->update(['calendar_used_at' => now()]);
    }

    private function plain(string $message, int $status): Response
    {
        return response($message, $status, [
            'Content-Type' => 'text/plain; charset=utf-8',
            'Cache-Control' => 'private, no-store',
            'X-Robots-Tag' => 'noindex',
        ]);
    }
}
