<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Mail\AppNotificationMail;
use App\Models\MailSetting;
use App\Models\User;
use App\Notifications\AppNotification;
use App\Services\Notifications\AppNotificationMessage;
use App\Services\Notifications\NotificationDispatcher;
use App\Services\Notifications\NotificationPreferences;
use Carbon\CarbonImmutable;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Mail\Mailable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Symfony\Component\Mailer\Exception\TransportException;
use Tests\TestCase;

/** Wspólna wysyłka powiadomień: kanały według preferencji, ochrona przed powtórką, błąd SMTP. */
final class NotificationDispatcherTest extends TestCase
{
    use RefreshDatabase;

    private mixed $mailFake = null;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolesAndPermissionsSeeder::class);
        Mail::fake();
        config(['app.frontend_url' => 'https://przetargi.example.pl']);
    }

    private function message(string $event = 'tender_mention', ?Mailable $mailable = null): AppNotificationMessage
    {
        return new AppNotificationMessage(
            event: $event,
            subjectKey: 'tender:12',
            title: 'Anna Nowak wspomina o Tobie w komentarzu',
            body: 'PRZ/2026/0012 · Rękawice: „Sprawdź ceny”',
            url: '/tenders/12?tab=komentarze',
            data: ['tender_id' => 12, 'type' => 'nie-nadpisze'],
            mailable: $mailable,
        );
    }

    public function test_bell_and_mail_by_default_preferences(): void
    {
        $user = User::factory()->withRole('handlowiec')->create(['email' => 'jan@supon.example.pl', 'name' => 'Jan']);

        $result = app(NotificationDispatcher::class)->send($user, $this->message());

        $this->assertSame([$user->id => ['bell' => true, 'mail' => true]], $result);
        $row = $user->notifications()->firstOrFail();
        $this->assertSame(AppNotification::class, $row->type);
        $this->assertSame([
            'tender_id' => 12,
            'type' => 'tender_mention',
            'title' => 'Anna Nowak wspomina o Tobie w komentarzu',
            'body' => 'PRZ/2026/0012 · Rękawice: „Sprawdź ceny”',
            'url' => '/tenders/12?tab=komentarze',
            'message' => 'Anna Nowak wspomina o Tobie w komentarzu',
        ], $row->data);

        Mail::assertSent(AppNotificationMail::class, function (AppNotificationMail $mail): bool {
            $mail->assertSeeInHtml('https://przetargi.example.pl/tenders/12?tab=komentarze', false);
            $mail->assertSeeInText('Sprawdź ceny');
            $mail->assertSeeInHtml('https://przetargi.example.pl/account#powiadomienia', false);

            return $mail->hasTo('jan@supon.example.pl') && $mail->envelope()->subject === 'Anna Nowak wspomina o Tobie w komentarzu';
        });
    }

    public function test_channels_follow_user_preferences(): void
    {
        $bellOnly = User::factory()->withRole('handlowiec')->create();
        $nothing = User::factory()->withRole('handlowiec')->create();
        $prefs = app(NotificationPreferences::class);
        $prefs->update($bellOnly, ['events' => ['tender_mention' => ['mail' => false]]]);
        $prefs->update($nothing, ['events' => ['tender_mention' => ['bell' => false, 'mail' => false]]]);

        $result = app(NotificationDispatcher::class)->send([$bellOnly->fresh(), $nothing->fresh()], $this->message(), '2026-10-05');

        $this->assertSame([
            $bellOnly->id => ['bell' => true, 'mail' => null],
            $nothing->id => ['bell' => false, 'mail' => null],
        ], $result);
        $this->assertSame(1, $bellOnly->notifications()->count());
        $this->assertSame(0, $nothing->notifications()->count());
        Mail::assertNothingSent();
        // kto nic nie chce, nie zajmuje wpisu ochrony — po włączeniu dostanie przypomnienie z tego okresu
        $this->assertSame(1, DB::table('notification_dispatches')->count());
    }

    public function test_same_period_is_sent_once_and_new_period_again(): void
    {
        $user = User::factory()->withRole('handlowiec')->create();
        $dispatcher = app(NotificationDispatcher::class);

        $this->assertCount(1, $dispatcher->send($user, $this->message(), '2026-10-05:7d'));
        $this->assertSame([], $dispatcher->send($user, $this->message(), '2026-10-05:7d'));
        $this->assertCount(1, $dispatcher->send($user, $this->message(), '2026-10-05:3d'));
        // bez okresu — bez ochrony (np. każda wzmianka to osobna rzecz)
        $dispatcher->send($user, $this->message());

        $this->assertSame(3, $user->notifications()->count());
        Mail::assertSentCount(3);
        $this->assertDatabaseHas('notification_dispatches', [
            'user_id' => $user->id, 'event' => 'tender_mention', 'subject_key' => 'tender:12', 'period_key' => '2026-10-05:7d',
        ]);
    }

    public function test_smtp_error_keeps_bell_and_other_recipients(): void
    {
        $first = User::factory()->withRole('handlowiec')->create(['email' => 'a@supon.example.pl']);
        $second = User::factory()->withRole('handlowiec')->create(['email' => 'b@supon.example.pl']);
        $calls = 0;
        Mail::shouldReceive('to')->twice()->andReturnUsing(function () use (&$calls): never {
            $calls++;
            throw new TransportException('Connection could not be established with host "smtp.example.pl:587"');
        });

        $result = app(NotificationDispatcher::class)->send([$first, $second], $this->message(), 'p1');

        $this->assertSame(2, $calls);
        $this->assertSame([
            $first->id => ['bell' => true, 'mail' => false],
            $second->id => ['bell' => true, 'mail' => false],
        ], $result);
        $this->assertSame(1, $first->notifications()->count());
        $this->assertSame(1, $second->notifications()->count());
    }

    public function test_failed_mail_is_retried_later_without_a_second_bell(): void
    {
        $this->travelTo(CarbonImmutable::parse('2026-10-05 07:00:00', 'UTC'));
        $user = User::factory()->withRole('handlowiec')->create(['email' => 'jan@supon.example.pl']);
        $this->failingSmtp(1);
        $dispatcher = app(NotificationDispatcher::class);

        $this->assertSame([$user->id => ['bell' => true, 'mail' => false]], $dispatcher->send($user, $this->message(), '2026-10-05:7d'));
        $this->assertDatabaseHas('notification_dispatches', ['user_id' => $user->id, 'mail_status' => 'pending', 'mail_attempts' => 1]);

        // przed upływem przerwy (15 min) nic — ani dzwonka, ani próby
        $this->workingSmtp();
        $this->travel(10)->minutes();
        $this->assertSame([], $dispatcher->send($user, $this->message(), '2026-10-05:7d'));
        Mail::assertNothingSent();

        // poczta naprawiona, przerwa minęła: sam e-mail, bez drugiego dzwonka
        $this->travel(6)->minutes();
        $this->assertSame([$user->id => ['bell' => false, 'mail' => true]], $dispatcher->send($user, $this->message(), '2026-10-05:7d'));
        Mail::assertSent(AppNotificationMail::class, fn (AppNotificationMail $m): bool => $m->hasTo('jan@supon.example.pl'));
        $this->assertSame(1, $user->notifications()->count());
        $this->assertDatabaseHas('notification_dispatches', ['user_id' => $user->id, 'mail_status' => 'sent', 'mail_attempts' => 2]);

        // wysłany — kolejne przebiegi już nic nie robią
        $this->travel(1)->hours();
        $this->assertSame([], $dispatcher->send($user, $this->message(), '2026-10-05:7d'));
        Mail::assertSentCount(1);
    }

    public function test_mail_retry_waits_longer_each_time_and_gives_up_after_the_limit(): void
    {
        config(['notifications.mail_retry.max_attempts' => 3, 'notifications.mail_retry.first_delay_minutes' => 15]);
        $this->travelTo(CarbonImmutable::parse('2026-10-05 07:00:00', 'UTC'));
        $user = User::factory()->withRole('handlowiec')->create();
        $this->failingSmtp(3);
        $dispatcher = app(NotificationDispatcher::class);

        $dispatcher->send($user, $this->message(), 'p'); // próba 1
        $this->travel(16)->minutes();
        $this->assertSame([$user->id => ['bell' => false, 'mail' => false]], $dispatcher->send($user, $this->message(), 'p')); // próba 2
        $this->travel(16)->minutes();
        $this->assertSame([], $dispatcher->send($user, $this->message(), 'p'), 'Po drugiej próbie przerwa to 30 minut.');
        $this->travel(15)->minutes();
        $dispatcher->send($user, $this->message(), 'p'); // próba 3 — ostatnia
        $this->assertDatabaseHas('notification_dispatches', ['user_id' => $user->id, 'mail_status' => 'failed', 'mail_attempts' => 3]);

        $this->workingSmtp();
        $this->travel(1)->days();
        $this->assertSame([], $dispatcher->send($user, $this->message(), 'p'));
        Mail::assertNothingSent();
        $this->assertSame(1, $user->notifications()->count());
    }

    public function test_mail_stuck_in_sending_is_retried_and_turned_off_mail_is_not(): void
    {
        $this->travelTo(CarbonImmutable::parse('2026-10-05 07:00:00', 'UTC'));
        $stuck = User::factory()->withRole('handlowiec')->create();
        $optedOut = User::factory()->withRole('handlowiec')->create();
        foreach ([$stuck, $optedOut] as $user) {
            DB::table('notification_dispatches')->insert([
                'user_id' => $user->id, 'event' => 'tender_mention', 'subject_key' => 'tender:12', 'period_key' => 'p',
                'mail_status' => $user->is($stuck) ? 'sending' : 'pending', 'mail_attempts' => 1,
                'mail_attempted_at' => now()->subMinutes(20), 'created_at' => now()->subMinutes(20),
            ]);
        }
        app(NotificationPreferences::class)->update($optedOut, ['events' => ['tender_mention' => ['mail' => false]]]);
        $dispatcher = app(NotificationDispatcher::class);

        $this->assertSame([], $dispatcher->send([$stuck, $optedOut->fresh()], $this->message(), 'p'), 'Wysyłka w toku krócej niż 30 min — czekamy.');
        $this->travel(11)->minutes();
        $this->assertSame([$stuck->id => ['bell' => false, 'mail' => true]], $dispatcher->send([$stuck, $optedOut->fresh()], $this->message(), 'p'));
        Mail::assertSentCount(1);
    }

    public function test_pending_mail_subjects_list_only_mails_waiting_for_retry(): void
    {
        $user = User::factory()->withRole('admin')->create();
        $this->failingSmtp(1);
        $dispatcher = app(NotificationDispatcher::class);
        $dispatcher->send($user, new AppNotificationMessage('system_alert', 'system_alert:7', 'Alert', 'Opis', '/admin/stan-systemu'), 'incident:7');
        $this->workingSmtp();
        $dispatcher->send($user, new AppNotificationMessage('system_alert', 'system_alert:8', 'Alert', 'Opis', '/admin/stan-systemu'), 'incident:8');

        $this->assertSame(['system_alert:7'], $dispatcher->pendingMailSubjects('system_alert'));
        $this->assertSame([], $dispatcher->pendingMailSubjects('tender_mention'));
    }

    public function test_smtp_settings_from_the_panel_are_read_again_before_sending(): void
    {
        // proces kolejki wystartował ze starymi ustawieniami; administrator zmienił serwer w panelu
        config(['mail.mailers.smtp.host' => 'smtp.stary.example.pl']);
        $row = MailSetting::query()->first() ?? new MailSetting;
        $row->forceFill([
            'mailer' => 'smtp', 'host' => 'smtp.nowy.example.pl', 'port' => 587, 'from_address' => 'przetargi@supon.example.pl', 'verify_peer' => true,
        ])->save();
        $user = User::factory()->withRole('handlowiec')->create();

        app(NotificationDispatcher::class)->send($user, $this->message());

        $this->assertSame('smtp.nowy.example.pl', config('mail.mailers.smtp.host'));
        Mail::assertSentCount(1);
    }

    public function test_text_part_of_the_mail_has_no_html_entities(): void
    {
        $user = User::factory()->withRole('handlowiec')->create(['name' => 'Jan & Syn']);
        $message = new AppNotificationMessage(
            event: 'tender_mention',
            subjectKey: 'tender:12',
            title: 'Ceny & rabaty',
            body: 'Pozycja „<5 szt.>” — O\'Neil & Co',
            url: '/tenders/12?tab=komentarze&x=1',
        );

        app(NotificationDispatcher::class)->send($user, $message);

        Mail::assertSent(AppNotificationMail::class, function (AppNotificationMail $mail): bool {
            $mail->assertSeeInText('Pozycja „<5 szt.>” — O\'Neil & Co');
            $mail->assertSeeInText('Cześć Jan & Syn');
            $mail->assertSeeInText('/tenders/12?tab=komentarze&x=1');
            $mail->assertDontSeeInText('&amp;');
            $mail->assertDontSeeInText('&lt;');
            // HTML dalej escapowany
            $mail->assertSeeInHtml('&lt;5 szt.&gt;', false);

            return true;
        });
    }

    /** SMTP nie działa przez `times` wysyłek; workingSmtp() przywraca atrapę poczty z setUp(). */
    private function failingSmtp(int $times): void
    {
        $this->mailFake ??= Mail::getFacadeRoot();
        Mail::shouldReceive('to')->times($times)->andThrow(new TransportException('Connection could not be established with host "smtp.example.pl:587"'));
    }

    private function workingSmtp(): void
    {
        Mail::swap($this->mailFake);
    }

    public function test_own_mailable_goes_to_each_recipient_separately(): void
    {
        $first = User::factory()->withRole('handlowiec')->create(['email' => 'a@supon.example.pl']);
        $second = User::factory()->withRole('handlowiec')->create(['email' => 'b@supon.example.pl']);
        $mailable = new AppNotificationMail($this->message(), $first);

        app(NotificationDispatcher::class)->send([$first, $second], $this->message(mailable: $mailable));

        Mail::assertSentCount(2);
        Mail::assertSent(AppNotificationMail::class, fn (AppNotificationMail $m): bool => $m->hasTo('a@supon.example.pl') && ! $m->hasTo('b@supon.example.pl'));
        Mail::assertSent(AppNotificationMail::class, fn (AppNotificationMail $m): bool => $m->hasTo('b@supon.example.pl') && ! $m->hasTo('a@supon.example.pl'));
    }

    public function test_event_with_permission_skips_people_without_it(): void
    {
        $admin = User::factory()->withRole('admin')->create();
        $salesman = User::factory()->withRole('handlowiec')->create();

        $result = app(NotificationDispatcher::class)->send([$admin, $salesman], $this->message('system_alert'));

        $this->assertSame([$admin->id], array_keys($result));
        $this->assertSame(0, $salesman->notifications()->count());
    }
}
