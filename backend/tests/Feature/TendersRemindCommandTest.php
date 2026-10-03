<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Mail\AppNotificationMail;
use App\Models\Client;
use App\Models\Product;
use App\Models\Tender;
use App\Models\TenderInvitation;
use App\Models\TenderItem;
use App\Models\User;
use App\Services\Notifications\NotificationPreferences;
use App\Services\Notifications\TenderReminderPlanner;
use Carbon\CarbonImmutable;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Mail;
use Symfony\Component\Mailer\Exception\TransportException;
use Tests\TestCase;

/**
 * tenders:remind — przypomnienia o terminie składania (7 dni, 3 dni, ostatni dzień roboczy, 3 godziny przed)
 * i o wpisaniu wyniku (dzień po terminie, potem co 3 dni), w czasie polskim, każde raz.
 */
final class TendersRemindCommandTest extends TestCase
{
    use RefreshDatabase;

    private User $owner;

    private User $invitee;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolesAndPermissionsSeeder::class);
        Mail::fake();
        $this->owner = User::factory()->withRole('handlowiec')->create(['name' => 'Jan Opiekun', 'email' => 'jan@supon.example.pl']);
        $this->invitee = User::factory()->withRole('handlowiec')->create(['name' => 'Ewa Zaproszona', 'email' => 'ewa@supon.example.pl']);
    }

    public function test_seven_days_before_from_seven_oclock_once_to_owner_and_invitee(): void
    {
        // środa 14.10.2026 → 7 dni przed: środa 7.10
        $tender = $this->tender('2026-10-14', '10:00');
        $this->invite($tender, $this->invitee);

        $this->runAt('2026-10-07 06:50');
        $this->assertSame([], $this->types($this->owner));

        $this->runAt('2026-10-07 07:05');
        $this->runAt('2026-10-07 15:30');
        $this->assertSame(['tender_deadline'], $this->types($this->owner));
        $this->assertSame(['tender_deadline'], $this->types($this->invitee));
        $data = $this->owner->notifications()->firstOrFail()->data;
        $this->assertSame('Termin składania oferty: środa 14.10.2026, 10:00', $data['title']);
        $this->assertStringContainsString('Przypomnienie 7 dni przed terminem.', $data['body']);
        $this->assertSame('/tenders/'.$tender->id, $data['url']);
        // e-mail domyślnie włączony dla terminu
        Mail::assertSent(AppNotificationMail::class, 2);

        $this->runAt('2026-10-08 08:00');
        $this->assertCount(1, $this->types($this->owner));
    }

    public function test_three_days_and_last_workday_and_offer_gaps(): void
    {
        // piątek 16.10 → 3 dni przed: wtorek 13.10; ostatni dzień roboczy: czwartek 15.10
        $tender = $this->tender('2026-10-16');
        $product = Product::query()->create(['sku' => 'R1', 'name' => 'Rękawice R1', 'manufacturer' => 'Supon']);
        $this->item($tender, 1, ['main_product_id' => $product->id, 'offer_price' => 12.5]);
        $this->item($tender, 2, ['main_product_id' => $product->id]);
        $this->item($tender, 3, ['custom_name' => 'Własny wyrób spoza katalogu']);
        $this->item($tender, 4, ['custom_name' => '   ']);

        $this->runAt('2026-10-13 09:00');
        $this->runAt('2026-10-14 09:00');
        $this->runAt('2026-10-15 09:00');

        $rows = $this->owner->notifications()->reorder('created_at')->get();
        $this->assertCount(2, $rows);
        $this->assertStringContainsString('3 dni przed', $rows[0]->data['body']);
        $this->assertStringContainsString('ostatni dzień roboczy', $rows[1]->data['body']);
        $this->assertStringContainsString('Brakuje w ofercie — pozycje bez produktu: 1, pozycje bez ceny: 3 (pozycji razem: 4).', $rows[0]->data['body']);
        $this->assertSame(1, $rows[0]->data['without_product']);
        $this->assertSame(3, $rows[0]->data['without_price']);
    }

    public function test_friday_before_monday_deadline_is_one_reminder(): void
    {
        // poniedziałek 19.10: 3 dni przed i ostatni dzień roboczy to ten sam piątek 16.10
        $this->tender('2026-10-19');

        $this->runAt('2026-10-16 07:00');
        $this->runAt('2026-10-16 12:00');
        $this->runAt('2026-10-17 09:00');
        $this->runAt('2026-10-18 09:00');

        $rows = $this->owner->notifications()->get();
        $this->assertCount(1, $rows);
        $this->assertStringContainsString('ostatni dzień roboczy', $rows[0]->data['body']);
        $this->assertSame('Termin składania oferty: poniedziałek 19.10.2026', $rows[0]->data['title']);
    }

    public function test_three_hours_before_only_when_chosen_and_time_is_known(): void
    {
        $withTime = $this->tender('2026-10-14', '10:00');
        $this->tender('2026-10-14');
        app(NotificationPreferences::class)->update($this->owner, ['deadline_offsets' => ['3h']]);
        $this->invite($withTime, $this->invitee); // domyślne momenty, bez „3 godziny przed”

        $this->runAt('2026-10-14 06:55');
        $this->assertSame([], $this->types($this->owner));

        $this->runAt('2026-10-14 07:00');
        $this->runAt('2026-10-14 09:45');
        $this->runAt('2026-10-14 10:00');

        $rows = $this->owner->notifications()->get();
        $this->assertCount(1, $rows);
        $this->assertSame('Dziś o 10:00 mija termin składania oferty', $rows[0]->data['title']);
        $this->assertSame($withTime->id, $rows[0]->data['tender_id']);
        $this->assertSame([], $this->types($this->invitee));
    }

    public function test_deadline_reminders_only_for_tenders_in_progress(): void
    {
        foreach (['exported', 'odrzucony', 'archiwum'] as $status) {
            $this->tender('2026-10-14', null, $status);
        }
        $this->tender('2026-10-14', null, 'akceptacja_dyrektor');

        $this->runAt('2026-10-07 08:00');

        $this->assertSame(['tender_deadline'], $this->types($this->owner));
    }

    public function test_result_needed_day_after_then_every_three_days_until_result(): void
    {
        $tender = $this->tender('2026-10-05', '10:00', 'exported');
        $this->invite($tender, $this->invitee);
        $this->tender('2026-10-05', null, 'draft');
        $this->tender('2026-10-05', null, 'odrzucony');

        $this->runAt('2026-10-05 12:00');
        $this->runAt('2026-10-06 06:00');
        $this->assertSame([], $this->types($this->owner));

        $this->runAt('2026-10-06 07:30'); // D+1
        $this->runAt('2026-10-06 18:00');
        $this->runAt('2026-10-07 08:00'); // D+2
        $this->runAt('2026-10-09 08:00'); // D+4
        $this->assertSame(['tender_result_needed', 'tender_result_needed'], $this->types($this->owner));
        $this->assertSame(['tender_result_needed', 'tender_result_needed'], $this->types($this->invitee));
        $data = $this->owner->notifications()->firstOrFail()->data;
        $this->assertSame('Wpisz wynik przetargu '.$tender->number, $data['title']);
        $this->assertSame('/tenders/'.$tender->id.'?tab=wynik', $data['url']);
        // „wpisz wynik” domyślnie tylko w dzwonku
        Mail::assertNothingSent();

        $tender->forceFill(['result_status' => 'lost'])->save();
        $this->runAt('2026-10-12 08:00'); // D+7
        $this->assertCount(2, $this->types($this->owner));
    }

    public function test_result_needed_stops_after_sixty_days(): void
    {
        Cache::forever(TenderReminderPlanner::STARTED_ON_CACHE_KEY, '2026-08-01');
        $this->tender('2026-08-20', null, 'exported');

        $this->runAt('2026-10-17 08:00'); // D+58
        $this->runAt('2026-10-20 08:00'); // D+61

        $this->assertCount(1, $this->types($this->owner));
    }

    public function test_no_flood_after_deployment(): void
    {
        // pierwszy przebieg 20.11: termin 19 dni wcześniej (wg reguły „co 3 dni” wypadałby dziś) jest za stary,
        // termin 13 dni wcześniej się mieści (14 dni wstecz)
        $this->tender('2026-11-01', null, 'exported');
        $recent = $this->tender('2026-11-07', null, 'exported');

        $this->runAt('2026-11-20 08:00');
        $this->assertSame(1, $this->owner->notifications()->count());
        $this->assertSame($recent->id, $this->owner->notifications()->firstOrFail()->data['tender_id']);

        // wyczyszczona pamięć podręczna: dzień pierwszego przebiegu wynika z zapisanych wysyłek (stary termin
        // wypadałby znów 23.11 — dzień 22 po terminie)
        Cache::flush();
        $this->runAt('2026-11-23 08:00');
        $this->assertSame(
            [$recent->id, $recent->id],
            $this->owner->notifications()->get()->pluck('data.tender_id')->all(),
        );
    }

    public function test_mail_error_fails_the_run_and_only_the_mail_is_sent_again_later(): void
    {
        $this->tender('2026-10-14', '10:00');
        $fake = Mail::getFacadeRoot();
        Mail::shouldReceive('to')->once()->andThrow(new TransportException('Connection could not be established with host "smtp.example.pl:587"'));

        $this->travelTo(CarbonImmutable::parse('2026-10-07 07:05', 'Europe/Warsaw'));
        $this->artisan('tenders:remind')
            ->expectsOutputToContain('nieudane e-maile: 1')
            ->assertFailed();
        $this->assertSame(['tender_deadline'], $this->types($this->owner));

        // poczta naprawiona: kolejny przebieg po przerwie wysyła sam e-mail, dzwonek się nie powtarza
        Mail::swap($fake);
        $this->runAt('2026-10-07 07:25');
        Mail::assertSent(AppNotificationMail::class, fn (AppNotificationMail $m): bool => $m->hasTo('jan@supon.example.pl'));
        Mail::assertSentCount(1);
        $this->assertSame(['tender_deadline'], $this->types($this->owner));

        $this->runAt('2026-10-07 09:00');
        Mail::assertSentCount(1);
    }

    private function runAt(string $polishTime): void
    {
        $this->travelTo(CarbonImmutable::parse($polishTime, 'Europe/Warsaw'));
        $this->artisan('tenders:remind')->assertSuccessful();
    }

    /** @return list<string> */
    private function types(User $user): array
    {
        return $user->notifications()->get()->pluck('data.type')->values()->all();
    }

    private function tender(string $deadline, ?string $time = null, string $status = 'wycena'): Tender
    {
        $client = Client::query()->create(['name' => 'Szpital Wojewódzki nr 2']);

        return Tender::query()->create([
            'number' => 'PRZ/REM/'.uniqid(),
            'title' => 'Rękawice i obuwie',
            'client_id' => $client->id,
            'owner_id' => $this->owner->id,
            'status' => $status,
            'deadline' => $deadline,
            'deadline_time' => $time,
            'ai_percent' => 0,
            'last_activity_at' => now(),
        ])->load('owner');
    }

    private function invite(Tender $tender, User $user): void
    {
        TenderInvitation::query()->create([
            'tender_id' => $tender->id,
            'user_id' => $user->id,
            'invited_by' => $this->owner->id,
        ]);
    }

    /** @param  array<string, mixed>  $attrs */
    private function item(Tender $tender, int $lineNo, array $attrs): void
    {
        TenderItem::query()->forceCreate([
            'tender_id' => $tender->id,
            'line_no' => $lineNo,
            'requirement' => 'Pozycja '.$lineNo,
            ...$attrs,
        ]);
    }
}
