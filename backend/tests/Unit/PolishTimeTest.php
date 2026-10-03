<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Models\Tender;
use App\Support\PolishTime;
use Carbon\CarbonImmutable;
use Illuminate\Support\Carbon;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

final class PolishTimeTest extends TestCase
{
    protected function tearDown(): void
    {
        Carbon::setTestNow();
        CarbonImmutable::setTestNow();
        parent::tearDown();
    }

    public function test_today_is_polish_calendar_day_not_utc(): void
    {
        // 22:30 UTC 4 października = 0:30 czasu polskiego 5 października
        $this->travelTo(CarbonImmutable::parse('2026-10-04 22:30:00', 'UTC'));

        $today = PolishTime::today();
        $this->assertSame('2026-10-05 00:00:00', $today->format('Y-m-d H:i:s'));
        $this->assertSame('Europe/Warsaw', $today->getTimezone()->getName());
    }

    public function test_deadline_at_combines_date_and_polish_wall_clock(): void
    {
        $moment = PolishTime::deadlineAt($this->tender('2026-10-05', '10:00'));

        $this->assertNotNull($moment);
        $this->assertSame('2026-10-05T10:00:00+02:00', $moment->toIso8601String());
        $this->assertSame('2026-10-05 08:00', $moment->utc()->format('Y-m-d H:i'));
    }

    public function test_deadline_at_in_winter_time(): void
    {
        $moment = PolishTime::deadlineAt($this->tender('2026-12-01', '09:30'));

        $this->assertSame('2026-12-01T09:30:00+01:00', $moment?->toIso8601String());
    }

    public function test_deadline_at_on_spring_forward_day(): void
    {
        // 29.03.2026 zegar przeskakuje z 2:00 na 3:00
        $this->assertSame('2026-03-29T10:00:00+02:00', PolishTime::deadlineAt($this->tender('2026-03-29', '10:00'))?->toIso8601String());
        $this->assertSame('2026-03-29T01:30:00+01:00', PolishTime::deadlineAt($this->tender('2026-03-29', '01:30'))?->toIso8601String());
        // godzina, której tego dnia nie ma, przesuwa się o godzinę do przodu
        $this->assertSame('2026-03-29T03:30:00+02:00', PolishTime::deadlineAt($this->tender('2026-03-29', '02:30'))?->toIso8601String());
    }

    public function test_deadline_at_on_fall_back_day(): void
    {
        // 25.10.2026 zegar cofa się z 3:00 na 2:00
        $this->assertSame('2026-10-25T10:00:00+01:00', PolishTime::deadlineAt($this->tender('2026-10-25', '10:00'))?->toIso8601String());
        // godzina podwójna — pierwsze wystąpienie (jeszcze czas letni)
        $this->assertSame('2026-10-25T02:30:00+02:00', PolishTime::deadlineAt($this->tender('2026-10-25', '02:30'))?->toIso8601String());
        $this->assertSame('2026-10-25T01:59:00+02:00', PolishTime::deadlineAt($this->tender('2026-10-25', '01:59'))?->toIso8601String());
        $this->assertSame('2026-10-25T03:00:00+01:00', PolishTime::deadlineAt($this->tender('2026-10-25', '03:00'))?->toIso8601String());
    }

    public function test_deadline_at_needs_date_and_time(): void
    {
        $this->assertNull(PolishTime::deadlineAt($this->tender('2026-10-05', null)));
        $this->assertNull(PolishTime::deadlineAt($this->tender(null, null)));
    }

    public function test_deadline_time_is_served_as_hours_and_minutes(): void
    {
        $tender = $this->tender('2026-10-05', '9:05');
        $this->assertSame('09:05:00', $tender->getAttributes()['deadline_time']);
        $this->assertSame('09:05', $tender->deadline_time);
        $this->assertSame('09:05', $tender->toArray()['deadline_time']);

        // kolumna TIME z bazy zwraca sekundy
        $tender->setRawAttributes(['deadline' => '2026-10-05', 'deadline_time' => '14:30:00']);
        $this->assertSame('14:30', $tender->deadline_time);

        $tender->deadline_time = '';
        $this->assertNull($tender->deadline_time);
    }

    public function test_deadline_time_rejects_other_spellings(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->tender('2026-10-05', '25:00');
    }

    /**
     * @return array<string, array{string, string}>
     */
    public static function businessDays(): array
    {
        return [
            'środa → wtorek' => ['2026-10-07', '2026-10-06'],
            'poniedziałek → piątek' => ['2026-10-05', '2026-10-02'],
            'niedziela → piątek' => ['2026-10-04', '2026-10-02'],
            'sobota → piątek' => ['2026-10-03', '2026-10-02'],
            'wtorek → poniedziałek' => ['2026-10-06', '2026-10-05'],
            'przez zmianę czasu' => ['2026-10-26', '2026-10-23'],
        ];
    }

    #[DataProvider('businessDays')]
    public function test_last_business_day_before(string $date, string $expected): void
    {
        $day = PolishTime::lastBusinessDayBefore($date);
        $this->assertSame($expected, $day->format('Y-m-d'));
        $this->assertSame('00:00', $day->format('H:i'));
        $this->assertSame('Europe/Warsaw', $day->getTimezone()->getName());
    }

    public function test_last_business_day_before_takes_calendar_day_of_date_cast(): void
    {
        // rzutowanie `date` modelu trzyma północ UTC — dzień kalendarza nie może się przesunąć
        $tender = $this->tender('2026-10-05', null);
        $this->assertSame('2026-10-02', PolishTime::lastBusinessDayBefore($tender->deadline)->format('Y-m-d'));
        $this->assertSame('2026-10-02', PolishTime::lastBusinessDayBefore(CarbonImmutable::parse('2026-10-05 23:30', 'UTC'))->format('Y-m-d'));
    }

    public function test_format(): void
    {
        $this->assertSame('5.10.2026, 10:00', PolishTime::format(CarbonImmutable::parse('2026-10-05 08:00', 'UTC')));
        $this->assertSame('5.10.2026', PolishTime::format(CarbonImmutable::parse('2026-10-05 08:00', 'UTC'), false));
        $this->assertSame('', PolishTime::format(null));
    }

    public function test_format_deadline(): void
    {
        $this->assertSame('5.10.2026, 10:00', PolishTime::formatDeadline($this->tender('2026-10-05', '10:00')));
        $this->assertSame('5.10.2026', PolishTime::formatDeadline($this->tender('2026-10-05', null)));
        $this->assertSame('', PolishTime::formatDeadline($this->tender(null, null)));
    }

    private function tender(?string $date, ?string $time): Tender
    {
        return new Tender(['deadline' => $date, 'deadline_time' => $time]);
    }
}
