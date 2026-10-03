<?php

declare(strict_types=1);

namespace App\Services\Notifications;

use App\Models\Tender;
use App\Models\User;
use App\Support\PolishTime;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

/**
 * Które przypomnienia o przetargach należą się teraz (polecenie tenders:remind co 15 minut).
 *
 * Termin składania (tender_deadline) — statusy DEADLINE_STATUSES, odbiorcy: opiekun i zaproszeni, każdy według
 * swoich momentów (NotificationPreferences::offsets):
 *  - „7d”, „3d”, „last_workday” — w dniu wypadającym 7 / 3 dni przed terminem albo w ostatni dzień roboczy przed
 *    nim (bez świąt), od godziny daily_from czasu polskiego. Kilka momentów tego samego dnia = jedno przypomnienie
 *    (okres „data terminu:dzień”), np. termin w poniedziałek: 3 dni przed = ostatni dzień roboczy = piątek.
 *  - „3h” — w oknie [termin − 3 h, termin), tylko gdy przetarg ma godzinę składania.
 * Pominięty moment (np. serwer stał) nie jest nadrabiany następnego dnia — przypomnienie dotyczy konkretnego dnia.
 *
 * Wpisz wynik (tender_result_needed) — statusy poza RESULT_SKIPPED_STATUSES, gdy result_status jest pusty:
 * dzień po terminie, potem co result_needed_every_days dni, najdłużej result_needed_max_days dni po terminie,
 * od godziny daily_from.
 *
 * Ochrona przed zalewem po wdrożeniu: liczą się tylko terminy od backfill_days dni przed dniem pierwszego
 * przebiegu (startedOn()). Ochronę przed powtórką w kolejnych przebiegach daje NotificationDispatcher (okres).
 */
class TenderReminderPlanner
{
    public const DEADLINE_STATUSES = ['draft', 'wycena', 'akceptacja_km', 'akceptacja_dyrektor', 'zatwierdzona'];

    public const RESULT_SKIPPED_STATUSES = ['draft', 'odrzucony'];

    /** Klucz w pamięci podręcznej z dniem pierwszego przebiegu przypomnień (Y-m-d, czas polski). */
    public const STARTED_ON_CACHE_KEY = 'notifications:tender_reminders_started_on';

    /** Momenty dzienne od najbliższego terminowi — ten opisuje przypomnienie, gdy kilka wypada tego samego dnia. */
    private const DAILY_OFFSETS = ['last_workday', '3d', '7d'];

    private const OFFSET_TEXT = [
        '7d' => 'Przypomnienie 7 dni przed terminem.',
        '3d' => 'Przypomnienie 3 dni przed terminem.',
        'last_workday' => 'Przypomnienie w ostatni dzień roboczy przed terminem.',
        '3h' => 'Przypomnienie 3 godziny przed terminem.',
    ];

    private const WEEKDAYS = [1 => 'poniedziałek', 2 => 'wtorek', 3 => 'środa', 4 => 'czwartek', 5 => 'piątek', 6 => 'sobota', 7 => 'niedziela'];

    public function __construct(private readonly NotificationPreferences $preferences) {}

    /**
     * Przypomnienia należne w chwili $now — każde dla jednej osoby. Ten sam wynik w kolejnym przebiegu tego
     * samego dnia ma ten sam okres, więc dyspozytor wyśle go raz.
     *
     * @return iterable<int, array{user: User, message: AppNotificationMessage, period: string}>
     */
    public function due(CarbonImmutable $now): iterable
    {
        $now = $now->setTimezone(PolishTime::TIMEZONE);
        $today = $now->startOfDay();
        $dailyOpen = $now->greaterThanOrEqualTo($this->dailyFrom($today));
        $floor = $this->startedOn($today)->subDays(max(0, (int) config('notifications.backfill_days', 14)));

        yield from $this->deadlineReminders($now, $today, $dailyOpen, $floor);
        if ($dailyOpen) {
            yield from $this->resultReminders($today, $floor);
        }
    }

    /**
     * Dzień pierwszego przebiegu przypomnień (czas polski). Najwcześniejszy z: zapisu w pamięci podręcznej
     * (ustawiany przy pierwszym przebiegu) i najstarszego wpisu ochrony przed powtórką dla przypomnień — wpisy
     * w bazie przetrwają wyczyszczenie pamięci podręcznej, a pamięć podręczna pokrywa dni, w których jeszcze
     * nic nie wysłano. Gdy nie ma żadnego — dziś.
     */
    public function startedOn(CarbonImmutable $today): CarbonImmutable
    {
        $candidates = [];
        $cached = Cache::get(self::STARTED_ON_CACHE_KEY);
        if (is_string($cached) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $cached) === 1) {
            $candidates[] = CarbonImmutable::parse($cached, PolishTime::TIMEZONE)->startOfDay();
        }
        $firstDispatch = DB::table('notification_dispatches')
            ->whereIn('event', ['tender_deadline', 'tender_result_needed'])
            ->min('created_at');
        if (is_string($firstDispatch) && $firstDispatch !== '') {
            $candidates[] = CarbonImmutable::parse($firstDispatch, 'UTC')->setTimezone(PolishTime::TIMEZONE)->startOfDay();
        }
        $candidates[] = $today;
        $started = min($candidates);

        if ($cached !== $started->format('Y-m-d')) {
            Cache::forever(self::STARTED_ON_CACHE_KEY, $started->format('Y-m-d'));
        }

        return $started;
    }

    /**
     * @return iterable<int, array{user: User, message: AppNotificationMessage, period: string}>
     */
    private function deadlineReminders(CarbonImmutable $now, CarbonImmutable $today, bool $dailyOpen, CarbonImmutable $floor): iterable
    {
        // daty terminów, w których dziś wypada jakiś moment: od dziś (3 godziny przed, także tuż po północy
        // następnego dnia) do 7 dni naprzód
        $from = $today->max($floor);
        $query = $this->tenders()
            ->whereIn('status', self::DEADLINE_STATUSES)
            ->whereDate('deadline', '>=', $from->format('Y-m-d'))
            ->whereDate('deadline', '<=', $today->addDays(7)->format('Y-m-d'));

        foreach ($query->lazyById(100) as $tender) {
            /** @var Tender $tender */
            $deadlineDay = $this->deadlineDay($tender);
            if ($deadlineDay === null) {
                continue;
            }
            $daily = $dailyOpen ? $this->dailyMomentsToday($deadlineDay, $today) : [];
            $deadlineAt = PolishTime::deadlineAt($tender);
            $threeHours = $deadlineAt !== null
                && $now->greaterThanOrEqualTo($deadlineAt->subHours(3))
                && $now->lessThan($deadlineAt);
            if ($daily === [] && ! $threeHours) {
                continue;
            }

            $missing = null;
            foreach ($this->recipients($tender) as $user) {
                $offsets = $this->preferences->offsets($user);
                $mine = array_values(array_intersect(self::DAILY_OFFSETS, $daily, $offsets));
                if ($mine !== []) {
                    $missing ??= $this->missing($tender);
                    yield [
                        'user' => $user,
                        'message' => $this->deadlineMessage($tender, $deadlineDay, $mine[0], $missing),
                        'period' => $deadlineDay->format('Y-m-d').':'.$today->format('Y-m-d'),
                    ];
                }
                if ($threeHours && in_array('3h', $offsets, true)) {
                    $missing ??= $this->missing($tender);
                    yield [
                        'user' => $user,
                        'message' => $this->deadlineMessage($tender, $deadlineDay, '3h', $missing),
                        'period' => $deadlineDay->format('Y-m-d').':3h',
                    ];
                }
            }
        }
    }

    /**
     * @return iterable<int, array{user: User, message: AppNotificationMessage, period: string}>
     */
    private function resultReminders(CarbonImmutable $today, CarbonImmutable $floor): iterable
    {
        $every = max(1, (int) config('notifications.result_needed_every_days', 3));
        $maxDays = max(1, (int) config('notifications.result_needed_max_days', 60));
        $from = $today->subDays($maxDays)->max($floor);

        $query = $this->tenders()
            ->whereNotIn('status', self::RESULT_SKIPPED_STATUSES)
            ->whereNull('result_status')
            ->whereDate('deadline', '>=', $from->format('Y-m-d'))
            ->whereDate('deadline', '<=', $today->subDay()->format('Y-m-d'));

        foreach ($query->lazyById(100) as $tender) {
            /** @var Tender $tender */
            $deadlineDay = $this->deadlineDay($tender);
            if ($deadlineDay === null) {
                continue;
            }
            $days = $this->daysBetween($deadlineDay, $today);
            if ($days < 1 || $days > $maxDays || ($days - 1) % $every !== 0) {
                continue;
            }
            $message = $this->resultMessage($tender, $deadlineDay);
            foreach ($this->recipients($tender) as $user) {
                yield [
                    'user' => $user,
                    'message' => $message,
                    'period' => $deadlineDay->format('Y-m-d').':+'.$days,
                ];
            }
        }
    }

    /** @return Builder<Tender> */
    private function tenders(): Builder
    {
        return Tender::query()
            ->whereNotNull('deadline')
            ->with([
                'client:id,name',
                'owner',
                'invitations.user',
            ]);
    }

    /**
     * Opiekun i zaproszeni, każdy raz.
     *
     * @return list<User>
     */
    private function recipients(Tender $tender): array
    {
        $users = [];
        if ($tender->owner instanceof User) {
            $users[(int) $tender->owner->id] = $tender->owner;
        }
        foreach ($tender->invitations as $invitation) {
            if ($invitation->user instanceof User) {
                $users[(int) $invitation->user->id] ??= $invitation->user;
            }
        }

        return array_values($users);
    }

    /**
     * Momenty dzienne wypadające dziś dla terminu w dniu $deadlineDay.
     *
     * @return list<string>
     */
    private function dailyMomentsToday(CarbonImmutable $deadlineDay, CarbonImmutable $today): array
    {
        $todayKey = $today->format('Y-m-d');
        $days = [
            '7d' => $deadlineDay->subDays(7)->format('Y-m-d'),
            '3d' => $deadlineDay->subDays(3)->format('Y-m-d'),
            'last_workday' => PolishTime::lastBusinessDayBefore($deadlineDay->format('Y-m-d'))->format('Y-m-d'),
        ];

        return array_keys(array_filter($days, static fn (string $day): bool => $day === $todayKey));
    }

    /**
     * Braki oferty: pozycje bez produktu (bez karty i bez własnej nazwy) i bez ceny oferty.
     *
     * @return array{total: int, without_product: int, without_price: int}
     */
    private function missing(Tender $tender): array
    {
        $row = DB::table('tender_items')
            ->where('tender_id', $tender->id)
            ->selectRaw('COUNT(*) AS total')
            ->selectRaw("SUM(CASE WHEN main_product_id IS NULL AND (custom_name IS NULL OR TRIM(custom_name) = '') THEN 1 ELSE 0 END) AS without_product")
            ->selectRaw('SUM(CASE WHEN offer_price IS NULL THEN 1 ELSE 0 END) AS without_price')
            ->first();

        return [
            'total' => (int) ($row->total ?? 0),
            'without_product' => (int) ($row->without_product ?? 0),
            'without_price' => (int) ($row->without_price ?? 0),
        ];
    }

    /**
     * @param  array{total: int, without_product: int, without_price: int}  $missing
     */
    private function deadlineMessage(Tender $tender, CarbonImmutable $deadlineDay, string $offset, array $missing): AppNotificationMessage
    {
        $when = PolishTime::formatDeadline($tender);
        $title = $offset === '3h' && $tender->deadline_time !== null
            ? 'Dziś o '.$tender->deadline_time.' mija termin składania oferty'
            : 'Termin składania oferty: '.self::WEEKDAYS[(int) $deadlineDay->isoWeekday()].' '.$when;

        return new AppNotificationMessage(
            event: 'tender_deadline',
            subjectKey: 'tender:'.$tender->id,
            title: $title,
            body: implode("\n", [
                $this->tenderLine($tender),
                $this->missingText($missing),
                self::OFFSET_TEXT[$offset] ?? '',
            ]),
            url: '/tenders/'.$tender->id,
            data: [
                'tender_id' => $tender->id,
                'tender_number' => $tender->number,
                'tender_title' => $tender->title,
                'deadline' => $deadlineDay->format('Y-m-d'),
                'deadline_time' => $tender->deadline_time,
                'offset' => $offset,
                'without_product' => $missing['without_product'],
                'without_price' => $missing['without_price'],
            ],
        );
    }

    private function resultMessage(Tender $tender, CarbonImmutable $deadlineDay): AppNotificationMessage
    {
        return new AppNotificationMessage(
            event: 'tender_result_needed',
            subjectKey: 'tender:'.$tender->id,
            title: 'Wpisz wynik przetargu '.$tender->number,
            body: implode("\n", [
                $this->tenderLine($tender),
                'Termin składania ofert minął '.PolishTime::formatDeadline($tender).'. Wpisz, jak się skończył — przypomnimy co '
                    .max(1, (int) config('notifications.result_needed_every_days', 3)).' dni, dopóki wyniku nie będzie.',
            ]),
            url: '/tenders/'.$tender->id.'?tab=wynik',
            data: [
                'tender_id' => $tender->id,
                'tender_number' => $tender->number,
                'tender_title' => $tender->title,
                'deadline' => $deadlineDay->format('Y-m-d'),
            ],
        );
    }

    private function tenderLine(Tender $tender): string
    {
        return implode(' · ', array_filter([
            (string) $tender->number,
            (string) $tender->title,
            $tender->client?->name,
        ], static fn (?string $part): bool => $part !== null && trim($part) !== ''));
    }

    /**
     * @param  array{total: int, without_product: int, without_price: int}  $missing
     */
    private function missingText(array $missing): string
    {
        if ($missing['total'] === 0) {
            return 'Przetarg nie ma jeszcze pozycji.';
        }
        $parts = [];
        if ($missing['without_product'] > 0) {
            $parts[] = 'pozycje bez produktu: '.$missing['without_product'];
        }
        if ($missing['without_price'] > 0) {
            $parts[] = 'pozycje bez ceny: '.$missing['without_price'];
        }

        return $parts === []
            ? 'Wszystkie pozycje ('.$missing['total'].') mają produkt i cenę.'
            : 'Brakuje w ofercie — '.implode(', ', $parts).' (pozycji razem: '.$missing['total'].').';
    }

    /** Dzień terminu (00:00 czasu polskiego) z daty „na zegarze”. */
    private function deadlineDay(Tender $tender): ?CarbonImmutable
    {
        $deadline = $tender->deadline;
        if ($deadline === null) {
            return null;
        }

        return CarbonImmutable::createFromFormat('!Y-m-d', $deadline->format('Y-m-d'), PolishTime::TIMEZONE) ?: null;
    }

    private function dailyFrom(CarbonImmutable $today): CarbonImmutable
    {
        $from = (string) config('notifications.daily_from', '07:00');
        if (preg_match('/^(\d{1,2}):(\d{2})$/', $from, $m) !== 1) {
            return $today->setTime(7, 0);
        }

        return $today->setTime((int) $m[1], (int) $m[2]);
    }

    /** Pełne dni kalendarza między dwiema datami (bez wpływu zmiany czasu letniego). */
    private function daysBetween(CarbonImmutable $from, CarbonImmutable $to): int
    {
        $a = CarbonImmutable::parse($from->format('Y-m-d'), 'UTC');
        $b = CarbonImmutable::parse($to->format('Y-m-d'), 'UTC');

        return (int) $a->diffInDays($b, false);
    }
}
